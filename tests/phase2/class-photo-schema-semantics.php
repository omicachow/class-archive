<?php

declare(strict_types=1);

/**
 * Real MariaDB semantic fingerprint gate for the ClassArchivePhoto mapping
 * table. It creates the complete forward-only schema under a random temporary
 * prefix, never changes the live ClassIdentity tables, and drops every table
 * in finally. `--derive` is only for recording a newly added locked digest.
 */

const PHOTO_SCHEMA_SUFFIXES = [
    'migration', 'identity', 'seat', 'account', 'principal', 'operation',
    'token', 'audit_event', 'role_group', 'rate_limit_bucket', 'submission',
    'archive_image', 'photo',
];

function photoSchemaFail(string $message): never
{
    throw new RuntimeException($message);
}

function photoSchemaIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        photoSchemaFail('photo_schema_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_SCHEMA=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_SCHEMA=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_SCHEMA=FAIL reason=piwigo_prefix_invalid\n");
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
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_SCHEMA=FAIL reason=database_unavailable\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Schema.php';

$derive = in_array('--derive', array_slice($_SERVER['argv'], 1), true);
$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_photo_sem_' . $run . '_';
$schema = new ClassIdentity\Schema($db, $basePrefix, '0.1.0');
$exit = 0;

try {
    $versionResult = $db->query('SELECT VERSION()');
    $version = $versionResult instanceof mysqli_result ? (string) ($versionResult->fetch_row()[0] ?? '') : '';
    if ($versionResult instanceof mysqli_result) {
        $versionResult->free();
    }
    if (!str_starts_with($version, '11.8.8-MariaDB')) {
        photoSchemaFail('photo_schema_locked_mariadb_required');
    }

    // InnoDB foreign-key names are schema-global in MariaDB. Running all
    // existing migrations under a second prefix would collide with the live
    // `fk_ci_*` names, so create only the one referenced temporary table and
    // invoke the isolated photo migration through reflection. This exercises
    // the exact tracked DDL without modifying any live table or FK.
    $submission = photoSchemaIdentifier($basePrefix . 'class_identity_submission');
    if ($db->query('CREATE TABLE ' . $submission . ' (`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') === false) {
        photoSchemaFail('photo_schema_temp_submission_create_failed_' . $db->errno);
    }
    $migrationMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'migrationClassArchivePhotoMapping');
    $migrationMethod->invoke($schema);

    $digestMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'semanticDigest');
    $actual = $digestMethod->invoke($schema, 'photo');
    if (!is_string($actual) || preg_match('/\A[0-9a-f]{64}\z/D', $actual) !== 1) {
        photoSchemaFail('photo_schema_digest_invalid');
    }
    if ($derive) {
        fwrite(STDOUT, 'CLASS_ARCHIVE_PHOTO_SCHEMA=DERIVED digest=' . $actual . ' run=' . $run . "\n");
    } else {
        $expectedMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'expectedSemanticDigests');
        $expected = $expectedMethod->invoke(null);
        if (!is_array($expected) || !hash_equals((string) ($expected['photo'] ?? ''), $actual)) {
            photoSchemaFail('photo_schema_expected_digest_mismatch');
        }
        fwrite(STDOUT, 'CLASS_ARCHIVE_PHOTO_SCHEMA=PASS digest=' . $actual . ' run=' . $run . "\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'CLASS_ARCHIVE_PHOTO_SCHEMA=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_reverse(PHOTO_SCHEMA_SUFFIXES) as $suffix) {
        $db->query('DROP TABLE IF EXISTS ' . photoSchemaIdentifier($basePrefix . 'class_identity_' . $suffix));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($basePrefix . 'class_identity_') . '%';
    $remaining = $db->query("SELECT COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $remainingCount = $remaining instanceof mysqli_result ? (int) (($remaining->fetch_assoc()['count'] ?? -1)) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($remainingCount !== 0) {
        fwrite(STDERR, 'CLASS_ARCHIVE_PHOTO_SCHEMA_CLEANUP=FAIL run=' . $run . ' remaining=' . $remainingCount . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
