<?php

declare(strict_types=1);

// Pure policy gate: database false is never a general runtime bypass. The
// trusted positive bootstrap case is exercised by the real installer flow.

defined('PHPWG_ROOT_PATH') or define('PHPWG_ROOT_PATH', dirname(__DIR__) . '/');
$conf = ['class_identity_enforcement' => false];
require_once dirname(__DIR__) . '/plugins/ClassIdentity/src/Access.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, "ENFORCEMENT_CONTEXT_ASSERTION_FAILED: {$message}\n");
        exit(1);
    }
};

$assert(\ClassIdentity\Access::isEnforcementEnabled(), 'CLI false without trusted context must remain enforced');
$assert(!\ClassIdentity\Access::enforcementDisabledForBootstrap(), 'untrusted CLI must not acquire bootstrap bypass');
$assert(\ClassIdentity\Access::hasUntrustedDisabledConfiguration(), 'untrusted false must be recognized as a fault');

$conf['class_identity_enforcement'] = true;
$assert(\ClassIdentity\Access::isEnforcementEnabled(), 'explicit true must be enforced');
$assert(!\ClassIdentity\Access::enforcementDisabledForBootstrap(), 'explicit true must not be bootstrap-disabled');
$assert(!\ClassIdentity\Access::hasUntrustedDisabledConfiguration(), 'explicit true must not be a fault');

unset($conf['class_identity_enforcement']);
$assert(\ClassIdentity\Access::isEnforcementEnabled(), 'missing setting must fail closed to enabled');
$assert(!\ClassIdentity\Access::hasUntrustedDisabledConfiguration(), 'missing setting is enabled, not an explicit disabled fault');

fwrite(STDOUT, 'CLASS_IDENTITY_ENFORCEMENT_CONTEXT=PASS assertions=' . $assertions . "\n");
