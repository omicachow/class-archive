<?php

declare(strict_types=1);

/**
 * Disposable MariaDB schema gate for the Collections-first v15 migration.
 *
 * It clones only the three parent tables required by v15 under a random
 * prefix, applies the migration by reflection, fingerprints every affected
 * table, and then proves the most important relational constraints.  It
 * never writes a live ClassIdentity table or a private media/runtime volume.
 * Pass --derive only while deliberately refreshing locked semantic digests.
 */

const COLLECTIONS_FIRST_DIGEST_SUFFIXES = [
    'album',
    'photo_comment',
    'auto_collection',
    'auto_collection_photo',
    'ai_asset_index',
    'ai_index_job',
];

function collectionsFirstFail(string $message): never
{
    throw new RuntimeException($message);
}

function collectionsFirstIdent(string $name): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) {
        collectionsFirstFail('collections_first_schema_identifier_invalid');
    }
    return '`' . $name . '`';
}

function collectionsFirstExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        collectionsFirstFail('collections_first_schema_query_failed_' . $db->errno);
    }
}

function collectionsFirstRejected(mysqli $db, string $sql, string $label): void
{
    if ($db->query($sql) !== false) {
        collectionsFirstFail('collections_first_constraint_not_enforced_' . $label);
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "COLLECTIONS_FIRST_SCHEMA=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "COLLECTIONS_FIRST_SCHEMA=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "COLLECTIONS_FIRST_SCHEMA=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "COLLECTIONS_FIRST_SCHEMA=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';

$derive = in_array('--derive', array_slice($_SERVER['argv'], 1), true);
$run = strtolower(bin2hex(random_bytes(6)));
// Keep below MariaDB's 64-character table-name limit even for the longest
// v15 suffix (`auto_collection_photo`).
$basePrefix = 'ci_cfs_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$sourceCi = $prefixeTable . 'class_identity_';
$created = [];
$assertions = 0;
$exit = 0;

try {
    $version = $db->query('SELECT VERSION()');
    $versionText = $version instanceof mysqli_result ? (string) ($version->fetch_row()[0] ?? '') : '';
    if ($version instanceof mysqli_result) {
        $version->free();
    }
    if (!str_starts_with($versionText, '11.8.8-MariaDB')) {
        collectionsFirstFail('collections_first_locked_mariadb_required');
    }

    // `album` must retain the exact v14/v15 source shape because v15 alters
    // it.  The other parent tables only need their FK contracts: cloning the
    // production `photo` table would make this disposable fixture depend on
    // unrelated non-null canonical-media columns.
    foreach (['album'] as $suffix) {
        $source = collectionsFirstIdent($sourceCi . $suffix);
        $target = $ci . $suffix;
        collectionsFirstExecute($db, 'CREATE TABLE ' . collectionsFirstIdent($target) . ' LIKE ' . $source);
        $created[] = $target;
    }
    collectionsFirstExecute($db, 'CREATE TABLE ' . collectionsFirstIdent($ci . 'photo') . ' ('
        . '`class_photo_id` BINARY(16) NOT NULL, PRIMARY KEY (`class_photo_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $created[] = $ci . 'photo';
    collectionsFirstExecute($db, 'CREATE TABLE ' . collectionsFirstIdent($ci . 'principal') . ' ('
        . '`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $created[] = $ci . 'principal';

    // Register every migration target before invoking it so a mid-migration
    // failure still cleans up a partially-created disposable fixture.
    foreach (array_slice(COLLECTIONS_FIRST_DIGEST_SUFFIXES, 1) as $suffix) {
        $created[] = $ci . $suffix;
    }
    $schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    $migration = new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationCollectionsFirstCommentsAndAiIndex');
    $migration->invoke($schema);

    $digest = new ReflectionMethod(\ClassIdentity\Schema::class, 'semanticDigest');
    $expectedMethod = new ReflectionMethod(\ClassIdentity\Schema::class, 'expectedSemanticDigests');
    $expected = $expectedMethod->invoke(null);
    if (!is_array($expected)) {
        collectionsFirstFail('collections_first_expected_digest_invalid');
    }
    $derived = [];
    foreach (COLLECTIONS_FIRST_DIGEST_SUFFIXES as $suffix) {
        $actual = $digest->invoke($schema, $suffix);
        if (!is_string($actual)) {
            collectionsFirstFail('collections_first_digest_invalid_' . $suffix);
        }
        if (!$derive && !hash_equals((string) ($expected[$suffix] ?? ''), $actual)) {
            collectionsFirstFail('collections_first_digest_mismatch_' . $suffix);
        }
        $derived[$suffix] = $actual;
        ++$assertions;
    }
    if ($derive) {
        fwrite(STDOUT, 'COLLECTIONS_FIRST_SCHEMA=DERIVED ' . implode(' ', array_map(
            static fn(string $suffix): string => $suffix . '=' . $derived[$suffix],
            COLLECTIONS_FIRST_DIGEST_SUFFIXES,
        )) . ' run=' . $run . "\n");
        exit(0);
    }

    $photo = collectionsFirstIdent($ci . 'photo');
    $principal = collectionsFirstIdent($ci . 'principal');
    $album = collectionsFirstIdent($ci . 'album');
    $comment = collectionsFirstIdent($ci . 'photo_comment');
    $auto = collectionsFirstIdent($ci . 'auto_collection');
    $autoPhoto = collectionsFirstIdent($ci . 'auto_collection_photo');
    $index = collectionsFirstIdent($ci . 'ai_asset_index');
    $job = collectionsFirstIdent($ci . 'ai_index_job');
    collectionsFirstExecute($db, "INSERT INTO {$photo} (`class_photo_id`) VALUES (UNHEX('10000000000040008000000000000001')), (UNHEX('10000000000040008000000000000002'))");
    collectionsFirstExecute($db, "INSERT INTO {$principal} (`id`) VALUES (1), (2)");
    collectionsFirstExecute($db, "INSERT INTO {$comment} (`comment_id`,`class_photo_id`,`author_principal_id`,`author_role`,`body`) VALUES (UNHEX('20000000000040008000000000000001'),UNHEX('10000000000040008000000000000001'),1,'CLASSMATE','synthetic comment')");
    // SQL FKs cannot express same-photo parenthood; PhotoCommentService owns
    // that business invariant.  The schema does prove that an arbitrary
    // nonexistent parent is impossible to persist.
    collectionsFirstRejected($db, "INSERT INTO {$comment} (`comment_id`,`class_photo_id`,`parent_comment_id`,`author_principal_id`,`author_role`,`body`) VALUES (UNHEX('20000000000040008000000000000002'),UNHEX('10000000000040008000000000000002'),UNHEX('2fffffffffff4fff8fff000000000001'),1,'CLASSMATE','missing parent')", 'comment_parent_fk');
    ++$assertions;
    collectionsFirstRejected($db, "INSERT INTO {$comment} (`comment_id`,`class_photo_id`,`author_principal_id`,`author_role`,`body`,`state`) VALUES (UNHEX('20000000000040008000000000000003'),UNHEX('10000000000040008000000000000001'),1,'FAMILY','forbidden role','ACTIVE')", 'comment_author_role');
    ++$assertions;
    collectionsFirstExecute($db, "INSERT INTO {$auto} (`auto_collection_id`,`collection_kind`,`title`,`source_reason`,`cover_class_photo_id`,`projection_revision`) VALUES (UNHEX('30000000000040008000000000000001'),'MEMORY','合成回忆','SYNTHETIC',UNHEX('10000000000040008000000000000001'),UNHEX(SHA2('synthetic',256)))");
    collectionsFirstRejected($db, "INSERT INTO {$auto} (`auto_collection_id`,`collection_kind`,`title`,`source_reason`,`cover_class_photo_id`,`projection_revision`) VALUES (UNHEX('30000000000040008000000000000002'),'MEMORY','重复来源','SYNTHETIC',UNHEX('10000000000040008000000000000002'),UNHEX(SHA2('different-revision',256)))", 'auto_collection_source_reason');
    ++$assertions;
    collectionsFirstExecute($db, "INSERT INTO {$autoPhoto} (`auto_collection_id`,`class_photo_id`,`ordinal`) VALUES (UNHEX('30000000000040008000000000000001'),UNHEX('10000000000040008000000000000001'),1)");
    collectionsFirstRejected($db, "INSERT INTO {$autoPhoto} (`auto_collection_id`,`class_photo_id`,`ordinal`) VALUES (UNHEX('30000000000040008000000000000001'),UNHEX('10000000000040008000000000000002'),1)", 'auto_collection_ordinal');
    ++$assertions;
    collectionsFirstExecute($db, "INSERT INTO {$index} (`class_photo_id`,`source_checksum`,`face_state`,`search_state`) VALUES (UNHEX('10000000000040008000000000000001'),UNHEX(SHA2('synthetic',256)),'PENDING','PENDING')");
    collectionsFirstRejected($db, "INSERT INTO {$index} (`class_photo_id`,`source_checksum`,`face_state`,`search_state`) VALUES (UNHEX('10000000000040008000000000000002'),UNHEX(SHA2('synthetic2',256)),'UNKNOWN','PENDING')", 'ai_index_state');
    ++$assertions;
    collectionsFirstExecute($db, "INSERT INTO {$job} (`job_id`,`class_photo_id`,`job_kind`,`trigger_kind`,`expected_checksum`) VALUES (UNHEX('40000000000040008000000000000001'),UNHEX('10000000000040008000000000000001'),'INDEX_ASSET','NEW_PHOTO',UNHEX(SHA2('synthetic',256)))");
    collectionsFirstRejected($db, "INSERT INTO {$job} (`job_id`,`class_photo_id`,`job_kind`,`trigger_kind`,`expected_checksum`) VALUES (UNHEX('40000000000040008000000000000002'),UNHEX('10000000000040008000000000000001'),'INDEX_ASSET','NEW_PHOTO',UNHEX(SHA2('synthetic',256)))", 'active_ai_job_dedupe');
    ++$assertions;
    if ($db->query("SELECT `display_alias` FROM {$album} LIMIT 1") === false) {
        collectionsFirstFail('collections_first_album_alias_missing');
    }
    ++$assertions;

    fwrite(STDOUT, 'COLLECTIONS_FIRST_SCHEMA=PASS assertions=' . $assertions . ' run=' . $run . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'COLLECTIONS_FIRST_SCHEMA=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_reverse(array_unique($created)) as $table) {
        $db->query('DROP TABLE IF EXISTS ' . collectionsFirstIdent($table));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($ci) . '%';
    $remaining = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $count = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($count !== 0) {
        fwrite(STDERR, 'COLLECTIONS_FIRST_SCHEMA_CLEANUP=FAIL run=' . $run . ' remaining=' . $count . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
