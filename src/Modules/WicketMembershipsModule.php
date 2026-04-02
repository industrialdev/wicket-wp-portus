<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use HyperFields\ContentTransferAdapter;
use HyperFields\Validation\SchemaValidator;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Unified export/import for Wicket Memberships plugin options and config CPT content.
 *
 * Combines MembershipOptionsModule and PostTypeExportModule for membership_config
 * into a single "Wicket Memberships" export row.
 */
class WicketMembershipsModule implements ConfigModuleInterface
{
    private const OPTION_KEY = 'wicket_membership_plugin_options';
    private const POST_TYPE = 'wicket_mship_config';
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
            'config_posts' => ContentTransferAdapter::exportRows(self::POST_TYPE),
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

        if (!isset($payload['config_posts']) || !is_array($payload['config_posts'])) {
            $errors[] = 'memberships: manifest must include a "config_posts" array.';
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

        $content_import = ContentTransferAdapter::importRows(
            rows: is_array($payload['config_posts'] ?? null) ? $payload['config_posts'] : [],
            options: [
                'default_post_type' => self::POST_TYPE,
                'allowed_post_types' => [self::POST_TYPE],
                'dry_run' => $dry_run,
                'create_missing' => true,
                'update_existing' => true,
                'include_meta' => true,
                // Keep unknown plugin/private keys that are not part of the
                // manifest while still updating known membership config keys.
                'meta_mode' => 'merge',
                'include_private_meta' => true,
                'normalization_profile' => 'wicket_memberships_config_v1',
            ]
        );

        foreach (($content_import['errors'] ?? []) as $error) {
            $result->add_error((string) $error);
        }

        $summary = ContentTransferAdapter::summarizeImportActions(
            $content_import,
            static fn (array $actionRow, string $slug): string => $slug !== '' ? "config_post:{$slug}" : 'config_post:unknown'
        );
        foreach ($summary['imported'] as $key) {
            $result->add_imported((string) $key);
        }
        foreach ($summary['skipped'] as $skip) {
            $result->add_skipped((string) ($skip['key'] ?? 'config_post:unknown'), (string) ($skip['reason'] ?? 'skipped'));
        }

        return $result;
    }
}
