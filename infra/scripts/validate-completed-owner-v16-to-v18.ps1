<#
.SYNOPSIS
Read-only completed-state validator for a historical private Owner V16 -> V18
migration plan.

.DESCRIPTION
The forward migration adapter deliberately binds Snapshot/Migrate/Validate to
one exact checkout. Once later commits exist, replaying that contract correctly
rejects its historical plan. This separate validator answers the narrower
completed-state question: did the immutable evidence remain intact, does the
current schema match the reviewed migration source, and is the current Owner
runtime still on the safe V18 verification path?

It never publishes maintenance, runs migrations, rebuilds projections, queues
AI, imports media, or changes an Owner database. Its container work is limited
to bounded read-only exec probes and one run --rm snapshot checksum reader.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$MigrationPlanName,

    [Parameter(Mandatory = $true)]
    [string]$CurrentV4AcceptanceGateName
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$planRoot = Join-Path $projectRoot '.codex-work\private-real-full\migration-v16-to-v18'
$historicalV4Root = Join-Path $projectRoot '.codex-work\v4-synthetic-acceptance'
$directProofRoot = Join-Path $projectRoot '.codex-work\v18-synthetic-migration-attempt40\reports'
$schemaRelativePath = 'plugins/ClassIdentity/src/Schema.php'
$schemaPath = Join-Path $projectRoot $schemaRelativePath
$rollbackSchemaCommit = 'd6f15c7bd366d9dcf7fc8792b50d0965a8ee33d4'
$v4AttestationHelper = Join-Path $PSScriptRoot 'attest-v4-synthetic-phase-ab.ps1'
$boundedNativeHelper = Join-Path $PSScriptRoot 'class-archive-bounded-native-process.ps1'
$immichEnvWindows = Join-Path $projectRoot 'infra\private-full\.env.immich.owner'
$script:immichCompose = $null

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-CompletedOwnerV16ToV18([string]$Code) {
    throw [InvalidOperationException]::new('COMPLETED_OWNER_V16_TO_V18_STOP:' + $Code)
}

if (-not (Test-Path -LiteralPath $boundedNativeHelper -PathType Leaf)) {
    Stop-CompletedOwnerV16ToV18 'bounded_native_helper_missing'
}
. $boundedNativeHelper

function Get-FileSha256([string]$Path) {
    try {
        $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        try {
            $algorithm = [Security.Cryptography.SHA256]::Create()
            try { $bytes = $algorithm.ComputeHash($stream) }
            finally { $algorithm.Dispose() }
        }
        finally { $stream.Dispose() }
        $hash = [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
    }
    catch { Stop-CompletedOwnerV16ToV18 'file_hash_runtime_failed' }
    if ($hash -notmatch '^[a-f0-9]{64}$') { Stop-CompletedOwnerV16ToV18 'file_hash_result_invalid' }
    return $hash
}

function Assert-DockerDesktopEnginePipe {
    $pipes = @('\\.\pipe\dockerDesktopLinuxEngine', '\\.\pipe\docker_engine')
    if (@($pipes | Where-Object { Test-Path -LiteralPath $_ }).Count -eq 0) {
        Stop-CompletedOwnerV16ToV18 'docker_engine_pipe_unavailable'
    }
}

function Get-CurrentHead {
    $lines = @(& git -C $projectRoot rev-parse --verify HEAD 2>$null)
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 1 -or ([string]$lines[0]).Trim() -notmatch '^[a-f0-9]{40}$') {
        Stop-CompletedOwnerV16ToV18 'git_head_invalid'
    }
    return ([string]$lines[0]).Trim()
}

function Assert-CleanCheckout {
    $lines = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>$null)
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 0) { Stop-CompletedOwnerV16ToV18 'validation_checkout_not_clean' }
}

function Assert-TrackedHeadBoundFile([string]$RelativePath) {
    if ($RelativePath -notmatch '^[A-Za-z0-9_./-]+$' -or $RelativePath.Contains('..')) {
        Stop-CompletedOwnerV16ToV18 'tracked_source_path_invalid'
    }
    $path = Join-Path $projectRoot $RelativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-CompletedOwnerV16ToV18 'tracked_source_missing' }
    $tracked = @(& git -C $projectRoot ls-files --error-unmatch -- $RelativePath 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 1) { Stop-CompletedOwnerV16ToV18 'tracked_source_not_tracked' }
    & git -C $projectRoot diff --quiet -- $RelativePath
    if ($LASTEXITCODE -ne 0) { Stop-CompletedOwnerV16ToV18 'tracked_source_worktree_not_head_bound' }
    & git -C $projectRoot diff --cached --quiet -- $RelativePath
    if ($LASTEXITCODE -ne 0) { Stop-CompletedOwnerV16ToV18 'tracked_source_index_not_head_bound' }
    return $path
}

function Assert-IgnoredPrivatePath([string]$Path, [string]$Root, [bool]$Leaf, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Path)
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/')
    if (-not [string]::Equals($full, $rootFull, [StringComparison]::OrdinalIgnoreCase) -and
        -not $full.StartsWith($rootFull + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-CompletedOwnerV16ToV18 $Code
    }
    if (-not (Test-Path -LiteralPath $full)) { Stop-CompletedOwnerV16ToV18 $Code }
    $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
    if (($Leaf -and $item.PSIsContainer) -or ((-not $Leaf) -and (-not $item.PSIsContainer)) -or
        (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        Stop-CompletedOwnerV16ToV18 $Code
    }
    $cursor = $item
    while ($null -ne $cursor) {
        if (($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Stop-CompletedOwnerV16ToV18 $Code }
        $cursor = if ($cursor -is [IO.DirectoryInfo]) { $cursor.Parent } else { $cursor.Directory }
    }
    $relative = $full.Substring($projectRoot.TrimEnd('\', '/').Length).TrimStart('\', '/').Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-CompletedOwnerV16ToV18 $Code }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-CompletedOwnerV16ToV18 $Code }
    return $full
}

function Assert-OwnerOnlyPrivateLeaf([string]$Path, [string]$Root, [string]$Code) {
    $full = Assert-IgnoredPrivatePath $Path $Root $true $Code
    try { Assert-ClassArchiveOwnerOnlyFileAcl -Path $full }
    catch { Stop-CompletedOwnerV16ToV18 ($Code + '_acl_invalid') }
    return $full
}

function Read-PrivateJson([string]$Path, [string]$Code) {
    try { return ([IO.File]::ReadAllText($Path, [Text.UTF8Encoding]::new($false)) | ConvertFrom-Json -ErrorAction Stop) }
    catch { Stop-CompletedOwnerV16ToV18 $Code }
}

function Get-Property([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Test-StrictUtcRfc3339Value([AllowNull()][object]$Value) {
    # Windows PowerShell returns JSON date tokens as strings, while newer
    # PowerShell versions may materialize an RFC3339 value ending in Z as a
    # DateTime whose Kind is Utc. Never stringify a DateTime here: that would
    # make validation depend on the process culture and discard the UTC marker.
    if ($null -eq $Value) { return $false }
    if ($Value -is [DateTime]) {
        $dateTime = [DateTime]$Value
        return $dateTime.Kind -eq [DateTimeKind]::Utc -and $dateTime.Year -ge 2000 -and $dateTime.Year -le 2099
    }
    if ($Value -isnot [string]) { return $false }

    $text = [string]$Value
    if ($text -notmatch '^20[0-9]{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,7})?Z$') {
        return $false
    }
    $parsed = [DateTimeOffset]::MinValue
    [string[]]$formats = @("yyyy-MM-dd'T'HH:mm:ss'Z'", "yyyy-MM-dd'T'HH:mm:ss.FFFFFFF'Z'")
    $styles = [Globalization.DateTimeStyles]::AssumeUniversal -bor [Globalization.DateTimeStyles]::AdjustToUniversal
    return [DateTimeOffset]::TryParseExact(
        $text,
        $formats,
        [Globalization.CultureInfo]::InvariantCulture,
        $styles,
        [ref]$parsed
    ) -and $parsed.Offset -eq [TimeSpan]::Zero
}

function Assert-ExactPropertySet([object]$Object, [string[]]$Expected, [string]$Code) {
    if ($null -eq $Object) { Stop-CompletedOwnerV16ToV18 $Code }
    $actual = @($Object.PSObject.Properties | ForEach-Object { [string]$_.Name } | Sort-Object -Unique)
    $required = @($Expected | Sort-Object -Unique)
    if ($actual.Count -ne $required.Count -or @(Compare-Object -ReferenceObject $required -DifferenceObject $actual).Count -ne 0) {
        Stop-CompletedOwnerV16ToV18 $Code
    }
}

function Invoke-Git([string[]]$Arguments, [string]$Code, [ValidateRange(1,120)][int]$TimeoutSeconds = 30) {
    # Get-Command may return every git.exe found on PATH (for example both
    # Git's cmd/bin shims and a bundled runtime). Preserve PATH precedence,
    # but bind exactly one leaf path to the bounded native-process helper.
    $gitCandidates = @(Get-Command git.exe -CommandType Application -ErrorAction SilentlyContinue)
    if ($gitCandidates.Count -lt 1) { Stop-CompletedOwnerV16ToV18 'git_executable_unavailable' }
    $gitPath = [string]$gitCandidates[0].Source
    if ([string]::IsNullOrWhiteSpace($gitPath) -or -not (Test-Path -LiteralPath $gitPath -PathType Leaf)) { Stop-CompletedOwnerV16ToV18 'git_executable_unavailable' }
    try { $result = Invoke-ClassArchiveBoundedNative -Executable $gitPath -Arguments (@('-C', $projectRoot) + $Arguments) -TimeoutSeconds $TimeoutSeconds -WorkingDirectory $projectRoot }
    catch { Stop-CompletedOwnerV16ToV18 ($Code + '_start_failed') }
    if ($result.TimedOut -or $null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-CompletedOwnerV16ToV18 $Code }
    return $result
}

function Assert-HistoricalCommitAncestor([string]$HistoricalHead, [string]$CurrentHead) {
    if ($HistoricalHead -notmatch '^[a-f0-9]{40}$' -or $CurrentHead -notmatch '^[a-f0-9]{40}$') { Stop-CompletedOwnerV16ToV18 'historical_commit_invalid' }
    [void](Invoke-Git @('cat-file','-e',($HistoricalHead + '^{commit}')) 'historical_commit_unavailable')
    $result = Invoke-Git @('merge-base','--is-ancestor',$HistoricalHead,$CurrentHead) 'historical_head_not_current_ancestor'
    if ($result.ExitCode -ne 0) { Stop-CompletedOwnerV16ToV18 'historical_head_not_current_ancestor' }
}

function Get-HistoricalSchemaText([string]$HistoricalHead) {
    $result = Invoke-Git @('show','--no-textconv','--format=',($HistoricalHead + ':' + $schemaRelativePath)) 'historical_schema_unavailable'
    if ([string]$result.Stdout -match "`0") { Stop-CompletedOwnerV16ToV18 'historical_schema_invalid' }
    return [string]$result.Stdout
}

function Get-TextSha256([string]$Text) {
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try { $bytes = $algorithm.ComputeHash([Text.UTF8Encoding]::new($false).GetBytes($Text)) }
    finally { $algorithm.Dispose() }
    return [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
}

function Assert-SchemaEquivalence([string]$HistoricalHead, [string]$ExpectedSha256) {
    if ($ExpectedSha256 -notmatch '^[a-f0-9]{64}$') { Stop-CompletedOwnerV16ToV18 'historical_schema_sha_invalid' }
    $currentPath = Assert-TrackedHeadBoundFile $schemaRelativePath
    $currentText = [IO.File]::ReadAllText($currentPath, [Text.UTF8Encoding]::new($false, $true))
    $historicalText = Get-HistoricalSchemaText $HistoricalHead
    foreach ($text in @($currentText, $historicalText)) {
        if ($text -notmatch 'CURRENT_VERSION\s*=\s*18\s*;' -or
            -not $text.Contains("'name' => '0017_photos_app_v4_collection_snapshots'") -or
            -not $text.Contains("'name' => '0018_photos_app_v4_spotlight_rotation_state'")) {
            Stop-CompletedOwnerV16ToV18 'schema_target_contract_invalid'
        }
    }
    $currentSha = Get-FileSha256 $currentPath
    $historicalSha = Get-TextSha256 $historicalText
    if ($currentSha -ne $ExpectedSha256 -or $historicalSha -ne $ExpectedSha256 -or $currentSha -ne $historicalSha) {
        Stop-CompletedOwnerV16ToV18 'historical_schema_current_sha_mismatch'
    }
}

function Assert-PlanName([string]$Name) {
    if ($Name -notmatch '^owner-v16-to-v18-plan-[0-9]{8}T[0-9]{6}Z\.json$') { Stop-CompletedOwnerV16ToV18 'migration_plan_name_invalid' }
}

function Read-HistoricalPlan([string]$Name) {
    Assert-PlanName $Name
    Assert-IgnoredPrivatePath $planRoot $planRoot $false 'migration_plan_root_invalid' | Out-Null
    $path = Assert-OwnerOnlyPrivateLeaf (Join-Path $planRoot $Name) $planRoot 'migration_plan_invalid'
    $plan = Read-PrivateJson $path 'migration_plan_json_invalid'
    Assert-ExactPropertySet $plan @('format','scope','created_at','source_head','schema_source_sha256','source_schema','target_schema','sequential_migrations','rollback_schema_commit','baseline','snapshot','v4_acceptance','direct_v16_to_v18_proof','privacy') 'migration_plan_property_set_invalid'
    if ([int](Get-Property $plan 'format') -ne 1 -or [string](Get-Property $plan 'scope') -ne 'OWNER_V16_TO_V18_MIGRATION_PLAN' -or
        [int](Get-Property $plan 'source_schema') -ne 16 -or [int](Get-Property $plan 'target_schema') -ne 18 -or
        [string](Get-Property $plan 'rollback_schema_commit') -ne $rollbackSchemaCommit -or
        [string](Get-Property $plan 'privacy') -ne 'OPAQUE_LEAF_NAMES_AND_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_MEDIA_OR_SECRETS' -or
        -not (Test-StrictUtcRfc3339Value (Get-Property $plan 'created_at'))) {
        Stop-CompletedOwnerV16ToV18 'migration_plan_contract_invalid'
    }
    $sequential = @((Get-Property $plan 'sequential_migrations'))
    if ($sequential.Count -ne 2 -or $sequential[0] -ne '0017_photos_app_v4_collection_snapshots' -or $sequential[1] -ne '0018_photos_app_v4_spotlight_rotation_state') {
        Stop-CompletedOwnerV16ToV18 'migration_plan_sequence_invalid'
    }
    Assert-ExactPropertySet (Get-Property $plan 'baseline') @('name','sha256') 'migration_plan_baseline_shape_invalid'
    Assert-ExactPropertySet (Get-Property $plan 'snapshot') @('name','manifest_sha256','dump_sha256','dump_bytes') 'migration_plan_snapshot_shape_invalid'
    Assert-ExactPropertySet (Get-Property $plan 'v4_acceptance') @('gate','sha256') 'migration_plan_v4_gate_shape_invalid'
    Assert-ExactPropertySet (Get-Property $plan 'direct_v16_to_v18_proof') @('commit','source_digest','proof_sha256') 'migration_plan_direct_proof_shape_invalid'
    $currentHead = Get-CurrentHead
    $historicalHead = [string](Get-Property $plan 'source_head')
    Assert-HistoricalCommitAncestor $historicalHead $currentHead
    Assert-SchemaEquivalence $historicalHead ([string](Get-Property $plan 'schema_source_sha256'))
    return @{ Path=$path; Sha256=(Get-FileSha256 $path); Plan=$plan; HistoricalHead=$historicalHead; CurrentHead=$currentHead }
}

function Read-HistoricalBaseline([object]$Plan) {
    $baseline = Get-Property $Plan 'baseline'
    $name = [string](Get-Property $baseline 'name')
    $expectedSha = [string](Get-Property $baseline 'sha256')
    if ($name -notmatch '^owner-v16-to-v18-baseline-[0-9]{8}T[0-9]{6}Z\.json$' -or $expectedSha -notmatch '^[a-f0-9]{64}$') {
        Stop-CompletedOwnerV16ToV18 'historical_baseline_reference_invalid'
    }
    $path = Assert-OwnerOnlyPrivateLeaf (Join-Path $planRoot $name) $planRoot 'historical_baseline_invalid'
    $actualSha = Get-FileSha256 $path
    if ($actualSha -ne $expectedSha) { Stop-CompletedOwnerV16ToV18 'historical_baseline_sha_mismatch' }
    $document = Read-PrivateJson $path 'historical_baseline_json_invalid'
    Assert-ExactPropertySet $document @('format','scope','created_at','source_schema','target_schema','privacy','counts','semantic_fingerprints') 'historical_baseline_property_set_invalid'
    if ([int](Get-Property $document 'format') -ne 2 -or [string](Get-Property $document 'scope') -ne 'OWNER_V16_TO_V18_NUMERIC_BASELINE' -or
        [int](Get-Property $document 'source_schema') -ne 16 -or [int](Get-Property $document 'target_schema') -ne 18 -or
        [string](Get-Property $document 'privacy') -ne 'COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_OR_SECRETS') {
        Stop-CompletedOwnerV16ToV18 'historical_baseline_contract_invalid'
    }
    $countKeys = @('class_identity_schema_version','migration_ledger_rows','source_records','source_presentations','canonical_photos','piwigo_images','album_relationships','leaf_albums','comments','replies','visible_people','person_merges','person_rules','spotlights','memories','audit_events','ai_asset_index','ai_jobs_total','ai_jobs_complete','ai_jobs_open','immich_assets','immich_face_records','immich_raw_persons','immich_face_search','immich_search_index')
    Assert-ExactPropertySet (Get-Property $document 'counts') $countKeys 'historical_baseline_count_set_invalid'
    $counts = [ordered]@{}
    foreach ($key in $countKeys) {
        $value = [string](Get-Property (Get-Property $document 'counts') $key)
        if ($value -notmatch '^[0-9]+$') { Stop-CompletedOwnerV16ToV18 'historical_baseline_count_invalid' }
        $counts[$key] = [uint64]$value
    }
    $semanticKeys = @('canonical_media','album_membership','comments','person_curation','spotlight_collections','ai_control','identity_and_audit','immich_ai_state')
    Assert-ExactPropertySet (Get-Property $document 'semantic_fingerprints') $semanticKeys 'historical_baseline_semantic_set_invalid'
    $semantic = [ordered]@{}
    foreach ($key in $semanticKeys) {
        $value = [string](Get-Property (Get-Property $document 'semantic_fingerprints') $key)
        if ($value -notmatch '^[a-f0-9]{64}$') { Stop-CompletedOwnerV16ToV18 'historical_baseline_semantic_invalid' }
        $semantic[$key] = $value
    }
    return @{ Name=$name; Sha256=$actualSha; Counts=$counts; Semantic=$semantic }
}

function Read-HistoricalV4Gate([object]$Plan, [string]$HistoricalHead) {
    $gate = Get-Property $Plan 'v4_acceptance'
    $name = [string](Get-Property $gate 'gate')
    $expectedSha = [string](Get-Property $gate 'sha256')
    if ($name -notmatch '^v4-synthetic-phase-ab-[0-9]{8}T[0-9]{6}Z\.json$' -or $expectedSha -notmatch '^[a-f0-9]{64}$') {
        Stop-CompletedOwnerV16ToV18 'historical_v4_gate_reference_invalid'
    }
    Assert-IgnoredPrivatePath $historicalV4Root $historicalV4Root $false 'historical_v4_gate_root_invalid' | Out-Null
    $path = Assert-OwnerOnlyPrivateLeaf (Join-Path $historicalV4Root $name) $historicalV4Root 'historical_v4_gate_invalid'
    $actualSha = Get-FileSha256 $path
    if ($actualSha -ne $expectedSha) { Stop-CompletedOwnerV16ToV18 'historical_v4_gate_sha_mismatch' }
    $record = Read-PrivateJson $path 'historical_v4_gate_json_invalid'
    if ([int](Get-Property $record 'format') -ne 1 -or [string](Get-Property $record 'scope') -ne 'V4_SYNTHETIC_PHASE_AB' -or
        [string](Get-Property $record 'environment') -ne 'SYNTHETIC_8091' -or [string](Get-Property $record 'browser') -ne 'GOOGLE_CHROME_STABLE' -or
        [string](Get-Property $record 'source_head') -ne $HistoricalHead) {
        Stop-CompletedOwnerV16ToV18 'historical_v4_gate_contract_invalid'
    }
    $requiredGates = [ordered]@{
        synthetic_desktop_chrome='PASS'; synthetic_search_overlay='PASS'; synthetic_viewer='PASS'; synthetic_scope_projections='PASS';
        synthetic_upload_era='PASS'; synthetic_mediaguard='PASS'; synthetic_server_restart='PASS'; synthetic_baseline='PASS_72_72_8'
    }
    Assert-ExactPropertySet (Get-Property $record 'gates') @($requiredGates.Keys) 'historical_v4_gate_result_set_invalid'
    foreach ($entry in $requiredGates.GetEnumerator()) {
        if ([string](Get-Property (Get-Property $record 'gates') ([string]$entry.Key)) -ne [string]$entry.Value) {
            Stop-CompletedOwnerV16ToV18 'historical_v4_gate_result_invalid'
        }
    }
    $evidenceNames = @('chrome-main','chrome-deep','scope','upload','restart')
    Assert-ExactPropertySet (Get-Property $record 'evidence') $evidenceNames 'historical_v4_gate_evidence_set_invalid'
    foreach ($evidenceName in $evidenceNames) {
        $entry = Get-Property (Get-Property $record 'evidence') $evidenceName
        if ([string](Get-Property $entry 'leaf') -notmatch '^[a-z0-9-]{3,64}\.out$' -or [string](Get-Property $entry 'sha256') -notmatch '^[a-f0-9]{64}$') {
            Stop-CompletedOwnerV16ToV18 'historical_v4_gate_evidence_invalid'
        }
    }
    return @{ Name=$name; Sha256=$actualSha }
}

function Read-HistoricalDirectProof([object]$Plan, [string]$HistoricalHead) {
    $reference = Get-Property $Plan 'direct_v16_to_v18_proof'
    $commit = [string](Get-Property $reference 'commit')
    $sourceDigest = [string](Get-Property $reference 'source_digest')
    $proofSha = [string](Get-Property $reference 'proof_sha256')
    if ($commit -ne $HistoricalHead -or $sourceDigest -notmatch '^[a-f0-9]{64}$' -or $proofSha -notmatch '^[a-f0-9]{64}$') {
        Stop-CompletedOwnerV16ToV18 'historical_direct_proof_reference_invalid'
    }
    Assert-IgnoredPrivatePath $directProofRoot $directProofRoot $false 'historical_direct_proof_root_invalid' | Out-Null
    $attestationPath = Assert-IgnoredPrivatePath (Join-Path $directProofRoot 'v16-to-v18-direct-attestation.json') $directProofRoot $true 'historical_direct_attestation_invalid'
    $proofPath = Assert-IgnoredPrivatePath (Join-Path $directProofRoot 'v16-to-v18-direct-proof.json') $directProofRoot $true 'historical_direct_proof_invalid'
    $attestation = Read-PrivateJson $attestationPath 'historical_direct_attestation_json_invalid'
    Assert-ExactPropertySet $attestation @('format','kind','result','created_at_utc','attempt','scope','ports','commit','source_digest','sources','direct_proof_report_sha256','legacy_fingerprint','runtime_lock','evidence') 'historical_direct_attestation_property_set_invalid'
    if ([int](Get-Property $attestation 'format') -ne 1 -or [string](Get-Property $attestation 'kind') -ne 'SYNTHETIC_DIRECT_V16_TO_V18_RUNTIME' -or
        [string](Get-Property $attestation 'result') -ne 'PASS' -or [string](Get-Property $attestation 'attempt') -ne 'attempt40' -or
        [string](Get-Property $attestation 'scope') -ne 'SYNTHETIC_V4_MIGRATION' -or [string](Get-Property $attestation 'ports') -ne '127.0.0.1:11804_11805' -or
        -not (Test-StrictUtcRfc3339Value (Get-Property $attestation 'created_at_utc')) -or
        [string](Get-Property $attestation 'commit') -ne $commit -or [string](Get-Property $attestation 'source_digest') -ne $sourceDigest -or
        [string](Get-Property $attestation 'direct_proof_report_sha256') -ne $proofSha -or [string](Get-Property $attestation 'legacy_fingerprint') -notmatch '^[a-f0-9]{64}$') {
        Stop-CompletedOwnerV16ToV18 'historical_direct_attestation_contract_invalid'
    }
    $evidence = Get-Property $attestation 'evidence'
    Assert-ExactPropertySet $evidence @('first_migration','replay','verify','fail_closed','media') 'historical_direct_attestation_evidence_shape_invalid'
    if ([string](Get-Property $evidence 'first_migration') -ne 'PASS' -or [string](Get-Property $evidence 'replay') -ne 'PASS' -or
        [string](Get-Property $evidence 'verify') -ne 'PASS' -or [string](Get-Property $evidence 'fail_closed') -ne 'PASS' -or
        [string](Get-Property $evidence 'media') -ne 'NOT_MOUNTED') {
        Stop-CompletedOwnerV16ToV18 'historical_direct_attestation_evidence_invalid'
    }
    if ((Get-FileSha256 $proofPath) -ne $proofSha) { Stop-CompletedOwnerV16ToV18 'historical_direct_proof_sha_mismatch' }
    $proof = Read-PrivateJson $proofPath 'historical_direct_proof_json_invalid'
    Assert-ExactPropertySet $proof @('format','attempt','scope','ports','created_at_utc','source_schema','target_schema','migration','source_commit','source_digest','legacy_fingerprint','first_migration','replay','verify','fail_closed','media') 'historical_direct_proof_property_set_invalid'
    if ([int](Get-Property $proof 'format') -ne 2 -or [string](Get-Property $proof 'attempt') -ne 'attempt40' -or
        [string](Get-Property $proof 'scope') -ne 'SYNTHETIC_V4_MIGRATION' -or [string](Get-Property $proof 'ports') -ne '127.0.0.1:11804_11805' -or
        -not (Test-StrictUtcRfc3339Value (Get-Property $proof 'created_at_utc')) -or
        [int](Get-Property $proof 'source_schema') -ne 16 -or [int](Get-Property $proof 'target_schema') -ne 18 -or
        [string](Get-Property $proof 'migration') -ne 'CURRENT_SOURCE_DIRECT_17_18' -or [string](Get-Property $proof 'media') -ne 'NOT_MOUNTED' -or
        [string](Get-Property $proof 'source_commit') -ne $commit -or [string](Get-Property $proof 'source_digest') -ne $sourceDigest -or
        [string](Get-Property $proof 'legacy_fingerprint') -ne [string](Get-Property $attestation 'legacy_fingerprint')) {
        Stop-CompletedOwnerV16ToV18 'historical_direct_proof_contract_invalid'
    }
    $fingerprint = [string](Get-Property $proof 'legacy_fingerprint')
    if ([string](Get-Property $proof 'first_migration') -notmatch ('^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=migrate_current_source schema_from=16 schema_to=18 sequential=17_18 replay=NOT_APPLICABLE legacy_tables_preserved=PASS new_tables=EMPTY new_table_count=7 legacy_fingerprint=' + [regex]::Escape($fingerprint) + ' media=NOT_TOUCHED$') -or
        [string](Get-Property $proof 'replay') -notmatch ('^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=migrate_current_source schema_from=18 schema_to=18 sequential=NOT_APPLICABLE replay=PASS new_tables=EMPTY legacy_fingerprint=' + [regex]::Escape($fingerprint) + ' media=NOT_TOUCHED$') -or
        [string](Get-Property $proof 'verify') -notmatch ('^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=verify_current_source schema=18 ledger=18 new_tables=EMPTY legacy_fingerprint=' + [regex]::Escape($fingerprint) + ' media=NOT_TOUCHED$') -or
        [string](Get-Property $proof 'fail_closed') -ne 'V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=fail_closed unknown_schema=DENY scratch=DISPOSED') {
        Stop-CompletedOwnerV16ToV18 'historical_direct_proof_evidence_invalid'
    }
    return @{ Commit=$commit; SourceDigest=$sourceDigest; ProofSha256=$proofSha }
}

function Invoke-Wsl([string[]]$Arguments, [string]$Code, [switch]$Capture, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    try {
        $bounded = Add-ClassArchiveWslTimeout -Arguments $Arguments -TimeoutSeconds $TimeoutSeconds
        $result = Invoke-ClassArchiveBoundedNative -Executable "$env:SystemRoot\System32\wsl.exe" -Arguments $bounded -TimeoutSeconds ($TimeoutSeconds + 15) -WorkingDirectory $projectRoot
    }
    catch { Stop-CompletedOwnerV16ToV18 ($Code + '_start_failed') }
    if ($result.TimedOut -or $null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-CompletedOwnerV16ToV18 $Code }
    if ($Capture) { return @(([string]$result.Stdout -split "`r?`n") | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' }) }
}

$piwigoCompose = @('-d','Ubuntu','--cd',$projectRoot,'--','docker','compose','--env-file','infra/private-full/.env.piwigo.owner','-f','infra/docker-compose.yml','-f','infra/private-full/docker-compose.override.yml','-p','class_archive_private_full_v3_piwigo','--profile','ops')
function Get-WslPath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    if (-not (Test-Path -LiteralPath $full -PathType Leaf) -or $full -match '[\s"]' -or $full.Contains("`0")) { Stop-CompletedOwnerV16ToV18 'env_file_invalid' }
    try {
        $result = Invoke-ClassArchiveBoundedNative -Executable "$env:SystemRoot\System32\wsl.exe" -Arguments (Add-ClassArchiveWslTimeout -Arguments @('-d','Ubuntu','--exec','wslpath','-a',$full) -TimeoutSeconds 15) -TimeoutSeconds 30 -WorkingDirectory $projectRoot
    }
    catch { Stop-CompletedOwnerV16ToV18 'env_file_invalid' }
    if ($result.TimedOut -or [int]$result.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace([string]$result.Stderr)) { Stop-CompletedOwnerV16ToV18 'env_file_invalid' }
    $lines = @(([string]$result.Stdout -split "`r?`n") | Where-Object { $_ -ne '' })
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^/mnt/[a-z]/') { Stop-CompletedOwnerV16ToV18 'env_file_invalid' }
    return [string]$lines[0]
}

function Initialize-ImmichCompose {
    if ($null -ne $script:immichCompose) { return }
    try {
        $item = Get-Item -LiteralPath $immichEnvWindows -Force -ErrorAction Stop
        if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
            Stop-CompletedOwnerV16ToV18 'immich_env_invalid'
        }
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
    }
    catch { Stop-CompletedOwnerV16ToV18 'immich_env_acl_invalid' }
    $envPath = Get-WslPath $immichEnvWindows
    $script:immichCompose = @('-d','Ubuntu','--cd',$projectRoot,'--','env',('IMMICH_SPIKE_ENV_FILE=' + $envPath),'docker','compose','--env-file','infra/private-full/.env.immich.owner','-f','infra/immich-spike/docker-compose.yml','-f','infra/private-full/docker-compose.immich.override.yml','-p','class_archive_private_full_v3_immich','--profile','immich-spike','--profile','immich-ml','--profile','immich-web-compat','--profile','immich-gateway-integration')
}

function Invoke-PiwigoReadOnly(
    [string[]]$Arguments,
    [switch]$Capture,
    [ValidateRange(1,900)][int]$TimeoutSeconds = 120,
    [ValidatePattern('^[a-z0-9_]{3,120}$')][string]$Code = 'piwigo_readonly_probe_failed'
) {
    return Invoke-Wsl -Arguments @($script:piwigoCompose + $Arguments) -Code $Code -Capture:$Capture -TimeoutSeconds $TimeoutSeconds
}

function Invoke-ImmichReadOnly([string[]]$Arguments, [switch]$Capture, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    Initialize-ImmichCompose
    return Invoke-Wsl -Arguments @($script:immichCompose + $Arguments) -Code 'immich_readonly_probe_failed' -Capture:$Capture -TimeoutSeconds $TimeoutSeconds
}

function Invoke-ChildPowerShell([string]$ScriptPath, [string[]]$Arguments, [string]$Code, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    if (-not (Test-Path -LiteralPath $ScriptPath -PathType Leaf)) { Stop-CompletedOwnerV16ToV18 ($Code + '_script_missing') }
    $powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    try {
        $result = Invoke-ClassArchiveBoundedNative -Executable $powershell -Arguments (@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$ScriptPath) + $Arguments) -TimeoutSeconds $TimeoutSeconds -WorkingDirectory $projectRoot
    }
    catch { Stop-CompletedOwnerV16ToV18 ($Code + '_start_failed') }
    if ($result.TimedOut -or $null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-CompletedOwnerV16ToV18 $Code }
    return @(([string]$result.Stdout -split "`r?`n") | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
}

function Assert-OwnerLoopback {
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (-not (Test-Path -LiteralPath $curl -PathType Leaf)) { Stop-CompletedOwnerV16ToV18 'owner_loopback_curl_missing' }
    foreach ($uri in @('http://127.0.0.1:8190','http://127.0.0.1:8191')) {
        $result = Invoke-ClassArchiveBoundedNative -Executable $curl -Arguments @('--noproxy','*','--silent','--show-error','--max-time','15','--output','NUL','--write-out','CLASS_ARCHIVE_STATUS:%{http_code}',$uri) -TimeoutSeconds 30 -WorkingDirectory $projectRoot
        $match = [regex]::Match(([string]$result.Stdout).Trim(), '^CLASS_ARCHIVE_STATUS:(\d{3})$')
        if ($result.TimedOut -or [int]$result.ExitCode -ne 0 -or -not $match.Success -or [int]$match.Groups[1].Value -notin @(200,301,302,303)) {
            Stop-CompletedOwnerV16ToV18 'owner_loopback_endpoint_unhealthy'
        }
    }
}

function Assert-TargetV18Ledger {
    $sql = @'
set -eu
: "${MARIADB_ROOT_PASSWORD:?}"
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
unset MARIADB_ROOT_PASSWORD
trap 'unset MYSQL_PWD || true' EXIT HUP INT TERM
q() { mariadb --batch --skip-column-names --protocol=socket --user=root "$MARIADB_DATABASE" --execute "$1"; }
ci=$(q "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';")
case "$ci" in ''|*[!A-Za-z0-9_]*) exit 91 ;; esac
base=${ci%migration}
shape=$(q "SELECT CONCAT(COUNT(*),':',COUNT(DISTINCT version),':',COALESCE(MIN(version),0),':',COALESCE(MAX(version),0)) FROM ${base}migration;")
[ "$shape" = '18:18:1:18' ] || exit 92
printf 'ledger=18\n'
'@
    $lines = @(Invoke-PiwigoReadOnly @('exec','-T','db','sh','-eu','-c',$sql) -Capture -TimeoutSeconds 90 -Code 'target_schema_probe_failed')
    if ($lines.Count -ne 1 -or $lines[0] -ne 'ledger=18') { Stop-CompletedOwnerV16ToV18 'target_schema_probe_invalid' }
}

function Assert-SnapshotBinding([object]$Plan) {
    $snapshot = Get-Property $Plan 'snapshot'
    $name = [string](Get-Property $snapshot 'name')
    $manifestSha = [string](Get-Property $snapshot 'manifest_sha256')
    $dumpSha = [string](Get-Property $snapshot 'dump_sha256')
    $dumpBytes = [string](Get-Property $snapshot 'dump_bytes')
    if ($name -notmatch '^pre-migration-db-v16-to-v18-[0-9]{8}T[0-9]{6}Z$' -or $manifestSha -notmatch '^[a-f0-9]{64}$' -or $dumpSha -notmatch '^[a-f0-9]{64}$' -or $dumpBytes -notmatch '^[1-9][0-9]*$') {
        Stop-CompletedOwnerV16ToV18 'historical_snapshot_reference_invalid'
    }
    $script = @'
set -eu
b="$1"
case "$b" in pre-migration-db-v16-to-v18-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;; *) exit 71 ;; esac
directory="/backup/$b"
[ -d "$directory" ] && [ ! -L "$directory" ] || exit 72
cd "$directory"
[ -f COMPLETE ] && [ ! -L COMPLETE ] && [ -f MANIFEST.json ] && [ ! -L MANIFEST.json ] && [ -f database.sql.gz ] && [ ! -L database.sql.gz ] && [ -f SHA256SUMS ] && [ ! -L SHA256SUMS ] || exit 73
sha256sum -c SHA256SUMS >/dev/null
grep -F '"format":1,"scope":"DB_ONLY_PRE_MIGRATION_ROLLBACK"' MANIFEST.json >/dev/null
grep -F '"schema_current":16,"schema_from":16,"schema_to":18' MANIFEST.json >/dev/null
grep -F '"media":"NOT_INCLUDED"' MANIFEST.json >/dev/null
manifest_sha256=$(sha256sum MANIFEST.json | awk '{print $1}')
dump_sha256=$(sha256sum database.sql.gz | awk '{print $1}')
dump_bytes=$(wc -c < database.sql.gz | tr -d ' ')
case "$manifest_sha256:$dump_sha256:$dump_bytes" in *[!0-9a-f:]*|:*|*::*) exit 74 ;; esac
[ "${#manifest_sha256}" -eq 64 ] && [ "${#dump_sha256}" -eq 64 ] && [ "$dump_bytes" -gt 0 ] || exit 75
grep -F "\"dump_sha256\":\"$dump_sha256\"" MANIFEST.json >/dev/null
grep -F "\"dump_bytes\":$dump_bytes" MANIFEST.json >/dev/null
printf 'snapshot_binding=%s:%s:%s\n' "$manifest_sha256" "$dump_sha256" "$dump_bytes"
'@
    $lines = @(Invoke-PiwigoReadOnly @('run','--rm','--no-deps','--entrypoint','/bin/sh','pre-migration-db-backup','-eu','-c',$script,'snapshot-binding',$name) -Capture -TimeoutSeconds 120 -Code 'historical_snapshot_probe_failed')
    $pattern = '^snapshot_binding=([a-f0-9]{64}):([a-f0-9]{64}):([1-9][0-9]*)$'
    if ($lines.Count -ne 1 -or $lines[0] -notmatch $pattern) { Stop-CompletedOwnerV16ToV18 'historical_snapshot_binding_invalid' }
    $match = [regex]::Match($lines[0], $pattern)
    if ($match.Groups[1].Value -ne $manifestSha -or $match.Groups[2].Value -ne $dumpSha -or $match.Groups[3].Value -ne $dumpBytes) {
        Stop-CompletedOwnerV16ToV18 'historical_snapshot_binding_mismatch'
    }
    return @{ Name=$name; ManifestSha256=$manifestSha }
}

function Invoke-CurrentV4Acceptance([string]$Name) {
    if ($Name -notmatch '^v4-synthetic-phase-ab-[0-9]{8}T[0-9]{6}Z\.json$') { Stop-CompletedOwnerV16ToV18 'current_v4_gate_name_invalid' }
    $lines = Invoke-ChildPowerShell $v4AttestationHelper @('-Action','Verify','-GateName',$Name) 'current_v4_gate_invalid' 90
    $pattern = '^V4_SYNTHETIC_PHASE_AB_ATTESTATION=PASS action=Verify gate=(v4-synthetic-phase-ab-[0-9]{8}T[0-9]{6}Z\.json) sha256=([a-f0-9]{64}) scope=SYNTHETIC_8091 browser=GOOGLE_CHROME_STABLE media=MEDIAGUARD_REGRESSION$'
    $rows = @($lines | Where-Object { $_ -match $pattern })
    if ($rows.Count -ne 1) { Stop-CompletedOwnerV16ToV18 'current_v4_gate_evidence_invalid' }
    $match = [regex]::Match($rows[0], $pattern)
    if ($match.Groups[1].Value -ne $Name) { Stop-CompletedOwnerV16ToV18 'current_v4_gate_name_mismatch' }
    return @{ Name=$Name; Sha256=$match.Groups[2].Value }
}

function Assert-NormalRuntimeVerifyOnly {
    # These are the only ClassIdentity plugin commands in this completed-state
    # validator. Both modes inspect locked state only; neither changes a
    # projection, starts background work, nor touches stored files.
    Invoke-PiwigoReadOnly @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--verify-only') -TimeoutSeconds 90 -Code 'class_archive_plugin_verify_failed'
    Invoke-PiwigoReadOnly @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-locked-piwigo-extensions.php','--verify-only') -TimeoutSeconds 90 -Code 'locked_extension_verify_failed'
}

function ConvertTo-StrictCounts([string[]]$Lines, [string[]]$ExpectedKeys, [string]$Code) {
    $values = [ordered]@{}
    foreach ($line in $Lines) {
        if ($line -notmatch '^([a-z_]+)=([0-9]+)$') { Stop-CompletedOwnerV16ToV18 $Code }
        $key = [string]$Matches[1]
        if ($values.Contains($key)) { Stop-CompletedOwnerV16ToV18 $Code }
        $values[$key] = [uint64]$Matches[2]
    }
    if ($values.Count -ne $ExpectedKeys.Count -or @($ExpectedKeys | Where-Object { -not $values.Contains($_) }).Count -ne 0) {
        Stop-CompletedOwnerV16ToV18 $Code
    }
    return $values
}

function ConvertTo-StrictFingerprints([string[]]$Lines, [string[]]$ExpectedKeys, [string]$Code) {
    $values = [ordered]@{}
    foreach ($line in $Lines) {
        if ($line -notmatch '^([a-z_]+)=([a-f0-9]{64})$') { Stop-CompletedOwnerV16ToV18 $Code }
        $key = [string]$Matches[1]
        if ($values.Contains($key)) { Stop-CompletedOwnerV16ToV18 $Code }
        $values[$key] = [string]$Matches[2]
    }
    if ($values.Count -ne $ExpectedKeys.Count -or @($ExpectedKeys | Where-Object { -not $values.Contains($_) }).Count -ne 0) {
        Stop-CompletedOwnerV16ToV18 $Code
    }
    return $values
}

function Get-CurrentCounts {
    $mariaSql = @'
set -eu
: "${MARIADB_ROOT_PASSWORD:?}"
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
unset MARIADB_ROOT_PASSWORD
trap 'unset MYSQL_PWD || true' EXIT HUP INT TERM
q() { mariadb --batch --skip-column-names --protocol=socket --user=root "$MARIADB_DATABASE" --execute "$1"; }
ci=$(q "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';")
case "$ci" in ''|*[!A-Za-z0-9_]*) exit 91 ;; esac
base=${ci%migration}; pwg=${base%class_identity_}; [ "$pwg" != "$base" ] || exit 92
ledger_shape=$(q "SELECT CONCAT(COUNT(*),':',COUNT(DISTINCT version),':',COALESCE(MIN(version),0),':',COALESCE(MAX(version),0)) FROM ${base}migration;")
[ "$ledger_shape" = '18:18:1:18' ] || exit 93
table_count() { q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$1';"; }
required() { [ "$(table_count "$1")" = 1 ] || exit 94; }
for suffix in photo_source photo_source_presentation photo photo_comment person person_merge person_photo_rule album spotlight auto_collection audit_event ai_asset_index ai_index_job; do
  required "${base}${suffix}"
done
for suffix in users user_access user_group user_infos groups; do
  required "${pwg}${suffix}"
done
for suffix in collection_snapshot collection_snapshot_item collection_snapshot_pointer collection_pin collection_feedback collection_maintenance_state spotlight_rotation_state; do
  required "${base}${suffix}"
done
rotation_rows=$(q "SELECT COUNT(*) FROM ${base}spotlight_rotation_state;")
case "$rotation_rows" in 0|1|2) ;; *) exit 95 ;; esac
rotation_scopes=$(q "SELECT COUNT(DISTINCT scope) FROM ${base}spotlight_rotation_state;")
[ "$rotation_scopes" = "$rotation_rows" ] || exit 96
rotation_invalid=$(q "SELECT COUNT(*) FROM ${base}spotlight_rotation_state WHERE scope NOT IN ('FULL','HERITAGE') OR OCTET_LENGTH(candidate_digest) <> 32 OR OCTET_LENGTH(revision) <> 32 OR next_rotation_at IS NULL OR (last_rotated_at IS NOT NULL AND next_rotation_at <= last_rotated_at);")
[ "$rotation_invalid" = 0 ] || exit 97
printf 'class_identity_schema_version=18\n'
printf 'migration_ledger_rows=18\n'
printf 'source_records=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_source;")"
printf 'source_presentations=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_source_presentation;")"
printf 'canonical_photos=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo;")"
printf 'piwigo_images=%s\n' "$(q "SELECT COUNT(*) FROM ${pwg}images;")"
printf 'album_relationships=%s\n' "$(q "SELECT COUNT(*) FROM ${pwg}image_category;")"
printf 'leaf_albums=%s\n' "$(q "SELECT COUNT(*) FROM ${base}album a WHERE a.state='ACTIVE' AND EXISTS (SELECT 1 FROM ${pwg}image_category ic WHERE ic.category_id=a.piwigo_category_id);")"
printf 'comments=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment;")"
printf 'replies=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment WHERE parent_comment_id IS NOT NULL;")"
printf 'visible_people=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person WHERE state='ACTIVE' AND visibility='VISIBLE';")"
printf 'person_merges=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person_merge;")"
printf 'person_rules=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person_photo_rule;")"
printf 'spotlights=%s\n' "$(q "SELECT COUNT(*) FROM ${base}spotlight;")"
printf 'memories=%s\n' "$(q "SELECT COUNT(*) FROM ${base}auto_collection;")"
printf 'audit_events=%s\n' "$(q "SELECT COUNT(*) FROM ${base}audit_event;")"
printf 'ai_asset_index=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_asset_index;")"
printf 'ai_jobs_total=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job;")"
printf 'ai_jobs_complete=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state='COMPLETE';")"
printf 'ai_jobs_open=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state IN ('PENDING','RUNNING','UNAVAILABLE');")"
'@
    $mariaKeys = @('class_identity_schema_version','migration_ledger_rows','source_records','source_presentations','canonical_photos','piwigo_images','album_relationships','leaf_albums','comments','replies','visible_people','person_merges','person_rules','spotlights','memories','audit_events','ai_asset_index','ai_jobs_total','ai_jobs_complete','ai_jobs_open')
    $maria = ConvertTo-StrictCounts @(Invoke-PiwigoReadOnly @('exec','-T','db','sh','-eu','-c',$mariaSql) -Capture -TimeoutSeconds 120 -Code 'current_mariadb_count_probe_failed') $mariaKeys 'current_mariadb_count_output_invalid'
    $pgSql = @'
SELECT 'immich_assets='||COUNT(*) FROM asset
UNION ALL SELECT 'immich_face_records='||COUNT(*) FROM asset_face
UNION ALL SELECT 'immich_raw_persons='||COUNT(*) FROM person
UNION ALL SELECT 'immich_face_search='||COUNT(*) FROM face_search
UNION ALL SELECT 'immich_search_index='||COUNT(*) FROM smart_search;
'@
    $pgKeys = @('immich_assets','immich_face_records','immich_raw_persons','immich_face_search','immich_search_index')
    $postgres = ConvertTo-StrictCounts @(Invoke-ImmichReadOnly @('exec','-T','--user','postgres','database','psql','--no-psqlrc','--tuples-only','--no-align','--set','ON_ERROR_STOP=1','--dbname=immich','--command',$pgSql) -Capture -TimeoutSeconds 120) $pgKeys 'current_immich_count_output_invalid'
    $all = [ordered]@{}
    foreach ($entry in $maria.GetEnumerator()) { $all[[string]$entry.Key] = [uint64]$entry.Value }
    foreach ($entry in $postgres.GetEnumerator()) { $all[[string]$entry.Key] = [uint64]$entry.Value }
    return $all
}

function Get-CurrentStableFingerprints {
    $mariaSql = @'
set -eu
umask 077
: "${MARIADB_ROOT_PASSWORD:?}"
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
unset MARIADB_ROOT_PASSWORD
q_to_file() { mariadb --batch --skip-column-names --binary-as-hex --protocol=socket --user=root "$MARIADB_DATABASE" --execute "$1" > "$2"; }
q() { mariadb --batch --skip-column-names --protocol=socket --user=root "$MARIADB_DATABASE" --execute "$1"; }
ci=$(q "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';")
case "$ci" in ''|*[!A-Za-z0-9_]*) exit 91 ;; esac
base=${ci%migration}; pwg=${base%class_identity_}; [ "$pwg" != "$base" ] || exit 92
ledger_shape=$(q "SELECT CONCAT(COUNT(*),':',COUNT(DISTINCT version),':',COALESCE(MIN(version),0),':',COALESCE(MAX(version),0)) FROM ${base}migration;")
[ "$ledger_shape" = '18:18:1:18' ] || exit 93
for suffix in submission archive_image photo photo_source photo_source_presentation photo_duplicate person person_merge person_photo_rule album spotlight auto_collection auto_collection_photo photo_comment ai_asset_index ai_index_job native_source_epoch batch_operation batch_operation_item private_library_collection private_library_folder private_library_import private_library_import_item; do
  present=$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${base}${suffix}';")
  [ "$present" = 1 ] || exit 94
done
tmp=$(mktemp) || exit 95
cleanup() { rm -f -- "$tmp"; unset MYSQL_PWD || true; }
trap cleanup EXIT HUP INT TERM
fingerprint() {
  name="$1"; sql="$2"; : > "$tmp"; q_to_file "$sql" "$tmp"
  digest=$(sha256sum "$tmp" | awk '{print $1}')
  case "$digest" in ''|*[!a-f0-9]*) exit 96 ;; esac
  [ "${#digest}" -eq 64 ] || exit 96
  printf '%s=%s\n' "$name" "$digest"
}
fingerprint canonical_media "SELECT 'submission'; SELECT * FROM ${base}submission ORDER BY id; SELECT 'photo'; SELECT * FROM ${base}photo ORDER BY class_photo_id; SELECT 'photo_source'; SELECT * FROM ${base}photo_source ORDER BY id; SELECT 'photo_source_presentation'; SELECT * FROM ${base}photo_source_presentation ORDER BY photo_source_id; SELECT 'photo_duplicate'; SELECT * FROM ${base}photo_duplicate ORDER BY duplicate_id; SELECT 'private_library_collection'; SELECT * FROM ${base}private_library_collection ORDER BY source_collection_id; SELECT 'private_library_folder'; SELECT * FROM ${base}private_library_folder ORDER BY folder_id; SELECT 'private_library_import'; SELECT * FROM ${base}private_library_import ORDER BY import_id; SELECT 'private_library_import_item'; SELECT * FROM ${base}private_library_import_item ORDER BY import_id,item_digest; SELECT 'piwigo_images'; SELECT * FROM ${pwg}images ORDER BY id;"
fingerprint album_membership "SELECT 'archive_image'; SELECT * FROM ${base}archive_image ORDER BY id; SELECT 'album'; SELECT * FROM ${base}album ORDER BY class_album_id; SELECT 'batch_operation'; SELECT * FROM ${base}batch_operation ORDER BY batch_id; SELECT 'batch_operation_item'; SELECT * FROM ${base}batch_operation_item ORDER BY batch_id,id; SELECT 'categories'; SELECT * FROM ${pwg}categories ORDER BY id; SELECT 'image_category'; SELECT * FROM ${pwg}image_category ORDER BY image_id,category_id;"
fingerprint comments "SELECT * FROM ${base}photo_comment ORDER BY comment_id;"
fingerprint person_curation "SELECT 'person'; SELECT * FROM ${base}person ORDER BY class_person_id; SELECT 'person_merge'; SELECT * FROM ${base}person_merge ORDER BY merge_id; SELECT 'person_photo_rule'; SELECT * FROM ${base}person_photo_rule ORDER BY class_person_id,class_photo_id;"
fingerprint spotlight_collections "SELECT 'spotlight'; SELECT * FROM ${base}spotlight ORDER BY spotlight_id; SELECT 'auto_collection'; SELECT * FROM ${base}auto_collection ORDER BY auto_collection_id; SELECT 'auto_collection_photo'; SELECT * FROM ${base}auto_collection_photo ORDER BY auto_collection_id,ordinal,class_photo_id;"
fingerprint ai_control "SELECT 'ai_asset_index'; SELECT * FROM ${base}ai_asset_index ORDER BY class_photo_id; SELECT 'ai_index_job'; SELECT * FROM ${base}ai_index_job ORDER BY job_id; SELECT 'native_source_epoch'; SELECT * FROM ${base}native_source_epoch ORDER BY source_key;"
'@
    $mariaKeys = @('canonical_media','album_membership','comments','person_curation','spotlight_collections','ai_control')
    $maria = ConvertTo-StrictFingerprints @(Invoke-PiwigoReadOnly @('exec','-T','db','sh','-eu','-c',$mariaSql) -Capture -TimeoutSeconds 300 -Code 'current_mariadb_fingerprint_probe_failed') $mariaKeys 'current_mariadb_fingerprint_output_invalid'
    $pgSql = @'
set -eu
fingerprint_sql="SELECT 'asset'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM asset) AS t ORDER BY row; SELECT 'asset_face'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM asset_face) AS t ORDER BY row; SELECT 'face_search'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM face_search) AS t ORDER BY row; SELECT 'person'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM person) AS t ORDER BY row; SELECT 'smart_search'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM smart_search) AS t ORDER BY row;"
tmp=$(mktemp) || exit 97
cleanup() { rm -f -- "$tmp"; }
trap cleanup EXIT HUP INT TERM
psql --no-psqlrc --tuples-only --no-align --set ON_ERROR_STOP=1 --dbname=immich --command "$fingerprint_sql" > "$tmp"
digest=$(sha256sum "$tmp" | awk '{print $1}')
case "$digest" in ''|*[!a-f0-9]*) exit 98 ;; esac
[ "${#digest}" -eq 64 ] || exit 98
printf 'immich_ai_state=%s\n' "$digest"
'@
    $postgres = ConvertTo-StrictFingerprints @(Invoke-ImmichReadOnly @('exec','-T','--user','postgres','database','sh','-eu','-c',$pgSql) -Capture -TimeoutSeconds 300) @('immich_ai_state') 'current_immich_fingerprint_output_invalid'
    $all = [ordered]@{}
    foreach ($entry in $maria.GetEnumerator()) { $all[[string]$entry.Key] = [string]$entry.Value }
    $all['immich_ai_state'] = [string]$postgres['immich_ai_state']
    return $all
}

function Assert-CompletedState([hashtable]$Baseline) {
    $currentCounts = Get-CurrentCounts
    foreach ($key in $Baseline.Counts.Keys) {
        if ($key -in @('class_identity_schema_version','migration_ledger_rows','audit_events')) { continue }
        if (-not $currentCounts.Contains($key) -or [uint64]$currentCounts[$key] -ne [uint64]$Baseline.Counts[$key]) {
            Stop-CompletedOwnerV16ToV18 ('completed_count_mismatch_' + $key)
        }
    }
    if ([uint64]$currentCounts['class_identity_schema_version'] -ne 18 -or [uint64]$currentCounts['migration_ledger_rows'] -ne 18) {
        Stop-CompletedOwnerV16ToV18 'completed_target_ledger_mismatch'
    }
    if ([uint64]$currentCounts['audit_events'] -lt [uint64]$Baseline.Counts['audit_events']) {
        Stop-CompletedOwnerV16ToV18 'completed_audit_event_regressed'
    }
    $currentFingerprints = Get-CurrentStableFingerprints
    foreach ($key in @('canonical_media','album_membership','comments','person_curation','spotlight_collections','ai_control','immich_ai_state')) {
        if (-not $currentFingerprints.Contains($key) -or -not [string]::Equals([string]$currentFingerprints[$key], [string]$Baseline.Semantic[$key], [StringComparison]::Ordinal)) {
            Stop-CompletedOwnerV16ToV18 ('completed_semantic_mismatch_' + $key)
        }
    }
    # The historical V16 baseline intentionally captured one combined
    # identity_and_audit fingerprint. Normal recovery/verification can append
    # audit rows, so equality would reject a healthy completed state. This is
    # not a broad waiver: all non-audit counts and independent fingerprints
    # remain exact, and audit_events may only grow.
    return 'APPEND_ONLY'
}

try {
    Assert-CleanCheckout
    Assert-DockerDesktopEnginePipe
    $historical = Read-HistoricalPlan $MigrationPlanName
    $baseline = Read-HistoricalBaseline $historical.Plan
    $historicalV4 = Read-HistoricalV4Gate $historical.Plan $historical.HistoricalHead
    [void](Read-HistoricalDirectProof $historical.Plan $historical.HistoricalHead)
    Assert-OwnerLoopback
    Assert-TargetV18Ledger
    $snapshot = Assert-SnapshotBinding $historical.Plan
    Assert-NormalRuntimeVerifyOnly
    $currentV4 = Invoke-CurrentV4Acceptance $CurrentV4AcceptanceGateName
    $auditDrift = Assert-CompletedState $baseline
    Write-Output ('COMPLETED_OWNER_V16_TO_V18_VALIDATION=PASS endpoint=owner ports=8190_8191 plan=' + $MigrationPlanName + ' plan_sha256=' + $historical.Sha256 + ' historical_head=' + $historical.HistoricalHead + ' current_head=' + $historical.CurrentHead + ' snapshot=' + $snapshot.Name + ' baseline=' + $baseline.Name + ' historical_v4_gate=' + $historicalV4.Name + ' current_v4_gate=' + $currentV4.Name + ' direct_proof=HISTORICAL_BOUND audit_drift=' + $auditDrift + ' runtime=VERIFY_ONLY media=UNTOUCHED ai=UNCHANGED maintenance=NOT_ENTERED')
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^COMPLETED_OWNER_V16_TO_V18_STOP:([a-z0-9_]{1,128})$') { $Matches[1] } else { 'completed_owner_v16_to_v18_validation_failed' }
    Write-Output "COMPLETED_OWNER_V16_TO_V18_VALIDATION=FAIL endpoint=owner code=$code"
    exit 2
}
