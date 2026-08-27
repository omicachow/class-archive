[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('validate', 'migrate')]
    [string]$Action,

    [switch]$ConfirmRestoreMigration
)

# Maintenance-gated v15 -> v16 deployment for the already restored owner
# recovery runtime only. This file intentionally does not share the private
# full-library staging/owner selector: 8290/8291 now belongs to the isolated
# owner-restore projects and must never be confused with the retired staging
# endpoint. The restore databases and media remain on their M:-resident ext4
# bind volumes; only the restore Piwigo and compatibility BFF are recreated.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$piwigoProject = 'class_archive_owner_restore_v1_piwigo'
$immichProject = 'class_archive_owner_restore_v1_immich'
$scopeLabel = 'owner-restore-drill'
$storageLabel = 'm-ext4-bind'
$restoreVolumeRoot = '/mnt/classarchive-owner-restore-v1/volumes'
$piwigoEnvRelative = 'infra/owner-restore/.env.piwigo'
$immichEnvRelative = 'infra/owner-restore/.env.immich'
$piwigoEnvPath = Join-Path $projectRoot $piwigoEnvRelative.Replace('/', '\')
$immichEnvPath = Join-Path $projectRoot $immichEnvRelative.Replace('/', '\')
$runtimeRoot = Join-Path $projectRoot '.codex-work\owner-restore\runtime'
$lockPath = Join-Path $runtimeRoot 'class-plugin-v15-v16.lock'
$gitEvidenceRoot = Join-Path $runtimeRoot 'git-evidence'
$gitEvidenceHead = Join-Path $gitEvidenceRoot 'HEAD'
$gitEvidenceRefs = Join-Path $gitEvidenceRoot 'refs'
$restoreNginxPath = Join-Path $runtimeRoot 'nginx.conf'
$reportPath = Join-Path $projectRoot '.codex-work\owner-restore\reports\schema-v15-v16-deployment.json'
$migrationSourceVersion = 15
$migrationTargetVersion = 16
$migrationRequiredStatus = 'REQUIRED_CURRENT_V15'
$migrationCurrentStatus = 'NOT_REQUIRED_CURRENT_V16'
$stage = 'preflight'

. (Join-Path $PSScriptRoot 'class-plugin-workflow-lock.ps1')
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-Deployment([string]$Code) {
    throw $Code
}

function Assert-Deployment([bool]$Condition, [string]$Code) {
    if (-not $Condition) { Stop-Deployment $Code }
}

function Assert-PlainFile([string]$Path, [string]$Code) {
    Assert-Deployment (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Deployment (-not (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) $Code
}

function Assert-PlainDirectory([string]$Path, [string]$Code) {
    Assert-Deployment (Test-Path -LiteralPath $Path -PathType Container) $Code
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Deployment ($item.PSIsContainer -and -not (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) $Code
}

function Get-DirectorySecurity([string]$Path) {
    $method = [IO.Directory].GetMethod('GetAccessControl',[type[]]@([string]))
    if ($null -ne $method) { return [IO.Directory]::GetAccessControl($Path) }
    return Get-Acl -LiteralPath $Path
}

function Set-DirectorySecurity([string]$Path, [Security.AccessControl.DirectorySecurity]$Acl) {
    $method = [IO.Directory].GetMethod('SetAccessControl',[type[]]@([string],[Security.AccessControl.DirectorySecurity]))
    if ($null -ne $method) {
        [IO.Directory]::SetAccessControl($Path,$Acl)
        return
    }
    Set-Acl -LiteralPath $Path -AclObject $Acl
}

function Assert-OwnerOnlyDirectoryAcl([string]$Path) {
    $resolved = (Resolve-Path -LiteralPath $Path).Path
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    Assert-Deployment ($null -ne $identity) 'restore_deploy_directory_acl_invalid'
    $system = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administrators = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = Get-DirectorySecurity $resolved
    $owner = try { ([Security.Principal.NTAccount]$acl.Owner).Translate([Security.Principal.SecurityIdentifier]) } catch { [Security.Principal.SecurityIdentifier]$acl.Owner }
    $rules = @($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
    $expected = @($identity.Value,$system.Value,$administrators.Value) | Sort-Object
    $actual = @($rules | ForEach-Object { $_.IdentityReference.Value }) | Sort-Object
    $exactRules = @($rules | Where-Object {
        $_.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or
        ($_.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -ne [Security.AccessControl.FileSystemRights]::FullControl -or
        $_.IsInherited
    }).Count -eq 0
    Assert-Deployment ($acl.AreAccessRulesProtected -and $owner -eq $identity -and $rules.Count -eq 3 -and
        @(Compare-Object $expected $actual).Count -eq 0 -and $exactRules) 'restore_deploy_directory_acl_invalid'
}

function Set-OwnerOnlyDirectoryAcl([string]$Path) {
    $resolved = (Resolve-Path -LiteralPath $Path).Path
    try {
        Assert-OwnerOnlyDirectoryAcl $resolved
        return
    }
    catch { }
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    Assert-Deployment ($null -ne $identity) 'restore_deploy_directory_acl_invalid'
    $system = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administrators = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true,$false)
    foreach ($sid in @($identity,$system,$administrators)) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        [void]$acl.AddAccessRule($rule)
    }
    Set-DirectorySecurity $resolved $acl
    Assert-OwnerOnlyDirectoryAcl $resolved
}

function Get-CheckoutHead {
    $lines = @(& git -C $projectRoot rev-parse HEAD 2>$null)
    Assert-Deployment ($LASTEXITCODE -eq 0 -and $lines.Count -eq 1) 'restore_deploy_git_head_invalid'
    $head = ([string]$lines[0]).Trim().ToLowerInvariant()
    Assert-Deployment ($head -match '\A[0-9a-f]{40}\z') 'restore_deploy_git_head_invalid'
    return $head
}

function Assert-SourceCheckout {
    $schemaPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
    Assert-PlainFile $schemaPath 'restore_deploy_schema_source_missing'
    $source = [IO.File]::ReadAllText($schemaPath)
    $matches = [regex]::Matches($source, 'public\s+const\s+CURRENT_VERSION\s*=\s*([0-9]+)\s*;')
    Assert-Deployment ($matches.Count -eq 1 -and [int]$matches[0].Groups[1].Value -eq $migrationTargetVersion) 'restore_deploy_schema_target_contract_mismatch'

    $status = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>$null)
    Assert-Deployment ($LASTEXITCODE -eq 0 -and $status.Count -eq 0) 'restore_deploy_checkout_not_clean'
    [void](Get-CheckoutHead)
}

function Read-IdentityEnvironment([string]$Path, [hashtable]$Expected, [string]$Code) {
    Assert-PlainFile $Path $Code
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path, [Text.Encoding]::UTF8)) {
        if ($line -match '^\s*(?:#|$)') { continue }
        if ($line -notmatch '\A([A-Z][A-Z0-9_]*)=(.*)\z') { Stop-Deployment $Code }
        $key = [string]$Matches[1]
        Assert-Deployment (-not $values.ContainsKey($key)) $Code
        $values[$key] = [string]$Matches[2]
    }
    foreach ($entry in $Expected.GetEnumerator()) {
        Assert-Deployment ($values.ContainsKey($entry.Key) -and [string]$values[$entry.Key] -ceq [string]$entry.Value) $Code
    }
}

function Invoke-UbuntuCapture([string[]]$Arguments, [string]$Code = 'restore_deploy_docker_failed') {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec @Arguments 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0) { Stop-Deployment $Code }
    return @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

function Invoke-Ubuntu([string[]]$Arguments, [string]$Code = 'restore_deploy_docker_failed') {
    [void](Invoke-UbuntuCapture $Arguments $Code)
}

function Get-WslPath([string]$WindowsPath, [string]$Code) {
    Assert-PlainFile $WindowsPath $Code
    $lines = @(Invoke-UbuntuCapture @('wslpath','-a',[IO.Path]::GetFullPath($WindowsPath)) $Code)
    Assert-Deployment ($lines.Count -eq 1 -and $lines[0] -match '\A/mnt/[a-z]/') $Code
    return [string]$lines[0]
}

$immichEnvWsl = Get-WslPath $immichEnvPath 'restore_deploy_immich_env_invalid'
$piwigoCompose = @(
    'docker','compose','--env-file',$piwigoEnvRelative,
    '-f','infra/docker-compose.yml','-f','infra/owner-restore/docker-compose.piwigo.override.yml',
    '-p',$piwigoProject,'--profile','ops'
)
$immichCompose = @(
    'env',('IMMICH_SPIKE_ENV_FILE=' + $immichEnvWsl),
    'docker','compose','--env-file',$immichEnvRelative,
    '-f','infra/immich-spike/docker-compose.yml','-f','infra/owner-restore/docker-compose.immich.override.yml',
    '-p',$immichProject,
    '--profile','immich-spike','--profile','immich-ml','--profile','immich-web-compat','--profile','immich-gateway-integration'
)

function Invoke-ComposeCapture([ValidateSet('piwigo','immich')][string]$Kind, [string[]]$Arguments, [string]$Code) {
    $prefix = if ($Kind -eq 'piwigo') { $script:piwigoCompose } else { $script:immichCompose }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --cd $projectRoot -- @($prefix + $Arguments) 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0) { Stop-Deployment $Code }
    return @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

function Invoke-Compose([ValidateSet('piwigo','immich')][string]$Kind, [string[]]$Arguments, [string]$Code) {
    [void](Invoke-ComposeCapture $Kind $Arguments $Code)
}

function Get-DockerInspectLine([string]$Container, [string]$Format, [string]$Code) {
    $lines = @(Invoke-UbuntuCapture @('docker','inspect','--format',$Format,$Container) $Code)
    Assert-Deployment ($lines.Count -eq 1) $Code
    return [string]$lines[0]
}

function Get-ProtectedRuntimeFingerprint {
    $protectedProjects = @(
        'class_archive_piwigo',
        'class-archive-immich-spike',
        'class_archive_private_full_v3_piwigo',
        'class_archive_private_full_v3_immich'
    )
    $parts = [Collections.Generic.List[string]]::new()
    foreach ($project in $protectedProjects) {
        $names = @(Invoke-UbuntuCapture @('docker','ps','-a','--filter',('label=com.docker.compose.project=' + $project),'--format','{{.Names}}') 'protected_runtime_unavailable' | Sort-Object -Unique)
        Assert-Deployment ($names.Count -gt 0) 'protected_runtime_topology_invalid'
        foreach ($name in $names) {
            $identity = Get-DockerInspectLine $name '{{.Id}}|{{.State.Running}}|{{.State.StartedAt}}|{{.RestartCount}}|{{index .Config.Labels "com.docker.compose.project"}}' 'protected_runtime_unavailable'
            Assert-Deployment ($identity -match ('\A[a-f0-9]{64}\|(true|false)\|.*\|' + [regex]::Escape($project) + '\z')) 'protected_runtime_identity_invalid'
            [void]$parts.Add($name + '=' + $identity)
        }
    }
    return (@($parts | Sort-Object) -join ';')
}

function Get-RestoreNonTargetFingerprint {
    $excluded = @($piwigoProject + '-piwigo-1', $immichProject + '-immich-web-compat-1')
    $names = @(Invoke-UbuntuCapture @('docker','ps','-a','--filter',('label=com.classarchive.scope=' + $scopeLabel),'--format','{{.Names}}') 'restore_deploy_topology_invalid' |
        Where-Object { $_ -notin $excluded } | Sort-Object -Unique)
    Assert-Deployment ($names.Count -ge 7) 'restore_deploy_topology_invalid'
    $parts = foreach ($name in $names) {
        $identity = Get-DockerInspectLine $name '{{.Id}}|{{.State.Running}}|{{.State.StartedAt}}|{{.RestartCount}}' 'restore_deploy_topology_invalid'
        $name + '=' + $identity
    }
    return ($parts -join ';')
}

function Assert-ProtectedHttp {
    foreach ($check in @(
        @('http://127.0.0.1:8090/identification.php',200),
        @('http://127.0.0.1:8091/photos',303),
        @('http://127.0.0.1:8190/identification.php',200),
        @('http://127.0.0.1:8191/home',303)
    )) {
        $status = 0
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $check[0] -MaximumRedirection 0 -TimeoutSec 15 -ErrorAction Stop
            $status = [int]$response.StatusCode
        }
        catch {
            if ($null -ne $_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        }
        Assert-Deployment ($status -eq [int]$check[1]) 'protected_runtime_http_invalid'
    }
}

function Get-RestoreVolumeSpecs {
    return @(
        @('class_archive_owner_restore_v1_piwigo_data',$piwigoProject,'piwigo_data'),
        @('class_archive_owner_restore_v1_piwigo_uploads',$piwigoProject,'piwigo_uploads'),
        @('class_archive_owner_restore_v1_piwigo_galleries',$piwigoProject,'piwigo_galleries'),
        @('class_archive_owner_restore_v1_piwigo_derivatives',$piwigoProject,'piwigo_derivatives'),
        @('class_archive_owner_restore_v1_piwigo_db',$piwigoProject,'piwigo_db'),
        @('class_archive_owner_restore_v1_piwigo_scripts',$piwigoProject,'piwigo_scripts'),
        @('class_archive_owner_restore_v1_piwigo_backups',$piwigoProject,'backups'),
        @('class_archive_owner_restore_v1_immich_upload',$immichProject,'immich_upload'),
        @('class_archive_owner_restore_v1_immich_model_cache',$immichProject,'immich_model_cache'),
        @('class_archive_owner_restore_v1_immich_db',$immichProject,'immich_db'),
        @('class_archive_owner_restore_v1_immich_gateway_secret',$immichProject,'immich_gateway_secret')
    )
}

function Assert-RestoreVolumeIdentities {
    foreach ($spec in Get-RestoreVolumeSpecs) {
        $name = [string]$spec[0]
        $expected = 'local|none|bind|' + $restoreVolumeRoot + '/' + $name + '|' + [string]$spec[1] + '|' + [string]$spec[2] + '|' + $scopeLabel + '|' + $storageLabel
        $lines = @(Invoke-UbuntuCapture @(
            'docker','volume','inspect','--format',
            '{{.Driver}}|{{index .Options "type"}}|{{index .Options "o"}}|{{index .Options "device"}}|{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.volume"}}|{{index .Labels "com.classarchive.scope"}}|{{index .Labels "com.classarchive.storage"}}',
            $name
        ) 'restore_deploy_volume_identity_invalid')
        Assert-Deployment ($lines.Count -eq 1 -and [string]$lines[0] -ceq $expected) 'restore_deploy_volume_identity_invalid'
    }
}

function Assert-RestoreContainer([string]$Name, [string]$Project, [bool]$MayBeExited = $false) {
    $identity = Get-DockerInspectLine $Name '{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.classarchive.scope"}}|{{.State.Running}}|{{.State.Status}}' 'restore_deploy_container_identity_invalid'
    $runningIdentity = $Project + '|' + $scopeLabel + '|true|running'
    $exitedIdentity = $Project + '|' + $scopeLabel + '|false|exited'
    $allowed = if ($MayBeExited) { @($runningIdentity, $exitedIdentity) } else { @($runningIdentity) }
    Assert-Deployment ($identity -in $allowed) 'restore_deploy_container_identity_invalid'
}

function Assert-RestoreTopology {
    $dockerRoot = @(Invoke-UbuntuCapture @('docker','info','--format','{{.DockerRootDir}}') 'restore_deploy_docker_root_invalid')
    Assert-Deployment ($dockerRoot.Count -eq 1 -and $dockerRoot[0] -ceq '/var/lib/docker') 'restore_deploy_docker_root_invalid'

    Read-IdentityEnvironment $piwigoEnvPath @{
        COMPOSE_PROJECT_NAME=$piwigoProject; CLASS_ARCHIVE_HTTP_PORT='8290'; CLASS_ARCHIVE_COMPAT_HTTP_PORT='8291';
        PIWIGO_DATA_VOLUME='class_archive_owner_restore_v1_piwigo_data'; PIWIGO_DB_VOLUME='class_archive_owner_restore_v1_piwigo_db';
        PIWIGO_BACKUPS_VOLUME='class_archive_owner_restore_v1_piwigo_backups';
        CLASS_ARCHIVE_RESTORE_NGINX_CONFIG='../.codex-work/owner-restore/runtime/nginx.conf'
    } 'restore_deploy_piwigo_env_identity_invalid'
    Read-IdentityEnvironment $immichEnvPath @{
        IMMICH_COMPOSE_PROJECT_NAME=$immichProject; CLASS_ARCHIVE_COMPAT_HTTP_PORT='8291'; CLASS_ARCHIVE_CORE_PUBLIC_PORT='8290';
        IMMICH_DB_VOLUME='class_archive_owner_restore_v1_immich_db'; IMMICH_GATEWAY_SECRET_VOLUME='class_archive_owner_restore_v1_immich_gateway_secret'
    } 'restore_deploy_immich_env_identity_invalid'

    Assert-RestoreVolumeIdentities
    Assert-RestoreContainer ($piwigoProject + '-db-1') $piwigoProject
    Assert-RestoreContainer ($piwigoProject + '-piwigo-1') $piwigoProject
    Assert-RestoreContainer ($immichProject + '-database-1') $immichProject
    Assert-RestoreContainer ($immichProject + '-redis-1') $immichProject
    Assert-RestoreContainer ($immichProject + '-immich-server-1') $immichProject
    Assert-RestoreContainer ($immichProject + '-immich-machine-learning-1') $immichProject
    Assert-RestoreContainer ($immichProject + '-immich-gateway-1') $immichProject
    Assert-RestoreContainer ($immichProject + '-immich-web-compat-1') $immichProject
    Assert-RestoreContainer ($immichProject + '-immich-gateway-secret-stager-1') $immichProject $true

    $ports = @(Invoke-UbuntuCapture @('docker','port',($piwigoProject + '-piwigo-1')) 'restore_deploy_ports_invalid' | Sort-Object)
    $expectedPorts = @('80/tcp -> 127.0.0.1:8290','8081/tcp -> 127.0.0.1:8291') | Sort-Object
    Assert-Deployment (@(Compare-Object $expectedPorts $ports).Count -eq 0) 'restore_deploy_ports_invalid'

    $published = @(Invoke-UbuntuCapture @('docker','ps','--filter',('label=com.classarchive.scope=' + $scopeLabel),'--format','{{.Names}}|{{.Ports}}') 'restore_deploy_ports_invalid') -join "`n"
    Assert-Deployment ($published -match '127\.0\.0\.1:8290->80/tcp' -and $published -match '127\.0\.0\.1:8291->8081/tcp') 'restore_deploy_ports_invalid'
    Assert-Deployment (-not ($published -match '0\.0\.0\.0|\[::\]|:2283->|:3000->|:8080->')) 'restore_deploy_internal_service_exposed'
}

function Wait-Maintenance {
    foreach ($attempt in 1..60) {
        $previous = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --cd $projectRoot -- @($script:piwigoCompose + @(
                'exec','-T','piwigo','curl','--silent','--show-error','--write-out','RESTORE_STATUS:%{http_code}','http://127.0.0.1/'
            )) 2>&1)
            $exit = $LASTEXITCODE
        }
        finally { $ErrorActionPreference = $previous }
        if ($exit -eq 0 -and $lines.Count -eq 2 -and [string]$lines[0] -ceq 'Class Archive maintenance mode.' -and [string]$lines[1] -ceq 'RESTORE_STATUS:503') { return }
        Start-Sleep -Seconds 1
    }
    Stop-Deployment 'restore_deploy_maintenance_not_ready'
}

function Assert-PiwigoStoppedForSnapshot {
    $identity = Get-DockerInspectLine ($piwigoProject + '-piwigo-1') '{{.State.Running}}|{{.State.Status}}' 'restore_deploy_writer_state_invalid'
    Assert-Deployment ($identity -ceq 'false|exited') 'restore_deploy_writer_not_stopped'
}

function Get-SnapshotRequirement {
    $lines = @(Invoke-ComposeCapture 'piwigo' @(
        'run','--rm',
        '-e',('CLASS_ARCHIVE_PRE_MIGRATION_FROM_VERSION=' + $migrationSourceVersion),
        '-e',('CLASS_ARCHIVE_PRE_MIGRATION_TO_VERSION=' + $migrationTargetVersion),
        '-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=probe',
        'pre-migration-db-backup'
    ) 'restore_deploy_schema_probe_failed')
    $required = 'PRE_MIGRATION_DB_SNAPSHOT=' + $migrationRequiredStatus + ' schema_current=15 schema_from=15 schema_to=16 scope=DB_ONLY media=NOT_INCLUDED'
    $current = 'PRE_MIGRATION_DB_SNAPSHOT=' + $migrationCurrentStatus + ' schema_current=16 schema_from=16 schema_to=16 scope=NONE media=NOT_INCLUDED'
    $records = @($lines | Where-Object { $_ -ceq $required -or $_ -ceq $current })
    Assert-Deployment ($records.Count -eq 1) 'restore_deploy_schema_probe_invalid'
    if ($records[0] -ceq $required) { return $migrationRequiredStatus }
    if ($records[0] -ceq $current) { return $migrationCurrentStatus }
    Stop-Deployment 'restore_deploy_schema_probe_invalid'
}

function Create-PreMigrationSnapshot {
    Invoke-Compose 'piwigo' @('stop','piwigo') 'restore_deploy_stop_writer_failed'
    Assert-PiwigoStoppedForSnapshot
    $lines = @(Invoke-ComposeCapture 'piwigo' @(
        'run','--rm',
        '-e',('CLASS_ARCHIVE_PRE_MIGRATION_FROM_VERSION=' + $migrationSourceVersion),
        '-e',('CLASS_ARCHIVE_PRE_MIGRATION_TO_VERSION=' + $migrationTargetVersion),
        '-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=snapshot',
        '-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM=true',
        'pre-migration-db-backup'
    ) 'restore_deploy_snapshot_failed')
    $pattern = '^PRE_MIGRATION_DB_SNAPSHOT=PASS bundle=pre-migration-db-v15-to-v16-[0-9]{8}T[0-9]{6}Z schema_from=15 schema_to=16 scope=DB_ONLY media=NOT_INCLUDED$'
    Assert-Deployment (@($lines | Where-Object { $_ -match $pattern }).Count -eq 1) 'restore_deploy_snapshot_evidence_invalid'
}

function Test-IgnoredPrivatePath([string]$Path, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Path)
    Assert-Deployment ($full.StartsWith($projectRoot.TrimEnd('\') + '\',[StringComparison]::OrdinalIgnoreCase)) $Code
    $relative = $full.Substring($projectRoot.TrimEnd('\').Length + 1).Replace('\','/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Deployment ($LASTEXITCODE -eq 0 -and @(& git -C $projectRoot ls-files -- $relative 2>$null).Count -eq 0) $Code
}

function Assert-RestoreGitEvidencePreflight {
    $ownerRestoreRoot = Split-Path -Parent $runtimeRoot
    Assert-PlainDirectory $ownerRestoreRoot 'restore_deploy_runtime_parent_invalid'
    Test-IgnoredPrivatePath $gitEvidenceHead 'restore_deploy_git_evidence_not_private'
    if (Test-Path -LiteralPath $runtimeRoot) {
        Assert-PlainDirectory $runtimeRoot 'restore_deploy_runtime_root_invalid'
    }
    if (Test-Path -LiteralPath $gitEvidenceRoot) {
        Assert-PlainDirectory $gitEvidenceRoot 'restore_deploy_git_evidence_root_invalid'
    }
    if (Test-Path -LiteralPath $gitEvidenceRefs) {
        Assert-PlainDirectory $gitEvidenceRefs 'restore_deploy_git_evidence_refs_invalid'
        Assert-Deployment (@(Get-ChildItem -LiteralPath $gitEvidenceRefs -Force).Count -eq 0) 'restore_deploy_git_evidence_refs_invalid'
    }
    if (Test-Path -LiteralPath $gitEvidenceHead) {
        Assert-PlainFile $gitEvidenceHead 'restore_deploy_git_evidence_head_invalid'
    }
}

function Initialize-RuntimeRootForLock {
    $ownerRestoreRoot = Split-Path -Parent $runtimeRoot
    Assert-PlainDirectory $ownerRestoreRoot 'restore_deploy_runtime_parent_invalid'
    Test-IgnoredPrivatePath $lockPath 'restore_deploy_lock_not_private'
    if (-not (Test-Path -LiteralPath $runtimeRoot)) { [void][IO.Directory]::CreateDirectory($runtimeRoot) }
    Assert-PlainDirectory $runtimeRoot 'restore_deploy_runtime_root_invalid'
    Set-OwnerOnlyDirectoryAcl $runtimeRoot
    if (-not (Test-Path -LiteralPath $lockPath)) {
        [IO.File]::WriteAllBytes($lockPath, [byte[]]::new(0))
    }
    Assert-PlainFile $lockPath 'restore_deploy_lock_file_invalid'
    Set-ClassArchiveOwnerOnlyFileAcl -Path $lockPath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $lockPath
}

function Get-RestoreNginxContent {
    $sourcePath = Join-Path $projectRoot 'infra\piwigo-nginx\nginx.conf'
    Assert-PlainFile $sourcePath 'restore_deploy_nginx_source_invalid'
    $source = [IO.File]::ReadAllText($sourcePath,[Text.Encoding]::UTF8)
    $anchor = '        set_real_ip_from 10.241.0.10/32;'
    Assert-Deployment (([regex]::Matches($source,[regex]::Escape($anchor))).Count -eq 1 -and
        -not $source.Contains('set_real_ip_from 10.245.0.10/32;')) 'restore_deploy_nginx_source_invalid'
    $generated = $source.Replace($anchor,$anchor + "`n        # Restore-only compatibility BFF on the isolated gateway.`n        set_real_ip_from 10.245.0.10/32;")
    Assert-Deployment (([regex]::Matches($generated,'set_real_ip_from 10\.245\.0\.10/32;')).Count -eq 1) 'restore_deploy_nginx_generation_invalid'
    return $generated
}

function Assert-RestoreNginxPreflight {
    [void](Get-RestoreNginxContent)
    Test-IgnoredPrivatePath $restoreNginxPath 'restore_deploy_nginx_not_private'
    if (Test-Path -LiteralPath $restoreNginxPath) {
        Assert-PlainFile $restoreNginxPath 'restore_deploy_nginx_target_invalid'
    }
}

function Initialize-RestoreGitEvidenceRoot {
    $ownerRestoreRoot = Split-Path -Parent $runtimeRoot
    Assert-PlainDirectory $ownerRestoreRoot 'restore_deploy_runtime_parent_invalid'
    if (-not (Test-Path -LiteralPath $runtimeRoot)) { [void][IO.Directory]::CreateDirectory($runtimeRoot) }
    Assert-PlainDirectory $runtimeRoot 'restore_deploy_runtime_root_invalid'
    Set-OwnerOnlyDirectoryAcl $runtimeRoot
    if (-not (Test-Path -LiteralPath $gitEvidenceRoot)) { [void][IO.Directory]::CreateDirectory($gitEvidenceRoot) }
    Assert-PlainDirectory $gitEvidenceRoot 'restore_deploy_git_evidence_root_invalid'
    Set-OwnerOnlyDirectoryAcl $gitEvidenceRoot
    if (-not (Test-Path -LiteralPath $gitEvidenceRefs)) { [void][IO.Directory]::CreateDirectory($gitEvidenceRefs) }
    Assert-PlainDirectory $gitEvidenceRefs 'restore_deploy_git_evidence_refs_invalid'
    Set-OwnerOnlyDirectoryAcl $gitEvidenceRefs
    Assert-Deployment (@(Get-ChildItem -LiteralPath $gitEvidenceRefs -Force).Count -eq 0) 'restore_deploy_git_evidence_refs_invalid'
    if (Test-Path -LiteralPath $gitEvidenceHead) { Set-ClassArchiveOwnerOnlyFileAcl -Path $gitEvidenceHead }
    Test-IgnoredPrivatePath $gitEvidenceHead 'restore_deploy_git_evidence_not_private'
}

function Initialize-RestoreNginxConfig {
    Assert-PlainDirectory $runtimeRoot 'restore_deploy_runtime_root_invalid'
    $content = Get-RestoreNginxContent
    if (Test-Path -LiteralPath $restoreNginxPath) {
        Assert-PlainFile $restoreNginxPath 'restore_deploy_nginx_target_invalid'
        if ([string]::Equals([IO.File]::ReadAllText($restoreNginxPath,[Text.Encoding]::UTF8),$content,[StringComparison]::Ordinal)) {
            Set-ClassArchiveOwnerOnlyFileAcl -Path $restoreNginxPath
            Test-IgnoredPrivatePath $restoreNginxPath 'restore_deploy_nginx_not_private'
            return
        }
    }
    $temporary = Join-Path $runtimeRoot ('nginx.' + [Guid]::NewGuid().ToString('N') + '.tmp')
    try {
        [IO.File]::WriteAllText($temporary,$content,[Text.UTF8Encoding]::new($false))
        Set-ClassArchiveOwnerOnlyFileAcl -Path $temporary
        if (Test-Path -LiteralPath $restoreNginxPath) {
            Assert-PlainFile $restoreNginxPath 'restore_deploy_nginx_target_invalid'
            [IO.File]::Replace($temporary,$restoreNginxPath,$null,$true)
        }
        else { Move-Item -LiteralPath $temporary -Destination $restoreNginxPath }
    }
    finally {
        if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force }
    }
    Set-ClassArchiveOwnerOnlyFileAcl -Path $restoreNginxPath
    Assert-Deployment ([string]::Equals([IO.File]::ReadAllText($restoreNginxPath,[Text.Encoding]::UTF8),$content,[StringComparison]::Ordinal)) 'restore_deploy_nginx_target_stale'
    Test-IgnoredPrivatePath $restoreNginxPath 'restore_deploy_nginx_not_private'
}

function Update-RestoreGitEvidence([string]$Head) {
    Assert-PlainDirectory $gitEvidenceRoot 'restore_deploy_git_evidence_root_invalid'
    Assert-PlainDirectory $gitEvidenceRefs 'restore_deploy_git_evidence_refs_invalid'
    Assert-Deployment (@(Get-ChildItem -LiteralPath $gitEvidenceRefs -Force).Count -eq 0) 'restore_deploy_git_evidence_refs_invalid'
    if (Test-Path -LiteralPath $gitEvidenceHead) {
        Assert-PlainFile $gitEvidenceHead 'restore_deploy_git_evidence_head_invalid'
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $gitEvidenceHead
    }
    # The restore container has already been removed, so there is no reader of
    # this single-file bind. DrvFS does not reliably support File.Replace for a
    # path that Docker previously bind-mounted. Use an exclusive, write-through
    # handle instead: a crash leaves invalid evidence and the next preflight
    # remains fail closed until this bounded step repairs it.
    $stream = $null
    try {
        $stream = [IO.FileStream]::new(
            $gitEvidenceHead,
            [IO.FileMode]::Create,
            [IO.FileAccess]::Write,
            [IO.FileShare]::None,
            4096,
            [IO.FileOptions]::WriteThrough
        )
        $bytes = [Text.Encoding]::ASCII.GetBytes($Head + "`n")
        $stream.Write($bytes,0,$bytes.Length)
        $stream.Flush($true)
    }
    finally {
        if ($null -ne $stream) { $stream.Dispose() }
    }
    Set-ClassArchiveOwnerOnlyFileAcl -Path $gitEvidenceHead
    Assert-Deployment ([string]::Equals([IO.File]::ReadAllText($gitEvidenceHead),($Head + "`n"),[StringComparison]::Ordinal)) 'restore_deploy_git_evidence_head_mismatch'
    Test-IgnoredPrivatePath $gitEvidenceHead 'restore_deploy_git_evidence_not_private'
}

function Recreate-RestorePiwigoUnderMaintenance([string]$Head) {
    # The attested HEAD is a read-only single-file bind. Windows correctly
    # prevents replacement while the container owns that bind. Stop only the
    # already maintenance-gated restore writer before advancing evidence.
    Invoke-Compose 'piwigo' @('stop','piwigo') 'restore_deploy_stop_for_evidence_failed'
    Assert-PiwigoStoppedForSnapshot
    # A stopped Docker container still retains the Windows single-file bind.
    # Remove only this stateless restore container (all durable state lives in
    # the attested volumes) so the host can exclusively update the evidence
    # path. Compose recreates the same scoped container immediately below.
    Invoke-Compose 'piwigo' @('rm','--force','--stop','piwigo') 'restore_deploy_remove_for_evidence_failed'
    $remaining = @(Invoke-UbuntuCapture @('docker','ps','-a','--filter',('name=^/' + $piwigoProject + '-piwigo-1$'),'--format','{{.Names}}') 'restore_deploy_remove_for_evidence_failed')
    Assert-Deployment ($remaining.Count -eq 0) 'restore_deploy_remove_for_evidence_failed'
    Update-RestoreGitEvidence $Head
    Invoke-Compose 'piwigo' @('up','-d','--force-recreate','--no-deps','piwigo') 'restore_deploy_piwigo_recreate_failed'
    Wait-Maintenance
    Invoke-Compose 'piwigo' @('exec','-T','--user','nginx','piwigo','php','/workspace/tests/phase1/php-fpm-ready.php') 'restore_deploy_php_fpm_invalid'
    Invoke-Compose 'piwigo' @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare') 'restore_deploy_maintenance_reassert_failed'
}

function Assert-RestoreWorkspaceMounts {
    $container = $piwigoProject + '-piwigo-1'
    $mounts = @(Invoke-UbuntuCapture @('docker','inspect','--format','{{range .Mounts}}{{printf "%s|%s\n" .Destination .Source}}{{end}}',$container) 'restore_deploy_workspace_mount_invalid')
    $rootWsl = @(Invoke-UbuntuCapture @('wslpath','-a',$projectRoot) 'restore_deploy_workspace_mount_invalid')
    Assert-Deployment ($rootWsl.Count -eq 1) 'restore_deploy_workspace_mount_invalid'
    foreach ($directory in @('infra','plugins','themes','tests')) {
        $expected = '/' + 'workspace/' + $directory + '|' + $rootWsl[0].TrimEnd('/') + '/' + $directory
        Assert-Deployment (@($mounts | Where-Object { $_ -ceq $expected }).Count -eq 1) 'restore_deploy_workspace_mount_invalid'
    }
    $head = @($mounts | Where-Object { $_ -like '/workspace/git/HEAD|*' })
    Assert-Deployment ($head.Count -eq 1) 'restore_deploy_git_evidence_mount_invalid'
}

function Assert-RestoreEndpointHealthy {
    $coreStatus = 0
    $compatStatus = 0
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8290/identification.php' -MaximumRedirection 0 -TimeoutSec 15 -ErrorAction Stop
        $coreStatus = [int]$response.StatusCode
    }
    catch { if ($null -ne $_.Exception.Response) { $coreStatus = [int]$_.Exception.Response.StatusCode } }
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8291/healthz' -MaximumRedirection 0 -TimeoutSec 15 -ErrorAction Stop
        $compatStatus = [int]$response.StatusCode
    }
    catch { if ($null -ne $_.Exception.Response) { $compatStatus = [int]$_.Exception.Response.StatusCode } }
    Assert-Deployment ($coreStatus -eq 200 -and $compatStatus -eq 200) 'restore_deploy_http_health_invalid'
}

function Write-DeploymentReport([System.Collections.IDictionary]$Record) {
    $directory = Split-Path -Parent $reportPath
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) { [void][IO.Directory]::CreateDirectory($directory) }
    Assert-PlainDirectory $directory 'restore_deploy_report_directory_invalid'
    $text = ($Record | ConvertTo-Json -Depth 5 -Compress) + "`n"
    [IO.File]::WriteAllText($reportPath, $text, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $reportPath
    $relative = [IO.Path]::GetFullPath($reportPath).Substring($projectRoot.TrimEnd('\').Length + 1).Replace('\','/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Deployment ($LASTEXITCODE -eq 0 -and @(& git -C $projectRoot ls-files -- $relative 2>$null).Count -eq 0) 'restore_deploy_report_not_private'
}

$lock = $null
$protectedBefore = ''
$restoreNonTargetBefore = ''
$sourceHead = ''
$snapshotEvidence = 'NOT_RUN'
try {
    Assert-SourceCheckout
    $sourceHead = Get-CheckoutHead
    $stage = 'runtime_identity'
    Assert-RestoreTopology
    Assert-RestoreGitEvidencePreflight
    Assert-RestoreNginxPreflight
    Assert-ProtectedHttp
    $protectedBefore = Get-ProtectedRuntimeFingerprint
    $restoreNonTargetBefore = Get-RestoreNonTargetFingerprint
    $schemaState = Get-SnapshotRequirement

    if ($Action -eq 'validate') {
        Assert-RestoreEndpointHealthy
        Assert-Deployment ([string]::Equals($protectedBefore,(Get-ProtectedRuntimeFingerprint),[StringComparison]::Ordinal)) 'protected_runtime_changed_during_validate'
        Write-Output ('OWNER_RESTORE_CLASS_PLUGINS=PASS action=validate endpoint=8290_8291 schema_state=' + $schemaState + ' scope=OWNER_RESTORE_ONLY durable_mutation=NONE transient_probe=DB_ONLY')
        exit 0
    }

    Assert-Deployment $ConfirmRestoreMigration.IsPresent 'restore_deploy_confirmation_required'
    $stage = 'workflow_lock'
    Initialize-RuntimeRootForLock
    $lock = Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath
    # The ACL is asserted before FileShare.None acquisition. Querying it after
    # acquisition would require opening the same file and fails correctly on
    # Windows, obscuring a successfully acquired exclusive mutex.
    Test-IgnoredPrivatePath $lockPath 'restore_deploy_lock_not_private'
    # Re-read exact identities after acquiring the migration lock. Validation
    # above is deliberately read-only and does not create a persistent lock.
    Assert-RestoreTopology
    Assert-Deployment ([string]::Equals($protectedBefore,(Get-ProtectedRuntimeFingerprint),[StringComparison]::Ordinal)) 'protected_runtime_changed_before_restore_deploy'
    Assert-Deployment ([string]::Equals($restoreNonTargetBefore,(Get-RestoreNonTargetFingerprint),[StringComparison]::Ordinal)) 'restore_non_target_service_changed'
    $stage = 'git_evidence_prepare'
    Initialize-RestoreGitEvidenceRoot
    Initialize-RestoreNginxConfig
    $stage = 'maintenance_prepare'
    Invoke-Compose 'piwigo' @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare') 'restore_deploy_maintenance_prepare_failed'
    Wait-Maintenance

    $stage = 'schema_gate'
    $schemaState = Get-SnapshotRequirement
    if ($schemaState -eq $migrationRequiredStatus) {
        $stage = 'pre_migration_snapshot'
        Create-PreMigrationSnapshot
        $snapshotEvidence = 'PASS_V15_TO_V16_DB_ONLY'
    }
    elseif ($schemaState -eq $migrationCurrentStatus) {
        $snapshotEvidence = 'NOT_REQUIRED_CURRENT_V16'
    }
    else { Stop-Deployment 'restore_deploy_schema_probe_invalid' }

    $stage = 'piwigo_recreate_current_source'
    Recreate-RestorePiwigoUnderMaintenance $sourceHead
    Assert-RestoreWorkspaceMounts

    $stage = 'plugin_migration'
    Invoke-Compose 'piwigo' @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-locked-piwigo-extensions.php') 'restore_deploy_locked_extensions_failed'
    Invoke-Compose 'piwigo' @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php') 'restore_deploy_plugin_install_failed'
    Invoke-Compose 'piwigo' @('exec','-T','--user','root','piwigo','/bin/ash','/workspace/infra/scripts/restore-piwigo-user-script.sh') 'restore_deploy_user_script_failed'
    Recreate-RestorePiwigoUnderMaintenance $sourceHead
    Assert-RestoreWorkspaceMounts
    Invoke-Compose 'piwigo' @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--verify-runtime') 'restore_deploy_runtime_verify_failed'
    Invoke-Compose 'piwigo' @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-locked-piwigo-extensions.php','--verify-only') 'restore_deploy_locked_extensions_verify_failed'
    Assert-Deployment ((Get-SnapshotRequirement) -eq $migrationCurrentStatus) 'restore_deploy_target_schema_not_current'

    $stage = 'projection_rebuild'
    Invoke-Compose 'piwigo' @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/rebuild-photo-read-projection.php','--scope=all','--json') 'restore_deploy_projection_rebuild_failed'

    $stage = 'compat_recreate'
    Invoke-Compose 'immich' @('up','-d','--wait','--wait-timeout','60','--force-recreate','--no-deps','immich-web-compat') 'restore_deploy_compat_recreate_failed'

    $stage = 'bounded_post_migration_verify'
    $postMigration = @(Invoke-ComposeCapture 'piwigo' @(
        'exec','-T','--user','nginx','-e','CLASS_ARCHIVE_OWNER_RESTORE_VERIFY=1','piwigo',
        'php','/workspace/infra/scripts/verify-owner-restore-post-migration.php'
    ) 'restore_deploy_post_migration_verify_failed')
    Assert-Deployment (@($postMigration | Where-Object { $_ -match '^OWNER_RESTORE_POST_MIGRATION=PASS schema=16 reconciliation=PASS checked_images=[0-9]+ ai=PASS open_jobs=0 derivatives=REBUILDABLE_NOT_REQUIRED$' }).Count -eq 1) 'restore_deploy_post_migration_evidence_invalid'

    $stage = 'maintenance_finalize'
    Invoke-Compose 'piwigo' @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--finalize-maintenance') 'restore_deploy_finalize_failed'
    Assert-RestoreEndpointHealthy

    $stage = 'post_verify'
    Assert-RestoreTopology
    Assert-RestoreWorkspaceMounts
    Assert-Deployment ((Get-SnapshotRequirement) -eq $migrationCurrentStatus) 'restore_deploy_target_schema_not_current'
    Assert-Deployment ([string]::Equals($protectedBefore,(Get-ProtectedRuntimeFingerprint),[StringComparison]::Ordinal)) 'protected_runtime_changed_during_restore_deploy'
    Assert-Deployment ([string]::Equals($restoreNonTargetBefore,(Get-RestoreNonTargetFingerprint),[StringComparison]::Ordinal)) 'restore_non_target_service_changed'
    Assert-ProtectedHttp
    Write-DeploymentReport ([ordered]@{
        result='PASS'; action='migrate'; source_head=$sourceHead; schema_from=$migrationSourceVersion; schema_to=$migrationTargetVersion;
        snapshot=$snapshotEvidence; endpoint='127.0.0.1:8290_8291'; scope='OWNER_RESTORE_ONLY'; protected_8091_8191='UNCHANGED';
        recreated=@('restore-piwigo','restore-immich-web-compat'); generated_at=(Get-Date).ToUniversalTime().ToString('o')
    })
    Write-Output ('OWNER_RESTORE_CLASS_PLUGINS=PASS action=migrate endpoint=8290_8291 schema_from=15 schema_to=16 snapshot=' + $snapshotEvidence + ' source_head=' + $sourceHead + ' piwigo=RESTORE_ONLY bff=RESTORE_COMPAT_ONLY projection=REBUILT reconciliation=PASS ai=PASS derivatives=REBUILDABLE_NOT_REQUIRED maintenance=FINALIZED protected_8091_8191=UNCHANGED idempotent=YES')
}
catch {
    $code = if ([string]$_.Exception.Message -match '\A[a-z0-9_]{1,96}\z') { [string]$_.Exception.Message } else { 'restore_deploy_failed' }
    try {
        if (-not [string]::IsNullOrWhiteSpace($protectedBefore)) {
            Assert-Deployment ([string]::Equals($protectedBefore,(Get-ProtectedRuntimeFingerprint),[StringComparison]::Ordinal)) 'protected_runtime_changed_during_restore_deploy'
        }
    }
    catch { $code = 'protected_runtime_changed_during_restore_deploy' }
    Write-Output ('OWNER_RESTORE_CLASS_PLUGINS=FAIL action=' + $Action + ' stage=' + $stage + ' code=' + $code + ' maintenance=FAIL_CLOSED')
    exit 2
}
finally {
    Exit-ClassArchivePluginWorkflowLock -Handle $lock
}
