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
    public const CURRENT_VERSION = 18;
    public const LOCKED_PIWIGO_VERSION = '16.4.0';

    private const COLLATION = 'utf8mb4_unicode_ci';

    private \mysqli $db;
    private string $piwigoPrefix;
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
        $this->piwigoPrefix = $tablePrefix;
        $this->prefix = $tablePrefix . 'class_identity_';
        $this->pluginVersion = $pluginVersion;
    }

    public static function fromPiwigo(string $pluginVersion): self
    {
        self::assertLockedPiwigoRuntime();

        return self::fromPiwigoDatabase($pluginVersion);
    }

    /**
     * Retirement must remain possible after an accidental Core upgrade.
     * Activation is version-locked, but refusing cleanup would strand
     * plugin-owned triggers on Piwigo's native tables.
     */
    public static function fromPiwigoForRetirement(string $pluginVersion): self
    {
        return self::fromPiwigoDatabase($pluginVersion);
    }

    public static function assertLockedPiwigoRuntime(?string $runtimeVersion = null): void
    {
        $actual = $runtimeVersion;
        if ($actual === null) {
            $actual = defined('PHPWG_VERSION') ? (string) PHPWG_VERSION : '';
        }
        if (!hash_equals(self::LOCKED_PIWIGO_VERSION, $actual)) {
            throw new \RuntimeException('class_identity_unsupported_piwigo_runtime');
        }
    }

    private static function fromPiwigoDatabase(string $pluginVersion): self
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

    /**
     * Restore plugin-owned guards before an inactive/retained installation is
     * migrated again. If a guard family was absent or drifted, the complete
     * role-neutral read model is invalidated after every trigger is installed.
     * A native write before its individual trigger is restored is therefore
     * still covered by the final invalidation/epoch rotation.
     */
    public function prepareNativeMutationProtectionForActivation(): void
    {
        self::assertLockedPiwigoRuntime();
        if (!$this->tableExists('read_projection')) {
            return;
        }

        $lockName = 'class_identity_native_lifecycle_' . hash('sha256', $this->prefix);
        if (!$this->acquireAdvisoryLock($lockName)) {
            throw new \RuntimeException('class_identity_native_lifecycle_lock_timeout');
        }

        try {
            $repaired = false;
            $projectionDefinitions = $this->nativeProjectionTriggerDefinitions();
            if (!$this->nativeProjectionTriggersAreCurrent($projectionDefinitions)) {
                $this->replaceNativeProjectionTriggers($projectionDefinitions);
                $repaired = true;
            }

            if ($this->tableExists('native_source_epoch')) {
                $epochDefinitions = $this->nativeSourceEpochTriggerDefinitions();
                if (!$this->nativeProjectionTriggersAreCurrent($epochDefinitions)) {
                    $this->replaceNativeProjectionTriggers($epochDefinitions);
                    $repaired = true;
                }
            }

            if ($repaired) {
                $this->invalidateNativeLifecycleState('PLUGIN_LIFECYCLE_ACTIVATION');
            }
        } finally {
            $this->releaseAdvisoryLock($lockName);
        }
    }

    /**
     * Deactivation/uninstall retain governed ClassIdentity data but remove all
     * 18 plugin-owned triggers from native Piwigo tables. Projection rows are
     * first made unusable, so any source writes after retirement can never be
     * served from an old ACTIVE read model. This operation is idempotent and
     * intentionally does not enforce the activation version lock: it must be
     * possible to clean up safely after an accidental Core upgrade.
     */
    public function retireNativeMutationProtection(): void
    {
        $lockName = 'class_identity_native_lifecycle_' . hash('sha256', $this->prefix);
        if (!$this->acquireAdvisoryLock($lockName)) {
            throw new \RuntimeException('class_identity_native_lifecycle_lock_timeout');
        }

        try {
            if ($this->tableExists('read_projection')) {
                // Retirement must also clean up an interrupted installation
                // whose projection/epoch singleton set is already incomplete.
                // Every row that does exist is made STALE before the exact
                // plugin-owned trigger names are removed.
                $this->invalidateNativeLifecycleState('PLUGIN_LIFECYCLE_RETIRED', false);
            }

            foreach (array_merge(
                $this->nativeSourceEpochTriggerDefinitions(),
                $this->nativeProjectionTriggerDefinitions(),
            ) as $definition) {
                $this->executeRaw('DROP TRIGGER IF EXISTS ' . $this->quotedIdentifier($definition['name']));
            }

            $remaining = $this->pluginOwnedNativeTriggerRows();
            if ($remaining !== []) {
                throw new \RuntimeException('class_identity_native_lifecycle_trigger_retained');
            }
        } finally {
            $this->releaseAdvisoryLock($lockName);
        }
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
            5 => [
                'name' => '0005_submissions_archive_metadata',
                'signature' => 'v1:family-pending-submission:admin-review:heritage-only:archive-date-precision:innodb:utf8mb4',
                'method' => 'migrationSubmissionsAndArchive',
            ],
            6 => [
                'name' => '0006_class_archive_photo_mapping',
                'signature' => 'v1:opaque-class-photo-uuid:piwigo-reference:nullable-immich-link:pending-provenance:fail-closed-state:innodb:utf8mb4',
                'method' => 'migrationClassArchivePhotoMapping',
            ],
            7 => [
                'name' => '0007_timeline_source_person_mapping',
                'signature' => 'v1:archive-date-source:no-upload-time-fallback:opaque-ai-cluster-person:optional-identity-link:innodb:utf8mb4',
                'method' => 'migrationTimelineSourceAndPersonMapping',
            ],
            8 => [
                'name' => '0008_photo_productization_domain',
                'signature' => 'v1:person-curation:reversible-projection-merge:photo-rules:opaque-mixed-album:spotlight-24h:source-provenance:duplicate-review:crash-visible-journal:innodb:utf8mb4',
                'method' => 'migrationPhotoProductizationDomain',
            ],
            9 => [
                'name' => '0009_gateway_persistent_read_projection',
                'signature' => 'v1:authorization-neutral-photo-catalog:atomic-generation:fail-closed-stale:dependency-invalidation:innodb:utf8mb4',
                'method' => 'migrationGatewayReadProjection',
            ],
            10 => [
                'name' => '0010_gateway_role_scoped_aggregate_projection',
                'signature' => 'v1:full-heritage-aggregate-payload:timeline-albums-people-memories:catalog-bound:fail-closed:innodb:utf8mb4',
                'method' => 'migrationGatewayAggregateProjection',
            ],
            11 => [
                'name' => '0011_native_piwigo_projection_guard',
                'signature' => 'v1:before-triggers:images-relevant-fields:image-category:categories:five-row-exact:fail-closed',
                'method' => 'migrationNativePiwigoProjectionGuard',
            ],
            12 => [
                'name' => '0012_durable_native_source_epoch',
                'signature' => 'v1:myisam-source-epoch:cross-engine-rollback-safe:catalog-binding:initialized-projection-epochs:fail-closed',
                'method' => 'migrationDurableNativeSourceEpoch',
            ],
            13 => [
                'name' => '0013_private_full_library_import',
                'signature' => 'v2:private-source-code-only:private-full-provenance:folder-path-digest:native-category-map:resumable-item-journal:sha256-exact-canonical-reuse:innodb:utf8mb4',
                'method' => 'migrationPrivateFullLibraryImport',
            ],
            14 => [
                'name' => '0014_private_full_native_checkpoint_recovery',
                'signature' => 'v1:processing-failed-native-image-checkpoint:photo-null-until-canonical-publish:resumable-cross-engine-saga:innodb:utf8mb4',
                'method' => 'migrationPrivateFullNativeCheckpointRecovery',
            ],
            15 => [
                'name' => '0015_collections_first_comments_ai_index',
                'signature' => 'v2:source-collection-leaf-alias:threaded-photo-comment:context-pseudonym:durable-auto-collection:unique-source-reason:per-asset-ai-index:conservative-job-queue:innodb:utf8mb4',
                'method' => 'migrationCollectionsFirstCommentsAndAiIndex',
            ],
            16 => [
                'name' => '0016_private_source_presentation_surrogate',
                'signature' => 'v1:source-bytes-preserved:presentation-checksum-bound:opaque-source-identity:fixed-transform-recipe:innodb:utf8mb4',
                'method' => 'migrationPrivateSourcePresentationSurrogate',
            ],
            17 => [
                'name' => '0017_photos_app_v4_collection_snapshots',
                'signature' => 'v1:versioned-role-scoped-collection-snapshots:atomic-active-pointer:retained-superseded-history:principal-pins-feedback:maintenance-watermark:innodb:utf8mb4',
                'method' => 'migrationPhotosAppV4CollectionSnapshots',
            ],
            18 => [
                'name' => '0018_photos_app_v4_spotlight_rotation_state',
                'signature' => 'v1:bounded-policy-scoped-hero:opaque-spotlight-fk:candidate-digest:deterministic-next-after-previous:monotonic-display-count:revisioned-schedule:innodb:utf8mb4',
                'method' => 'migrationPhotosAppV4SpotlightRotationState',
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

    private function migrationSubmissionsAndArchive(): void
    {
        $submission = $this->quotedTable('submission');
        $archive = $this->quotedTable('archive_image');
        $identity = $this->quotedTable('identity');
        $seat = $this->quotedTable('seat');
        $account = $this->quotedTable('account');
        $principal = $this->quotedTable('principal');

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$submission} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seat_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `principal_id` BIGINT UNSIGNED NOT NULL,
  `identity_id` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  `original_filename` VARCHAR(255) NOT NULL,
  `storage_ref` VARCHAR(190) NOT NULL,
  `thumbnail_ref` VARCHAR(190) NOT NULL,
  `mime_type` VARCHAR(64) NOT NULL,
  `extension` VARCHAR(8) NOT NULL,
  `byte_size` BIGINT UNSIGNED NOT NULL,
  `sha256` BINARY(32) NOT NULL,
  `width` INT UNSIGNED NOT NULL,
  `height` INT UNSIGNED NOT NULL,
  `suggested_date` DATE NULL,
  `date_precision` VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
  `suggested_album` VARCHAR(190) NULL,
  `description` TEXT NULL,
  `uploaded_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `reviewed_at` DATETIME(6) NULL,
  `reviewed_by_principal_id` BIGINT UNSIGNED NULL,
  `review_reason` VARCHAR(500) NULL,
  `approved_image_id` MEDIUMINT(8) UNSIGNED NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_submission_storage` (`storage_ref`),
  UNIQUE KEY `uq_ci_submission_thumb` (`thumbnail_ref`),
  KEY `idx_ci_submission_state` (`state`, `uploaded_at`),
  KEY `idx_ci_submission_identity` (`identity_id`, `uploaded_at`),
  KEY `idx_ci_submission_seat` (`seat_id`, `uploaded_at`),
  KEY `idx_ci_submission_approved` (`approved_image_id`),
  CONSTRAINT `fk_ci_submission_identity` FOREIGN KEY (`identity_id`) REFERENCES {$identity} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_ci_submission_seat` FOREIGN KEY (`seat_id`) REFERENCES {$seat} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_ci_submission_account` FOREIGN KEY (`account_id`) REFERENCES {$account} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_ci_submission_principal` FOREIGN KEY (`principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_ci_submission_reviewed_by` FOREIGN KEY (`reviewed_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_submission_state` CHECK (`state` IN ('PENDING', 'APPROVED', 'REJECTED')),
  CONSTRAINT `chk_ci_submission_precision` CHECK (`date_precision` IN ('EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN')),
  CONSTRAINT `chk_ci_submission_dimensions` CHECK (`byte_size` > 0 AND `width` > 0 AND `height` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$archive} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `piwigo_image_id` MEDIUMINT(8) UNSIGNED NOT NULL,
  `era` VARCHAR(16) NOT NULL DEFAULT 'HERITAGE',
  `archive_date` DATE NULL,
  `date_precision` VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
  `date_confidence` VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
  `event_label` VARCHAR(190) NULL,
  `official` TINYINT(1) NOT NULL DEFAULT 0,
  `source_submission_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_archive_image` (`piwigo_image_id`),
  UNIQUE KEY `uq_ci_archive_submission` (`source_submission_id`),
  KEY `idx_ci_archive_era_date` (`era`, `archive_date`),
  KEY `idx_ci_archive_precision` (`date_precision`),
  CONSTRAINT `fk_ci_archive_submission` FOREIGN KEY (`source_submission_id`) REFERENCES {$submission} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_archive_era` CHECK (`era` IN ('HERITAGE', 'LIVING')),
  CONSTRAINT `chk_ci_archive_precision` CHECK (`date_precision` IN ('EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN')),
  CONSTRAINT `chk_ci_archive_confidence` CHECK (`date_confidence` IN ('HIGH', 'MEDIUM', 'LOW', 'UNKNOWN')),
  CONSTRAINT `chk_ci_archive_official` CHECK (`official` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('submission', [
            'id', 'seat_id', 'account_id', 'principal_id', 'identity_id', 'state',
            'original_filename', 'storage_ref', 'thumbnail_ref', 'mime_type', 'extension',
            'byte_size', 'sha256', 'width', 'height', 'suggested_date', 'date_precision',
            'suggested_album', 'description', 'uploaded_at', 'reviewed_at',
            'reviewed_by_principal_id', 'review_reason', 'approved_image_id',
        ]);
        $this->assertTable('archive_image', [
            'id', 'piwigo_image_id', 'era', 'archive_date', 'date_precision',
            'date_confidence', 'event_label', 'official', 'source_submission_id',
        ]);
    }

    /**
     * Canonical ClassArchivePhoto mapping.
     *
     * Piwigo image tables are MyISAM in the pinned runtime, so an FK to a
     * Piwigo image row is neither possible nor claimed here. The mapping keeps
     * an opaque UUID independent of both Piwigo and Immich ids; reconciliation
     * verifies that the external Piwigo row/file still agrees before any API
     * projection uses it. Pending records may point only to the already-owned
     * InnoDB submission row and can never carry an Immich linkage.
     */
    private function migrationClassArchivePhotoMapping(): void
    {
        $photo = $this->quotedTable('photo');
        $submission = $this->quotedTable('submission');
        // MariaDB/InnoDB foreign-key names are schema-global.  The prefix
        // suffix keeps a disposable semantic-test schema from colliding with
        // the live v6 constraint while the fingerprint deliberately checks
        // FK meaning rather than a deployment-specific constraint name.
        $submissionForeignKey = 'fk_ci_photo_submission_' . substr(hash('sha256', $this->table('photo')), 0, 12);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$photo} (
  `class_photo_id` BINARY(16) NOT NULL,
  `piwigo_image_id` MEDIUMINT(8) UNSIGNED NULL,
  `source_submission_id` BIGINT UNSIGNED NULL,
  `immich_asset_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `media_checksum` BINARY(32) NOT NULL,
  `media_reference` VARCHAR(512) NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`class_photo_id`),
  UNIQUE KEY `uq_ci_photo_piwigo_image` (`piwigo_image_id`),
  UNIQUE KEY `uq_ci_photo_submission` (`source_submission_id`),
  UNIQUE KEY `uq_ci_photo_immich_asset` (`immich_asset_id`),
  KEY `idx_ci_photo_state_updated` (`state`, `updated_at`),
  CONSTRAINT `{$submissionForeignKey}` FOREIGN KEY (`source_submission_id`) REFERENCES {$submission} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_photo_state` CHECK (`state` IN ('PENDING', 'ACTIVE', 'STALE', 'RETIRED')),
  CONSTRAINT `chk_ci_photo_target` CHECK ((`state` = 'PENDING' AND `piwigo_image_id` IS NULL AND `source_submission_id` IS NOT NULL AND `immich_asset_id` IS NULL) OR (`state` IN ('ACTIVE', 'STALE', 'RETIRED') AND `piwigo_image_id` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('photo', [
            'class_photo_id', 'piwigo_image_id', 'source_submission_id', 'immich_asset_id',
            'media_checksum', 'media_reference', 'state', 'created_at', 'updated_at',
        ]);
    }

    /**
     * Archive dates are business evidence, not Piwigo import timestamps.
     *
     * The ClassArchivePerson record keeps a deliberately opaque adapter
     * mapping for Immich face clusters.  Its optional roster relation is a
     * future curation choice; no facial-recognition result can create it.
     */
    private function migrationTimelineSourceAndPersonMapping(): void
    {
        $archive = $this->quotedTable('archive_image');
        $person = $this->quotedTable('person');
        $identity = $this->quotedTable('identity');
        $identityForeignKey = 'fk_ci_person_identity_' . substr(hash('sha256', $this->table('person')), 0, 12);

        $this->ensureColumn(
            'archive_image',
            'date_source',
            'ALTER TABLE ' . $archive . ' ADD COLUMN `date_source` VARCHAR(24) NOT NULL DEFAULT \'UNKNOWN\' AFTER `date_confidence`',
        );
        $this->ensureIndex(
            'archive_image',
            'idx_ci_archive_date_source',
            'ALTER TABLE ' . $archive . ' ADD KEY `idx_ci_archive_date_source` (`date_source`,`archive_date`)',
        );
        $this->ensureCheckConstraint(
            'archive_image',
            'chk_ci_archive_date_source',
            'ALTER TABLE ' . $archive . " ADD CONSTRAINT `chk_ci_archive_date_source` CHECK (`date_source` IN ('ARCHIVE_CONFIRMED', 'EVENT_INFERENCE', 'EXIF_TRUSTED', 'UNKNOWN'))",
        );

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$person} (
  `class_person_id` BINARY(16) NOT NULL,
  `immich_person_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `display_name` VARCHAR(190) NULL,
  `classmate_identity_id` BIGINT UNSIGNED NULL,
  `source_kind` VARCHAR(24) NOT NULL DEFAULT 'IMMICH_CLUSTER',
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`class_person_id`),
  UNIQUE KEY `uq_ci_person_immich` (`immich_person_id`),
  KEY `idx_ci_person_identity` (`classmate_identity_id`,`state`),
  KEY `idx_ci_person_state_updated` (`state`,`updated_at`),
  CONSTRAINT `{$identityForeignKey}` FOREIGN KEY (`classmate_identity_id`) REFERENCES {$identity} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_person_source` CHECK (`source_kind` IN ('IMMICH_CLUSTER', 'MANUAL')),
  CONSTRAINT `chk_ci_person_state` CHECK (`state` IN ('ACTIVE', 'STALE', 'RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('archive_image', [
            'id', 'piwigo_image_id', 'era', 'archive_date', 'date_precision',
            'date_confidence', 'date_source', 'event_label', 'official', 'source_submission_id',
        ]);
        $this->assertTable('person', [
            'class_person_id', 'immich_person_id', 'display_name', 'classmate_identity_id',
            'source_kind', 'state', 'created_at', 'updated_at',
        ]);
    }

    /**
     * Product-facing curation is deliberately an overlay on Piwigo/Immich.
     *
     * No table below mutates an Immich face cluster or makes it a roster
     * identity.  Person merge/correction rows are reversible presentation
     * rules, album ids are opaque Class Archive ids mapped to Piwigo, and
     * duplicate consolidation is a logical relationship which never deletes
     * either physical original.  The batch journal is the InnoDB half of a
     * conservative compensation saga around Piwigo's legacy category tables.
     */
    private function migrationPhotoProductizationDomain(): void
    {
        $person = $this->quotedTable('person');
        $photo = $this->quotedTable('photo');
        $identity = $this->quotedTable('identity');
        $principal = $this->quotedTable('principal');
        $personMerge = $this->quotedTable('person_merge');
        $personPhotoRule = $this->quotedTable('person_photo_rule');
        $album = $this->quotedTable('album');
        $spotlight = $this->quotedTable('spotlight');
        $photoSource = $this->quotedTable('photo_source');
        $photoDuplicate = $this->quotedTable('photo_duplicate');
        $batchOperation = $this->quotedTable('batch_operation');
        $batchItem = $this->quotedTable('batch_operation_item');

        $fk = static fn(string $purpose, string $table): string => 'fk_ci_' . $purpose . '_'
            . substr(hash('sha256', $table), 0, 12);

        $this->ensureColumn(
            'person',
            'manual_cover_class_photo_id',
            'ALTER TABLE ' . $person . ' ADD COLUMN `manual_cover_class_photo_id` BINARY(16) NULL AFTER `classmate_identity_id`',
        );
        $this->ensureColumn(
            'person',
            'visibility',
            "ALTER TABLE {$person} ADD COLUMN `visibility` VARCHAR(16) NOT NULL DEFAULT 'VISIBLE' AFTER `source_kind`",
        );
        $this->ensureColumn(
            'person',
            'lock_version',
            'ALTER TABLE ' . $person . ' ADD COLUMN `lock_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `state`',
        );
        $this->ensureIndex(
            'person',
            'idx_ci_person_visibility_state',
            'ALTER TABLE ' . $person . ' ADD KEY `idx_ci_person_visibility_state` (`visibility`,`state`,`updated_at`)',
        );
        $this->ensureForeignKey(
            'person',
            $fk('person_cover', $this->table('person')),
            'ALTER TABLE ' . $person . ' ADD CONSTRAINT `' . $fk('person_cover', $this->table('person'))
                . '` FOREIGN KEY (`manual_cover_class_photo_id`) REFERENCES ' . $photo
                . ' (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT',
        );
        $this->ensureCheckConstraint(
            'person',
            'chk_ci_person_visibility',
            "ALTER TABLE {$person} ADD CONSTRAINT `chk_ci_person_visibility` CHECK (`visibility` IN ('VISIBLE', 'HIDDEN'))",
        );

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$personMerge} (
  `merge_id` BINARY(16) NOT NULL,
  `source_class_person_id` BINARY(16) NOT NULL,
  `target_class_person_id` BINARY(16) NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `active_source_class_person_id` BINARY(16) GENERATED ALWAYS AS (CASE WHEN `state` = 'ACTIVE' THEN `source_class_person_id` ELSE NULL END) PERSISTENT,
  `created_by_principal_id` BIGINT UNSIGNED NOT NULL,
  `reverted_by_principal_id` BIGINT UNSIGNED NULL,
  `reason` VARCHAR(500) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `reverted_at` DATETIME(6) NULL,
  PRIMARY KEY (`merge_id`),
  UNIQUE KEY `uq_ci_person_merge_active_source` (`active_source_class_person_id`),
  KEY `idx_ci_person_merge_target_state` (`target_class_person_id`,`state`),
  CONSTRAINT `{$fk('person_merge_source', $this->table('person_merge'))}` FOREIGN KEY (`source_class_person_id`) REFERENCES {$person} (`class_person_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('person_merge_target', $this->table('person_merge'))}` FOREIGN KEY (`target_class_person_id`) REFERENCES {$person} (`class_person_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('person_merge_created', $this->table('person_merge'))}` FOREIGN KEY (`created_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('person_merge_reverted', $this->table('person_merge'))}` FOREIGN KEY (`reverted_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_person_merge_state` CHECK (`state` IN ('ACTIVE', 'REVERTED')),
  CONSTRAINT `chk_ci_person_merge_distinct` CHECK (`source_class_person_id` <> `target_class_person_id`),
  CONSTRAINT `chk_ci_person_merge_reversion` CHECK ((`state` = 'ACTIVE' AND `reverted_by_principal_id` IS NULL AND `reverted_at` IS NULL) OR (`state` = 'REVERTED' AND `reverted_by_principal_id` IS NOT NULL AND `reverted_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$personPhotoRule} (
  `class_person_id` BINARY(16) NOT NULL,
  `class_photo_id` BINARY(16) NOT NULL,
  `rule` VARCHAR(16) NOT NULL,
  `updated_by_principal_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`class_person_id`,`class_photo_id`),
  KEY `idx_ci_person_photo_rule_photo` (`class_photo_id`,`rule`),
  CONSTRAINT `{$fk('person_photo_person', $this->table('person_photo_rule'))}` FOREIGN KEY (`class_person_id`) REFERENCES {$person} (`class_person_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('person_photo_photo', $this->table('person_photo_rule'))}` FOREIGN KEY (`class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('person_photo_actor', $this->table('person_photo_rule'))}` FOREIGN KEY (`updated_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_person_photo_rule` CHECK (`rule` IN ('INCLUDE', 'EXCLUDE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$album} (
  `class_album_id` BINARY(16) NOT NULL,
  `piwigo_category_id` MEDIUMINT(8) UNSIGNED NOT NULL,
  `album_type` VARCHAR(16) NOT NULL,
  `owner_principal_id` BIGINT UNSIGNED NULL,
  `era` VARCHAR(16) NOT NULL,
  `description` TEXT NULL,
  `event_label` VARCHAR(190) NULL,
  `manual_cover_class_photo_id` BINARY(16) NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`class_album_id`),
  UNIQUE KEY `uq_ci_album_piwigo_category` (`piwigo_category_id`),
  KEY `idx_ci_album_type_era_state` (`album_type`,`era`,`state`),
  KEY `idx_ci_album_owner_state` (`owner_principal_id`,`state`),
  CONSTRAINT `{$fk('album_owner', $this->table('album'))}` FOREIGN KEY (`owner_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('album_cover', $this->table('album'))}` FOREIGN KEY (`manual_cover_class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_album_type` CHECK (`album_type` IN ('OFFICIAL', 'COMMUNITY')),
  CONSTRAINT `chk_ci_album_era` CHECK (`era` IN ('HERITAGE', 'LIVING', 'MIXED')),
  CONSTRAINT `chk_ci_album_state` CHECK (`state` IN ('ACTIVE', 'HIDDEN', 'RETIRED')),
  CONSTRAINT `chk_ci_album_owner` CHECK ((`album_type` = 'OFFICIAL' AND `owner_principal_id` IS NULL) OR (`album_type` = 'COMMUNITY' AND `owner_principal_id` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$spotlight} (
  `spotlight_id` BINARY(16) NOT NULL,
  `owner_principal_id` BIGINT UNSIGNED NOT NULL,
  `class_album_id` BINARY(16) NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `active_owner_principal_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `state` = 'ACTIVE' THEN `owner_principal_id` ELSE NULL END) PERSISTENT,
  `starts_at` DATETIME(6) NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `cancelled_at` DATETIME(6) NULL,
  `cancelled_by_principal_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`spotlight_id`),
  UNIQUE KEY `uq_ci_spotlight_active_owner` (`active_owner_principal_id`),
  KEY `idx_ci_spotlight_state_expiry` (`state`,`expires_at`),
  KEY `idx_ci_spotlight_album_state` (`class_album_id`,`state`),
  CONSTRAINT `{$fk('spotlight_owner', $this->table('spotlight'))}` FOREIGN KEY (`owner_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('spotlight_album', $this->table('spotlight'))}` FOREIGN KEY (`class_album_id`) REFERENCES {$album} (`class_album_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('spotlight_cancel', $this->table('spotlight'))}` FOREIGN KEY (`cancelled_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_spotlight_state` CHECK (`state` IN ('ACTIVE', 'EXPIRED', 'CANCELLED')),
  CONSTRAINT `chk_ci_spotlight_duration` CHECK (TIMESTAMPDIFF(SECOND, `starts_at`, `expires_at`) = 86400),
  CONSTRAINT `chk_ci_spotlight_cancel` CHECK ((`state` = 'CANCELLED' AND `cancelled_at` IS NOT NULL AND `cancelled_by_principal_id` IS NOT NULL) OR (`state` <> 'CANCELLED' AND `cancelled_at` IS NULL AND `cancelled_by_principal_id` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$photoSource} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_photo_id` BINARY(16) NOT NULL,
  `source_kind` VARCHAR(24) NOT NULL,
  `provenance_code` VARCHAR(64) NOT NULL,
  `source_reference_digest` BINARY(32) NULL,
  `original_filename_digest` BINARY(32) NULL,
  `source_checksum` BINARY(32) NOT NULL,
  `byte_size` BIGINT UNSIGNED NOT NULL,
  `observed_at` DATETIME(6) NULL,
  `created_by_principal_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_photo_source_provenance` (`class_photo_id`,`source_kind`,`provenance_code`),
  KEY `idx_ci_photo_source_checksum` (`source_checksum`,`class_photo_id`),
  CONSTRAINT `{$fk('photo_source_photo', $this->table('photo_source'))}` FOREIGN KEY (`class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('photo_source_actor', $this->table('photo_source'))}` FOREIGN KEY (`created_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_photo_source_kind` CHECK (`source_kind` IN ('SUBMISSION', 'PIWIGO_IMPORT', 'PRIVATE_QA', 'PRIVATE_FULL', 'MIGRATION', 'OTHER')),
  CONSTRAINT `chk_ci_photo_source_size` CHECK (`byte_size` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$photoDuplicate} (
  `duplicate_id` BINARY(16) NOT NULL,
  `left_class_photo_id` BINARY(16) NOT NULL,
  `right_class_photo_id` BINARY(16) NOT NULL,
  `relation_kind` VARCHAR(16) NOT NULL,
  `similarity` DECIMAL(6,5) NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'CANDIDATE',
  `canonical_class_photo_id` BINARY(16) NULL,
  `active_alias_class_photo_id` BINARY(16) GENERATED ALWAYS AS (CASE WHEN `state` = 'CONSOLIDATED' AND `canonical_class_photo_id` = `left_class_photo_id` THEN `right_class_photo_id` WHEN `state` = 'CONSOLIDATED' AND `canonical_class_photo_id` = `right_class_photo_id` THEN `left_class_photo_id` ELSE NULL END) PERSISTENT,
  `created_by_principal_id` BIGINT UNSIGNED NOT NULL,
  `reviewed_by_principal_id` BIGINT UNSIGNED NULL,
  `reason` VARCHAR(500) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `reviewed_at` DATETIME(6) NULL,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`duplicate_id`),
  UNIQUE KEY `uq_ci_photo_duplicate_pair` (`left_class_photo_id`,`right_class_photo_id`,`relation_kind`),
  UNIQUE KEY `uq_ci_photo_duplicate_active_alias` (`active_alias_class_photo_id`),
  KEY `idx_ci_photo_duplicate_state_kind` (`state`,`relation_kind`,`updated_at`),
  CONSTRAINT `{$fk('photo_duplicate_left', $this->table('photo_duplicate'))}` FOREIGN KEY (`left_class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('photo_duplicate_right', $this->table('photo_duplicate'))}` FOREIGN KEY (`right_class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('photo_duplicate_canonical', $this->table('photo_duplicate'))}` FOREIGN KEY (`canonical_class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('photo_duplicate_created', $this->table('photo_duplicate'))}` FOREIGN KEY (`created_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('photo_duplicate_reviewed', $this->table('photo_duplicate'))}` FOREIGN KEY (`reviewed_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_photo_duplicate_kind` CHECK (`relation_kind` IN ('EXACT', 'NEAR')),
  CONSTRAINT `chk_ci_photo_duplicate_state` CHECK (`state` IN ('CANDIDATE', 'REJECTED', 'CONSOLIDATED', 'REVERTED')),
  CONSTRAINT `chk_ci_photo_duplicate_distinct` CHECK (`left_class_photo_id` <> `right_class_photo_id`),
  CONSTRAINT `chk_ci_photo_duplicate_similarity` CHECK (`similarity` IS NULL OR (`similarity` >= 0 AND `similarity` <= 1)),
  CONSTRAINT `chk_ci_photo_duplicate_canonical` CHECK ((`state` = 'CONSOLIDATED' AND (`canonical_class_photo_id` = `left_class_photo_id` OR `canonical_class_photo_id` = `right_class_photo_id`) AND `relation_kind` = 'EXACT') OR (`state` <> 'CONSOLIDATED' AND `canonical_class_photo_id` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$batchOperation} (
  `batch_id` BINARY(16) NOT NULL,
  `actor_principal_id` BIGINT UNSIGNED NOT NULL,
  `operation_type` VARCHAR(32) NOT NULL,
  `state` VARCHAR(24) NOT NULL DEFAULT 'PREPARED',
  `payload_digest` BINARY(32) NOT NULL,
  `item_count` INT UNSIGNED NOT NULL,
  `applied_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `high_risk_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
  `reason` VARCHAR(500) NOT NULL,
  `error_code` VARCHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `completed_at` DATETIME(6) NULL,
  PRIMARY KEY (`batch_id`),
  KEY `idx_ci_batch_state_created` (`state`,`created_at`),
  CONSTRAINT `{$fk('batch_actor', $this->table('batch_operation'))}` FOREIGN KEY (`actor_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_batch_type` CHECK (`operation_type` IN ('ARCHIVE_BULK_UPDATE', 'EXACT_DUPLICATE_CONSOLIDATE')),
  CONSTRAINT `chk_ci_batch_state` CHECK (`state` IN ('PREPARED', 'APPLIED', 'FAILED', 'COMPENSATED', 'MANUAL_REVIEW')),
  CONSTRAINT `chk_ci_batch_counts` CHECK (`item_count` > 0 AND `applied_count` <= `item_count` AND `failed_count` <= `item_count`),
  CONSTRAINT `chk_ci_batch_high_risk` CHECK (`high_risk_confirmed` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$batchItem} (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BINARY(16) NOT NULL,
  `class_photo_id` BINARY(16) NOT NULL,
  `state` VARCHAR(24) NOT NULL DEFAULT 'PREPARED',
  `before_value` JSON NOT NULL,
  `after_value` JSON NOT NULL,
  `error_code` VARCHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_batch_item_photo` (`batch_id`,`class_photo_id`),
  KEY `idx_ci_batch_item_state` (`batch_id`,`state`),
  CONSTRAINT `{$fk('batch_item_batch', $this->table('batch_operation_item'))}` FOREIGN KEY (`batch_id`) REFERENCES {$batchOperation} (`batch_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('batch_item_photo', $this->table('batch_operation_item'))}` FOREIGN KEY (`class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_batch_item_state` CHECK (`state` IN ('PREPARED', 'APPLIED', 'FAILED', 'COMPENSATED', 'MANUAL_REVIEW'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('person', [
            'class_person_id', 'manual_cover_class_photo_id', 'visibility', 'lock_version',
        ]);
        $this->assertTable('person_merge', [
            'merge_id', 'source_class_person_id', 'target_class_person_id', 'state',
            'active_source_class_person_id', 'created_by_principal_id', 'reverted_by_principal_id',
        ]);
        $this->assertTable('person_photo_rule', [
            'class_person_id', 'class_photo_id', 'rule', 'updated_by_principal_id',
        ]);
        $this->assertTable('album', [
            'class_album_id', 'piwigo_category_id', 'album_type', 'owner_principal_id',
            'era', 'description', 'event_label', 'manual_cover_class_photo_id', 'state',
        ]);
        $this->assertTable('spotlight', [
            'spotlight_id', 'owner_principal_id', 'class_album_id', 'state',
            'active_owner_principal_id', 'starts_at', 'expires_at',
        ]);
        $this->assertTable('photo_source', [
            'id', 'class_photo_id', 'source_kind', 'provenance_code', 'source_reference_digest',
            'original_filename_digest', 'source_checksum', 'byte_size',
        ]);
        $this->assertTable('photo_duplicate', [
            'duplicate_id', 'left_class_photo_id', 'right_class_photo_id', 'relation_kind',
            'state', 'canonical_class_photo_id', 'active_alias_class_photo_id',
        ]);
        $this->assertTable('batch_operation', [
            'batch_id', 'actor_principal_id', 'operation_type', 'state', 'payload_digest',
            'item_count', 'applied_count', 'failed_count', 'high_risk_confirmed',
        ]);
        $this->assertTable('batch_operation_item', [
            'id', 'batch_id', 'class_photo_id', 'state', 'before_value', 'after_value',
        ]);
    }

    /**
     * Authorization-neutral, generation-swapped Gateway read model.
     *
     * The tables deliberately contain no principal, role or visibility
     * result. They shorten source reads only; current authorization continues
     * to be evaluated by GatewayPolicy for every HTTP request.
     */
    private function migrationGatewayReadProjection(): void
    {
        $projection = $this->quotedTable('read_projection');
        $photo = $this->quotedTable('read_photo');
        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$projection} (
  `projection_key` VARCHAR(32) NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'STALE',
  `source_revision` BINARY(32) NULL,
  `generation` BINARY(16) NULL,
  `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `invalidated_reason` VARCHAR(64) NULL,
  `built_at` DATETIME(6) NULL,
  `invalidated_at` DATETIME(6) NULL,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`projection_key`),
  KEY `idx_ci_read_projection_state` (`state`,`updated_at`),
  CONSTRAINT `chk_ci_read_projection_key` CHECK (`projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT')),
  CONSTRAINT `chk_ci_read_projection_state` CHECK (`state` IN ('ACTIVE','STALE','BUILDING','FAILED')),
  CONSTRAINT `chk_ci_read_projection_active` CHECK ((`state` = 'ACTIVE' AND `source_revision` IS NOT NULL AND `generation` IS NOT NULL AND `built_at` IS NOT NULL AND `invalidated_reason` IS NULL AND `invalidated_at` IS NULL) OR (`state` <> 'ACTIVE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$photo} (
  `class_photo_id` BINARY(16) NOT NULL,
  `piwigo_image_id` MEDIUMINT(8) UNSIGNED NOT NULL,
  `era` VARCHAR(16) NOT NULL,
  `payload_json` JSON NOT NULL,
  `row_digest` BINARY(32) NOT NULL,
  `generation` BINARY(16) NOT NULL,
  `built_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`class_photo_id`),
  UNIQUE KEY `uq_ci_read_photo_piwigo` (`piwigo_image_id`),
  KEY `idx_ci_read_photo_generation` (`generation`,`class_photo_id`),
  KEY `idx_ci_read_photo_era` (`era`,`class_photo_id`),
  CONSTRAINT `chk_ci_read_photo_era` CHECK (`era` IN ('HERITAGE','LIVING'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'] as $key) {
            $escaped = $this->db->real_escape_string($key);
            $this->executeRaw("INSERT IGNORE INTO {$projection} (`projection_key`,`state`) VALUES ('{$escaped}','STALE')");
        }

        $this->assertTable('read_projection', [
            'projection_key', 'state', 'source_revision', 'generation', 'item_count',
            'invalidated_reason', 'built_at', 'invalidated_at', 'updated_at',
        ]);
        $this->assertTable('read_photo', [
            'class_photo_id', 'piwigo_image_id', 'era', 'payload_json', 'row_digest',
            'generation', 'built_at',
        ]);
    }

    /**
     * Durable role-scope aggregates. The JSON object contains only two
     * presentation scopes (FULL and HERITAGE); it never stores a principal,
     * Account, Seat or authorization decision. Runtime identity resolution
     * remains mandatory before a scope may be selected.
     */
    private function migrationGatewayAggregateProjection(): void
    {
        $projection = $this->quotedTable('read_projection');
        $this->ensureColumn(
            'read_projection',
            'payload_json',
            "ALTER TABLE {$projection} ADD COLUMN `payload_json` JSON NULL AFTER `item_count`",
        );
        $this->ensureColumn(
            'read_projection',
            'payload_digest',
            "ALTER TABLE {$projection} ADD COLUMN `payload_digest` BINARY(32) NULL AFTER `payload_json`",
        );
        $this->ensureColumn(
            'read_projection',
            'dependency_revision',
            "ALTER TABLE {$projection} ADD COLUMN `dependency_revision` BINARY(32) NULL AFTER `payload_digest`",
        );
        $this->ensureCheckConstraint(
            'read_projection',
            'chk_ci_read_projection_aggregate_active',
            "ALTER TABLE {$projection} ADD CONSTRAINT `chk_ci_read_projection_aggregate_active` CHECK ("
                . "`projection_key` = 'PHOTO_CATALOG' OR `state` <> 'ACTIVE' OR ("
                . '`payload_json` IS NOT NULL AND `payload_digest` IS NOT NULL AND `dependency_revision` IS NOT NULL))',
        );
        $this->assertTable('read_projection', [
            'projection_key', 'state', 'source_revision', 'generation', 'item_count',
            'payload_json', 'payload_digest', 'dependency_revision',
            'invalidated_reason', 'built_at', 'invalidated_at', 'updated_at',
        ]);
    }

    /**
     * Fail-closed bridge for Piwigo's native content mutation surfaces.
     *
     * Piwigo 16.4.0 exposes useful notifications for uploads and deletions,
     * but it has no complete pre-write event spanning image metadata, album
     * associations and album updates. Post-write hooks are insufficient for
     * a MyISAM source table: an unavailable projection database could leave
     * an old ACTIVE read model after the Core write has already succeeded.
     *
     * These BEFORE triggers are plugin-owned database objects, not Core file
     * changes. Every relevant native statement first rotates all five read
     * generations and marks them STALE. The exact ROW_COUNT assertion means
     * a missing projection row, a broken schema or an unavailable InnoDB
     * target aborts the native MyISAM write before it changes archive state.
     *
     * The images UPDATE guard intentionally excludes hit, rating_score and
     * lastmodified-only activity, so normal viewing does not invalidate the
     * catalog. Every field that can affect displayed metadata, delivery,
     * privacy, orientation or future location-aware presentation is covered.
     */
    private function migrationNativePiwigoProjectionGuard(): void
    {
        $this->assertNativePiwigoTable('images', [
            'id', 'file', 'date_available', 'date_creation', 'name', 'comment',
            'author', 'filesize', 'width', 'height', 'coi', 'representative_ext',
            'date_metadata_update', 'path', 'storage_category_id', 'level',
            'md5sum', 'added_by', 'rotation', 'latitude', 'longitude',
        ]);
        $this->assertNativePiwigoTable('image_category', ['image_id', 'category_id', 'rank']);
        $this->assertNativePiwigoTable('categories', ['id']);

        $this->replaceNativeProjectionTriggers($this->nativeProjectionTriggerDefinitions());
    }

    /**
     * Durable source epoch for Piwigo's MyISAM mutation boundary.
     *
     * A trigger that writes only InnoDB metadata is not sufficient when a
     * caller wraps a MyISAM source mutation in an explicit transaction: the
     * source bytes persist after ROLLBACK while the InnoDB invalidation can be
     * rolled back. This one-row MyISAM sentinel changes in the same
     * non-transactional durability domain as the protected Piwigo tables.
     * Every ACTIVE catalog is bound to its exact epoch and every read checks
     * that binding before serving presentation data.
     */
    private function migrationDurableNativeSourceEpoch(): void
    {
        $epoch = $this->quotedTable('native_source_epoch');
        $projection = $this->quotedTable('read_projection');
        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$epoch} (
  `source_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `generation` BINARY(16) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`source_key`),
  CONSTRAINT `chk_ci_native_source_epoch_key` CHECK (`source_key` = 'PIWIGO_NATIVE')
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->ensureColumn(
            'read_projection',
            'native_source_generation',
            "ALTER TABLE {$projection} ADD COLUMN `native_source_generation` BINARY(16) NULL AFTER `generation`",
        );
        $this->executeRaw(
            "INSERT IGNORE INTO {$epoch} (`source_key`,`generation`,`updated_at`) "
                . "VALUES ('PIWIGO_NATIVE',RANDOM_BYTES(16),UTC_TIMESTAMP(6))",
        );
        foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'] as $key) {
            $escaped = $this->db->real_escape_string($key);
            $this->executeRaw(
                "INSERT IGNORE INTO {$projection} (`projection_key`,`state`,`generation`,`invalidated_reason`,`invalidated_at`) "
                    . "VALUES ('{$escaped}','STALE',RANDOM_BYTES(16),'PROJECTION_EPOCH_INITIALIZED',UTC_TIMESTAMP(6))",
            );
        }
        // Introducing the durable epoch deliberately invalidates the complete
        // photo read model once. No pre-v12 ACTIVE catalog has an authenticated
        // binding to the new sentinel, so retaining it would be fail-open.
        $this->executeRaw(
            "UPDATE {$projection} SET `state`='STALE',`generation`=COALESCE(`generation`,RANDOM_BYTES(16)),"
                . "`native_source_generation`=CASE WHEN `projection_key`='PHOTO_CATALOG' THEN NULL ELSE `native_source_generation` END,"
                . "`invalidated_reason`='DURABLE_SOURCE_EPOCH_REQUIRED',`invalidated_at`=UTC_TIMESTAMP(6),"
                . "`updated_at`=UTC_TIMESTAMP(6) WHERE `projection_key` IN "
                . "('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES')",
        );
        $this->executeRaw(
            "UPDATE {$projection} SET `state`='STALE',`generation`=RANDOM_BYTES(16),"
                . "`invalidated_reason`='PROJECTION_EPOCH_INITIALIZED',`invalidated_at`=UTC_TIMESTAMP(6),"
                . "`updated_at`=UTC_TIMESTAMP(6) WHERE `generation` IS NULL",
        );
        $this->ensureCheckConstraint(
            'read_projection',
            'chk_ci_read_projection_native_epoch_active',
            "ALTER TABLE {$projection} ADD CONSTRAINT `chk_ci_read_projection_native_epoch_active` CHECK ("
                . "`projection_key` <> 'PHOTO_CATALOG' OR `state` <> 'ACTIVE' OR `native_source_generation` IS NOT NULL)",
        );

        // Add a second trigger set instead of replacing the already-installed
        // v11 guards. An online upgrade therefore never creates a window in
        // which native Piwigo writes are unguarded.
        foreach ($this->nativeSourceEpochTriggerDefinitions() as $definition) {
            $name = $this->quotedIdentifier($definition['name']);
            $table = $this->quotedIdentifier($definition['table']);
            $existing = $this->informationSchemaRows(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
                    . 'WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?',
                [$definition['name']],
            );
            if ($existing === []) {
                $this->executeRaw(
                    "CREATE TRIGGER {$name} BEFORE {$definition['event']} ON {$table} "
                    . 'FOR EACH ROW ' . $definition['statement'],
                );
            } elseif (count($existing) !== 1) {
                throw new \RuntimeException('class_identity_native_source_epoch_trigger_ambiguous');
            }
        }

        $this->assertTable('native_source_epoch', ['source_key', 'generation', 'updated_at'], 'MYISAM');
        $this->assertTable('read_projection', [
            'projection_key', 'state', 'source_revision', 'generation', 'native_source_generation', 'item_count',
            'payload_json', 'payload_digest', 'dependency_revision',
            'invalidated_reason', 'built_at', 'invalidated_at', 'updated_at',
        ]);
        $this->assertProjectionEpochsInitialized();
        $this->assertNativeProjectionTriggers($this->nativeSourceEpochTriggerDefinitions());
    }

    /**
     * Private full-library import state.
     *
     * The source filesystem remains outside the database.  These tables keep
     * only an allowlisted source collection code, displayable folder segment
     * names, and cryptographic digests of source paths / filenames.  That is
     * enough to resume a local import and preserve folder membership without
     * ever making a workstation path or original filename part of the product
     * data model.
     */
    private function migrationPrivateFullLibraryImport(): void
    {
        $principal = $this->quotedTable('principal');
        $photo = $this->quotedTable('photo');
        $album = $this->quotedTable('album');
        $collection = $this->quotedTable('private_library_collection');
        $folder = $this->quotedTable('private_library_folder');
        $import = $this->quotedTable('private_library_import');
        $item = $this->quotedTable('private_library_import_item');
        $photoSource = $this->quotedTable('photo_source');
        $fk = static fn(string $purpose, string $table): string => 'fk_ci_' . $purpose . '_'
            . substr(hash('sha256', $table), 0, 12);

        // Migration 8 created the first provenance vocabulary. A full local
        // library must remain distinguishable from the disposable sample QA
        // corpus, so converge that existing check before writing any v13
        // journal rows. Replacement is inspect/create safe across a DDL
        // interruption: a retry either finds the old check or reinstalls the
        // target check.
        $this->replaceCheckConstraint(
            'photo_source',
            'chk_ci_photo_source_kind',
            "ALTER TABLE {$photoSource} ADD CONSTRAINT `chk_ci_photo_source_kind` CHECK (`source_kind` IN ('SUBMISSION', 'PIWIGO_IMPORT', 'PRIVATE_QA', 'PRIVATE_FULL', 'MIGRATION', 'OTHER'))",
        );

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$collection} (
  `source_collection_id` BINARY(16) NOT NULL,
  `source_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `display_name` VARCHAR(190) NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `created_by_principal_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`source_collection_id`),
  UNIQUE KEY `uq_ci_private_library_source_code` (`source_code`),
  KEY `idx_ci_private_library_source_state` (`state`,`updated_at`),
  CONSTRAINT `{$fk('private_library_collection_actor', $this->table('private_library_collection'))}` FOREIGN KEY (`created_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_private_library_collection_code` CHECK (`source_code` IN ('PRIVATE_SOURCE_A', 'PRIVATE_SOURCE_B')),
  CONSTRAINT `chk_ci_private_library_collection_state` CHECK (`state` IN ('ACTIVE', 'RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$folder} (
  `folder_id` BINARY(16) NOT NULL,
  `source_collection_id` BINARY(16) NOT NULL,
  `relative_path_digest` BINARY(32) NOT NULL,
  `parent_folder_id` BINARY(16) NULL,
  `piwigo_category_id` MEDIUMINT(8) UNSIGNED NOT NULL,
  `class_album_id` BINARY(16) NOT NULL,
  `display_name` VARCHAR(190) NOT NULL,
  `depth` SMALLINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`folder_id`),
  UNIQUE KEY `uq_ci_private_library_folder_path` (`source_collection_id`,`relative_path_digest`),
  UNIQUE KEY `uq_ci_private_library_folder_category` (`piwigo_category_id`),
  UNIQUE KEY `uq_ci_private_library_folder_album` (`class_album_id`),
  KEY `idx_ci_private_library_folder_parent` (`parent_folder_id`,`display_name`),
  CONSTRAINT `{$fk('private_library_folder_collection', $this->table('private_library_folder'))}` FOREIGN KEY (`source_collection_id`) REFERENCES {$collection} (`source_collection_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('private_library_folder_parent', $this->table('private_library_folder'))}` FOREIGN KEY (`parent_folder_id`) REFERENCES {$folder} (`folder_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('private_library_folder_album', $this->table('private_library_folder'))}` FOREIGN KEY (`class_album_id`) REFERENCES {$album} (`class_album_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_private_library_folder_depth` CHECK (`depth` <= 255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$import} (
  `import_id` BINARY(16) NOT NULL,
  `manifest_digest` BINARY(32) NOT NULL,
  `manifest_version` SMALLINT UNSIGNED NOT NULL,
  `item_total` INT UNSIGNED NOT NULL,
  `state` VARCHAR(24) NOT NULL DEFAULT 'PREPARED',
  `applied_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `deduplicated_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_by_principal_id` BIGINT UNSIGNED NOT NULL,
  `started_at` DATETIME(6) NULL,
  `completed_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`import_id`),
  UNIQUE KEY `uq_ci_private_library_import_manifest` (`manifest_digest`),
  KEY `idx_ci_private_library_import_state` (`state`,`updated_at`),
  CONSTRAINT `{$fk('private_library_import_actor', $this->table('private_library_import'))}` FOREIGN KEY (`created_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_private_library_import_state` CHECK (`state` IN ('PREPARED', 'RUNNING', 'COMPLETED', 'COMPLETED_WITH_ERRORS', 'FAILED')),
  CONSTRAINT `chk_ci_private_library_import_counts` CHECK (`item_total` > 0 AND `applied_count` + `deduplicated_count` + `failed_count` <= `item_total`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$item} (
  `import_id` BINARY(16) NOT NULL,
  `item_digest` BINARY(32) NOT NULL,
  `source_collection_id` BINARY(16) NOT NULL,
  `folder_id` BINARY(16) NULL,
  `source_reference_digest` BINARY(32) NOT NULL,
  `original_filename_digest` BINARY(32) NOT NULL,
  `source_checksum` BINARY(32) NOT NULL,
  `staging_name_digest` BINARY(32) NOT NULL,
  `byte_size` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  `class_photo_id` BINARY(16) NULL,
  `piwigo_image_id` MEDIUMINT(8) UNSIGNED NULL,
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`import_id`,`item_digest`),
  KEY `idx_ci_private_library_item_state` (`import_id`,`state`,`updated_at`),
  KEY `idx_ci_private_library_item_checksum` (`source_checksum`,`state`),
  KEY `idx_ci_private_library_item_photo` (`class_photo_id`),
  CONSTRAINT `{$fk('private_library_item_import', $this->table('private_library_import_item'))}` FOREIGN KEY (`import_id`) REFERENCES {$import} (`import_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('private_library_item_collection', $this->table('private_library_import_item'))}` FOREIGN KEY (`source_collection_id`) REFERENCES {$collection} (`source_collection_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('private_library_item_folder', $this->table('private_library_import_item'))}` FOREIGN KEY (`folder_id`) REFERENCES {$folder} (`folder_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('private_library_item_photo', $this->table('private_library_import_item'))}` FOREIGN KEY (`class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_private_library_item_state` CHECK (`state` IN ('PENDING', 'PROCESSING', 'APPLIED', 'DEDUPLICATED', 'FAILED')),
  CONSTRAINT `chk_ci_private_library_item_size` CHECK (`byte_size` > 0),
  CONSTRAINT `chk_ci_private_library_item_target` CHECK ((`state` IN ('APPLIED', 'DEDUPLICATED') AND `class_photo_id` IS NOT NULL AND `piwigo_image_id` IS NOT NULL) OR (`state` = 'PENDING' AND `class_photo_id` IS NULL AND `piwigo_image_id` IS NULL) OR (`state` IN ('PROCESSING', 'FAILED') AND `class_photo_id` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('private_library_collection', [
            'source_collection_id', 'source_code', 'display_name', 'state', 'created_by_principal_id',
        ]);
        $this->assertTable('private_library_folder', [
            'folder_id', 'source_collection_id', 'relative_path_digest', 'parent_folder_id',
            'piwigo_category_id', 'class_album_id', 'display_name', 'depth',
        ]);
        $this->assertTable('private_library_import', [
            'import_id', 'manifest_digest', 'manifest_version', 'item_total', 'state',
            'applied_count', 'deduplicated_count', 'failed_count', 'last_error_code',
        ]);
        $this->assertTable('private_library_import_item', [
            'import_id', 'item_digest', 'source_collection_id', 'folder_id', 'source_reference_digest',
            'original_filename_digest', 'source_checksum', 'staging_name_digest', 'byte_size',
            'state', 'class_photo_id', 'piwigo_image_id', 'attempt_count', 'last_error_code',
        ]);
    }

    /**
     * The import journal is deliberately able to retain a verified native
     * Piwigo image checkpoint while the InnoDB canonical mapping is still
     * PROCESSING or after a retryable FAILED item.  Without this state, a
     * crash between Piwigo's MyISAM write and mapping publication either
     * loses the recovery anchor or forces a duplicate original.  A canonical
     * photo id remains forbidden until APPLIED/DEDUPLICATED.
     */
    private function migrationPrivateFullNativeCheckpointRecovery(): void
    {
        $item = $this->quotedTable('private_library_import_item');
        $this->replaceCheckConstraint(
            'private_library_import_item',
            'chk_ci_private_library_item_target',
            "ALTER TABLE {$item} ADD CONSTRAINT `chk_ci_private_library_item_target` CHECK ((`state` IN ('APPLIED', 'DEDUPLICATED') AND `class_photo_id` IS NOT NULL AND `piwigo_image_id` IS NOT NULL) OR (`state` = 'PENDING' AND `class_photo_id` IS NULL AND `piwigo_image_id` IS NULL) OR (`state` IN ('PROCESSING', 'FAILED') AND `class_photo_id` IS NULL))",
        );
    }

    /**
     * Collections-first is intentionally an overlay on the immutable private
     * source graph and Piwigo's category/media graph.  A display alias never
     * replaces an importer path or source collection identity; comments are a
     * small, threaded business interaction domain rather than an attempt to
     * turn Piwigo's flat Core comment surface back on; and AI records retain
     * only bounded control-plane state, never embeddings or model bytes.
     */
    private function migrationCollectionsFirstCommentsAndAiIndex(): void
    {
        $album = $this->quotedTable('album');
        $photo = $this->quotedTable('photo');
        $principal = $this->quotedTable('principal');
        $comment = $this->quotedTable('photo_comment');
        $autoCollection = $this->quotedTable('auto_collection');
        $autoCollectionPhoto = $this->quotedTable('auto_collection_photo');
        $aiIndex = $this->quotedTable('ai_asset_index');
        $aiJob = $this->quotedTable('ai_index_job');
        $fk = static fn(string $purpose, string $table): string => 'fk_ci_' . $purpose . '_'
            . substr(hash('sha256', $table), 0, 12);

        // Keep the source/importer identity untouched.  The alias is the
        // only member-facing override and is deliberately nullable so an
        // absent alias means "use the Piwigo category display name".
        $this->ensureColumn(
            'album',
            'display_alias',
            'ALTER TABLE ' . $album . ' ADD COLUMN `display_alias` VARCHAR(190) NULL AFTER `event_label`',
        );

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$comment} (
  `comment_id` BINARY(16) NOT NULL,
  `class_photo_id` BINARY(16) NOT NULL,
  `parent_comment_id` BINARY(16) NULL,
  `author_principal_id` BIGINT UNSIGNED NOT NULL,
  `author_role` VARCHAR(16) NOT NULL,
  `body` TEXT NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `deleted_by_principal_id` BIGINT UNSIGNED NULL,
  `delete_reason` VARCHAR(500) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `deleted_at` DATETIME(6) NULL,
  PRIMARY KEY (`comment_id`),
  KEY `idx_ci_photo_comment_photo_state_created` (`class_photo_id`,`state`,`created_at`,`comment_id`),
  KEY `idx_ci_photo_comment_parent_created` (`parent_comment_id`,`created_at`,`comment_id`),
  KEY `idx_ci_photo_comment_author_state` (`author_principal_id`,`state`,`created_at`),
  CONSTRAINT `{$fk('photo_comment_photo', $this->table('photo_comment'))}` FOREIGN KEY (`class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('photo_comment_parent', $this->table('photo_comment'))}` FOREIGN KEY (`parent_comment_id`) REFERENCES {$comment} (`comment_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('photo_comment_author', $this->table('photo_comment'))}` FOREIGN KEY (`author_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('photo_comment_deleted_by', $this->table('photo_comment'))}` FOREIGN KEY (`deleted_by_principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_photo_comment_author_role` CHECK (`author_role` IN ('CLASSMATE','TEACHER','ANONYMOUS','SYSTEM_ADMIN')),
  CONSTRAINT `chk_ci_photo_comment_state` CHECK (`state` IN ('ACTIVE','DELETED')),
  CONSTRAINT `chk_ci_photo_comment_parent_distinct` CHECK (`parent_comment_id` IS NULL OR `parent_comment_id` <> `comment_id`),
  CONSTRAINT `chk_ci_photo_comment_delete` CHECK ((`state` = 'ACTIVE' AND `deleted_by_principal_id` IS NULL AND `delete_reason` IS NULL AND `deleted_at` IS NULL) OR (`state` = 'DELETED' AND `deleted_by_principal_id` IS NOT NULL AND `delete_reason` IS NOT NULL AND `deleted_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Auto collections deliberately persist their business description
        // and exact candidate membership.  Gateway still filters both the
        // cover and member count for the current role on every response.
        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$autoCollection} (
  `auto_collection_id` BINARY(16) NOT NULL,
  `collection_kind` VARCHAR(16) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `subtitle` VARCHAR(190) NULL,
  `source_reason` VARCHAR(64) NOT NULL,
  `archive_date` DATE NULL,
  `date_precision` VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
  `cover_class_photo_id` BINARY(16) NOT NULL,
  `visibility_scope` VARCHAR(24) NOT NULL DEFAULT 'POLICY_FILTERED',
  `projection_revision` BINARY(32) NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `generated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`auto_collection_id`),
  UNIQUE KEY `uq_ci_auto_collection_source_reason` (`source_reason`),
  KEY `idx_ci_auto_collection_state_kind_updated` (`state`,`collection_kind`,`updated_at`),
  CONSTRAINT `{$fk('auto_collection_cover', $this->table('auto_collection'))}` FOREIGN KEY (`cover_class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_auto_collection_kind` CHECK (`collection_kind` IN ('MEMORY','RECENT','CURATED')),
  CONSTRAINT `chk_ci_auto_collection_precision` CHECK (`date_precision` IN ('EXACT','DAY','MONTH','TERM','YEAR','EVENT_ONLY','UNKNOWN')),
  CONSTRAINT `chk_ci_auto_collection_scope` CHECK (`visibility_scope` = 'POLICY_FILTERED'),
  CONSTRAINT `chk_ci_auto_collection_state` CHECK (`state` IN ('ACTIVE','RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$autoCollectionPhoto} (
  `auto_collection_id` BINARY(16) NOT NULL,
  `class_photo_id` BINARY(16) NOT NULL,
  `ordinal` INT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`auto_collection_id`,`class_photo_id`),
  UNIQUE KEY `uq_ci_auto_collection_ordinal` (`auto_collection_id`,`ordinal`),
  KEY `idx_ci_auto_collection_photo` (`class_photo_id`,`auto_collection_id`),
  CONSTRAINT `{$fk('auto_collection_photo_collection', $this->table('auto_collection_photo'))}` FOREIGN KEY (`auto_collection_id`) REFERENCES {$autoCollection} (`auto_collection_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('auto_collection_photo_photo', $this->table('auto_collection_photo'))}` FOREIGN KEY (`class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Embeddings and face vectors stay inside the separately isolated
        // Immich/Postgres runtime.  This table binds their version/checksum
        // state to the canonical photo without copying a vector, original
        // path, or an Immich authorization decision into Class Archive.
        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$aiIndex} (
  `class_photo_id` BINARY(16) NOT NULL,
  `source_checksum` BINARY(32) NOT NULL,
  `immich_asset_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `face_state` VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  `search_state` VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  `face_model_name` VARCHAR(190) NULL,
  `face_model_revision` VARCHAR(190) NULL,
  `search_model_name` VARCHAR(190) NULL,
  `search_model_revision` VARCHAR(190) NULL,
  `indexed_at` DATETIME(6) NULL,
  `last_error_code` VARCHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`class_photo_id`),
  KEY `idx_ci_ai_asset_index_state` (`face_state`,`search_state`,`updated_at`),
  KEY `idx_ci_ai_asset_index_immich` (`immich_asset_id`),
  CONSTRAINT `{$fk('ai_asset_index_photo', $this->table('ai_asset_index'))}` FOREIGN KEY (`class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_ai_asset_face_state` CHECK (`face_state` IN ('PENDING','INDEXED','UNAVAILABLE','FAILED','STALE','REMOVED')),
  CONSTRAINT `chk_ci_ai_asset_search_state` CHECK (`search_state` IN ('PENDING','INDEXED','UNAVAILABLE','FAILED','STALE','REMOVED')),
  CONSTRAINT `chk_ci_ai_asset_indexed` CHECK ((`face_state` = 'INDEXED' OR `search_state` = 'INDEXED') = (`indexed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$aiJob} (
  `job_id` BINARY(16) NOT NULL,
  `class_photo_id` BINARY(16) NULL,
  `job_kind` VARCHAR(16) NOT NULL,
  `trigger_kind` VARCHAR(24) NOT NULL,
  `expected_checksum` BINARY(32) NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  `active_photo_id` BINARY(16) GENERATED ALWAYS AS (CASE WHEN `state` IN ('PENDING','RUNNING') THEN `class_photo_id` ELSE NULL END) PERSISTENT,
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `not_before` DATETIME(6) NULL,
  `last_error_code` VARCHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `completed_at` DATETIME(6) NULL,
  PRIMARY KEY (`job_id`),
  UNIQUE KEY `uq_ci_ai_index_job_active_photo_kind` (`active_photo_id`,`job_kind`),
  KEY `idx_ci_ai_index_job_state_not_before` (`state`,`not_before`,`created_at`),
  KEY `idx_ci_ai_index_job_photo_state` (`class_photo_id`,`state`,`updated_at`),
  CONSTRAINT `{$fk('ai_index_job_photo', $this->table('ai_index_job'))}` FOREIGN KEY (`class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_ai_index_job_kind` CHECK (`job_kind` IN ('INDEX_ASSET','DELETE_ASSET','REINDEX_MODEL')),
  CONSTRAINT `chk_ci_ai_index_job_trigger` CHECK (`trigger_kind` IN ('NEW_PHOTO','PIXEL_CHANGED','PHOTO_DELETED','MODEL_CHANGED','ADMIN_REINDEX','RECONCILIATION')),
  CONSTRAINT `chk_ci_ai_index_job_state` CHECK (`state` IN ('PENDING','RUNNING','UNAVAILABLE','FAILED','COMPLETE','CANCELLED')),
  CONSTRAINT `chk_ci_ai_index_job_attempt` CHECK (`attempt_count` <= 100),
  CONSTRAINT `chk_ci_ai_index_job_completion` CHECK ((`state` IN ('UNAVAILABLE','FAILED','COMPLETE','CANCELLED') AND `completed_at` IS NOT NULL) OR (`state` IN ('PENDING','RUNNING') AND `completed_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('album', [
            'class_album_id', 'piwigo_category_id', 'display_alias', 'album_type', 'era', 'state',
        ]);
        $this->assertTable('photo_comment', [
            'comment_id', 'class_photo_id', 'parent_comment_id', 'author_principal_id', 'author_role',
            'body', 'state', 'deleted_by_principal_id', 'delete_reason', 'created_at', 'deleted_at',
        ]);
        $this->assertTable('auto_collection', [
            'auto_collection_id', 'collection_kind', 'title', 'subtitle', 'source_reason', 'archive_date',
            'date_precision', 'cover_class_photo_id', 'visibility_scope', 'projection_revision', 'state',
        ]);
        $this->assertTable('auto_collection_photo', [
            'auto_collection_id', 'class_photo_id', 'ordinal',
        ]);
        $this->assertTable('ai_asset_index', [
            'class_photo_id', 'source_checksum', 'immich_asset_id', 'face_state', 'search_state',
            'face_model_name', 'face_model_revision', 'search_model_name', 'search_model_revision', 'indexed_at', 'created_at',
        ]);
        $this->assertTable('ai_index_job', [
            'job_id', 'class_photo_id', 'job_kind', 'trigger_kind', 'expected_checksum', 'state',
            'active_photo_id', 'attempt_count', 'not_before', 'completed_at',
        ]);
    }

    /**
     * A presentation surrogate never replaces source provenance.  The
     * original source digest remains on photo_source while this one-to-one
     * extension binds the exact bytes delivered by MediaGuard to a fixed,
     * reproducible conversion recipe.  Raw paths and filenames are still
     * deliberately absent.
     */
    private function migrationPrivateSourcePresentationSurrogate(): void
    {
        $source = $this->quotedTable('photo_source');
        $presentation = $this->quotedTable('photo_source_presentation');
        $fk = static fn(string $purpose, string $table): string => 'fk_ci_' . $purpose . '_'
            . substr(hash('sha256', $table), 0, 12);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$presentation} (
  `photo_source_id` BIGINT UNSIGNED NOT NULL,
  `source_identity_digest` BINARY(32) NOT NULL,
  `presentation_checksum` BINARY(32) NOT NULL,
  `presentation_byte_size` BIGINT UNSIGNED NOT NULL,
  `source_format` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `presentation_format` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transform_kind` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transform_tool` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transform_version` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transform_recipe_digest` BINARY(32) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`photo_source_id`),
  UNIQUE KEY `uq_ci_photo_source_presentation_identity` (`source_identity_digest`),
  KEY `idx_ci_photo_source_presentation_checksum` (`presentation_checksum`,`photo_source_id`),
  CONSTRAINT `{$fk('photo_source_presentation_source', $this->table('photo_source_presentation'))}` FOREIGN KEY (`photo_source_id`) REFERENCES {$source} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_photo_source_presentation_size` CHECK (`presentation_byte_size` > 0),
  CONSTRAINT `chk_ci_photo_source_presentation_source_format` CHECK (`source_format` = 'MPO'),
  CONSTRAINT `chk_ci_photo_source_presentation_format` CHECK (`presentation_format` = 'JPEG'),
  CONSTRAINT `chk_ci_photo_source_presentation_kind` CHECK (`transform_kind` = 'MPO_PRIMARY_FRAME_JPEG'),
  CONSTRAINT `chk_ci_photo_source_presentation_tool` CHECK (`transform_tool` = 'PILLOW')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('photo_source_presentation', [
            'photo_source_id', 'source_identity_digest', 'presentation_checksum', 'presentation_byte_size',
            'source_format', 'presentation_format', 'transform_kind', 'transform_tool', 'transform_version',
            'transform_recipe_digest', 'created_at',
        ]);
    }

    /**
     * Versioned, policy-scoped Collection snapshots for the Photos App v4.
     *
     * Existing `auto_collection` rows remain the durable v15 Memory mirror;
     * they are intentionally not repurposed here because v15 updates one row
     * in place.  A v17 snapshot is immutable after publication, and a pointer
     * swaps the active revision in the same InnoDB transaction that retires the
     * prior revision.  This keeps a valid previous snapshot available whenever
     * a later build fails before publication.
     */
    private function migrationPhotosAppV4CollectionSnapshots(): void
    {
        $photo = $this->quotedTable('photo');
        $principal = $this->quotedTable('principal');
        $snapshot = $this->quotedTable('collection_snapshot');
        $item = $this->quotedTable('collection_snapshot_item');
        $pointer = $this->quotedTable('collection_snapshot_pointer');
        $pin = $this->quotedTable('collection_pin');
        $feedback = $this->quotedTable('collection_feedback');
        $maintenance = $this->quotedTable('collection_maintenance_state');
        $fk = static fn(string $purpose, string $table): string => 'fk_ci_' . $purpose . '_'
            . substr(hash('sha256', $table), 0, 12);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$snapshot} (
  `snapshot_id` BINARY(16) NOT NULL,
  `scope` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `projection_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'BUILDING',
  `input_revision` BINARY(32) NOT NULL,
  `payload_digest` BINARY(32) NOT NULL,
  `item_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `published_at` DATETIME(6) NULL,
  `superseded_at` DATETIME(6) NULL,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`snapshot_id`),
  UNIQUE KEY `uq_ci_collection_snapshot_scope_kind_id` (`scope`,`projection_kind`,`snapshot_id`),
  KEY `idx_ci_collection_snapshot_scope_kind_state` (`scope`,`projection_kind`,`state`,`published_at`),
  KEY `idx_ci_collection_snapshot_input_revision` (`scope`,`projection_kind`,`input_revision`,`state`),
  CONSTRAINT `chk_ci_collection_snapshot_scope` CHECK (`scope` IN ('FULL','HERITAGE')),
  CONSTRAINT `chk_ci_collection_snapshot_kind` CHECK (`projection_kind` IN ('HOME','MEMORY','SPOTLIGHT','SEARCH_SUGGESTION')),
  CONSTRAINT `chk_ci_collection_snapshot_state` CHECK (`state` IN ('BUILDING','ACTIVE','SUPERSEDED','FAILED')),
  CONSTRAINT `chk_ci_collection_snapshot_count` CHECK (`item_count` <= 10000),
  CONSTRAINT `chk_ci_collection_snapshot_timestamps` CHECK ((`state` = 'BUILDING' AND `published_at` IS NULL AND `superseded_at` IS NULL) OR (`state` = 'ACTIVE' AND `published_at` IS NOT NULL AND `superseded_at` IS NULL) OR (`state` = 'SUPERSEDED' AND `published_at` IS NOT NULL AND `superseded_at` IS NOT NULL) OR (`state` = 'FAILED' AND `published_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$item} (
  `snapshot_id` BINARY(16) NOT NULL,
  `ordinal` SMALLINT UNSIGNED NOT NULL,
  `item_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `cover_class_photo_id` BINARY(16) NULL,
  `photo_ids_json` JSON NOT NULL,
  `payload_json` JSON NOT NULL,
  `payload_digest` BINARY(32) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`snapshot_id`,`ordinal`),
  UNIQUE KEY `uq_ci_collection_snapshot_item_key` (`snapshot_id`,`item_kind`,`item_key`),
  KEY `idx_ci_collection_snapshot_item_cover` (`cover_class_photo_id`,`snapshot_id`),
  CONSTRAINT `{$fk('collection_snapshot_item_snapshot', $this->table('collection_snapshot_item'))}` FOREIGN KEY (`snapshot_id`) REFERENCES {$snapshot} (`snapshot_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `{$fk('collection_snapshot_item_cover', $this->table('collection_snapshot_item'))}` FOREIGN KEY (`cover_class_photo_id`) REFERENCES {$photo} (`class_photo_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_collection_snapshot_item_kind` CHECK (`item_kind` IN ('AUTO_COLLECTION','ALBUM','PERSON','SPOTLIGHT','PHOTO','SEARCH_SUGGESTION')),
  CONSTRAINT `chk_ci_collection_snapshot_item_key` CHECK (CHAR_LENGTH(`item_key`) BETWEEN 1 AND 96),
  CONSTRAINT `chk_ci_collection_snapshot_item_ordinal` CHECK (`ordinal` < 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$pointer} (
  `scope` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `projection_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `active_snapshot_id` BINARY(16) NOT NULL,
  `active_revision` BINARY(32) NOT NULL,
  `activated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`scope`,`projection_kind`),
  KEY `idx_ci_collection_snapshot_pointer_active` (`active_snapshot_id`),
  CONSTRAINT `{$fk('collection_snapshot_pointer_snapshot', $this->table('collection_snapshot_pointer'))}` FOREIGN KEY (`scope`,`projection_kind`,`active_snapshot_id`) REFERENCES {$snapshot} (`scope`,`projection_kind`,`snapshot_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_collection_snapshot_pointer_scope` CHECK (`scope` IN ('FULL','HERITAGE')),
  CONSTRAINT `chk_ci_collection_snapshot_pointer_kind` CHECK (`projection_kind` IN ('HOME','MEMORY','SPOTLIGHT','SEARCH_SUGGESTION'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$pin} (
  `pin_id` BINARY(16) NOT NULL,
  `principal_id` BIGINT UNSIGNED NOT NULL,
  `scope` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `projection_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ordinal` INT UNSIGNED NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE',
  `active_target` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN `state` = 'ACTIVE' THEN CONCAT(`projection_kind`, ':', `item_kind`, ':', `item_key`) ELSE NULL END) PERSISTENT,
  `active_ordinal` INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `state` = 'ACTIVE' THEN `ordinal` ELSE NULL END) PERSISTENT,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `removed_at` DATETIME(6) NULL,
  PRIMARY KEY (`pin_id`),
  UNIQUE KEY `uq_ci_collection_pin_active_target` (`principal_id`,`scope`,`active_target`),
  UNIQUE KEY `uq_ci_collection_pin_active_ordinal` (`principal_id`,`scope`,`active_ordinal`),
  KEY `idx_ci_collection_pin_principal_scope_state` (`principal_id`,`scope`,`state`,`ordinal`),
  CONSTRAINT `{$fk('collection_pin_principal', $this->table('collection_pin'))}` FOREIGN KEY (`principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_collection_pin_scope` CHECK (`scope` IN ('FULL','HERITAGE')),
  CONSTRAINT `chk_ci_collection_pin_projection_kind` CHECK (`projection_kind` IN ('HOME','MEMORY','SPOTLIGHT','SEARCH_SUGGESTION')),
  CONSTRAINT `chk_ci_collection_pin_item_kind` CHECK (`item_kind` IN ('AUTO_COLLECTION','ALBUM','PERSON','SPOTLIGHT','PHOTO','SEARCH_SUGGESTION')),
  CONSTRAINT `chk_ci_collection_pin_state` CHECK (`state` IN ('ACTIVE','REMOVED')),
  CONSTRAINT `chk_ci_collection_pin_removed` CHECK ((`state` = 'ACTIVE' AND `removed_at` IS NULL) OR (`state` = 'REMOVED' AND `removed_at` IS NOT NULL)),
  CONSTRAINT `chk_ci_collection_pin_ordinal` CHECK (`ordinal` < 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$feedback} (
  `feedback_id` BINARY(16) NOT NULL,
  `principal_id` BIGINT UNSIGNED NOT NULL,
  `scope` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `projection_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `feedback_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE',
  `active_target` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN `state` = 'ACTIVE' THEN CONCAT(`projection_kind`, ':', `item_kind`, ':', `item_key`) ELSE NULL END) PERSISTENT,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `retracted_at` DATETIME(6) NULL,
  PRIMARY KEY (`feedback_id`),
  UNIQUE KEY `uq_ci_collection_feedback_active_target` (`principal_id`,`scope`,`active_target`),
  KEY `idx_ci_collection_feedback_principal_scope_state` (`principal_id`,`scope`,`state`,`updated_at`),
  CONSTRAINT `{$fk('collection_feedback_principal', $this->table('collection_feedback'))}` FOREIGN KEY (`principal_id`) REFERENCES {$principal} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_collection_feedback_scope` CHECK (`scope` IN ('FULL','HERITAGE')),
  CONSTRAINT `chk_ci_collection_feedback_projection_kind` CHECK (`projection_kind` IN ('HOME','MEMORY','SPOTLIGHT','SEARCH_SUGGESTION')),
  CONSTRAINT `chk_ci_collection_feedback_item_kind` CHECK (`item_kind` IN ('AUTO_COLLECTION','ALBUM','PERSON','SPOTLIGHT','PHOTO','SEARCH_SUGGESTION')),
  CONSTRAINT `chk_ci_collection_feedback_kind` CHECK (`feedback_kind` IN ('HIDE','LESS_LIKE','LIKE')),
  CONSTRAINT `chk_ci_collection_feedback_state` CHECK (`state` IN ('ACTIVE','RETRACTED')),
  CONSTRAINT `chk_ci_collection_feedback_retracted` CHECK ((`state` = 'ACTIVE' AND `retracted_at` IS NULL) OR (`state` = 'RETRACTED' AND `retracted_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$maintenance} (
  `maintenance_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'IDLE',
  `last_input_revision` BINARY(32) NULL,
  `last_snapshot_id` BINARY(16) NULL,
  `started_at` DATETIME(6) NULL,
  `completed_at` DATETIME(6) NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`maintenance_key`),
  KEY `idx_ci_collection_maintenance_state_updated` (`state`,`updated_at`),
  CONSTRAINT `{$fk('collection_maintenance_snapshot', $this->table('collection_maintenance_state'))}` FOREIGN KEY (`last_snapshot_id`) REFERENCES {$snapshot} (`snapshot_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_collection_maintenance_key` CHECK (CHAR_LENGTH(`maintenance_key`) BETWEEN 1 AND 64),
  CONSTRAINT `chk_ci_collection_maintenance_state` CHECK (`state` IN ('IDLE','RUNNING','COMPLETE','FAILED')),
  CONSTRAINT `chk_ci_collection_maintenance_timestamps` CHECK ((`state` = 'IDLE' AND `started_at` IS NULL AND `completed_at` IS NULL) OR (`state` = 'RUNNING' AND `started_at` IS NOT NULL AND `completed_at` IS NULL) OR (`state` IN ('COMPLETE','FAILED') AND `started_at` IS NOT NULL AND `completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('collection_snapshot', [
            'snapshot_id', 'scope', 'projection_kind', 'state', 'input_revision', 'payload_digest', 'item_count',
            'created_at', 'published_at', 'superseded_at', 'updated_at',
        ]);
        $this->assertTable('collection_snapshot_item', [
            'snapshot_id', 'ordinal', 'item_kind', 'item_key', 'cover_class_photo_id', 'photo_ids_json',
            'payload_json', 'payload_digest', 'created_at',
        ]);
        $this->assertTable('collection_snapshot_pointer', [
            'scope', 'projection_kind', 'active_snapshot_id', 'active_revision', 'activated_at', 'updated_at',
        ]);
        $this->assertTable('collection_pin', [
            'pin_id', 'principal_id', 'scope', 'projection_kind', 'item_kind', 'item_key', 'ordinal', 'state',
            'active_target', 'active_ordinal', 'created_at', 'updated_at', 'removed_at',
        ]);
        $this->assertTable('collection_feedback', [
            'feedback_id', 'principal_id', 'scope', 'projection_kind', 'item_kind', 'item_key', 'feedback_kind',
            'state', 'active_target', 'created_at', 'updated_at', 'retracted_at',
        ]);
        $this->assertTable('collection_maintenance_state', [
            'maintenance_key', 'state', 'last_input_revision', 'last_snapshot_id', 'started_at', 'completed_at',
            'last_error_code', 'created_at', 'updated_at',
        ]);
    }

    /**
     * Bounded, policy-scoped Spotlight rotation state for the Photos App v4.
     *
     * This is intentionally separate from the immutable snapshot history and
     * the user-created 24-hour `spotlight` records. A scope has at most one
     * mutable scheduling row, so a job can atomically retain the last selected
     * opaque spotlight, its exact candidate-set digest, and the next eligible
     * rotation time without growing an unbounded operational log. The service
     * remains responsible for policy evaluation and deterministic selection;
     * this table only persists a fail-closed scheduling checkpoint.
     */
    private function migrationPhotosAppV4SpotlightRotationState(): void
    {
        $spotlight = $this->quotedTable('spotlight');
        $rotation = $this->quotedTable('spotlight_rotation_state');
        $fk = static fn(string $purpose, string $table): string => 'fk_ci_' . $purpose . '_'
            . substr(hash('sha256', $table), 0, 12);

        $this->executeRaw(<<<SQL
CREATE TABLE IF NOT EXISTS {$rotation} (
  `scope` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `hero_spotlight_id` BINARY(16) NULL,
  `candidate_digest` BINARY(32) NOT NULL,
  `display_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_rotated_at` DATETIME(6) NULL,
  `next_rotation_at` DATETIME(6) NOT NULL,
  `revision` BINARY(32) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`scope`),
  KEY `idx_ci_spotlight_rotation_due` (`next_rotation_at`,`scope`),
  KEY `idx_ci_spotlight_rotation_hero` (`hero_spotlight_id`),
  CONSTRAINT `{$fk('spotlight_rotation_hero', $this->table('spotlight_rotation_state'))}` FOREIGN KEY (`hero_spotlight_id`) REFERENCES {$spotlight} (`spotlight_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_spotlight_rotation_scope` CHECK (`scope` IN ('FULL','HERITAGE')),
  CONSTRAINT `chk_ci_spotlight_rotation_display_count` CHECK (`display_count` <= 9223372036854775807),
  CONSTRAINT `chk_ci_spotlight_rotation_schedule` CHECK ((`last_rotated_at` IS NULL) OR (`next_rotation_at` > `last_rotated_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->assertTable('spotlight_rotation_state', [
            'scope', 'hero_spotlight_id', 'candidate_digest', 'display_count', 'last_rotated_at',
            'next_rotation_at', 'revision', 'created_at', 'updated_at',
        ]);
    }

    /**
     * @return list<array{name:string,table:string,event:string,statement:string}>
     */
    private function nativeProjectionTriggerDefinitions(): array
    {
        $images = $this->piwigoPrefix . 'images';
        $imageCategory = $this->piwigoPrefix . 'image_category';
        $categories = $this->piwigoPrefix . 'categories';
        $invalidation = $this->nativeProjectionInvalidationStatement();
        $imageFields = [
            'file', 'date_available', 'date_creation', 'name', 'comment', 'author',
            'filesize', 'width', 'height', 'coi', 'representative_ext',
            'date_metadata_update', 'path', 'storage_category_id', 'level',
            'md5sum', 'added_by', 'rotation', 'latitude', 'longitude',
        ];
        $changed = implode(
            ' OR ',
            array_map(
                static fn(string $field): string => 'NOT (OLD.`' . $field . '` <=> NEW.`' . $field . '`)',
                $imageFields,
            ),
        );

        return [
            $this->nativeTriggerDefinition('images_bi', $images, 'INSERT', "BEGIN {$invalidation} END"),
            $this->nativeTriggerDefinition(
                'images_bu',
                $images,
                'UPDATE',
                "BEGIN IF {$changed} THEN {$invalidation} END IF; END",
            ),
            $this->nativeTriggerDefinition('images_bd', $images, 'DELETE', "BEGIN {$invalidation} END"),
            $this->nativeTriggerDefinition('image_category_bi', $imageCategory, 'INSERT', "BEGIN {$invalidation} END"),
            $this->nativeTriggerDefinition('image_category_bu', $imageCategory, 'UPDATE', "BEGIN {$invalidation} END"),
            $this->nativeTriggerDefinition('image_category_bd', $imageCategory, 'DELETE', "BEGIN {$invalidation} END"),
            $this->nativeTriggerDefinition('categories_bi', $categories, 'INSERT', "BEGIN {$invalidation} END"),
            $this->nativeTriggerDefinition('categories_bu', $categories, 'UPDATE', "BEGIN {$invalidation} END"),
            $this->nativeTriggerDefinition('categories_bd', $categories, 'DELETE', "BEGIN {$invalidation} END"),
        ];
    }

    /** @return list<array{name:string,table:string,event:string,statement:string}> */
    private function nativeSourceEpochTriggerDefinitions(): array
    {
        $images = $this->piwigoPrefix . 'images';
        $imageCategory = $this->piwigoPrefix . 'image_category';
        $categories = $this->piwigoPrefix . 'categories';
        $invalidation = $this->nativeSourceEpochInvalidationStatement();
        $imageFields = [
            'file', 'date_available', 'date_creation', 'name', 'comment', 'author',
            'filesize', 'width', 'height', 'coi', 'representative_ext',
            'date_metadata_update', 'path', 'storage_category_id', 'level',
            'md5sum', 'added_by', 'rotation', 'latitude', 'longitude',
        ];
        $changed = implode(
            ' OR ',
            array_map(
                static fn(string $field): string => 'NOT (OLD.`' . $field . '` <=> NEW.`' . $field . '`)',
                $imageFields,
            ),
        );
        return [
            $this->nativeSourceEpochTriggerDefinition('images_bi', $images, 'INSERT', "BEGIN {$invalidation} END"),
            $this->nativeSourceEpochTriggerDefinition('images_bu', $images, 'UPDATE', "BEGIN IF {$changed} THEN {$invalidation} END IF; END"),
            $this->nativeSourceEpochTriggerDefinition('images_bd', $images, 'DELETE', "BEGIN {$invalidation} END"),
            $this->nativeSourceEpochTriggerDefinition('image_category_bi', $imageCategory, 'INSERT', "BEGIN {$invalidation} END"),
            $this->nativeSourceEpochTriggerDefinition('image_category_bu', $imageCategory, 'UPDATE', "BEGIN {$invalidation} END"),
            $this->nativeSourceEpochTriggerDefinition('image_category_bd', $imageCategory, 'DELETE', "BEGIN {$invalidation} END"),
            $this->nativeSourceEpochTriggerDefinition('categories_bi', $categories, 'INSERT', "BEGIN {$invalidation} END"),
            $this->nativeSourceEpochTriggerDefinition('categories_bu', $categories, 'UPDATE', "BEGIN {$invalidation} END"),
            $this->nativeSourceEpochTriggerDefinition('categories_bd', $categories, 'DELETE', "BEGIN {$invalidation} END"),
        ];
    }

    /** @return array{name:string,table:string,event:string,statement:string} */
    private function nativeSourceEpochTriggerDefinition(
        string $suffix,
        string $table,
        string $event,
        string $statement,
    ): array {
        $name = $this->piwigoPrefix . 'ci_source_epoch_' . $suffix;
        foreach ([$name, $table] as $identifier) {
            if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $identifier) !== 1) {
                throw new \RuntimeException('class_identity_native_source_epoch_identifier_invalid');
            }
        }
        if (!in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            throw new \RuntimeException('class_identity_native_source_epoch_event_invalid');
        }
        return ['name' => $name, 'table' => $table, 'event' => $event, 'statement' => $statement];
    }

    /** @return array{name:string,table:string,event:string,statement:string} */
    private function nativeTriggerDefinition(
        string $suffix,
        string $table,
        string $event,
        string $statement,
    ): array {
        $name = $this->piwigoPrefix . 'ci_projection_' . $suffix;
        foreach ([$name, $table] as $identifier) {
            if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $identifier) !== 1) {
                throw new \RuntimeException('class_identity_native_projection_identifier_invalid');
            }
        }
        if (!in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            throw new \RuntimeException('class_identity_native_projection_event_invalid');
        }
        return ['name' => $name, 'table' => $table, 'event' => $event, 'statement' => $statement];
    }

    private function nativeProjectionInvalidationStatement(): string
    {
        $projection = $this->quotedTable('read_projection');
        return "UPDATE {$projection} SET `state`='STALE',`generation`=RANDOM_BYTES(16),"
            . "`invalidated_reason`='NATIVE_PIWIGO_MUTATION',`invalidated_at`=UTC_TIMESTAMP(6),"
            . "`updated_at`=UTC_TIMESTAMP(6) WHERE `projection_key` IN "
            . "('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES'); "
            . "IF ROW_COUNT() <> 5 THEN SIGNAL SQLSTATE '45000' "
            . "SET MESSAGE_TEXT='class_archive_projection_guard_failed'; END IF;";
    }

    private function nativeSourceEpochInvalidationStatement(): string
    {
        $epoch = $this->quotedTable('native_source_epoch');
        return "UPDATE {$epoch} SET `generation`=RANDOM_BYTES(16),`updated_at`=UTC_TIMESTAMP(6) "
            . "WHERE `source_key`='PIWIGO_NATIVE'; "
            . "IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' "
            . "SET MESSAGE_TEXT='class_archive_source_epoch_guard_failed'; END IF;";
    }

    /** @param list<string> $requiredColumns */
    private function assertNativePiwigoTable(string $suffix, array $requiredColumns): void
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $suffix) !== 1) {
            throw new \RuntimeException('class_identity_native_projection_table_invalid');
        }
        $name = $this->piwigoPrefix . $suffix;
        $rows = $this->informationSchemaRows(
            'SELECT ENGINE FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'',
            [$name],
        );
        if (count($rows) !== 1 || strtoupper((string) ($rows[0]['ENGINE'] ?? '')) !== 'MYISAM') {
            throw new \RuntimeException('class_identity_native_projection_source_invalid_' . $suffix);
        }
        $columns = $this->informationSchemaRows(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$name],
        );
        $actual = array_fill_keys(
            array_map(static fn(array $row): string => (string) ($row['COLUMN_NAME'] ?? ''), $columns),
            true,
        );
        foreach ($requiredColumns as $column) {
            if (!isset($actual[$column])) {
                throw new \RuntimeException('class_identity_native_projection_column_missing_' . $suffix . '_' . $column);
            }
        }
    }

    /** @param list<array{name:string,table:string,event:string,statement:string}>|null $definitions */
    private function assertNativeProjectionTriggers(?array $definitions = null): void
    {
        foreach ($definitions ?? $this->nativeProjectionTriggerDefinitions() as $definition) {
            $rows = $this->informationSchemaRows(
                'SELECT EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT '
                    . 'FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?',
                [$definition['name']],
            );
            if (count($rows) !== 1) {
                throw new \RuntimeException('class_identity_native_projection_trigger_missing');
            }
            $row = $rows[0];
            if (
                ($row['EVENT_OBJECT_TABLE'] ?? null) !== $definition['table']
                || strtoupper((string) ($row['ACTION_TIMING'] ?? '')) !== 'BEFORE'
                || strtoupper((string) ($row['EVENT_MANIPULATION'] ?? '')) !== $definition['event']
                || !hash_equals(
                    self::normalizeTriggerStatement($definition['statement']),
                    self::normalizeTriggerStatement((string) ($row['ACTION_STATEMENT'] ?? '')),
                )
            ) {
                throw new \RuntimeException('class_identity_native_projection_trigger_drift');
            }
        }
    }

    /** @param list<array{name:string,table:string,event:string,statement:string}> $definitions */
    private function replaceNativeProjectionTriggers(array $definitions): void
    {
        foreach ($definitions as $definition) {
            $name = $this->quotedIdentifier($definition['name']);
            $table = $this->quotedIdentifier($definition['table']);
            $this->executeRaw("DROP TRIGGER IF EXISTS {$name}");
            $this->executeRaw(
                "CREATE TRIGGER {$name} BEFORE {$definition['event']} ON {$table} "
                . 'FOR EACH ROW ' . $definition['statement'],
            );
        }
        $this->assertNativeProjectionTriggers($definitions);
    }

    /** @param list<array{name:string,table:string,event:string,statement:string}> $definitions */
    private function nativeProjectionTriggersAreCurrent(array $definitions): bool
    {
        foreach ($definitions as $definition) {
            $rows = $this->informationSchemaRows(
                'SELECT EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT '
                    . 'FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?',
                [$definition['name']],
            );
            if (count($rows) !== 1) {
                return false;
            }
            $row = $rows[0];
            if (($row['EVENT_OBJECT_TABLE'] ?? null) !== $definition['table']
                || strtoupper((string) ($row['ACTION_TIMING'] ?? '')) !== 'BEFORE'
                || strtoupper((string) ($row['EVENT_MANIPULATION'] ?? '')) !== $definition['event']
                || !hash_equals(
                    self::normalizeTriggerStatement($definition['statement']),
                    self::normalizeTriggerStatement((string) ($row['ACTION_STATEMENT'] ?? '')),
                )
            ) {
                return false;
            }
        }
        return true;
    }

    private function invalidateNativeLifecycleState(string $reason, bool $requireComplete = true): void
    {
        if (preg_match('/\A[A-Z0-9_]{1,64}\z/D', $reason) !== 1) {
            throw new \RuntimeException('class_identity_native_lifecycle_reason_invalid');
        }

        if ($this->tableExists('native_source_epoch')) {
            $epoch = $this->quotedTable('native_source_epoch');
            $this->executeRaw(
                "UPDATE {$epoch} SET `generation`=RANDOM_BYTES(16),`updated_at`=UTC_TIMESTAMP(6) "
                    . "WHERE `source_key`='PIWIGO_NATIVE'",
            );
            if ($requireComplete && $this->db->affected_rows !== 1) {
                throw new \RuntimeException('class_identity_native_lifecycle_epoch_invalid');
            }
        }

        $projection = $this->quotedTable('read_projection');
        $escapedReason = $this->db->real_escape_string($reason);
        $this->executeRaw(
            "UPDATE {$projection} SET `state`='STALE',`generation`=RANDOM_BYTES(16),"
                . "`invalidated_reason`='{$escapedReason}',`invalidated_at`=UTC_TIMESTAMP(6),"
                . "`updated_at`=UTC_TIMESTAMP(6) WHERE `projection_key` IN "
                . "('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT')",
        );
        if ($requireComplete && $this->db->affected_rows !== 6) {
            throw new \RuntimeException('class_identity_native_lifecycle_projection_invalid');
        }
    }

    /** @return list<array<string,mixed>> */
    private function pluginOwnedNativeTriggerRows(): array
    {
        $names = array_map(
            static fn(array $definition): string => $definition['name'],
            array_merge(
                $this->nativeProjectionTriggerDefinitions(),
                $this->nativeSourceEpochTriggerDefinitions(),
            ),
        );
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        return $this->informationSchemaRows(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
                . "WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ({$placeholders})",
            $names,
        );
    }

    private static function normalizeTriggerStatement(string $statement): string
    {
        $normalized = strtolower(str_replace('`', '', $statement));
        $normalized = preg_replace('/\s+/u', '', $normalized);
        if (!is_string($normalized) || $normalized === '') {
            throw new \RuntimeException('class_identity_native_projection_trigger_invalid');
        }
        return $normalized;
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

    private function ensureCheckConstraint(string $table, string $constraint, string $ddl): void
    {
        if (!$this->constraintExists($table, $constraint, 'CHECK')) {
            $this->executeRaw($ddl);
        }
        if (!$this->constraintExists($table, $constraint, 'CHECK')) {
            throw new \RuntimeException('class_identity_missing_check_constraint_' . $table . '_' . $constraint);
        }
    }

    /**
     * Replace a named CHECK constraint with the exact locked vocabulary.
     *
     * MariaDB does not expose a portable ALTER ... IF EXISTS for every locked
     * deployment target. Checking first makes a retry safe when an earlier
     * run stopped between DROP and ADD; after a completed add, a retry simply
     * converges the same named constraint again before the migration ledger is
     * recorded.
     */
    private function replaceCheckConstraint(string $table, string $constraint, string $ddl): void
    {
        if ($this->constraintExists($table, $constraint, 'CHECK')) {
            $this->executeRaw('ALTER TABLE ' . $this->quotedTable($table) . ' DROP CONSTRAINT `' . $constraint . '`');
        }
        $this->executeRaw($ddl);
        if (!$this->constraintExists($table, $constraint, 'CHECK')) {
            throw new \RuntimeException('class_identity_missing_replaced_check_constraint_' . $table . '_' . $constraint);
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->informationSchemaExists(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$this->table($table), $column],
        );
    }

    private function tableExists(string $suffix): bool
    {
        return $this->informationSchemaExists(
            'SELECT 1 FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\' LIMIT 1',
            [$this->table($suffix)],
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
        $this->assertProjectionEpochsInitialized();
        $this->assertNativeProjectionTriggers();
        $this->assertNativeProjectionTriggers($this->nativeSourceEpochTriggerDefinitions());
    }

    private function assertProjectionEpochsInitialized(): void
    {
        $epochTable = $this->table('native_source_epoch');
        $tableRows = $this->informationSchemaRows(
            'SELECT ENGINE FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'',
            [$epochTable],
        );
        if (count($tableRows) !== 1 || strtoupper((string) ($tableRows[0]['ENGINE'] ?? '')) !== 'MYISAM') {
            throw new \RuntimeException('class_identity_native_source_epoch_engine_invalid');
        }
        $epochRows = $this->informationSchemaRows(
            'SELECT `source_key`,`generation` FROM ' . $this->quotedTable('native_source_epoch'),
            [],
        );
        if (count($epochRows) !== 1
            || ($epochRows[0]['source_key'] ?? null) !== 'PIWIGO_NATIVE'
            || !is_string($epochRows[0]['generation'] ?? null)
            || strlen((string) $epochRows[0]['generation']) !== 16
        ) {
            throw new \RuntimeException('class_identity_native_source_epoch_invalid');
        }
        $rows = $this->informationSchemaRows(
            'SELECT `projection_key`,`state`,`generation`,`native_source_generation` FROM '
                . $this->quotedTable('read_projection') . ' ORDER BY `projection_key`',
            [],
        );
        $expected = ['ALBUMS', 'MEMORIES', 'PEOPLE', 'PHOTO_CATALOG', 'SPOTLIGHT', 'TIMELINE'];
        if (count($rows) !== count($expected)) {
            throw new \RuntimeException('class_identity_projection_epoch_rows_incomplete');
        }
        foreach ($rows as $index => $row) {
            if (($row['projection_key'] ?? null) !== $expected[$index]
                || !is_string($row['generation'] ?? null)
                || strlen((string) $row['generation']) !== 16
            ) {
                throw new \RuntimeException('class_identity_projection_epoch_invalid');
            }
            if (($row['projection_key'] ?? null) === 'PHOTO_CATALOG'
                && ($row['state'] ?? null) === 'ACTIVE'
                && (!is_string($row['native_source_generation'] ?? null)
                    || !hash_equals((string) $epochRows[0]['generation'], (string) $row['native_source_generation']))
            ) {
                throw new \RuntimeException('class_identity_projection_native_epoch_binding_invalid');
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
            'submission' => '7f6b4832baf74dd5ccdfbeffe20fb849e1cd8e3e7b32f324869efd1b34bb9c28',
            'archive_image' => '68c63c66f6ddba6063fdb5b1ee41be95b44f2916d632a35d8931700a46fecb6e',
            // Generated from the locked MariaDB 11.8.8 information_schema
            // contract by tests/phase2/class-photo-schema-semantics.php.
            'photo' => 'd165182447f6d8eef53add07cca881edd8b9273e5ba56411a81c959aaebd42e4',
            // Generated from migration 8 on an isolated MariaDB 11.8.8
            // fixture. The fixture contains only disposable dependency rows.
            'person' => '057428d4584745f190db85426a2c40a797ef84468054949ac6e8e9c43413c02c',
            'person_merge' => '9593a0ab6aa938d5324a778b26ae605f68bff509103e32b2e3aea44a27d0578e',
            'person_photo_rule' => '1cfd7d1394a6ab6cc357ff8492fb2dbd1ab3a8c27c8c2d6b2fbdb461d6192011',
            // Derived by tests/phase3/collections-first-schema-semantics.php
            // against the locked MariaDB 11.8.8 runtime.
            'album' => '8a0bf13f7091df3ea4a23e55f1d311c995c7601ada9ada001770b7f2fc6d494e',
            'spotlight' => 'a83686fe1cbfbaa193aafa90e3b0f208e02c5b0feaa0249bb8b7f62d7673e11b',
            'photo_source' => 'ce248992be43a980eea5988e661995e114c37a4cf27e396556c4a3e9cb5024d9',
            // Derived by tests/phase3/private-full-library-schema-semantics.php
            // against the locked MariaDB 11.8.8 runtime.
            'photo_source_presentation' => '5db9013d6b8f9147ea1d56db43d94dc81403fd9c197d8779ec942b89555a435a',
            'photo_duplicate' => '9f4216b1bf06c4c600807a1e2b193ff77bb15eea442f4076753304622f38ff05',
            'batch_operation' => '819f4bab9f845999655f333156b8a627589251e3c7221cde19e1132a8c0b39e7',
            'batch_operation_item' => '0cfec340df85bdbabc3ca5126511439b161a4dbfe0421e1cb37fb86a0af2487a',
            // Derived by tests/phase3/private-full-library-schema-semantics.php
            // against the locked MariaDB 11.8.8 runtime.
            'private_library_collection' => '2bb1eb76f9f035f88cd89cc491880bcbd2bcb7ed6a9d8caa02391b5c00cc9d48',
            'private_library_folder' => 'c8e84370cda5ea8e82c593d3fc2ef2b50ade2783e0a020a684e37a045c96eba0',
            'private_library_import' => '82026d485e07eeca630f1b0e12a540a1d138bf6272fabc6b460722fcda32b50e',
            'private_library_import_item' => 'c765a10a63fafa5d0234f300f6cd591c1eded8bc03527f62b986acb28abf222f',
            // Generated from migration 9 on the locked MariaDB 11.8.8
            // information_schema contract.
            'native_source_epoch' => '38835ba61ef74fb5a7133a0ed32f50fad6bfda27fa97d932ae6a0f163d7c80cb',
            'read_projection' => '50cd33236df8d42d76ba44b7dd9b3fe2a62b5e5023c4600f67e596f9846c1983',
            'read_photo' => '017afaaaadbf02491813dab1b0ff6fb548af157e20f5ac4ec2f7fa3dbc0d83ec',
            'photo_comment' => 'a704da76ee1999be5b9a60abf07b19996327e0b6b839584d804c8201a80db9a8',
            'auto_collection' => '0f88b7ddb06b0bbee0084345958380fc658089cc78e0db1debe8ad9b726da0e9',
            'auto_collection_photo' => '5f77bed777bcac6dc682a522aea34481616343f95ab2ab5c1bd0d52470d2bd28',
            'ai_asset_index' => '01d9f1b78701101a0f8ec513d20ac95fb21d968989afba4fd0cd4b14feb60ce9',
            'ai_index_job' => '50c1bbf3f0cce34f6ba693ac795df37bf90b28a57fec7323c46e82382bfde9a5',
            // Derived by tests/phase3/collection-snapshot-schema-semantics.php
            // against the locked MariaDB 11.8.8 runtime.
            'collection_snapshot' => '8b1b36721d2e8202b5d38eaf3e02afd8ecf5151bbf956d476b02649ddaa9dfe3',
            'collection_snapshot_item' => 'f6baf990751528ce671a788eb2629ec2169944982106f0bdfdeb09cb37129be8',
            'collection_snapshot_pointer' => 'eaa24d66e1f15fd4124aaf4ffd1c7cd527c2eb8f8e6c05404c913308b111602f',
            'collection_pin' => '7c36c262a2eef70bd32c8afd6418d0615648e83b34f6b091716cab02f973f649',
            'collection_feedback' => '0f084e15b96f872c806d6a30fd620c0749c4bc29a9a12507e48175c3b7649d0d',
            'collection_maintenance_state' => '97aceea1b26ffc870ef8b9aed09bb1363c57d12e811ce00247af60a25d792f4a',
            // Derived by tests/phase3/spotlight-rotation-schema-semantics.php
            // against the locked MariaDB 11.8.8 runtime.
            'spotlight_rotation_state' => '7a5a1f7857e3a2678f8752d02d3e77f1e08d8323849276816726e3f6905f3b8b',
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
    private function assertTable(string $suffix, array $requiredColumns, string $expectedEngine = 'INNODB'): void
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
        if (!in_array($expectedEngine, ['INNODB', 'MYISAM'], true)
            || strtoupper((string) $row['ENGINE']) !== $expectedEngine
        ) {
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

    private function quotedIdentifier(string $identifier): string
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $identifier) !== 1) {
            throw new \RuntimeException('class_identity_schema_identifier_invalid');
        }
        return '`' . $identifier . '`';
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
            'spotlight_rotation_state',
            'photo_source',
            'photo_source_presentation',
            'photo_duplicate',
            'batch_operation',
            'batch_operation_item',
            'private_library_collection',
            'private_library_folder',
            'private_library_import',
            'private_library_import_item',
            'photo_comment',
            'auto_collection',
            'auto_collection_photo',
            'ai_asset_index',
            'ai_index_job',
            'collection_snapshot',
            'collection_snapshot_item',
            'collection_snapshot_pointer',
            'collection_pin',
            'collection_feedback',
            'collection_maintenance_state',
            'native_source_epoch',
            'read_projection',
            'read_photo',
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
