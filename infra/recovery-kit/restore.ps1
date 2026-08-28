[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$BundlePath,
    [Parameter(Mandatory = $true)][string]$OutputSecretPath
)

# Portable envelope decoder copied verbatim into every v2 recovery kit. It
# never reads the Windows DPAPI envelope and never writes the recovery phrase
# or decrypted values to stdout. GnuPG performs all cryptography.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Stop-PortableRestore([string]$Code) { throw 'PORTABLE_RESTORE_STOP:' + $Code }

function Assert-PlainFile([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $item.Length -le 0) {
        Stop-PortableRestore $Code
    }
}

function Set-OwnerOnlyAcl([string]$Path) {
    if ($env:OS -ne 'Windows_NT') { return }
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $system = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administrators = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = [Security.AccessControl.FileSecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($sid in @($identity, $system, $administrators)) {
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            $sid, [Security.AccessControl.FileSystemRights]::FullControl,
            [Security.AccessControl.AccessControlType]::Allow
        ))
    }
    $static = [IO.File].GetMethod('SetAccessControl', [type[]]@([string], [Security.AccessControl.FileSecurity]))
    if ($null -ne $static) { [IO.File]::SetAccessControl($Path, $acl); return }
    if ($null -eq (Get-Command Set-Acl -CommandType Cmdlet -ErrorAction SilentlyContinue)) {
        Import-Module Microsoft.PowerShell.Security -ErrorAction Stop
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

$bundle = (Resolve-Path -LiteralPath $BundlePath).Path.TrimEnd('\', '/')
$bundleItem = Get-Item -LiteralPath $bundle -Force
if (-not $bundleItem.PSIsContainer -or ($bundleItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
    [IO.Path]::GetFileName($bundle) -notmatch '\Aowner-full-v2-[0-9]{8}T[0-9]{6}Z\z') {
    Stop-PortableRestore 'bundle_untrusted'
}
$backupId = [IO.Path]::GetFileName($bundle)
$kit = Join-Path $bundle 'recovery-kit'
$kitItem = Get-Item -LiteralPath $kit -Force -ErrorAction Stop
if (-not $kitItem.PSIsContainer -or ($kitItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    Stop-PortableRestore 'kit_untrusted'
}

$checksumPath = Join-Path $kit 'checksums.sha256'
Assert-PlainFile $checksumPath 'kit_checksums_missing'
$expectedFiles = @(
    'README-PORTABLE-RESTORE.txt','container-lock.json','manifest.json','migration-info.json',
    'ml-artifact-manifest.json','portable-key-envelope.gpg','restore.ps1','restore.sh'
) | Sort-Object
$actualFiles = @()
foreach ($line in [IO.File]::ReadAllLines($checksumPath)) {
    if ($line -notmatch '\A([0-9a-f]{64})  ([A-Za-z0-9._-]+)\z') { Stop-PortableRestore 'kit_checksums_invalid' }
    $name = [string]$Matches[2]
    $actualFiles += $name
    $path = Join-Path $kit $name
    Assert-PlainFile $path 'kit_payload_missing'
    $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -cne [string]$Matches[1]) { Stop-PortableRestore 'kit_sha256_mismatch' }
}
if (@(Compare-Object $expectedFiles @($actualFiles | Sort-Object)).Count -ne 0) {
    Stop-PortableRestore 'kit_inventory_invalid'
}

$output = [IO.Path]::GetFullPath($OutputSecretPath)
$outputParent = [IO.Directory]::GetParent($output)
if ($null -eq $outputParent -or -not $outputParent.Exists -or
    (Get-Item -LiteralPath $outputParent.FullName -Force).Attributes -band [IO.FileAttributes]::ReparsePoint -or
    $output.StartsWith($bundle + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or
    (Test-Path -LiteralPath $output)) {
    Stop-PortableRestore 'secret_output_untrusted'
}

if ($null -eq $Host -or $null -eq $Host.UI -or $null -eq $Host.UI.PSObject.Methods['ReadLineAsSecureString']) {
    Stop-PortableRestore 'secure_user_secret_entry_unavailable'
}
$Host.UI.Write('Enter portable recovery phrase (hidden): ')
$phrase = $Host.UI.ReadLineAsSecureString()
if ($null -eq $phrase) { Stop-PortableRestore 'secure_user_secret_entry_unavailable' }

$temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) ('class-archive-portable-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporaryRoot | Out-Null
$phrasePath = Join-Path $temporaryRoot 'portable-recovery-passphrase.txt'
$pointer = [IntPtr]::Zero
try {
    $stream = [IO.File]::Open($phrasePath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($phrase)
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
        if ($plain.Contains("`r") -or $plain.Contains("`n") -or $plain.Contains([char]0)) {
            Stop-PortableRestore 'portable_phrase_invalid'
        }
        $bytes = [Text.UTF8Encoding]::new($false).GetBytes($plain + "`n")
        try { $stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true) }
        finally { [Array]::Clear($bytes, 0, $bytes.Length) }
    }
    finally { $stream.Dispose() }
    Set-OwnerOnlyAcl $phrasePath
    $empty = [IO.File]::Open($output, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $empty.Dispose()
    Set-OwnerOnlyAcl $output

    $gpg = Get-Command gpg, gpg.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $gpg) { Stop-PortableRestore 'gpg_missing' }
    & $gpg.Source --batch --yes --no-tty --pinentry-mode loopback --passphrase-file $phrasePath `
        --decrypt --output $output (Join-Path $kit 'portable-key-envelope.gpg') 2>$null
    if ($LASTEXITCODE -ne 0) { Stop-PortableRestore 'envelope_decrypt_failed' }
    Assert-PlainFile $output 'decrypted_payload_invalid'
    try { $payload = Get-Content -LiteralPath $output -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-PortableRestore 'decrypted_payload_invalid' }
    $required = @('anonymous_pseudonym_secret','claim_code_pepper','gpg_passphrase','piwigo_db_password') | Sort-Object
    $actual = @($payload.secrets.PSObject.Properties.Name | Sort-Object)
    if ($payload.format -ne 'owner-portable-recovery-secrets-v1' -or [int]$payload.version -ne 1 -or
        $payload.backup_id -ne $backupId -or $payload.scope -ne 'OWNER_PRIVATE_FULL' -or
        @(Compare-Object $required $actual).Count -ne 0) {
        Stop-PortableRestore 'decrypted_payload_contract_invalid'
    }
    Write-Output ('PORTABLE_RECOVERY_DECRYPT=PASS backup_id=' + $backupId + ' dpapi_used=NO')
}
catch {
    if (Test-Path -LiteralPath $output -PathType Leaf) { Remove-Item -LiteralPath $output -Force }
    throw
}
finally {
    $plain = $null
    $payload = $null
    if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    if ($phrase -is [IDisposable]) { $phrase.Dispose() }
    if (Test-Path -LiteralPath $phrasePath -PathType Leaf) { Remove-Item -LiteralPath $phrasePath -Force }
    if ((Test-Path -LiteralPath $temporaryRoot -PathType Container) -and @(Get-ChildItem -LiteralPath $temporaryRoot -Force).Count -eq 0) {
        Remove-Item -LiteralPath $temporaryRoot -Force
    }
}
