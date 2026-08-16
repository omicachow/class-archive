<?php

declare(strict_types=1);

/**
 * Narrow reversible fault injector for the enforcement=false/no-marker HTTP
 * gate. It never changes plugin trees, identities, media or credentials.
 */

const CI_FAULT_ROOT = '/var/www/html/piwigo';
const CI_FAULT_MARKER = CI_FAULT_ROOT . '/_data/.class-archive-maintenance';
const CI_FAULT_OWNER_PARAM = 'ci_enforce_fault_owner';

function ciFaultFail(string $message): never
{
    fwrite(STDERR, "ENFORCEMENT_FAULT_FIXTURE=ERROR {$message}\n");
    exit(2);
}

/** @param array<string, mixed> $payload */
function ciFaultJson(array $payload): never
{
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

function ciFaultAssertRuntime(): void
{
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
        ciFaultFail('CLI POSIX runtime required.');
    }
    $uid = posix_geteuid();
    $account = posix_getpwuid($uid);
    $root = realpath(CI_FAULT_ROOT);
    $data = realpath(CI_FAULT_ROOT . '/_data');
    if (
        $uid === 0
        || !is_array($account)
        || ($account['name'] ?? null) !== 'nginx'
        || $root !== CI_FAULT_ROOT
        || $data !== CI_FAULT_ROOT . '/_data'
        || !is_dir($data)
        || is_link(CI_FAULT_ROOT)
        || is_link(CI_FAULT_ROOT . '/_data')
    ) {
        ciFaultFail('Trusted nginx runtime and persistent root required.');
    }
}

function ciFaultPathState(string $path): string
{
    clearstatcache(true, $path);
    if (is_link($path)) {
        return 'SYMLINK';
    }
    if (!file_exists($path)) {
        return 'ABSENT';
    }
    $metadata = @lstat($path);
    if (
        !is_array($metadata)
        || (($metadata['mode'] ?? 0) & 0170000) !== 0100000
        || (($metadata['mode'] ?? 0) & 0777) !== 0600
        || (int) ($metadata['uid'] ?? -1) !== posix_geteuid()
        || (int) ($metadata['nlink'] ?? 0) !== 1
        || realpath($path) !== $path
    ) {
        return 'UNTRUSTED';
    }
    return 'FILE';
}

/** @return array{database: mysqli, table: string} */
function ciFaultDatabase(): array
{
    $conf = [];
    $prefixeTable = null;
    require CI_FAULT_ROOT . '/local/config/database.inc.php';
    if (
        !is_string($prefixeTable)
        || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1
        || ($conf['dblayer'] ?? null) !== 'mysqli'
    ) {
        ciFaultFail('Database configuration unavailable.');
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $database = @new mysqli(
        (string) ($conf['db_host'] ?? ''),
        (string) ($conf['db_user'] ?? ''),
        (string) ($conf['db_password'] ?? ''),
        (string) ($conf['db_base'] ?? ''),
    );
    if ($database->connect_errno !== 0 || !$database->set_charset('utf8mb4')) {
        ciFaultFail('Database unavailable.');
    }
    return ['database' => $database, 'table' => $prefixeTable . 'config'];
}

function ciFaultReadState(mysqli $database, string $table): string
{
    $result = $database->query(
        "SELECT `value` FROM `{$table}` WHERE `param` = 'class_identity_enforcement'"
    );
    $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    if (count($rows) !== 1 || !in_array($rows[0]['value'] ?? null, ['true', 'false'], true)) {
        ciFaultFail('Enforcement state is unavailable or invalid.');
    }
    return (string) $rows[0]['value'];
}

function ciFaultSetState(mysqli $database, string $table, string $state): void
{
    if (!in_array($state, ['true', 'false'], true)) {
        ciFaultFail('Invalid enforcement target.');
    }
    $statement = $database->prepare(
        "UPDATE `{$table}` SET `value` = ? WHERE `param` = 'class_identity_enforcement'"
    );
    if ($statement === false) {
        ciFaultFail('Enforcement update unavailable.');
    }
    $statement->bind_param('s', $state);
    $ok = $statement->execute();
    $affected = $statement->affected_rows;
    $statement->close();
    if (!$ok || $affected < 0 || ciFaultReadState($database, $table) !== $state) {
        ciFaultFail('Enforcement state did not converge.');
    }
}

function ciFaultOwnerState(mysqli $database, string $table, ?string $expectedRunId = null): string
{
    $statement = $database->prepare(
        "SELECT `value` FROM `{$table}` WHERE `param` = ?"
    );
    if ($statement === false) {
        ciFaultFail('Fault owner lookup unavailable.');
    }
    $parameter = CI_FAULT_OWNER_PARAM;
    $statement->bind_param('s', $parameter);
    if (!$statement->execute()) {
        $statement->close();
        ciFaultFail('Fault owner lookup failed.');
    }
    $result = $statement->get_result();
    $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $statement->close();

    if ($rows === []) {
        return 'ABSENT';
    }
    if (count($rows) !== 1) {
        return 'UNKNOWN';
    }
    $value = $rows[0]['value'] ?? null;
    if (!is_string($value) || preg_match('/\A[a-f0-9]{12}\z/D', $value) !== 1) {
        return 'UNKNOWN';
    }
    if ($expectedRunId === null) {
        return 'OWNED';
    }
    return hash_equals($expectedRunId, $value) ? 'EXACT' : 'UNKNOWN';
}

function ciFaultCreateOwner(mysqli $database, string $table, string $runId): void
{
    if (ciFaultOwnerState($database, $table) !== 'ABSENT') {
        ciFaultFail('Another enforcement fault is already active or ownership is unknown.');
    }
    $statement = $database->prepare(
        "INSERT INTO `{$table}` (`param`, `value`, `comment`) VALUES (?, ?, ?)"
    );
    if ($statement === false) {
        ciFaultFail('Fault owner creation unavailable.');
    }
    $parameter = CI_FAULT_OWNER_PARAM;
    $comment = 'Class Archive test-only enforcement fault recovery owner';
    $statement->bind_param('sss', $parameter, $runId, $comment);
    $ok = $statement->execute();
    $affected = $statement->affected_rows;
    $statement->close();
    if (!$ok || $affected !== 1 || ciFaultOwnerState($database, $table, $runId) !== 'EXACT') {
        ciFaultFail('Fault owner did not converge.');
    }
}

function ciFaultDeleteOwner(mysqli $database, string $table, string $runId): void
{
    if (ciFaultOwnerState($database, $table, $runId) !== 'EXACT') {
        ciFaultFail('Refusing recovery without the exact database run owner.');
    }
    $statement = $database->prepare(
        "DELETE FROM `{$table}` WHERE `param` = ? AND `value` = ?"
    );
    if ($statement === false) {
        ciFaultFail('Fault owner deletion unavailable.');
    }
    $parameter = CI_FAULT_OWNER_PARAM;
    $statement->bind_param('ss', $parameter, $runId);
    $ok = $statement->execute();
    $affected = $statement->affected_rows;
    $statement->close();
    if (!$ok || $affected !== 1 || ciFaultOwnerState($database, $table) !== 'ABSENT') {
        ciFaultFail('Recovered fault owner did not clear exactly.');
    }
}

function ciFaultBegin(string $runId, mysqli $database, string $table): never
{
    if (ciFaultPathState(CI_FAULT_MARKER) !== 'ABSENT') {
        ciFaultFail('Maintenance or another enforcement fault is already active.');
    }
    if (ciFaultOwnerState($database, $table) !== 'ABSENT') {
        ciFaultFail('Another enforcement fault is already active or ownership is unknown.');
    }
    if (ciFaultReadState($database, $table) !== 'true') {
        ciFaultFail('Fault injection requires explicit enforcement=true baseline.');
    }

    // Durable recovery ownership must exist before enforcement is disabled.
    ciFaultCreateOwner($database, $table, $runId);
    ciFaultSetState($database, $table, 'false');
    if (ciFaultPathState(CI_FAULT_MARKER) !== 'ABSENT') {
        ciFaultFail('The production maintenance marker unexpectedly appeared.');
    }
    ciFaultJson(['state' => 'false', 'marker' => 'ABSENT', 'owner' => 'EXACT']);
}

function ciFaultRestore(string $runId, mysqli $database, string $table): never
{
    if (ciFaultOwnerState($database, $table, $runId) !== 'EXACT') {
        ciFaultFail('Refusing recovery without the exact database run owner.');
    }
    // Restore enforcement first. The database owner remains if this write or its
    // verification fails, preserving explicit operator recovery evidence.
    ciFaultSetState($database, $table, 'true');
    ciFaultDeleteOwner($database, $table, $runId);
    ciFaultJson([
        'state' => ciFaultReadState($database, $table),
        'marker' => ciFaultPathState(CI_FAULT_MARKER),
        'owner' => ciFaultOwnerState($database, $table),
    ]);
}

$action = $argv[1] ?? '';
$runId = strtolower((string) ($argv[2] ?? ''));
if (!preg_match('/\A[a-f0-9]{12}\z/D', $runId)) {
    ciFaultFail('A 12-hex run id is required.');
}
ciFaultAssertRuntime();
$connection = ciFaultDatabase();
try {
    match ($action) {
        'begin' => ciFaultBegin($runId, $connection['database'], $connection['table']),
        'restore' => ciFaultRestore($runId, $connection['database'], $connection['table']),
        'status' => ciFaultJson([
            'state' => ciFaultReadState($connection['database'], $connection['table']),
            'marker' => ciFaultPathState(CI_FAULT_MARKER),
            'owner' => ciFaultOwnerState($connection['database'], $connection['table'], $runId),
        ]),
        default => ciFaultFail('Unknown action.'),
    };
} finally {
    $connection['database']->close();
}
