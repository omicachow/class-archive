[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('preflight', 'prepare-model-cache', 'up', 'validate', 'status', 'provision', 'resume', 'finish', 'finalize-indexes')]
    [string]$Action = 'preflight'
)

# Safe operator for the private-full Immich runtime. It deliberately exposes
# no reset/down/delete action, never publishes an Immich port, and imports only
# the already verified offline model closure from the synthetic artifact cache.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$piwigoEnvRelative = 'infra/private-full/.env.piwigo.owner'
$immichEnvRelative = 'infra/private-full/.env.immich.owner'
$piwigoEnv = Join-Path $projectRoot ($piwigoEnvRelative -replace '/', '\')
$immichEnv = Join-Path $projectRoot ($immichEnvRelative -replace '/', '\')
$piwigoProject = 'class_archive_private_full_v3_piwigo'
$immichProject = 'class_archive_private_full_v3_immich'
$sourceCache = 'class_archive_immich_spike_model_cache'
$targetCache = 'class_archive_private_full_v3_immich_model_cache'
$manifestRelative = 'infra/immich-spike/ml-artifacts/manifest.json'
$manifest = Join-Path $projectRoot ($manifestRelative -replace '/', '\')
$expectedManifestSha256 = '46380b30910608a8f0226d6ed14e3535cdd3f43c6080115e19842a8eaeda7e7a'
$minimumFreeBytes = 6GB
$script:stage = 'initialization'
$script:assertions = 0

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Get-WslPath([string]$Path) {
    $raw = @(& wsl.exe -d Ubuntu --exec wslpath -a $Path 2>$null)
    if ($LASTEXITCODE -ne 0 -or $raw.Count -ne 1 -or [string]$raw[0] -notmatch '^/mnt/[a-z]/') { Fail 'wsl_path_invalid' }
    return [string]$raw[0]
}

function Fail([string]$Code) {
    throw "PRIVATE_FULL_IMMICH=FAIL stage=$script:stage code=$Code assertions=$script:assertions"
}

function Assert-Exact([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Fail $Code }
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

function Invoke-ImmichCompose([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $immichEnvRelative `
            -f 'infra/immich-spike/docker-compose.yml' -f 'infra/private-full/docker-compose.immich.override.yml' `
            -p $immichProject --profile 'immich-ml' @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Fail 'immich_compose_failed' }
    return [string]::Join("`n", $lines)
}

function Invoke-PiwigoWorkerCompose([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $piwigoEnvRelative `
            -f 'infra/docker-compose.yml' -f 'infra/private-full/docker-compose.override.yml' `
            -f 'infra/private-full/docker-compose.ai-worker.override.yml' -p $piwigoProject @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Fail 'piwigo_worker_compose_failed' }
    return [string]::Join("`n", $lines)
}

function Invoke-PiwigoBaseCompose([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $piwigoEnvRelative `
            -f 'infra/docker-compose.yml' -f 'infra/private-full/docker-compose.override.yml' `
            -p $piwigoProject @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Fail 'piwigo_base_compose_failed' }
    return [string]::Join("`n", $lines)
}

function Read-StrictEnv([string]$Path) {
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line -eq '' -or $line.StartsWith('#')) { continue }
        if ($line -notmatch '\A([A-Z][A-Z0-9_]*)=([^\r\n]*)\z') { Fail 'dotenv_invalid' }
        if ($values.ContainsKey($matches[1])) { Fail 'dotenv_duplicate' }
        $values[$matches[1]] = $matches[2]
    }
    return $values
}

function Assert-PrivateEnv([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Exact (-not $item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) ($Code + '_type')
    $relative = $item.FullName.Substring($projectRoot.TrimEnd('\').Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Exact ($LASTEXITCODE -eq 0) ($Code + '_not_ignored')
    Assert-Exact (@(& git -C $projectRoot ls-files -- $relative).Count -eq 0) ($Code + '_tracked')
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
}

function Get-Volume([string]$Name, [bool]$Required) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $raw = @(& wsl.exe -d Ubuntu --exec docker volume inspect $Name 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) {
        if ($Required) { Fail 'volume_missing' }
        return $null
    }
    try { return @(([string]::Join("`n", $raw) | ConvertFrom-Json -ErrorAction Stop))[0] } catch { Fail 'volume_inspect_invalid' }
}

function Get-ModelVerifierScript {
    return @'
import hashlib,json,os,stat,sys
manifest_path='/spec/manifest.json'
cache='/cache'
raw=open(manifest_path,'rb').read()
expected_manifest=sys.argv[1]
if hashlib.sha256(raw).hexdigest()!=expected_manifest: raise SystemExit('manifest_digest')
manifest=json.loads(raw)
expected={'class-archive-model-manifest.json':(len(raw),expected_manifest)}
for item in manifest['artifacts']:
    expected[item['relative_cache_path']]=(int(item['file_size']),item['sha256'])
actual={}
for root,dirs,files in os.walk(cache,followlinks=False):
    dirs[:]=[d for d in dirs if not os.path.islink(os.path.join(root,d))]
    for name in files:
        path=os.path.join(root,name)
        if os.path.islink(path): raise SystemExit('link')
        rel=os.path.relpath(path,cache).replace(os.sep,'/')
        actual[rel]=path
if set(actual)!=set(expected): raise SystemExit('inventory')
for rel,path in actual.items():
    size,digest=expected[rel]
    st=os.stat(path)
    if st.st_size!=size or stat.S_IMODE(st.st_mode)!=0o444: raise SystemExit('metadata')
    h=hashlib.sha256()
    with open(path,'rb') as handle:
        for chunk in iter(lambda:handle.read(1024*1024),b''): h.update(chunk)
    if h.hexdigest()!=digest: raise SystemExit('digest')
if open(os.path.join(cache,'class-archive-model-manifest.json'),'rb').read()!=raw: raise SystemExit('runtime_manifest')
print(sum(v[0] for v in expected.values()))
'@
}

function Test-ModelCache([string]$Volume, [bool]$Required) {
    $record = Get-Volume $Volume $Required
    if ($null -eq $record) { return $null }
    $probe = Invoke-UbuntuDocker @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=$Volume,target=/cache,readonly", `
        'alpine:3.21', 'sh', '-lc', 'find /cache -mindepth 1 -print -quit')
    if ([string]::IsNullOrWhiteSpace($probe)) { return [int64]0 }
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes((Get-ModelVerifierScript)))
    $manifestWsl = Get-WslPath $manifest
    $raw = Invoke-UbuntuDocker @('run', '--rm', '--network', 'none', '--read-only', '--cap-drop', 'ALL', `
        '--security-opt', 'no-new-privileges', '--mount', "type=volume,source=$Volume,target=/cache,readonly", `
        '--mount', "type=bind,source=$manifestWsl,target=/spec/manifest.json,readonly", `
        '--entrypoint', 'sh', 'ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05', `
        '-lc', ('printf %s ' + $encoded + ' | base64 -d | python - ' + $expectedManifestSha256))
    if ($raw.Trim() -notmatch '^([0-9]{6,12})$') { Fail 'model_cache_verify_invalid' }
    return [int64]$matches[1]
}

function Assert-NoHostPorts([string[]]$Containers) {
    foreach ($container in $Containers) {
        $state = (Invoke-UbuntuDocker @('inspect', $container, '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{json .HostConfig.PortBindings}}')).Trim()
        $parts = $state -split '\|', 3
        Assert-Exact ($parts.Count -eq 3 -and $parts[0] -eq 'running' -and ($parts[1] -eq 'healthy' -or $parts[1] -eq 'none')) 'container_unhealthy'
        Assert-Exact ($parts[2] -eq 'null' -or $parts[2] -eq '{}') 'immich_host_port_forbidden'
    }
}

function Invoke-Preflight {
    $script:stage = 'environment'
    Assert-PrivateEnv $piwigoEnv 'piwigo_env'
    Assert-PrivateEnv $immichEnv 'immich_env'
    $values = Read-StrictEnv $immichEnv
    foreach ($pair in @(
        @('IMMICH_COMPOSE_PROJECT_NAME', $immichProject),
        @('IMMICH_MODEL_CACHE_VOLUME', $targetCache),
        @('IMMICH_DB_VOLUME', 'class_archive_private_full_v3_control_immich_db'),
        @('PIWIGO_UPLOADS_VOLUME', 'class_archive_private_full_v3_piwigo_uploads'),
        @('PIWIGO_GALLERIES_VOLUME', 'class_archive_private_full_v3_piwigo_galleries')
    )) {
        Assert-Exact ($values.ContainsKey($pair[0]) -and [string]$values[$pair[0]] -ceq [string]$pair[1]) 'environment_identity_invalid'
    }
    $script:stage = 'manifest'
    Assert-Exact ((Get-FileHash -LiteralPath $manifest -Algorithm SHA256).Hash.ToLowerInvariant() -ceq $expectedManifestSha256) 'manifest_digest_mismatch'
    & git -C $projectRoot diff --quiet HEAD -- $manifestRelative
    Assert-Exact ($LASTEXITCODE -eq 0) 'manifest_modified'
    $script:stage = 'source_cache'
    $sourceBytes = Test-ModelCache $sourceCache $true
    $script:stage = 'storage'
    $drive = Get-PSDrive -Name C
    Assert-Exact ([int64]$drive.Free -ge $minimumFreeBytes) 'host_free_space_insufficient'
    $targetBytes = Test-ModelCache $targetCache $false
    return [ordered]@{ source_bytes = $sourceBytes; target_bytes = $(if ($null -eq $targetBytes) { 0 } else { $targetBytes }); host_free_bytes = [int64]$drive.Free }
}

function Prepare-ModelCache {
    $result = Invoke-Preflight
    if ([int64]$result.target_bytes -gt 0) {
        return [ordered]@{ source_bytes = [int64]$result.source_bytes; target_bytes = [int64]$result.target_bytes; host_free_bytes = [int64]$result.host_free_bytes; cache = 'REUSED_VERIFIED' }
    }
    $script:stage = 'target_cache_create'
    $record = Get-Volume $targetCache $false
    if ($null -eq $record) {
        [void](Invoke-UbuntuDocker @('volume', 'create', '--label', 'com.classarchive.scope=private-real-full', `
            '--label', ('com.docker.compose.project=' + $immichProject), '--label', 'com.docker.compose.volume=immich_model_cache', $targetCache))
    } else {
        $probe = Invoke-UbuntuDocker @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=$targetCache,target=/cache,readonly", `
            'alpine:3.21', 'sh', '-lc', 'find /cache -mindepth 1 -print -quit')
        Assert-Exact ([string]::IsNullOrWhiteSpace($probe)) 'target_cache_partial_or_unknown'
    }
    $script:stage = 'target_cache_copy'
    [void](Invoke-UbuntuDocker @('run', '--rm', '--network', 'none', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', `
        '--mount', "type=volume,source=$sourceCache,target=/source,readonly", '--mount', "type=volume,source=$targetCache,target=/target", `
        'alpine:3.21', 'sh', '-lc', 'set -eu; test -z "$(find /target -mindepth 1 -print -quit)"; cp -R /source/. /target/; find /target -type f -exec chmod 0444 {} +; find /target -depth -type d -exec chmod 0555 {} +'))
    $script:stage = 'target_cache_verify'
    $targetBytes = Test-ModelCache $targetCache $true
    Assert-Exact ($targetBytes -eq [int64]$result.source_bytes) 'target_cache_size_mismatch'
    return [ordered]@{ source_bytes = [int64]$result.source_bytes; target_bytes = $targetBytes; host_free_bytes = [int64]$result.host_free_bytes; cache = 'IMPORTED_VERIFIED' }
}

function Wait-Healthy([string]$Container, [int]$Seconds) {
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    while ([DateTime]::UtcNow -lt $deadline) {
        $previous = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $state = @(& wsl.exe -d Ubuntu --exec docker inspect $Container --format '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' 2>&1)
            $code = $LASTEXITCODE
        } finally { $ErrorActionPreference = $previous }
        if ($code -eq 0 -and ([string]::Join("`n", $state)).Trim() -match '^running\|(healthy|none)$') { return }
        Start-Sleep -Seconds 2
    }
    Fail 'container_health_timeout'
}

try {
    if ($Action -eq 'preflight') {
        $result = Invoke-Preflight
        Write-Output ("PRIVATE_FULL_IMMICH=PASS action=preflight source_model_bytes={0} target_model_bytes={1} host_free_bytes={2} assertions={3} network=INTERNAL_ONLY" -f $result.source_bytes,$result.target_bytes,$result.host_free_bytes,$script:assertions)
        exit 0
    }
    if ($Action -eq 'prepare-model-cache') {
        $result = Prepare-ModelCache
        Write-Output ("PRIVATE_FULL_IMMICH=PASS action=prepare-model-cache cache={0} bytes={1} host_free_bytes={2} assertions={3}" -f $result.cache,$result.target_bytes,$result.host_free_bytes,$script:assertions)
        exit 0
    }
    if ($Action -eq 'up') {
        [void](Prepare-ModelCache)
        $script:stage = 'immich_start'
        [void](Invoke-ImmichCompose @('up', '-d', 'database', 'redis', 'immich-machine-learning', 'immich-server'))
        foreach ($name in @('database','redis','immich-machine-learning','immich-server')) {
            Wait-Healthy ($immichProject + '-' + $name + '-1') 600
        }
        $script:stage = 'immich_exposure'
        Assert-NoHostPorts @(
            ($immichProject + '-database-1'),
            ($immichProject + '-redis-1'),
            ($immichProject + '-immich-machine-learning-1'),
            ($immichProject + '-immich-server-1')
        )
        $script:stage = 'piwigo_runtime_without_worker'
        # Merely starting the isolated containers is not indexing evidence.
        # Keep the worker flag absent until the direct Immich runner has
        # completed Face/Search queues and the checksum-bound v15 hand-off.
        [void](Invoke-PiwigoBaseCompose @('up', '-d', '--force-recreate', 'piwigo'))
        Wait-Healthy ($piwigoProject + '-piwigo-1') 300
        Write-Output "PRIVATE_FULL_IMMICH=PASS action=up assertions=$script:assertions runtime=OFFLINE_INTERNAL worker=DISABLED_PENDING_RUNTIME_EVIDENCE host_ports=NONE"
        exit 0
    }
    if ($Action -in @('validate','status','provision','resume','finish','finalize-indexes')) {
        $script:stage = 'delegate'
        $delegate = Join-Path $PSScriptRoot 'private-qa-immich.ps1'
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $delegate -Action $Action -Runtime full
        if ($LASTEXITCODE -ne 0) { Fail 'delegate_failed' }
        Write-Output "PRIVATE_FULL_IMMICH=PASS action=$Action assertions=$script:assertions evidence=PRIVATE_FULL_RUNTIME"
        exit 0
    }
    Fail 'action_invalid'
} catch {
    if ($_.Exception.Message -like 'PRIVATE_FULL_IMMICH=FAIL*') { throw }
    Fail 'unexpected'
}
