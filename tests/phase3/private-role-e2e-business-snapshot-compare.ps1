$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$tool = Join-Path $projectRoot 'infra\scripts\private-role-e2e-business-snapshot.ps1'
$root = Join-Path $projectRoot '.codex-work\private-role-e2e\business-snapshots'
. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')

$assertions = 0
$created = [Collections.Generic.List[string]]::new()

function Assert-Synthetic([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Set-OwnerOnlyDirectory([string]$Path) {
    [IO.Directory]::CreateDirectory($Path) | Out-Null
    $resolved = (Resolve-Path -LiteralPath $Path).Path
    try {
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
        return
    }
    catch {}
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    if ($null -eq $identity) { throw 'synthetic_owner_identity_unavailable' }
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    foreach ($sid in @($identity, $systemSid, $administratorsSid)) {
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        ))
    }
    Set-Acl -LiteralPath $resolved -AclObject $acl
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
}

function New-RandomRunMarker {
    $bytes = New-Object byte[] 12
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Get-TextSha([string]$Value) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return (($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value)) | ForEach-Object { $_.ToString('x2') }) -join '')
    }
    finally { $sha.Dispose() }
}

function Get-FileSha([string]$Path) {
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Write-OwnerFile([string]$Path, [string]$Value) {
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function New-State(
    [uint64]$AuditRows,
    [string]$AuditFull,
    [string]$AuditPrefix,
    [switch]$CountDrift,
    [string]$UnsafeFqaCountKey = '',
    [switch]$TeacherFixturePresent,
    [string]$UnsafeFqtCountKey = '',
    [switch]$NonFqaSecurityDrift,
    [switch]$FqaTopologyDrift,
    [switch]$FqtTopologyDrift
) {
    $counts = [ordered]@{
        class_identity_schema_version = 18
        migration_ledger_rows = 18
        source_records = 3175
        canonical_photos = $(if ($CountDrift) { 2378 } else { 2377 })
        piwigo_images = 2377
        albums = 40
        album_relationships = 3151
        comment_rows = 12
        active_comments = 10
        reply_rows = 4
        active_replies = 3
        spotlights = 2
        memories = 6
        active_pins = 3
        identities = 40
        seats = 90
        accounts = 50
        principals = 51
        people_mappings = 716
        visible_people = 509
        person_merges = 2
        person_rules = 3
        claims = 12
        invitations = 7
        submissions = 5
        audit_events = $AuditRows
        ai_asset_index = 2377
        ai_jobs_total = 2377
        ai_jobs_open = 0
        projection_epoch_rows = 8
        fqa_identity_rows = 1
        fqa_frozen_identity_rows = 1
        fqa_account_rows = 3
        fqa_current_account_rows = 3
        fqa_principal_rows = 3
        fqa_seat_principal_rows = 3
        fqa_valid_binding_rows = 3
        fqa_disallowed_business_rows = 0
        fqa_active_leases = 0
        fqa_conflict_leases = 0
        fqa_live_sessions = 0
        fqa_live_auth_keys = 0
        fqa_valid_password_rows = 3
        fqa_system_admin_rows = 1
        fqt_identity_rows = $(if ($TeacherFixturePresent) { 1 } else { 0 })
        fqt_frozen_identity_rows = $(if ($TeacherFixturePresent) { 1 } else { 0 })
        fqt_account_rows = $(if ($TeacherFixturePresent) { 1 } else { 0 })
        fqt_current_account_rows = $(if ($TeacherFixturePresent) { 1 } else { 0 })
        fqt_principal_rows = $(if ($TeacherFixturePresent) { 1 } else { 0 })
        fqt_seat_principal_rows = $(if ($TeacherFixturePresent) { 1 } else { 0 })
        fqt_valid_binding_rows = $(if ($TeacherFixturePresent) { 1 } else { 0 })
        fqt_disallowed_business_rows = 0
        fqt_active_leases = 0
        fqt_conflict_leases = 0
        fqt_live_sessions = 0
        fqt_live_auth_keys = 0
        fqt_valid_password_rows = $(if ($TeacherFixturePresent) { 1 } else { 0 })
    }
    if (-not [string]::IsNullOrEmpty($UnsafeFqaCountKey)) {
        if (-not $counts.Contains($UnsafeFqaCountKey) -or -not $UnsafeFqaCountKey.StartsWith('fqa_', [StringComparison]::Ordinal)) {
            throw 'synthetic_unsafe_fqa_count_key_invalid'
        }
        $counts[$UnsafeFqaCountKey] = if ([uint64]$counts[$UnsafeFqaCountKey] -eq 0) { [uint64]1 } else { [uint64]$counts[$UnsafeFqaCountKey] - 1 }
    }
    if (-not [string]::IsNullOrEmpty($UnsafeFqtCountKey)) {
        if (-not $TeacherFixturePresent -or -not $counts.Contains($UnsafeFqtCountKey) -or -not $UnsafeFqtCountKey.StartsWith('fqt_', [StringComparison]::Ordinal)) {
            throw 'synthetic_unsafe_fqt_count_key_invalid'
        }
        $counts[$UnsafeFqtCountKey] = if ([uint64]$counts[$UnsafeFqtCountKey] -eq 0) { [uint64]1 } else { [uint64]$counts[$UnsafeFqtCountKey] - 1 }
    }
    $semantic = [ordered]@{}
    foreach ($name in @(
        'schema_ledger', 'canonical_media', 'album_membership', 'comments',
        'identity_security', 'fqa_security_equivalence', 'fqt_security_equivalence', 'submissions', 'person_curation',
        'spotlight_memories_pins', 'ai_projection_control'
    )) {
        $semantic[$name] = Get-TextSha ('stable:' + $name)
    }
    if ($NonFqaSecurityDrift) { $semantic.identity_security = Get-TextSha 'drift:non-fqa-identity-security' }
    if ($FqaTopologyDrift) { $semantic.fqa_security_equivalence = Get-TextSha 'drift:fqa-safe-topology' }
    if ($FqtTopologyDrift) { $semantic.fqt_security_equivalence = Get-TextSha 'drift:fqt-safe-topology' }
    $semantic.audit_full = $AuditFull
    $semantic.audit_preexisting_prefix = $AuditPrefix
    $semantic.audit_high_water_opaque = Get-TextSha ('high-water:' + $AuditRows)
    return @{ Counts = $counts; Semantic = $semantic }
}

function New-Bundle(
    [string]$Run,
    [string]$Phase,
    [hashtable]$State,
    [uint64]$AuditPrefixRows,
    [bool]$DurableRecoveryEmpty = $true
) {
    $bundle = Join-Path $root ($Phase + '-' + $Run)
    Set-OwnerOnlyDirectory $bundle
    $script:created.Add($bundle)
    $dumpPath = Join-Path $bundle 'database.sql.gz'
    [IO.File]::WriteAllBytes($dumpPath, [byte[]](31, 139, 8, 0, 0, 0, 0, 0, 0, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $dumpPath
    $markerDigest = Get-TextSha ("classarchive-private-role-e2e-business-snapshot-v3`0$Run")
    $manifest = [ordered]@{
        format = 3
        scope = 'PRIVATE_ROLE_E2E_OWNER_DB_ONLY_ROLLBACK'
        phase = $Phase
        created_at = '2026-08-30T00:00:00.0000000Z'
        source_head = ('a' * 40)
        schema_version = 18
        run_marker_sha256 = $markerDigest
        privacy = 'COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_COMMENT_BODIES_OR_SECRETS'
        consistency = 'SYNTHETIC_PROTOCOL_FIXTURE'
        media = 'NOT_INCLUDED'
        disaster_backup = $false
        dump = [ordered]@{
            file = 'database.sql.gz'
            bytes = [uint64](Get-Item -LiteralPath $dumpPath).Length
            sha256 = Get-FileSha $dumpPath
        }
        counts = $State.Counts
        semantic_fingerprints = $State.Semantic
        audit_policy = [ordered]@{
            mode = 'APPEND_ONLY_PREFIX_PRESERVED'
            preexisting_rows = $AuditPrefixRows
            preexisting_prefix_sha256 = [string]$State.Semantic.audit_preexisting_prefix
            high_water_mark = 'OPAQUE_SHA256_ONLY'
        }
        fqa_security_policy = [ordered]@{
            version = 1
            roster = 'FQA-C-99CA3B3B6AF1'
            comparison = 'FQA_SAFE_TERMINAL_EQUIVALENCE_V1'
            non_fqa_identity_security = 'BYTE_EXACT_SHA256'
            terminal_counts = 'EXACT_REQUIRED_VALUES'
            durable_recovery_empty = $DurableRecoveryEmpty
            allowed_volatile = @(
                'identity.lock_version', 'identity.updated_at', 'identity.frozen_at',
                'principal.auth_epoch', 'principal.updated_at', 'core_user.password',
                'core_user_info.last_visit', 'core_user_info.last_visit_from_history',
                'core_user_info.lastmodified', 'released_fixture_lease_history',
                'revoked_fqa_auth_key_history'
            )
        }
        fqt_security_policy = [ordered]@{
            version = 1
            roster = 'FQA-T-3E2F1A94B0C74D81952E6F0A'
            comparison = 'FQT_SAFE_TERMINAL_EQUIVALENCE_V1'
            terminal_counts = 'OPTIONAL_ABSENT_OR_EXACT_REQUIRED_VALUES'
            allowed_volatile = @(
                'identity.lock_version', 'identity.updated_at', 'identity.frozen_at',
                'principal.auth_epoch', 'principal.updated_at', 'core_user.password',
                'core_user_info.last_visit', 'core_user_info.last_visit_from_history',
                'core_user_info.lastmodified', 'released_fixture_lease_history',
                'revoked_fqa_auth_key_history'
            )
        }
        excluded = @('SYNTHETIC_PROTOCOL_FIXTURE')
    }
    $manifestPath = Join-Path $bundle 'MANIFEST.json'
    Write-OwnerFile $manifestPath (($manifest | ConvertTo-Json -Depth 8) + [Environment]::NewLine)
    $manifestSha = Get-FileSha $manifestPath
    Write-OwnerFile (Join-Path $bundle 'MANIFEST.sha256') ($manifestSha + '  MANIFEST.json' + [Environment]::NewLine)
    Write-OwnerFile (Join-Path $bundle 'COMPLETE') ('PRIVATE_ROLE_E2E_BUSINESS_SNAPSHOT_COMPLETE' + [Environment]::NewLine)
    $sumLines = foreach ($name in @('database.sql.gz', 'MANIFEST.json', 'MANIFEST.sha256', 'COMPLETE')) {
        (Get-FileSha (Join-Path $bundle $name)) + '  ' + $name
    }
    Write-OwnerFile (Join-Path $bundle 'SHA256SUMS') (($sumLines -join [Environment]::NewLine) + [Environment]::NewLine)
    return $manifestSha
}

function Invoke-Compare([string]$Run, [string]$PreSha, [string]$PostSha) {
    $output = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $tool `
        -Action Compare -Endpoint owner -RunMarker $Run `
        -ExpectedPreManifestSha256 $PreSha -ExpectedPostManifestSha256 $PostSha 2>&1)
    return @{ ExitCode = $LASTEXITCODE; Output = @($output | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' }) }
}

function Assert-CompareFailure([hashtable]$Result, [string]$ExpectedCode, [string]$AssertionPrefix) {
    Assert-Synthetic ($Result.ExitCode -eq 2) ($AssertionPrefix + '_not_rejected')
    $pattern = '^PRIVATE_ROLE_E2E_BUSINESS_SNAPSHOT=FAIL action=Compare phase=pre code=' + [regex]::Escape($ExpectedCode) + ' diagnostic_type=InvalidOperationException diagnostic_line=[1-9][0-9]*$'
    Assert-Synthetic (
        $Result.Output.Count -eq 1 -and [regex]::IsMatch([string]$Result.Output[0], $pattern),
        ($AssertionPrefix + '_failure_not_bounded')
    )
}

try {
    Set-OwnerOnlyDirectory $root
    $relative = '.codex-work/private-role-e2e/business-snapshots'
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Synthetic ($LASTEXITCODE -eq 0) 'synthetic_snapshot_root_not_ignored'

    # Positive: every business count and normalized fingerprint is stable
    # while audit is append-only. Equal FQA normalized topology represents a
    # lease that rotated only its explicitly allowed verifier/revision fields
    # and returned to the required frozen, credential-free terminal state.
    $run = New-RandomRunMarker
    $auditPre = Get-TextSha 'audit-pre'
    $preSha = New-Bundle $run 'pre' (New-State 100 $auditPre $auditPre) 100
    $postSha = New-Bundle $run 'post' (New-State 104 (Get-TextSha 'audit-post') $auditPre) 100
    $result = Invoke-Compare $run $preSha $postSha
    Assert-Synthetic ($result.ExitCode -eq 0) 'synthetic_append_only_compare_failed'
    Assert-Synthetic ($result.Output.Count -eq 1 -and $result.Output[0] -eq 'PRIVATE_ROLE_E2E_BUSINESS_STATE=PASS action=compare records=PRESERVED semantics=PRESERVED audit=APPEND_ONLY_PREFIX_PRESERVED scope=DB_ONLY') 'synthetic_append_only_compare_output_invalid'

    # Positive: the optional fixed Teacher fixture is also safe only when the
    # exact terminal topology existed on both sides of the E2E window.
    $runFqtPresent = New-RandomRunMarker
    $auditFqtPresent = Get-TextSha 'audit-fqt-present'
    $preFqtPresentSha = New-Bundle $runFqtPresent 'pre' (New-State 105 $auditFqtPresent $auditFqtPresent -TeacherFixturePresent) 105
    $postFqtPresentSha = New-Bundle $runFqtPresent 'post' (New-State 108 (Get-TextSha 'audit-fqt-present-post') $auditFqtPresent -TeacherFixturePresent) 105
    $fqtPresentResult = Invoke-Compare $runFqtPresent $preFqtPresentSha $postFqtPresentSha
    Assert-Synthetic ($fqtPresentResult.ExitCode -eq 0) 'synthetic_fqt_terminal_compare_failed'
    Assert-Synthetic ($fqtPresentResult.Output.Count -eq 1 -and $fqtPresentResult.Output[0] -eq 'PRIVATE_ROLE_E2E_BUSINESS_STATE=PASS action=compare records=PRESERVED semantics=PRESERVED audit=APPEND_ONLY_PREFIX_PRESERVED scope=DB_ONLY') 'synthetic_fqt_terminal_compare_output_invalid'

    # Negative: even the fixed FQA exception is fail-closed. Frozen state,
    # exact account/principal topology, zero live lease/session/key state, and
    # valid closed password hashes are independently mandatory.
    foreach ($unsafeFqaCount in @(
        'fqa_frozen_identity_rows', 'fqa_account_rows', 'fqa_principal_rows',
        'fqa_active_leases', 'fqa_conflict_leases', 'fqa_live_sessions',
        'fqa_live_auth_keys', 'fqa_valid_password_rows'
    )) {
        $runFqaUnsafe = New-RandomRunMarker
        $auditFqaUnsafe = Get-TextSha ('audit-fqa-unsafe-' + $unsafeFqaCount)
        $preFqaUnsafeSha = New-Bundle $runFqaUnsafe 'pre' (New-State 10 $auditFqaUnsafe $auditFqaUnsafe) 10
        $postFqaUnsafeSha = New-Bundle $runFqaUnsafe 'post' (New-State 11 (Get-TextSha ('audit-fqa-unsafe-post-' + $unsafeFqaCount)) $auditFqaUnsafe -UnsafeFqaCountKey $unsafeFqaCount) 10
        $fqaUnsafeResult = Invoke-Compare $runFqaUnsafe $preFqaUnsafeSha $postFqaUnsafeSha
        Assert-CompareFailure $fqaUnsafeResult ('manifest_fqa_terminal_invalid_' + $unsafeFqaCount) ('synthetic_' + $unsafeFqaCount)
    }

    # Negative: the FQA-T exception remains fail-closed. Every terminal count
    # is exact when it exists, including frozen/closed/no-live-credential
    # invariants; the absent state is separately valid only on both sides.
    foreach ($unsafeFqtCount in @(
        'fqt_frozen_identity_rows', 'fqt_account_rows', 'fqt_principal_rows',
        'fqt_active_leases', 'fqt_conflict_leases', 'fqt_live_sessions',
        'fqt_live_auth_keys', 'fqt_valid_password_rows'
    )) {
        $runFqtUnsafe = New-RandomRunMarker
        $auditFqtUnsafe = Get-TextSha ('audit-fqt-unsafe-' + $unsafeFqtCount)
        $preFqtUnsafeSha = New-Bundle $runFqtUnsafe 'pre' (New-State 20 $auditFqtUnsafe $auditFqtUnsafe -TeacherFixturePresent) 20
        $postFqtUnsafeSha = New-Bundle $runFqtUnsafe 'post' (New-State 21 (Get-TextSha ('audit-fqt-unsafe-post-' + $unsafeFqtCount)) $auditFqtUnsafe -TeacherFixturePresent -UnsafeFqtCountKey $unsafeFqtCount) 20
        $fqtUnsafeResult = Invoke-Compare $runFqtUnsafe $preFqtUnsafeSha $postFqtUnsafeSha
        Assert-CompareFailure $fqtUnsafeResult ('manifest_fqt_terminal_invalid_' + $unsafeFqtCount) ('synthetic_' + $unsafeFqtCount)
    }

    # Bootstrap/removal during the test window is forbidden even if aggregate
    # business counts were somehow made to look equal. The explicit presence
    # binding makes this a stable, privacy-safe failure code.
    $runFqtPresence = New-RandomRunMarker
    $auditFqtPresence = Get-TextSha 'audit-fqt-presence'
    $preFqtPresenceSha = New-Bundle $runFqtPresence 'pre' (New-State 22 $auditFqtPresence $auditFqtPresence) 22
    $postFqtPresenceSha = New-Bundle $runFqtPresence 'post' (New-State 23 (Get-TextSha 'audit-fqt-presence-post') $auditFqtPresence -TeacherFixturePresent) 22
    $fqtPresenceResult = Invoke-Compare $runFqtPresence $preFqtPresenceSha $postFqtPresenceSha
    Assert-CompareFailure $fqtPresenceResult 'teacher_fixture_presence_changed' 'synthetic_fqt_presence_change'

    # The converse is equally unsafe: a terminal fixture that existed before
    # the run cannot disappear afterwards under the optional-exception rule.
    $runFqtRemoval = New-RandomRunMarker
    $auditFqtRemoval = Get-TextSha 'audit-fqt-removal'
    $preFqtRemovalSha = New-Bundle $runFqtRemoval 'pre' (New-State 24 $auditFqtRemoval $auditFqtRemoval -TeacherFixturePresent) 24
    $postFqtRemovalSha = New-Bundle $runFqtRemoval 'post' (New-State 25 (Get-TextSha 'audit-fqt-removal-post') $auditFqtRemoval) 24
    $fqtRemovalResult = Invoke-Compare $runFqtRemoval $preFqtRemovalSha $postFqtRemovalSha
    Assert-CompareFailure $fqtRemovalResult 'teacher_fixture_presence_changed' 'synthetic_fqt_presence_removal'

    # Negative: Compare also rejects a bundle that merely claims the durable
    # recovery volume was not proven empty during Capture.
    $runRecovery = New-RandomRunMarker
    $auditRecovery = Get-TextSha 'audit-fqa-recovery'
    $preRecoverySha = New-Bundle $runRecovery 'pre' (New-State 11 $auditRecovery $auditRecovery) 11
    $postRecoverySha = New-Bundle $runRecovery 'post' (New-State 12 (Get-TextSha 'audit-fqa-recovery-post') $auditRecovery) 11 -DurableRecoveryEmpty $false
    $recoveryResult = Invoke-Compare $runRecovery $preRecoverySha $postRecoverySha
    Assert-CompareFailure $recoveryResult 'manifest_fqa_security_policy_invalid' 'synthetic_fqa_durable_recovery'

    # Negative: every non-FQA identity/security byte remains exact.
    $runNonFqa = New-RandomRunMarker
    $auditNonFqa = Get-TextSha 'audit-non-fqa'
    $preNonFqaSha = New-Bundle $runNonFqa 'pre' (New-State 12 $auditNonFqa $auditNonFqa) 12
    $postNonFqaSha = New-Bundle $runNonFqa 'post' (New-State 13 (Get-TextSha 'audit-non-fqa-post') $auditNonFqa -NonFqaSecurityDrift) 12
    $nonFqaResult = Invoke-Compare $runNonFqa $preNonFqaSha $postNonFqaSha
    Assert-CompareFailure $nonFqaResult 'post_cleanup_fingerprint_mismatch_identity_security' 'synthetic_non_fqa_security_drift'

    # Negative: a fixed FQA topology/group/account change is not volatile.
    $runFqaTopology = New-RandomRunMarker
    $auditFqaTopology = Get-TextSha 'audit-fqa-topology'
    $preFqaTopologySha = New-Bundle $runFqaTopology 'pre' (New-State 14 $auditFqaTopology $auditFqaTopology) 14
    $postFqaTopologySha = New-Bundle $runFqaTopology 'post' (New-State 15 (Get-TextSha 'audit-fqa-topology-post') $auditFqaTopology -FqaTopologyDrift) 14
    $fqaTopologyResult = Invoke-Compare $runFqaTopology $preFqaTopologySha $postFqaTopologySha
    Assert-CompareFailure $fqaTopologyResult 'post_cleanup_fingerprint_mismatch_fqa_security_equivalence' 'synthetic_fqa_topology_drift'

    # Negative: FQA-T static topology is just as strict as FQA-C. The
    # normalized projection only omits deliberately lease-owned volatile
    # values, never account/group/seat/principal topology.
    $runFqtTopology = New-RandomRunMarker
    $auditFqtTopology = Get-TextSha 'audit-fqt-topology'
    $preFqtTopologySha = New-Bundle $runFqtTopology 'pre' (New-State 16 $auditFqtTopology $auditFqtTopology -TeacherFixturePresent) 16
    $postFqtTopologySha = New-Bundle $runFqtTopology 'post' (New-State 17 (Get-TextSha 'audit-fqt-topology-post') $auditFqtTopology -TeacherFixturePresent -FqtTopologyDrift) 16
    $fqtTopologyResult = Invoke-Compare $runFqtTopology $preFqtTopologySha $postFqtTopologySha
    Assert-CompareFailure $fqtTopologyResult 'post_cleanup_fingerprint_mismatch_fqt_security_equivalence' 'synthetic_fqt_topology_drift'

    # Negative: an equal-hash bundle with a changed canonical count must still
    # fail the business-state comparison.
    $runCount = New-RandomRunMarker
    $auditCount = Get-TextSha 'audit-count-pre'
    $preCountSha = New-Bundle $runCount 'pre' (New-State 20 $auditCount $auditCount) 20
    $postCountSha = New-Bundle $runCount 'post' (New-State 21 (Get-TextSha 'audit-count-post') $auditCount -CountDrift) 20
    $countResult = Invoke-Compare $runCount $preCountSha $postCountSha
    Assert-CompareFailure $countResult 'post_cleanup_count_mismatch_canonical_photos' 'synthetic_count_drift'

    # Negative: audit may grow, but the pre-test prefix may never be changed or
    # removed. The comparison rejects a forged prefix without exposing rows.
    $runAudit = New-RandomRunMarker
    $auditBase = Get-TextSha 'audit-prefix-pre'
    $preAuditSha = New-Bundle $runAudit 'pre' (New-State 30 $auditBase $auditBase) 30
    $postAuditSha = New-Bundle $runAudit 'post' (New-State 33 (Get-TextSha 'audit-prefix-post') (Get-TextSha 'audit-prefix-forged')) 30
    $auditResult = Invoke-Compare $runAudit $preAuditSha $postAuditSha
    Assert-CompareFailure $auditResult 'audit_preexisting_prefix_changed' 'synthetic_audit_prefix_drift'

    # Negative: a changed dump byte is rejected by the per-file SHA-256 set
    # before any semantic comparison can accept the bundle.
    $runHash = New-RandomRunMarker
    $auditHash = Get-TextSha 'audit-hash-pre'
    $preHashSha = New-Bundle $runHash 'pre' (New-State 40 $auditHash $auditHash) 40
    $postHashSha = New-Bundle $runHash 'post' (New-State 41 (Get-TextSha 'audit-hash-post') $auditHash) 40
    $postDump = Join-Path $root (Join-Path ('post-' + $runHash) 'database.sql.gz')
    $bytes = [IO.File]::ReadAllBytes($postDump)
    $bytes[0] = $bytes[0] -bxor 1
    [IO.File]::WriteAllBytes($postDump, $bytes)
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $postDump
    $hashResult = Invoke-Compare $runHash $preHashSha $postHashSha
    Assert-CompareFailure $hashResult 'bundle_checksum_mismatch' 'synthetic_dump_hash_drift'

    Write-Output "PRIVATE_ROLE_E2E_BUSINESS_SNAPSHOT_COMPARE=PASS assertions=$assertions"
}
finally {
    foreach ($path in @($created | Sort-Object { $_.Length } -Descending)) {
        if (Test-Path -LiteralPath $path) {
            $full = [IO.Path]::GetFullPath($path)
            $boundary = [IO.Path]::GetFullPath($root).TrimEnd('\') + '\'
            if ($full.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) {
                Remove-Item -LiteralPath $full -Recurse -Force
            }
        }
    }
}

# Negative child-process probes intentionally return non-zero while this
# harness verifies that they fail closed. Do not let the final expected probe
# leak its native exit status to CI after the whole harness has passed.
exit 0
