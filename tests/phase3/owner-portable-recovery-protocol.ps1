[CmdletBinding()]
param()

# Synthetic-only GnuPG protocol gate. It never reads owner configuration,
# Docker, source photos, or a recovery target and never writes outside the
# ignored repository work root.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'infra\scripts\owner-temporary-backup.ps1'
$libraryPath = Join-Path $projectRoot 'infra\scripts\owner-portable-recovery.ps1'
$helperPath = Join-Path $projectRoot 'infra\scripts\owner-portable-recovery-helper.sh'
$kitRoot = Join-Path $projectRoot 'infra\recovery-kit'
$aclPath = Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

foreach ($path in @($runnerPath,$libraryPath,$helperPath,$aclPath,
    (Join-Path $kitRoot 'restore.ps1'),(Join-Path $kitRoot 'restore.sh'),
    (Join-Path $kitRoot 'README-PORTABLE-RESTORE.txt'))) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('portable_file_missing_' + [IO.Path]::GetFileName($path))
}
$runner = [IO.File]::ReadAllText($runnerPath)
$library = [IO.File]::ReadAllText($libraryPath)
$helper = [IO.File]::ReadAllText($helperPath)
$restorePs = [IO.File]::ReadAllText((Join-Path $kitRoot 'restore.ps1'))
$restoreSh = [IO.File]::ReadAllText((Join-Path $kitRoot 'restore.sh'))

foreach ($needle in @(
    '[switch]$CreatePortableRecoveryEnvelope',
    "'verify-portable'",
    "'owner-full-recovery-v2'",
    "'owner-full-v2-' + `$stamp",
    "'WINDOWS_DPAPI_CURRENT_USER_PLUS_PORTABLE_GPG_ENVELOPE'",
    "'DPAPI_OR_OWNER_HELD_PORTABLE_RECOVERY_PHRASE'",
    "'owner-portable-recovery-secrets-v1'",
    "'recovery-kit/portable-key-envelope.gpg'",
    "'recovery-kit/checksums.sha256'",
    'Invoke-VerifyPortableBundle -Bundle $published -PortablePhrase $portablePhrase',
    'dpapi_used=NO'
)) { Assert-True ($runner.Contains($needle)) ('portable_runner_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'ReadLineAsSecureString',
    'portable_phrase_confirmation_mismatch',
    'portable_phrase_strength_invalid',
    'GNU_GPG_ITERATED_SALTED_S2K_SHA512',
    's2k_count = 65011712',
    'depends_on_windows_profile = $false',
    'dpapi_required = $false',
    'foreach ($path in @($payloadPath, $phrasePath, $envelopePath))',
    'Remove-Item -LiteralPath $path -Force'
)) { Assert-True ($library.Contains($needle)) ('portable_library_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    '--cipher-algo AES256','--s2k-mode 3','--s2k-digest-algo SHA512','--s2k-count 65011712',
    '--compress-algo none','umask 077','OWNER_PORTABLE_RECOVERY_HELPER=PASS'
)) { Assert-True ($helper.Contains($needle)) ('portable_helper_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($text in @($runner,$library,$helper,$restorePs,$restoreSh)) {
    Assert-True (-not ($text -match '(?i)Write-(?:Output|Host).*\$(?:plain|phrase|passphrase|secret|payload)')) 'portable_secret_output_detected'
    Assert-True (-not ($text -match '(?i)(?:PASSPHRASE|RECOVERY_PHRASE|SECRET)=\$')) 'portable_secret_environment_assignment_detected'
}
Assert-True (-not $library.Contains('ConvertFrom-SecureString -SecureString')) 'portable_library_must_not_use_dpapi'
Assert-True ($restorePs.Contains('dpapi_used=NO') -and -not $restorePs.Contains('ConvertFrom-SecureString')) 'portable_restore_powershell_dpapi_boundary_invalid'
Assert-True ($restoreSh.Contains('dpapi_used=NO') -and $restoreSh.Contains('IFS= read -r -s')) 'portable_restore_shell_noecho_boundary_invalid'

[void][ScriptBlock]::Create($runner)
[void][ScriptBlock]::Create($library)
[void][ScriptBlock]::Create($restorePs)
$helperWsl = '/mnt/' + $helperPath.Substring(0,1).ToLowerInvariant() + '/' + $helperPath.Substring(3).Replace('\','/')
$restoreShWsl = '/mnt/' + (Join-Path $kitRoot 'restore.sh').Substring(0,1).ToLowerInvariant() + '/' + (Join-Path $kitRoot 'restore.sh').Substring(3).Replace('\','/')
& $wsl -d Ubuntu --exec bash -n $helperWsl
Assert-True ($LASTEXITCODE -eq 0) 'portable_helper_parse_failed'
& $wsl -d Ubuntu --exec bash -n $restoreShWsl
Assert-True ($LASTEXITCODE -eq 0) 'portable_restore_shell_parse_failed'

. $aclPath
. $libraryPath

function New-SyntheticSecret([int]$Bytes = 48) {
    $buffer = New-Object byte[] $Bytes
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    try { return ([Convert]::ToBase64String($buffer).TrimEnd('=').Replace('+','-').Replace('/','_')) }
    finally { [Array]::Clear($buffer, 0, $buffer.Length) }
}

$runtimeParent = Join-Path $projectRoot '.codex-work\private-real-full\runtime\owner-temporary-backup'
$backupId = 'owner-full-v2-20990101T010203Z'
$testRoot = Join-Path $runtimeParent ($backupId + '-protocol-' + [Guid]::NewGuid().ToString('N'))
$expectedPrefix = (Join-Path $projectRoot '.codex-work').TrimEnd('\') + '\'
$resolvedCandidate = [IO.Path]::GetFullPath($testRoot)
Assert-True ($resolvedCandidate.StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase)) 'portable_test_root_outside_workspace'
$destination = Join-Path $testRoot 'published-portable-key-envelope.gpg'
$phrase = ConvertTo-SecureString ((New-SyntheticSecret 48) + '!Aa1') -AsPlainText -Force
$wrongPhrase = ConvertTo-SecureString ((New-SyntheticSecret 48) + '!Bb2') -AsPlainText -Force
$archivePassphrase = New-SyntheticSecret 64
$ownerSecrets = @{
    DB_PASSWORD = New-SyntheticSecret
    CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET = New-SyntheticSecret
    CLASS_ARCHIVE_CLAIM_CODE_PEPPER = New-SyntheticSecret
}
try {
    New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
    $metadata = New-ClassArchivePortableRecoveryEnvelope -BackupId $backupId -SecretRoot $testRoot `
        -DestinationPath $destination -ArchivePassphrase $archivePassphrase -OwnerSecrets $ownerSecrets `
        -PortablePhrase $phrase -Wsl $wsl -HelperPath $helperPath
    Assert-True (Test-Path -LiteralPath $destination -PathType Leaf) 'portable_synthetic_envelope_missing'
    Assert-True ($metadata.protection -eq 'GPG_SYMMETRIC_AES256' -and $metadata.dpapi_required -eq $false) 'portable_synthetic_metadata_invalid'
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $testRoot 'portable-secret-payload.json'))) 'portable_plaintext_payload_not_removed'
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $testRoot 'portable-recovery-passphrase.txt'))) 'portable_phrase_file_not_removed'

    $values = Read-ClassArchivePortableRecoveryEnvelope -BackupId $backupId -EnvelopePath $destination `
        -SecretRoot $testRoot -PortablePhrase $phrase -Wsl $wsl -HelperPath $helperPath
    Assert-True ($values.gpg_passphrase -ceq $archivePassphrase) 'portable_archive_key_roundtrip_failed'
    Assert-True ($values.piwigo_db_password -ceq $ownerSecrets.DB_PASSWORD) 'portable_database_secret_roundtrip_failed'
    Assert-True ($values.anonymous_pseudonym_secret -ceq $ownerSecrets.CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET) 'portable_pseudonym_secret_roundtrip_failed'
    Assert-True ($values.claim_code_pepper -ceq $ownerSecrets.CLASS_ARCHIVE_CLAIM_CODE_PEPPER) 'portable_claim_secret_roundtrip_failed'
    $values = $null

    $wrongRejected = $false
    try {
        [void](Read-ClassArchivePortableRecoveryEnvelope -BackupId $backupId -EnvelopePath $destination `
            -SecretRoot $testRoot -PortablePhrase $wrongPhrase -Wsl $wsl -HelperPath $helperPath)
    }
    catch { $wrongRejected = $_.Exception.Message -match 'envelope_decrypt_failed' }
    Assert-True $wrongRejected 'portable_wrong_phrase_not_rejected'
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $testRoot 'portable-secret-payload.json'))) 'portable_failed_decrypt_plaintext_residue'
}
finally {
    $archivePassphrase = $null
    $ownerSecrets = $null
    if ($phrase -is [IDisposable]) { $phrase.Dispose() }
    if ($wrongPhrase -is [IDisposable]) { $wrongPhrase.Dispose() }
    if (Test-Path -LiteralPath $testRoot -PathType Container) {
        $resolved = (Resolve-Path -LiteralPath $testRoot).Path
        if (-not $resolved.StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase)) { throw 'portable_test_cleanup_boundary_invalid' }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}

Write-Output "OWNER_PORTABLE_RECOVERY_PROTOCOL=PASS assertions=$assertions gpg_roundtrip=PASS dpapi_used=NO"
