Set-StrictMode -Version Latest

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
    Set-Acl -LiteralPath $resolved -AclObject $acl
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
}

function Assert-ClassArchiveOwnerOnlyFileAcl {
    param([Parameter(Mandatory = $true)][string]$Path)

    $resolved = (Resolve-Path -LiteralPath $Path).Path
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = Get-Acl -LiteralPath $resolved
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
