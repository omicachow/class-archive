<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Forward-only ClassIdentity schema manager.
 *
 * Piwigo's plugin version is deliberately not used as migration state. Each
 * migration is recorded with a checksum in our own InnoDB ledger, so a retry
 * after MariaDB's implicit DDL commits resumes from the database itself.
 */
final class Schema
{
    public const CURRENT_VERSION = 4;

    private const COLLATION = 'utf8mb4_unicode_ci';

    private \mysqli $db;
    private string $prefix;
    private string $pluginVersion;

    public function __construct(\mysqli $db, string $tablePrefix, string $pluginVersion)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $tablePrefix)) {
            throw new \InvalidArgumentException('class_identity_invalid_table_prefix');
        }
        if ($pluginVersion === '' || strlen($pluginVersion) > 32) {
            throw new \InvalidArgumentException('class_identity_invalid_plugin_version');
        }

        $this->db = $db;
        $this->prefix = $tablePrefix . 'class_identity_';
        $this->pluginVersion = $pluginVersion;
    }

    public static function fromPiwigo(string $pluginVersion): self
    {
        global $mysqli, $prefixeTable;

        if (!$mysqli instanceof \mysqli || !is_string($prefixeTable)) {
            throw new \RuntimeException('class_identity_database_unavailable');
        }
        if (!$mysqli->set_charset('utf8mb4')) {
            throw new \RuntimeException('class_identity_utf8mb4_connection_required');
        }

        return new self($mysqli, $prefixeTable, $pluginVersion);
    }

    public function migrate(): void
    {
        $this->ensureMigrationLedger();
        $lockName = 'class_identity_schema_' . hash('sha256', $this->prefix);

        if (!$this->acquireAdvisoryLock($lockName)) {
            throw new \RuntimeException('class_identity_migration_lock_timeout');
        }

        try {
            foreach ($this->migrations() as $version => $migration) {
                $checksum = hash(
                    'sha256',
                    $version . "\0" . $migration['name'] . "\0" . $migration['signature'],
                    true,
                );
                $applied = $this->findAppliedMigration($version);

                if ($applied !== null) {
                    if (
                        $applied['migration_name'] !== $migration['name']
                        || !hash_equals($checksum, $applied['checksum'])
                    ) {
                        throw new \RuntimeException('class_identity_migration_checksum_mismatch_' . $version);
                    }
                    continue;
                }

                // Every migration is inspect/create/assert based. If a prior
                // attempt stopped after an implicit DDL commit, rerunning the
                // same method safely converges before the ledger row is added.
                $method = $migration['method'];
                $this->{$method}();
                $this->recordMigration($version, $migration['name'], $checksum);
            }

            $this->assertCurrentSchema();
        } finally {
            $this->releaseAdvisoryLock($lockName);
        }
    }

    /**
     * Read-only runtime attestation used by System Health.
     *
     * Unlike migrate(), this method never creates or repairs an object. Every
     * expected ledger checksum and current schema assertion must already be
     * present or the caller receives a fail-closed exception.
     */
    public function verifyCurrent(): void
    {
        $migrations = $this->migrations();
        foreach ($migrations as $version => $migration) {
            $expectedChecksum = hash(
                'sha256',
                $version . "\0" . $migration['name'] . "\0" . $migration['signature'],
                true,
            );
            $applied = $this->findAppliedMigration($version);
            if (
                $applied === null
                || $applied['migration_name'] !== $migration['name']
                || !hash_equals($expectedChecksum, $applied['checksum'])
            ) {
                throw new \RuntimeException('class_identity_migration_attestation_failed_' . $version);
            }
        }

        $result = $this->db->query(
            'SELECT COUNT(*) AS migration_count, COALESCE(MAX(version), 0) AS max_version '
            . 'FROM ' . $this->quotedTable('migration')
        );
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('class_identity_migration_attestation_unavailable');
        }
        try {
            $row = $result->fetch_assoc();
        } finally {
            $result->free();
        }
        if (
            !is_array($row)
            || (int) ($row['migration_count'] ?? -1) !== count($migrations)
            || (int) ($row['max_version'] ?? 0) !== self::CURRENT_VERSION
        ) {
            throw new \RuntimeException('class_identity_migration_ledger_drift');
        }

        $this->assertCurrentSchema();
    }

    public function table(string $suffix): string
    {
        if (!in_array($suffix, self::tableSuffixes(), true)) {
            throw new \InvalidArgumentException('class_identity_unknown_table');
        }

        return $this->prefix . $suffix;
    }

    /**
     * @return array<int, array{name: string, signature: string, method: string}>
     */
    private function migrations(): array
    {
        return [
            1 => [
                'name' => '0001_identity_seat_account_principal',
                'signature' => 'v2:identity-seat-singletons-account-principal:innodb:utf8mb4:system-role-xor',
                'method' => 'migrationIdentityAndPrincipals',
            ],
            2 => [
                'name' => '0002_operation_token_audit',
                'signature' => 'v2:operation-token-audit:nullable-seat-principal-purpose-check:target-generation-unique:account-operation-fk',
                'method' => 'migrationOperationsTokensAndAudit',
            ],
            3 => [
                'name' => '0003_role_group_projection',
                'signature' => 'v1:role-group:external-piwigo-group-projection',
                'method' => 'migrationRoleGroups',
            ],
            4 => [
                'name' => '0004_public_claim_rate_limit',
                'signature' => 'v1:fixed-window:hmac-subject:source-selector-roster:atomic-counter:innodb:utf8mb4',
                'method' => 'migrationPublicClaimRateLimit',
            ],
        ];
    }

    private function migrationIdentityAndPrincipals(): void
    {
        $identity = $this->quotedTable('identity');
        $seat = $this->quotedTable('seat');
        $account = $this->quotedTable('account');
        $principal = $this->quotedTable('principal');

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$identity} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `roster_code` VARCHAR(64) NOT NULL,
  `identity_type` VARCHAR(16) NOT NULL,
  `real_name` VARCHAR(190) NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `seat_template_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `frozen_at` DATETIME(6) NULL,
  `retired_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_identity_roster` (`roster_code`),
  KEY `idx_ci_identity_type_state` (`identity_type`, `state`),
  CONSTRAINT `chk_ci_identity_type` CHECK (`identity_type` IN ('CLASSMATE', 'TEACHER')),
  CONSTRAINT `chk_ci_identity_state` CHECK (`state` IN ('ACTIVE', 'FROZEN', 'RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$seat} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identity_id` BIGINT UNSIGNED NOT NULL,
  `ordinal` SMALLINT UNSIGNED NOT NULL,
  `seat_type` VARCHAR(16) NOT NULL,
  `singleton_marker` VARCHAR(16) GENERATED ALWAYS AS (CASE WHEN `seat_type` IN ('CLASSMATE', 'TEACHER', 'ANONYMOUS') THEN `seat_type` ELSE NULL END) STORED,
  `state` VARCHAR(24) NOT NULL DEFAULT 'AVAILABLE',
  `pseudonym_subject` BINARY(16) NULL,
  `invite_generation` INT UNSIGNED NOT NULL DEFAULT 0,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `invited_at` DATETIME(6) NULL,
  `activated_at` DATETIME(6) NULL,
  `frozen_at` DATETIME(6) NULL,
  `released_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_seat_ordinal` (`identity_id`, `ordinal`),
  UNIQUE KEY `uq_ci_seat_singleton` (`identity_id`, `singleton_marker`),
  UNIQUE KEY `uq_ci_seat_pseudonym` (`pseudonym_subject`),
  KEY `idx_ci_seat_identity_type_state` (`identity_id`, `seat_type`, `state`),
  CONSTRAINT `fk_ci_seat_identity` FOREIGN KEY (`identity_id`) REFERENCES {$identity} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_ci_seat_type` CHECK (`seat_type` IN ('CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS')),
  CONSTRAINT `chk_ci_seat_state` CHECK (`state` IN ('AVAILABLE', 'INVITED', 'PROVISIONING', 'ACTIVE', 'FROZEN', 'DISABLED', 'RELEASED')),
  CONSTRAINT `chk_ci_seat_pseudonym` CHECK ((`seat_type` = 'ANONYMOUS' AND `pseudonym_subject` IS NOT NULL) OR (`seat_type` <> 'ANONYMOUS' AND `pseudonym_subject` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$account} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seat_id` BIGINT UNSIGNED NOT NULL,
  `requested_username` VARCHAR(100) NOT NULL,
  `real_name` VARCHAR(190) NULL,
  `family_relationship` VARCHAR(24) NULL,
  `state` VARCHAR(32) NOT NULL DEFAULT 'PREPARED',
  `current_marker` TINYINT UNSIGNED NULL,
  `pseudonym_key_version` SMALLINT UNSIGNED NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `core_created_at` DATETIME(6) NULL,
  `bound_at` DATETIME(6) NULL,
  `frozen_at` DATETIME(6) NULL,
  `released_at` DATETIME(6) NULL,
  `deleted_at` DATETIME(6) NULL,
  `reconciled_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_account_current` (`seat_id`, `current_marker`),
  KEY `idx_ci_account_seat_state` (`seat_id`, `state`),
  CONSTRAINT `fk_ci_account_seat` FOREIGN KEY (`seat_id`) REFERENCES {$seat} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_ci_account_state` CHECK (`state` IN ('PREPARED', 'CORE_CREATED', 'ACTIVE', 'FROZEN', 'RELEASED', 'DELETED', 'COMPENSATION_REQUIRED')),
  CONSTRAINT `chk_ci_account_current` CHECK ((`state` IN ('ACTIVE', 'FROZEN') AND `current_marker` = 1) OR (`state` NOT IN ('ACTIVE', 'FROZEN') AND `current_marker` IS NULL)),
  CONSTRAINT `chk_ci_account_relationship` CHECK (`family_relationship` IS NULL OR `family_relationship` IN ('FATHER', 'MOTHER', 'SIBLING', 'GUARDIAN', 'OTHER_FAMILY')),
  CONSTRAINT `chk_ci_account_key_version` CHECK (`pseudonym_key_version` IS NULL OR `pseudonym_key_version` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$principal} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `principal_type` VARCHAR(24) NOT NULL,
  `system_role` VARCHAR(24) NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `piwigo_user_id` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `auth_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `frozen_at` DATETIME(6) NULL,
  `disabled_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_principal_account` (`account_id`),
  UNIQUE KEY `uq_ci_principal_core_user` (`piwigo_user_id`),
  KEY `idx_ci_principal_type_state` (`principal_type`, `state`),
  CONSTRAINT `fk_ci_principal_account` FOREIGN KEY (`account_id`) REFERENCES {$account} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_ci_principal_type` CHECK (`principal_type` IN ('SEAT_ACCOUNT', 'SYSTEM_ACCOUNT')),
  CONSTRAINT `chk_ci_principal_system_role` CHECK (`system_role` IS NULL OR `system_role` IN ('SYSTEM_ADMIN', 'ARCHIVIST', 'MODERATOR')),
  CONSTRAINT `chk_ci_principal_state` CHECK (`state` IN ('ACTIVE', 'FROZEN', 'DISABLED')),
  CONSTRAINT `chk_ci_principal_account_xor` CHECK ((`principal_type` = 'SEAT_ACCOUNT' AND `account_id` IS NOT NULL AND `system_role` IS NULL) OR (`principal_type` = 'SYSTEM_ACCOUNT' AND `account_id` IS NULL AND `system_role` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function migrationOperationsTokensAndAudit(): void
    {
        $identity = $this->quotedTable('identity');
        $seat = $this->quotedTable('seat');
        $account = $this->quotedTable('account');
        $principal = $this->quotedTable('principal');
        $operation = $this->quotedTable('operation');
        $token = $this->quotedTable('token');
        $audit = $this->quotedTable('audit_event');

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$operation} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation_type` VARCHAR(32) NOT NULL,
  `idempotency_hash` BINARY(32) NOT NULL,
  `identity_id` BIGINT UNSIGNED NULL,
  `seat_id` BIGINT UNSIGNED NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `principal_id` BIGINT UNSIGNED NULL,
  `state` VARCHAR(32) NOT NULL DEFAULT 'PREPARED',
  `core_user_id` BIGINT UNSIGNED NULL,
  `safe_payload` JSON NOT NULL,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME(6) NULL,
  `lease_until` DATETIME(6) NULL,
  `last_error_code` VARCHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `completed_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_operation_idempotency` (`idempotency_hash`),
  KEY `idx_ci_operation_state_retry` (`state`, `next_attempt_at`, `lease_until`),
  KEY `idx_ci_operation_identity` (`identity_id`),
  KEY `idx_ci_operation_seat` (`seat_id`),
  KEY `idx_ci_operation_account` (`account_id`),
  KEY `idx_ci_operation_principal` (`principal_id`),
  CONSTRAINT `fk_ci_operation_identity` FOREIGN KEY (`identity_id`) REFERENCES {$identity} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_operation_seat` FOREIGN KEY (`seat_id`) REFERENCES {$seat} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_operation_account` FOREIGN KEY (`account_id`) REFERENCES {$account} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_operation_principal` FOREIGN KEY (`principal_id`) REFERENCES {$principal} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_ci_operation_state` CHECK (`state` IN ('PREPARED', 'CORE_USER_CREATED', 'CORE_GROUP_ASSIGNED', 'COMMITTED', 'RETRY_CREDENTIAL_REQUIRED', 'COMPENSATING', 'COMPENSATED', 'FAILED_MANUAL')),
  CONSTRAINT `chk_ci_operation_attempts` CHECK (`attempt_count` <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->ensureColumn(
            'account',
            'provisioning_operation_id',
            'ALTER TABLE ' . $account . ' ADD COLUMN `provisioning_operation_id` BIGINT UNSIGNED NULL AFTER `pseudonym_key_version`',
        );
        $this->ensureIndex(
            'account',
            'uq_ci_account_operation',
            'ALTER TABLE ' . $account . ' ADD UNIQUE KEY `uq_ci_account_operation` (`provisioning_operation_id`)',
        );
        $this->ensureForeignKey(
            'account',
            'fk_ci_account_operation',
            'ALTER TABLE ' . $account . ' ADD CONSTRAINT `fk_ci_account_operation` FOREIGN KEY (`provisioning_operation_id`) REFERENCES ' . $operation . ' (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
        );

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$token} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seat_id` BIGINT UNSIGNED NULL,
  `principal_id` BIGINT UNSIGNED NULL,
  `purpose` VARCHAR(24) NOT NULL,
  `generation` INT UNSIGNED NOT NULL,
  `selector_hash` BINARY(32) NOT NULL,
  `validator_hash` BINARY(32) NOT NULL,
  `pepper_version` SMALLINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ISSUED',
  `reserved_by_operation_id` BIGINT UNSIGNED NULL,
  `issued_by_principal_id` BIGINT UNSIGNED NULL,
  `issued_by_user_id` BIGINT UNSIGNED NULL,
  `issued_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `expires_at` DATETIME(6) NOT NULL,
  `reserved_at` DATETIME(6) NULL,
  `consumed_at` DATETIME(6) NULL,
  `revoked_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_token_selector` (`selector_hash`),
  UNIQUE KEY `uq_ci_token_validator` (`validator_hash`),
  UNIQUE KEY `uq_ci_token_seat_generation` (`seat_id`, `purpose`, `generation`),
  UNIQUE KEY `uq_ci_token_principal_generation` (`principal_id`, `purpose`, `generation`),
  KEY `idx_ci_token_seat_purpose_state` (`seat_id`, `purpose`, `state`),
  KEY `idx_ci_token_principal_purpose_state` (`principal_id`, `purpose`, `state`),
  KEY `idx_ci_token_expiry` (`state`, `expires_at`),
  KEY `idx_ci_token_operation` (`reserved_by_operation_id`),
  KEY `idx_ci_token_issuer` (`issued_by_principal_id`),
  CONSTRAINT `fk_ci_token_seat` FOREIGN KEY (`seat_id`) REFERENCES {$seat} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_token_principal` FOREIGN KEY (`principal_id`) REFERENCES {$principal} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_token_operation` FOREIGN KEY (`reserved_by_operation_id`) REFERENCES {$operation} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_token_issuer` FOREIGN KEY (`issued_by_principal_id`) REFERENCES {$principal} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_ci_token_purpose` CHECK (`purpose` IN ('CLAIM', 'FAMILY_INVITE', 'PASSWORD_RESET')),
  CONSTRAINT `chk_ci_token_state` CHECK (`state` IN ('ISSUED', 'RESERVED', 'CONSUMED', 'REVOKED', 'EXPIRED')),
  CONSTRAINT `chk_ci_token_target` CHECK (((`purpose` IN ('CLAIM', 'FAMILY_INVITE')) AND `seat_id` IS NOT NULL AND `principal_id` IS NULL) OR (`purpose` = 'PASSWORD_RESET' AND `seat_id` IS NULL AND `principal_id` IS NOT NULL)),
  CONSTRAINT `chk_ci_token_pepper` CHECK (`pepper_version` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$audit} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `request_id` BINARY(16) NOT NULL,
  `actor_principal_id` BIGINT UNSIGNED NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `actor_kind` VARCHAR(24) NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `target_type` VARCHAR(32) NOT NULL,
  `target_id` VARCHAR(190) NULL,
  `target_identity_id` BIGINT UNSIGNED NULL,
  `target_seat_id` BIGINT UNSIGNED NULL,
  `target_account_id` BIGINT UNSIGNED NULL,
  `target_principal_id` BIGINT UNSIGNED NULL,
  `old_value` JSON NULL,
  `new_value` JSON NULL,
  `reason` VARCHAR(500) NULL,
  `source_ip_hash` BINARY(32) NULL,
  `result` VARCHAR(16) NOT NULL,
  `error_code` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ci_audit_occurred` (`occurred_at`, `id`),
  KEY `idx_ci_audit_request` (`request_id`),
  KEY `idx_ci_audit_actor` (`actor_principal_id`, `occurred_at`),
  KEY `idx_ci_audit_identity` (`target_identity_id`, `occurred_at`),
  KEY `idx_ci_audit_seat` (`target_seat_id`, `occurred_at`),
  KEY `idx_ci_audit_account` (`target_account_id`, `occurred_at`),
  KEY `idx_ci_audit_principal` (`target_principal_id`, `occurred_at`),
  KEY `idx_ci_audit_action_result` (`action`, `result`, `occurred_at`),
  CONSTRAINT `fk_ci_audit_actor` FOREIGN KEY (`actor_principal_id`) REFERENCES {$principal} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_audit_identity` FOREIGN KEY (`target_identity_id`) REFERENCES {$identity} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_audit_seat` FOREIGN KEY (`target_seat_id`) REFERENCES {$seat} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_audit_account` FOREIGN KEY (`target_account_id`) REFERENCES {$account} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ci_audit_principal` FOREIGN KEY (`target_principal_id`) REFERENCES {$principal} (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_ci_audit_result` CHECK (`result` IN ('SUCCESS', 'DENIED', 'FAILED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function migrationRoleGroups(): void
    {
        $roleGroup = $this->quotedTable('role_group');

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$roleGroup} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_code` VARCHAR(32) NOT NULL,
  `piwigo_group_id` SMALLINT UNSIGNED NULL,
  `expected_group_name` VARCHAR(100) NOT NULL,
  `is_business_role` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_role_group_role` (`role_code`),
  UNIQUE KEY `uq_ci_role_group_core_group` (`piwigo_group_id`),
  UNIQUE KEY `uq_ci_role_group_name` (`expected_group_name`),
  KEY `idx_ci_role_group_state` (`state`, `is_business_role`),
  CONSTRAINT `chk_ci_role_group_business` CHECK (`is_business_role` IN (0, 1)),
  CONSTRAINT `chk_ci_role_group_state` CHECK (`state` IN ('ACTIVE', 'DISABLED')),
  CONSTRAINT `chk_ci_role_group_required` CHECK (`is_business_role` = 0 OR `piwigo_group_id` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function migrationPublicClaimRateLimit(): void
    {
        $rateLimit = $this->quotedTable('rate_limit_bucket');

        // The subject is always an application-side HMAC. The table has no
        // column capable of retaining a raw IP address, roster code, selector,
        // claim code or invitation token.
        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$rateLimit} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` VARCHAR(16) NOT NULL,
  `purpose` VARCHAR(24) NOT NULL,
  `subject_hash` BINARY(32) NOT NULL,
  `window_id` BIGINT UNSIGNED NOT NULL,
  `window_seconds` INT UNSIGNED NOT NULL,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `expires_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_rate_limit_window` (`scope`, `purpose`, `subject_hash`, `window_id`, `window_seconds`),
  KEY `idx_ci_rate_limit_expiry` (`expires_at`),
  CONSTRAINT `chk_ci_rate_limit_scope` CHECK (`scope` IN ('SOURCE_IP', 'SELECTOR', 'ROSTER')),
  CONSTRAINT `chk_ci_rate_limit_purpose` CHECK (`purpose` IN ('CLAIM', 'FAMILY_INVITE')),
  CONSTRAINT `chk_ci_rate_limit_window` CHECK (`window_id` > 0 AND `window_seconds` BETWEEN 60 AND 86400),
  CONSTRAINT `chk_ci_rate_limit_attempts` CHECK (`attempt_count` BETWEEN 1 AND 1000000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function ensureMigrationLedger(): void
    {
        $ledger = $this->quotedTable('migration');
        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$ledger} (
  `version` INT UNSIGNED NOT NULL,
  `migration_name` VARCHAR(190) NOT NULL,
  `checksum` BINARY(32) NOT NULL,
  `plugin_version` VARCHAR(32) NOT NULL,
  `applied_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`version`),
  UNIQUE KEY `uq_ci_migration_name` (`migration_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('migration', ['version', 'migration_name', 'checksum', 'plugin_version', 'applied_at']);
    }

    /** @return array{migration_name: string, checksum: string}|null */
    private function findAppliedMigration(int $version): ?array
    {
        $sql = 'SELECT `migration_name`, `checksum` FROM ' . $this->quotedTable('migration') . ' WHERE `version` = ?';
        $statement = $this->prepare($sql);
        try {
            $statement->bind_param('i', $version);
            $this->executeStatement($statement);
            $result = $statement->get_result();
            $row = $result->fetch_assoc();

            return is_array($row)
                ? ['migration_name' => (string) $row['migration_name'], 'checksum' => (string) $row['checksum']]
                : null;
        } finally {
            $statement->close();
        }
    }

    private function recordMigration(int $version, string $name, string $checksum): void
    {
        $sql = 'INSERT INTO ' . $this->quotedTable('migration')
            . ' (`version`, `migration_name`, `checksum`, `plugin_version`) VALUES (?, ?, ?, ?)';
        $statement = $this->prepare($sql);
        try {
            $statement->bind_param('isss', $version, $name, $checksum, $this->pluginVersion);
            $this->executeStatement($statement);
        } finally {
            $statement->close();
        }
    }

    private function acquireAdvisoryLock(string $name): bool
    {
        $statement = $this->prepare('SELECT GET_LOCK(?, 10) AS `acquired`');
        try {
            $statement->bind_param('s', $name);
            $this->executeStatement($statement);
            $row = $statement->get_result()->fetch_assoc();

            return is_array($row) && (int) $row['acquired'] === 1;
        } finally {
            $statement->close();
        }
    }

    private function releaseAdvisoryLock(string $name): void
    {
        $statement = $this->prepare('SELECT RELEASE_LOCK(?)');
        try {
            $statement->bind_param('s', $name);
            $this->executeStatement($statement);
            $statement->get_result();
        } finally {
            $statement->close();
        }
    }

    private function ensureColumn(string $table, string $column, string $ddl): void
    {
        if (!$this->columnExists($table, $column)) {
            $this->executeRaw($ddl);
        }
        if (!$this->columnExists($table, $column)) {
            throw new \RuntimeException('class_identity_missing_column_' . $table . '_' . $column);
        }
    }

    private function ensureIndex(string $table, string $index, string $ddl): void
    {
        if (!$this->indexExists($table, $index)) {
            $this->executeRaw($ddl);
        }
        if (!$this->indexExists($table, $index)) {
            throw new \RuntimeException('class_identity_missing_index_' . $table . '_' . $index);
        }
    }

    private function ensureForeignKey(string $table, string $constraint, string $ddl): void
    {
        if (!$this->constraintExists($table, $constraint, 'FOREIGN KEY')) {
            $this->executeRaw($ddl);
        }
        if (!$this->constraintExists($table, $constraint, 'FOREIGN KEY')) {
            throw new \RuntimeException('class_identity_missing_foreign_key_' . $table . '_' . $constraint);
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->informationSchemaExists(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$this->table($table), $column],
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return $this->informationSchemaExists(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$this->table($table), $index],
        );
    }

    private function constraintExists(string $table, string $constraint, string $type): bool
    {
        return $this->informationSchemaExists(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$this->table($table), $constraint, $type],
        );
    }

    /** @param list<string> $values */
    private function informationSchemaExists(string $sql, array $values): bool
    {
        $statement = $this->prepare($sql);
        try {
            if (!$statement->execute(array_values($values))) {
                throw new \RuntimeException('class_identity_schema_execute_failed_' . $statement->errno);
            }
            $result = $statement->get_result();

            return $result->fetch_row() !== null;
        } finally {
            $statement->close();
        }
    }

    private function assertCurrentSchema(): void
    {
        foreach (self::expectedSemanticDigests() as $suffix => $expectedDigest) {
            $actualDigest = $this->semanticDigest($suffix);
            if (!hash_equals($expectedDigest, $actualDigest)) {
                throw new \RuntimeException('class_identity_schema_semantic_drift_' . $suffix);
            }
        }
    }

    /**
     * Locked MariaDB 11.8.8 semantic fingerprints.
     *
     * The digest input is deliberately assembled from information_schema, not
     * SHOW CREATE TABLE: AUTO_INCREMENT counters and other data-dependent table
     * options must not affect authorization readiness. It includes every
     * column's ordered type/null/default/extra/generated/collation contract,
     * every index's uniqueness and ordered parts, every FK target/action, and
     * every normalized CHECK clause (including MariaDB's JSON alias checks).
     *
     * @return array<string, string>
     */
    private static function expectedSemanticDigests(): array
    {
        return [
            'migration' => '0bed6a865301388ab4c8bf803a6d715dfbb6f01465d48ee079081a1a85cb4652',
            'identity' => '23669d8150ca2474c60882da619172c1c8aaeac4ed6a5a2a02544eec074302d5',
            'seat' => 'c8c25f4eef0503d4b83193e8f9a2909624dc9cff814e357410fdd5b159859598',
            'account' => 'e8ebc7c9fddc09344fbc0059b743c383e8dc5497b03f136db0fe566eaeb82084',
            'principal' => 'f34506326bea502cc5491d71d8753bcb37db4057d7d7d01bbd7285a8587df508',
            'operation' => '6c075f64d0077e875c68c5aad877cc677a70a49bb62907b94954e7d5f58f84a9',
            'token' => '5017a1d7bcb14f0f8d05ad9bda1b7ee93f4af2b27763babc132c0070a32a582c',
            'audit_event' => 'eb2cf5ede710cc8f8c0dd6fbc45bcbe6d274adc8997966d1ef31070397db1a39',
            'role_group' => '51cbc79121f83b63cbf70c538cc77194f9b726a54f9f893731d8412fdc1ceee4',
            'rate_limit_bucket' => 'e5717a295f89b6554ff8c6a2c8e526433c7de4922a5344e1c82578829787577a',
        ];
    }

    private function semanticDigest(string $suffix): string
    {
        $table = $this->table($suffix);
        $tableRows = $this->informationSchemaRows(
            'SELECT TABLE_TYPE, ENGINE, TABLE_COLLATION, CREATE_OPTIONS '
            . 'FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );
        if (count($tableRows) !== 1) {
            throw new \RuntimeException('class_identity_missing_table_' . $suffix);
        }
        $tableRow = $tableRows[0];
        $semantic = [
            'table' => [
                'type' => strtoupper((string) ($tableRow['TABLE_TYPE'] ?? '')),
                'engine' => strtoupper((string) ($tableRow['ENGINE'] ?? '')),
                'collation' => strtolower((string) ($tableRow['TABLE_COLLATION'] ?? '')),
                'options' => self::normalizeSpace((string) ($tableRow['CREATE_OPTIONS'] ?? '')),
            ],
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
            'checks' => [],
        ];

        $columns = $this->informationSchemaRows(
            'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, '
            . 'EXTRA, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        );
        foreach ($columns as $column) {
            $semantic['columns'][] = [
                'name' => (string) ($column['COLUMN_NAME'] ?? ''),
                'position' => (int) ($column['ORDINAL_POSITION'] ?? 0),
                'type' => self::normalizeSpace((string) ($column['COLUMN_TYPE'] ?? '')),
                'nullable' => strtoupper((string) ($column['IS_NULLABLE'] ?? '')),
                'default' => self::normalizeNullableSql($column['COLUMN_DEFAULT'] ?? null),
                'extra' => self::normalizeSpace((string) ($column['EXTRA'] ?? '')),
                'generation' => self::normalizeNullableSql($column['GENERATION_EXPRESSION'] ?? null),
                'charset' => self::normalizeNullableName($column['CHARACTER_SET_NAME'] ?? null),
                'collation' => self::normalizeNullableName($column['COLLATION_NAME'] ?? null),
            ];
        }

        $indexes = $this->informationSchemaRows(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, COLLATION, '
            . 'INDEX_TYPE, PACKED, INDEX_COMMENT, IGNORED '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );
        $indexGroups = [];
        foreach ($indexes as $index) {
            $name = (string) ($index['INDEX_NAME'] ?? '');
            $position = (int) ($index['SEQ_IN_INDEX'] ?? 0);
            if ($name === '' || $position <= 0) {
                throw new \RuntimeException('class_identity_schema_index_metadata_invalid_' . $suffix);
            }
            $header = [
                'primary' => $name === 'PRIMARY',
                'unique' => (int) ($index['NON_UNIQUE'] ?? 1) === 0,
                'type' => strtoupper((string) ($index['INDEX_TYPE'] ?? '')),
                'packed' => self::normalizeNullableName($index['PACKED'] ?? null),
                'ignored' => strtoupper((string) ($index['IGNORED'] ?? '')),
            ];
            if (!isset($indexGroups[$name])) {
                $indexGroups[$name] = $header + ['parts' => []];
            } elseif (array_intersect_key($indexGroups[$name], $header) !== $header) {
                throw new \RuntimeException('class_identity_schema_index_metadata_inconsistent_' . $suffix);
            }
            $indexGroups[$name]['parts'][$position] = [
                'column' => (string) ($index['COLUMN_NAME'] ?? ''),
                'prefix' => isset($index['SUB_PART']) ? (int) $index['SUB_PART'] : null,
                'order' => strtoupper((string) ($index['COLLATION'] ?? '')),
            ];
        }
        foreach ($indexGroups as $index) {
            ksort($index['parts'], SORT_NUMERIC);
            $index['parts'] = array_values($index['parts']);
            $semantic['indexes'][] = $index;
        }
        self::sortSemanticRecords($semantic['indexes']);

        $foreignKeys = $this->informationSchemaRows(
            'SELECT k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, '
            . 'k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, '
            . 'r.MATCH_OPTION, r.UPDATE_RULE, r.DELETE_RULE '
            . 'FROM information_schema.KEY_COLUMN_USAGE k '
            . 'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
            . 'AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? '
            . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [$table],
        );
        $foreignKeyGroups = [];
        foreach ($foreignKeys as $foreignKey) {
            $name = (string) ($foreignKey['CONSTRAINT_NAME'] ?? '');
            $position = (int) ($foreignKey['ORDINAL_POSITION'] ?? 0);
            if ($name === '' || $position <= 0) {
                throw new \RuntimeException('class_identity_schema_foreign_key_metadata_invalid_' . $suffix);
            }
            $referencedTable = (string) ($foreignKey['REFERENCED_TABLE_NAME'] ?? '');
            $header = [
                'match' => strtoupper((string) ($foreignKey['MATCH_OPTION'] ?? '')),
                'update' => strtoupper((string) ($foreignKey['UPDATE_RULE'] ?? '')),
                'delete' => strtoupper((string) ($foreignKey['DELETE_RULE'] ?? '')),
            ];
            if (!isset($foreignKeyGroups[$name])) {
                $foreignKeyGroups[$name] = $header + ['parts' => []];
            } elseif (array_intersect_key($foreignKeyGroups[$name], $header) !== $header) {
                throw new \RuntimeException('class_identity_schema_foreign_key_metadata_inconsistent_' . $suffix);
            }
            $foreignKeyGroups[$name]['parts'][$position] = [
                'column' => (string) ($foreignKey['COLUMN_NAME'] ?? ''),
                'referenced_table' => str_starts_with($referencedTable, $this->prefix)
                    ? substr($referencedTable, strlen($this->prefix))
                    : $referencedTable,
                'referenced_column' => (string) ($foreignKey['REFERENCED_COLUMN_NAME'] ?? ''),
            ];
        }
        foreach ($foreignKeyGroups as $foreignKey) {
            ksort($foreignKey['parts'], SORT_NUMERIC);
            $foreignKey['parts'] = array_values($foreignKey['parts']);
            $semantic['foreign_keys'][] = $foreignKey;
        }
        self::sortSemanticRecords($semantic['foreign_keys']);

        $checks = $this->informationSchemaRows(
            'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE '
            . 'FROM information_schema.TABLE_CONSTRAINTS tc '
            . 'INNER JOIN information_schema.CHECK_CONSTRAINTS cc '
            . 'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA '
            . 'AND cc.TABLE_NAME = tc.TABLE_NAME AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . "WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK' "
            . 'ORDER BY tc.CONSTRAINT_NAME',
            [$table],
        );
        foreach ($checks as $check) {
            $semantic['checks'][] = self::normalizeSqlExpression((string) ($check['CHECK_CLAUSE'] ?? ''));
        }
        sort($semantic['checks'], SORT_STRING);

        $encoded = json_encode($semantic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash('sha256', $encoded);
    }

    /** @param list<mixed> $values
     *  @return list<array<string, mixed>>
     */
    private function informationSchemaRows(string $sql, array $values): array
    {
        $statement = $this->prepare($sql);
        try {
            if (!$statement->execute(array_values($values))) {
                throw new \RuntimeException('class_identity_schema_execute_failed_' . $statement->errno);
            }
            $result = $statement->get_result();
            if (!$result instanceof \mysqli_result) {
                throw new \RuntimeException('class_identity_schema_result_unavailable');
            }
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }

    private static function normalizeNullableName(mixed $value): ?string
    {
        return $value === null ? null : strtolower((string) $value);
    }

    private static function normalizeNullableSql(mixed $value): ?string
    {
        return $value === null ? null : self::normalizeSqlExpression((string) $value);
    }

    private static function normalizeSpace(string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /** @param list<array<string, mixed>> $records */
    private static function sortSemanticRecords(array &$records): void
    {
        usort(
            $records,
            static fn(array $left, array $right): int => strcmp(
                json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ),
        );
    }

    /**
     * Normalize server formatting without erasing SQL semantics. Whitespace,
     * identifier quotes and keyword case are ignored; quoted literal bytes and
     * every operator/parenthesis remain part of the fingerprint.
     */
    private static function normalizeSqlExpression(string $expression): string
    {
        $result = '';
        $quoted = false;
        $length = strlen($expression);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $expression[$offset];
            if ($quoted) {
                $result .= $character;
                if ($character === "'" && $offset + 1 < $length && $expression[$offset + 1] === "'") {
                    $result .= "'";
                    ++$offset;
                    continue;
                }
                if ($character === "'" && ($offset === 0 || $expression[$offset - 1] !== '\\')) {
                    $quoted = false;
                }
                continue;
            }
            if ($character === "'") {
                $quoted = true;
                $result .= $character;
                continue;
            }
            if ($character === '`' || ctype_space($character)) {
                continue;
            }
            $result .= strtolower($character);
        }
        if ($quoted) {
            throw new \RuntimeException('class_identity_schema_expression_invalid');
        }
        return $result;
    }

    /** @param list<string> $requiredColumns */
    private function assertTable(string $suffix, array $requiredColumns): void
    {
        $statement = $this->prepare(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        );
        $name = $this->table($suffix);
        try {
            $statement->bind_param('s', $name);
            $this->executeStatement($statement);
            $row = $statement->get_result()->fetch_assoc();
        } finally {
            $statement->close();
        }

        if (!is_array($row)) {
            throw new \RuntimeException('class_identity_missing_table_' . $suffix);
        }
        if (strtoupper((string) $row['ENGINE']) !== 'INNODB') {
            throw new \RuntimeException('class_identity_wrong_engine_' . $suffix);
        }
        if (!str_starts_with(strtolower((string) $row['TABLE_COLLATION']), 'utf8mb4_')) {
            throw new \RuntimeException('class_identity_wrong_charset_' . $suffix);
        }

        foreach ($requiredColumns as $column) {
            if (!$this->columnExists($suffix, $column)) {
                throw new \RuntimeException('class_identity_missing_column_' . $suffix . '_' . $column);
            }
        }
    }

    private function quotedTable(string $suffix): string
    {
        return '`' . $this->table($suffix) . '`';
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
        ];
    }

    private function executeRaw(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new \RuntimeException('class_identity_schema_query_failed_' . $this->db->errno);
        }
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if (!$statement instanceof \mysqli_stmt) {
            throw new \RuntimeException('class_identity_schema_prepare_failed_' . $this->db->errno);
        }

        return $statement;
    }

    private function executeStatement(\mysqli_stmt $statement): void
    {
        if (!$statement->execute()) {
            throw new \RuntimeException('class_identity_schema_execute_failed_' . $statement->errno);
        }
    }
}
