[CmdletBinding()]
param(
    # Uses the real offline face + CLIP fixture rather than the small metadata
    # bridge fixture. Default remains the narrow historical bridge gate so it
    # can still isolate bridge transport regressions quickly.
    [switch]$RuntimePeopleSearch,
    # Runs a real local Chromium pass only after the runtime People/Search
    # bridge is fully populated. It is deliberately not available for the
    # small metadata-only bridge fixture.
    [switch]$BrowserE2E
)

# True runtime integration gate for the narrow Class Archive -> Immich
# metadata bridge. It creates only a disposable Immich technical user/library
# over read-only synthetic originals, binds exactly one HERITAGE and one
# LIVING ClassArchivePhoto for this run, and proves aggregation filtering via
# the real same-origin Piwigo Gateway. It never publishes an Immich port,
# media URL, browser route, real photo, NAS mount, or persistent credential.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$piwigoComposeFile = 'infra/docker-compose.yml'
$piwigoEnvFile = '.env.piwigo'
$spikeComposeFile = 'infra/immich-spike/docker-compose.yml'
$spikeEnvFile = 'infra/immich-spike/.env'
$useRuntimePeopleSearch = $RuntimePeopleSearch.IsPresent
if ($BrowserE2E.IsPresent -and -not $useRuntimePeopleSearch) {
    throw 'browser_e2e_requires_runtime_people_search'
}
$piwigoFixturePath = if ($useRuntimePeopleSearch) { 'tests/phase2/immich-people-fixture.php' } else { 'tests/phase2/immich-gateway-fixture.php' }
$nodeFixturePath = if ($useRuntimePeopleSearch) { 'tests/phase2/immich-people-search-runtime.mjs' } else { 'tests/phase2/immich-gateway-runtime.mjs' }
$browserFixturePath = 'tests/phase2/immich-people-search-browser.mjs'
$userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
$programFiles = [Environment]::GetFolderPath([Environment+SpecialFolder]::ProgramFiles)
$bundledDependencies = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies'
$browserNode = if ($env:CLASS_ARCHIVE_PHASE2_BROWSER_NODE) { $env:CLASS_ARCHIVE_PHASE2_BROWSER_NODE } else { Join-Path $bundledDependencies 'node\bin\node.exe' }
$browserNodeModules = if ($env:CLASS_ARCHIVE_PHASE2_BROWSER_NODE_MODULES) { $env:CLASS_ARCHIVE_PHASE2_BROWSER_NODE_MODULES } else { Join-Path $bundledDependencies 'node\node_modules' }
$browserChrome = if ($env:CLASS_ARCHIVE_PHASE2_BROWSER_CHROME) { $env:CLASS_ARCHIVE_PHASE2_BROWSER_CHROME } else { Join-Path $programFiles 'Google\Chrome\Application\chrome.exe' }
$piwigoFixtureEnvironment = if ($useRuntimePeopleSearch) { 'CLASS_ARCHIVE_ALLOW_IMMICH_PEOPLE_FIXTURE=1' } else { 'CLASS_ARCHIVE_ALLOW_IMMICH_GATEWAY_FIXTURE=1' }
$piwigoFixtureUser = if ($useRuntimePeopleSearch) { '1000:1000' } else { 'nginx' }
$piwigoFixtureFile = if ($useRuntimePeopleSearch) { '/workspace/tests/phase2/immich-people-fixture.php' } else { '/workspace/tests/phase2/immich-gateway-fixture.php' }
$secretAclPath = Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1'
$runtimeIsolationPath = Join-Path $projectRoot 'tests\phase2\immich-runtime-isolation.ps1'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')
. $secretAclPath

$script:assertions = 0
$script:probes = 0
$script:stage = 'initialization'
$run = ([Guid]::NewGuid().ToString('N')).Substring(0, 16)
$workDirectory = Join-Path $projectRoot ('.codex-work\immich-gateway\' + $run)
$hostNodeInput = Join-Path $workDirectory 'node-input.json'
$hostNodeOutput = Join-Path $workDirectory 'node-output.json'
$piwigoInput = Join-Path $workDirectory 'piwigo-input.json'
$hostFixturePassword = Join-Path $workDirectory 'fixture-password.txt'
$nodeInputContainer = if ($useRuntimePeopleSearch) { '/tmp/class-archive-immich-people-input.json' } else { '/tmp/class-archive-immich-gateway-input.json' }
$nodeOutputContainer = if ($useRuntimePeopleSearch) { '/tmp/class-archive-immich-people-output.json' } else { '/tmp/class-archive-immich-gateway-output.json' }
$nodeFixtureContainer = if ($useRuntimePeopleSearch) { '/tmp/class-archive-immich-people-runtime.mjs' } else { '/tmp/class-archive-immich-gateway-runtime.mjs' }
$piwigoStagedInput = $null
$piwigoFixturePasswordPath = $null
$fixturePrepared = $false
$bridgeStarted = $false
$spikeStateChanged = $false
$beforeOriginals = $null
$baselineOriginalCount = 0
$failure = $null
$failureStage = $null
$fixtureCleanupFailure = $null
$browserPassed = $false
$browserResult = $null
$searchFailClosedPassed = $false
$sessions = @{}
$fixturePassword = $null
$piwigoRecordBefore = $null

function Fail([string]$Reason) {
    throw "IMMICH_GATEWAY_BRIDGE=FAIL evidence=RUNTIME_TESTED reason=$Reason assertions=$script:assertions"
}

function Get-SafeFailureCode($ErrorRecord) {
    # Test helpers deliberately throw compact machine codes. Preserve only that
    # narrow form in the terminal; never surface Docker/PHP output because it
    # could include an internal path, fixture JSON, or a transient secret.
    $message = [string]$ErrorRecord.Exception.Message
    if ($message -match '\A[a-z0-9_]{1,96}\z') { return $message }
    # Assertions use Fail() so that cleanup still has a uniform exception
    # path. Recover just the pre-approved assertion code for the outer result;
    # never forward its longer message, which may someday contain diagnostics.
    $assertion = [regex]::Match($message, '\breason=([a-z0-9_]{1,96})\b')
    if ($assertion.Success) { return $assertion.Groups[1].Value }
    return 'unexpected'
}

function Assert-Exact([bool]$Condition, [string]$Reason) {
    $script:assertions++
    if (-not $Condition) { Fail $Reason }
}

function New-SecretText {
    $bytes = New-Object byte[] 36
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Read-DotEnv([string]$Path) {
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $index = $trimmed.IndexOf('=')
        if ($index -lt 1) { throw 'dotenv_invalid' }
        $values[$trimmed.Substring(0, $index)] = $trimmed.Substring($index + 1)
    }
    return $values
}

function Require-Setting([hashtable]$Settings, [string]$Name) {
    if (-not $Settings.ContainsKey($Name) -or [string]::IsNullOrWhiteSpace([string]$Settings[$Name])) {
        throw 'required_setting_missing'
    }
    return [string]$Settings[$Name]
}

function Invoke-UbuntuDocker([string[]]$Arguments) {
    $prior = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        # `wsl.exe --` passes a command through a shell on this host, which
        # corrupts Docker Go-template arguments such as `{{.State.Status}}`.
        # `--exec` preserves every argument verbatim and avoids a second shell
        # parser for container IDs, templates, and the already bounded scripts.
        $lines = @(& wsl.exe -d Ubuntu --exec docker @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prior
    }
    if ($code -ne 0) { throw 'docker_command_failed' }
    return [string]::Join("`n", $lines)
}

function Invoke-PiwigoCompose([string[]]$Arguments) {
    $prior = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $piwigoEnvFile -f $piwigoComposeFile @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prior
    }
    # Keep failure diagnostics safe for the terminal: a compose exit code helps
    # distinguish transport/setup failures from fixture policy failures without
    # exposing fixture JSON, media paths, or any bridge credential.
    if ($code -ne 0) { throw ('piwigo_compose_failed_exit_' + $code) }
    return [string]::Join("`n", $lines)
}

function Get-PiwigoContainerId {
    $id = (Invoke-PiwigoCompose @('ps', '-q', 'piwigo')).Trim()
    if ($id -notmatch '^[a-f0-9]{64}$') { throw 'piwigo_container_identity_invalid' }
    return $id
}

function Get-PiwigoContainerRecord {
    $id = Get-PiwigoContainerId
    $startedAt = (Invoke-UbuntuDocker @('inspect', $id, '--format', '{{.State.StartedAt}}')).Trim()
    if ($startedAt -notmatch '^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9:.+-]+Z$') { throw 'piwigo_container_started_at_invalid' }
    return [pscustomobject]@{ Id = $id; StartedAt = $startedAt }
}

function Get-ContainerStatus([string]$ContainerId) {
    # Docker Desktop reports a just-created Piwigo application container as
    # `starting` while its health endpoint rebuilds plugin state. A bounded
    # wait accepts only an eventual healthy/running state; it never treats a
    # restart, exit, or missing health check as usable.
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $status = (Invoke-UbuntuDocker @('inspect', $ContainerId, '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}')).Trim()
        if ($status -in @('running|healthy', 'running|none')) { return $status }
        Start-Sleep -Seconds 1
    }
    throw 'container_not_healthy'
}

function Assert-PiwigoLifecycleUnchanged($ExpectedRecord, [string]$Checkpoint) {
    if ($null -eq $ExpectedRecord -or $Checkpoint -notmatch '^[a-z0-9_]{1,64}$') {
        throw 'piwigo_lifecycle_checkpoint_invalid'
    }
    # This runner owns only the disposable Immich stack. Every nested gate and
    # browser setup step must leave the independently owned Piwigo application
    # container untouched; otherwise a transient 503 could be mistaken for a
    # browser or policy defect.
    $actual = Get-PiwigoContainerRecord
    Assert-Exact ($actual.Id -eq $ExpectedRecord.Id -and $actual.StartedAt -eq $ExpectedRecord.StartedAt) ('piwigo_container_restarted_' + $Checkpoint)
    [void](Get-ContainerStatus $actual.Id)
}

function Invoke-SpikeCompose([string[]]$Arguments, [string]$BridgeSecretWslPath = '') {
    $prior = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        if ($BridgeSecretWslPath -eq '') {
            $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $spikeEnvFile -f $spikeComposeFile @Arguments 2>&1)
        } else {
            $assignment = 'IMMICH_GATEWAY_SECRET_FILE=' + $BridgeSecretWslPath
            $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec env $assignment docker compose --env-file $spikeEnvFile -f $spikeComposeFile @Arguments 2>&1)
        }
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prior
    }
    $text = [string]::Join("`n", $lines)
    if ($code -ne 0) {
        # The disposable Node fixture intentionally emits only a compact error
        # code. Preserve that one allowlisted code for debugging while keeping
        # arbitrary Compose stderr out of the terminal.
        $fixtureFailure = [regex]::Match($text, '(?m)^IMMICH_(?:GATEWAY|PEOPLE_SEARCH)_RUNTIME=FAIL reason=([a-z0-9_]{1,96})$')
        if ($fixtureFailure.Success) { throw ('immich_runtime_' + $fixtureFailure.Groups[1].Value) }
        throw ('immich_compose_failed_exit_' + $code)
    }
    return $text
}

function Invoke-LocalPowerShell([string]$ScriptPath) {
    $prior = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ScriptPath 2>&1)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prior
    }
    if ($code -ne 0) { throw 'project_gate_failed' }
    return [string]::Join("`n", $lines)
}

function Write-OwnerOnlyJson([string]$Path, $Value) {
    if (Test-Path -LiteralPath $Path) { throw 'private_file_already_exists' }
    $json = $Value | ConvertTo-Json -Compress -Depth 8
    [IO.File]::WriteAllText($Path, $json, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function Write-OwnerOnlyText([string]$Path, [string]$Value) {
    if ((Test-Path -LiteralPath $Path) -or ($Value -notmatch '^[A-Za-z0-9_-]{24,190}$')) {
        throw 'private_text_invalid'
    }
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function Remove-ExactFile([string]$Path) {
    if (Test-Path -LiteralPath $Path -PathType Leaf) {
        Remove-Item -LiteralPath $Path -Force -ErrorAction Stop
        if (Test-Path -LiteralPath $Path) { throw 'private_file_cleanup_unproven' }
    }
}

function Get-PiwigoOriginalFingerprints {
    $script = @'
for p in /var/www/html/piwigo/upload /var/www/html/piwigo/galleries; do
  count=$(find "$p" -type f \( -iname '*.avif' -o -iname '*.gif' -o -iname '*.jpeg' -o -iname '*.jpg' -o -iname '*.png' -o -iname '*.webp' \) -printf . | wc -c)
  digest=$(find "$p" -type f \( -iname '*.avif' -o -iname '*.gif' -o -iname '*.jpeg' -o -iname '*.jpg' -o -iname '*.png' -o -iname '*.webp' \) -print0 | sort -z | xargs -0 -r sha256sum | sha256sum | cut -d' ' -f1)
  printf 'FINGERPRINT|%s|%s|%s\n' "$p" "$count" "$digest"
done
'@
    # Avoid passing shell variables through the Windows -> WSL native-command
    # argument grammar. The payload has no secrets and is decoded only inside
    # the already scoped Piwigo container.
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($script))
    $command = 'echo ' + $encoded + ' | base64 -d | sh'
    $lines = @((Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', $command)) -split "`r?`n" | Where-Object { $_ -like 'FINGERPRINT|*' })
    if ($lines.Count -ne 2) { throw 'piwigo_fingerprint_invalid' }
    $result = @{}
    foreach ($line in $lines) {
        $pieces = $line -split '\|', 4
        if ($pieces.Count -ne 4 -or $pieces[2] -notmatch '^\d+$' -or $pieces[3] -notmatch '^[a-f0-9]{64}$') {
            throw 'piwigo_fingerprint_invalid'
        }
        $result[$pieces[1]] = @{ Count = [int]$pieces[2]; Digest = [string]$pieces[3] }
    }
    return $result
}

function Get-ImmichCounts {
    $envPath = Join-Path $projectRoot $spikeEnvFile
    $values = Read-DotEnv $envPath
    $user = if ($values.ContainsKey('DB_USERNAME')) { [string]$values['DB_USERNAME'] } else { 'postgres' }
    $database = if ($values.ContainsKey('DB_DATABASE_NAME')) { [string]$values['DB_DATABASE_NAME'] } else { 'immich' }
    if ($user -notmatch '^[A-Za-z0-9_.-]+$' -or $database -notmatch '^[A-Za-z0-9_.-]+$') { throw 'immich_env_invalid' }
    $sql = 'SELECT (SELECT count(*) FROM "user") AS users, (SELECT count(*) FROM library) AS libraries, (SELECT count(*) FROM asset) AS assets, (SELECT count(*) FROM memory) AS memories;'
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($sql))
    $output = Invoke-SpikeCompose @('exec', '-T', 'database', 'sh', '-lc', ('echo ' + $encoded + ' | base64 -d | psql -U ' + $user + ' -d ' + $database + ' -At'))
    $match = [regex]::Match($output.Trim(), '^([0-9]+)\|([0-9]+)\|([0-9]+)\|([0-9]+)$')
    if (-not $match.Success) { throw 'immich_counts_invalid' }
    return @{ Users = [int]$match.Groups[1].Value; Libraries = [int]$match.Groups[2].Value; Assets = [int]$match.Groups[3].Value; Memories = [int]$match.Groups[4].Value }
}

function Remove-DisposableSpikeVolume([string]$volume) {
    $expectedComposeVolume = @{
        class_archive_immich_spike_upload = 'immich_upload'
        class_archive_immich_spike_db = 'immich_db'
        class_archive_immich_gateway_secret = 'immich_gateway_secret'
    }
    if (-not $expectedComposeVolume.ContainsKey($volume)) {
        throw 'unexpected_disposable_volume'
    }
    $names = @((Invoke-UbuntuDocker @('volume', 'ls', '--format', '{{.Name}}')) -split "`r?`n" | Where-Object { $_ -ne '' })
    if ($names -contains $volume) {
        $records = @(Invoke-UbuntuDocker @('volume', 'inspect', $volume) | ConvertFrom-Json -ErrorAction Stop)
        $dockerRoot = (Invoke-UbuntuDocker @('info', '--format', '{{.DockerRootDir}}')).TrimEnd('/')
        $record = if ($records.Count -eq 1) { $records[0] } else { $null }
        $labels = if ($null -ne $record) { $record.Labels } else { $null }
        $expectedMountpoint = $dockerRoot + '/volumes/' + $volume + '/_data'
        $invalid = ($dockerRoot -notmatch '^/[A-Za-z0-9._/-]{1,240}$') -or
            $dockerRoot.Contains('..') -or
            ($null -eq $record) -or
            ([string]$record.Name -cne $volume) -or
            ([string]$record.Driver -cne 'local') -or
            ([string]$record.Scope -cne 'local') -or
            ($null -ne $record.Options) -or
            ([string]$record.Mountpoint -cne $expectedMountpoint) -or
            ($null -eq $labels) -or
            ([string]($labels.'com.docker.compose.project') -cne 'class-archive-immich-spike') -or
            ([string]($labels.'com.docker.compose.volume') -cne [string]$expectedComposeVolume[$volume])
        if ($invalid) { throw 'disposable_volume_identity_invalid' }
        [void](Invoke-UbuntuDocker @('volume', 'rm', $volume))
    }
}

function Reset-ImmichSpike {
    # Keep the separately verified model cache intact. This suite owns only
    # the disposable Immich index/upload state and its one-time bridge secret.
    $profiles = @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration')
    if ($useRuntimePeopleSearch) { $profiles += @('--profile', 'immich-ml') }
    if ($BrowserE2E.IsPresent) { $profiles += @('--profile', 'immich-web-compat') }
    # This compose file shares an explicitly external gateway network with the
    # independently owned Piwigo compose project. Do not use --remove-orphans:
    # it is broader than this spike's declared services and could tear down a
    # healthy Piwigo container during a disposable test reset.
    [void](Invoke-SpikeCompose @($profiles + @('down')))
    foreach ($volume in @('class_archive_immich_spike_upload', 'class_archive_immich_spike_db', 'class_archive_immich_gateway_secret')) {
        Remove-DisposableSpikeVolume $volume
    }
    foreach ($volume in @('class_archive_piwigo_uploads', 'class_archive_piwigo_galleries')) {
        [void](Invoke-UbuntuDocker @('volume', 'inspect', $volume))
    }
    if ($useRuntimePeopleSearch) {
        # On Docker Desktop/WSL a freshly-created Postgres service can report
        # healthy just before Immich's first connection attempt. Start the ML
        # dependency tier first, then server; this remains fully internal and
        # avoids treating that host startup race as an ACL failure.
        [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-ml', 'up', '-d', 'database', 'redis', 'immich-machine-learning'))
        $dependencyContainers = @(
            'class-archive-immich-spike-database-1',
            'class-archive-immich-spike-redis-1',
            'class-archive-immich-spike-immich-machine-learning-1'
        )
        for ($attempt = 0; $attempt -lt 120; $attempt++) {
            try {
                $health = @($dependencyContainers | ForEach-Object {
                    (Invoke-UbuntuDocker @('inspect', $_, '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}')).Trim()
                })
                if (@($health | Where-Object { $_ -eq 'running|healthy' }).Count -eq $dependencyContainers.Count) { break }
            } catch { }
            if ($attempt -eq 119) { throw 'immich_dependency_reset_not_healthy' }
            Start-Sleep -Seconds 1
        }
        [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-ml', 'up', '-d', 'immich-server'))
    } else {
        [void](Invoke-SpikeCompose @('--profile', 'immich-spike', 'up', '-d'))
    }
    $requiredContainers = @('class-archive-immich-spike-immich-server-1', 'class-archive-immich-spike-database-1', 'class-archive-immich-spike-redis-1')
    if ($useRuntimePeopleSearch) { $requiredContainers += 'class-archive-immich-spike-immich-machine-learning-1' }
    for ($attempt = 0; $attempt -lt 90; $attempt++) {
        try {
            # Compose's JSON formatter can transiently block while the
            # project recreates services on Docker Desktop. Inspect the three
            # known, isolated containers directly instead; `--exec` preserves
            # the Go template exactly and there is no user-controlled input.
            $health = @($requiredContainers | ForEach-Object {
                (Invoke-UbuntuDocker @('inspect', $_, '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}')).Trim()
            })
            $allHealthy = @($health | Where-Object { $_ -eq 'running|healthy' }).Count -eq $requiredContainers.Count
            if ($allHealthy) { return }
        } catch { }
        Start-Sleep -Seconds 1
    }
    throw 'immich_reset_not_healthy'
}

function Start-CompatibilityWebForBrowserE2E {
    if (-not $BrowserE2E.IsPresent) { return }
    if (-not (Test-Path -LiteralPath (Join-Path $projectRoot $browserFixturePath) -PathType Leaf) -or -not (Test-Path -LiteralPath $browserNode -PathType Leaf) -or -not (Test-Path -LiteralPath $browserChrome -PathType Leaf)) {
        throw 'browser_e2e_runtime_missing'
    }
    # The compatibility shell remains a separately profiled, no-media-mount
    # container. It reaches only the Piwigo Gateway over the narrow internal
    # network; port 8091 remains the loopback nginx ingress.
    $profiles = @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', '--profile', 'immich-ml', '--profile', 'immich-web-compat')
    [void](Invoke-SpikeCompose @($profiles + @('up', '-d', 'immich-web-compat')))
    $container = 'class-archive-immich-spike-immich-web-compat-1'
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        try {
            $status = (Invoke-UbuntuDocker @('inspect', $container, '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}')).Trim()
            if ($status -eq 'running|healthy') { return }
        } catch { }
        Start-Sleep -Seconds 1
    }
    throw 'browser_e2e_compatibility_shell_not_healthy'
}

function Get-CompatibilityWebDiagnostic {
    if (-not $BrowserE2E.IsPresent) { return '' }
    try {
        $profiles = @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', '--profile', 'immich-ml', '--profile', 'immich-web-compat')
        $logs = Invoke-SpikeCompose @($profiles + @('logs', '--no-log-prefix', 'immich-web-compat'))
        $matches = [regex]::Matches($logs, '(?m)^CLASS_ARCHIVE_BFF_DIAGNOSTIC operation=([a-z0-9_]{1,48}) code=([a-z0-9_]{1,96})$')
        if ($matches.Count -eq 0) { return '' }
        $last = $matches[$matches.Count - 1]
        return ([string]$last.Groups[1].Value + '_' + [string]$last.Groups[2].Value)
    } catch {
        # Diagnostics are strictly supplementary. A log-read failure must not
        # overwrite the actual browser result or reveal compose output.
        return ''
    }
}

function Invoke-CompatibilityBrowserE2E([string]$Password, [string]$LivingPhotoId) {
    if (-not $BrowserE2E.IsPresent -or $Password -notmatch '^[A-Za-z0-9_-]{24,190}$' -or $LivingPhotoId -notmatch '^[0-9a-f-]{36}$') {
        throw 'browser_e2e_arguments_invalid'
    }
    $fixture = Join-Path $projectRoot $browserFixturePath
    if (-not (Test-Path -LiteralPath $fixture -PathType Leaf) -or -not (Test-Path -LiteralPath $browserNode -PathType Leaf) -or -not (Test-Path -LiteralPath $browserNodeModules -PathType Container) -or -not (Test-Path -LiteralPath $browserChrome -PathType Leaf)) {
        throw 'browser_e2e_runtime_missing'
    }
    # A run-specific directory prevents a failed browser pass from inheriting
    # stale screenshots from an earlier success. The opaque run marker is
    # synthetic, ignored by Git, and contains no identity or credential.
    $screenshotDirectory = Join-Path $projectRoot ('.codex-work\screenshots\phase2-5-runtime-ai\' + $run)
    $previous = @{}
    $names = @(
        'NODE_PATH',
        'CLASS_ARCHIVE_PHASE2_BROWSER_PIWIGO_ORIGIN',
        'CLASS_ARCHIVE_PHASE2_BROWSER_COMPAT_ORIGIN',
        'CLASS_ARCHIVE_PHASE2_BROWSER_SCREENSHOT_DIR',
        'CLASS_ARCHIVE_PHASE2_BROWSER_CHROME',
        'CLASS_ARCHIVE_PHASE2_BROWSER_PASSWORD',
        'CLASS_ARCHIVE_PHASE2_BROWSER_LIVING_PHOTO_ID'
    )
    foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
    try {
        $env:NODE_PATH = $browserNodeModules
        $env:CLASS_ARCHIVE_PHASE2_BROWSER_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'
        $env:CLASS_ARCHIVE_PHASE2_BROWSER_COMPAT_ORIGIN = 'http://127.0.0.1:8091/'
        $env:CLASS_ARCHIVE_PHASE2_BROWSER_SCREENSHOT_DIR = $screenshotDirectory
        $env:CLASS_ARCHIVE_PHASE2_BROWSER_CHROME = $browserChrome
        $env:CLASS_ARCHIVE_PHASE2_BROWSER_PASSWORD = $Password
        $env:CLASS_ARCHIVE_PHASE2_BROWSER_LIVING_PHOTO_ID = $LivingPhotoId
        $previousErrorAction = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $output = @(& $browserNode $fixture 2>&1)
            $exitCode = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousErrorAction
        }
        $text = [string]::Join("`n", $output)
        $pass = [regex]::Match($text, '(?m)^IMMICH_RUNTIME_AI_BROWSER=PASS evidence=BROWSER_E2E_TESTED assertions=([0-9]+) screenshots=([0-9]+) roles=CLASSMATE_FAMILY_TEACHER_ANONYMOUS media=MEDIAGUARD_ONLY$')
        if ($exitCode -ne 0 -or -not $pass.Success) {
            $failure = [regex]::Match($text, 'IMMICH_RUNTIME_AI_BROWSER=FAIL evidence=BROWSER_E2E_TESTED reason=([a-z0-9_]{1,96}) assertions=[0-9]+')
            $code = if ($failure.Success) { [string]$failure.Groups[1].Value } else { 'unexpected' }
            # A BFF diagnostic is meaningful only for the API operation that
            # failed. Do not append an unrelated background-Web diagnostic to
            # a Piwigo login/session assertion.
            if ($code -match '_(?:people|search|asset)_(?:http|transport|thumbnail|viewer)') {
                $diagnostic = Get-CompatibilityWebDiagnostic
                if ($diagnostic -ne '' -and ($code.Length + $diagnostic.Length + 14) -le 96) {
                    $code += '_gateway_' + $diagnostic
                }
            }
            throw ('browser_e2e_' + $code)
        }
        if ([int]$pass.Groups[1].Value -lt 40 -or [int]$pass.Groups[2].Value -lt 8) {
            throw 'browser_e2e_evidence_insufficient'
        }
        return $pass.Value
    } finally {
        foreach ($name in $names) {
            Remove-Item -LiteralPath ('Env:' + $name) -ErrorAction SilentlyContinue
            if ($null -ne $previous[$name]) { Set-Item -LiteralPath ('Env:' + $name) -Value $previous[$name] }
        }
    }
}

function Copy-BridgeSecretToStager([string]$HostPath, [string]$RelativeHostPath) {
    # Docker Desktop does not reliably permit a Windows owner-only secret as a
    # direct volume bind. Copy it once through the current user's Docker CLI
    # into the no-network stager's private volume, then erase the host source.
    # The bridge sees only the stager-normalized 0600 named-volume copy.
    if (-not (Test-Path -LiteralPath $HostPath -PathType Leaf)) { throw 'bridge_secret_host_source_missing' }
    $hostRaw = $null
    $hostSecret = $null
    try {
        $hostRaw = [IO.File]::ReadAllText($HostPath, [Text.UTF8Encoding]::new($false))
        $expectedLength = $hostRaw.Length
        $hostSecret = $hostRaw | ConvertFrom-Json -ErrorAction Stop
        $hostKeys = @($hostSecret.PSObject.Properties.Name | Sort-Object) -join ','
        if ($hostRaw.Length -eq 0 -or [int][char]$hostRaw[0] -ne 123 -or $hostSecret.version -ne 1 -or $hostKeys -ne 'bridge_token,immich_access_token,version' -or [string]$hostSecret.bridge_token -notmatch '^[A-Za-z0-9_-]{32,128}$' -or [string]$hostSecret.immich_access_token -notmatch '^[A-Za-z0-9._~-]{32,8192}$') {
            throw 'bridge_secret_host_source_invalid'
        }
    } catch {
        if ($_.Exception.Message -eq 'bridge_secret_host_source_invalid') { throw }
        throw 'bridge_secret_host_json_invalid'
    } finally {
        $hostSecret = $null
        $hostRaw = $null
    }
    try {
        [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'cp', $RelativeHostPath, 'immich-gateway-secret-stager:/run/secrets/bridge.json'))
    } catch { throw 'bridge_secret_copy_failed' }
    $stagerScript = @'
test -f /run/secrets/bridge.json || exit 60
chmod 600 /run/secrets/bridge.json || exit 61
node -e 'const fs=require("fs");let raw,x;try{raw=fs.readFileSync("/run/secrets/bridge.json","utf8")}catch{process.exit(9)};if(raw.length===0){process.exit(6)};if(raw.length!==__EXPECTED_LENGTH__){process.exit(8)};if(raw.charCodeAt(0)!==123){process.exit(7)};try{x=JSON.parse(raw)}catch{process.exit(10)};const keys=Object.keys(x).sort().join(",");if(x.version!==1||keys!=="bridge_token,immich_access_token,version"){process.exit(2)};if(typeof x.bridge_token!=="string"||typeof x.immich_access_token!=="string"){process.exit(3)};if(!/^[A-Za-z0-9_-]{32,128}$/.test(x.bridge_token)){process.exit(4)};if(!/^[A-Za-z0-9._~-]{32,8192}$/.test(x.immich_access_token)){process.exit(5)}'
stage_code=$?
if [ "$stage_code" -ne 0 ]; then exit $((40 + stage_code)); fi
chown 65532:65532 /run/secrets/bridge.json || exit 62
test -f /run/secrets/bridge.json || exit 64
'@
    $stagerScript = $stagerScript.Replace('__EXPECTED_LENGTH__', [string]$expectedLength)
    Invoke-BridgeStagerStaticScript $stagerScript 'bridge_secret_stage_failed'
}

function Write-BridgeSecretToStager([string]$BridgeToken, [string]$ImmichAccessToken) {
    if ($BridgeToken -notmatch '^[A-Za-z0-9_-]{32,128}$' -or $ImmichAccessToken -notmatch '^[A-Za-z0-9._~-]{32,8192}$') {
        throw 'bridge_secret_value_invalid'
    }
    try {
        [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'up', '-d', 'immich-gateway-secret-stager'))
    } catch { throw 'bridge_secret_stager_start_failed' }
    Invoke-BridgeStagerStaticScript @'
test -d /run/secrets
if find /run/secrets -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
  exit 1
fi
'@ 'bridge_secret_stager_preflight_failed'
    $hostPath = Join-Path $workDirectory 'bridge-secret.json'
    $relativeHostPath = '.codex-work/immich-gateway/' + $run + '/bridge-secret.json'
    try {
        Write-OwnerOnlyJson $hostPath ([ordered]@{ version = 1; bridge_token = $BridgeToken; immich_access_token = $ImmichAccessToken })
        Copy-BridgeSecretToStager $hostPath $relativeHostPath
    } finally {
        Remove-ExactFile $hostPath
    }
Invoke-BridgeStagerStaticScript @'
test -f /run/secrets/bridge.json
stat -c '%a|%u|%h' /run/secrets/bridge.json | grep -qx '600|65532|1'
'@ 'bridge_secret_stager_verify_failed'
}

function Assert-BridgeSecretVolumeAbsent {
    # Docker Desktop can acknowledge a compose teardown just before its WSL
    # engine completes the final volume removal. Wait briefly, but still fail
    # if the secret volume remains observable after the bounded grace period.
    for ($attempt = 0; $attempt -lt 15; $attempt++) {
        $prior = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            [void](& wsl.exe -d Ubuntu --exec docker volume inspect class_archive_immich_gateway_secret 2>$null)
            $code = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $prior
        }
        if ($code -ne 0) { return }
        Start-Sleep -Seconds 1
    }
    throw 'bridge_secret_volume_persisted'
}

function Get-BridgeRuntimeRecord {
    $text = Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'ps', '-a', '-q', 'immich-gateway')
    $ids = @($text -split "`r?`n" | Where-Object { $_ -match '^[a-f0-9]{12,64}$' })
    if ($ids.Count -eq 0) { return $null }
    if ($ids.Count -ne 1) { throw 'bridge_runtime_record_ambiguous' }
    try {
        $state = (Invoke-UbuntuDocker @('inspect', $ids[0], '--format', '{{.State.Status}}|{{.State.ExitCode}}')).Trim()
    } catch {
        return [pscustomobject]@{ ID = $ids[0]; State = 'unavailable'; ExitCode = -1 }
    }
    $match = [regex]::Match($state, '^(created|running|restarting|removing|paused|exited|dead)\|([0-9]+)$')
    if (-not $match.Success) { throw 'bridge_runtime_record_invalid' }
    return [pscustomobject]@{ ID = $ids[0]; State = $match.Groups[1].Value; ExitCode = [int]$match.Groups[2].Value }
}

function Get-BridgeStartupCode {
    $prior = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $spikeEnvFile -f $spikeComposeFile --profile immich-spike --profile immich-gateway-integration logs --no-log-prefix immich-gateway 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $prior }
    if ($code -ne 0) { return 'container_unavailable' }
    $text = [string]::Join("`n", $lines)
    $match = [regex]::Match($text, '(?m)^IMMICH_GATEWAY_STARTUP=FAIL code=([a-z_]{1,64})$')
    if ($match.Success) { return $match.Groups[1].Value }
    return 'container_unavailable'
}

function Invoke-BridgeStagerStaticScript([string]$Script, [string]$FailureCode) {
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Script))
    $remote = 'echo ' + $encoded + ' | base64 -d | sh'
    $prior = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $spikeEnvFile -f $spikeComposeFile --profile immich-spike --profile immich-gateway-integration exec -T immich-gateway-secret-stager sh -lc $remote 2>&1)
        $code = $LASTEXITCODE
    } catch {
        $code = -1
        $lines = @('')
    } finally {
        $ErrorActionPreference = $prior
    }
    if ($code -ne 0) {
        # Exit status is an allowlisted, secret-free staging diagnostic. Do
        # not surface command output because it could otherwise contain an
        # internal path or a transient credential.
        throw ($FailureCode + '_exit_' + $code)
    }
    if (-not [string]::IsNullOrWhiteSpace([string]::Join("`n", $lines))) { throw ($FailureCode + '_unexpected_output') }
}

function Invoke-PiwigoFixture([string]$Action, [string]$Run, $RequestPayload = $null) {
    $allowedActions = if ($useRuntimePeopleSearch) { @('prepare', 'bind', 'enable', 'probe', 'request-probe', 'media-probe', 'fault-mapping-start', 'fault-mapping-stop', 'fault-era-start', 'fault-era-stop', 'cleanup') } else { @('snapshot', 'bind', 'enable', 'probe', 'cleanup') }
    if ($Action -notin $allowedActions -or $Run -notmatch '^[a-f0-9]{16}$') { throw 'fixture_arguments_invalid' }
    $effectiveFixtureUser = if ($useRuntimePeopleSearch -and $Action -eq 'request-probe') { 'nginx' } else { $piwigoFixtureUser }
    if ($null -eq $RequestPayload) {
        $text = Invoke-PiwigoCompose @('exec', '-T', '--user', $effectiveFixtureUser, '-e', $piwigoFixtureEnvironment, 'piwigo', 'php', $piwigoFixtureFile, $Action, $Run)
    } else {
        if ($null -ne $script:piwigoStagedInput) { throw 'fixture_input_reentrant' }
        $stagedHostPath = $piwigoInput
        Write-OwnerOnlyJson $stagedHostPath $RequestPayload
        $container = '/tmp/class-archive-immich-gateway-piwigo-' + $Run + '.json'
        $script:piwigoStagedInput = $container
        try {
            [void](Invoke-PiwigoCompose @('cp', ('.codex-work/immich-gateway/' + $Run + '/piwigo-input.json'), ('piwigo:' + $container)))
            $owner = if ($effectiveFixtureUser -eq 'nginx') { 'nginx:nginx' } else { '1000:1000' }
            [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown ' + $owner + ' ' + $container + ' && chmod 0600 ' + $container)))
            $text = Invoke-PiwigoCompose @('exec', '-T', '--user', $effectiveFixtureUser, '-e', $piwigoFixtureEnvironment, 'piwigo', 'sh', '-lc', ('php ' + $piwigoFixtureFile + ' ' + $Action + ' ' + $Run + ' < ' + $container))
        } finally {
            try { [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('test ! -e ' + $container + ' || rm -f -- ' + $container))) } catch { }
            Remove-ExactFile $stagedHostPath
            $script:piwigoStagedInput = $null
        }
    }
    try { return ($text | ConvertFrom-Json -ErrorAction Stop) } catch { throw 'fixture_response_invalid' }
}

function Invoke-PiwigoReadProjectionRebuild {
    # The just-finished fixture removed many native image rows and can leave a
    # brief Docker/PHP health transition. Retry only the deterministic publish,
    # never the business cleanup, and keep the bound short and fail-closed.
    for ($attempt = 1; $attempt -le 5; $attempt++) {
        try {
            $text = Invoke-PiwigoCompose @(
                'exec', '-T', '--user', 'nginx', 'piwigo', 'php',
                '/workspace/infra/scripts/rebuild-photo-read-projection.php', '--scope=all'
            )
            if ($text -match '(?m)^READ_PROJECTION_REBUILD=PASS\b') {
                return
            }
        } catch {
            if ($attempt -eq 5) { break }
        }
        if ($attempt -lt 5) { Start-Sleep -Seconds 2 }
    }
    throw 'piwigo_projection_cleanup_rebuild_failed'
}

function Provision-PiwigoFixtureAccounts([string]$Password) {
    if ($Password -notmatch '^[A-Za-z0-9_-]{24,190}$' -or $null -ne $script:piwigoFixturePasswordPath) {
        throw 'fixture_password_arguments_invalid'
    }
    $container = '/tmp/class-archive-fixture-password-' + $run + '.txt'
    $script:piwigoFixturePasswordPath = $container
    Write-OwnerOnlyText $hostFixturePassword $Password
    try {
        [void](Invoke-PiwigoCompose @('cp', ('.codex-work/immich-gateway/' + $run + '/fixture-password.txt'), ('piwigo:' + $container)))
        [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $container + ' && chmod 0600 ' + $container)))
        $text = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', '-e', ('CLASS_ARCHIVE_FIXTURE_PASSWORD_FILE=' + $container), 'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php')
        if ($text -notmatch '^ACCESS_FIXTURES_READY$') { throw 'gateway_fixture_provisioning_failed' }
    } finally {
        $cleanupFailure = $null
        try { Remove-ExactFile $hostFixturePassword } catch { $cleanupFailure = $_ }
        try {
            [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('test ! -e ' + $container + ' || rm -f -- ' + $container + '; test ! -e ' + $container)))
        } catch {
            if ($null -eq $cleanupFailure) { $cleanupFailure = $_ }
        } finally {
            $script:piwigoFixturePasswordPath = $null
        }
        if ($null -ne $cleanupFailure) { throw $cleanupFailure }
    }
}

function Rebuild-BridgeBackedReadProjections {
    # AI-derived People/Memories are write-time projections. Enabling the
    # isolated bridge must explicitly materialize them before the HTTP read
    # path is exercised; GET is never allowed to fall back to a live Immich
    # scan just because the bridge became available.
    $text = Invoke-PiwigoCompose @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php',
        '--scope=aggregates', '--kinds=PEOPLE,MEMORIES', '--json'
    )
    try { $result = $text | ConvertFrom-Json -ErrorAction Stop } catch { throw 'bridge_projection_rebuild_response_invalid' }
    $changedKinds = @($result.aggregates.changed_kinds)
    $unexpectedChangedKinds = @($changedKinds | Where-Object { $_ -notin @('PEOPLE', 'MEMORIES') })
    $states = @{}
    foreach ($projection in @($result.projections)) {
        if ($null -ne $projection.kind) { $states[[string]$projection.kind] = [string]$projection.state }
    }
    # changed_kinds is a delta, not the requested set. Archive-derived
    # Memories may already be byte-identical when the bridge becomes
    # available, while People must change from unavailable to populated.
    Assert-Exact ([string]$result.result -eq 'PASS' `
        -and $result.aggregates.dry_run -eq $false `
        -and $changedKinds -contains 'PEOPLE' `
        -and $unexpectedChangedKinds.Count -eq 0 `
        -and $states['PEOPLE'] -eq 'ACTIVE' `
        -and $states['MEMORIES'] -eq 'ACTIVE') 'bridge_projection_rebuild_invalid'
}

function Invoke-WS([Uri]$Uri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session, [hashtable]$Body) {
    return Invoke-RestMethod -Uri $Uri -Method Post -Body $Body -WebSession $Session -TimeoutSec 30
}

function Login-Role([Uri]$Uri, [string]$Username, [string]$Password) {
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-WS $Uri $session @{ method = 'pwg.session.login'; username = $Username; password = $Password }
    Assert-Exact ($response.stat -eq 'ok' -and [bool]$response.result) 'fixture_login_failed'
    return $session
}

function Invoke-Logout([Uri]$Uri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session) {
    try { [void](Invoke-WS $Uri $Session @{ method = 'pwg.session.logout' }) } catch { throw 'fixture_logout_failed' }
}

function Read-ResponseText([Net.HttpWebResponse]$Response) {
    $stream = $Response.GetResponseStream()
    if ($null -eq $stream) { return '' }
    $buffer = New-Object byte[] 8192
    $memory = [IO.MemoryStream]::new()
    try {
        while ($true) {
            $read = $stream.Read($buffer, 0, $buffer.Length)
            if ($read -le 0) { break }
            if ($memory.Length + $read -gt 1048576) { throw 'gateway_response_too_large' }
            $memory.Write($buffer, 0, $read)
        }
        return [Text.Encoding]::UTF8.GetString($memory.ToArray())
    } finally {
        [Array]::Clear($buffer, 0, $buffer.Length)
        $memory.Dispose()
        $stream.Dispose()
    }
}

function Invoke-Gateway([Uri]$Uri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session) {
    $script:probes++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = 'GET'
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-Immich-Bridge-Runtime/1.0'
    $response = $null
    try { $response = [Net.HttpWebResponse]$request.GetResponse() }
    catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw 'gateway_no_response' }
        $response = [Net.HttpWebResponse]$_.Exception.Response
    }
    try {
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            CacheControl = [string]$response.Headers['Cache-Control']
            Text = Read-ResponseText $response
        }
    } finally {
        if ($null -ne $response) { $response.Dispose() }
    }
}

function Read-GatewayJson($Response, [string]$Label, [int]$ExpectedStatus = 200) {
    if ($Response.Status -ne $ExpectedStatus) {
        # The numeric HTTP status is deliberately safe diagnostic evidence. Do
        # not include the response body: it may contain a transient upstream
        # detail and must never be copied into a test failure or log.
        throw ($Label + '_status_' + [string]$Response.Status)
    }
    Assert-Exact $true ($Label + '_status')
    Assert-Exact ($Response.ContentType -like 'application/json*') ($Label + '_json')
    Assert-Exact ($Response.CacheControl -like '*no-store*') ($Label + '_private')
    try { return $Response.Text | ConvertFrom-Json -ErrorAction Stop } catch { throw ($Label + '_invalid_json') }
}

function Assert-SearchUnavailable([Uri]$SearchUri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session, [string]$Label) {
    $response = Invoke-Gateway $SearchUri $Session
    $payload = Read-GatewayJson $response $Label 503
    $propertyNames = @($payload.PSObject.Properties.Name)
    Assert-Exact ($propertyNames.Count -eq 1 -and $payload.error -is [string] -and $payload.error.Length -gt 0 -and $propertyNames -notcontains 'total' -and $propertyNames -notcontains 'items') ($Label + '_expanded_visibility')
}

function Assert-NoInternalLeak([string]$Text, [string[]]$AssetIds, [string[]]$References, [string]$Label) {
    foreach ($needle in @('immich_asset_id', 'immich_person_id', 'piwigo_image_id', 'media_reference', 'media_checksum', 'classmate_identity_id', 'identity_id', 'seat_id', 'account_id', 'user_id', '/upload/', '/galleries/', 'class-archive-immich-gateway', 'X-Accel-Redirect') + $AssetIds + $References) {
        Assert-Exact (-not $Text.Contains($needle)) ($Label + '_leak')
    }
}

function Assert-PeopleProjection($Payload, [int[]]$ExpectedCounts, [hashtable]$VisiblePhotoIds, [string[]]$AssetIds, [string[]]$References, [string]$Label) {
    Assert-Exact ([bool]$Payload.available -and [int]$Payload.total -eq $ExpectedCounts.Count -and @($Payload.items).Count -eq $ExpectedCounts.Count) ($Label + '_shape_invalid')
    $actualCounts = @($Payload.items | ForEach-Object {
        $id = [string]$_.id
        $cover = [string]$_.cover_photo_id
        Assert-Exact ($id -match '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') ($Label + '_person_id_invalid')
        Assert-Exact ($cover -match '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' -and $VisiblePhotoIds.ContainsKey($cover)) ($Label + '_cover_visibility_invalid')
        Assert-Exact ([int]$_.photo_count -ge 1) ($Label + '_photo_count_invalid')
        [int]$_.photo_count
    } | Sort-Object)
    Assert-Exact ((@($actualCounts) -join ',') -eq (@($ExpectedCounts | Sort-Object) -join ',')) ($Label + '_count_aggregation_invalid')
    Assert-NoInternalLeak ($Payload | ConvertTo-Json -Compress -Depth 12) $AssetIds $References $Label
}

function Assert-PersonDetails([Uri]$BaseUri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session, $PeoplePayload, [hashtable]$VisiblePhotoIds, [string[]]$AssetIds, [string[]]$References, [string]$Label) {
    foreach ($person in @($PeoplePayload.items)) {
        $id = [string]$person.id
        $detail = Read-GatewayJson (Invoke-Gateway ([Uri]::new($BaseUri, ('api/people/' + $id))) $Session) ($Label + '_detail')
        Assert-Exact ($detail.id -eq $id -and [int]$detail.photo_count -eq [int]$person.photo_count -and @($detail.items).Count -eq [int]$person.photo_count) ($Label + '_detail_count_invalid')
        foreach ($photo in @($detail.items)) {
            $photoId = [string]$photo.id
            Assert-Exact ($VisiblePhotoIds.ContainsKey($photoId)) ($Label + '_detail_visibility_invalid')
        }
        Assert-NoInternalLeak ($detail | ConvertTo-Json -Compress -Depth 12) $AssetIds $References ($Label + '_detail')
    }
}

try {
    if (-not (Test-Path -LiteralPath (Join-Path $projectRoot $piwigoFixturePath) -PathType Leaf) -or -not (Test-Path -LiteralPath (Join-Path $projectRoot $nodeFixturePath) -PathType Leaf)) {
        Fail 'fixture_source_missing'
    }
    $settings = Read-DotEnv (Join-Path $projectRoot $piwigoEnvFile)
    $port = Require-Setting $settings 'CLASS_ARCHIVE_HTTP_PORT'
    if ($port -notmatch '^\d{1,5}$') { Fail 'http_port_invalid' }
    $baseUri = [Uri]("http://127.0.0.1:$port/")
    $wsUri = [Uri]::new($baseUri, 'ws.php?format=json')

    $script:stage = 'piwigo_lifecycle_precondition'
    $piwigoRecordBefore = Get-PiwigoContainerRecord
    [void](Get-ContainerStatus $piwigoRecordBefore.Id)
    $script:stage = 'original_fingerprint_before'
    $beforeOriginals = Get-PiwigoOriginalFingerprints
    if ($useRuntimePeopleSearch) {
        foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
            $baselineOriginalCount += $beforeOriginals[$path].Count
        }
        Assert-Exact ($baselineOriginalCount -eq 72) 'canonical_baseline_original_count_invalid'
    }
    # Own the disposable spike lifecycle. Requiring an already-running stack
    # made this gate race with a prior cleanup on Windows/WSL. Reset starts a
    # pristine, internal-only stack and waits for its real health checks before
    # the isolation assertion below; it never touches the Piwigo originals.
    $script:stage = 'immich_reset_start'
    $spikeStateChanged = $true
    Reset-ImmichSpike
    $script:stage = 'piwigo_lifecycle_after_reset'
    $piwigoRecordAfterReset = Get-PiwigoContainerRecord
    Assert-Exact ($piwigoRecordAfterReset.Id -eq $piwigoRecordBefore.Id -and $piwigoRecordAfterReset.StartedAt -eq $piwigoRecordBefore.StartedAt) 'piwigo_container_restarted_by_spike_reset'
    [void](Get-ContainerStatus $piwigoRecordAfterReset.Id)
    $script:stage = 'isolation_precondition'
    $isolation = Invoke-LocalPowerShell $runtimeIsolationPath
    Assert-Exact ($isolation -match '(?m)^IMMICH_RUNTIME_ISOLATION=PASS evidence=RUNTIME_TESTED') 'immich_isolation_precondition'
    Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_isolation'
    if ($useRuntimePeopleSearch) {
        $mlReadinessPath = Join-Path $projectRoot 'tests\phase2\immich-ml-artifact-readiness.ps1'
        Assert-Exact (Test-Path -LiteralPath $mlReadinessPath -PathType Leaf) 'ml_readiness_script_missing'
        $mlReadiness = Invoke-LocalPowerShell $mlReadinessPath
        Assert-Exact ($mlReadiness -match '(?m)^IMMICH_ML_MODEL_ARTIFACTS=READY evidence=RUNTIME_TESTED artifacts=8') 'ml_artifacts_not_ready'
        Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_ml_readiness'
    }
    $script:stage = 'immich_pristine'
    $initial = Get-ImmichCounts
    Assert-Exact ($initial.Users -eq 0 -and $initial.Libraries -eq 0 -and $initial.Assets -eq 0 -and $initial.Memories -eq 0) 'immich_pristine_required'

    New-Item -ItemType Directory -Path $workDirectory -Force | Out-Null
    $script:stage = if ($useRuntimePeopleSearch) { 'piwigo_people_prepare' } else { 'piwigo_snapshot' }
    $fixturePrepareAction = if ($useRuntimePeopleSearch) { 'prepare' } else { 'snapshot' }
    $snapshot = Invoke-PiwigoFixture $fixturePrepareAction $run
    $fixturePrepared = $true
    Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_fixture_prepare'
    if ($useRuntimePeopleSearch) {
        Assert-Exact ([bool]$snapshot.ok -and @($snapshot.photos).Count -eq 32 -and @($snapshot.catalog).Count -eq ($baselineOriginalCount + @($snapshot.photos).Count)) 'piwigo_people_prepare_invalid'
        Assert-Exact (@($snapshot.photos | Where-Object { $_.era -eq 'HERITAGE' }).Count -eq 18) 'piwigo_people_heritage_count_invalid'
        Assert-Exact (@($snapshot.photos | Where-Object { $_.era -eq 'LIVING' }).Count -eq 14) 'piwigo_people_living_count_invalid'
    } else {
        Assert-Exact ([bool]$snapshot.ok -and @($snapshot.photos).Count -ge 2) 'piwigo_snapshot_invalid'
    }

    $script:stage = 'stage_node_input'
    $nodeInput = [ordered]@{
        version = 1
        email = "immich-gateway-$run@synthetic.invalid"
        password = New-SecretText
        name = 'Class Archive Internal Runtime User'
        libraryName = if ($useRuntimePeopleSearch) { "Class Archive Fictional People Gateway $run" } else { "Class Archive Gateway Synthetic $run" }
        photos = @($snapshot.photos)
    }
    if ($useRuntimePeopleSearch) {
        $nodeInput['expectedCatalogAssets'] = $baselineOriginalCount + @($snapshot.photos).Count
        $nodeInput['catalog'] = @($snapshot.catalog)
        $serializedInput = $nodeInput | ConvertTo-Json -Compress -Depth 8 | ConvertFrom-Json -ErrorAction Stop
        $inputKeys = @($serializedInput.PSObject.Properties.Name | Sort-Object) -join ','
        Assert-Exact ($inputKeys -eq 'catalog,email,expectedCatalogAssets,libraryName,name,password,photos,version' -and $serializedInput.version -eq 1 -and @($serializedInput.photos).Count -eq 32 -and @($serializedInput.catalog).Count -eq ($baselineOriginalCount + @($snapshot.photos).Count) -and [int]$serializedInput.expectedCatalogAssets -eq ($baselineOriginalCount + @($snapshot.photos).Count)) 'runtime_node_input_shape_invalid'
    }
    Write-OwnerOnlyJson $hostNodeInput $nodeInput
    $script:stage = 'stage_node_fixture'
    [void](Invoke-SpikeCompose @('cp', $nodeFixturePath, ('immich-server:' + $nodeFixtureContainer)))
    [void](Invoke-SpikeCompose @('cp', ('.codex-work/immich-gateway/' + $run + '/node-input.json'), ('immich-server:' + $nodeInputContainer)))
    [void](Invoke-SpikeCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('chown 65532:65532 ' + $nodeFixtureContainer + ' ' + $nodeInputContainer + ' && chmod 0555 ' + $nodeFixtureContainer + ' && chmod 0600 ' + $nodeInputContainer)))
    if ($useRuntimePeopleSearch) {
        $nodeInputMetadata = (Invoke-SpikeCompose @('exec', '-T', '--user', '65532:65532', 'immich-server', 'sh', '-lc', ('stat -c ''%a|%u|%g|%s'' ' + $nodeInputContainer))).Trim()
        Assert-Exact ($nodeInputMetadata -match '^600\|65532\|65532\|[1-9][0-9]{1,131071}$') 'runtime_node_input_permissions_invalid'
    }
    $script:stage = if ($useRuntimePeopleSearch) { 'immich_people_face_search_runtime' } else { 'immich_technical_library' }
    # Keep fixed Linux `/tmp` paths out of the Windows -> WSL native argument
    # boundary. The encoded command contains no data from users, photos or
    # credentials and is decoded only by the already-isolated container.
    $nodeCommand = 'exec node ' + $nodeFixtureContainer + ' --input-file ' + $nodeInputContainer
    $nodeEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($nodeCommand))
    $nodeOutput = Invoke-SpikeCompose @('exec', '-T', '--user', '65532:65532', 'immich-server', 'sh', '-lc', ('echo ' + $nodeEncoded + ' | base64 -d | sh'))
    Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_immich_runtime'
    if ($useRuntimePeopleSearch) {
        $nodePass = [regex]::Match($nodeOutput, '^IMMICH_PEOPLE_SEARCH_RUNTIME=PASS assets=([0-9]+) catalog_assets=([0-9]+) people=([0-9]+) face_jobs=([0-9]+) recognition_jobs=([0-9]+) smart_jobs=([0-9]+)$', [Text.RegularExpressions.RegexOptions]::Multiline)
        Assert-Exact ($nodePass.Success -and [int]$nodePass.Groups[1].Value -eq @($snapshot.photos).Count -and [int]$nodePass.Groups[2].Value -eq ($baselineOriginalCount + @($snapshot.photos).Count) -and [int]$nodePass.Groups[3].Value -ge 3) 'immich_people_runtime_fixture'
    } else {
        $nodePass = [regex]::Match($nodeOutput, '^IMMICH_GATEWAY_RUNTIME=PASS assets=([0-9]+) memory=1$', [Text.RegularExpressions.RegexOptions]::Multiline)
        Assert-Exact ($nodePass.Success -and [int]$nodePass.Groups[1].Value -eq @($snapshot.photos).Count) 'immich_gateway_runtime_fixture'
    }
    [void](Invoke-SpikeCompose @('cp', ('immich-server:' + $nodeOutputContainer), ('.codex-work/immich-gateway/' + $run + '/node-output.json')))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $hostNodeOutput
    $nodeResult = Get-Content -LiteralPath $hostNodeOutput -Raw | ConvertFrom-Json -ErrorAction Stop
    $expectedBindingCount = if ($useRuntimePeopleSearch) { $baselineOriginalCount + @($snapshot.photos).Count } else { @($snapshot.photos).Count }
    Assert-Exact ($nodeResult.version -eq 1 -and @($nodeResult.assets).Count -eq $expectedBindingCount -and [string]$nodeResult.access_token -match '^[A-Za-z0-9._~-]{32,8192}$') 'immich_runtime_result_invalid'
    if ($useRuntimePeopleSearch) {
        Assert-Exact (@($nodeResult.fixture_assets).Count -eq @($snapshot.photos).Count -and @($nodeResult.people).Count -ge 3 -and @($nodeResult.runtime.search_results).Count -eq 8 -and [int]$nodeResult.runtime.search_candidate_limit -eq 500 -and [int]$nodeResult.runtime.search_result_limit -eq 50 -and [int]$nodeResult.runtime.library_asset_count -eq ($baselineOriginalCount + @($snapshot.photos).Count)) 'immich_people_runtime_result_invalid'
    }
    $assetIds = @($nodeResult.assets | ForEach-Object { [string]$_.immich_asset_id })
    Assert-Exact ($assetIds.Count -eq $expectedBindingCount -and ($assetIds | Select-Object -Unique).Count -eq $expectedBindingCount) 'immich_asset_bindings_invalid'

    $script:stage = 'piwigo_bindings'
    $bindingResult = Invoke-PiwigoFixture 'bind' $run ([ordered]@{ version = 1; assets = @($nodeResult.assets) })
    Assert-Exact ([bool]$bindingResult.ok) 'piwigo_binding_failed'

    $script:stage = 'bridge_secret'
    $bridgeToken = New-SecretText
    Write-BridgeSecretToStager $bridgeToken ([string]$nodeResult.access_token)
    $script:stage = 'start_internal_bridge'
    $bridgeProfiles = @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration')
    if ($useRuntimePeopleSearch) { $bridgeProfiles += @('--profile', 'immich-ml') }
    try {
        [void](Invoke-SpikeCompose @($bridgeProfiles + @('up', '-d', 'immich-gateway')))
    } catch {
        throw ('bridge_start_' + (Get-BridgeStartupCode))
    }
    $bridgeStarted = $true
    Start-Sleep -Seconds 1
    $bridgeRecord = Get-BridgeRuntimeRecord
    if ($null -eq $bridgeRecord -or $bridgeRecord.State -ne 'running') {
        throw ('bridge_start_' + (Get-BridgeStartupCode))
    }
    $bridgeContainer = [string]$bridgeRecord.ID
    Assert-Exact ($bridgeContainer -match '^[a-f0-9]{64}$') 'bridge_container_invalid'
    $script:stage = 'network_isolation'
    $bridgeNetworks = Invoke-UbuntuDocker @('inspect', $bridgeContainer, '--format', '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}};{{end}}')
    $bridgeNetworkSet = @($bridgeNetworks -split ';' | Where-Object { $_ -ne '' } | Sort-Object)
    Assert-Exact ($bridgeNetworkSet.Count -eq 2 -and $bridgeNetworkSet -contains 'class-archive-immich-spike_immich_bridge_internal' -and $bridgeNetworkSet -contains 'class_archive_immich_gateway') 'bridge_network_scope_invalid'
    $bridgeInternal = (Invoke-UbuntuDocker @('network', 'inspect', 'class-archive-immich-spike_immich_bridge_internal', '--format', '{{.Internal}}')).Trim()
    Assert-Exact ($bridgeInternal -eq 'true') 'bridge_network_not_internal'
    $dnsScript = 'const dns=require("node:dns");let n=2,r=[];for(const x of ["database","redis"]){dns.lookup(x,e=>{r.push(e?"DENIED":"RESOLVED");if(!--n){console.log(r.join("|"));clearTimeout(t)}})}const t=setTimeout(()=>process.exit(3),5000)'
    $dnsEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($dnsScript))
    $dnsCommand = 'echo ' + $dnsEncoded + ' | base64 -d | node'
    $bridgeDns = (Invoke-UbuntuDocker @('exec', '--user', '65532:65532', $bridgeContainer, 'sh', '-lc', $dnsCommand)).Trim()
    Assert-Exact ($bridgeDns -eq 'DENIED|DENIED') 'bridge_database_or_redis_dns_visible'
    $bridgePorts = Invoke-UbuntuDocker @('inspect', $bridgeContainer, '--format', '{{json .NetworkSettings.Ports}}')
    try {
        $bridgePortMap = $bridgePorts | ConvertFrom-Json -ErrorAction Stop
    } catch {
        throw 'bridge_port_inspect_invalid'
    }
    # Docker records an internal `expose: 8080` as {"8080/tcp":null}. That is
    # not a host listener. Reject only a non-empty published binding; this keeps
    # the bridge reachable solely from its two internal Docker networks.
    $publishedBridgePorts = @()
    if ($null -ne $bridgePortMap) {
        $publishedBridgePorts = @($bridgePortMap.PSObject.Properties | Where-Object {
            $null -ne $_.Value -and @($_.Value).Count -gt 0
        })
    }
    Assert-Exact ($publishedBridgePorts.Count -eq 0) 'bridge_host_port_present'
    $bridgeMounts = Invoke-UbuntuDocker @('inspect', $bridgeContainer, '--format', '{{range .Mounts}}{{.Name}}|{{.Source}};{{end}}')
    Assert-Exact (-not $bridgeMounts.Contains('class_archive_piwigo_uploads') -and -not $bridgeMounts.Contains('class_archive_piwigo_galleries')) 'bridge_has_piwigo_original_mount'
    $piwigoContainer = (Get-PiwigoContainerRecord).Id
    $piwigoNetworks = Invoke-UbuntuDocker @('inspect', $piwigoContainer, '--format', '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}};{{end}}')
    Assert-Exact ($piwigoNetworks.Contains('class_archive_immich_gateway;') -and -not $piwigoNetworks.Contains('class-archive-immich-spike_immich_internal;') -and -not $piwigoNetworks.Contains('class-archive-immich-spike_immich_bridge_internal;')) 'piwigo_network_scope_invalid'

    $script:stage = 'enable_bridge'
    $enabled = Invoke-PiwigoFixture 'enable' $run ([ordered]@{ version = 1; token = $bridgeToken })
    Assert-Exact ([bool]$enabled.ok) 'bridge_enable_failed'
    Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_bridge_enable'
    Rebuild-BridgeBackedReadProjections
    Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_bridge_projection_rebuild'
    $bridgeToken = $null
    if ($BrowserE2E.IsPresent) {
        $script:stage = 'start_compatibility_browser_shell'
        Start-CompatibilityWebForBrowserE2E
        Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_compatibility_shell'
    }
    if (-not $useRuntimePeopleSearch) { $nodeResult = $null }

    $script:stage = 'bridge_adapter_probe'
    if (-not $useRuntimePeopleSearch) {
        $adapterProbe = Invoke-PiwigoFixture 'probe' $run
        if (-not [bool]$adapterProbe.ok) {
            $probeCode = [string]$adapterProbe.code
            if ($probeCode -notmatch '^[a-z_]{1,64}$') { $probeCode = 'invalid' }
            throw ('bridge_adapter_probe_' + $probeCode)
        }
    } else {
        $adapterProbe = Invoke-PiwigoFixture 'probe' $run
        if (-not [bool]$adapterProbe.ok -or [int]$adapterProbe.people -lt 3) {
            $probeCode = [string]$adapterProbe.code
            if ($probeCode -notmatch '^[a-z_]{1,64}$') { $probeCode = 'invalid' }
            throw ('runtime_people_adapter_probe_' + $probeCode)
        }
        $requestProbe = Invoke-PiwigoFixture 'request-probe' $run ([ordered]@{
            version = 1
            class_photo_ids = @($nodeResult.assets | ForEach-Object { [string]$_.class_photo_id })
        })
        if (-not [bool]$requestProbe.ok -or [int]$requestProbe.people -lt 3) {
            $probeCode = [string]$requestProbe.code
            if ($probeCode -notmatch '^[a-z_]{1,64}$') { $probeCode = 'invalid' }
            throw ('runtime_people_request_probe_' + $probeCode)
        }
    }

    $script:stage = 'fixture_accounts'
    $fixturePassword = New-SecretText
    Provision-PiwigoFixtureAccounts $fixturePassword
    Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_fixture_accounts'
    $sessions['CLASSMATE'] = Login-Role $wsUri 'fixture-classmate' $fixturePassword
    $sessions['FAMILY'] = Login-Role $wsUri 'fixture-family' $fixturePassword
    if ($useRuntimePeopleSearch) {
        $sessions['TEACHER'] = Login-Role $wsUri 'fixture-teacher' $fixturePassword
        $sessions['ANONYMOUS'] = Login-Role $wsUri 'fixture-anonymous' $fixturePassword
        $script:stage = 'media_guard_fixture_policy'
        $mediaProbe = Invoke-PiwigoFixture 'media-probe' $run
        if (-not [bool]$mediaProbe.ok -or [int]$mediaProbe.checks -ne 8) {
            $mediaProbeCode = [string]$mediaProbe.code
            if ($mediaProbeCode -notmatch '^[a-z_]{1,64}$') { $mediaProbeCode = 'invalid' }
            throw ('runtime_media_guard_fixture_policy_' + $mediaProbeCode)
        }
    }
    if (-not $BrowserE2E.IsPresent) { $fixturePassword = $null }

    $script:stage = 'bridge_gateway_http'
    $references = if ($useRuntimePeopleSearch) {
        @($snapshot.catalog | ForEach-Object { [string]$_.media_reference })
    } else {
        @($snapshot.photos | ForEach-Object { [string]$_.media_reference })
    }
    if ($useRuntimePeopleSearch) {
        $photoById = @{}
        $assetToPhoto = @{}
        foreach ($photo in @($snapshot.catalog)) {
            $photoById[[string]$photo.class_photo_id] = $photo
        }
        foreach ($binding in @($nodeResult.assets)) {
            $canonical = [string]$binding.class_photo_id
            $asset = [string]$binding.immich_asset_id
            Assert-Exact ($photoById.ContainsKey($canonical) -and $asset -match '^[0-9a-f]{8}-') 'runtime_asset_binding_invalid'
            $assetToPhoto[$asset] = $photoById[$canonical]
        }
        $allVisible = @{}
        $heritageVisible = @{}
        $livingIds = @()
        foreach ($photo in @($snapshot.catalog)) {
            $id = [string]$photo.class_photo_id
            $allVisible[$id] = $true
            if ([string]$photo.era -eq 'HERITAGE') { $heritageVisible[$id] = $true } else { $livingIds += $id }
        }
        $classmateCounts = @()
        $familyCounts = @()
        foreach ($cluster in @($nodeResult.people)) {
            $members = @($cluster.asset_ids)
            $known = @()
            foreach ($asset in $members) {
                $key = [string]$asset
                Assert-Exact ($assetToPhoto.ContainsKey($key)) 'runtime_person_member_unmapped'
                $known += $assetToPhoto[$key]
            }
            Assert-Exact ($known.Count -ge 1) 'runtime_person_member_empty'
            $classmateCounts += $known.Count
            $familyCounts += @($known | Where-Object { [string]$_.era -eq 'HERITAGE' }).Count
        }
        Assert-Exact ($classmateCounts.Count -ge 3 -and (@($classmateCounts | Where-Object { $_ -gt 0 }).Count -eq $classmateCounts.Count)) 'runtime_person_clusters_invalid'

        $classmatePeople = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/people')) $sessions['CLASSMATE']) 'classmate_people'
        $familyPeople = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/people')) $sessions['FAMILY']) 'family_people'
        $teacherPeople = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/people')) $sessions['TEACHER']) 'teacher_people'
        $anonymousPeople = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/people')) $sessions['ANONYMOUS']) 'anonymous_people'
        Assert-PeopleProjection $classmatePeople $classmateCounts $allVisible $assetIds $references 'classmate_people'
        Assert-PeopleProjection $teacherPeople $classmateCounts $allVisible $assetIds $references 'teacher_people'
        Assert-PeopleProjection $anonymousPeople $classmateCounts $allVisible $assetIds $references 'anonymous_people'
        Assert-PeopleProjection $familyPeople $familyCounts $heritageVisible $assetIds $references 'family_people'
        Assert-PersonDetails $baseUri $sessions['CLASSMATE'] $classmatePeople $allVisible $assetIds $references 'classmate_people'
        Assert-PersonDetails $baseUri $sessions['FAMILY'] $familyPeople $heritageVisible $assetIds $references 'family_people'

        $smartByName = @{}
        foreach ($entry in @($nodeResult.runtime.search_results)) {
            $name = [string]$entry.name
            $members = @()
            foreach ($asset in @($entry.asset_ids)) {
                $key = [string]$asset
                if ($assetToPhoto.ContainsKey($key)) { $members += $assetToPhoto[$key] }
            }
            $smartByName[$name] = [pscustomobject]@{ Query = [string]$entry.query; Photos = $members }
        }
        foreach ($name in @('zh_basketball', 'en_basketball')) {
            Assert-Exact ($smartByName.ContainsKey($name) -and -not [string]::IsNullOrWhiteSpace([string]$smartByName[$name].Query)) ('runtime_smart_query_missing_' + $name)
            $query = [Uri]::EscapeDataString([string]$smartByName[$name].Query)
            $classmateSearch = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, ('api/search/smart?q=' + $query))) $sessions['CLASSMATE']) ('classmate_smart_' + $name)
            $familySearch = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, ('api/search/smart?q=' + $query))) $sessions['FAMILY']) ('family_smart_' + $name)
            $expectedAll = @($smartByName[$name].Photos | Select-Object -First 50)
            $expectedFamily = @($smartByName[$name].Photos | Where-Object { [string]$_.era -eq 'HERITAGE' } | Select-Object -First 50)
            Assert-Exact ([bool]$classmateSearch.available -and [int]$classmateSearch.total -eq $expectedAll.Count -and @($classmateSearch.items).Count -eq $expectedAll.Count) ('classmate_smart_count_invalid_' + $name)
            Assert-Exact ([bool]$familySearch.available -and [int]$familySearch.total -eq $expectedFamily.Count -and @($familySearch.items).Count -eq $expectedFamily.Count) ('family_smart_count_invalid_' + $name)
            foreach ($item in @($classmateSearch.items)) { Assert-Exact ($allVisible.ContainsKey([string]$item.id)) ('classmate_smart_visibility_invalid_' + $name) }
            foreach ($item in @($familySearch.items)) { Assert-Exact ($heritageVisible.ContainsKey([string]$item.id)) ('family_smart_visibility_invalid_' + $name) }
            Assert-NoInternalLeak ($classmateSearch | ConvertTo-Json -Compress -Depth 12) $assetIds $references ('classmate_smart_' + $name)
            Assert-NoInternalLeak ($familySearch | ConvertTo-Json -Compress -Depth 12) $assetIds $references ('family_smart_' + $name)
        }
        Assert-Exact ($smartByName['en_basketball'].Photos.Count -gt 0) 'runtime_smart_search_no_fixture_result'
        $familyLivingPhoto = [string]$livingIds[0]
        $familyLiving = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, ('api/photos/' + $familyLivingPhoto))) $sessions['FAMILY']) 'family_living_photo_guess' 404
        # The HTTP 404 is the security assertion. Keep the localized response
        # opaque here so this runner remains executable in Windows PowerShell
        # 5.1 even when the repository is UTF-8 without a BOM.
        Assert-Exact ($familyLiving.error -is [string] -and $familyLiving.error.Length -gt 0) 'family_living_photo_guess_error_invalid'
        $familyLivingMedia = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, ('api/photos/' + $familyLivingPhoto + '/media/thumbnail'))) $sessions['FAMILY']) 'family_living_media_guess' 404
        Assert-Exact ($familyLivingMedia.error -is [string] -and $familyLivingMedia.error.Length -gt 0) 'family_living_media_guess_error_invalid'
        if ($BrowserE2E.IsPresent) {
            $script:stage = 'chromium_people_search_browser'
            $browserResult = Invoke-CompatibilityBrowserE2E $fixturePassword $familyLivingPhoto
            Assert-PiwigoLifecycleUnchanged $piwigoRecordBefore 'after_browser'
            Assert-Exact ($browserResult -match '^IMMICH_RUNTIME_AI_BROWSER=PASS evidence=BROWSER_E2E_TESTED ') 'browser_e2e_result_invalid'
            $browserPassed = $true
        }

        $script:stage = 'runtime_search_fail_closed'
        $faultQuery = [Uri]::EscapeDataString([string]$smartByName['en_basketball'].Query)
        $faultSearchUri = [Uri]::new($baseUri, ('api/search/smart?q=' + $faultQuery))
        $baselineFaultSearch = Read-GatewayJson (Invoke-Gateway $faultSearchUri $sessions['CLASSMATE']) 'fault_baseline_search'
        $fixturePhotoIds = @{}
        foreach ($photo in @($snapshot.photos)) { $fixturePhotoIds[[string]$photo.class_photo_id] = $true }
        $faultTarget = @($baselineFaultSearch.items | Where-Object { $fixturePhotoIds.ContainsKey([string]$_.id) } | Select-Object -First 1)
        Assert-Exact ($faultTarget.Count -eq 1 -and [string]$faultTarget[0].id -match '^[0-9a-f-]{36}$') 'fault_target_search_result_missing'
        $faultPayload = [ordered]@{ version = 1; class_photo_id = [string]$faultTarget[0].id }

        $mappingFaultStarted = $false
        try {
            $fault = Invoke-PiwigoFixture 'fault-mapping-start' $run $faultPayload
            Assert-Exact ([bool]$fault.ok -and -not [bool]$fault.restored) 'mapping_fault_start_failed'
            $mappingFaultStarted = $true
            Assert-SearchUnavailable $faultSearchUri $sessions['CLASSMATE'] 'mapping_missing_search'
        } finally {
            if ($mappingFaultStarted) {
                $restored = Invoke-PiwigoFixture 'fault-mapping-stop' $run $faultPayload
                Assert-Exact ([bool]$restored.ok -and [bool]$restored.restored) 'mapping_fault_restore_failed'
            }
        }

        $eraFaultStarted = $false
        try {
            $fault = Invoke-PiwigoFixture 'fault-era-start' $run $faultPayload
            Assert-Exact ([bool]$fault.ok -and -not [bool]$fault.restored) 'era_fault_start_failed'
            $eraFaultStarted = $true
            # The opposite-root association is a native Piwigo mutation. Its
            # durable trigger rotates the source epoch and makes the complete
            # catalog unavailable immediately; serving a filtered 200 from an
            # older generation would be a stale-policy bypass. A generic 503
            # is therefore the required fail-closed result until rebuild.
            Assert-SearchUnavailable $faultSearchUri $sessions['CLASSMATE'] 'ambiguous_era_search'
            $ambiguousPhotoUri = [Uri]::new($baseUri, ('api/photos/' + [string]$faultTarget[0].id))
            $ambiguousPhoto = Read-GatewayJson (Invoke-Gateway $ambiguousPhotoUri $sessions['CLASSMATE']) 'ambiguous_era_photo' 503
            Assert-Exact ($ambiguousPhoto.error -is [string] -and $ambiguousPhoto.error.Length -gt 0) 'ambiguous_era_photo_not_unavailable'
        } finally {
            if ($eraFaultStarted) {
                $restored = Invoke-PiwigoFixture 'fault-era-stop' $run $faultPayload
                Assert-Exact ([bool]$restored.ok -and [bool]$restored.restored) 'era_fault_restore_failed'
                # The second native mutation restores business truth but also
                # advances the source epoch. Publish a verified generation in
                # a separate write-side process before testing later faults.
                Invoke-PiwigoReadProjectionRebuild
            }
        }

        $profiles = @('--profile', 'immich-spike', '--profile', 'immich-ml', '--profile', 'immich-gateway-integration')
        # Reusing an earlier query would exercise Immich's in-process text
        # embedding cache and could legitimately succeed while ML is down.
        # A per-run synthetic query forces a new textual embedding request.
        $mlFaultQuery = [Uri]::EscapeDataString('synthetic offline model probe ' + $run)
        $mlFaultSearchUri = [Uri]::new($baseUri, ('api/search/smart?q=' + $mlFaultQuery))
        $mlStopped = $false
        try {
            [void](Invoke-SpikeCompose @($profiles + @('stop', 'immich-machine-learning')))
            $mlStopped = $true
            Assert-SearchUnavailable $mlFaultSearchUri $sessions['CLASSMATE'] 'ml_unavailable_search'
        } finally {
            if ($mlStopped) { [void](Invoke-SpikeCompose @($profiles + @('up', '-d', '--wait', '--wait-timeout', '180', 'immich-machine-learning'))) }
        }

        $serverStopped = $false
        try {
            [void](Invoke-SpikeCompose @($profiles + @('stop', 'immich-server')))
            $serverStopped = $true
            Assert-SearchUnavailable $faultSearchUri $sessions['CLASSMATE'] 'immich_unavailable_search'
        } finally {
            if ($serverStopped) { [void](Invoke-SpikeCompose @($profiles + @('up', '-d', '--wait', '--wait-timeout', '180', 'immich-server'))) }
        }

        $databaseStopped = $false
        try {
            [void](Invoke-PiwigoCompose @('stop', 'db'))
            $databaseStopped = $true
            Assert-SearchUnavailable $faultSearchUri $sessions['CLASSMATE'] 'gateway_database_unavailable_search'
        } finally {
            if ($databaseStopped) {
                [void](Invoke-PiwigoCompose @('up', '-d', '--wait', '--wait-timeout', '120', 'db'))
                [void](Get-ContainerStatus $piwigoRecordBefore.Id)
            }
        }
        $restoredSearch = Read-GatewayJson (Invoke-Gateway $faultSearchUri $sessions['CLASSMATE']) 'fault_restored_search'
        Assert-Exact ([int]$restoredSearch.total -eq [int]$baselineFaultSearch.total) 'fault_restore_search_drift'
        $searchFailClosedPassed = $true
    } else {
        $classmateMemories = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/memories')) $sessions['CLASSMATE']) 'classmate_memories'
        $familyMemories = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/memories')) $sessions['FAMILY']) 'family_memories'
        Assert-Exact ([bool]$classmateMemories.available -and [int]$classmateMemories.total -eq 1) 'classmate_memory_aggregation_invalid'
        Assert-Exact ([bool]$familyMemories.available -and [int]$familyMemories.total -eq 1) 'family_memory_aggregation_invalid'
        Assert-Exact ([int]$classmateMemories.items[0].photo_count -eq 2) 'classmate_memory_visible_count_invalid'
        Assert-Exact ([int]$familyMemories.items[0].photo_count -eq 1) 'family_memory_living_side_channel'
        Assert-NoInternalLeak ($classmateMemories | ConvertTo-Json -Compress -Depth 8) $assetIds $references 'classmate_memories'
        Assert-NoInternalLeak ($familyMemories | ConvertTo-Json -Compress -Depth 8) $assetIds $references 'family_memories'

        $classmatePeople = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/people')) $sessions['CLASSMATE']) 'classmate_people'
        $familyPeople = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/people')) $sessions['FAMILY']) 'family_people'
        Assert-Exact ([bool]$classmatePeople.available -and [int]$classmatePeople.total -eq 0) 'classmate_people_runtime_invalid'
        Assert-Exact ([bool]$familyPeople.available -and [int]$familyPeople.total -eq 0) 'family_people_runtime_invalid'
        Assert-NoInternalLeak ($classmatePeople | ConvertTo-Json -Compress -Depth 8) $assetIds $references 'classmate_people'
        Assert-NoInternalLeak ($familyPeople | ConvertTo-Json -Compress -Depth 8) $assetIds $references 'family_people'
        $noMedia = Invoke-Gateway ([Uri]::new($baseUri, 'api/media')) $sessions['FAMILY']
        $null = Read-GatewayJson $noMedia 'gateway_media_route_absent' 404
    }

    $script:stage = 'original_fingerprint_after_scan'
    $afterScan = Get-PiwigoOriginalFingerprints
    foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
        if ($useRuntimePeopleSearch) {
            $root = if ($path -like '*/upload') { 'upload/' } else { 'galleries/' }
            $fixtureOriginalCount = @($snapshot.photos | Where-Object { $_.media_reference -like ($root + '*') }).Count
            Assert-Exact ($afterScan[$path].Count -eq ($beforeOriginals[$path].Count + $fixtureOriginalCount)) 'piwigo_runtime_original_count_invalid'
        } else {
            Assert-Exact ($beforeOriginals[$path].Count -eq $afterScan[$path].Count -and $beforeOriginals[$path].Digest -eq $afterScan[$path].Digest) 'piwigo_originals_changed'
        }
    }
} catch {
    $failure = $_
    $failureStage = $script:stage
} finally {
    foreach ($session in @($sessions.Values)) {
        try { Invoke-Logout $wsUri $session } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'logout' } }
    }
    if ($fixturePrepared) {
        # Business cleanup mutates MyISAM and InnoDB state and therefore runs
        # exactly once. Projection publication is a separate idempotent phase
        # in a fresh PHP process, so it observes the restored bridge config and
        # only that safe publish step receives a bounded retry.
        try {
            $script:stage = 'cleanup_piwigo_fixture'
            $cleanup = Invoke-PiwigoFixture 'cleanup' $run
            Assert-Exact ([bool]$cleanup.ok) 'piwigo_fixture_cleanup_invalid'
            Invoke-PiwigoReadProjectionRebuild
        } catch {
            $fixtureCleanupFailure = $_
            if ($null -eq $failure) {
                $failure = $fixtureCleanupFailure
                $failureStage = 'cleanup_piwigo_fixture'
            }
        }
    }
    foreach ($path in @($hostNodeInput, $hostNodeOutput, $piwigoInput, $hostFixturePassword)) {
        try { Remove-ExactFile $path } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_host_secret' } }
    }
    if ($null -ne $piwigoStagedInput) {
        try { [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('test ! -e ' + $piwigoStagedInput + ' || rm -f -- ' + $piwigoStagedInput))) } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_piwigo_input' } }
    }
    if ($null -ne $piwigoFixturePasswordPath) {
        try { [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('test ! -e ' + $piwigoFixturePasswordPath + ' || rm -f -- ' + $piwigoFixturePasswordPath + '; test ! -e ' + $piwigoFixturePasswordPath))) } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_piwigo_fixture_password' } }
    }
    try {
        [void](Invoke-SpikeCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('rm -f -- ' + $nodeFixtureContainer + ' ' + $nodeInputContainer + ' ' + $nodeOutputContainer)))
    } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_immich_files' } }
    if ($spikeStateChanged) {
        try {
            $script:stage = 'cleanup_immich_reset'
            Reset-ImmichSpike
            if ($null -ne $piwigoRecordBefore) {
                $piwigoRecordAfterCleanup = Get-PiwigoContainerRecord
                Assert-Exact ($piwigoRecordAfterCleanup.Id -eq $piwigoRecordBefore.Id -and $piwigoRecordAfterCleanup.StartedAt -eq $piwigoRecordBefore.StartedAt) 'piwigo_container_restarted_by_spike_cleanup'
                [void](Get-ContainerStatus $piwigoRecordAfterCleanup.Id)
            }
            Assert-BridgeSecretVolumeAbsent
            $empty = Get-ImmichCounts
            Assert-Exact ($empty.Users -eq 0 -and $empty.Libraries -eq 0 -and $empty.Assets -eq 0 -and $empty.Memories -eq 0) 'immich_reset_not_empty'
            if ($null -ne $beforeOriginals) {
                $afterReset = Get-PiwigoOriginalFingerprints
                foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
                    Assert-Exact ($beforeOriginals[$path].Count -eq $afterReset[$path].Count -and $beforeOriginals[$path].Digest -eq $afterReset[$path].Digest) 'piwigo_originals_changed_after_reset'
                }
            }
        } catch { if ($null -eq $failure) { $failure = $_; $failureStage = $script:stage } }
    }
    try {
        if ((Test-Path -LiteralPath $workDirectory -PathType Container)) {
            if (@(Get-ChildItem -LiteralPath $workDirectory -Force).Count -ne 0) {
                throw 'work_directory_not_empty'
            }
            Remove-Item -LiteralPath $workDirectory -Force -ErrorAction Stop
            if (Test-Path -LiteralPath $workDirectory) {
                throw 'work_directory_cleanup_unproven'
            }
        }
    } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_work_directory' } }
    $fixturePassword = $null
}

if ($null -ne $failure) {
    if ($null -ne $fixtureCleanupFailure) {
        $cleanupCode = Get-SafeFailureCode $fixtureCleanupFailure
        [Console]::Error.WriteLine(('IMMICH_GATEWAY_FIXTURE_CLEANUP=FAIL evidence=RUNTIME_TESTED code=' + $cleanupCode))
    }
    $safeStage = if ($null -ne $failureStage -and $failureStage -ne '') { $failureStage } else { $script:stage }
    $failureType = [string]$failure.Exception.GetType().Name
    if ($failureType -notmatch '\A[A-Za-z0-9_]{1,64}\z') { $failureType = 'Unknown' }
    $safeCode = Get-SafeFailureCode $failure
    [Console]::Error.WriteLine(('IMMICH_GATEWAY_BRIDGE=FAIL evidence=RUNTIME_TESTED reason=stage_' + $safeStage + ' code=' + $safeCode + ' type=' + $failureType))
    exit 1
}

Write-Output "IMMICH_GATEWAY_BRIDGE=PASS evidence=RUNTIME_TESTED assertions=$script:assertions probes=$script:probes"
if ($useRuntimePeopleSearch) {
    Write-Output 'IMMICH_RUNTIME_AI_ACL=PASS evidence=RUNTIME_TESTED people_count_filter=PASS person_thumbnail_filter=PASS smart_search_count_filter=PASS roles=CLASSMATE_FAMILY_TEACHER_ANONYMOUS'
    if (-not $searchFailClosedPassed) { throw 'search_fail_closed_completion_missing' }
    Write-Output 'IMMICH_RUNTIME_SEARCH_FAIL_CLOSED=PASS evidence=RUNTIME_TESTED ml_unavailable=503 immich_unavailable=503 mapping_missing=503 ambiguous_era=503 gateway_db=503'
    if ($BrowserE2E.IsPresent) {
        if (-not $browserPassed) { throw 'browser_e2e_completion_missing' }
        Write-Output $browserResult
    }
} else {
    Write-Output 'IMMICH_GATEWAY_ACL_AGGREGATION=PASS classmate_memory=2 family_memory=1 people=actual_empty'
}
Write-Output 'IMMICH_GATEWAY_MEDIA_PATH=MEDIAGUARD_REQUIRED no_bridge_media_route=PASS'
Write-Output 'IMMICH_GATEWAY_CLEANUP=PASS spike_state=empty originals_unchanged=PASS'
