[CmdletBinding()]
param()

# Public-safe contract for the completed V16 -> V18 Owner validator. This test
# reads tracked source text and starts one bounded, read-only local Git probe;
# it starts no container, opens no Owner database, reads no private
# plan/snapshot/baseline, and never accesses media.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$validatorPath = Join-Path $projectRoot 'infra\scripts\validate-completed-owner-v16-to-v18.ps1'
$boundedNativePath = Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Slice-Function([string]$Text, [string]$StartName, [string]$NextName, [string]$Code) {
    $start = $Text.IndexOf($StartName, [StringComparison]::Ordinal)
    $next = $Text.IndexOf($NextName, [StringComparison]::Ordinal)
    Assert-True ($start -ge 0 -and $next -gt $start) $Code
    return $Text.Substring($start, $next - $start)
}

Assert-True (Test-Path -LiteralPath $validatorPath -PathType Leaf) 'completed_v16_to_v18_validator_missing'
$tokens = $null
$parseErrors = $null
$validatorAst = [System.Management.Automation.Language.Parser]::ParseFile($validatorPath, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'completed_v16_to_v18_validator_parse_invalid'
$validator = [IO.File]::ReadAllText($validatorPath)

# Exercise the validator's actual timestamp helper in isolation. PowerShell 7.6
# converts a JSON RFC3339 token ending in Z to DateTime(Utc), whereas Windows
# PowerShell leaves it as a string. Both representations must pass without a
# culture-sensitive string cast; invalid calendar values and non-UTC forms
# must remain fail closed.
$timeHelperAsts = @($validatorAst.FindAll({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -eq 'Test-StrictUtcRfc3339Value'
}, $true))
Assert-True ($timeHelperAsts.Count -eq 1) 'completed_v16_to_v18_utc_timestamp_helper_shape_invalid'
Invoke-Expression ([string]$timeHelperAsts[0].Extent.Text)
Assert-True (Test-StrictUtcRfc3339Value '2026-08-29T13:31:09Z') 'completed_v16_to_v18_raw_utc_timestamp_rejected'
Assert-True (Test-StrictUtcRfc3339Value '2026-08-29T13:31:09.1234567Z') 'completed_v16_to_v18_fractional_utc_timestamp_rejected'
$utcDate = [DateTime]::SpecifyKind([DateTime]::new(2026, 8, 29, 13, 31, 9), [DateTimeKind]::Utc)
Assert-True (Test-StrictUtcRfc3339Value $utcDate) 'completed_v16_to_v18_utc_datetime_rejected'
$jsonUtcValue = (([pscustomobject]@{ created_at_utc = '2026-08-29T13:31:09Z' } | ConvertTo-Json -Compress) | ConvertFrom-Json).created_at_utc
Assert-True (Test-StrictUtcRfc3339Value $jsonUtcValue) 'completed_v16_to_v18_version_specific_json_utc_value_rejected'
foreach ($nonUtcJsonTimestamp in @('2026-08-29T13:31:09+00:00','2026-08-29T13:31:09+08:00','2026-08-29T13:31:09')) {
    $nonUtcJsonValue = (([pscustomobject]@{ created_at_utc = $nonUtcJsonTimestamp } | ConvertTo-Json -Compress) | ConvertFrom-Json).created_at_utc
    Assert-True (-not (Test-StrictUtcRfc3339Value $nonUtcJsonValue)) 'completed_v16_to_v18_json_offset_or_unzoned_timestamp_accepted'
}
foreach ($invalidTime in @(
    '2026-02-30T13:31:09Z',
    '2026-08-29T24:00:00Z',
    '2026-08-29T13:31:09+00:00',
    '2026-08-29T13:31:09',
    '2026-08-29T13:31:09.12345678Z',
    '1999-08-29T13:31:09Z',
    [DateTime]::SpecifyKind([DateTime]::new(2026, 8, 29, 13, 31, 9), [DateTimeKind]::Local),
    [DateTime]::SpecifyKind([DateTime]::new(2026, 8, 29, 13, 31, 9), [DateTimeKind]::Unspecified),
    [DateTimeOffset]::new(2026, 8, 29, 13, 31, 9, [TimeSpan]::Zero)
)) {
    Assert-True (-not (Test-StrictUtcRfc3339Value $invalidTime)) 'completed_v16_to_v18_invalid_or_non_utc_timestamp_accepted'
}

# The public surface is a fixed validator, never a concealed state-changing
# action. It accepts only opaque local leaf names for a historical plan and a
# fresh current V4 gate.
Assert-True ($validator.Contains('[string]$MigrationPlanName') -and $validator.Contains('[string]$CurrentV4AcceptanceGateName')) 'completed_v16_to_v18_validator_inputs_missing'
Assert-True (-not $validator.Contains('ConfirmOwnerV16ToV18Migration') -and -not $validator.Contains('[ValidateSet(')) 'completed_v16_to_v18_validator_write_action_surface_forbidden'
Assert-True ($validator.Contains('Set-StrictMode -Version Latest') -and $validator.Contains("`$ErrorActionPreference = 'Stop'")) 'completed_v16_to_v18_validator_fail_closed_mode_missing'
Assert-True ($validator.Contains('COMPLETED_OWNER_V16_TO_V18_VALIDATION=PASS') -and $validator.Contains('COMPLETED_OWNER_V16_TO_V18_VALIDATION=FAIL')) 'completed_v16_to_v18_validator_bounded_output_missing'
Assert-True ($validator.Contains('endpoint=owner ports=8190_8191') -and $validator.Contains('media=UNTOUCHED ai=UNCHANGED maintenance=NOT_ENTERED')) 'completed_v16_to_v18_validator_safe_scope_output_missing'

# Private artifacts are restricted to ignored owner-only leaves, reject
# reparse points, and are never supplied as arbitrary paths.
foreach ($needle in @('.codex-work\private-real-full\migration-v16-to-v18', '.codex-work\v4-synthetic-acceptance', '.codex-work\v18-synthetic-migration-attempt40\reports', 'check-ignore --quiet --no-index', 'ls-files', 'Assert-ClassArchiveOwnerOnlyFileAcl', 'ReparsePoint')) {
    Assert-True ($validator.Contains($needle)) ('completed_v16_to_v18_private_boundary_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}
Assert-True ($validator.Contains("'^owner-v16-to-v18-plan-[0-9]{8}T[0-9]{6}Z\.json$'") -and $validator.Contains("'^v4-synthetic-phase-ab-[0-9]{8}T[0-9]{6}Z\.json$'")) 'completed_v16_to_v18_leaf_name_validation_missing'
Assert-True ($validator.Contains('OPAQUE_LEAF_NAMES_AND_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_MEDIA_OR_SECRETS') -and $validator.Contains('COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_OR_SECRETS')) 'completed_v16_to_v18_private_metadata_contract_missing'

# A completed validator intentionally accepts a historical source only when it
# remains reachable from current HEAD and its schema bytes still equal both the
# plan SHA-256 and current checked-out Schema.php bytes.
Assert-True ($validator.Contains("merge-base','--is-ancestor") -and $validator.Contains('historical_head_not_current_ancestor') -and $validator.Contains("cat-file','-e")) 'completed_v16_to_v18_historical_ancestor_guard_missing'
Assert-True ($validator.Contains('$gitCandidates = @(Get-Command git.exe') -and $validator.Contains('$gitCandidates[0].Source') -and $validator.Contains('-Executable $gitPath')) 'completed_v16_to_v18_multiple_git_candidates_not_resolved'
Assert-True ($validator.Contains('function Assert-SchemaEquivalence') -and $validator.Contains('Get-HistoricalSchemaText') -and $validator.Contains('Get-TextSha256') -and $validator.Contains('historical_schema_current_sha_mismatch')) 'completed_v16_to_v18_schema_equivalence_guard_missing'
Assert-True ($validator.Contains('CURRENT_VERSION\s*=\s*18') -and $validator.Contains('0017_photos_app_v4_collection_snapshots') -and $validator.Contains('0018_photos_app_v4_spotlight_rotation_state')) 'completed_v16_to_v18_schema_ledger_guard_missing'
Assert-True ($validator.Contains('tracked_source_worktree_not_head_bound') -and $validator.Contains('tracked_source_index_not_head_bound')) 'completed_v16_to_v18_schema_checkout_binding_missing'
Assert-True ($validator.Contains('Test-StrictUtcRfc3339Value') -and $validator.Contains('[DateTimeKind]::Utc') -and $validator.Contains('[Globalization.CultureInfo]::InvariantCulture')) 'completed_v16_to_v18_utc_timestamp_contract_missing'
Assert-True (-not $validator.Contains("[string](Get-Property `$plan 'created_at')")) 'completed_v16_to_v18_culture_sensitive_timestamp_cast_forbidden'
$directProofReader = Slice-Function $validator 'function Read-HistoricalDirectProof' 'function Invoke-Wsl' 'completed_v16_to_v18_direct_proof_reader_slice_invalid'
Assert-True ([regex]::Matches($directProofReader, 'Test-StrictUtcRfc3339Value \(Get-Property \$(?:attestation|proof) ''created_at_utc''\)').Count -eq 2) 'completed_v16_to_v18_direct_timestamps_not_strictly_validated'
Assert-True (-not $directProofReader.Contains("[string](Get-Property `$attestation 'created_at_utc')") -and -not $directProofReader.Contains("[string](Get-Property `$proof 'created_at_utc')")) 'completed_v16_to_v18_direct_timestamp_culture_cast_forbidden'

# Exercise the validator's actual bounded Git wrapper. On developer machines
# Get-Command can return several git.exe applications; the wrapper must select
# one PATH-preferred leaf instead of passing a Source array to -Executable.
Assert-True (Test-Path -LiteralPath $boundedNativePath -PathType Leaf) 'completed_v16_to_v18_bounded_native_helper_missing'
. $boundedNativePath
$gitFunctionAsts = @($validatorAst.FindAll({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -eq 'Invoke-Git'
}, $true))
Assert-True ($gitFunctionAsts.Count -eq 1) 'completed_v16_to_v18_git_wrapper_shape_invalid'
Invoke-Expression ([string]$gitFunctionAsts[0].Extent.Text)
function Stop-CompletedOwnerV16ToV18([string]$Code) { throw $Code }
$gitProbe = Invoke-Git @('cat-file','-e','HEAD^{commit}') 'protocol_head_commit_unavailable'
Assert-True (-not $gitProbe.TimedOut -and [int]$gitProbe.ExitCode -eq 0) 'completed_v16_to_v18_git_wrapper_runtime_failed'
Assert-True ([string]::IsNullOrWhiteSpace([string]$gitProbe.Stdout) -and [string]::IsNullOrWhiteSpace([string]$gitProbe.Stderr)) 'completed_v16_to_v18_git_wrapper_runtime_output_unexpected'

# All historical evidence classes are checked independently. A fresh V4 gate
# is separately verified by the head-bound acceptance helper instead of reusing
# the historical Snapshot gate after code has changed.
foreach ($functionName in @('Read-HistoricalPlan','Read-HistoricalBaseline','Read-HistoricalV4Gate','Read-HistoricalDirectProof','Assert-SnapshotBinding','Invoke-CurrentV4Acceptance')) {
    Assert-True ($validator.Contains('function ' + $functionName)) ('completed_v16_to_v18_historical_validator_missing_' + $functionName.ToLowerInvariant())
}
Assert-True ($validator.Contains("@('-Action','Verify','-GateName',`$Name)")) 'completed_v16_to_v18_current_v4_verify_invocation_missing'
Assert-True ($validator.Contains('V4_SYNTHETIC_PHASE_AB_ATTESTATION=PASS action=Verify') -and $validator.Contains('GOOGLE_CHROME_STABLE')) 'completed_v16_to_v18_current_v4_evidence_contract_missing'
Assert-True ($validator.Contains('SYNTHETIC_DIRECT_V16_TO_V18_RUNTIME') -and $validator.Contains('v16-to-v18-direct-attestation.json') -and $validator.Contains('v16-to-v18-direct-proof.json') -and $validator.Contains('HISTORICAL_BOUND')) 'completed_v16_to_v18_direct_proof_binding_missing'
Assert-True ($validator.Contains('sha256sum -c SHA256SUMS') -and $validator.Contains('DB_ONLY_PRE_MIGRATION_ROLLBACK') -and $validator.Contains('historical_snapshot_binding_mismatch') -and $validator.Contains("'run','--rm','--no-deps','--entrypoint','/bin/sh','pre-migration-db-backup'")) 'completed_v16_to_v18_snapshot_checksum_binding_missing'

# Runtime checks are read-only. The normal ClassIdentity path has exactly the
# two reviewed --verify-only invocations; runtime installer mode, maintenance,
# projection rebuild, and AI/media write paths are forbidden.
$normalVerifier = Slice-Function $validator 'function Assert-NormalRuntimeVerifyOnly' 'function ConvertTo-StrictCounts' 'completed_v16_to_v18_normal_verifier_slice_invalid'
Assert-True ([regex]::Matches($normalVerifier, '--verify-only').Count -eq 2) 'completed_v16_to_v18_normal_verifier_verify_only_count_invalid'
Assert-True ($normalVerifier.Contains('install-class-archive-plugins.php') -and $normalVerifier.Contains('install-locked-piwigo-extensions.php')) 'completed_v16_to_v18_normal_verifier_targets_missing'
foreach ($probeCode in @(
    'target_schema_probe_failed',
    'historical_snapshot_probe_failed',
    'class_archive_plugin_verify_failed',
    'locked_extension_verify_failed',
    'current_mariadb_count_probe_failed',
    'current_mariadb_fingerprint_probe_failed'
)) {
    Assert-True ($validator.Contains("-Code '$probeCode'")) ('completed_v16_to_v18_stage_specific_probe_code_missing_' + $probeCode)
}
foreach ($forbidden in @('--verify-runtime','--prepare','--finalize-maintenance','Enter-Maintenance','Finalize-Maintenance','rebuild-photo-read-projection.php','AiIndexService','queue','upload','import','media-gateway.php')) {
    Assert-True (-not $normalVerifier.Contains($forbidden)) ('completed_v16_to_v18_normal_verifier_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}
# Evidence names may legitimately contain words such as "restart". Inspect
# actual compose argument arrays instead: this validator permits only exec and
# the one ephemeral run --rm checksum reader, never lifecycle operations.
$composeVerbMatches = [regex]::Matches($validator, "Invoke-(?:Piwigo|Immich)ReadOnly\s+@\('([^']+)'")
Assert-True ($composeVerbMatches.Count -ge 1) 'completed_v16_to_v18_compose_invocations_missing'
foreach ($match in $composeVerbMatches) {
    $verb = [string]$match.Groups[1].Value
    Assert-True ($verb -in @('exec','run')) ('completed_v16_to_v18_mutating_compose_verb_forbidden_' + $verb)
}
Assert-True ([regex]::Matches($validator, "Invoke-(?:Piwigo|Immich)ReadOnly\s+@\('run'").Count -eq 1 -and $validator.Contains("@('run','--rm','--no-deps','--entrypoint','/bin/sh','pre-migration-db-backup'")) 'completed_v16_to_v18_ephemeral_snapshot_reader_contract_invalid'
Assert-True ($validator.Contains("'exec','-T','db','sh','-eu','-c'") -and $validator.Contains("'exec','-T','--user','postgres','database','psql'")) 'completed_v16_to_v18_readonly_database_probe_missing'
foreach ($forbiddenSql in @('INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'DROP ', 'CREATE TABLE', 'TRUNCATE ')) {
    Assert-True (-not $validator.Contains($forbiddenSql)) ('completed_v16_to_v18_dml_forbidden_' + ($forbiddenSql -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}

# Immediate migration comparison remains untouched. The later validator has a
# deliberately narrow audit rule: every non-audit count and every independent
# stable semantic hash stays exact, while audit count can only move forward.
Assert-True ($validator.Contains('function Assert-CompletedState') -and $validator.Contains("`$key -in @('class_identity_schema_version','migration_ledger_rows','audit_events')") -and $validator.Contains('completed_audit_event_regressed')) 'completed_v16_to_v18_audit_append_only_guard_missing'
Assert-True ($validator.Contains("@('canonical_media','album_membership','comments','person_curation','spotlight_collections','ai_control','immich_ai_state')") -and $validator.Contains('completed_semantic_mismatch_')) 'completed_v16_to_v18_stable_semantic_exactness_missing'
Assert-True ($validator.Contains("return 'APPEND_ONLY'")) 'completed_v16_to_v18_audit_drift_classification_missing'
Assert-True ($validator.Contains('.Replace("`r`n", "`n").Replace("`r", "`n")') -and $validator.Contains('Add-ClassArchiveWslTimeout -Arguments $normalizedArguments')) 'completed_v16_to_v18_wsl_crlf_normalization_missing'

# The protocol itself is source-only; it must not acquire a Docker/runtime
# connection, browse loopback, or contain a private source path. Construct
# the restricted strings so this self-audit does not match its own literal
# assertion data.
$protocol = [IO.File]::ReadAllText($PSCommandPath)
$protocolForbidden = @(
    ('docker' + ' compose'),
    ('Start' + '-Process'),
    ('Invoke' + '-WebRequest'),
    ('127.0.0.1:' + '8191'),
    ('M:' + [char]92)
)
foreach ($forbidden in $protocolForbidden) {
    Assert-True (-not $protocol.Contains($forbidden)) ('completed_v16_to_v18_protocol_runtime_or_private_reference_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}

Write-Output "COMPLETED_OWNER_V16_TO_V18_VALIDATOR_PROTOCOL=PASS assertions=$assertions"
