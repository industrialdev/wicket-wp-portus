<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Contracts\OptionGroupProviderInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Export/import adapter for theme ACF options-page values stored in wp_options.
 */
class ThemeAcfOptionsModule implements ConfigModuleInterface, OptionGroupProviderInterface
{
    /**
     * LIKE patterns used to discover ACF options rows.
     *
     * @return string[]
     */
    private function option_name_patterns(): array
    {
        $patterns = [
            'options_%',
            '_options_%',
        ];

        $patterns = apply_filters('wicket_portus_theme_acf_option_name_patterns', $patterns);

        return is_array($patterns) ? $patterns : [];
    }

    public function __construct(
        private readonly WordPressOptionReader $reader,
        private readonly HyperfieldsOptionTransfer $transfer
    ) {}

    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return 'theme_acf_options';
    }

    /**
     * @inheritdoc
     */
    public function option_groups(): array
    {
        $groups = [];
        foreach ($this->discover_option_names() as $option_name) {
            $groups[$option_name] = sprintf('Theme ACF Option: %s', $option_name);
        }

        return $groups;
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
            $errors[] = 'theme_acf_options: payload must include an "options" array.';
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
            $diff = $this->transfer->diff_option_values($option_values, $allowed, '', 'replace');
            if (!($diff['success'] ?? false)) {
                $result->add_error((string) ($diff['message'] ?? 'theme_acf_options: dry-run diff failed.'));

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

        $import = $this->transfer->import_option_values($option_values, $allowed, '', 'replace');

        if ($import['success'] ?? false) {
            foreach ($allowed as $option_name) {
                if (array_key_exists($option_name, $option_values)) {
                    $result->add_imported($option_name);
                }
            }
        } else {
            $result->add_error((string) ($import['message'] ?? 'theme_acf_options: import failed.'));
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
