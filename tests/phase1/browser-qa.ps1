[CmdletBinding()]
param()

# Actual Chromium end-to-end acceptance for localhost synthetic data only.
# It creates a short-lived independent SYSTEM_ADMIN through the narrow PHP
# fixture, exercises all public/admin flows in a real browser, then removes
# every run-scoped identity, submission, image, anonymous comment and account.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
$node = 'C:\Users\Omica\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$nodeModules = 'C:\Users\Omica\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$script:runId = ''
$script:adminCreated = $false
$script:commentContextsPrepared = $false
$script:browserStarted = $false
$script:cleanupErrors = New-Object 'System.Collections.Generic.List[string]'
$script:primaryFailure = $null

function Read-DotEnv {
    param([Parameter(Mandatory = $true)][string]$Path)
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { throw 'Invalid ignored .env.piwigo syntax.' }
        $values[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
    }
    return $values
}

function New-RunId {
    $bytes = New-Object byte[] 6
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function New-TransientPassword {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    # Keep the browser-only secret high entropy but form-encoding-neutral so
    # this acceptance test diagnoses authorization, not punctuation handling.
    return 'Bqa' + (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Invoke-QuietWsl {
    param([Parameter(Mandatory = $true)][string[]]$WslArguments)

    $previousErrorAction = $ErrorActionPreference
    try {
        # docker compose emits benign copy progress on stderr. Capture native
        # output as data and decide only from its exit code.
        $ErrorActionPreference = 'Continue'
        $output = @(& wsl.exe @WslArguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorAction
    }
    return [pscustomobject]@{ ExitCode = $exitCode; Output = $output }
}

function Invoke-BrowserFixture {
    param(
        [Parameter(Mandatory = $true)][string]$Action,
        [Parameter(Mandatory = $true)][string]$RunId,
        [AllowEmptyString()][string]$Password = ''
    )
    $fixtureArgs = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', 'piwigo', 'php',
        '/workspace/tests/phase1/browser-qa-fixture.php', $Action, $RunId
    )
    if ($Password -ne '') {
        # Windows PowerShell 5.1 native-command stdin is BOM-prefixed even
        # when OutputEncoding is changed. Stage this one high-entropy secret
        # in an ignored, owner-only file, copy it to a short-lived 0600
        # nginx-owned container file, and feed PHP bytes with cat. The host
        # and container files are both removed in finally blocks.
        $secretName = '.browser-qa-secret-' + $RunId + '.bin'
        $secretHostPath = Join-Path $projectRoot (Join-Path '.codex-work' $secretName)
        $secretContainerPath = '/tmp/' + $secretName
        if (Test-Path -LiteralPath $secretHostPath) {
            throw 'Browser QA temporary secret path already exists.'
        }
        $secretDirectory = Split-Path -Parent $secretHostPath
        if (-not (Test-Path -LiteralPath $secretDirectory -PathType Container)) {
            [void][IO.Directory]::CreateDirectory($secretDirectory)
        }
        [IO.File]::WriteAllBytes($secretHostPath, (New-Object System.Text.UTF8Encoding($false)).GetBytes($Password))
        . (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
        Set-ClassArchiveOwnerOnlyFileAcl -Path $secretHostPath
        try {
            $copyArgs = @(
                '-d', 'Ubuntu', '--cd', $projectRoot, '--',
                'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
                'cp', ('.codex-work/' + $secretName), ('piwigo:' + $secretContainerPath)
            )
            $copyResult = Invoke-QuietWsl -WslArguments $copyArgs
            if ($copyResult.ExitCode -ne 0) { throw 'Could not stage the browser-QA container secret.' }
            $protectArgs = @(
                '-d', 'Ubuntu', '--cd', $projectRoot, '--',
                'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
                'exec', '-T', '--user', 'root', 'piwigo', 'sh', '-lc',
                ('test -f ' + $secretContainerPath + ' && chown nginx:nginx ' + $secretContainerPath + ' && chmod 0600 ' + $secretContainerPath)
            )
            $protectResult = Invoke-QuietWsl -WslArguments $protectArgs
            if ($protectResult.ExitCode -ne 0) { throw 'Could not secure the browser-QA container secret.' }
            $runArgs = @(
                '-d', 'Ubuntu', '--cd', $projectRoot, '--',
                'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
                'exec', '-T', '--user', 'nginx', '-e', ('CLASS_ARCHIVE_BROWSER_QA_SECRET_FILE=' + $secretContainerPath),
                'piwigo', 'php', '/workspace/tests/phase1/browser-qa-fixture.php', $Action, $RunId
            )
            $runResult = Invoke-QuietWsl -WslArguments $runArgs
            $output = @($runResult.Output)
            if ($runResult.ExitCode -ne 0) {
                $safeOutput = (($runResult.Output | ForEach-Object { [string]$_ }) -join ' ' -replace '[\r\n]+', ' ').Trim()
                if ($safeOutput.Length -gt 240) { $safeOutput = $safeOutput.Substring(0, 240) }
                throw "Browser QA fixture action $Action failed [$safeOutput]."
            }
        } finally {
            $removeContainerArgs = @(
                '-d', 'Ubuntu', '--cd', $projectRoot, '--',
                'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
                'exec', '-T', '--user', 'root', 'piwigo', 'sh', '-lc',
                ('test ! -e ' + $secretContainerPath + ' || rm -f -- ' + $secretContainerPath)
            )
            $removeResult = Invoke-QuietWsl -WslArguments $removeContainerArgs
            if ($removeResult.ExitCode -ne 0) { throw 'Browser QA container secret cleanup failed.' }
            if (Test-Path -LiteralPath $secretHostPath) {
                Remove-Item -LiteralPath $secretHostPath -Force -ErrorAction Stop
            }
            if (Test-Path -LiteralPath $secretHostPath) { throw 'Browser QA host secret cleanup failed.' }
        }
    } else {
        $output = @(& wsl.exe @fixtureArgs 2>&1)
    }
    if ($LASTEXITCODE -ne 0) { throw "Browser QA fixture action $Action failed." }
    try { return (($output -join "`n") | ConvertFrom-Json) }
    catch { throw "Browser QA fixture action $Action returned invalid JSON." }
}

function Invoke-ClassIdentityCleanup {
    param([Parameter(Mandatory = $true)][string]$RunId)
    $args = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', '-e', 'CI_TEST_BASELINE_IMAGE_COUNT=72', 'piwigo', 'php',
        '/workspace/tests/phase1/class-identity-fixture.php', 'cleanup', $RunId
    )
    $output = @(& wsl.exe @args 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'Browser QA ClassIdentity cleanup failed.' }
    try { return (($output -join "`n") | ConvertFrom-Json) }
    catch { throw 'Browser QA ClassIdentity cleanup returned invalid JSON.' }
}

function Assert-PhotoBaseline {
    $args = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/tests/phase0/assert-photo-model.php'
    )
    $output = @(& wsl.exe @args 2>&1)
    if ($LASTEXITCODE -ne 0 -or 'PHOTO_MODEL_ASSERTIONS=PASS' -notin $output) {
        throw 'Browser QA cleanup did not restore the canonical synthetic photo baseline.'
    }
}

if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) { throw 'Missing ignored .env.piwigo.' }
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'Bundled Node runtime is unavailable.' }
if (-not (Test-Path -LiteralPath $chrome -PathType Leaf)) { throw 'Local Google Chrome is unavailable for browser acceptance.' }

$settings = Read-DotEnv -Path $envPath
if (-not $settings.ContainsKey('CLASS_ARCHIVE_HTTP_PORT') -or $settings['CLASS_ARCHIVE_HTTP_PORT'] -notmatch '^\d{2,5}$') {
    throw 'Missing local HTTP port setting.'
}
$port = [int]$settings['CLASS_ARCHIVE_HTTP_PORT']
$screenshotDir = Join-Path $projectRoot '.codex-work\screenshots\phase1.5'
$profileDir = Join-Path $projectRoot ('.codex-work\browser-qa-profile-' + (New-RunId))
$adminPassword = $null
$previousNodePath = $env:NODE_PATH
$oldBrowserEnv = @{}

try {
    $script:runId = New-RunId
    # A prior interrupted run must not be silently reused. This namespace is
    # fresh, but invoking the exact cleanup first proves that condition.
    [void](Invoke-ClassIdentityCleanup -RunId $script:runId)

    $adminPassword = New-TransientPassword
    $admin = Invoke-BrowserFixture -Action 'create-admin' -RunId $script:runId -Password $adminPassword
    if ($null -eq $admin -or [string]$admin.username -notmatch '^bqa_admin_[a-f0-9]{12}$') {
        throw 'Browser QA SYSTEM_ADMIN fixture returned an invalid identity.'
    }
    $provisionFingerprintHasher = [Security.Cryptography.SHA256]::Create()
    try {
        $provisionFingerprint = (
            $provisionFingerprintHasher.ComputeHash([Text.Encoding]::UTF8.GetBytes($adminPassword)) |
                ForEach-Object { $_.ToString('x2') }
        ) -join ''
    } finally {
        $provisionFingerprintHasher.Dispose()
    }
    if ([string]$admin.password_sha256 -cne $provisionFingerprint) {
        throw "Browser QA SYSTEM_ADMIN credential changed during fixture provisioning (hostBytes=$([Text.Encoding]::UTF8.GetByteCount($adminPassword)), fixtureBytes=$([int]$admin.password_bytes), fixtureBom=$([bool]$admin.password_has_utf8_bom))."
    }
    $script:adminCreated = $true
    $media = Invoke-BrowserFixture -Action 'media' -RunId $script:runId
    if ([int]$media.heritage_image_id -le 0 -or [int]$media.living_image_id -le 0) {
        throw 'Browser QA media fixture returned invalid image identifiers.'
    }
    $mediaFingerprintHasher = [Security.Cryptography.SHA256]::Create()
    try {
        $mediaFingerprint = (
            $mediaFingerprintHasher.ComputeHash([Text.Encoding]::UTF8.GetBytes($adminPassword)) |
                ForEach-Object { $_.ToString('x2') }
        ) -join ''
    } finally {
        $mediaFingerprintHasher.Dispose()
    }
    if ([string]$admin.password_sha256 -cne $mediaFingerprint) {
        throw 'Browser QA SYSTEM_ADMIN credential changed while resolving synthetic media.'
    }
    $commentContexts = Invoke-BrowserFixture -Action 'prepare-comment-contexts' -RunId $script:runId
    if ($null -eq $commentContexts -or -not [bool]$commentContexts.prepared -or [int]$commentContexts.contexts -ne 2) {
        throw 'Browser QA could not establish two reversible synthetic comment contexts.'
    }
    $script:commentContextsPrepared = $true

    $oldBrowserEnv = @{
        CLASS_ARCHIVE_BROWSER_QA_RUN_ID = $env:CLASS_ARCHIVE_BROWSER_QA_RUN_ID
        CLASS_ARCHIVE_BROWSER_QA_ADMIN_USERNAME = $env:CLASS_ARCHIVE_BROWSER_QA_ADMIN_USERNAME
        CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD = $env:CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD
        CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD_SHA256 = $env:CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD_SHA256
        CLASS_ARCHIVE_BROWSER_QA_HERITAGE_IMAGE_ID = $env:CLASS_ARCHIVE_BROWSER_QA_HERITAGE_IMAGE_ID
        CLASS_ARCHIVE_BROWSER_QA_LIVING_IMAGE_ID = $env:CLASS_ARCHIVE_BROWSER_QA_LIVING_IMAGE_ID
        CLASS_ARCHIVE_BROWSER_QA_SCREENSHOT_DIR = $env:CLASS_ARCHIVE_BROWSER_QA_SCREENSHOT_DIR
        CLASS_ARCHIVE_BROWSER_QA_PROFILE_DIR = $env:CLASS_ARCHIVE_BROWSER_QA_PROFILE_DIR
        CLASS_ARCHIVE_BROWSER_QA_BASE_URL = $env:CLASS_ARCHIVE_BROWSER_QA_BASE_URL
        CLASS_ARCHIVE_BROWSER_QA_CHROME = $env:CLASS_ARCHIVE_BROWSER_QA_CHROME
    }
    $env:NODE_PATH = $nodeModules
    $env:CLASS_ARCHIVE_BROWSER_QA_RUN_ID = $script:runId
    $env:CLASS_ARCHIVE_BROWSER_QA_ADMIN_USERNAME = [string]$admin.username
    $env:CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD = $adminPassword
    $env:CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD_SHA256 = [string]$admin.password_sha256
    $env:CLASS_ARCHIVE_BROWSER_QA_HERITAGE_IMAGE_ID = [string]$media.heritage_image_id
    $env:CLASS_ARCHIVE_BROWSER_QA_LIVING_IMAGE_ID = [string]$media.living_image_id
    $env:CLASS_ARCHIVE_BROWSER_QA_SCREENSHOT_DIR = $screenshotDir
    $env:CLASS_ARCHIVE_BROWSER_QA_PROFILE_DIR = $profileDir
    $env:CLASS_ARCHIVE_BROWSER_QA_BASE_URL = "http://127.0.0.1:$port/"
    $env:CLASS_ARCHIVE_BROWSER_QA_CHROME = $chrome

    $expectedFingerprint = [string]$admin.password_sha256
    $fingerprintHasher = [Security.Cryptography.SHA256]::Create()
    try {
        $actualFingerprint = (
            $fingerprintHasher.ComputeHash([Text.Encoding]::UTF8.GetBytes($adminPassword)) |
                ForEach-Object { $_.ToString('x2') }
        ) -join ''
    } finally {
        $fingerprintHasher.Dispose()
    }
    if ($expectedFingerprint -notmatch '^[a-f0-9]{64}$' -or $expectedFingerprint -cne $actualFingerprint) {
        $expectedPrefix = $expectedFingerprint.Substring(0, [Math]::Min(8, $expectedFingerprint.Length))
        $actualPrefix = $actualFingerprint.Substring(0, [Math]::Min(8, $actualFingerprint.Length))
        throw "Browser QA SYSTEM_ADMIN credential handoff changed before Chromium launch (expectedLength=$($expectedFingerprint.Length), actualLength=$($actualFingerprint.Length), expectedPrefix=$expectedPrefix, actualPrefix=$actualPrefix)."
    }
    $nodeFingerprint = @(& $node -e "process.stdout.write(require('crypto').createHash('sha256').update(process.env.CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD,'utf8').digest('hex'))") -join ''
    if ($expectedFingerprint -cne $nodeFingerprint) {
        throw 'Browser QA SYSTEM_ADMIN credential handoff changed in the Node process environment.'
    }

    $script:browserStarted = $true
    $previousErrorAction = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $browserOutput = @(& $node (Join-Path $PSScriptRoot 'browser-qa.mjs') 2>&1)
        $browserExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorAction
    }
    $browserPassLines = @($browserOutput | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^AUTOMATED_BROWSER_QA=PASS\b' })
    if ($browserExit -ne 0 -or $browserPassLines.Count -ne 1) {
        $safeLines = @($browserOutput | ForEach-Object { [string]$_ } | Where-Object { $_ -match 'BROWSER_QA:' } | Select-Object -Last 1)
        $stageLines = @($browserOutput | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^BROWSER_QA_STAGE=[A-Za-z0-9_-]+$' } | Select-Object -Last 1)
        $detail = if ($safeLines.Count -eq 1) { ' (' + $safeLines[0] + ')' } else { '' }
        $stage = if ($stageLines.Count -eq 1) { ' last_stage=' + $stageLines[0].Substring('BROWSER_QA_STAGE='.Length) } else { '' }
        throw ('Chromium browser acceptance did not complete (node_exit=' + $browserExit + ').' + $stage + $detail)
    }
    Write-Output $browserPassLines[0]
} catch {
    $script:primaryFailure = $_
    throw
} finally {
    foreach ($name in @(
        'CLASS_ARCHIVE_BROWSER_QA_RUN_ID', 'CLASS_ARCHIVE_BROWSER_QA_ADMIN_USERNAME', 'CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD',
        'CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD_SHA256',
        'CLASS_ARCHIVE_BROWSER_QA_HERITAGE_IMAGE_ID', 'CLASS_ARCHIVE_BROWSER_QA_LIVING_IMAGE_ID',
        'CLASS_ARCHIVE_BROWSER_QA_SCREENSHOT_DIR', 'CLASS_ARCHIVE_BROWSER_QA_PROFILE_DIR',
        'CLASS_ARCHIVE_BROWSER_QA_BASE_URL', 'CLASS_ARCHIVE_BROWSER_QA_CHROME'
    )) {
        Remove-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
        if ($oldBrowserEnv.ContainsKey($name) -and $null -ne $oldBrowserEnv[$name]) {
            Set-Item -LiteralPath ("Env:$name") -Value $oldBrowserEnv[$name]
        }
    }
    if ($null -ne $previousNodePath) { $env:NODE_PATH = $previousNodePath } else { Remove-Item Env:NODE_PATH -ErrorAction SilentlyContinue }
    $adminPassword = $null

    if ($script:runId -match '^[a-f0-9]{12}$') {
        try { [void](Invoke-BrowserFixture -Action 'cleanup-comments' -RunId $script:runId) }
        catch { $script:cleanupErrors.Add('anonymous comments') }
        if ($script:commentContextsPrepared) {
            try { [void](Invoke-BrowserFixture -Action 'cleanup-comment-contexts' -RunId $script:runId) }
            catch { $script:cleanupErrors.Add('comment contexts') }
        }
        try { [void](Invoke-ClassIdentityCleanup -RunId $script:runId) }
        catch { $script:cleanupErrors.Add('ClassIdentity namespace') }
        if ($script:adminCreated) {
            try { [void](Invoke-BrowserFixture -Action 'cleanup-admin' -RunId $script:runId) }
            catch { $script:cleanupErrors.Add('SYSTEM_ADMIN fixture') }
        }
        try { Assert-PhotoBaseline }
        catch { $script:cleanupErrors.Add('photo baseline') }
    }
    if (Test-Path -LiteralPath $profileDir) {
        try { Remove-Item -LiteralPath $profileDir -Recurse -Force -ErrorAction Stop }
        catch { $script:cleanupErrors.Add('browser profile') }
    }
    if ($script:cleanupErrors.Count -gt 0) {
        $message = 'Browser QA cleanup failed: ' + ($script:cleanupErrors -join ', ') + '.'
        if ($null -eq $script:primaryFailure) {
            throw $message
        }
        [Console]::Error.WriteLine($message)
    }
}

Write-Output "AUTOMATED_BROWSER_QA=PASS run=$script:runId screenshots=$screenshotDir cleanup=verified"
