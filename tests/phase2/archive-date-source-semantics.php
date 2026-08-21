<?php

declare(strict_types=1);

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');

require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/ArchiveService.php';

$assertions = 0;

/** @param callable():mixed $callback */
function archiveDateSourceAssertThrows(callable $callback, string $expected): void
{
    global $assertions;
    ++$assertions;
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        if ($error->getMessage() === $expected) {
            return;
        }
    }
    throw new RuntimeException('archive_date_source_expected_' . $expected);
}

function archiveDateSourceAssertSame(string $expected, mixed $actual): void
{
    global $assertions;
    ++$assertions;
    if ($actual !== $expected) {
        throw new RuntimeException('archive_date_source_value_mismatch');
    }
}

try {
    $method = new ReflectionMethod(\ClassIdentityArchiveService::class, 'normalizeDateSource');
    archiveDateSourceAssertSame('ARCHIVE_CONFIRMED', $method->invoke(null, 'ARCHIVE_CONFIRMED', '2012-06-18', 'EXACT', 'MEDIUM', null));
    archiveDateSourceAssertSame('EXIF_TRUSTED', $method->invoke(null, 'EXIF_TRUSTED', '2011-01-01', 'YEAR', 'HIGH', null));
    archiveDateSourceAssertSame('EVENT_INFERENCE', $method->invoke(null, 'EVENT_INFERENCE', null, 'TERM', 'MEDIUM', '2012年秋季学期'));
    archiveDateSourceAssertSame('UNKNOWN', $method->invoke(null, 'UNKNOWN', null, 'UNKNOWN', 'UNKNOWN', null));
    archiveDateSourceAssertThrows(static fn () => $method->invoke(null, 'EXIF_TRUSTED', '2011-01-01', 'YEAR', 'MEDIUM', null), 'archive_date_source_exif_requires_high_confidence');
    archiveDateSourceAssertThrows(static fn () => $method->invoke(null, 'EVENT_INFERENCE', '2012-01-01', 'EVENT_ONLY', 'HIGH', '运动会'), 'archive_date_source_evidence_mismatch');
    archiveDateSourceAssertThrows(static fn () => $method->invoke(null, 'UNKNOWN', '2012-01-01', 'DAY', 'HIGH', null), 'archive_date_source_evidence_mismatch');
    archiveDateSourceAssertThrows(static fn () => $method->invoke(null, 'INVALID', null, 'UNKNOWN', 'UNKNOWN', null), 'archive_date_source_invalid');
} catch (Throwable $error) {
    fwrite(STDERR, 'ARCHIVE_DATE_SOURCE_SEMANTICS=FAIL reason=' . $error->getMessage() . " assertions={$assertions}\n");
    exit(1);
}

fwrite(STDOUT, "ARCHIVE_DATE_SOURCE_SEMANTICS=PASS assertions={$assertions}\n");
