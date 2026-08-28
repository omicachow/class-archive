<?php

declare(strict_types=1);

/**
 * Disposable persistence proof for the v18 Spotlight rotation checkpoint.
 *
 * It only creates a random synthetic table prefix in the synthetic MariaDB
 * container, then removes every table in finally. No Piwigo account, media,
 * private source, browser request, or principal fixture is read.
 */

function spotlightRotationRuntimeFail(string $message): never
{
    throw new RuntimeException($message);
}

function spotlightRotationRuntimeIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        spotlightRotationRuntimeFail('spotlight_rotation_runtime_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function spotlightRotationRuntimeExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        spotlightRotationRuntimeFail('spotlight_rotation_runtime_query_failed_' . $db->errno);
    }
}

/** @return array<string,mixed>|null */
function spotlightRotationRuntimeOne(mysqli $db, string $sql): ?array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        spotlightRotationRuntimeFail('spotlight_rotation_runtime_query_failed_' . $db->errno);
    }
    $row = $result->fetch_assoc();
    $result->free();
    return is_array($row) ? $row : null;
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_RUNTIME=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/Audit.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/SpotlightRotationService.php';

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_srr_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$assertions = 0;
$exit = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        spotlightRotationRuntimeFail($message);
    }
};

try {
    $version = spotlightRotationRuntimeOne($db, 'SELECT VERSION() AS `version`');
    if (!str_starts_with((string) ($version['version'] ?? ''), '11.8.8-MariaDB')) {
        spotlightRotationRuntimeFail('spotlight_rotation_runtime_locked_mariadb_required');
    }

    $photo = $ci . 'photo';
    $principal = $ci . 'principal';
    $spotlight = $ci . 'spotlight';
    $audit = $ci . 'audit_event';
    foreach ([$photo, $principal, $spotlight, $audit] as $table) {
        spotlightRotationRuntimeIdentifier($table);
    }
    spotlightRotationRuntimeExecute($db, 'CREATE TABLE ' . spotlightRotationRuntimeIdentifier($photo) . ' ('
        . '`class_photo_id` BINARY(16) NOT NULL, PRIMARY KEY (`class_photo_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    spotlightRotationRuntimeExecute($db, 'CREATE TABLE ' . spotlightRotationRuntimeIdentifier($principal) . ' ('
        . '`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    spotlightRotationRuntimeExecute($db, 'CREATE TABLE ' . spotlightRotationRuntimeIdentifier($spotlight) . ' ('
        . '`spotlight_id` BINARY(16) NOT NULL, PRIMARY KEY (`spotlight_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    spotlightRotationRuntimeExecute($db, 'CREATE TABLE ' . spotlightRotationRuntimeIdentifier($audit) . ' ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
        . '`request_id` BINARY(16) NOT NULL, `actor_principal_id` BIGINT UNSIGNED NULL, `actor_user_id` BIGINT UNSIGNED NULL,'
        . '`actor_kind` VARCHAR(24) NOT NULL, `action` VARCHAR(64) NOT NULL, `target_type` VARCHAR(32) NOT NULL,'
        . '`target_id` VARCHAR(190) NULL, `target_identity_id` BIGINT UNSIGNED NULL, `target_seat_id` BIGINT UNSIGNED NULL,'
        . '`target_account_id` BIGINT UNSIGNED NULL, `target_principal_id` BIGINT UNSIGNED NULL, `old_value` JSON NULL,'
        . '`new_value` JSON NULL, `reason` VARCHAR(500) NULL, `source_ip_hash` BINARY(32) NULL, `result` VARCHAR(16) NOT NULL,'
        . '`error_code` VARCHAR(64) NULL, PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationPhotosAppV4CollectionSnapshots'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationPhotosAppV4SpotlightRotationState'))->invoke($schema);
    $repository = new \ClassIdentity\Repository($db, $basePrefix);
    $service = new \ClassIdentity\SpotlightRotationService($repository);

    $a = '10000000-0000-4000-8000-000000000001';
    $b = '20000000-0000-4000-8000-000000000002';
    $c = '30000000-0000-4000-8000-000000000003';
    $d = '40000000-0000-4000-8000-000000000004';
    foreach ([$a, $b, $c, $d] as $id) {
        spotlightRotationRuntimeExecute($db, 'INSERT INTO ' . spotlightRotationRuntimeIdentifier($spotlight)
            . " (`spotlight_id`) VALUES (UNHEX('" . str_replace('-', '', $id) . "'))");
    }

    $t0 = new DateTimeImmutable('2030-01-01 00:00:00.000000', new DateTimeZone('UTC'));
    $initial = $service->advanceAtForSyntheticTest('FULL', [$c, $a, $b], $t0);
    $assert(($initial['heroSpotlightId'] ?? null) === $a
        && ($initial['orderedSpotlightIds'] ?? null) === [$a, $b, $c]
        && ($initial['displayCount'] ?? null) === 1,
        'initial_persisted_rotation_invalid');
    $assert(!array_key_exists('candidateDigest', $initial), 'raw_candidate_digest_exposed');

    $rotation = spotlightRotationRuntimeOne($db, 'SELECT HEX(`hero_spotlight_id`) AS `hero`,HEX(`candidate_digest`) AS `digest`,'
        . '`display_count`,`last_rotated_at`,`next_rotation_at`,HEX(`revision`) AS `revision`,`updated_at` FROM '
        . spotlightRotationRuntimeIdentifier($ci . 'spotlight_rotation_state') . " WHERE `scope`='FULL'");
    $assert(is_array($rotation)
        && strtolower((string) ($rotation['hero'] ?? '')) === str_replace('-', '', $a)
        && (int) ($rotation['display_count'] ?? 0) === 1
        && ($rotation['last_rotated_at'] ?? null) === '2030-01-01 00:00:00.000000'
        && ($rotation['next_rotation_at'] ?? null) === '2030-01-01 01:00:00.000000',
        'rotation_row_not_durable');
    $assert(is_string($rotation['digest'] ?? null) && strlen((string) $rotation['digest']) === 64
        && is_string($rotation['revision'] ?? null) && strlen((string) $rotation['revision']) === 64,
        'rotation_digest_or_revision_invalid');

    $readBefore = $rotation;
    $published = $service->stateForPublishedCandidates('FULL', [$a, $b, $c]);
    $readAfter = spotlightRotationRuntimeOne($db, 'SELECT HEX(`revision`) AS `revision`,`updated_at` FROM '
        . spotlightRotationRuntimeIdentifier($ci . 'spotlight_rotation_state') . " WHERE `scope`='FULL'");
    $assert(($published['heroSpotlightId'] ?? null) === $a
        && ($published['orderedSpotlightIds'] ?? null) === [$a, $b, $c]
        && !array_key_exists('candidateDigest', $published)
        && ($readAfter['revision'] ?? null) === ($readBefore['revision'] ?? null)
        && ($readAfter['updated_at'] ?? null) === ($readBefore['updated_at'] ?? null),
        'query_path_mutated_or_leaked');

    $held = $service->advanceAtForSyntheticTest('FULL', [$a, $b, $c], $t0->add(new DateInterval('PT30M')));
    $assert(($held['heroSpotlightId'] ?? null) === $a && ($held['changed'] ?? null) === false
        && ($held['displayCount'] ?? null) === 1,
        'hero_advanced_before_deadline');

    $due = $service->advanceAtForSyntheticTest('FULL', [$a, $b, $c], $t0->add(new DateInterval('PT1H')));
    $assert(($due['heroSpotlightId'] ?? null) === $b && ($due['orderedSpotlightIds'] ?? null) === [$b, $c, $a]
        && ($due['displayCount'] ?? null) === 2,
        'due_rotation_not_persistent_or_fair');
    $expanded = $service->advanceAtForSyntheticTest('FULL', [$a, $b, $c, $d], $t0->add(new DateInterval('PT1H30M')));
    $assert(($expanded['heroSpotlightId'] ?? null) === $b && ($expanded['orderedSpotlightIds'] ?? null) === [$b, $c, $d, $a]
        && ($expanded['displayCount'] ?? null) === 2,
        'candidate_change_displaced_current_hero');

    try {
        $service->stateForPublishedCandidates('FULL', [$a, $b]);
        spotlightRotationRuntimeFail('candidate_digest_mismatch_not_fail_closed');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'class_archive_spotlight_rotation_state_stale', 'unexpected_candidate_mismatch_error');
    }

    $removed = $service->advanceAtForSyntheticTest('FULL', [$a, $c, $d], $t0->add(new DateInterval('PT1H31M')));
    $assert(($removed['heroSpotlightId'] ?? null) === $c && ($removed['orderedSpotlightIds'] ?? null) === [$c, $d, $a]
        && ($removed['displayCount'] ?? null) === 3,
        'removed_hero_did_not_select_next_visible_candidate');
    $empty = $service->advanceAtForSyntheticTest('HERITAGE', [], $t0);
    $assert(array_key_exists('heroSpotlightId', $empty) && $empty['heroSpotlightId'] === null && ($empty['orderedSpotlightIds'] ?? null) === []
        && ($empty['displayCount'] ?? null) === 0 && is_string($empty['nextRotationAt'] ?? null),
        'empty_scope_checkpoint_invalid');

    // Time may be read server-side to fail closed, but it is never taken from
    // a browser. A deliberately historical test checkpoint must be rejected
    // by the query-only accessor rather than silently served.
    $past = new DateTimeImmutable('2000-01-01 00:00:00.000000', new DateTimeZone('UTC'));
    $service->advanceAtForSyntheticTest('HERITAGE', [$a], $past);
    try {
        $service->stateForPublishedCandidates('HERITAGE', [$a]);
        spotlightRotationRuntimeFail('overdue_rotation_state_served');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'class_archive_spotlight_rotation_state_due', 'unexpected_overdue_state_error');
    }
    $cleared = $service->advanceAtForSyntheticTest('HERITAGE', [], $t0->add(new DateInterval('PT2H')));
    $assert(array_key_exists('heroSpotlightId', $cleared) && $cleared['heroSpotlightId'] === null
        && ($cleared['displayCount'] ?? null) === 0,
        'active_scope_clear_not_persisted');

    $maintenance = $ci . 'collection_maintenance_state';
    spotlightRotationRuntimeExecute($db, 'UPDATE ' . spotlightRotationRuntimeIdentifier($maintenance)
        . " SET `state`='RUNNING',`started_at`=UTC_TIMESTAMP(6),`completed_at`=NULL WHERE `maintenance_key`='SPOTLIGHT_ROTATION_FULL'");
    try {
        $service->advanceAtForSyntheticTest('FULL', [$a, $c, $d], $t0->add(new DateInterval('PT2H')));
        spotlightRotationRuntimeFail('concurrent_maintenance_lease_accepted');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'class_archive_spotlight_rotation_lease_unavailable', 'unexpected_lease_error');
    }

    $auditRows = spotlightRotationRuntimeOne($db, 'SELECT COUNT(*) AS `count`, '
        . "SUM(`action`='SPOTLIGHT_ROTATION_ADVANCE') AS `advance_count`, "
        . "SUM(`action`='SPOTLIGHT_ROTATION_CLEAR') AS `clear_count` "
        . 'FROM ' . spotlightRotationRuntimeIdentifier($audit));
    $auditPayloads = $db->query('SELECT `new_value` FROM ' . spotlightRotationRuntimeIdentifier($audit));
    $auditLeaks = 0;
    if (!$auditPayloads instanceof mysqli_result) {
        spotlightRotationRuntimeFail('audit_payload_query_failed');
    }
    while ($row = $auditPayloads->fetch_assoc()) {
        $payload = strtolower((string) ($row['new_value'] ?? ''));
        foreach (['principal', 'account', 'seat', 'owner'] as $forbidden) {
            if (str_contains($payload, $forbidden)) {
                ++$auditLeaks;
            }
        }
    }
    $auditPayloads->free();
    $assert((int) ($auditRows['count'] ?? 0) >= 5
        && (int) ($auditRows['advance_count'] ?? 0) >= 4
        && (int) ($auditRows['clear_count'] ?? 0) >= 1
        && $auditLeaks === 0,
        'audit_evidence_missing_or_identity_leak');

    fwrite(STDOUT, "SPOTLIGHT_ROTATION_RUNTIME=PASS assertions={$assertions} run={$run}\n");
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'SPOTLIGHT_ROTATION_RUNTIME=FAIL run=' . $run . ' reason='
        . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $like = $db->real_escape_string($ci) . '%';
    $tables = $db->query("SELECT `TABLE_NAME` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $names = [];
    if ($tables instanceof mysqli_result) {
        while ($row = $tables->fetch_assoc()) {
            if (is_array($row) && is_string($row['TABLE_NAME'] ?? null)) {
                $names[] = $row['TABLE_NAME'];
            }
        }
        $tables->free();
    }
    foreach (array_reverse($names) as $name) {
        $db->query('DROP TABLE IF EXISTS ' . spotlightRotationRuntimeIdentifier($name));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $remaining = spotlightRotationRuntimeOne($db, "SELECT COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    if ((int) ($remaining['count'] ?? -1) !== 0) {
        fwrite(STDERR, "SPOTLIGHT_ROTATION_RUNTIME_CLEANUP=FAIL run={$run} remaining=" . (int) ($remaining['count'] ?? -1) . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
