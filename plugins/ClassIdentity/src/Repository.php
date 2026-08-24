<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Small prepared-query boundary for ClassIdentity-owned InnoDB tables.
 *
 * It intentionally does not wrap Piwigo's MyISAM user/group writes. Domain
 * services must perform those through the Core adapter and compensation saga.
 */
final class Repository
{
    private \mysqli $db;
    private string $prefix;
    private int $transactionDepth = 0;

    public function __construct(\mysqli $db, string $tablePrefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $tablePrefix)) {
            throw new \InvalidArgumentException('class_identity_invalid_table_prefix');
        }

        $this->db = $db;
        $this->prefix = $tablePrefix . 'class_identity_';
    }

    public static function fromPiwigo(): self
    {
        global $mysqli, $prefixeTable;

        if (!$mysqli instanceof \mysqli || !is_string($prefixeTable)) {
            throw new \RuntimeException('class_identity_database_unavailable');
        }
        if (!$mysqli->set_charset('utf8mb4')) {
            throw new \RuntimeException('class_identity_utf8mb4_connection_required');
        }

        return new self($mysqli, $prefixeTable);
    }

    public function table(string $suffix): string
    {
        if (!in_array($suffix, self::tableSuffixes(), true)) {
            throw new \InvalidArgumentException('class_identity_unknown_table');
        }

        return $this->prefix . $suffix;
    }

    /** @return array<string, mixed>|null */
    public function findPrincipalByPiwigoUserId(int $piwigoUserId): ?array
    {
        if ($piwigoUserId <= 0) {
            return null;
        }

        return $this->fetchOne(
            'SELECT `id`, `principal_type`, `system_role`, `account_id`, `piwigo_user_id`, `state`, `auth_epoch`, '
            . '`created_at`, `updated_at`, `frozen_at`, `disabled_at` FROM '
            . $this->quotedTable('principal') . ' WHERE `piwigo_user_id` = ? LIMIT 1',
            [$piwigoUserId],
        );
    }

    /**
     * Resolve, but do not authorize, the current principal graph.
     *
     * Callers must fail closed unless every applicable state/current-marker,
     * Core status and managed-group condition is valid.
     *
     * @return array<string, mixed>|null
     */
    public function findAuthorizationContextByPiwigoUserId(int $piwigoUserId): ?array
    {
        if ($piwigoUserId <= 0) {
            return null;
        }

        $principal = $this->quotedTable('principal');
        $account = $this->quotedTable('account');
        $seat = $this->quotedTable('seat');
        $identity = $this->quotedTable('identity');
        $roleGroup = $this->quotedTable('role_group');

        $row = $this->fetchOne(<<<SQL
SELECT
  p.`id` AS `principal_id`,
  p.`principal_type`,
  p.`system_role`,
  p.`state` AS `principal_state`,
  p.`auth_epoch` AS `principal_auth_epoch`,
  p.`piwigo_user_id`,
  a.`id` AS `account_id`,
  a.`state` AS `account_state`,
  a.`current_marker`,
  s.`id` AS `seat_id`,
  s.`seat_type`,
  s.`state` AS `seat_state`,
  i.`id` AS `identity_id`,
  i.`identity_type`,
  i.`state` AS `identity_state`,
  rg.`role_code` AS `managed_role_code`,
  rg.`piwigo_group_id` AS `expected_group_id`,
  rg.`expected_group_name`,
  rg.`state` AS `role_group_state`
FROM {$principal} p
LEFT JOIN {$account} a ON a.`id` = p.`account_id`
LEFT JOIN {$seat} s ON s.`id` = a.`seat_id`
LEFT JOIN {$identity} i ON i.`id` = s.`identity_id`
LEFT JOIN {$roleGroup} rg
  ON rg.`role_code` = CASE
    WHEN p.`principal_type` = 'SEAT_ACCOUNT' THEN s.`seat_type`
    ELSE p.`system_role`
  END
WHERE p.`piwigo_user_id` = ?
LIMIT 1
SQL, [$piwigoUserId]);

        if ($row === null) {
            return null;
        }

        foreach (
            [
                'principal_id',
                'principal_auth_epoch',
                'piwigo_user_id',
                'account_id',
                'current_marker',
                'seat_id',
                'identity_id',
                'expected_group_id',
            ] as $integerKey
        ) {
            if ($row[$integerKey] !== null) {
                $row[$integerKey] = (int) $row[$integerKey];
            }
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function findRoleGroup(string $roleCode): ?array
    {
        return $this->fetchOne(
            'SELECT `id`, `role_code`, `piwigo_group_id`, `expected_group_name`, `is_business_role`, `state` '
            . 'FROM ' . $this->quotedTable('role_group') . ' WHERE `role_code` = ? LIMIT 1',
            [$roleCode],
        );
    }

    /** @return array<string, mixed>|null */
    public function lockIdentityByRosterCode(string $rosterCode): ?array
    {
        $this->requireTransaction();

        return $this->fetchOne(
            'SELECT * FROM ' . $this->quotedTable('identity') . ' WHERE `roster_code` = ? FOR UPDATE',
            [$rosterCode],
        );
    }

    /** @return array<string, mixed>|null */
    public function lockSeatById(int $seatId): ?array
    {
        $this->requireTransaction();

        return $this->fetchOne(
            'SELECT * FROM ' . $this->quotedTable('seat') . ' WHERE `id` = ? FOR UPDATE',
            [$seatId],
        );
    }

    /** @return array<string, mixed>|null */
    public function lockTokenBySelectorHash(string $selectorHash): ?array
    {
        $this->requireTransaction();
        if (strlen($selectorHash) !== 32) {
            throw new \InvalidArgumentException('class_identity_invalid_selector_hash');
        }

        return $this->fetchOne(
            'SELECT * FROM ' . $this->quotedTable('token') . ' WHERE `selector_hash` = ? FOR UPDATE',
            [$selectorHash],
        );
    }

    /**
     * Atomically count one public Claim/Family Invite attempt in an existing
     * transaction and return the count for that fixed-window bucket.
     *
     * Only a domain-separated HMAC reaches this boundary. Raw IP addresses,
     * roster codes, selectors and tokens must never be passed as subjectHash.
     */
    public function incrementRateLimitBucket(
        string $scope,
        string $purpose,
        string $subjectHash,
        int $windowId,
        int $windowSeconds,
        string $expiresAtUtc,
    ): int {
        $this->requireTransaction();

        if (!in_array($scope, ['SOURCE_IP', 'SELECTOR', 'ROSTER'], true)) {
            throw new \InvalidArgumentException('class_identity_invalid_rate_limit_scope');
        }
        if (!in_array($purpose, ['CLAIM', 'FAMILY_INVITE'], true)) {
            throw new \InvalidArgumentException('class_identity_invalid_rate_limit_purpose');
        }
        if (strlen($subjectHash) !== 32) {
            throw new \InvalidArgumentException('class_identity_invalid_rate_limit_hash');
        }
        if ($windowId <= 0 || $windowSeconds < 60 || $windowSeconds > 86400) {
            throw new \InvalidArgumentException('class_identity_invalid_rate_limit_window');
        }
        if (!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/D', $expiresAtUtc)) {
            throw new \InvalidArgumentException('class_identity_invalid_rate_limit_expiry');
        }

        $table = $this->quotedTable('rate_limit_bucket');
        $this->execute(
            'INSERT INTO ' . $table . ' '
            . '(`scope`, `purpose`, `subject_hash`, `window_id`, `window_seconds`, `attempt_count`, `expires_at`) '
            . 'VALUES (?, ?, ?, ?, ?, 1, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`attempt_count` = LEAST(`attempt_count` + 1, 1000000000), '
            . '`expires_at` = GREATEST(`expires_at`, VALUES(`expires_at`)), '
            . '`updated_at` = UTC_TIMESTAMP(6)',
            [$scope, $purpose, $subjectHash, $windowId, $windowSeconds, $expiresAtUtc],
        );

        $row = $this->fetchOne(
            'SELECT `attempt_count` FROM ' . $table
            . ' WHERE `scope` = ? AND `purpose` = ? AND `subject_hash` = ? '
            . 'AND `window_id` = ? AND `window_seconds` = ? FOR UPDATE',
            [$scope, $purpose, $subjectHash, $windowId, $windowSeconds],
        );
        if ($row === null || !isset($row['attempt_count'])) {
            throw new \RuntimeException('class_identity_rate_limit_counter_unavailable');
        }

        $count = (int) $row['attempt_count'];
        if ($count < 1 || $count > 1000000000) {
            throw new \RuntimeException('class_identity_rate_limit_counter_invalid');
        }

        return $count;
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $depth = $this->transactionDepth;
        $savepoint = 'class_identity_sp_' . $depth;

        if ($depth === 0) {
            if (!$this->db->begin_transaction()) {
                throw new \RuntimeException('class_identity_transaction_begin_failed_' . $this->db->errno);
            }
        } else {
            $this->executeRaw('SAVEPOINT `' . $savepoint . '`');
        }
        $this->transactionDepth++;

        try {
            $result = $callback($this);
            $this->transactionDepth--;
            if ($depth === 0) {
                if (!$this->db->commit()) {
                    throw new \RuntimeException('class_identity_transaction_commit_failed_' . $this->db->errno);
                }
            } else {
                $this->executeRaw('RELEASE SAVEPOINT `' . $savepoint . '`');
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->transactionDepth = $depth;
            if ($depth === 0) {
                $this->db->rollback();
            } else {
                $this->executeRaw('ROLLBACK TO SAVEPOINT `' . $savepoint . '`');
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $rows = $this->fetchAll($sql, $parameters);

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $statement = $this->prepareAndExecute($sql, $parameters);
        try {
            $result = $statement->get_result();
            if (!$result instanceof \mysqli_result) {
                throw new \RuntimeException('class_identity_expected_result_set');
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $result->fetch_all(MYSQLI_ASSOC);

            return $rows;
        } finally {
            $statement->close();
        }
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->prepareAndExecute($sql, $parameters);
        try {
            return $statement->affected_rows;
        } finally {
            $statement->close();
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->db->insert_id;
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    private function quotedTable(string $suffix): string
    {
        return '`' . $this->table($suffix) . '`';
    }

    private function requireTransaction(): void
    {
        if (!$this->inTransaction()) {
            throw new \LogicException('class_identity_row_lock_requires_transaction');
        }
    }

    private function prepareAndExecute(string $sql, array $parameters): \mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if (!$statement instanceof \mysqli_stmt) {
            throw new \RuntimeException('class_identity_query_prepare_failed_' . $this->db->errno);
        }

        try {
            // PHP 8.1+ mysqli accepts a positional value list. No value is ever
            // interpolated into SQL; identifiers come only from table().
            if (!$statement->execute($parameters === [] ? null : array_values($parameters))) {
                throw new \RuntimeException('class_identity_query_execute_failed_' . $statement->errno);
            }
        } catch (\Throwable $exception) {
            $statement->close();
            throw $exception;
        }

        return $statement;
    }

    private function executeRaw(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new \RuntimeException('class_identity_query_failed_' . $this->db->errno);
        }
    }

    /** @return list<string> */
    private static function tableSuffixes(): array
    {
        return [
            'migration',
            'identity',
            'seat',
            'account',
            'principal',
            'token',
            'operation',
            'audit_event',
            'role_group',
            'rate_limit_bucket',
            'submission',
            'archive_image',
            'photo',
            'person',
            'person_merge',
            'person_photo_rule',
            'album',
            'spotlight',
            'photo_source',
            'photo_duplicate',
            'batch_operation',
            'batch_operation_item',
            'native_source_epoch',
            'read_projection',
            'read_photo',
        ];
    }
}
