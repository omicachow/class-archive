[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw $Message }
}

function Read-DotEnv([string]$Path) {
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { throw 'Invalid .env.piwigo line.' }
        $values[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
    }
    return $values
}

if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing ignored .env.piwigo.'
}
$settings = Read-DotEnv $envPath
$baseUri = [Uri]('http://127.0.0.1:' + $settings['CLASS_ARCHIVE_HTTP_PORT'] + '/')

$guest = Invoke-WebRequest -UseBasicParsing -Uri $baseUri -TimeoutSec 20
Assert-True ($guest.StatusCode -eq 200) 'Guest entry page did not respond.'
Assert-True ($guest.Content -match '<title>Identification \| Class Archive</title>') 'Guest was not forced to the identification surface.'
Assert-True ($guest.Content -notmatch 'name="remember_me"') 'Remember-me is still exposed in the private V1 baseline.'
Assert-True ($guest.Content -notmatch 'i\.php\?/upload/') 'Guest entry page leaked a formal-photo derivative URL.'

$registrationDenied = $false
try {
    Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($baseUri, 'register.php')) -TimeoutSec 20 | Out-Null
}
catch {
    $registrationDenied = [int]$_.Exception.Response.StatusCode -eq 403
}
Assert-True $registrationDenied 'Open registration did not fail with HTTP 403.'

$null = Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($baseUri, 'identification.php')) -SessionVariable session -TimeoutSec 20
$encodedLogin = 'username=' + [Uri]::EscapeDataString($settings['PIWIGO_ADMIN_USERNAME']) `
    + '&password=' + [Uri]::EscapeDataString($settings['PIWIGO_ADMIN_PASSWORD']) `
    + '&login=Sign%20in&redirect='
$login = Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($baseUri, 'identification.php')) `
    -Method Post -Body $encodedLogin -ContentType 'application/x-www-form-urlencoded' `
    -WebSession $session -TimeoutSec 30
Assert-True ($login.Content -match 'Hello classarchive-admin!') 'Bootstrap administrator login failed.'

$gallery = Invoke-WebRequest -UseBasicParsing -Uri $baseUri -WebSession $session -TimeoutSec 30
Assert-True ($gallery.Content -match 'themes/bootstrap_darkroom/') 'Pinned Bootstrap Darkroom theme is not rendering.'
Assert-True ($gallery.Content -match 'data-src="i\.php\?/upload/.+-cu_e520x360\.') 'Album grid did not use a generated cover derivative.'

$albumUri = [Uri]::new($baseUri, 'index.php?/category/fixture-heritage-graduation')
$album = Invoke-WebRequest -UseBasicParsing -Uri $albumUri -WebSession $session -TimeoutSec 30
Assert-True ($album.Content -match 'class="card-img-top thumb-img"') 'Photo album grid is missing.'
Assert-True ($album.Content -match 'data-src="i\.php\?/upload/.+-cu_e520x360\.') 'Photo grid is not thumbnail-first.'
Assert-True ($album.Content -notmatch '<img[^>]+(?:src|data-src)="upload/') 'Photo grid references an original file directly.'

$pictureLink = $album.Links | Where-Object { $_.href -match '^picture\.php\?/' } | Select-Object -First 1
Assert-True ($null -ne $pictureLink) 'No photo viewer link was rendered.'
$picture = Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($baseUri, $pictureLink.href)) -WebSession $session -TimeoutSec 30
Assert-True ($picture.Content -match 'id="startPhotoSwipe"') 'Fullscreen PhotoSwipe trigger is missing.'
Assert-True ($picture.Content -match 'new PhotoSwipe\(') 'PhotoSwipe viewer integration is missing.'
Assert-True ($picture.Content -match '<link rel="prefetch" href="i\.php\?/.+-me\.') 'Adjacent preview preload is missing.'
Assert-True ($picture.Content -match '(?:src|data-src)="(?:i\.php\?/|_data/i/)upload/.+-me\.') 'Viewer does not default to a screen-sized preview.'
Assert-True ($picture.Content -match 'href="action\.php\?id=[0-9]+&amp;part=e&amp;download"') 'Explicit original download action is missing.'

$previewMatch = [regex]::Match($picture.Content, '(?:src|data-src)="((?:i\.php\?/|_data/i/)upload/.+?-me\.[a-z0-9]+)"')
Assert-True $previewMatch.Success 'Could not locate viewer preview URL.'
$preview = Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($baseUri, $previewMatch.Groups[1].Value)) -WebSession $session -TimeoutSec 30
Assert-True ($preview.StatusCode -eq 200 -and $preview.RawContentLength -gt 1000) 'Screen preview could not be loaded.'
Assert-True ($preview.Headers['Content-Type'] -like 'image/*') 'Screen preview response was not an image.'

Write-Output 'PHOTO_UI_SMOKE=PASS'
Write-Output 'GUEST_PRIVATE=PASS'
Write-Output 'OPEN_REGISTRATION_DISABLED=PASS'
Write-Output 'REMEMBER_ME_DISABLED=PASS'
Write-Output 'THUMBNAIL_FIRST=PASS'
Write-Output 'PHOTOSWIPE_INTEGRATION_MARKERS=PASS'
