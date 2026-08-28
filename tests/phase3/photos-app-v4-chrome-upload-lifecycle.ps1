[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$CredentialFile
)

# Synthetic-only, mutating Chrome Stable lifecycle. This wrapper never starts
# or stops Docker, never touches 8191, and removes only opaque UUID/SHA-256
# records returned by the member-upload response or the exact pending lookup.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$workRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work'))
$separator = [IO.Path]::DirectorySeparatorChar
. (Join-Path $projectRoot 'infra\scripts\v4-synthetic-phase-a-lease.ps1')
$compose = @('-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml')

function Assert-ChildPath([string]$Base, [string]$Target, [string]$Code) {
    $relative = [IO.Path]::GetRelativePath($Base, $Target)
    if ([string]::IsNullOrWhiteSpace($relative) -or $relative -eq '..' -or $relative.StartsWith('..' + $separator, [StringComparison]::Ordinal) -or [IO.Path]::IsPathRooted($relative)) { throw $Code }
}
function Assert-IgnoredUntracked([string]$Path, [string]$Code) {
    Assert-ChildPath $projectRoot $Path $Code
    $relative = $Path.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { throw $Code }
}
function New-RunId {
    $bytes = New-Object byte[] 8; $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}
function Get-JsonOutput([string[]]$Arguments, [string]$Code) {
    $prior = $ErrorActionPreference
    try { $ErrorActionPreference = 'Continue'; $lines = @(& wsl.exe @($compose + $Arguments) 2>&1); $exit = $LASTEXITCODE }
    finally { $ErrorActionPreference = $prior }
    if ($exit -ne 0) { throw $Code }
    $json = @($lines | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^\{.*\}$' })
    if ($json.Count -ne 1) { throw $Code }
    try { return ($json[0] | ConvertFrom-Json -ErrorAction Stop) } catch { throw $Code }
}
function Invoke-UploadFixture([string[]]$Arguments) {
    $command = @('exec', '-T', '--user', 'nginx', '-e', 'CLASS_ARCHIVE_V4_UPLOAD_LIFECYCLE=1', 'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-upload-lifecycle-fixture.php') + $Arguments
    return Get-JsonOutput -Arguments $command -Code 'v4_upload_fixture_failed'
}
function Invoke-SyntheticMutationLock([string]$Action, [string]$Run) {
    $result = Get-JsonOutput @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_SCOPE_UNKNOWN_FIXTURE=1','piwigo','php','/workspace/tests/phase3/photos-app-v4-scope-unknown-fixture.php',$Action,$Run) 'v4_upload_mutation_lock_failed'
    if ($Action -eq 'lock' -and $result.locked -ne $true) { throw 'v4_upload_mutation_lock_invalid' }
    if ($Action -eq 'unlock' -and $result.unlocked -ne $true) { throw 'v4_upload_mutation_unlock_invalid' }
    return $result
}
function Assert-Baseline([string]$Code) {
    $state = Invoke-UploadFixture @('baseline')
    if ([int]$state.images -ne 72 -or [int]$state.active_canonical -ne 72 -or [int]$state.physical_originals -ne 72 -or [int]$state.multi_album_images -ne 8) { throw $Code }
}
function Get-Sha256([string]$Path) { return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() }
function Get-Crc32([byte[]]$Bytes) {
    [uint32]$crc = 0xffffffff
    foreach ($byte in $Bytes) {
        $crc = $crc -bxor [uint32]$byte
        for ($i = 0; $i -lt 8; $i++) { if (($crc -band 1) -ne 0) { $crc = ([uint32]($crc -shr 1)) -bxor [uint32]0xedb88320 } else { $crc = [uint32]($crc -shr 1) } }
    }
    return [uint32](-bnot $crc)
}
function New-SyntheticPng([string]$Path, [string]$Marker) {
    # One valid PNG, with an ancillary tEXt chunk before IEND. The random
    # marker changes bytes/checksum without producing visible private data.
    $base = [Convert]::FromBase64String('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
    $prefix = $base[0..($base.Length - 13)]
    $iend = $base[($base.Length - 12)..($base.Length - 1)]
    $type = [Text.Encoding]::ASCII.GetBytes('tEXt')
    $data = [Text.Encoding]::UTF8.GetBytes("class_archive_fixture`0$Marker")
    $payload = New-Object byte[] ($type.Length + $data.Length); [Array]::Copy($type, 0, $payload, 0, $type.Length); [Array]::Copy($data, 0, $payload, $type.Length, $data.Length)
    $length = [BitConverter]::GetBytes([uint32]$data.Length); if ([BitConverter]::IsLittleEndian) { [Array]::Reverse($length) }
    $crc = [BitConverter]::GetBytes((Get-Crc32 $payload)); if ([BitConverter]::IsLittleEndian) { [Array]::Reverse($crc) }
    $stream = [IO.MemoryStream]::new(); try { $stream.Write($prefix,0,$prefix.Length); $stream.Write($length,0,4); $stream.Write($payload,0,$payload.Length); $stream.Write($crc,0,4); $stream.Write($iend,0,$iend.Length); [IO.File]::WriteAllBytes($Path,$stream.ToArray()) } finally { $stream.Dispose() }
}
function Read-Journal([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $null }
    try { return ([IO.File]::ReadAllText($Path, [Text.UTF8Encoding]::new($false,$true)) | ConvertFrom-Json -ErrorAction Stop) } catch { throw 'v4_upload_journal_invalid' }
}

$credentialPath = (Resolve-Path -LiteralPath $CredentialFile).Path
Assert-ChildPath $workRoot $credentialPath 'v4_upload_credential_outside_work_root'
Assert-IgnoredUntracked $credentialPath 'v4_upload_credential_not_private'
. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
$userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
$deps = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies'
$node = Join-Path $deps 'node\bin\node.exe'
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'v4_upload_node_unavailable' }

$run = New-RunId
$root = [IO.Path]::GetFullPath((Join-Path $projectRoot ('.codex-work\runtime\phase3-upload-lifecycle\' + $run)))
$fixtures = Join-Path $root 'fixtures'; $journal = Join-Path $root 'result.json'
$profile = [IO.Path]::GetFullPath((Join-Path $projectRoot ('.codex-work\browser\photos-app-v4-chrome-upload-lifecycle\' + $run)))
$fileMap = @{}
$names = @('classmate-heritage.png','classmate-living.png','teacher-heritage.png','teacher-living.png','family-tampered-living.png','family-heritage.png')
$result = $null; $cleanupFailure = $null; $mutationLockAttempted = $false
$phaseAMutationLease = $null
try {
    $phaseAMutationLease = Enter-V4SyntheticPhaseAMutationLease -ProjectRoot $projectRoot -Purpose 'upload-lifecycle'
    foreach ($directory in @($root, $fixtures, $profile)) {
        Assert-ChildPath $workRoot $directory 'v4_upload_run_path_invalid'
        if (Test-Path -LiteralPath $directory) { throw 'v4_upload_run_path_not_fresh' }
        [void][IO.Directory]::CreateDirectory($directory); Assert-IgnoredUntracked $directory 'v4_upload_output_not_private'
    }
    foreach ($name in $names) { $target = Join-Path $fixtures $name; New-SyntheticPng $target ($run + '-' + $name); $fileMap[$name] = Get-Sha256 $target }
    if (@($fileMap.Values | Select-Object -Unique).Count -ne $names.Count) { throw 'v4_upload_fixture_checksum_collision' }
    # Uploads temporarily add and remove canonical rows and rebuild the shared
    # synthetic projection. Hold the same lease as the UNKNOWN scope fault so
    # independently invoked V4 gates cannot race or corrupt baseline evidence.
    $mutationLockAttempted = $true
    [void](Invoke-SyntheticMutationLock -Action 'lock' -Run $run)
    Assert-Baseline 'v4_upload_baseline_before_failed'
    foreach ($checksum in $fileMap.Values) { [void](Invoke-UploadFixture @('assert-absent', $checksum)) }
    $envNames = @('NODE_PATH','CLASS_ARCHIVE_V4_UPLOAD_CREDENTIAL_FILE','CLASS_ARCHIVE_V4_UPLOAD_PIWIGO_ORIGIN','CLASS_ARCHIVE_V4_UPLOAD_PHOTO_ORIGIN','CLASS_ARCHIVE_V4_UPLOAD_FIXTURE_ROOT','CLASS_ARCHIVE_V4_UPLOAD_USER_DATA_ROOT','CLASS_ARCHIVE_V4_UPLOAD_RESULT_FILE')
    $old = @{}; foreach ($name in $envNames) { $item = Get-Item "Env:$name" -ErrorAction SilentlyContinue; $old[$name] = if ($null -eq $item) { $null } else { $item.Value } }
    try {
        $env:NODE_PATH = Join-Path $deps 'node\node_modules'; $env:CLASS_ARCHIVE_V4_UPLOAD_CREDENTIAL_FILE = $credentialPath; $env:CLASS_ARCHIVE_V4_UPLOAD_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'; $env:CLASS_ARCHIVE_V4_UPLOAD_PHOTO_ORIGIN = 'http://127.0.0.1:8091/'; $env:CLASS_ARCHIVE_V4_UPLOAD_FIXTURE_ROOT = $fixtures; $env:CLASS_ARCHIVE_V4_UPLOAD_USER_DATA_ROOT = $profile; $env:CLASS_ARCHIVE_V4_UPLOAD_RESULT_FILE = $journal
        $output = @(& $node (Join-Path $PSScriptRoot 'photos-app-v4-chrome-upload-lifecycle.mjs') 2>&1); $code = $LASTEXITCODE
        $safe = @($output | ForEach-Object {[string]$_} | Where-Object { $_ -match '^V4_CHROME_UPLOAD_STAGE=[a-z0-9_-]+$' -or $_ -match '^V4_CHROME_UPLOAD_LIFECYCLE=(PASS assertions=[0-9]+ uploads=5 channel=chrome chrome_product=chrome chrome_version=[0-9.]+|FAIL stage=[a-z0-9_-]+ code=[a-z0-9_]+)$' })
        $pass = @($safe | Where-Object { $_ -match '^V4_CHROME_UPLOAD_LIFECYCLE=PASS\b' })
        if ($code -ne 0 -or $pass.Count -ne 1) { throw 'v4_upload_browser_failed' }
        $result = $pass[0]
        # This exact checksum differs from the successful Heritage fixture.
        # Assert the forged Family LIVING form did not create a submission,
        # pending photo, audit-targeted row, or pending binary before treating
        # the separate Heritage Pending path as valid evidence.
        [void](Invoke-UploadFixture @('assert-absent', $fileMap['family-tampered-living.png']))
    } finally { foreach ($name in $envNames) { Remove-Item "Env:$name" -ErrorAction SilentlyContinue; if ($null -ne $old[$name]) { Set-Item "Env:$name" -Value $old[$name] } } }
} finally {
    try {
        # Cleanup runs for browser success/failure. The tampered Family checksum
        # is separately proven absent, so malformed LIVING cannot leave a Pending
        # row, mapping, audit event, or binary before the real HERITAGE submit.
        try {
            $state = Read-Journal $journal
            foreach ($expected in @('classmate-heritage.png','classmate-living.png','teacher-heritage.png','teacher-living.png')) {
                $checksum = $fileMap[$expected]; if (-not $checksum) { continue }
                $entry = if ($null -ne $state) { @($state.uploads | Where-Object { $_.kind -in @('PUBLISHED','PUBLISHED_INTENT') -and $_.checksum -eq $checksum }) | Select-Object -First 1 } else { $null }
                if ($null -ne $entry) {
                    if ($entry.kind -eq 'PUBLISHED' -and [string]$entry.photoId -match '^[0-9a-f-]{36}$') {
                        [void](Invoke-UploadFixture @('cleanup-published',[string]$entry.photoId,$checksum))
                    } else {
                        # The helper returns absent only if this unique preflighted
                        # checksum was never accepted; otherwise it resolves one
                        # ACTIVE UUID, revalidates the pair, then cleans exactly it.
                        [void](Invoke-UploadFixture @('cleanup-published-by-checksum',$checksum))
                    }
                }
            }
            $tamper = $fileMap['family-tampered-living.png']; if ($tamper -and $null -ne $state) { [void](Invoke-UploadFixture @('assert-absent',$tamper)) }
            $family = $fileMap['family-heritage.png']; if ($family) {
                $familyEntry = if ($null -ne $state) { @($state.uploads | Where-Object { $_.checksum -eq $family -and $_.kind -match '^PENDING' }) | Select-Object -First 1 } else { $null }
                if ($null -ne $familyEntry) {
                    $pending = Invoke-UploadFixture @('locate-pending',$family)
                    [void](Invoke-UploadFixture @('cleanup-pending',[string]$pending.submission_id,[string]$pending.photo_id,$family))
                }
            }
            [void](Get-JsonOutput @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/rebuild-photo-read-projection.php','--scope=all','--json') 'v4_upload_projection_rebuild_failed')
            Assert-Baseline 'v4_upload_baseline_after_failed'
        } catch { $cleanupFailure = $_ }
        try {
            if ($mutationLockAttempted) { [void](Invoke-SyntheticMutationLock -Action 'unlock' -Run $run) }
        } catch {
            if ($null -eq $cleanupFailure) { $cleanupFailure = $_ }
        }
        foreach ($path in @($profile,$root)) {
            if (Test-Path -LiteralPath $path) {
                $item = Get-Item -LiteralPath $path -Force
                if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { $cleanupFailure = if ($null -eq $cleanupFailure) { 'v4_upload_run_output_reparse_point' } else { $cleanupFailure }; continue }
                try {
                    Remove-Item -LiteralPath $path -Recurse -Force -ErrorAction Stop
                    if (Test-Path -LiteralPath $path) { throw 'v4_upload_run_output_cleanup_failed' }
                } catch {
                    if ($null -eq $cleanupFailure) { $cleanupFailure = $_ }
                }
            }
        }
        if ($null -ne $cleanupFailure) { throw 'v4_upload_cleanup_or_baseline_failed' }
    }
    finally {
        if ($null -ne $phaseAMutationLease) {
            Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease
            $phaseAMutationLease = $null
        }
    }
}
if ([string]::IsNullOrWhiteSpace([string]$result)) { throw 'v4_upload_result_missing' }
Write-Output $result
Write-Output 'V4_CHROME_UPLOAD_BASELINE=PASS images=72 originals=72 multi_album=8'
# This terminal record is emitted only after the synthetic upload cleanup,
# baseline rebuild and ephemeral browser/profile cleanup have all completed.
Write-Output 'V4_CHROME_UPLOAD_LIFECYCLE_COMPLETE=PASS'
