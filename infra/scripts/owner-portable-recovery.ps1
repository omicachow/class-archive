Set-StrictMode -Version Latest

# Shared v2 portable-envelope primitives. This file only defines functions and
# is dot-sourced by owner-temporary-backup.ps1. It uses mature GnuPG symmetric
# encryption and its iterated salted S2K; it does not implement cryptography.

function Stop-ClassArchivePortableRecovery([string]$Code) {
    throw [InvalidOperationException]::new('OWNER_PORTABLE_RECOVERY_STOP:' + $Code)
}

function ConvertFrom-ClassArchiveSecureString([Security.SecureString]$Value) {
    if ($null -eq $Value) { Stop-ClassArchivePortableRecovery 'secure_phrase_missing' }
    $pointer = [IntPtr]::Zero
    try {
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    finally {
        if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    }
}

function Test-ClassArchiveFixedTextEqual([string]$Left, [string]$Right) {
    if ($Left.Length -ne $Right.Length) { return $false }
    $difference = 0
    for ($index = 0; $index -lt $Left.Length; $index++) {
        $difference = $difference -bor (([int][char]$Left[$index]) -bxor ([int][char]$Right[$index]))
    }
    return $difference -eq 0
}

function Assert-ClassArchivePortablePhraseStrength([Security.SecureString]$Value) {
    $plain = ConvertFrom-ClassArchiveSecureString $Value
    try {
        if ($plain.Length -lt 20 -or $plain.Length -gt 512 -or $plain.Contains("`r") -or
            $plain.Contains("`n") -or $plain.Contains([char]0)) {
            Stop-ClassArchivePortableRecovery 'portable_phrase_strength_invalid'
        }
        $classes = 0
        foreach ($pattern in @('[a-z]', '[A-Z]', '[0-9]', '[^A-Za-z0-9]')) {
            if ($plain -match $pattern) { $classes++ }
        }
        if ($classes -lt 3 -and $plain.Length -lt 32) {
            Stop-ClassArchivePortableRecovery 'portable_phrase_strength_invalid'
        }
        $normalized = $plain.ToLowerInvariant()
        foreach ($weak in @('password', 'qwerty', '123456', 'letmein', 'classarchive', '班级相册')) {
            if ($normalized.Contains($weak)) { Stop-ClassArchivePortableRecovery 'portable_phrase_strength_invalid' }
        }
        $userName = [string][Environment]::UserName
        if ($userName.Length -ge 3 -and $normalized.Contains($userName.ToLowerInvariant())) {
            Stop-ClassArchivePortableRecovery 'portable_phrase_strength_invalid'
        }
    }
    finally { $plain = $null }
}

function Read-ClassArchivePortableRecoveryPhrase {
    if ($null -eq $Host -or $null -eq $Host.UI -or
        $null -eq $Host.UI.PSObject.Methods['ReadLineAsSecureString']) {
        Stop-ClassArchivePortableRecovery 'secure_user_secret_entry_unavailable'
    }
    try {
        $Host.UI.Write('Enter portable recovery phrase (hidden): ')
        $first = $Host.UI.ReadLineAsSecureString()
        $Host.UI.Write('Confirm portable recovery phrase (hidden): ')
        $second = $Host.UI.ReadLineAsSecureString()
    }
    catch { Stop-ClassArchivePortableRecovery 'secure_user_secret_entry_unavailable' }
    if ($null -eq $first -or $null -eq $second) {
        if ($first -is [IDisposable]) { $first.Dispose() }
        if ($second -is [IDisposable]) { $second.Dispose() }
        Stop-ClassArchivePortableRecovery 'secure_user_secret_entry_unavailable'
    }
    $left = ConvertFrom-ClassArchiveSecureString $first
    $right = ConvertFrom-ClassArchiveSecureString $second
    try {
        if (-not (Test-ClassArchiveFixedTextEqual $left $right)) {
            Stop-ClassArchivePortableRecovery 'portable_phrase_confirmation_mismatch'
        }
        Assert-ClassArchivePortablePhraseStrength $first
    }
    catch {
        if ($first -is [IDisposable]) { $first.Dispose() }
        throw
    }
    finally {
        $left = $null
        $right = $null
        if ($second -is [IDisposable]) { $second.Dispose() }
    }
    return $first
}

function Write-ClassArchiveOwnerOnlyUtf8Secret {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Value
    )
    if (Test-Path -LiteralPath $Path) { Stop-ClassArchivePortableRecovery 'secret_output_exists' }
    $stream = [IO.File]::Open($Path, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $bytes = [Text.UTF8Encoding]::new($false).GetBytes($Value)
        try { $stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true) }
        finally { [Array]::Clear($bytes, 0, $bytes.Length) }
    }
    finally { $stream.Dispose() }
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function Write-ClassArchiveSecurePhraseFile {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][Security.SecureString]$Value
    )
    $plain = ConvertFrom-ClassArchiveSecureString $Value
    try { Write-ClassArchiveOwnerOnlyUtf8Secret -Path $Path -Value ($plain + "`n") }
    finally { $plain = $null }
}

function ConvertTo-ClassArchiveWslPath([string]$Wsl, [string]$Path) {
    $lines = @(& $Wsl -d Ubuntu --exec wslpath -a $Path 2>$null)
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 1 -or [string]$lines[0] -notmatch '\A/(?:mnt/[a-z]|tmp)/') {
        Stop-ClassArchivePortableRecovery 'wsl_path_conversion_failed'
    }
    return [string]$lines[0]
}

function Invoke-ClassArchivePortableHelper {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('encrypt','decrypt')][string]$Action,
        [Parameter(Mandatory = $true)][string]$Wsl,
        [Parameter(Mandatory = $true)][string]$HelperPath,
        [Parameter(Mandatory = $true)][string]$WorkRoot,
        [Parameter(Mandatory = $true)][string]$InputPath,
        [Parameter(Mandatory = $true)][string]$PassphrasePath,
        [Parameter(Mandatory = $true)][string]$OutputPath
    )
    $helperWsl = ConvertTo-ClassArchiveWslPath $Wsl $HelperPath
    $arguments = @(
        $Action,
        '--work-root', (ConvertTo-ClassArchiveWslPath $Wsl $WorkRoot),
        '--input', (ConvertTo-ClassArchiveWslPath $Wsl $InputPath),
        '--passphrase-file', (ConvertTo-ClassArchiveWslPath $Wsl $PassphrasePath),
        '--output', (ConvertTo-ClassArchiveWslPath $Wsl $OutputPath)
    )
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& $Wsl -d Ubuntu --exec bash $helperWsl @arguments 2>&1 | ForEach-Object { [string]$_ })
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) {
        $safe = @($lines | Where-Object { $_ -match '\AOWNER_PORTABLE_RECOVERY_HELPER=FAIL code=[a-z0-9_]{1,128}\z' }) | Select-Object -Last 1
        if ($null -ne $safe) { throw [InvalidOperationException]::new([string]$safe) }
        Stop-ClassArchivePortableRecovery 'portable_helper_failed'
    }
    if (($lines -join "`n") -ne ('OWNER_PORTABLE_RECOVERY_HELPER=PASS action=' + $Action)) {
        Stop-ClassArchivePortableRecovery 'portable_helper_output_unsafe'
    }
}

function New-ClassArchivePortableRecoveryEnvelope {
    param(
        [Parameter(Mandatory = $true)][string]$BackupId,
        [Parameter(Mandatory = $true)][string]$SecretRoot,
        [Parameter(Mandatory = $true)][string]$DestinationPath,
        [Parameter(Mandatory = $true)][string]$ArchivePassphrase,
        [Parameter(Mandatory = $true)][hashtable]$OwnerSecrets,
        [Parameter(Mandatory = $true)][Security.SecureString]$PortablePhrase,
        [Parameter(Mandatory = $true)][string]$Wsl,
        [Parameter(Mandatory = $true)][string]$HelperPath
    )
    if ($BackupId -notmatch '\Aowner-full-v2-[0-9]{8}T[0-9]{6}Z\z') {
        Stop-ClassArchivePortableRecovery 'backup_id_invalid'
    }
    Assert-ClassArchivePortablePhraseStrength $PortablePhrase
    foreach ($name in @('DB_PASSWORD','CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET','CLASS_ARCHIVE_CLAIM_CODE_PEPPER')) {
        if (-not $OwnerSecrets.ContainsKey($name)) { Stop-ClassArchivePortableRecovery 'required_recovery_secret_missing' }
    }
    if (-not (Test-Path -LiteralPath $SecretRoot)) { New-Item -ItemType Directory -Path $SecretRoot -Force | Out-Null }
    $rootItem = Get-Item -LiteralPath $SecretRoot -Force
    if (-not $rootItem.PSIsContainer -or ($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-ClassArchivePortableRecovery 'secret_root_untrusted'
    }
    if (Test-Path -LiteralPath $DestinationPath) { Stop-ClassArchivePortableRecovery 'portable_envelope_exists' }
    $payloadPath = Join-Path $SecretRoot 'portable-secret-payload.json'
    $phrasePath = Join-Path $SecretRoot 'portable-recovery-passphrase.txt'
    $envelopePath = Join-Path $SecretRoot 'portable-key-envelope.gpg'
    try {
        $payload = [ordered]@{
            format = 'owner-portable-recovery-secrets-v1'
            version = 1
            backup_id = $BackupId
            scope = 'OWNER_PRIVATE_FULL'
            created_at = (Get-Date).ToUniversalTime().ToString('o', [Globalization.CultureInfo]::InvariantCulture)
            secrets = [ordered]@{
                gpg_passphrase = $ArchivePassphrase
                piwigo_db_password = [string]$OwnerSecrets.DB_PASSWORD
                anonymous_pseudonym_secret = [string]$OwnerSecrets.CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET
                claim_code_pepper = [string]$OwnerSecrets.CLASS_ARCHIVE_CLAIM_CODE_PEPPER
            }
        }
        $payloadJson = $payload | ConvertTo-Json -Depth 8 -Compress
        Write-ClassArchiveOwnerOnlyUtf8Secret -Path $payloadPath -Value ($payloadJson + "`n")
        Write-ClassArchiveSecurePhraseFile -Path $phrasePath -Value $PortablePhrase
        Invoke-ClassArchivePortableHelper -Action encrypt -Wsl $Wsl -HelperPath $HelperPath -WorkRoot $SecretRoot `
            -InputPath $payloadPath -PassphrasePath $phrasePath -OutputPath $envelopePath
        [IO.File]::Copy($envelopePath, $DestinationPath, $false)
        $item = Get-Item -LiteralPath $DestinationPath -Force
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $item.Length -le 0) {
            Stop-ClassArchivePortableRecovery 'portable_envelope_invalid'
        }
        return [ordered]@{
            payload_format = 'owner-portable-recovery-secrets-v1'
            protection = 'GPG_SYMMETRIC_AES256'
            cipher = 'AES256'
            kdf = 'GNU_GPG_ITERATED_SALTED_S2K_SHA512'
            s2k_mode = 3
            s2k_count = 65011712
            depends_on_windows_profile = $false
            dpapi_required = $false
        }
    }
    finally {
        $payloadJson = $null
        $payload = $null
        foreach ($path in @($payloadPath, $phrasePath, $envelopePath)) {
            if (Test-Path -LiteralPath $path -PathType Leaf) { Remove-Item -LiteralPath $path -Force }
        }
    }
}

function Read-ClassArchivePortableRecoveryEnvelope {
    param(
        [Parameter(Mandatory = $true)][string]$BackupId,
        [Parameter(Mandatory = $true)][string]$EnvelopePath,
        [Parameter(Mandatory = $true)][string]$SecretRoot,
        [Parameter(Mandatory = $true)][Security.SecureString]$PortablePhrase,
        [Parameter(Mandatory = $true)][string]$Wsl,
        [Parameter(Mandatory = $true)][string]$HelperPath
    )
    if ($BackupId -notmatch '\Aowner-full-v2-[0-9]{8}T[0-9]{6}Z\z') {
        Stop-ClassArchivePortableRecovery 'backup_id_invalid'
    }
    $sourceItem = Get-Item -LiteralPath $EnvelopePath -Force -ErrorAction Stop
    if ($sourceItem.PSIsContainer -or ($sourceItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $sourceItem.Length -le 0) {
        Stop-ClassArchivePortableRecovery 'portable_envelope_untrusted'
    }
    if (-not (Test-Path -LiteralPath $SecretRoot)) { New-Item -ItemType Directory -Path $SecretRoot -Force | Out-Null }
    $rootItem = Get-Item -LiteralPath $SecretRoot -Force
    if (-not $rootItem.PSIsContainer -or ($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-ClassArchivePortableRecovery 'secret_root_untrusted'
    }
    $localEnvelope = Join-Path $SecretRoot 'portable-key-envelope.gpg'
    $phrasePath = Join-Path $SecretRoot 'portable-recovery-passphrase.txt'
    $payloadPath = Join-Path $SecretRoot 'portable-secret-payload.json'
    try {
        [IO.File]::Copy($EnvelopePath, $localEnvelope, $false)
        Write-ClassArchiveSecurePhraseFile -Path $phrasePath -Value $PortablePhrase
        $empty = [IO.File]::Open($payloadPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        $empty.Dispose()
        Set-ClassArchiveOwnerOnlyFileAcl -Path $payloadPath
        Invoke-ClassArchivePortableHelper -Action decrypt -Wsl $Wsl -HelperPath $HelperPath -WorkRoot $SecretRoot `
            -InputPath $localEnvelope -PassphrasePath $phrasePath -OutputPath $payloadPath
        try { $payload = Get-Content -LiteralPath $payloadPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
        catch { Stop-ClassArchivePortableRecovery 'portable_payload_invalid' }
        $actualKeys = @($payload.secrets.PSObject.Properties.Name | Sort-Object)
        $requiredKeys = @('anonymous_pseudonym_secret','claim_code_pepper','gpg_passphrase','piwigo_db_password') | Sort-Object
        if ($payload.format -ne 'owner-portable-recovery-secrets-v1' -or [int]$payload.version -ne 1 -or
            $payload.backup_id -ne $BackupId -or $payload.scope -ne 'OWNER_PRIVATE_FULL' -or
            @(Compare-Object $requiredKeys $actualKeys).Count -ne 0) {
            Stop-ClassArchivePortableRecovery 'portable_payload_contract_invalid'
        }
        $result = @{}
        foreach ($name in $requiredKeys) {
            $value = [string]$payload.secrets.$name
            if ($value.Length -lt 32 -or $value.Length -gt 512 -or $value.Contains("`r") -or
                $value.Contains("`n") -or $value.Contains([char]0)) {
                Stop-ClassArchivePortableRecovery 'portable_payload_secret_invalid'
            }
            $result[$name] = $value
        }
        return $result
    }
    finally {
        $payload = $null
        foreach ($path in @($payloadPath, $phrasePath, $localEnvelope)) {
            if (Test-Path -LiteralPath $path -PathType Leaf) { Remove-Item -LiteralPath $path -Force }
        }
    }
}
