<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Handles export/import of Wicket Gravity Forms plugin-level settings.
 *
 * Does NOT implement OptionGroupProviderInterface — this module presents as a
 * single "Wicket Gravity Forms" row in the export UI, not as individual option rows.
 */
class WicketGfOptionsModule implements ConfigModuleInterface
{
    private const PLAIN_KEYS = [
        'wicket_gf_pagination_sidebar_layout',
        'wicket_gf_member_fields',
    ];

    private const JSON_ENCODED_KEYS = [
        'wicket_gf_slug_mapping',
    ];

    public function __construct(
        private readonly WordPressOptionReader $reader,
        private readonly HyperfieldsOptionTransfer $transfer
    ) {}

    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return 'gravity_forms_wicket_plugin';
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        $data = [];

        foreach (self::PLAIN_KEYS as $key) {
            $data[$key] = $this->reader->get($key, null);
        }

        foreach (self::JSON_ENCODED_KEYS as $key) {
            $raw = $this->reader->get($key, null);

            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $data[$key] = (JSON_ERROR_NONE === json_last_error()) ? $decoded : $raw;
            } else {
                $data[$key] = $raw;
            }
        }

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        // Absent keys are not a structural error — the import loop skips them
        // gracefully. Only flag a hard error if the payload is not an array at all.
        if (!is_array($payload)) {
            return ['gravity_forms_wicket_plugin: payload must be an array.'];
        }

        return [];
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

        $option_values = [];

        foreach (self::PLAIN_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $option_values[$key] = $payload[$key];
            } else {
                $result->add_skipped($key, 'absent from manifest');
            }
        }

        foreach (self::JSON_ENCODED_KEYS as $key) {
            if (!array_key_exists($key, $payload)) {
                $result->add_skipped($key, 'absent from manifest');
                continue;
            }

            $option_values[$key] = is_array($payload[$key])
                ? wp_json_encode($payload[$key])
                : $payload[$key];
        }

        if (empty($option_values)) {
            return $result;
        }

        $allowed = array_keys($option_values);

        if ($dry_run) {
            $diff = $this->transfer->diff_option_values($option_values, $allowed, '', 'replace');

            if (!($diff['success'] ?? false)) {
                $result->add_error((string) ($diff['message'] ?? 'gravity_forms_wicket_plugin: dry-run diff failed.'));

                return $result;
            }

            $changes = $diff['changes'] ?? [];
            if (is_array($changes)) {
                foreach (array_keys($changes) as $changed_key) {
                    $result->add_imported((string) $changed_key);
                }
            }

            if (empty($changes)) {
                foreach ($allowed as $key) {
                    $result->add_skipped($key, 'no changes detected');
                }
            }

            return $result;
        }

        $import = $this->transfer->import_option_values($option_values, $allowed, '', 'replace');

        if ($import['success'] ?? false) {
            foreach ($allowed as $key) {
                $result->add_imported($key);
            }
        } else {
            $result->add_error((string) ($import['message'] ?? 'gravity_forms_wicket_plugin: import failed.'));
        }

        return $result;
    }
}
