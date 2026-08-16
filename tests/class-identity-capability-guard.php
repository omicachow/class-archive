<?php

declare(strict_types=1);

define('PHPWG_ROOT_PATH', __DIR__ . '/../');

require_once __DIR__ . '/../plugins/ClassIdentity/src/Access.php';
require_once __DIR__ . '/../plugins/ClassIdentity/src/CapabilityGuard.php';

use ClassIdentity\Access;
use ClassIdentity\CapabilityGuard;

$assertions = 0;

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    global $assertions;
    ++$assertions;
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL %s expected=%s actual=%s\n",
                $label,
                var_export($expected, true),
                var_export($actual, true),
            ),
        );
        exit(1);
    }
}

$allCapabilities = [
    CapabilityGuard::COMMENT_IMAGE,
    CapabilityGuard::RATE_IMAGE,
    CapabilityGuard::UPLOAD_PHOTO,
    CapabilityGuard::MANAGE_PHOTO,
    CapabilityGuard::CREATE_ALBUM,
    CapabilityGuard::MANAGE_ALBUM,
    CapabilityGuard::MANAGE_TAG,
    CapabilityGuard::PRIVATE_COLLECTION,
    CapabilityGuard::ACCOUNT_PREFERENCE,
];

foreach ([Access::ROLE_CLASSMATE, Access::ROLE_TEACHER, Access::ROLE_SYSTEM_ADMIN] as $role) {
    foreach ($allCapabilities as $capability) {
        assertSameValue(true, CapabilityGuard::roleAllows($role, $capability), "{$role}/{$capability}");
    }
}

foreach ($allCapabilities as $capability) {
    assertSameValue(
        in_array($capability, [CapabilityGuard::PRIVATE_COLLECTION, CapabilityGuard::ACCOUNT_PREFERENCE], true),
        CapabilityGuard::roleAllows(Access::ROLE_FAMILY, $capability),
        "FAMILY/{$capability}",
    );
    assertSameValue(false, CapabilityGuard::roleAllows(Access::ROLE_ANONYMOUS, $capability), "ANONYMOUS/default/{$capability}");
    assertSameValue(
        $capability === CapabilityGuard::COMMENT_IMAGE,
        CapabilityGuard::roleAllows(Access::ROLE_ANONYMOUS, $capability, true),
        "ANONYMOUS/presenter-ready/{$capability}",
    );
    assertSameValue(false, CapabilityGuard::roleAllows('UNKNOWN', $capability, true), "UNKNOWN/{$capability}");
}

$methodCases = [
    'pwg.images.addComment' => CapabilityGuard::COMMENT_IMAGE,
    'pwg.userComments.delete' => CapabilityGuard::COMMENT_IMAGE,
    'pwg.images.rate' => CapabilityGuard::RATE_IMAGE,
    'pwg.images.addChunk' => CapabilityGuard::UPLOAD_PHOTO,
    'pwg.images.addSimple' => CapabilityGuard::UPLOAD_PHOTO,
    'pwg.images.uploadAsync' => CapabilityGuard::UPLOAD_PHOTO,
    'community.images.uploadCompleted' => CapabilityGuard::UPLOAD_PHOTO,
    'pwg.images.setInfo' => CapabilityGuard::MANAGE_PHOTO,
    'pwg.images.delete' => CapabilityGuard::MANAGE_PHOTO,
    'pwg.categories.add' => CapabilityGuard::CREATE_ALBUM,
    'pwg.categories.setInfo' => CapabilityGuard::MANAGE_ALBUM,
    'pwg.tags.add' => CapabilityGuard::MANAGE_TAG,
    'pwg.users.favorites.add' => CapabilityGuard::PRIVATE_COLLECTION,
    'pwg.users.preferences.set' => CapabilityGuard::ACCOUNT_PREFERENCE,
];
foreach ($methodCases as $method => $capability) {
    assertSameValue($capability, CapabilityGuard::requiredCapabilityForWsMethod($method), "method/{$method}");
}

foreach (['pwg.images.getInfo', 'pwg.categories.getImages', 'pwg.session.getStatus', 'reflection.getMethodList'] as $method) {
    assertSameValue(null, CapabilityGuard::requiredCapabilityForWsMethod($method), "read/{$method}");
    assertSameValue(true, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_FAMILY, $method), "family-read/{$method}");
    assertSameValue(true, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_ANONYMOUS, $method), "anonymous-read/{$method}");
}

assertSameValue(true, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_FAMILY, 'pwg.users.favorites.getList'), 'family/favorites-read');
assertSameValue(false, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_ANONYMOUS, 'pwg.users.favorites.getList'), 'anonymous/favorites-read');
assertSameValue(false, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_FAMILY, 'thirdparty.unknownWrite'), 'family/unknown-ws');
assertSameValue(false, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_ANONYMOUS, 'thirdparty.unknownWrite'), 'anonymous/unknown-ws');
assertSameValue(false, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_CLASSMATE, 'thirdparty.unknownWrite'), 'classmate/unknown-ws');
assertSameValue(false, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_TEACHER, 'thirdparty.unknownWrite'), 'teacher/unknown-ws');
assertSameValue(true, CapabilityGuard::roleAllowsUnclassifiedWs(Access::ROLE_SYSTEM_ADMIN, 'thirdparty.unknownWrite'), 'system-admin/unknown-ws');

fwrite(STDOUT, "CLASS_IDENTITY_CAPABILITY_GUARD_OK assertions={$assertions}\n");
