<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;

/**
 * Exports plugin inventory and reports destination mismatches.
 *
 * Import does not activate/deactivate plugins in MVP; it only reports
 * missing plugins and version differences.
 */
class PluginInventoryModule implements ConfigModuleInterface
{
    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return 'site_inventory';
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $active_plugins = is_array($active_plugins) ? $active_plugins : [];

        $inventory = [];
        foreach ($plugins as $plugin_file => $plugin_data) {
            $inventory[] = [
                'plugin' => (string) $plugin_file,
                'name' => (string) ($plugin_data['Name'] ?? $plugin_file),
                'version' => (string) ($plugin_data['Version'] ?? ''),
                'active' => in_array($plugin_file, $active_plugins, true),
            ];
        }

        return [
            'plugins' => $inventory,
        ];
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];
        if (!isset($payload['plugins']) || !is_array($payload['plugins'])) {
            $errors[] = 'site_inventory: payload must contain a "plugins" array.';
        }

        return $errors;
    }

    /**
     * @inheritdoc
     */
    public function import(array $payload, array $options = []): ImportResult
    {
        $dry_run = (bool) ($options['dry_run'] ?? true);
        $result = $dry_run ? ImportResult::dry_run() : ImportResult::commit();

        foreach ($this->validate($payload) as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = get_plugins();
        $installed = is_array($installed) ? $installed : [];

        foreach ($payload['plugins'] as $plugin_row) {
            if (!is_array($plugin_row)) {
                continue;
            }

            $plugin_file = (string) ($plugin_row['plugin'] ?? '');
            $expected_version = (string) ($plugin_row['version'] ?? '');

            if ($plugin_file === '') {
                continue;
            }

            if (!array_key_exists($plugin_file, $installed)) {
                $result->add_warning(sprintf('site_inventory: plugin "%s" is missing on destination.', $plugin_file));
                $result->add_skipped($plugin_file, 'missing plugin');
                continue;
            }

            $installed_version = (string) ($installed[$plugin_file]['Version'] ?? '');
            if ($expected_version !== '' && $installed_version !== $expected_version) {
                $result->add_warning(
                    sprintf(
                        'site_inventory: plugin "%s" version mismatch (source: %s, destination: %s).',
                        $plugin_file,
                        $expected_version,
                        $installed_version
                    )
                );
            }

            $result->add_skipped($plugin_file, 'activation/deactivation writes are deferred in MVP');
        }

        return $result;
    }
}
