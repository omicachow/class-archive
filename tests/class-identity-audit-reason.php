<?php

declare(strict_types=1);

define('PHPWG_ROOT_PATH', __DIR__ . '/../');

require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/src/Repository.php';
require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/src/Audit.php';

use ClassIdentity\Audit;

$passed = 0;
$failures = [];

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    global $passed, $failures;
    if ($expected !== $actual) {
        $failures[] = $label;
        return;
    }
    $passed++;
}

function assertRejected(string $value, string $label): void
{
    global $passed, $failures;
    try {
        Audit::validateReason($value, true);
        $failures[] = $label . ': accepted';
    } catch (InvalidArgumentException $error) {
        if (str_contains($error->getMessage(), $value)) {
            $failures[] = $label . ': reflected input';
            return;
        }
        $passed++;
    }
}

$normalReason = '  名册核对完成，重新签发家庭邀请。  ';
assertSameValue('名册核对完成，重新签发家庭邀请。', Audit::validateReason($normalReason, true), 'normal Chinese reason');
assertSameValue(null, Audit::validateReason(null), 'optional null');

try {
    Audit::validateReason(null, true);
    $failures[] = 'required null: accepted';
} catch (InvalidArgumentException $error) {
    assertSameValue('class_identity_audit_reason_required', $error->getMessage(), 'required null');
}

$selector = 'abcdefghijklmnopqrstuv.wxYZ0123456789_abcdefghijklmnopqrstuv';
$password = 'NeverPersist-Password-Canary-47';
foreach ([
    'selector.validator' => '重新签发 ' . $selector,
    'password assignment' => 'password=' . $password,
    'encoded password assignment' => 'password%3D' . $password,
    'Chinese password assignment' => '密码=' . $password,
    'Chinese passphrase assignment' => '口令：' . $password,
    'bare password-shaped token' => $password,
    'bare high entropy blob' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
    'authorization header' => 'Authorization: Bearer ABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
    'cookie' => 'Cookie: PHPSESSID=abcdef0123456789',
    'session' => 'session_id=abcdef0123456789',
    'api key' => 'api-key: ABCDEFGHIJKLMNOPQRSTUVWXYZ',
    'jwt' => 'eyJabcdefghijk.abcdefghijk.abcdefghijk',
    'control character' => "操作原因\n第二行",
    'unsupported symbol' => '冻结账号 🔑',
    'too long' => str_repeat('理', 501),
] as $label => $candidate) {
    assertRejected($candidate, $label);
}

// Prove the append-only writer itself is the final defense. Constructing it
// without a Repository is safe here because sensitive reason rejection must
// happen before the first persistence call.
$audit = (new ReflectionClass(Audit::class))->newInstanceWithoutConstructor();
try {
    $audit->append([
        'actor_kind' => 'SYSTEM_ACCOUNT',
        'action' => 'IDENTITY_CREATE',
        'target_type' => 'IDENTITY',
        'target_id' => '1',
        'reason' => 'password=' . $password,
        'result' => 'SUCCESS',
    ]);
    $failures[] = 'audit final defense: accepted';
} catch (InvalidArgumentException $error) {
    if (str_contains($error->getMessage(), $password)) {
        $failures[] = 'audit final defense: reflected input';
    } else {
        $passed++;
    }
} catch (Throwable $error) {
    $failures[] = 'audit final defense: reached persistence';
}

try {
    $audit->append([
        'actor_kind' => 'SYSTEM_ACCOUNT',
        'action' => 'IDENTITY_CREATE',
        'target_type' => 'IDENTITY',
        'target_id' => '1',
        'new_value' => ['real_name' => $password],
        'reason' => 'Roster entry correction',
        'result' => 'SUCCESS',
    ]);
    $failures[] = 'structured value final defense: accepted';
} catch (InvalidArgumentException $error) {
    if (str_contains($error->getMessage(), $password)) {
        $failures[] = 'structured value final defense: reflected input';
    } else {
        $passed++;
    }
} catch (Throwable $error) {
    $failures[] = 'structured value final defense: reached persistence';
}

// Archive date provenance is operational metadata, not a secret. It must be
// allowed through the same final audit-value allowlist that protects passwords
// and raw claim material; otherwise approval would create a Core image and
// then roll back the ClassIdentity transaction.
try {
    $encoder = new ReflectionMethod(Audit::class, 'encodeValue');
    $encoder->setAccessible(true);
    $encoded = $encoder->invoke($audit, ['date_source' => 'ARCHIVE_CONFIRMED'], 'new_value');
    assertSameValue('{"date_source":"ARCHIVE_CONFIRMED"}', $encoded, 'archive date source audit value');
    $album = $encoder->invoke($audit, ['era' => 'HERITAGE', 'name' => '毕业（私有 QA）', 'official' => 1], 'new_value');
    assertSameValue('{"era":"HERITAGE","name":"毕业（私有 QA）","official":1}', $album, 'archive album name audit value');

    // The full-library import records bounded operational state, never source
    // paths or original filenames. These fields must remain compatible with
    // Audit before an import can mutate native Piwigo state.
    $fullImport = $encoder->invoke($audit, [
        'manifest_version' => 1,
        'item_total' => 3,
        'applied_count' => 1,
        'deduplicated_count' => 1,
        'failed_count' => 1,
        'source_code' => 'PRIVATE_SOURCE_A',
        'presentation_kind' => 'MPO_PRIMARY_FRAME_JPEG',
        'source_collection_id' => '123e4567-e89b-42d3-a456-426614174000',
        'depth' => 2,
        'error_code' => 'STAGING_MIME',
        'state' => 'RUNNING',
    ], 'new_value');
    assertSameValue(
        '{"manifest_version":1,"item_total":3,"applied_count":1,"deduplicated_count":1,"failed_count":1,"source_code":"PRIVATE_SOURCE_A","presentation_kind":"MPO_PRIMARY_FRAME_JPEG","source_collection_id":"123e4567-e89b-42d3-a456-426614174000","depth":2,"error_code":"STAGING_MIME","state":"RUNNING"}',
        $fullImport,
        'private full import audit value',
    );
    try {
        $encoder->invoke($audit, ['relative_source_path' => 'private/source.jpg'], 'new_value');
        $failures[] = 'private full source path audit value: accepted';
    } catch (InvalidArgumentException) {
        $passed++;
    }
    try {
        $encoder->invoke($audit, ['presentation_kind' => '../PRIVATE_SOURCE'], 'new_value');
        $failures[] = 'private presentation kind audit value: accepted';
    } catch (InvalidArgumentException) {
        $passed++;
    }
} catch (Throwable $error) {
    $failures[] = 'archive date source audit value: rejected';
}

if ($failures !== []) {
    fwrite(STDERR, 'CLASS_IDENTITY_AUDIT_REASON=FAIL assertions=' . $passed . ' failures=' . count($failures) . "\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

fwrite(STDOUT, 'CLASS_IDENTITY_AUDIT_REASON=PASS assertions=' . $passed . "\n");
