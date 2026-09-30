<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use HyperFields\Validation\SchemaValidator;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Export/import for Wicket Memberships plugin options.
 *
 * The membership config CPT content half was removed (WWID-2665): its
 * pipeline (ContentTransferAdapter) was deleted in commit 2cdcc8d, so every
 * content export/import call fataled. Options transfer still works; the
 * content feature needs a rebuilt pipeline before it returns.
 */
class WicketMembershipsModule implements ConfigModuleInterface
{
    private const OPTION_KEY = 'wicket_membership_plugin_options';
    private const OPTION_SCHEMA = [
        self::OPTION_KEY => ['type' => 'array'],
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
        return 'memberships';
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        // Export plugin options
        $plugin_options = $this->reader->get(self::OPTION_KEY, []);
        $plugin_options = is_array($plugin_options) ? $plugin_options : [];

        return [
            'plugin_options' => $plugin_options,
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
        } elseif (!is_array($payload['plugin_options'])) {
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

        // Import plugin options
        $option_values = [
            self::OPTION_KEY => $payload['plugin_options'],
        ];
        foreach (SchemaValidator::validateMap($option_values, self::OPTION_SCHEMA, 'memberships') as $validationError) {
            $result->add_error((string) $validationError);
        }
        if (!$result->is_successful()) {
            return $result;
        }

        if ($dry_run) {
            $diff = $this->transfer->diff_option_values(
                $option_values,
                [self::OPTION_KEY],
                '',
                'merge'
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
        } else {
            $import = $this->transfer->import_option_values(
                $option_values,
                [self::OPTION_KEY],
                '',
                'merge'
            );

            if ($import['success'] ?? false) {
                $result->add_imported(self::OPTION_KEY);
            } else {
                $result->add_error((string) ($import['message'] ?? 'memberships: import failed.'));
            }
        }

        return $result;
    }
}
