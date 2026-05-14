<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Handles export/import of Wicket Gravity Forms plugin-level settings
 * plus per-form and per-field MDP/slug configuration.
 *
 * Does NOT implement OptionGroupProviderInterface — this module presents as a
 * single "Wicket Gravity Forms" row in the export UI.
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

    /**
     * GF field properties to export/import per field.
     *
     * @var string[]
     */
    private const FIELD_KEYS = [
        'wicket_enable_mdp_mapping',
        'wicket_mdp_target_object',
        'wicket_mdp_target_field',
        'wicket_field_slug',
    ];

    /**
     * GF form-level properties to export/import per form.
     *
     * @var string[]
     */
    private const FORM_KEYS = [
        'wicket_mdp_entity_type',
        'wicket_mdp_uuid_source_field',
    ];

    /**
     * @param WordPressOptionReader     $reader   WordPress options reader.
     * @param HyperfieldsOptionTransfer $transfer HyperFields transfer adapter.
     */
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

        // wp_options keys
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

        // Per-form and per-field settings from GF display_meta
        $data['per_form_settings'] = $this->export_per_form_settings();

        return $data;
    }

    /**
     * Collect per-form and per-field Wicket settings from GF form display_meta.
     *
     * @return array<string, array>
     */
    private function export_per_form_settings(): array
    {
        if (!class_exists('GFAPI')) {
            return [];
        }

        $forms = \GFAPI::get_forms();
        if (!is_array($forms)) {
            return [];
        }

        $per_form = [];

        foreach ($forms as $form) {
            $form_id = (string) ($form['id'] ?? '');
            if ($form_id === '') {
                continue;
            }

            $form_entry = [];

            // Per-form settings
            foreach (self::FORM_KEYS as $fk) {
                $val = $form[$fk] ?? null;
                if ($val !== null && $val !== '') {
                    $form_entry[$fk] = $val;
                }
            }

            // Per-field settings
            $fields_data = [];
            if (isset($form['fields']) && is_array($form['fields'])) {
                foreach ($form['fields'] as $field) {
                    $field_id = (string) ($field->id ?? '');
                    if ($field_id === '') {
                        continue;
                    }

                    $field_entry = [];
                    foreach (self::FIELD_KEYS as $fk) {
                        $val = $field->{$fk} ?? null;
                        // Include false explicitly — wicket_enable_mdp_mapping
                        // defaults to false and must survive round-trip.
                        if ($val !== null && $val !== '') {
                            $field_entry[$fk] = $val;
                        }
                    }

                    if (!empty($field_entry)) {
                        $fields_data[$field_id] = $field_entry;
                    }
                }
            }

            if (!empty($fields_data)) {
                $form_entry['fields'] = $fields_data;
            }

            if (!empty($form_entry)) {
                $per_form[$form_id] = $form_entry;
            }
        }

        return $per_form;
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (isset($payload['per_form_settings']) && !is_array($payload['per_form_settings'])) {
            $errors[] = 'gravity_forms_wicket_plugin: per_form_settings must be an array.';
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

        // --- wp_options keys (existing behaviour) ---

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

        if (!empty($option_values)) {
            $allowed = array_keys($option_values);

            if ($dry_run) {
                $diff = $this->transfer->diff_option_values($option_values, $allowed, '', 'merge');

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
            } else {
                $import = $this->transfer->import_option_values($option_values, $allowed, '', 'merge');

                if ($import['success'] ?? false) {
                    foreach ($allowed as $key) {
                        $result->add_imported($key);
                    }
                } else {
                    $result->add_error((string) ($import['message'] ?? 'gravity_forms_wicket_plugin: import failed.'));
                }
            }
        }

        // --- Per-form and per-field settings ---

        $per_form = $payload['per_form_settings'] ?? [];
        if (!is_array($per_form) || empty($per_form)) {
            return $result;
        }

        if (!class_exists('GFAPI')) {
            $result->add_error('gravity_forms_wicket_plugin: GFAPI not available for per-form import.');

            return $result;
        }

        foreach ($per_form as $form_id => $form_data) {
            if (!is_array($form_data)) {
                $result->add_warning("gravity_forms_wicket_plugin: per_form_settings.{$form_id} is not an array, skipped.");
                continue;
            }

            $form_id_int = absint($form_id);
            if ($form_id_int <= 0) {
                $result->add_warning("gravity_forms_wicket_plugin: invalid form ID {$form_id}, skipped.");
                continue;
            }

            $form = \GFAPI::get_form($form_id_int);
            if (!$form) {
                $result->add_warning("gravity_forms_wicket_plugin: form {$form_id} not found, skipped.");
                continue;
            }

            $form_changed = false;

            // Buffer imported keys — only report success after write confirms.
            $form_imported_keys = [];
            $form_skipped = [];

            // Apply per-form settings
            foreach (self::FORM_KEYS as $fk) {
                if (!array_key_exists($fk, $form_data)) {
                    continue;
                }

                $current = $form[$fk] ?? null;
                $incoming = $form_data[$fk];

                if ($current !== $incoming) {
                    $form[$fk] = $incoming;
                    $form_changed = true;
                    $form_imported_keys[] = "gf_form:{$form_id}:{$fk}";
                } else {
                    $form_skipped[] = ["gf_form:{$form_id}:{$fk}", 'no change'];
                }
            }

            // Apply per-field settings
            $fields_data = $form_data['fields'] ?? [];
            if (is_array($fields_data) && !empty($fields_data)) {
                $field_map = [];
                if (isset($form['fields']) && is_array($form['fields'])) {
                    foreach ($form['fields'] as $idx => $field) {
                        $field_map[(string) $field->id] = $idx;
                    }
                }

                foreach ($fields_data as $field_id => $field_entry) {
                    if (!is_array($field_entry)) {
                        // Malformed entry — report as warning so operators can debug.
                        $result->add_warning("gravity_forms_wicket_plugin: per_form_settings.{$form_id}.fields.{$field_id} is not an array.");
                        continue;
                    }

                    if (!isset($field_map[$field_id])) {
                        $form_skipped[] = ["gf_form:{$form_id}:field:{$field_id}", 'field not found in form'];
                        continue;
                    }

                    $field_idx = $field_map[$field_id];
                    $field = &$form['fields'][$field_idx];

                    foreach (self::FIELD_KEYS as $fk) {
                        if (!array_key_exists($fk, $field_entry)) {
                            continue;
                        }

                        $current = $field->{$fk} ?? null;
                        $incoming = $field_entry[$fk];

                        if ($current !== $incoming) {
                            $field->{$fk} = $incoming;
                            $form_changed = true;
                            $form_imported_keys[] = "gf_form:{$form_id}:field:{$field_id}:{$fk}";
                        } else {
                            $form_skipped[] = ["gf_form:{$form_id}:field:{$field_id}:{$fk}", 'no change'];
                        }
                    }
                }
            }

            if ($form_changed && !$dry_run) {
                $update = \GFAPI::update_form($form, $form_id_int);
                if (is_wp_error($update)) {
                    $result->add_error("gravity_forms_wicket_plugin: update_form failed for form {$form_id}: " . $update->get_error_message());
                } else {
                    foreach ($form_imported_keys as $key) {
                        $result->add_imported($key);
                    }
                }
            } else {
                // Dry-run: report what would be imported.
                foreach ($form_imported_keys as $key) {
                    $result->add_imported($key);
                }
            }

            // Report skipped keys regardless of write outcome.
            foreach ($form_skipped as [$key, $reason]) {
                $result->add_skipped($key, $reason);
            }
        }

        return $result;
    }
}
