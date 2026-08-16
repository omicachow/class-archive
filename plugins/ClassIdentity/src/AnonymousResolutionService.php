<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use RuntimeException;

/**
 * Audited SYSTEM_ADMIN-only boundary for reversing a displayed anonymous alias.
 *
 * HMAC aliases are not encrypted and therefore cannot be decrypted. Resolution
 * enumerates the small Class Archive Anonymous-seat set inside the trusted
 * backend, derives each candidate for the supplied context and returns a
 * mapping only after its audit event has been durably appended.
 */
final class AnonymousResolutionService
{
    public function __construct(
        private Repository $repository,
        private Audit $audit,
    ) {
    }

    public static function fromPiwigo(): self
    {
        $repository = Repository::fromPiwigo();
        return new self($repository, new Audit($repository));
    }

    /**
     * @return array{
     *   alias:string,context_type:string,context_id:int,identity_id:int,
     *   classmate_id:string,real_name:string,seat_id:int,account_id:int,
     *   principal_id:int,piwigo_user_id:int,seat_state:string,
     *   account_state:string,principal_state:string
     * }
     */
    public function resolveAlias(
        int $adminUserId,
        string $contextType,
        int $contextId,
        string $alias,
        string $reason,
    ): array {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new RuntimeException('class_identity_anonymous_resolution_reason_required');
        }

        $admin = Access::resolveAuthorizationContext($adminUserId);
        if (($admin['role'] ?? null) !== Access::ROLE_SYSTEM_ADMIN
            || ($admin['principal_type'] ?? null) !== Access::PRINCIPAL_SYSTEM_ACCOUNT
            || (int) ($admin['principal_id'] ?? 0) <= 0
        ) {
            throw new RuntimeException('class_identity_system_admin_required');
        }

        $rows = $this->anonymousMappings();
        $collisionCandidates = array_map(
            static fn(array $row): array => [
                'subject' => (string) $row['pseudonym_subject'],
                'key_version' => (int) $row['pseudonym_key_version'],
            ],
            $rows,
        );

        $matches = [];
        foreach ($rows as $row) {
            $version = (int) ($row['pseudonym_key_version'] ?? 0);
            $secret = $this->pseudonymSecret($version);
            try {
                $candidate = AnonymousPresenter::deriveAlias(
                    $secret,
                    $contextType,
                    $contextId,
                    (string) ($row['pseudonym_subject'] ?? ''),
                    $version,
                    $collisionCandidates,
                );
            } finally {
                if (is_string($secret)) {
                    $secret = str_repeat("\0", strlen($secret));
                }
                unset($secret);
            }
            if (hash_equals($candidate, $alias)) {
                $matches[] = $row;
            }
        }

        if (count($matches) !== 1) {
            // Public callers never reach this service. The message is still
            // deliberately generic so an accidentally exposed controller does
            // not become an alias-enumeration oracle.
            throw new RuntimeException('class_identity_anonymous_resolution_failed');
        }

        $row = $matches[0];
        $identityId = (int) $row['identity_id'];
        $seatId = (int) $row['seat_id'];
        $accountId = (int) $row['account_id'];
        $principalId = (int) $row['principal_id'];
        $piwigoUserId = (int) $row['piwigo_user_id'];
        if (min($identityId, $seatId, $accountId, $principalId, $piwigoUserId) <= 0) {
            throw new RuntimeException('class_identity_anonymous_resolution_failed');
        }

        // Fail closed: no mapping is returned unless this sensitive view is
        // first recorded in the append-only audit table.
        $this->audit->append([
            'actor_principal_id' => (int) $admin['principal_id'],
            'actor_user_id' => $adminUserId,
            'actor_kind' => 'SYSTEM_ADMIN',
            'action' => 'ANONYMOUS_RESOLVE',
            'target_type' => 'ANONYMOUS_ALIAS',
            'target_id' => strtoupper($contextType) . ':' . $contextId,
            'target_identity_id' => $identityId,
            'target_seat_id' => $seatId,
            'target_account_id' => $accountId,
            'target_principal_id' => $principalId,
            'new_value' => [
                'seat_type' => 'ANONYMOUS',
                'result' => 'RESOLVED',
            ],
            'reason' => $reason,
            'result' => 'SUCCESS',
        ]);

        return [
            'alias' => $alias,
            'context_type' => strtoupper($contextType),
            'context_id' => $contextId,
            'identity_id' => $identityId,
            'classmate_id' => (string) $row['roster_code'],
            'real_name' => (string) $row['real_name'],
            'seat_id' => $seatId,
            'account_id' => $accountId,
            'principal_id' => $principalId,
            'piwigo_user_id' => $piwigoUserId,
            'seat_state' => (string) $row['seat_state'],
            'account_state' => (string) $row['account_state'],
            'principal_state' => (string) $row['principal_state'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function anonymousMappings(): array
    {
        return $this->repository->fetchAll(
            'SELECT i.`id` AS identity_id, i.`roster_code`, i.`real_name`, '
            . 's.`id` AS seat_id, s.`state` AS seat_state, s.`pseudonym_subject`, '
            . 'a.`id` AS account_id, a.`state` AS account_state, a.`pseudonym_key_version`, '
            . 'p.`id` AS principal_id, p.`piwigo_user_id`, p.`state` AS principal_state '
            . 'FROM `' . $this->repository->table('seat') . '` s '
            . 'INNER JOIN `' . $this->repository->table('identity') . '` i ON i.`id` = s.`identity_id` '
            . 'INNER JOIN `' . $this->repository->table('account') . '` a ON a.`seat_id` = s.`id` '
            . 'INNER JOIN `' . $this->repository->table('principal') . '` p ON p.`account_id` = a.`id` '
            . "WHERE s.`seat_type` = 'ANONYMOUS' ORDER BY s.`id`, a.`id`",
        );
    }

    private function pseudonymSecret(int $version): string
    {
        if ($version <= 0) {
            throw new RuntimeException('class_identity_anonymous_resolution_failed');
        }
        $name = $version === 1
            ? 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET'
            : 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET_V' . $version;
        $secret = getenv($name);
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('class_identity_anonymous_resolution_failed');
        }
        return $secret;
    }
}
