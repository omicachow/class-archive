[CmdletBinding()]
param()

# Static, public-safe contract. It reads no ignored private manifest, source
# inventory, owner environment, staging file, Docker volume, or real image.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$operatorPath = Join-Path $projectRoot 'infra\scripts\private-real-supplemental.ps1'
$overlayPath = Join-Path $projectRoot 'infra\private-full\docker-compose.supplemental-ingress.override.yml'
$docsPath = Join-Path $projectRoot 'docs\private-real-supplemental-ingress.md'
$operator = [IO.File]::ReadAllText($operatorPath, [Text.UTF8Encoding]::new($false, $true))
$overlay = [IO.File]::ReadAllText($overlayPath, [Text.UTF8Encoding]::new($false, $true))
$docs = [IO.File]::ReadAllText($docsPath, [Text.UTF8Encoding]::new($false, $true))
$assertions = 0

function Assert-Protocol([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

try {
    Assert-Protocol ($operator.Contains("[ValidateSet('prepare', 'verify', 'compose-validate')]")) 'operator_action_set_invalid'
    foreach ($forbidden in @("'import'", "'apply'", 'docker compose run', 'docker compose exec', 'docker compose up')) {
        Assert-Protocol ($operator.IndexOf($forbidden, [StringComparison]::OrdinalIgnoreCase) -lt 0) 'operator_mutating_action_detected'
    }
    Assert-Protocol ($operator.Contains('ConfirmPrivateSourceRead.IsPresent')) 'prepare_confirmation_missing'
    $confirmation = $operator.IndexOf("Assert-Operator `$ConfirmPrivateSourceRead.IsPresent 'prepare_confirmation_required'", [StringComparison]::Ordinal)
    $directoryCreation = $operator.LastIndexOf("`$output = Ensure-PrivateDirectory", [StringComparison]::Ordinal)
    Assert-Protocol ($confirmation -ge 0 -and $directoryCreation -gt $confirmation) 'prepare_confirmation_after_mutation'
    Assert-Protocol ($operator.Contains(".codex-work\private-real-qa")) 'private_root_missing'
    Assert-Protocol ($operator.Contains('Assert-NoReparseComponents')) 'reparse_guard_missing'
    Assert-Protocol ($operator.Contains('Get-PrivateTreeItems')) 'safe_tree_enumerator_missing'
    Assert-Protocol (-not $operator.Contains('Get-ChildItem -LiteralPath $Path -Force -Recurse')) 'recursive_reparse_traversal_detected'
    Assert-Protocol ($operator.Contains("Assert-PathBelow `$staging `$output 'staging_must_be_below_output'")) 'staging_output_containment_missing'
    Assert-Protocol ($operator.Contains('Assert-IgnoredUntracked')) 'ignored_untracked_guard_missing'
    Assert-Protocol ($operator.Contains('Set-ClassArchiveOwnerOnlyFileAcl')) 'owner_file_acl_missing'
    Assert-Protocol ($operator.Contains('Set-OwnerOnlyDirectoryAcl')) 'owner_directory_acl_missing'
    Assert-Protocol ($operator.Contains("if (`$Action -eq 'prepare')")) 'verify_may_create_private_tree'
    Assert-Protocol ($operator.Contains('Verify and compose-validate are observational')) 'read_only_action_boundary_missing'
    Assert-Protocol ($operator.Contains('source_paths=NOT_PRINTED')) 'aggregate_output_marker_missing'
    Assert-Protocol (-not ($operator -match 'Write-(?:Output|Host).*\$(?:InventoryPath|AuditPath|OutputPath|StagingPath)')) 'private_path_echo_detected'
    Assert-Protocol ($operator.Contains("'^PRIVATE_REAL_SUPPLEMENTAL_PREPARE=PASS sources=")) 'prepare_output_allowlist_missing'
    Assert-Protocol ($operator.Contains("'^PRIVATE_REAL_SUPPLEMENTAL_VERIFY=PASS sources=")) 'verify_output_allowlist_missing'
    Assert-Protocol ($operator.Contains('config --format json')) 'compose_config_only_missing'
    Assert-Protocol ($operator.Contains("'class_archive_private_supplemental_ingress'")) 'compose_project_identity_guard_missing'
    Assert-Protocol ($operator.Contains("'owner_env_reparse'")) 'owner_env_reparse_guard_missing'
    Assert-Protocol ($operator.Contains('docker=NOT_STARTED')) 'docker_not_started_marker_missing'
    Assert-Protocol ($operator.Contains("'/mnt/m/'")) 'm_drive_mount_guard_missing'
    Assert-Protocol ($operator.Contains("'full-real-import-manifest.json'")) 'legacy_manifest_guard_missing'

    Assert-Protocol (($overlay | Select-String -Pattern '(?m)^\s+supplemental-ingress-audit:$' -AllMatches).Matches.Count -eq 1) 'overlay_service_invalid'
    Assert-Protocol ($overlay.Contains('network_mode: "none"')) 'overlay_network_not_disabled'
    Assert-Protocol ($overlay.Contains('read_only: true')) 'overlay_root_not_read_only'
    Assert-Protocol ($overlay.Contains('pull_policy: never')) 'overlay_pull_forbidden_missing'
    Assert-Protocol ($overlay.Contains('no-new-privileges:true')) 'overlay_no_new_privileges_missing'
    Assert-Protocol ($overlay.Contains('com.classarchive.operation: verify-only')) 'overlay_verify_only_label_missing'
    Assert-Protocol ([regex]::Matches($overlay, 'create_host_path: false').Count -eq 2) 'overlay_create_host_path_guard_invalid'
    Assert-Protocol (($overlay | Select-String -Pattern '(?m)^\s+- type: bind$' -AllMatches).Matches.Count -eq 2) 'overlay_bind_count_invalid'
    Assert-Protocol ($overlay.Contains('PRIVATE_SUPPLEMENTAL_MANIFEST_PATH')) 'overlay_manifest_mount_missing'
    Assert-Protocol ($overlay.Contains('PRIVATE_SUPPLEMENTAL_STAGING_PATH')) 'overlay_staging_mount_missing'
    Assert-Protocol ($overlay.Contains('/private-real-full/manifests/supplemental-import-manifest.json')) 'overlay_manifest_target_invalid'
    $privateDriveRootMarker = 'M:' + [IO.Path]::DirectorySeparatorChar
    foreach ($forbidden in @('FULL_REAL_STAGING_PATH', 'FULL_REAL_IMPORT_MANIFEST_PATH', 'full-real-import-manifest.json', '/mnt/m/', $privateDriveRootMarker, 'source_root', 'relative_source_path')) {
        Assert-Protocol ($overlay.IndexOf($forbidden, [StringComparison]::OrdinalIgnoreCase) -lt 0) 'overlay_source_or_legacy_input_detected'
    }

    Assert-Protocol ($docs.Contains('prepare / verify / compose-validate')) 'docs_action_boundary_missing'
    Assert-Protocol ($docs.Contains('does not import')) 'docs_no_import_boundary_missing'
    Assert-Protocol ($docs.Contains('Public CI')) 'docs_public_ci_boundary_missing'
    Assert-Protocol (-not ($docs -match '[A-Z]:\\')) 'docs_absolute_private_path_detected'
    Write-Output ('PRIVATE_REAL_SUPPLEMENTAL_OPERATOR_PROTOCOL=PASS assertions=' + $assertions)
}
catch {
    $code = [string]$_.Exception.Message
    if ($code -notmatch '^[a-z0-9_]{1,96}$') { $code = 'supplemental_operator_protocol_failed' }
    Write-Output ('PRIVATE_REAL_SUPPLEMENTAL_OPERATOR_PROTOCOL=FAIL code=' + $code + ' assertions=' + $assertions)
    exit 1
}
