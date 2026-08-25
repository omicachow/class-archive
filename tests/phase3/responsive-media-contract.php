<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtimeRoot = sys_get_temp_dir() . '/class-archive-responsive-' . bin2hex(random_bytes(8));
if (!mkdir($runtimeRoot . '/upload', 0700, true) || file_put_contents($runtimeRoot . '/upload/synthetic.jpg', 'synthetic') === false) {
    throw new RuntimeException('responsive_media_runtime_root_unavailable');
}
if (!chmod($runtimeRoot . '/upload/synthetic.jpg', 0660)) {
    throw new RuntimeException('responsive_media_runtime_mode_unavailable');
}
define('PHPWG_ROOT_PATH', $runtimeRoot . '/');
define('IMAGES_TABLE', 'piwigo_images');
define('IMG_THUMB', 'th');
define('IMG_XSMALL', 'xs');
define('IMG_SMALL', 'sm');
define('IMG_MEDIUM', 'me');
define('IMG_LARGE', 'la');
define('IMG_XLARGE', 'xl');
define('IMG_CUSTOM', 'cu');

final class ImageStdParams
{
    /** @return array<string,true> */
    public static function get_defined_type_map(): array
    {
        return array_fill_keys(['th', 'xs', 'sm', 'me', 'la', 'xl'], true);
    }
}

final class SrcImage
{
    /** @param array<string,mixed> $row */
    public function __construct(public readonly array $row)
    {
    }
}

/**
 * A narrow Piwigo Core double. The XLARGE request deliberately resolves to
 * LARGE, mirroring Core's safe fallback behavior for sources that do not need
 * the requested transform. XS is deliberately an identity transform to prove
 * Class Archive still uses its forced-safe cache entry rather than the
 * original returned by Core.
 */
final class DerivativeImage
{
    private function __construct(
        private readonly string $effectiveType,
        private readonly bool $sameAsSource,
    ) {
    }

    public static function get_one(string $type, SrcImage $source): ?self
    {
        unset($source);
        if (!in_array($type, ['th', 'xs', 'sm', 'me', 'la', 'xl'], true)) {
            return null;
        }
        return new self(
            $type === 'xl' ? 'la' : $type,
            $type === 'xs',
        );
    }

    public function same_as_source(): bool
    {
        return $this->sameAsSource;
    }

    public function get_path(): string
    {
        return PHPWG_ROOT_PATH . '_data/i/upload/synthetic-' . $this->effectiveType . '.jpg';
    }
}

function derivative_to_url(string $type): string
{
    return $type;
}

function pwg_db_real_escape_string(string $value): string
{
    return addslashes($value);
}

/** @return list<array<string,mixed>> */
function query2array(string $sql): array
{
    unset($sql);
    return [[
        'id' => 42,
        'path' => './upload/synthetic.jpg',
        'file' => 'synthetic.jpg',
        'ext' => 'jpg',
    ]];
}

require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/CoreAdapter.php';
require $root . '/plugins/ClassIdentity/src/Access.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePerson.php';
require $root . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require $root . '/plugins/ClassIdentity/src/Gateway/GatewayPolicy.php';
require $root . '/plugins/ClassIdentity/src/Gateway/GatewayService.php';
require $root . '/plugins/ClassArchivePolicy/src/MediaGuard.php';

use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\Gateway\GatewayPhotoCandidate;
use ClassIdentity\Gateway\GatewayRouteContract;

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $label;
    }
};
$expects = static function (callable $callback, string $message, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    try {
        $callback();
        $failures[] = $label . ':not_thrown';
    } catch (Throwable $error) {
        if ($error->getMessage() !== $message) {
            $failures[] = $label . ':wrong_error:' . $error->getMessage();
        }
    }
};

$revision = str_repeat('a', 32);
$candidate = new GatewayPhotoCandidate(
    '10000000-0000-4000-8000-000000000001',
    'HERITAGE',
    ClassArchivePhoto::STATE_ACTIVE,
    ClassArchivePhoto::STATE_ACTIVE,
    '响应式媒体测试',
    '2023-10-18',
    ['毕业前档案'],
    '响应式媒体测试',
    42,
    'EXACT',
    'ARCHIVE_CONFIRMED',
    null,
    [7],
    $revision,
    4032,
    3024,
);
$public = $candidate->publicProjection();
$assert(($public['media_revision'] ?? null) === $revision, 'opaque_revision_projected');
$assert(($public['width'] ?? null) === 4032 && ($public['height'] ?? null) === 3024, 'safe_dimensions_projected');
$assert(($public['media']['delivery'] ?? null) === 'MEDIAGUARD_REQUIRED', 'mediaguard_contract_preserved');
$serialized = json_encode($public, JSON_THROW_ON_ERROR);
$assert(!str_contains($serialized, 'piwigo_image_id') && !str_contains($serialized, 'media_checksum') && !str_contains($serialized, 'media_reference'), 'backend_mapping_not_projected');

$readModel = $candidate->readModelProjection();
$roundTrip = GatewayPhotoCandidate::fromReadModelProjection($readModel)->publicProjection();
$assert(($roundTrip['media_revision'] ?? null) === $revision, 'read_model_preserves_revision');
$assert(($roundTrip['width'] ?? null) === 4032 && ($roundTrip['height'] ?? null) === 3024, 'read_model_preserves_dimensions');

$expects(
    static fn () => new GatewayPhotoCandidate(
        '10000000-0000-4000-8000-000000000002',
        'HERITAGE',
        ClassArchivePhoto::STATE_ACTIVE,
        ClassArchivePhoto::STATE_ACTIVE,
        null,
        null,
        [],
        '',
        1,
        'UNKNOWN',
        'UNKNOWN',
        null,
        [],
        'not-a-revision',
    ),
    'class_archive_gateway_media_revision_invalid',
    'revision_bounded',
);
$expects(
    static fn () => new GatewayPhotoCandidate(
        '10000000-0000-4000-8000-000000000003',
        'HERITAGE',
        ClassArchivePhoto::STATE_ACTIVE,
        ClassArchivePhoto::STATE_ACTIVE,
        null,
        null,
        [],
        '',
        1,
        'UNKNOWN',
        'UNKNOWN',
        null,
        [],
        $revision,
        4032,
        null,
    ),
    'class_archive_gateway_media_dimensions_invalid',
    'partial_dimensions_rejected',
);

$routes = GatewayRouteContract::routes();
$route = '/api/photos/{id}/media/{thumbnail|xsmall|small|medium|large|preview|original}';
$assert(($routes[$route]['method'] ?? null) === 'GET, HEAD', 'responsive_route_contract');

$guard = file_get_contents($root . '/plugins/ClassArchivePolicy/src/MediaGuard.php');
$controller = file_get_contents($root . '/plugins/ClassIdentity/src/Gateway/GatewayHttpController.php');
$bff = file_get_contents($root . '/infra/immich-spike/web-compat/server.mjs');
$adapter = file_get_contents($root . '/plugins/ClassIdentity/src/Gateway/PiwigoGatewayAdapter.php');
$assert(is_string($guard) && str_contains($guard, "'xsmall' => 'IMG_XSMALL'") && str_contains($guard, "'large' => 'IMG_LARGE'"), 'piwigo_profiles_reused');
$assert(is_string($controller) && str_contains($controller, "self::requireExactQuery(['v'], ['v'])") && str_contains($controller, 'every request; this value is never an authorization credential'), 'revision_not_authorization');
$assert(is_string($bff) && str_contains($bff, "['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview']") && str_contains($bff, "upstream.searchParams.set('v', mediaRevision)"), 'bff_responsive_whitelist');
$assert(is_string($adapter) && str_contains($adapter, 'class-archive-media-revision-v1\\0') && str_contains($adapter, 'pm.`media_checksum`'), 'revision_domain_separated_from_checksum');

$conf = ['class_archive_safe_preview_type' => IMG_XLARGE];
$expectedTokens = [
    'thumbnail' => 'th',
    'xsmall' => 'xs',
    'small' => 'sm',
    'medium' => 'me',
    'large' => 'la',
    // This is a Core-selected fallback. Canonical delivery must use exactly
    // the same warmed target, not reconstruct the requested `-xl` filename.
    'preview' => 'la',
];
if (!mkdir($runtimeRoot . '/_data/i/upload', 0700, true)) {
    throw new RuntimeException('responsive_media_derivative_root_unavailable');
}
foreach ($expectedTokens as $variant => $token) {
    if (file_put_contents($runtimeRoot . '/_data/i/upload/synthetic-' . $token . '.jpg', 'cached-' . $token) === false) {
        throw new RuntimeException('responsive_media_derivative_unavailable');
    }
    if (!chmod($runtimeRoot . '/_data/i/upload/synthetic-' . $token . '.jpg', 0660)) {
        throw new RuntimeException('responsive_media_derivative_mode_unavailable');
    }
    $resolved = ClassArchiveMediaGuard::resolveCanonicalDelivery(42, $variant);
    $path = $resolved['request']->derivativePath;
    $assert(is_string($path) && str_ends_with($path, '-' . $token . '.jpg'), 'runtime_derivative_profile_' . $variant);
}
$preview = ClassArchiveMediaGuard::resolveCanonicalDelivery(42, 'preview');
$assert(
    is_string($preview['request']->derivativePath)
    && str_ends_with($preview['request']->derivativePath, '-la.jpg')
    && !str_ends_with($preview['request']->derivativePath, '-xl.jpg'),
    'runtime_core_fallback_path_reused',
);
$identity = ClassArchiveMediaGuard::resolveCanonicalDelivery(42, 'xsmall');
$assert(
    is_string($identity['request']->derivativePath)
    && str_ends_with($identity['request']->derivativePath, '-xs.jpg'),
    'runtime_identity_uses_forced_safe_cache',
);
$hardlinkTarget = $runtimeRoot . '/_data/i/upload/synthetic-sm.jpg';
$hardlinkPeer = $runtimeRoot . '/_data/i/upload/.synthetic-hardlink.test';
if (!link($hardlinkTarget, $hardlinkPeer)) {
    throw new RuntimeException('responsive_media_hardlink_fixture_unavailable');
}
$hardlinked = ClassArchiveMediaGuard::resolveCanonicalDelivery(42, 'small');
$expects(
    static function () use ($hardlinked): void {
        ClassArchiveMediaGuard::assertDeliveryTarget($hardlinked['request']);
    },
    'derivative_not_ready',
    'runtime_hardlink_explicitly_unavailable',
);
if (!unlink($hardlinkPeer)) {
    throw new RuntimeException('responsive_media_hardlink_fixture_cleanup_failed');
}
ClassArchiveMediaGuard::assertDeliveryTarget($hardlinked['request']);
$assert(true, 'runtime_hardlink_removal_recovers_delivery');
$missing = $runtimeRoot . '/_data/i/upload/synthetic-la.jpg';
@unlink($missing);
$missingDelivery = ClassArchiveMediaGuard::resolveCanonicalDelivery(42, 'preview');
$expects(
    static function () use ($missingDelivery): void {
        ClassArchiveMediaGuard::assertDeliveryTarget($missingDelivery['request']);
    },
    'derivative_not_ready',
    'runtime_cache_miss_explicitly_unavailable',
);
$original = ClassArchiveMediaGuard::resolveCanonicalDelivery(42, 'original');
$assert($original['request']->variant === 'original' && $original['request']->derivativePath === null, 'runtime_original_stays_original');

if ($failures !== []) {
    foreach (array_keys($expectedTokens) as $variant) {
        @unlink($runtimeRoot . '/_data/i/upload/synthetic-' . $expectedTokens[$variant] . '.jpg');
    }
    @rmdir($runtimeRoot . '/_data/i/upload');
    @rmdir($runtimeRoot . '/_data/i');
    @rmdir($runtimeRoot . '/_data');
    @unlink($runtimeRoot . '/upload/synthetic.jpg');
    @rmdir($runtimeRoot . '/upload');
    @rmdir($runtimeRoot);
    fwrite(STDERR, "RESPONSIVE_MEDIA_CONTRACT=FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}

foreach ($expectedTokens as $token) {
    @unlink($runtimeRoot . '/_data/i/upload/synthetic-' . $token . '.jpg');
}
@rmdir($runtimeRoot . '/_data/i/upload');
@rmdir($runtimeRoot . '/_data/i');
@rmdir($runtimeRoot . '/_data');
@unlink($runtimeRoot . '/upload/synthetic.jpg');
@rmdir($runtimeRoot . '/upload');
@rmdir($runtimeRoot);
echo "RESPONSIVE_MEDIA_CONTRACT=PASS\n";
echo 'ASSERTIONS=' . $assertions . "\n";
