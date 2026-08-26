[CmdletBinding()]
param()

# Fixed owner-local entry point for auditing image records excluded by the
# private full-library manifest. It captures decoder output and exposes only a
# path-free aggregate PASS/FAIL protocol. Source files are always read-only.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$inventory = Join-Path $projectRoot '.codex-work\private-real-full\inventory\full-real-source-inventory.json'
$manifest = Join-Path $projectRoot '.codex-work\private-real-full\manifests\full-real-import-manifest.json'
$report = Join-Path $projectRoot '.codex-work\private-real-qa\reports\unimported-images.json'
$tool = Join-Path $PSScriptRoot 'private-real-unimported-audit.py'
$script:stage = 'initialization'

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-UnimportedAudit([string]$Code) {
    Write-Output "PRIVATE_REAL_UNIMPORTED_AUDIT_WRAPPER=FAIL stage=$script:stage code=$Code"
    exit 2
}

function Get-ProjectRelative([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'private_path_outside_checkout'
    }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredUntracked([string]$Path) {
    $relative = Get-ProjectRelative $Path
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { throw 'report_not_ignored' }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { throw 'report_is_tracked' }
}

try {
    $script:stage = 'inputs'
    foreach ($inputPath in @($inventory, $manifest, $tool)) {
        $item = Get-Item -LiteralPath $inputPath -Force -ErrorAction Stop
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw 'input_path_untrusted'
        }
    }
    Assert-IgnoredUntracked $inventory
    Assert-IgnoredUntracked $manifest

    $script:stage = 'report_boundary'
    Assert-IgnoredUntracked $report
    $reportDirectory = Split-Path -Parent $report
    if (-not (Test-Path -LiteralPath $reportDirectory -PathType Container)) {
        [void](New-Item -ItemType Directory -Path $reportDirectory -Force -ErrorAction Stop)
    }

    $script:stage = 'decoder_audit'
    $python = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($null -eq $python) { $python = Get-Command python -ErrorAction SilentlyContinue }
    if ($null -eq $python) { throw 'python_unavailable' }
    $captured = @(& $python.Source $tool --inventory $inventory --runtime-manifest $manifest --output $report 2>&1)
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) { throw 'decoder_audit_failed' }
    $matches = @($captured | Where-Object { [string]$_ -match '^PRIVATE_REAL_UNIMPORTED_AUDIT=PASS discovered=\d+ imported=\d+ missing=\d+ safe=\d+ unique_safe=\d+ deferred=\d+ source_integrity=PASS report=OWNER_LOCAL_IGNORED$' })
    if ($matches.Count -ne 1) { throw 'decoder_audit_result_invalid' }

    $script:stage = 'report_protection'
    $reportItem = Get-Item -LiteralPath $report -Force -ErrorAction Stop
    if ($reportItem.PSIsContainer -or ($reportItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'report_path_untrusted'
    }
    Set-ClassArchiveOwnerOnlyFileAcl -Path $reportItem.FullName
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $reportItem.FullName
    Assert-IgnoredUntracked $reportItem.FullName

    Write-Output ([string]$matches[0])
    Write-Output 'PRIVATE_REAL_UNIMPORTED_AUDIT_WRAPPER=PASS source=READ_ONLY report=OWNER_LOCAL_IGNORED'
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^[a-z0-9_]{1,96}$') { $message } else { 'unimported_audit_failed' }
    Stop-UnimportedAudit $code
}
