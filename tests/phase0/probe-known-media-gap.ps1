[CmdletBinding()]
param()

# Fast regression for the exact Piwigo 16.4 static-media bypass reproduced by
# the architecture spike. The prior insecure behavior is preserved in Git
# history; the current gate succeeds only when both known URLs are denied.

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$settings = @{}
foreach ($line in [IO.File]::ReadAllLines((Join-Path $projectRoot '.env.piwigo'))) {
    $trimmed = $line.Trim()
    if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
    $separator = $trimmed.IndexOf('=')
    if ($separator -gt 0) {
        $settings[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
    }
}

$baseUri = [Uri]("http://127.0.0.1:$($settings['CLASS_ARCHIVE_HTTP_PORT'])/")
$null = Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($baseUri, 'identification.php')) -SessionVariable adminSession
$form = 'username=' + [Uri]::EscapeDataString($settings['PIWIGO_ADMIN_USERNAME']) `
    + '&password=' + [Uri]::EscapeDataString($settings['PIWIGO_ADMIN_PASSWORD']) `
    + '&login=Sign%20in&redirect='
$null = Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($baseUri, 'identification.php')) `
    -Method Post -Body $form -ContentType 'application/x-www-form-urlencoded' -WebSession $adminSession

$album = Invoke-WebRequest -UseBasicParsing `
    -Uri ([Uri]::new($baseUri, 'index.php?/category/fixture-living-reunion')) `
    -WebSession $adminSession
$pictureLink = $album.Links | Where-Object { $_.href -match '^picture\.php\?/' } | Select-Object -First 1
if ($null -eq $pictureLink) { throw 'No synthetic LIVING photo found.' }
$picture = Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($baseUri, $pictureLink.href)) -WebSession $adminSession
$previewMatch = [regex]::Match(
    $picture.Content,
    '(?:src|data-src)="((?:i\.php\?/|_data/i/)(upload/.+?)-me\.png)"'
)
if (-not $previewMatch.Success) { throw 'Could not resolve the LIVING media paths.' }

$previewUri = [Uri]::new($baseUri, $previewMatch.Groups[1].Value)
$originalUri = [Uri]::new($baseUri, $previewMatch.Groups[2].Value + '.png')
function Get-DeniedStatus([Uri]$Uri) {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $Uri -TimeoutSec 20
        return [int]$response.StatusCode
    }
    catch {
        return [int]$_.Exception.Response.StatusCode
    }
}

$previewStatus = Get-DeniedStatus $previewUri
$originalStatus = Get-DeniedStatus $originalUri
if ($previewStatus -notin @(401, 403, 404) -or $originalStatus -notin @(401, 403, 404)) {
    throw "Known media URL bypass remains: derivative=$previewStatus original=$originalStatus"
}

Write-Output 'KNOWN_MEDIA_ACL_GAP=RESOLVED'
Write-Output 'GUEST_KNOWN_LIVING_DERIVATIVE=DENY'
Write-Output 'GUEST_KNOWN_LIVING_ORIGINAL=DENY'
