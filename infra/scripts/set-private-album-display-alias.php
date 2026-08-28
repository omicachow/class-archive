<?php

declare(strict_types=1);

const CLASS_ARCHIVE_ALIAS_ROOT = '/var/www/html/piwigo';

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('private_album_alias_cli_required');
    }

    $sourceCode = null;
    $folderName = null;
    $displayAlias = null;
    $reason = null;
    $verifyOnly = false;
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if ($argument === '--verify-only') {
            $verifyOnly = true;
        } elseif (str_starts_with($argument, '--source-code=')) {
            $sourceCode = substr($argument, strlen('--source-code='));
        } elseif (str_starts_with($argument, '--folder-name=')) {
            $folderName = substr($argument, strlen('--folder-name='));
        } elseif (str_starts_with($argument, '--alias=')) {
            $displayAlias = substr($argument, strlen('--alias='));
        } elseif (str_starts_with($argument, '--reason=')) {
            $reason = substr($argument, strlen('--reason='));
        } else {
            throw new InvalidArgumentException('private_album_alias_argument_invalid');
        }
    }
    if (!in_array($sourceCode, ['PRIVATE_SOURCE_A', 'PRIVATE_SOURCE_B'], true)) {
        throw new InvalidArgumentException('private_album_alias_source_code_invalid');
    }
    if (!is_string($displayAlias) || trim($displayAlias) === '' || strlen($displayAlias) > 570
        || preg_match('//u', $displayAlias) !== 1 || str_contains($displayAlias, "\0")
    ) {
        throw new InvalidArgumentException('private_album_alias_value_invalid');
    }
    $displayAlias = trim($displayAlias);
    if ($folderName !== null
        && (!is_string($folderName) || trim($folderName) === '' || strlen($folderName) > 570
            || preg_match('//u', $folderName) !== 1 || str_contains($folderName, "\0"))
    ) {
        throw new InvalidArgumentException('private_album_alias_folder_name_invalid');
    }
    $folderName = $folderName === null ? null : trim($folderName);
    if (!$verifyOnly && (!is_string($reason) || trim($reason) === '')) {
        throw new InvalidArgumentException('private_album_alias_reason_required');
    }

    if (realpath(CLASS_ARCHIVE_ALIAS_ROOT) !== CLASS_ARCHIVE_ALIAS_ROOT
        || is_link(CLASS_ARCHIVE_ALIAS_ROOT)
        || !is_file(CLASS_ARCHIVE_ALIAS_ROOT . '/local/config/database.inc.php')
    ) {
        throw new RuntimeException('private_album_alias_root_untrusted');
    }
    chdir(CLASS_ARCHIVE_ALIAS_ROOT) || throw new RuntimeException('private_album_alias_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';

    $repository = \ClassIdentity\Repository::fromPiwigo();
    $collection = $repository->table('private_library_collection');
    $folder = $repository->table('private_library_folder');
    $album = $repository->table('album');
    $principal = $repository->table('principal');
    $mappingSql = 'SELECT f.`class_album_id`,a.`display_alias` '
            . 'FROM `' . $collection . '` c '
            . 'INNER JOIN `' . $folder . '` f ON f.`source_collection_id`=c.`source_collection_id` '
            . 'INNER JOIN `' . $album . '` a ON a.`class_album_id`=f.`class_album_id` '
            . 'WHERE c.`source_code`=? AND c.`state`=\'ACTIVE\' AND a.`state`=\'ACTIVE\'';
    if ($folderName === null) {
        $mappingSql .= ' AND f.`depth`=0 AND f.`parent_folder_id` IS NULL';
        $mappingParameters = [$sourceCode];
    } else {
        // An operator-visible source segment may select a non-root leaf, but
        // the full relative path and its digest never cross this CLI output.
        $mappingSql .= ' AND f.`display_name`=?';
        $mappingParameters = [$sourceCode, $folderName];
    }
    $rows = $repository->fetchAll($mappingSql, $mappingParameters);
    if (count($rows) !== 1 || !is_string($rows[0]['class_album_id'] ?? null)) {
        throw new RuntimeException('private_album_alias_root_mapping_invalid');
    }
    $classAlbumId = \ClassIdentity\DomainSupport::binaryToId((string) $rows[0]['class_album_id']);
    $currentAlias = $rows[0]['display_alias'] ?? null;
    if ($currentAlias !== null && !is_string($currentAlias)) {
        throw new RuntimeException('private_album_alias_state_invalid');
    }
    if ($verifyOnly) {
        if (!is_string($currentAlias) || !hash_equals($displayAlias, $currentAlias)) {
            throw new RuntimeException('private_album_alias_not_applied');
        }
        fwrite(STDOUT, 'PRIVATE_ALBUM_DISPLAY_ALIAS=PASS SOURCE=' . $sourceCode . " MODE=VERIFY\n");
        exit(0);
    }

    $admins = $repository->fetchAll(
        'SELECT `piwigo_user_id` FROM `' . $principal . '` '
            . 'WHERE `principal_type`=\'SYSTEM_ACCOUNT\' AND `system_role`=\'SYSTEM_ADMIN\' '
            . 'AND `account_id` IS NULL AND `state`=\'ACTIVE\' ORDER BY `id` ASC',
    );
    if (count($admins) !== 1 || (int) ($admins[0]['piwigo_user_id'] ?? 0) <= 0) {
        throw new RuntimeException('private_album_alias_admin_ambiguous');
    }
    (\ClassIdentity\AlbumService::fromPiwigo())->setDisplayAlias(
        (int) $admins[0]['piwigo_user_id'],
        $classAlbumId,
        $displayAlias,
        trim((string) $reason),
    );
    fwrite(STDOUT, 'PRIVATE_ALBUM_DISPLAY_ALIAS=PASS SOURCE=' . $sourceCode . " MODE=APPLY\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'PRIVATE_ALBUM_DISPLAY_ALIAS=FAIL code='
        . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
