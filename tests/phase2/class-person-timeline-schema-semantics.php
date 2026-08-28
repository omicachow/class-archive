<?php

declare(strict_types=1);

/**
 * Derive and lock the v7 schema fingerprints without touching live data.
 *
 * The test reconstructs the exact v5 archive table under a random prefix,
 * applies only the forward v7 migration through reflection, fingerprints the
 * two affected tables, then drops every temporary object in finally.
 */

const TIMELINE_SCHEMA_SUFFIXES = ['person', 'archive_image', 'submission', 'identity'];
const TIMELINE_V7_ARCHIVE_DIGEST = '68c63c66f6ddba6063fdb5b1ee41be95b44f2916d632a35d8931700a46fecb6e';
const TIMELINE_V7_PERSON_DIGEST = '2a168b8aa4e61a766ea39ae93a3f66295c8e756b339a010947e8d04c17f7f2d6';

function timelineSchemaFail(string $message): never
{
    throw new RuntimeException($message);
}

function timelineSchemaIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        timelineSchemaFail('timeline_schema_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "CLASS_ARCHIVE_TIMELINE_SCHEMA=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_ARCHIVE_TIMELINE_SCHEMA=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "CLASS_ARCHIVE_TIMELINE_SCHEMA=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(
    (string) ($conf['db_host'] ?? ''),
    (string) ($conf['db_user'] ?? ''),
    (string) ($conf['db_password'] ?? ''),
    (string) ($conf['db_base'] ?? ''),
);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "CLASS_ARCHIVE_TIMELINE_SCHEMA=FAIL reason=database_unavailable\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Schema.php';

$derive = in_array('--derive', array_slice($_SERVER['argv'], 1), true);
$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_timeline_sem_' . $run . '_';
$schema = new ClassIdentity\Schema($db, $basePrefix, '0.1.0');
$exit = 0;

try {
    $versionResult = $db->query('SELECT VERSION()');
    $version = $versionResult instanceof mysqli_result ? (string) ($versionResult->fetch_row()[0] ?? '') : '';
    if ($versionResult instanceof mysqli_result) {
        $versionResult->free();
    }
    if (!str_starts_with($version, '11.8.8-MariaDB')) {
        timelineSchemaFail('timeline_schema_locked_mariadb_required');
    }

    $identity = timelineSchemaIdentifier($basePrefix . 'class_identity_identity');
    $submission = timelineSchemaIdentifier($basePrefix . 'class_identity_submission');
    $archive = timelineSchemaIdentifier($basePrefix . 'class_identity_archive_image');
    if ($db->query('CREATE TABLE ' . $identity . ' (`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') === false) {
        timelineSchemaFail('timeline_schema_temp_identity_create_failed_' . $db->errno);
    }
    if ($db->query('CREATE TABLE ' . $submission . ' (`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') === false) {
        timelineSchemaFail('timeline_schema_temp_submission_create_failed_' . $db->errno);
    }
    if ($db->query(<<<SQL
CREATE TABLE {$archive} (
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
  CONSTRAINT `fk_ci_timeline_archive_submission_{$run}` FOREIGN KEY (`source_submission_id`) REFERENCES {$submission} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_archive_era` CHECK (`era` IN ('HERITAGE', 'LIVING')),
  CONSTRAINT `chk_ci_archive_precision` CHECK (`date_precision` IN ('EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN')),
  CONSTRAINT `chk_ci_archive_confidence` CHECK (`date_confidence` IN ('HIGH', 'MEDIUM', 'LOW', 'UNKNOWN')),
  CONSTRAINT `chk_ci_archive_official` CHECK (`official` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL) === false) {
        timelineSchemaFail('timeline_schema_temp_archive_create_failed_' . $db->errno);
    }

    $migrationMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'migrationTimelineSourceAndPersonMapping');
    $migrationMethod->invoke($schema);
    $digestMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'semanticDigest');
    $archiveDigest = $digestMethod->invoke($schema, 'archive_image');
    $personDigest = $digestMethod->invoke($schema, 'person');
    if (!is_string($archiveDigest) || !is_string($personDigest) || preg_match('/\A[0-9a-f]{64}\z/D', $archiveDigest) !== 1 || preg_match('/\A[0-9a-f]{64}\z/D', $personDigest) !== 1) {
        timelineSchemaFail('timeline_schema_digest_invalid');
    }
    if ($derive) {
        fwrite(STDOUT, 'CLASS_ARCHIVE_TIMELINE_SCHEMA=DERIVED archive_image=' . $archiveDigest . ' person=' . $personDigest . ' run=' . $run . "\n");
    } else {
        // This fixture deliberately invokes only migration 7. Keep its locked
        // fingerprints independent of the current schema's forward-only v8
        // person-curation overlay.
        if (!hash_equals(TIMELINE_V7_ARCHIVE_DIGEST, $archiveDigest)
            || !hash_equals(TIMELINE_V7_PERSON_DIGEST, $personDigest)
        ) {
            timelineSchemaFail('timeline_schema_expected_digest_mismatch');
        }
        fwrite(STDOUT, 'CLASS_ARCHIVE_TIMELINE_SCHEMA=PASS archive_image=' . $archiveDigest . ' person=' . $personDigest . ' run=' . $run . "\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'CLASS_ARCHIVE_TIMELINE_SCHEMA=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (TIMELINE_SCHEMA_SUFFIXES as $suffix) {
        $db->query('DROP TABLE IF EXISTS ' . timelineSchemaIdentifier($basePrefix . 'class_identity_' . $suffix));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($basePrefix . 'class_identity_') . '%';
    $remaining = $db->query("SELECT COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $remainingCount = $remaining instanceof mysqli_result ? (int) (($remaining->fetch_assoc()['count'] ?? -1)) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($remainingCount !== 0) {
        fwrite(STDERR, 'CLASS_ARCHIVE_TIMELINE_SCHEMA_CLEANUP=FAIL run=' . $run . ' remaining=' . $remainingCount . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
