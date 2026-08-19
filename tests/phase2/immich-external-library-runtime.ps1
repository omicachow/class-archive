[CmdletBinding()]
param()

# A deliberately disposable true-runtime test. It provisions Immich's first
# technical user only inside the internal network, creates an external library
# over the two read-only Piwigo original volumes, scans it, and then destroys
# ONLY the named Immich spike volumes before recreating an empty runtime. It
# never maps a browser route, prints a credential, retains an API key, or lets
# Immich write Piwigo originals.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$spikeCompose = 'infra/immich-spike/docker-compose.yml'
$spikeEnv = 'infra/immich-spike/.env'
$runtimeIsolation = Join-Path $projectRoot 'tests\phase2\immich-runtime-isolation.ps1'
$fixturePath = Join-Path $projectRoot 'tests\phase2\immich-technical-user-library.mjs'
$fixtureDestination = '/tmp/class-archive-immich-technical-library.mjs'
$fixtureInputDestination = '/tmp/class-archive-immich-technical-library-input.json'
$secretFileAclScript = Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1'
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoEnv = '.env.piwigo'
$assertions = 0
$resetRequired = $false
$failure = $null
$failureStage = $null
$stage = 'initialization'
$hostFixtureInputPath = $null
$containerFixtureInputStaged = $false

function Fail([string]$reason) {
    throw "IMMICH_EXTERNAL_LIBRARY=FAIL evidence=RUNTIME_TESTED reason=$reason assertions=$script:assertions"
}

function Assert-Exact([bool]$condition, [string]$reason) {
    $script:assertions++
    if (-not $condition) {
        Fail $reason
    }
}

function Invoke-UbuntuDocker([string[]]$arguments) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu -- docker @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        throw ('docker_command_failed_' + ($arguments -join '_'))
    }
    return [string]::Join("`n", $lines)
}

function Get-DockerInspect([string]$name) {
    $json = Invoke-UbuntuDocker @('inspect', $name)
    $parsed = $json | ConvertFrom-Json -ErrorAction Stop
    if ($parsed -is [System.Array]) {
        if ($parsed.Count -ne 1) { throw 'docker_inspect_ambiguous' }
        return $parsed[0]
    }
    return $parsed
}

function Invoke-SpikeCompose([string[]]$arguments) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot -- docker compose --env-file $spikeEnv -f $spikeCompose @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        throw ('immich_compose_failed_' + ($arguments -join '_'))
    }
    return [string]::Join("`n", $lines)
}

function Invoke-PiwigoCompose([string[]]$arguments) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot -- docker compose --env-file $piwigoEnv -f $piwigoCompose @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        throw ('piwigo_compose_failed_' + ($arguments -join '_'))
    }
    return [string]::Join("`n", $lines)
}

function Invoke-ProjectPowerShell([string]$scriptPath) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        # Treat Windows PowerShell's native stderr the same way as Docker's:
        # collect it, then judge success only by the child process exit code.
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $scriptPath 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        throw 'project_powershell_script_failed'
    }
    return [string]::Join("`n", $lines)
}

function Invoke-ContainerShell([string]$container, [string]$script) {
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($script))
    return Invoke-UbuntuDocker @('exec', $container, 'sh', '-c', ("echo $encoded | base64 -d | sh"))
}

function Get-PiwigoOriginalFingerprints {
    $script = @'
for p in /var/www/html/piwigo/upload /var/www/html/piwigo/galleries; do
  count=$(find "$p" -type f -printf . | wc -c)
  digest=$(find "$p" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)
  printf 'FINGERPRINT|%s|%s|%s\n' "$p" "$count" "$digest"
done
'@
    $lines = @((Invoke-ContainerShell 'class_archive_piwigo-piwigo-1' $script) -split "`r?`n" | Where-Object { $_ -like 'FINGERPRINT|*' })
    if ($lines.Count -ne 2) { throw 'piwigo_fingerprint_output_invalid' }
    $result = @{}
    foreach ($line in $lines) {
        $parts = $line -split '\|', 4
        if ($parts.Count -ne 4 -or $parts[2] -notmatch '^\d+$' -or $parts[3] -notmatch '^[0-9a-f]{64}$') {
            throw 'piwigo_fingerprint_line_invalid'
        }
        $result[$parts[1]] = @{ Count = [int]$parts[2]; Digest = $parts[3] }
    }
    return $result
}

function Get-LocalImmichEnvValue([string]$name, [string]$fallback) {
    $envPath = Join-Path $projectRoot $spikeEnv
    $matches = @(
        Get-Content -LiteralPath $envPath -ErrorAction Stop | Where-Object {
            $_ -match ('^\s*' + [regex]::Escape($name) + '\s*=\s*([^\s#]+)\s*$')
        }
    )
    if ($matches.Count -gt 1) { throw "duplicate_immich_env_$name" }
    if ($matches.Count -eq 0) { return $fallback }
    $value = [regex]::Match([string]$matches[0], '^\s*[^=]+\s*=\s*([^\s#]+)\s*$').Groups[1].Value
    if ($value -notmatch '^[A-Za-z0-9_.-]+$') { throw "unsafe_immich_env_$name" }
    return $value
}

function Get-ImmichCounts {
    $databaseUser = Get-LocalImmichEnvValue 'DB_USERNAME' 'postgres'
    $databaseName = Get-LocalImmichEnvValue 'DB_DATABASE_NAME' 'immich'
    $query = 'SELECT (SELECT count(*) FROM "user") AS users, (SELECT count(*) FROM library) AS libraries, (SELECT count(*) FROM asset) AS assets;'
    # Pass SQL through base64-encoded stdin rather than as a Windows->WSL
    # command-line argument. In particular, PostgreSQL's quoted "user" table
    # identifier must not be stripped or rewritten by either shell.
    $queryEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($query))
    $command = 'echo ' + $queryEncoded + ' | base64 -d | psql -U ' + $databaseUser + ' -d ' + $databaseName + ' -At'
    $result = Invoke-SpikeCompose @('exec', '-T', 'database', 'sh', '-lc', $command)
    $match = [regex]::Match($result.Trim(), '^([0-9]+)\|([0-9]+)\|([0-9]+)$')
    if (-not $match.Success) { throw 'immich_count_output_invalid' }
    return @{
        Users = [int]($match.Groups[1].Value)
        Libraries = [int]($match.Groups[2].Value)
        Assets = [int]($match.Groups[3].Value)
    }
}

function Get-PiwigoImageCount {
    $output = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/tests/phase0/assert-photo-model.php')
    $match = [regex]::Match($output, '(?m)^IMAGES=([0-9]+)\r?$')
    if (-not $match.Success) { throw 'piwigo_photo_model_count_invalid' }
    return [int]($match.Groups[1].Value)
}

function New-SecretText {
    $bytes = New-Object byte[] 36
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }
    return ([Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_'))
}

function Get-SafeFailureCode([object]$errorRecord) {
    if ($null -eq $errorRecord) { return 'unexpected' }
    $message = [string]$errorRecord.Exception.Message
    if ($message -match '^IMMICH_EXTERNAL_LIBRARY=FAIL evidence=RUNTIME_TESTED reason=([a-z0-9_]+) assertions=[0-9]+$') {
        return $Matches[1]
    }
    if ($message -match '^[a-z0-9_]+$') {
        return $message
    }
    # Native process diagnostics may contain user-controlled values. Keep the
    # persisted/stdout result strictly categorical instead of reflecting them.
    return 'unexpected'
}

function Wait-ImmichReady {
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        try {
            $server = Get-DockerInspect 'class-archive-immich-spike-immich-server-1'
            $database = Get-DockerInspect 'class-archive-immich-spike-database-1'
            $redis = Get-DockerInspect 'class-archive-immich-spike-redis-1'
            if ($server.State.Health.Status -eq 'healthy' -and $database.State.Health.Status -eq 'healthy' -and $redis.State.Health.Status -eq 'healthy') {
                return
            }
        } catch {
            # Container initialization is expected briefly after recreation.
        }
        Start-Sleep -Seconds 1
    }
    throw 'immich_restart_not_healthy'
}

function Reset-ImmichSpike {
    # The compose model owns only these three named, non-external spike volumes.
    # Prove their identities before destructive cleanup; never target Piwigo volumes.
    # The ML profile is intentionally not started for this gate, so its cache
    # volume may not exist. The upload and database volumes must exist because
    # they contain every possible state created by this test.
    $script:stage = 'cleanup_verify_owned_volumes'
    foreach ($volume in @('class_archive_immich_spike_upload', 'class_archive_immich_spike_db')) {
        [void](Invoke-UbuntuDocker @('volume', 'inspect', $volume))
    }
    $script:stage = 'cleanup_compose_down'
    [void](Invoke-SpikeCompose @('--profile', 'immich-spike', 'down', '--volumes', '--remove-orphans'))
    $script:stage = 'cleanup_verify_piwigo_volumes'
    foreach ($piwigoVolume in @('class_archive_piwigo_uploads', 'class_archive_piwigo_galleries')) {
        [void](Invoke-UbuntuDocker @('volume', 'inspect', $piwigoVolume))
    }
    $script:stage = 'cleanup_compose_up'
    [void](Invoke-SpikeCompose @('--profile', 'immich-spike', 'up', '-d'))
    $script:stage = 'cleanup_wait_healthy'
    Wait-ImmichReady
}

try {
    if (-not (Test-Path -LiteralPath $fixturePath -PathType Leaf)) { Fail 'node_fixture_missing' }
    $stage = 'isolation_precondition'
    $isolationOutput = Invoke-ProjectPowerShell $runtimeIsolation
    $isolationOutput -split "`r?`n" | ForEach-Object { if ($_ -ne '') { Write-Output $_ } }
    $stage = 'original_fingerprint'
    $before = Get-PiwigoOriginalFingerprints
    $stage = 'pristine_check'
    $initial = Get-ImmichCounts
    Write-Output ("IMMICH_SPIKE_INITIAL_STATE=users=$($initial.Users) libraries=$($initial.Libraries) assets=$($initial.Assets)")
    Assert-Exact ($initial.Users -eq 0 -and $initial.Libraries -eq 0 -and $initial.Assets -eq 0) 'immich_runtime_not_pristine'
    $stage = 'photo_model'
    $minimumAssets = Get-PiwigoImageCount
    Assert-Exact ($minimumAssets -ge 1) 'piwigo_image_count_invalid'

    $run = ([Guid]::NewGuid().ToString('N')).Substring(0, 16)
    $payload = [ordered]@{
        email = "immich-tech-$run@synthetic.invalid"
        password = New-SecretText
        name = 'Class Archive Runtime Technical User'
        libraryName = "Class Archive Synthetic Library $run"
        minimumAssetCount = $minimumAssets
    } | ConvertTo-Json -Compress
    $resetRequired = $true

    $stage = 'stage_secret_input'
    $inputName = '.immich-technical-library-input-' + $run + '.json'
    $relativeInputPath = '.codex-work/immich-spike/' + $inputName
    $hostFixtureInputPath = Join-Path $projectRoot ($relativeInputPath -replace '/', '\\')
    if (Test-Path -LiteralPath $hostFixtureInputPath) { Fail 'technical_input_path_already_exists' }
    $inputDirectory = Split-Path -Parent $hostFixtureInputPath
    if (-not (Test-Path -LiteralPath $inputDirectory -PathType Container)) {
        [void][IO.Directory]::CreateDirectory($inputDirectory)
    }
    [IO.File]::WriteAllText($hostFixtureInputPath, $payload, [Text.UTF8Encoding]::new($false))
    . $secretFileAclScript
    Set-ClassArchiveOwnerOnlyFileAcl -Path $hostFixtureInputPath

    $stage = 'copy_fixture'
    [void](Invoke-SpikeCompose @('cp', 'tests/phase2/immich-technical-user-library.mjs', "immich-server:$fixtureDestination"))
    $stage = 'copy_secret_input'
    [void](Invoke-SpikeCompose @('cp', $relativeInputPath, "immich-server:$fixtureInputDestination"))
    $stage = 'protect_secret_input'
    [void](Invoke-SpikeCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ("test -f $fixtureInputDestination && chmod 0600 $fixtureInputDestination")))
    $containerFixtureInputStaged = $true
    $stage = 'run_fixture'
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $nodeLines = @(& wsl.exe -d Ubuntu --cd $projectRoot -- docker compose --env-file $spikeEnv -f $spikeCompose exec -T immich-server node $fixtureDestination '--input-file' $fixtureInputDestination 2>&1)
        $nodeExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    $nodeText = [string]::Join("`n", $nodeLines)
    $nodePass = [regex]::Match($nodeText, '^IMMICH_TECHNICAL_LIBRARY=PASS assets=([0-9]+) minimum=([0-9]+)$', [Text.RegularExpressions.RegexOptions]::Multiline)
    if ($nodeExit -ne 0 -or -not $nodePass.Success) {
        $nodeFailure = [regex]::Match($nodeText, '^IMMICH_TECHNICAL_LIBRARY=FAIL reason=([a-z0-9_]+)$', [Text.RegularExpressions.RegexOptions]::Multiline)
        if ($nodeFailure.Success) {
            Fail ('technical_user_library_' + $nodeFailure.Groups[1].Value)
        }
        Fail 'technical_user_library_fixture_failed'
    }
    Assert-Exact ([int]$nodePass.Groups[1].Value -ge $minimumAssets -and [int]$nodePass.Groups[2].Value -eq $minimumAssets) 'technical_library_asset_count_invalid'

    $stage = 'runtime_state_check'
    $during = Get-ImmichCounts
    Assert-Exact ($during.Users -eq 1 -and $during.Libraries -eq 1 -and $during.Assets -ge $minimumAssets) 'technical_library_db_state_invalid'
    $stage = 'post_scan_fingerprint'
    $afterScan = Get-PiwigoOriginalFingerprints
    foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
        Assert-Exact ($before[$path].Count -eq $afterScan[$path].Count -and $before[$path].Digest -eq $afterScan[$path].Digest) 'piwigo_originals_changed_during_external_scan'
    }
} catch {
    $failure = $_
    $failureStage = $stage
} finally {
    if ($containerFixtureInputStaged) {
        try {
            $stage = 'cleanup_remove_staged_input'
            [void](Invoke-SpikeCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ("test ! -e $fixtureInputDestination || rm -f -- $fixtureInputDestination")))
        } catch {
            if ($null -eq $failure) {
                $failure = $_
                $failureStage = $stage
            }
        }
    }
    if ($null -ne $hostFixtureInputPath -and (Test-Path -LiteralPath $hostFixtureInputPath)) {
        try {
            $stage = 'cleanup_remove_host_input'
            Remove-Item -LiteralPath $hostFixtureInputPath -Force -ErrorAction Stop
            if (Test-Path -LiteralPath $hostFixtureInputPath) { throw 'technical_input_host_cleanup_unproven' }
        } catch {
            if ($null -eq $failure) {
                $failure = $_
                $failureStage = $stage
            }
        }
    }
    if ($resetRequired) {
        try {
            $stage = 'cleanup_remove_fixture'
            try { [void](Invoke-SpikeCompose @('exec', '-T', 'immich-server', 'rm', '-f', $fixtureDestination)) } catch {}
            $stage = 'cleanup_down_and_recreate'
            Reset-ImmichSpike
            $stage = 'cleanup_state_check'
            $restored = Get-ImmichCounts
            Assert-Exact ($restored.Users -eq 0 -and $restored.Libraries -eq 0 -and $restored.Assets -eq 0) 'immich_spike_cleanup_not_empty'
            $stage = 'cleanup_fingerprint'
            $afterReset = Get-PiwigoOriginalFingerprints
            foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
                Assert-Exact ($before[$path].Count -eq $afterReset[$path].Count -and $before[$path].Digest -eq $afterReset[$path].Digest) 'piwigo_originals_changed_after_spike_reset'
            }
            $stage = 'post_reset_isolation'
            $isolationOutput = Invoke-ProjectPowerShell $runtimeIsolation
            $isolationOutput -split "`r?`n" | ForEach-Object { if ($_ -ne '') { Write-Output $_ } }
        } catch {
            if ($null -eq $failure) {
                $failure = $_
                $failureStage = $stage
            }
        }
    }
    $payload = $null
}

if ($null -ne $failure) {
    $safeCode = Get-SafeFailureCode $failure
    if ($safeCode -eq 'unexpected') {
        if ($null -ne $failureStage) {
            $safeCode = 'stage_' + $failureStage
        } else {
            $safeCode = 'stage_' + $stage
        }
    }
    [Console]::Error.WriteLine(('IMMICH_EXTERNAL_LIBRARY=FAIL evidence=RUNTIME_TESTED reason=' + $safeCode))
    exit 1
}

Write-Output "IMMICH_EXTERNAL_LIBRARY=PASS evidence=RUNTIME_TESTED assertions=$assertions cleanup=spike_volumes_reset"
Write-Output 'IMMICH_TECHNICAL_USER=EPHEMERAL_INTERNAL_ONLY'
Write-Output 'IMMICH_EXTERNAL_LIBRARY_SCAN=PASS originals_unchanged=PASS'
