[CmdletBinding()]
param()

# Public-safe protocol gate only. This test parses and inspects the copy
# operator; it does not enumerate private backup contents or write to either
# host recovery volume.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'infra\scripts\owner-second-media-copy.ps1'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

Assert-True (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'owner_second_media_runner_missing'
$runner = [IO.File]::ReadAllText($runnerPath)
[void][ScriptBlock]::Create($runner)

foreach ($needle in @(
    "[ValidateSet('preflight', 'copy', 'verify')]",
    "[string]`$TargetRoot = ([IO.Path]::Combine('C:' + [IO.Path]::DirectorySeparatorChar",
    '[switch]$ConfirmSecondMediaCopy',
    "'M:' + [IO.Path]::DirectorySeparatorChar",
    "'source_target_same_physical_disk'",
    "'filesystem_boundary_invalid'",
    "'target_capacity_insufficient'",
    'recommendedRequired = ($BundleBytes * 2)',
    "'CLASS_ARCHIVE_INDEPENDENT_RECOVERY_TARGET'",
    "'.partial-' + `$bundle.name",
    '& robocopy.exe $bundle.path $partial /E /COPY:DAT /DCOPY:DAT /R:2 /W:2 /J',
    '[IO.Directory]::Move($partial, $destination)',
    'Get-FileHash -Algorithm SHA256',
    "'bundle_sha256_mismatch'",
    "'destination_exists'",
    "'portable_envelope_missing'",
    "'owner-full-recovery-v2'",
    "'GPG_SYMMETRIC_AES256'",
    'portable_envelope.dpapi_required -ne $false',
    '$file.IsReadOnly = $true'
)) {
    Assert-True ($runner.Contains($needle)) ('owner_second_media_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}

Assert-True (-not $runner.Contains('Remove-Item')) 'owner_second_media_destructive_remove_detected'
Assert-True (-not $runner.Contains('docker ')) 'owner_second_media_runtime_mutation_detected'
$privateSourceMarker = 'M:' + [IO.Path]::DirectorySeparatorChar + '图片资源'
Assert-True (-not $runner.Contains($privateSourceMarker)) 'owner_second_media_private_source_path_detected'
Assert-True (-not ($runner -match '(?i)Write-(?:Output|Host).*(?:PASSWORD|PASSPHRASE|TOKEN|SECRET)')) 'owner_second_media_secret_output_detected'

$partialIndex = $runner.IndexOf("'.partial-' + `$bundle.name")
$copyIndex = $runner.IndexOf('& robocopy.exe $bundle.path $partial')
$partialVerifyIndex = $runner.IndexOf('Assert-Checksums $partial $partialEvidence.checksums')
$publishIndex = $runner.IndexOf('[IO.Directory]::Move($partial, $destination)')
$publishedVerifyIndex = $runner.IndexOf('Assert-Checksums $destination $destinationEvidence.checksums', $publishIndex)
Assert-True ($partialIndex -ge 0 -and $partialIndex -lt $copyIndex -and $copyIndex -lt $partialVerifyIndex -and
    $partialVerifyIndex -lt $publishIndex -and $publishIndex -lt $publishedVerifyIndex) 'owner_second_media_atomic_publish_order_invalid'

Write-Output ('OWNER_SECOND_MEDIA_PROTOCOL=PASS assertions=' + $assertions)
