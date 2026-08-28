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
        try {
            $this->applyMigrations((string) $pluginVersion, $errors, true);
        } finally {
            // A standalone Piwigo "install" leaves the plugin inactive. The
            // subsequent "activate" action restores the guards when install
            // and activation are requested together.
            $this->retireNativeMutationProtection();
        }
    }

    public function activate($pluginVersion, &$errors = []): void
    {
        $this->applyMigrations((string) $pluginVersion, $errors, true);
    }

    public function update($oldVersion, $newVersion, &$errors = []): void
    {
        // Database ledger state, not a caller-supplied prior plugin version,
        // is the only authority for deciding which migrations remain.
        unset($oldVersion);
        $active = $this->isActiveInPiwigoRegistry();
        try {
            $this->applyMigrations((string) $newVersion, $errors, true);
        } finally {
            if (!$active) {
                $this->retireNativeMutationProtection();
            }
        }
    }

    public function deactivate(): void
    {
        $this->retireNativeMutationProtection();
    }

    public function uninstall(): void
    {
        // Piwigo 16.4.0 calls deactivate() first when uninstalling an active
        // plugin, then calls uninstall(). Repeat retirement so uninstalling an
        // already-inactive or partially deactivated plugin is equally safe.
        $this->retireNativeMutationProtection();

        // Uninstall is intentionally non-destructive. Identity, Seat,
        // principal, token-operation history and Audit records require a
        // separate backed-up retention/erasure procedure.
    }

    /** @param array<int, string> $errors */
    private function applyMigrations(string $pluginVersion, array &$errors, bool $prepareActivation): void
    {
        try {
            $schema = \ClassIdentity\Schema::fromPiwigo($pluginVersion);
            if ($prepareActivation) {
                $schema->prepareNativeMutationProtectionForActivation();
            }
            $schema->migrate();
        } catch (\Throwable $exception) {
            $errors[] = 'ClassIdentity migration failed (' . $exception->getMessage() . ').';
            throw $exception;
        }
    }

    private function retireNativeMutationProtection(): void
    {
        // Cleanup deliberately bypasses the activation version lock. If Core
        // was upgraded accidentally, plugin-owned triggers must still be
        // removable before any further Piwigo maintenance.
        $version = defined('CLASS_IDENTITY_VERSION') ? (string) CLASS_IDENTITY_VERSION : 'retirement';
        \ClassIdentity\Schema::fromPiwigoForRetirement($version)
            ->retireNativeMutationProtection();
    }

    private function isActiveInPiwigoRegistry(): bool
    {
        if (!function_exists('get_db_plugins')) {
            throw new \RuntimeException('class_identity_plugin_registry_unavailable');
        }
        $plugins = get_db_plugins('', $this->plugin_id);
        if (!is_array($plugins) || count($plugins) !== 1 || !is_array($plugins[0] ?? null)) {
            throw new \RuntimeException('class_identity_plugin_registry_ambiguous');
        }
        return ($plugins[0]['state'] ?? null) === 'active';
    }
}
