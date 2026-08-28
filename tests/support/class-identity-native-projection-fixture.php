<?php

declare(strict_types=1);

/**
 * Create empty, disposable Piwigo source tables for a prefixed Schema test.
 *
 * Migration 11 installs plugin-owned BEFORE triggers on these three Core
 * tables. Schema tests must never point those triggers at the live Piwigo
 * tables, so they clone DDL only and explicitly drop the clones in finally.
 * No source rows or private media are copied.
 *
 * @return list<string> unquoted table names in safe drop order
 */
function classIdentityCreateNativeProjectionFixture(
    mysqli $db,
    string $sourcePrefix,
    string $temporaryPrefix,
): array {
    foreach ([$sourcePrefix, $temporaryPrefix] as $prefix) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('native_projection_fixture_prefix_invalid');
        }
    }

    $created = [];
    foreach (['images', 'image_category', 'categories'] as $suffix) {
        $source = $sourcePrefix . $suffix;
        $target = $temporaryPrefix . $suffix;
        if ($db->query('CREATE TABLE `' . $target . '` LIKE `' . $source . '`') === false) {
            throw new RuntimeException('native_projection_fixture_create_failed_' . $db->errno);
        }
        $created[] = $target;
    }
    return array_reverse($created);
}

/** @param list<string> $tables */
function classIdentityDropNativeProjectionFixture(mysqli $db, array $tables): void
{
    foreach ($tables as $table) {
        if (!is_string($table) || preg_match('/\A[A-Za-z0-9_]+\z/D', $table) !== 1) {
            throw new RuntimeException('native_projection_fixture_table_invalid');
        }
        if ($db->query('DROP TABLE IF EXISTS `' . $table . '`') === false) {
            throw new RuntimeException('native_projection_fixture_drop_failed_' . $db->errno);
        }
    }
}
