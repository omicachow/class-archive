<?php

declare(strict_types=1);

/**
 * Disposable MariaDB contract for the additive v17 -> v18 Spotlight rotation
 * checkpoint. The fixture creates a minimal v17 snapshot domain, fingerprints
 * it, applies v18, and proves that v17's table semantics remain unchanged.
 * It never reads or writes any persistent Class Archive row.
 */

const SPOTLIGHT_ROTATION_V17_SUFFIXES = [
    'collection_snapshot',
    'collection_snapshot_item',
    'collection_snapshot_pointer',
    'collection_pin',
    'collection_feedback',
    'collection_maintenance_state',
];

function spotlightRotationFail(string $message): never
{
    throw new RuntimeException($message);
}

function spotlightRotationIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        spotlightRotationFail('spotlight_rotation_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function spotlightRotationExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        spotlightRotationFail('spotlight_rotation_query_failed_' . $db->errno);
    }
}

function spotlightRotationRejected(mysqli $db, string $sql, string $label): void
{
    if ($db->query($sql) !== false) {
        spotlightRotationFail('spotlight_rotation_constraint_not_enforced_' . $label);
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_SCHEMA=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_SCHEMA=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_SCHEMA=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_SCHEMA=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';

$derive = in_array('--derive', array_slice($_SERVER['argv'], 1), true);
$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_srs_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$photo = $ci . 'photo';
$principal = $ci . 'principal';
$spotlight = $ci . 'spotlight';
$created = array_merge([$photo, $principal, $spotlight], array_map(
    static fn(string $suffix): string => $ci . $suffix,
    array_merge(SPOTLIGHT_ROTATION_V17_SUFFIXES, ['spotlight_rotation_state']),
));
$assertions = 0;
$exit = 0;

try {
    $version = $db->query('SELECT VERSION()');
    $versionText = $version instanceof mysqli_result ? (string) ($version->fetch_row()[0] ?? '') : '';
    if ($version instanceof mysqli_result) {
        $version->free();
    }
    if (!str_starts_with($versionText, '11.8.8-MariaDB')) {
        spotlightRotationFail('spotlight_rotation_locked_mariadb_required');
    }

    spotlightRotationExecute($db, 'CREATE TABLE ' . spotlightRotationIdentifier($photo) . ' ('
        . '`class_photo_id` BINARY(16) NOT NULL, PRIMARY KEY (`class_photo_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    spotlightRotationExecute($db, 'CREATE TABLE ' . spotlightRotationIdentifier($principal) . ' ('
        . '`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    spotlightRotationExecute($db, 'CREATE TABLE ' . spotlightRotationIdentifier($spotlight) . ' ('
        . '`spotlight_id` BINARY(16) NOT NULL, PRIMARY KEY (`spotlight_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    $migration17 = new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationPhotosAppV4CollectionSnapshots');
    $migration18 = new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationPhotosAppV4SpotlightRotationState');
    $digest = new ReflectionMethod(\ClassIdentity\Schema::class, 'semanticDigest');
    $expectedMethod = new ReflectionMethod(\ClassIdentity\Schema::class, 'expectedSemanticDigests');

    $migration17->invoke($schema);
    $before = [];
    foreach (SPOTLIGHT_ROTATION_V17_SUFFIXES as $suffix) {
        $value = $digest->invoke($schema, $suffix);
        if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
            spotlightRotationFail('spotlight_rotation_v17_digest_invalid_' . $suffix);
        }
        $before[$suffix] = $value;
    }

    $migration18->invoke($schema);
    $rotationDigest = $digest->invoke($schema, 'spotlight_rotation_state');
    if (!is_string($rotationDigest) || preg_match('/\A[0-9a-f]{64}\z/D', $rotationDigest) !== 1) {
        spotlightRotationFail('spotlight_rotation_digest_invalid');
    }
    if ($derive) {
        fwrite(STDOUT, 'SPOTLIGHT_ROTATION_SCHEMA=DERIVED spotlight_rotation_state=' . $rotationDigest . ' run=' . $run . "\n");
        exit(0);
    }

    $expected = $expectedMethod->invoke(null);
    if (!is_array($expected) || !hash_equals((string) ($expected['spotlight_rotation_state'] ?? ''), $rotationDigest)) {
        spotlightRotationFail('spotlight_rotation_digest_mismatch');
    }
    ++$assertions;

    foreach (SPOTLIGHT_ROTATION_V17_SUFFIXES as $suffix) {
        $after = $digest->invoke($schema, $suffix);
        if (!is_string($after) || !hash_equals($before[$suffix], $after)) {
            spotlightRotationFail('spotlight_rotation_v17_semantics_mutated_' . $suffix);
        }
        ++$assertions;
    }

    $migration18->invoke($schema);
    $retryDigest = $digest->invoke($schema, 'spotlight_rotation_state');
    if (!is_string($retryDigest) || !hash_equals($rotationDigest, $retryDigest)) {
        spotlightRotationFail('spotlight_rotation_migration_not_idempotent');
    }
    ++$assertions;

    $rotation = spotlightRotationIdentifier($ci . 'spotlight_rotation_state');
    $knownHero = '90000000000040008000000000000001';
    spotlightRotationExecute($db, "INSERT INTO " . spotlightRotationIdentifier($spotlight)
        . " (`spotlight_id`) VALUES (UNHEX('{$knownHero}'))");
    spotlightRotationExecute($db, "INSERT INTO {$rotation} (`scope`,`hero_spotlight_id`,`candidate_digest`,`display_count`,`last_rotated_at`,`next_rotation_at`,`revision`) VALUES ('FULL',UNHEX('{$knownHero}'),UNHEX(SHA2('full-candidates',256)),7,'2030-01-01 00:00:00.000000','2030-01-01 01:00:00.000000',UNHEX(SHA2('full-revision',256)))");
    spotlightRotationExecute($db, "INSERT INTO {$rotation} (`scope`,`candidate_digest`,`display_count`,`next_rotation_at`,`revision`) VALUES ('HERITAGE',UNHEX(SHA2('heritage-empty',256)),0,'2030-01-01 00:00:00.000000',UNHEX(SHA2('heritage-revision',256)))");
    $rows = $db->query("SELECT `scope`,`hero_spotlight_id`,`display_count`,`last_rotated_at`,`next_rotation_at` FROM {$rotation} ORDER BY `scope`");
    if (!$rows instanceof mysqli_result || $rows->num_rows !== 2) {
        spotlightRotationFail('spotlight_rotation_two_scope_bound_failed');
    }
    $rows->free();
    ++$assertions;

    spotlightRotationRejected($db, "INSERT INTO {$rotation} (`scope`,`candidate_digest`,`next_rotation_at`,`revision`) VALUES ('INVALID',UNHEX(SHA2('invalid',256)),'2030-01-01 00:00:00.000000',UNHEX(SHA2('invalid',256)))", 'scope');
    ++$assertions;
    spotlightRotationRejected($db, "INSERT INTO {$rotation} (`scope`,`candidate_digest`,`next_rotation_at`,`revision`) VALUES ('FULL',UNHEX(SHA2('duplicate',256)),'2030-01-01 00:00:00.000000',UNHEX(SHA2('duplicate',256)))", 'scope_primary_key');
    ++$assertions;
    spotlightRotationRejected($db, "UPDATE {$rotation} SET `hero_spotlight_id`=UNHEX('9fffffffffff4fff8fff000000000001') WHERE `scope`='FULL'", 'hero_fk');
    ++$assertions;
    spotlightRotationRejected($db, "UPDATE {$rotation} SET `last_rotated_at`='2030-01-02 00:00:00.000000',`next_rotation_at`='2030-01-01 00:00:00.000000' WHERE `scope`='HERITAGE'", 'schedule');
    ++$assertions;
    spotlightRotationRejected($db, "UPDATE {$rotation} SET `display_count`=9223372036854775808 WHERE `scope`='HERITAGE'", 'display_count');
    ++$assertions;
    spotlightRotationRejected($db, "DELETE FROM " . spotlightRotationIdentifier($spotlight) . " WHERE `spotlight_id`=UNHEX('{$knownHero}')", 'hero_delete_restrict');
    ++$assertions;

    fwrite(STDOUT, 'SPOTLIGHT_ROTATION_SCHEMA=PASS assertions=' . $assertions . ' run=' . $run . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'SPOTLIGHT_ROTATION_SCHEMA=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_reverse(array_unique($created)) as $table) {
        $db->query('DROP TABLE IF EXISTS ' . spotlightRotationIdentifier($table));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($ci) . '%';
    $remaining = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $count = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($count !== 0) {
        fwrite(STDERR, 'SPOTLIGHT_ROTATION_SCHEMA_CLEANUP=FAIL run=' . $run . ' remaining=' . $count . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
