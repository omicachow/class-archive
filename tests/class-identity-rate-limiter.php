<?php

declare(strict_types=1);

/**
 * Destructive only to a random zz_ci_rate_test_* table prefix created by this
 * process. Run inside the localhost-only Piwigo container:
 *
 * php /workspace/tests/class-identity-rate-limiter.php
 */

const TEST_PIWIGO_ROOT = '/var/www/html/piwigo/';

define('PHPWG_ROOT_PATH', TEST_PIWIGO_ROOT);

$conf = [];
require TEST_PIWIGO_ROOT . 'local/config/database.inc.php';
require '/workspace/plugins/ClassIdentity/src/Repository.php';
require '/workspace/plugins/ClassIdentity/src/RateLimiter.php';

use ClassIdentity\RateLimiter;
use ClassIdentity\Repository;

$passes = 0;
$isChild = false;
$cleaned = false;
$prefix = 'zz_ci_rate_test_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_';
if (preg_match('/\Azz_ci_rate_test_[0-9]+_[a-f0-9]{8}_\z/D', $prefix) !== 1) {
    throw new RuntimeException('unsafe_test_prefix');
}

/** @return mysqli */
function testDb(array $configuration): mysqli
{
    $db = new mysqli(
        (string) $configuration['db_host'],
        (string) $configuration['db_user'],
        (string) $configuration['db_password'],
        (string) $configuration['db_base'],
    );
    if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
        throw new RuntimeException('test_database_unavailable');
    }

    return $db;
}

function expect(bool $condition, string $label): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $passes++;
}

/** @return list<string> */
function testTableNames(string $prefix): array
{
    return [$prefix . 'class_identity_rate_limit_bucket'];
}

function cleanupTestTables(mysqli $db, string $prefix): void
{
    if (preg_match('/\Azz_ci_rate_test_[0-9]+_[a-f0-9]{8}_\z/D', $prefix) !== 1) {
        throw new RuntimeException('refusing_unsafe_test_cleanup');
    }

    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (testTableNames($prefix) as $table) {
            if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) {
                throw new RuntimeException('refusing_unsafe_test_table');
            }
            if ($db->query('DROP TABLE IF EXISTS `' . $table . '`') === false) {
                throw new RuntimeException('test_cleanup_failed');
            }
        }
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function countTestTables(mysqli $db, string $prefix): int
{
    $statement = $db->prepare(
        'SELECT COUNT(*) AS table_count FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, CHAR_LENGTH(?)) = ?',
    );
    $tablePrefix = $prefix . 'class_identity_';
    $statement->bind_param('ss', $tablePrefix, $tablePrefix);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();

    return (int) ($row['table_count'] ?? -1);
}

$db = null;
$secret = random_bytes(48);

try {
    $db = testDb($conf);
    cleanupTestTables($db, $prefix);
    if (!isset($prefixeTable) || !is_string($prefixeTable)
        || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1
    ) {
        throw new RuntimeException('test_source_prefix_invalid');
    }
    $sourceRateTable = $prefixeTable . 'class_identity_rate_limit_bucket';
    $temporaryRateTable = $prefix . 'class_identity_rate_limit_bucket';
    if ($db->query(
        'CREATE TABLE `' . $temporaryRateTable . '` LIKE `' . $sourceRateTable . '`'
    ) === false) {
        throw new RuntimeException('test_rate_table_clone_failed');
    }
    $repository = new Repository($db, $prefix);
    expect(countTestTables($db, $prefix) === 1, 'isolated rate-limit table was cloned');

    $now = 2000000000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $limiter = new RateLimiter($repository, $secret, 60, 10, 2, $clock);
    $code = str_repeat('S', 20) . '.' . str_repeat('V', 40);
    $first = $limiter->consume('FAMILY_INVITE', '192.0.2.10', $code);
    $second = $limiter->consume('FAMILY_INVITE', '192.0.2.10', $code);
    $third = $limiter->consume('FAMILY_INVITE', '192.0.2.10', $code);
    expect($first['allowed'] && $second['allowed'], 'exact target threshold is allowed');
    expect(!$third['allowed'] && $third['state'] === RateLimiter::STATE_LIMITED, 'threshold plus one is denied');

    $now += 60;
    $rolled = $limiter->consume('FAMILY_INVITE', '192.0.2.10', $code);
    expect($rolled['allowed'], 'new fixed window allows a new attempt');

    $rosterLimiter = new RateLimiter($repository, $secret, 60, 50, 2, $clock);
    $claim1 = str_repeat('A', 20) . '.' . str_repeat('V', 40);
    $claim2 = str_repeat('B', 20) . '.' . str_repeat('V', 40);
    $claim3 = str_repeat('C', 20) . '.' . str_repeat('V', 40);
    expect($rosterLimiter->consume('CLAIM', '198.51.100.10', $claim1, 'c25-001')['allowed'], 'roster attempt one allowed');
    expect($rosterLimiter->consume('CLAIM', '198.51.100.11', $claim2, 'C25-001')['allowed'], 'roster canonicalization shares target');
    $rosterDenied = $rosterLimiter->consume('CLAIM', '198.51.100.12', $claim3, 'C25-001');
    expect(!$rosterDenied['allowed'] && $rosterDenied['state'] === RateLimiter::STATE_LIMITED, 'roster target threshold is enforced');

    $columns = $repository->fetchAll(
        'SELECT `COLUMN_NAME` FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY `ORDINAL_POSITION`',
        [$repository->table('rate_limit_bucket')],
    );
    $columnNames = array_column($columns, 'COLUMN_NAME');
    expect(
        count(array_intersect($columnNames, ['ip', 'ip_address', 'roster_code', 'selector', 'token', 'raw_value'])) === 0,
        'rate table has no raw subject column',
    );
    $hashRows = $repository->fetchAll(
        'SELECT OCTET_LENGTH(`subject_hash`) AS hash_bytes FROM `'
        . $repository->table('rate_limit_bucket') . '`',
    );
    expect(
        $hashRows !== [] && array_reduce($hashRows, static fn (bool $ok, array $row): bool => $ok && (int) $row['hash_bytes'] === 32, true),
        'every persisted subject is a fixed-size HMAC',
    );

    // Close the inherited connection before forking. Each child uses a fresh
    // connection so the test exercises real InnoDB row locking, not a fake.
    $db->close();
    $db = null;
    $concurrentNow = 2000100000;
    $children = [];
    for ($childIndex = 0; $childIndex < 8; $childIndex++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork_failed');
        }
        if ($pid === 0) {
            $isChild = true;
            try {
                $childDb = testDb($conf);
                $childRepository = new Repository($childDb, $prefix);
                $childLimiter = new RateLimiter(
                    $childRepository,
                    $secret,
                    60,
                    200,
                    200,
                    static fn (): int => $concurrentNow,
                );
                $childCode = str_repeat('Q', 20) . '.' . str_repeat('W', 40);
                for ($attempt = 0; $attempt < 10; $attempt++) {
                    $decision = $childLimiter->consume(
                        'FAMILY_INVITE',
                        '203.0.113.77',
                        $childCode,
                    );
                    if (!$decision['allowed']) {
                        exit(21);
                    }
                }
                $childDb->close();
                exit(0);
            } catch (Throwable) {
                exit(22);
            }
        }
        $children[] = $pid;
    }

    foreach ($children as $childPid) {
        pcntl_waitpid($childPid, $status);
        expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'concurrent worker completed');
    }

    $db = testDb($conf);
    $repository = new Repository($db, $prefix);
    $concurrentRows = $repository->fetchAll(
        'SELECT `scope`, `attempt_count` FROM `' . $repository->table('rate_limit_bucket') . '` '
        . "WHERE `purpose` = 'FAMILY_INVITE' AND `window_id` = ? ORDER BY `scope`",
        [intdiv($concurrentNow, 60)],
    );
    expect(count($concurrentRows) === 2, 'concurrency produced one row per logical bucket');
    expect(
        array_map('intval', array_column($concurrentRows, 'attempt_count')) === [80, 80],
        'concurrent atomic counters lost no increments',
    );

    $closedDb = testDb($conf);
    $closedRepository = new Repository($closedDb, $prefix);
    $closedDb->close();
    $unavailableLimiter = new RateLimiter($closedRepository, $secret, 60, 10, 10, static fn (): int => $now);
    $unavailable = $unavailableLimiter->consume('FAMILY_INVITE', '192.0.2.22', $code);
    expect(!$unavailable['allowed'] && $unavailable['state'] === RateLimiter::STATE_UNAVAILABLE, 'database error fails closed');

    $invalidSource = $limiter->consume('FAMILY_INVITE', 'not-an-ip', $code);
    expect(!$invalidSource['allowed'] && $invalidSource['state'] === RateLimiter::STATE_UNAVAILABLE, 'unknown source fails closed');

    cleanupTestTables($db, $prefix);
    $cleaned = true;
    expect(countTestTables($db, $prefix) === 0, 'isolated migration leaves no test tables');

    echo 'ClassIdentity RateLimiter: PASS (' . $passes . " assertions)\n";
} finally {
    if (!$isChild) {
        if (!$db instanceof mysqli) {
            $db = testDb($conf);
        }
        if (!$cleaned) {
            cleanupTestTables($db, $prefix);
        }
        $db->close();
    }
    if (function_exists('sodium_memzero')) {
        sodium_memzero($secret);
    }
}
