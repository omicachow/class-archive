[CmdletBinding()]
param(
    [ValidateSet('Index', 'Head', 'Outgoing')]
    [string]$Mode = 'Index',
    [string]$BaseRef = '',
    [string]$RepositoryRoot = ''
)

# Public Git boundary for Class Archive. This script inspects Git metadata and
# blobs only. It never walks ignored/private roots, enumerates drives, or emits
# a matched path, filename, blob body, hash-to-path mapping, or native error.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:violations = @{}
$script:scannedBlobs = 0
$script:scannedCommits = 0
$script:blobCache = @{}

$syntheticFixtures = @{
    'tests/fixtures/phase2-synthetic/fictional-cast-classroom.png' = '9bc950a58a09490ccdd118a3db56805ff2caa5fd953e3f83ecb79245345e99c3'
    'tests/fixtures/phase2-synthetic/fictional-cast-night-cake.png' = 'ce2cd9cd2fc38caa8c3aa4cf17abe6394da420383106097d5f4bea701aca3ad0'
    'tests/fixtures/phase2-synthetic/fictional-cast-outdoor.png' = '23b3aaf513f69c7b9ed9a208078d8e47989bac77fe458853f9c2895e03dcbd93'
    'tests/fixtures/phase2-synthetic/fictional-cast-playground.png' = 'd2d8d3478288affdc8d0fa89be92d063fc669e18d8152cf6952c497e1ef81987'
    'tests/fixtures/phase2-synthetic/fictional-cast-portraits.png' = '7d4f5570cd81033d609b13d0c8fee55199b47360aef9728aa8835735e6a8379f'
}

$publicManifestPaths = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
[void]$publicManifestPaths.Add('infra/immich-spike/ml-artifacts/manifest.json')

$forbiddenSegments = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
@(
    '.codex-work', '.codex_tmp', '.private-real-qa', 'private-real-qa',
    'private-data', 'real-data',
    'screenshots', 'screenshot', 'embeddings', 'embedding', 'face-index',
    'face-indexes', 'qa-manifest', 'qa-manifests', 'uploads', 'upload',
    'backups', 'backup', 'database-exports', 'secrets', 'tests-artifacts',
    '__pycache__', '.pytest_cache'
) | ForEach-Object { [void]$forbiddenSegments.Add($_) }

$forbiddenExtensions = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
@(
    '.sql', '.dump', '.sqlite', '.sqlite3', '.db', '.pem', '.key', '.p12', '.pfx',
    '.jpg', '.jpeg', '.png', '.webp', '.heic', '.heif', '.avif', '.gif', '.bmp',
    '.jxl', '.tif', '.tiff', '.dng', '.cr2', '.cr3', '.nef', '.arw', '.raf',
    '.rw2', '.orf', '.srw', '.pef', '.mov', '.mp4', '.m4v', '.avi', '.mkv',
    '.webm', '.mts', '.m2ts', '.mp3', '.m4a', '.aac', '.wav', '.flac', '.ogg',
    '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.zip', '.7z',
    '.rar', '.tar', '.gz', '.bz2', '.xz', '.zst', '.jsonl', '.ndjson', '.csv', '.tsv',
    '.parquet', '.npy', '.npz', '.pt', '.pth', '.ckpt', '.safetensors', '.faiss',
    '.index', '.h5', '.hdf5', '.onnx', '.tflite', '.gguf', '.pkl', '.pickle',
    '.xmp', '.bak', '.bin', '.pyc', '.pyo', '.pyd', '.exe', '.dll', '.so',
    '.dylib', '.jar', '.class', '.wasm', '.o', '.obj', '.lib', '.a'
) | ForEach-Object { [void]$forbiddenExtensions.Add($_) }

function Add-Violation([string]$Reason) {
    if ($Reason -notmatch '^[a-z0-9_]{1,64}$') { $Reason = 'invalid_gate_reason' }
    if (!$script:violations.ContainsKey($Reason)) { $script:violations[$Reason] = 0 }
    $script:violations[$Reason] = [int]$script:violations[$Reason] + 1
}

function Stop-Gate([string]$Reason) {
    Write-Output "PUBLIC_BOUNDARY=FAIL reason=$Reason count=1"
    exit 1
}

function Invoke-Git([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& git -C $script:repositoryRoot -c core.quotepath=false @Arguments 2>$null)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($code -ne 0) { throw 'git_command_failed' }
    return $output
}

function Resolve-Commit([string]$Reference) {
    if ($Reference -notmatch '^[0-9a-fA-F]{40,64}$') { throw 'git_reference_invalid' }
    $resolved = @(Invoke-Git @('rev-parse', '--verify', ($Reference + '^{commit}')))
    if ($resolved.Count -ne 1 -or [string]$resolved[0] -notmatch '^[0-9a-f]{40,64}$') {
        throw 'git_reference_invalid'
    }
    return [string]$resolved[0]
}

function Get-IndexEntries {
    $entries = @()
    foreach ($line in @(Invoke-Git @('ls-files', '--stage'))) {
        $match = [regex]::Match([string]$line, '^([0-9]{6}) ([0-9a-f]{40,64}) ([0-3])\t(.+)$')
        if (!$match.Success) { throw 'git_index_shape_invalid' }
        if ($match.Groups[3].Value -ne '0') { throw 'git_index_unmerged' }
        $entries += [pscustomobject]@{ Oid = $match.Groups[2].Value; Path = $match.Groups[4].Value }
    }
    return $entries
}

function Get-TreeEntries([string]$Commit) {
    $entries = @()
    foreach ($line in @(Invoke-Git @('ls-tree', '-r', '--full-tree', $Commit))) {
        $match = [regex]::Match([string]$line, '^([0-9]{6}) blob ([0-9a-f]{40,64})\t(.+)$')
        if (!$match.Success) { throw 'git_tree_shape_invalid' }
        $entries += [pscustomobject]@{ Oid = $match.Groups[2].Value; Path = $match.Groups[3].Value }
    }
    return $entries
}

function Get-BlobBytes([string]$Oid) {
    if ($script:blobCache.ContainsKey($Oid)) { return [byte[]]$script:blobCache[$Oid] }
    if ($Oid -notmatch '^[0-9a-f]{40,64}$') { throw 'git_blob_oid_invalid' }
    $sizeOutput = @(Invoke-Git @('cat-file', '-s', $Oid))
    if ($sizeOutput.Count -ne 1 -or [string]$sizeOutput[0] -notmatch '^[0-9]+$') { throw 'git_blob_size_invalid' }
    $size = [int64]$sizeOutput[0]
    if ($size -gt 32MB) {
        Add-Violation 'oversized_tracked_blob'
        return [byte[]]@()
    }

    $gitCommand = Get-Command git -ErrorAction Stop
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $gitCommand.Source
    $start.WorkingDirectory = $script:repositoryRoot
    $start.Arguments = 'cat-file blob ' + $Oid
    $start.UseShellExecute = $false
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.CreateNoWindow = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $start
    if (!$process.Start()) { throw 'git_blob_read_failed' }
    $memory = [IO.MemoryStream]::new()
    try {
        $process.StandardOutput.BaseStream.CopyTo($memory)
        [void]$process.StandardError.ReadToEnd()
        $process.WaitForExit()
        if ($process.ExitCode -ne 0 -or $memory.Length -ne $size) { throw 'git_blob_read_failed' }
        $bytes = $memory.ToArray()
    } finally {
        $memory.Dispose()
        $process.Dispose()
    }
    $script:blobCache[$Oid] = $bytes
    return [byte[]]$bytes
}

function Get-Sha256([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return -join ($sha.ComputeHash($Bytes) | ForEach-Object { $_.ToString('x2') }) }
    finally { $sha.Dispose() }
}

function Test-Prefix([byte[]]$Bytes, [byte[]]$Prefix) {
    if ($Bytes.Length -lt $Prefix.Length) { return $false }
    for ($index = 0; $index -lt $Prefix.Length; $index++) {
        if ($Bytes[$index] -ne $Prefix[$index]) { return $false }
    }
    return $true
}

function Test-PrivateMagic([byte[]]$Bytes) {
    if ($Bytes.Length -eq 0) { return $false }
    $prefixes = @(
        [byte[]](0xFF,0xD8,0xFF),
        [byte[]](0x89,0x50,0x4E,0x47,0x0D,0x0A,0x1A,0x0A),
        [Text.Encoding]::ASCII.GetBytes('GIF87a'),
        [Text.Encoding]::ASCII.GetBytes('GIF89a'),
        [Text.Encoding]::ASCII.GetBytes('%PDF-'),
        [Text.Encoding]::ASCII.GetBytes("SQLite format 3`0"),
        [byte[]](0x49,0x49,0x2A,0x00),
        [byte[]](0x4D,0x4D,0x00,0x2A),
        [Text.Encoding]::ASCII.GetBytes('BM'),
        [byte[]](0x50,0x4B,0x03,0x04),
        [byte[]](0x1F,0x8B),
        [byte[]](0x37,0x7A,0xBC,0xAF,0x27,0x1C),
        [byte[]](0x52,0x61,0x72,0x21,0x1A,0x07),
        [byte[]](0xFD,0x37,0x7A,0x58,0x5A,0x00),
        [byte[]](0x28,0xB5,0x2F,0xFD),
        [byte[]](0xD0,0xCF,0x11,0xE0,0xA1,0xB1,0x1A,0xE1),
        [byte[]](0x7F,0x45,0x4C,0x46),
        [byte[]](0x4D,0x5A),
        [byte[]](0x1A,0x45,0xDF,0xA3),
        [Text.Encoding]::ASCII.GetBytes('BZh'),
        [Text.Encoding]::ASCII.GetBytes('ID3'),
        [Text.Encoding]::ASCII.GetBytes('fLaC'),
        [Text.Encoding]::ASCII.GetBytes('OggS'),
        [Text.Encoding]::ASCII.GetBytes('PAR1'),
        [byte[]](0x93,0x4E,0x55,0x4D,0x50,0x59)
    )
    foreach ($prefix in $prefixes) { if (Test-Prefix $Bytes $prefix) { return $true } }
    if ($Bytes.Length -ge 12) {
        $head = [Text.Encoding]::ASCII.GetString($Bytes, 0, [Math]::Min(16, $Bytes.Length))
        if ($head.StartsWith('RIFF')) { return $true }
        if ($head.Substring(4, 4) -eq 'ftyp') { return $true }
    }
    if ($Bytes.Length -ge 262 -and [Text.Encoding]::ASCII.GetString($Bytes, 257, 5) -eq 'ustar') { return $true }
    $lfs = [Text.Encoding]::ASCII.GetBytes('version https://git-lfs.github.com/spec/v1')
    return Test-Prefix $Bytes $lfs
}

function Test-PrivateAbsolutePath([byte[]]$Bytes) {
    if ($Bytes.Length -eq 0 -or $Bytes.Length -gt 4MB -or [Array]::IndexOf($Bytes, [byte]0) -ge 0) { return $false }
    try {
        $text = [Text.UTF8Encoding]::new($false, $true).GetString($Bytes)
    } catch { return $false }
    $backslash = [regex]::Escape([string][char]92)
    $drive = '[A-Za-z]:(?:' + $backslash + '|/)'
    $unc = $backslash + $backslash + '[A-Za-z0-9._-]+' + $backslash
    $extended = $backslash + $backslash + '\?' + $backslash + '[A-Za-z]:' + $backslash
    $unixUser = '/(?:Users|home)/[^/\s]+/'
    $pattern = '(?i)(?:^|[\s"''=])(?:' + $drive + '|' + $unc + '|' + $extended + '|' + $unixUser + ')'
    return [regex]::IsMatch($text, $pattern)
}

function Test-Entry([string]$Path, [string]$Oid) {
    $normalized = $Path.Replace([char]92, [char]47)
    if (
        $normalized -eq '' -or
        $normalized.StartsWith('/') -or
        $normalized -match '^[A-Za-z]:/' -or
        $normalized.StartsWith('//') -or
        $normalized.StartsWith('"') -or
        $normalized.Contains('..') -or
        $normalized.IndexOfAny([char[]](0,9,10,13)) -ge 0
    ) {
        Add-Violation 'forbidden_path'
        return
    }

    $isSynthetic = $syntheticFixtures.ContainsKey($normalized)
    if (!$isSynthetic) {
        $segments = @($normalized.Split('/'))
        if (@($segments | Where-Object { $forbiddenSegments.Contains($_) }).Count -gt 0) {
            Add-Violation 'forbidden_path'
            return
        }
        $leaf = $segments[$segments.Count - 1]
        if ($leaf -match '^\.env(?:$|\.)' -and $leaf -ne '.env.example') {
            Add-Violation 'forbidden_path'
            return
        }
        $extension = [IO.Path]::GetExtension($leaf)
        if (
            !$publicManifestPaths.Contains($normalized) -and
            (
                $leaf -match '^(?:manifest|inventory)\.(?:json|ya?ml)$' -or
                $leaf -match '^(?:private|real)[-_].*(?:manifest|inventory|report|result|output|embedding|index|screenshot|media|photo).*\.(?:json|ya?ml|md|txt)$'
            )
        ) {
            Add-Violation 'forbidden_path'
            return
        }
        if ($forbiddenExtensions.Contains($extension) -or $leaf -match '\.(?:sql|dump)\.') {
            Add-Violation 'forbidden_extension'
            return
        }
        if ($leaf -match '\.(?:sqlite3?|db)-(?:wal|shm)$') {
            Add-Violation 'forbidden_extension'
            return
        }
    }

    $bytes = Get-BlobBytes $Oid
    $script:scannedBlobs++
    if ($isSynthetic) {
        if ((Get-Sha256 $bytes) -cne $syntheticFixtures[$normalized]) { Add-Violation 'synthetic_fixture_digest' }
        return
    }
    if (Test-PrivateMagic $bytes) { Add-Violation 'private_blob_magic' }
    if (Test-PrivateAbsolutePath $bytes) { Add-Violation 'private_absolute_path' }
}

try {
    if ([string]::IsNullOrWhiteSpace($RepositoryRoot)) {
        $RepositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    }
    $script:repositoryRoot = [IO.Path]::GetFullPath($RepositoryRoot)
    $top = @(Invoke-Git @('rev-parse', '--show-toplevel'))
    $resolvedTop = if ($top.Count -eq 1) { [IO.Path]::GetFullPath([string]$top[0]).TrimEnd([char[]]@([char]92, [char]47)) } else { '' }
    $resolvedRoot = $script:repositoryRoot.TrimEnd([char[]]@([char]92, [char]47))
    if ($top.Count -ne 1 -or ![StringComparer]::OrdinalIgnoreCase.Equals($resolvedTop, $resolvedRoot)) {
        throw 'repository_root_invalid'
    }

    $entrySets = @()
    if ($Mode -eq 'Index') {
        $entrySets += ,@(Get-IndexEntries)
    } elseif ($Mode -eq 'Head') {
        $head = @(Invoke-Git @('rev-parse', '--verify', 'HEAD^{commit}'))
        if ($head.Count -ne 1 -or [string]$head[0] -notmatch '^[0-9a-f]{40,64}$') { throw 'git_head_invalid' }
        $script:scannedCommits = 1
        $entrySets += ,@(Get-TreeEntries ([string]$head[0]))
    } else {
        $range = 'HEAD'
        if (![string]::IsNullOrWhiteSpace($BaseRef) -and $BaseRef -notmatch '^0+$') {
            $base = Resolve-Commit $BaseRef
            $range = $base + '..HEAD'
        }
        $commits = @(Invoke-Git @('rev-list', '--reverse', $range))
        foreach ($commit in $commits) {
            if ([string]$commit -notmatch '^[0-9a-f]{40,64}$') { throw 'git_history_shape_invalid' }
            $script:scannedCommits++
            $entrySets += ,@(Get-TreeEntries ([string]$commit))
        }
    }

    foreach ($entries in $entrySets) {
        foreach ($entry in @($entries)) { Test-Entry ([string]$entry.Path) ([string]$entry.Oid) }
    }
} catch {
    $message = [string]$_.Exception.Message
    if ($message -notmatch '^[a-z0-9_]{1,64}$') { $message = 'unexpected' }
    Stop-Gate $message
}

if ($script:violations.Count -gt 0) {
    $count = 0
    foreach ($value in $script:violations.Values) { $count += [int]$value }
    $reason = if ($script:violations.Count -eq 1) { [string]@($script:violations.Keys)[0] } else { 'multiple' }
    Write-Output "PUBLIC_BOUNDARY=FAIL reason=$reason count=$count"
    exit 1
}

Write-Output "PUBLIC_BOUNDARY=PASS mode=$($Mode.ToUpperInvariant()) commits=$script:scannedCommits blobs=$script:scannedBlobs"
