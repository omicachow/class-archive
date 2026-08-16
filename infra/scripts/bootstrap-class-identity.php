<?php

declare(strict_types=1);

const CLASS_IDENTITY_PIWIGO_ROOT = '/var/www/html/piwigo';
const CLASS_IDENTITY_PLUGIN_ID = 'ClassIdentity';
const CLASS_IDENTITY_PLUGIN_VERSION = '0.1.0';
const CLASS_IDENTITY_MAINTENANCE_MARKER = CLASS_IDENTITY_PIWIGO_ROOT . '/_data/.class-archive-maintenance';
const CLASS_IDENTITY_MAINTENANCE_CONTENT = "class-archive-identity-bootstrap\n";

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function assertRuntimeUser(): void
{
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
        fail('ClassIdentity bootstrap requires PHP CLI with POSIX support.');
    }
    $uid = posix_geteuid();
    $account = posix_getpwuid($uid);
    if ($uid === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        fail('Run ClassIdentity bootstrap as the nginx user, never root.');
    }
}

function assertTrustedMaintenanceGate(): void
{
    $uid = posix_geteuid();
    $root = realpath(CLASS_IDENTITY_PIWIGO_ROOT);
    $dataDirectory = realpath(CLASS_IDENTITY_PIWIGO_ROOT . '/_data');
    if (
        $root !== CLASS_IDENTITY_PIWIGO_ROOT
        || $dataDirectory !== CLASS_IDENTITY_PIWIGO_ROOT . '/_data'
        || !is_dir($dataDirectory)
        || is_link(CLASS_IDENTITY_PIWIGO_ROOT)
        || is_link(CLASS_IDENTITY_PIWIGO_ROOT . '/_data')
    ) {
        fail('The ClassIdentity maintenance root is unsafe or unavailable.');
    }

    $path = CLASS_IDENTITY_MAINTENANCE_MARKER;
    clearstatcache(true, $path);
    $metadata = @lstat($path);
    if (
        !is_array($metadata)
        || is_link($path)
        || (($metadata['mode'] ?? 0) & 0170000) !== 0100000
        || realpath($path) !== $path
    ) {
        fail('An exact regular ClassIdentity maintenance marker is required.');
    }
    if (
        (int) ($metadata['uid'] ?? -1) !== $uid
        || (($metadata['mode'] ?? 0) & 0777) !== 0600
        || (int) ($metadata['nlink'] ?? 0) !== 1
    ) {
        fail('The ClassIdentity maintenance marker owner or permissions are untrusted.');
    }
    $contents = file_get_contents($path);
    if (!is_string($contents) || !hash_equals(CLASS_IDENTITY_MAINTENANCE_CONTENT, $contents)) {
        fail('The ClassIdentity maintenance marker content is untrusted.');
    }
}

/**
 * @return array{verify_only: bool, with_synthetic_fixtures: bool, require_maintenance_marker: bool}
 */
function parseArguments(array $arguments): array
{
    $verifyOnly = false;
    $withSyntheticFixtures = false;
    $requireMaintenanceMarker = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--verify-only' && !$verifyOnly) {
            $verifyOnly = true;
            continue;
        }
        if ($argument === '--with-synthetic-fixtures' && !$withSyntheticFixtures) {
            $withSyntheticFixtures = true;
            continue;
        }
        if ($argument === '--require-maintenance-marker' && !$requireMaintenanceMarker) {
            $requireMaintenanceMarker = true;
            continue;
        }
        fail("Unknown or duplicate bootstrap argument: {$argument}");
    }
    if (!$verifyOnly && $requireMaintenanceMarker) {
        fail('--require-maintenance-marker is only valid with --verify-only.');
    }

    return [
        'verify_only' => $verifyOnly,
        'with_synthetic_fixtures' => $withSyntheticFixtures,
        // A state-changing bootstrap always requires the exact installer gate.
        'require_maintenance_marker' => !$verifyOnly || $requireMaintenanceMarker,
    ];
}

function preparePiwigoBootstrap(bool $requireMaintenanceMarker): void
{
    assertRuntimeUser();
    if ($requireMaintenanceMarker) {
        // This check deliberately happens before Piwigo/plugin bootstrap and is
        // repeated immediately before enforcement can be disabled.
        assertTrustedMaintenanceGate();
    }
    if (!is_file(CLASS_IDENTITY_PIWIGO_ROOT . '/local/config/database.inc.php')) {
        fail('Piwigo is not installed.');
    }
    chdir(CLASS_IDENTITY_PIWIGO_ROOT) || fail('Cannot enter the Piwigo application directory.');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

function assertPluginActive(): void
{
    $rows = query2array(
        'SELECT id, version, state FROM ' . PLUGINS_TABLE
        . " WHERE id = 'ClassIdentity'"
    );
    if (
        count($rows) !== 1
        || ($rows[0]['state'] ?? null) !== 'active'
        || ($rows[0]['version'] ?? null) !== CLASS_IDENTITY_PLUGIN_VERSION
    ) {
        fail('Install and activate ClassIdentity 0.1.0 before bootstrap.');
    }
}

/** @return array<string, int> */
function ensureManagedGroups(\ClassIdentity\Repository $repository): array
{
    $groupIds = [];
    foreach (['CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS'] as $roleCode) {
        $escaped = pwg_db_real_escape_string($roleCode);
        $rows = query2array(
            'SELECT id, name, is_default FROM ' . GROUPS_TABLE . " WHERE name = '{$escaped}'"
        );
        if (count($rows) > 1) {
            fail("Managed group {$roleCode} is ambiguous.");
        }
        if ($rows === []) {
            single_insert(GROUPS_TABLE, ['name' => $roleCode, 'is_default' => 'false']);
            $rows = query2array(
                'SELECT id, name, is_default FROM ' . GROUPS_TABLE . " WHERE name = '{$escaped}'"
            );
        }
        if (
            count($rows) !== 1
            || ($rows[0]['name'] ?? null) !== $roleCode
            || ($rows[0]['is_default'] ?? null) !== 'false'
        ) {
            fail("Managed group {$roleCode} does not match the locked non-default definition.");
        }
        $groupId = (int) ($rows[0]['id'] ?? 0);
        if ($groupId <= 0 || $groupId > 65535) {
            fail("Managed group {$roleCode} has an unsupported identifier.");
        }
        $groupIds[$roleCode] = $groupId;
    }

    $mappingTable = '`' . $repository->table('role_group') . '`';
    foreach ($groupIds as $roleCode => $groupId) {
        $collisions = $repository->fetchAll(
            "SELECT `id`, `role_code`, `piwigo_group_id`, `expected_group_name`, `is_business_role`, `state` FROM {$mappingTable} "
            . 'WHERE `role_code` = ? OR `piwigo_group_id` = ? OR `expected_group_name` = ?',
            [$roleCode, $groupId, $roleCode],
        );
        if ($collisions === []) {
            $repository->execute(
                "INSERT INTO {$mappingTable} (`role_code`, `piwigo_group_id`, `expected_group_name`, `is_business_role`, `state`) "
                . "VALUES (?, ?, ?, 1, 'ACTIVE')",
                [$roleCode, $groupId, $roleCode],
            );
            $collisions = $repository->fetchAll(
                "SELECT `id`, `role_code`, `piwigo_group_id`, `expected_group_name`, `is_business_role`, `state` FROM {$mappingTable} "
                . 'WHERE `role_code` = ?',
                [$roleCode],
            );
        }
        if (
            count($collisions) !== 1
            || ($collisions[0]['role_code'] ?? null) !== $roleCode
            || (int) ($collisions[0]['piwigo_group_id'] ?? 0) !== $groupId
            || ($collisions[0]['expected_group_name'] ?? null) !== $roleCode
            || (int) ($collisions[0]['is_business_role'] ?? 0) !== 1
            || ($collisions[0]['state'] ?? null) !== 'ACTIVE'
        ) {
            fail("Managed role mapping {$roleCode} conflicts with existing state.");
        }
    }

    $activeBusinessMappings = $repository->fetchAll(
        "SELECT `role_code` FROM {$mappingTable} WHERE `is_business_role` = 1 AND `state` = 'ACTIVE' ORDER BY `role_code`"
    );
    $actualRoles = array_map(static fn(array $row): string => (string) $row['role_code'], $activeBusinessMappings);
    $expectedRoles = array_keys($groupIds);
    sort($expectedRoles, SORT_STRING);
    if ($actualRoles !== $expectedRoles) {
        fail('Unexpected active business-role mapping exists.');
    }

    return $groupIds;
}

/** @return array{id: int, created: bool} */
function ensureSystemAdmin(\ClassIdentity\Repository $repository): array
{
    global $conf;

    $webmasterId = (int) ($conf['webmaster_id'] ?? 0);
    if ($webmasterId <= 0 || $webmasterId === (int) ($conf['guest_id'] ?? 0)) {
        fail('Piwigo has no explicit bootstrap webmaster.');
    }
    $coreRows = query2array(
        'SELECT u.id, ui.status FROM ' . USERS_TABLE . ' u '
        . 'JOIN ' . USER_INFOS_TABLE . ' ui ON ui.user_id = u.id WHERE u.id = ' . $webmasterId
    );
    if (count($coreRows) !== 1 || ($coreRows[0]['status'] ?? null) !== 'webmaster') {
        fail('The configured Piwigo webmaster is missing or has an unexpected status.');
    }

    $principalTable = '`' . $repository->table('principal') . '`';
    $created = false;
    $principal = $repository->transaction(
        static function (\ClassIdentity\Repository $transaction) use ($principalTable, $webmasterId, &$created): array {
            $row = $transaction->fetchOne(
                "SELECT * FROM {$principalTable} WHERE `piwigo_user_id` = ? FOR UPDATE",
                [$webmasterId],
            );
            if ($row === null) {
                $transaction->execute(
                    "INSERT INTO {$principalTable} "
                    . "(`principal_type`, `system_role`, `account_id`, `piwigo_user_id`, `state`, `auth_epoch`) "
                    . "VALUES ('SYSTEM_ACCOUNT', 'SYSTEM_ADMIN', NULL, ?, 'ACTIVE', 0)",
                    [$webmasterId],
                );
                $created = true;
                $row = $transaction->fetchOne(
                    "SELECT * FROM {$principalTable} WHERE `piwigo_user_id` = ? FOR UPDATE",
                    [$webmasterId],
                );
            }
            if (
                !is_array($row)
                || ($row['principal_type'] ?? null) !== 'SYSTEM_ACCOUNT'
                || ($row['system_role'] ?? null) !== 'SYSTEM_ADMIN'
                || ($row['account_id'] ?? null) !== null
                || ($row['state'] ?? null) !== 'ACTIVE'
            ) {
                fail('The Piwigo webmaster conflicts with an existing ClassIdentity principal.');
            }
            return $row;
        }
    );

    $principalId = (int) ($principal['id'] ?? 0);
    if ($principalId <= 0) {
        fail('SYSTEM_ADMIN principal creation could not be verified.');
    }
    if ($created) {
        \ClassIdentity\Audit::fromPiwigo()->append([
            'actor_principal_id' => $principalId,
            'actor_user_id' => $webmasterId,
            'actor_kind' => 'SYSTEM_ADMIN',
            'action' => 'PRINCIPAL_SECURITY_CHANGE',
            'target_type' => 'PRINCIPAL',
            'target_id' => (string) $principalId,
            'target_principal_id' => $principalId,
            'new_value' => [
                'principal_type' => 'SYSTEM_ACCOUNT',
                'system_role' => 'SYSTEM_ADMIN',
                'state' => 'ACTIVE',
            ],
            'reason' => 'Initial secure CLI bootstrap',
            'result' => 'SUCCESS',
        ]);
    }

    // Existing sessions predate the principal binding and cannot carry a
    // trusted auth_epoch snapshot. Force a clean post-bootstrap login.
    \ClassIdentity\CoreAdapter::revokeAllCredentials($webmasterId);

    return ['id' => $principalId, 'created' => $created];
}

/** @return array<string, array{id: int, username: string, role: string}> */
function locateSyntheticUsers(): array
{
    $sets = [
        'fixture' => [
            'CLASSMATE' => 'fixture-classmate',
            'TEACHER' => 'fixture-teacher',
            'FAMILY' => 'fixture-family',
            'ANONYMOUS' => 'fixture-anonymous',
        ],
        'eval' => [
            'CLASSMATE' => 'classmate_eval',
            'TEACHER' => 'teacher_eval',
            'FAMILY' => 'family_eval',
            'ANONYMOUS' => 'anonymous_eval',
        ],
    ];

    $complete = [];
    foreach ($sets as $setName => $roleNames) {
        $resolved = [];
        $present = 0;
        foreach ($roleNames as $role => $username) {
            $escaped = pwg_db_real_escape_string($username);
            $rows = query2array(
                'SELECT u.id, u.username, ui.status FROM ' . USERS_TABLE . ' u '
                . 'JOIN ' . USER_INFOS_TABLE . " ui ON ui.user_id = u.id WHERE u.username = '{$escaped}'"
            );
            if (count($rows) > 1) {
                fail('Synthetic fixture username is ambiguous.');
            }
            if ($rows !== []) {
                ++$present;
                if (($rows[0]['status'] ?? null) !== 'normal') {
                    fail('Synthetic fixture account has an unexpected Core status.');
                }
                $resolved[$role] = [
                    'id' => (int) $rows[0]['id'],
                    'username' => (string) $rows[0]['username'],
                    'role' => $role,
                ];
            }
        }
        if ($present !== 0 && $present !== count($roleNames)) {
            fail("Synthetic {$setName} account set is incomplete.");
        }
        if ($present === count($roleNames)) {
            $complete[] = $resolved;
        }
    }
    if (count($complete) !== 1) {
        fail('Expected exactly one complete allowlisted synthetic account set.');
    }
    return $complete[0];
}

/**
 * Create only the exact fixture-* Core accounts required by the explicit
 * synthetic bootstrap. Production bootstrap never calls this function.
 * Passwords are random, transient and immediately discarded; HTTP gates later
 * rotate these already-bound accounts to their own per-run password.
 */
function ensureSyntheticCoreUsers(): void
{
    $fixtureUsers = [
        'CLASSMATE' => 'fixture-classmate',
        'TEACHER' => 'fixture-teacher',
        'FAMILY' => 'fixture-family',
        'ANONYMOUS' => 'fixture-anonymous',
    ];
    $evalUsers = [
        'CLASSMATE' => 'classmate_eval',
        'TEACHER' => 'teacher_eval',
        'FAMILY' => 'family_eval',
        'ANONYMOUS' => 'anonymous_eval',
    ];

    $evalPresent = 0;
    foreach ($evalUsers as $username) {
        if (get_userid($username)) {
            ++$evalPresent;
        }
    }
    $fixturePresent = 0;
    foreach ($fixtureUsers as $username) {
        if (get_userid($username)) {
            ++$fixturePresent;
        }
    }

    if ($evalPresent !== 0) {
        if ($evalPresent !== count($evalUsers) || $fixturePresent !== 0) {
            fail('Synthetic Core account sets are partial or ambiguous.');
        }
        return;
    }
    if ($fixturePresent !== 0 && $fixturePresent !== count($fixtureUsers)) {
        fail('Pre-existing partial fixture Core account set is untrusted; maintenance remains active.');
    }

    $created = [];
    try {
        foreach ($fixtureUsers as $role => $username) {
            $userId = get_userid($username);
            if (!$userId) {
                $password = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
                try {
                    try {
                        $userId = \ClassIdentity\CoreAdapter::registerUser(
                            $username,
                            $password,
                            $username . '@class-archive.invalid',
                        );
                        $created[] = ['id' => (int) $userId, 'username' => $username];
                    } catch (\ClassIdentity\CoreRegistrationException $registrationError) {
                        if (($createdId = $registrationError->createdUserId()) !== null) {
                            $created[] = ['id' => $createdId, 'username' => $username];
                        }
                        throw $registrationError;
                    }
                } finally {
                    unset($password);
                }
            }

            $rows = query2array(
                'SELECT u.id, u.username, ui.status FROM ' . USERS_TABLE . ' u '
                . 'JOIN ' . USER_INFOS_TABLE . ' ui ON ui.user_id = u.id WHERE u.id = ' . (int) $userId,
            );
            if (count($rows) !== 1
                || ($rows[0]['username'] ?? null) !== $username
                || ($rows[0]['status'] ?? null) !== 'normal'
            ) {
                fail("Synthetic {$role} Core account is not safely converged.");
            }
        }
    } catch (Throwable $creationError) {
        $uncompensated = [];
        foreach (array_reverse($created) as $candidate) {
            try {
                $userId = (int) $candidate['id'];
                $username = (string) $candidate['username'];
                $rows = query2array(
                    'SELECT username FROM ' . USERS_TABLE . ' WHERE id = ' . $userId,
                );
                if ($rows === []) {
                    continue;
                }
                $principalCount = (int) $GLOBALS['pwg_db_link']->query(
                    'SELECT COUNT(*) AS count FROM `'
                    . \ClassIdentity\Repository::fromPiwigo()->table('principal')
                    . '` WHERE piwigo_user_id = ' . $userId,
                )->fetch_assoc()['count'];
                if (count($rows) !== 1
                    || ($rows[0]['username'] ?? null) !== $username
                    || !array_key_exists($username, array_flip($fixtureUsers))
                    || $principalCount !== 0
                ) {
                    $uncompensated[] = $username;
                    continue;
                }
                \ClassIdentity\Access::withCoreMutationPermit(
                    static fn() => delete_user($userId),
                );
                if (get_userid($username)) {
                    $uncompensated[] = $username;
                }
            } catch (Throwable) {
                $uncompensated[] = (string) ($candidate['username'] ?? 'unknown');
            }
        }
        if ($uncompensated !== []) {
            fail('Synthetic Core account creation failed and exact compensation was incomplete; maintenance remains active for operator recovery.');
        }
        throw $creationError;
    }
}

function ensureSyntheticIdentity(
    \ClassIdentity\Repository $repository,
    string $rosterCode,
    string $identityType,
    string $realName,
): int {
    $table = '`' . $repository->table('identity') . '`';
    $row = $repository->fetchOne("SELECT * FROM {$table} WHERE `roster_code` = ?", [$rosterCode]);
    if ($row === null) {
        $repository->execute(
            "INSERT INTO {$table} (`roster_code`, `identity_type`, `real_name`, `state`, `seat_template_version`) "
            . "VALUES (?, ?, ?, 'ACTIVE', 1)",
            [$rosterCode, $identityType, $realName],
        );
        $row = $repository->fetchOne("SELECT * FROM {$table} WHERE `roster_code` = ?", [$rosterCode]);
    }
    if (
        !is_array($row) || ($row['identity_type'] ?? null) !== $identityType
        || ($row['real_name'] ?? null) !== $realName || ($row['state'] ?? null) !== 'ACTIVE'
        || (int) ($row['seat_template_version'] ?? 0) !== 1
    ) {
        fail("Synthetic identity {$rosterCode} conflicts with existing state.");
    }
    return (int) $row['id'];
}

function ensureSyntheticSeat(
    \ClassIdentity\Repository $repository,
    int $identityId,
    int $ordinal,
    string $seatType,
    string $state,
): int {
    $table = '`' . $repository->table('seat') . '`';
    $row = $repository->fetchOne(
        "SELECT * FROM {$table} WHERE `identity_id` = ? AND `ordinal` = ?",
        [$identityId, $ordinal],
    );
    if ($row === null) {
        $pseudonym = $seatType === 'ANONYMOUS' ? random_bytes(16) : null;
        $repository->execute(
            "INSERT INTO {$table} (`identity_id`, `ordinal`, `seat_type`, `state`, `pseudonym_subject`, `activated_at`) "
            . 'VALUES (?, ?, ?, ?, ?, ' . ($state === 'ACTIVE' ? 'CURRENT_TIMESTAMP(6)' : 'NULL') . ')',
            [$identityId, $ordinal, $seatType, $state, $pseudonym],
        );
        $row = $repository->fetchOne(
            "SELECT * FROM {$table} WHERE `identity_id` = ? AND `ordinal` = ?",
            [$identityId, $ordinal],
        );
    }
    if (
        !is_array($row) || ($row['seat_type'] ?? null) !== $seatType || ($row['state'] ?? null) !== $state
        || ($seatType === 'ANONYMOUS' && strlen((string) ($row['pseudonym_subject'] ?? '')) !== 16)
        || ($seatType !== 'ANONYMOUS' && ($row['pseudonym_subject'] ?? null) !== null)
    ) {
        fail('Synthetic Seat conflicts with existing state.');
    }
    return (int) $row['id'];
}

function ensureSyntheticBinding(
    \ClassIdentity\Repository $repository,
    int $seatId,
    array $coreUser,
    ?string $realName,
    ?string $familyRelationship,
): void {
    $accountTable = '`' . $repository->table('account') . '`';
    $principalTable = '`' . $repository->table('principal') . '`';
    $accounts = $repository->fetchAll("SELECT * FROM {$accountTable} WHERE `seat_id` = ?", [$seatId]);
    if ($accounts === []) {
        $repository->execute(
            "INSERT INTO {$accountTable} "
            . "(`seat_id`, `requested_username`, `real_name`, `family_relationship`, `state`, `current_marker`, `pseudonym_key_version`, `core_created_at`, `bound_at`) "
            . "VALUES (?, ?, ?, ?, 'ACTIVE', 1, ?, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))",
            [
                $seatId,
                $coreUser['username'],
                $realName,
                $familyRelationship,
                $coreUser['role'] === 'ANONYMOUS' ? 1 : null,
            ],
        );
        $accounts = $repository->fetchAll("SELECT * FROM {$accountTable} WHERE `seat_id` = ?", [$seatId]);
    }
    if (
        count($accounts) !== 1 || ($accounts[0]['requested_username'] ?? null) !== $coreUser['username']
        || ($accounts[0]['real_name'] ?? null) !== $realName
        || ($accounts[0]['family_relationship'] ?? null) !== $familyRelationship
        || ($accounts[0]['state'] ?? null) !== 'ACTIVE' || (int) ($accounts[0]['current_marker'] ?? 0) !== 1
    ) {
        fail('Synthetic Account conflicts with existing state.');
    }
    $accountId = (int) $accounts[0]['id'];

    $principals = $repository->fetchAll(
        "SELECT * FROM {$principalTable} WHERE `account_id` = ? OR `piwigo_user_id` = ?",
        [$accountId, $coreUser['id']],
    );
    if ($principals === []) {
        $repository->execute(
            "INSERT INTO {$principalTable} (`principal_type`, `system_role`, `account_id`, `piwigo_user_id`, `state`, `auth_epoch`) "
            . "VALUES ('SEAT_ACCOUNT', NULL, ?, ?, 'ACTIVE', 0)",
            [$accountId, $coreUser['id']],
        );
        $principals = $repository->fetchAll(
            "SELECT * FROM {$principalTable} WHERE `account_id` = ? OR `piwigo_user_id` = ?",
            [$accountId, $coreUser['id']],
        );
    }
    if (
        count($principals) !== 1 || ($principals[0]['principal_type'] ?? null) !== 'SEAT_ACCOUNT'
        || ($principals[0]['system_role'] ?? null) !== null
        || (int) ($principals[0]['account_id'] ?? 0) !== $accountId
        || (int) ($principals[0]['piwigo_user_id'] ?? 0) !== (int) $coreUser['id']
        || ($principals[0]['state'] ?? null) !== 'ACTIVE'
    ) {
        fail('Synthetic principal conflicts with existing state.');
    }
    \ClassIdentity\CoreAdapter::reconcileManagedGroups((int) $coreUser['id'], (string) $coreUser['role']);
}

function provisionSyntheticFixtures(\ClassIdentity\Repository $repository): void
{
    $users = locateSyntheticUsers();
    $classmateIdentity = ensureSyntheticIdentity(
        $repository, 'C-SYN-001', 'CLASSMATE', 'Synthetic Classmate'
    );
    $teacherIdentity = ensureSyntheticIdentity(
        $repository, 'T-SYN-001', 'TEACHER', 'Synthetic Teacher'
    );

    $classmateSeat = ensureSyntheticSeat($repository, $classmateIdentity, 1, 'CLASSMATE', 'ACTIVE');
    $familySeat = ensureSyntheticSeat($repository, $classmateIdentity, 2, 'FAMILY', 'ACTIVE');
    ensureSyntheticSeat($repository, $classmateIdentity, 3, 'FAMILY', 'AVAILABLE');
    ensureSyntheticSeat($repository, $classmateIdentity, 4, 'FAMILY', 'AVAILABLE');
    $anonymousSeat = ensureSyntheticSeat($repository, $classmateIdentity, 5, 'ANONYMOUS', 'ACTIVE');
    $teacherSeat = ensureSyntheticSeat($repository, $teacherIdentity, 1, 'TEACHER', 'ACTIVE');

    $classmateSeats = $repository->fetchAll(
        'SELECT `id` FROM `' . $repository->table('seat') . '` WHERE `identity_id` = ?',
        [$classmateIdentity],
    );
    $teacherSeats = $repository->fetchAll(
        'SELECT `id` FROM `' . $repository->table('seat') . '` WHERE `identity_id` = ?',
        [$teacherIdentity],
    );
    if (count($classmateSeats) !== 5 || count($teacherSeats) !== 1) {
        fail('Synthetic identity has an unexpected Seat count.');
    }

    ensureSyntheticBinding($repository, $classmateSeat, $users['CLASSMATE'], 'Synthetic Classmate', null);
    ensureSyntheticBinding($repository, $familySeat, $users['FAMILY'], 'Synthetic Family', 'OTHER_FAMILY');
    ensureSyntheticBinding($repository, $anonymousSeat, $users['ANONYMOUS'], null, null);
    ensureSyntheticBinding($repository, $teacherSeat, $users['TEACHER'], 'Synthetic Teacher', null);
}

function assertMigrationLedger(\ClassIdentity\Repository $repository): void
{
    $rows = $repository->fetchAll(
        'SELECT `version`, `migration_name`, LENGTH(`checksum`) AS `checksum_length` FROM `'
        . $repository->table('migration') . '` ORDER BY `version`'
    );
    if (count($rows) !== \ClassIdentity\Schema::CURRENT_VERSION) {
        fail('ClassIdentity migration ledger is incomplete.');
    }
    foreach ($rows as $index => $row) {
        if ((int) $row['version'] !== $index + 1 || (int) $row['checksum_length'] !== 32) {
            fail('ClassIdentity migration ledger is inconsistent.');
        }
    }
}

function assertManagedGroupsConverged(\ClassIdentity\Repository $repository): void
{
    $expectedRoles = ['ANONYMOUS', 'CLASSMATE', 'FAMILY', 'TEACHER'];
    $resolvedGroupIds = [];
    foreach ($expectedRoles as $roleCode) {
        $escaped = pwg_db_real_escape_string($roleCode);
        $rows = query2array(
            'SELECT id, name, is_default FROM ' . GROUPS_TABLE . " WHERE name = '{$escaped}'"
        );
        if (
            count($rows) !== 1
            || ($rows[0]['name'] ?? null) !== $roleCode
            || ($rows[0]['is_default'] ?? null) !== 'false'
        ) {
            fail("Managed group {$roleCode} is not converged.");
        }
        $groupId = (int) ($rows[0]['id'] ?? 0);
        if ($groupId <= 0 || $groupId > 65535) {
            fail("Managed group {$roleCode} has an unsupported identifier.");
        }
        $resolvedGroupIds[$roleCode] = $groupId;
    }

    $mappingTable = '`' . $repository->table('role_group') . '`';
    $rows = $repository->fetchAll(
        "SELECT `role_code`, `piwigo_group_id`, `expected_group_name`, `is_business_role`, `state` "
        . "FROM {$mappingTable} WHERE `is_business_role` = 1 AND `state` = 'ACTIVE' ORDER BY `role_code`"
    );
    if (count($rows) !== count($expectedRoles)) {
        fail('Managed business-role mappings are not converged.');
    }
    foreach ($rows as $index => $row) {
        $roleCode = $expectedRoles[$index];
        if (
            ($row['role_code'] ?? null) !== $roleCode
            || ($row['expected_group_name'] ?? null) !== $roleCode
            || (int) ($row['piwigo_group_id'] ?? 0) !== $resolvedGroupIds[$roleCode]
            || (int) ($row['is_business_role'] ?? 0) !== 1
            || ($row['state'] ?? null) !== 'ACTIVE'
        ) {
            fail("Managed role mapping {$roleCode} is not converged.");
        }
    }
}

function assertSystemAdminConverged(\ClassIdentity\Repository $repository): void
{
    global $conf;

    $webmasterId = (int) ($conf['webmaster_id'] ?? 0);
    if ($webmasterId <= 0 || $webmasterId === (int) ($conf['guest_id'] ?? 0)) {
        fail('Piwigo has no explicit bootstrap webmaster.');
    }
    $coreRows = query2array(
        'SELECT u.id, ui.status FROM ' . USERS_TABLE . ' u '
        . 'JOIN ' . USER_INFOS_TABLE . ' ui ON ui.user_id = u.id WHERE u.id = ' . $webmasterId
    );
    if (count($coreRows) !== 1 || ($coreRows[0]['status'] ?? null) !== 'webmaster') {
        fail('The configured Piwigo webmaster is not converged.');
    }

    $principal = $repository->fetchOne(
        'SELECT * FROM `' . $repository->table('principal') . '` WHERE `piwigo_user_id` = ?',
        [$webmasterId],
    );
    if (
        !is_array($principal)
        || ($principal['principal_type'] ?? null) !== 'SYSTEM_ACCOUNT'
        || ($principal['system_role'] ?? null) !== 'SYSTEM_ADMIN'
        || ($principal['account_id'] ?? null) !== null
        || ($principal['state'] ?? null) !== 'ACTIVE'
    ) {
        fail('The SYSTEM_ADMIN principal is not converged.');
    }

    \ClassIdentity\Access::resetRepositoryForTests();
    $context = \ClassIdentity\Access::resolveAuthorizationContext($webmasterId);
    if (
        !is_array($context)
        || ($context['principal_type'] ?? null) !== 'SYSTEM_ACCOUNT'
        || ($context['system_role'] ?? null) !== 'SYSTEM_ADMIN'
        || ($context['account_id'] ?? null) !== null
        || ($context['seat_id'] ?? null) !== null
        || ($context['identity_id'] ?? null) !== null
    ) {
        fail('SYSTEM_ADMIN fail-closed authorization verification failed.');
    }
}

function assertSyntheticPrincipalsConverged(): void
{
    foreach (locateSyntheticUsers() as $user) {
        $fixtureContext = \ClassIdentity\Access::resolveAuthorizationContext((int) $user['id']);
        if (($fixtureContext['role'] ?? null) !== $user['role']) {
            fail('Synthetic principal authorization verification failed.');
        }
    }
}

function verifyRuntimeState(bool $withSyntheticFixtures): void
{
    global $conf;

    if (($conf['class_identity_enforcement'] ?? null) !== true) {
        fail('ClassIdentity enforcement is not explicitly enabled.');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    assertMigrationLedger($repository);
    assertManagedGroupsConverged($repository);
    assertSystemAdminConverged($repository);
    if ($withSyntheticFixtures) {
        assertSyntheticPrincipalsConverged();
    }

    fwrite(STDOUT, 'CLASS_IDENTITY_RUNTIME_VERIFIED system_admin=verified');
    fwrite(STDOUT, $withSyntheticFixtures ? " synthetic=verified\n" : " synthetic=skipped\n");
}

/** @param array{verify_only: bool, with_synthetic_fixtures: bool, require_maintenance_marker: bool} $options */
function main(array $options): void
{
    $withSyntheticFixtures = $options['with_synthetic_fixtures'];

    global $conf;
    assertPluginActive();
    if ($options['verify_only']) {
        verifyRuntimeState($withSyntheticFixtures);
        return;
    }

    // Never open the permissive bootstrap window based only on a caller flag.
    // Revalidate the exact persistent marker immediately before the DB change.
    assertTrustedMaintenanceGate();
    $bootstrapWindowOpen = true;
    try {
        // The only supported enforcement-off state is this bounded CLI setup
        // window. Missing/corrupt settings still mean enabled in Access.php.
        conf_update_param('class_identity_enforcement', false, true);
        if (($conf['class_identity_enforcement'] ?? null) !== false) {
            fail('Could not open the explicit ClassIdentity bootstrap window.');
        }
        assertTrustedMaintenanceGate();

        \ClassIdentity\Schema::fromPiwigo(CLASS_IDENTITY_PLUGIN_VERSION)->migrate();
        $repository = \ClassIdentity\Repository::fromPiwigo();
        assertMigrationLedger($repository);
        ensureManagedGroups($repository);
        $systemAdmin = ensureSystemAdmin($repository);
        if ($withSyntheticFixtures) {
            ensureSyntheticCoreUsers();
            provisionSyntheticFixtures($repository);
        }

        conf_update_param('class_identity_enforcement', true, true);
        $bootstrapWindowOpen = false;
        if (($conf['class_identity_enforcement'] ?? null) !== true) {
            fail('Could not close the ClassIdentity bootstrap window.');
        }

        \ClassIdentity\Access::resetRepositoryForTests();
        $webmasterId = (int) ($conf['webmaster_id'] ?? 0);
        $context = \ClassIdentity\Access::resolveAuthorizationContext($webmasterId);
        if (
            !is_array($context) || ($context['principal_type'] ?? null) !== 'SYSTEM_ACCOUNT'
            || ($context['system_role'] ?? null) !== 'SYSTEM_ADMIN'
            || ($context['account_id'] ?? null) !== null || ($context['seat_id'] ?? null) !== null
            || ($context['identity_id'] ?? null) !== null
        ) {
            fail('SYSTEM_ADMIN fail-closed authorization assertion failed.');
        }
        if ($withSyntheticFixtures) {
            foreach (locateSyntheticUsers() as $user) {
                $fixtureContext = \ClassIdentity\Access::resolveAuthorizationContext((int) $user['id']);
                if (($fixtureContext['role'] ?? null) !== $user['role']) {
                    fail('Synthetic principal authorization assertion failed.');
                }
            }
        }
        if (function_exists('invalidate_user_cache')) {
            invalidate_user_cache();
        }

        fwrite(STDOUT, 'CLASS_IDENTITY_BOOTSTRAPPED system_admin=' . ($systemAdmin['created'] ? 'created' : 'verified'));
        fwrite(STDOUT, $withSyntheticFixtures ? " synthetic=verified\n" : " synthetic=skipped\n");
    } catch (Throwable $exception) {
        if ($bootstrapWindowOpen) {
            try {
                // A failed bootstrap must deny unmapped accounts on the next
                // request rather than silently leaving a permissive window.
                conf_update_param('class_identity_enforcement', true, true);
            } catch (Throwable) {
                // Preserve the original bounded error without disclosing DB or
                // configuration values. The process still exits non-zero.
            }
        }
        throw $exception;
    }
}

try {
    $options = parseArguments($_SERVER['argv'] ?? []);
    preparePiwigoBootstrap($options['require_maintenance_marker']);
    if (!$options['verify_only']) {
        if (defined('CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT')) {
            fail('Refusing a pre-defined ClassIdentity bootstrap context.');
        }
        // Access.php accepts enforcement=false only when this exact value was
        // established by this marker-validated CLI entry point before plugins
        // load. HTTP/FPM and arbitrary CLI processes always remain enforced.
        define('CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT', 'class-archive-cli-bootstrap-v1');
    }
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    if (!defined('PHPWG_VERSION') || PHPWG_VERSION !== '16.4.0') {
        fail('ClassIdentity bootstrap requires the locked Piwigo 16.4.0 runtime.');
    }
    require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    require_once CLASS_IDENTITY_PIWIGO_ROOT . '/plugins/ClassIdentity/src/Schema.php';
    require_once CLASS_IDENTITY_PIWIGO_ROOT . '/plugins/ClassIdentity/src/Audit.php';
    main($options);
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(1);
}
