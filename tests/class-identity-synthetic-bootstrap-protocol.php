<?php

declare(strict_types=1);

$assertions = 0;
function protocolAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, "SYNTHETIC_BOOTSTRAP_PROTOCOL=FAIL {$message}\n");
        exit(1);
    }
}

$bootstrap = file_get_contents('/workspace/infra/scripts/bootstrap-class-identity.php');
$fixture = file_get_contents('/workspace/tests/fixtures/provision-access-users.php');
protocolAssert(is_string($bootstrap) && is_string($fixture), 'tracked protocol sources unavailable');
protocolAssert(substr_count($bootstrap, 'ensureSyntheticCoreUsers();') === 1, 'synthetic Core creation call is not singular');
protocolAssert(
    preg_match('/if \(\$withSyntheticFixtures\) \{\s*ensureSyntheticCoreUsers\(\);\s*provisionSyntheticFixtures/s', $bootstrap) === 1,
    'synthetic Core creation is not bounded by the explicit synthetic flag',
);
protocolAssert(str_contains($bootstrap, 'CoreAdapter::registerUser('), 'Core provisioning adapter is not used');
protocolAssert(str_contains($bootstrap, 'random_bytes(36)'), 'transient fixture passwords are not random');
protocolAssert(str_contains($bootstrap, '$created[] ='), 'new Core user ids are not tracked for compensation');
protocolAssert(
    str_contains($bootstrap, 'Pre-existing partial fixture Core account set is untrusted'),
    'pre-existing partial allowlist can be adopted instead of failing closed',
);
protocolAssert(str_contains($bootstrap, 'array_reverse($created)'), 'compensation does not unwind in reverse order');
protocolAssert(str_contains($bootstrap, 'Access::withCoreMutationPermit('), 'Core deletion is not permit-bounded');
protocolAssert(str_contains($bootstrap, 'delete_user($userId)'), 'exact Core compensation is missing');
protocolAssert(
    str_contains($bootstrap, 'exact compensation was incomplete; maintenance remains active'),
    'incomplete compensation does not declare the fail-closed maintenance outcome',
);
protocolAssert(!str_contains($fixture, 'register_user('), 'HTTP fixture can still create unbound Core accounts');
protocolAssert(
    str_contains($fixture, 'run the explicit synthetic ClassIdentity bootstrap first'),
    'HTTP fixture does not require the bound synthetic bootstrap order',
);

fwrite(STDOUT, "CLASS_IDENTITY_SYNTHETIC_BOOTSTRAP_PROTOCOL=PASS assertions={$assertions}\n");
