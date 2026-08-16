<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Repository.php';
require_once __DIR__ . '/src/Audit.php';

final class ClassIdentity_maintain extends PluginMaintain
{
    public function install($pluginVersion, &$errors = []): void
    {
        $this->applyMigrations((string) $pluginVersion, $errors);
    }

    public function activate($pluginVersion, &$errors = []): void
    {
        $this->applyMigrations((string) $pluginVersion, $errors);
    }

    public function update($oldVersion, $newVersion, &$errors = []): void
    {
        // Database ledger state, not a caller-supplied prior plugin version,
        // is the only authority for deciding which migrations remain.
        unset($oldVersion);
        $this->applyMigrations((string) $newVersion, $errors);
    }

    public function deactivate(): void
    {
    }

    public function uninstall(): void
    {
        // Uninstall is intentionally non-destructive. Identity, Seat,
        // principal, token-operation history and Audit records require a
        // separate backed-up retention/erasure procedure.
    }

    /** @param array<int, string> $errors */
    private function applyMigrations(string $pluginVersion, array &$errors): void
    {
        try {
            \ClassIdentity\Schema::fromPiwigo($pluginVersion)->migrate();
        } catch (\Throwable $exception) {
            $errors[] = 'ClassIdentity migration failed (' . $exception->getMessage() . ').';
            throw $exception;
        }
    }
}
