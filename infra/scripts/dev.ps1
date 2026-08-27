[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('up', 'stop', 'down', 'ps', 'logs', 'pull', 'config', 'bootstrap', 'extensions', 'extensions-verify', 'class-plugins', 'class-plugins-verify', 'identity-bootstrap', 'identity-bootstrap-synthetic', 'baseline-verify', 'seed', 'normalize-media-permissions', 'test-access', 'test-phase0', 'test-phase1', 'test-phase2-contract', 'test-phase3-contract', 'test-phase2-gateway-http', 'test-phase2-performance-contract', 'test-phase2-archive-timeline-runtime', 'test-phase2-runtime', 'test-phase2-runtime-integration', 'test-phase2-immich-gateway-bridge', 'test-phase2-immich-web-compat', 'phase2-ml-readiness', 'browser-qa', 'backup')]
    [string]$Action = 'ps'
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')
if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing .env.piwigo. Run infra\scripts\init-dev-env.ps1 first.'
}
Assert-ClassArchiveOwnerOnlyFileAcl -Path $envPath
if ([IO.File]::ReadAllText($envPath) -match '(?m)^[ \t]*PIWIGO_ADMIN_PASSWORD[ \t]*=') {
    throw 'Refusing long-lived PIWIGO_ADMIN_PASSWORD in .env.piwigo; run remove-admin-password-from-env.ps1.'
}

$runtimeDirectory = Join-Path $projectRoot '.codex-work'
$keepAlivePidPath = Join-Path $runtimeDirectory 'wsl-keepalive.pid'
$classPluginWorkflowLockPath = Join-Path $runtimeDirectory 'runtime\class-plugin-workflow.lock'
. (Join-Path $PSScriptRoot 'class-plugin-workflow-lock.ps1')

function Get-KeepAliveProcess {
    if (-not (Test-Path -LiteralPath $keepAlivePidPath)) {
        return $null
    }

    $storedPid = [int]([IO.File]::ReadAllText($keepAlivePidPath).Trim())
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $storedPid" -ErrorAction SilentlyContinue
    if ($process -and $process.Name -eq 'wsl.exe' -and $process.CommandLine -match '--exec sleep infinity') {
        return $process
    }

    Remove-Item -LiteralPath $keepAlivePidPath -Force -ErrorAction SilentlyContinue
    return $null
}

function Start-KeepAlive {
    if (Get-KeepAliveProcess) {
        return
    }

    New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
    $process = Start-Process -FilePath "$env:SystemRoot\System32\wsl.exe" `
        -ArgumentList @('-d', 'Ubuntu', '--exec', 'sleep', 'infinity') `
        -WindowStyle Hidden -PassThru
    [IO.File]::WriteAllText($keepAlivePidPath, [string]$process.Id, [Text.UTF8Encoding]::new($false))
}

function Stop-KeepAlive {
    $process = Get-KeepAliveProcess
    if ($process) {
        Stop-Process -Id $process.ProcessId -Force
    }
    Remove-Item -LiteralPath $keepAlivePidPath -Force -ErrorAction SilentlyContinue
}

$composeArguments = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--env-file', '.env.piwigo',
    '-f', 'infra/docker-compose.yml'
)

# The public synthetic Photo UI is deliberately a separate, internal-only BFF
# behind Piwigo nginx. Keep its lifecycle tied to `dev up`/`dev stop` so 8091
# never relies on a private QA compatibility service merely because it happens
# to share a Docker host.
$publicCompatComposeArguments = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--env-file', 'infra/immich-spike/.env',
    '-f', 'infra/immich-spike/docker-compose.yml',
    '-p', 'class-archive-immich-spike',
    '--profile', 'immich-web-compat'
)
$publicCompatContainer = 'class-archive-immich-spike-immich-web-compat-1'

$classPluginWorkflow = $false
$classPluginSynthetic = $false

function Wait-ClassArchiveMaintenanceReady {
    # Docker's public healthcheck intentionally receives 503 while the durable
    # maintenance marker is present. During this bounded window, exact nginx
    # fail-closed output plus the later CLI runtime verifier is the readiness
    # contract. The normal Docker healthcheck resumes after finalization.
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $previousErrorAction = $ErrorActionPreference
        try {
            # A just-recreated container can refuse the first few loopback
            # connections. Windows PowerShell promotes native stderr to a
            # terminating ErrorRecord under Stop, so keep this expected retry
            # condition inside the bounded readiness loop.
            $ErrorActionPreference = 'Continue'
            $probeLines = @(& wsl.exe @($composeArguments + @(
                'exec', '-T', 'piwigo',
                'curl', '--silent', '--show-error',
                '--write-out', 'CLASS_ARCHIVE_STATUS:%{http_code}',
                'http://127.0.0.1/'
            )) 2>&1)
            $probeExit = $LASTEXITCODE
        }
        finally {
            $ErrorActionPreference = $previousErrorAction
        }
        if (
            $probeExit -eq 0 `
            -and $probeLines.Count -eq 2 `
            -and $probeLines[0] -eq 'Class Archive maintenance mode.' `
            -and $probeLines[1] -eq 'CLASS_ARCHIVE_STATUS:503'
        ) {
            # The nginx maintenance response does not enter FastCGI. Require
            # the restarted PHP-FPM listener as separate process-level health
            # evidence before running the current-code CLI verifier.
            & wsl.exe @($composeArguments + @(
                'exec', '-T', '--user', 'nginx', 'piwigo',
                'php', '/workspace/tests/phase1/php-fpm-ready.php'
            ))
            if ($LASTEXITCODE -eq 0) {
                return
            }
        }
        Start-Sleep -Seconds 1
    }
    throw 'Piwigo did not reach the exact fail-closed maintenance readiness state after restart.'
}

function Invoke-ClassArchiveMaintenancePrepare {
    & wsl.exe @($composeArguments + @(
        'exec', '-T', '--user', 'root', 'piwigo',
        'php', '/workspace/infra/scripts/prepare-class-archive-maintenance.php', '--prepare'
    ))
    if ($LASTEXITCODE -ne 0) {
        throw 'The controlled maintenance marker preparation failed; maintenance remains active.'
    }
}

function Restore-PiwigoPersistentUserScript {
    # Narrow root-only repair for the one pinned lifecycle hook stored in the
    # persistent scripts volume. It also normalizes existing private media
    # modes; it never accepts a caller-supplied source or destination path.
    & wsl.exe @($composeArguments + @(
        'exec', '-T', '--user', 'root', 'piwigo',
        '/bin/ash', '/workspace/infra/scripts/restore-piwigo-user-script.sh'
    ))
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not restore and run the pinned Piwigo media-permission hook.'
    }
}

function Wait-PublicCompatibilityReady {
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        $previousErrorAction = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $state = @(& wsl.exe @($publicCompatComposeArguments + @(
                'ps', '-q', 'immich-web-compat'
            )) 2>$null)
            $stateExit = $LASTEXITCODE
            $containerId = if ($stateExit -eq 0 -and $state.Count -eq 1) { ([string]$state[0]).Trim() } else { '' }
            if ($containerId -match '^[a-f0-9]{12,64}$') {
                $health = @(& wsl.exe -d Ubuntu --exec docker inspect --format '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $publicCompatContainer 2>$null)
                if ($LASTEXITCODE -eq 0 -and $health.Count -eq 1 -and ([string]$health[0]).Trim() -eq 'running|healthy') {
                    return
                }
            }
        }
        finally {
            $ErrorActionPreference = $previousErrorAction
        }
        Start-Sleep -Seconds 1
    }
    throw 'Public synthetic compatibility BFF did not become healthy.'
}

function Start-PublicCompatibility {
    & wsl.exe @($publicCompatComposeArguments + @('up', '-d', '--force-recreate', 'immich-web-compat'))
    if ($LASTEXITCODE -ne 0) { throw 'Could not start the public synthetic compatibility BFF.' }
    Wait-PublicCompatibilityReady
}

function Stop-PublicCompatibility {
    & wsl.exe @($publicCompatComposeArguments + @('stop', 'immich-web-compat'))
    if ($LASTEXITCODE -ne 0) { throw 'Could not stop the public synthetic compatibility BFF.' }
}

if ($Action -eq 'up' -or $Action -eq 'stop' -or $Action -eq 'down') {
    Start-KeepAlive
}

switch ($Action) {
    'up' { $commandArguments = $composeArguments + @('up', '-d') }
    'stop' { $commandArguments = $composeArguments + @('stop') }
    'down' { $commandArguments = $composeArguments + @('down') }
    'ps' { $commandArguments = $composeArguments + @('ps') }
    'logs' { $commandArguments = $composeArguments + @('logs', '--tail=200') }
    'pull' { $commandArguments = $composeArguments + @('pull') }
    'config' { $commandArguments = $composeArguments + @('config', '--quiet') }
    'extensions' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-locked-piwigo-extensions.php'
        )
    }
    'extensions-verify' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-locked-piwigo-extensions.php', '--verify-only'
        )
    }
    'class-plugins' {
        $classPluginWorkflow = $true
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-class-archive-plugins.php'
        )
    }
    'class-plugins-verify' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--verify-only'
        )
    }
    'identity-bootstrap' {
        # Compatibility alias: bootstrap may no longer be invoked online by
        # itself. It runs the complete install/restart/verify/finalize protocol.
        $classPluginWorkflow = $true
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-class-archive-plugins.php'
        )
    }
    'identity-bootstrap-synthetic' {
        # Synthetic fixtures use the same bounded protocol; only their explicit
        # bootstrap and post-restart verification requirement differs.
        $classPluginWorkflow = $true
        $classPluginSynthetic = $true
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--with-synthetic-fixtures'
        )
    }
    'baseline-verify' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/configure-piwigo-baseline.php', '--verify-only'
        )
    }
    'bootstrap' {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $PSScriptRoot 'bootstrap-piwigo.ps1')
        exit $LASTEXITCODE
    }
    'seed' {
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/generate-test-images.php', '72'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx',
            '-e', 'CLASS_ARCHIVE_ALLOW_SYNTHETIC_SEED=1', 'piwigo',
            'php', '/workspace/tests/fixtures/seed-piwigo.php'
        )
    }
    'normalize-media-permissions' {
        Restore-PiwigoPersistentUserScript
        exit 0
    }
    'test-phase0' {
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase0/assert-photo-model.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', 'piwigo',
            'sh', '/workspace/tests/phase0/assert-media-permissions.sh'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\smoke-photo-ui.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\access-matrix.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\media-guard-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\media-guard-tiny-preview.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\media-guard-state-transitions.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        # The tiny-preview and state-transition fixtures deliberately mutate
        # native Piwigo rows, which advances the durable source epoch and makes
        # presentation projections stale. Restore the canonical 72-photo read
        # generation as part of successful fixture cleanup so MediaGuard
        # attestation cannot leave the photo application unavailable.
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php'
        ))
        exit $LASTEXITCODE
    }
    'test-phase1' {
        # Cheap deterministic policy checks run first. Any nonzero status is a
        # hard stop; an HTTP runner's explicit PENDING exit must not be hidden.
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\class-plugin-workflow-lock.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/class-identity-maintenance-protocol.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase1/media-file-policy.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/class-identity-enforcement-context.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/class-identity-anonymous-presenter.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/class-identity-audit-reason.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/class-identity-capability-guard.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/class-identity-rate-limiter.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/class-identity-schema-semantics.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/class-identity-synthetic-bootstrap-protocol.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/system-admin-credential-protocol.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\system-admin-session-fault-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\class-identity-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\maintenance-gate-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\runtime-surface-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\enforcement-fault-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\capability-guard-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\pending-media-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\anonymous-presenter-http.ps1')
        exit $LASTEXITCODE
    }
    'test-phase2-contract' {
        # This is intentionally a CONTRACT_TESTED gate, not Immich runtime or
        # browser evidence. It starts no Immich service and contacts no
        # external network.
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase2/class-person-timeline-schema-semantics.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase2/archive-date-source-semantics.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase2/class-photo-schema-semantics.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase2/class-photo-mapping-integration.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase2/gateway-contract.php'
        ))
        exit $LASTEXITCODE
    }
    'test-phase3-contract' {
        # Public-safe Phase 3.2 gates: real MariaDB semantics run under an
        # isolated random prefix; UI/BFF checks are source-level contracts.
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/photo-product-schema-semantics.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/ai-index-persistence-static.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/ai-index-persistence-runtime.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/photo-product-ops-protocol.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/presentation-epoch-static.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/presentation-epoch-runtime.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/read-projection-semantics.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/spotlight-read-projection.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/final-source-invalidation-race.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/native-piwigo-projection-guard.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/plugin-native-trigger-lifecycle.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/bulk-archive-projection-runtime.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/canonical-projection-boundary.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/submission-review-preflight-semantics.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/responsive-media-contract.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/photo-cache-warmup-static.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx',
            '-e', 'CLASS_ARCHIVE_ALLOW_DERIVATIVE_QUEUE_FIXTURE=1',
            'piwigo', 'php', '/workspace/tests/phase3/derivative-warmup-queue-runtime.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/private-incremental-media-protocol.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase3/private-incremental-media-retry-synthetic.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & node.exe (Join-Path $projectRoot 'tests\phase3\photo-ui-static.mjs')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & node.exe (Join-Path $projectRoot 'tests\phase3\photo-cache-contract.mjs')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & node.exe (Join-Path $projectRoot 'tests\phase3\photo-product-contract.mjs')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase3\private-full-owner-deploy-protocol.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase3\owner-restore-schema-migration-protocol.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase3\private-full-owner-operations-protocol.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase3\private-real-supplemental-operator-protocol.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase3\private-real-supplemental-apply-operator-protocol.ps1')
        exit $LASTEXITCODE
    }
    'test-phase2-gateway-http' {
        # This verifies only the same-origin Class Archive Gateway backed by
        # Piwigo/ClassIdentity. It does not connect the API to Immich and does
        # not constitute browser or Immich-adapter E2E evidence. Earlier
        # fixture suites intentionally invalidate the durable read projection
        # during their cleanup. Rebuild the canonical synthetic projection
        # first so this HTTP suite always begins from a verified, readable
        # baseline instead of accidentally treating the expected fail-closed
        # 503 state as an API regression.
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase2\gateway-http.ps1')
        exit $LASTEXITCODE
    }
    'test-phase2-performance-contract' {
        # Memory-only scale benchmark for the Gateway projection code. This
        # does not misrepresent 5k/20k as an HTTP, browser or ML benchmark.
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase2/gateway-performance-contract.php'
        ))
        exit $LASTEXITCODE
    }
    'test-phase2-archive-timeline-runtime' {
        # A real localhost BFF projection test. It changes only four known
        # synthetic archive metadata rows and restores them in finally.
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase2\archive-timeline-runtime.ps1')
        exit $LASTEXITCODE
    }
    'test-phase2-runtime' {
        # This is a runtime-isolation gate for the already-running, internal
        # Immich spike. It neither opens a host port nor creates a user,
        # external library, asset or browser session.
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase2\immich-runtime-isolation.ps1')
        exit $LASTEXITCODE
    }
    'test-phase2-runtime-integration' {
        # This is a disposable RUNTIME_TESTED lifecycle gate. It creates an
        # internal-only technical user and external library over read-only
        # synthetic Piwigo originals, then resets only the spike volumes and
        # proves the fresh state again. It never creates a browser endpoint.
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase2\immich-external-library-runtime.ps1')
        exit $LASTEXITCODE
    }
    'test-phase2-immich-gateway-bridge' {
        # This is an opt-in, disposable RUNTIME_TESTED bridge gate. It keeps
        # Immich internal, uses a temporary technical user/library plus two
        # canonical mappings, and restores the spike back to an empty state.
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase2\immich-gateway-bridge-runtime.ps1')
        exit $LASTEXITCODE
    }
    'test-phase2-immich-web-compat' {
        # This exercises the isolated official Web build through the narrow
        # Class Archive compatibility boundary. It is RUNTIME_TESTED only;
        # browser interaction is separately reported by browser QA evidence.
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase2\immich-web-compat-http.ps1') -KeepRunning
        exit $LASTEXITCODE
    }
    'phase2-ml-readiness' {
        # Diagnostic only: a BLOCKED offline-model result is intentionally
        # reported as state, while callers requiring ML execution must invoke
        # the script with -RequireReady and stop on its nonzero exit code.
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase2\immich-ml-artifact-readiness.ps1')
        exit $LASTEXITCODE
    }
    'browser-qa' {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase1\browser-qa.ps1')
        exit $LASTEXITCODE
    }
    'test-access' {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\access-matrix.ps1')
        exit $LASTEXITCODE
    }
    'backup' {
        New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
        $backupLockPath = Join-Path $runtimeDirectory 'backup.lock'
        $backupLock = $null
        try {
            try {
                $backupLock = [IO.File]::Open(
                    $backupLockPath,
                    [IO.FileMode]::OpenOrCreate,
                    [IO.FileAccess]::ReadWrite,
                    [IO.FileShare]::None
                )
            }
            catch [IO.IOException] {
                [Console]::Error.WriteLine('Refusing overlapping backup: another helper owns the local backup lock.')
                exit 1
            }

            $runningServices = @(& wsl.exe @($composeArguments + @('ps', '--status', 'running', '--services')))
            if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
            $piwigoWasRunning = 'piwigo' -in $runningServices
            $databaseWasRunning = 'db' -in $runningServices
            if (-not $databaseWasRunning) {
                [Console]::Error.WriteLine('Refusing backup because the database was not already running.')
                exit 1
            }
            if ($piwigoWasRunning) {
                & wsl.exe @($composeArguments + @('stop', 'piwigo'))
                if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
            }
            $backupExitCode = 1
            $restartExitCode = 0
            try {
                & wsl.exe @($composeArguments + @('--profile', 'ops', 'run', '--rm', '-e', 'CLASS_ARCHIVE_BACKUP_QUIESCED=true', 'backup'))
                $backupExitCode = $LASTEXITCODE
                if ($backupExitCode -eq 0) {
                    & wsl.exe @($composeArguments + @('--profile', 'ops', 'run', '--rm', '-e', 'CLASS_ARCHIVE_BACKUP_AUDIT_WRITE=true', 'backup-audit'))
                    $backupExitCode = $LASTEXITCODE
                }
            }
            finally {
                if ($piwigoWasRunning) {
                    & wsl.exe @($composeArguments + @('start', 'piwigo'))
                    $restartExitCode = $LASTEXITCODE
                }
            }
            if ($restartExitCode -ne 0) {
                [Console]::Error.WriteLine("Piwigo restart failed after backup (backup exit $backupExitCode, restart exit $restartExitCode).")
                exit $restartExitCode
            }
            exit $backupExitCode
        }
        finally {
            if ($null -ne $backupLock) {
                $backupLock.Dispose()
            }
            Remove-Item -LiteralPath $backupLockPath -Force -ErrorAction SilentlyContinue
        }
    }
}

$classPluginWorkflowLock = $null
try {
    if ($classPluginWorkflow) {
        try {
            # This non-blocking Windows handle must be owned before any helper
            # can create/adopt the durable maintenance marker. It remains held
            # through install, restart, runtime verification and finalization.
            $classPluginWorkflowLock = Enter-ClassArchivePluginWorkflowLock `
                -LockPath $classPluginWorkflowLockPath
        }
        catch [InvalidOperationException] {
            [Console]::Error.WriteLine($_.Exception.Message)
            exit 1
        }

        # The pinned image normalizes persistent-volume ownership at startup. A
        # root-only narrow helper creates/adopts only the exact marker inode; the
        # installer and bootstrap themselves continue to run as nginx.
        Invoke-ClassArchiveMaintenancePrepare
    }

    & wsl.exe @commandArguments
    $commandExitCode = $LASTEXITCODE

    if ($commandExitCode -eq 0 -and $Action -eq 'up') {
        Start-PublicCompatibility
    }

    if ($classPluginWorkflow -and $commandExitCode -eq 0) {
        # A successful installer intentionally leaves nginx in durable maintenance.
        # Restart clears PHP-FPM/opcache; every failure below returns with the exact
        # marker still present. No direct online bootstrap path exists in dev.ps1.
        Restore-PiwigoPersistentUserScript
        # A plain container restart does not apply compose-level changes such as
        # loopback port mappings. Recreate only Piwigo while the durable
        # maintenance marker is still present, so nginx/PHP-FPM/opcache and the
        # container network surface move forward as one fail-closed step.
        & wsl.exe @($composeArguments + @('up', '-d', '--force-recreate', '--no-deps', 'piwigo'))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        Wait-ClassArchiveMaintenanceReady
        # Restart may normalize the marker to the exact persistent-volume form.
        # Re-establish nginx ownership atomically before any runtime/finalize check.
        Invoke-ClassArchiveMaintenancePrepare

        $runtimeVerificationArguments = @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--verify-runtime'
        )
        if ($classPluginSynthetic) {
            $runtimeVerificationArguments += '--with-synthetic-fixtures'
        }
        & wsl.exe @($composeArguments + $runtimeVerificationArguments)
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

        # Schema/plugin publication and read-projection publication are one
        # maintenance-gated product transition. Never remove the outer nginx
        # gate while the browser would still observe an old or STALE Home,
        # album, People, Memory or Spotlight projection.
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php', '--scope=all', '--json'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        Start-PublicCompatibility

        # Finalization is a separate process and repeats current tree, active plugin,
        # schema, enforcement and principal assertions before exact-marker removal.
        $finalizeArguments = @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--finalize-maintenance'
        )
        if ($classPluginSynthetic) {
            $finalizeArguments += '--with-synthetic-fixtures'
        }
        & wsl.exe @($composeArguments + $finalizeArguments)
        $commandExitCode = $LASTEXITCODE
    }

    if ($Action -eq 'stop' -or $Action -eq 'down') {
        if ($commandExitCode -eq 0) {
            Stop-PublicCompatibility
        }
        Stop-KeepAlive
    }

    exit $commandExitCode
}
finally {
    # Dispose only our OS handle. The persistent lock inode and any unknown
    # bytes in it are deliberately preserved for safe crash/restart behavior.
    Exit-ClassArchivePluginWorkflowLock -Handle $classPluginWorkflowLock
}
