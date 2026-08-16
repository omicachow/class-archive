[CmdletBinding()]
param()

# Engineering-spike evidence, not a passing production gate. This probe exits
# zero only while the documented Piwigo 16.4 static-media ACL gap is reproduced.
# ClassArchivePolicy must replace it with a deny test before production.

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
$preview = Invoke-WebRequest -UseBasicParsing -Uri $previewUri -TimeoutSec 20
$original = Invoke-WebRequest -UseBasicParsing -Uri $originalUri -TimeoutSec 20
if (
    $preview.StatusCode -ne 200 -or $preview.Headers['Content-Type'] -notlike 'image/*' `
    -or $original.StatusCode -ne 200 -or $original.Headers['Content-Type'] -notlike 'image/*'
) {
    throw 'The known media gap was not reproduced; replace this probe with the production 403 regression gate.'
}

Write-Output 'KNOWN_MEDIA_ACL_GAP=CONFIRMED'
Write-Output 'GUEST_KNOWN_LIVING_DERIVATIVE=HTTP_200'
Write-Output 'GUEST_KNOWN_LIVING_ORIGINAL=HTTP_200'
