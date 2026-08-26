Set-StrictMode -Version Latest

# Windows PowerShell 5.1 exposes the file ACL API through System.IO.File.
# Prefer that API because explicitly importing Microsoft.PowerShell.Security
# in a redirected NoProfile child can fail on duplicate ObjectSecurity type
# data before Get-Acl / Set-Acl become callable. PowerShell 7 does not expose
# those static methods, so it uses the normal Security module cmdlets instead.
# Both paths fail closed when neither supported ACL backend is available.
function Test-ClassArchiveStaticAclApiAvailable {
    $getMethod = [System.IO.File].GetMethod(
        'GetAccessControl',
        [type[]]@([string])
    )
    $setMethod = [System.IO.File].GetMethod(
        'SetAccessControl',
        [type[]]@([string], [Security.AccessControl.FileSecurity])
    )
    return $null -ne $getMethod -and $null -ne $setMethod
}

function Test-ClassArchiveAclCmdletsAvailable {
    $setAcl = Get-Command Set-Acl -CommandType Cmdlet -ErrorAction SilentlyContinue
    $getAcl = Get-Command Get-Acl -CommandType Cmdlet -ErrorAction SilentlyContinue
    return $null -ne $setAcl -and $null -ne $getAcl
}

if (-not (Test-ClassArchiveStaticAclApiAvailable) -and -not (Test-ClassArchiveAclCmdletsAvailable)) {
    try {
        Import-Module Microsoft.PowerShell.Security -ErrorAction Stop
    }
    catch {
        if (-not (Test-ClassArchiveAclCmdletsAvailable)) {
            throw
        }
    }
}
if (-not (Test-ClassArchiveStaticAclApiAvailable) -and -not (Test-ClassArchiveAclCmdletsAvailable)) {
    throw 'No supported Windows file ACL backend is available.'
}

function Get-ClassArchiveFileSecurity {
    param([Parameter(Mandatory = $true)][string]$Path)

    if (Test-ClassArchiveStaticAclApiAvailable) {
        return [System.IO.File]::GetAccessControl($Path)
    }
    return Get-Acl -LiteralPath $Path
}

function Set-ClassArchiveFileSecurity {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][Security.AccessControl.FileSecurity]$AclObject
    )

    if (Test-ClassArchiveStaticAclApiAvailable) {
        [System.IO.File]::SetAccessControl($Path, $AclObject)
        return
    }
    Set-Acl -LiteralPath $Path -AclObject $AclObject
}

function Set-ClassArchiveOwnerOnlyFileAcl {
    param([Parameter(Mandatory = $true)][string]$Path)

    $resolved = (Resolve-Path -LiteralPath $Path).Path
    try {
        # Avoid rewriting an already exact descriptor. On some Windows builds,
        # reapplying an identical owner-bearing FileSecurity object can request
        # SeSecurityPrivilege even though no ACL change is needed.
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
        return
    }
    catch {
        # Continue with the exact descriptor replacement below.
    }
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    if ($null -eq $identity) {
        throw 'Cannot resolve the current Windows security identifier.'
    }

    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = [Security.AccessControl.FileSecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($sid in @($identity, $systemSid, $administratorsSid)) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            [Security.AccessControl.AccessControlType]::Allow
        )
        [void]$acl.AddAccessRule($rule)
    }
    Set-ClassArchiveFileSecurity -Path $resolved -AclObject $acl
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
}

function Assert-ClassArchiveOwnerOnlyFileAcl {
    param([Parameter(Mandatory = $true)][string]$Path)

    $resolved = (Resolve-Path -LiteralPath $Path).Path
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = Get-ClassArchiveFileSecurity -Path $resolved
    $ownerSid = try {
        ([Security.Principal.NTAccount]$acl.Owner).Translate([Security.Principal.SecurityIdentifier])
    }
    catch {
        [Security.Principal.SecurityIdentifier]$acl.Owner
    }
    $rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    $expectedSids = @($identity.Value, $systemSid.Value, $administratorsSid.Value) | Sort-Object
    $actualSids = @($rules | ForEach-Object { $_.IdentityReference.Value }) | Sort-Object
    $allRulesExact = @($rules | Where-Object {
        $_.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow `
            -or ($_.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -ne [Security.AccessControl.FileSystemRights]::FullControl `
            -or $_.IsInherited
    }).Count -eq 0
    $sidDifferenceCount = @(Compare-Object -ReferenceObject $expectedSids -DifferenceObject $actualSids).Count
    $isRestricted = $acl.AreAccessRulesProtected `
        -and $null -ne $identity `
        -and $ownerSid -eq $identity `
        -and $rules.Count -eq 3 `
        -and $sidDifferenceCount -eq 0 `
        -and $allRulesExact
    if (-not $isRestricted) {
        throw "Secret file ACL is not restricted to owner, SYSTEM and Administrators: $resolved"
    }
}
