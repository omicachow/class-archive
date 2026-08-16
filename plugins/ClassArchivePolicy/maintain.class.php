<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

final class ClassArchivePolicy_maintain extends PluginMaintain
{
    public function install($pluginVersion, &$errors = []): void
    {
        unset($pluginVersion, $errors);
    }

    public function activate($pluginVersion, &$errors = []): void
    {
        unset($pluginVersion, $errors);
    }

    public function deactivate(): void
    {
    }

    public function uninstall(): void
    {
        // Media and policy data are never deleted as a side effect of plugin
        // deactivation/uninstallation. Future migrations require an explicit,
        // separately backed-up data lifecycle operation.
    }
}
