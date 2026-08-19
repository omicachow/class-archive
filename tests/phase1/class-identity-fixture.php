<?php

declare(strict_types=1);

/**
 * Narrow fixture driver for the Phase 1 real-HTTP acceptance test.
 *
 * This helper is deliberately not an authorization oracle. Authorization is
 * always asserted by class-identity-http.ps1 over HTTP. The helper may only:
 *
 * - create two unbound Piwigo users used to prove fail-closed login;
 * - return non-secret database state used for invariant assertions;
 * - expire one run-scoped Family Invitation and seed exact run-scoped saga
 *   failure states for the Admin HTTP repair/blocker acceptance paths;
 * - scan persistence for exact transient secrets without printing them; and
 * - remove rows whose roster/user names carry this run's CITEST namespace.
 *
 * It never inserts, updates, deletes or associates an image/category, and all
 * identity mutations are bounded to the random CITEST roster namespace.
 */

const CI_TEST_PIWIGO_ROOT = '/var/www/html/piwigo';

function ciTestFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function ciTestBoot(): void
{
    if (PHP_SAPI !== 'cli') {
        ciTestFail('CLI required.');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        ciTestFail('Refusing to run a fixture as root.');
    }
    chdir(CI_TEST_PIWIGO_ROOT) || ciTestFail('Cannot enter Piwigo root.');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

/** @return array{classmate:string,teacher:string,csrf_prefix:string,user_prefix:string} */
function ciTestNames(string $runId): array
{
    return [
        'classmate' => 'CIT-C-' . strtoupper($runId),
        'teacher' => 'CIT-T-' . strtoupper($runId),
        'csrf_prefix' => 'CIT-X-' . strtoupper($runId),
        'user_prefix' => 'cit_' . $runId . '_',
    ];
}

function ciTestJson(array $payload): never
{
    fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function ciTestScalar(string $sql): int
{
    $rows = query2array($sql);
    if (count($rows) !== 1) {
        ciTestFail('Scalar query did not return one row.');
    }
    return (int) array_values($rows[0])[0];
}

function ciTestQuoted(string $value): string
{
    return "'" . pwg_db_real_escape_string($value) . "'";
}

/**
 * Reset only the fixed-window limiter state used by this localhost synthetic
 * runner.  The real HTTP suite intentionally exercises rejected attempts;
 * retaining those buckets across retries would make a later run fail before
 * it reaches the business flow.  This helper is never loaded by production
 * requests and the suite is explicitly prohibited from using real accounts.
 */
function ciTestResetRateLimitBuckets(): void
{
    $table = \ClassIdentity\Repository::fromPiwigo()->table('rate_limit_bucket');
    \ClassIdentity\Repository::fromPiwigo()->execute(
        'DELETE FROM `' . $table . '` WHERE purpose IN (\'CLAIM\', \'FAMILY_INVITE\')',
    );
}

/** @return array{id:int,username:string,status:string} */
function ciTestCreateUnboundUser(string $username, string $password, string $status): array
{
    global $conf;

    if (!in_array($status, ['normal', 'admin'], true)) {
        ciTestFail('Unsupported unbound fixture status.');
    }
    if (get_userid($username)) {
        ciTestFail('Fixture username collision.');
    }

    $errors = [];
    $userId = \ClassIdentity\Access::withProvisioningPermit(
        static function () use ($username, $password, &$errors) {
            return register_user(
                $username,
                $password,
                $username . '@class-archive.invalid',
                false,
                $errors,
                false,
            );
        },
    );
    if ($userId === false || (int) $userId <= 0 || $errors !== []) {
        ciTestFail('Could not create an unbound Core fixture account.');
    }
    single_update(USER_INFOS_TABLE, ['status' => $status], ['user_id' => (int) $userId]);
    $rows = query2array(
        'SELECT u.id, u.username, u.password, ui.status FROM ' . USERS_TABLE . ' u '
        . 'JOIN ' . USER_INFOS_TABLE . ' ui ON ui.user_id = u.id WHERE u.id = ' . (int) $userId,
    );
    if (
        count($rows) !== 1
        || (string) $rows[0]['username'] !== $username
        || (string) $rows[0]['status'] !== $status
        || hash_equals((string) $rows[0]['password'], $password)
        || !isset($conf['password_hash'])
    ) {
        ciTestFail('Unbound Core fixture verification failed.');
    }

    return ['id' => (int) $userId, 'username' => $username, 'status' => $status];
}

function ciTestSetup(string $runId): never
{
    ciTestResetRateLimitBuckets();
    $names = ciTestNames($runId);
    $identity = \ClassIdentity\Repository::fromPiwigo()->table('identity');
    $existingIdentityCount = ciTestScalar(
        'SELECT COUNT(*) FROM `' . $identity . '` WHERE roster_code IN ('
        . ciTestQuoted($names['classmate']) . ',' . ciTestQuoted($names['teacher']) . ') '
        . 'OR roster_code LIKE ' . ciTestQuoted($names['csrf_prefix'] . '%'),
    );
    $existingUserCount = ciTestScalar(
        'SELECT COUNT(*) FROM ' . USERS_TABLE . ' WHERE username LIKE '
        . ciTestQuoted($names['user_prefix'] . '%'),
    );
    if ($existingIdentityCount !== 0 || $existingUserCount !== 0) {
        ciTestFail('Run namespace is not clean; run cleanup before retrying.');
    }

    $password = getenv('CI_TEST_UNBOUND_PASSWORD');
    if (!is_string($password) || strlen($password) < 24) {
        ciTestFail('A transient 24+ character unbound password is required.');
    }
    $normal = ciTestCreateUnboundUser($names['user_prefix'] . 'unbound_normal', $password, 'normal');
    $admin = ciTestCreateUnboundUser($names['user_prefix'] . 'unbound_admin', $password, 'admin');

    $livingRows = query2array(
        'SELECT DISTINCT i.id, i.path FROM ' . IMAGES_TABLE . ' i '
        . 'JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id = i.id '
        . 'JOIN ' . CATEGORIES_TABLE . " c ON c.id = ic.category_id "
        . "WHERE c.permalink = 'fixture-living-reunion' ORDER BY i.id LIMIT 1",
    );
    $heritageRows = query2array(
        'SELECT DISTINCT i.id FROM ' . IMAGES_TABLE . ' i '
        . 'JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id = i.id '
        . 'JOIN ' . CATEGORIES_TABLE . " c ON c.id = ic.category_id "
        . "WHERE c.permalink = 'fixture-heritage-graduation' ORDER BY i.id LIMIT 1",
    );
    if (count($livingRows) !== 1) {
        ciTestFail('Synthetic LIVING fixture photo is missing or ambiguous.');
    }
    if (count($heritageRows) !== 1 || (int) ($heritageRows[0]['id'] ?? 0) <= 0) {
        ciTestFail('Synthetic HERITAGE fixture photo is missing or ambiguous.');
    }
    $storagePath = preg_replace('#^\./#', '', (string) $livingRows[0]['path']);
    if (!is_string($storagePath)
        || preg_match('#\Aupload/[A-Za-z0-9_./-]+\z#D', $storagePath) !== 1
        || str_contains($storagePath, '..')
        || !is_file(CI_TEST_PIWIGO_ROOT . '/' . $storagePath)
    ) {
        ciTestFail('Synthetic LIVING source path is unsafe or missing.');
    }

    invalidate_user_cache();
    ciTestJson([
        'run_id' => $runId,
        'baseline_image_count' => ciTestScalar('SELECT COUNT(*) FROM ' . IMAGES_TABLE),
        'unbound_normal' => $normal,
        'unbound_admin' => $admin,
        'heritage_image_id' => (int) $heritageRows[0]['id'],
        'living_image_id' => (int) $livingRows[0]['id'],
        'living_original_path' => $storagePath,
    ]);
}

/** @return array<string,mixed>|null */
function ciTestIdentityState(string $rosterCode): ?array
{
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $identity = $repository->table('identity');
    $seat = $repository->table('seat');
    $account = $repository->table('account');
    $principal = $repository->table('principal');
    $rows = query2array(
        'SELECT id, roster_code, identity_type, real_name, state FROM `' . $identity . '` '
        . 'WHERE roster_code = ' . ciTestQuoted($rosterCode),
    );
    if ($rows === []) {
        return null;
    }
    if (count($rows) !== 1) {
        ciTestFail('Fixture Identity became ambiguous.');
    }
    $identityId = (int) $rows[0]['id'];
    $seats = query2array(
        'SELECT s.id, s.ordinal, s.seat_type, s.state, s.invite_generation, '
        . 'a.id AS account_id, a.requested_username, a.state AS account_state, '
        . 'p.id AS principal_id, p.piwigo_user_id, p.state AS principal_state, p.auth_epoch '
        . 'FROM `' . $seat . '` s '
        . 'LEFT JOIN `' . $account . '` a ON a.seat_id = s.id AND a.current_marker = 1 '
        . 'LEFT JOIN `' . $principal . '` p ON p.account_id = a.id '
        . 'WHERE s.identity_id = ' . $identityId . ' ORDER BY s.ordinal',
    );

    return [
        'id' => $identityId,
        'roster_code' => (string) $rows[0]['roster_code'],
        'identity_type' => (string) $rows[0]['identity_type'],
        'real_name' => (string) $rows[0]['real_name'],
        'state' => (string) $rows[0]['state'],
        'seats' => array_map(static function (array $row): array {
            foreach (['id', 'ordinal', 'account_id', 'principal_id', 'piwigo_user_id', 'auth_epoch', 'invite_generation'] as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            return $row;
        }, $seats),
    ];
}

function ciTestState(string $runId): never
{
    global $conf;

    $names = ciTestNames($runId);
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $principal = $repository->table('principal');
    $account = $repository->table('account');
    $seat = $repository->table('seat');
    $identity = $repository->table('identity');
    $token = $repository->table('token');
    $audit = $repository->table('audit_event');
    $operation = $repository->table('operation');
    $submission = $repository->table('submission');
    $archiveImage = $repository->table('archive_image');
    $photo = $repository->table('photo');
    $webmasterId = (int) ($conf['webmaster_id'] ?? 0);

    $adminRows = query2array(
        'SELECT p.id, p.principal_type, p.system_role, p.account_id, p.piwigo_user_id, p.state, '
        . '(SELECT COUNT(*) FROM `' . $account . '` a WHERE a.id = p.account_id) AS account_links, '
        . '(SELECT COUNT(*) FROM `' . $seat . '` s JOIN `' . $account . '` a2 ON a2.seat_id = s.id '
        . 'WHERE a2.id = p.account_id) AS seat_links '
        . 'FROM `' . $principal . '` p WHERE p.piwigo_user_id = ' . $webmasterId,
    );

    $identityIds = query2array(
        'SELECT id FROM `' . $identity . '` WHERE roster_code IN ('
        . ciTestQuoted($names['classmate']) . ',' . ciTestQuoted($names['teacher']) . ')',
        null,
        'id',
    );
    $ids = array_map('intval', $identityIds);
    $idList = $ids === [] ? '0' : implode(',', $ids);
    $tokenRows = query2array(
        'SELECT t.id, t.seat_id, t.purpose, t.generation, t.state, s.seat_type, s.identity_id '
        . 'FROM `' . $token . '` t JOIN `' . $seat . '` s ON s.id = t.seat_id '
        . 'WHERE s.identity_id IN (' . $idList . ') ORDER BY t.id',
    );
    $auditRows = query2array(
        'SELECT action, result, COUNT(*) AS event_count FROM `' . $audit . '` '
        . 'WHERE target_identity_id IN (' . $idList . ') GROUP BY action, result ORDER BY action, result',
    );
    $operationRows = query2array(
        'SELECT o.id, o.operation_type, o.state, o.core_user_id, o.last_error_code, '
        . 'o.seat_id, o.account_id, a.state AS account_state, s.state AS seat_state '
        . 'FROM `' . $operation . '` o '
        . 'LEFT JOIN `' . $account . '` a ON a.id = o.account_id '
        . 'LEFT JOIN `' . $seat . '` s ON s.id = o.seat_id '
        . 'WHERE o.identity_id IN (' . $idList . ') ORDER BY o.id',
    );
    $submissionRows = query2array(
        'SELECT s.id, s.state, s.original_filename, s.storage_ref, s.thumbnail_ref, '
        . 's.approved_image_id, s.mime_type, s.byte_size, s.width, s.height, '
        . 's.suggested_date, s.date_precision, s.review_reason, '
        . 'ai.era, ai.archive_date, ai.date_precision AS archive_date_precision '
        . 'FROM `' . $submission . '` s LEFT JOIN `' . $archiveImage . '` ai '
        . 'ON ai.source_submission_id = s.id WHERE s.identity_id IN (' . $idList . ') '
        . 'ORDER BY s.id',
    );
    $incidentUsername = $names['user_prefix'] . 'incident';
    $tombstoneRows = query2array(
        'SELECT u.id, u.username, '
        . '(SELECT COUNT(*) FROM ' . USER_GROUP_TABLE . ' ug WHERE ug.user_id = u.id) AS group_count, '
        . '(SELECT COUNT(*) FROM ' . USER_AUTH_KEYS_TABLE . ' ak WHERE ak.user_id = u.id AND ak.revoked_on IS NULL) AS active_auth_keys '
        . 'FROM ' . USERS_TABLE . ' u WHERE u.username = ' . ciTestQuoted($incidentUsername),
    );

    ciTestJson([
        'system_admin' => count($adminRows) === 1 ? $adminRows[0] : $adminRows,
        'classmate' => ciTestIdentityState($names['classmate']),
        'teacher' => ciTestIdentityState($names['teacher']),
        'tokens' => $tokenRows,
        'audit' => $auditRows,
        'operations' => $operationRows,
        'submissions' => $submissionRows,
        'incident_tombstone' => count($tombstoneRows) === 1 ? $tombstoneRows[0] : $tombstoneRows,
        'csrf_identity_count' => ciTestScalar(
            'SELECT COUNT(*) FROM `' . $identity . '` WHERE roster_code LIKE '
            . ciTestQuoted($names['csrf_prefix'] . '%'),
        ),
        'run_user_count' => ciTestScalar(
            'SELECT COUNT(*) FROM ' . USERS_TABLE . ' WHERE username LIKE '
            . ciTestQuoted($names['user_prefix'] . '%'),
        ),
        'image_count' => ciTestScalar('SELECT COUNT(*) FROM ' . IMAGES_TABLE),
    ]);
}

function ciTestExpireFamilyInvitation(string $runId): never
{
    $names = ciTestNames($runId);
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $identity = $repository->table('identity');
    $seat = $repository->table('seat');
    $token = $repository->table('token');
    $rows = query2array(
        'SELECT t.id, t.seat_id, t.generation FROM `' . $token . '` t '
        . 'JOIN `' . $seat . '` s ON s.id = t.seat_id '
        . 'JOIN `' . $identity . '` i ON i.id = s.identity_id '
        . 'WHERE i.roster_code = ' . ciTestQuoted($names['classmate'])
        . " AND s.seat_type = 'FAMILY' AND s.state = 'INVITED'"
        . " AND t.purpose = 'FAMILY_INVITE' AND t.state = 'ISSUED'"
        . ' ORDER BY t.id DESC LIMIT 2',
    );
    if (count($rows) !== 1) {
        ciTestFail('Expected exactly one issued Family Invitation to expire.');
    }
    $tokenId = (int) $rows[0]['id'];
    $affected = $repository->execute(
        'UPDATE `' . $token . '` SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND '
        . "WHERE id = ? AND state = 'ISSUED'",
        [$tokenId],
    );
    if ($affected !== 1) {
        ciTestFail('Could not expire the targeted Family Invitation.');
    }
    ciTestJson([
        'token_id' => $tokenId,
        'seat_id' => (int) $rows[0]['seat_id'],
        'generation' => (int) $rows[0]['generation'],
    ]);
}

function ciTestSeedProvisioningIncident(string $runId): never
{
    $names = ciTestNames($runId);
    $password = getenv('CI_TEST_INCIDENT_PASSWORD');
    if (!is_string($password) || strlen($password) < 24) {
        ciTestFail('A transient 24+ character incident password is required.');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $identity = $repository->table('identity');
    $seat = $repository->table('seat');
    $account = $repository->table('account');
    $operation = $repository->table('operation');
    $token = $repository->table('token');
    $rows = query2array(
        'SELECT t.id AS token_id, t.generation, s.id AS seat_id, s.identity_id '
        . 'FROM `' . $token . '` t JOIN `' . $seat . '` s ON s.id = t.seat_id '
        . 'JOIN `' . $identity . '` i ON i.id = s.identity_id '
        . 'WHERE i.roster_code = ' . ciTestQuoted($names['classmate'])
        . " AND s.seat_type = 'FAMILY' AND s.state = 'INVITED'"
        . " AND t.purpose = 'FAMILY_INVITE' AND t.state = 'ISSUED'"
        . ' ORDER BY t.id DESC LIMIT 1',
    );
    if (count($rows) !== 1) {
        ciTestFail('No issued Family Invitation is available for the incident fixture.');
    }
    $core = ciTestCreateUnboundUser($names['user_prefix'] . 'incident', $password, 'normal');
    \ClassIdentity\CoreAdapter::reconcileManagedGroups($core['id'], \ClassIdentity\Access::ROLE_FAMILY);
    $tokenId = (int) $rows[0]['token_id'];
    $seatId = (int) $rows[0]['seat_id'];
    $identityId = (int) $rows[0]['identity_id'];
    $generation = (int) $rows[0]['generation'];

    $result = $repository->transaction(static function (\ClassIdentity\Repository $tx) use (
        $account, $operation, $seat, $token, $tokenId, $seatId, $identityId, $generation, $core, $names,
    ): array {
        $locked = $tx->fetchOne(
            'SELECT t.id, t.state AS token_state, t.generation, s.state AS seat_state, s.invite_generation '
            . 'FROM `' . $token . '` t JOIN `' . $seat . '` s ON s.id = t.seat_id '
            . 'WHERE t.id = ? AND s.id = ? FOR UPDATE',
            [$tokenId, $seatId],
        );
        if ($locked === null
            || ($locked['token_state'] ?? null) !== 'ISSUED'
            || ($locked['seat_state'] ?? null) !== 'INVITED'
            || (int) ($locked['generation'] ?? 0) !== $generation
            || (int) ($locked['invite_generation'] ?? 0) !== $generation
        ) {
            ciTestFail('Incident fixture target drifted before reservation.');
        }
        $tx->execute(
            'INSERT INTO `' . $account . '` '
            . '(seat_id, requested_username, real_name, family_relationship, state, current_marker, core_created_at) '
            . "VALUES (?, ?, 'Synthetic Incident', 'GUARDIAN', 'COMPENSATION_REQUIRED', NULL, UTC_TIMESTAMP(6))",
            [$seatId, $names['user_prefix'] . 'incident'],
        );
        $accountId = $tx->lastInsertId();
        $tx->execute(
            'INSERT INTO `' . $operation . '` '
            . '(operation_type, idempotency_hash, identity_id, seat_id, account_id, state, core_user_id, '
            . 'safe_payload, attempt_count, last_error_code, updated_at) '
            . "VALUES ('FAMILY_ACCEPT', ?, ?, ?, ?, 'FAILED_MANUAL', ?, ?, 1, 'post_core_provisioning_failed', UTC_TIMESTAMP(6))",
            [random_bytes(32), $identityId, $seatId, $accountId, $core['id'], json_encode(['role_code' => 'FAMILY', 'generation' => $generation], JSON_THROW_ON_ERROR)],
        );
        $operationId = $tx->lastInsertId();
        if ($tx->execute('UPDATE `' . $account . '` SET provisioning_operation_id = ? WHERE id = ?', [$operationId, $accountId]) !== 1
            || $tx->execute("UPDATE `{$token}` SET state = 'RESERVED', reserved_by_operation_id = ?, reserved_at = UTC_TIMESTAMP(6) WHERE id = ? AND state = 'ISSUED'", [$operationId, $tokenId]) !== 1
            || $tx->execute("UPDATE `{$seat}` SET state = 'PROVISIONING', updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ? AND state = 'INVITED'", [$seatId]) !== 1
        ) {
            ciTestFail('Incident fixture state transition failed.');
        }
        return ['operation_id' => $operationId, 'account_id' => $accountId];
    });

    ciTestJson([
        'operation_id' => (int) $result['operation_id'],
        'account_id' => (int) $result['account_id'],
        'seat_id' => $seatId,
        'token_id' => $tokenId,
        'core_user_id' => $core['id'],
    ]);
}

function ciTestSeedStaleProvisioning(string $runId): never
{
    $names = ciTestNames($runId);
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $identity = $repository->table('identity');
    $seat = $repository->table('seat');
    $account = $repository->table('account');
    $operation = $repository->table('operation');
    $token = $repository->table('token');
    $rows = query2array(
        'SELECT t.id AS token_id, t.generation, s.id AS seat_id, s.identity_id '
        . 'FROM `' . $token . '` t JOIN `' . $seat . '` s ON s.id = t.seat_id '
        . 'JOIN `' . $identity . '` i ON i.id = s.identity_id '
        . 'WHERE i.roster_code = ' . ciTestQuoted($names['classmate'])
        . " AND s.seat_type = 'FAMILY' AND s.state = 'INVITED'"
        . " AND t.purpose = 'FAMILY_INVITE' AND t.state = 'ISSUED'"
        . ' ORDER BY t.id DESC LIMIT 1',
    );
    if (count($rows) !== 1) {
        ciTestFail('No issued Family Invitation is available for the stale fixture.');
    }
    $tokenId = (int) $rows[0]['token_id'];
    $seatId = (int) $rows[0]['seat_id'];
    $identityId = (int) $rows[0]['identity_id'];
    $generation = (int) $rows[0]['generation'];

    $result = $repository->transaction(static function (\ClassIdentity\Repository $tx) use (
        $account, $operation, $seat, $token, $tokenId, $seatId, $identityId, $generation, $names,
    ): array {
        $locked = $tx->fetchOne(
            'SELECT t.state AS token_state, s.state AS seat_state FROM `' . $token . '` t '
            . 'JOIN `' . $seat . '` s ON s.id = t.seat_id WHERE t.id = ? AND s.id = ? FOR UPDATE',
            [$tokenId, $seatId],
        );
        if ($locked === null || ($locked['token_state'] ?? null) !== 'ISSUED' || ($locked['seat_state'] ?? null) !== 'INVITED') {
            ciTestFail('Stale fixture target drifted before reservation.');
        }
        $tx->execute(
            'INSERT INTO `' . $account . '` (seat_id, requested_username, real_name, family_relationship, state, current_marker) '
            . "VALUES (?, ?, 'Synthetic Stale', 'GUARDIAN', 'PREPARED', NULL)",
            [$seatId, $names['user_prefix'] . 'stale'],
        );
        $accountId = $tx->lastInsertId();
        $tx->execute(
            'INSERT INTO `' . $operation . '` '
            . '(operation_type, idempotency_hash, identity_id, seat_id, account_id, state, safe_payload, updated_at) '
            . "VALUES ('FAMILY_ACCEPT', ?, ?, ?, ?, 'PREPARED', ?, UTC_TIMESTAMP(6) - INTERVAL 2 HOUR)",
            [random_bytes(32), $identityId, $seatId, $accountId, json_encode(['role_code' => 'FAMILY', 'generation' => $generation], JSON_THROW_ON_ERROR)],
        );
        $operationId = $tx->lastInsertId();
        if ($tx->execute('UPDATE `' . $account . '` SET provisioning_operation_id = ?, updated_at = UTC_TIMESTAMP(6) - INTERVAL 2 HOUR WHERE id = ?', [$operationId, $accountId]) !== 1
            || $tx->execute("UPDATE `{$token}` SET state = 'RESERVED', reserved_by_operation_id = ?, reserved_at = UTC_TIMESTAMP(6) - INTERVAL 2 HOUR WHERE id = ? AND state = 'ISSUED'", [$operationId, $tokenId]) !== 1
            || $tx->execute("UPDATE `{$seat}` SET state = 'PROVISIONING', updated_at = UTC_TIMESTAMP(6) - INTERVAL 2 HOUR, lock_version = lock_version + 1 WHERE id = ? AND state = 'INVITED'", [$seatId]) !== 1
        ) {
            ciTestFail('Stale fixture state transition failed.');
        }
        return ['operation_id' => $operationId, 'account_id' => $accountId];
    });
    ciTestJson([
        'operation_id' => (int) $result['operation_id'],
        'account_id' => (int) $result['account_id'],
        'seat_id' => $seatId,
        'token_id' => $tokenId,
    ]);
}

/** @return list<string> */
function ciTestDecodeSecrets(): array
{
    $encoded = getenv('CI_TEST_SECRETS_B64');
    if (!is_string($encoded) || $encoded === '') {
        ciTestFail('Secret scan input is required.');
    }
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded)) {
        ciTestFail('Secret scan input is invalid.');
    }
    $values = json_decode($decoded, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($values)) {
        ciTestFail('Secret scan payload must be an array.');
    }
    $secrets = [];
    foreach ($values as $value) {
        if (!is_string($value) || strlen($value) < 12 || strlen($value) > 2048) {
            ciTestFail('Secret scan value has an unsafe length.');
        }
        $secrets[] = $value;
    }
    if ($secrets === [] || count(array_unique($secrets)) !== count($secrets)) {
        ciTestFail('Secret scan values are empty or duplicated.');
    }
    return $secrets;
}

function ciTestAssertNoSecrets(): never
{
    global $conf;

    $secrets = ciTestDecodeSecrets();
    $database = (string) ($conf['db_base'] ?? '');
    if ($database === '') {
        $databaseRows = query2array('SELECT DATABASE() AS db_name');
        $database = (string) ($databaseRows[0]['db_name'] ?? '');
    }
    if ($database === '') {
        ciTestFail('Cannot resolve the active database name.');
    }

    $columns = query2array(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS "
        . 'WHERE TABLE_SCHEMA = ' . ciTestQuoted($database) . " AND DATA_TYPE IN "
        . "('char','varchar','tinytext','text','mediumtext','longtext','binary','varbinary','tinyblob','blob','mediumblob','longblob','json') "
        . 'ORDER BY TABLE_NAME, ORDINAL_POSITION',
    );
    $matches = 0;
    foreach ($columns as $column) {
        $tableName = (string) ($column['TABLE_NAME'] ?? '');
        $columnName = (string) ($column['COLUMN_NAME'] ?? '');
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $tableName) !== 1
            || preg_match('/\A[A-Za-z0-9_]+\z/D', $columnName) !== 1
        ) {
            ciTestFail('Unsafe information_schema identifier.');
        }
        foreach ($secrets as $secret) {
            $matches += ciTestScalar(
                'SELECT COUNT(*) FROM `' . $tableName . '` WHERE INSTR(CAST(`' . $columnName
                . '` AS CHAR CHARACTER SET utf8mb4), ' . ciTestQuoted($secret) . ') > 0',
            );
        }
    }

    $filesScanned = 0;
    $fileMatches = 0;
    foreach (['/var/lib/php/session', '/var/lib/php/sessions', CI_TEST_PIWIGO_ROOT . '/_data/logs'] as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getSize() > 16 * 1024 * 1024) {
                continue;
            }
            ++$filesScanned;
            $contents = file_get_contents($entry->getPathname());
            if (!is_string($contents)) {
                ciTestFail('Could not inspect a session/log file.');
            }
            foreach ($secrets as $secret) {
                if (str_contains($contents, $secret)) {
                    ++$fileMatches;
                }
            }
        }
    }

    if ($matches !== 0 || $fileMatches !== 0) {
        ciTestFail('A raw transient secret was found in persistent database/session/log storage.');
    }
    ciTestJson([
        'database_secret_matches' => 0,
        'session_log_secret_matches' => 0,
        'session_log_files_scanned' => $filesScanned,
    ]);
}

/** @param list<int> $ids
 *  @param list<int> $boundFixtureIds
 */
function ciTestDeleteCoreUsers(array $ids, string $prefix, array $boundFixtureIds): void
{
    global $conf;

    $protected = array_filter([
        (int) ($conf['guest_id'] ?? 0),
        (int) ($conf['webmaster_id'] ?? 0),
    ]);
    foreach (array_values(array_unique($ids)) as $userId) {
        if ($userId <= 0 || in_array($userId, $protected, true)) {
            ciTestFail('Cleanup resolved a protected Core user.');
        }
        $rows = query2array('SELECT username FROM ' . USERS_TABLE . ' WHERE id = ' . $userId);
        if ($rows === []) {
            continue;
        }
        $isBoundFixture = in_array($userId, $boundFixtureIds, true);
        $hasRunPrefix = count($rows) === 1 && str_starts_with((string) $rows[0]['username'], $prefix);
        if (count($rows) !== 1 || (!$isBoundFixture && !$hasRunPrefix)) {
            ciTestFail('Cleanup refused a Core user outside the run namespace.');
        }
        delete_user($userId);
    }
}

/** @param list<int> $identityIds */
function ciTestCleanupSubmissionMedia(array $identityIds, string $submission, string $archiveImage): void
{
    if ($identityIds === []) {
        return;
    }
    $idList = implode(',', array_map('intval', $identityIds));
    $rows = query2array(
        'SELECT id, storage_ref, thumbnail_ref, approved_image_id FROM `' . $submission . '` '
        . 'WHERE identity_id IN (' . $idList . ') ORDER BY id',
    );
    foreach ($rows as $row) {
        $submissionId = (int) ($row['id'] ?? 0);
        foreach (['storage_ref', 'thumbnail_ref'] as $field) {
            $ref = (string) ($row[$field] ?? '');
            if (preg_match('#\Aclass_identity_pending/[a-f0-9]{48}\.(?:jpg|jpeg|png|webp)\z#D', $ref) !== 1) {
                ciTestFail('Cleanup refused an unsafe submission storage reference.');
            }
            $path = CI_TEST_PIWIGO_ROOT . '/_data/' . $ref;
            if (is_link($path) || (file_exists($path) && (!is_file($path) || !unlink($path)))) {
                ciTestFail('Cleanup could not safely remove a submission file.');
            }
            if (file_exists($path) || is_link($path)) {
                ciTestFail('Submission file survived cleanup.');
            }
        }

        $approvedImageId = (int) ($row['approved_image_id'] ?? 0);
        if ($approvedImageId <= 0) {
            continue;
        }
        $archiveRows = query2array(
            'SELECT piwigo_image_id FROM `' . $archiveImage . '` '
            . 'WHERE source_submission_id = ' . $submissionId,
        );
        if (count($archiveRows) !== 1 || (int) $archiveRows[0]['piwigo_image_id'] !== $approvedImageId) {
            ciTestFail('Cleanup refused an ambiguous approved submission link.');
        }
        $imageRows = query2array('SELECT id, path FROM ' . IMAGES_TABLE . ' WHERE id = ' . $approvedImageId);
        if (count($imageRows) !== 1) {
            ciTestFail('Approved submission image is missing or ambiguous.');
        }
        $relative = preg_replace('#^\./#', '', (string) $imageRows[0]['path']);
        if (!is_string($relative) || preg_match('#\A(?:upload|galleries)/[A-Za-z0-9_. /-]+\z#D', $relative) !== 1 || str_contains($relative, '..')) {
            ciTestFail('Approved submission image path is unsafe.');
        }
        $sourcePath = CI_TEST_PIWIGO_ROOT . '/' . $relative;
        if (delete_elements([$approvedImageId], true) !== 1) {
            ciTestFail('Approved submission image cleanup failed.');
        }
        if (query2array('SELECT id FROM ' . IMAGES_TABLE . ' WHERE id = ' . $approvedImageId) !== []
            || is_file($sourcePath) || is_link($sourcePath)
        ) {
            ciTestFail('Approved submission image survived cleanup.');
        }
    }
}

function ciTestCleanup(string $runId): never
{
    $names = ciTestNames($runId);
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $identity = $repository->table('identity');
    $seat = $repository->table('seat');
    $account = $repository->table('account');
    $principal = $repository->table('principal');
    $operation = $repository->table('operation');
    $token = $repository->table('token');
    $audit = $repository->table('audit_event');
    $submission = $repository->table('submission');
    $archiveImage = $repository->table('archive_image');
    $photo = $repository->table('photo');

    $identityRows = query2array(
        'SELECT id, roster_code FROM `' . $identity . '` WHERE roster_code IN ('
        . ciTestQuoted($names['classmate']) . ',' . ciTestQuoted($names['teacher']) . ') '
        . 'OR roster_code LIKE ' . ciTestQuoted($names['csrf_prefix'] . '%'),
    );
    foreach ($identityRows as $row) {
        $code = (string) ($row['roster_code'] ?? '');
        if (!in_array($code, [$names['classmate'], $names['teacher']], true)
            && !str_starts_with($code, $names['csrf_prefix'])
        ) {
            ciTestFail('Cleanup refused an Identity outside the run namespace.');
        }
    }
    $identityIds = array_map(static fn(array $row): int => (int) $row['id'], $identityRows);
    $idList = $identityIds === [] ? '0' : implode(',', $identityIds);
    $accountRows = query2array(
        'SELECT a.id, a.requested_username FROM `' . $account . '` a JOIN `' . $seat . '` s ON s.id = a.seat_id '
        . 'WHERE s.identity_id IN (' . $idList . ')',
    );
    $accountIds = array_map(static fn(array $row): int => (int) $row['id'], $accountRows);
    $accountList = $accountIds === [] ? '0' : implode(',', $accountIds);
    $principalRows = query2array(
        'SELECT id, piwigo_user_id FROM `' . $principal . '` WHERE account_id IN (' . $accountList . ')',
    );
    $principalIds = array_map(static fn(array $row): int => (int) $row['id'], $principalRows);
    $principalList = $principalIds === [] ? '0' : implode(',', $principalIds);
    $safeFixtureCoreIds = array_map(static fn(array $row): int => (int) $row['piwigo_user_id'], $principalRows);
    foreach ($accountRows as $row) {
        $username = (string) ($row['requested_username'] ?? '');
        if ($username === '') {
            continue;
        }
        $coreRows = query2array(
            'SELECT id FROM ' . USERS_TABLE . ' WHERE username = ' . ciTestQuoted($username),
        );
        if (count($coreRows) > 1) {
            ciTestFail('Cleanup found an ambiguous Account username.');
        }
        if ($coreRows !== []) {
            $safeFixtureCoreIds[] = (int) $coreRows[0]['id'];
        }
    }
    foreach (query2array(
        'SELECT DISTINCT core_user_id FROM `' . $operation . '` WHERE identity_id IN (' . $idList . ') '
        . 'AND core_user_id IS NOT NULL',
    ) as $row) {
        $safeFixtureCoreIds[] = (int) $row['core_user_id'];
    }
    $safeFixtureCoreIds = array_values(array_unique(array_filter($safeFixtureCoreIds)));
    $coreUserIds = $safeFixtureCoreIds;
    foreach (query2array(
        'SELECT id FROM ' . USERS_TABLE . ' WHERE username LIKE ' . ciTestQuoted($names['user_prefix'] . '%'),
    ) as $row) {
        $coreUserIds[] = (int) $row['id'];
    }

    ciTestCleanupSubmissionMedia($identityIds, $submission, $archiveImage);

    $repository->transaction(static function (\ClassIdentity\Repository $tx) use (
        $identity, $seat, $account, $principal, $operation, $token, $audit, $submission, $archiveImage, $photo,
        $idList, $accountList, $principalList,
    ): void {
        $tx->execute('DELETE FROM `' . $photo . '` WHERE source_submission_id IN (SELECT id FROM `' . $submission . '` WHERE identity_id IN (' . $idList . '))');
        $tx->execute('DELETE FROM `' . $archiveImage . '` WHERE source_submission_id IN (SELECT id FROM `' . $submission . '` WHERE identity_id IN (' . $idList . '))');
        $tx->execute('DELETE FROM `' . $submission . '` WHERE identity_id IN (' . $idList . ')');
        $tx->execute(
        'DELETE FROM `' . $audit . '` WHERE target_identity_id IN (' . $idList . ') '
            . 'OR target_seat_id IN (SELECT id FROM `' . $seat . '` WHERE identity_id IN (' . $idList . ')) '
            . 'OR target_account_id IN (' . $accountList . ') OR target_principal_id IN (' . $principalList . ') '
            . 'OR actor_principal_id IN (' . $principalList . ')',
        );
        $tx->execute(
            'UPDATE `' . $account . '` SET provisioning_operation_id = NULL WHERE id IN (' . $accountList . ')',
        );
        $tx->execute(
            'DELETE FROM `' . $token . '` WHERE seat_id IN (SELECT id FROM `' . $seat . '` WHERE identity_id IN ('
            . $idList . ')) OR principal_id IN (' . $principalList . ') OR issued_by_principal_id IN (' . $principalList . ')',
        );
        $tx->execute(
            'DELETE FROM `' . $operation . '` WHERE identity_id IN (' . $idList . ') '
            . 'OR account_id IN (' . $accountList . ') OR principal_id IN (' . $principalList . ')',
        );
        $tx->execute('DELETE FROM `' . $principal . '` WHERE id IN (' . $principalList . ')');
        $tx->execute('DELETE FROM `' . $account . '` WHERE id IN (' . $accountList . ')');
        $tx->execute('DELETE FROM `' . $seat . '` WHERE identity_id IN (' . $idList . ')');
        $tx->execute('DELETE FROM `' . $identity . '` WHERE id IN (' . $idList . ')');
    });

    ciTestDeleteCoreUsers($coreUserIds, $names['user_prefix'], $safeFixtureCoreIds);
    ciTestResetRateLimitBuckets();
    invalidate_user_cache();

    $remainingIdentities = ciTestScalar(
        'SELECT COUNT(*) FROM `' . $identity . '` WHERE roster_code IN ('
        . ciTestQuoted($names['classmate']) . ',' . ciTestQuoted($names['teacher']) . ') '
        . 'OR roster_code LIKE ' . ciTestQuoted($names['csrf_prefix'] . '%'),
    );
    $remainingUsers = ciTestScalar(
        'SELECT COUNT(*) FROM ' . USERS_TABLE . ' WHERE username LIKE '
        . ciTestQuoted($names['user_prefix'] . '%'),
    );
    $remainingSubmissions = ciTestScalar(
        'SELECT COUNT(*) FROM `' . $submission . '` WHERE identity_id IN (' . $idList . ')',
    );
    $remainingArchiveRows = ciTestScalar(
        'SELECT COUNT(*) FROM `' . $archiveImage . '` WHERE source_submission_id NOT IN (SELECT id FROM `' . $submission . '`)',
    );
    $remainingPhotoRows = ciTestScalar(
        'SELECT COUNT(*) FROM `' . $photo . '` WHERE source_submission_id NOT IN (SELECT id FROM `' . $submission . '`) AND source_submission_id IS NOT NULL',
    );
    $expectedImageCount = getenv('CI_TEST_BASELINE_IMAGE_COUNT');
    $actualImageCount = ciTestScalar('SELECT COUNT(*) FROM ' . IMAGES_TABLE);
    if ($remainingIdentities !== 0 || $remainingUsers !== 0 || $remainingSubmissions !== 0 || $remainingArchiveRows !== 0 || $remainingPhotoRows !== 0) {
        ciTestFail('Fixture cleanup was incomplete.');
    }
    if (is_string($expectedImageCount)
        && preg_match('/\A[0-9]+\z/D', $expectedImageCount) === 1
        && $actualImageCount !== (int) $expectedImageCount
    ) {
        ciTestFail('Fixture cleanup detected a changed image count.');
    }
    ciTestJson([
        'identities_remaining' => 0,
        'users_remaining' => 0,
        'image_count' => $actualImageCount,
    ]);
}

$command = $_SERVER['argv'][1] ?? '';
$runId = $_SERVER['argv'][2] ?? '';
if (!is_string($runId) || preg_match('/\A[a-f0-9]{12}\z/D', $runId) !== 1) {
    ciTestFail('Run id must be exactly 12 lowercase hexadecimal characters.');
}

ciTestBoot();
// Piwigo bootstrap must run in global scope. Requiring common.inc.php inside a
// helper function would strand $conf/$user/$page in that function scope and
// produce a misleading, partially initialized runtime.
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();
require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
if (!defined('PHPWG_VERSION') || PHPWG_VERSION !== '16.4.0') {
    ciTestFail('Locked Piwigo 16.4.0 runtime required.');
}
if (!class_exists(\ClassIdentity\Access::class)
    || !class_exists(\ClassIdentity\Repository::class)
    || !\ClassIdentity\Access::isEnforcementEnabled()
) {
    ciTestFail('Active fail-closed ClassIdentity runtime required.');
}
try {
    match ($command) {
        'setup' => ciTestSetup($runId),
        'state' => ciTestState($runId),
        'expire-family' => ciTestExpireFamilyInvitation($runId),
        'seed-provisioning-incident' => ciTestSeedProvisioningIncident($runId),
        'seed-stale-provisioning' => ciTestSeedStaleProvisioning($runId),
        'assert-no-secrets' => ciTestAssertNoSecrets(),
        'cleanup' => ciTestCleanup($runId),
        default => ciTestFail('Unknown fixture command.'),
    };
} catch (Throwable $error) {
    // Never echo exception messages from database/framework operations: they
    // may contain query data. The bounded class name is enough for diagnostics.
    ciTestFail('Fixture command failed [' . get_class($error) . '].');
}
