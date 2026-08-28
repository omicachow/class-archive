[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('prepare', 'verify', 'compose-validate')]
    [string]$Action = 'verify',

    # The inventory and audit are ignored owner-local artifacts. They are
    # consumed only by the Windows preparation process and never mounted into
    # Docker. Prepare requires an explicit source-read acknowledgement.
    [string]$InventoryPath,
    [string]$AuditPath,
    [switch]$ConfirmPrivateSourceRead,

    # Public source intentionally keeps defaults generic. For an existing
    # private library, owner-local ignored input must supply the already stored
    # display labels; a mismatch fails closed before any import is attempted.
    [string]$CollectionLabelA = '来源集合 A',
    [string]$CollectionLabelB = '来源集合 B',

    # Both paths must remain below the ignored supplemental work root on C:.
    # The manifest path is derived from OutputPath and cannot be overridden.
    [string]$OutputPath,
    [string]$StagingPath,
    [string]$PiwigoOwnerEnvPath
)

# Owner-local preparation boundary for the small remaining-photo closure.
# This script intentionally has no import/apply/run action. Only the host-side
# converter can read ignored source inventory; Docker config receives two
# already-verified, read-only, path-free inputs and is never started here.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateQaRoot = Join-Path $projectRoot '.codex-work\private-real-qa'
$supplementalRoot = Join-Path $privateQaRoot 'supplemental'
$pythonTool = Join-Path $PSScriptRoot 'private-real-supplemental.py'
$composeOverlayRelative = 'infra/private-full/docker-compose.supplemental-ingress.override.yml'
$composeOverlay = Join-Path $projectRoot ($composeOverlayRelative -replace '/', '\')
$ownerEnvRelative = 'infra/private-full/.env.piwigo.owner'
$script:stage = 'initialization'
$script:assertions = 0

if ([string]::IsNullOrWhiteSpace($InventoryPath)) {
    $InventoryPath = Join-Path $privateQaRoot 'inventory\real-data-inventory.json'
}
if ([string]::IsNullOrWhiteSpace($AuditPath)) {
    $AuditPath = Join-Path $privateQaRoot 'reports\unimported-images.json'
}
if ([string]::IsNullOrWhiteSpace($OutputPath)) { $OutputPath = $supplementalRoot }
if ([string]::IsNullOrWhiteSpace($StagingPath)) { $StagingPath = Join-Path $supplementalRoot 'staging' }
if ([string]::IsNullOrWhiteSpace($PiwigoOwnerEnvPath)) {
    $PiwigoOwnerEnvPath = Join-Path $projectRoot ($ownerEnvRelative -replace '/', '\')
}

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-PrivateSupplemental([string]$Code) {
    if ($Code -notmatch '^[a-z0-9_]{1,96}$') { $Code = 'private_supplemental_failed' }
    Write-Output "PRIVATE_REAL_SUPPLEMENTAL_OPERATOR=FAIL action=$Action stage=$script:stage code=$Code"
    exit 2
}

function Assert-Operator([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw $Code }
}

function Get-FullPath([string]$Path, [string]$Code) {
    try { return [IO.Path]::GetFullPath($Path) } catch { throw $Code }
}

function Assert-PathBelow([string]$Path, [string]$Root, [string]$Code, [switch]$AllowRoot) {
    $full = Get-FullPath $Path $Code
    $fullRoot = (Get-FullPath $Root $Code).TrimEnd('\', '/')
    $prefix = $fullRoot + [IO.Path]::DirectorySeparatorChar
    $valid = $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase) `
        -or ($AllowRoot.IsPresent -and $full.Equals($fullRoot, [StringComparison]::OrdinalIgnoreCase))
    Assert-Operator $valid $Code
    return $full
}

function Assert-NoReparseComponents([string]$Path, [bool]$AllowMissing, [string]$Code) {
    $full = Get-FullPath $Path $Code
    $root = [IO.Path]::GetPathRoot($full)
    Assert-Operator (-not [string]::IsNullOrWhiteSpace($root)) $Code
    $current = $root
    $relative = $full.Substring($root.Length)
    foreach ($component in @($relative -split '[\\/]' | Where-Object { $_ -ne '' })) {
        $current = Join-Path $current $component
        if (-not (Test-Path -LiteralPath $current)) {
            Assert-Operator $AllowMissing $Code
            break
        }
        $item = Get-Item -LiteralPath $current -Force -ErrorAction Stop
        Assert-Operator (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) $Code
    }
    return $full
}

function Get-ProjectRelative([string]$Path, [string]$Code) {
    $full = Assert-PathBelow $Path $projectRoot $Code
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredUntracked([string]$Path, [string]$Code) {
    $relative = Get-ProjectRelative $Path ($Code + '_outside_checkout')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Operator ($LASTEXITCODE -eq 0) ($Code + '_not_ignored')
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    Assert-Operator ($LASTEXITCODE -eq 0 -and $tracked.Count -eq 0) ($Code + '_tracked')
}

function Assert-PrivateLeaf([string]$Path, [string]$Code, [switch]$ProtectAcl) {
    $full = Assert-PathBelow $Path $privateQaRoot ($Code + '_outside_private_qa')
    [void](Assert-NoReparseComponents $full $false ($Code + '_reparse'))
    $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
    Assert-Operator (-not $item.PSIsContainer) ($Code + '_not_file')
    Assert-IgnoredUntracked $full $Code
    if ($ProtectAcl.IsPresent) { Set-ClassArchiveOwnerOnlyFileAcl -Path $full }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $full
    $script:assertions++
    return $full
}

function Set-OwnerOnlyDirectoryAcl([string]$Path) {
    $resolved = (Resolve-Path -LiteralPath $Path -ErrorAction Stop).Path
    $item = Get-Item -LiteralPath $resolved -Force -ErrorAction Stop
    Assert-Operator ($item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'private_directory_untrusted'
    try {
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
        return
    }
    catch {
        # Replace inheritance with an explicit owner/SYSTEM/Administrators
        # descriptor so subsequently-created private files inherit no broad
        # user or authenticated-user access.
    }
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    Assert-Operator ($null -ne $identity) 'private_acl_identity_unavailable'
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    foreach ($sid in @($identity, $systemSid, $administratorsSid)) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $resolved -AclObject $acl
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
    $script:assertions++
}

function Ensure-PrivateDirectory([string]$Path, [string]$Code) {
    $full = Assert-PathBelow $Path $supplementalRoot ($Code + '_outside_supplemental_root') -AllowRoot
    [void](Assert-NoReparseComponents $full $true ($Code + '_reparse'))
    if (-not (Test-Path -LiteralPath $full)) {
        [void](New-Item -ItemType Directory -Path $full -Force -ErrorAction Stop)
    }
    [void](Assert-NoReparseComponents $full $false ($Code + '_reparse'))
    Set-OwnerOnlyDirectoryAcl $full
    Assert-IgnoredUntracked $full $Code
    return $full
}

function Resolve-PrivateDirectory([string]$Path, [string]$Code, [switch]$AllowRoot) {
    $full = Assert-PathBelow $Path $supplementalRoot ($Code + '_outside_supplemental_root') -AllowRoot:$AllowRoot.IsPresent
    [void](Assert-NoReparseComponents $full $false ($Code + '_reparse'))
    $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
    Assert-Operator ($item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) ($Code + '_not_directory')
    Assert-IgnoredUntracked $full $Code
    return $full
}

function Get-PrivateTreeItems([string]$Path) {
    # Enumerate one directory at a time. Get-ChildItem -Recurse has changed
    # junction traversal behavior across PowerShell versions; rejecting every
    # child before enqueueing it keeps the private tree boundary deterministic.
    $pending = [Collections.Generic.Queue[string]]::new()
    $items = [Collections.Generic.List[IO.FileSystemInfo]]::new()
    $pending.Enqueue($Path)
    while ($pending.Count -gt 0) {
        $directory = $pending.Dequeue()
        foreach ($item in @(Get-ChildItem -LiteralPath $directory -Force -ErrorAction Stop)) {
            Assert-Operator (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'private_tree_reparse'
            $items.Add($item)
            Assert-Operator ($items.Count -le 10000) 'private_tree_item_limit_exceeded'
            if ($item.PSIsContainer) { $pending.Enqueue($item.FullName) }
        }
    }
    return $items.ToArray()
}

function Protect-PrivateTree([string]$Path) {
    [void](Assert-NoReparseComponents $Path $false 'private_tree_reparse')
    $root = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Operator $root.PSIsContainer 'private_tree_not_directory'
    $all = @(Get-PrivateTreeItems $Path)
    foreach ($item in @($all | Where-Object { $_.PSIsContainer })) {
        Assert-Operator (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'private_tree_reparse'
        Set-OwnerOnlyDirectoryAcl $item.FullName
        Assert-IgnoredUntracked $item.FullName 'private_tree_directory'
    }
    foreach ($item in @($all | Where-Object { -not $_.PSIsContainer })) {
        Assert-Operator (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'private_tree_reparse'
        Set-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
        $script:assertions++
        Assert-IgnoredUntracked $item.FullName 'private_tree_file'
    }
    Set-OwnerOnlyDirectoryAcl $Path
    Assert-IgnoredUntracked $Path 'private_tree_root'
}

function Assert-PrivateTreeAcl([string]$Path) {
    [void](Assert-NoReparseComponents $Path $false 'private_tree_reparse')
    $root = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Operator $root.PSIsContainer 'private_tree_not_directory'
    foreach ($item in @($root) + @(Get-PrivateTreeItems $Path)) {
        Assert-Operator (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'private_tree_reparse'
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
        $script:assertions++
        Assert-IgnoredUntracked $item.FullName 'private_tree_artifact'
    }
}

function Assert-PrivateLabel([string]$Value, [string]$Code) {
    if ($null -eq $Value) { throw $Code }
    $label = $Value.Trim()
    $invalid = [string]::IsNullOrWhiteSpace($label) -or $label.Length -gt 190 `
        -or $label.Contains('/') -or $label.Contains([string][char]92) `
        -or $label.Contains([string][char]0) -or $label -match '^[A-Za-z]:' `
        -or $label -match '[\x00-\x1F\x7F]'
    Assert-Operator (-not $invalid) $Code
    return $label
}

function Resolve-Python {
    $candidate = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($null -eq $candidate) { $candidate = Get-Command python -ErrorAction SilentlyContinue }
    Assert-Operator ($null -ne $candidate) 'python_unavailable'
    return [string]$candidate.Source
}

function Invoke-SupplementalPython([string[]]$Arguments, [string]$Pattern, [string]$Code) {
    $python = Resolve-Python
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $captured = @(& $python $pythonTool @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    Assert-Operator ($exitCode -eq 0) ($Code + '_failed')
    $safe = @($captured | Where-Object { [string]$_ -match $Pattern })
    Assert-Operator ($safe.Count -eq 1) ($Code + '_result_invalid')
    $match = [regex]::Match([string]$safe[0], $Pattern)
    Assert-Operator $match.Success ($Code + '_result_invalid')
    return @{ sources = [int]$match.Groups['sources'].Value; presentations = [int]$match.Groups['presentations'].Value }
}

function Invoke-VerifiedPrepare([string]$Output, [string]$Staging) {
    Assert-Operator $ConfirmPrivateSourceRead.IsPresent 'prepare_confirmation_required'
    $inventory = Assert-PrivateLeaf $InventoryPath 'inventory' -ProtectAcl
    $audit = Assert-PrivateLeaf $AuditPath 'audit' -ProtectAcl
    $labelA = Assert-PrivateLabel $CollectionLabelA 'collection_label_a_invalid'
    $labelB = Assert-PrivateLabel $CollectionLabelB 'collection_label_b_invalid'
    $script:stage = 'prepare_runtime'
    $result = Invoke-SupplementalPython @(
        'prepare', '--inventory', $inventory, '--audit', $audit,
        '--output', $Output, '--staging', $Staging,
        '--collection-label', ('PRIVATE_SOURCE_A=' + $labelA),
        '--collection-label', ('PRIVATE_SOURCE_B=' + $labelB)
    ) '^PRIVATE_REAL_SUPPLEMENTAL_PREPARE=PASS sources=(?<sources>[0-9]+) presentations=(?<presentations>[0-9]+) source_integrity=PASS$' 'prepare'
    $script:stage = 'protect_output'
    Protect-PrivateTree $Output
    Assert-PrivateTreeAcl $Output
    return $result
}

function Invoke-VerifiedCheck([string]$Output, [string]$Staging) {
    $script:stage = 'verify_acl'
    Assert-PrivateTreeAcl $Output
    $script:stage = 'verify_runtime'
    return Invoke-SupplementalPython @('verify', '--output', $Output, '--staging', $Staging) `
        '^PRIVATE_REAL_SUPPLEMENTAL_VERIFY=PASS sources=(?<sources>[0-9]+) presentations=(?<presentations>[0-9]+)$' 'verify'
}

function Get-WslPath([string]$Path, [string]$Code) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $captured = @(& wsl.exe -d Ubuntu --exec wslpath -a $Path 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    Assert-Operator ($exitCode -eq 0 -and $captured.Count -eq 1 -and [string]$captured[0] -match '^/mnt/c/') $Code
    return [string]$captured[0]
}

function Get-Property([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Assert-ComposeBoundary([string]$Output, [string]$Staging) {
    $result = Invoke-VerifiedCheck $Output $Staging
    $manifest = Assert-PrivateLeaf (Join-Path $Output 'manifests\supplemental-import-manifest.json') 'runtime_manifest'
    $envItem = Get-Item -LiteralPath $PiwigoOwnerEnvPath -Force -ErrorAction Stop
    Assert-Operator (-not $envItem.PSIsContainer -and -not ($envItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'owner_env_untrusted'
    $envRelative = Get-ProjectRelative $envItem.FullName 'owner_env_outside_checkout'
    Assert-Operator ($envRelative -ceq $ownerEnvRelative) 'owner_env_identity_invalid'
    Assert-IgnoredUntracked $envItem.FullName 'owner_env'
    [void](Assert-NoReparseComponents $envItem.FullName $false 'owner_env_reparse')
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $envItem.FullName
    $script:assertions++
    Assert-Operator (Test-Path -LiteralPath $composeOverlay -PathType Leaf) 'compose_overlay_missing'
    $manifestWsl = Get-WslPath $manifest 'manifest_wsl_path_invalid'
    $stagingWsl = Get-WslPath $Staging 'staging_wsl_path_invalid'
    Assert-Operator (-not $manifestWsl.StartsWith('/mnt/m/', [StringComparison]::OrdinalIgnoreCase)) 'source_mount_forbidden'
    Assert-Operator (-not $stagingWsl.StartsWith('/mnt/m/', [StringComparison]::OrdinalIgnoreCase)) 'source_mount_forbidden'

    $script:stage = 'compose_config'
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $captured = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec env `
            ('PRIVATE_SUPPLEMENTAL_MANIFEST_PATH=' + $manifestWsl) `
            ('PRIVATE_SUPPLEMENTAL_STAGING_PATH=' + $stagingWsl) `
            docker compose --env-file $ownerEnvRelative -f $composeOverlayRelative `
            -p class_archive_private_supplemental_ingress --profile private-supplemental `
            config --format json 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    Assert-Operator ($exitCode -eq 0 -and $captured.Count -gt 0) 'compose_config_failed'
    try { $config = [string]::Join("`n", $captured) | ConvertFrom-Json -ErrorAction Stop } catch { throw 'compose_config_invalid' }
    Assert-Operator ([string](Get-Property $config 'name') -ceq 'class_archive_private_supplemental_ingress') 'compose_project_identity_invalid'
    $services = Get-Property $config 'services'
    $serviceProperties = @($services.PSObject.Properties)
    Assert-Operator ($serviceProperties.Count -eq 1 -and $serviceProperties[0].Name -ceq 'supplemental-ingress-audit') 'compose_service_set_invalid'
    $service = $serviceProperties[0].Value
    Assert-Operator ([string](Get-Property $service 'network_mode') -ceq 'none') 'compose_network_not_disabled'
    Assert-Operator ((Get-Property $service 'read_only') -eq $true) 'compose_root_not_read_only'
    $ports = Get-Property $service 'ports'
    $expose = Get-Property $service 'expose'
    Assert-Operator ($null -eq $ports -or @($ports).Count -eq 0) 'compose_host_port_forbidden'
    Assert-Operator ($null -eq $expose -or @($expose).Count -eq 0) 'compose_expose_forbidden'
    Assert-Operator ($null -eq (Get-Property $service 'build')) 'compose_build_forbidden'
    Assert-Operator ([string](Get-Property $service 'pull_policy') -ceq 'never') 'compose_pull_policy_invalid'
    Assert-Operator ([string](Get-Property $service 'restart') -ceq 'no') 'compose_restart_policy_invalid'
    Assert-Operator ([string](Get-Property $service 'user') -match '^[0-9]+:[0-9]+$') 'compose_user_invalid'
    $profiles = @((Get-Property $service 'profiles'))
    Assert-Operator ($profiles.Count -eq 1 -and [string]$profiles[0] -ceq 'private-supplemental') 'compose_profile_invalid'
    Assert-Operator (@((Get-Property $service 'cap_drop')) -contains 'ALL') 'compose_cap_drop_missing'
    Assert-Operator (@((Get-Property $service 'security_opt')) -contains 'no-new-privileges:true') 'compose_no_new_privileges_missing'
    $environment = Get-Property $service 'environment'
    $environmentProperties = @($environment.PSObject.Properties)
    Assert-Operator ($environmentProperties.Count -eq 1 -and $environmentProperties[0].Name -ceq 'CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL' `
        -and [string]$environmentProperties[0].Value -ceq '1') 'compose_environment_not_minimal'
    $labels = Get-Property $service 'labels'
    Assert-Operator ([string](Get-Property $labels 'com.classarchive.scope') -ceq 'private-real-supplemental-ingress' `
        -and [string](Get-Property $labels 'com.classarchive.operation') -ceq 'verify-only') 'compose_labels_invalid'

    $volumes = @((Get-Property $service 'volumes'))
    Assert-Operator ($volumes.Count -eq 2) 'compose_mount_count_invalid'
    $expected = @{
        '/private-real-full/manifests/supplemental-import-manifest.json' = $manifestWsl
        '/private-real-full/staging' = $stagingWsl
    }
    foreach ($volume in $volumes) {
        $target = [string](Get-Property $volume 'target')
        Assert-Operator ($expected.ContainsKey($target)) 'compose_mount_target_invalid'
        Assert-Operator ([string](Get-Property $volume 'type') -ceq 'bind') 'compose_mount_type_invalid'
        Assert-Operator ([string](Get-Property $volume 'source') -ceq [string]$expected[$target]) 'compose_mount_source_invalid'
        Assert-Operator ((Get-Property $volume 'read_only') -eq $true) 'compose_mount_not_read_only'
        $bind = Get-Property $volume 'bind'
        Assert-Operator ((Get-Property $bind 'create_host_path') -eq $false) 'compose_mount_may_autocreate'
    }
    $serialized = $config | ConvertTo-Json -Depth 30 -Compress
    foreach ($forbidden in @('full-real-import-manifest.json', 'FULL_REAL_STAGING_PATH', '/mnt/m/', 'source_root', 'relative_source_path')) {
        Assert-Operator ($serialized.IndexOf($forbidden, [StringComparison]::OrdinalIgnoreCase) -lt 0) 'compose_legacy_or_source_mount_detected'
    }
    return $result
}

try {
    $script:stage = 'path_preflight'
    [void](Assert-PathBelow $OutputPath $supplementalRoot 'output_path_invalid' -AllowRoot)
    [void](Assert-PathBelow $StagingPath $supplementalRoot 'staging_path_invalid')
    if ($Action -eq 'prepare') {
        Assert-Operator $ConfirmPrivateSourceRead.IsPresent 'prepare_confirmation_required'
    }
    if ($Action -eq 'prepare') {
        $output = Ensure-PrivateDirectory $OutputPath 'output'
        $staging = Ensure-PrivateDirectory $StagingPath 'staging'
    }
    else {
        # Verify and compose-validate are observational: they never create or
        # repair a missing private tree and never normalize an existing ACL.
        $output = Resolve-PrivateDirectory $OutputPath 'output' -AllowRoot
        $staging = Resolve-PrivateDirectory $StagingPath 'staging'
    }
    Assert-Operator (-not $output.Equals($staging, [StringComparison]::OrdinalIgnoreCase)) 'output_staging_identity_invalid'
    [void](Assert-PathBelow $staging $output 'staging_must_be_below_output')

    $result = switch ($Action) {
        'prepare' { Invoke-VerifiedPrepare $output $staging }
        'verify' { Invoke-VerifiedCheck $output $staging }
        'compose-validate' { Assert-ComposeBoundary $output $staging }
    }
    Write-Output ('PRIVATE_REAL_SUPPLEMENTAL_OPERATOR=PASS action=' + $Action `
        + ' sources=' + [string]$result.sources + ' presentations=' + [string]$result.presentations `
        + ' artifact_acl=OWNER_ONLY git=IGNORED_UNTRACKED source_paths=NOT_PRINTED docker=NOT_STARTED assertions=' + [string]$script:assertions)
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^[a-z0-9_]{1,96}$') { $message } else { 'private_supplemental_failed' }
    Stop-PrivateSupplemental $code
}
