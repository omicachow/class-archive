<#
.SYNOPSIS
Creates or verifies a head-bound attestation for the isolated direct V16 ->
V18 synthetic runtime proof.

.DESCRIPTION
The attestation is deliberately separate from a Photo UI browser attestation.
It is valid only when the current checked-out commit and the direct migration
source closure exactly match the proof that ran in attempt39.  The emitted
artifact lives under .codex-work, contains no credentials, and is not an
authorization input for the application itself.  Owner migration tooling may
use Verify as a release gate only.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('create', 'verify', 'status')]
    [string]$Action = 'status'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$attempt = 'attempt39'
$httpPort = '11802'
$compatPort = '11803'
$sandboxRoot = Join-Path $projectRoot ('.codex-work\v18-synthetic-migration-' + $attempt)
$reportRoot = Join-Path $sandboxRoot 'reports'
$proofReportPath = Join-Path $reportRoot 'v16-to-v18-direct-proof.json'
$attestationPath = Join-Path $reportRoot 'v16-to-v18-direct-attestation.json'
$sourcePaths = @(
    'infra/docker-compose.yml',
    'infra/v18-synthetic-migration/docker-compose.override.yml',
    'infra/scripts/v18-synthetic-db-probe.sh',
    'infra/scripts/v18-synthetic-migration.ps1',
    'infra/scripts/create-pre-migration-db-snapshot.sh',
    'infra/scripts/restore-v4-synthetic-pre-migration-db.sh',
    'infra/scripts/v16-to-v18-synthetic-direct-proof.php',
    'infra/scripts/v16-to-v18-synthetic-direct-runtime.ps1',
    'infra/scripts/attest-v16-to-v18-synthetic-direct-runtime.ps1',
    'plugins/ClassIdentity/src/Schema.php'
)

function Stop-V16ToV18DirectAttestation([string]$Code) {
    throw [InvalidOperationException]::new('V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION_STOP:' + $Code)
}

function Get-FileSha256([string]$Path) {
    try {
        $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        try {
            $algorithm = [Security.Cryptography.SHA256]::Create()
            try { $bytes = $algorithm.ComputeHash($stream) }
            finally { $algorithm.Dispose() }
        }
        finally { $stream.Dispose() }
        $hash = [BitConverter]::ToString($bytes).Replace('-','')
    }
    catch {
        Stop-V16ToV18DirectAttestation 'file_hash_runtime_failed'
    }
    if ([string]$hash -notmatch '^[a-fA-F0-9]{64}$') { Stop-V16ToV18DirectAttestation 'file_hash_result_invalid' }
    return ([string]$hash).ToLowerInvariant()
}

function Write-V16ToV18DirectAttestation([string]$State, [string]$Extra = '') {
    $suffix = if ([string]::IsNullOrWhiteSpace($Extra)) { '' } else { ' ' + $Extra }
    Write-Output ("V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION={0}{1}" -f $State, $suffix)
}

function Assert-PathInside([string]$Path, [string]$Root, [bool]$MustExist = $true) {
    $full = [IO.Path]::GetFullPath($Path)
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not (($full + [IO.Path]::DirectorySeparatorChar).StartsWith($rootFull, [StringComparison]::OrdinalIgnoreCase))) {
        Stop-V16ToV18DirectAttestation 'path_outside_allowed_root'
    }
    if ($MustExist -and -not (Test-Path -LiteralPath $full)) { Stop-V16ToV18DirectAttestation 'required_path_missing' }
    $cursor = if (Test-Path -LiteralPath $full) { Get-Item -LiteralPath $full -Force } else { Get-Item -LiteralPath (Split-Path -Parent $full) -Force }
    while ($null -ne $cursor) {
        if (($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Stop-V16ToV18DirectAttestation 'reparse_point_forbidden' }
        $cursor = if ($cursor -is [IO.DirectoryInfo]) { $cursor.Parent } else { $cursor.Directory }
    }
    return $full
}

function Assert-IgnoredUntracked([string]$Path, [bool]$Directory, [bool]$MustExist = $true) {
    $full = Assert-PathInside $Path $projectRoot $MustExist
    if ($MustExist) {
        $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
        if (($Directory -and -not $item.PSIsContainer) -or (-not $Directory -and $item.PSIsContainer)) {
            Stop-V16ToV18DirectAttestation 'ignored_path_type_invalid'
        }
    }
    $relative = $full.Substring($projectRoot.TrimEnd('\', '/').Length).TrimStart('\', '/').Replace('\','/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V16ToV18DirectAttestation 'attestation_path_not_ignored' }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-V16ToV18DirectAttestation 'attestation_path_tracked' }
    return $full
}

function Get-Head {
    $head = @(& git -C $projectRoot rev-parse --verify HEAD 2>$null)
    if ($LASTEXITCODE -ne 0 -or $head.Count -ne 1 -or ([string]$head[0]).Trim() -notmatch '^[a-f0-9]{40}$') {
        Stop-V16ToV18DirectAttestation 'git_head_invalid'
    }
    return ([string]$head[0]).Trim()
}

function ConvertTo-NormalizedSourceEntries([object[]]$Entries, [string]$InvalidCode) {
    $normalized = @($Entries | ForEach-Object {
        $path = [string]$_.path
        $sha256 = [string]$_.sha256
        if ($path -notmatch '^[A-Za-z0-9_./-]+$' -or $path.Contains('..') -or $sha256 -notmatch '^[a-f0-9]{64}$') {
            Stop-V16ToV18DirectAttestation $InvalidCode
        }
        [pscustomobject]@{ path = $path; sha256 = $sha256 }
    } | Sort-Object -Property path)
    if ($normalized.Count -ne $Entries.Count -or @($normalized.path | Select-Object -Unique).Count -ne $normalized.Count) {
        Stop-V16ToV18DirectAttestation $InvalidCode
    }
    return $normalized
}

function Get-SourceClosure {
    $head = Get-Head
    $records = [System.Collections.Generic.List[object]]::new()
    foreach ($relative in $sourcePaths) {
        if ($relative -notmatch '^[A-Za-z0-9_./-]+$' -or $relative.Contains('..')) { Stop-V16ToV18DirectAttestation 'source_path_invalid' }
        $full = Assert-PathInside (Join-Path $projectRoot $relative) $projectRoot $true
        $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
        if ($item.PSIsContainer) { Stop-V16ToV18DirectAttestation 'source_path_not_leaf' }
        $tracked = @(& git -C $projectRoot ls-files --error-unmatch -- $relative 2>$null)
        if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 1) { Stop-V16ToV18DirectAttestation 'source_not_tracked' }
        & git -C $projectRoot diff --quiet -- $relative
        if ($LASTEXITCODE -ne 0) { Stop-V16ToV18DirectAttestation 'source_worktree_not_head_bound' }
        & git -C $projectRoot diff --cached --quiet -- $relative
        if ($LASTEXITCODE -ne 0) { Stop-V16ToV18DirectAttestation 'source_index_not_head_bound' }
        [void]$records.Add([pscustomobject]@{
            path = $relative
            sha256 = Get-FileSha256 $full
        })
    }
    $ordered = ConvertTo-NormalizedSourceEntries @($records) 'source_entry_invalid'
    $material = [string]::Join("`n", @($ordered | ForEach-Object { $_.path + "`0" + $_.sha256 })) + "`n"
    $bytes = [Text.Encoding]::UTF8.GetBytes($material)
    $digest = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash($bytes)).Replace('-','').ToLowerInvariant()
    if ($head -ne (Get-Head)) { Stop-V16ToV18DirectAttestation 'source_head_changed_during_capture' }
    return [ordered]@{ digest = $digest; sources = $ordered }
}

function Read-DirectProofReport([string]$ExpectedCommit, [string]$ExpectedSourceDigest) {
    Assert-IgnoredUntracked $sandboxRoot $true | Out-Null
    Assert-IgnoredUntracked $reportRoot $true | Out-Null
    Assert-IgnoredUntracked $proofReportPath $false | Out-Null
    $proofText = [IO.File]::ReadAllText($proofReportPath, [Text.UTF8Encoding]::new($false))
    $proof = $proofText | ConvertFrom-Json -ErrorAction Stop
    if ($ExpectedCommit -notmatch '^[a-f0-9]{40}$' -or $ExpectedSourceDigest -notmatch '^[a-f0-9]{64}$') {
        Stop-V16ToV18DirectAttestation 'expected_source_closure_invalid'
    }
    if ($proof.format -ne 2 -or $proof.attempt -ne $attempt -or $proof.scope -ne 'SYNTHETIC_V4_MIGRATION' -or
        $proof.ports -ne ('127.0.0.1:' + $httpPort + '_' + $compatPort) -or $proof.source_schema -ne 16 -or $proof.target_schema -ne 18 -or
        $proof.migration -ne 'CURRENT_SOURCE_DIRECT_17_18' -or $proof.media -ne 'NOT_MOUNTED' -or
        ([string]$proof.legacy_fingerprint) -notmatch '^[a-f0-9]{64}$' -or ([string]$proof.source_commit) -notmatch '^[a-f0-9]{40}$' -or
        ([string]$proof.source_digest) -notmatch '^[a-f0-9]{64}$') {
        Stop-V16ToV18DirectAttestation 'direct_proof_report_invalid'
    }
    if ([string]$proof.source_commit -ne $ExpectedCommit -or [string]$proof.source_digest -ne $ExpectedSourceDigest) {
        Stop-V16ToV18DirectAttestation 'direct_proof_source_closure_stale'
    }
    if ([string]$proof.first_migration -notmatch '^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=migrate_current_source schema_from=16 schema_to=18 sequential=17_18 replay=NOT_APPLICABLE legacy_tables_preserved=PASS new_tables=EMPTY new_table_count=7 legacy_fingerprint=[a-f0-9]{64} media=NOT_TOUCHED$' -or
        [string]$proof.replay -notmatch '^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=migrate_current_source schema_from=18 schema_to=18 sequential=NOT_APPLICABLE replay=PASS new_tables=EMPTY legacy_fingerprint=[a-f0-9]{64} media=NOT_TOUCHED$' -or
        [string]$proof.verify -notmatch '^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=verify_current_source schema=18 ledger=18 new_tables=EMPTY legacy_fingerprint=[a-f0-9]{64} media=NOT_TOUCHED$' -or
        [string]$proof.fail_closed -ne 'V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=fail_closed unknown_schema=DENY scratch=DISPOSED') {
        Stop-V16ToV18DirectAttestation 'direct_proof_evidence_invalid'
    }
    foreach ($record in @([string]$proof.first_migration, [string]$proof.replay, [string]$proof.verify)) {
        $match = [regex]::Match($record, '(?:^|\s)legacy_fingerprint=([^\s]+)')
        if (-not $match.Success -or $match.Groups[1].Value -ne [string]$proof.legacy_fingerprint) {
            Stop-V16ToV18DirectAttestation 'direct_proof_fingerprint_inconsistent'
        }
    }
    return [ordered]@{
        sha256 = Get-FileSha256 $proofReportPath
        legacy_fingerprint = [string]$proof.legacy_fingerprint
        report = $proof
    }
}

function Get-RuntimeLockMetadata {
    $envPath = Join-Path $sandboxRoot 'config\.env.piwigo'
    Assert-IgnoredUntracked $envPath $false | Out-Null
    $values = @{}
    foreach ($line in ([IO.File]::ReadAllText($envPath, [Text.UTF8Encoding]::new($false)) -split "`r?`n")) {
        if ($line -eq '') { continue }
        if ($line -notmatch '^([A-Z][A-Z0-9_]*)=(.*)$') { Stop-V16ToV18DirectAttestation 'runtime_env_invalid' }
        if ($values.ContainsKey($Matches[1])) { Stop-V16ToV18DirectAttestation 'runtime_env_duplicate_key' }
        $values[[string]$Matches[1]] = [string]$Matches[2]
    }
    $safe = [ordered]@{}
    foreach ($key in @('COMPOSE_PROJECT_NAME','CLASS_ARCHIVE_HTTP_PORT','CLASS_ARCHIVE_COMPAT_HTTP_PORT','CLASS_ARCHIVE_BASE_URL','PIWIGO_IMAGE','MARIADB_IMAGE')) {
        if (-not $values.ContainsKey($key)) { Stop-V16ToV18DirectAttestation ('runtime_env_required_key_missing_' + $key.ToLowerInvariant()) }
        $safe[$key] = [string]$values[$key]
    }
    if ($safe.COMPOSE_PROJECT_NAME -ne 'class_archive_v18_synthetic_migration_attempt39' -or $safe.CLASS_ARCHIVE_HTTP_PORT -ne $httpPort -or
        $safe.CLASS_ARCHIVE_COMPAT_HTTP_PORT -ne $compatPort -or $safe.CLASS_ARCHIVE_BASE_URL -ne ('http://127.0.0.1:' + $httpPort) -or
        $safe.PIWIGO_IMAGE -notmatch '^piwigo/piwigo:16\.4\.0a@sha256:[a-f0-9]{64}$' -or $safe.MARIADB_IMAGE -notmatch '^mariadb:11\.8\.8@sha256:[a-f0-9]{64}$') {
        Stop-V16ToV18DirectAttestation 'runtime_lock_metadata_invalid'
    }
    return $safe
}

function Get-AttestationMaterial {
    $head = Get-Head
    $sources = Get-SourceClosure
    if ($head -ne (Get-Head)) { Stop-V16ToV18DirectAttestation 'source_head_changed_during_attestation_capture' }
    # A new attestation may only be made from a proof produced by this exact
    # HEAD/source closure. It cannot bless an old runtime run after code moved.
    $proof = Read-DirectProofReport $head ([string]$sources.digest)
    $runtime = Get-RuntimeLockMetadata
    return [ordered]@{
        head = $head
        source_digest = [string]$sources.digest
        sources = @($sources.sources)
        direct_proof_sha256 = [string]$proof.sha256
        legacy_fingerprint = [string]$proof.legacy_fingerprint
        runtime = $runtime
    }
}

function Create-Attestation {
    if (Test-Path -LiteralPath $attestationPath) { Stop-V16ToV18DirectAttestation 'attestation_already_exists' }
    $material = Get-AttestationMaterial
    $record = [ordered]@{
        format = 1
        kind = 'SYNTHETIC_DIRECT_V16_TO_V18_RUNTIME'
        result = 'PASS'
        created_at_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ss.fffffffZ')
        attempt = $attempt
        scope = 'SYNTHETIC_V4_MIGRATION'
        ports = ('127.0.0.1:' + $httpPort + '_' + $compatPort)
        commit = $material.head
        source_digest = $material.source_digest
        sources = $material.sources
        direct_proof_report_sha256 = $material.direct_proof_sha256
        legacy_fingerprint = $material.legacy_fingerprint
        runtime_lock = $material.runtime
        evidence = [ordered]@{
            first_migration = 'PASS'
            replay = 'PASS'
            verify = 'PASS'
            fail_closed = 'PASS'
            media = 'NOT_MOUNTED'
        }
    }
    $json = $record | ConvertTo-Json -Depth 8
    [IO.File]::WriteAllText($attestationPath, ($json + "`n"), [Text.UTF8Encoding]::new($false))
    Assert-IgnoredUntracked $attestationPath $false | Out-Null
    Write-V16ToV18DirectAttestation 'PASS' ('action=create commit=' + $material.head + ' source_digest=' + $material.source_digest + ' proof_sha256=' + $material.direct_proof_sha256 + ' attempt=attempt39 media=NOT_MOUNTED')
}

function Verify-Attestation {
    Assert-IgnoredUntracked $attestationPath $false | Out-Null
    $text = [IO.File]::ReadAllText($attestationPath, [Text.UTF8Encoding]::new($false))
    $record = $text | ConvertFrom-Json -ErrorAction Stop
    if ($record.format -ne 1 -or $record.kind -ne 'SYNTHETIC_DIRECT_V16_TO_V18_RUNTIME' -or $record.result -ne 'PASS' -or
        $record.attempt -ne $attempt -or $record.scope -ne 'SYNTHETIC_V4_MIGRATION' -or $record.ports -ne ('127.0.0.1:' + $httpPort + '_' + $compatPort) -or
        ([string]$record.commit) -notmatch '^[a-f0-9]{40}$' -or ([string]$record.source_digest) -notmatch '^[a-f0-9]{64}$' -or
        ([string]$record.direct_proof_report_sha256) -notmatch '^[a-f0-9]{64}$' -or ([string]$record.legacy_fingerprint) -notmatch '^[a-f0-9]{64}$' -or
        $record.evidence.first_migration -ne 'PASS' -or $record.evidence.replay -ne 'PASS' -or $record.evidence.verify -ne 'PASS' -or
        $record.evidence.fail_closed -ne 'PASS' -or $record.evidence.media -ne 'NOT_MOUNTED') {
        Stop-V16ToV18DirectAttestation 'attestation_manifest_invalid'
    }
    $material = Get-AttestationMaterial
    if ($record.commit -ne $material.head -or $record.source_digest -ne $material.source_digest -or
        $record.direct_proof_report_sha256 -ne $material.direct_proof_sha256 -or $record.legacy_fingerprint -ne $material.legacy_fingerprint) {
        Stop-V16ToV18DirectAttestation 'attestation_stale'
    }
    $recordRuntime = $record.runtime_lock
    foreach ($key in @('COMPOSE_PROJECT_NAME','CLASS_ARCHIVE_HTTP_PORT','CLASS_ARCHIVE_COMPAT_HTTP_PORT','CLASS_ARCHIVE_BASE_URL','PIWIGO_IMAGE','MARIADB_IMAGE')) {
        if ([string]$recordRuntime.$key -ne [string]$material.runtime.$key) { Stop-V16ToV18DirectAttestation 'attestation_runtime_lock_stale' }
    }
    # JSON restores entries as PSCustomObject while a fresh source closure
    # uses ordered dictionaries. Normalize both before sorting so a valid
    # attestation cannot fail merely due to PowerShell object representation.
    $recordSources = ConvertTo-NormalizedSourceEntries @($record.sources) 'attestation_source_entry_invalid'
    $materialSources = ConvertTo-NormalizedSourceEntries @($material.sources) 'attestation_source_entry_invalid'
    if ($recordSources.Count -ne $materialSources.Count) { Stop-V16ToV18DirectAttestation 'attestation_source_set_stale' }
    for ($index = 0; $index -lt $recordSources.Count; ++$index) {
        if ($recordSources[$index].path -ne $materialSources[$index].path -or $recordSources[$index].sha256 -ne $materialSources[$index].sha256) {
            Stop-V16ToV18DirectAttestation 'attestation_source_hash_stale'
        }
    }
    Write-V16ToV18DirectAttestation 'PASS' ('action=verify commit=' + $material.head + ' source_digest=' + $material.source_digest + ' proof_sha256=' + $material.direct_proof_sha256 + ' attempt=attempt39 media=NOT_MOUNTED')
}

try {
    switch ($Action) {
        'create' { Create-Attestation }
        'verify' { Verify-Attestation }
        'status' {
            $exists = Test-Path -LiteralPath $attestationPath
            Write-V16ToV18DirectAttestation 'STATUS' ('attempt=attempt39 attestation=' + $exists.ToString().ToUpperInvariant() + ' ports=127.0.0.1:11802_11803 media=NOT_MOUNTED')
        }
    }
} catch {
    # Do not emit PowerShell's path-rich error record for an ignored local
    # proof artifact. The consumer only needs a bounded fail-closed code.
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION_STOP:([a-z0-9_]{1,96})$') {
        $Matches[1]
    } else {
        $type = $_.Exception.GetType().Name
        if ($type -notmatch '^[A-Za-z0-9]{1,64}$') { $type = 'Exception' }
        'unexpected_' + $type.ToLowerInvariant()
    }
    Write-Output ('V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION=FAIL code=' + $code)
    exit 1
}
