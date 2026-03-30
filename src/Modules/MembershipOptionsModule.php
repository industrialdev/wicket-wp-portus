<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Contracts\OptionGroupProviderInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Handles export/import of Wicket Memberships plugin-level settings.
 */
class MembershipOptionsModule implements ConfigModuleInterface, OptionGroupProviderInterface
{
    private const OPTION_KEY = 'wicket_membership_plugin_options';

    public function __construct(
        private readonly WordPressOptionReader $reader,
        private readonly HyperfieldsOptionTransfer $transfer
    ) {}

    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return 'memberships';
    }

    /**
     * @inheritdoc
     */
    public function option_groups(): array
    {
        return [
            self::OPTION_KEY => 'Wicket Memberships Plugin Options',
        ];
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        $value = $this->reader->get(self::OPTION_KEY, []);

        return [
            'plugin_options' => is_array($value) ? $value : [],
        ];
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (!isset($payload['plugin_options'])) {
            $errors[] = 'memberships: manifest is missing "plugin_options" key.';

            return $errors;
        }

        if (!is_array($payload['plugin_options'])) {
            $errors[] = 'memberships: "plugin_options" must be an array.';
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

        $option_values = [
            self::OPTION_KEY => $payload['plugin_options'],
        ];

        if ($dry_run) {
            $diff = $this->transfer->diff_option_values(
                $option_values,
                [self::OPTION_KEY],
                '',
                'replace'
            );

            if (!($diff['success'] ?? false)) {
                $result->add_error((string) ($diff['message'] ?? 'memberships: dry-run diff failed.'));

                return $result;
            }

            $changes = $diff['changes'] ?? [];
            if (is_array($changes) && array_key_exists(self::OPTION_KEY, $changes)) {
                $result->add_imported(self::OPTION_KEY);
            } else {
                $result->add_skipped(self::OPTION_KEY, 'no changes detected');
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
            $result->add_error((string) ($import['message'] ?? 'memberships: import failed.'));
        }

        return $result;
    }
}
