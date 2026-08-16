<?php

declare(strict_types=1);

function pendingFail(string $message): never
{
    fwrite(STDERR, "PENDING_MEDIA_FIXTURE_ERROR {$message}\n");
    exit(1);
}

function pendingTableExists(string $table): bool
{
    $escaped = pwg_db_real_escape_string($table);
    return count(query2array(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$escaped}'"
    )) === 1;
}

function pendingScalar(string $sql): int
{
    $rows = query2array($sql);
    if (count($rows) !== 1 || count($rows[0]) !== 1) {
        throw new RuntimeException('scalar query was ambiguous');
    }
    return (int) array_values($rows[0])[0];
}

/** @param array<string, scalar|null> $payload */
function pendingJson(array $payload): never
{
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

function pendingMeta(string $metaTable, string $key): string
{
    $escaped = pwg_db_real_escape_string($key);
    $rows = query2array("SELECT meta_value FROM `{$metaTable}` WHERE meta_key = '{$escaped}'");
    if (count($rows) !== 1 || !isset($rows[0]['meta_value'])) {
        throw new RuntimeException('fixture metadata is missing or ambiguous');
    }
    return (string) $rows[0]['meta_value'];
}

function pendingMetaOptional(string $metaTable, string $key): ?string
{
    $escaped = pwg_db_real_escape_string($key);
    $rows = query2array("SELECT meta_value FROM `{$metaTable}` WHERE meta_key = '{$escaped}'");
    if ($rows === []) {
        return null;
    }
    if (count($rows) !== 1 || !isset($rows[0]['meta_value'])) {
        throw new RuntimeException('optional fixture metadata is ambiguous');
    }
    return (string) $rows[0]['meta_value'];
}

function pendingCreateCommunityTable(string $table): void
{
    pwg_query("CREATE TABLE `{$table}` (
        image_id MEDIUMINT(8) UNSIGNED NOT NULL,
        state VARCHAR(255) NOT NULL,
        added_on DATETIME NOT NULL,
        notified_on DATETIME DEFAULT NULL,
        validated_by MEDIUMINT(8) UNSIGNED DEFAULT NULL
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
}

function pendingAssertCommunitySchema(string $table): void
{
    if (!pendingTableExists($table)) {
        throw new RuntimeException('Community pending table is absent');
    }
    $columns = [];
    foreach (query2array("SHOW COLUMNS FROM `{$table}`") as $row) {
        $columns[(string) ($row['Field'] ?? '')] = true;
    }
    foreach (['image_id', 'state', 'added_on', 'notified_on', 'validated_by'] as $column) {
        if (!isset($columns[$column])) {
            throw new RuntimeException('Community pending schema is incompatible');
        }
    }
}

/** @return list<array<string, mixed>> */
function pendingOwnedRows(string $communityTable, int $imageId, string $marker): array
{
    $escapedMarker = pwg_db_real_escape_string($marker);
    return query2array(
        "SELECT image_id, state, added_on, notified_on, validated_by FROM `{$communityTable}` "
        . "WHERE image_id = {$imageId} AND added_on = '{$escapedMarker}' "
        . 'ORDER BY state, validated_by'
    );
}

function pendingAssertOwnedShape(string $communityTable, int $imageId, string $marker, string $shape): void
{
    $rows = pendingOwnedRows($communityTable, $imageId, $marker);
    $states = array_map(static fn (array $row): string => (string) ($row['state'] ?? ''), $rows);
    sort($states, SORT_STRING);
    $expected = match ($shape) {
        'pending' => ['moderation_pending'],
        'validated' => ['validated'],
        'malformed' => ['class_archive_invalid_state'],
        'ambiguous' => ['moderation_pending', 'validated'],
        default => throw new RuntimeException('unsupported fixture shape'),
    };
    if ($states !== $expected) {
        throw new RuntimeException('owned Community fixture shape drifted');
    }
    foreach ($rows as $row) {
        if ((int) ($row['image_id'] ?? 0) !== $imageId || $row['notified_on'] !== null || $row['validated_by'] !== null) {
            throw new RuntimeException('owned Community fixture row drifted');
        }
    }
    if (pendingScalar("SELECT COUNT(*) FROM `{$communityTable}` WHERE image_id = {$imageId}") !== count($expected)) {
        throw new RuntimeException('foreign Community row appeared on fixture image');
    }
}

function pendingSetSingleState(string $communityTable, int $imageId, string $marker, string $state): void
{
    $escapedMarker = pwg_db_real_escape_string($marker);
    pwg_query("DELETE FROM `{$communityTable}` WHERE image_id = {$imageId}");
    single_insert($communityTable, [
        'image_id' => $imageId,
        'state' => $state,
        'added_on' => $marker,
        'notified_on' => null,
        'validated_by' => null,
    ]);
    pendingAssertOwnedShape($communityTable, $imageId, $marker, match ($state) {
        'moderation_pending' => 'pending',
        'validated' => 'validated',
        'class_archive_invalid_state' => 'malformed',
        default => throw new RuntimeException('unsupported state'),
    });
}

function pendingRestore(string $usersTable, string $metaTable, string $communityTable): void
{
    if (!pendingTableExists($usersTable) || !pendingTableExists($metaTable)) {
        throw new RuntimeException('fixture backup is incomplete');
    }
    $imageId = (int) pendingMeta($metaTable, 'image_id');
    $imageLevel = (int) pendingMeta($metaTable, 'image_level');
    $marker = pendingMeta($metaTable, 'marker');
    $communityTableCreated = pendingMeta($metaTable, 'community_table_created') === '1';
    $escapedMarker = pwg_db_real_escape_string($marker);

    // Remove only rows carrying the exact fixture image and marker. Any
    // concurrent/foreign row is preserved and makes exact restoration fail.
    pwg_query(
        "DELETE FROM `{$communityTable}` WHERE image_id = {$imageId} "
        . "AND added_on = '{$escapedMarker}' "
        . "AND state IN ('moderation_pending','validated','class_archive_invalid_state') "
        . 'AND notified_on IS NULL AND validated_by IS NULL'
    );
    single_update(IMAGES_TABLE, ['level' => $imageLevel], ['id' => $imageId]);
    foreach (query2array("SELECT user_id, password_hash, privacy_level FROM `{$usersTable}` ORDER BY user_id") as $row) {
        $userId = (int) $row['user_id'];
        single_update(USERS_TABLE, ['password' => (string) $row['password_hash']], ['id' => $userId]);
        single_update(USER_INFOS_TABLE, ['level' => (int) $row['privacy_level']], ['user_id' => $userId]);
    }
    invalidate_user_cache();

    if (
        pendingScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE) !== 72
        || pendingScalar('SELECT COUNT(DISTINCT path) FROM '.IMAGES_TABLE) !== 72
        || pendingScalar("SELECT COUNT(*) FROM `{$communityTable}` WHERE image_id = {$imageId}") !== 0
        || pendingScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE." WHERE id = {$imageId} AND level = {$imageLevel}") !== 1
    ) {
        throw new RuntimeException('media fixture baseline was not restored');
    }
    foreach (query2array("SELECT user_id, password_hash, privacy_level FROM `{$usersTable}` ORDER BY user_id") as $row) {
        $userId = (int) $row['user_id'];
        $password = pwg_db_real_escape_string((string) $row['password_hash']);
        $level = (int) $row['privacy_level'];
        if (
            pendingScalar('SELECT COUNT(*) FROM '.USERS_TABLE." WHERE id = {$userId} AND password = '{$password}'") !== 1
            || pendingScalar('SELECT COUNT(*) FROM '.USER_INFOS_TABLE." WHERE user_id = {$userId} AND level = {$level}") !== 1
        ) {
            throw new RuntimeException('fixture account baseline was not restored');
        }
    }

    pwg_query("DROP TABLE `{$usersTable}`");
    if ($communityTableCreated) {
        if (pendingScalar("SELECT COUNT(*) FROM `{$communityTable}`") !== 0) {
            throw new RuntimeException('fixture-created Community table is not empty');
        }
        pwg_query("DROP TABLE `{$communityTable}`");
    }
    pwg_query("DROP TABLE `{$metaTable}`");
}

function pendingRecoverPartial(string $usersTable, string $metaTable, string $communityTable): void
{
    if (!pendingTableExists($metaTable)) {
        return;
    }
    $imageId = pendingMetaOptional($metaTable, 'image_id');
    $imageLevel = pendingMetaOptional($metaTable, 'image_level');
    $marker = pendingMetaOptional($metaTable, 'marker');
    if ($imageId !== null && $imageLevel !== null && $marker !== null && pendingTableExists($communityTable)) {
        $id = (int) $imageId;
        $escapedMarker = pwg_db_real_escape_string($marker);
        pwg_query(
            "DELETE FROM `{$communityTable}` WHERE image_id = {$id} AND added_on = '{$escapedMarker}' "
            . "AND state IN ('moderation_pending','validated','class_archive_invalid_state') "
            . 'AND notified_on IS NULL AND validated_by IS NULL'
        );
        single_update(IMAGES_TABLE, ['level' => (int) $imageLevel], ['id' => $id]);
    }
    if (pendingTableExists($usersTable)) {
        foreach (query2array("SELECT user_id, password_hash, privacy_level FROM `{$usersTable}`") as $row) {
            $userId = (int) $row['user_id'];
            single_update(USERS_TABLE, ['password' => (string) $row['password_hash']], ['id' => $userId]);
            single_update(USER_INFOS_TABLE, ['level' => (int) $row['privacy_level']], ['user_id' => $userId]);
        }
        pwg_query("DROP TABLE `{$usersTable}`");
    }
    $created = pendingMetaOptional($metaTable, 'community_table_created') === '1';
    if ($created && pendingTableExists($communityTable)) {
        if (pendingScalar("SELECT COUNT(*) FROM `{$communityTable}`") !== 0) {
            throw new RuntimeException('partial recovery found foreign Community rows');
        }
        pwg_query("DROP TABLE `{$communityTable}`");
    }
    pwg_query("DROP TABLE `{$metaTable}`");
    invalidate_user_cache();
}

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    pendingFail('refusing non-CLI or root execution');
}
$action = (string) getenv('CLASS_ARCHIVE_PENDING_ACTION');
$runId = (string) getenv('CLASS_ARCHIVE_PENDING_RUN_ID');
if (!preg_match('/\A[a-f0-9]{16}\z/D', $runId)) {
    pendingFail('invalid run id');
}

try {
    chdir('/var/www/html/piwigo') || throw new RuntimeException('application root unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    global $prefixeTable, $conf;
    $communityTable = $prefixeTable . 'community_pendings';
    $usersTable = $prefixeTable . 'class_archive_pending_users_' . $runId;
    $metaTable = $prefixeTable . 'class_archive_pending_meta_' . $runId;
    foreach ([$communityTable, $usersTable, $metaTable] as $table) {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) {
            throw new RuntimeException('unsafe table name');
        }
    }
    if ($action === 'prepare') {
        if (pendingTableExists($usersTable) || pendingTableExists($metaTable)) {
            throw new RuntimeException('fixture backup already exists');
        }
        $pluginRows = query2array(
            "SELECT state FROM " . PLUGINS_TABLE . " WHERE id IN ('Community','community')"
        );
        if (count($pluginRows) > 1 || (count($pluginRows) === 1 && (string) $pluginRows[0]['state'] === 'active')) {
            throw new RuntimeException('Community must remain inactive');
        }
        if (
            pendingScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE) !== 72
            || pendingScalar('SELECT COUNT(DISTINCT path) FROM '.IMAGES_TABLE) !== 72
        ) {
            throw new RuntimeException('fixture requires the exact 72-image baseline');
        }

        pwg_query("CREATE TABLE `{$metaTable}` (
            meta_key VARCHAR(64) NOT NULL,
            meta_value VARCHAR(1024) NOT NULL,
            PRIMARY KEY (meta_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $communityTableCreated = !pendingTableExists($communityTable);
        single_insert($metaTable, [
            'meta_key' => 'community_table_created',
            'meta_value' => $communityTableCreated ? '1' : '0',
        ]);
        if ($communityTableCreated) {
            pendingCreateCommunityTable($communityTable);
        }
        pendingAssertCommunitySchema($communityTable);

        $candidates = query2array(
            'SELECT i.id, i.path, i.level, COUNT(*) AS association_count, '
            . "SUM(CASE WHEN root.permalink = 'class-archive-heritage' THEN 1 ELSE 0 END) AS heritage_count "
            . 'FROM '.IMAGES_TABLE.' i JOIN '.IMAGE_CATEGORY_TABLE.' ic ON ic.image_id = i.id '
            . 'JOIN '.CATEGORIES_TABLE.' c ON c.id = ic.category_id '
            . "LEFT JOIN ".CATEGORIES_TABLE." root ON root.id = CAST(SUBSTRING_INDEX(c.uppercats, ',', 1) AS UNSIGNED) "
            . "WHERE NOT EXISTS (SELECT 1 FROM `{$communityTable}` cp WHERE cp.image_id = i.id) "
            . 'GROUP BY i.id, i.path, i.level HAVING association_count >= 1 AND heritage_count = association_count '
            . 'ORDER BY i.id LIMIT 1'
        );
        if (count($candidates) !== 1) {
            throw new RuntimeException('unmoderated HERITAGE fixture image unavailable');
        }
        $imageId = (int) $candidates[0]['id'];
        $sourcePath = preg_replace('#^\./#', '', (string) $candidates[0]['path']);
        if (
            !is_string($sourcePath)
            || !preg_match('#\A(?:upload|galleries)/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z#D', $sourcePath)
            || str_contains($sourcePath, '..')
        ) {
            throw new RuntimeException('fixture source path is unsafe');
        }
        $markerUnix = 946684800 + (hexdec(substr($runId, 0, 8)) % 3153600000);
        $marker = gmdate('Y-m-d H:i:s', $markerUnix);

        pwg_query("CREATE TABLE `{$usersTable}` (
            username VARCHAR(100) BINARY NOT NULL,
            user_id MEDIUMINT UNSIGNED NOT NULL,
            password_hash VARCHAR(255) BINARY NOT NULL,
            privacy_level TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (username), UNIQUE KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach ([
            'image_id' => (string) $imageId,
            'image_level' => (string) (int) $candidates[0]['level'],
            'marker' => $marker,
            'source_path' => $sourcePath,
        ] as $key => $value) {
            single_insert($metaTable, ['meta_key' => $key, 'meta_value' => $value]);
        }

        $password = getenv('CLASS_ARCHIVE_PENDING_PASSWORD');
        if (!is_string($password) || strlen($password) < 32) {
            throw new RuntimeException('transient password missing');
        }
        $expected = [
            'fixture-classmate' => 'CLASSMATE',
            'fixture-teacher' => 'TEACHER',
            'fixture-family' => 'FAMILY',
            'fixture-anonymous' => 'ANONYMOUS',
        ];
        foreach ($expected as $username => $role) {
            $userId = (int) get_userid($username);
            $escapedUsername = pwg_db_real_escape_string($username);
            $rows = query2array(
                'SELECT u.password, ui.level, ui.status FROM '.USERS_TABLE.' u '
                . 'JOIN '.USER_INFOS_TABLE.' ui ON ui.user_id = u.id '
                . "WHERE u.id = {$userId} AND u.username = '{$escapedUsername}'"
            );
            if (
                $userId <= 0
                || count($rows) !== 1
                || (string) $rows[0]['status'] !== 'normal'
                || !class_exists('ClassIdentity\\Access', false)
                || \ClassIdentity\Access::resolveMediaRole($userId) !== $role
            ) {
                throw new RuntimeException('fixture principal baseline is invalid');
            }
            single_insert($usersTable, [
                'username' => $username,
                'user_id' => $userId,
                'password_hash' => (string) $rows[0]['password'],
                'privacy_level' => (int) $rows[0]['level'],
            ]);
            single_update(USERS_TABLE, ['password' => $conf['password_hash']($password)], ['id' => $userId]);
        }
        pendingJson([
            'imageId' => $imageId,
            'sourcePath' => $sourcePath,
            'imageCount' => 72,
        ]);
    }

    pendingAssertCommunitySchema($communityTable);

    if (!pendingTableExists($usersTable) || !pendingTableExists($metaTable)) {
        throw new RuntimeException('fixture backup is not active');
    }
    $imageId = (int) pendingMeta($metaTable, 'image_id');
    $imageLevel = (int) pendingMeta($metaTable, 'image_level');
    $marker = pendingMeta($metaTable, 'marker');

    switch ($action) {
        case 'set_validated':
        case 'set_pending':
            if (pendingScalar("SELECT COUNT(*) FROM `{$communityTable}` WHERE image_id = {$imageId}") !== 0) {
                throw new RuntimeException('fixture image unexpectedly has a Community row');
            }
            single_update(IMAGES_TABLE, ['level' => 16], ['id' => $imageId]);
            foreach (query2array("SELECT user_id FROM `{$usersTable}`") as $row) {
                single_update(USER_INFOS_TABLE, ['level' => 16], ['user_id' => (int) $row['user_id']]);
            }
            pendingSetSingleState(
                $communityTable,
                $imageId,
                $marker,
                $action === 'set_validated' ? 'validated' : 'moderation_pending',
            );
            invalidate_user_cache();
            break;
        case 'validated_to_pending':
            pendingAssertOwnedShape($communityTable, $imageId, $marker, 'validated');
            $escapedMarker = pwg_db_real_escape_string($marker);
            pwg_query(
                "UPDATE `{$communityTable}` SET state = 'moderation_pending' "
                . "WHERE image_id = {$imageId} AND added_on = '{$escapedMarker}' AND state = 'validated'"
            );
            pendingAssertOwnedShape($communityTable, $imageId, $marker, 'pending');
            break;
        case 'set_malformed':
            pendingAssertOwnedShape($communityTable, $imageId, $marker, 'pending');
            $escapedMarker = pwg_db_real_escape_string($marker);
            pwg_query(
                "UPDATE `{$communityTable}` SET state = 'class_archive_invalid_state' "
                . "WHERE image_id = {$imageId} AND added_on = '{$escapedMarker}' AND state = 'moderation_pending'"
            );
            pendingAssertOwnedShape($communityTable, $imageId, $marker, 'malformed');
            break;
        case 'malformed_to_pending':
            pendingAssertOwnedShape($communityTable, $imageId, $marker, 'malformed');
            $escapedMarker = pwg_db_real_escape_string($marker);
            pwg_query(
                "UPDATE `{$communityTable}` SET state = 'moderation_pending' "
                . "WHERE image_id = {$imageId} AND added_on = '{$escapedMarker}' AND state = 'class_archive_invalid_state'"
            );
            pendingAssertOwnedShape($communityTable, $imageId, $marker, 'pending');
            break;
        case 'set_ambiguous':
            pendingAssertOwnedShape($communityTable, $imageId, $marker, 'pending');
            single_insert($communityTable, [
                'image_id' => $imageId,
                'state' => 'validated',
                'added_on' => $marker,
                'notified_on' => null,
                'validated_by' => null,
            ]);
            pendingAssertOwnedShape($communityTable, $imageId, $marker, 'ambiguous');
            break;
        case 'restore':
            pendingRestore($usersTable, $metaTable, $communityTable);
            pendingJson(['state' => 'RESTORED', 'imageCount' => 72]);
        default:
            throw new RuntimeException('unsupported fixture action');
    }
    pendingJson(['state' => strtoupper($action), 'imageId' => $imageId]);
} catch (Throwable $error) {
    // Preparation may fail between creation of the durable metadata table and
    // the full account snapshot. Recover whichever exact stages exist.
    try {
        if (
            isset($usersTable, $metaTable, $communityTable)
            && pendingTableExists($metaTable)
        ) {
            pendingRecoverPartial($usersTable, $metaTable, $communityTable);
        }
    } catch (Throwable) {
        // Keep the original generic error; backup tables remain as recovery
        // evidence if exact restoration cannot be proved.
    }
    pendingFail('fixture action failed: ' . $error->getMessage());
}
