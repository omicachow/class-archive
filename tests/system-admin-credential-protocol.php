<?php

declare(strict_types=1);

$assertions = 0;
function credentialAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, "SYSTEM_ADMIN_CREDENTIAL_PROTOCOL=FAIL {$message}\n");
        exit(1);
    }
}

$sessionPhp = file_get_contents('/workspace/tests/support/system-admin-session.php');
$sessionPs = file_get_contents('/workspace/tests/support/system-admin-session.ps1');
$passwordPhp = file_get_contents('/workspace/infra/scripts/set-system-admin-password.php');
$bootstrapPs = file_get_contents('/workspace/infra/scripts/bootstrap-piwigo.ps1');
$migrationPs = file_get_contents('/workspace/infra/scripts/remove-admin-password-from-env.ps1');
$faultPs = file_get_contents('/workspace/tests/system-admin-session-fault-http.ps1');
$composeYaml = file_get_contents('/workspace/infra/docker-compose.yml');
foreach ([$sessionPhp, $sessionPs, $passwordPhp, $bootstrapPs, $migrationPs, $faultPs, $composeYaml] as $source) {
    credentialAssert(is_string($source), 'tracked credential protocol source unavailable');
}
credentialAssert(str_contains($sessionPhp, "'database_session_hash'"), 'lease does not persist a one-way row locator');
credentialAssert(!str_contains($sessionPhp, "'database_session_id' =>"), 'lease persists a bearer-capable session id');
credentialAssert(str_contains($sessionPhp, "'absent' => true"), 'exact revoke is not idempotent before lease creation');
credentialAssert(
    preg_match('/try \{\s*\$result = Invoke-ClassArchiveSessionFixture[\s\S]+catch \{[\s\S]+Action revoke -Handle \$handle/', $sessionPs) === 1,
    'mint/output/HTTP verification failures are not covered by exact revoke',
);
credentialAssert(
    str_contains($sessionPs, '[bool]$revocation.revoked -or [bool]$revocation.absent'),
    'mint cleanup does not accept proven pre-lease absence without masking the original failure',
);
credentialAssert(
    preg_match('/function Remove-ClassArchiveSystemAdminSession[\s\S]+-or \[bool\]\$result\.absent/', $sessionPs) === 1,
    'normal lease cleanup can mistake an unexpectedly absent lease for exact revocation',
);
credentialAssert(str_contains($passwordPhp, "class_identity_'"), 'pre-ClassIdentity mode does not inspect all schema remnants');
credentialAssert(str_contains($passwordPhp, 'auth_epoch = auth_epoch + 1'), 'normal reset does not invalidate old session snapshots first');
credentialAssert(str_contains($passwordPhp, 'Core credential revocation could not be proven'), 'pre-ClassIdentity reset does not verify revocation');
credentialAssert(str_contains($bootstrapPs, 'Get-SystemAdminPasswordStage'), 'installed bootstrap has no prompt-only staged recovery');
credentialAssert(str_contains($bootstrapPs, 'Remove-ConsumedAdminPasswordFile'), 'successful one-time file consumption is not verified');
credentialAssert(
    preg_match('/Read-FreshInstallAdminPassword[^\n]+\n\s*\$adminPasswordFileConsumed\s*=/', $bootstrapPs) === 1
        && preg_match('/Read-FreshInstallAdminPassword[^\n]+\n\s*\$recoveryFileConsumed\s*=/', $bootstrapPs) === 1,
    'one-time password files are not marked for finally deletion immediately after a successful read',
);
credentialAssert(
    str_contains($migrationPs, 'function Remove-ExactMigrationArtifact')
        && substr_count($migrationPs, 'Remove-ExactMigrationArtifact -Path') === 1
        && str_contains($migrationPs, "@{ Path = \$temporaryPath; Label = 'rewrite temporary' }")
        && str_contains($migrationPs, "@{ Path = \$backupPath; Label = 'legacy backup' }")
        && !str_contains($migrationPs, '-ErrorAction SilentlyContinue'),
    'plaintext migration artifacts are not both deleted and absence-verified strictly',
);
credentialAssert(
    preg_match('/function caSessionMint[\s\S]+?function caSessionRevoke/', $sessionPhp, $mintSource) === 1
        && !str_contains($mintSource[0], 'unlink($path)'),
    'mint can discard its only exact-revocation lease inside an ambiguous native-process window',
);
credentialAssert(
    strpos($sessionPhp, '$statement->execute()') < strpos($sessionPhp, 'SYNTHETIC_SESSION_FAULT=')
        && str_contains($sessionPhp, "CA_TEST_FAULT_AFTER_DB_COMMIT = 'after_db_commit_before_json'"),
    'the deterministic fault is not injected after the exact database transition commits',
);
credentialAssert(
    str_contains($sessionPs, 'CLASS_ARCHIVE_SYNTHETIC_SESSION_FAULT=$FaultInjection')
        && str_contains($sessionPs, "-FaultInjection 'after_db_commit_before_json'") === false
        && !str_contains($composeYaml, 'CLASS_ARCHIVE_SYNTHETIC_SESSION_FAULT'),
    'fault injection is not restricted to an explicit per-exec test invocation',
);
credentialAssert(
    str_contains($faultPs, "-FaultInjection 'after_db_commit_before_json'")
        && substr_count($faultPs, 'LeaseCount -ne 0') === 2
        && substr_count($faultPs, 'AdminSessionCount -ne 0') === 2,
    'isolated fault regression does not prove clean lease/session state before and after the fault',
);

fwrite(STDOUT, "SYSTEM_ADMIN_CREDENTIAL_PROTOCOL=PASS assertions={$assertions}\n");
