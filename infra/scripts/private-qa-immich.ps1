[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'status', 'provision', 'resume', 'finish')]
    [string]$Action = 'validate'
)

# Persistent, private-only Immich bridge provisioning for Phase 3 QA.  This
# runner intentionally has no reset/down/delete action.  On a partial failure
# it leaves the isolated databases fail-closed for inspection instead of
# silently destroying or weakening state.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRoot = Join-Path $projectRoot '.codex-work\private-real-qa'
$runtimeRoot = Join-Path $privateRoot 'runtime\immich'
$reportRoot = Join-Path $privateRoot 'reports'
$piwigoEnv = Join-Path $projectRoot 'infra\private-qa\.env.piwigo'
$immichEnv = Join-Path $projectRoot 'infra\private-qa\.env.immich'
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoOverride = 'infra/private-qa/docker-compose.override.yml'
$immichCompose = 'infra/immich-spike/docker-compose.yml'
$immichOverride = 'infra/private-qa/docker-compose.immich.override.yml'
$piwigoProject = 'class_archive_private_qa_piwigo'
$immichProject = 'class_archive_private_qa_immich'
$catalogScript = '/workspace/infra/scripts/private-qa-immich-catalog.php'
$runtimeScriptHost = 'infra/scripts/private-qa-immich-runtime.mjs'
$runtimeScriptContainer = '/tmp/class-archive-private-qa-immich-runtime.mjs'
$passwordResetScriptHost = 'infra/scripts/private-qa-immich-reset-admin.mjs'
$passwordResetScriptContainer = '/tmp/class-archive-private-qa-immich-reset-admin.mjs'
$runtimeInputContainer = '/tmp/class-archive-private-qa-immich-runtime-input.json'
$runtimeOutputContainer = '/tmp/class-archive-private-qa-immich-runtime-output.json'
$passwordResetInputContainer = '/tmp/class-archive-private-qa-immich-password-reset-input.txt'
$passwordResetOutputContainer = '/tmp/class-archive-private-qa-immich-password-reset-output.txt'
$catalogContainer = '/tmp/class-archive-private-qa-immich-catalog.json'
$bindingContainer = '/tmp/class-archive-private-qa-immich-bindings.json'
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
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file 'infra/private-qa/.env.piwigo' -f $piwigoCompose -f $piwigoOverride -p $piwigoProject @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) {
        $safe = [regex]::Match([string]::Join("`n", $lines), '(?m)^PRIVATE_QA_IMMICH_CATALOG=FAIL reason=([a-z0-9_.-]{1,96})$')
        if ($safe.Success) { Fail ('piwigo_' + $safe.Groups[1].Value) }
        Fail 'piwigo_compose_failed'
    }
    return [string]::Join("`n", $lines)
}

function Invoke-ImmichCompose([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file 'infra/private-qa/.env.immich' -f $immichCompose -f $immichOverride -p $immichProject @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) {
        $safe = [regex]::Match([string]::Join("`n", $lines), '(?m)^PRIVATE_QA_IMMICH_RUNTIME=FAIL reason=([a-z0-9_.-]{1,96})$')
        if ($safe.Success) { Fail ('immich_' + $safe.Groups[1].Value) }
        Fail 'immich_compose_failed'
    }
    return [string]::Join("`n", $lines)
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
    Assert-Exact ($ports -match '(?m)^' + [regex]::Escape($piwigoProject + '-piwigo-1') + '\|[^\r\n]*127\.0\.0\.1:8190->80/tcp[^\r\n]*127\.0\.0\.1:8191->8081/tcp') 'loopback_ports_invalid'
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
        Assert-Exact ($Action -in @('resume', 'finish') -and $counts.users -eq 1 -and $counts.libraries -eq 1 -and $counts.assets -ge 1 -and $counts.assets -le 500 -and $counts.memories -eq 0) 'immich_resume_state_invalid'
    }
    $immichTemporary = @($runtimeScriptContainer, $runtimeInputContainer, $runtimeOutputContainer, $passwordResetScriptContainer, $passwordResetInputContainer, $passwordResetOutputContainer)
    foreach ($path in @($immichTemporary + @($catalogContainer, $bindingContainer, $enableContainer))) {
        $service = if ($path -in $immichTemporary) { 'immich-server' } else { 'piwigo' }
        $probe = if ($service -eq 'immich-server') { Invoke-ImmichCompose @('exec', '-T', $service, 'sh', '-lc', ('test ! -e ' + $path + '; echo $?')) } else { Invoke-PiwigoCompose @('exec', '-T', $service, 'sh', '-lc', ('test ! -e ' + $path + '; echo $?')) }
        Assert-Exact ($probe.Trim() -eq '0') 'container_temporary_not_clean'
    }

    $run = ([Guid]::NewGuid().ToString('N')).Substring(0, 16)
    $work = Join-Path $runtimeRoot $run
    [void][IO.Directory]::CreateDirectory($work)
    $catalogHost = Join-Path $work 'catalog.json'
    $nodeInputHost = Join-Path $work 'runtime-input.json'
    $nodeOutputHost = Join-Path $work 'runtime-output.json'
    $bindingHost = Join-Path $work 'bindings.json'
    $enableHost = Join-Path $work 'enable.json'
    $bridgeHost = Join-Path $work 'bridge-secret.json'
    $passwordResetHost = Join-Path $work 'password-reset-input.txt'
    $sanitizedReport = Join-Path $reportRoot 'private-immich-runtime.json'
    $technicalPassword = $null
    $accessToken = $null
    $bridgeToken = $null

    try {
        $script:stage = 'catalog_export'
        $catalogAction = if ($Action -eq 'finish') { 'export-bound' } else { 'export' }
        $catalogResult = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, $catalogAction)
        Assert-Exact ($catalogResult -match ('^PRIVATE_QA_IMMICH_CATALOG=PASS action=' + [regex]::Escape($catalogAction) + ' count=([0-9]+)$')) 'catalog_export_failed'
        [void](Invoke-PiwigoCompose @('cp', ('piwigo:' + $catalogContainer), ('.codex-work/private-real-qa/runtime/immich/' + $run + '/catalog.json')))
        Set-ClassArchiveOwnerOnlyFileAcl -Path $catalogHost
        Assert-IgnoredOwnerOnly $catalogHost 'catalog'
        $catalog = Get-Content -LiteralPath $catalogHost -Raw | ConvertFrom-Json -ErrorAction Stop
        Assert-Exact ($catalog.version -eq 1 -and $catalog.scope -eq 'PRIVATE_REAL_DATA_QA' -and [int]$catalog.count -eq @($catalog.photos).Count -and [int]$catalog.count -le 500 -and [string]$catalog.catalog_digest -match '^[0-9a-f]{64}$') 'catalog_shape_invalid'
        if ($Action -in @('resume', 'finish')) {
            Assert-Exact ($counts.assets -eq [int]$catalog.count) 'immich_resume_asset_count_mismatch'
        }

        $script:stage = 'runtime_input'
        $technicalPassword = New-SecretText
        $technicalEmail = if ($Action -in @('resume', 'finish')) { Get-ImmichAdminEmail } else { 'class-archive-private-' + $run + '@private.invalid' }
        if ($Action -in @('resume', 'finish')) {
            Reset-ImmichAdminPassword -HostInput $passwordResetHost -Password $technicalPassword
        }
        $nodeInput = [ordered]@{
            version = 1
            scope = 'PRIVATE_REAL_DATA_QA'
            mode = if ($Action -in @('resume', 'finish')) { 'RESUME' } else { 'INITIAL' }
            catalog_digest = [string]$catalog.catalog_digest
            email = $technicalEmail
            password = $technicalPassword
            name = 'Class Archive Private QA Technical User'
            library_name = 'Class Archive Private QA Library'
            photos = @($catalog.photos)
        }
        Write-OwnerOnlyJson $nodeInputHost $nodeInput
        [void](Invoke-ImmichCompose @('cp', $runtimeScriptHost, ('immich-server:' + $runtimeScriptContainer)))
        [void](Invoke-ImmichCompose @('cp', ('.codex-work/private-real-qa/runtime/immich/' + $run + '/runtime-input.json'), ('immich-server:' + $runtimeInputContainer)))
        # Immich itself runs as root in the pinned upstream container because
        # its external-library scanner must read 0660 Piwigo originals.  The
        # transient verifier uses that same container identity only to hash
        # the read-only mount before calling 127.0.0.1; it receives no host or
        # ClassIdentity database mount and leaves no executable behind.
        [void](Invoke-ImmichCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('chown 0:0 ' + $runtimeScriptContainer + ' ' + $runtimeInputContainer + ' && chmod 0500 ' + $runtimeScriptContainer + ' && chmod 0600 ' + $runtimeInputContainer)))

        $script:stage = 'ml_runtime'
        $runtimeResult = Invoke-ImmichCompose @('exec', '-T', '--user', '0:0', 'immich-server', 'node', $runtimeScriptContainer, '--input-file', $runtimeInputContainer)
        Assert-Exact ($runtimeResult -match '^PRIVATE_QA_IMMICH_RUNTIME=PASS assets=([0-9]+) people=([0-9]+) face_jobs=([0-9]+) recognition_jobs=([0-9]+) smart_jobs=([0-9]+)$') 'runtime_failed'
        [void](Invoke-ImmichCompose @('cp', ('immich-server:' + $runtimeOutputContainer), ('.codex-work/private-real-qa/runtime/immich/' + $run + '/runtime-output.json')))
        Set-ClassArchiveOwnerOnlyFileAcl -Path $nodeOutputHost
        Assert-IgnoredOwnerOnly $nodeOutputHost 'runtime_output'
        $runtime = Get-Content -LiteralPath $nodeOutputHost -Raw | ConvertFrom-Json -ErrorAction Stop
        Assert-Exact ($runtime.version -eq 1 -and $runtime.scope -eq 'PRIVATE_REAL_DATA_QA' -and [string]$runtime.catalog_digest -eq [string]$catalog.catalog_digest -and @($runtime.assets).Count -eq [int]$catalog.count) 'runtime_output_invalid'
        $accessToken = [string]$runtime.access_token
        Assert-Exact ($accessToken -match '^[A-Za-z0-9._~-]{32,8192}$') 'access_token_invalid'

        if ($Action -ne 'finish') {
            $script:stage = 'canonical_bind'
            Write-OwnerOnlyJson $bindingHost ([ordered]@{ version = 1; scope = 'PRIVATE_REAL_DATA_QA'; catalog_digest = [string]$catalog.catalog_digest; assets = @($runtime.assets) })
            [void](Invoke-PiwigoCompose @('cp', ('.codex-work/private-real-qa/runtime/immich/' + $run + '/bindings.json'), ('piwigo:' + $bindingContainer)))
            [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $bindingContainer + ' && chmod 0600 ' + $bindingContainer)))
            $bindResult = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'bind')
            Assert-Exact ($bindResult -match '^PRIVATE_QA_IMMICH_CATALOG=PASS action=bind count=([0-9]+)$') 'binding_failed'
        }

        $script:stage = 'bridge_stager_start'
        $bridgeToken = New-SecretText
        Write-OwnerOnlyJson $bridgeHost ([ordered]@{ version = 1; bridge_token = $bridgeToken; immich_access_token = $accessToken })
        [void](Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'up', '-d', 'immich-gateway-secret-stager'))
        $script:stage = 'bridge_secret_clean'
        $empty = Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'exec', '-T', 'immich-gateway-secret-stager', 'sh', '-lc', 'find /run/secrets -mindepth 1 -maxdepth 1 -print -quit')
        Assert-Exact ([string]::IsNullOrWhiteSpace($empty)) 'bridge_secret_volume_not_clean'
        $script:stage = 'bridge_secret_copy'
        [void](Invoke-ImmichCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'cp', ('.codex-work/private-real-qa/runtime/immich/' + $run + '/bridge-secret.json'), 'immich-gateway-secret-stager:/run/secrets/bridge.json'))
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
        Write-OwnerOnlyJson $enableHost ([ordered]@{ version = 1; scope = 'PRIVATE_REAL_DATA_QA'; catalog_digest = [string]$catalog.catalog_digest; token = $bridgeToken })
        [void](Invoke-PiwigoCompose @('cp', ('.codex-work/private-real-qa/runtime/immich/' + $run + '/enable.json'), ('piwigo:' + $enableContainer)))
        [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $enableContainer + ' && chmod 0600 ' + $enableContainer)))
        $enableResult = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'enable')
        Assert-Exact ($enableResult -match '^PRIVATE_QA_IMMICH_CATALOG=PASS action=enable count=([0-9]+)$') 'bridge_enable_failed'

        $script:stage = 'bridge_probe'
        $probe = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'probe')
        Assert-Exact ($probe -match '^PRIVATE_QA_IMMICH_CATALOG=PASS action=probe count=([0-9]+) people=([0-9]+)$') 'bridge_probe_failed'

        $script:stage = 'sanitized_report'
        if (Test-Path -LiteralPath $sanitizedReport) { Fail 'sanitized_report_already_exists' }
        Write-OwnerOnlyJson $sanitizedReport ([ordered]@{
            version = 1
            scope = 'PRIVATE_REAL_DATA_QA'
            timestamp_utc = [DateTime]::UtcNow.ToString('o')
            catalog_count = [int]$catalog.count
            people_count = [int]$runtime.metrics.people_count
            metrics = $runtime.metrics
            media_mount = 'PIWIGO_ORIGINALS_READ_ONLY'
            media_delivery = 'MEDIAGUARD_ONLY'
        })
        Write-Output ("PRIVATE_QA_IMMICH=PASS action={0} assets={1} people={2} assertions={3} evidence=RUNTIME_TESTED" -f $Action, [int]$catalog.count, [int]$runtime.metrics.people_count, $script:assertions)
    } finally {
        $technicalPassword = $null
        $accessToken = $null
        $bridgeToken = $null
        try { [void](Invoke-ImmichCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('rm -f -- ' + $runtimeScriptContainer + ' ' + $runtimeInputContainer + ' ' + $runtimeOutputContainer + ' ' + $passwordResetScriptContainer + ' ' + $passwordResetInputContainer + ' ' + $passwordResetOutputContainer))) } catch { }
        try { [void](Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'sh', '-lc', ('rm -f -- ' + $catalogContainer + ' ' + $bindingContainer + ' ' + $enableContainer))) } catch { }
        foreach ($path in @($nodeInputHost, $nodeOutputHost, $bindingHost, $enableHost, $bridgeHost, $passwordResetHost)) {
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
