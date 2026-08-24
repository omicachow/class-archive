[CmdletBinding()]
param()

# True isolated runtime proof for the v3.1.0 offline face and smart-search
# pipeline. It creates only fictional committed fixture images, binds their
# canonical UUIDs after Immich scans them, and returns both Piwigo and Immich
# to the deterministic empty state. Browser access, public Immich routes and
# original-byte writes are deliberately out of scope for this runner.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoEnv = '.env.piwigo'
$spikeCompose = 'infra/immich-spike/docker-compose.yml'
$spikeEnv = 'infra/immich-spike/.env'
$readinessScript = Join-Path $projectRoot 'tests\phase2\immich-ml-artifact-readiness.ps1'
$isolationScript = Join-Path $projectRoot 'tests\phase2\immich-runtime-isolation.ps1'
$fixtureScript = 'tests/phase2/immich-people-fixture.php'
$nodeFixture = 'tests/phase2/immich-people-search-runtime.mjs'
$secretAclScript = Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1'
. $secretAclScript

$script:assertions = 0
$script:stage = 'initialization'
$script:runtimeMetrics = $null
$script:chineseSearchQuality = 'UNKNOWN'
$script:englishSearchQuality = 'UNKNOWN'
$script:chinesePrecisionAt5 = 'NA'
$script:chineseRecallAt5 = 'NA'
$script:chineseTop5HitRate = 'NA'
$script:englishPrecisionAt5 = 'NA'
$script:englishRecallAt5 = 'NA'
$script:englishTop5HitRate = 'NA'
$script:mlRuntimeTiming = 'NA'
$script:mlRuntimeCounts = 'NA'
$run = ([Guid]::NewGuid().ToString('N')).Substring(0, 16)
$workDirectory = Join-Path $projectRoot ('.codex-work\immich-people\' + $run)
$hostNodeInput = Join-Path $workDirectory 'node-input.json'
$hostNodeOutput = Join-Path $workDirectory 'node-output.json'
$hostPiwigoInput = Join-Path $workDirectory 'piwigo-input.json'
$nodeInputContainer = '/tmp/class-archive-immich-people-input.json'
$nodeOutputContainer = '/tmp/class-archive-immich-people-output.json'
$nodeFixtureContainer = '/tmp/class-archive-immich-people-runtime.mjs'
$piwigoStagedInput = $null
$fixturePrepared = $false
$spikeReset = $false
$beforeOriginals = $null
$failure = $null
$failureStage = $null

function Fail([string]$reason) {
    throw "IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL evidence=RUNTIME_TESTED reason=$reason assertions=$script:assertions"
}

function Assert-Exact([bool]$condition, [string]$reason) {
    $script:assertions++
    if (-not $condition) { Fail $reason }
}

function Get-SafeFailureCode([object]$errorRecord) {
    $message = [string]$errorRecord.Exception.Message
    $match = [regex]::Match($message, 'reason=([a-z0-9_]{1,96})')
    if ($match.Success) { return $match.Groups[1].Value }
    if ($message -match '^[a-z0-9_]{1,96}$') { return $message }
    return 'unexpected'
}

function New-SecretText {
    $bytes = New-Object byte[] 36
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Invoke-UbuntuDocker([string[]]$arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --exec docker @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($exitCode -ne 0) { throw ('docker_command_failed_' + ($arguments -join '_')) }
    return [string]::Join("`n", $lines)
}

function Invoke-PiwigoCompose([string[]]$arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $piwigoEnv -f $piwigoCompose @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    $text = [string]::Join("`n", $lines)
    if ($exitCode -ne 0) {
        # The gated fixture emits only a fixed, non-secret reason code.  Carry
        # that code into the runner's final line while keeping arbitrary
        # container stderr out of the test report.
        $fixtureFailure = [regex]::Match($text, '(?m)^IMMICH_PEOPLE_FIXTURE=FAIL reason=([a-z0-9_]{1,96})$')
        if ($fixtureFailure.Success) { throw ('piwigo_fixture_' + $fixtureFailure.Groups[1].Value) }
        throw 'piwigo_compose_failed'
    }
    return $text
}

function Invoke-SpikeCompose([string[]]$arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $spikeEnv -f $spikeCompose @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    $text = [string]::Join("`n", $lines)
    if ($exitCode -ne 0) {
        $metrics = [regex]::Match($text, '(?m)^IMMICH_PEOPLE_SEARCH_RUNTIME_METRICS=assets=([0-9]+) people=([0-9]+)$')
        if ($metrics.Success) {
            $script:runtimeMetrics = 'assets=' + $metrics.Groups[1].Value + ' people=' + $metrics.Groups[2].Value
        }
        $fixtureFailure = [regex]::Match($text, '(?m)^IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=([a-z0-9_]{1,96})$')
        if ($fixtureFailure.Success) { throw ('immich_fixture_' + $fixtureFailure.Groups[1].Value) }
        throw 'immich_compose_failed'
    }
    return $text
}

function Invoke-ProjectPowerShell([string]$path, [string[]]$arguments = @()) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        # The host's legacy Windows PowerShell child process can lack modules
        # used by existing Phase 2 probes after a hidden Start-Process launch.
        # The project runtime has a pinned PowerShell 7 binary; use that same
        # verified host for these read-only gates rather than weakening either
        # gate or its hash checks.
        $lines = @(& pwsh.exe -NoProfile -ExecutionPolicy Bypass -File $path @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($exitCode -ne 0) { throw 'project_gate_failed' }
    return [string]::Join("`n", $lines)
}

function Write-OwnerOnlyJson([string]$path, [object]$value) {
    if (Test-Path -LiteralPath $path) { throw 'private_file_already_exists' }
    [IO.File]::WriteAllText($path, ($value | ConvertTo-Json -Compress -Depth 12), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $path
}

function Remove-ExactFile([string]$path) {
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        Remove-Item -LiteralPath $path -Force -ErrorAction Stop
        if (Test-Path -LiteralPath $path) { throw 'private_file_cleanup_unproven' }
    }
}

function Get-PiwigoOriginalFingerprints {
    $script = @'
for p in /var/www/html/piwigo/upload /var/www/html/piwigo/galleries; do
  # Archive safety is about physical originals, not Piwigo's harmless
  # index.htm sentinels.  Limit both the count and digest to the same
  # supported original-media extension set used by the canonical model test.
  count=$(find "$p" -type f \( -iname '*.avif' -o -iname '*.gif' -o -iname '*.jpeg' -o -iname '*.jpg' -o -iname '*.png' -o -iname '*.webp' \) -printf . | wc -c)
  digest=$(find "$p" -type f \( -iname '*.avif' -o -iname '*.gif' -o -iname '*.jpeg' -o -iname '*.jpg' -o -iname '*.png' -o -iname '*.webp' \) -print0 | sort -z | xargs -0 -r sha256sum | sha256sum | cut -d' ' -f1)
  printf 'FINGERPRINT|%s|%s|%s\n' "$p" "$count" "$digest"
done
'@
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($script))
    $lines = @((Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('echo ' + $encoded + ' | base64 -d | sh'))) -split "`r?`n" | Where-Object { $_ -like 'FINGERPRINT|*' })
    if ($lines.Count -ne 2) { throw 'piwigo_fingerprint_invalid' }
    $result = @{}
    foreach ($line in $lines) {
        $parts = $line -split '\|', 4
        if ($parts.Count -ne 4 -or $parts[2] -notmatch '^\d+$' -or $parts[3] -notmatch '^[a-f0-9]{64}$') { throw 'piwigo_fingerprint_invalid' }
        $result[$parts[1]] = @{ Count = [int]$parts[2]; Digest = [string]$parts[3] }
    }
    return $result
}

function Remove-DisposableSpikeVolume([string]$volume) {
    if ($volume -notin @('class_archive_immich_spike_upload', 'class_archive_immich_spike_db', 'class_archive_immich_gateway_secret')) {
        throw 'unexpected_disposable_volume'
    }
    $names = @((Invoke-UbuntuDocker @('volume', 'ls', '--format', '{{.Name}}')) -split "`r?`n" | Where-Object { $_ -ne '' })
    if ($names -contains $volume) {
        [void](Invoke-UbuntuDocker @('volume', 'inspect', $volume))
        [void](Invoke-UbuntuDocker @('volume', 'rm', $volume))
    }
}

function Wait-ContainersHealthy([string[]]$containers) {
    for ($attempt = 0; $attempt -lt 120; $attempt++) {
        try {
            $states = @($containers | ForEach-Object {
                (Invoke-UbuntuDocker @('inspect', $_, '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}')).Trim()
            })
            if (@($states | Where-Object { $_ -eq 'running|healthy' }).Count -eq $containers.Count) { return }
        } catch { }
        Start-Sleep -Seconds 1
    }
    throw 'immich_restart_not_healthy'
}

function Wait-ImmichHealthy {
    Wait-ContainersHealthy @(
        'class-archive-immich-spike-immich-server-1',
        'class-archive-immich-spike-database-1',
        'class-archive-immich-spike-redis-1',
        'class-archive-immich-spike-immich-machine-learning-1'
    )
}

function Reset-ImmichSpike {
    foreach ($volume in @('class_archive_immich_spike_upload', 'class_archive_immich_spike_db')) {
        [void](Invoke-UbuntuDocker @('volume', 'inspect', $volume))
    }
    # The spike shares an external gateway network with the independently
    # owned Piwigo compose project. Limit teardown to declared spike services;
    # --remove-orphans is too broad for an isolated synthetic test reset.
    [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-ml', '--profile', 'immich-gateway-integration', 'down'))
    foreach ($volume in @('class_archive_immich_spike_upload', 'class_archive_immich_spike_db', 'class_archive_immich_gateway_secret')) {
        Remove-DisposableSpikeVolume $volume
    }
    foreach ($volume in @('class_archive_piwigo_uploads', 'class_archive_piwigo_galleries', 'class_archive_immich_spike_model_cache')) {
        [void](Invoke-UbuntuDocker @('volume', 'inspect', $volume))
    }
    # Start dependencies first.  Compose's dependency health gate can race a
    # freshly-created Postgres service on Docker Desktop/WSL, which would make
    # the intentionally non-restarting Immich server exit once with ECONNREFUSED.
    # This explicit sequencing is still an isolated, synthetic-only startup;
    # it never grants the server a broader network or writable originals.
    [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-ml', 'up', '-d', 'database', 'redis', 'immich-machine-learning'))
    Wait-ContainersHealthy @(
        'class-archive-immich-spike-database-1',
        'class-archive-immich-spike-redis-1',
        'class-archive-immich-spike-immich-machine-learning-1'
    )
    [void](Invoke-SpikeCompose @('--profile', 'immich-spike', '--profile', 'immich-ml', 'up', '-d', 'immich-server'))
    Wait-ImmichHealthy
}

function Invoke-PiwigoFixture([string]$action, [object]$payload = $null) {
    if ($action -notin @('prepare', 'bind', 'cleanup') -or $run -notmatch '^[a-f0-9]{16}$') { throw 'fixture_arguments_invalid' }
    if ($null -eq $payload) {
        # Piwigo's persistent volume is owned by the image's documented
        # PIWIGO_UID/PIWIGO_GID (1000:1000).  The nginx FPM account receives
        # an ACL for request handling, but a crash-recovery fixture state must
        # be created and later removed by its durable owner.
        $text = Invoke-PiwigoCompose @('exec', '-T', '--user', '1000:1000', '-e', 'CLASS_ARCHIVE_ALLOW_IMMICH_PEOPLE_FIXTURE=1', 'piwigo', 'php', ('/workspace/' + $fixtureScript), $action, $run)
    } else {
        if ($null -ne $script:piwigoStagedInput) { throw 'fixture_input_reentrant' }
        Write-OwnerOnlyJson $hostPiwigoInput $payload
        $container = '/tmp/class-archive-immich-people-piwigo-' + $run + '.json'
        $script:piwigoStagedInput = $container
        try {
            [void](Invoke-PiwigoCompose @('cp', ('.codex-work/immich-people/' + $run + '/piwigo-input.json'), ('piwigo:' + $container)))
            [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown 1000:1000 ' + $container + ' && chmod 0600 ' + $container)))
            $text = Invoke-PiwigoCompose @('exec', '-T', '--user', '1000:1000', '-e', 'CLASS_ARCHIVE_ALLOW_IMMICH_PEOPLE_FIXTURE=1', 'piwigo', 'sh', '-lc', ('php /workspace/' + $fixtureScript + ' ' + $action + ' ' + $run + ' < ' + $container))
        } finally {
            try { [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('test ! -e ' + $container + ' || rm -f -- ' + $container))) } catch { }
            Remove-ExactFile $hostPiwigoInput
            $script:piwigoStagedInput = $null
        }
    }
    try { return $text | ConvertFrom-Json -ErrorAction Stop } catch { throw 'fixture_response_invalid' }
}

function Get-ImmichRuntimeCounts {
    $query = 'SELECT (SELECT count(*) FROM asset) AS assets, (SELECT count(*) FROM asset_face WHERE "deletedAt" IS NULL) AS faces, (SELECT count(*) FROM face_search) AS embeddings, (SELECT count(*) FROM person) AS people, (SELECT count(*) FROM smart_search) AS smart;'
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($query))
    $command = @('exec', '-T', 'database', 'sh', '-lc', ('echo ' + $encoded + ' | base64 -d | psql -U postgres -d immich -At'))
    $lastFailure = $null
    # A healthy Immich container can publish its health check before migrations
    # create the application tables.  Waiting only for these read-only counts
    # avoids treating that normal start-up window as an empty, trusted library.
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        try {
            $text = Invoke-SpikeCompose $command
            $match = [regex]::Match($text.Trim(), '^([0-9]+)\|([0-9]+)\|([0-9]+)\|([0-9]+)\|([0-9]+)$')
            if ($match.Success) {
                return @{ Assets = [int]$match.Groups[1].Value; Faces = [int]$match.Groups[2].Value; Embeddings = [int]$match.Groups[3].Value; People = [int]$match.Groups[4].Value; Smart = [int]$match.Groups[5].Value }
            }
            $lastFailure = 'immich_counts_invalid'
        } catch {
            $lastFailure = $_
        }
        Start-Sleep -Seconds 1
    }
    if ($lastFailure -is [System.Management.Automation.ErrorRecord]) { throw $lastFailure }
    throw $lastFailure
}

try {
    Assert-Exact (Test-Path -LiteralPath $readinessScript -PathType Leaf) 'ml_readiness_script_missing'
    Assert-Exact (Test-Path -LiteralPath $isolationScript -PathType Leaf) 'isolation_script_missing'
    Assert-Exact (Test-Path -LiteralPath (Join-Path $projectRoot $fixtureScript) -PathType Leaf) 'piwigo_fixture_missing'
    Assert-Exact (Test-Path -LiteralPath (Join-Path $projectRoot $nodeFixture) -PathType Leaf) 'node_fixture_missing'

    $script:stage = 'reset_immich'
    $spikeReset = $true
    Reset-ImmichSpike
    $script:stage = 'offline_artifacts'
    $readiness = Invoke-ProjectPowerShell $readinessScript @('-RequireReady')
    Assert-Exact ($readiness -match 'IMMICH_ML_MODEL_ARTIFACTS=READY evidence=RUNTIME_TESTED artifacts=8') 'ml_artifacts_not_ready'
    $script:stage = 'runtime_isolation'
    $isolation = Invoke-ProjectPowerShell $isolationScript
    Assert-Exact ($isolation -match 'IMMICH_RUNTIME_ISOLATION=PASS evidence=RUNTIME_TESTED') 'immich_runtime_not_isolated'
    $script:stage = 'original_fingerprint'
    $beforeOriginals = Get-PiwigoOriginalFingerprints
    $baselineOriginalCount = 0
    foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
        $baselineOriginalCount += $beforeOriginals[$path].Count
    }
    # The fixed Piwigo-first fixture deliberately exposes the complete
    # read-only synthetic archive to the technical Immich user.  This is not
    # a second media copy: 72 canonical originals plus this run's 32 images
    # must be exactly what its external library sees.
    Assert-Exact ($baselineOriginalCount -eq 72) 'canonical_baseline_original_count_invalid'
    $script:stage = 'pristine_counts'
    $initial = Get-ImmichRuntimeCounts
    Assert-Exact ($initial.Assets -eq 0 -and $initial.Faces -eq 0 -and $initial.Embeddings -eq 0 -and $initial.People -eq 0 -and $initial.Smart -eq 0) 'immich_runtime_not_pristine'

    New-Item -ItemType Directory -Path $workDirectory -Force | Out-Null
    $script:stage = 'prepare_piwigo_fixture'
    # Cleanup is safe and idempotent even when prepare fails before publishing
    # its success response. Mark ownership before entering the crash-sensitive
    # import so a partially written synthetic fixture cannot survive finally.
    $fixturePrepared = $true
    $prepared = Invoke-PiwigoFixture 'prepare'
    $photos = @($prepared.photos)
    $catalog = @($prepared.catalog)
    Assert-Exact ([bool]$prepared.ok -and $photos.Count -eq 32 -and $catalog.Count -eq ($baselineOriginalCount + $photos.Count)) 'people_fixture_prepare_invalid'
    # The required A/B/C portrait split is 16 HERITAGE / 12 LIVING.  Two
    # HERITAGE and two LIVING scene fixtures make the final deterministic
    # corpus 18/14; asserting that exact split prevents an ACL test from
    # silently losing its intended cross-era evidence.
    Assert-Exact (@($photos | Where-Object { $_.era -eq 'HERITAGE' }).Count -eq 18) 'people_fixture_heritage_count_invalid'
    Assert-Exact (@($photos | Where-Object { $_.era -eq 'LIVING' }).Count -eq 14) 'people_fixture_living_count_invalid'

    $script:stage = 'stage_node_fixture'
    $nodeInput = [ordered]@{
        version = 1
        email = "immich-people-$run@synthetic.invalid"
        password = New-SecretText
        name = 'Class Archive Fictional AI Runtime User'
        libraryName = "Class Archive Fictional People $run"
        expectedCatalogAssets = $baselineOriginalCount + $photos.Count
        photos = $photos
        catalog = $catalog
    }
    Write-OwnerOnlyJson $hostNodeInput $nodeInput
    [void](Invoke-SpikeCompose @('cp', $nodeFixture, ('immich-server:' + $nodeFixtureContainer)))
    [void](Invoke-SpikeCompose @('cp', ('.codex-work/immich-people/' + $run + '/node-input.json'), ('immich-server:' + $nodeInputContainer)))
    [void](Invoke-SpikeCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('chown 65532:65532 ' + $nodeFixtureContainer + ' ' + $nodeInputContainer + ' && chmod 0555 ' + $nodeFixtureContainer + ' && chmod 0600 ' + $nodeInputContainer)))

    $script:stage = 'run_face_and_search'
    try {
        # Pass the fixed container paths inside a base64-encoded Linux shell
        # payload. Windows/WSL native argument conversion can otherwise
        # rewrite a literal `/tmp/...` into a host-looking path before Node
        # sees it; no caller-controlled string enters this command.
        $nodeCommand = 'exec node ' + $nodeFixtureContainer + ' --input-file ' + $nodeInputContainer
        $nodeEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($nodeCommand))
        $nodeText = Invoke-SpikeCompose @('exec', '-T', '--user', '65532:65532', 'immich-server', 'sh', '-lc', ('echo ' + $nodeEncoded + ' | base64 -d | sh'))
    } catch {
        # Preserve only aggregate synthetic-pipeline counts for diagnosis.  The
        # query happens before cleanup, and never serializes an Immich asset,
        # person, path, user, or token identifier.
        try {
            $runtimeCounts = Get-ImmichRuntimeCounts
            $script:runtimeMetrics = 'assets=' + $runtimeCounts.Assets + ' faces=' + $runtimeCounts.Faces + ' embeddings=' + $runtimeCounts.Embeddings + ' people=' + $runtimeCounts.People + ' smart=' + $runtimeCounts.Smart
        } catch { }
        throw
    }
    $nodePass = [regex]::Match($nodeText, '^IMMICH_PEOPLE_SEARCH_RUNTIME=PASS assets=([0-9]+) catalog_assets=([0-9]+) people=([0-9]+) face_jobs=([0-9]+) recognition_jobs=([0-9]+) smart_jobs=([0-9]+)$', [Text.RegularExpressions.RegexOptions]::Multiline)
    Assert-Exact ($nodePass.Success) 'immich_runtime_fixture_failed'
    Assert-Exact ([int]$nodePass.Groups[1].Value -eq 32 -and [int]$nodePass.Groups[2].Value -eq $catalog.Count -and [int]$nodePass.Groups[3].Value -ge 3) 'immich_runtime_fixture_result_invalid'
    [void](Invoke-SpikeCompose @('cp', ('immich-server:' + $nodeOutputContainer), ('.codex-work/immich-people/' + $run + '/node-output.json')))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $hostNodeOutput
    $result = Get-Content -LiteralPath $hostNodeOutput -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
    Assert-Exact ($result.version -eq 1 -and [string]$result.access_token -match '^[A-Za-z0-9._~-]{32,8192}$') 'immich_runtime_output_invalid'
    Assert-Exact (@($result.assets).Count -eq $catalog.Count -and @($result.fixture_assets).Count -eq 32 -and @($result.people).Count -ge 3) 'immich_runtime_output_shape_invalid'
    Assert-Exact (@($result.runtime.search_results).Count -eq 8) 'smart_search_results_missing'
    Assert-Exact ([int]$result.runtime.library_asset_count -eq ($baselineOriginalCount + $photos.Count)) 'immich_external_library_count_invalid'
    $quality = $result.runtime.search_quality
    $qualityBands = @('EXCELLENT', 'GOOD', 'FAIR', 'POOR')
    Assert-Exact ($null -ne $quality -and [int]$quality.version -eq 1 -and [int]$quality.top_k -eq 5) 'smart_search_quality_shape_invalid'
    foreach ($language in @('zh', 'en')) {
        $group = $quality.$language
        Assert-Exact ($null -ne $group -and $qualityBands -contains [string]$group.quality -and @($group.queries).Count -eq 4) ('smart_search_quality_' + $language + '_group_invalid')
        foreach ($entry in @($group.queries)) {
            Assert-Exact (
                $null -ne $entry -and
                [string]$entry.name -match ('^' + $language + '_(?:playground|basketball|classroom|night)$') -and
                [int]$entry.result_count -ge 0 -and
                [int]$entry.expected_relevant -ge 1 -and
                $null -ne $entry.precision_at_5 -and
                $null -ne $entry.recall_at_5 -and
                [double]$entry.precision_at_5 -ge 0 -and [double]$entry.precision_at_5 -le 1 -and
                [double]$entry.recall_at_5 -ge 0 -and [double]$entry.recall_at_5 -le 1 -and
                $entry.top_5_hit -is [bool] -and
                [int]$entry.acceptable_at_5 -ge 0
            ) ('smart_search_quality_' + $language + '_entry_invalid')
        }
    }
    $script:chineseSearchQuality = [string]$quality.zh.quality
    $script:englishSearchQuality = [string]$quality.en.quality
    $invariant = [Globalization.CultureInfo]::InvariantCulture
    $script:chinesePrecisionAt5 = ([double]$quality.zh.mean_precision_at_5).ToString('F3', $invariant)
    $script:chineseRecallAt5 = ([double]$quality.zh.mean_recall_at_5).ToString('F3', $invariant)
    $script:chineseTop5HitRate = ([double]$quality.zh.top_5_hit_rate).ToString('F3', $invariant)
    $script:englishPrecisionAt5 = ([double]$quality.en.mean_precision_at_5).ToString('F3', $invariant)
    $script:englishRecallAt5 = ([double]$quality.en.mean_recall_at_5).ToString('F3', $invariant)
    $script:englishTop5HitRate = ([double]$quality.en.top_5_hit_rate).ToString('F3', $invariant)
    $timing = $result.runtime.timings_ms
    Assert-Exact ($null -ne $timing) 'immich_ml_timing_missing'
    $timingValues = @('face_detection', 'face_recognition', 'smart_search_index', 'people_query', 'smart_search_queries') | ForEach-Object {
        $property = $timing.PSObject.Properties[$_]
        if ($null -eq $property) { $null } else { $property.Value }
    }
    Assert-Exact (@($timingValues | Where-Object { $null -eq $_ -or [int64]$_ -lt 0 -or [int64]$_ -gt 3600000 }).Count -eq 0) 'immich_ml_timing_invalid'
    $script:mlRuntimeTiming = 'face_detection_ms=' + [int64]$timing.face_detection + ' face_recognition_ms=' + [int64]$timing.face_recognition + ' smart_index_ms=' + [int64]$timing.smart_search_index + ' people_query_ms=' + [int64]$timing.people_query + ' smart_queries_ms=' + [int64]$timing.smart_search_queries + ' total_ms=' + [int64]$result.runtime.total_milliseconds

    $script:stage = 'bind_canonical_photos'
    $binding = Invoke-PiwigoFixture 'bind' ([ordered]@{ version = 1; assets = @($result.assets) })
    Assert-Exact ([bool]$binding.ok) 'canonical_photo_binding_failed'
    $counts = Get-ImmichRuntimeCounts
    Assert-Exact ($counts.Assets -eq ($baselineOriginalCount + $photos.Count) -and $counts.Faces -ge 3 -and $counts.Embeddings -ge 3 -and $counts.People -ge 3 -and $counts.Smart -eq ($baselineOriginalCount + $photos.Count)) 'immich_model_pipeline_counts_invalid'
    $script:mlRuntimeCounts = 'catalog_assets=' + $counts.Assets + ' detected_faces=' + $counts.Faces + ' face_embeddings=' + $counts.Embeddings + ' person_clusters=' + $counts.People + ' smart_embeddings=' + $counts.Smart
    $script:stage = 'original_fingerprint_after_runtime'
    $afterRuntime = Get-PiwigoOriginalFingerprints
    foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
        $root = if ($path -like '*/upload') { 'upload/' } else { 'galleries/' }
        $fixtureOriginalCount = @($photos | Where-Object { $_.media_reference -like ($root + '*') }).Count
        # The Piwigo submission-like fixture intentionally adds exactly these
        # synthetic originals before Immich reads its immutable mount.  During
        # the run, permit only that exact count delta; the final cleanup below
        # still requires the full original-tree digest to return to baseline.
        Assert-Exact ($afterRuntime[$path].Count -eq ($beforeOriginals[$path].Count + $fixtureOriginalCount)) 'piwigo_runtime_original_count_invalid'
    }
} catch {
    $failure = $_
    $failureStage = $script:stage
} finally {
    if ($fixturePrepared) {
        try {
            $script:stage = 'cleanup_piwigo_fixture'
            $cleanup = Invoke-PiwigoFixture 'cleanup'
            Assert-Exact ([bool]$cleanup.ok) 'piwigo_fixture_cleanup_invalid'
        } catch {
            if ($null -eq $failure) { $failure = $_; $failureStage = $script:stage }
        }
    }
    foreach ($path in @($hostNodeInput, $hostNodeOutput, $hostPiwigoInput)) {
        try { Remove-ExactFile $path } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_private_file' } }
    }
    if ($null -ne $piwigoStagedInput) {
        try { [void](Invoke-PiwigoCompose @('exec', '-T', 'piwigo', 'sh', '-lc', ('test ! -e ' + $piwigoStagedInput + ' || rm -f -- ' + $piwigoStagedInput))) } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_piwigo_input' } }
    }
    try {
        [void](Invoke-SpikeCompose @('exec', '-T', 'immich-server', 'sh', '-lc', ('rm -f -- ' + $nodeFixtureContainer + ' ' + $nodeInputContainer + ' ' + $nodeOutputContainer)))
    } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_immich_files' } }
    if ($spikeReset) {
        try {
            $script:stage = 'cleanup_immich_reset'
            Reset-ImmichSpike
            $empty = Get-ImmichRuntimeCounts
            Assert-Exact ($empty.Assets -eq 0 -and $empty.Faces -eq 0 -and $empty.Embeddings -eq 0 -and $empty.People -eq 0 -and $empty.Smart -eq 0) 'immich_reset_not_empty'
            if ($null -ne $beforeOriginals) {
                $afterReset = Get-PiwigoOriginalFingerprints
                foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
                    Assert-Exact ($beforeOriginals[$path].Count -eq $afterReset[$path].Count -and $beforeOriginals[$path].Digest -eq $afterReset[$path].Digest) 'piwigo_originals_changed_after_reset'
                }
            }
        } catch { if ($null -eq $failure) { $failure = $_; $failureStage = $script:stage } }
    }
    try {
        if (Test-Path -LiteralPath $workDirectory -PathType Container) {
            if (@(Get-ChildItem -LiteralPath $workDirectory -Force).Count -ne 0) { throw 'work_directory_not_empty' }
            Remove-Item -LiteralPath $workDirectory -Force -ErrorAction Stop
            if (Test-Path -LiteralPath $workDirectory) { throw 'work_directory_cleanup_unproven' }
        }
    } catch { if ($null -eq $failure) { $failure = $_; $failureStage = 'cleanup_work_directory' } }
}

if ($null -ne $failure) {
    $safeStage = if ($null -ne $failureStage -and $failureStage -ne '') { $failureStage } else { $script:stage }
    $safeCode = Get-SafeFailureCode $failure
    $metricSuffix = if ($null -eq $script:runtimeMetrics) { '' } else { ' ' + $script:runtimeMetrics }
    [Console]::Error.WriteLine("IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL evidence=RUNTIME_TESTED stage=$safeStage reason=$safeCode$metricSuffix")
    exit 1
}

Write-Output "IMMICH_PEOPLE_SEARCH_RUNTIME=PASS evidence=RUNTIME_TESTED assertions=$script:assertions assets=32 pipeline=face_detection_face_embedding_clustering_smart_search"
Write-Output "IMMICH_PEOPLE_SEARCH_COUNTS=RUNTIME_TESTED $script:mlRuntimeCounts"
Write-Output "IMMICH_SMART_SEARCH_QUALITY=TESTED evidence=RUNTIME_TESTED chinese=$script:chineseSearchQuality english=$script:englishSearchQuality top_k=5"
Write-Output "IMMICH_SMART_SEARCH_METRICS=RUNTIME_TESTED zh_p5=$script:chinesePrecisionAt5 zh_r5=$script:chineseRecallAt5 zh_hit5=$script:chineseTop5HitRate en_p5=$script:englishPrecisionAt5 en_r5=$script:englishRecallAt5 en_hit5=$script:englishTop5HitRate"
Write-Output "IMMICH_ML_RUNTIME_METRICS=RUNTIME_TESTED $script:mlRuntimeTiming"
Write-Output 'IMMICH_PEOPLE_SEARCH_CLEANUP=PASS baseline=72_originals spike_index=empty model_cache=preserved'
