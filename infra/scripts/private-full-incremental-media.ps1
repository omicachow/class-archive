[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'plan', 'apply')]
    [string]$Action = 'validate',

    [ValidateSet('full', 'restore')]
    [string]$Runtime = 'full'
)

# Explicit post-import delta operator.  It is never sourced by the web app and
# has no HTTP route.  It accepts only the checksum-bound NEW_PHOTO /
# PIXEL_CHANGED jobs and derivative queue markers already created at the
# controlled write boundary.  Any baseline drift, extra job or queue mismatch
# fails before a model or derivative generator is started.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$config = if ($Runtime -eq 'restore') {
    [ordered]@{
        private_relative = '.codex-work/owner-restore'
        piwigo_env = 'infra/owner-restore/.env.piwigo'
        immich_env = 'infra/owner-restore/.env.immich'
        piwigo_override = 'infra/owner-restore/docker-compose.piwigo.override.yml'
        immich_override = 'infra/owner-restore/docker-compose.immich.override.yml'
        piwigo_project = 'class_archive_owner_restore_v1_piwigo'
        immich_project = 'class_archive_owner_restore_v1_immich'
        report_prefix = 'owner-restore-incremental-media'
        core_port = 8290
        compat_port = 8291
    }
} else {
    [ordered]@{
        private_relative = '.codex-work/private-real-full'
        piwigo_env = 'infra/private-full/.env.piwigo.owner'
        immich_env = 'infra/private-full/.env.immich.owner'
        piwigo_override = 'infra/private-full/docker-compose.override.yml'
        immich_override = 'infra/private-full/docker-compose.immich.override.yml'
        piwigo_project = 'class_archive_private_full_v3_piwigo'
        immich_project = 'class_archive_private_full_v3_immich'
        report_prefix = 'private-full-incremental-media'
        core_port = 8190
        compat_port = 8191
    }
}
$privateRelative = [string]$config.private_relative
$privateRoot = Join-Path $projectRoot ($privateRelative -replace '/', '\')
$runtimeRoot = Join-Path $privateRoot 'runtime\incremental-media'
$reportRoot = Join-Path $privateRoot 'reports'
$modelManifest = Join-Path $projectRoot 'infra\immich-spike\ml-artifacts\manifest.json'
$catalogScript = '/workspace/infra/scripts/private-qa-immich-catalog.php'
$warmerScript = '/workspace/infra/scripts/warm-photo-cache.php'
$runtimeScriptHost = 'infra/scripts/private-qa-immich-incremental-runtime.mjs'
$planContainer = '/tmp/class-archive-private-qa-immich-incremental-plan.json'
$evidenceContainer = '/tmp/class-archive-private-qa-immich-incremental-evidence.json'
$runtimeContainer = '/tmp/class-archive-private-qa-immich-incremental-runtime.mjs'
$snapshotContainer = '/tmp/class-archive-private-qa-immich-incremental-snapshot.sql'
$exactDerivativeContainer = '/tmp/class-archive-photo-cache-exact.json'
$script:stage = 'initialization'
$script:assertions = 0

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Fail([string]$Code) {
    throw "PRIVATE_INCREMENTAL_MEDIA=FAIL stage=$script:stage code=$Code assertions=$script:assertions"
}

function Assert-Exact([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Fail $Code }
}

function Invoke-Piwigo([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file ([string]$config.piwigo_env) `
            -f 'infra/docker-compose.yml' -f ([string]$config.piwigo_override) `
            -f 'infra/private-full/docker-compose.ai-worker.override.yml' -p ([string]$config.piwigo_project) @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Fail 'piwigo_compose_failed' }
    return [string]::Join("`n", $lines)
}

function Invoke-Immich([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file ([string]$config.immich_env) `
            -f 'infra/immich-spike/docker-compose.yml' -f ([string]$config.immich_override) `
            -p ([string]$config.immich_project) --profile 'immich-spike' --profile 'immich-ml' `
            --profile 'immich-gateway-integration' @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Fail 'immich_compose_failed' }
    return [string]::Join("`n", $lines)
}

function Assert-IgnoredOwnerOnly([string]$Path) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Exact (-not $item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'private_file_type_invalid'
    $relative = $item.FullName.Substring($projectRoot.TrimEnd('\').Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Exact ($LASTEXITCODE -eq 0) 'private_file_not_ignored'
    Assert-Exact (@(& git -C $projectRoot ls-files -- $relative).Count -eq 0) 'private_file_tracked'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
}

function Write-OwnerOnlyJson([string]$Path, [object]$Value) {
    $parent = Split-Path -Parent $Path
    [void][IO.Directory]::CreateDirectory($parent)
    $raw = $Value | ConvertTo-Json -Depth 12 -Compress
    $utf8 = [Text.UTF8Encoding]::new($false)
    try {
        [IO.File]::WriteAllText($Path, $raw, $utf8)
        Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
        Assert-IgnoredOwnerOnly $Path
    } finally { $raw = $null }
}

function Write-OwnerOnlyText([string]$Path, [string]$Value) {
    $parent = Split-Path -Parent $Path
    [void][IO.Directory]::CreateDirectory($parent)
    $utf8 = [Text.UTF8Encoding]::new($false)
    try {
        [IO.File]::WriteAllText($Path, $Value, $utf8)
        Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
        Assert-IgnoredOwnerOnly $Path
    } finally { $Value = $null }
}

function Get-TextSha256([string]$Value) {
    $hash = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes($Value)
        return [BitConverter]::ToString($hash.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant()
    } finally {
        if ($null -ne $hash) { $hash.Dispose() }
    }
}

function Read-StrictJson([string]$Path, [int64]$MaximumBytes) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Exact ($item.Length -ge 16 -and $item.Length -le $MaximumBytes) 'private_json_size_invalid'
    Assert-IgnoredOwnerOnly $Path
    try { return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Fail 'private_json_invalid' }
}

function Get-ModelContract {
    Assert-Exact ((Get-FileHash -LiteralPath $modelManifest -Algorithm SHA256).Hash.ToLowerInvariant() `
        -eq '46380b30910608a8f0226d6ed14e3535cdd3f43c6080115e19842a8eaeda7e7a') 'model_manifest_digest_invalid'
    try { $manifest = Get-Content -LiteralPath $modelManifest -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Fail 'model_manifest_invalid' }
    $face = @($manifest.artifacts | Where-Object { $_.required -eq $true -and [string]$_.relative_cache_path -like 'facial-recognition/*' })
    $search = @($manifest.artifacts | Where-Object { $_.required -eq $true -and [string]$_.relative_cache_path -like 'clip/*' })
    $faceName = @($face | ForEach-Object { [string]$_.model_name } | Sort-Object -Unique)
    $faceRevision = @($face | ForEach-Object { [string]$_.exact_revision } | Sort-Object -Unique)
    $searchName = @($search | ForEach-Object { [string]$_.model_name } | Sort-Object -Unique)
    $searchRevision = @($search | ForEach-Object { [string]$_.exact_revision } | Sort-Object -Unique)
    Assert-Exact ($face.Count -ge 2 -and $search.Count -ge 4 -and $faceName.Count -eq 1 -and $faceRevision.Count -eq 1 `
        -and $searchName.Count -eq 1 -and $searchRevision.Count -eq 1) 'model_manifest_ambiguous'
    return [ordered]@{
        face_model_name = $faceName[0]
        face_model_revision = $faceRevision[0]
        search_model_name = $searchName[0]
        search_model_revision = $searchRevision[0]
    }
}

function Get-Warmup(
    [string]$Scope,
    [bool]$DryRun,
    [string]$QueueDigest = '',
    [string]$ExactManifestDigest = '',
    [string]$ExactDeltaDigest = ''
) {
    $arguments = @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $warmerScript, ('--scope=' + $Scope), '--json')
    if ($DryRun) { $arguments += '--dry-run' }
    if ($QueueDigest -ne '') {
        Assert-Exact ($Scope -eq 'queue' -and -not $DryRun -and $QueueDigest -match '^[0-9a-f]{64}$') 'warmup_queue_digest_invalid'
        $arguments += ('--queue-digest=' + $QueueDigest)
    }
    if ($ExactManifestDigest -ne '' -or $ExactDeltaDigest -ne '') {
        Assert-Exact ($Scope -eq 'exact' -and $DryRun -and $QueueDigest -eq '' `
            -and $ExactManifestDigest -match '^[0-9a-f]{64}$' `
            -and $ExactDeltaDigest -match '^[0-9a-f]{64}$') 'warmup_exact_digest_invalid'
        $arguments += ('--exact-manifest-digest=' + $ExactManifestDigest)
        $arguments += ('--exact-delta-digest=' + $ExactDeltaDigest)
    }
    $raw = Invoke-Piwigo $arguments
    try { return $raw | ConvertFrom-Json -ErrorAction Stop } catch { Fail 'warmup_output_invalid' }
}

function New-ImmichIndexSnapshotSql([object[]]$Markers) {
    Assert-Exact ($Markers.Count -ge 1 -and $Markers.Count -le 5000) 'snapshot_target_count_invalid'
    $ids = @()
    foreach ($marker in $Markers) {
        $id = [string]$marker.immich_asset_id
        Assert-Exact ($id -match '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$') 'snapshot_asset_id_invalid'
        $ids += $id.ToLowerInvariant()
    }
    Assert-Exact (@($ids | Sort-Object -Unique).Count -eq $ids.Count) 'snapshot_asset_duplicate'
    $values = [string]::Join(",`n", @($ids | ForEach-Object { "    ('$_'::uuid)" }))
    return @"
\set ON_ERROR_STOP on
WITH target("assetId") AS (
  VALUES
$values
), asset_rows AS (
  SELECT a.id,encode(a.checksum,'hex') AS checksum,a."updatedAt"::text AS updated_at
  FROM asset a INNER JOIN target t ON t."assetId"=a.id
), status_rows AS (
  SELECT s."assetId",s."facesRecognizedAt"::text AS faces_at
  FROM asset_job_status s INNER JOIN target t ON t."assetId"=s."assetId"
), smart_rows AS (
  SELECT s."assetId",md5(s.embedding::text) AS embedding_digest
  FROM smart_search s INNER JOIN target t ON t."assetId"=s."assetId"
), face_rows AS (
  SELECT f."assetId",f.id,f."personId",f."imageWidth",f."imageHeight",
         f."boundingBoxX1",f."boundingBoxY1",f."boundingBoxX2",f."boundingBoxY2",
         f."sourceType"::text AS source_type,f."deletedAt"::text AS deleted_at,
         f."updatedAt"::text AS updated_at,f."updateId",f."isVisible",
         fs."faceId" AS embedding_face_id,md5(COALESCE(fs.embedding::text,'')) AS embedding_digest
  FROM asset_face f INNER JOIN target t ON t."assetId"=f."assetId"
  LEFT JOIN face_search fs ON fs."faceId"=f.id
)
SELECT json_build_object(
  'version',1,
  'target_count',(SELECT count(*) FROM target),
  'asset_count',(SELECT count(*) FROM asset_rows),
  'status_ready',(SELECT count(*) FROM status_rows WHERE faces_at IS NOT NULL),
  'smart_ready',(SELECT count(*) FROM smart_rows),
  'face_count',(SELECT count(*) FROM face_rows),
  'face_embedding_required',(SELECT count(*) FROM face_rows WHERE source_type='machine-learning' AND deleted_at IS NULL),
  'face_embedding_ready',(SELECT count(*) FROM face_rows WHERE source_type='machine-learning' AND deleted_at IS NULL AND embedding_face_id IS NOT NULL),
  'asset_digest',COALESCE((SELECT md5(string_agg(id::text||'|'||checksum||'|'||updated_at,'' ORDER BY id)) FROM asset_rows),md5('')),
  'status_digest',COALESCE((SELECT md5(string_agg("assetId"::text||'|'||COALESCE(faces_at,''),'' ORDER BY "assetId")) FROM status_rows),md5('')),
  'smart_digest',COALESCE((SELECT md5(string_agg("assetId"::text||'|'||embedding_digest,'' ORDER BY "assetId")) FROM smart_rows),md5('')),
  'face_digest',COALESCE((SELECT md5(string_agg(
    "assetId"::text||'|'||id::text||'|'||COALESCE("personId"::text,'')||'|'||
    "imageWidth"::text||'|'||"imageHeight"::text||'|'||"boundingBoxX1"::text||'|'||
    "boundingBoxY1"::text||'|'||"boundingBoxX2"::text||'|'||"boundingBoxY2"::text||'|'||
    source_type||'|'||COALESCE(deleted_at,'')||'|'||updated_at||'|'||"updateId"::text||'|'||
    "isVisible"::text||'|'||embedding_digest,'' ORDER BY "assetId",id)) FROM face_rows),md5(''))
)::text;
"@
}

function Get-ImmichIndexSnapshot([object[]]$Markers, [string]$Name, [string]$Run) {
    Assert-Exact ($Name -match '^[a-z-]{3,32}$' -and $Run -match '^[0-9a-f]{16}$') 'snapshot_name_invalid'
    $host = Join-Path $runRoot ($Name + '.sql')
    Write-OwnerOnlyText $host (New-ImmichIndexSnapshotSql $Markers)
    $relative = $privateRelative + '/runtime/incremental-media/' + $Run + '/' + $Name + '.sql'
    try {
        [void](Invoke-Immich @('cp', $relative, ('database:' + $snapshotContainer)))
        [void](Invoke-Immich @('exec', '-T', '--user', '0:0', 'database', 'sh', '-lc', `
            ('chown postgres:postgres ' + $snapshotContainer + ' && chmod 0400 ' + $snapshotContainer)))
        $raw = Invoke-Immich @('exec', '-T', '--user', 'postgres', 'database', 'sh', '-lc', `
            ('exec psql -X --quiet --tuples-only --no-align --set ON_ERROR_STOP=1 --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --file=' + $snapshotContainer))
        try { $result = $raw | ConvertFrom-Json -ErrorAction Stop } catch { Fail 'snapshot_output_invalid' }
        $digests = @('asset_digest','status_digest','smart_digest','face_digest')
        Assert-Exact ([int]$result.version -eq 1 -and [int]$result.target_count -eq $Markers.Count `
            -and [int]$result.asset_count -ge 0 -and [int]$result.status_ready -ge 0 `
            -and [int]$result.smart_ready -ge 0 -and [int]$result.face_count -ge 0 `
            -and [int]$result.face_embedding_required -ge 0 -and [int]$result.face_embedding_required -le [int]$result.face_count `
            -and [int]$result.face_embedding_ready -ge 0 -and [int]$result.face_embedding_ready -le [int]$result.face_embedding_required) 'snapshot_contract_invalid'
        foreach ($field in $digests) {
            Assert-Exact ([string]$result.$field -match '^[0-9a-f]{32}$') 'snapshot_digest_invalid'
        }
        return $result
    } finally {
        try { [void](Invoke-Immich @('exec', '-T', '--user', '0:0', 'database', 'rm', '-f', '--', $snapshotContainer)) } catch { }
    }
}

function Get-ImmichSnapshotDigest([object]$Snapshot) {
    return Get-TextSha256 ([string]::Join('|', @(
        [string]$Snapshot.target_count,[string]$Snapshot.asset_count,[string]$Snapshot.status_ready,
        [string]$Snapshot.smart_ready,[string]$Snapshot.face_count,[string]$Snapshot.face_embedding_required,
        [string]$Snapshot.face_embedding_ready,[string]$Snapshot.asset_digest,
        [string]$Snapshot.status_digest,[string]$Snapshot.smart_digest,[string]$Snapshot.face_digest
    )))
}

function Assert-RuntimeBoundary {
    $script:stage = 'runtime_boundary'
    foreach ($path in @(
        (Join-Path $projectRoot (([string]$config.piwigo_env) -replace '/', '\')),
        (Join-Path $projectRoot (([string]$config.immich_env) -replace '/', '\'))
    )) {
        Assert-IgnoredOwnerOnly $path
    }
    foreach ($name in @(
        ([string]$config.piwigo_project + '-piwigo-1'),
        ([string]$config.piwigo_project + '-db-1'),
        ([string]$config.immich_project + '-immich-server-1'),
        ([string]$config.immich_project + '-immich-machine-learning-1'),
        ([string]$config.immich_project + '-database-1'),
        ([string]$config.immich_project + '-redis-1'),
        ([string]$config.immich_project + '-immich-gateway-1')
    )) {
        $state = (& wsl.exe -d Ubuntu --exec docker inspect $name --format '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{json .HostConfig.PortBindings}}' 2>$null)
        Assert-Exact ($LASTEXITCODE -eq 0 -and [string]$state -match '^running\|(healthy|none)\|') 'runtime_container_unhealthy'
        if ($name -notmatch '-piwigo-1$') {
            Assert-Exact ([string]$state -match '\|(null|\{\})$') 'internal_service_port_published'
        }
    }
    $ports = & wsl.exe -d Ubuntu --exec docker inspect ([string]$config.piwigo_project + '-piwigo-1') --format '{{json .HostConfig.PortBindings}}' 2>$null
    Assert-Exact ($LASTEXITCODE -eq 0 -and @($ports).Count -eq 1) 'piwigo_loopback_binding_invalid'
    try { $bindings = [string]$ports | ConvertFrom-Json -ErrorAction Stop }
    catch { Fail 'piwigo_loopback_binding_invalid' }
    $core = @($bindings.'80/tcp')
    $compat = @($bindings.'8081/tcp')
    Assert-Exact ($core.Count -eq 1 -and $compat.Count -eq 1 `
        -and [string]$core[0].HostIp -eq '127.0.0.1' -and [string]$core[0].HostPort -eq [string]$config.core_port `
        -and [string]$compat[0].HostIp -eq '127.0.0.1' -and [string]$compat[0].HostPort -eq [string]$config.compat_port `
        -and @($bindings.PSObject.Properties.Name | Where-Object { $_ -notin @('80/tcp','8081/tcp') }).Count -eq 0) `
        'piwigo_loopback_binding_invalid'
}

$lock = $null
$runRoot = $null
try {
    Assert-RuntimeBoundary
    if ($Action -eq 'validate') {
        Write-Output "PRIVATE_INCREMENTAL_MEDIA=PASS action=validate runtime=$Runtime assertions=$script:assertions evidence=RUNTIME_BOUNDARY"
        exit 0
    }

    $script:stage = 'single_instance_lock'
    [void][IO.Directory]::CreateDirectory($runtimeRoot)
    $lockPath = Join-Path $runtimeRoot 'operator.lock'
    if (-not (Test-Path -LiteralPath $lockPath)) { [IO.File]::WriteAllBytes($lockPath, [byte[]]@()) }
    Set-ClassArchiveOwnerOnlyFileAcl -Path $lockPath
    Assert-IgnoredOwnerOnly $lockPath
    try { $lock = [IO.File]::Open($lockPath, [IO.FileMode]::Open, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None) } catch { Fail 'already_running' }

    $run = ([Guid]::NewGuid().ToString('N')).Substring(0, 16)
    $runRoot = Join-Path $runtimeRoot $run
    [void][IO.Directory]::CreateDirectory($runRoot)
    $planHost = Join-Path $runRoot 'plan.json'
    $inputHost = Join-Path $runRoot 'runtime-input.json'
    $evidenceHost = Join-Path $runRoot 'evidence.json'

    $script:stage = 'container_temp_clean'
    [void](Invoke-Piwigo @('exec', '-T', '--user', 'nginx', 'piwigo', 'sh', '-lc', `
        ('test ! -e ' + $planContainer + ' -a ! -e ' + $evidenceContainer + ' -a ! -e ' + $exactDerivativeContainer)))
    [void](Invoke-Immich @('exec', '-T', '--user', '65532:65532', 'immich-gateway', 'sh', '-lc', `
        ('test ! -e ' + $planContainer + ' -a ! -e ' + $evidenceContainer + ' -a ! -e ' + $runtimeContainer)))
    [void](Invoke-Immich @('exec', '-T', '--user', 'postgres', 'database', 'sh', '-lc', ('test ! -e ' + $snapshotContainer)))

    $script:stage = 'delta_plan'
    $planMarker = Invoke-Piwigo @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'export-incremental')
    $planMatch = [regex]::Match($planMarker, '^PRIVATE_QA_IMMICH_CATALOG=PASS action=export-incremental count=([0-9]+) baseline=([0-9]+) delta=([0-9]+)$')
    Assert-Exact ($planMatch.Success) 'delta_plan_failed'
    [void](Invoke-Piwigo @('cp', ('piwigo:' + $planContainer), ($privateRelative + '/runtime/incremental-media/' + $run + '/plan.json')))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $planHost
    $plan = Read-StrictJson $planHost 8MB
    $catalogCount = [int]$plan.catalog_count
    $baselineCount = [int]$plan.baseline_count
    $deltaCount = [int]$plan.delta_count
    Assert-Exact ($catalogCount -eq [int]$planMatch.Groups[1].Value -and $baselineCount -eq [int]$planMatch.Groups[2].Value `
        -and $deltaCount -eq [int]$planMatch.Groups[3].Value -and $baselineCount + $deltaCount -eq $catalogCount `
        -and $deltaCount -ge 0 -and $deltaCount -le 512 `
        -and [string]$plan.delta_digest -match '^[0-9a-f]{64}$') 'delta_plan_contract_invalid'

    $deltaDerivativeKeys = @{}
    $deltaDerivativeEntries = [Collections.Generic.List[object]]::new()
    foreach ($delta in @($plan.delta)) {
        $classPhotoId = [string]$delta.class_photo_id
        $piwigoImageId = [int64]$delta.piwigo_image_id
        Assert-Exact ($classPhotoId -match '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$' `
            -and $piwigoImageId -ge 1 -and $piwigoImageId -le 2147483647) 'delta_derivative_marker_invalid'
        $key = $classPhotoId.ToLowerInvariant() + ':' + [string]$piwigoImageId
        Assert-Exact (-not $deltaDerivativeKeys.ContainsKey($key)) 'delta_derivative_marker_duplicate'
        $deltaDerivativeKeys[$key] = $true
        $deltaDerivativeEntries.Add([ordered]@{
            class_photo_id = $classPhotoId.ToLowerInvariant()
            piwigo_image_id = $piwigoImageId
        })
    }
    $deltaDerivativeEntries = @($deltaDerivativeEntries | Sort-Object class_photo_id,piwigo_image_id)
    Assert-Exact ($deltaDerivativeKeys.Count -eq $deltaCount) 'delta_derivative_marker_count_invalid'

    $script:stage = 'derivative_baseline'
    # The owner/full runtime has a precomputed derivative contract and must
    # continue proving that every baseline profile is present.  Restore
    # packages intentionally exclude the rebuildable derivative cache.  A
    # restore delta therefore must never invoke the all-library selector: the
    # durable queue selects the still-pending subset, while a read-only exact
    # manifest verifies any already-drained subset. Together they must
    # partition only the checksum-bound supplemental delta. This keeps missing
    # baseline derivatives outside the selection/generation set instead of
    # silently turning recovery validation into a 2k-photo warmup.
    $requireFullDerivativeCache = $Runtime -eq 'full'
    $prewarm = if ($requireFullDerivativeCache) { Get-Warmup 'all' $true } else { $null }
    $deltaPrewarm = Get-Warmup 'queue' $true
    $profiles = 6
    $pendingDerivativeKeys = @{}
    foreach ($entry in @($deltaPrewarm.queue_entries)) {
        $properties = @($entry.PSObject.Properties.Name | Sort-Object)
        $classPhotoId = [string]$entry.class_photo_id
        $piwigoImageId = [int64]$entry.piwigo_image_id
        Assert-Exact ($properties.Count -eq 2 -and $properties[0] -eq 'class_photo_id' -and $properties[1] -eq 'piwigo_image_id' `
            -and $classPhotoId -match '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$' `
            -and $piwigoImageId -ge 1 -and $piwigoImageId -le 2147483647) 'derivative_queue_marker_invalid'
        $key = $classPhotoId.ToLowerInvariant() + ':' + [string]$piwigoImageId
        Assert-Exact ($deltaDerivativeKeys.ContainsKey($key) -and -not $pendingDerivativeKeys.ContainsKey($key)) 'derivative_queue_outside_delta'
        $pendingDerivativeKeys[$key] = $true
    }
    $pendingDerivativeCount = $pendingDerivativeKeys.Count
    $completedDerivativeCount = $deltaCount - $pendingDerivativeCount
    Assert-Exact ($completedDerivativeCount -ge 0 -and [string]$deltaPrewarm.queue_digest -match '^[0-9a-f]{64}$') 'derivative_queue_count_invalid'
    $completedDerivativeEntries = [Collections.Generic.List[object]]::new()
    foreach ($delta in @($plan.delta)) {
        $classPhotoId = ([string]$delta.class_photo_id).ToLowerInvariant()
        $piwigoImageId = [int64]$delta.piwigo_image_id
        $key = $classPhotoId + ':' + [string]$piwigoImageId
        if (-not $pendingDerivativeKeys.ContainsKey($key)) {
            $completedDerivativeEntries.Add([ordered]@{
                class_photo_id = $classPhotoId
                piwigo_image_id = $piwigoImageId
            })
        }
    }
    $completedDerivativeEntries = @($completedDerivativeEntries | Sort-Object class_photo_id,piwigo_image_id)
    Assert-Exact ($completedDerivativeEntries.Count -eq $completedDerivativeCount) 'derivative_completed_set_invalid'
    if ($requireFullDerivativeCache) {
        Assert-Exact ([int]$prewarm.selected_images -eq $catalogCount -and [int]$prewarm.checked -eq $catalogCount * $profiles `
            -and [int]$prewarm.queued -eq $pendingDerivativeCount -and [int]$prewarm.queue_retained -eq $pendingDerivativeCount `
            -and [int]$prewarm.queue_quarantined -eq 0 `
            -and [int]$prewarm.queue_completed -eq 0 `
            -and [int]$prewarm.would_repair_mode -eq 0 -and [int]$prewarm.would_normalize_metadata -eq 0) 'derivative_baseline_not_ready'
        Assert-Exact ([int]$prewarm.cached - [int]$deltaPrewarm.cached -eq ($baselineCount + $completedDerivativeCount) * $profiles `
            -and [int]$prewarm.would_generate -eq [int]$deltaPrewarm.would_generate) 'derivative_full_cache_delta_mismatch'
    } else {
        # Restore packages intentionally omit the rebuildable baseline cache.
        # A partially drained retry is accepted only after a second bounded,
        # dry-run selector proves that every completed delta item has all six
        # profiles. The exact manifest is SHA-256 and plan-delta bound; it
        # cannot select any baseline item or generate a missing derivative.
        if ($completedDerivativeCount -gt 0) {
            $exactHost = Join-Path $runRoot 'completed-derivatives.json'
            Write-OwnerOnlyJson $exactHost ([ordered]@{
                version = 1
                delta_digest = [string]$plan.delta_digest
                entries = @($completedDerivativeEntries)
            })
            $exactManifestDigest = (Get-FileHash -LiteralPath $exactHost -Algorithm SHA256).Hash.ToLowerInvariant()
            $exactRelative = $privateRelative + '/runtime/incremental-media/' + $run + '/completed-derivatives.json'
            [void](Invoke-Piwigo @('cp', $exactRelative, ('piwigo:' + $exactDerivativeContainer)))
            [void](Invoke-Piwigo @('exec', '-T', '--user', '0:0', 'piwigo', 'sh', '-lc', `
                ('chown nginx:nginx ' + $exactDerivativeContainer + ' && chmod 0600 ' + $exactDerivativeContainer)))
            $completedPrewarm = Get-Warmup 'exact' $true '' $exactManifestDigest ([string]$plan.delta_digest)
            Assert-Exact ([int]$completedPrewarm.selected_images -eq $completedDerivativeCount `
                -and [int]$completedPrewarm.exact_entries -eq $completedDerivativeCount `
                -and [int]$completedPrewarm.checked -eq $completedDerivativeCount * $profiles `
                -and [int]$completedPrewarm.cached -eq $completedDerivativeCount * $profiles `
                -and [int]$completedPrewarm.would_generate -eq 0 -and [int]$completedPrewarm.generated -eq 0 `
                -and [int]$completedPrewarm.would_repair_mode -eq 0 `
                -and [int]$completedPrewarm.would_normalize_metadata -eq 0 `
                -and [string]$completedPrewarm.exact_manifest_digest -eq $exactManifestDigest `
                -and [string]$completedPrewarm.exact_delta_digest -eq [string]$plan.delta_digest) `
                'derivative_restore_completed_not_ready'
        }
    }
    Assert-Exact ([int]$deltaPrewarm.selected_images -eq $pendingDerivativeCount -and [int]$deltaPrewarm.checked -eq $pendingDerivativeCount * $profiles `
        -and [int]$deltaPrewarm.queued -eq $pendingDerivativeCount -and [int]$deltaPrewarm.queue_retained -eq $pendingDerivativeCount `
        -and [int]$deltaPrewarm.queue_quarantined -eq 0 `
        -and [int]$deltaPrewarm.queue_completed -eq 0 `
        -and [int]$deltaPrewarm.cached + [int]$deltaPrewarm.would_generate -eq $pendingDerivativeCount * $profiles `
        -and [int]$deltaPrewarm.would_repair_mode -eq 0 -and [int]$deltaPrewarm.would_normalize_metadata -eq 0) 'derivative_delta_preflight_invalid'

    $script:stage = 'ai_baseline_snapshot'
    $baselineBeforeDb = Get-ImmichIndexSnapshot @($plan.baseline) 'baseline-before' $run
    Assert-Exact ([int]$baselineBeforeDb.target_count -eq $baselineCount `
        -and [int]$baselineBeforeDb.asset_count -eq $baselineCount `
        -and [int]$baselineBeforeDb.status_ready -eq $baselineCount `
        -and [int]$baselineBeforeDb.smart_ready -eq $baselineCount `
        -and [int]$baselineBeforeDb.face_embedding_ready -eq [int]$baselineBeforeDb.face_embedding_required) 'ai_baseline_not_ready'
    $baselineBeforeDbDigest = Get-ImmichSnapshotDigest $baselineBeforeDb

    if ($Action -eq 'plan') {
        Write-Output ("PRIVATE_INCREMENTAL_MEDIA=PASS action=plan runtime={0} total={1} baseline={2} delta={3} ai_baseline=READY old_selected=0 assertions={4}" `
            -f $Runtime,$catalogCount,$baselineCount,$deltaCount,$script:assertions)
        exit 0
    }

    if ($deltaCount -eq 0) {
        # A second operator run is a verified no-op, not an excuse to schedule
        # a whole-library pass. Baseline AI rows were attested above. The full
        # runtime additionally proved all product derivatives; restore proved
        # an exact empty queue without selecting rebuildable baseline cache.
        $script:stage = 'aggregate_noop_report'
        [void][IO.Directory]::CreateDirectory($reportRoot)
        $report = Join-Path $reportRoot (([string]$config.report_prefix) + '-' + $run + '.json')
        Write-OwnerOnlyJson $report ([ordered]@{
            version = 1
            timestamp_utc = [DateTime]::UtcNow.ToString('o')
            runtime = $Runtime.ToUpperInvariant()
            total = $catalogCount
            baseline = $baselineCount
            delta = 0
            catalog_digest = [string]$plan.catalog_digest
            baseline_digest = [string]$plan.baseline_digest
            delta_digest = [string]$plan.delta_digest
            no_op = $true
            ai = [ordered]@{ result = 'PASS'; force_full = $false; old_asset_changes = 0; queues_started = 0 }
            derivatives = [ordered]@{ result = 'PASS'; selected = 0; generated = 0; old_selected = 0; pending = 0; queue_digest = [string]$deltaPrewarm.queue_digest }
            ai_baseline_digest = $baselineBeforeDbDigest
            private_artifacts = 'OWNER_ONLY_IGNORED'
            media_delivery = 'MEDIAGUARD_ONLY'
        })
        Write-Output ("PRIVATE_INCREMENTAL_MEDIA=PASS action=apply runtime={0} total={1} baseline={2} delta=0 no_op=1 ai_old_changed=0 derivative_old_selected=0 assertions={3}" `
            -f $Runtime,$catalogCount,$baselineCount,$script:assertions)
        exit 0
    }

    $script:stage = 'runtime_input'
    $input = [ordered]@{}
    foreach ($property in $plan.PSObject.Properties) { $input[$property.Name] = $property.Value }
    $input['models'] = Get-ModelContract
    Write-OwnerOnlyJson $inputHost $input
    [void](Invoke-Immich @('cp', $runtimeScriptHost, ('immich-gateway:' + $runtimeContainer)))
    [void](Invoke-Immich @('cp', ($privateRelative + '/runtime/incremental-media/' + $run + '/runtime-input.json'), ('immich-gateway:' + $planContainer)))
    [void](Invoke-Immich @('exec', '-T', '--user', '0:0', 'immich-gateway', 'sh', '-lc', `
        ('chown 65532:65532 ' + $runtimeContainer + ' ' + $planContainer + ' && chmod 0500 ' + $runtimeContainer + ' && chmod 0600 ' + $planContainer)))

    $script:stage = 'runtime_delta'
    $marker = Invoke-Immich @('exec', '-T', '--user', '65532:65532', 'immich-gateway', 'sh', '-lc', ('exec node ' + $runtimeContainer + ' 2>&1'))
    $runtimeMatch = [regex]::Match($marker, '^PRIVATE_QA_IMMICH_INCREMENTAL=PASS assets=([0-9]+) baseline=([0-9]+) delta=([0-9]+) old_changed=0 force_full=0$')
    Assert-Exact ($runtimeMatch.Success -and [int]$runtimeMatch.Groups[1].Value -eq $catalogCount `
        -and [int]$runtimeMatch.Groups[2].Value -eq $baselineCount -and [int]$runtimeMatch.Groups[3].Value -eq $deltaCount) 'runtime_delta_failed'
    [void](Invoke-Immich @('cp', ('immich-gateway:' + $evidenceContainer), ($privateRelative + '/runtime/incremental-media/' + $run + '/evidence.json')))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $evidenceHost
    $evidence = Read-StrictJson $evidenceHost 1MB
    Assert-Exact ([string]$evidence.runtime_mode -eq 'INCREMENTAL' -and [int]$evidence.asset_count -eq $catalogCount `
        -and [int]$evidence.baseline_count -eq $baselineCount -and [int]$evidence.delta_count -eq $deltaCount `
        -and $evidence.force_full -eq $false -and [int]$evidence.old_asset_changes -eq 0 `
        -and $evidence.library_queue_idle -eq $true -and $evidence.metadata_queue_idle -eq $true `
        -and $evidence.thumbnail_queue_idle -eq $true -and $evidence.face_queue_idle -eq $true `
        -and $evidence.recognition_queue_idle -eq $true -and $evidence.search_queue_idle -eq $true `
        -and [string]$evidence.baseline_runtime_digest_before -eq [string]$evidence.baseline_runtime_digest_after) 'runtime_evidence_invalid'

    $script:stage = 'ai_delta_snapshot'
    $baselineAfterDb = Get-ImmichIndexSnapshot @($plan.baseline) 'baseline-after' $run
    Assert-Exact ((Get-ImmichSnapshotDigest $baselineAfterDb) -eq $baselineBeforeDbDigest) 'ai_baseline_reprocessed'
    $deltaIds = @{}
    foreach ($delta in @($plan.delta)) { $deltaIds[[string]$delta.class_photo_id] = $true }
    $deltaBindings = @($evidence.assets | Where-Object { $deltaIds.ContainsKey([string]$_.class_photo_id) })
    Assert-Exact ($deltaBindings.Count -eq $deltaCount) 'ai_delta_binding_count_invalid'
    $deltaAfterDb = Get-ImmichIndexSnapshot $deltaBindings 'delta-after' $run
    Assert-Exact ([int]$deltaAfterDb.target_count -eq $deltaCount -and [int]$deltaAfterDb.asset_count -eq $deltaCount `
        -and [int]$deltaAfterDb.status_ready -eq $deltaCount -and [int]$deltaAfterDb.smart_ready -eq $deltaCount `
        -and [int]$deltaAfterDb.face_embedding_ready -eq [int]$deltaAfterDb.face_embedding_required) 'ai_delta_not_ready'

    # Drain only the exact durable marker subset before committing Class AI
    # state. If this process dies before marker completion, the AI delta remains
    # PENDING with the same exact queue. The full runtime can accept a marker
    # subset only after proving every drained item is cached. Restore proves
    # the same fact through the bounded exact delta manifest rather than the
    # intentionally absent baseline cache. Neither path can expand to baseline.
    $script:stage = 'derivative_delta'
    $warm = Get-Warmup 'queue' $false ([string]$deltaPrewarm.queue_digest)
    Assert-Exact ([int]$warm.selected_images -eq $pendingDerivativeCount -and [int]$warm.checked -eq $pendingDerivativeCount * $profiles `
        -and [int]$warm.generated + [int]$warm.cached -eq $pendingDerivativeCount * $profiles `
        -and [int]$warm.queue_completed -eq $pendingDerivativeCount -and [int]$warm.queue_retained -eq 0 `
        -and [int]$warm.queue_quarantined -eq 0 `
        -and [int]$warm.metadata_normalized -eq 0 -and [int]$warm.mode_repairs -eq 0) 'derivative_delta_invalid'
    $postwarm = Get-Warmup 'queue' $true
    Assert-Exact ([int]$postwarm.selected_images -eq 0 -and [int]$postwarm.checked -eq 0 `
        -and [int]$postwarm.queued -eq 0 -and [int]$postwarm.queue_quarantined -eq 0 `
        -and [int]$postwarm.would_generate -eq 0 -and @($postwarm.queue_entries).Count -eq 0) 'derivative_delta_not_idempotent'
    if ($requireFullDerivativeCache) {
        $postwarmAll = Get-Warmup 'all' $true
        Assert-Exact ([int]$postwarmAll.selected_images -eq $catalogCount -and [int]$postwarmAll.checked -eq $catalogCount * $profiles `
            -and [int]$postwarmAll.cached -eq $catalogCount * $profiles -and [int]$postwarmAll.would_generate -eq 0 `
            -and [int]$postwarmAll.queued -eq 0 -and [int]$postwarmAll.queue_quarantined -eq 0 `
            -and [int]$postwarmAll.would_repair_mode -eq 0 -and [int]$postwarmAll.would_normalize_metadata -eq 0) 'derivative_library_not_ready'
    } elseif ($deltaCount -gt 0) {
        # Re-attest the complete delta after draining the pending subset. This
        # remains bounded to the same plan digest and catches deletion or
        # corruption between the completed-subset preflight and final commit.
        $postExactHost = Join-Path $runRoot 'all-delta-derivatives.json'
        Write-OwnerOnlyJson $postExactHost ([ordered]@{
            version = 1
            delta_digest = [string]$plan.delta_digest
            entries = @($deltaDerivativeEntries)
        })
        $postExactDigest = (Get-FileHash -LiteralPath $postExactHost -Algorithm SHA256).Hash.ToLowerInvariant()
        $postExactRelative = $privateRelative + '/runtime/incremental-media/' + $run + '/all-delta-derivatives.json'
        [void](Invoke-Piwigo @('cp', $postExactRelative, ('piwigo:' + $exactDerivativeContainer)))
        [void](Invoke-Piwigo @('exec', '-T', '--user', '0:0', 'piwigo', 'sh', '-lc', `
            ('chown nginx:nginx ' + $exactDerivativeContainer + ' && chmod 0600 ' + $exactDerivativeContainer)))
        $postExact = Get-Warmup 'exact' $true '' $postExactDigest ([string]$plan.delta_digest)
        Assert-Exact ([int]$postExact.selected_images -eq $deltaCount `
            -and [int]$postExact.exact_entries -eq $deltaCount `
            -and [int]$postExact.checked -eq $deltaCount * $profiles `
            -and [int]$postExact.cached -eq $deltaCount * $profiles `
            -and [int]$postExact.would_generate -eq 0 -and [int]$postExact.generated -eq 0 `
            -and [int]$postExact.would_repair_mode -eq 0 -and [int]$postExact.would_normalize_metadata -eq 0 `
            -and [string]$postExact.exact_manifest_digest -eq $postExactDigest `
            -and [string]$postExact.exact_delta_digest -eq [string]$plan.delta_digest) `
            'derivative_restore_post_delta_not_ready'
    }

    # This is the final state transition.  Therefore any earlier model or
    # derivative failure remains safely retryable as the same checksum-bound
    # PENDING delta; ordinary GET cannot perform this action.
    $script:stage = 'index_control_plane_commit'
    [void](Invoke-Piwigo @('cp', ($privateRelative + '/runtime/incremental-media/' + $run + '/evidence.json'), ('piwigo:' + $evidenceContainer)))
    [void](Invoke-Piwigo @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $evidenceContainer + ' && chmod 0600 ' + $evidenceContainer)))
    $completeMarker = Invoke-Piwigo @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', $catalogScript, 'complete-incremental')
    $completeMatch = [regex]::Match($completeMarker, '^PRIVATE_QA_IMMICH_CATALOG=PASS action=complete-incremental count=([0-9]+) baseline=([0-9]+) delta=([0-9]+) completed=([0-9]+) old_changed=0 state=READY$')
    Assert-Exact ($completeMatch.Success -and [int]$completeMatch.Groups[1].Value -eq $catalogCount `
        -and [int]$completeMatch.Groups[2].Value -eq $baselineCount -and [int]$completeMatch.Groups[3].Value -eq $deltaCount `
        -and [int]$completeMatch.Groups[4].Value -eq $deltaCount) 'index_control_plane_commit_failed'

    $script:stage = 'aggregate_report'
    [void][IO.Directory]::CreateDirectory($reportRoot)
    $report = Join-Path $reportRoot (([string]$config.report_prefix) + '-' + $run + '.json')
    Write-OwnerOnlyJson $report ([ordered]@{
        version = 1
        timestamp_utc = [DateTime]::UtcNow.ToString('o')
        runtime = $Runtime.ToUpperInvariant()
        total = $catalogCount
        baseline = $baselineCount
        delta = $deltaCount
        catalog_digest = [string]$plan.catalog_digest
        baseline_digest = [string]$plan.baseline_digest
        delta_digest = [string]$plan.delta_digest
        ai = [ordered]@{ result = 'PASS'; force_full = $false; old_asset_changes = 0; queues_idle = $true }
        derivatives = [ordered]@{ result = 'PASS'; selected = $pendingDerivativeCount; previously_completed = $completedDerivativeCount; generated = [int]$warm.generated; old_selected = 0; pending = 0; queue_digest = [string]$deltaPrewarm.queue_digest }
        ai_baseline_digest = $baselineBeforeDbDigest
        private_artifacts = 'OWNER_ONLY_IGNORED'
        media_delivery = 'MEDIAGUARD_ONLY'
    })
    Write-Output ("PRIVATE_INCREMENTAL_MEDIA=PASS action=apply runtime={0} total={1} baseline={2} delta={3} ai_old_changed=0 derivative_old_selected=0 assertions={4}" `
        -f $Runtime,$catalogCount,$baselineCount,$deltaCount,$script:assertions)
} catch {
    if ([string]$_.Exception.Message -like 'PRIVATE_INCREMENTAL_MEDIA=FAIL*') { throw }
    Fail 'unexpected'
} finally {
    # `validate` is read-only and never creates container temporaries.  Do not
    # turn its cleanup path into a write against either runtime.
    if ($null -ne $runRoot) {
        try { [void](Invoke-Piwigo @('exec', '-T', '--user', 'nginx', 'piwigo', 'rm', '-f', '--', $planContainer, $evidenceContainer, $exactDerivativeContainer)) } catch { }
        try { [void](Invoke-Immich @('exec', '-T', '--user', '65532:65532', 'immich-gateway', 'rm', '-f', '--', $planContainer, $evidenceContainer, $runtimeContainer)) } catch { }
        try { [void](Invoke-Immich @('exec', '-T', '--user', '0:0', 'database', 'rm', '-f', '--', $snapshotContainer)) } catch { }
    }
    if ($null -ne $runRoot -and (Test-Path -LiteralPath $runRoot)) {
        foreach ($name in @('plan.json','runtime-input.json','evidence.json','completed-derivatives.json','all-delta-derivatives.json','baseline-before.sql','baseline-after.sql','delta-after.sql')) {
            $path = Join-Path $runRoot $name
            if (Test-Path -LiteralPath $path) { try { Remove-Item -LiteralPath $path -Force } catch { } }
        }
    }
    if ($null -ne $lock) { $lock.Dispose() }
}
