[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('synthetic', 'private')]
    [string]$Environment,

    [Parameter(Mandatory = $true)]
    [string]$CredentialFile
)

# Real Chromium QA for the owned Phase 3 Photo UI. The credential document is
# an ignored, owner-only local artifact. This wrapper passes only its path to
# Node; passwords never appear in argv, stdout, a committed file, or a report.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$workRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work'))
$credentialPath = (Resolve-Path -LiteralPath $CredentialFile).Path
$separator = [IO.Path]::DirectorySeparatorChar
if (-not $credentialPath.StartsWith($workRoot + $separator, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'phase3_browser_credential_must_be_under_ignored_work_root'
}

& git -C $projectRoot check-ignore --quiet -- $credentialPath
if ($LASTEXITCODE -ne 0) { throw 'phase3_browser_credential_is_not_git_ignored' }

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath

$userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
$programFiles = [Environment]::GetFolderPath([Environment+SpecialFolder]::ProgramFiles)
$bundledDependencies = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies'
$node = if ($env:CLASS_ARCHIVE_PHASE3_BROWSER_NODE) { $env:CLASS_ARCHIVE_PHASE3_BROWSER_NODE } else { Join-Path $bundledDependencies 'node\bin\node.exe' }
$nodeModules = if ($env:CLASS_ARCHIVE_PHASE3_BROWSER_NODE_MODULES) { $env:CLASS_ARCHIVE_PHASE3_BROWSER_NODE_MODULES } else { Join-Path $bundledDependencies 'node\node_modules' }
$chrome = if ($env:CLASS_ARCHIVE_PHASE3_BROWSER_CHROME) { $env:CLASS_ARCHIVE_PHASE3_BROWSER_CHROME } else { Join-Path $programFiles 'Google\Chrome\Application\chrome.exe' }
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'phase3_browser_node_unavailable' }
if (-not (Test-Path -LiteralPath $chrome -PathType Leaf)) { throw 'phase3_browser_chrome_unavailable' }

$ports = if ($Environment -eq 'private') { @(8190, 8191) } else { @(8090, 8091) }
$piwigoOrigin = "http://127.0.0.1:$($ports[0])/"
$photoOrigin = "http://127.0.0.1:$($ports[1])/"

# A fresh run directory avoids overwriting prior visual evidence. Private-data
# screenshots can only land below the dedicated ignored private QA root.
$random = New-Object byte[] 6
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $rng.GetBytes($random) } finally { $rng.Dispose() }
$runId = (($random | ForEach-Object { $_.ToString('x2') }) -join '')
$screenshotBase = if ($Environment -eq 'private') {
    Join-Path $projectRoot '.codex-work\private-real-qa\screenshots\phase3'
} else {
    Join-Path $projectRoot '.codex-work\screenshots\phase3'
}
$screenshotDirectory = Join-Path $screenshotBase $runId
$profileDirectory = Join-Path $projectRoot ('.codex-work\browser-profiles\phase3-' + $Environment + '-' + $runId)
[void][IO.Directory]::CreateDirectory($screenshotDirectory)
[void][IO.Directory]::CreateDirectory($profileDirectory)

$oldNodePath = $env:NODE_PATH
$names = @(
    'CLASS_ARCHIVE_PHASE3_ENVIRONMENT',
    'CLASS_ARCHIVE_PHASE3_PIWIGO_ORIGIN',
    'CLASS_ARCHIVE_PHASE3_PHOTO_ORIGIN',
    'CLASS_ARCHIVE_PHASE3_CREDENTIAL_FILE',
    'CLASS_ARCHIVE_PHASE3_SCREENSHOT_DIR',
    'CLASS_ARCHIVE_PHASE3_PROFILE_DIR',
    'CLASS_ARCHIVE_PHASE3_CHROME'
)
$oldValues = @{}
foreach ($name in $names) {
    $current = Get-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
    $oldValues[$name] = if ($null -ne $current) { [string]$current.Value } else { $null }
}

try {
    # Readiness is a narrow loopback check. Redirects are expected because an
    # unauthenticated request must be sent to Piwigo login, never into Immich.
    foreach ($origin in @($piwigoOrigin, $photoOrigin)) {
        try {
            $request = [Net.HttpWebRequest]::Create($origin)
            $request.Method = 'HEAD'
            $request.AllowAutoRedirect = $false
            $request.Timeout = 5000
            $response = $request.GetResponse()
            $status = [int]$response.StatusCode
            $response.Close()
        }
        catch [Net.WebException] {
            if ($null -eq $_.Exception.Response) { throw 'phase3_browser_loopback_runtime_unavailable' }
            $status = [int]$_.Exception.Response.StatusCode
            $_.Exception.Response.Close()
        }
        if ($status -lt 200 -or $status -ge 500) { throw 'phase3_browser_loopback_runtime_unhealthy' }
    }

    $env:NODE_PATH = $nodeModules
    $env:CLASS_ARCHIVE_PHASE3_ENVIRONMENT = $Environment
    $env:CLASS_ARCHIVE_PHASE3_PIWIGO_ORIGIN = $piwigoOrigin
    $env:CLASS_ARCHIVE_PHASE3_PHOTO_ORIGIN = $photoOrigin
    $env:CLASS_ARCHIVE_PHASE3_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_PHASE3_SCREENSHOT_DIR = $screenshotDirectory
    $env:CLASS_ARCHIVE_PHASE3_PROFILE_DIR = $profileDirectory
    $env:CLASS_ARCHIVE_PHASE3_CHROME = $chrome

    $previousErrorAction = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $node (Join-Path $PSScriptRoot 'photo-ui-browser-qa.mjs') 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorAction
    }
    $safeLines = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^PHOTO_UI_BROWSER_QA=(?:PASS|FAIL)\b' -or $_ -match '^PHOTO_UI_BROWSER_STAGE=[a-z0-9_-]+$'
    })
    $pass = @($safeLines | Where-Object { $_ -match '^PHOTO_UI_BROWSER_QA=PASS\b' })
    if ($exitCode -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safeLines | Where-Object { $_ -match '^PHOTO_UI_BROWSER_QA=FAIL\b' } | Select-Object -Last 1)
        $stage = @($safeLines | Where-Object { $_ -match '^PHOTO_UI_BROWSER_STAGE=' } | Select-Object -Last 1)
        $detail = if ($failure.Count -eq 1) { ' ' + $failure[0] } else { '' }
        $where = if ($stage.Count -eq 1) { ' ' + $stage[0] } else { '' }
        throw ('phase3_browser_qa_failed' + $where + $detail)
    }
    Write-Output $pass[0]
    Write-Output ('PHOTO_UI_BROWSER_SCREENSHOTS=' + $screenshotDirectory)
}
finally {
    foreach ($name in $names) {
        Remove-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
        if ($null -ne $oldValues[$name]) { Set-Item -LiteralPath ("Env:$name") -Value $oldValues[$name] }
    }
    if ($null -ne $oldNodePath) { $env:NODE_PATH = $oldNodePath } else { Remove-Item Env:NODE_PATH -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $profileDirectory) {
        Remove-Item -LiteralPath $profileDirectory -Recurse -Force -ErrorAction SilentlyContinue
    }
}
