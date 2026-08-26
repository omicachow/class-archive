[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'status', 'provision', 'resume', 'finish', 'finalize-indexes')]
    [string]$Action = 'validate',

    [ValidateSet('qa', 'full')]
    [string]$Runtime = 'qa'
)

# Persistent, private-only Immich bridge provisioning for Phase 3 QA.  This
# runner intentionally has no reset/down/delete action.  On a partial failure
# it leaves the isolated databases fail-closed for inspection instead of
# silently destroying or weakening state.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runtimeConfig = if ($Runtime -eq 'full') {
    [ordered]@{
        private_relative = '.codex-work/private-real-full'
        piwigo_env_relative = 'infra/private-full/.env.piwigo.owner'
        immich_env_relative = 'infra/private-full/.env.immich.owner'
        piwigo_override = 'infra/private-full/docker-compose.override.yml'
        piwigo_worker_override = 'infra/private-full/docker-compose.ai-worker.override.yml'
        immich_override = 'infra/private-full/docker-compose.immich.override.yml'
        piwigo_project = 'class_archive_private_full_v3_piwigo'
        immich_project = 'class_archive_private_full_v3_immich'
        scope = 'PRIVATE_REAL_FULL'
        max_assets = 5000
        core_port = 8190
        compat_port = 8191
        report_name = 'private-full-immich-runtime.json'
        index_report_name = 'private-full-ai-index-runtime.json'
        technical_name = 'Class Archive Private Full Technical User'
        library_name = 'Class Archive Private Full Library'
    }
} else {
    [ordered]@{
        private_relative = '.codex-work/private-real-qa'
        piwigo_env_relative = 'infra/private-qa/.env.piwigo'
        immich_env_relative = 'infra/private-qa/.env.immich'
        piwigo_override = 'infra/private-qa/docker-compose.override.yml'
        piwigo_worker_override = $null
        immich_override = 'infra/private-qa/docker-compose.immich.override.yml'
        piwigo_project = 'class_archive_private_qa_piwigo'
        immich_project = 'class_archive_private_qa_immich'
        scope = 'PRIVATE_REAL_DATA_QA'
        max_assets = 500
        core_port = 8190
        compat_port = 8191
        report_name = 'private-immich-runtime.json'
        index_report_name = $null
        technical_name = 'Class Archive Private QA Technical User'
        library_name = 'Class Archive Private QA Library'
    }
}
$privateRoot = Join-Path $projectRoot ($runtimeConfig.private_relative -replace '/', '\')
$runtimeRoot = Join-Path $privateRoot 'runtime\immich'
$reportRoot = Join-Path $privateRoot 'reports'
$piwigoEnv = Join-Path $projectRoot ($runtimeConfig.piwigo_env_relative -replace '/', '\')
$immichEnv = Join-Path $projectRoot ($runtimeConfig.immich_env_relative -replace '/', '\')
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoOverride = [string]$runtimeConfig.piwigo_override
$immichCompose = 'infra/immich-spike/docker-compose.yml'
$immichOverride = [string]$runtimeConfig.immich_override
$piwigoProject = [string]$runtimeConfig.piwigo_project
$immichProject = [string]$runtimeConfig.immich_project
$runtimeScope = [string]$runtimeConfig.scope
$maxAssets = [int]$runtimeConfig.max_assets
$privateRelative = [string]$runtimeConfig.private_relative
$piwigoEnvRelative = [string]$runtimeConfig.piwigo_env_relative
$immichEnvRelative = [string]$runtimeConfig.immich_env_relative
$modelManifest = Join-Path $projectRoot 'infra\immich-spike\ml-artifacts\manifest.json'
$catalogScript = '/workspace/infra/scripts/private-qa-immich-catalog.php'
$runtimeScriptHost = 'infra/scripts/private-qa-immich-runtime.mjs'
$runtimeScriptContainer = '/tmp/class-archive-private-qa-immich-runtime.mjs'
$passwordResetScriptHost = 'infra/scripts/private-qa-immich-reset-admin.mjs'
$passwordResetScriptContainer = '/tmp/class-archive-private-qa-immich-reset-admin.mjs'
$runtimeInputContainer = '/tmp/class-archive-private-qa-immich-runtime-input.json'
$runtimeOutputContainer = '/tmp/class-archive-private-qa-immich-runtime-output.json'
$runtimeSummaryContainer = '/tmp/class-archive-private-qa-immich-runtime-summary.txt'
$runtimeBindingsContainer = '/tmp/class-archive-private-qa-immich-runtime-bindings.json'
$runtimeIndexEvidenceContainer = '/tmp/class-archive-private-qa-immich-runtime-index-evidence.json'
$passwordResetInputContainer = '/tmp/class-archive-private-qa-immich-password-reset-input.txt'
$passwordResetOutputContainer = '/tmp/class-archive-private-qa-immich-password-reset-output.txt'
$catalogContainer = '/tmp/class-archive-private-qa-immich-catalog.json'
$bindingContainer = '/tmp/class-archive-private-qa-immich-bindings.json'
$indexEvidenceContainer = '/tmp/class-archive-private-qa-immich-index-evidence.json'
$enableContainer = '/tmp/class-archive-private-qa-immich-enable.json'
$script:assertions = 0
$script:stage = 'initialization'

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Fail([string]$Code) {
    throw "PRIVATE_QA_IMMICH=FAIL stage=$script:stage code=$Code assertions=$script:assertions"
}

function Assert-Exact([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Fail $Code }
}

function New-SecretText {
    $bytes = New-Object byte[] 36
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Assert-IgnoredOwnerOnly([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Exact (-not $item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) ($Code + '_type')
    $relative = $item.FullName.Substring($projectRoot.TrimEnd('\').Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Exact ($LASTEXITCODE -eq 0) ($Code + '_not_ignored')
    Assert-Exact (@(& git -C $projectRoot ls-files -- $relative).Count -eq 0) ($Code + '_tracked')
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
}

function Write-OwnerOnlyJson([string]$Path, [object]$Value) {
    if (Test-Path -LiteralPath $Path) { Fail 'private_output_not_clean' }
    $directory = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        [void][IO.Directory]::CreateDirectory($directory)
    }
    $raw = $Value | ConvertTo-Json -Compress -Depth 8
    [IO.File]::WriteAllText($Path, $raw, [Text.UTF8Encoding]::new($false))
    $raw = $null
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-IgnoredOwnerOnly $Path 'private_json'
}

function Write-OwnerOnlyText([string]$Path, [string]$Value) {
    if (Test-Path -LiteralPath $Path) { Fail 'private_output_not_clean' }
    $directory = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        [void][IO.Directory]::CreateDirectory($directory)
    }
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-IgnoredOwnerOnly $Path 'private_text'
}

function Remove-PrivateFile([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = [IO.Path]::GetFullPath($runtimeRoot).TrimEnd('\') + '\'
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { Fail 'cleanup_path_invalid' }
    Remove-Item -LiteralPath $full -Force -ErrorAction Stop
    Assert-Exact (-not (Test-Path -LiteralPath $full)) 'cleanup_failed'
}

function Invoke-UbuntuDocker([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --exec docker @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Fail 'docker_command_failed' }
    return [string]::Join("`n", $lines)
}

function Invoke-PiwigoCompose([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $composeFiles = @('-f', $piwigoCompose, '-f', $piwigoOverride)
        if ($Runtime -eq 'full') {
            $composeFiles += @('-f', [string]$runtimeConfig.piwigo_worker_override)
        }
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $piwigoEnvRelative @composeFiles -p $piwigoProject @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) {
        $safe = [regex]::Match([string]::Join("`n", $lines), '(?m)^PRIVATE_QA_IMMICH_CATALOG=FAIL reason=([a-z0-9_.-]{1,96})$')
        if ($safe.Success) { Fail ('piwigo_' + $safe.Groups[1].Value) }
        Fail 'piwigo_compose_failed'
    }
    return [string]::Join("`n", $lines)
}

function Get-ModelContract {
    $script:stage = 'model_contract'
    Assert-Exact (Test-Path -LiteralPath $modelManifest -PathType Leaf) 'model_manifest_missing'
    # Windows PowerShell 5.1 otherwise decodes BOM-less UTF-8 as the current
    # ANSI code page.  The manifest deliberately carries Chinese descriptive
    # metadata, so an explicit UTF-8 decode is part of its integrity boundary.
    try { $manifest = Get-Content -LiteralPath $modelManifest -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Fail 'model_manifest_invalid' }
    Assert-Exact ($manifest.manifest_version -eq 1 -and $manifest.generated_for.immich_version -eq '3.1.0' `
        -and $manifest.generated_for.immich_commit -eq '8aa95c67470a02a8ddedf03c2e52963af33065ff') 'model_manifest_identity_invalid'
    $face = @($manifest.artifacts | Where-Object { [string]$_.relative_cache_path -like 'facial-recognition/*' -and $_.required -eq $true })
    $search = @($manifest.artifacts | Where-Object { [string]$_.relative_cache_path -like 'clip/*' -and $_.required -eq $true })
    $faceNames = @($face | ForEach-Object { [string]$_.model_name } | Sort-Object -Unique)
    $faceRevisions = @($face | ForEach-Object { [string]$_.exact_revision } | Sort-Object -Unique)
    $searchNames = @($search | ForEach-Object { [string]$_.model_name } | Sort-Object -Unique)
    $searchRevisions = @($search | ForEach-Object { [string]$_.exact_revision } | Sort-Object -Unique)
    Assert-Exact ($face.Count -ge 2 -and $search.Count -ge 4 -and $faceNames.Count -eq 1 -and $faceRevisions.Count -eq 1 `
        -and $searchNames.Count -eq 1 -and $searchRevisions.Count -eq 1) 'model_contract_ambiguous'
    foreach ($value in @($faceNames[0], $faceRevisions[0], $searchNames[0], $searchRevisions[0])) {
        Assert-Exact ($value -match '^[A-Za-z0-9._:@/-]{1,190}$') 'model_contract_value_invalid'
    }
    return [ordered]@{
        face_model_name = $faceNames[0]
        face_model_revision = $faceRevisions[0]
        search_model_name = $searchNames[0]
        search_model_revision = $searchRevisions[0]
    }
}

function Invoke-ImmichCompose([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    $lines = @()
    $code = -1
    $nativeError = ''
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $immichEnvRelative -f $immichCompose -f $immichOverride -p $immichProject @Arguments 2>&1)
        $code = $LASTEXITCODE
    } catch {
        # Windows PowerShell 5.1 can still surface a native stderr record as
        # an exception even while ErrorActionPreference is temporarily set to
        # Continue. Keep only its allowlisted machine marker; never return the
        # arbitrary native message or command output to the caller.
        $nativeError = [string]$_.Exception.Message
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) {
        $safeInput = [string]::Join("`n", @($lines | ForEach-Object { [string]$_ }) + @($nativeError))
        $safe = [regex]::Match($safeInput, '(?m)^PRIVATE_QA_IMMICH_RUNTIME=FAIL reason=([a-z0-9_.-]{1,96})$')
        if ($safe.Success) { Fail ('immich_' + $safe.Groups[1].Value) }
        Fail 'immich_compose_failed'
    }
    return [string]::Join("`n", @($lines | ForEach-Object { [string]$_ }))
}

function Read-DotEnvValue([string]$Path, [string]$Name, [string]$Fallback) {
    $matches = @([IO.File]::ReadAllLines($Path) | Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') })
    if ($matches.Count -gt 1) { Fail 'dotenv_duplicate' }
    if ($matches.Count -eq 0) { return $Fallback }
    $value = $matches[0].Substring($Name.Length + 1)
    if ($value -notmatch '^[A-Za-z0-9_.-]+$') { Fail 'dotenv_value_invalid' }
    return $value
}

function Get-ImmichCounts {
    $user = Read-DotEnvValue $immichEnv 'DB_USERNAME' 'postgres'
    $database = Read-DotEnvValue $immichEnv 'DB_DATABASE_NAME' 'immich'
    $sql = 'SELECT (SELECT count(*) FROM "user"),(SELECT count(*) FROM library),(SELECT count(*) FROM asset),(SELECT count(*) FROM memory);'
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($sql))
    $output = Invoke-ImmichCompose @('exec', '-T', 'database', 'sh', '-lc', ('echo ' + $encoded + ' | base64 -d | psql -U ' + $user + ' -d ' + $database + ' -At'))
    $match = [regex]::Match($output.Trim(), '^([0-9]+)\|([0-9]+)\|([0-9]+)\|([0-9]+)$')
    if (-not $match.Success) { Fail 'immich_counts_invalid' }
    return [ordered]@{ users = [int]$match.Groups[1].Value; libraries = [int]$match.Groups[2].Value; assets = [int]$match.Groups[3].Value; memories = [int]$match.Groups[4].Value }
}

function Get-ImmichAdminEmail {
    $user = Read-DotEnvValue $immichEnv 'DB_USERNAME' 'postgres'
    $database = Read-DotEnvValue $immichEnv 'DB_DATABASE_NAME' 'immich'
    if ($user -notmatch '^[A-Za-z0-9_]{1,63}$' -or $database -notmatch '^[A-Za-z0-9_]{1,63}$') {
        Fail 'immich_database_identity_invalid'
    }
    $sql = 'SELECT email FROM "user" WHERE "isAdmin" IS TRUE AND "deletedAt" IS NULL ORDER BY id;'
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($sql))
    $output = Invoke-ImmichCompose @('exec', '-T', 'database', 'sh', '-lc', ('echo ' + $encoded + ' | base64 -d | psql -U ' + $user + ' -d ' + $database + ' -At'))
    $rows = @($output -split "`r?`n" | Where-Object { $_ -ne '' })
    Assert-Exact ($rows.Count -eq 1 -and $rows[0] -match '^class-archive-private-[0-9a-f]{16}@private\.invalid$') 'immich_admin_identity_invalid'
    return [string]$rows[0]
}

function Reset-ImmichAdminPassword([string]$HostInput, [string]$Password) {
    $script:stage = 'resume_credential_file'
    Write-OwnerOnlyText $HostInput $Password
    $script:stage = 'resume_credential_copy'
    [void](Invoke-ImmichCompose @('cp', $HostInput.Substring($projectRoot.Length + 1).Replace('\', '/'), ('immich-server:' + $passwordResetInputContainer)))
    [void](Invoke-ImmichCompose @('cp', $passwordResetScriptHost, ('immich-server:' + $passwordResetScriptContainer)))
    $script:stage = 'resume_credential_permissions'
    [void](Invoke-ImmichCompose @('exec', '-T', '--user', '0:0', 'immich-server', 'sh', '-lc', ('chown 0:0 ' + $passwordResetInputContainer + ' ' + $passwordResetScriptContainer + ' && chmod 0600 ' + $passwordResetInputContainer + ' && chmod 0500 ' + $passwordResetScriptContainer)))
    $script:stage = 'resume_credential_rotation'
    $resetResult = (Invoke-ImmichCompose @('exec', '-T', '--user', '0:0', 'immich-server', 'node', $passwordResetScriptContainer)).Trim()
    if ($resetResult -ne 'RESET_PASS') {
        $safeCode = if ($resetResult -match '^RESET_[A-Z_]{1,40}$') { $resetResult.ToLowerInvariant() } else { 'reset_marker_invalid' }
        Fail $safeCode
    }
    $script:stage = 'resume_credential_cleanup'
    [void](Invoke-ImmichCompose @('exec', '-T', '--user', '0:0', 'immich-server', 'rm', '-f', '--', $passwordResetScriptContainer))
    Remove-PrivateFile $HostInput
}

function Assert-Container([string]$Name, [bool]$RequireHealth) {
    $status = (Invoke-UbuntuDocker @('inspect', $Name, '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{json .HostConfig.PortBindings}}')).Trim()
    $parts = $status -split '\|', 3
    Assert-Exact ($parts.Count -eq 3 -and $parts[0] -eq 'running' -and (!$RequireHealth -or $parts[1] -eq 'healthy')) 'container_unhealthy'
    if ($Name -like '*immich*' -and $Name -notlike '*web-compat*') {
        Assert-Exact ($parts[2] -eq 'null' -or $parts[2] -eq '{}') 'immich_host_port_forbidden'
    }
}

function Wait-ContainerHealthy([string]$Name, [int]$Seconds) {
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    while ([DateTime]::UtcNow -lt $deadline) {
        $previous = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $raw = @(& wsl.exe -d Ubuntu --exec docker inspect $Name --format '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' 2>&1)
            $code = $LASTEXITCODE
        } finally { $ErrorActionPreference = $previous }
        if ($code -eq 0 -and ([string]::Join("`n", $raw)).Trim() -match '^running\|(healthy|none)$') { return }
        Start-Sleep -Seconds 2
    }
    Fail 'container_health_timeout'
}

function Assert-RuntimeBoundary {
    $script:stage = 'boundary'
    foreach ($path in @($piwigoEnv, $immichEnv)) { Assert-IgnoredOwnerOnly $path 'env' }
    Assert-Exact (Test-Path -LiteralPath (Join-Path $projectRoot $runtimeScriptHost) -PathType Leaf) 'runtime_script_missing'
    Assert-Container ($piwigoProject + '-piwigo-1') $true
    Assert-Container ($piwigoProject + '-db-1') $true
    Assert-Container ($immichProject + '-immich-server-1') $true
    Assert-Container ($immichProject + '-immich-machine-learning-1') $true
    Assert-Container ($immichProject + '-database-1') $true
    Assert-Container ($immichProject + '-redis-1') $true
    $ports = Invoke-UbuntuDocker @('ps', '--format', '{{.Names}}|{{.Ports}}')
    $expectedPorts = '(?m)^' + [regex]::Escape($piwigoProject + '-piwigo-1') + '\|[^\r\n]*127\.0\.0\.1:' `
        + [string]$runtimeConfig.core_port + '->80/tcp[^\r\n]*127\.0\.0\.1:' + [string]$runtimeConfig.compat_port + '->8081/tcp'
    Assert-Exact ($ports -match $expectedPorts) 'loopback_ports_invalid'
    Assert-Exact ($ports -notmatch '(?m)^' + [regex]::Escape($immichProject) + '-[^|]+\|[^\r\n]*(?:0\.0\.0\.0|\[::\]|127\.0\.0\.1):') 'immich_port_published'
}

try {
    Assert-RuntimeBoundary
    if ($Action -eq 'validate') {
        Write-Output "PRIVATE_QA_IMMICH=PASS action=validate assertions=$script:assertions evidence=RUNTIME_BOUNDARY"
        exit 0
    }

    $counts = Get-ImmichCounts
    if ($Action -eq 'status') {
        $bridge = (Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'ps', '-a', '--format', 'json', 'immich-gateway')).Trim()
        $bridgeState = if ($bridge -eq '') { 'ABSENT' } elseif ($bridge -match 'running') { 'RUNNING' } else { 'PRESENT' }
        Write-Output ("PRIVATE_QA_IMMICH=PASS action=status users={0} libraries={1} assets={2} memories={3} bridge={4} assertions={5}" -f $counts.users, $counts.libraries, $counts.assets, $counts.memories, $bridgeState, $script:assertions)
        exit 0
    }

    $script:stage = 'state_precondition'
    if ($Action -eq 'provision') {
        Assert-Exact ($counts.users -eq 0 -and $counts.libraries -eq 0 -and $counts.assets -eq 0 -and $counts.memories -eq 0) 'immich_pristine_required'
    } else {
        Assert-Exact ($Action -in @('resume', 'finish', 'finalize-indexes') -and $counts.users -eq 1 -and $counts.libraries -eq 1 -and $counts.assets -ge 1 -and $counts.assets -le $maxAssets -and $counts.memories -eq 0) 'immich_resume_state_invalid'
        Assert-Exact ($Action -ne 'finalize-indexes' -or $Runtime -eq 'full') 'index_finalize_scope_invalid'
    }
    $immichTemporary = @(
        $runtimeScriptContainer, $runtimeInputContainer, $runtimeOutputContainer,
        $runtimeSummaryContainer, $runtimeBindingsContainer, $runtimeIndexEvidenceContainer,
        $passwordResetScriptContainer, $passwordResetInputContainer, $passwordResetOutputContainer
    )
    foreach ($path in @($immichTemporary + @($catalogContainer, $bindingContainer, $indexEvidenceContainer, $enableContainer))) {
        $service = if ($path -in $immichTemporary) { 'immich-server' } else { 'piwigo' }
        $probe = if ($service -eq 'immich-server') { Invoke-ImmichCompose @('exec', '-T', $service, 'sh', '-lc', ('test ! -e ' + $path + '; echo $?')) } else { Invoke-PiwigoCompose @('exec', '-T', $service, 'sh', '-lc', ('test ! -e ' + $path + '; echo $?')) }
        Assert-Exact ($probe.Trim() -eq '0') 'container_temporary_not_clean'
    }

    $run = ([Guid]::NewGuid().ToString('N')).Substring(0, 16)
    $work = Join-Path $runtimeRoot $run
    [void][IO.Directory]::CreateDirectory($work)
    $catalogHost = Join-Path $work 'catalog.json'
    $nodeInputHost = Join-Path $work 'runtime-input.json'
    $nodeOutputHost = Join-Path $work 'runtime-summary.txt'
    $bindingHost = Join-Path $work 'bindings.json'
    $indexEvidenceHost = Join-Path $work 'index-evidence.json'
    $enableHost = Join-Path $work 'enable.json'
    $bridgeHost = Join-Path $work 'bridge-secret.json'
    $passwordResetHost = Join-Path $work 'password-reset-input.txt'
    $sanitizedReport = Join-Path $reportRoot $(if ($Action -eq 'finalize-indexes') { [string]$runtimeConfig.index_report_name } else { [string]$runtimeConfig.report_name })
    $technicalPassword = $null
    $accessToken = $null
    $bridgeToken = $null

    try {
        $script:stage = 'catalog_export'
        $catalogAction = if ($Action -in @('finish', 'finalize-indexes')) { 'export-bound' } else { 'export' }
        $catalogResult = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, $catalogAction)
        Assert-Exact ($catalogResult -match ('^PRIVATE_QA_IMMICH_CATALOG=PASS action=' + [regex]::Escape($catalogAction) + ' count=([0-9]+)$')) 'catalog_export_failed'
        [void](Invoke-PiwigoCompose @('cp', ('piwigo:' + $catalogContainer), ($privateRelative + '/runtime/immich/' + $run + '/catalog.json')))
        Set-ClassArchiveOwnerOnlyFileAcl -Path $catalogHost
        Assert-IgnoredOwnerOnly $catalogHost 'catalog'
        $catalog = Get-Content -LiteralPath $catalogHost -Raw | ConvertFrom-Json -ErrorAction Stop
        Assert-Exact ($catalog.version -eq 1 -and $catalog.scope -eq $runtimeScope -and [int]$catalog.count -eq @($catalog.photos).Count -and [int]$catalog.count -le $maxAssets -and [string]$catalog.catalog_digest -match '^[0-9a-f]{64}$') 'catalog_shape_invalid'
        if ($Action -in @('resume', 'finish', 'finalize-indexes')) {
            Assert-Exact ($counts.assets -eq [int]$catalog.count) 'immich_resume_asset_count_mismatch'
        }

        $script:stage = 'runtime_input'
        $technicalPassword = New-SecretText
        $technicalEmail = if ($Action -in @('resume', 'finish', 'finalize-indexes')) { Get-ImmichAdminEmail } else { 'class-archive-private-' + $run + '@private.invalid' }
        if ($Action -in @('resume', 'finish', 'finalize-indexes')) {
            Reset-ImmichAdminPassword -HostInput $passwordResetHost -Password $technicalPassword
        }
        $modelContract = Get-ModelContract
        $nodeInput = [ordered]@{
            version = 1
            scope = $runtimeScope
            mode = if ($Action -in @('resume', 'finish', 'finalize-indexes')) { 'RESUME' } else { 'INITIAL' }
            catalog_digest = [string]$catalog.catalog_digest
            email = $technicalEmail
            password = $technicalPassword
            name = [string]$runtimeConfig.technical_name
            library_name = [string]$runtimeConfig.library_name
            models = $modelContract
            photos = @($catalog.photos)
        }
        Write-OwnerOnlyJson $nodeInputHost $nodeInput
        [void](Invoke-ImmichCompose @('cp', $runtimeScriptHost, ('immich-server:' + $runtimeScriptContainer)))
        [void](Invoke-ImmichCompose @('cp', ($privateRelative + '/runtime/immich/' + $run + '/runtime-input.json'), ('immich-server:' + $runtimeInputContainer)))
        # Immich itself runs as root in the pinned upstream container because
        # its external-library scanner must read 0660 Piwigo originals.  The
        # transient verifier uses that same container identity only to hash
        # the read-only mount before calling 127.0.0.1; it receives no host or
        # ClassIdentity database mount and leaves no executable behind.
        [void](Invoke-ImmichCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('chown 0:0 ' + $runtimeScriptContainer + ' ' + $runtimeInputContainer + ' && chmod 0500 ' + $runtimeScriptContainer + ' && chmod 0600 ' + $runtimeInputContainer)))

        $script:stage = 'ml_runtime_execute'
        # The runtime emits only an allowlisted PASS/FAIL marker. Redirect its
        # stderr inside the Linux container so Windows PowerShell 5.1 cannot
        # turn that marker into a NativeCommandError before the wrapper gets a
        # chance to validate and map the safe reason code.
        $runtimeCommand = 'exec node ' + $runtimeScriptContainer + ' --input-file ' + $runtimeInputContainer + ' 2>&1'
        $runtimeResult = Invoke-ImmichCompose @('exec', '-T', '--user', '0:0', 'immich-server', 'sh', '-lc', $runtimeCommand)
        $script:stage = 'ml_runtime_marker'
        Assert-Exact ($runtimeResult -match '^PRIVATE_QA_IMMICH_RUNTIME=PASS assets=([0-9]+) people=([0-9]+) face_jobs=([0-9]+) recognition_jobs=([0-9]+) smart_jobs=([0-9]+)$') 'runtime_failed'
        $script:stage = 'ml_runtime_output_copy'
        [void](Invoke-ImmichCompose @('cp', ('immich-server:' + $runtimeSummaryContainer), ($privateRelative + '/runtime/immich/' + $run + '/runtime-summary.txt')))
        [void](Invoke-ImmichCompose @('cp', ('immich-server:' + $runtimeBindingsContainer), ($privateRelative + '/runtime/immich/' + $run + '/bindings.json')))
        [void](Invoke-ImmichCompose @('cp', ('immich-server:' + $runtimeIndexEvidenceContainer), ($privateRelative + '/runtime/immich/' + $run + '/index-evidence.json')))
        $script:stage = 'ml_runtime_output_acl'
        foreach ($privateArtifact in @($nodeOutputHost, $bindingHost, $indexEvidenceHost)) {
            Set-ClassArchiveOwnerOnlyFileAcl -Path $privateArtifact
            Assert-IgnoredOwnerOnly $privateArtifact 'runtime_output'
        }
        $script:stage = 'ml_runtime_output_read'
        $runtimeReadStep = 'bytes'
        try {
            $runtimeBytes = [IO.File]::ReadAllBytes($nodeOutputHost)
            if ($runtimeBytes.Length -lt 16 -or $runtimeBytes.Length -gt 128KB) { Fail 'runtime_output_size_invalid' }
            $runtimeReadStep = 'utf8'
            $runtimeText = [Text.UTF8Encoding]::new($false, $true).GetString($runtimeBytes)
            $runtimeReadStep = 'line_endings'
            if (-not $runtimeText.EndsWith("`n") -or $runtimeText.Contains("`r") -or $runtimeText.Contains([char]0)) {
                Fail 'runtime_output_line_endings_invalid'
            }
            $runtimeReadStep = 'keys'
            $allowedRuntimeKeys = @(
                'VERSION', 'SCOPE', 'CATALOG_DIGEST', 'ACCESS_TOKEN', 'ASSET_COUNT', 'PEOPLE_COUNT',
                'RUNTIME_MODE', 'FACE_MODEL_NAME', 'FACE_MODEL_REVISION', 'SEARCH_MODEL_NAME', 'SEARCH_MODEL_REVISION',
                'FACE_QUEUE_IDLE', 'RECOGNITION_QUEUE_IDLE', 'SEARCH_QUEUE_IDLE',
                'FACE_JOBS', 'RECOGNITION_JOBS', 'SMART_JOBS', 'REUSED_EXISTING_INDEXES',
                'SEARCH_ZH_CLASSROOM', 'SEARCH_ZH_PLAYGROUND', 'SEARCH_ZH_GRADUATION', 'SEARCH_ZH_NIGHT',
                'SEARCH_EN_CLASSROOM', 'SEARCH_EN_PLAYGROUND', 'SEARCH_EN_GRADUATION', 'SEARCH_EN_NIGHT',
                'TIMING_MOUNTED_SHA256', 'TIMING_LIBRARY_SCAN', 'TIMING_FACE_DETECTION',
                'TIMING_FACE_RECOGNITION', 'TIMING_SMART_INDEX', 'TIMING_SMART_QUERIES', 'TIMING_TOTAL'
            )
            $runtimeFields = @{}
            $runtimeLines = $runtimeText.Substring(0, $runtimeText.Length - 1).Split("`n")
            if ($runtimeLines.Count -ne $allowedRuntimeKeys.Count) { Fail 'runtime_output_key_count_invalid' }
            foreach ($runtimeLine in $runtimeLines) {
                $match = [regex]::Match($runtimeLine, '^([A-Z][A-Z0-9_]*)=(.*)$')
                if (-not $match.Success) { Fail 'runtime_output_line_invalid' }
                $key = $match.Groups[1].Value
                if ($allowedRuntimeKeys -notcontains $key -or $runtimeFields.ContainsKey($key)) {
                    Fail 'runtime_output_key_invalid'
                }
                $runtimeFields[$key] = $match.Groups[2].Value
            }
            foreach ($key in $allowedRuntimeKeys) {
                if (-not $runtimeFields.ContainsKey($key)) { Fail 'runtime_output_key_missing' }
            }

            $runtimeReadStep = 'values'
            if ($runtimeFields.VERSION -ne '1' `
                -or $runtimeFields.SCOPE -ne $runtimeScope `
                -or $runtimeFields.CATALOG_DIGEST -notmatch '^[0-9a-f]{64}$' `
                -or $runtimeFields.ACCESS_TOKEN -notmatch '^[A-Za-z0-9._~-]{32,8192}$' `
                -or $runtimeFields.RUNTIME_MODE -notin @('INITIAL', 'RESUME')) {
                Fail 'runtime_output_value_invalid'
            }
            foreach ($key in @('FACE_MODEL_NAME', 'FACE_MODEL_REVISION', 'SEARCH_MODEL_NAME', 'SEARCH_MODEL_REVISION')) {
                if ($runtimeFields[$key] -notmatch '^[A-Za-z0-9._:@/-]{1,190}$') { Fail 'runtime_output_model_invalid' }
            }
            foreach ($key in @('FACE_QUEUE_IDLE', 'RECOGNITION_QUEUE_IDLE', 'SEARCH_QUEUE_IDLE', 'REUSED_EXISTING_INDEXES')) {
                if ($runtimeFields[$key] -notmatch '^[01]$') { Fail 'runtime_output_boolean_invalid' }
            }
            $numericRuntimeKeys = @(
                'ASSET_COUNT', 'PEOPLE_COUNT', 'FACE_JOBS', 'RECOGNITION_JOBS', 'SMART_JOBS',
                'SEARCH_ZH_CLASSROOM', 'SEARCH_ZH_PLAYGROUND', 'SEARCH_ZH_GRADUATION', 'SEARCH_ZH_NIGHT',
                'SEARCH_EN_CLASSROOM', 'SEARCH_EN_PLAYGROUND', 'SEARCH_EN_GRADUATION', 'SEARCH_EN_NIGHT',
                'TIMING_MOUNTED_SHA256', 'TIMING_LIBRARY_SCAN', 'TIMING_FACE_DETECTION',
                'TIMING_FACE_RECOGNITION', 'TIMING_SMART_INDEX', 'TIMING_SMART_QUERIES', 'TIMING_TOTAL'
            )
            foreach ($key in $numericRuntimeKeys) {
                if ($runtimeFields[$key] -notmatch '^(?:0|[1-9][0-9]{0,11})$') { Fail 'runtime_output_number_invalid' }
            }
            foreach ($key in @(
                'SEARCH_ZH_CLASSROOM', 'SEARCH_ZH_PLAYGROUND', 'SEARCH_ZH_GRADUATION', 'SEARCH_ZH_NIGHT',
                'SEARCH_EN_CLASSROOM', 'SEARCH_EN_PLAYGROUND', 'SEARCH_EN_GRADUATION', 'SEARCH_EN_NIGHT'
            )) {
                if ([long]::Parse($runtimeFields[$key], [Globalization.CultureInfo]::InvariantCulture) -lt 1 `
                    -or [long]::Parse($runtimeFields[$key], [Globalization.CultureInfo]::InvariantCulture) -gt 50) {
                    Fail 'runtime_output_search_count_invalid'
                }
            }

            $runtimeReadStep = 'number_projection'
            $runtimeNumbers = @{}
            foreach ($key in $numericRuntimeKeys) {
                $runtimeNumbers[$key] = [long]::Parse($runtimeFields[$key], [Globalization.CultureInfo]::InvariantCulture)
            }
            $runtimeReadStep = 'model_projection'
            $runtimeModels = [ordered]@{
                face_model_name = $runtimeFields.FACE_MODEL_NAME
                face_model_revision = $runtimeFields.FACE_MODEL_REVISION
                search_model_name = $runtimeFields.SEARCH_MODEL_NAME
                search_model_revision = $runtimeFields.SEARCH_MODEL_REVISION
            }
            $runtimeReadStep = 'queue_projection'
            $runtimeQueueIdle = [ordered]@{
                face_detection = $runtimeFields.FACE_QUEUE_IDLE -eq '1'
                facial_recognition = $runtimeFields.RECOGNITION_QUEUE_IDLE -eq '1'
                smart_search = $runtimeFields.SEARCH_QUEUE_IDLE -eq '1'
            }
            $runtimeReadStep = 'search_projection'
            $runtimeSearchCounts = [ordered]@{
                zh_classroom = $runtimeNumbers.SEARCH_ZH_CLASSROOM
                zh_playground = $runtimeNumbers.SEARCH_ZH_PLAYGROUND
                zh_graduation = $runtimeNumbers.SEARCH_ZH_GRADUATION
                zh_night = $runtimeNumbers.SEARCH_ZH_NIGHT
                en_classroom = $runtimeNumbers.SEARCH_EN_CLASSROOM
                en_playground = $runtimeNumbers.SEARCH_EN_PLAYGROUND
                en_graduation = $runtimeNumbers.SEARCH_EN_GRADUATION
                en_night = $runtimeNumbers.SEARCH_EN_NIGHT
            }
            $runtimeReadStep = 'timing_projection'
            $runtimeTimings = [ordered]@{
                mounted_sha256 = $runtimeNumbers.TIMING_MOUNTED_SHA256
                library_scan = $runtimeNumbers.TIMING_LIBRARY_SCAN
                face_detection = $runtimeNumbers.TIMING_FACE_DETECTION
                face_recognition = $runtimeNumbers.TIMING_FACE_RECOGNITION
                smart_index = $runtimeNumbers.TIMING_SMART_INDEX
                smart_queries = $runtimeNumbers.TIMING_SMART_QUERIES
                total = $runtimeNumbers.TIMING_TOTAL
            }
            $runtimeReadStep = 'metrics_projection'
            $runtimeMetrics = [ordered]@{
                asset_count = $runtimeNumbers.ASSET_COUNT
                people_count = $runtimeNumbers.PEOPLE_COUNT
                face_jobs = $runtimeNumbers.FACE_JOBS
                recognition_jobs = $runtimeNumbers.RECOGNITION_JOBS
                smart_jobs = $runtimeNumbers.SMART_JOBS
                reused_existing_indexes = $runtimeFields.REUSED_EXISTING_INDEXES -eq '1'
                search_counts = $runtimeSearchCounts
                timings_ms = $runtimeTimings
            }
            $runtimeReadStep = 'root_projection'
            # PowerShell variable names are case-insensitive. Do not call this
            # `$runtime`: that would overwrite the validated [string]$Runtime
            # parameter and fail (or corrupt the selected runtime scope).
            $runtimeEvidence = [ordered]@{
                version = 1
                scope = $runtimeFields.SCOPE
                catalog_digest = $runtimeFields.CATALOG_DIGEST
                access_token = $runtimeFields.ACCESS_TOKEN
                asset_count = $runtimeNumbers.ASSET_COUNT
                people_count = $runtimeNumbers.PEOPLE_COUNT
                index_evidence = [ordered]@{
                    runtime_mode = $runtimeFields.RUNTIME_MODE
                    models = $runtimeModels
                    queue_idle = $runtimeQueueIdle
                }
                metrics = $runtimeMetrics
            }
        } catch {
            if ([string]$_.Exception.Message -match '^PRIVATE_QA_IMMICH=FAIL ') { throw }
            Fail ('runtime_output_' + $runtimeReadStep + '_invalid')
        } finally {
            $runtimeBytes = $null
            $runtimeText = $null
            $runtimeLines = $null
            $runtimeLine = $null
            $allowedRuntimeKeys = $null
            $numericRuntimeKeys = $null
            $runtimeFields = $null
            $runtimeNumbers = $null
            $runtimeModels = $null
            $runtimeQueueIdle = $null
            $runtimeSearchCounts = $null
            $runtimeTimings = $null
            $runtimeMetrics = $null
            $match = $null
            $key = $null
            $runtimeReadStep = $null
        }
        $script:stage = 'ml_runtime_output_contract'
        Assert-Exact ($runtimeEvidence.version -eq 1 -and $runtimeEvidence.scope -eq $runtimeScope `
            -and [string]$runtimeEvidence.catalog_digest -eq [string]$catalog.catalog_digest `
            -and [int]$runtimeEvidence.asset_count -eq [int]$catalog.count `
            -and [int]$runtimeEvidence.people_count -ge 1) 'runtime_output_invalid'
        Assert-Exact ($runtimeEvidence.index_evidence.runtime_mode -in @('INITIAL', 'RESUME') `
            -and $runtimeEvidence.index_evidence.queue_idle.face_detection -eq $true `
            -and $runtimeEvidence.index_evidence.queue_idle.facial_recognition -eq $true `
            -and $runtimeEvidence.index_evidence.queue_idle.smart_search -eq $true `
            -and [int]$runtimeEvidence.metrics.people_count -ge 1) 'runtime_index_evidence_invalid'
        $script:stage = 'ml_runtime_access_token'
        $accessToken = [string]$runtimeEvidence.access_token
        Assert-Exact ($accessToken -match '^[A-Za-z0-9._~-]{32,8192}$') 'access_token_invalid'

        if ($Action -in @('provision', 'resume')) {
            $script:stage = 'canonical_bind'
            [void](Invoke-PiwigoCompose @('cp', ($privateRelative + '/runtime/immich/' + $run + '/bindings.json'), ('piwigo:' + $bindingContainer)))
            [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $bindingContainer + ' && chmod 0600 ' + $bindingContainer)))
            $bindResult = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'bind')
            Assert-Exact ($bindResult -match '^PRIVATE_QA_IMMICH_CATALOG=PASS action=bind count=([0-9]+)$') 'binding_failed'
        }

        if ($Runtime -eq 'full') {
            # The worker capability is intentionally absent during cold start
            # and all expensive runtime work. Enable it only after the direct
            # Immich runner has proved stable-idle Face/Search queues plus
            # non-empty persisted People and Search results.
            $script:stage = 'index_worker_enable_after_runtime'
            [void](Invoke-PiwigoCompose @('up', '-d', '--force-recreate', 'piwigo'))
            Wait-ContainerHealthy ($piwigoProject + '-piwigo-1') 300
            $script:stage = 'index_evidence'
            $models = $runtimeEvidence.index_evidence.models
            [void](Invoke-PiwigoCompose @('cp', ($privateRelative + '/runtime/immich/' + $run + '/index-evidence.json'), ('piwigo:' + $indexEvidenceContainer)))
            [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $indexEvidenceContainer + ' && chmod 0600 ' + $indexEvidenceContainer)))
            $script:stage = 'index_control_plane_complete'
            $indexResult = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'complete-indexes')
            Assert-Exact ($indexResult -match ('^PRIVATE_QA_IMMICH_CATALOG=PASS action=complete-indexes count=' + [regex]::Escape([string]$catalog.count) + ' completed=([0-9]+) state=READY$')) 'index_completion_failed'
        }

        if ($Action -ne 'finalize-indexes') {
        $script:stage = 'bridge_stager_start'
        $bridgeToken = New-SecretText
        Write-OwnerOnlyJson $bridgeHost ([ordered]@{ version = 1; bridge_token = $bridgeToken; immich_access_token = $accessToken })
        [void](Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'up', '-d', 'immich-gateway-secret-stager'))
        $script:stage = 'bridge_secret_clean'
        $empty = Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'exec', '-T', 'immich-gateway-secret-stager', 'sh', '-lc', 'find /run/secrets -mindepth 1 -maxdepth 1 -print -quit')
        Assert-Exact ([string]::IsNullOrWhiteSpace($empty)) 'bridge_secret_volume_not_clean'
        $script:stage = 'bridge_secret_copy'
        [void](Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'cp', ($privateRelative + '/runtime/immich/' + $run + '/bridge-secret.json'), 'immich-gateway-secret-stager:/run/secrets/bridge.json'))
        $script:stage = 'bridge_secret_mode'
        # The stager deliberately has CAP_CHOWN but not CAP_FOWNER. Set mode
        # while root still owns the copied inode, then transfer ownership.
        [void](Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'exec', '-T', 'immich-gateway-secret-stager', 'chmod', '0600', '/run/secrets/bridge.json'))
        $script:stage = 'bridge_secret_owner'
        [void](Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'exec', '-T', 'immich-gateway-secret-stager', 'chown', '65532:65532', '/run/secrets/bridge.json'))
        $script:stage = 'bridge_secret_verify'
        $secretMode = (Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'exec', '-T', 'immich-gateway-secret-stager', 'stat', '-c', '%a', '/run/secrets/bridge.json')).Trim()
        $secretOwner = (Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'exec', '-T', 'immich-gateway-secret-stager', 'stat', '-c', '%u', '/run/secrets/bridge.json')).Trim()
        $secretLinks = (Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'exec', '-T', 'immich-gateway-secret-stager', 'stat', '-c', '%h', '/run/secrets/bridge.json')).Trim()
        Assert-Exact ($secretMode -eq '600' -and $secretOwner -eq '65532' -and $secretLinks -eq '1') 'bridge_secret_permissions_invalid'
        $script:stage = 'gateway_start'
        [void](Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'up', '-d', 'immich-gateway'))
        Start-Sleep -Seconds 2
        $gatewayId = (Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'ps', '-q', 'immich-gateway')).Trim()
        Assert-Exact ($gatewayId -match '^[a-f0-9]{12,64}$') 'gateway_not_running'
        $gatewayState = (Invoke-UbuntuDocker @('inspect', $gatewayId, '--format', '{{.State.Status}}|{{json .HostConfig.PortBindings}}')).Trim()
        Assert-Exact ($gatewayState -eq 'running|null' -or $gatewayState -eq 'running|{}') 'gateway_exposure_invalid'

        $script:stage = 'bridge_enable'
        Write-OwnerOnlyJson $enableHost ([ordered]@{ version = 1; scope = $runtimeScope; catalog_digest = [string]$catalog.catalog_digest; token = $bridgeToken })
        [void](Invoke-PiwigoCompose @('cp', ($privateRelative + '/runtime/immich/' + $run + '/enable.json'), ('piwigo:' + $enableContainer)))
        [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $enableContainer + ' && chmod 0600 ' + $enableContainer)))
        $enableResult = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'enable')
        Assert-Exact ($enableResult -match '^PRIVATE_QA_IMMICH_CATALOG=PASS action=enable count=([0-9]+)$') 'bridge_enable_failed'

        $script:stage = 'bridge_probe'
        $probe = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'probe')
        Assert-Exact ($probe -match '^PRIVATE_QA_IMMICH_CATALOG=PASS action=probe count=([0-9]+) people=([0-9]+)$') 'bridge_probe_failed'
        } else {
            $script:stage = 'bridge_probe_existing'
            $probe = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'probe')
            Assert-Exact ($probe -match '^PRIVATE_QA_IMMICH_CATALOG=PASS action=probe count=([0-9]+) people=([0-9]+)$') 'bridge_probe_failed'
        }

        $script:stage = 'sanitized_report'
        if (Test-Path -LiteralPath $sanitizedReport) { Fail 'sanitized_report_already_exists' }
        Write-OwnerOnlyJson $sanitizedReport ([ordered]@{
            version = 1
            scope = $runtimeScope
            timestamp_utc = [DateTime]::UtcNow.ToString('o')
            catalog_count = [int]$catalog.count
            people_count = [int]$runtimeEvidence.metrics.people_count
            metrics = $runtimeEvidence.metrics
            ai_index_state = $(if ($Runtime -eq 'full') { 'READY' } else { 'EXTERNAL_QA_ONLY' })
            ai_models = $runtimeEvidence.index_evidence.models
            media_mount = 'PIWIGO_ORIGINALS_READ_ONLY'
            media_delivery = 'MEDIAGUARD_ONLY'
        })
        Write-Output ("PRIVATE_QA_IMMICH=PASS action={0} assets={1} people={2} assertions={3} evidence=RUNTIME_TESTED" -f $Action, [int]$catalog.count, [int]$runtimeEvidence.metrics.people_count, $script:assertions)
    } finally {
        $technicalPassword = $null
        $accessToken = $null
        $bridgeToken = $null
        $runtimeEvidence = $null
        try { [void](Invoke-ImmichCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('rm -f -- ' + $runtimeScriptContainer + ' ' + $runtimeInputContainer + ' ' + $runtimeOutputContainer + ' ' + $runtimeSummaryContainer + ' ' + $runtimeBindingsContainer + ' ' + $runtimeIndexEvidenceContainer + ' ' + $passwordResetScriptContainer + ' ' + $passwordResetInputContainer + ' ' + $passwordResetOutputContainer))) } catch { }
        try { [void](Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'sh', '-lc', ('rm -f -- ' + $catalogContainer + ' ' + $bindingContainer + ' ' + $indexEvidenceContainer + ' ' + $enableContainer))) } catch { }
        foreach ($path in @($nodeInputHost, $nodeOutputHost, $bindingHost, $indexEvidenceHost, $enableHost, $bridgeHost, $passwordResetHost)) {
            try { Remove-PrivateFile $path } catch { }
        }
        # The catalog contains only opaque private runtime references and is
        # retained under the ignored owner-only tree as an audit input.  It is
        # never copied to Git or public reports.
    }
} catch {
    $message = [string]$_.Exception.Message
    if ($message -match '^PRIVATE_QA_IMMICH=FAIL ') {
        [Console]::Error.WriteLine($message)
    } else {
        [Console]::Error.WriteLine("PRIVATE_QA_IMMICH=FAIL stage=$script:stage code=unexpected assertions=$script:assertions")
    }
    exit 1
}
