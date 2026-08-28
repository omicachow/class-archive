<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Server-owned fairness state for simultaneous active Spotlights.
 *
 * A Spotlight remains active for its own 24-hour lifecycle.  This narrower
 * service only chooses which active card is the hero for a bounded interval
 * and returns the complete ordered candidate set for snapshot publication.
 * It has no browser/principal input and must only be called by a maintenance
 * or build path, never from a Gateway GET.
 *
 * `spotlight_rotation_state` is the mutable source of truth.  The existing
 * `collection_maintenance_state` row is deliberately only a transaction
 * lease/watermark; audit rows are evidence, not a query-time state store.
 */
final class SpotlightRotationService
{
    public const SCOPE_FULL = 'FULL';
    public const SCOPE_HERITAGE = 'HERITAGE';

    private const ROTATION_INTERVAL_SECONDS = 3600;
    private const MAX_CANDIDATES = 64;

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Advance a due server-side hero selection and return all simultaneous
     * candidates with the durable hero first.  The current time is created on
     * the application server; a browser cannot select, advance, or extend it.
     *
     * @param list<string> $candidateSpotlightIds
     * @return array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,nextRotationAt:?string,revision:string,changed:bool}
     */
    public function advanceForMaintenance(string $scope, array $candidateSpotlightIds): array
    {
        return self::publicResult($this->advanceAt($scope, $candidateSpotlightIds, self::serverNow()));
    }

    /**
     * Test-only clock seam.  Production callers must use
     * advanceForMaintenance(), which takes no caller-supplied time.
     *
     * @param list<string> $candidateSpotlightIds
     * @return array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,nextRotationAt:?string,revision:string,changed:bool}
     */
    public function advanceAtForSyntheticTest(
        string $scope,
        array $candidateSpotlightIds,
        \DateTimeImmutable $now,
    ): array {
        return self::publicResult($this->advanceAt($scope, $candidateSpotlightIds, $now));
    }

    /**
     * Pure deterministic selection seam used by the synthetic contract test.
     * It does not write, read a clock, inspect a principal, or perform policy.
     *
     * @param list<string> $candidateSpotlightIds
     * @param array{heroSpotlightId:?string,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,candidateDigest?:string,revision?:string}|null $previous
     * @return array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool,candidateDigest:string}
     */
    public static function planForSyntheticTest(
        string $scope,
        array $candidateSpotlightIds,
        ?array $previous,
        \DateTimeImmutable $now,
    ): array {
        $scope = self::normalizeScope($scope);
        $candidateIds = self::normalizeCandidateIds($candidateSpotlightIds);
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $candidateDigest = self::candidateDigest($scope, $candidateIds);
        $previous = self::normalizePrevious($previous);

        if ($candidateIds === []) {
            $previousHero = $previous['heroSpotlightId'] ?? null;
            $previousDigest = $previous['candidateDigest'] ?? null;
            $previousNext = self::parsePersistedUtc($previous['nextRotationAt'] ?? null);
            $candidateChanged = !is_string($previousDigest) || !hash_equals($previousDigest, $candidateDigest);
            $due = $previousNext === null || $previousNext <= $now;
            // Empty scopes still have a server-owned checkpoint.  Crucially,
            // an unchanged, not-yet-due empty scope must retain its persisted
            // checkpoint: recomputing a fresh timestamp while reporting
            // `changed=false` would produce a revision that does not match
            // the stored state and make all Collections snapshots fail closed.
            $changed = $previous === null || $previousHero !== null || $candidateChanged || $due;
            $emptyNext = !$changed && $previousNext instanceof \DateTimeImmutable
                ? $previousNext
                : $now->add(new \DateInterval('PT' . self::ROTATION_INTERVAL_SECONDS . 'S'));
            return self::stateResult($scope, null, [], 0, null, $emptyNext, $candidateDigest, $changed);
        }

        $candidateSet = array_fill_keys($candidateIds, true);
        $previousHero = $previous['heroSpotlightId'] ?? null;
        $previousDigest = $previous['candidateDigest'] ?? null;
        $previousNext = self::parsePersistedUtc($previous['nextRotationAt'] ?? null);
        $heroStillVisible = is_string($previousHero) && isset($candidateSet[$previousHero]);
        $candidateChanged = !is_string($previousDigest) || !hash_equals($previousDigest, $candidateDigest);
        $due = $previousNext === null || $previousNext <= $now;

        // An unchanged set holds its current hero until the server deadline.
        // A changing set also holds a still-valid hero until that deadline,
        // avoiding a new card repeatedly displacing the current display.
        $mustAdvance = !$heroStillVisible || $due;
        if (!$mustAdvance && $previous !== null) {
            return self::stateResult(
                $scope,
                $previousHero,
                self::orderedAfterHero($candidateIds, $previousHero),
                $previous['displayCount'],
                self::parsePersistedUtc($previous['lastRotatedAt']),
                $previousNext,
                $candidateDigest,
                $candidateChanged,
            );
        }

        $hero = self::nextCandidate($candidateIds, $previousHero);
        $displayCount = $previous === null ? 1 : self::incrementDisplayCount($previous['displayCount']);
        $next = $now->add(new \DateInterval('PT' . self::ROTATION_INTERVAL_SECONDS . 'S'));
        return self::stateResult(
            $scope,
            $hero,
            self::orderedAfterHero($candidateIds, $hero),
            $displayCount,
            $now,
            $next,
            $candidateDigest,
            true,
        );
    }

    /**
     * Query-only state accessor for a builder after a maintenance advance.
     * A missing/mismatched/expired state is an error: callers must schedule a
     * build rather than falling back to epoch modulo or a live source read.
     *
     * @param list<string> $candidateSpotlightIds
     * @return array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool}
     */
    public function stateForPublishedCandidates(string $scope, array $candidateSpotlightIds): array
    {
        $scope = self::normalizeScope($scope);
        $candidateIds = self::normalizeCandidateIds($candidateSpotlightIds);
        $row = $this->repository->fetchOne(
            'SELECT `hero_spotlight_id`,`candidate_digest`,`display_count`,`last_rotated_at`,`next_rotation_at`,`revision` '
                . 'FROM `' . DomainSupport::table($this->repository, 'spotlight_rotation_state') . '` WHERE `scope`=? LIMIT 1',
            [$scope],
        );
        if ($row === null) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_unavailable');
        }
        $previous = self::stateFromRow($row);
        $expectedDigest = self::candidateDigest($scope, $candidateIds);
        if (!hash_equals($previous['candidateDigest'], $expectedDigest)) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_stale');
        }
        if ($candidateIds === []) {
            if ($previous['heroSpotlightId'] !== null || $previous['displayCount'] !== 0 || $previous['nextRotationAt'] === null) {
                throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
            }
            $result = self::stateResult(
                $scope,
                null,
                [],
                0,
                null,
                self::parsePersistedUtc($previous['nextRotationAt']),
                $expectedDigest,
                false,
            );
            if (!hash_equals($previous['revision'], $result['revision'])) {
                throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
            }
            return self::publicResult($result);
        }
        if ($previous['heroSpotlightId'] === null || !in_array($previous['heroSpotlightId'], $candidateIds, true)
            || $previous['nextRotationAt'] === null) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
        }
        // A builder must never silently publish an overdue hero.  It may read
        // the server clock, but it must not mutate state from a GET path; the
        // maintenance path is responsible for advancing the checkpoint first.
        $next = self::parsePersistedUtc($previous['nextRotationAt']);
        if (!$next instanceof \DateTimeImmutable || $next <= self::serverNow()) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_due');
        }
        $result = self::stateResult(
            $scope,
            $previous['heroSpotlightId'],
            self::orderedAfterHero($candidateIds, $previous['heroSpotlightId']),
            $previous['displayCount'],
            self::parsePersistedUtc($previous['lastRotatedAt']),
            $next,
            $expectedDigest,
            false,
        );
        if (!hash_equals($previous['revision'], $result['revision'])) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
        }
        return self::publicResult($result);
    }

    /**
     * @param list<string> $candidateSpotlightIds
     * @return array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool}
     */
    private function advanceAt(string $scope, array $candidateSpotlightIds, \DateTimeImmutable $now): array
    {
        $scope = self::normalizeScope($scope);
        $candidateIds = self::normalizeCandidateIds($candidateSpotlightIds);
        $now = $now->setTimezone(new \DateTimeZone('UTC'));

        return $this->repository->transaction(function (Repository $repository) use ($scope, $candidateIds, $now): array {
            $maintenanceTable = DomainSupport::table($repository, 'collection_maintenance_state');
            $rotationTable = DomainSupport::table($repository, 'spotlight_rotation_state');
            $maintenanceKey = self::maintenanceKey($scope);
            $candidateDigest = self::candidateDigest($scope, $candidateIds);

            // The upsert establishes a stable, dedicated row.  It is then
            // locked in the same transaction; no GET path shares this key.
            $repository->execute(
                'INSERT INTO `' . $maintenanceTable . '` '
                    . '(`maintenance_key`,`state`,`created_at`,`updated_at`) '
                    . "VALUES (?, 'IDLE', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
                    . 'ON DUPLICATE KEY UPDATE `maintenance_key`=VALUES(`maintenance_key`)',
                [$maintenanceKey],
            );
            $maintenance = $repository->fetchOne(
                'SELECT `state` FROM `' . $maintenanceTable . '` WHERE `maintenance_key`=? FOR UPDATE',
                [$maintenanceKey],
            );
            if ($maintenance === null || !in_array((string) ($maintenance['state'] ?? ''), ['IDLE', 'COMPLETE', 'FAILED'], true)) {
                throw new \RuntimeException('class_archive_spotlight_rotation_lease_unavailable');
            }
            $repository->execute(
                'UPDATE `' . $maintenanceTable . '` SET `state`=?,`last_input_revision`=?,`last_snapshot_id`=NULL,'
                    . '`started_at`=UTC_TIMESTAMP(6),`completed_at`=NULL,`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `maintenance_key`=?',
                ['RUNNING', $candidateDigest, $maintenanceKey],
            );

            $row = $repository->fetchOne(
                'SELECT `hero_spotlight_id`,`candidate_digest`,`display_count`,`last_rotated_at`,`next_rotation_at`,`revision` '
                    . 'FROM `' . $rotationTable . '` WHERE `scope`=? FOR UPDATE',
                [$scope],
            );
            $previous = $row === null ? null : self::stateFromRow($row);
            $plan = self::planForSyntheticTest($scope, $candidateIds, $previous, $now);

            if ($row === null) {
                $repository->execute(
                    'INSERT INTO `' . $rotationTable . '` '
                        . '(`scope`,`hero_spotlight_id`,`candidate_digest`,`display_count`,`last_rotated_at`,`next_rotation_at`,`revision`,`created_at`,`updated_at`) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    self::stateInsertParameters($plan),
                );
            } elseif (($plan['changed'] ?? false) === true) {
                $repository->execute(
                    'UPDATE `' . $rotationTable . '` SET `hero_spotlight_id`=?,`candidate_digest`=?,`display_count`=?,`last_rotated_at`=?,`next_rotation_at`=?,`revision`=?,`updated_at`=UTC_TIMESTAMP(6) '
                        . 'WHERE `scope`=?',
                    self::stateUpdateParameters($plan, $scope),
                );
            }

            if (($plan['changed'] ?? false) === true) {
                $this->appendAudit($repository, $scope, $plan, $candidateIds);
            }
            $repository->execute(
                'UPDATE `' . $maintenanceTable . '` SET `state`=?,`last_input_revision`=?,`last_snapshot_id`=NULL,'
                    . '`completed_at`=UTC_TIMESTAMP(6),`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `maintenance_key`=? AND `state`=?',
                ['COMPLETE', $candidateDigest, $maintenanceKey, 'RUNNING'],
            );
            return $plan;
        });
    }

    /**
     * @param array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool,candidateDigest:string} $plan
     * @return list<mixed>
     */
    private static function stateInsertParameters(array $plan): array
    {
        return [
            $plan['scope'],
            $plan['heroSpotlightId'] === null ? null : self::idToBinary($plan['heroSpotlightId']),
            $plan['candidateDigest'],
            $plan['displayCount'],
            $plan['lastRotatedAt'],
            $plan['nextRotationAt'],
            self::hexToBinaryRevision($plan['revision']),
        ];
    }

    /**
     * @param array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool,candidateDigest:string} $plan
     * @return list<mixed>
     */
    private static function stateUpdateParameters(array $plan, string $scope): array
    {
        return [
            $plan['heroSpotlightId'] === null ? null : self::idToBinary($plan['heroSpotlightId']),
            $plan['candidateDigest'],
            $plan['displayCount'],
            $plan['lastRotatedAt'],
            $plan['nextRotationAt'],
            self::hexToBinaryRevision($plan['revision']),
            $scope,
        ];
    }

    /**
     * @param array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool,candidateDigest:string} $plan
     * @param list<string> $candidateIds
     */
    private function appendAudit(Repository $repository, string $scope, array $plan, array $candidateIds): void
    {
        $event = [
            'actor_kind' => 'SYSTEM',
            'action' => $plan['heroSpotlightId'] === null ? 'SPOTLIGHT_ROTATION_CLEAR' : 'SPOTLIGHT_ROTATION_ADVANCE',
            'target_type' => 'SPOTLIGHT_ROTATION',
            'target_id' => $scope,
            'new_value' => [
                'scope' => $scope,
                'spotlight_id' => $plan['heroSpotlightId'],
                'display_count' => $plan['displayCount'],
                'candidate_count' => count($candidateIds),
                'next_rotation_at' => $plan['nextRotationAt'],
                'rotation_interval_seconds' => self::ROTATION_INTERVAL_SECONDS,
                'rotation_state' => $plan['heroSpotlightId'] === null ? 'EMPTY' : 'ACTIVE',
            ],
            'result' => 'SUCCESS',
        ];
        (new Audit($repository))->append($event);
    }

    /** @param array<string,mixed>|null $previous @return array{heroSpotlightId:?string,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,candidateDigest:string,revision:string}|null */
    private static function normalizePrevious(?array $previous): ?array
    {
        if ($previous === null) {
            return null;
        }
        $hero = $previous['heroSpotlightId'] ?? null;
        if ($hero !== null && !is_string($hero)) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
        }
        if (is_string($hero)) {
            $hero = self::normalizeSpotlightId($hero);
        }
        $count = $previous['displayCount'] ?? null;
        if (!is_int($count) || $count < 0 || $count > PHP_INT_MAX - 1) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
        }
        $last = $previous['lastRotatedAt'] ?? null;
        if ($last !== null && (!is_string($last) || self::parsePersistedUtc($last) === null)) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
        }
        $next = $previous['nextRotationAt'] ?? null;
        if ($next !== null && (!is_string($next) || self::parsePersistedUtc($next) === null)) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
        }
        $digest = $previous['candidateDigest'] ?? null;
        if ($digest !== null && (!is_string($digest) || strlen($digest) !== 32)) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
        }
        $revision = $previous['revision'] ?? null;
        if ($revision !== null && (!is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision))) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
        }
        if ($hero === null && ($count !== 0 || $last !== null)) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
        }
        if ($hero !== null && ($count < 1 || $last === null || $next === null)) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
        }
        if ($last !== null && $next !== null) {
            $parsedLast = self::parsePersistedUtc($last);
            $parsedNext = self::parsePersistedUtc($next);
            if (!$parsedLast instanceof \DateTimeImmutable || !$parsedNext instanceof \DateTimeImmutable || $parsedNext <= $parsedLast) {
                throw new \InvalidArgumentException('class_archive_spotlight_rotation_previous_invalid');
            }
        }
        return [
            'heroSpotlightId' => $hero,
            'displayCount' => $count,
            'lastRotatedAt' => $last,
            'nextRotationAt' => $next,
            'candidateDigest' => $digest ?? str_repeat("\0", 32),
            'revision' => $revision ?? str_repeat('0', 64),
        ];
    }

    /** @param array<string,mixed> $row @return array{heroSpotlightId:?string,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,candidateDigest:string,revision:string} */
    private static function stateFromRow(array $row): array
    {
        $heroBinary = $row['hero_spotlight_id'] ?? null;
        if ($heroBinary !== null && (!is_string($heroBinary) || strlen($heroBinary) !== 16)) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
        }
        $hero = $heroBinary === null ? null : self::binaryToId($heroBinary);
        $digest = $row['candidate_digest'] ?? null;
        $revision = $row['revision'] ?? null;
        $count = $row['display_count'] ?? null;
        if (!is_string($digest) || strlen($digest) !== 32
            || !is_string($revision) || strlen($revision) !== 32
            || (!is_int($count) && !is_numeric($count))
        ) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
        }
        $displayCount = (int) $count;
        if ($displayCount < 0 || $displayCount > PHP_INT_MAX - 1) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
        }
        $last = $row['last_rotated_at'] ?? null;
        $next = $row['next_rotation_at'] ?? null;
        if ($next === null
            || ($hero === null && ($last !== null || $displayCount !== 0))
            || ($hero !== null && ($last === null || $displayCount < 1))
        ) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
        }
        if ($last !== null && (!is_string($last) || self::parsePersistedUtc($last) === null)) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
        }
        if ($next !== null && (!is_string($next) || self::parsePersistedUtc($next) === null)) {
            throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
        }
        if ($last !== null && $next !== null) {
            $parsedLast = self::parsePersistedUtc($last);
            $parsedNext = self::parsePersistedUtc($next);
            if (!$parsedLast instanceof \DateTimeImmutable || !$parsedNext instanceof \DateTimeImmutable || $parsedNext <= $parsedLast) {
                throw new \RuntimeException('class_archive_spotlight_rotation_state_invalid');
            }
        }
        return [
            'heroSpotlightId' => $hero,
            'displayCount' => $displayCount,
            'lastRotatedAt' => $last,
            'nextRotationAt' => $next,
            'candidateDigest' => $digest,
            'revision' => bin2hex($revision),
        ];
    }

    /** @param list<string> $candidateIds @return array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool,candidateDigest:string} */
    private static function stateResult(
        string $scope,
        ?string $hero,
        array $candidateIds,
        int $displayCount,
        ?\DateTimeImmutable $last,
        ?\DateTimeImmutable $next,
        string $candidateDigest,
        bool $changed,
    ): array {
        $nextValue = $next === null ? null : self::formatUtc($next);
        $lastValue = $last === null ? null : self::formatUtc($last);
        $revision = hash(
            'sha256',
            "class-archive/spotlight-rotation/state/v1\0{$scope}\0"
                . $candidateDigest . "\0" . ($hero ?? '') . "\0{$displayCount}\0" . ($lastValue ?? '') . "\0" . ($nextValue ?? ''),
        );
        return [
            'scope' => $scope,
            'heroSpotlightId' => $hero,
            'orderedSpotlightIds' => $candidateIds,
            'displayCount' => $displayCount,
            'lastRotatedAt' => $lastValue,
            'nextRotationAt' => $nextValue,
            'revision' => $revision,
            'changed' => $changed,
            'candidateDigest' => $candidateDigest,
        ];
    }

    /**
     * Strip raw digest material from all callable service results.  The
     * builder only needs the deterministic order and revision; digest bytes
     * stay inside the transactional persistence boundary.
     *
     * @param array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool,candidateDigest:string} $plan
     * @return array{scope:string,heroSpotlightId:?string,orderedSpotlightIds:list<string>,displayCount:int,lastRotatedAt:?string,nextRotationAt:?string,revision:string,changed:bool}
     */
    private static function publicResult(array $plan): array
    {
        unset($plan['candidateDigest']);
        return $plan;
    }

    /** @param list<string> $candidateIds */
    private static function candidateDigest(string $scope, array $candidateIds): string
    {
        return hash(
            'sha256',
            "class-archive/spotlight-rotation/candidates/v1\0{$scope}\0" . implode("\0", $candidateIds),
            true,
        );
    }

    /** @param list<string> $candidateIds @return list<string> */
    private static function orderedAfterHero(array $candidateIds, string $hero): array
    {
        $offset = array_search($hero, $candidateIds, true);
        if (!is_int($offset)) {
            throw new \RuntimeException('class_archive_spotlight_rotation_hero_not_candidate');
        }
        return $offset === 0
            ? $candidateIds
            : array_merge(array_slice($candidateIds, $offset), array_slice($candidateIds, 0, $offset));
    }

    /** @param list<string> $candidateIds */
    private static function nextCandidate(array $candidateIds, ?string $previousHero): string
    {
        if ($previousHero === null) {
            return $candidateIds[0];
        }
        foreach ($candidateIds as $candidate) {
            if (strcmp($candidate, $previousHero) > 0) {
                return $candidate;
            }
        }
        return $candidateIds[0];
    }

    private static function incrementDisplayCount(int $count): int
    {
        if ($count < 0 || $count >= PHP_INT_MAX) {
            throw new \RuntimeException('class_archive_spotlight_rotation_display_count_invalid');
        }
        return $count + 1;
    }

    /** @param list<string> $candidateSpotlightIds @return list<string> */
    private static function normalizeCandidateIds(array $candidateSpotlightIds): array
    {
        if (!array_is_list($candidateSpotlightIds) || count($candidateSpotlightIds) > self::MAX_CANDIDATES) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_candidates_invalid');
        }
        $normalized = [];
        foreach ($candidateSpotlightIds as $candidateId) {
            if (!is_string($candidateId)) {
                throw new \InvalidArgumentException('class_archive_spotlight_rotation_candidate_invalid');
            }
            $candidateId = self::normalizeSpotlightId($candidateId);
            if (isset($normalized[$candidateId])) {
                throw new \InvalidArgumentException('class_archive_spotlight_rotation_candidate_duplicate');
            }
            $normalized[$candidateId] = true;
        }
        $ids = array_keys($normalized);
        sort($ids, SORT_STRING);
        return $ids;
    }

    private static function normalizeSpotlightId(string $value): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) !== 1) {
            throw new \InvalidArgumentException('class_archive_spotlight_rotation_candidate_invalid');
        }
        return strtolower($value);
    }

    private static function normalizeScope(string $scope): string
    {
        return match ($scope) {
            self::SCOPE_FULL, self::SCOPE_HERITAGE => $scope,
            default => throw new \InvalidArgumentException('class_archive_spotlight_rotation_scope_invalid'),
        };
    }

    private static function maintenanceKey(string $scope): string
    {
        return 'SPOTLIGHT_ROTATION_' . $scope;
    }

    private static function serverNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function formatUtc(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parsePersistedUtc(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $zone = new \DateTimeZone('UTC');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, $zone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || !hash_equals($value, $parsed->format('Y-m-d H:i:s.u'))
        ) {
            return null;
        }
        return $parsed;
    }

    private static function idToBinary(string $value): string
    {
        $id = self::normalizeSpotlightId($value);
        $binary = hex2bin(str_replace('-', '', $id));
        if (!is_string($binary) || strlen($binary) !== 16) {
            throw new \RuntimeException('class_archive_spotlight_rotation_id_binary_invalid');
        }
        return $binary;
    }

    private static function binaryToId(string $value): string
    {
        if (strlen($value) !== 16) {
            throw new \RuntimeException('class_archive_spotlight_rotation_id_binary_invalid');
        }
        $hex = bin2hex($value);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private static function hexToBinaryRevision(string $value): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \RuntimeException('class_archive_spotlight_rotation_revision_invalid');
        }
        $binary = hex2bin($value);
        if (!is_string($binary) || strlen($binary) !== 32) {
            throw new \RuntimeException('class_archive_spotlight_rotation_revision_invalid');
        }
        return $binary;
    }
}
