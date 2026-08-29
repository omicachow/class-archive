[CmdletBinding()]
param()

# Static-only contract for the narrowly scoped finalizer used after a failed
# V16 pre-migration database snapshot.  It never starts Docker, reads the
# private Owner database, opens media, or changes a maintenance marker.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) {
        throw "OWNER_V16_SNAPSHOT_RECOVERY_FINALIZER_PROTOCOL=FAIL code=$Code"
    }
}

$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$path = Join-Path $root 'infra\scripts\finalize-owner-v16-snapshot-recovery.php'
Assert-True (Test-Path -LiteralPath $path -PathType Leaf) 'finalizer_missing'
$source = [IO.File]::ReadAllText($path)
$assertions = 0

Assert-True ($source.Contains("getenv('CLASS_ARCHIVE_OWNER_V16_SNAPSHOT_RECOVERY') !== '1'") -and $source.Contains("'--finalize-owner-v16-snapshot-recovery'")) 'scope_or_exact_argument_missing'; $assertions++
Assert-True ($source.Contains('($account[''name''] ?? null) !== ''nginx''') -and $source.Contains('nginx_user_required')) 'runtime_user_guard_missing'; $assertions++
Assert-True ($source.Contains("RECOVERY_V16_SOURCE_COMMIT = '57e419e832897cabdc2d3d45ed0ea1bf8ac88b8b'") -and $source.Contains("'b11eb0010d8e76b4c63da7171df31ea3bd0b43507972b16dea48e2a56dfe257d'") -and $source.Contains("'b23e907140b6a19a8af8a03ecf2eeec73a0e199f75d1bb441505b312365bd5e7'")) 'historical_v16_plugin_lock_missing'; $assertions++
Assert-True ($source.Contains('RecursiveDirectoryIterator') -and $source.Contains('isLink()') -and $source.Contains("hash_file('sha256'") -and $source.Contains('recoveryTreeDigest') -and $source.Contains('plugin_tree_lock_mismatch')) 'installed_tree_lock_verification_missing'; $assertions++
Assert-True ($source.Contains('realpath(RECOVERY_PIWIGO_ROOT)') -and $source.Contains('RECOVERY_MARKER_CONTENT') -and $source.Contains('recoveryTrustedMarkerOwnership') -and $source.Contains('maintenance_marker_untrusted')) 'trusted_marker_guard_missing'; $assertions++
Assert-True ($source.Contains("SET SESSION TRANSACTION READ ONLY") -and $source.Contains('MYSQLI_TRANS_START_READ_ONLY') -and $source.Contains('$db->rollback()')) 'readonly_database_boundary_missing'; $assertions++
Assert-True ($source.Contains("'class_identity_enforcement'") -and $source.Contains("!== 'true'")) 'enforcement_readonly_gate_missing'; $assertions++
foreach ($suffix in @('collection_snapshot','collection_snapshot_item','collection_snapshot_pointer','collection_pin','collection_feedback','collection_maintenance_state','spotlight_rotation_state')) {
    Assert-True ($source.Contains("'$suffix'")) ('v17_v18_table_absence_missing_' + $suffix); $assertions++
}
Assert-True ($source.Contains('Schema::CURRENT_VERSION !== 16') -and $source.Contains('->verifyCurrent()') -and $source.Contains('v16_ledger_not_exact')) 'installed_v16_schema_verification_missing'; $assertions++
Assert-True ($source.Contains('recoveryCloseTrustedMarker') -and ([regex]::Matches($source, '\bunlink\(').Count -eq 1)) 'marker_unlink_not_single_purpose'; $assertions++
foreach ($forbidden in @('CLASS_ARCHIVE_PLUGIN_SOURCE_ROOT','installPlugin(','activatePlugin(','configurePiwigoBaseline(','bootstrapClassIdentity(','Schema::migrate(','scanAndPersist(','/workspace/plugins','MediaGuard','Immich')) {
    Assert-True (-not $source.Contains($forbidden)) ('forbidden_mutation_or_dependency_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_')); $assertions++
}

Write-Output "OWNER_V16_SNAPSHOT_RECOVERY_FINALIZER_PROTOCOL=PASS assertions=$assertions"
