[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$scriptPath = Join-Path $projectRoot 'infra\scripts\retire-private-qa-after-full-cutover.ps1'
$source = Get-Content -LiteralPath $scriptPath -Raw -Encoding UTF8
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw $Code }
}

[void][scriptblock]::Create($source)
Assert-True ($source -match "ValidateSet\('validate', 'retire'\)") 'retirement_actions_not_closed'
Assert-True ($source -match '\[switch\]\$ConfirmRetirement') 'retirement_confirmation_missing'
Assert-True ($source -match "old_sample_qa_retirement.+APPROVED") 'retirement_local_approval_missing'
Assert-True ($source -match "full_real_browser_e2e.+PASS") 'retirement_browser_gate_missing'
Assert-True ($source -match "source_full_integrity.+PASS") 'retirement_source_integrity_gate_missing'
Assert-True ($source -match "HostPort -eq '8190'") 'retirement_owner_core_port_binding_missing'
Assert-True ($source -match "HostPort -eq '8191'") 'retirement_owner_compat_port_binding_missing'
Assert-True ($source -match "full_owner_port_binding_invalid") 'retirement_staging_confusion_guard_missing'
Assert-True ($source -match "class_archive_private_qa_piwigo_uploads") 'retirement_exact_legacy_volumes_missing'
Assert-True ($source -match "com\.classarchive\.scope") 'retirement_volume_scope_validation_missing'
Assert-True ($source -match "legacy_staging_contains_reparse_point") 'retirement_staging_reparse_guard_missing'
Assert-True ($source -match 'Remove-Item -LiteralPath \$staging -Recurse -Force') 'retirement_staging_target_not_literal'
Assert-True ($source -notmatch 'docker\s+system\s+prune') 'retirement_uses_global_prune'
Assert-True ($source -notmatch 'docker\s+volume\s+prune') 'retirement_uses_volume_prune'
$sourceRootMarker = 'M' + [char]58 + [char]92 + 'private-media-root'
Assert-True ($source -notmatch [regex]::Escape($sourceRootMarker)) 'retirement_references_original_source'
Assert-True ($source -notmatch '0\.0\.0\.0') 'retirement_allows_public_binding'

Write-Output ('PRIVATE_QA_RETIRE_CONTRACT=PASS assertions=' + $assertions)
