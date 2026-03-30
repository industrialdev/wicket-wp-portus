<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;

/**
 * Developer-only snapshot of the full wp_options table.
 *
 * Import writes are deferred intentionally; this module is for diagnostics,
 * migration analysis, and forensic diff workflows.
 */
class DeveloperWpOptionsSnapshotModule implements ConfigModuleInterface
{
    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return 'developer_wp_options_snapshot';
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value, autoload FROM {$wpdb->options} ORDER BY option_name ASC",
            ARRAY_A
        );

        $options = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $option_name = (string) ($row['option_name'] ?? '');
                if ($option_name === '') {
                    continue;
                }

                $options[] = [
                    'option_name' => $option_name,
                    'option_value' => (string) ($row['option_value'] ?? ''),
                    'autoload' => (string) ($row['autoload'] ?? ''),
                ];
            }
        }

        return [
            'table' => $wpdb->options,
            'count' => count($options),
            'options' => $options,
        ];
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (!isset($payload['options']) || !is_array($payload['options'])) {
            $errors[] = 'developer_wp_options_snapshot: payload must contain an "options" array.';
        }

        return $errors;
    }

    /**
     * @inheritdoc
     */
    public function import(array $payload, array $options = []): ImportResult
    {
        unset($options);

        $result = ImportResult::dry_run();
        foreach ($this->validate($payload) as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        foreach (($payload['options'] ?? []) as $row) {
            $key = is_array($row) ? (string) ($row['option_name'] ?? 'unknown') : 'unknown';
            $result->add_skipped($key, 'developer wp_options snapshot import is deferred');
        }

        $result->add_warning('developer_wp_options_snapshot: import writes are deferred; module is export-only.');

        return $result;
    }
}

