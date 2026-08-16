<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Fixed-window limiter for the unauthenticated Claim and Family Invite paths.
 *
 * Every submitted attempt is counted before any identity/token lookup. This
 * gives existing and non-existing targets the same externally visible path.
 * Database, clock, secret or request-source uncertainty is a denial.
 */
final class RateLimiter
{
    public const STATE_ALLOW = 'ALLOW';
    public const STATE_LIMITED = 'LIMITED';
    public const STATE_UNAVAILABLE = 'UNAVAILABLE';

    private Repository $repository;
    private string $hmacKey;
    private int $windowSeconds;
    private int $ipAttemptLimit;
    private int $targetAttemptLimit;
    private \Closure $clock;

    public function __construct(
        Repository $repository,
        string $secret,
        int $windowSeconds = 900,
        int $ipAttemptLimit = 20,
        int $targetAttemptLimit = 8,
        ?callable $clock = null,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('class_identity_rate_limit_secret_unavailable');
        }
        if ($windowSeconds < 60 || $windowSeconds > 86400) {
            throw new \InvalidArgumentException('class_identity_rate_limit_window_invalid');
        }
        if ($ipAttemptLimit < 2 || $ipAttemptLimit > 1000) {
            throw new \InvalidArgumentException('class_identity_rate_limit_ip_threshold_invalid');
        }
        if ($targetAttemptLimit < 2 || $targetAttemptLimit > 1000) {
            throw new \InvalidArgumentException('class_identity_rate_limit_target_threshold_invalid');
        }

        $this->repository = $repository;
        // A dedicated sub-key prevents rate-limit hashes from being usable as
        // token-validator hashes even when the Claim pepper is the fallback.
        $this->hmacKey = hash_hmac(
            'sha256',
            "class-identity/rate-limit/key/v1",
            $secret,
            true,
        );
        $this->windowSeconds = $windowSeconds;
        $this->ipAttemptLimit = $ipAttemptLimit;
        $this->targetAttemptLimit = $targetAttemptLimit;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
    }

    public static function fromPiwigo(): self
    {
        global $conf;

        $secret = getenv('CLASS_ARCHIVE_RATE_LIMIT_SECRET');
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = getenv('CLASS_ARCHIVE_CLAIM_CODE_PEPPER');
        }
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException('class_identity_rate_limit_secret_unavailable');
        }

        $settings = is_array($conf ?? null) ? $conf : [];
        try {
            return new self(
                Repository::fromPiwigo(),
                $secret,
                self::boundedSetting($settings, 'class_identity_rate_limit_window_seconds', 900, 60, 86400),
                self::boundedSetting($settings, 'class_identity_rate_limit_ip_attempts', 20, 2, 1000),
                self::boundedSetting($settings, 'class_identity_rate_limit_target_attempts', 8, 2, 1000),
            );
        } finally {
            self::wipe($secret);
        }
    }

    /**
     * Fail-closed convenience boundary for HTTP controllers.
     *
     * sourceIp must be the web server's trusted REMOTE_ADDR. A forwarded
     * header is not accepted here because its trust boundary belongs in the
     * reverse proxy configuration, not in this plugin.
     *
     * @return array{allowed: bool, state: string, retry_after: int}
     */
    public static function checkFromPiwigo(
        string $purpose,
        string $sourceIp,
        string $rawCode,
        ?string $rosterCode = null,
    ): array {
        try {
            return self::fromPiwigo()->consume($purpose, $sourceIp, $rawCode, $rosterCode);
        } catch (\Throwable) {
            return self::denied(self::STATE_UNAVAILABLE, 60);
        }
    }

    /**
     * Consume one attempt. Exactly N attempts are allowed per configured
     * bucket; N+1 and later attempts in the same window are denied.
     *
     * @return array{allowed: bool, state: string, retry_after: int}
     */
    public function consume(
        string $purpose,
        string $sourceIp,
        string $rawCode,
        ?string $rosterCode = null,
    ): array {
        if (!in_array($purpose, ['CLAIM', 'FAMILY_INVITE'], true)) {
            return self::denied(self::STATE_UNAVAILABLE, 60);
        }

        try {
            $now = ($this->clock)();
            if (!is_int($now) || $now < $this->windowSeconds) {
                return self::denied(self::STATE_UNAVAILABLE, 60);
            }

            $packedIp = @inet_pton(trim($sourceIp));
            if (!is_string($packedIp) || !in_array(strlen($packedIp), [4, 16], true)) {
                return self::denied(self::STATE_UNAVAILABLE, 60);
            }
            $ipSubject = (strlen($packedIp) === 4 ? "\x04" : "\x06") . $packedIp;

            $buckets = [
                [
                    'scope' => 'SOURCE_IP',
                    'hash' => $this->subjectHash('SOURCE_IP', $purpose, $ipSubject),
                    'limit' => $this->ipAttemptLimit,
                ],
                [
                    'scope' => 'SELECTOR',
                    'hash' => $this->subjectHash('SELECTOR', $purpose, self::selectorSubject($rawCode)),
                    'limit' => $this->targetAttemptLimit,
                ],
            ];
            if ($purpose === 'CLAIM') {
                $buckets[] = [
                    'scope' => 'ROSTER',
                    'hash' => $this->subjectHash('ROSTER', $purpose, self::rosterSubject($rosterCode)),
                    'limit' => $this->targetAttemptLimit,
                ];
            }

            // Stable lock order avoids cross-request deadlocks when several
            // requests share only a subset of IP/selector/roster buckets.
            usort(
                $buckets,
                static fn (array $left, array $right): int => strcmp($left['scope'], $right['scope']),
            );

            $windowId = intdiv($now, $this->windowSeconds);
            if ($windowId <= 0) {
                return self::denied(self::STATE_UNAVAILABLE, 60);
            }
            $retryAfter = max(1, (($windowId + 1) * $this->windowSeconds) - $now);
            // Keep one completed window for operations/health inspection. A
            // future idempotent cron cleanup can delete by expires_at.
            $expiresAt = gmdate(
                'Y-m-d H:i:s',
                ($windowId + 2) * $this->windowSeconds,
            ) . '.000000';

            $limited = $this->repository->transaction(function (Repository $repository) use (
                $buckets,
                $purpose,
                $windowId,
                $expiresAt,
            ): bool {
                $denied = false;
                foreach ($buckets as $bucket) {
                    $count = $repository->incrementRateLimitBucket(
                        $bucket['scope'],
                        $purpose,
                        $bucket['hash'],
                        $windowId,
                        $this->windowSeconds,
                        $expiresAt,
                    );
                    if ($count > $bucket['limit']) {
                        $denied = true;
                    }
                }

                return $denied;
            });

            return $limited
                ? self::denied(self::STATE_LIMITED, $retryAfter)
                : ['allowed' => true, 'state' => self::STATE_ALLOW, 'retry_after' => 0];
        } catch (\Throwable) {
            // UNKNOWN != ALLOW. Do not emit DB/secret/target detail here.
            return self::denied(self::STATE_UNAVAILABLE, 60);
        }
    }

    private function subjectHash(string $scope, string $purpose, string $subject): string
    {
        return hash_hmac(
            'sha256',
            "class-identity/rate-limit/subject/v1\0{$scope}\0{$purpose}\0{$subject}",
            $this->hmacKey,
            true,
        );
    }

    private static function selectorSubject(string $rawCode): string
    {
        $bounded = trim(substr($rawCode, 0, 160));
        if (preg_match('/\A([A-Za-z0-9_-]{20,32})(?:\.|\z)/D', $bounded, $match) === 1) {
            return 'selector:' . $match[1];
        }

        // One constant bucket prevents attacker-controlled malformed inputs
        // from causing unbounded cardinality in the limiter table.
        return 'selector:INVALID';
    }

    private static function rosterSubject(?string $rosterCode): string
    {
        $bounded = strtoupper(trim(substr((string) $rosterCode, 0, 128)));
        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,63}\z/D', $bounded) === 1) {
            return 'roster:' . $bounded;
        }

        return 'roster:INVALID';
    }

    /** @param array<string, mixed> $settings */
    private static function boundedSetting(
        array $settings,
        string $key,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        if (!array_key_exists($key, $settings)) {
            return $default;
        }
        if (!is_int($settings[$key]) && !is_string($settings[$key])) {
            throw new \RuntimeException('class_identity_rate_limit_setting_invalid');
        }
        $value = filter_var(
            $settings[$key],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]],
        );
        if (!is_int($value)) {
            throw new \RuntimeException('class_identity_rate_limit_setting_invalid');
        }

        return $value;
    }

    /** @return array{allowed: bool, state: string, retry_after: int} */
    private static function denied(string $state, int $retryAfter): array
    {
        return [
            'allowed' => false,
            'state' => $state,
            'retry_after' => max(1, min(86400, $retryAfter)),
        ];
    }

    private static function wipe(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
            return;
        }
        $value = str_repeat("\0", strlen($value));
    }
}
