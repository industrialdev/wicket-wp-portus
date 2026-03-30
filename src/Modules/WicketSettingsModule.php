<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Contracts\OptionGroupProviderInterface;
use WicketPortus\Contracts\SanitizableModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Registry\SensitiveFieldsRegistry;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Handles export/import of the shared `wicket_settings` option.
 *
 * Uses HyperFields transfer primitives for diff/import so unchanged values are
 * handled correctly (no false hard-fail on update_option(false)).
 */
class WicketSettingsModule implements ConfigModuleInterface, OptionGroupProviderInterface, SanitizableModuleInterface
{
    private const OPTION_KEY = 'wicket_settings';

    public function __construct(
        private readonly WordPressOptionReader $reader,
        private readonly HyperfieldsOptionTransfer $transfer
    ) {}

    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return 'wicket_settings';
    }

    /**
     * @inheritdoc
     */
    public function option_groups(): array
    {
        return [
            self::OPTION_KEY => 'Wicket Base Settings',
        ];
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        $value = $this->reader->get(self::OPTION_KEY, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (empty($payload)) {
            $errors[] = 'wicket_settings: payload is empty — nothing would be imported.';

            return $errors;
        }

        if (!is_array($payload)) {
            $errors[] = 'wicket_settings: payload must be an array.';

            return $errors;
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

        $result->add_warning(
            'wicket_settings contains API credentials and environment secrets. '
            . 'Treat exports/imports as sensitive data.'
        );

        foreach ($this->validate($payload) as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        $option_values = [self::OPTION_KEY => $payload];

        if ($dry_run) {
            $diff = $this->transfer->diff_option_values(
                $option_values,
                [self::OPTION_KEY],
                '',
                'replace'
            );

            if (!($diff['success'] ?? false)) {
                $result->add_error((string) ($diff['message'] ?? 'wicket_settings: dry-run diff failed.'));

                return $result;
            }

            $changes = $diff['changes'] ?? [];
            if (is_array($changes) && array_key_exists(self::OPTION_KEY, $changes)) {
                $result->add_imported(self::OPTION_KEY);
            } else {
                $result->add_skipped(self::OPTION_KEY, 'no changes detected');
            }

            $skipped = $diff['skipped'] ?? [];
            if (is_array($skipped)) {
                foreach ($skipped as $message) {
                    $result->add_warning((string) $message);
                }
            }

            return $result;
        }

        $import = $this->transfer->import_option_values(
            $option_values,
            [self::OPTION_KEY],
            '',
            'replace'
        );

        if ($import['success'] ?? false) {
            $result->add_imported(self::OPTION_KEY);
        } else {
            $result->add_error((string) ($import['message'] ?? 'wicket_settings: import failed.'));
        }

        if (isset($import['backup_keys'][self::OPTION_KEY])) {
            $result->add_warning(
                sprintf(
                    'wicket_settings backup key: %s',
                    (string) $import['backup_keys'][self::OPTION_KEY]
                )
            );
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function sanitize(array $payload): array
    {
        $sanitized = $payload;
        $sensitiveKeys = SensitiveFieldsRegistry::for_module($this->key());

        foreach ($sensitiveKeys as $key) {
            unset($sanitized[$key]);
        }

        return $sanitized;
    }
}
