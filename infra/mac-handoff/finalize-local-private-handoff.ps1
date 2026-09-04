[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$PackageRoot,
    [Parameter(Mandatory)]
    [string]$ApprovedStagingRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Fail([string]$Code) {
    throw "LOCAL_HANDOFF_FINALIZE=FAIL reason=$Code"
}

$approvedBase = (Resolve-Path -LiteralPath $ApprovedStagingRoot).Path
$approvedBaseItem = Get-Item -LiteralPath $approvedBase -Force
if (-not $approvedBaseItem.PSIsContainer -or ($approvedBaseItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    Fail 'approved_staging_root_invalid_or_reparse_point'
}
$resolvedRoot = (Resolve-Path -LiteralPath $PackageRoot).Path
$relativeRoot = [IO.Path]::GetRelativePath($approvedBase, $resolvedRoot).Replace('\','/')
$rootMatch = [regex]::Match($relativeRoot, '^[.]staging-([0-9]{8}T[0-9]{6}Z)/ClassArchive-Complete-Mac-Handoff-([0-9]{8}T[0-9]{6}Z)$')
if (-not $rootMatch.Success -or $rootMatch.Groups[1].Value -cne $rootMatch.Groups[2].Value) {
    Fail 'package_root_outside_approved_m_staging'
}
$rootItem = Get-Item -LiteralPath $resolvedRoot -Force
if (-not $rootItem.PSIsContainer -or ($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    Fail 'package_root_reparse_point_forbidden'
}

$payloadRoot = Join-Path $resolvedRoot 'payloads'
if (-not (Test-Path -LiteralPath $payloadRoot -PathType Container)) { Fail 'payload_root_missing' }
foreach ($name in @('HANDOFF-MAC-PRIVATE.md','manifest.json','checksums.sha256','COMPLETE','COMPLETE.partial')) {
    if (Test-Path -LiteralPath (Join-Path $resolvedRoot $name)) { Fail "immutable_output_already_exists_$name" }
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$head = (git -C $projectRoot rev-parse HEAD).Trim()
$branch = (git -C $projectRoot branch --show-current).Trim()
if ($head -notmatch '^[0-9a-f]{40}$') { Fail 'git_head_invalid' }
if ($branch -notmatch '^codex/[a-z0-9._/-]+$') { Fail 'git_branch_invalid' }
if (git -C $projectRoot status --short) { Fail 'git_worktree_dirty' }
$remoteBase = (git -C $projectRoot rev-parse --verify "origin/$branch^{commit}").Trim()
if ($LASTEXITCODE -ne 0 -or $remoteBase -notmatch '^[0-9a-f]{40}$') { Fail 'git_remote_base_missing' }
# Security gate (equivalent invocation with an explicit repository): git fsck --full --strict
$null = git -C $projectRoot fsck --full --strict 2>&1
if ($LASTEXITCODE -ne 0) { Fail 'git_fsck_failed' }
$boundaryScript = Join-Path $projectRoot 'infra\scripts\verify-public-boundary.ps1'
$pwsh = (Get-Process -Id $PID).Path
$headBoundary = @(& $pwsh -NoProfile -ExecutionPolicy Bypass -File $boundaryScript -Mode Head -RepositoryRoot $projectRoot 2>&1)
if ($LASTEXITCODE -ne 0 -or -not (@($headBoundary | Where-Object { [string]$_ -match '^PUBLIC_BOUNDARY=PASS mode=HEAD ' }).Count -eq 1)) {
    Fail 'git_head_public_boundary_failed'
}
$outgoingBoundary = @(& $pwsh -NoProfile -ExecutionPolicy Bypass -File $boundaryScript -Mode Outgoing -BaseRef $remoteBase -RepositoryRoot $projectRoot 2>&1)
if ($LASTEXITCODE -ne 0 -or -not (@($outgoingBoundary | Where-Object { [string]$_ -match '^PUBLIC_BOUNDARY=PASS mode=OUTGOING ' }).Count -eq 1)) {
    Fail 'git_outgoing_public_boundary_failed'
}

$handoffDocSource = Join-Path $projectRoot 'infra\mac-handoff\HANDOFF-MAC-PRIVATE.md'
$handoffDocTarget = Join-Path $resolvedRoot 'HANDOFF-MAC-PRIVATE.md'

$sourceInventoryPath = Join-Path $resolvedRoot 'payloads\private-metadata\source-inventory-before.json'
$sourceInventoryAfterPath = Join-Path $resolvedRoot 'payloads\private-metadata\source-inventory-after.json'
$ownerFixturePath = Join-Path $resolvedRoot 'payloads\private-metadata\owner-restore-fixture.json'
$ownerMariaCountsPath = Join-Path $resolvedRoot 'payloads\private-metadata\owner-mariadb-counts.json'
$ownerPostgresCountsPath = Join-Path $resolvedRoot 'payloads\private-metadata\owner-postgres-counts.json'
$ownerCaptureCountsPath = Join-Path $resolvedRoot 'payloads\private-metadata\owner-capture-counts.json'
$ownerPostgresCaptureCountsPath = Join-Path $resolvedRoot 'payloads\private-metadata\owner-postgres-capture-counts.json'
$sanitizationPath = Join-Path $resolvedRoot 'payloads\private-metadata\runtime-sanitization.json'
$syntheticFixturePath = Join-Path $resolvedRoot 'payloads\synthetic\synthetic-restore-fixture.json'
$syntheticCaptureCountsPath = Join-Path $resolvedRoot 'payloads\synthetic\synthetic-capture-counts.json'
foreach ($path in @($sourceInventoryPath,$sourceInventoryAfterPath,$ownerFixturePath,$ownerMariaCountsPath,$ownerPostgresCountsPath,$ownerCaptureCountsPath,$ownerPostgresCaptureCountsPath,$sanitizationPath,$syntheticFixturePath,$syntheticCaptureCountsPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Fail ('required_evidence_missing_' + [IO.Path]::GetFileName($path)) }
}

$sourceInventory = Get-Content -LiteralPath $sourceInventoryPath -Raw | ConvertFrom-Json
$sourceInventoryAfter = Get-Content -LiteralPath $sourceInventoryAfterPath -Raw | ConvertFrom-Json
$ownerFixture = Get-Content -LiteralPath $ownerFixturePath -Raw | ConvertFrom-Json
$ownerMaria = Get-Content -LiteralPath $ownerMariaCountsPath -Raw | ConvertFrom-Json
$ownerPostgres = Get-Content -LiteralPath $ownerPostgresCountsPath -Raw | ConvertFrom-Json
$ownerCapture = Get-Content -LiteralPath $ownerCaptureCountsPath -Raw | ConvertFrom-Json
$ownerPostgresCapture = Get-Content -LiteralPath $ownerPostgresCaptureCountsPath -Raw | ConvertFrom-Json
$sanitization = Get-Content -LiteralPath $sanitizationPath -Raw | ConvertFrom-Json
$syntheticFixture = Get-Content -LiteralPath $syntheticFixturePath -Raw | ConvertFrom-Json
$syntheticCapture = Get-Content -LiteralPath $syntheticCaptureCountsPath -Raw | ConvertFrom-Json

$imageExtensions = @('.jpg','.jpeg','.png','.webp','.heic','.heif','.gif','.bmp','.tif','.tiff','.avif','.mpo')
$videoExtensions = @('.mp4','.mov','.m4v','.avi','.mkv','.webm','.mts','.m2ts','.3gp')
$sourceFiles = @($sourceInventory.files)
$sourceImageCount = @($sourceFiles | Where-Object { $imageExtensions -contains ([string]$_.extension).ToLowerInvariant() }).Count
$sourceVideoCount = @($sourceFiles | Where-Object { $videoExtensions -contains ([string]$_.extension).ToLowerInvariant() }).Count
$sourceOtherCount = $sourceFiles.Count - $sourceImageCount - $sourceVideoCount

$expectedPayloads = [ordered]@{}
function Add-ExpectedPayload([string]$Path, [string]$Classification, [string]$Component) {
    if ($expectedPayloads.Contains($Path)) { Fail "duplicate_expected_payload_$Path" }
    $expectedPayloads[$Path] = [ordered]@{ classification=$Classification; component=$Component }
}

$sourceDirectory = Join-Path $payloadRoot 'source'
$upstreamCaches = @(Get-ChildItem -LiteralPath $sourceDirectory -File -Force | Where-Object { $_.Name -match '^official-upstream-cache-[0-9]{8}T[0-9]{6}Z[.]tar[.]gz$' })
if ($upstreamCaches.Count -ne 1) { Fail 'official_upstream_cache_set_invalid' }
Add-ExpectedPayload "payloads/source/class-archive-source-$head.tar.gz" 'PUBLIC_SAFE_SOURCE' 'SOURCE_CODE'
Add-ExpectedPayload "payloads/source/class-archive-history-$head.bundle" 'PUBLIC_SAFE_SOURCE' 'SOURCE_CODE'
Add-ExpectedPayload ('payloads/source/' + $upstreamCaches[0].Name) 'PUBLIC_SAFE_SOURCE' 'SOURCE_CODE'
foreach ($name in @('immich-upstream.lock.json','ml-artifact-manifest.json','container-lock.json')) {
    Add-ExpectedPayload "payloads/source/$name" 'PUBLIC_SAFE_SOURCE' 'IMMICH_LOCKS_AND_ML_MANIFEST'
}
foreach ($name in @(
    'synthetic-extra-fixtures.tar','synthetic-restore-fixture.json','synthetic-capture-counts.json',
    'synthetic-mariadb.sql.gz','synthetic-piwigo-data.tar','synthetic-piwigo-scripts.tar',
    'synthetic-uploads.tar','synthetic-galleries.tar','synthetic-derivatives.tar'
)) { Add-ExpectedPayload "payloads/synthetic/$name" 'SYNTHETIC_TEST_DATA' 'SYNTHETIC_BASELINE' }
foreach ($name in @('owner-mariadb.sql.gz')) {
    Add-ExpectedPayload "payloads/owner/$name" 'PRIVATE_UNENCRYPTED_LOCAL_DATA' 'OWNER_MARIADB'
}
Add-ExpectedPayload 'payloads/owner/owner-immich-postgres.dump' 'PRIVATE_UNENCRYPTED_LOCAL_DATA' 'OWNER_IMMICH_POSTGRES'
foreach ($name in @('owner-canonical-uploads.tar','owner-canonical-galleries.tar','owner-immich-canonical.tar')) {
    Add-ExpectedPayload "payloads/owner/$name" 'PRIVATE_UNENCRYPTED_LOCAL_DATA' 'OWNER_CANONICAL_MEDIA'
}
foreach ($name in @('owner-piwigo-derivatives.tar','owner-immich-derivatives.tar')) {
    Add-ExpectedPayload "payloads/owner/$name" 'PRIVATE_UNENCRYPTED_LOCAL_DATA' 'OWNER_DERIVATIVES'
}
foreach ($name in @('owner-piwigo-data.tar','owner-piwigo-scripts.tar')) {
    Add-ExpectedPayload "payloads/owner/$name" 'PRIVATE_UNENCRYPTED_LOCAL_DATA' 'OWNER_PRIVATE_METADATA'
}
foreach ($name in @(
    'owner-restore-fixture.json','owner-mariadb-counts.json','owner-postgres-counts.json',
    'owner-capture-counts.json','owner-postgres-capture-counts.json','runtime-sanitization.json',
    'private-import-and-provenance.tar','source-inventory-before.json','source-inventory-after.json',
    'source-allowlist-a.nul','source-allowlist-b.nul'
)) { Add-ExpectedPayload "payloads/private-metadata/$name" 'PRIVATE_UNENCRYPTED_LOCAL_DATA' 'OWNER_PRIVATE_METADATA' }
foreach ($name in @('private-source-a.tar','private-source-b.tar')) {
    Add-ExpectedPayload "payloads/private-sources/$name" 'PRIVATE_UNENCRYPTED_LOCAL_DATA' 'PRIVATE_SOURCE_LIBRARY'
}

$allEntries = @(Get-ChildItem -LiteralPath $payloadRoot -Recurse -Force)
if (@($allEntries | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }).Count -ne 0) {
    Fail 'payload_reparse_point_forbidden'
}
$allowedDirectories = @('payloads/source','payloads/synthetic','payloads/owner','payloads/private-metadata','payloads/private-sources')
$actualDirectories = @($allEntries | Where-Object { $_.PSIsContainer } | ForEach-Object { [IO.Path]::GetRelativePath($resolvedRoot,$_.FullName).Replace('\','/') })
if (@($actualDirectories | Where-Object { $_ -notin $allowedDirectories }).Count -ne 0 -or @($allowedDirectories | Where-Object { $_ -notin $actualDirectories }).Count -ne 0) {
    Fail 'payload_directory_set_invalid'
}
$files = @($allEntries | Where-Object { -not $_.PSIsContainer } | Sort-Object FullName)
$actualPayloadPaths = @($files | ForEach-Object { [IO.Path]::GetRelativePath($resolvedRoot,$_.FullName).Replace('\','/') })
if ($files.Count -ne $expectedPayloads.Count -or @($actualPayloadPaths | Where-Object { -not $expectedPayloads.Contains($_) }).Count -ne 0 -or @($expectedPayloads.Keys | Where-Object { $_ -notin $actualPayloadPaths }).Count -ne 0) {
    Fail 'payload_exact_set_invalid'
}

$sourceBundlePath = Join-Path $resolvedRoot "payloads\source\class-archive-history-$head.bundle"
$null = git -C $projectRoot bundle verify $sourceBundlePath 2>&1
if ($LASTEXITCODE -ne 0) { Fail 'git_bundle_verify_failed' }
$bundleHeads = @(git -C $projectRoot bundle list-heads $sourceBundlePath | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
if ($LASTEXITCODE -ne 0 -or $bundleHeads.Count -ne 1 -or $bundleHeads[0] -cne "$head refs/heads/$branch") { Fail 'git_bundle_head_set_invalid' }
$containerLock = Get-Content -LiteralPath (Join-Path $sourceDirectory 'container-lock.json') -Raw | ConvertFrom-Json
if ([string]$containerLock.source_git_head -cne $head) { Fail 'container_lock_git_head_mismatch' }

function Convert-ToWslPath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $match = [regex]::Match($full, '^([A-Za-z]):\\(.*)$')
    if (-not $match.Success) { Fail 'wsl_path_conversion_failed' }
    $drive = $match.Groups[1].Value.ToLowerInvariant()
    $tail = $match.Groups[2].Value.Replace('\','/')
    return "/mnt/$drive/$tail"
}
$sourceVerifier = Convert-ToWslPath (Join-Path $projectRoot 'infra\mac-handoff\verify-private-source-archives.py')
$sourceVerifyOutput = @(& wsl.exe -d Ubuntu -- python3 -I $sourceVerifier (Convert-ToWslPath $sourceInventoryPath) (Convert-ToWslPath $sourceInventoryAfterPath) (Convert-ToWslPath (Join-Path $resolvedRoot 'payloads\private-sources\private-source-a.tar')) (Convert-ToWslPath (Join-Path $resolvedRoot 'payloads\private-sources\private-source-b.tar')) 2>&1)
if ($LASTEXITCODE -ne 0 -or 'PRIVATE_SOURCE_ARCHIVE_VERIFY=PASS' -notin $sourceVerifyOutput) { Fail 'private_source_archive_verification_failed' }

$payloads = [Collections.Generic.List[object]]::new()
foreach ($file in $files) {
    $relative = [IO.Path]::GetRelativePath($resolvedRoot,$file.FullName).Replace('\','/')
    $basename = $file.Name.ToLowerInvariant()
    if ($basename -match '(^[.]env(?:[.]|$)|secret|token|cookie|session|password|credential|private[-_]?key|data[-_]?key)' -or $basename.EndsWith('.partial') -or $basename.EndsWith('.tmp') -or $basename.EndsWith('.log')) {
        Fail "secret_or_transient_named_payload_forbidden_$relative"
    }
    $contract = $expectedPayloads[$relative]
    if ($file.Length -le 0) { Fail "payload_empty_$relative" }
    $sha = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    $payloads.Add([ordered]@{
        path = $relative
        component = $contract.component
        classification = $contract.classification
        encrypted = $false
        required = $true
        size = [int64]$file.Length
        sha256 = $sha
    })
}

$summary = $ownerFixture.summary
if ([string]$ownerCapture.format -cne 'class-archive-owner-capture-counts-v1' -or [string]$ownerPostgresCapture.format -cne 'class-archive-owner-postgres-capture-counts-v1' -or [string]$syntheticCapture.format -cne 'class-archive-synthetic-capture-counts-v1') {
    Fail 'capture_count_format_invalid'
}
if ([string]$sanitization.format -cne 'class-archive-runtime-sanitization-v2' -or
    [bool]$sanitization.runtime_secrets_included -or
    [int]$sanitization.owner_mariadb_sessions -ne 0 -or [int]$sanitization.owner_mariadb_auth_keys -ne 0 -or
    [int]$sanitization.synthetic_mariadb_sessions -ne 0 -or [int]$sanitization.synthetic_mariadb_auth_keys -ne 0 -or
    [int]$sanitization.mariadb_activation_keys -ne 0 -or [int]$sanitization.outstanding_identity_tokens -ne 0 -or
    [int]$sanitization.invited_seats -ne 0 -or [int]$sanitization.piwigo_secret_config_candidates -ne 0 -or
    [int]$sanitization.audit_raw_token_candidates -ne 0 -or
    [string]$sanitization.postgres_sessions -cne 'excluded' -or [string]$sanitization.postgres_api_keys -cne 'excluded' -or
    [string]$sanitization.postgres_shared_links -cne 'excluded' -or [string]$sanitization.postgres_stream_sessions -cne 'excluded' -or
    [string]$sanitization.postgres_system_metadata -cne 'excluded_all' -or [string]$sanitization.postgres_user_metadata -cne 'excluded_all') {
    Fail 'runtime_sanitization_evidence_invalid'
}
$ownerInvariants = @(
    ([int]$ownerCapture.schema_version -eq [int]$ownerFixture.class_identity_schema_version),
    ([int]$ownerCapture.source_records -eq [int]$summary.photo_source.count),
    ([int]$ownerCapture.canonical_photos -eq [int]$summary.photo.count),
    ([int]$ownerCapture.piwigo_images -eq [int]$summary.images.count),
    ([int]$ownerCapture.physical_originals -eq [int]$summary.physical_originals.count),
    ([int]$ownerCapture.album_relationships -eq [int]$summary.image_category.count),
    ([int]$ownerCapture.albums -eq [int]$summary.album.count),
    ([int]$ownerCapture.comments_and_replies -eq [int]$ownerMaria.comments_and_replies),
    ([int]$ownerCapture.replies -eq [int]$ownerMaria.replies),
    ([int]$ownerCapture.ai_index_rows -eq [int]$summary.ai_asset_index.count),
    ([int]$ownerCapture.ai_jobs -eq [int]$summary.ai_index_job.count),
    ([int]$ownerCapture.ai_jobs_complete -eq [int]$ownerMaria.ai_jobs_complete),
    ([int]$ownerCapture.ai_jobs_open -eq 0),
    ([int]$ownerCapture.ai_jobs_complete + [int]$ownerCapture.ai_jobs_open -eq [int]$ownerCapture.ai_jobs),
    ([int]$ownerCapture.canonical_photos -eq [int]$ownerCapture.piwigo_images),
    ([int]$ownerCapture.canonical_photos -eq [int]$ownerCapture.physical_originals),
    ([int]$ownerCapture.canonical_photos -eq [int]$ownerCapture.ai_index_rows),
    ([int]$ownerPostgresCapture.assets -eq [int]$ownerPostgres.assets),
    ([int]$ownerPostgresCapture.faces -eq [int]$ownerPostgres.faces),
    ([int]$ownerPostgresCapture.raw_people -eq [int]$ownerPostgres.raw_people),
    ([int]$ownerPostgresCapture.search_indexed -eq [int]$ownerPostgres.search_indexed),
    ([int]$ownerPostgresCapture.assets -eq [int]$ownerCapture.canonical_photos),
    ([int]$ownerPostgresCapture.search_indexed -eq [int]$ownerCapture.canonical_photos),
    ([int]$ownerPostgres.exported_sessions -eq 0),
    ([int]$ownerPostgres.exported_api_keys -eq 0),
    ([int]$ownerPostgres.exported_shared_links -eq 0)
)
if ($ownerInvariants -contains $false) { Fail 'owner_capture_count_invariant_failed' }
if ([int]$ownerCapture.visible_people -lt 0 -or [int]$ownerCapture.visible_people -gt [int]$ownerPostgresCapture.raw_people) { Fail 'visible_people_count_invalid' }
if ([int]$syntheticCapture.schema_version -ne [int]$syntheticFixture.class_identity_schema_version -or
    [int]$syntheticCapture.images -ne [int]$syntheticFixture.summary.images.count -or
    [int]$syntheticCapture.physical_originals -ne [int]$syntheticFixture.summary.physical_originals.count -or
    [int]$syntheticCapture.multi_album_images -ne [int]$syntheticFixture.summary.multi_album_images) {
    Fail 'synthetic_capture_count_invariant_failed'
}
if ([int]$sourceInventory.total_files -ne $sourceFiles.Count -or [int64]$sourceInventory.total_bytes -ne [int64](($sourceFiles | Measure-Object -Property size -Sum).Sum) -or
    [int]$sourceInventoryAfter.total_files -ne $sourceFiles.Count -or [int64]$sourceInventoryAfter.total_bytes -ne [int64]$sourceInventory.total_bytes) {
    Fail 'source_inventory_count_invariant_failed'
}

$manifest = [ordered]@{
    format = 'class-archive-mac-private-handoff-v2'
    version = 2
    package_id = Split-Path $resolvedRoot -Leaf
    created_at = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    git = [ordered]@{ branch=$branch; head=$head; public_remote_base=$remoteBase }
    transport = [ordered]@{
        archive_format='POSIX_TAR_ZSTD'
        single_file=$true
        encryption='NONE'
        confidentiality_protection='NONE'
        storage_scope='LOCAL_PHYSICAL_MEDIA_ONLY'
        public_distribution_allowed=$false
        cloud_transfer_allowed=$false
        outer_sha256_verification='OUT_OF_BAND_REQUIRED'
    }
    privacy = [ordered]@{
        classification='PRIVATE_LOCAL_ARTIFACT'
        contains_real_media=$true
        contains_database_dumps=$true
        contains_password_hashes=$true
        contains_face_embeddings=$true
        contains_real_filenames=$true
        contains_identity_and_account_metadata=$true
        contains_comments_and_audit=$true
        contains_biometric_and_search_vectors=$true
        contains_plaintext_runtime_secrets=$false
        git_safe=$false
    }
    integrity = [ordered]@{ algorithm='SHA-256'; authenticated=$false; external_archive_sha256_required=$true }
    acknowledgement = [ordered]@{ unencrypted_private_transfer=$true; physical_custody_required=$true }
    evidence = [ordered]@{
        capture_completed=$true
        package_verification='EXTERNAL_VERIFIER_REQUIRED'
        private_source_archive_verification='PASS'
        source_integrity_before_after='PASS'
        runtime_sanitization='PASS'
        git_head_public_boundary='PASS'
        git_outgoing_public_boundary='PASS'
        mac_runtime_tested=$false
        windows_runtime_capture='PASS'
    }
    expected_restore_counts = [ordered]@{
        owner = [ordered]@{
            schema_version=[int]$ownerCapture.schema_version
            source_records=[int]$ownerCapture.source_records
            canonical_photos=[int]$ownerCapture.canonical_photos
            piwigo_images=[int]$ownerCapture.piwigo_images
            physical_originals=[int]$ownerCapture.physical_originals
            album_relationships=[int]$ownerCapture.album_relationships
            albums=[int]$ownerCapture.albums
            comments_and_replies=[int]$ownerCapture.comments_and_replies
            replies=[int]$ownerCapture.replies
            visible_people=[int]$ownerCapture.visible_people
            ai_index_rows=[int]$ownerCapture.ai_index_rows
            ai_jobs=[int]$ownerCapture.ai_jobs
            ai_jobs_complete=[int]$ownerCapture.ai_jobs_complete
            ai_jobs_open=[int]$ownerCapture.ai_jobs_open
            immich_assets=[int]$ownerPostgresCapture.assets
            immich_faces=[int]$ownerPostgresCapture.faces
            immich_raw_people=[int]$ownerPostgresCapture.raw_people
            immich_search_indexed=[int]$ownerPostgresCapture.search_indexed
        }
        synthetic = [ordered]@{
            schema_version=[int]$syntheticCapture.schema_version
            images=[int]$syntheticCapture.images
            physical_originals=[int]$syntheticCapture.physical_originals
            multi_album_images=[int]$syntheticCapture.multi_album_images
        }
        private_sources = [ordered]@{
            files=[int]$sourceFiles.Count
            bytes=[int64]$sourceInventory.total_bytes
            static_images=$sourceImageCount
            videos=$sourceVideoCount
            other_files=$sourceOtherCount
            archive_role='READ_ONLY_PROVENANCE_DO_NOT_AUTO_IMPORT'
        }
    }
    exclusions = @(
        [ordered]@{component='RUNTIME_SECRETS_AND_SESSIONS';reason='Regenerate on Mac; reusable session/token/API secrets are prohibited in plaintext handoff.';rebuild='Generate fresh env values; the sanitized dump already revokes outstanding claim/invite/reset tokens and clears invited seats.'},
        [ordered]@{component='IMMICH_SYSTEM_AND_USER_METADATA';reason='All rows are excluded because these tables may carry OAuth, SMTP, license, or other deployment secrets.';rebuild='Reapply only reviewed non-secret settings on the Mac after fresh runtime secret bootstrap.'},
        [ordered]@{component='ML_MODEL_BINARIES';reason='InsightFace redistribution prohibited and OpenCLIP weight redistribution unresolved.';rebuild='Fetch only from manifest-pinned official sources, verify SHA-256, then validate offline cold start.'},
        [ordered]@{component='DOCKER_RAW_VOLUMES_OR_VHDX';reason='Host-specific and unsafe as a portable database backup.';rebuild='Restore logical DB dumps and POSIX tar payloads into fresh named volumes.'},
        [ordered]@{component='BROWSER_AND_TRANSIENT_CACHE';reason='Rebuildable and may contain session state.';rebuild='Regenerated by the target runtime.'}
    )
    limitations = [ordered]@{
        encryption='NONE_OWNER_DECLINED_PASSPHRASE'
        anonymous_pseudonym_continuity='NOT_GUARANTEED_WITHOUT_OUT_OF_BAND_ORIGINAL_SECRET'
        apple_silicon='BLOCKED_PENDING_ISOLATED_AMD64_OR_NATIVE_IMAGE_RUNTIME_PROOF'
        mac_runtime_tested=$false
        production_ready=$false
    }
    payloads = $payloads
}

$utf8 = [Text.UTF8Encoding]::new($false)
Copy-Item -LiteralPath $handoffDocSource -Destination $handoffDocTarget
$manifestPath = Join-Path $resolvedRoot 'manifest.json'
$manifestPartial = $manifestPath + '.partial'
[IO.File]::WriteAllText($manifestPartial,(($manifest | ConvertTo-Json -Depth 12) + "`n"),$utf8)
Move-Item -LiteralPath $manifestPartial -Destination $manifestPath

$checksumFiles = @(Get-ChildItem -LiteralPath $resolvedRoot -Recurse -File -Force |
    Where-Object { $_.Name -notin @('checksums.sha256','COMPLETE') } |
    Sort-Object FullName)
$checksumLines = foreach ($file in $checksumFiles) {
    $relative = [IO.Path]::GetRelativePath($resolvedRoot,$file.FullName).Replace('\','/')
    $sha = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    "$sha  $relative"
}
$checksumsPath = Join-Path $resolvedRoot 'checksums.sha256'
$checksumsPartial = $checksumsPath + '.partial'
[IO.File]::WriteAllText($checksumsPartial,(($checksumLines -join "`n") + "`n"),$utf8)
Move-Item -LiteralPath $checksumsPartial -Destination $checksumsPath
$completePath = Join-Path $resolvedRoot 'COMPLETE'
$completePartial = $completePath + '.partial'
[IO.File]::WriteAllText($completePartial,"CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V2`n",$utf8)
Move-Item -LiteralPath $completePartial -Destination $completePath

$packageVerifier = Convert-ToWslPath (Join-Path $projectRoot 'infra\mac-handoff\verify-handoff-package.sh')
$packageVerifyOutput = @(& wsl.exe -d Ubuntu -- bash $packageVerifier (Convert-ToWslPath $resolvedRoot) 2>&1)
if ($LASTEXITCODE -ne 0 -or 'HANDOFF_PACKAGE_VERIFY=PASS' -notin $packageVerifyOutput) {
    Remove-Item -LiteralPath $completePath -Force -ErrorAction SilentlyContinue
    Fail 'independent_package_verification_failed'
}
$packageVerifyOutput | Write-Output

Write-Output "HANDOFF_PAYLOAD_FILES=$($payloads.Count)"
Write-Output "HANDOFF_EXPECTED_OWNER_CANONICAL=$($ownerCapture.canonical_photos)"
Write-Output "HANDOFF_EXPECTED_SYNTHETIC=$($syntheticCapture.images)_$($syntheticCapture.physical_originals)_$($syntheticCapture.multi_album_images)"
Write-Output 'LOCAL_HANDOFF_FINALIZE=PASS'
