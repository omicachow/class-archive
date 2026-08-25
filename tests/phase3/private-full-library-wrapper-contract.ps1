[CmdletBinding()]
param()

# Static-only guard for the Windows full-library preparation wrapper. It reads
# no source inventory, staging volume, or private manifest.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wrapper = Join-Path $projectRoot 'infra\scripts\prepare-private-full-library.ps1'
$source = [IO.File]::ReadAllText($wrapper, [Text.UTF8Encoding]::new($false, $true))
$preflight = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\test-private-full-storage-preflight.ps1'), [Text.UTF8Encoding]::new($false, $true))
$assertions = 0

function Assert-Wrapper([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

try {
    Assert-Wrapper ($source.Contains('.codex-work\private-real-full')) 'private_work_root_missing'
    Assert-Wrapper ($source.Contains("secret-file-acl.ps1")) 'owner_acl_helper_missing'
    Assert-Wrapper ($source.Contains("full-real-import-manifest.json")) 'runtime_manifest_missing'
    Assert-Wrapper ($source.Contains("full-real-source-journal.json")) 'source_journal_missing'
    Assert-Wrapper ($source.Contains("full-real-source-inventory.json")) 'inventory_snapshot_missing'
    Assert-Wrapper ($source.Contains('Set-ClassArchiveOwnerOnlyDirectoryAcl')) 'private_directory_acl_missing'
    Assert-Wrapper ($source.Contains('Set-ClassArchiveOwnerOnlyFileAcl')) 'private_file_acl_missing'
    Assert-Wrapper ($source.Contains('source_paths=NOT_PRINTED')) 'safe_output_marker_missing'
    Assert-Wrapper (-not ($source -match '[A-Z]:\\')) 'tracked_absolute_source_path_disclosure'
    Assert-Wrapper (-not ($source -match 'Write-(?:Output|Host).*\$(?:InventoryPath|StagingPath|CollectionLabelA|CollectionLabelB)')) 'private_input_echo_detected'
    Assert-Wrapper ($preflight.Contains('Get-PrivateFullManagedVolumeCapacity')) 'managed_volume_capacity_probe_missing'
    Assert-Wrapper ($preflight.Contains('class_archive_private_full_v3_piwigo_uploads')) 'managed_payload_volume_missing'
    Assert-Wrapper ($preflight.Contains('readonly')) 'capacity_probe_not_read_only'
    Assert-Wrapper ($preflight.Contains('CANONICAL_PRESENT')) 'post_import_capacity_state_missing'
    Assert-Wrapper ($preflight.Contains('target_volume_partial_import_ambiguous')) 'partial_import_fail_closed_missing'
    Assert-Wrapper (-not $preflight.Contains('Win32_LogicalDisk')) 'host_drive_capacity_probe_retained'
    Write-Output ('PRIVATE_FULL_LIBRARY_WRAPPER_CONTRACT=PASS assertions=' + $assertions)
}
catch {
    $code = [string]$_.Exception.Message
    if ($code -notmatch '^[a-z0-9_]{1,96}$') { $code = 'wrapper_contract_failed' }
    Write-Output ('PRIVATE_FULL_LIBRARY_WRAPPER_CONTRACT=FAIL code=' + $code)
    exit 1
}
