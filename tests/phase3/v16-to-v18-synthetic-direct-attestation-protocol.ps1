[CmdletBinding()]
param()

# Static-only check for the head/source-bound direct V16 -> V18 attestation.
# It opens tracked source text only and never launches WSL, Docker, a database,
# a browser, a media service, or a private runtime.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$attestationPath = Join-Path $projectRoot 'infra\scripts\attest-v16-to-v18-synthetic-direct-runtime.ps1'
$runnerPath = Join-Path $projectRoot 'infra\scripts\v16-to-v18-synthetic-direct-runtime.ps1'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

Assert-True (Test-Path -LiteralPath $attestationPath -PathType Leaf) 'direct_attestation_script_missing'
Assert-True (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'direct_attestation_runner_missing'

$tokens = $null
$parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($attestationPath, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'direct_attestation_parse_error'

$source = [IO.File]::ReadAllText($attestationPath)
Assert-True ($source.Contains("[ValidateSet('create', 'verify', 'status')]") -and $source.Contains("`$attempt = 'attempt17'") -and $source.Contains("`$httpPort = '10190'") -and $source.Contains("`$compatPort = '10191'")) 'direct_attestation_surface_or_attempt_invalid'
Assert-True ($source.Contains("'SYNTHETIC_DIRECT_V16_TO_V18_RUNTIME'") -and $source.Contains("'CURRENT_SOURCE_DIRECT_17_18'") -and $source.Contains("'SYNTHETIC_V4_MIGRATION'")) 'direct_attestation_identity_missing'
Assert-True ($source.Contains('Get-Head') -and $source.Contains('git -C $projectRoot rev-parse --verify HEAD') -and $source.Contains('source_worktree_not_head_bound') -and $source.Contains('source_index_not_head_bound')) 'direct_attestation_head_clean_boundary_missing'
Assert-True ($source.Contains('Get-SourceClosure') -and $source.Contains('source_digest') -and $source.Contains('Get-FileHash') -and $source.Contains('plugins/ClassIdentity/src/Schema.php') -and $source.Contains('v16-to-v18-synthetic-direct-proof.php') -and $source.Contains('v16-to-v18-synthetic-direct-runtime.ps1') -and $source.Contains('create-pre-migration-db-snapshot.sh') -and $source.Contains('restore-v4-synthetic-pre-migration-db.sh')) 'direct_attestation_source_closure_missing'
Assert-True ($source.Contains('Read-DirectProofReport') -and $source.Contains('schema_from=16 schema_to=18 sequential=17_18 replay=NOT_APPLICABLE') -and $source.Contains('schema_from=18 schema_to=18 sequential=NOT_APPLICABLE replay=PASS') -and $source.Contains('stage=verify_current_source schema=18 ledger=18') -and $source.Contains('unknown_schema=DENY scratch=DISPOSED')) 'direct_attestation_runtime_evidence_binding_missing'
Assert-True ($source.Contains('direct_proof_report_sha256') -and $source.Contains('legacy_fingerprint') -and $source.Contains('attestation_stale') -and $source.Contains('attestation_source_hash_stale')) 'direct_attestation_staleness_checks_missing'
Assert-True ($source.Contains("'infra/scripts/attest-v16-to-v18-synthetic-direct-runtime.ps1'") -and $source.Contains('function ConvertTo-NormalizedSourceEntries') -and $source.Contains("ConvertTo-NormalizedSourceEntries @(`$record.sources) 'attestation_source_entry_invalid'") -and $source.Contains("ConvertTo-NormalizedSourceEntries @(`$material.sources) 'attestation_source_entry_invalid'") -and $source.Contains('PowerShell object representation')) 'direct_attestation_source_object_canonicalization_missing'
# The attester must reject a proof generated from an older source revision;
# it is not sufficient to hash the proof report after a new checkout.  The
# runtime proof now carries a V2 source commit/digest and Get-AttestationMaterial
# compares them to the current clean source closure before either create or
# verify can pass.
Assert-True ($source.Contains('function Read-DirectProofReport([string]$ExpectedCommit, [string]$ExpectedSourceDigest)') -and $source.Contains('$proof.format -ne 2') -and $source.Contains('$proof.source_commit') -and $source.Contains('$proof.source_digest')) 'direct_attestation_proof_v2_contract_missing'
Assert-True ($source.Contains('direct_proof_source_closure_stale') -and $source.Contains('Read-DirectProofReport $head ([string]$sources.digest)') -and $source.Contains('ExpectedCommit') -and $source.Contains('ExpectedSourceDigest')) 'direct_attestation_proof_current_source_equality_missing'
Assert-True ($source.Contains('Get-RuntimeLockMetadata') -and $source.Contains('PIWIGO_IMAGE') -and $source.Contains('MARIADB_IMAGE') -and $source.Contains('runtime_lock')) 'direct_attestation_container_lock_missing'
Assert-True ($source.Contains('Assert-IgnoredUntracked $attestationPath') -and $source.Contains('Assert-IgnoredUntracked $proofReportPath') -and $source.Contains("media = 'NOT_MOUNTED'")) 'direct_attestation_private_boundary_missing'
$privateSourceMarker = (([string][char]77) + ':' + [char]92) + '图片资源'
foreach ($forbidden in @('8091','8191','8291','private-real',$privateSourceMarker,'Copy-Item','Move-Item','Remove-Item','docker compose down','docker volume rm','Write-Output $stderr','Write-Error $stderr')) {
    Assert-True (-not $source.Contains($forbidden)) ('direct_attestation_forbidden_surface_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Failures are consumed as a machine-readable gate.  The catch must emit one
# bounded record, rather than PowerShell's path-rich error formatting or the
# original exception text from an ignored local proof artifact.
Assert-True ($source.Contains("`$message -match '^V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION_STOP:([a-z0-9_]{1,96})`$'")) 'direct_attestation_stop_code_parser_not_bounded'
Assert-True ($source.Contains("`$type -notmatch '^[A-Za-z0-9]{1,64}`$'") -and $source.Contains("'unexpected_' + `$type.ToLowerInvariant()")) 'direct_attestation_unexpected_code_parser_not_bounded'
Assert-True ($source.Contains("Write-Output ('V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION=FAIL code=' + `$code)") -and $source.Contains('    exit 1')) 'direct_attestation_single_line_fail_output_missing'
Assert-True (@($tokens | Where-Object { $_.Kind -eq 'Generic' -and $_.Text -ieq 'Write-Error' }).Count -eq 0) 'direct_attestation_write_error_command_forbidden'
foreach ($forbiddenFailureOutput in @('Write-Host', 'Write-Warning', 'Write-Verbose', 'Write-Debug', 'Write-Information', 'Write-Output $message', 'Write-Output $_', 'Exception.ToString()', 'InvocationInfo', 'ScriptStackTrace')) {
    Assert-True (-not $source.Contains($forbiddenFailureOutput)) ('direct_attestation_path_rich_failure_output_forbidden_' + ($forbiddenFailureOutput -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$attestationCatchIndex = $source.LastIndexOf('} catch {', [StringComparison]::Ordinal)
Assert-True ($attestationCatchIndex -ge 0) 'direct_attestation_final_catch_missing'
$attestationFinalCatch = $source.Substring($attestationCatchIndex)
Assert-True ($attestationFinalCatch.Contains('$message = [string]$_.Exception.Message') -and $attestationFinalCatch.Contains("`$message -match '^V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION_STOP:([a-z0-9_]{1,96})`$'") -and $attestationFinalCatch.Contains("Write-Output ('V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION=FAIL code=' + `$code)") -and $attestationFinalCatch.Contains('exit 1')) 'direct_attestation_final_catch_path_free_contract_missing'
Assert-True (([regex]::Matches($attestationFinalCatch, '(?m)^\s*Write-Output\s+\(')).Count -eq 1) 'direct_attestation_final_catch_must_emit_exactly_one_line'

Write-Output "V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION_PROTOCOL=PASS assertions=$assertions"
