$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$scriptPath = Join-Path $projectRoot 'infra\scripts\private-role-e2e-business-snapshot.ps1'
$assertions = 0

function Assert-Protocol([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

Assert-Protocol (Test-Path -LiteralPath $scriptPath -PathType Leaf) 'private_role_snapshot_tool_missing'
$source = [IO.File]::ReadAllText($scriptPath)
$tokens = $null
$parseErrors = $null
[void][Management.Automation.Language.Parser]::ParseFile($scriptPath, [ref]$tokens, [ref]$parseErrors)
Assert-Protocol (@($parseErrors).Count -eq 0) 'private_role_snapshot_script_parse_invalid'

# Action and runtime scope are deliberately narrow. Compare is local-only and
# Capture is the sole Docker/database path.
Assert-Protocol ($source -match "(?s)\[ValidateSet\('Capture', 'Compare'\)\]") 'snapshot_action_surface_invalid'
Assert-Protocol ($source -match "(?s)\[ValidateSet\('owner'\)\]") 'snapshot_owner_endpoint_not_exact'
Assert-Protocol ($source.Contains('runtime-owner') -and $source.Contains('http://127.0.0.1:8190') -and $source.Contains('http://127.0.0.1:8191')) 'snapshot_owner_runtime_proof_missing'
Assert-Protocol (-not $source.Contains('0.0.0.0')) 'snapshot_non_loopback_endpoint_forbidden'
Assert-Protocol ($source.Contains('[switch]$ConfirmOwnerPrivateSnapshot') -and $source.Contains('explicit_confirmation_required')) 'snapshot_explicit_confirmation_missing'
Assert-Protocol ($source.Contains('Assert-CleanCheckout') -and $source.Contains('checkout_not_clean')) 'snapshot_source_binding_preflight_missing'
Assert-Protocol ($source.Contains('$expectedSchema = 18') -and $source.Contains('CURRENT_VERSION')) 'snapshot_current_schema_binding_missing'

# The output root and every sensitive artifact must be ignored, untracked,
# non-reparse, owner-only, and serialized behind a single-instance lock.
foreach ($needle in @(
    '.codex-work\private-role-e2e\business-snapshots',
    'check-ignore --quiet --no-index',
    'git -C $projectRoot ls-files',
    'Assert-NoReparseAncestor',
    'Set-OwnerOnlyDirectoryAcl',
    'Set-ClassArchiveOwnerOnlyFileAcl',
    'Assert-ClassArchiveOwnerOnlyFileAcl',
    '[IO.FileMode]::CreateNew',
    'snapshot_lock_unavailable'
)) {
    Assert-Protocol ($source.Contains($needle)) ('snapshot_private_boundary_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-Protocol ($source.Contains('immutable_bundle_already_exists') -and $source.Contains("'COMPLETE'")) 'snapshot_immutable_publish_boundary_missing'

# This is a MariaDB DB-only rollback point. It cannot touch media, Immich,
# source directories, or restore/import data.
Assert-Protocol ($source.Contains('mariadb-dump --quick --lock-all-tables --triggers --routines --events --add-drop-table')) 'snapshot_consistent_dump_strategy_missing'
Assert-Protocol ($source.Contains('MARIADB_DUMP_LOCK_ALL_TABLES_WITH_PRE_POST_STATE_EQUALITY')) 'snapshot_pre_post_consistency_contract_missing'
Assert-Protocol ($source.Contains("media = 'NOT_INCLUDED'") -and $source.Contains("disaster_backup = `$false")) 'snapshot_scope_not_db_only'
Assert-Protocol ($source.Contains('CANONICAL_ORIGINALS') -and $source.Contains('IMMICH_POSTGRES') -and $source.Contains('FACE_EMBEDDINGS')) 'snapshot_exclusion_manifest_missing'
$privateDriveMarker = 'M' + [char]58 + [char]92
Assert-Protocol ($source.Contains("'-d', 'Ubuntu', '--cd', `$projectRoot, '--exec'")) 'snapshot_wsl_compose_not_exec_safe'
Assert-Protocol ($source.Contains("`$lines = @(Invoke-WslCapture @('-d', 'Ubuntu', '--exec', 'wslpath'")) 'snapshot_single_line_wsl_capture_not_array_safe'
foreach ($forbidden in @('piwigo_uploads:', 'piwigo_galleries:', 'piwigo_derivatives:', $privateDriveMarker, 'restore-owner', 'mariadb --execute "DELETE', 'mariadb --execute "UPDATE', 'mariadb --execute "INSERT')) {
    Assert-Protocol (-not $source.Contains($forbidden)) ('snapshot_forbidden_mutation_or_private_path_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-Protocol ($source.Contains('export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"') -and $source.Contains('unset MARIADB_ROOT_PASSWORD') -and -not $source.Contains('--password=')) 'snapshot_database_secret_process_boundary_invalid'

# Required business counts are machine-readable aggregate values only.
foreach ($count in @(
    'source_records', 'canonical_photos', 'albums', 'album_relationships',
    'comment_rows', 'active_comments', 'reply_rows', 'active_replies',
    'spotlights', 'memories', 'active_pins', 'people_mappings',
    'identities', 'seats', 'accounts', 'principals',
    'person_merges', 'person_rules', 'claims', 'invitations', 'submissions',
    'audit_events', 'ai_jobs_total', 'ai_jobs_open', 'projection_epoch_rows'
)) {
    Assert-Protocol ($source.Contains("'$count'") -and $source.Contains("count.$count=")) ('snapshot_required_count_missing_' + $count)
}

# Raw rows stay in a container temporary file. Only deterministic SHA-256
# values cross the boundary, including an opaque audit high-water mark.
Assert-Protocol ($source.Contains('q "$sql" > "$tmp"') -and $source.Contains('sha256sum "$tmp"')) 'snapshot_container_local_fingerprint_missing'
foreach ($domain in @(
    'schema_ledger', 'canonical_media', 'album_membership', 'comments',
    'identity_security', 'submissions', 'person_curation',
    'spotlight_memories_pins', 'ai_projection_control',
    'audit_full', 'audit_preexisting_prefix', 'audit_high_water_opaque'
)) {
    Assert-Protocol ($source.Contains("'$domain'") -and $source.Contains("fingerprint $domain")) ('snapshot_semantic_domain_missing_' + $domain)
}
Assert-Protocol ($source.Contains('APPEND_ONLY_PREFIX_PRESERVED') -and $source.Contains('audit_rows_deleted') -and $source.Contains('audit_preexisting_prefix_changed')) 'snapshot_audit_append_only_guard_missing'
Assert-Protocol ($source.Contains('SELECT * FROM ${base}audit_event ORDER BY id LIMIT ${CLASS_ARCHIVE_AUDIT_PREFIX_ROWS}') -and $source.Contains('SELECT COALESCE(MAX(id),0) FROM ${base}audit_event')) 'snapshot_audit_prefix_or_opaque_high_water_missing'

# SHA-256 binds dump, manifest, completion marker, and the compare inputs.
foreach ($needle in @('MANIFEST.json', 'MANIFEST.sha256', 'SHA256SUMS', 'database.sql.gz', 'Get-FileHash', 'bundle_checksum_mismatch', 'ExpectedPreManifestSha256', 'ExpectedPostManifestSha256')) {
    Assert-Protocol ($source.Contains($needle)) ('snapshot_sha256_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Compare must hold every business count/fingerprint exact, with only audit
# append-only growth permitted by the Phase 3.4.1 cleanup contract.
Assert-Protocol ($source.Contains("if (`$key -eq 'audit_events') { continue }") -and $source.Contains('post_cleanup_count_mismatch_')) 'snapshot_stable_count_compare_missing'
Assert-Protocol ($source.Contains('$stableSemanticKeys') -and $source.Contains('post_cleanup_fingerprint_mismatch_')) 'snapshot_stable_semantic_compare_missing'
Assert-Protocol ($source.Contains('records=PRESERVED semantics=PRESERVED audit=APPEND_ONLY_PREFIX_PRESERVED')) 'snapshot_bounded_compare_record_missing'
Assert-Protocol ($source.Contains("`$privacyMarker = 'COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_COMMENT_BODIES_OR_SECRETS'")) 'snapshot_privacy_marker_missing'

# PASS/FAIL output is deliberately bounded: no bundle path, row identifier,
# filename, comment, credential, or secret value is interpolated.
foreach ($forbiddenOutput in @('bundle=', 'path=', 'filename=', 'comment=', 'credential=', 'secret=', 'RunMarker')) {
    $writeLines = @($source -split "`r?`n" | Where-Object { $_ -match 'Write-Output' }) -join "`n"
    Assert-Protocol (-not $writeLines.Contains($forbiddenOutput)) ('snapshot_stdout_private_marker_' + $forbiddenOutput.TrimEnd('=').ToLowerInvariant())
}
Assert-Protocol ($source.Contains('PRIVATE_ROLE_E2E_PRE_SNAPSHOT') -and $source.Contains('PRIVATE_ROLE_E2E_POST_SNAPSHOT') -and $source.Contains('PRIVATE_ROLE_E2E_BUSINESS_STATE=PASS')) 'snapshot_gate_records_missing'

Write-Output "PRIVATE_ROLE_E2E_BUSINESS_SNAPSHOT_PROTOCOL=PASS assertions=$assertions"
