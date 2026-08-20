[CmdletBinding()]
param()

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
$piwigoFixturePath = 'tests/phase2/immich-gateway-fixture.php'
$nodeFixturePath = 'tests/phase2/immich-gateway-runtime.mjs'
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
$nodeInputContainer = '/tmp/class-archive-immich-gateway-input.json'
$nodeOutputContainer = '/tmp/class-archive-immich-gateway-output.json'
$nodeFixtureContainer = '/tmp/class-archive-immich-gateway-runtime.mjs'
$piwigoStagedInput = $null
$piwigoFixturePasswordPath = $null
$fixturePrepared = $false
$bridgeStarted = $false
$spikeStateChanged = $false
$beforeOriginals = $null
$failure = $null
$failureStage = $null
$sessions = @{}
$fixturePassword = $null

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
        $fixtureFailure = [regex]::Match($text, '(?m)^IMMICH_GATEWAY_RUNTIME=FAIL reason=([a-z0-9_]{1,96})$')
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
  count=$(find "$p" -type f -printf . | wc -c)
  digest=$(find "$p" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)
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

function Reset-ImmichSpike {
    foreach ($volume in @('class_archive_immich_spike_upload', 'class_archive_immich_spike_db')) {
        [void](Invoke-UbuntuDocker @('volume', 'inspect', $volume))
    }
    [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'down', '--volumes', '--remove-orphans'))
    foreach ($volume in @('class_archive_piwigo_uploads', 'class_archive_piwigo_galleries')) {
        [void](Invoke-UbuntuDocker @('volume', 'inspect', $volume))
    }
    [void](Invoke-SpikeCompose @('--profile', 'immich-spike', 'up', '-d'))
    $requiredContainers = @(
        'class-archive-immich-spike-immich-server-1',
        'class-archive-immich-spike-database-1',
        'class-archive-immich-spike-redis-1'
    )
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
    $prior = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        [void](& wsl.exe -d Ubuntu --exec docker volume inspect class_archive_immich_gateway_secret 2>$null)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prior
    }
    if ($code -eq 0) { throw 'bridge_secret_volume_persisted' }
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
    if ($Action -notin @('snapshot', 'bind', 'enable', 'probe', 'cleanup') -or $Run -notmatch '^[a-f0-9]{16}$') { throw 'fixture_arguments_invalid' }
    if ($null -eq $RequestPayload) {
        $text = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', '-e', 'CLASS_ARCHIVE_ALLOW_IMMICH_GATEWAY_FIXTURE=1', 'piwigo', 'php', '/workspace/tests/phase2/immich-gateway-fixture.php', $Action, $Run)
    } else {
        if ($null -ne $script:piwigoStagedInput) { throw 'fixture_input_reentrant' }
        $stagedHostPath = $piwigoInput
        Write-OwnerOnlyJson $stagedHostPath $RequestPayload
        $container = '/tmp/class-archive-immich-gateway-piwigo-' + $Run + '.json'
        $script:piwigoStagedInput = $container
        try {
            [void](Invoke-PiwigoCompose @('cp', ('.codex-work/immich-gateway/' + $Run + '/piwigo-input.json'), ('piwigo:' + $container)))
            [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $container + ' && chmod 0600 ' + $container)))
            $text = Invoke-PiwigoCompose @('exec', '-T', '--user', 'nginx', '-e', 'CLASS_ARCHIVE_ALLOW_IMMICH_GATEWAY_FIXTURE=1', 'piwigo', 'sh', '-lc', ('php /workspace/tests/phase2/immich-gateway-fixture.php ' + $Action + ' ' + $Run + ' < ' + $container))
        } finally {
            try { [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('test ! -e ' + $container + ' || rm -f -- ' + $container))) } catch { }
            Remove-ExactFile $stagedHostPath
            $script:piwigoStagedInput = $null
        }
    }
    try { return ($text | ConvertFrom-Json -ErrorAction Stop) } catch { throw 'fixture_response_invalid' }
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

function Assert-NoInternalLeak([string]$Text, [string[]]$AssetIds, [string[]]$References, [string]$Label) {
    foreach ($needle in @('immich_asset_id', 'piwigo_image_id', 'media_reference', 'media_checksum', '/upload/', '/galleries/', 'class-archive-immich-gateway', 'X-Accel-Redirect') + $AssetIds + $References) {
        Assert-Exact (-not $Text.Contains($needle)) ($Label + '_leak')
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

    $script:stage = 'original_fingerprint_before'
    $beforeOriginals = Get-PiwigoOriginalFingerprints
    # Own the disposable spike lifecycle. Requiring an already-running stack
    # made this gate race with a prior cleanup on Windows/WSL. Reset starts a
    # pristine, internal-only stack and waits for its real health checks before
    # the isolation assertion below; it never touches the Piwigo originals.
    $script:stage = 'immich_reset_start'
    $spikeStateChanged = $true
    Reset-ImmichSpike
    $script:stage = 'isolation_precondition'
    $isolation = Invoke-LocalPowerShell $runtimeIsolationPath
    Assert-Exact ($isolation -match '(?m)^IMMICH_RUNTIME_ISOLATION=PASS evidence=RUNTIME_TESTED') 'immich_isolation_precondition'
    $script:stage = 'immich_pristine'
    $initial = Get-ImmichCounts
    Assert-Exact ($initial.Users -eq 0 -and $initial.Libraries -eq 0 -and $initial.Assets -eq 0 -and $initial.Memories -eq 0) 'immich_pristine_required'

    New-Item -ItemType Directory -Path $workDirectory -Force | Out-Null
    $script:stage = 'piwigo_snapshot'
    $snapshot = Invoke-PiwigoFixture 'snapshot' $run
    $fixturePrepared = $true
    Assert-Exact ([bool]$snapshot.ok -and @($snapshot.photos).Count -ge 2) 'piwigo_snapshot_invalid'

    $script:stage = 'stage_node_input'
    $nodeInput = [ordered]@{
        version = 1
        email = "immich-gateway-$run@synthetic.invalid"
        password = New-SecretText
        name = 'Class Archive Internal Runtime User'
        libraryName = "Class Archive Gateway Synthetic $run"
        photos = @($snapshot.photos)
    }
    Write-OwnerOnlyJson $hostNodeInput $nodeInput
    $script:stage = 'stage_node_fixture'
    [void](Invoke-SpikeCompose @('cp', $nodeFixturePath, ('immich-server:' + $nodeFixtureContainer)))
    [void](Invoke-SpikeCompose @('cp', ('.codex-work/immich-gateway/' + $run + '/node-input.json'), ('immich-server:' + $nodeInputContainer)))
    [void](Invoke-SpikeCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('chown 65532:65532 ' + $nodeFixtureContainer + ' ' + $nodeInputContainer + ' && chmod 0555 ' + $nodeFixtureContainer + ' && chmod 0600 ' + $nodeInputContainer)))
    $script:stage = 'immich_technical_library'
    $nodeOutput = Invoke-SpikeCompose @('exec', '-T', '--user', '65532:65532', 'immich-server', 'node', $nodeFixtureContainer, '--input-file', $nodeInputContainer)
    $nodePass = [regex]::Match($nodeOutput, '^IMMICH_GATEWAY_RUNTIME=PASS assets=([0-9]+) memory=1$', [Text.RegularExpressions.RegexOptions]::Multiline)
    Assert-Exact ($nodePass.Success -and [int]$nodePass.Groups[1].Value -eq @($snapshot.photos).Count) 'immich_gateway_runtime_fixture'
    [void](Invoke-SpikeCompose @('cp', ('immich-server:' + $nodeOutputContainer), ('.codex-work/immich-gateway/' + $run + '/node-output.json')))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $hostNodeOutput
    $nodeResult = Get-Content -LiteralPath $hostNodeOutput -Raw | ConvertFrom-Json -ErrorAction Stop
    Assert-Exact ($nodeResult.version -eq 1 -and @($nodeResult.assets).Count -eq @($snapshot.photos).Count -and [string]$nodeResult.access_token -match '^[A-Za-z0-9._~-]{32,8192}$') 'immich_runtime_result_invalid'
    $assetIds = @($nodeResult.assets | ForEach-Object { [string]$_.immich_asset_id })
    Assert-Exact ($assetIds.Count -eq @($snapshot.photos).Count -and ($assetIds | Select-Object -Unique).Count -eq @($snapshot.photos).Count) 'immich_asset_bindings_invalid'

    $script:stage = 'piwigo_bindings'
    $bindingResult = Invoke-PiwigoFixture 'bind' $run ([ordered]@{ version = 1; assets = @($nodeResult.assets) })
    Assert-Exact ([bool]$bindingResult.ok) 'piwigo_binding_failed'

    $script:stage = 'bridge_secret'
    $bridgeToken = New-SecretText
    Write-BridgeSecretToStager $bridgeToken ([string]$nodeResult.access_token)
    $script:stage = 'start_internal_bridge'
    try {
        [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-gateway-integration', 'up', '-d', 'immich-gateway'))
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
    Assert-Exact ($bridgeNetworks.Contains('class_archive_immich_gateway;') -and $bridgeNetworks.Contains('class-archive-immich-spike_immich_internal;') -and -not $bridgeNetworks.Contains('class_archive_piwigo_app;')) 'bridge_network_scope_invalid'
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
    $piwigoContainer = (Invoke-PiwigoCompose @('ps', '-q', 'piwigo')).Trim()
    Assert-Exact ($piwigoContainer -match '^[a-f0-9]{64}$') 'piwigo_container_invalid'
    $piwigoNetworks = Invoke-UbuntuDocker @('inspect', $piwigoContainer, '--format', '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}};{{end}}')
    Assert-Exact ($piwigoNetworks.Contains('class_archive_immich_gateway;') -and -not $piwigoNetworks.Contains('class-archive-immich-spike_immich_internal;')) 'piwigo_network_scope_invalid'

    $script:stage = 'enable_bridge'
    $enabled = Invoke-PiwigoFixture 'enable' $run ([ordered]@{ version = 1; token = $bridgeToken })
    Assert-Exact ([bool]$enabled.ok) 'bridge_enable_failed'
    $bridgeToken = $null
    $nodeResult = $null

    $script:stage = 'bridge_adapter_probe'
    $adapterProbe = Invoke-PiwigoFixture 'probe' $run
    if (-not [bool]$adapterProbe.ok) {
        $probeCode = [string]$adapterProbe.code
        if ($probeCode -notmatch '^[a-z_]{1,64}$') { $probeCode = 'invalid' }
        throw ('bridge_adapter_probe_' + $probeCode)
    }

    $script:stage = 'fixture_accounts'
    $fixturePassword = New-SecretText
    Provision-PiwigoFixtureAccounts $fixturePassword
    $sessions['CLASSMATE'] = Login-Role $wsUri 'fixture-classmate' $fixturePassword
    $sessions['FAMILY'] = Login-Role $wsUri 'fixture-family' $fixturePassword
    $fixturePassword = $null

    $script:stage = 'bridge_gateway_http'
    $classmateMemories = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/memories')) $sessions['CLASSMATE']) 'classmate_memories'
    $familyMemories = Read-GatewayJson (Invoke-Gateway ([Uri]::new($baseUri, 'api/memories')) $sessions['FAMILY']) 'family_memories'
    Assert-Exact ([bool]$classmateMemories.available -and [int]$classmateMemories.total -eq 1) 'classmate_memory_aggregation_invalid'
    Assert-Exact ([bool]$familyMemories.available -and [int]$familyMemories.total -eq 1) 'family_memory_aggregation_invalid'
    Assert-Exact ([int]$classmateMemories.items[0].photo_count -eq 2) 'classmate_memory_visible_count_invalid'
    Assert-Exact ([int]$familyMemories.items[0].photo_count -eq 1) 'family_memory_living_side_channel'
    $references = @($snapshot.photos | ForEach-Object { [string]$_.media_reference })
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

    $script:stage = 'original_fingerprint_after_scan'
    $afterScan = Get-PiwigoOriginalFingerprints
    foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
        Assert-Exact ($beforeOriginals[$path].Count -eq $afterScan[$path].Count -and $beforeOriginals[$path].Digest -eq $afterScan[$path].Digest) 'piwigo_originals_changed'
    }
} catch {
    $failure = $_
    $failureStage = $script:stage
} finally {
    foreach ($session in @($sessions.Values)) {
        try { Invoke-Logout $wsUri $session } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'logout' } }
    }
    if ($fixturePrepared) {
        try {
            $script:stage = 'cleanup_piwigo_fixture'
            $cleanup = Invoke-PiwigoFixture 'cleanup' $run
            Assert-Exact ([bool]$cleanup.ok) 'piwigo_fixture_cleanup_invalid'
        } catch { if ($null -eq $failure) { $failure = $_; $failureStage = $script:stage } }
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
    $safeStage = if ($null -ne $failureStage -and $failureStage -ne '') { $failureStage } else { $script:stage }
    $failureType = [string]$failure.Exception.GetType().Name
    if ($failureType -notmatch '\A[A-Za-z0-9_]{1,64}\z') { $failureType = 'Unknown' }
    $safeCode = Get-SafeFailureCode $failure
    [Console]::Error.WriteLine(('IMMICH_GATEWAY_BRIDGE=FAIL evidence=RUNTIME_TESTED reason=stage_' + $safeStage + ' code=' + $safeCode + ' type=' + $failureType))
    exit 1
}

Write-Output "IMMICH_GATEWAY_BRIDGE=PASS evidence=RUNTIME_TESTED assertions=$script:assertions probes=$script:probes"
Write-Output 'IMMICH_GATEWAY_ACL_AGGREGATION=PASS classmate_memory=2 family_memory=1 people=actual_empty'
Write-Output 'IMMICH_GATEWAY_MEDIA_PATH=MEDIAGUARD_REQUIRED no_bridge_media_route=PASS'
Write-Output 'IMMICH_GATEWAY_CLEANUP=PASS spike_state=empty originals_unchanged=PASS'
