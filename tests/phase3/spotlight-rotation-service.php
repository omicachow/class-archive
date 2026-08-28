<?php

declare(strict_types=1);

/**
 * Pure/static contract for the V4 persistent Spotlight hero rotation.
 *
 * No Piwigo, user, private media, browser clock or database fixture is used.
 * The companion MariaDB schema semantic suite verifies the additive v18 table.
 */

function spotlightRotationServiceFail(string $message): never
{
    throw new RuntimeException($message);
}

function spotlightRotationServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        spotlightRotationServiceFail($message);
    }
}

if (!defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', '/synthetic/class-archive/');
}

$root = dirname(__DIR__, 2);
$servicePath = $root . '/plugins/ClassIdentity/src/SpotlightRotationService.php';
$mainPath = $root . '/plugins/ClassIdentity/main.inc.php';
$installerPath = $root . '/infra/scripts/install-class-archive-plugins.php';
$auditPath = $root . '/plugins/ClassIdentity/src/Audit.php';
$source = file_get_contents($servicePath);
$main = file_get_contents($mainPath);
$installer = file_get_contents($installerPath);
$audit = file_get_contents($auditPath);
if (!is_string($source) || !is_string($main) || !is_string($installer) || !is_string($audit)) {
    fwrite(STDERR, "SPOTLIGHT_ROTATION_SERVICE=FAIL reason=source_unavailable\n");
    exit(1);
}

require $servicePath;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    spotlightRotationServiceAssert($condition, $message);
    ++$assertions;
};

try {
    foreach ([
        'spotlight_rotation_state',
        'collection_maintenance_state',
        'FOR UPDATE',
        'advanceForMaintenance',
        'stateForPublishedCandidates',
        'SPOTLIGHT_ROTATION_ADVANCE',
        'SPOTLIGHT_ROTATION_CLEAR',
    ] as $needle) {
        $assert(str_contains($source, $needle), 'rotation_service_missing_' . strtolower($needle));
    }
    $assert(str_contains($source, 'private static function publicResult') && str_contains($source, "unset(\$plan['candidateDigest'])"), 'rotation_service_must_not_return_raw_digest');
    $assert(!str_contains($source, '$_GET') && !str_contains($source, '$_POST') && !str_contains($source, '$_COOKIE'), 'rotation_service_must_not_read_browser_input');
    $assert(str_contains($main, "'src/SpotlightRotationService.php'"), 'rotation_service_not_loaded_by_plugin');
    $assert(str_contains($installer, "'src/SpotlightRotationService.php'"), 'rotation_service_not_installed');
    foreach (['scope', 'display_count', 'candidate_count', 'next_rotation_at', 'rotation_interval_seconds', 'rotation_state'] as $field) {
        $assert(str_contains($audit, "'{$field}'"), 'rotation_audit_field_not_allowlisted_' . $field);
    }

    $a = '10000000-0000-4000-8000-000000000001';
    $b = '20000000-0000-4000-8000-000000000002';
    $c = '30000000-0000-4000-8000-000000000003';
    $d = '40000000-0000-4000-8000-000000000004';
    $t0 = new DateTimeImmutable('2030-01-01 00:00:00.000000', new DateTimeZone('UTC'));

    $initial = \ClassIdentity\SpotlightRotationService::planForSyntheticTest('FULL', [$c, $a, $b], null, $t0);
    $assert($initial['heroSpotlightId'] === $a, 'initial_hero_must_be_deterministic');
    $assert($initial['orderedSpotlightIds'] === [$a, $b, $c], 'initial_order_must_preserve_all_candidates');
    $assert($initial['displayCount'] === 1 && $initial['lastRotatedAt'] === '2030-01-01 00:00:00.000000', 'initial_display_state_invalid');
    $assert($initial['nextRotationAt'] === '2030-01-01 01:00:00.000000' && $initial['changed'] === true, 'initial_schedule_invalid');

    $held = \ClassIdentity\SpotlightRotationService::planForSyntheticTest(
        'FULL', [$a, $b, $c], $initial, $t0->add(new DateInterval('PT30M')),
    );
    $assert($held['heroSpotlightId'] === $a && $held['orderedSpotlightIds'] === [$a, $b, $c], 'hero_changed_before_server_deadline');
    $assert($held['displayCount'] === 1 && $held['changed'] === false && $held['revision'] === $initial['revision'], 'held_state_not_persistent');

    $due = \ClassIdentity\SpotlightRotationService::planForSyntheticTest(
        'FULL', [$a, $b, $c], $held, $t0->add(new DateInterval('PT1H')),
    );
    $assert($due['heroSpotlightId'] === $b && $due['orderedSpotlightIds'] === [$b, $c, $a], 'due_rotation_not_fair_successor');
    $assert($due['displayCount'] === 2 && $due['nextRotationAt'] === '2030-01-01 02:00:00.000000', 'due_rotation_state_invalid');

    $expanded = \ClassIdentity\SpotlightRotationService::planForSyntheticTest(
        'FULL', [$a, $b, $c, $d], $due, $t0->add(new DateInterval('PT1H30M')),
    );
    $assert($expanded['heroSpotlightId'] === $b && $expanded['orderedSpotlightIds'] === [$b, $c, $d, $a], 'new_candidate_displaced_current_hero');
    $assert($expanded['displayCount'] === 2 && $expanded['changed'] === true && $expanded['lastRotatedAt'] === $due['lastRotatedAt'], 'candidate_reconciliation_restarted_rotation');

    $removed = \ClassIdentity\SpotlightRotationService::planForSyntheticTest(
        'FULL', [$a, $c, $d], $expanded, $t0->add(new DateInterval('PT1H31M')),
    );
    $assert($removed['heroSpotlightId'] === $c && $removed['orderedSpotlightIds'] === [$c, $d, $a], 'removed_hero_did_not_select_successor');
    $assert($removed['displayCount'] === 3 && $removed['changed'] === true, 'removed_hero_display_count_invalid');

    $empty = \ClassIdentity\SpotlightRotationService::planForSyntheticTest(
        'FULL', [], $removed, $t0->add(new DateInterval('PT1H32M')),
    );
    $assert($empty['heroSpotlightId'] === null && $empty['orderedSpotlightIds'] === [] && $empty['displayCount'] === 0, 'empty_rotation_state_invalid');
    $assert(is_string($empty['nextRotationAt']) && $empty['nextRotationAt'] !== '', 'empty_rotation_checkpoint_missing');

    $heritage = \ClassIdentity\SpotlightRotationService::planForSyntheticTest('HERITAGE', [$a, $b], null, $t0);
    $assert($heritage['heroSpotlightId'] === $a && $heritage['revision'] !== $initial['revision'], 'scope_state_not_isolated');

    foreach ([
        static fn() => \ClassIdentity\SpotlightRotationService::planForSyntheticTest('INVALID', [$a], null, $t0),
        static fn() => \ClassIdentity\SpotlightRotationService::planForSyntheticTest('FULL', [$a, $a], null, $t0),
        static fn() => \ClassIdentity\SpotlightRotationService::planForSyntheticTest('FULL', ['not-a-uuid'], null, $t0),
    ] as $invalid) {
        try {
            $invalid();
            spotlightRotationServiceFail('invalid_rotation_input_accepted');
        } catch (InvalidArgumentException) {
            ++$assertions;
        }
    }

    $publicShape = $due;
    unset($publicShape['candidateDigest']);
    $public = json_encode($publicShape, JSON_THROW_ON_ERROR);
    foreach (['principal', 'account', 'seat', 'owner'] as $forbidden) {
        $assert(stripos($public, $forbidden) === false, 'rotation_public_shape_leaks_' . $forbidden);
    }

    fwrite(STDOUT, "SPOTLIGHT_ROTATION_SERVICE=PASS assertions={$assertions}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'SPOTLIGHT_ROTATION_SERVICE=FAIL reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
