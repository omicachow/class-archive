[CmdletBinding()]
param()

# Static-only protocol gate for staging an existing synthetic DB-only snapshot
# into the v4 migration laboratory.  It never reads .env.piwigo, a Docker
# volume, an ignored bundle, or any runtime service.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'infra\scripts\stage-v4-synthetic-pre-migration-snapshot.ps1'
$composePath = Join-Path $projectRoot 'infra\docker-compose.yml'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

Assert-True (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'v4_snapshot_stage_runner_missing'
Assert-True (Test-Path -LiteralPath $composePath -PathType Leaf) 'v4_snapshot_stage_base_compose_missing'
$runner = [IO.File]::ReadAllText($runnerPath)
$compose = [IO.File]::ReadAllText($composePath)
$privateDriveRootMarker = 'M' + [char]58 + [char]92
$privateDriveSlashMarker = 'M' + [char]58 + '/'

# The helper has exactly two explicit actions.  validate is read-only and
# stage has a separate confirmation switch; there is no snapshot action.
Assert-True ($runner.Contains("[ValidateSet('validate', 'stage')]") -and $runner.Contains("[string]`$Action = 'validate'") -and $runner.Contains('[switch]$ConfirmStage')) 'v4_snapshot_stage_action_boundary_missing'
Assert-True ($runner.Contains("`$sandboxRoot = Join-Path `$projectRoot '.codex-work\v4-synthetic-migration'") -and $runner.Contains("`$inputRoot = Join-Path `$sandboxRoot 'input'")) 'v4_snapshot_stage_ignored_destination_missing'
Assert-True ($runner.Contains("`$baseProjectName = 'class_archive_piwigo'") -and $runner.Contains("`$baseBackupVolume = 'class_archive_piwigo_backups'")) 'v4_snapshot_stage_base_identity_missing'
Assert-True ($runner.Contains('function Assert-SandboxPath') -and $runner.Contains('private_or_source_path_forbidden') -and $runner.Contains('Assert-NoReparsePoints')) 'v4_snapshot_stage_destination_path_guard_missing'
Assert-True ($runner.Contains('git -C $projectRoot check-ignore --quiet --no-index') -and $runner.Contains('git -C $projectRoot ls-files')) 'v4_snapshot_stage_git_boundary_missing'
Assert-True ($runner.Contains("[ValidatePattern('^pre-migration-db-v16-to-v17-[0-9]{8}T[0-9]{6}Z$')]") -and $runner.Contains('snapshot_bundle_name_invalid')) 'v4_snapshot_stage_exact_bundle_name_missing'
Assert-True ($runner.Contains('stage_confirmation_required') -and $runner.Contains('Assert-EmptyInputRoot') -and $runner.Contains('snapshot_destination_already_exists')) 'v4_snapshot_stage_no_overwrite_boundary_missing'

# Actual base Compose is inspected in-memory and must prove the source is the
# fixed loopback synthetic runtime and its existing DB-only producer.  The
# stage helper never gets a path selector or a private-runtime selector.
Assert-True ($runner.Contains('docker compose --env-file $baseEnvFile -f $baseComposeFile -p $baseProjectName --profile ops config --format json')) 'v4_snapshot_stage_compose_provenance_missing'
Assert-True ($runner.Contains("`$actualPorts['8090'] -ne '80'") -and $runner.Contains("`$actualPorts['8091'] -ne '8081'") -and $runner.Contains('base_synthetic_non_loopback_port')) 'v4_snapshot_stage_loopback_source_guard_missing'
Assert-True ($runner.Contains('pre-migration-db-backup') -and $runner.Contains('create-pre-migration-db-snapshot') -and $runner.Contains('base_synthetic_snapshot_producer_invalid')) 'v4_snapshot_stage_existing_producer_guard_missing'
Assert-True ($runner.Contains("`$manifest.scope -ne 'DB_ONLY_PRE_MIGRATION_ROLLBACK'") -and $runner.Contains('schema_current -ne 16') -and $runner.Contains('schema_to -ne 17') -and $runner.Contains("`$manifest.media -ne 'NOT_INCLUDED'")) 'v4_snapshot_stage_db_only_manifest_guard_missing'
Assert-True ($runner.Contains('snapshot_not_created_by_existing_mechanism') -and $runner.Contains('create-pre-migration-db-snapshot.sh') -and $runner.Contains('Get-FileHash')) 'v4_snapshot_stage_snapshot_hash_provenance_missing'
Assert-True ($runner.Contains('base_synthetic_backup_volume_invalid') -and $runner.Contains('base_synthetic_backup_volume_not_docker_managed')) 'v4_snapshot_stage_volume_identity_guard_missing'
Assert-True ($runner.Contains('base_synthetic_env_not_ignored') -and $runner.Contains('Assert-ClassArchiveOwnerOnlyFileAcl')) 'v4_snapshot_stage_secret_env_boundary_missing'

# Copy can only occur through a stopped temporary container whose root FS and
# only source mount are read-only.  It is networkless, capability-dropped,
# accepts no source path, and is removed only after its own scope label is
# re-inspected.
Assert-True ($runner.Contains('docker create --name $name --label') -and $runner.Contains("'com.classarchive.scope=V4_SYNTHETIC_SNAPSHOT_STAGING'")) 'v4_snapshot_stage_container_identity_missing'
Assert-True ($runner.Contains('--read-only --network none --cap-drop ALL --security-opt') -and $runner.Contains("'no-new-privileges:true'")) 'v4_snapshot_stage_container_isolation_missing'
Assert-True ($runner.Contains('type=volume,source=') -and $runner.Contains('target=/backup,readonly') -and $runner.Contains('stage_container_mount_invalid')) 'v4_snapshot_stage_read_only_volume_mount_missing'
Assert-True ($runner.Contains("--tmpfs '/var/lib/mysql:rw,nosuid,nodev,noexec,size=1m'") -and $runner.Contains('Get-WslPath $StageParent')) 'v4_snapshot_stage_mariadb_volume_and_wsl_destination_guard_missing'
Assert-True ($runner.Contains('[BitConverter]::ToString($bytes)') -and -not $runner.Contains('[Convert]::ToHexString($bytes)')) 'v4_snapshot_stage_windows_powershell_hex_compatibility_missing'
Assert-True ($runner.Contains('docker cp') -and $runner.Contains('docker rm $name') -and $runner.Contains('stage_container_label_invalid')) 'v4_snapshot_stage_copy_cleanup_scope_missing'
Assert-True ($runner.Contains('stage_container_create_failed') -and $runner.Contains('stage_container_copy_failed') -and $runner.Contains('stage_partial_parent_not_empty')) 'v4_snapshot_stage_fail_closed_copy_missing'
Assert-True ($runner.Contains('snapshot_file_set_invalid') -and $runner.Contains("@('COMPLETE', 'MANIFEST.json', 'SHA256SUMS', 'database.sql.gz')") -and $runner.Contains('snapshot_checksum_failed') -and $runner.Contains('snapshot_complete_invalid')) 'v4_snapshot_stage_exact_bundle_integrity_missing'

# It must not make or alter a source snapshot, stop/start any existing stack,
# reach runtime databases, or select common owner/private endpoints and paths.
foreach ($forbidden in @(
    'docker compose run',
    'docker stop',
    'docker start',
    'docker exec',
    'mariadb-dump',
    'mariadb ',
    'mysql ',
    'docker volume rm',
    'docker system prune',
    'Remove-Item',
    $privateDriveRootMarker,
    $privateDriveSlashMarker,
    '127.0.0.1:8191',
    '127.0.0.1:8291',
    '/source/',
    '/private-real'
)) { Assert-True (-not $runner.Contains($forbidden)) ('v4_snapshot_stage_forbidden_operation_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

# The Compose source producer really is DB-only: /backup is its one mutable
# payload, while media/source volumes are absent.  This assertion protects
# against a future source-service expansion silently broadening the staging
# helper's scope.
$serviceStart = $compose.IndexOf('  pre-migration-db-backup:')
$serviceEnd = $compose.IndexOf("`n  backup-audit:", $serviceStart)
Assert-True ($serviceStart -ge 0 -and $serviceEnd -gt $serviceStart) 'v4_snapshot_stage_base_service_block_missing'
$serviceBlock = $compose.Substring($serviceStart, $serviceEnd - $serviceStart)
Assert-True ($serviceBlock.Contains('read_only: true') -and $serviceBlock.Contains('cap_drop: ["ALL"]') -and $serviceBlock.Contains('no-new-privileges:true')) 'v4_snapshot_stage_base_service_hardening_missing'
Assert-True ($serviceBlock.Contains('- backups:/backup') -and $serviceBlock.Contains('- ../infra:/workspace/infra:ro')) 'v4_snapshot_stage_base_service_required_mounts_missing'
foreach ($forbiddenMount in @('/source/', 'piwigo_uploads:', 'piwigo_galleries:', 'piwigo_derivatives:', $privateDriveRootMarker, $privateDriveSlashMarker)) {
    Assert-True (-not $serviceBlock.Contains($forbiddenMount)) ('v4_snapshot_stage_base_service_scope_expanded_' + ($forbiddenMount -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}

[void][ScriptBlock]::Create($runner)
Write-Output "V4_SYNTHETIC_MIGRATION_STAGE_PROTOCOL=PASS assertions=$assertions"
