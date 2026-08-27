[CmdletBinding()]
param()

# Protocol tests for the public Git boundary. Every repository and blob made
# here is synthetic, run-scoped and stored under the ignored .codex-work root.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$gate = Join-Path $projectRoot 'infra\scripts\verify-public-boundary.ps1'
$shell = (Get-Process -Id $PID).Path
$workParent = Join-Path $projectRoot '.codex-work\public-boundary-protocol'
$runRoot = Join-Path $workParent ([Guid]::NewGuid().ToString('N'))
$script:assertions = 0

function Assert-Protocol([bool]$Condition, [string]$Reason) {
    $script:assertions++
    if (!$Condition) { throw $Reason }
}

function Invoke-TestGit([string]$Repository, [string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & git -C $Repository @Arguments 1>$null 2>$null
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($code -ne 0) { throw 'protocol_git_command_failed' }
}

function Get-TestGitValue([string]$Repository, [string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $values = @(& git -C $Repository @Arguments 2>$null)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($code -ne 0 -or $values.Count -ne 1) { throw 'protocol_git_value_failed' }
    return [string]$values[0]
}

function New-TestRepository([string]$Name) {
    if ($Name -notmatch '^[a-z0-9-]+$') { throw 'protocol_repository_name_invalid' }
    $repository = Join-Path $runRoot $Name
    [void](New-Item -ItemType Directory -Path $repository -Force)
    Invoke-TestGit $repository @('init', '-q')
    Invoke-TestGit $repository @('config', 'user.name', 'Synthetic Boundary Test')
    Invoke-TestGit $repository @('config', 'user.email', 'synthetic-boundary@example.invalid')
    [IO.File]::WriteAllText((Join-Path $repository 'README.md'), "synthetic public boundary fixture`n", [Text.UTF8Encoding]::new($false))
    Invoke-TestGit $repository @('add', '--', 'README.md')
    Invoke-TestGit $repository @('commit', '-q', '-m', 'synthetic baseline')
    return $repository
}

function Invoke-BoundaryGate([string]$Repository, [string]$Mode, [string]$BaseRef = '') {
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $gate, '-Mode', $Mode, '-RepositoryRoot', $Repository)
    if ($BaseRef -ne '') { $arguments += @('-BaseRef', $BaseRef) }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& $shell @arguments 2>$null)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    return [pscustomobject]@{
        ExitCode = $code
        Output = (($lines | ForEach-Object { [string]$_ }) -join "`n").Trim()
    }
}

function Assert-Pass($Result, [string]$Mode) {
    Assert-Protocol ($Result.ExitCode -eq 0) 'protocol_expected_pass'
    Assert-Protocol ($Result.Output -match ('^PUBLIC_BOUNDARY=PASS mode=' + $Mode.ToUpperInvariant() + ' commits=[0-9]+ blobs=[0-9]+$')) 'protocol_pass_output_invalid'
}

function Assert-Fail($Result, [string]$Reason, [string]$PrivateMarker) {
    Assert-Protocol ($Result.ExitCode -ne 0) 'protocol_expected_failure'
    Assert-Protocol ($Result.Output -match ('^PUBLIC_BOUNDARY=FAIL reason=' + [regex]::Escape($Reason) + ' count=[1-9][0-9]*$')) 'protocol_failure_output_invalid'
    Assert-Protocol (!$Result.Output.Contains($PrivateMarker)) 'protocol_private_marker_disclosed'
    Assert-Protocol (@($Result.Output -split "`n").Count -eq 1) 'protocol_failure_was_not_single_line'
}

try {
    if (!(Test-Path -LiteralPath $gate -PathType Leaf)) { throw 'protocol_gate_missing' }
    [void](New-Item -ItemType Directory -Path $runRoot -Force)

    $safe = New-TestRepository 'safe-index'
    Assert-Pass (Invoke-BoundaryGate $safe 'Index') 'Index'

    $safeOutgoing = New-TestRepository 'safe-outgoing'
    $safeOutgoingBase = Get-TestGitValue $safeOutgoing @('rev-parse', 'HEAD')
    [IO.File]::WriteAllText((Join-Path $safeOutgoing 'safe-change.md'), "synthetic safe change`n", [Text.UTF8Encoding]::new($false))
    Invoke-TestGit $safeOutgoing @('add', '--', 'safe-change.md')
    Invoke-TestGit $safeOutgoing @('commit', '-q', '-m', 'synthetic safe change')
    Assert-Pass (Invoke-BoundaryGate $safeOutgoing 'Outgoing' $safeOutgoingBase) 'Outgoing'

    # An event base that is syntactically valid but no longer reachable (for
    # example after a rewritten branch) must fail closed rather than silently
    # reducing the scan to HEAD.
    $unreachableBase = ('f' * 40)
    Assert-Fail (Invoke-BoundaryGate $safeOutgoing 'Outgoing' $unreachableBase) 'git_command_failed' 'unreachable-base-marker'

    $allowlist = New-TestRepository 'synthetic-allowlist'
    $fixtureDirectory = Join-Path $allowlist 'tests\fixtures\phase2-synthetic'
    [void](New-Item -ItemType Directory -Path $fixtureDirectory -Force)
    $fixtureName = 'fictional-cast-classroom.png'
    [IO.File]::Copy(
        (Join-Path $projectRoot ('tests\fixtures\phase2-synthetic\' + $fixtureName)),
        (Join-Path $fixtureDirectory $fixtureName),
        $false
    )
    Invoke-TestGit $allowlist @('add', '--', ('tests/fixtures/phase2-synthetic/' + $fixtureName))
    Assert-Pass (Invoke-BoundaryGate $allowlist 'Index') 'Index'

    $tampered = New-TestRepository 'tampered-allowlist'
    $tamperedDirectory = Join-Path $tampered 'tests\fixtures\phase2-synthetic'
    [void](New-Item -ItemType Directory -Path $tamperedDirectory -Force)
    $tamperedPath = Join-Path $tamperedDirectory $fixtureName
    [IO.File]::Copy((Join-Path $projectRoot ('tests\fixtures\phase2-synthetic\' + $fixtureName)), $tamperedPath, $false)
    $tamperedBytes = [IO.File]::ReadAllBytes($tamperedPath)
    $tamperedBytes[$tamperedBytes.Length - 1] = $tamperedBytes[$tamperedBytes.Length - 1] -bxor 0x01
    [IO.File]::WriteAllBytes($tamperedPath, $tamperedBytes)
    Invoke-TestGit $tampered @('add', '--', ('tests/fixtures/phase2-synthetic/' + $fixtureName))
    Assert-Fail (Invoke-BoundaryGate $tampered 'Index') 'synthetic_fixture_digest' 'tampered-allowlist'

    $privatePath = New-TestRepository 'private-path'
    $privateMarker = 'private-path-marker'
    $privateDirectory = Join-Path $privatePath '.private-real-qa'
    [void](New-Item -ItemType Directory -Path $privateDirectory -Force)
    [IO.File]::WriteAllText((Join-Path $privateDirectory ($privateMarker + '.json')), '{}', [Text.UTF8Encoding]::new($false))
    Invoke-TestGit $privatePath @('add', '-f', '--', ('.private-real-qa/' + $privateMarker + '.json'))
    Assert-Fail (Invoke-BoundaryGate $privatePath 'Index') 'forbidden_path' $privateMarker

    $extension = New-TestRepository 'private-extension'
    $extensionMarker = 'private-extension-marker'
    [IO.File]::WriteAllText((Join-Path $extension ($extensionMarker + '.npy')), 'synthetic', [Text.UTF8Encoding]::new($false))
    Invoke-TestGit $extension @('add', '-f', '--', ($extensionMarker + '.npy'))
    Assert-Fail (Invoke-BoundaryGate $extension 'Index') 'forbidden_extension' $extensionMarker

    $magic = New-TestRepository 'private-magic'
    $magicMarker = 'private-magic-marker'
    $magicBytes = [Collections.Generic.List[byte]]::new()
    $magicBytes.AddRange([byte[]](0x89,0x50,0x4E,0x47,0x0D,0x0A,0x1A,0x0A))
    $magicBytes.AddRange([Text.Encoding]::ASCII.GetBytes($magicMarker))
    [IO.File]::WriteAllBytes((Join-Path $magic ($magicMarker + '.txt')), $magicBytes.ToArray())
    Invoke-TestGit $magic @('add', '--', ($magicMarker + '.txt'))
    Assert-Fail (Invoke-BoundaryGate $magic 'Index') 'private_blob_magic' $magicMarker

    $absolute = New-TestRepository 'private-absolute-path'
    $absoluteMarker = 'private-absolute-marker'
    $syntheticAbsolute = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) $absoluteMarker
    [IO.File]::WriteAllText((Join-Path $absolute 'configuration.txt'), ('runtime=' + $syntheticAbsolute), [Text.UTF8Encoding]::new($false))
    Invoke-TestGit $absolute @('add', '--', 'configuration.txt')
    Assert-Fail (Invoke-BoundaryGate $absolute 'Index') 'private_absolute_path' $absoluteMarker

    $portableAbsolute = New-TestRepository 'private-portable-absolute-path'
    $portableMarker = 'private-portable-marker'
    $portableDrive = 'Q' + ':' + '/' + 'Synthetic' + '/'
    [IO.File]::WriteAllText((Join-Path $portableAbsolute 'configuration.txt'), ('runtime=' + $portableDrive + $portableMarker), [Text.UTF8Encoding]::new($false))
    Invoke-TestGit $portableAbsolute @('add', '--', 'configuration.txt')
    Assert-Fail (Invoke-BoundaryGate $portableAbsolute 'Index') 'private_absolute_path' $portableMarker

    $markdownAbsolute = New-TestRepository 'private-markdown-absolute-path'
    $markdownMarker = 'private-markdown-marker'
    $markdownDrive = 'Q' + ':' + [char]92 + 'Synthetic' + [char]92
    $markdownFence = [char]96
    [IO.File]::WriteAllText(
        (Join-Path $markdownAbsolute 'notes.md'),
        ('local path: ' + $markdownFence + $markdownDrive + $markdownMarker + $markdownFence),
        [Text.UTF8Encoding]::new($false)
    )
    Invoke-TestGit $markdownAbsolute @('add', '--', 'notes.md')
    Assert-Fail (Invoke-BoundaryGate $markdownAbsolute 'Index') 'private_absolute_path' $markdownMarker

    $history = New-TestRepository 'outgoing-history'
    $base = Get-TestGitValue $history @('rev-parse', 'HEAD')
    $historyMarker = 'outgoing-private-marker'
    $historyDirectory = Join-Path $history 'real-data'
    [void](New-Item -ItemType Directory -Path $historyDirectory -Force)
    [IO.File]::WriteAllText((Join-Path $historyDirectory ($historyMarker + '.json')), '{}', [Text.UTF8Encoding]::new($false))
    Invoke-TestGit $history @('add', '-f', '--', ('real-data/' + $historyMarker + '.json'))
    Invoke-TestGit $history @('commit', '-q', '-m', 'synthetic private history')
    [IO.File]::Delete((Join-Path $historyDirectory ($historyMarker + '.json')))
    Invoke-TestGit $history @('add', '-A')
    Invoke-TestGit $history @('commit', '-q', '-m', 'remove synthetic private history')
    Assert-Pass (Invoke-BoundaryGate $history 'Head') 'Head'
    Assert-Fail (Invoke-BoundaryGate $history 'Outgoing' $base) 'forbidden_path' $historyMarker

    Write-Output "PUBLIC_BOUNDARY_PROTOCOL=PASS assertions=$script:assertions"
} catch {
    $reason = [string]$_.Exception.Message
    if ($reason -notmatch '^[a-z0-9_]{1,64}$') { $reason = 'unexpected' }
    Write-Output "PUBLIC_BOUNDARY_PROTOCOL=FAIL reason=$reason"
    exit 1
} finally {
    if (Test-Path -LiteralPath $runRoot) {
        $resolvedParent = [IO.Path]::GetFullPath($workParent).TrimEnd([char[]]@([char]92, [char]47))
        $resolvedRun = [IO.Path]::GetFullPath($runRoot).TrimEnd([char[]]@([char]92, [char]47))
        $expectedPrefix = $resolvedParent + [IO.Path]::DirectorySeparatorChar
        if ($resolvedRun.StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $resolvedRun -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

# PowerShell on Linux can otherwise propagate the final deliberately failing
# native Git probe through $LASTEXITCODE even though every protocol assertion
# passed. Make the successful contract explicit for GitHub Actions.
exit 0
