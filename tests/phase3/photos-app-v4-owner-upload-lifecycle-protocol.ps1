[CmdletBinding()]
param()

# Static-only safety protocol for the sealed private Owner lifecycle entrypoint.
# It must not access Docker/WSL, localhost, credentials, .codex-work, databases,
# media files or the private runtime. The only executed code is the inert sealed
# entrypoint itself, in a child PowerShell process, to prove both parameter
# combinations return the same explicit BLOCKED result.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wrapperPath = Join-Path $PSScriptRoot 'photos-app-v4-owner-upload-lifecycle.ps1'
$retiredBrowserPath = Join-Path $PSScriptRoot 'photos-app-v4-owner-upload-lifecycle.mjs'
$retiredFixturePath = Join-Path $PSScriptRoot 'photos-app-v4-owner-upload-lifecycle-fixture.php'
$docPath = Join-Path $projectRoot 'docs\photos-app-v4-owner-upload-lifecycle.md'
$assertions = 0

function Assert-OwnerUploadProtocol([bool]$Condition, [string]$Code) {
    $script:assertions += 1
    if (-not $Condition) { throw ('V4_OWNER_UPLOAD_PROTOCOL_FAIL:' + $Code) }
}

function Get-StrictUtf8([string]$Path) {
    Assert-OwnerUploadProtocol (Test-Path -LiteralPath $Path -PathType Leaf) 'source_missing'
    return [IO.File]::ReadAllText($Path, [Text.UTF8Encoding]::new($false, $true))
}

function Get-PowerShellParse([string]$Path) {
    $tokens = $null
    $errors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
    Assert-OwnerUploadProtocol ($null -ne $errors -and $errors.Count -eq 0) 'powershell_parse'
    return $ast
}

function Assert-Contains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-OwnerUploadProtocol ($Text.IndexOf($Needle, [StringComparison]::Ordinal) -ge 0) $Code
}

function Assert-NotMatch([string]$Text, [string]$Pattern, [string]$Code) {
    Assert-OwnerUploadProtocol ($Text -notmatch $Pattern) $Code
}

function Invoke-SealedEntryPoint([bool]$Confirmed) {
    $pwsh = if ($IsWindows) { Join-Path $PSHOME 'pwsh.exe' } else { Join-Path $PSHOME 'pwsh' }
    Assert-OwnerUploadProtocol (Test-Path -LiteralPath $pwsh -PathType Leaf) 'child_pwsh_missing'
    $arguments = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $wrapperPath)
    if ($Confirmed) { $arguments += '-ConfirmPrivateOwnerMutation' }
    $output = @(& $pwsh @arguments 2>&1 | ForEach-Object { [string]$_ })
    $exitCode = $LASTEXITCODE
    return [pscustomobject]@{ Output = $output; ExitCode = $exitCode }
}

try {
    $wrapperAst = Get-PowerShellParse $wrapperPath
    [void](Get-PowerShellParse $PSCommandPath)
    $wrapper = Get-StrictUtf8 $wrapperPath
    $doc = Get-StrictUtf8 $docPath

    # The previously executable helpers are removed, not merely hidden behind
    # an environment flag that could later be invoked directly.
    Assert-OwnerUploadProtocol (-not (Test-Path -LiteralPath $retiredBrowserPath)) 'dangerous_browser_helper_present'
    Assert-OwnerUploadProtocol (-not (Test-Path -LiteralPath $retiredFixturePath)) 'dangerous_cleanup_helper_present'

    Assert-Contains $wrapper '[switch]$ConfirmPrivateOwnerMutation' 'compatibility_switch_missing'
    Assert-Contains $wrapper 'BLOCKED_UNSAFE_ORIGIN_CLEANUP' 'explicit_unsafe_cleanup_block_missing'
    Assert-Contains $wrapper 'runtime=not_executed' 'runtime_evidence_boundary_missing'

    # Only Set-StrictMode and Write-Output may be executable commands. This AST
    # allowlist prevents a future edit from placing discovery or mutation before
    # the blocking exit while retaining the expected text later in the file.
    $commands = @($wrapperAst.FindAll({ param($node) $node -is [System.Management.Automation.Language.CommandAst] }, $true))
    $commandNames = @($commands | ForEach-Object { $_.GetCommandName() })
    Assert-OwnerUploadProtocol ($commandNames.Count -eq 2) 'sealed_command_count_invalid'
    Assert-OwnerUploadProtocol (@($commandNames | Where-Object { $_ -notin @('Set-StrictMode', 'Write-Output') }).Count -eq 0) 'sealed_command_allowlist_invalid'

    # No private origin, service, credential, filesystem or browser dispatch is
    # allowed in the sealed wrapper. These assertions specifically prevent the
    # previously reviewed in-place 8191 mutation path from returning.
    Assert-NotMatch $wrapper '(?i)8190|8191|private-full|docker|wsl|compose|mariadb|immich' 'no_private_runtime_reference'
    Assert-NotMatch $wrapper '(?i)invoke-webrequest|invoke-restmethod|start-process|launchpersistentcontext|new-classarchivesystemadminsession' 'no_runtime_dispatch'
    Assert-NotMatch $wrapper '(?i)writealltext|create(?:directory|file)|set-content|add-content|out-file|new-item|copy-item|move-item' 'no_file_write'
    Assert-NotMatch $wrapper '(?i)remove-item|unlink|delete_elements|\bdelete\s+from\b' 'no_delete_path'
    Assert-NotMatch $wrapper '(?i)remove-item[^\r\n]*-recurse' 'no_dangerous_recursive_remove'
    Assert-NotMatch $wrapper '(?i)delete\s+from[^\r\n]*audit_event' 'no_direct_audit_delete'
    Assert-NotMatch $wrapper '(?i)admin-session|cookie|claim|invite|password|token|secret-file' 'no_credential_path'
    Assert-NotMatch $wrapper '(?i)\.codex-work|photos-app-v4-owner-upload-lifecycle\.(?:mjs|php)' 'no_retired_helper_dispatch'

    # Their absence from the checked-out tree is the fail-closed proof that
    # neither the former DB-before-Core deletion nor DB-before-file deletion
    # order remains callable. CI evaluates this same assertion after commit.

    foreach ($confirmed in @($false, $true)) {
        $result = Invoke-SealedEntryPoint -Confirmed $confirmed
        Assert-OwnerUploadProtocol ($result.ExitCode -eq 2) 'sealed_exit_code_invalid'
        Assert-OwnerUploadProtocol (@($result.Output).Count -eq 1) 'sealed_output_count_invalid'
        Assert-OwnerUploadProtocol ([string]::Equals(
            [string]$result.Output[0],
            'V4_OWNER_UPLOAD_LIFECYCLE=BLOCKED code=BLOCKED_UNSAFE_ORIGIN_CLEANUP runtime=not_executed',
            [StringComparison]::Ordinal
        )) 'sealed_output_invalid'
    }

    Assert-Contains $doc 'BLOCKED_UNSAFE_ORIGIN_CLEANUP' 'doc_block_reason_missing'
    Assert-Contains $doc 'IN_PLACE_8191_MUTATION=PROHIBITED' 'doc_in_place_prohibition_missing'
    Assert-Contains $doc 'DIRECT_AUDIT_DELETE=PROHIBITED' 'doc_audit_delete_prohibition_missing'
    Assert-Contains $doc 'DB_BEFORE_CORE_OR_FILE_DELETE=PROHIBITED' 'doc_delete_order_prohibition_missing'
    Assert-Contains $doc 'DANGEROUS_RECURSIVE_REMOVE=PROHIBITED' 'doc_recursive_remove_prohibition_missing'
    Assert-Contains $doc 'STATIC_PROTOCOL_ONLY' 'doc_static_evidence_boundary'

    Write-Output ('V4_OWNER_UPLOAD_LIFECYCLE_PROTOCOL=PASS assertions=' + $assertions + ' runtime=blocked_only')
    exit 0
}
catch {
    $code = 'unexpected'
    if ($_.Exception.Message -match '^V4_OWNER_UPLOAD_PROTOCOL_FAIL:([A-Za-z0-9_]{1,120})$') {
        $code = [string]$Matches[1]
    }
    Write-Output ('V4_OWNER_UPLOAD_LIFECYCLE_PROTOCOL=FAIL code=' + $code)
    exit 1
}
