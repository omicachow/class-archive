[CmdletBinding()]
param(
    [switch]$IncludeDatabaseOutage
)

# Real HTTP regression coverage for authorization state transitions. The
# fixture driver changes only synthetic users and one synthetic image/category
# relation. Every explicit database mutation is restored from an InnoDB backup
# table in the outer finally block. The optional database outage never removes
# containers or volumes.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
$script:httpProbeCount = 0

function Read-DotEnv {
    param([Parameter(Mandatory = $true)][string]$Path)

    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { throw 'Invalid .env.piwigo syntax.' }
        $values[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
    }
    return $values
}

function Require-Setting {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Settings,
        [Parameter(Mandatory = $true)][string]$Key
    )

    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace($Settings[$Key])) {
        throw "Missing required local setting: $Key."
    }
    return [string]$Settings[$Key]
}

function New-TransientSecret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Invoke-WebService {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory = $true)][hashtable]$Body
    )

    return Invoke-RestMethod -Uri $Uri -Method Post -Body $Body -WebSession $Session -TimeoutSec 30
}

function New-AuthenticatedSession {
    param(
        [Parameter(Mandatory = $true)][Uri]$WebServiceUri,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password,
        [Parameter(Mandatory = $true)][string]$RoleLabel
    )

    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    try {
        $response = Invoke-WebService -Uri $WebServiceUri -Session $session -Body @{
            method = 'pwg.session.login'
            username = $Username
            password = $Password
        }
    }
    catch {
        throw "Synthetic login request failed for $RoleLabel."
    }
    if ($response.stat -ne 'ok' -or -not $response.result) {
        throw "Synthetic login was rejected for $RoleLabel."
    }
    return $session
}

function Invoke-LogoutBestEffort {
    param(
        [Parameter(Mandatory = $true)][Uri]$WebServiceUri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )

    try {
        Invoke-WebService -Uri $WebServiceUri -Session $Session -Body @{
            method = 'pwg.session.logout'
        } | Out-Null
    }
    catch {
        # Cleanup is best effort. The fixture password and all explicit table
        # mutations are restored independently by the database fixture driver.
    }
}

function Resolve-PreviewUri {
    param(
        [Parameter(Mandatory = $true)][Uri]$BaseUri,
        [Parameter(Mandatory = $true)][Uri]$WebServiceUri,
        [Parameter(Mandatory = $true)][int]$ImageId,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$AdminSession
    )

    try {
        $response = Invoke-WebService -Uri $WebServiceUri -Session $AdminSession -Body @{
            method = 'pwg.images.getInfo'
            image_id = $ImageId
        }
    }
    catch {
        throw 'Could not resolve the synthetic transition-test preview metadata.'
    }
    if ($response.stat -ne 'ok' -or $null -eq $response.result.derivatives) {
        throw 'Synthetic transition-test preview metadata was rejected.'
    }
    $reference = $null
    foreach ($size in @('medium', 'small', 'large', 'xsmall')) {
        $property = $response.result.derivatives.PSObject.Properties[$size]
        if ($null -ne $property -and $null -ne $property.Value) {
            $urlProperty = $property.Value.PSObject.Properties['url']
            if ($null -ne $urlProperty -and -not [string]::IsNullOrWhiteSpace([string]$urlProperty.Value)) {
                $reference = [string]$urlProperty.Value
                break
            }
        }
    }
    if ($null -eq $reference) {
        throw 'Synthetic transition-test metadata did not expose a preview.'
    }
    return [Uri]::new($BaseUri, $reference)
}

function Invoke-MediaProbe {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )

    $script:httpProbeCount++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = 'GET'
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 20000
    $request.ReadWriteTimeout = 20000
    $request.UserAgent = 'ClassArchive-MediaGuard-State-Transition/1.0'
    $request.AutomaticDecompression = [Net.DecompressionMethods]::GZip -bor [Net.DecompressionMethods]::Deflate
    $request.CachePolicy = [Net.Cache.RequestCachePolicy]::new([Net.Cache.RequestCacheLevel]::BypassCache)
    $request.Headers['Cache-Control'] = 'no-cache'

    $response = $null
    $transportFailure = $false
    try {
        $response = [Net.HttpWebResponse]$request.GetResponse()
    }
    catch [Net.WebException] {
        if ($null -ne $_.Exception.Response) {
            $response = [Net.HttpWebResponse]$_.Exception.Response
        }
        else {
            $transportFailure = $true
        }
    }

    if ($null -eq $response) {
        return [pscustomobject]@{
            Status = 0
            ContentType = ''
            Body = [byte[]]@()
            TransportFailure = $transportFailure
        }
    }

    try {
        $body = [IO.MemoryStream]::new()
        $stream = $response.GetResponseStream()
        try {
            if ($null -ne $stream) {
                $buffer = New-Object byte[] 8192
                while (($read = $stream.Read($buffer, 0, $buffer.Length)) -gt 0) {
                    $body.Write($buffer, 0, $read)
                    if ($body.Length -gt 10MB) {
                        throw 'Media transition probe exceeded the bounded response size.'
                    }
                }
            }
            return [pscustomobject]@{
                Status = [int]$response.StatusCode
                ContentType = [string]$response.ContentType
                Body = $body.ToArray()
                TransportFailure = $false
            }
        }
        finally {
            if ($null -ne $stream) { $stream.Dispose() }
            $body.Dispose()
        }
    }
    finally {
        $response.Dispose()
    }
}

function Test-ImageMagic {
    param([Parameter(Mandatory = $true)][byte[]]$Bytes)

    if ($Bytes.Length -ge 3 -and $Bytes[0] -eq 0xFF -and $Bytes[1] -eq 0xD8 -and $Bytes[2] -eq 0xFF) { return $true }
    if ($Bytes.Length -ge 8 -and ($Bytes[0..7] -join ',') -eq '137,80,78,71,13,10,26,10') { return $true }
    if ($Bytes.Length -ge 6) {
        $six = [Text.Encoding]::ASCII.GetString($Bytes, 0, 6)
        if ($six -in @('GIF87a', 'GIF89a')) { return $true }
    }
    if ($Bytes.Length -ge 12) {
        $riff = [Text.Encoding]::ASCII.GetString($Bytes, 0, 4)
        $webp = [Text.Encoding]::ASCII.GetString($Bytes, 8, 4)
        if ($riff -eq 'RIFF' -and $webp -eq 'WEBP') { return $true }
    }
    if ($Bytes.Length -ge 4) {
        if (($Bytes[0..3] -join ',') -in @('73,73,42,0', '77,77,0,42')) { return $true }
    }
    return $false
}

function Assert-AllowedMedia {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if ($Response.TransportFailure -or $Response.Status -ne 200) {
        throw "$Label did not return an authorized HTTP 200 image (status $($Response.Status))."
    }
    if ($Response.ContentType -notlike 'image/*' -or -not (Test-ImageMagic -Bytes $Response.Body)) {
        throw "$Label did not return verified image bytes."
    }
}

function Assert-DeniedMedia {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if ($Response.TransportFailure -or $Response.Status -ne 403) {
        throw "$Label did not fail closed with HTTP 403 (status $($Response.Status))."
    }
    if ($Response.ContentType -like 'image/*' -or (Test-ImageMagic -Bytes $Response.Body)) {
        throw "$Label returned media bytes while denied."
    }
}

function Assert-AmbiguousMediaDenied {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if ($Response.TransportFailure -or $Response.Status -ne 404) {
        throw "$Label did not fail closed as an ambiguous media binding (status $($Response.Status))."
    }
    if ($Response.ContentType -like 'image/*' -or (Test-ImageMagic -Bytes $Response.Body)) {
        throw "$Label returned media bytes from an ambiguous physical path."
    }
}

function Assert-DatabaseOutageDenied {
    param([Parameter(Mandatory = $true)]$Response)

    if ($Response.Status -in @(200, 206) -or $Response.ContentType -like 'image/*' -or (Test-ImageMagic -Bytes $Response.Body)) {
        $safeType = if ([string]::IsNullOrWhiteSpace([string]$Response.ContentType)) {
            'none'
        }
        else {
            ([string]$Response.ContentType -replace '[^A-Za-z0-9+./;-]', '_')
        }
        throw "Database outage served protected media bytes (status=$($Response.Status), type=$safeType, bytes=$($Response.Body.Length))."
    }
}

$fixturePhp = @'
<?php

function stateFail(string $message): never
{
    fwrite(STDERR, "STATE_FIXTURE_ERROR: {$message}\n");
    exit(1);
}

function stateScalar(string $sql): int
{
    $result = pwg_query($sql);
    $row = pwg_db_fetch_row($result);
    return (int) $row[0];
}

function stateTableExists(string $table): bool
{
    $escaped = pwg_db_real_escape_string($table);
    return pwg_db_num_rows(pwg_query("SHOW TABLES LIKE '{$escaped}'")) === 1;
}

function stateMeta(string $table, string $key): int
{
    $escaped = pwg_db_real_escape_string($key);
    $rows = query2array("SELECT meta_value FROM `{$table}` WHERE meta_key = '{$escaped}'");
    if (count($rows) !== 1) {
        throw new RuntimeException('fixture metadata missing');
    }
    return (int) $rows[0]['meta_value'];
}

function stateMetaExists(string $table, string $key): bool
{
    if (!stateTableExists($table)) {
        return false;
    }
    $escaped = pwg_db_real_escape_string($key);
    return stateScalar("SELECT COUNT(*) FROM `{$table}` WHERE meta_key = '{$escaped}'") === 1;
}

function stateBackedAccessCount(string $accessTable): int
{
    return stateTableExists($accessTable) ? stateScalar("SELECT COUNT(*) FROM `{$accessTable}`") : 0;
}

function statePresentBackedAccessCount(string $accessTable): int
{
    if (!stateTableExists($accessTable)) {
        return 0;
    }
    return stateScalar(
        "SELECT COUNT(*) FROM `{$accessTable}` backup "
        .'JOIN '.GROUP_ACCESS_TABLE.' active '
        .'ON active.group_id = backup.group_id AND active.cat_id = backup.cat_id'
    );
}

function stateActiveGroupAccessInScope(string $scopeTable, int $userId): int
{
    if (!stateTableExists($scopeTable)) {
        return 0;
    }
    return stateScalar(
        'SELECT COUNT(DISTINCT ga.group_id, ga.cat_id) FROM '.GROUP_ACCESS_TABLE.' ga '
        .'JOIN '.USER_GROUP_TABLE.' ug ON ug.group_id = ga.group_id '
        ."JOIN `{$scopeTable}` scope ON scope.cat_id = ga.cat_id "
        .'WHERE ug.user_id = '.$userId
    );
}

function stateRemoveDuplicatePathFixture(string $metaTable): void
{
    if (
        !stateTableExists($metaTable)
        || !stateMetaExists($metaTable, 'image_id')
        || !stateMetaExists($metaTable, 'duplicate_image_id')
    ) {
        return;
    }

    $sourceId = stateMeta($metaTable, 'image_id');
    $duplicateId = stateMeta($metaTable, 'duplicate_image_id');
    $duplicateRows = query2array('SELECT id, path, file, md5sum FROM '.IMAGES_TABLE.' WHERE id = '.$duplicateId);
    if (count($duplicateRows) === 0) {
        return;
    }
    $sourceRows = query2array('SELECT id, path, file, md5sum FROM '.IMAGES_TABLE.' WHERE id = '.$sourceId);
    if (
        count($duplicateRows) !== 1
        || count($sourceRows) !== 1
        || (string) $duplicateRows[0]['path'] !== (string) $sourceRows[0]['path']
        || (string) $duplicateRows[0]['file'] !== (string) $sourceRows[0]['file']
        || (string) ($duplicateRows[0]['md5sum'] ?? '') !== (string) ($sourceRows[0]['md5sum'] ?? '')
    ) {
        throw new RuntimeException('refusing to remove an unexpected duplicate-path row');
    }

    pwg_query('DELETE FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$duplicateId);
    pwg_query('DELETE FROM '.IMAGES_TABLE.' WHERE id = '.$duplicateId);
    if (
        stateScalar('SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$duplicateId) !== 0
        || stateScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE.' WHERE id = '.$duplicateId) !== 0
    ) {
        throw new RuntimeException('duplicate-path fixture removal was incomplete');
    }
}

function restoreFixtureState(string $usersTable, string $metaTable, string $accessTable, string $scopeTable): void
{
    stateRemoveDuplicatePathFixture($metaTable);

    if (stateTableExists($accessTable)) {
        foreach (query2array("SELECT group_id, cat_id FROM `{$accessTable}` ORDER BY group_id, cat_id") as $row) {
            $groupId = (int) $row['group_id'];
            $categoryId = (int) $row['cat_id'];
            if (stateScalar('SELECT COUNT(*) FROM '.GROUP_ACCESS_TABLE.' WHERE group_id = '.$groupId.' AND cat_id = '.$categoryId) === 0) {
                single_insert(GROUP_ACCESS_TABLE, ['group_id' => $groupId, 'cat_id' => $categoryId]);
            }
        }
        if (statePresentBackedAccessCount($accessTable) !== stateBackedAccessCount($accessTable)) {
            throw new RuntimeException('fixture group access restoration was incomplete');
        }
        invalidate_user_cache();
    }

    if (
        stateTableExists($metaTable)
        && stateMetaExists($metaTable, 'image_id')
        && stateMetaExists($metaTable, 'living_category_id')
        && stateMetaExists($metaTable, 'original_cross_count')
    ) {
        $imageId = stateMeta($metaTable, 'image_id');
        $livingCategoryId = stateMeta($metaTable, 'living_category_id');
        $originalCross = stateMeta($metaTable, 'original_cross_count');

        if ($originalCross === 0) {
            pwg_query('DELETE FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$imageId.' AND category_id = '.$livingCategoryId);
        }
    }

    if (stateTableExists($usersTable)) {
        foreach (query2array("SELECT username, user_id, password_hash FROM `{$usersTable}`") as $row) {
            $username = pwg_db_real_escape_string((string) $row['username']);
            $userId = (int) $row['user_id'];
            if (stateScalar('SELECT COUNT(*) FROM '.USERS_TABLE." WHERE id = {$userId} AND username = '{$username}'") !== 1) {
                throw new RuntimeException('fixture account changed during test');
            }
            single_update(USERS_TABLE, ['password' => (string) $row['password_hash']], ['id' => $userId]);
        }
    }

    if (stateTableExists($metaTable)) {
        pwg_query("DROP TABLE `{$metaTable}`");
    }
    if (stateTableExists($accessTable)) {
        pwg_query("DROP TABLE `{$accessTable}`");
    }
    if (stateTableExists($scopeTable)) {
        pwg_query("DROP TABLE `{$scopeTable}`");
    }
    if (stateTableExists($usersTable)) {
        pwg_query("DROP TABLE `{$usersTable}`");
    }
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    stateFail('refusing root execution');
}
$runId = (string) getenv('CLASS_ARCHIVE_STATE_RUN_ID');
$action = (string) getenv('CLASS_ARCHIVE_STATE_ACTION');
if (!preg_match('/\A[a-f0-9]{16}\z/D', $runId)) {
    stateFail('invalid run id');
}

chdir('/var/www/html/piwigo') || stateFail('cannot enter application root');
define('PHPWG_ROOT_PATH', './');
$_SERVER['SCRIPT_NAME'] = '/ws.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
require PHPWG_ROOT_PATH.'include/common.inc.php';
ob_end_clean();
require_once PHPWG_ROOT_PATH.'admin/include/functions.php';

global $prefixeTable, $conf;
$usersTable = $prefixeTable.'class_archive_state_users_'.$runId;
$metaTable = $prefixeTable.'class_archive_state_meta_'.$runId;
$accessTable = $prefixeTable.'class_archive_state_access_'.$runId;
$scopeTable = $prefixeTable.'class_archive_state_scope_'.$runId;

try {
    if ($action === 'prepare') {
        if (stateTableExists($usersTable) || stateTableExists($metaTable) || stateTableExists($accessTable) || stateTableExists($scopeTable)) {
            throw new RuntimeException('backup tables already exist');
        }
        pwg_query("CREATE TABLE `{$usersTable}` (
            username VARCHAR(100) BINARY NOT NULL,
            user_id MEDIUMINT UNSIGNED NOT NULL,
            password_hash VARCHAR(255) NULL,
            PRIMARY KEY (username), UNIQUE KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        pwg_query("CREATE TABLE `{$metaTable}` (
            meta_key VARCHAR(64) NOT NULL,
            meta_value BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (meta_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        pwg_query("CREATE TABLE `{$accessTable}` (
            group_id SMALLINT UNSIGNED NOT NULL,
            cat_id SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (group_id, cat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        pwg_query("CREATE TABLE `{$scopeTable}` (
            cat_id SMALLINT UNSIGNED NOT NULL,
            direct_association TINYINT(1) UNSIGNED NOT NULL,
            PRIMARY KEY (cat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $password = getenv('CLASS_ARCHIVE_STATE_PASSWORD');
        if (!is_string($password) || strlen($password) < 24) {
            throw new RuntimeException('transient password missing');
        }
        $expected = ['fixture-classmate' => 'CLASSMATE', 'fixture-family' => 'FAMILY'];
        $ids = [];
        foreach ($expected as $username => $role) {
            $userId = (int) get_userid($username);
            if ($userId <= 0) {
                throw new RuntimeException('required fixture account missing');
            }
            $escapedUsername = pwg_db_real_escape_string($username);
            $rows = query2array(
                'SELECT u.password, ui.status FROM '.USERS_TABLE.' u JOIN '.USER_INFOS_TABLE.' ui ON ui.user_id = u.id '
                ."WHERE u.id = {$userId} AND u.username = '{$escapedUsername}'"
            );
            $roles = query2array(
                'SELECT g.name FROM '.GROUPS_TABLE.' g JOIN '.USER_GROUP_TABLE.' ug ON ug.group_id = g.id '
                ."WHERE ug.user_id = {$userId} AND g.name IN ('CLASSMATE','TEACHER','FAMILY','ANONYMOUS')"
            );
            if (count($rows) !== 1 || (string) $rows[0]['status'] !== 'normal' || count($roles) !== 1 || (string) $roles[0]['name'] !== $role) {
                throw new RuntimeException('fixture account is not in its expected baseline role');
            }
            single_insert($usersTable, [
                'username' => $username,
                'user_id' => $userId,
                'password_hash' => (string) $rows[0]['password'],
            ]);
            single_update(USERS_TABLE, ['password' => $conf['password_hash']($password)], ['id' => $userId]);
            $ids[$username] = $userId;
        }

        $groupRows = query2array("SELECT id FROM ".GROUPS_TABLE." WHERE name = 'CLASSMATE'");
        if (count($groupRows) !== 1) {
            throw new RuntimeException('CLASSMATE group is ambiguous');
        }
        $classmateGroupId = (int) $groupRows[0]['id'];
        $candidateRows = query2array(
            'SELECT ic.image_id, COUNT(*) AS association_count, '
            ."SUM(CASE WHEN root.permalink = 'class-archive-heritage' THEN 1 ELSE 0 END) AS heritage_count "
            .'FROM '.IMAGE_CATEGORY_TABLE.' ic JOIN '.CATEGORIES_TABLE.' c ON c.id = ic.category_id '
            .'LEFT JOIN '.CATEGORIES_TABLE." root ON root.id = CAST(SUBSTRING_INDEX(c.uppercats, ',', 1) AS UNSIGNED) "
            .'GROUP BY ic.image_id HAVING association_count >= 2 AND heritage_count = association_count '
            .'ORDER BY ic.image_id LIMIT 1'
        );
        $livingRows = query2array("SELECT id FROM ".CATEGORIES_TABLE." WHERE permalink = 'fixture-living-reunion'");
        $heritageRows = query2array("SELECT id FROM ".CATEGORIES_TABLE." WHERE permalink = 'class-archive-heritage'");
        if (count($candidateRows) !== 1 || count($livingRows) !== 1 || count($heritageRows) !== 1) {
            throw new RuntimeException('required same-era fixture topology missing');
        }
        $imageId = (int) $candidateRows[0]['image_id'];
        $livingCategoryId = (int) $livingRows[0]['id'];
        $heritageCategoryId = (int) $heritageRows[0]['id'];
        $imageRows = query2array('SELECT id, path FROM '.IMAGES_TABLE.' WHERE id = '.$imageId);
        if (count($imageRows) !== 1) {
            throw new RuntimeException('duplicate-path source image is missing');
        }
        $sourcePath = preg_replace('#^\./#', '', (string) $imageRows[0]['path']);
        if (
            !is_string($sourcePath)
            || !preg_match('#\A(?:upload|galleries)/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z#D', $sourcePath)
            || str_contains($sourcePath, '..')
        ) {
            throw new RuntimeException('duplicate-path source path is unsafe');
        }
        $baselineImageCount = stateScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE);
        $baselineDistinctPathCount = stateScalar('SELECT COUNT(DISTINCT path) FROM '.IMAGES_TABLE);
        if ($baselineImageCount !== 72 || $baselineDistinctPathCount !== $baselineImageCount) {
            throw new RuntimeException('duplicate-path fixture requires the 72-image unique-path baseline');
        }
        $duplicateImageId = stateScalar('SELECT COALESCE(MAX(id), 0) + 1 FROM '.IMAGES_TABLE);
        if ($duplicateImageId <= $imageId || $duplicateImageId > 16777215) {
            throw new RuntimeException('no safe duplicate image id is available');
        }
        $originalCross = stateScalar('SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$imageId.' AND category_id = '.$livingCategoryId);
        if ($originalCross !== 0) {
            throw new RuntimeException('fixture mutation precondition failed');
        }

        // Build the hierarchy closure around every album directly associated
        // with the selected image. Piwigo's permission UI materializes grants
        // on ancestors and descendants, so removing only the Era root is not
        // a valid role-revocation fixture for a multi-album image.
        $associatedRows = query2array(
            'SELECT c.id, c.uppercats FROM '.IMAGE_CATEGORY_TABLE.' ic '
            .'JOIN '.CATEGORIES_TABLE.' c ON c.id = ic.category_id '
            .'WHERE ic.image_id = '.$imageId
        );
        $associatedIds = [];
        $scopeIds = [];
        foreach ($associatedRows as $row) {
            $categoryId = (int) $row['id'];
            $associatedIds[$categoryId] = true;
            foreach (explode(',', (string) $row['uppercats']) as $ancestorId) {
                $scopeIds[(int) $ancestorId] = true;
            }
        }
        if (count($associatedIds) < 2) {
            throw new RuntimeException('same-era fixture associations disappeared');
        }
        foreach (query2array('SELECT id, uppercats FROM '.CATEGORIES_TABLE) as $row) {
            $chain = array_map('intval', explode(',', (string) $row['uppercats']));
            if (count(array_intersect(array_keys($associatedIds), $chain)) > 0) {
                $scopeIds[(int) $row['id']] = true;
            }
        }
        ksort($scopeIds, SORT_NUMERIC);
        foreach (array_keys($scopeIds) as $categoryId) {
            single_insert($scopeTable, [
                'cat_id' => $categoryId,
                'direct_association' => isset($associatedIds[$categoryId]) ? 1 : 0,
            ]);
        }

        // Snapshot every explicit group grant held by the CLASSMATE fixture
        // account within that closure, not merely the canonical CLASSMATE
        // group's root grant. This also covers any future auxiliary group the
        // fixture may legitimately belong to.
        $permissionRows = query2array(
            'SELECT DISTINCT ga.group_id, ga.cat_id FROM '.GROUP_ACCESS_TABLE.' ga '
            .'JOIN '.USER_GROUP_TABLE.' ug ON ug.group_id = ga.group_id '
            ."JOIN `{$scopeTable}` scope ON scope.cat_id = ga.cat_id "
            .'WHERE ug.user_id = '.$ids['fixture-classmate'].' ORDER BY ga.group_id, ga.cat_id'
        );
        if (count($permissionRows) < 1) {
            throw new RuntimeException('fixture has no revocable group access rows');
        }
        foreach ($permissionRows as $row) {
            single_insert($accessTable, [
                'group_id' => (int) $row['group_id'],
                'cat_id' => (int) $row['cat_id'],
            ]);
        }
        if (
            stateActiveGroupAccessInScope($scopeTable, $ids['fixture-classmate']) !== count($permissionRows)
            || stateScalar(
                'SELECT COUNT(*) FROM '.USER_ACCESS_TABLE.' ua '
                ."JOIN `{$scopeTable}` scope ON scope.cat_id = ua.cat_id "
                .'WHERE ua.user_id = '.$ids['fixture-classmate']
            ) !== 0
        ) {
            throw new RuntimeException('fixture has an unsupported additional access path');
        }

        $meta = [
            'classmate_user_id' => $ids['fixture-classmate'],
            'classmate_group_id' => $classmateGroupId,
            'heritage_category_id' => $heritageCategoryId,
            'image_id' => $imageId,
            'living_category_id' => $livingCategoryId,
            'permission_backup_count' => count($permissionRows),
            'permission_scope_count' => count($scopeIds),
            'original_cross_count' => $originalCross,
            'same_era_association_count' => (int) $candidateRows[0]['association_count'],
            'baseline_image_count' => $baselineImageCount,
            'baseline_distinct_path_count' => $baselineDistinctPathCount,
            'duplicate_image_id' => $duplicateImageId,
        ];
        foreach ($meta as $key => $value) {
            single_insert($metaTable, ['meta_key' => $key, 'meta_value' => $value]);
        }
        echo json_encode([
            'imageId' => $imageId,
            'duplicateImageId' => $duplicateImageId,
            'sourcePath' => $sourcePath,
            'sameEraAssociationCount' => (int) $candidateRows[0]['association_count'],
        ], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }

    if (!stateTableExists($usersTable) || !stateTableExists($metaTable) || !stateTableExists($accessTable) || !stateTableExists($scopeTable)) {
        throw new RuntimeException('fixture backup is not active');
    }
    $classmateUserId = stateMeta($metaTable, 'classmate_user_id');
    $classmateGroupId = stateMeta($metaTable, 'classmate_group_id');
    $heritageCategoryId = stateMeta($metaTable, 'heritage_category_id');
    $imageId = stateMeta($metaTable, 'image_id');
    $livingCategoryId = stateMeta($metaTable, 'living_category_id');

    switch ($action) {
        case 'remove_permission':
            $backedAccessCount = stateMeta($metaTable, 'permission_backup_count');
            if (
                $backedAccessCount < 1
                || stateBackedAccessCount($accessTable) !== $backedAccessCount
                || statePresentBackedAccessCount($accessTable) !== $backedAccessCount
                || stateActiveGroupAccessInScope($scopeTable, $classmateUserId) !== $backedAccessCount
            ) {
                throw new RuntimeException('permission removal precondition failed');
            }
            foreach (query2array("SELECT group_id, cat_id FROM `{$accessTable}` ORDER BY group_id, cat_id") as $row) {
                pwg_query(
                    'DELETE FROM '.GROUP_ACCESS_TABLE
                    .' WHERE group_id = '.(int) $row['group_id']
                    .' AND cat_id = '.(int) $row['cat_id']
                );
            }
            invalidate_user_cache();
            if (
                statePresentBackedAccessCount($accessTable) !== 0
                || stateActiveGroupAccessInScope($scopeTable, $classmateUserId) !== 0
            ) {
                throw new RuntimeException('permission removal did not persist');
            }
            break;
        case 'restore_permission':
            foreach (query2array("SELECT group_id, cat_id FROM `{$accessTable}` ORDER BY group_id, cat_id") as $row) {
                $groupId = (int) $row['group_id'];
                $categoryId = (int) $row['cat_id'];
                if (stateScalar('SELECT COUNT(*) FROM '.GROUP_ACCESS_TABLE.' WHERE group_id = '.$groupId.' AND cat_id = '.$categoryId) === 0) {
                    single_insert(GROUP_ACCESS_TABLE, ['group_id' => $groupId, 'cat_id' => $categoryId]);
                }
            }
            invalidate_user_cache();
            if (
                statePresentBackedAccessCount($accessTable) !== stateMeta($metaTable, 'permission_backup_count')
                || stateActiveGroupAccessInScope($scopeTable, $classmateUserId) !== stateMeta($metaTable, 'permission_backup_count')
            ) {
                throw new RuntimeException('permission restoration did not persist');
            }
            break;
        case 'add_cross_era':
            if (stateScalar('SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$imageId.' AND category_id = '.$livingCategoryId) !== 0) {
                throw new RuntimeException('cross-era insertion precondition failed');
            }
            single_insert(IMAGE_CATEGORY_TABLE, ['image_id' => $imageId, 'category_id' => $livingCategoryId]);
            break;
        case 'remove_cross_era':
            pwg_query('DELETE FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$imageId.' AND category_id = '.$livingCategoryId);
            break;
        case 'add_duplicate_path':
            $duplicateImageId = stateMeta($metaTable, 'duplicate_image_id');
            $baselineImageCount = stateMeta($metaTable, 'baseline_image_count');
            $baselineDistinctPathCount = stateMeta($metaTable, 'baseline_distinct_path_count');
            if (
                stateScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE) !== $baselineImageCount
                || stateScalar('SELECT COUNT(DISTINCT path) FROM '.IMAGES_TABLE) !== $baselineDistinctPathCount
                || stateScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE.' WHERE id = '.$duplicateImageId) !== 0
                || stateScalar('SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$duplicateImageId) !== 0
            ) {
                throw new RuntimeException('duplicate-path insertion precondition failed');
            }

            $columns = [];
            foreach (query2array('SHOW COLUMNS FROM '.IMAGES_TABLE) as $column) {
                $field = (string) $column['Field'];
                if ($field !== 'id') {
                    $columns[] = '`'.str_replace('`', '``', $field).'`';
                }
            }
            if ($columns === []) {
                throw new RuntimeException('image schema could not be copied safely');
            }
            $columnList = implode(',', $columns);
            pwg_query(
                'INSERT INTO '.IMAGES_TABLE.' (`id`,'.$columnList.') '
                .'SELECT '.$duplicateImageId.','.$columnList.' FROM '.IMAGES_TABLE.' WHERE id = '.$imageId
            );
            single_insert(IMAGE_CATEGORY_TABLE, [
                'image_id' => $duplicateImageId,
                'category_id' => $livingCategoryId,
            ]);
            if (
                stateScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE) !== $baselineImageCount + 1
                || stateScalar('SELECT COUNT(DISTINCT path) FROM '.IMAGES_TABLE) !== $baselineDistinctPathCount
                || stateScalar(
                    'SELECT COUNT(*) FROM '.IMAGES_TABLE.' source JOIN '.IMAGES_TABLE.' duplicate '
                    .'ON duplicate.path = source.path WHERE source.id = '.$imageId.' AND duplicate.id = '.$duplicateImageId
                ) !== 1
                || stateScalar('SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$duplicateImageId.' AND category_id = '.$livingCategoryId) !== 1
            ) {
                throw new RuntimeException('duplicate-path fixture did not persist exactly');
            }
            break;
        case 'remove_duplicate_path':
            stateRemoveDuplicatePathFixture($metaTable);
            break;
        case 'verify_baseline':
            if (
                stateBackedAccessCount($accessTable) !== stateMeta($metaTable, 'permission_backup_count')
                || statePresentBackedAccessCount($accessTable) !== stateMeta($metaTable, 'permission_backup_count')
                || stateActiveGroupAccessInScope($scopeTable, $classmateUserId) !== stateMeta($metaTable, 'permission_backup_count')
                || stateScalar('SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$imageId.' AND category_id = '.$livingCategoryId) !== 0
                || stateMeta($metaTable, 'same_era_association_count') < 2
                || stateScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE) !== stateMeta($metaTable, 'baseline_image_count')
                || stateScalar('SELECT COUNT(DISTINCT path) FROM '.IMAGES_TABLE) !== stateMeta($metaTable, 'baseline_distinct_path_count')
                || stateScalar('SELECT COUNT(*) FROM '.IMAGES_TABLE.' WHERE id = '.stateMeta($metaTable, 'duplicate_image_id')) !== 0
                || stateScalar('SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.stateMeta($metaTable, 'duplicate_image_id')) !== 0
            ) {
                throw new RuntimeException('fixture baseline was not restored');
            }
            break;
        case 'restore_all':
            restoreFixtureState($usersTable, $metaTable, $accessTable, $scopeTable);
            break;
        default:
            throw new RuntimeException('unsupported fixture action');
    }
    echo "STATE_FIXTURE_OK\n";
} catch (Throwable $error) {
    if ($action === 'prepare') {
        try {
            restoreFixtureState($usersTable, $metaTable, $accessTable, $scopeTable);
        } catch (Throwable $ignored) {
        }
    }
    stateFail('action failed');
}
'@

function Invoke-StateFixture {
    param(
        [Parameter(Mandatory = $true)][string]$Action,
        [Parameter(Mandatory = $true)][string]$RunId,
        [Parameter(Mandatory = $true)][array]$ComposeBase,
        [string]$TransientPassword = ''
    )

    $arguments = $ComposeBase + @(
        'exec', '-T', '--user', 'nginx',
        '-e', "CLASS_ARCHIVE_STATE_ACTION=$Action",
        '-e', "CLASS_ARCHIVE_STATE_RUN_ID=$RunId"
    )
    if ($Action -eq 'prepare') {
        $arguments += @('-e', "CLASS_ARCHIVE_STATE_PASSWORD=$TransientPassword")
    }
    $arguments += @('piwigo', 'php', '-d', 'output_buffering=4096')

    $output = $fixturePhp | & wsl.exe @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Reversible fixture action failed: $Action."
    }
    $lines = @($output | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) })
    if ($Action -eq 'prepare') {
        if ($lines.Count -ne 1) { throw 'Fixture preparation returned an invalid result.' }
        return $lines[0] | ConvertFrom-Json
    }
    if ('STATE_FIXTURE_OK' -notin $lines) {
        throw "Reversible fixture action did not confirm completion: $Action."
    }
}

function Invoke-ComposeQuiet {
    param(
        [Parameter(Mandatory = $true)][array]$ComposeBase,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [Parameter(Mandatory = $true)][string]$FailureMessage
    )

    # Windows PowerShell 5.1 surfaces native stderr progress lines as
    # ErrorRecord objects. Capture them with a non-terminating preference so
    # Compose's normal "Container ... Running" messages cannot abort the
    # reversible outage fixture; the native exit code remains authoritative.
    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $nativeOutput = @(& wsl.exe @($ComposeBase + $Arguments) 2>&1)
        $nativeExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    $nativeOutput = $null
    if ($nativeExitCode -ne 0) { throw $FailureMessage }
}

if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing ignored .env.piwigo.'
}
$settings = Read-DotEnv -Path $envPath
$port = Require-Setting -Settings $settings -Key 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting -Settings $settings -Key 'PIWIGO_ADMIN_USERNAME'
$adminPassword = Require-Setting -Settings $settings -Key 'PIWIGO_ADMIN_PASSWORD'
if ($port -notmatch '^[0-9]{1,5}$' -or [int]$port -lt 1 -or [int]$port -gt 65535) {
    throw 'Invalid local HTTP port.'
}

$baseUri = [Uri]("http://127.0.0.1:$port/")
$webServiceUri = [Uri]::new($baseUri, 'ws.php?format=json')
$composeBase = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)
$runBytes = New-Object byte[] 8
$runGenerator = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $runGenerator.GetBytes($runBytes) } finally { $runGenerator.Dispose() }
$runId = [BitConverter]::ToString($runBytes).Replace('-', '').ToLowerInvariant()
$fixturePassword = New-TransientSecret

$prepared = $false
$databaseStopAttempted = $false
$sessions = [ordered]@{}
$failure = $null

try {
    $runningServices = @(& wsl.exe @($composeBase + @('ps', '--status', 'running', '--services')))
    if ($LASTEXITCODE -ne 0 -or 'db' -notin $runningServices -or 'piwigo' -notin $runningServices) {
        throw 'Piwigo and database services must already be running.'
    }

    $fixture = Invoke-StateFixture `
        -Action 'prepare' -RunId $runId -ComposeBase $composeBase `
        -TransientPassword $fixturePassword
    $prepared = $true
    if ([int]$fixture.sameEraAssociationCount -lt 2) {
        throw 'The selected image is not a same-Era multi-album fixture.'
    }

    $sessions.Admin = New-AuthenticatedSession `
        -WebServiceUri $webServiceUri -Username $adminUsername `
        -Password $adminPassword -RoleLabel 'Admin'
    $sessions.Classmate = New-AuthenticatedSession `
        -WebServiceUri $webServiceUri -Username 'fixture-classmate' `
        -Password $fixturePassword -RoleLabel 'Classmate'
    $sessions.Family = New-AuthenticatedSession `
        -WebServiceUri $webServiceUri -Username 'fixture-family' `
        -Password $fixturePassword -RoleLabel 'Family'

    $knownPreview = Resolve-PreviewUri `
        -BaseUri $baseUri -WebServiceUri $webServiceUri `
        -ImageId ([int]$fixture.imageId) `
        -AdminSession $sessions.Admin
    if (($knownPreview.Scheme -ne $baseUri.Scheme) -or
        ($knownPreview.Host -ne $baseUri.Host) -or
        ($knownPreview.Port -ne $baseUri.Port) -or
        ($knownPreview.AbsolutePath -ne '/i.php' -and -not $knownPreview.AbsolutePath.StartsWith('/_data/i/'))) {
        $endpointClass = switch -Regex ($knownPreview.AbsolutePath) {
            '^/upload/' { 'source-upload'; break }
            '^/galleries/' { 'source-gallery'; break }
            '^/themes/' { 'theme-static'; break }
            '^/plugins/' { 'plugin-static'; break }
            '^/picture\.php$' { 'picture-controller'; break }
            default { 'other'; break }
        }
        throw "Resolved preview does not use an expected preview endpoint ($endpointClass)."
    }

    $sourcePath = [string]$fixture.sourcePath
    if ($sourcePath -notmatch '^(?:upload|galleries)/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$' -or
        $sourcePath -match '(?:^|/)\.\.(?:/|$)') {
        throw 'The duplicate-path fixture returned an unsafe storage path.'
    }
    $rawSource = [Uri]::new($baseUri, $sourcePath)
    $originalAction = [Uri]::new($baseUri, "action.php?id=$([int]$fixture.imageId)&part=e")
    $duplicateAction = [Uri]::new($baseUri, "action.php?id=$([int]$fixture.duplicateImageId)&part=e")
    $originalRepresentativeAction = [Uri]::new($baseUri, "action.php?id=$([int]$fixture.imageId)&part=r")
    $duplicateRepresentativeAction = [Uri]::new($baseUri, "action.php?id=$([int]$fixture.duplicateImageId)&part=r")

    # A same-Era multi-album image remains readable under Piwigo's union
    # semantics. These sessions and this exact URL are retained for every
    # subsequent transition probe.
    foreach ($roleName in @('Family', 'Classmate', 'Admin')) {
        $response = Invoke-MediaProbe -Uri $knownPreview -Session $sessions[$roleName]
        Assert-AllowedMedia -Response $response -Label "same-era union/$roleName"
    }

    try {
        Invoke-StateFixture -Action 'remove_permission' -RunId $runId -ComposeBase $composeBase
        $revoked = Invoke-MediaProbe -Uri $knownPreview -Session $sessions.Classmate
        Assert-DeniedMedia -Response $revoked -Label 'old session after CLASSMATE permission removal'
    }
    finally {
        if ($prepared) {
            Invoke-StateFixture -Action 'restore_permission' -RunId $runId -ComposeBase $composeBase
        }
    }
    $permissionRestored = Invoke-MediaProbe -Uri $knownPreview -Session $sessions.Classmate
    Assert-AllowedMedia -Response $permissionRestored -Label 'old session after CLASSMATE permission restoration'

    try {
        Invoke-StateFixture -Action 'add_cross_era' -RunId $runId -ComposeBase $composeBase
        foreach ($roleName in @('Family', 'Classmate', 'Admin')) {
            $response = Invoke-MediaProbe -Uri $knownPreview -Session $sessions[$roleName]
            Assert-DeniedMedia -Response $response -Label "cross-era conflict/$roleName"
        }
    }
    finally {
        if ($prepared) {
            Invoke-StateFixture -Action 'remove_cross_era' -RunId $runId -ComposeBase $composeBase
        }
    }
    Invoke-StateFixture -Action 'verify_baseline' -RunId $runId -ComposeBase $composeBase
    foreach ($roleName in @('Family', 'Classmate', 'Admin')) {
        $response = Invoke-MediaProbe -Uri $knownPreview -Session $sessions[$roleName]
        Assert-AllowedMedia -Response $response -Label "cross-era restoration/$roleName"
    }


    # Piwigo images.path is not unique. A second row can therefore reference
    # the same physical original while carrying a different Era association.
    # The URL and either image id must all become unusable for every actor,
    # including Admin, until that ambiguous binding is removed.
    try {
        Invoke-StateFixture -Action 'add_duplicate_path' -RunId $runId -ComposeBase $composeBase
        $ambiguousEndpoints = [ordered]@{
            'raw-source' = $rawSource
            'known-derivative' = $knownPreview
            'source-action-id' = $originalAction
            'duplicate-action-id' = $duplicateAction
            'source-representative-action-id' = $originalRepresentativeAction
            'duplicate-representative-action-id' = $duplicateRepresentativeAction
        }
        foreach ($roleName in @('Family', 'Classmate', 'Admin')) {
            foreach ($endpoint in $ambiguousEndpoints.GetEnumerator()) {
                $response = Invoke-MediaProbe -Uri $endpoint.Value -Session $sessions[$roleName]
                Assert-AmbiguousMediaDenied -Response $response -Label "duplicate-path/$roleName/$($endpoint.Key)"
            }
        }
    }
    finally {
        if ($prepared) {
            Invoke-StateFixture -Action 'remove_duplicate_path' -RunId $runId -ComposeBase $composeBase
        }
    }

    Invoke-StateFixture -Action 'verify_baseline' -RunId $runId -ComposeBase $composeBase
    foreach ($roleName in @('Family', 'Classmate', 'Admin')) {
        $response = Invoke-MediaProbe -Uri $originalAction -Session $sessions[$roleName]
        Assert-AllowedMedia -Response $response -Label "duplicate-path restoration/action/$roleName"
    }
    foreach ($roleName in @('Classmate', 'Admin')) {
        $response = Invoke-MediaProbe -Uri $rawSource -Session $sessions[$roleName]
        Assert-AllowedMedia -Response $response -Label "duplicate-path restoration/source/$roleName"
    }
    $familyOriginal = Invoke-MediaProbe -Uri $rawSource -Session $sessions.Family
    Assert-DeniedMedia -Response $familyOriginal -Label 'duplicate-path restoration/source/Family policy'
    foreach ($roleName in @('Family', 'Classmate', 'Admin')) {
        $removedId = Invoke-MediaProbe -Uri $duplicateAction -Session $sessions[$roleName]
        Assert-AmbiguousMediaDenied -Response $removedId -Label "duplicate-path restoration/removed-id/$roleName"
    }

    if ($IncludeDatabaseOutage) {
        Invoke-ComposeQuiet `
            -ComposeBase $composeBase `
            -Arguments @('up', '-d', '--wait', '--wait-timeout', '120', 'db', 'piwigo') `
            -FailureMessage 'Could not establish a healthy pre-outage runtime.'
        $databaseStopAttempted = $true
        Invoke-ComposeQuiet `
            -ComposeBase $composeBase -Arguments @('stop', '--timeout', '30', 'db') `
            -FailureMessage 'Could not stop the database for the opt-in outage probe.'

        $outageResponse = Invoke-MediaProbe -Uri $knownPreview -Session $sessions.Admin
        Assert-DatabaseOutageDenied -Response $outageResponse

        Invoke-ComposeQuiet `
            -ComposeBase $composeBase `
            -Arguments @('up', '-d', '--wait', '--wait-timeout', '180', 'db', 'piwigo') `
            -FailureMessage 'Database/application health did not recover after the outage probe.'
        $databaseStopAttempted = $false
        $recovered = Invoke-MediaProbe -Uri $knownPreview -Session $sessions.Admin
        Assert-AllowedMedia -Response $recovered -Label 'post-outage recovery'
    }
}
catch {
    $failure = $_.Exception.Message
}
finally {
    if ($databaseStopAttempted) {
        try {
            Invoke-ComposeQuiet `
                -ComposeBase $composeBase `
                -Arguments @('up', '-d', '--wait', '--wait-timeout', '180', 'db', 'piwigo') `
                -FailureMessage 'Database/application health recovery failed during cleanup.'
            $databaseStopAttempted = $false
        }
        catch {
            if ($null -eq $failure) { $failure = $_.Exception.Message }
        }
    }

    if ($prepared) {
        try {
            Invoke-StateFixture -Action 'restore_all' -RunId $runId -ComposeBase $composeBase
            $prepared = $false
        }
        catch {
            if ($null -eq $failure) { $failure = $_.Exception.Message }
        }
    }

    foreach ($session in $sessions.Values) {
        if ($null -ne $session) {
            Invoke-LogoutBestEffort -WebServiceUri $webServiceUri -Session $session
        }
    }
    $fixturePassword = $null
    $adminPassword = $null
}

if ($null -ne $failure) {
    [Console]::Error.WriteLine("MEDIA_GUARD_STATE_TRANSITIONS=FAIL $failure")
    exit 1
}

Write-Output 'MEDIA_GUARD_STATE_TRANSITIONS=PASS'
Write-Output "HTTP_PROBES=$script:httpProbeCount"
Write-Output 'KNOWN_URL_ROLE_REVOCATION=PASS'
Write-Output 'CROSS_ERA_FAIL_CLOSED=PASS'
Write-Output 'SAME_ERA_MULTI_ALBUM_UNION=PASS'
Write-Output 'DUPLICATE_PHYSICAL_PATH_FAIL_CLOSED=PASS'
Write-Output 'IMAGE_MODEL_RESTORED=72'
Write-Output $(if ($IncludeDatabaseOutage) { 'DATABASE_OUTAGE_FAIL_CLOSED=PASS' } else { 'DATABASE_OUTAGE_FAIL_CLOSED=SKIPPED_OPT_IN' })
