[CmdletBinding()]
param()

# Real localhost HTTP regression for the public nginx surface. The test owns
# only uniquely named synthetic canaries below Piwigo's private `_data`
# directories and removes those exact files in `finally`.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
$script:assertions = 0
$script:probes = 0
$script:compose = @()
$script:canaryRunId = ''

function Assert-True {
    param(
        [Parameter(Mandatory = $true)][bool]$Condition,
        [Parameter(Mandatory = $true)][string]$Message
    )
    $script:assertions++
    if (-not $Condition) { throw "RUNTIME_SURFACE_HTTP: $Message" }
}

function Read-DotEnv {
    param([Parameter(Mandatory = $true)][string]$Path)
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { throw 'Invalid .env.piwigo syntax.' }
        $values[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
    }
    return $values
}

function Require-Setting {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Settings,
        [Parameter(Mandatory = $true)][string]$Key
    )
    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace([string]$Settings[$Key])) {
        throw "Missing required ignored local setting: $Key."
    }
    return [string]$Settings[$Key]
}

function Invoke-Fixture {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('setup', 'cleanup', 'status', 'find-assets')][string]$Action,
        [Parameter(Mandatory = $true)][string]$RunId
    )
    $command = [Collections.Generic.List[string]]::new()
    foreach ($item in $script:compose) { $command.Add($item) }
    foreach ($item in @(
        'exec', '-T', '--user', 'root', 'piwigo', 'php',
        '/workspace/tests/phase1/runtime-surface-fixture.php', $Action, $RunId
    )) { $command.Add($item) }

    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = @(& wsl.exe @command 2>&1) }
    finally { $ErrorActionPreference = $previousErrorAction }
    if ($LASTEXITCODE -ne 0 -or $output.Count -ne 1) {
        throw "The isolated runtime-surface fixture action '$Action' failed."
    }
    try { return ($output[0] | ConvertFrom-Json) }
    catch { throw "The isolated runtime-surface fixture action '$Action' returned invalid JSON." }
}

function Find-HeaderBoundary {
    param([Parameter(Mandatory = $true)][byte[]]$Bytes)
    for ($index = 0; $index -le $Bytes.Length - 4; $index++) {
        if (
            $Bytes[$index] -eq 13 -and $Bytes[$index + 1] -eq 10 -and
            $Bytes[$index + 2] -eq 13 -and $Bytes[$index + 3] -eq 10
        ) { return $index }
    }
    return -1
}

function Invoke-RawHttp {
    param(
        [Parameter(Mandatory = $true)][int]$Port,
        [Parameter(Mandatory = $true)][string]$RequestTarget,
        [ValidateRange(1024, 1048576)][int]$MaxResponseBytes = 131072
    )
    if ($RequestTarget -notmatch '^/' -or $RequestTarget -match "[\r\n]") {
        throw 'Unsafe raw HTTP request target in test definition.'
    }

    $script:probes++
    $client = [Net.Sockets.TcpClient]::new()
    try {
        $client.ReceiveTimeout = 30000
        $client.SendTimeout = 30000
        $client.Connect('127.0.0.1', $Port)
        $stream = $client.GetStream()
        try {
            $requestText = "GET $RequestTarget HTTP/1.1`r`nHost: localhost`r`nAccept: */*`r`nConnection: close`r`n`r`n"
            $requestBytes = [Text.Encoding]::ASCII.GetBytes($requestText)
            $stream.Write($requestBytes, 0, $requestBytes.Length)

            $memory = [IO.MemoryStream]::new()
            try {
                $buffer = New-Object byte[] 8192
                while ($memory.Length -lt $MaxResponseBytes) {
                    $remaining = [int]($MaxResponseBytes - $memory.Length)
                    $read = $stream.Read($buffer, 0, [Math]::Min($buffer.Length, $remaining))
                    if ($read -le 0) { break }
                    $memory.Write($buffer, 0, $read)
                }
                $wire = $memory.ToArray()
            } finally { $memory.Dispose() }
        } finally { $stream.Dispose() }
    } finally { $client.Dispose() }

    $boundary = Find-HeaderBoundary -Bytes $wire
    if ($boundary -lt 1) { throw 'Malformed HTTP response from localhost runtime.' }
    $headerText = [Text.Encoding]::GetEncoding(28591).GetString($wire, 0, $boundary)
    $statusMatch = [regex]::Match($headerText, '^HTTP/\d(?:\.\d)?\s+(\d{3})(?:\s|$)')
    if (-not $statusMatch.Success) { throw 'Missing HTTP status line from localhost runtime.' }

    $headers = @{}
    foreach ($line in ($headerText -split "`r`n" | Select-Object -Skip 1)) {
        $separator = $line.IndexOf(':')
        if ($separator -lt 1) { continue }
        $name = $line.Substring(0, $separator).Trim().ToLowerInvariant()
        if (-not $headers.ContainsKey($name)) { $headers[$name] = $line.Substring($separator + 1).Trim() }
    }
    $bodyOffset = $boundary + 4
    $bodyLength = [Math]::Max(0, $wire.Length - $bodyOffset)
    $body = New-Object byte[] $bodyLength
    if ($bodyLength -gt 0) { [Array]::Copy($wire, $bodyOffset, $body, 0, $bodyLength) }

    return [pscustomobject]@{
        Status = [int]$statusMatch.Groups[1].Value
        Headers = $headers
        Body = $body
        Text = [Text.Encoding]::UTF8.GetString($body)
    }
}

function Assert-Denied {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][string]$CanaryMarker,
        [Parameter(Mandatory = $true)][string]$CanaryFile,
        [switch]$ExactNotFound
    )
    if ($ExactNotFound) {
        Assert-True ($Response.Status -eq 404) "$Label was not the expected HTTP 404."
    } else {
        Assert-True ($Response.Status -in @(400, 403, 404)) "$Label was not denied with HTTP 400/403/404."
    }
    Assert-True (-not $Response.Headers.ContainsKey('location')) "$Label returned a redirect."
    Assert-True ($Response.Body.Length -le 8192) "$Label returned an oversized denial body."
    Assert-True ($Response.Text -notlike "*$CanaryMarker*") "$Label leaked synthetic canary content."
    Assert-True ($Response.Text -notlike "*$CanaryFile*") "$Label leaked the synthetic canary filename."
    Assert-True ($Response.Text -notlike "*$script:canaryRunId*") "$Label leaked the synthetic fixture identifier."
    Assert-True ($Response.Text -notmatch '(?i)(SQLSTATE|mysqli|stack trace|fatal error|PHP warning|/var/www/|document_root)') "$Label leaked a runtime diagnostic."
    $contentType = if ($Response.Headers.ContainsKey('content-type')) { [string]$Response.Headers['content-type'] } else { '' }
    Assert-True ($contentType -notlike 'image/*') "$Label returned an image MIME type."
}

function Assert-PublicAsset {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$ExpectedKind,
        [Parameter(Mandatory = $true)][string]$Label
    )
    Assert-True ($Response.Status -eq 200) "$Label was not HTTP 200."
    Assert-True ($Response.Body.Length -gt 0) "$Label returned an empty body."
    $contentType = if ($Response.Headers.ContainsKey('content-type')) { [string]$Response.Headers['content-type'] } else { '' }
    if ($ExpectedKind -eq 'css') {
        Assert-True ($contentType -match '(?i)(text/css|application/octet-stream)') "$Label returned an unexpected MIME type."
    } else {
        Assert-True ($contentType -match '(?i)(javascript|application/octet-stream)') "$Label returned an unexpected MIME type."
    }
    Assert-True (-not $Response.Headers.ContainsKey('location')) "$Label returned a redirect."
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv -Path $envPath
$portText = Require-Setting -Settings $settings -Key 'CLASS_ARCHIVE_HTTP_PORT'
if ($portText -notmatch '^[0-9]{1,5}$' -or [int]$portText -lt 1 -or [int]$portText -gt 65535) {
    throw 'Invalid localhost HTTP port.'
}
$port = [int]$portText
$script:compose = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)

$runId = ([Guid]::NewGuid().ToString('N').Substring(0, 16)).ToLowerInvariant()
$script:canaryRunId = $runId
$canaryFile = "class-archive-surface-$runId.canary"
$dotCanaryFile = ".class-archive-maintenance-$runId.canary"
$canaryMarker = "CLASS_ARCHIVE_RUNTIME_SURFACE_$($runId.ToUpperInvariant())"
$manifestFile = ".class-archive-runtime-surface-$runId.manifest"
$fixtureCreated = $false

Write-Output "RUNTIME_SURFACE_HTTP_RUN=$runId"

try {
    $preflight = Invoke-RawHttp -Port $port -RequestTarget '/'
    Assert-True ($preflight.Status -in @(200, 301, 302, 303)) 'The Piwigo localhost preflight was unavailable.'
    Assert-True ($preflight.Status -ne 503) 'The Piwigo localhost runtime remained in maintenance mode.'

    $initialFixture = Invoke-Fixture -Action status -RunId $runId
    Assert-True ($initialFixture.state -eq 'ABSENT') 'The isolated fixture was not absent at preflight.'
    $setupFixture = Invoke-Fixture -Action setup -RunId $runId
    Assert-True ($setupFixture.state -eq 'ACTIVE') 'The isolated fixture did not enter the active state.'
    $fixtureCreated = $true

    $privateTargets = @(
        "/_data/logs/$canaryFile",
        "/_data/tmp/$canaryFile",
        "/_data/cache/$canaryFile",
        "/_data/templates_c/$canaryFile",
        "/_data/maintenance/$canaryFile",
        "/_data/$canaryFile",
        "/_data/$dotCanaryFile",
        "/_data/$manifestFile",
        "/_data//logs/$canaryFile",
        "/_data/logs/../logs/$canaryFile",
        "/_data/%6cogs/$canaryFile",
        "/_data/logs/%63lass-archive-surface-$runId.canary",
        "/_data%2flogs/$canaryFile",
        "/_data/logs%2f$canaryFile",
        "/%5fdata/logs/$canaryFile",
        "//_data/logs/$canaryFile",
        "/_data/combined/../logs/$canaryFile",
        "/_data/combined/%2e%2e/logs/$canaryFile"
    )
    foreach ($target in $privateTargets) {
        $response = Invoke-RawHttp -Port $port -RequestTarget $target
        Assert-Denied -Response $response -Label 'private _data surface' -CanaryMarker $canaryMarker -CanaryFile $canaryFile
    }

    $runtimeTargets = @(
        '/install.php',
        '/upgrade.php',
        '/upgrade_feed.php',
        '/install',
        '/install/',
        '/install/index.php',
        '/tools',
        '/tools/',
        '/tools/index.php',
        '/%69nstall.php',
        '/install%2ephp',
        '//install.php',
        '/./install.php',
        '/install/../install.php',
        '/install%2f',
        '/%75pgrade.php',
        '/upgrade%2ephp',
        '//upgrade.php',
        '/./upgrade_feed.php',
        '/upgrade_feed%2ephp',
        '/%74ools',
        '/tools%2f',
        '//tools/',
        '/tools/../tools/'
    )
    foreach ($target in $runtimeTargets) {
        $response = Invoke-RawHttp -Port $port -RequestTarget $target
        $exact = $target -in @(
            '/install.php', '/upgrade.php', '/upgrade_feed.php', '/install', '/install/',
            '/install/index.php', '/tools', '/tools/', '/tools/index.php'
        )
        Assert-Denied -Response $response -Label 'disabled runtime entry point' -CanaryMarker $canaryMarker -CanaryFile $canaryFile -ExactNotFound:$exact
    }

    $assetOutput = Invoke-Fixture -Action find-assets -RunId $runId
    $cssPath = [string]$assetOutput.css
    $jsPath = [string]$assetOutput.js
    Assert-True ($cssPath -match '^_data/combined/[A-Za-z0-9._/-]+\.css$') 'Combined CSS fixture returned an unsafe path.'
    Assert-True ($jsPath -match '^_data/combined/[A-Za-z0-9._/-]+\.js$') 'Combined JS fixture returned an unsafe path.'
    Assert-PublicAsset -Response (Invoke-RawHttp -Port $port -RequestTarget "/$cssPath") -ExpectedKind 'css' -Label 'combined CSS'
    Assert-PublicAsset -Response (Invoke-RawHttp -Port $port -RequestTarget "/$jsPath") -ExpectedKind 'js' -Label 'combined JavaScript'
} finally {
    if ($fixtureCreated) {
        $cleanupFixture = Invoke-Fixture -Action cleanup -RunId $runId
        Assert-True ($cleanupFixture.state -eq 'ABSENT') 'The isolated fixture cleanup did not report absent.'
        $finalFixture = Invoke-Fixture -Action status -RunId $runId
        Assert-True ($finalFixture.state -eq 'ABSENT') 'The isolated fixture left residual state.'
    }
}

Write-Output "RUNTIME_SURFACE_HTTP_PASS probes=$script:probes assertions=$script:assertions"
