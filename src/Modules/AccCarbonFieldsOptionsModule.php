<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Export/import adapter for Account Centre Carbon Fields theme options.
 *
 * Does NOT implement OptionGroupProviderInterface — this module presents as a
 * single "Wicket Account Centre" row in the export UI.
 */
class AccCarbonFieldsOptionsModule implements ConfigModuleInterface
{
    /**
     * LIKE patterns used to discover ACC Carbon Fields option rows.
     *
     * @return string[]
     */
    private function option_name_patterns(): array
    {
        $patterns = [
            'carbon_fields_container_wicket_acc_options%',
            '_carbon_fields_container_wicket_acc_options%',
        ];

        $patterns = apply_filters('wicket_portus_acc_option_name_patterns', $patterns);

        return is_array($patterns) ? $patterns : [];
    }

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
        return 'account_centre';
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        $names = $this->discover_option_names();

        return [
            'option_names' => $names,
            'options' => $this->transfer->export_option_values($names),
        ];
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (!isset($payload['options']) || !is_array($payload['options'])) {
            $errors[] = 'account_centre: payload must include an "options" array.';
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

        $option_values = $payload['options'];
        $allowed = array_values(
            array_unique(
                array_map('strval', is_array($payload['option_names'] ?? null) ? $payload['option_names'] : array_keys($option_values))
            )
        );

        if ($dry_run) {
            $diff = $this->transfer->diff_option_values($option_values, $allowed, '', 'merge');
            if (!($diff['success'] ?? false)) {
                $result->add_error((string) ($diff['message'] ?? 'account_centre: dry-run diff failed.'));

                return $result;
            }

            $changes = $diff['changes'] ?? [];
            if (is_array($changes)) {
                foreach (array_keys($changes) as $option_name) {
                    $result->add_imported((string) $option_name);
                }
            }

            return $result;
        }

        $import = $this->transfer->import_option_values($option_values, $allowed, '', 'merge');

        if ($import['success'] ?? false) {
            foreach ($allowed as $option_name) {
                if (array_key_exists($option_name, $option_values)) {
                    $result->add_imported($option_name);
                }
            }
        } else {
            $result->add_error((string) ($import['message'] ?? 'account_centre: import failed.'));
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function discover_option_names(): array
    {
        $names = [];

        foreach ($this->option_name_patterns() as $pattern) {
            foreach ($this->reader->find_option_names_by_like($pattern) as $option_name) {
                $names[] = $option_name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
