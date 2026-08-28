<?php

declare(strict_types=1);

const CI_ANON_PIWIGO_ROOT = '/var/www/html/piwigo';

function ciAnonFail(string $message): never
{
    fwrite(STDERR, "ANONYMOUS_PRESENTER_FIXTURE_ERROR: {$message}\n");
    exit(1);
}

function ciAnonStatePath(string $runId): string
{
    return '/tmp/class-archive-anonymous-presenter-' . $runId . '.json';
}

/** @return array<string, mixed> */
function ciAnonLoadState(string $runId): array
{
    $path = ciAnonStatePath($runId);
    if (!is_file($path) || is_link($path)) {
        ciAnonFail('fixture state unavailable');
    }
    $json = file_get_contents($path);
    $state = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($state) || ($state['run_id'] ?? null) !== $runId) {
        ciAnonFail('fixture state invalid');
    }
    return $state;
}

function ciAnonWriteState(string $runId, array $state): void
{
    $path = ciAnonStatePath($runId);
    if (file_exists($path) || is_link($path)) {
        ciAnonFail('fixture state already exists');
    }
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($path, $json, LOCK_EX) === false || !chmod($path, 0600)) {
        ciAnonFail('fixture state cannot be written');
    }
}

function ciAnonReplaceState(string $runId, array $state): void
{
    $path = ciAnonStatePath($runId);
    if (!is_file($path) || is_link($path)) {
        ciAnonFail('fixture state unavailable for replacement');
    }
    $next = $path . '.next';
    if (file_exists($next) || is_link($next)) {
        ciAnonFail('fixture replacement state already exists');
    }
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($next, $json, LOCK_EX) === false
        || !chmod($next, 0600)
        || !rename($next, $path)
    ) {
        if (is_file($next) && !is_link($next)) {
            unlink($next);
        }
        ciAnonFail('fixture state cannot be replaced');
    }
}

function ciAnonDeleteState(string $runId): void
{
    $path = ciAnonStatePath($runId);
    if (is_link($path)) {
        ciAnonFail('refusing symbolic-link fixture state');
    }
    if (is_file($path) && !unlink($path)) {
        ciAnonFail('fixture state cannot be removed');
    }
}

/** @return array<string, mixed> */
function ciAnonOne(string $sql): array
{
    $rows = query2array($sql);
    if (count($rows) !== 1) {
        ciAnonFail('fixture lookup was missing or ambiguous');
    }
    return $rows[0];
}

/** @return list<string> */
function ciAnonMarkerContents(string $marker): array
{
    return [
        $marker . '_PHOTO_1',
        $marker . '_PHOTO_2',
        $marker . '_REAL_HTTP',
    ];
}

function ciAnonQuotedMarkerContents(string $marker): string
{
    return implode(',', array_map(
        static fn(string $content): string => "'" . pwg_db_real_escape_string($content) . "'",
        ciAnonMarkerContents($marker),
    ));
}

function ciAnonAuditReason(string $runId): string
{
    return 'CITEST anonymous presenter '
        . substr($runId, 0, 6) . ' ' . substr($runId, 6);
}

function ciAnonAuditTable(): string
{
    $table = (string) $GLOBALS['prefixeTable'] . 'class_identity_audit_event';
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $table) !== 1) {
        ciAnonFail('unsafe audit table identifier');
    }
    return $table;
}

/** @return list<array<string, mixed>> */
function ciAnonAuditRowsByReason(string $reason): array
{
    $table = ciAnonAuditTable();
    return query2array(
        'SELECT id, HEX(request_id) AS request_id, actor_principal_id, actor_user_id, '
        . 'actor_kind, action, target_type, target_id, target_identity_id, target_seat_id, '
        . 'target_account_id, target_principal_id, old_value, new_value, reason, '
        . 'HEX(source_ip_hash) AS source_ip_hash, result, error_code '
        . "FROM `{$table}` WHERE reason = '" . pwg_db_real_escape_string($reason) . "' ORDER BY id"
    );
}

/** @param array<string, mixed> $state @param array<string, mixed> $row */
function ciAnonAuditRowIsOwned(array $state, array $row, string $reason): bool
{
    $photoIds = $state['photo_ids'] ?? null;
    $anonymous = $state['anonymous'] ?? null;
    $systemAdmin = $state['system_admin'] ?? null;
    if (!is_array($photoIds) || !isset($photoIds[0]) || !is_array($anonymous) || !is_array($systemAdmin)) {
        return false;
    }
    $boundaryIds = [
        (int) $photoIds[0],
        (int) ($systemAdmin['principal_id'] ?? 0),
        (int) ($systemAdmin['piwigo_user_id'] ?? 0),
        (int) ($anonymous['identity_id'] ?? 0),
        (int) ($anonymous['seat_id'] ?? 0),
        (int) ($anonymous['account_id'] ?? 0),
        (int) ($anonymous['principal_id'] ?? 0),
    ];
    if (min($boundaryIds) <= 0) {
        return false;
    }
    $newValue = is_string($row['new_value'] ?? null)
        ? json_decode((string) $row['new_value'], true)
        : null;
    if (!is_array($newValue) || array_keys($newValue) !== ['seat_type', 'result']) {
        return false;
    }

    return (int) ($row['id'] ?? 0) > 0
        && preg_match('/\A[A-F0-9]{32}\z/D', (string) ($row['request_id'] ?? '')) === 1
        && (int) ($row['actor_principal_id'] ?? 0) === (int) ($systemAdmin['principal_id'] ?? 0)
        && (int) ($row['actor_user_id'] ?? 0) === (int) ($systemAdmin['piwigo_user_id'] ?? 0)
        && (string) ($row['actor_kind'] ?? '') === 'SYSTEM_ADMIN'
        && (string) ($row['action'] ?? '') === 'ANONYMOUS_RESOLVE'
        && (string) ($row['target_type'] ?? '') === 'ANONYMOUS_ALIAS'
        && (string) ($row['target_id'] ?? '') === 'PHOTO:' . (int) $photoIds[0]
        && (int) ($row['target_identity_id'] ?? 0) === (int) ($anonymous['identity_id'] ?? 0)
        && (int) ($row['target_seat_id'] ?? 0) === (int) ($anonymous['seat_id'] ?? 0)
        && (int) ($row['target_account_id'] ?? 0) === (int) ($anonymous['account_id'] ?? 0)
        && (int) ($row['target_principal_id'] ?? 0) === (int) ($anonymous['principal_id'] ?? 0)
        && ($row['old_value'] ?? null) === null
        && $newValue === ['seat_type' => 'ANONYMOUS', 'result' => 'RESOLVED']
        && (string) ($row['reason'] ?? '') === $reason
        && ($row['source_ip_hash'] ?? null) === null
        && (string) ($row['result'] ?? '') === 'SUCCESS'
        && ($row['error_code'] ?? null) === null;
}

/** @return list<int> */
function ciAnonOwnedAuditIds(array $state, string $reason): array
{
    $rows = ciAnonAuditRowsByReason($reason);
    if (count($rows) > 1) {
        ciAnonFail('test audit reason is ambiguous');
    }
    $ids = [];
    foreach ($rows as $row) {
        if (!ciAnonAuditRowIsOwned($state, $row, $reason)) {
            ciAnonFail('test audit row does not match the exact fixture boundary');
        }
        $ids[] = (int) $row['id'];
    }
    return $ids;
}

/** @return array<string, mixed> */
function ciAnonSetup(string $runId): array
{
    global $prefixeTable, $conf;

    $principal = $prefixeTable . 'class_identity_principal';
    $account = $prefixeTable . 'class_identity_account';
    $seat = $prefixeTable . 'class_identity_seat';
    $identity = $prefixeTable . 'class_identity_identity';
    $usernameField = (string) ($conf['user_fields']['username'] ?? 'username');
    $idField = (string) ($conf['user_fields']['id'] ?? 'id');
    foreach ([$principal, $account, $seat, $identity, $usernameField, $idField] as $identifier) {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier)) {
            ciAnonFail('unsafe table or field identifier');
        }
    }

    $anonymous = ciAnonOne(
        'SELECT p.id AS principal_id, p.piwigo_user_id, a.id AS account_id, '
        . 's.id AS seat_id, i.id AS identity_id, i.roster_code, i.real_name, '
        . 'u.`' . $usernameField . '` AS core_username '
        . 'FROM `' . $principal . '` p '
        . 'INNER JOIN `' . $account . '` a ON a.id = p.account_id '
        . 'INNER JOIN `' . $seat . '` s ON s.id = a.seat_id '
        . 'INNER JOIN `' . $identity . '` i ON i.id = s.identity_id '
        . 'INNER JOIN ' . USERS_TABLE . ' u ON u.`' . $idField . '` = p.piwigo_user_id '
        . "WHERE s.seat_type = 'ANONYMOUS' AND a.requested_username = 'fixture-anonymous'"
    );
    $systemAdmin = ciAnonOne(
        'SELECT p.id AS principal_id, p.piwigo_user_id '
        . 'FROM `' . $principal . '` p '
        . "WHERE p.principal_type = 'SYSTEM_ACCOUNT' AND p.system_role = 'SYSTEM_ADMIN' "
        . "AND p.state = 'ACTIVE'"
    );
    $heritage = ciAnonOne(
        "SELECT id FROM " . CATEGORIES_TABLE . " WHERE permalink = 'class-archive-heritage'"
    );
    $heritageId = (int) $heritage['id'];
    $photos = query2array(
        'SELECT DISTINCT i.id, i.file FROM ' . IMAGES_TABLE . ' i '
        . 'INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id = i.id '
        . 'INNER JOIN ' . CATEGORIES_TABLE . ' c ON c.id = ic.category_id '
        . "WHERE c.uppercats REGEXP '(^|,)" . $heritageId . "(,|$)' ORDER BY i.id LIMIT 2"
    );
    if (count($photos) !== 2) {
        ciAnonFail('two synthetic Heritage photos are required');
    }

    $categoryState = [];
    $pictureUrls = [];
    foreach ($photos as $index => $photo) {
        $photoId = (int) $photo['id'];
        $category = ciAnonOne(
            'SELECT c.id, c.name, c.permalink, c.uppercats, c.commentable '
            . 'FROM ' . CATEGORIES_TABLE . ' c '
            . 'INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.category_id = c.id '
            . 'WHERE ic.image_id = ' . $photoId . ' ORDER BY c.id LIMIT 1'
        );
        $categoryId = (int) $category['id'];
        if (!isset($categoryState[$categoryId])) {
            $categoryState[$categoryId] = (string) $category['commentable'];
        }
        $pictureUrls[$index] = html_entity_decode(
            make_picture_url([
                'image_id' => $photoId,
                'image_file' => (string) $photo['file'],
                'category' => $category,
            ]),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
    }

    $marker = 'CITEST_ANON_PRESENTER_' . strtoupper($runId);
    $testConfig = [
        'activate_comments' => true,
        'comments_validation' => false,
        'email_admin_on_comment' => false,
        'email_admin_on_comment_validation' => false,
    ];
    $configState = [];
    foreach ($testConfig as $param => $_value) {
        $rows = query2array(
            "SELECT value FROM " . CONFIG_TABLE
            . " WHERE param = '" . pwg_db_real_escape_string($param) . "'"
        );
        if (count($rows) > 1) {
            ciAnonFail('test configuration is ambiguous');
        }
        $configState[$param] = [
            'exists' => count($rows) === 1,
            'value' => count($rows) === 1 ? (string) $rows[0]['value'] : null,
        ];
    }

    $state = [
        'run_id' => $runId,
        'marker' => $marker,
        'photo_ids' => array_map(static fn(array $photo): int => (int) $photo['id'], $photos),
        'picture_urls' => $pictureUrls,
        'comment_ids' => [],
        'audit_event_ids' => [],
        'category_state' => $categoryState,
        'config_state' => $configState,
        'anonymous' => [
            'core_username' => (string) $anonymous['core_username'],
            'piwigo_user_id' => (int) $anonymous['piwigo_user_id'],
            'principal_id' => (int) $anonymous['principal_id'],
            'account_id' => (int) $anonymous['account_id'],
            'seat_id' => (int) $anonymous['seat_id'],
            'identity_id' => (int) $anonymous['identity_id'],
            'roster_code' => (string) $anonymous['roster_code'],
            'real_name' => (string) $anonymous['real_name'],
        ],
        'system_admin' => [
            'piwigo_user_id' => (int) $systemAdmin['piwigo_user_id'],
            'principal_id' => (int) $systemAdmin['principal_id'],
        ],
    ];
    ciAnonWriteState($runId, $state);

    try {
        foreach ($testConfig as $param => $value) {
            conf_update_param($param, $value, true);
        }
        foreach ($categoryState as $categoryId => $_commentable) {
            single_update(CATEGORIES_TABLE, ['commentable' => 'true'], ['id' => (int) $categoryId]);
        }

        $commentIds = [];
        foreach ($photos as $index => $photo) {
            single_insert(COMMENTS_TABLE, [
                'author' => (string) $anonymous['core_username'],
                'author_id' => (int) $anonymous['piwigo_user_id'],
                'anonymous_id' => '127.0.0.1',
                'content' => $marker . '_PHOTO_' . ($index + 1),
                'date' => '2020-01-0' . ($index + 1) . ' 00:00:00',
                'validated' => 'true',
                'validation_date' => date('Y-m-d H:i:s'),
                'image_id' => (int) $photo['id'],
                'website_url' => 'https://identity-leak.invalid/' . $runId,
                'email' => 'anonymous-leak-' . $runId . '@invalid.example',
            ]);
            $commentIds[] = (int) pwg_db_insert_id(COMMENTS_TABLE);
        }
        invalidate_user_cache_nb_comments();
        $state['comment_ids'] = $commentIds;
        ciAnonReplaceState($runId, $state);
    } catch (Throwable $error) {
        try {
            ciAnonCleanup($runId);
        } catch (Throwable) {
            // The original bounded fixture error remains the useful signal.
        }
        throw $error;
    }

    $attested = trigger_change('class_identity_anonymous_presenter_ready', false) === true;
    return $state + ['attested' => $attested];
}

/** @return array<string, mixed> */
function ciAnonSetGate(string $runId, string $requestedState): array
{
    ciAnonLoadState($runId);
    if (!in_array($requestedState, ['on', 'off'], true)) {
        ciAnonFail('gate state must be on or off');
    }
    if ($requestedState === 'on'
        && trigger_change('class_identity_anonymous_presenter_ready', false) !== true
    ) {
        ciAnonFail('presenter did not attest readiness');
    }
    $enabled = $requestedState === 'on';
    conf_update_param('class_identity_anon_presenter_ready', $enabled, true);
    $row = ciAnonOne(
        "SELECT value FROM " . CONFIG_TABLE
        . " WHERE param = 'class_identity_anon_presenter_ready'"
    );
    $stored = (string) $row['value'];
    $verified = $enabled ? in_array($stored, ['true', '1'], true) : in_array($stored, ['false', '0'], true);
    if (!$verified) {
        ciAnonFail('gate state did not converge');
    }
    return ['gate' => $requestedState, 'verified' => true];
}

/** @return array<string, mixed> */
function ciAnonAssertPosted(string $runId): array
{
    $state = ciAnonLoadState($runId);
    $content = (string) $state['marker'] . '_REAL_HTTP';
    $rows = query2array(
        'SELECT id, author, author_id, image_id, email, website_url FROM ' . COMMENTS_TABLE
        . " WHERE content = '" . pwg_db_real_escape_string($content) . "'"
    );
    if (count($rows) !== 1) {
        ciAnonFail('real HTTP anonymous comment is missing or ambiguous');
    }
    $row = $rows[0];
    $safe = (string) ($row['author'] ?? '') === '匿名账号'
        && (int) ($row['author_id'] ?? 0) === (int) $state['anonymous']['piwigo_user_id']
        && (int) ($row['image_id'] ?? 0) === (int) $state['photo_ids'][0]
        && empty($row['email'])
        && empty($row['website_url']);
    return ['posted' => true, 'stored_author_redacted' => $safe, 'comment_id' => (int) $row['id']];
}

/** @return array<string, mixed> */
function ciAnonResolve(string $runId, string $alias): array
{
    $state = ciAnonLoadState($runId);
    require_once PHPWG_PLUGINS_PATH . 'ClassIdentity/src/AnonymousResolutionService.php';
    $auditReason = ciAnonAuditReason($runId);
    $trackedAuditIds = $state['audit_event_ids'] ?? null;
    if (!is_array($trackedAuditIds) || $trackedAuditIds !== []) {
        ciAnonFail('fixture permits exactly one anonymous resolution');
    }
    if (ciAnonAuditRowsByReason($auditReason) !== []) {
        ciAnonFail('test audit reason already exists');
    }

    $service = \ClassIdentity\AnonymousResolutionService::fromPiwigo();
    $mapping = $service->resolveAlias(
        (int) $state['system_admin']['piwigo_user_id'],
        'PHOTO',
        (int) $state['photo_ids'][0],
        $alias,
        $auditReason,
    );
    $auditRows = ciAnonAuditRowsByReason($auditReason);
    if (count($auditRows) !== 1 || !ciAnonAuditRowIsOwned($state, $auditRows[0], $auditReason)) {
        ciAnonFail('anonymous resolution audit did not match its exact test boundary');
    }
    $audit = $auditRows[0];
    $state['audit_event_ids'] = [(int) $audit['id']];
    ciAnonReplaceState($runId, $state);

    $expected = $state['anonymous'];
    $mappingOk = (int) $mapping['identity_id'] === (int) $expected['identity_id']
        && (int) $mapping['seat_id'] === (int) $expected['seat_id']
        && (int) $mapping['account_id'] === (int) $expected['account_id']
        && (int) $mapping['principal_id'] === (int) $expected['principal_id']
        && (int) $mapping['piwigo_user_id'] === (int) $expected['piwigo_user_id']
        && (string) $mapping['classmate_id'] === (string) $expected['roster_code'];
    $auditOk = ($audit['action'] ?? null) === 'ANONYMOUS_RESOLVE'
        && ($audit['result'] ?? null) === 'SUCCESS'
        && (int) ($audit['target_identity_id'] ?? 0) === (int) $expected['identity_id']
        && (int) ($audit['target_seat_id'] ?? 0) === (int) $expected['seat_id']
        && (int) ($audit['target_account_id'] ?? 0) === (int) $expected['account_id']
        && (int) ($audit['target_principal_id'] ?? 0) === (int) $expected['principal_id'];
    $serializedAudit = json_encode($audit, JSON_THROW_ON_ERROR);
    $auditRedacted = !str_contains($serializedAudit, (string) $expected['core_username'])
        && !str_contains($serializedAudit, $alias)
        && !str_contains(strtolower($serializedAudit), 'password')
        && !str_contains(strtolower($serializedAudit), 'token');

    return [
        'mapping_ok' => $mappingOk,
        'audit_ok' => $auditOk,
        'audit_redacted' => $auditRedacted,
        'audit_event_id' => (int) $audit['id'],
    ];
}

/** @return array<string, mixed> */
function ciAnonCleanup(string $runId, bool $preserveValidatedPresenterGate = false): array
{
    global $conf;

    $path = ciAnonStatePath($runId);
    if (!is_file($path)) {
        return ['cleaned' => true, 'comments_remaining' => 0];
    }
    $state = ciAnonLoadState($runId);
    $ids = array_values(array_filter(array_map('intval', $state['comment_ids'] ?? [])));
    $marker = (string) $state['marker'];
    $auditReason = ciAnonAuditReason($runId);
    $trackedAuditIds = $state['audit_event_ids'] ?? null;
    if (!is_array($trackedAuditIds) || count($trackedAuditIds) > 1) {
        ciAnonFail('test audit rollback state invalid');
    }
    $trackedAuditIds = array_values(array_map('intval', $trackedAuditIds));
    if (count(array_unique($trackedAuditIds)) !== count($trackedAuditIds)
        || array_filter($trackedAuditIds, static fn(int $id): bool => $id <= 0) !== []
    ) {
        ciAnonFail('test audit rollback identifiers invalid');
    }
    $ownedAuditIds = ciAnonOwnedAuditIds($state, $auditReason);
    if ($trackedAuditIds !== [] && $ownedAuditIds !== [] && $trackedAuditIds !== $ownedAuditIds) {
        ciAnonFail('tracked test audit identifier drifted');
    }
    if ($trackedAuditIds !== [] && $ownedAuditIds === []) {
        $auditTable = ciAnonAuditTable();
        $existingTracked = (int) ciAnonOne(
            'SELECT COUNT(*) AS count FROM `' . $auditTable . '` WHERE id = ' . $trackedAuditIds[0]
        )['count'];
        if ($existingTracked !== 0) {
            ciAnonFail('tracked audit id now belongs to a different row');
        }
    }
    pwg_query(
        'DELETE FROM ' . COMMENTS_TABLE
        . ' WHERE content IN (' . ciAnonQuotedMarkerContents($marker) . ')'
    );
    foreach (($state['category_state'] ?? []) as $categoryId => $commentable) {
        if (!in_array($commentable, ['true', 'false'], true) || (int) $categoryId <= 0) {
            ciAnonFail('unsafe category rollback state');
        }
        single_update(CATEGORIES_TABLE, ['commentable' => $commentable], ['id' => (int) $categoryId]);
    }
    $configState = $state['config_state'] ?? null;
    if (!is_array($configState) || $configState === []) {
        ciAnonFail('test configuration rollback state unavailable');
    }
    foreach ($configState as $param => $saved) {
        if (!is_string($param)
            || preg_match('/\A[A-Za-z0-9_]{1,40}\z/D', $param) !== 1
            || !is_array($saved)
            || !array_key_exists('exists', $saved)
        ) {
            ciAnonFail('test configuration rollback state invalid');
        }
        // A successful full presenter regression is the only caller allowed
        // to retain readiness. The source installer resets this key whenever
        // ClassIdentity bytes change; ordinary failure cleanup always restores
        // the saved value (and the runner explicitly drives it false first).
        if ($preserveValidatedPresenterGate && $param === 'class_identity_anon_presenter_ready') {
            conf_update_param($param, true, true);
            $conf[$param] = true;
        } elseif ($saved['exists'] === true) {
            $value = $saved['value'] ?? null;
            if (!is_string($value) || strlen($value) > 100) {
                ciAnonFail('test configuration rollback value invalid');
            }
            single_update(CONFIG_TABLE, ['value' => $value], ['param' => $param]);
            $conf[$param] = get_boolean($value);
        } elseif ($saved['exists'] === false) {
            pwg_query(
                "DELETE FROM " . CONFIG_TABLE
                . " WHERE param = '" . pwg_db_real_escape_string($param) . "'"
            );
            unset($conf[$param]);
        } else {
            ciAnonFail('test configuration rollback state invalid');
        }
    }
    invalidate_user_cache_nb_comments();
    $remainingById = $ids === [] ? 0 : (int) ciAnonOne(
        'SELECT COUNT(*) AS count FROM ' . COMMENTS_TABLE . ' WHERE id IN (' . implode(',', $ids) . ')'
    )['count'];
    $remainingByMarker = (int) ciAnonOne(
        'SELECT COUNT(*) AS count FROM ' . COMMENTS_TABLE
        . ' WHERE content IN (' . ciAnonQuotedMarkerContents($marker) . ')'
    )['count'];

    // Product Audit remains append-only. This direct DELETE exists only in the
    // localhost synthetic fixture and is bounded by the random run reason,
    // the exact SYSTEM_ADMIN actor, all target foreign keys and the redacted
    // payload checked above. Any collision or drift aborts without deletion.
    $auditTable = ciAnonAuditTable();
    $adminPrincipalId = (int) $state['system_admin']['principal_id'];
    $adminUserId = (int) $state['system_admin']['piwigo_user_id'];
    $photoId = (int) $state['photo_ids'][0];
    $identityId = (int) $state['anonymous']['identity_id'];
    $seatId = (int) $state['anonymous']['seat_id'];
    $accountId = (int) $state['anonymous']['account_id'];
    $principalId = (int) $state['anonymous']['principal_id'];
    $expectedNewValue = pwg_db_real_escape_string(json_encode(
        ['seat_type' => 'ANONYMOUS', 'result' => 'RESOLVED'],
        JSON_THROW_ON_ERROR,
    ));
    foreach ($ownedAuditIds as $auditId) {
        pwg_query(
            'DELETE FROM `' . $auditTable . '` WHERE id = ' . $auditId
            . " AND action = 'ANONYMOUS_RESOLVE' AND reason = '"
            . pwg_db_real_escape_string($auditReason) . "'"
            . " AND actor_kind = 'SYSTEM_ADMIN' AND actor_principal_id = {$adminPrincipalId}"
            . " AND actor_user_id = {$adminUserId} AND target_type = 'ANONYMOUS_ALIAS'"
            . " AND target_id = 'PHOTO:{$photoId}' AND target_identity_id = {$identityId}"
            . " AND target_seat_id = {$seatId} AND target_account_id = {$accountId}"
            . " AND target_principal_id = {$principalId} AND old_value IS NULL"
            . " AND new_value = '{$expectedNewValue}' AND source_ip_hash IS NULL"
            . " AND result = 'SUCCESS' AND error_code IS NULL"
        );
    }
    $trackedAuditClause = $trackedAuditIds === [] ? '' : ' OR id = ' . $trackedAuditIds[0];
    $auditRemaining = (int) ciAnonOne(
        'SELECT COUNT(*) AS count FROM `' . $auditTable . "` WHERE reason = '"
        . pwg_db_real_escape_string($auditReason) . "'" . $trackedAuditClause
    )['count'];
    if ($remainingById === 0 && $remainingByMarker === 0 && $auditRemaining === 0) {
        ciAnonDeleteState($runId);
    }
    return [
        'cleaned' => $remainingById === 0 && $remainingByMarker === 0 && $auditRemaining === 0,
        'comments_remaining' => $remainingById + $remainingByMarker,
        'audit_events_deleted' => count($ownedAuditIds),
        'audit_events_remaining' => $auditRemaining,
    ];
}

/**
 * Narrow recovery for a setup process that failed before its state file was
 * published. Synthetic fixture albums are created non-commentable by contract.
 * This path refuses every non-fixture album.
 *
 * @return array<string, mixed>
 */
function ciAnonRecoverOrphan(string $runId): array
{
    if (is_file(ciAnonStatePath($runId))) {
        return ciAnonCleanup($runId);
    }
    $marker = 'CITEST_ANON_PRESENTER_' . strtoupper($runId);
    $rows = query2array(
        'SELECT c.id AS comment_id, ca.id AS category_id, ca.permalink '
        . 'FROM ' . COMMENTS_TABLE . ' c '
        . 'INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id = c.image_id '
        . 'INNER JOIN ' . CATEGORIES_TABLE . ' ca ON ca.id = ic.category_id '
        . 'WHERE c.content IN (' . ciAnonQuotedMarkerContents($marker) . ')'
    );
    $categoryIds = [];
    foreach ($rows as $row) {
        if (!str_starts_with((string) ($row['permalink'] ?? ''), 'fixture-heritage-')) {
            ciAnonFail('orphan recovery encountered a non-fixture album');
        }
        $categoryIds[(int) $row['category_id']] = (int) $row['category_id'];
    }
    pwg_query(
        'DELETE FROM ' . COMMENTS_TABLE
        . ' WHERE content IN (' . ciAnonQuotedMarkerContents($marker) . ')'
    );
    foreach ($categoryIds as $categoryId) {
        single_update(CATEGORIES_TABLE, ['commentable' => 'false'], ['id' => $categoryId]);
    }
    invalidate_user_cache_nb_comments();
    return ['cleaned' => true, 'orphan_recovery' => true, 'categories_restored' => count($categoryIds)];
}

$action = $argv[1] ?? '';
$runId = strtolower($argv[2] ?? '');
if (!in_array($action, ['setup', 'resolve', 'gate', 'assert-posted', 'cleanup', 'cleanup-ready', 'recover-orphan'], true)
    || preg_match('/\A[a-f0-9]{12}\z/D', $runId) !== 1
) {
    ciAnonFail('usage: anonymous-presenter-fixture.php ACTION RUN_ID [VALUE]');
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    ciAnonFail('refusing root');
}

chdir(CI_ANON_PIWIGO_ROOT) || ciAnonFail('Piwigo root unavailable');
define('PHPWG_ROOT_PATH', './');
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();
require_once PHPWG_ROOT_PATH . 'include/functions_comment.inc.php';

try {
    $result = match ($action) {
        'setup' => ciAnonSetup($runId),
        'resolve' => ciAnonResolve($runId, (string) ($argv[3] ?? '')),
        'gate' => ciAnonSetGate($runId, (string) ($argv[3] ?? '')),
        'assert-posted' => ciAnonAssertPosted($runId),
        'cleanup' => ciAnonCleanup($runId),
        'cleanup-ready' => ciAnonCleanup($runId, true),
        'recover-orphan' => ciAnonRecoverOrphan($runId),
    };
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) {
    ciAnonFail('operation failed: ' . $error->getMessage());
}
