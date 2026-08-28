[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('status', 'provision')]
    [string]$Action = 'status'
)

# Compatibility entry point for the private-full workflow. The former
# loop-image approach was retired because Docker Desktop cannot safely consume
# a mount created in another WSL mount namespace. Docker creates the required
# local POSIX volumes lazily, so "provision" proves the storage contract rather
# than allocating a host image or touching a removable drive.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$runner = Join-Path $PSScriptRoot 'private-full-storage.ps1'
$probeAction = if ($Action -eq 'provision') { 'initialize' } else { 'status' }

$lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runner $probeAction 2>&1)
if ($LASTEXITCODE -ne 0 -or @($lines | Where-Object { $_ -match '^PRIVATE_FULL_STORAGE=PASS action=' }).Count -ne 1) {
    Write-Output ('PRIVATE_FULL_PROVISION=FAIL action=' + $Action + ' code=storage_contract_failed')
    exit 2
}
Write-Output ('PRIVATE_FULL_PROVISION=PASS action=' + $Action + ' storage=DOCKER_MANAGED_POSIX_VOLUMES production_gate=BLOCKED')
