<?php

declare(strict_types=1);

namespace WicketPortus\Support;

use HyperFields\ExportImport;

/**
 * Thin adapter over HyperFields option transfer APIs.
 *
 * Centralises JSON payload construction + diff/import calls so modules can stay
 * focused on payload shape and scope rules.
 */
class HyperfieldsOptionTransfer
{
    /**
     * Exports a set of options and returns a decoded option map.
     *
     * @param string[] $option_names
     * @param string $prefix
     * @return array<string, mixed>
     */
    public function export_option_values(array $option_names, string $prefix = ''): array
    {
        $json = ExportImport::exportOptions($option_names, $prefix);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        $options = $decoded['options'] ?? [];

        return is_array($options) ? $options : [];
    }

    /**
     * Runs a HyperFields dry-run diff for the provided option values.
     *
     * @param array<string, mixed> $option_values
     * @param string[] $allowed_option_names
     * @param string $prefix
     * @param 'merge'|'replace' $mode
     * @return array<string, mixed>
     */
    public function diff_option_values(
        array $option_values,
        array $allowed_option_names,
        string $prefix = '',
        string $mode = 'merge'
    ): array {
        $json = $this->build_transport_json($option_values, $prefix);

        return ExportImport::diffOptions(
            $json,
            $allowed_option_names,
            $prefix,
            ['mode' => $mode]
        );
    }

    /**
     * Imports option values through HyperFields importOptions().
     *
     * @param array<string, mixed> $option_values
     * @param string[] $allowed_option_names
     * @param string $prefix
     * @param 'merge'|'replace' $mode
     * @return array<string, mixed>
     */
    public function import_option_values(
        array $option_values,
        array $allowed_option_names,
        string $prefix = '',
        string $mode = 'merge'
    ): array {
        $json = $this->build_transport_json($option_values, $prefix);

        return ExportImport::importOptions(
            $json,
            $allowed_option_names,
            $prefix,
            ['mode' => $mode]
        );
    }

    /**
     * Builds the HyperFields transport JSON shape from an option map.
     *
     * @param array<string, mixed> $option_values
     * @param string $prefix
     * @return string
     */
    private function build_transport_json(array $option_values, string $prefix = ''): string
    {
        $json = wp_json_encode(
            [
                'version' => '1.0',
                'type' => 'hyperfields_export',
                'prefix' => $prefix,
                'exported_at' => current_time('mysql'),
                'site_url' => get_site_url(),
                'options' => $option_values,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        return is_string($json) ? $json : '{}';
    }
}
