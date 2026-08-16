Set-StrictMode -Version Latest

function New-ClassArchiveHexId {
    param([ValidateRange(8, 64)][int]$Bytes = 12)
    $buffer = New-Object byte[] $Bytes
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return (($buffer | ForEach-Object { $_.ToString('x2') }) -join '')
}

function ConvertTo-ClassArchiveNativeArgument {
    param([AllowEmptyString()][string]$Value)

    if ($Value.Length -eq 0) { return '""' }
    if ($Value -notmatch '[\s"]') { return $Value }

    $builder = [Text.StringBuilder]::new()
    [void]$builder.Append('"')
    $backslashes = 0
    foreach ($character in $Value.ToCharArray()) {
        if ($character -eq '\') {
            $backslashes++
            continue
        }
        if ($character -eq '"') {
            [void]$builder.Append(('\' * (($backslashes * 2) + 1)))
            [void]$builder.Append('"')
            $backslashes = 0
            continue
        }
        if ($backslashes -gt 0) {
            [void]$builder.Append(('\' * $backslashes))
            $backslashes = 0
        }
        [void]$builder.Append($character)
    }
    if ($backslashes -gt 0) {
        [void]$builder.Append(('\' * ($backslashes * 2)))
    }
    [void]$builder.Append('"')
    return $builder.ToString()
}

function Invoke-ClassArchiveNativeWithInput {
    param(
        [Parameter(Mandatory = $true)][string]$FileName,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [AllowNull()][string]$StandardInput = $null
    )

    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $FileName
    $startInfo.Arguments = (($Arguments | ForEach-Object {
        ConvertTo-ClassArchiveNativeArgument -Value ([string]$_)
    }) -join ' ')
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true

    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    try {
        if (-not $process.Start()) { throw 'The native helper process did not start.' }
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $inputStream = $process.StandardInput.BaseStream
        if ($null -ne $StandardInput) {
            # Bypass StreamWriter: Windows PowerShell 5.1 prefixes its native
            # UTF-8 stdin with a BOM, which would mutate the exact cookie.
            $inputBytes = [Text.Encoding]::ASCII.GetBytes($StandardInput)
            try { $inputStream.Write($inputBytes, 0, $inputBytes.Length) }
            finally { [Array]::Clear($inputBytes, 0, $inputBytes.Length) }
        }
        $inputStream.Flush()
        $inputStream.Close()
        $process.WaitForExit()
        $stdout = $stdoutTask.GetAwaiter().GetResult()
        $stderr = $stderrTask.GetAwaiter().GetResult()
        return [pscustomobject]@{
            ExitCode = $process.ExitCode
            Output = @($stdout, $stderr)
        }
    }
    finally {
        $StandardInput = $null
        $process.Dispose()
    }
}

function Invoke-ClassArchiveSessionFixture {
    param(
        [Parameter(Mandatory = $true)][string[]]$ComposeBase,
        [Parameter(Mandatory = $true)][ValidateSet('mint', 'revoke', 'status')][string]$Action,
        [Parameter(Mandatory = $true)][string]$Handle,
        [string]$AdminUsername = '',
        [AllowNull()][string]$GuestCookie = $null,
        [AllowEmptyString()][string]$FaultInjection = ''
    )

    if ($FaultInjection -notin @('', 'after_db_commit_before_json')) {
        throw 'Unknown synthetic SYSTEM_ADMIN session fault injection.'
    }
    if ($FaultInjection.Length -gt 0 -and $Action -ne 'mint') {
        throw 'Synthetic SYSTEM_ADMIN session faults are valid only while minting.'
    }

    $arguments = [Collections.Generic.List[string]]::new()
    foreach ($argument in $ComposeBase) { $arguments.Add($argument) }
    foreach ($argument in @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_ALLOW_SYNTHETIC_ADMIN_SESSION=1'
    )) { $arguments.Add($argument) }
    if ($FaultInjection.Length -gt 0) {
        $arguments.Add('-e')
        $arguments.Add("CLASS_ARCHIVE_SYNTHETIC_SESSION_FAULT=$FaultInjection")
    }
    foreach ($argument in @(
        'piwigo', 'php', '/workspace/tests/support/system-admin-session.php', $Action, $Handle
    )) { $arguments.Add($argument) }
    if (-not [string]::IsNullOrEmpty($AdminUsername)) { $arguments.Add($AdminUsername) }

    $native = Invoke-ClassArchiveNativeWithInput -FileName 'wsl.exe' `
        -Arguments $arguments.ToArray() -StandardInput $GuestCookie
    $GuestCookie = $null
    $combinedOutput = ($native.Output -join '')
    if ([int]$native.ExitCode -ne 0) {
        if (
            $FaultInjection -eq 'after_db_commit_before_json' `
            -and $combinedOutput -match '(?m)^SYNTHETIC_SESSION_FAULT=after_db_commit_before_json\r?$'
        ) {
            throw 'Observed injected SYSTEM_ADMIN session failure after the database transition committed.'
        }
        throw "The test-only SYSTEM_ADMIN session fixture failed during $Action."
    }
    try { return (($combinedOutput.Trim()) | ConvertFrom-Json) }
    catch { throw "The test-only SYSTEM_ADMIN session fixture returned invalid output during $Action." }
}

function New-ClassArchiveSystemAdminSession {
    param(
        [Parameter(Mandatory = $true)][Uri]$BaseUri,
        [Parameter(Mandatory = $true)][string[]]$ComposeBase,
        [Parameter(Mandatory = $true)][string]$AdminUsername,
        [AllowEmptyString()][string]$FaultInjection = ''
    )

    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $statusUri = [Uri]::new($BaseUri, 'ws.php?format=json')
    try {
        $guestResponse = Invoke-WebRequest -UseBasicParsing `
            -Uri ([Uri]::new($BaseUri, 'identification.php')) `
            -WebSession $session -TimeoutSec 30
    }
    catch {
        throw 'Could not create the fresh guest HTTP session required by the SYSTEM_ADMIN fixture.'
    }
    if ([int]$guestResponse.StatusCode -ne 200) {
        throw 'The SYSTEM_ADMIN fixture preflight did not receive the login page.'
    }

    $cookies = @($session.Cookies.GetCookies($BaseUri) | Where-Object {
        $_.Name -eq 'pwg_id' -and $_.Value -match '^[A-Za-z0-9,-]{16,128}$'
    })
    if ($cookies.Count -ne 1) {
        throw 'The fresh guest HTTP session did not produce one bounded Piwigo cookie.'
    }

    $handle = New-ClassArchiveHexId
    $cookieValue = [string]$cookies[0].Value
    try {
        $result = Invoke-ClassArchiveSessionFixture -ComposeBase $ComposeBase -Action mint `
            -Handle $handle -AdminUsername $AdminUsername -GuestCookie $cookieValue `
            -FaultInjection $FaultInjection
        if (-not [bool]$result.ok -or [string]$result.handle -ne $handle) {
            throw 'The SYSTEM_ADMIN session lease was not established.'
        }
        $verified = Invoke-RestMethod -Uri $statusUri -Method Post -Body @{
            method = 'pwg.session.getStatus'
        } -WebSession $session -TimeoutSec 30
        $verifiedAdmin = $verified.stat -eq 'ok' `
            -and [string]$verified.result.username -eq $AdminUsername `
            -and [string]$verified.result.status -in @('admin', 'webmaster')
        if (-not $verifiedAdmin) {
            throw 'The elevated session did not resolve as the requested SYSTEM_ADMIN over real HTTP.'
        }
    }
    catch {
        $originalError = $_
        try {
            $revocation = Invoke-ClassArchiveSessionFixture -ComposeBase $ComposeBase -Action revoke -Handle $handle
            $safeCleanup = [bool]$revocation.ok `
                -and ([bool]$revocation.revoked -or [bool]$revocation.absent)
            if (-not $safeCleanup) {
                throw 'The fixture did not prove exact revocation or safe pre-lease absence.'
            }
        }
        catch {
            throw 'SYSTEM_ADMIN HTTP verification failed and exact fixture revocation also failed.'
        }
        throw $originalError
    }
    finally {
        $cookieValue = $null
    }

    return [pscustomobject]@{
        Handle = $handle
        Session = $session
        AdminUserId = [int]$result.admin_user_id
        ComposeBase = $ComposeBase
    }
}

function Get-ClassArchiveSystemAdminSessionFixtureState {
    param(
        [Parameter(Mandatory = $true)][string[]]$ComposeBase,
        [Parameter(Mandatory = $true)][string]$AdminUsername
    )

    $result = Invoke-ClassArchiveSessionFixture -ComposeBase $ComposeBase -Action status `
        -Handle (New-ClassArchiveHexId) -AdminUsername $AdminUsername
    if (
        -not [bool]$result.ok `
        -or [int]$result.lease_count -lt 0 `
        -or [int]$result.admin_session_count -lt 0
    ) {
        throw 'The SYSTEM_ADMIN session fixture state was not proven.'
    }
    return [pscustomobject]@{
        LeaseCount = [int]$result.lease_count
        AdminSessionCount = [int]$result.admin_session_count
    }
}

function Remove-ClassArchiveSystemAdminSession {
    param([Parameter(Mandatory = $true)]$Lease)

    $result = Invoke-ClassArchiveSessionFixture -ComposeBase ([string[]]$Lease.ComposeBase) `
        -Action revoke -Handle ([string]$Lease.Handle)
    if (
        -not [bool]$result.ok `
        -or -not [bool]$result.revoked `
        -or [bool]$result.absent
    ) {
        throw 'The test-only SYSTEM_ADMIN session was not exactly revoked.'
    }
    if ($null -ne $Lease.Session) {
        $Lease.Session.Cookies = [Net.CookieContainer]::new()
    }
}
