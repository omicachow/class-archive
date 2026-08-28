<?php

declare(strict_types=1);

/**
 * Reversible synthetic People prerequisite for the V4 scoped Chrome gate.
 *
 * This fixture deliberately proves the ClassArchivePerson/manual-rule
 * projection path without pretending it has run Immich face detection. It
 * creates two opaque MANUAL people over two HERITAGE and two LIVING synthetic
 * photos, rebuilds the durable read projections, then removes exactly those
 * rows again. It never creates media, touches Piwigo associations, calls an
 * HTTP endpoint, or targets a private Owner runtime.
 */

const V4PL_ROOT = '/var/www/html/piwigo';
const V4PL_PEOPLE = 2;
const V4PL_LABELS = ['合成人物甲', '合成人物乙'];

function v4plFail(string $code): never
{
    fwrite(STDERR, "V4_SCOPE_PEOPLE_FIXTURE=FAIL code={$code}\n");
    exit(1);
}

/** @param array<string,mixed> $value */
function v4plJson(array $value): never
{
    fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function v4plRequireRuntime(): void
{
    if (PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || posix_geteuid() === 0
        || getenv('CLASS_ARCHIVE_V4_SCOPE_PEOPLE_FIXTURE') !== '1'
        || !is_file('/workspace/tests/phase3/photos-app-v4-scope-people-fixture.php')
    ) {
        v4plFail('test_gate_required');
    }
    if (realpath(V4PL_ROOT) !== V4PL_ROOT || is_link(V4PL_ROOT)) {
        v4plFail('piwigo_root_untrusted');
    }
    chdir(V4PL_ROOT) || v4plFail('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

function v4plRunId(string $value): string
{
    $value = strtolower($value);
    if (preg_match('/\A[a-f0-9]{16}\z/D', $value) !== 1) {
        v4plFail('run_invalid');
    }
    return $value;
}

function v4plUuid(string $value): string
{
    $value = strtolower($value);
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) !== 1) {
        v4plFail('uuid_invalid');
    }
    return $value;
}

function v4plStatePath(string $run): string
{
    return '/tmp/class-archive-v4-scope-people-' . v4plRunId($run) . '.json';
}

function v4plLockPath(): string
{
    // This is intentionally the exact shared mutation lock used by the
    // UNKNOWN-era scope fixture. Projection rebuilds from separate synthetic
    // gates must never run concurrently just because their state files have
    // different names.
    return '/tmp/class-archive-v4-scope.lock';
}

/** @param array<string,mixed> $document */
function v4plWriteExclusiveJson(string $path, array $document, string $code): void
{
    if (file_exists($path) || is_link($path)) {
        v4plFail($code);
    }
    $raw = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $oldUmask = umask(0077);
    try {
        $handle = @fopen($path, 'x');
    } finally {
        umask($oldUmask);
    }
    if (!is_resource($handle)) {
        v4plFail($code);
    }
    try {
        if (fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            v4plFail($code);
        }
    } finally {
        fclose($handle);
    }
    if (!chmod($path, 0600)) {
        v4plFail($code);
    }
}

/** @return array<string,mixed> */
function v4plReadProtectedJson(string $path, string $code, int $minimumBytes, int $maximumBytes): array
{
    if (!is_file($path) || is_link($path)) {
        v4plFail($code);
    }
    $stat = lstat($path);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
        || (int) ($stat['size'] ?? 0) < $minimumBytes
        || (int) ($stat['size'] ?? 0) > $maximumBytes
    ) {
        v4plFail($code);
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        v4plFail($code);
    }
    try {
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        v4plFail($code);
    }
    if (!is_array($value)) {
        v4plFail($code);
    }
    return $value;
}

function v4plAcquireLock(string $run): void
{
    v4plWriteExclusiveJson(v4plLockPath(), ['version' => 1, 'run' => v4plRunId($run)], 'global_lock_held');
    $lock = v4plReadProtectedJson(v4plLockPath(), 'global_lock_invalid', 32, 512);
    if (array_keys($lock) !== ['version', 'run'] || ($lock['version'] ?? null) !== 1
        || !is_string($lock['run'] ?? null) || !hash_equals(v4plRunId($run), $lock['run'])) {
        v4plFail('global_lock_invalid');
    }
}

function v4plReleaseLock(string $run): void
{
    $lock = v4plReadProtectedJson(v4plLockPath(), 'global_lock_invalid', 32, 512);
    if (array_keys($lock) !== ['version', 'run'] || ($lock['version'] ?? null) !== 1
        || !is_string($lock['run'] ?? null) || !hash_equals(v4plRunId($run), $lock['run'])) {
        v4plFail('global_lock_invalid');
    }
    if (!unlink(v4plLockPath()) || file_exists(v4plLockPath()) || is_link(v4plLockPath())) {
        v4plFail('global_lock_release_failed');
    }
}

/**
 * Successful prepare releases the shared mutation lock before the delegated
 * UNKNOWN scope fixture runs. Cleanup re-acquires it. If a process failed
 * while prepare held the same run's lock, accept only that exact owner so the
 * bounded cleanup can recover without deleting a different fixture's lock.
 */
function v4plAcquireCleanupLock(string $run): void
{
    $path = v4plLockPath();
    if (!file_exists($path) && !is_link($path)) {
        v4plAcquireLock($run);
        return;
    }
    $lock = v4plReadProtectedJson($path, 'global_lock_invalid', 32, 512);
    if (array_keys($lock) !== ['version', 'run'] || ($lock['version'] ?? null) !== 1
        || !is_string($lock['run'] ?? null) || !hash_equals(v4plRunId($run), $lock['run'])) {
        v4plFail('global_lock_held');
    }
}

/** @param array<string,mixed> $state */
function v4plValidateState(array $state, string $run): array
{
    if (array_keys($state) !== ['version', 'run', 'stage', 'admin_principal_id', 'people']
        || ($state['version'] ?? null) !== 1
        || !is_string($state['run'] ?? null) || !hash_equals(v4plRunId($run), $state['run'])
        || !in_array($state['stage'] ?? null, ['PREPARED', 'ACTIVE'], true)
        || !is_int($state['admin_principal_id'] ?? null) || $state['admin_principal_id'] <= 0
        || !is_array($state['people'] ?? null) || count($state['people']) !== V4PL_PEOPLE
    ) {
        v4plFail('state_shape_invalid');
    }
    $ids = [];
    $photos = [];
    foreach ($state['people'] as $index => $person) {
        if (!is_array($person) || array_keys($person) !== ['person_id', 'heritage_photo_id', 'living_photo_id']) {
            v4plFail('state_person_shape_invalid');
        }
        foreach (['person_id', 'heritage_photo_id', 'living_photo_id'] as $field) {
            if (!is_string($person[$field] ?? null)) {
                v4plFail('state_person_field_invalid');
            }
            $state['people'][$index][$field] = v4plUuid((string) $person[$field]);
        }
        $personId = (string) $state['people'][$index]['person_id'];
        if (isset($ids[$personId])) {
            v4plFail('state_person_duplicate');
        }
        $ids[$personId] = true;
        foreach (['heritage_photo_id', 'living_photo_id'] as $field) {
            $photo = (string) $state['people'][$index][$field];
            if (isset($photos[$photo])) {
                v4plFail('state_photo_duplicate');
            }
            $photos[$photo] = true;
        }
    }
    return $state;
}

/** @return array<string,mixed> */
function v4plReadState(string $run): array
{
    return v4plValidateState(v4plReadProtectedJson(v4plStatePath($run), 'state_invalid', 160, 4096), $run);
}

/** @param array<string,mixed> $state */
function v4plWriteState(string $run, array $state): void
{
    $state = v4plValidateState($state, $run);
    v4plWriteExclusiveJson(v4plStatePath($run), $state, 'state_already_exists');
    v4plReadState($run);
}

/** @param array<string,mixed> $state */
function v4plReplaceState(string $run, array $state): void
{
    $before = v4plReadState($run);
    $state = v4plValidateState($state, $run);
    if ($before['people'] !== $state['people'] || $before['admin_principal_id'] !== $state['admin_principal_id']) {
        v4plFail('state_replace_mismatch');
    }
    $path = v4plStatePath($run);
    $next = $path . '.next';
    v4plWriteExclusiveJson($next, $state, 'state_replace_failed');
    if (!rename($next, $path)) {
        v4plFail('state_replace_failed');
    }
    v4plReadState($run);
}

function v4plAssertSyntheticBaseline(): void
{
    global $prefixeTable;
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $images = query2array('SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'images`');
    $multi = query2array(
        'SELECT COUNT(*) AS `count` FROM (SELECT `image_id` FROM `' . $prefixeTable . 'image_category` '
        . 'GROUP BY `image_id` HAVING COUNT(*) > 1) AS `multi_album_images`',
    );
    $active = $repository->fetchOne(
        'SELECT COUNT(*) AS `count`,COUNT(DISTINCT `media_reference`) AS `physical_originals` FROM `'
        . $repository->table('photo') . '` WHERE `state`=?',
        [\ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
    );
    $actual = [
        'images' => (int) ($images[0]['count'] ?? -1),
        'active_canonical' => (int) ($active['count'] ?? -1),
        'physical_originals' => (int) ($active['physical_originals'] ?? -1),
        'multi_album_images' => (int) ($multi[0]['count'] ?? -1),
    ];
    if ($actual !== ['images' => 72, 'active_canonical' => 72, 'physical_originals' => 72, 'multi_album_images' => 8]) {
        v4plFail('synthetic_baseline_drift');
    }
}

function v4plAssertSchema(): void
{
    if (!class_exists(\ClassIdentity\Schema::class) || \ClassIdentity\Schema::CURRENT_VERSION !== 18) {
        v4plFail('schema_version_invalid');
    }
}

/** @return array{heritage:list<string>,living:list<string>} */
function v4plSelectRoleScopedPhotos(\ClassIdentity\Repository $repository): array
{
    $rows = $repository->fetchAll(
        'SELECT rp.`class_photo_id`,rp.`era` FROM `' . $repository->table('read_photo') . '` rp '
        . 'INNER JOIN `' . $repository->table('read_projection') . '` r '
        . "ON r.`projection_key`='PHOTO_CATALOG' AND r.`state`='ACTIVE' AND rp.`generation`=r.`generation` "
        . "WHERE rp.`era` IN ('HERITAGE','LIVING') ORDER BY rp.`era` ASC,rp.`class_photo_id` ASC",
    );
    $result = ['heritage' => [], 'living' => []];
    foreach ($rows as $row) {
        $era = (string) ($row['era'] ?? '');
        $binary = $row['class_photo_id'] ?? null;
        if (!is_string($binary) || strlen($binary) !== 16) {
            v4plFail('projection_photo_invalid');
        }
        if ($era === 'HERITAGE' && count($result['heritage']) < V4PL_PEOPLE) {
            $result['heritage'][] = \ClassIdentity\DomainSupport::binaryToId($binary);
        }
        if ($era === 'LIVING' && count($result['living']) < V4PL_PEOPLE) {
            $result['living'][] = \ClassIdentity\DomainSupport::binaryToId($binary);
        }
    }
    if (count($result['heritage']) !== V4PL_PEOPLE || count($result['living']) !== V4PL_PEOPLE
        || count(array_unique(array_merge($result['heritage'], $result['living']))) !== V4PL_PEOPLE * 2) {
        v4plFail('projection_era_fixture_unavailable');
    }
    return $result;
}

function v4plSystemAdminPrincipal(\ClassIdentity\Repository $repository): int
{
    global $prefixeTable;
    $rows = $repository->fetchAll(
        'SELECT p.`id` FROM `' . $repository->table('principal') . '` p '
        . 'INNER JOIN `' . $prefixeTable . 'user_infos` ui ON ui.`user_id`=p.`piwigo_user_id` '
        . "WHERE p.`principal_type`='SYSTEM_ACCOUNT' AND p.`system_role`='SYSTEM_ADMIN' AND p.`state`='ACTIVE' "
        . "AND p.`account_id` IS NULL AND ui.`status` IN ('admin','webmaster') ORDER BY p.`id` ASC LIMIT 2",
    );
    if (count($rows) !== 1 || !is_numeric($rows[0]['id'] ?? null) || (int) $rows[0]['id'] <= 0) {
        v4plFail('system_admin_principal_invalid');
    }
    return (int) $rows[0]['id'];
}

function v4plAssertNoPreexistingManualPeople(\ClassIdentity\Repository $repository): void
{
    $person = $repository->fetchOne(
        'SELECT COUNT(*) AS `count` FROM `' . $repository->table('person') . "` WHERE `source_kind`='MANUAL'",
    );
    $rules = $repository->fetchOne(
        'SELECT COUNT(*) AS `count` FROM `' . $repository->table('person_photo_rule') . '` r '
        . 'INNER JOIN `' . $repository->table('person') . "` p ON p.`class_person_id`=r.`class_person_id` WHERE p.`source_kind`='MANUAL'",
    );
    if ((int) ($person['count'] ?? -1) !== 0 || (int) ($rules['count'] ?? -1) !== 0) {
        v4plFail('preexisting_manual_people');
    }
}

/** @param array<string,mixed> $state */
function v4plAssertPhotoEras(\ClassIdentity\Repository $repository, array $state): void
{
    $ids = [];
    foreach ($state['people'] as $person) {
        $ids[] = \ClassIdentity\DomainSupport::idToBinary((string) $person['heritage_photo_id']);
        $ids[] = \ClassIdentity\DomainSupport::idToBinary((string) $person['living_photo_id']);
    }
    $rows = $repository->fetchAll(
        'SELECT rp.`class_photo_id`,rp.`era` FROM `' . $repository->table('read_photo') . '` rp '
        . 'INNER JOIN `' . $repository->table('read_projection') . '` r '
        . "ON r.`projection_key`='PHOTO_CATALOG' AND r.`state`='ACTIVE' AND rp.`generation`=r.`generation` "
        . 'WHERE rp.`class_photo_id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids,
    );
    $eras = [];
    foreach ($rows as $row) {
        $binary = $row['class_photo_id'] ?? null;
        if (!is_string($binary) || strlen($binary) !== 16) {
            v4plFail('projection_photo_invalid');
        }
        $eras[\ClassIdentity\DomainSupport::binaryToId($binary)] = (string) ($row['era'] ?? '');
    }
    foreach ($state['people'] as $person) {
        if (($eras[$person['heritage_photo_id']] ?? null) !== 'HERITAGE'
            || ($eras[$person['living_photo_id']] ?? null) !== 'LIVING') {
            v4plFail('projection_era_drift');
        }
    }
}

/** @param array<string,mixed> $state */
function v4plOwnedStateStatus(\ClassIdentity\Repository $repository, array $state): string
{
    $personIds = array_map(
        static fn(array $person): string => \ClassIdentity\DomainSupport::idToBinary((string) $person['person_id']),
        $state['people'],
    );
    $people = $repository->fetchAll(
        'SELECT `class_person_id`,`immich_person_id`,`display_name`,`classmate_identity_id`,`manual_cover_class_photo_id`,`source_kind`,`visibility`,`state`,`lock_version` '
        . 'FROM `' . $repository->table('person') . '` WHERE `class_person_id` IN (' . implode(',', array_fill(0, count($personIds), '?')) . ') '
        . 'ORDER BY `class_person_id` ASC',
        $personIds,
    );
    $rules = $repository->fetchAll(
        'SELECT `class_person_id`,`class_photo_id`,`rule`,`updated_by_principal_id`,`reason` FROM `'
        . $repository->table('person_photo_rule') . '` WHERE `class_person_id` IN (' . implode(',', array_fill(0, count($personIds), '?')) . ') '
        . 'ORDER BY `class_person_id` ASC,`class_photo_id` ASC',
        $personIds,
    );
    if ($people === [] && $rules === []) {
        return 'ABSENT';
    }
    if (count($people) !== V4PL_PEOPLE || count($rules) !== V4PL_PEOPLE * 2) {
        v4plFail('owned_state_partial');
    }
    $expected = [];
    foreach ($state['people'] as $index => $person) {
        $personBinary = \ClassIdentity\DomainSupport::idToBinary((string) $person['person_id']);
        $coverBinary = \ClassIdentity\DomainSupport::idToBinary((string) $person['heritage_photo_id']);
        $expected[bin2hex($personBinary)] = [
            'label' => V4PL_LABELS[$index],
            'cover' => $coverBinary,
            'photos' => [
                bin2hex(\ClassIdentity\DomainSupport::idToBinary((string) $person['heritage_photo_id'])) => true,
                bin2hex(\ClassIdentity\DomainSupport::idToBinary((string) $person['living_photo_id'])) => true,
            ],
        ];
    }
    foreach ($people as $row) {
        $personBinary = $row['class_person_id'] ?? null;
        $coverBinary = $row['manual_cover_class_photo_id'] ?? null;
        if (!is_string($personBinary) || !is_string($coverBinary) || strlen($personBinary) !== 16 || strlen($coverBinary) !== 16) {
            v4plFail('owned_person_invalid');
        }
        $key = bin2hex($personBinary);
        $wanted = $expected[$key] ?? null;
        if ($wanted === null
            || !hash_equals((string) $wanted['label'], (string) ($row['display_name'] ?? ''))
            || $row['immich_person_id'] !== null
            || !hash_equals((string) $wanted['cover'], $coverBinary)
            || $row['classmate_identity_id'] !== null
            || ($row['source_kind'] ?? null) !== 'MANUAL'
            || ($row['visibility'] ?? null) !== 'VISIBLE'
            || ($row['state'] ?? null) !== 'ACTIVE'
            || (int) ($row['lock_version'] ?? -1) !== 0
        ) {
            v4plFail('owned_person_invalid');
        }
    }
    $seenRules = [];
    foreach ($rules as $row) {
        $personBinary = $row['class_person_id'] ?? null;
        $photoBinary = $row['class_photo_id'] ?? null;
        if (!is_string($personBinary) || !is_string($photoBinary) || strlen($personBinary) !== 16 || strlen($photoBinary) !== 16) {
            v4plFail('owned_rule_invalid');
        }
        $personKey = bin2hex($personBinary);
        $photoKey = bin2hex($photoBinary);
        if (!isset($expected[$personKey]['photos'][$photoKey])
            || ($row['rule'] ?? null) !== 'INCLUDE'
            || (int) ($row['updated_by_principal_id'] ?? 0) !== (int) $state['admin_principal_id']
            || ($row['reason'] ?? null) !== 'Synthetic V4 scope People prerequisite'
            || isset($seenRules[$personKey . ':' . $photoKey])) {
            v4plFail('owned_rule_invalid');
        }
        $seenRules[$personKey . ':' . $photoKey] = true;
    }
    if (count($seenRules) !== V4PL_PEOPLE * 2) {
        v4plFail('owned_rule_invalid');
    }
    $merge = $repository->fetchOne(
        'SELECT COUNT(*) AS `count` FROM `' . $repository->table('person_merge') . '` '
        . 'WHERE `source_class_person_id` IN (' . implode(',', array_fill(0, count($personIds), '?')) . ') '
        . 'OR `target_class_person_id` IN (' . implode(',', array_fill(0, count($personIds), '?')) . ')',
        array_merge($personIds, $personIds),
    );
    if ((int) ($merge['count'] ?? -1) !== 0) {
        v4plFail('owned_person_merge_detected');
    }
    return 'OWNED';
}

/** @param array<string,mixed> $state */
function v4plInsertOwnedState(\ClassIdentity\Repository $repository, array $state): void
{
    v4plAssertPhotoEras($repository, $state);
    $repository->transaction(function (\ClassIdentity\Repository $transaction) use ($state): void {
        foreach ($state['people'] as $index => $person) {
            $personBinary = \ClassIdentity\DomainSupport::idToBinary((string) $person['person_id']);
            $heritageBinary = \ClassIdentity\DomainSupport::idToBinary((string) $person['heritage_photo_id']);
            $livingBinary = \ClassIdentity\DomainSupport::idToBinary((string) $person['living_photo_id']);
            $created = $transaction->execute(
                'INSERT INTO `' . $transaction->table('person') . '` '
                . '(`class_person_id`,`display_name`,`classmate_identity_id`,`manual_cover_class_photo_id`,`source_kind`,`visibility`,`state`,`lock_version`,`created_at`,`updated_at`) '
                . "VALUES (?, ?, NULL, ?, 'MANUAL', 'VISIBLE', 'ACTIVE', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [$personBinary, V4PL_LABELS[$index], $heritageBinary],
            );
            if ($created !== 1) {
                v4plFail('owned_person_create_failed');
            }
            foreach ([$heritageBinary, $livingBinary] as $photoBinary) {
                $createdRule = $transaction->execute(
                    'INSERT INTO `' . $transaction->table('person_photo_rule') . '` '
                    . '(`class_person_id`,`class_photo_id`,`rule`,`updated_by_principal_id`,`reason`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, 'INCLUDE', ?, 'Synthetic V4 scope People prerequisite', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [$personBinary, $photoBinary, (int) $state['admin_principal_id']],
                );
                if ($createdRule !== 1) {
                    v4plFail('owned_rule_create_failed');
                }
            }
        }
    });
    if (v4plOwnedStateStatus($repository, $state) !== 'OWNED') {
        v4plFail('owned_state_create_invalid');
    }
}

/** @param array<string,mixed> $state */
function v4plDeleteOwnedState(\ClassIdentity\Repository $repository, array $state): bool
{
    $status = v4plOwnedStateStatus($repository, $state);
    if ($status === 'ABSENT') {
        return false;
    }
    $personIds = array_map(
        static fn(array $person): string => \ClassIdentity\DomainSupport::idToBinary((string) $person['person_id']),
        $state['people'],
    );
    $repository->transaction(function (\ClassIdentity\Repository $transaction) use ($personIds): void {
        $rulesDeleted = $transaction->execute(
            'DELETE FROM `' . $transaction->table('person_photo_rule') . '` WHERE `class_person_id` IN ('
            . implode(',', array_fill(0, count($personIds), '?')) . ')',
            $personIds,
        );
        $peopleDeleted = $transaction->execute(
            'DELETE FROM `' . $transaction->table('person') . '` WHERE `class_person_id` IN ('
            . implode(',', array_fill(0, count($personIds), '?')) . ') AND `source_kind`=\'MANUAL\'',
            $personIds,
        );
        if ($rulesDeleted !== V4PL_PEOPLE * 2 || $peopleDeleted !== V4PL_PEOPLE) {
            v4plFail('owned_state_delete_failed');
        }
    });
    if (v4plOwnedStateStatus($repository, $state) !== 'ABSENT') {
        v4plFail('owned_state_delete_invalid');
    }
    return true;
}

function v4plRebuildProjection(): void
{
    if (!class_exists(\ClassIdentity\Gateway\ReadProjectionBuilder::class)) {
        v4plFail('projection_builder_unavailable');
    }
    $result = \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
    if (!is_array($result)) {
        v4plFail('projection_rebuild_invalid');
    }
}

/** @return array<string,mixed> */
function v4plPrepare(string $run): array
{
    v4plAssertSchema();
    v4plAssertSyntheticBaseline();
    $repository = \ClassIdentity\Repository::fromPiwigo();
    v4plAcquireLock($run);
    $lockHeld = true;
    try {
        // Recheck only after acquiring the global lock. Without this second
        // check, two lifecycle invocations could both observe an empty MANUAL
        // overlay before either writes, then create concurrent test people.
        v4plAssertNoPreexistingManualPeople($repository);
        $photos = v4plSelectRoleScopedPhotos($repository);
        $admin = v4plSystemAdminPrincipal($repository);
        $state = [
            'version' => 1,
            'run' => $run,
            'stage' => 'PREPARED',
            'admin_principal_id' => $admin,
            'people' => [],
        ];
        for ($index = 0; $index < V4PL_PEOPLE; ++$index) {
            $state['people'][] = [
                'person_id' => \ClassIdentity\DomainSupport::generateId(),
                'heritage_photo_id' => $photos['heritage'][$index],
                'living_photo_id' => $photos['living'][$index],
            ];
        }
        v4plWriteState($run, $state);
        v4plInsertOwnedState($repository, $state);
        v4plRebuildProjection();
        $state['stage'] = 'ACTIVE';
        v4plReplaceState($run, $state);
        v4plReleaseLock($run);
        $lockHeld = false;
        return ['prepared' => true, 'people' => V4PL_PEOPLE, 'scope' => 'SYNTHETIC_8091'];
    } catch (Throwable $error) {
        // A state file is created before the first database mutation. Keep
        // that exact recovery record and lock for the outer finally cleanup.
        // If state creation itself failed, no mutation is possible, so release
        // the run-owned lock rather than stranding unrelated synthetic tests.
        if ($lockHeld && !file_exists(v4plStatePath($run)) && !is_link(v4plStatePath($run))) {
            v4plReleaseLock($run);
        }
        throw $error;
    }
}

/** @return array<string,mixed> */
function v4plCleanup(string $run): array
{
    v4plAssertSchema();
    v4plAcquireCleanupLock($run);
    $statePath = v4plStatePath($run);
    if (!file_exists($statePath) && !is_link($statePath)) {
        // Prepare did not get as far as its protected state write, therefore
        // no database mutation can have occurred. Release only our exact
        // shared lock after proving the public baseline remains intact.
        v4plAssertSyntheticBaseline();
        v4plReleaseLock($run);
        return ['cleaned' => false, 'absent' => true, 'people' => V4PL_PEOPLE, 'scope' => 'SYNTHETIC_8091'];
    }
    $state = v4plReadState($run);
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $deleted = v4plDeleteOwnedState($repository, $state);
    if ($deleted) {
        v4plRebuildProjection();
    }
    v4plAssertSyntheticBaseline();
    if (!unlink($statePath) || file_exists($statePath) || is_link($statePath)) {
        v4plFail('state_release_failed');
    }
    v4plReleaseLock($run);
    return ['cleaned' => $deleted, 'absent' => !$deleted, 'people' => V4PL_PEOPLE, 'scope' => 'SYNTHETIC_8091'];
}

try {
    v4plRequireRuntime();
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    $arguments = array_values(array_slice($_SERVER['argv'] ?? [], 1));
    $command = $arguments[0] ?? '';
    if (!is_string($command) || count($arguments) !== 2 || !in_array($command, ['prepare', 'cleanup'], true)) {
        v4plFail('usage');
    }
    $run = v4plRunId((string) $arguments[1]);
    if ($command === 'prepare') {
        v4plJson(v4plPrepare($run));
    }
    v4plJson(v4plCleanup($run));
} catch (Throwable $error) {
    v4plFail('unexpected');
}
