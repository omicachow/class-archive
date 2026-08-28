[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('status', 'initialize', 'probe')]
    [string]$Action = 'status'
)

# Runtime media for the full private library lives in Docker-managed local
# volumes. This is intentional: Docker Desktop resolves those volumes inside
# its own Linux storage namespace, where POSIX modes and ACLs are real. A mount
# created only in a developer WSL distro is not a safe bind source for Docker
# Desktop, and raw removable-drive binds do not preserve chmod(0660).
#
# The opaque, read-only ingress staging directory is checked elsewhere. It is
# never a runtime media volume and no original source directory is accepted by
# this script.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$wsl = "$env:SystemRoot\System32\wsl.exe"
$piwigoImage = 'piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84'
$script:stage = 'initialization'

function Stop-Storage([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_FULL_STORAGE_STOP:' + $Code)
}

function Invoke-Docker([string[]]$Arguments) {
    $output = @(& $wsl -d Ubuntu --exec docker @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) { Stop-Storage 'docker_command_failed' }
    return @($output | ForEach-Object { [string]$_ })
}

function Assert-DockerEngine {
    $record = @(Invoke-Docker @('info', '--format', '{{.DockerRootDir}}') | Where-Object { $_ -ne '' })
    if ($record.Count -ne 1 -or $record[0] -ne '/var/lib/docker') {
        Stop-Storage 'docker_root_invalid'
    }
}

function Invoke-PosixVolumeProbe {
    $suffix = [Guid]::NewGuid().ToString('N')
    $volume = 'class_archive_private_full_probe_' + $suffix
    if ($volume -notmatch '^class_archive_private_full_probe_[0-9a-f]{32}$') {
        Stop-Storage 'probe_volume_name_invalid'
    }
    $script:stage = 'posix_volume_probe'
    try {
        [void](Invoke-Docker @('volume', 'create', '--label', 'com.classarchive.scope=private-real-full-probe', $volume))
        $script = 'set -eu; touch /payload/mode; chmod 0660 /payload/mode; test "$(stat -c %a /payload/mode)" = 660; setfacl -m u:1000:rw /payload/mode; getfacl -p /payload/mode >/dev/null; test "$(stat -c %a /payload/mode)" = 660'
        $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($script))
        [void](Invoke-Docker @('run', '--rm', '--network', 'none', '--entrypoint', 'sh', '--mount', ('type=volume,source=' + $volume + ',target=/payload'), $piwigoImage, '-lc', ('printf %s ' + $encoded + ' | base64 -d | sh')))
    }
    finally {
        # This is an exact, newly-created empty probe volume only. It never
        # addresses a project media volume or an owner-controlled path.
        if ($volume -notmatch '^class_archive_private_full_probe_[0-9a-f]{32}$') {
            Stop-Storage 'probe_cleanup_target_invalid'
        }
        $previous = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $null = & $wsl -d Ubuntu --exec docker volume rm $volume 2>$null
        }
        finally { $ErrorActionPreference = $previous }
    }
}

try {
    $script:stage = 'docker_engine'
    Assert-DockerEngine
    if ($Action -in @('initialize', 'probe')) {
        Invoke-PosixVolumeProbe
    }
    Write-Output ('PRIVATE_FULL_STORAGE=PASS action=' + $Action + ' mode=DOCKER_MANAGED_POSIX_VOLUMES payload=DOCKER_VOLUME_POSIX_PROBED at_rest_owner_acl=DOCKER_DESKTOP_LOCAL_ONLY production_gate=BLOCKED')
    exit 0
}
catch {
    $failureCode = $null
    if ($_.Exception.Message -match '^PRIVATE_FULL_STORAGE_STOP:([a-z0-9_]{1,128})$') { $failureCode = [string]$Matches[1] }
    if ($null -ne $failureCode) {
        Write-Output "PRIVATE_FULL_STORAGE=FAIL stage=$script:stage code=$failureCode"
        exit 2
    }
    $type = $_.Exception.GetType().Name
    if ($type -notmatch '^[A-Za-z0-9]{1,64}$') { $type = 'Exception' }
    Write-Output ('PRIVATE_FULL_STORAGE=FAIL stage=' + $script:stage + ' code=unexpected_' + $type)
    exit 2
}
