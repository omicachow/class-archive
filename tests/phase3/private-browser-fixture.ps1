[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('prepare', 'rotate')]
    [string]$Action = 'prepare',

    [ValidateSet('private', 'synthetic')]
    [string]$Environment = 'private',

    [string]$CredentialFile = ''
)

# Local-only synthetic principals for Phase 3 browser QA. The private and
# canonical environments use the same role workflow but distinct compose
# state, ports and ignored credential roots. Passwords are passed through an
# owner-only file consumed by Piwigo; they never appear in argv, environment
# values, stdout or Git.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runtimeRelative = if ($Environment -eq 'private') { '.codex-work\private-real-qa\runtime\browser' } else { '.codex-work\runtime\phase3-browser' }
$fixtureLabel = if ($Environment -eq 'private') { 'PRIVATE' } else { 'SYNTHETIC' }
$privateRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot $runtimeRelative))
$compose = if ($Environment -eq 'private') {
    @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec',
        'docker', 'compose', '--env-file', 'infra/private-qa/.env.piwigo',
        '-f', 'infra/docker-compose.yml', '-f', 'infra/private-qa/docker-compose.override.yml',
        '-p', 'class_archive_private_qa_piwigo'
    )
} else {
    @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec',
        'docker', 'compose', '--env-file', '.env.piwigo',
        '-f', 'infra/docker-compose.yml'
    )
}
$corePort = if ($Environment -eq 'private') { 8190 } else { 8090 }
$wsUri = [Uri]("http://127.0.0.1:$corePort/ws.php?format=json")
$photosUri = [Uri]("http://127.0.0.1:$corePort/api/photos")

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')

function New-SecretText {
    $bytes = New-Object byte[] 36
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Assert-PrivateFile([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    if (-not $full.StartsWith($privateRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'private_browser_path_outside_runtime'
    }
    & git -C $projectRoot check-ignore --quiet --no-index -- $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    if ($LASTEXITCODE -ne 0) { throw 'private_browser_path_not_ignored' }
    if (@(& git -C $projectRoot ls-files -- $full.Substring($projectRoot.Length + 1).Replace('\', '/')).Count -ne 0) {
        throw 'private_browser_path_tracked'
    }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $full
}

function Write-OwnerOnly([string]$Path, [string]$Value) {
    if (Test-Path -LiteralPath $Path) { throw 'private_browser_output_exists' }
    $directory = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) { [void][IO.Directory]::CreateDirectory($directory) }
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-PrivateFile $Path
}

function Invoke-Piwigo([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe @($compose + $Arguments) 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { throw 'private_browser_piwigo_command_failed' }
    return [string]::Join("`n", $lines)
}

function Provision-Fixtures([string]$Password, [string]$Run, [string]$HostPassword) {
    if ($Password -notmatch '^[A-Za-z0-9._~-]{32,190}$' -or $Run -notmatch '^[a-f0-9]{16}$') {
        throw 'private_browser_secret_invalid'
    }
    $container = '/tmp/class-archive-fixture-password-' + $Run + '.txt'
    Write-OwnerOnly $HostPassword $Password
    try {
        $relative = $HostPassword.Substring($projectRoot.Length + 1).Replace('\', '/')
        [void](Invoke-Piwigo @('cp', $relative, ('piwigo:' + $container)))
        [void](Invoke-Piwigo @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $container + ' && chmod 0600 ' + $container)))
        $result = Invoke-Piwigo @('exec', '-T', '--user', 'nginx', '-e', ('CLASS_ARCHIVE_FIXTURE_PASSWORD_FILE=' + $container), 'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php')
        if ($result.Trim() -ne 'ACCESS_FIXTURES_READY') { throw 'private_browser_fixture_rejected' }
    } finally {
        try { [void](Invoke-Piwigo @('exec', '-T', '--user', 'nginx', 'piwigo', 'rm', '-f', '--', $container)) } catch { }
        if (Test-Path -LiteralPath $HostPassword) { Remove-Item -LiteralPath $HostPassword -Force }
    }
}

function Login-Classmate([string]$Password) {
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $login = Invoke-RestMethod -Uri $wsUri -Method Post -Body @{
        method = 'pwg.session.login'; username = 'fixture-classmate'; password = $Password
    } -WebSession $session -TimeoutSec 30
    if ($login.stat -ne 'ok' -or -not [bool]$login.result) { throw 'private_browser_classmate_login_failed' }
    return $session
}

$runBytes = New-Object byte[] 8
$runRng = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $runRng.GetBytes($runBytes) } finally { $runRng.Dispose() }
$run = (($runBytes | ForEach-Object { $_.ToString('x2') }) -join '')
$work = Join-Path $privateRoot $run
$passwordPath = Join-Path $work 'fixture-password.txt'
$password = New-SecretText

try {
    Provision-Fixtures -Password $password -Run $run -HostPassword $passwordPath
    if ($Action -eq 'rotate') {
        if ([string]::IsNullOrWhiteSpace($CredentialFile)) { throw 'private_browser_credential_required' }
        $target = (Resolve-Path -LiteralPath $CredentialFile).Path
        Assert-PrivateFile $target
        if ([IO.Path]::GetFileName($target) -ne 'credentials.json') { throw 'private_browser_credential_name_invalid' }
        Remove-Item -LiteralPath $target -Force
        if (Test-Path -LiteralPath $target) { throw 'private_browser_credential_cleanup_failed' }
        Write-Output ($fixtureLabel + '_BROWSER_FIXTURE=PASS action=rotate')
        exit 0
    }

    if (-not [string]::IsNullOrWhiteSpace($CredentialFile)) { throw 'private_browser_prepare_credential_forbidden' }
    $session = Login-Classmate $password
    $photos = Invoke-RestMethod -Uri $photosUri -Method Get -WebSession $session -Headers @{ Accept = 'application/json' } -TimeoutSec 60
    $living = @($photos.items | Where-Object { [string]$_.era -eq 'LIVING' -and [string]$_.id -match '^[0-9a-f-]{36}$' } | Select-Object -First 1)
    if ($photos.total -lt 1 -or $living.Count -ne 1) { throw 'private_browser_living_fixture_missing' }
    $credentialPath = Join-Path $work 'credentials.json'
    $credential = [ordered]@{
        version = 1
        environment = $Environment
        familyDeniedPhotoId = [string]$living[0].id
        roles = [ordered]@{
            classmate = [ordered]@{ username = 'fixture-classmate'; password = $password }
            family = [ordered]@{ username = 'fixture-family'; password = $password }
            teacher = [ordered]@{ username = 'fixture-teacher'; password = $password }
            anonymous = [ordered]@{ username = 'fixture-anonymous'; password = $password }
        }
    }
    Write-OwnerOnly $credentialPath ($credential | ConvertTo-Json -Compress -Depth 5)
    Write-Output ($fixtureLabel + '_BROWSER_FIXTURE=PASS action=prepare credential=' + $credentialPath)
} finally {
    $password = $null
    if (Test-Path -LiteralPath $passwordPath) { Remove-Item -LiteralPath $passwordPath -Force }
}
