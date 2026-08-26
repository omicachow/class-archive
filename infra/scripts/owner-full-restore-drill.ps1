[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'prepare-storage', 'restore', 'verify', 'cold-restart', 'status')]
    [string]$Action = 'validate',

    [Parameter(Mandatory = $true)]
    [string]$BackupBundlePath,

    [ValidateRange(32, 256)]
    [int]$RuntimeImageSizeGiB = 64,

    [switch]$ConfirmCreateRestoreStorage,
    [switch]$ConfirmIsolatedRestore,
    [switch]$ConfirmColdRestart
)

# The restore runtime deliberately uses a second Unix-socket-only Docker daemon
# whose complete data-root lives in an ext4 image on the temporary M: target.
# There is no reset, prune, volume removal, image removal or target deletion
# action in this tool. A failed drill remains intact for inspection.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wsl = "$env:SystemRoot\System32\wsl.exe"
$targetRoot = '<temporary-recovery-target>'
$markerText = "CLASS_ARCHIVE_BACKUP_TARGET`nversion=1`nscope=OWNER_PRIVATE_FULL`n"
$runtimeImage = Join-Path $targetRoot 'runtime\classarchive-owner-restore-v1.ext4'
$mountPoint = '/mnt/classarchive-owner-restore-v1'
$dockerRoot = $mountPoint + '/docker-data'
$dockerSocket = '/run/classarchive-owner-restore-v1/docker.sock'
$dockerHost = 'unix://' + $dockerSocket
$piwigoProject = 'class_archive_owner_restore_v1_piwigo'
$immichProject = 'class_archive_owner_restore_v1_immich'
$gatewayNetwork = 'class_archive_owner_restore_v1_gateway'
$piwigoEnvPath = Join-Path $projectRoot 'infra\owner-restore\.env.piwigo'
$immichEnvPath = Join-Path $projectRoot 'infra\owner-restore\.env.immich'
$privateRuntimeRoot = Join-Path $projectRoot '.codex-work\owner-restore\runtime'
$statePath = Join-Path $privateRuntimeRoot 'restore-state.json'
$streamHelper = Join-Path $PSScriptRoot 'restore-owner-temporary-backup.sh'
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoOverride = 'infra/owner-restore/docker-compose.piwigo.override.yml'
$piwigoWorkerOverride = 'infra/private-full/docker-compose.ai-worker.override.yml'
$immichCompose = 'infra/immich-spike/docker-compose.yml'
$immichOverride = 'infra/owner-restore/docker-compose.immich.override.yml'
$script:stage = 'initialization'
$script:assertions = 0

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-Restore([string]$Code) {
    throw [InvalidOperationException]::new('OWNER_RESTORE_STOP:' + $Code)
}

function Assert-Restore([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Stop-Restore $Code }
}

function Test-FixedAsciiEqual([string]$Left, [string]$Right) {
    if ($Left.Length -ne $Right.Length) { return $false }
    $difference = 0
    for ($index = 0; $index -lt $Left.Length; $index++) {
        $difference = $difference -bor (([int][char]$Left[$index]) -bxor ([int][char]$Right[$index]))
    }
    return $difference -eq 0
}

function Get-WslPath([string]$Path) {
    $result = @(& $wsl -d Ubuntu --exec wslpath -a ([IO.Path]::GetFullPath($Path)) 2>&1)
    Assert-Restore ($LASTEXITCODE -eq 0 -and $result.Count -eq 1 -and [string]$result[0] -match '\A/mnt/[a-z]/') 'wsl_path_conversion_failed'
    return [string]$result[0]
}

function Invoke-Ubuntu([string[]]$Arguments, [string]$FailureCode = 'ubuntu_command_failed') {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $result = @(& $wsl -d Ubuntu --exec @Arguments 2>&1)
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Stop-Restore $FailureCode }
    return @($result | ForEach-Object { [string]$_ })
}

function Invoke-RestoreDocker([string[]]$Arguments, [string]$FailureCode = 'restore_docker_failed') {
    return Invoke-Ubuntu (@('docker', '--host', $dockerHost) + $Arguments) $FailureCode
}

function Invoke-RestoreCompose([ValidateSet('piwigo','immich')][string]$Scope, [string[]]$Arguments) {
    if ($Scope -eq 'piwigo') {
        $composeArguments = @(
            'env', ('DOCKER_HOST=' + $dockerHost), 'docker', 'compose', '--env-file', 'infra/owner-restore/.env.piwigo',
            '-f', $piwigoCompose, '-f', $piwigoOverride, '-f', $piwigoWorkerOverride, '-p', $piwigoProject
        ) + $Arguments
    } else {
        $composeArguments = @(
            'env', ('DOCKER_HOST=' + $dockerHost), 'docker', 'compose', '--env-file', 'infra/owner-restore/.env.immich',
            '-f', $immichCompose, '-f', $immichOverride, '-p', $immichProject
        ) + $Arguments
    }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $result = @(& $wsl -d Ubuntu --cd $projectRoot --exec @composeArguments 2>&1)
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Stop-Restore ($Scope + '_compose_failed') }
    return @($result | ForEach-Object { [string]$_ })
}

function Assert-PlainFile([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-Restore (-not $item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -and $item.Length -gt 0) $Code
}

function Get-PayloadSpecs {
    return @(
        @{ artifact_id='runtime_counts'; file='business-state/runtime-counts.json'; kind='SANITIZED_EVIDENCE'; restore_order=1; preserves_posix_metadata=$false; encrypted=$false },
        @{ artifact_id='immich_upstream_lock'; file='business-state/immich-upstream.lock.json'; kind='NON_SECRET_CONFIGURATION'; restore_order=2; preserves_posix_metadata=$false; encrypted=$false },
        @{ artifact_id='ml_artifact_manifest'; file='business-state/ml-artifact-manifest.json'; kind='NON_SECRET_CONFIGURATION'; restore_order=3; preserves_posix_metadata=$false; encrypted=$false },
        @{ artifact_id='recovery_secrets'; file='business-state/recovery-secrets.dpapi.json'; kind='WINDOWS_DPAPI_ENVELOPE'; restore_order=5; preserves_posix_metadata=$false; encrypted=$false },
        @{ artifact_id='mariadb'; file='databases/mariadb.sql.gz.gpg'; kind='DATABASE_LOGICAL_GPG'; restore_order=10; preserves_posix_metadata=$false; encrypted=$true },
        @{ artifact_id='immich_postgres'; file='databases/immich-postgres.dump.gpg'; kind='DATABASE_LOGICAL_GPG'; restore_order=20; preserves_posix_metadata=$false; encrypted=$true },
        @{ artifact_id='piwigo_data'; file='business-state/piwigo-data.tar.gpg'; kind='POSIX_TAR_GPG'; restore_order=30; preserves_posix_metadata=$true; encrypted=$true },
        @{ artifact_id='piwigo_scripts'; file='business-state/piwigo-scripts.tar.gpg'; kind='POSIX_TAR_GPG'; restore_order=40; preserves_posix_metadata=$true; encrypted=$true },
        @{ artifact_id='piwigo_uploads'; file='media-archives/piwigo-uploads.tar.gpg'; kind='POSIX_TAR_GPG'; restore_order=50; preserves_posix_metadata=$true; encrypted=$true },
        @{ artifact_id='piwigo_galleries'; file='media-archives/piwigo-galleries.tar.gpg'; kind='POSIX_TAR_GPG'; restore_order=60; preserves_posix_metadata=$true; encrypted=$true },
        @{ artifact_id='immich_upload'; file='immich-state/immich-upload.tar.gpg'; kind='POSIX_TAR_GPG'; restore_order=70; preserves_posix_metadata=$true; encrypted=$true }
    )
}

function Get-RestoreToolCommitAllowlist {
    # A restore drill may use a narrowly reviewed follow-up commit without
    # pretending that the backup was created from that later commit.  Keep the
    # list exact: application, schema, base Compose and shared runtime changes
    # are deliberately outside this recovery-tool boundary.
    return @(
        'infra/owner-restore/README.md',
        'infra/owner-restore/docker-compose.immich.override.yml',
        'infra/owner-restore/docker-compose.piwigo.override.yml',
        'infra/scripts/owner-full-restore-drill.ps1',
        'infra/scripts/restore-owner-temporary-backup.sh',
        'tests/phase3/full-real-browser-qa.mjs',
        'tests/phase3/full-real-browser-qa.ps1',
        'tests/phase3/full-real-family-browser-qa.mjs',
        'tests/phase3/full-real-family-browser-qa.ps1',
        'tests/phase3/owner-full-restore-protocol.ps1'
    )
}

function Assert-RestoreCheckout([string]$SourceHead) {
    $checkoutHead = @(& git -C $projectRoot rev-parse --verify HEAD 2>$null)
    Assert-Restore ($LASTEXITCODE -eq 0 -and $checkoutHead.Count -eq 1 -and [string]$checkoutHead[0] -match '\A[0-9a-f]{40}\z') 'restore_checkout_head_invalid'
    $restoreToolHead = [string]$checkoutHead[0]
    $checkoutStatus = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>$null)
    Assert-Restore ($LASTEXITCODE -eq 0 -and $checkoutStatus.Count -eq 0) 'restore_checkout_dirty'

    if (-not [string]::Equals($restoreToolHead, $SourceHead, [StringComparison]::Ordinal)) {
        & git -C $projectRoot merge-base --is-ancestor $SourceHead $restoreToolHead 2>$null
        Assert-Restore ($LASTEXITCODE -eq 0) 'restore_tool_head_not_source_descendant'
        $commitRange = $SourceHead + '..' + $restoreToolHead
        $mergeCommits = @(& git -C $projectRoot rev-list --merges $commitRange 2>$null)
        Assert-Restore ($LASTEXITCODE -eq 0 -and $mergeCommits.Count -eq 0) 'restore_tool_history_merge_forbidden'
        # Inspect every commit rather than only the final tree diff: touching a
        # forbidden business file and reverting it later must still fail.
        $changedPaths = @(& git -C $projectRoot log --format= --name-only --no-renames $commitRange -- 2>$null | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) })
        Assert-Restore ($LASTEXITCODE -eq 0 -and $changedPaths.Count -gt 0) 'restore_tool_diff_invalid'
        $allowed = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
        foreach ($path in Get-RestoreToolCommitAllowlist) { [void]$allowed.Add([string]$path) }
        foreach ($path in $changedPaths) {
            Assert-Restore (-not [string]::IsNullOrWhiteSpace([string]$path) -and $allowed.Contains([string]$path)) 'restore_tool_diff_outside_allowlist'
        }
    }
    return $restoreToolHead
}

function Read-VerifiedBundle {
    $script:stage = 'bundle_boundary'
    $bundle = [IO.Path]::GetFullPath($BackupBundlePath).TrimEnd('\')
    $expectedParent = Join-Path $targetRoot 'bundles'
    foreach ($directory in @($targetRoot,$expectedParent)) {
        $directoryItem = Get-Item -LiteralPath $directory -Force -ErrorAction Stop
        Assert-Restore ($directoryItem.PSIsContainer -and -not ($directoryItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'bundle_ancestor_untrusted'
    }
    Assert-Restore ([string]::Equals([IO.Directory]::GetParent($bundle).FullName, $expectedParent, [StringComparison]::OrdinalIgnoreCase)) 'bundle_parent_invalid'
    Assert-Restore ([IO.Path]::GetFileName($bundle) -match '\Aowner-full-[0-9]{8}T[0-9]{6}Z\z') 'bundle_name_invalid'
    $bundleItem = Get-Item -LiteralPath $bundle -Force -ErrorAction Stop
    Assert-Restore ($bundleItem.PSIsContainer -and -not ($bundleItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'bundle_untrusted'
    $markerPath = Join-Path $targetRoot 'CLASS_ARCHIVE_BACKUP_TARGET'
    Assert-PlainFile $markerPath 'target_marker_untrusted'
    Assert-Restore ([string]::Equals([IO.File]::ReadAllText($markerPath), $markerText, [StringComparison]::Ordinal)) 'target_marker_invalid'

    $manifestPath = Join-Path $bundle 'manifest.json'
    $checksumPath = Join-Path $bundle 'SHA256SUMS'
    $completePath = Join-Path $bundle 'COMPLETE'
    foreach ($path in @($manifestPath, $checksumPath, $completePath)) { Assert-PlainFile $path 'bundle_control_file_untrusted' }
    try { $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-Restore 'manifest_invalid' }
    Assert-Restore ($manifest.format -eq 'owner-temporary-recovery-v1' -and [int]$manifest.version -eq 1 -and
        $manifest.scope -eq 'OWNER_PRIVATE_FULL' -and $manifest.temporary_recovery_target -eq $true -and
        $manifest.independent_disaster_backup -eq $false -and $manifest.filesystem -eq 'exFAT') 'manifest_identity_invalid'
    Assert-Restore ([string]$manifest.backup_id -eq [IO.Path]::GetFileName($bundle) -and
        [string]::Equals([IO.File]::ReadAllText($completePath), ([string]$manifest.backup_id + "`n"), [StringComparison]::Ordinal)) 'manifest_complete_invalid'
    Assert-Restore ([string]$manifest.source_head -match '\A[0-9a-f]{40}\z' -and
        [int]$manifest.schema_versions.class_identity -eq 15 -and [string]$manifest.schema_versions.piwigo -eq '16.4.0' -and
        [string]$manifest.schema_versions.immich -eq '3.1.0') 'manifest_schema_invalid'
    $restoreToolHead = Assert-RestoreCheckout ([string]$manifest.source_head)
    Assert-Restore ($manifest.encryption.archive -eq 'GPG_SYMMETRIC_AES256' -and
        $manifest.encryption.key_protection -eq 'WINDOWS_DPAPI_CURRENT_USER' -and
        $manifest.encryption.plaintext_archive_on_exfat -eq $false) 'manifest_encryption_invalid'
    Assert-Restore ($manifest.restore_runtime.must_use_fresh_volumes -eq $true -and
        $manifest.restore_runtime.current_owner_runtime_must_not_be_destroyed -eq $true) 'manifest_restore_policy_invalid'
    $expectedImages = [ordered]@{
        piwigo='piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84'
        mariadb='mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf'
        immich_server='ghcr.io/immich-app/immich-server:v3.1.0@sha256:079cc990b26a88d71f96027341c67329cb11829d4c341ce33b3718fe0f84cbfa'
        immich_machine_learning='ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05'
        postgres='ghcr.io/immich-app/postgres:14-vectorchord0.4.3-pgvectors0.2.0@sha256:bcf63357191b76a916ae5eb93464d65c07511da41e3bf7a8416db519b40b1c23'
    }
    foreach ($property in $expectedImages.GetEnumerator()) {
        Assert-Restore ([string]$manifest.container_images.($property.Key) -ceq [string]$property.Value) 'manifest_container_image_invalid'
    }

    $specs = @(Get-PayloadSpecs)
    $archives = @($manifest.archives)
    Assert-Restore ($archives.Count -eq $specs.Count) 'manifest_archive_count_invalid'
    foreach ($spec in $specs) {
        $records = @($archives | Where-Object { [string]$_.artifact_id -ceq [string]$spec.artifact_id })
        Assert-Restore ($records.Count -eq 1) 'manifest_archive_identity_invalid'
        $record = $records[0]
        Assert-Restore ([string]$record.file -ceq [string]$spec.file -and [string]$record.kind -ceq [string]$spec.kind -and
            [int]$record.restore_order -eq [int]$spec.restore_order -and [bool]$record.preserves_posix_metadata -eq [bool]$spec.preserves_posix_metadata -and
            [bool]$record.encrypted -eq [bool]$spec.encrypted -and [uint64]$record.size -gt 0 -and [string]$record.sha256 -match '\A[0-9a-f]{64}\z') 'manifest_archive_contract_invalid'
    }

    $expectedHashes = @{}
    foreach ($line in [IO.File]::ReadAllLines($checksumPath)) {
        Assert-Restore ($line -match '\A([0-9a-f]{64})  ([A-Za-z0-9._/-]+)\z') 'checksum_line_invalid'
        $relative = [string]$Matches[2]
        Assert-Restore (-not $expectedHashes.ContainsKey($relative) -and -not $relative.Contains('..') -and -not $relative.StartsWith('/')) 'checksum_path_invalid'
        $expectedHashes[$relative] = [string]$Matches[1]
    }
    $requiredFiles = @(($specs | ForEach-Object { [string]$_.file }) + @('manifest.json','COMPLETE')) | Sort-Object
    Assert-Restore (@(Compare-Object $requiredFiles @($expectedHashes.Keys | Sort-Object)).Count -eq 0) 'checksum_inventory_invalid'
    foreach ($relative in $requiredFiles) {
        $path = Join-Path $bundle $relative.Replace('/', '\')
        Assert-PlainFile $path 'bundle_payload_untrusted'
        $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
        Assert-Restore (Test-FixedAsciiEqual $actual ([string]$expectedHashes[$relative])) 'bundle_sha256_mismatch'
        $archive = @($archives | Where-Object { [string]$_.file -ceq $relative })
        if ($archive.Count -eq 1) {
            Assert-Restore ([uint64](Get-Item -LiteralPath $path).Length -eq [uint64]$archive[0].size -and
                (Test-FixedAsciiEqual $actual ([string]$archive[0].sha256))) 'manifest_payload_digest_mismatch'
        }
    }
    foreach ($pair in @(
        @('business-state\immich-upstream.lock.json','infra\immich-spike\immich-upstream.lock.json'),
        @('business-state\ml-artifact-manifest.json','infra\immich-spike\ml-artifacts\manifest.json')
    )) {
        $bundleDigest = (Get-FileHash -LiteralPath (Join-Path $bundle $pair[0]) -Algorithm SHA256).Hash.ToLowerInvariant()
        $trackedDigest = (Get-FileHash -LiteralPath (Join-Path $projectRoot $pair[1]) -Algorithm SHA256).Hash.ToLowerInvariant()
        Assert-Restore (Test-FixedAsciiEqual $bundleDigest $trackedDigest) 'bundle_supply_chain_contract_mismatch'
    }
    $countKeys = @('source_records','canonical_photos','piwigo_images','album_relationships','leaf_albums','comments','replies','visible_people','person_merges','person_rules','spotlights','memories','audit_events','ai_asset_index','immich_assets','immich_face_records','immich_raw_persons','immich_search_index')
    foreach ($key in $countKeys) {
        Assert-Restore ($null -ne $manifest.counts.$key -and [string]$manifest.counts.$key -match '\A(?:0|[1-9][0-9]{0,11})\z') 'manifest_count_invalid'
    }
    return [ordered]@{ bundle=$bundle; manifest=$manifest; specs=$specs; restore_tool_head=$restoreToolHead }
}

function Unprotect-DpapiValue([string]$Ciphertext) {
    Assert-Restore ($Ciphertext -match '\A[0-9a-fA-F]{128,}\z') 'dpapi_ciphertext_invalid'
    $secure = ConvertTo-SecureString -String $Ciphertext
    $pointer = [IntPtr]::Zero
    try {
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    finally {
        if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
        if ($secure -is [IDisposable]) { $secure.Dispose() }
    }
}

function Read-RecoverySecrets([string]$Bundle) {
    $path = Join-Path $Bundle 'business-state\recovery-secrets.dpapi.json'
    try { $envelope = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-Restore 'recovery_envelope_invalid' }
    Assert-Restore ([int]$envelope.version -eq 1 -and $envelope.scope -eq 'OWNER_PRIVATE_FULL' -and
        $envelope.protection -eq 'WINDOWS_DPAPI_CURRENT_USER' -and $envelope.dpapi_scope -eq 'CurrentUser' -and
        $envelope.plaintext_written_to_recovery_target -eq $false) 'recovery_envelope_contract_invalid'
    $protectedKeys = @($envelope.protected.PSObject.Properties.Name | Sort-Object)
    $requiredKeys = @('anonymous_pseudonym_secret','claim_code_pepper','gpg_passphrase','piwigo_db_password') | Sort-Object
    Assert-Restore (@(Compare-Object $requiredKeys $protectedKeys).Count -eq 0) 'recovery_envelope_keys_invalid'
    $result = @{}
    foreach ($key in $requiredKeys) {
        $value = Unprotect-DpapiValue ([string]$envelope.protected.$key)
        Assert-Restore ($value.Length -ge 32 -and $value.Length -le 190 -and $value -match '\A[A-Za-z0-9_-]+\z') 'recovery_secret_shape_invalid'
        $result[$key] = $value
    }
    return $result
}

function New-RandomSecret {
    $bytes = New-Object byte[] 48
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    try { return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+','-').Replace('/','_') }
    finally { [Array]::Clear($bytes, 0, $bytes.Length) }
}

function Write-OwnerOnlyText([string]$Path, [string]$Text) {
    if (Test-Path -LiteralPath $Path) { Stop-Restore 'private_output_already_exists' }
    $parent = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $parent -PathType Container)) { [void][IO.Directory]::CreateDirectory($parent) }
    [IO.File]::WriteAllText($Path, $Text, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    $relative = [IO.Path]::GetFullPath($Path).Substring($projectRoot.TrimEnd('\').Length + 1).Replace('\','/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Restore ($LASTEXITCODE -eq 0 -and @(& git -C $projectRoot ls-files -- $relative 2>$null).Count -eq 0) 'private_output_git_visible'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function Initialize-RestoreEnvironments([hashtable]$Secrets) {
    $rootPassword = New-RandomSecret
    $immichPassword = New-RandomSecret
    try {
        $piwigo = @(
            'COMPOSE_PROJECT_NAME=' + $piwigoProject, 'CLASS_ARCHIVE_HTTP_PORT=8290', 'CLASS_ARCHIVE_COMPAT_HTTP_PORT=8291',
            'CLASS_ARCHIVE_GATEWAY_NETWORK=' + $gatewayNetwork, 'CLASS_ARCHIVE_BASE_URL=http://127.0.0.1:8290',
            'CLASS_ARCHIVE_TIMEZONE=Asia/Shanghai', 'PIWIGO_UID=1000', 'PIWIGO_GID=1000',
            'PIWIGO_DATA_VOLUME=class_archive_owner_restore_v1_piwigo_data', 'PIWIGO_UPLOADS_VOLUME=class_archive_owner_restore_v1_piwigo_uploads',
            'PIWIGO_GALLERIES_VOLUME=class_archive_owner_restore_v1_piwigo_galleries', 'PIWIGO_DERIVATIVES_VOLUME=class_archive_owner_restore_v1_piwigo_derivatives',
            'PIWIGO_DB_VOLUME=class_archive_owner_restore_v1_piwigo_db', 'PIWIGO_SCRIPTS_VOLUME=class_archive_owner_restore_v1_piwigo_scripts',
            'PIWIGO_BACKUPS_VOLUME=class_archive_owner_restore_v1_piwigo_backups',
            'PIWIGO_IMAGE=piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84',
            'MARIADB_IMAGE=mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf',
            'DB_NAME=piwigo', 'DB_USER=piwigo', 'DB_PASSWORD=' + $Secrets.piwigo_db_password, 'DB_ROOT_PASSWORD=' + $rootPassword,
            'PIWIGO_ADMIN_USERNAME=owner-restore-admin', 'PIWIGO_ADMIN_EMAIL=admin@owner-restore.invalid',
            'CLASS_ARCHIVE_CLAIM_CODE_PEPPER=' + $Secrets.claim_code_pepper,
            'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=' + $Secrets.anonymous_pseudonym_secret,
            'SMTP_HOST=', 'SMTP_PORT=', 'SMTP_USERNAME=', 'SMTP_PASSWORD=', 'SMTP_ENCRYPTION='
        ) -join "`n"
        $immich = @(
            'IMMICH_COMPOSE_PROJECT_NAME=' + $immichProject, 'CLASS_ARCHIVE_COMPAT_HTTP_PORT=8291', 'CLASS_ARCHIVE_CORE_PUBLIC_PORT=8290',
            'CLASS_ARCHIVE_GATEWAY_NETWORK=' + $gatewayNetwork, 'IMMICH_UPLOAD_VOLUME=class_archive_owner_restore_v1_immich_upload',
            'IMMICH_MODEL_CACHE_VOLUME=class_archive_owner_restore_v1_immich_model_cache', 'IMMICH_DB_VOLUME=class_archive_owner_restore_v1_immich_db',
            'IMMICH_GATEWAY_SECRET_VOLUME=class_archive_owner_restore_v1_immich_gateway_secret',
            'PIWIGO_UPLOADS_VOLUME=class_archive_owner_restore_v1_piwigo_uploads', 'PIWIGO_GALLERIES_VOLUME=class_archive_owner_restore_v1_piwigo_galleries',
            'DB_PASSWORD=' + $immichPassword, 'DB_USERNAME=postgres', 'DB_DATABASE_NAME=immich', 'TZ=Asia/Shanghai'
        ) -join "`n"
        Write-OwnerOnlyText $piwigoEnvPath ($piwigo + "`n")
        Write-OwnerOnlyText $immichEnvPath ($immich + "`n")
    }
    finally { $rootPassword = $null; $immichPassword = $null; $piwigo = $null; $immich = $null }
}

function Assert-HostCapabilities {
    $script:stage = 'host_capabilities'
    Assert-Restore (Test-Path -LiteralPath $wsl -PathType Leaf) 'wsl_unavailable'
    $values = Invoke-Ubuntu @('sh','-eu','-c','test "$(id -u)" = 0; command -v dockerd; command -v losetup; command -v mkfs.ext4; command -v blkid; command -v mount; command -v findmnt; command -v gpg; command -v tar; docker info --format "{{.DockerRootDir}}"')
    Assert-Restore ($values[-1].Trim() -eq '/var/lib/docker') 'primary_docker_root_changed'
    $disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='M:'" -ErrorAction Stop
    Assert-Restore ($null -ne $disk -and [string]::Equals([string]$disk.FileSystem, 'exFAT', [StringComparison]::OrdinalIgnoreCase)) 'm_filesystem_invalid'
}

function Assert-PortsFree {
    foreach ($port in @(8290,8291)) {
        $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, $port)
        try { $listener.Start() } catch { Stop-Restore 'restore_port_in_use' } finally { $listener.Stop() }
    }
}

function Get-PrimaryOwnerFingerprint {
    $names = @(
        'class_archive_private_full_v3_piwigo-piwigo-1','class_archive_private_full_v3_piwigo-db-1',
        'class_archive_private_full_v3_immich-immich-server-1','class_archive_private_full_v3_immich-database-1'
    )
    $parts = foreach ($name in $names) {
        $line = Invoke-Ubuntu @('docker','inspect','--format','{{.Id}}|{{.State.Running}}|{{.State.StartedAt}}',$name) 'owner_runtime_unavailable'
        Assert-Restore ($line.Count -eq 1 -and $line[0] -match '\A[a-f0-9]{64}\|true\|') 'owner_runtime_unhealthy'
        $name + '=' + $line[0]
    }
    return ($parts -join ';')
}

function Start-RestoreDaemon([bool]$AllowCreate) {
    $script:stage = 'restore_storage'
    $imageWsl = Get-WslPath $runtimeImage
    if (-not (Test-Path -LiteralPath $runtimeImage -PathType Leaf)) {
        Assert-Restore $AllowCreate 'restore_image_missing'
        Assert-Restore $ConfirmCreateRestoreStorage.IsPresent 'storage_confirmation_required'
        $runtimeDirectory = Split-Path -Parent $runtimeImage
        if (-not (Test-Path -LiteralPath $runtimeDirectory -PathType Container)) { [void][IO.Directory]::CreateDirectory($runtimeDirectory) }
        $runtimeDirectoryItem = Get-Item -LiteralPath $runtimeDirectory -Force -ErrorAction Stop
        Assert-Restore ($runtimeDirectoryItem.PSIsContainer -and -not ($runtimeDirectoryItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'restore_runtime_directory_untrusted'
        $disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='M:'" -ErrorAction Stop
        $bytes = [uint64]$RuntimeImageSizeGiB * [uint64]1GB
        Assert-Restore ([uint64]$disk.FreeSpace -gt ($bytes + [uint64]10GB)) 'restore_image_space_insufficient'
        [void](Invoke-Ubuntu @('sh','-eu','-c','if ! fallocate -l "$1" "$2"; then truncate -s "$1" "$2"; fi; test "$(stat -c %s "$2")" = "$3"','sh',($RuntimeImageSizeGiB.ToString() + 'G'),$imageWsl,([string]([uint64]$RuntimeImageSizeGiB * [uint64]1GB))) 'restore_image_allocate_failed')
    }
    Assert-PlainFile $runtimeImage 'restore_image_untrusted'
    $imageType = @(Invoke-Ubuntu @('sh','-eu','-c','tool=$(command -v blkid); test -n "$tool"; "$tool" -p -s TYPE -o value "$1" 2>/dev/null || true','sh',$imageWsl) 'restore_image_probe_failed')
    Assert-Restore ($imageType.Count -le 1) 'restore_image_type_ambiguous'
    if ($imageType.Count -eq 0 -or [string]::IsNullOrWhiteSpace([string]$imageType[0])) {
        # A failed first-time mkfs may leave the exact preallocated image behind.
        # Retrying is permitted only through the explicit create action, with
        # confirmation and the exact requested byte size. Any recognized
        # filesystem is handled below and is never overwritten.
        Assert-Restore ($AllowCreate -and $ConfirmCreateRestoreStorage.IsPresent) 'restore_unformatted_image_requires_confirmation'
        $expectedBytes = [uint64]$RuntimeImageSizeGiB * [uint64]1GB
        Assert-Restore ([uint64](Get-Item -LiteralPath $runtimeImage -Force).Length -eq $expectedBytes) 'restore_unformatted_image_size_invalid'
        # `wsl.exe --exec` does not consistently inherit /usr/sbin in PATH even
        # though an interactive shell can resolve mkfs.ext4. Resolve it inside
        # the already-validated shell boundary before formatting this exact file.
        # ext4 labels are limited to 16 bytes; keep this exact marker within
        # that boundary so it is not silently truncated by mkfs.ext4.
        [void](Invoke-Ubuntu @('sh','-eu','-c','tool=$(command -v mkfs.ext4); test -n "$tool"; exec "$tool" -F -L CLASSARCHIVE_OWN "$1"','sh',$imageWsl) 'restore_image_format_failed')
    }
    else {
        Assert-Restore ([string]$imageType[0] -eq 'ext4') 'restore_image_filesystem_invalid'
    }
    $loopLines = Invoke-Ubuntu @('sh','-eu','-c','existing=$(losetup -j "$1" | sed -n "1s/:.*//p"); if [ -n "$existing" ]; then printf "%s\n" "$existing"; else losetup --find --show --nooverlap "$1"; fi','sh',$imageWsl) 'loop_attach_failed'
    Assert-Restore ($loopLines.Count -eq 1 -and $loopLines[0] -match '\A/dev/loop[0-9]+\z') 'loop_device_invalid'
    $loop = $loopLines[0]
    [void](Invoke-Ubuntu @('sh','-eu','-c','mkdir -p "$1"; if ! mountpoint -q "$1"; then mount -t ext4 -o nodev,nosuid "$2" "$1"; fi; test "$(findmnt -n -o SOURCE -T "$1")" = "$2"; test "$(blkid -s LABEL -o value "$2")" = CLASSARCHIVE_OWN; mkdir -p "$1/docker-data" "$1/daemon"','sh',$mountPoint,$loop) 'restore_mount_failed')
    $socketState = @(Invoke-Ubuntu @('sh','-c','test -S "$1" && printf READY || true','sh',$dockerSocket))
    if ($socketState -notcontains 'READY') {
        [void](Invoke-Ubuntu @('sh','-eu','-c','mkdir -p /run/classarchive-owner-restore-v1; nohup dockerd --host=unix:///run/classarchive-owner-restore-v1/docker.sock --data-root=/mnt/classarchive-owner-restore-v1/docker-data --exec-root=/run/classarchive-owner-restore-v1/exec --pidfile=/run/classarchive-owner-restore-v1/dockerd.pid --bridge=ca_restore0 --bip=10.246.0.1/24 --default-address-pool=base=10.247.0.0/16,size=24 --storage-driver=overlay2 --userland-proxy=false --log-level=error > /mnt/classarchive-owner-restore-v1/daemon/dockerd.log 2>&1 &','sh') 'restore_daemon_start_failed')
        for ($attempt = 0; $attempt -lt 60; $attempt++) {
            Start-Sleep -Milliseconds 500
            $ready = @(Invoke-Ubuntu @('sh','-c','DOCKER_HOST="$1" docker info --format "{{.DockerRootDir}}" 2>/dev/null || true','sh',$dockerHost))
            if ($ready -contains $dockerRoot) { break }
        }
    }
    $root = (Invoke-RestoreDocker @('info','--format','{{.DockerRootDir}}'))[-1].Trim()
    Assert-Restore ($root -eq $dockerRoot) 'restore_daemon_root_invalid'
    $primaryRoot = (Invoke-Ubuntu @('docker','info','--format','{{.DockerRootDir}}'))[-1].Trim()
    Assert-Restore ($primaryRoot -eq '/var/lib/docker') 'primary_docker_root_changed'
}

function Copy-PinnedImages([object]$Manifest) {
    $refs = @(
        [string]$Manifest.container_images.piwigo, [string]$Manifest.container_images.mariadb,
        [string]$Manifest.container_images.immich_server, [string]$Manifest.container_images.immich_machine_learning,
        [string]$Manifest.container_images.postgres,
        'docker.io/valkey/valkey:9@sha256:8e8d64b405ce18f41b8e5ee20aa4687a8ed0022d1298f2ce31cdcf3a76e09411'
    ) | Sort-Object -Unique
    foreach ($ref in $refs) {
        Assert-Restore ($ref -match '\A[A-Za-z0-9._/-]+(?::[A-Za-z0-9._-]+)?@sha256:[0-9a-f]{64}\z') 'container_image_ref_invalid'
        [void](Invoke-Ubuntu @('bash','-o','pipefail','-c','docker image inspect "$1" >/dev/null; docker image save "$1" | docker --host "$2" image load >/dev/null','bash',$ref,$dockerHost) 'offline_image_copy_failed')
    }
}

function New-RestoreVolume([string]$Name, [string]$Project, [string]$Logical) {
    $existing = @(Invoke-RestoreDocker @('volume','ls','--quiet','--filter',('name=^' + $Name + '$')))
    Assert-Restore ($existing.Count -eq 0) 'restore_volume_not_fresh'
    [void](Invoke-RestoreDocker @('volume','create','--label',('com.docker.compose.project=' + $Project),'--label',('com.docker.compose.volume=' + $Logical),'--label','com.classarchive.scope=owner-restore-drill',$Name))
}

function Assert-RestoreGatewayNetwork {
    # The Piwigo Compose project owns this network and therefore must create
    # its Compose identity labels. Immich consumes the same name as an external
    # network; a hand-made network would be rejected by Compose (or could bind
    # the restore services to an untrusted bridge with the same name).
    $identity = @(Invoke-RestoreDocker @(
        'network','inspect','--format',
        '{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.network"}}|{{index .Labels "com.classarchive.scope"}}|{{.Internal}}|{{range .IPAM.Config}}{{.Subnet}}{{end}}',
        $gatewayNetwork
    ) 'restore_gateway_network_missing')
    Assert-Restore ($identity.Count -eq 1 -and $identity[0] -eq ($piwigoProject + '|immich_gateway|owner-restore-drill|true|10.245.0.0/16')) 'restore_gateway_network_identity_invalid'
}

function Invoke-StreamHelper([string]$Mode, [string]$Bundle, [string]$PassphrasePath, [switch]$NeedsPiwigoEnv) {
    $arguments = @((Get-WslPath $streamHelper),$Mode,'--bundle',(Get-WslPath $Bundle),'--passphrase-file',(Get-WslPath $PassphrasePath))
    if ($NeedsPiwigoEnv) { $arguments += @('--piwigo-env',(Get-WslPath $piwigoEnvPath)) }
    $lines = Invoke-Ubuntu (@('bash') + $arguments) 'restore_stream_failed'
    Assert-Restore ($lines.Count -eq 1 -and $lines[0] -eq ('OWNER_RESTORE_STREAM=PASS action=' + $Mode)) 'restore_stream_output_invalid'
}

function Copy-VerifiedModelCache([object]$BundleInfo) {
    $sourceVolume = 'class_archive_private_full_v3_immich_model_cache'
    $targetVolume = 'class_archive_owner_restore_v1_immich_model_cache'
    $identity = (Invoke-Ubuntu @('docker','volume','inspect','--format','{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.volume"}}|{{index .Labels "com.classarchive.scope"}}',$sourceVolume))[-1]
    Assert-Restore ($identity -eq 'class_archive_private_full_v3_immich|immich_model_cache|private-real-full') 'source_model_cache_identity_invalid'
    $mlImage = 'ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05'
    $expectedManifestHash = (Get-FileHash -LiteralPath (Join-Path $BundleInfo.bundle 'business-state\ml-artifact-manifest.json') -Algorithm SHA256).Hash.ToLowerInvariant()
    $sourceManifest = (Invoke-Ubuntu @('docker','run','--rm','--network','none','--read-only','--cap-drop','ALL','--cap-add','DAC_READ_SEARCH','--security-opt','no-new-privileges:true','--entrypoint','sha256sum','-v',($sourceVolume + ':/cache:ro'),$mlImage,'/cache/class-archive-model-manifest.json'))[-1]
    Assert-Restore ($sourceManifest -match '\A([0-9a-f]{64})  /cache/class-archive-model-manifest\.json\z' -and (Test-FixedAsciiEqual $Matches[1] $expectedManifestHash)) 'source_model_manifest_mismatch'
    $copyScript = @'
docker run --rm --log-driver none --network none --read-only --cap-drop ALL --cap-add DAC_READ_SEARCH --security-opt no-new-privileges:true --entrypoint tar -v "$1:/source:ro" "$3" --numeric-owner --acls --xattrs --xattrs-include="*" -C /source -cf - . |
docker --host "$2" run --rm -i --network none --read-only --cap-drop ALL --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE --security-opt no-new-privileges:true --entrypoint sh -v "$4:/target" "$3" -eu -c 'test -z "$(find /target -mindepth 1 -print -quit)"; exec tar --numeric-owner --same-owner --same-permissions --acls --xattrs --xattrs-include="*" -C /target -xf -'
'@
    [void](Invoke-Ubuntu @('bash','-o','pipefail','-c',$copyScript,'bash',$sourceVolume,$dockerHost,$mlImage,$targetVolume) 'model_cache_copy_failed')
    $targetManifest = (Invoke-RestoreDocker @('run','--rm','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges:true','--entrypoint','sha256sum','-v',($targetVolume + ':/cache:ro'),$mlImage,'/cache/class-archive-model-manifest.json'))[-1]
    Assert-Restore ($targetManifest -match '\A([0-9a-f]{64})  /cache/class-archive-model-manifest\.json\z' -and (Test-FixedAsciiEqual $Matches[1] $expectedManifestHash)) 'target_model_manifest_mismatch'
}

function Wait-RestoreContainer([string]$Name, [int]$Seconds = 300) {
    $inspectScript = @'
docker --host "$1" inspect --format '{{.State.Running}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$2" 2>/dev/null || true
'@
    for ($attempt = 0; $attempt -lt $Seconds; $attempt++) {
        $state = @(Invoke-Ubuntu @('sh','-c',$inspectScript,'sh',$dockerHost,$Name))
        if ($state -contains 'true|healthy' -or $state -contains 'true|none') { return }
        Start-Sleep -Seconds 1
    }
    Stop-Restore 'restore_container_health_timeout'
}

function Invoke-PrivateImmichFinish {
    $oldDockerHost = $env:DOCKER_HOST
    $oldWslEnv = $env:WSLENV
    try {
        $env:DOCKER_HOST = $dockerHost
        $env:WSLENV = if ([string]::IsNullOrWhiteSpace($oldWslEnv)) { 'DOCKER_HOST/u' } elseif ($oldWslEnv -match '(^|:)DOCKER_HOST(?:/u)?(:|$)') { $oldWslEnv } else { $oldWslEnv + ':DOCKER_HOST/u' }
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'private-qa-immich.ps1') finish -Runtime restore 2>&1)
        Assert-Restore ($LASTEXITCODE -eq 0 -and @($lines | Where-Object { [string]$_ -match '\APRIVATE_QA_IMMICH=PASS action=finish ' }).Count -eq 1) 'immich_finish_failed'
    }
    finally { $env:DOCKER_HOST = $oldDockerHost; $env:WSLENV = $oldWslEnv }
}

function Write-RestoreState([object]$BundleInfo) {
    if (-not (Test-Path -LiteralPath $privateRuntimeRoot -PathType Container)) { [void][IO.Directory]::CreateDirectory($privateRuntimeRoot) }
    $state = [ordered]@{
        version=1; backup_id=[string]$BundleInfo.manifest.backup_id; source_head=[string]$BundleInfo.manifest.source_head
        restore_tool_head=[string]$BundleInfo.restore_tool_head
        restored_at=(Get-Date).ToUniversalTime().ToString('o'); docker_root=$dockerRoot; ports=@(8290,8291)
        counts=$BundleInfo.manifest.counts; browser_e2e='NOT_RUN_BY_RESTORE_TOOL'
    }
    Write-OwnerOnlyText $statePath (($state | ConvertTo-Json -Depth 8 -Compress) + "`n")
}

function Read-RestoreState([object]$BundleInfo) {
    Assert-PlainFile $statePath 'restore_state_missing'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $statePath
    try { $state = Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-Restore 'restore_state_invalid' }
    Assert-Restore ([int]$state.version -eq 1 -and [string]$state.backup_id -eq [string]$BundleInfo.manifest.backup_id -and
        [string]$state.source_head -eq [string]$BundleInfo.manifest.source_head -and
        [string]$state.restore_tool_head -eq [string]$BundleInfo.restore_tool_head -and
        [string]$state.docker_root -eq $dockerRoot) 'restore_state_identity_invalid'
    return $state
}

function Get-RestoreCounts {
    $mariaSql = @'
set -eu
q() { mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute "$1"; }
ci=$(q "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';")
case "$ci" in ''|*[!A-Za-z0-9_]*) exit 91 ;; esac
base=${ci%migration}; pwg=${base%class_identity_}; [ "$pwg" != "$base" ]
printf 'source_records=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_source;")"
printf 'canonical_photos=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo;")"
printf 'piwigo_images=%s\n' "$(q "SELECT COUNT(*) FROM ${pwg}images;")"
printf 'album_relationships=%s\n' "$(q "SELECT COUNT(*) FROM ${pwg}image_category;")"
printf 'leaf_albums=%s\n' "$(q "SELECT COUNT(*) FROM ${base}album WHERE state='ACTIVE';")"
printf 'comments=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment;")"
printf 'replies=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment WHERE parent_comment_id IS NOT NULL;")"
printf 'visible_people=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person WHERE state='ACTIVE' AND visibility='VISIBLE';")"
printf 'person_merges=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person_merge;")"
printf 'person_rules=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person_photo_rule;")"
printf 'spotlights=%s\n' "$(q "SELECT COUNT(*) FROM ${base}spotlight;")"
printf 'memories=%s\n' "$(q "SELECT COUNT(*) FROM ${base}auto_collection;")"
printf 'audit_events=%s\n' "$(q "SELECT COUNT(*) FROM ${base}audit_event;")"
printf 'ai_asset_index=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_asset_index;")"
'@
    $lines = Invoke-RestoreDocker @('exec','class_archive_owner_restore_v1_piwigo-db-1','sh','-eu','-c',$mariaSql) 'restore_mariadb_verify_failed'
    $pgSql = 'SELECT ''immich_assets=''||COUNT(*) FROM asset UNION ALL SELECT ''immich_face_records=''||COUNT(*) FROM asset_face UNION ALL SELECT ''immich_raw_persons=''||COUNT(*) FROM person UNION ALL SELECT ''immich_search_index=''||COUNT(*) FROM smart_search;'
    $lines += Invoke-RestoreDocker @('exec','--user','postgres','class_archive_owner_restore_v1_immich-database-1','psql','--no-psqlrc','--tuples-only','--no-align','--set','ON_ERROR_STOP=1','--dbname=immich','--command',$pgSql) 'restore_postgres_verify_failed'
    $result = @{}
    foreach ($line in $lines) {
        if ($line -match '\A([a-z_]+)=([0-9]+)\z' -and -not $result.ContainsKey($Matches[1])) { $result[[string]$Matches[1]] = [uint64]$Matches[2] }
        elseif (-not [string]::IsNullOrWhiteSpace($line)) { Stop-Restore 'restore_count_output_invalid' }
    }
    return $result
}

function Assert-AiRestoreEvidence {
    $path = Join-Path $projectRoot '.codex-work\owner-restore\reports\owner-restore-immich-runtime.json'
    Assert-PlainFile $path 'restore_ai_evidence_missing'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
    try { $evidence = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-Restore 'restore_ai_evidence_invalid' }
    Assert-Restore ($evidence.version -eq 1 -and $evidence.scope -eq 'PRIVATE_REAL_FULL' -and
        $evidence.ai_index_state -eq 'READY' -and $evidence.media_mount -eq 'PIWIGO_ORIGINALS_READ_ONLY' -and
        $evidence.media_delivery -eq 'MEDIAGUARD_ONLY') 'restore_ai_evidence_contract_invalid'
    Assert-Restore ($evidence.metrics.reused_existing_indexes -eq $true -and [uint64]$evidence.metrics.face_jobs -eq 0 -and
        [uint64]$evidence.metrics.recognition_jobs -eq 0 -and [uint64]$evidence.metrics.smart_jobs -eq 0 -and
        [uint64]$evidence.people_count -gt 0) 'restore_ai_reindex_detected'
    foreach ($property in $evidence.metrics.search_counts.PSObject.Properties) {
        Assert-Restore ([uint64]$property.Value -gt 0) 'restore_search_result_missing'
    }
}

function Invoke-AggregateVerify([object]$BundleInfo) {
    $script:stage = 'aggregate_verify'
    [void](Read-RestoreState $BundleInfo)
    foreach ($name in @(
        'class_archive_owner_restore_v1_piwigo-piwigo-1','class_archive_owner_restore_v1_piwigo-db-1',
        'class_archive_owner_restore_v1_immich-immich-server-1','class_archive_owner_restore_v1_immich-immich-machine-learning-1',
        'class_archive_owner_restore_v1_immich-database-1','class_archive_owner_restore_v1_immich-immich-gateway-1',
        'class_archive_owner_restore_v1_immich-immich-web-compat-1'
    )) { Wait-RestoreContainer $name 60 }
    $counts = Get-RestoreCounts
    foreach ($property in $BundleInfo.manifest.counts.PSObject.Properties) {
        Assert-Restore ($counts.ContainsKey($property.Name) -and [uint64]$counts[$property.Name] -eq [uint64]$property.Value) 'restored_count_mismatch'
    }
    Assert-AiRestoreEvidence
    $published = Invoke-RestoreDocker @('ps','--filter','label=com.classarchive.scope=owner-restore-drill','--format','{{.Names}}|{{.Ports}}')
    $joined = $published -join "`n"
    Assert-Restore ($joined -match '127\.0\.0\.1:8290->80/tcp' -and $joined -match '127\.0\.0\.1:8291->8081/tcp') 'restore_loopback_ports_missing'
    Assert-Restore (-not ($joined -match '0\.0\.0\.0|\[::\]|:2283->|:3000->|:8080->')) 'restore_internal_service_exposed'
    $badMode = Invoke-RestoreCompose piwigo @('exec','-T','piwigo','sh','-eu','-c','find /var/www/html/piwigo/upload /var/www/html/piwigo/galleries -type f ! -perm 0660 -print -quit')
    Assert-Restore ([string]::IsNullOrWhiteSpace(($badMode -join ''))) 'restored_original_mode_invalid'
    $media = Invoke-RestoreCompose piwigo @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_PRIVATE_FULL_OWNER_MEDIA_HTTP=1','piwigo','php','/workspace/tests/phase3/private-full-owner-media-http.php')
    Assert-Restore (@($media | Where-Object { $_ -match '\APRIVATE_FULL_OWNER_MEDIA_HTTP=PASS .*direct_guest_requests=6 ' }).Count -eq 1) 'restore_mediaguard_probe_failed'
    $health0 = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8290/' -MaximumRedirection 0 -ErrorAction SilentlyContinue
    $health1 = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8291/healthz' -ErrorAction Stop
    Assert-Restore ($null -ne $health0 -and $health0.StatusCode -in @(200,301,302,303) -and $health1.StatusCode -eq 200) 'restore_http_health_failed'
    Write-Output ('OWNER_RESTORE_VERIFY=PASS backup_id=' + $BundleInfo.manifest.backup_id + ' source_head=' + $BundleInfo.manifest.source_head +
        ' restore_tool_head=' + $BundleInfo.restore_tool_head + ' counts=' + $counts.Count +
        ' mediaguard=PASS ai_results=IMMEDIATE browser_e2e=NOT_RUN assertions=' + $script:assertions)
}

try {
    $bundleInfo = Read-VerifiedBundle
    Assert-HostCapabilities
    if ($Action -eq 'validate') {
        Assert-Restore (Test-Path -LiteralPath $streamHelper -PathType Leaf) 'stream_helper_missing'
        Write-Output ('OWNER_RESTORE_VALIDATE=PASS backup_id=' + $bundleInfo.manifest.backup_id + ' source_head=' + $bundleInfo.manifest.source_head +
            ' restore_tool_head=' + $bundleInfo.restore_tool_head + ' sha256=PASS manifest=PASS temporary_target=YES independent_disaster_backup=NO assertions=' + $script:assertions)
        exit 0
    }

    if ($Action -eq 'prepare-storage') {
        Start-RestoreDaemon $true
        Copy-PinnedImages $bundleInfo.manifest
        Write-Output ('OWNER_RESTORE_STORAGE=PASS data_root=' + $dockerRoot + ' daemon=SECOND_UNIX_SOCKET_ONLY ports=NONE assertions=' + $script:assertions)
        exit 0
    }

    Start-RestoreDaemon $false
    if ($Action -eq 'status') {
        $primary = Get-PrimaryOwnerFingerprint
        Assert-Restore (-not [string]::IsNullOrWhiteSpace($primary)) 'owner_fingerprint_invalid'
        Write-Output ('OWNER_RESTORE_STATUS=PASS daemon=ISOLATED data_root=' + $dockerRoot + ' primary_owner=UNCHANGED assertions=' + $script:assertions)
        exit 0
    }

    if ($Action -eq 'restore') {
        Assert-Restore $ConfirmIsolatedRestore.IsPresent 'restore_confirmation_required'
        Assert-PortsFree
        Assert-Restore (-not (Test-Path -LiteralPath $statePath) -and -not (Test-Path -LiteralPath $piwigoEnvPath) -and -not (Test-Path -LiteralPath $immichEnvPath)) 'restore_state_not_fresh'
        $ownerBefore = Get-PrimaryOwnerFingerprint
        $secrets = Read-RecoverySecrets $bundleInfo.bundle
        $secretRun = Join-Path $privateRuntimeRoot ([string]$bundleInfo.manifest.backup_id)
        $passphrasePath = Join-Path $secretRun 'gpg-passphrase.txt'
        try {
            Initialize-RestoreEnvironments $secrets
            Write-OwnerOnlyText $passphrasePath ([string]$secrets.gpg_passphrase + "`n")
            Copy-PinnedImages $bundleInfo.manifest
            Assert-Restore (@(Invoke-RestoreDocker @('network','ls','--quiet','--filter',('name=^' + $gatewayNetwork + '$'))).Count -eq 0) 'restore_network_not_fresh'
            foreach ($spec in @(
                @('class_archive_owner_restore_v1_piwigo_data',$piwigoProject,'piwigo_data'), @('class_archive_owner_restore_v1_piwigo_uploads',$piwigoProject,'piwigo_uploads'),
                @('class_archive_owner_restore_v1_piwigo_galleries',$piwigoProject,'piwigo_galleries'), @('class_archive_owner_restore_v1_piwigo_derivatives',$piwigoProject,'piwigo_derivatives'),
                @('class_archive_owner_restore_v1_piwigo_db',$piwigoProject,'piwigo_db'), @('class_archive_owner_restore_v1_piwigo_scripts',$piwigoProject,'piwigo_scripts'),
                @('class_archive_owner_restore_v1_piwigo_backups',$piwigoProject,'backups'), @('class_archive_owner_restore_v1_immich_upload',$immichProject,'immich_upload'),
                @('class_archive_owner_restore_v1_immich_model_cache',$immichProject,'immich_model_cache'), @('class_archive_owner_restore_v1_immich_db',$immichProject,'immich_db'),
                @('class_archive_owner_restore_v1_immich_gateway_secret',$immichProject,'immich_gateway_secret')
            )) { New-RestoreVolume $spec[0] $spec[1] $spec[2] }
            [void](Invoke-RestoreCompose piwigo @('up','-d','db'))
            Assert-RestoreGatewayNetwork
            Wait-RestoreContainer ($piwigoProject + '-db-1')
            [void](Invoke-RestoreCompose immich @('--profile','immich-spike','up','-d','database','redis'))
            Wait-RestoreContainer ($immichProject + '-database-1')
            Invoke-StreamHelper verify $bundleInfo.bundle $passphrasePath
            foreach ($mode in @('restore-piwigo-data','restore-piwigo-scripts','restore-piwigo-uploads','restore-piwigo-galleries','restore-mariadb','write-piwigo-config','restore-immich-upload','restore-immich-postgres')) {
                Invoke-StreamHelper $mode $bundleInfo.bundle $passphrasePath -NeedsPiwigoEnv:($mode -eq 'write-piwigo-config')
            }
            Copy-VerifiedModelCache $bundleInfo
            [void](Invoke-RestoreCompose piwigo @('up','-d','piwigo'))
            Wait-RestoreContainer ($piwigoProject + '-piwigo-1')
            [void](Invoke-RestoreCompose immich @('--profile','immich-spike','--profile','immich-ml','up','-d','immich-machine-learning','immich-server'))
            Wait-RestoreContainer ($immichProject + '-immich-machine-learning-1') 600
            Wait-RestoreContainer ($immichProject + '-immich-server-1') 600
            Invoke-PrivateImmichFinish
            [void](Invoke-RestoreCompose immich @('--profile','immich-web-compat','up','-d','immich-web-compat'))
            Wait-RestoreContainer ($immichProject + '-immich-web-compat-1') 300
            Write-RestoreState $bundleInfo
            $ownerAfter = Get-PrimaryOwnerFingerprint
            Assert-Restore ([string]::Equals($ownerBefore,$ownerAfter,[StringComparison]::Ordinal)) 'primary_owner_changed_during_restore'
        }
        finally {
            $secrets = $null
            if (Test-Path -LiteralPath $passphrasePath -PathType Leaf) { Remove-Item -LiteralPath $passphrasePath -Force }
        }
        Invoke-AggregateVerify $bundleInfo
        exit 0
    }

    if ($Action -eq 'cold-restart') {
        Assert-Restore $ConfirmColdRestart.IsPresent 'cold_restart_confirmation_required'
        [void](Read-RestoreState $bundleInfo)
        $before = Get-RestoreCounts
        [void](Invoke-RestoreCompose immich @('--profile','immich-spike','--profile','immich-ml','--profile','immich-gateway-integration','--profile','immich-web-compat','stop','-t','30'))
        [void](Invoke-RestoreCompose piwigo @('stop','-t','30'))
        [void](Invoke-RestoreCompose piwigo @('up','-d','db','piwigo'))
        [void](Invoke-RestoreCompose immich @('--profile','immich-spike','--profile','immich-ml','--profile','immich-gateway-integration','--profile','immich-web-compat','up','-d',
            'database','redis','immich-machine-learning','immich-server','immich-gateway','immich-web-compat'))
        Wait-RestoreContainer ($piwigoProject + '-piwigo-1')
        Wait-RestoreContainer ($immichProject + '-immich-server-1') 600
        Wait-RestoreContainer ($immichProject + '-immich-machine-learning-1') 600
        $after = Get-RestoreCounts
        Assert-Restore (($before | ConvertTo-Json -Compress) -eq ($after | ConvertTo-Json -Compress)) 'cold_restart_counts_changed'
        Invoke-AggregateVerify $bundleInfo
        exit 0
    }

    Invoke-AggregateVerify $bundleInfo
}
catch {
    $code = if ($_.Exception.Message -match '\AOWNER_RESTORE_STOP:([a-z0-9_]{1,128})\z') { [string]$Matches[1] } else { 'unexpected_failure' }
    Write-Error ('OWNER_RESTORE=FAIL stage=' + $script:stage + ' code=' + $code + ' assertions=' + $script:assertions)
    exit 2
}
