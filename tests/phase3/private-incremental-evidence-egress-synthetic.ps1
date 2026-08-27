[CmdletBinding()]
param()

# Public-safe local exercise of the fixed stdout evidence decoder.  It extracts
# only that function from the operator AST and replaces the owner-only writer
# with an in-memory sink; no Docker service, private path or media is opened.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:assertions = 0
$script:stage = 'synthetic'
$script:savedPath = $null
$script:savedRaw = $null

function Fail([string]$Code) { throw "SYNTHETIC_FAIL:$Code" }
function Assert-Exact([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Fail $Code }
}
function Write-OwnerOnlyText([string]$Path, [string]$Value) {
    $script:savedPath = $Path
    $script:savedRaw = $Value
}
function Assert-True([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw "PRIVATE_INCREMENTAL_EVIDENCE_EGRESS_SYNTHETIC=FAIL code=$Code assertions=$script:assertions" }
}

$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$operator = Join-Path $root 'infra\scripts\private-full-incremental-media.ps1'
$tokens = $null
$parseErrors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($operator, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'operator_parse_failed'
$functionAst = $ast.Find({
    param($node)
    $node -is [Management.Automation.Language.FunctionDefinitionAst] `
        -and $node.Name -eq 'Receive-FixedImmichStdoutEvidence'
}, $true)
Assert-True ($null -ne $functionAst) 'decoder_function_missing'
Invoke-Expression $functionAst.Extent.Text

$raw = '{"version":1,"assets":[],"runtime_mode":"INCREMENTAL"}'
$bytes = [Text.Encoding]::UTF8.GetBytes($raw)
$sha = [Security.Cryptography.SHA256]::Create()
try { $digest = [BitConverter]::ToString($sha.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
$base64 = [Convert]::ToBase64String($bytes)
$marker = 'PRIVATE_QA_IMMICH_INCREMENTAL=PASS assets=2 baseline=1 delta=1 old_changed=0 force_full=0'
$envelope = "CLASS_ARCHIVE_IMMICH_EVIDENCE_V1 bytes=$($bytes.Length) sha256=$digest base64=$base64`n$marker"

$decodedMarker = Receive-FixedImmichStdoutEvidence -Output $envelope -Path 'memory-only'
Assert-True ($decodedMarker -eq $marker -and $script:savedPath -eq 'memory-only' -and $script:savedRaw -eq $raw) 'valid_envelope_rejected'

$badDigest = ('0' * 64)
if ($badDigest -eq $digest) { $badDigest = ('1' * 64) }
$script:savedRaw = $null
try {
    [void](Receive-FixedImmichStdoutEvidence -Output ($envelope.Replace($digest, $badDigest)) -Path 'memory-only')
    Assert-True $false 'digest_tamper_accepted'
} catch {
    Assert-True ([string]$_.Exception.Message -eq 'SYNTHETIC_FAIL:runtime_stdout_evidence_sha256_mismatch') 'digest_tamper_wrong_failure'
}
Assert-True ($null -eq $script:savedRaw) 'tampered_payload_written'

try {
    [void](Receive-FixedImmichStdoutEvidence -Output ("unexpected`n" + $envelope) -Path 'memory-only')
    Assert-True $false 'prefixed_output_accepted'
} catch {
    Assert-True ([string]$_.Exception.Message -eq 'SYNTHETIC_FAIL:runtime_stdout_envelope_invalid') 'prefixed_output_wrong_failure'
}

$invalidUtf8 = [byte[]](0xc3, 0x28, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20, 0x20)
$invalidSha = [Security.Cryptography.SHA256]::Create()
try { $invalidDigest = [BitConverter]::ToString($invalidSha.ComputeHash($invalidUtf8)).Replace('-', '').ToLowerInvariant() } finally { $invalidSha.Dispose() }
$invalidEnvelope = "CLASS_ARCHIVE_IMMICH_EVIDENCE_V1 bytes=$($invalidUtf8.Length) sha256=$invalidDigest base64=$([Convert]::ToBase64String($invalidUtf8))`n$marker"
try {
    [void](Receive-FixedImmichStdoutEvidence -Output $invalidEnvelope -Path 'memory-only')
    Assert-True $false 'invalid_utf8_accepted'
} catch {
    Assert-True ([string]$_.Exception.Message -eq 'SYNTHETIC_FAIL:runtime_stdout_evidence_utf8_invalid') 'invalid_utf8_wrong_failure'
}

Write-Output "PRIVATE_INCREMENTAL_EVIDENCE_EGRESS_SYNTHETIC=PASS assertions=$script:assertions evidence=MEMORY_ONLY"
