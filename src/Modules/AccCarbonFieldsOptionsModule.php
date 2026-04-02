<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use HyperFields\ContentTransferAdapter;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\PrivateContentPlusAttachmentsProfile;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Unified export/import for Account Centre options + curated my-account pages.
 *
 * Does NOT implement OptionGroupProviderInterface — this module presents as a
 * single "Wicket Account Centre" row in the export UI.
 */
class AccCarbonFieldsOptionsModule implements ConfigModuleInterface
{
    private const MY_ACCOUNT_POST_TYPE = 'my-account';

    /**
     * Curated list of account-centre my-account page slugs to export/import.
     *
     * @var string[]
     */
    private const MY_ACCOUNT_CURATED_SLUGS = [
        'dashboard',
        'manage-password',
        'manage-preferences',
        'payment-methods',
        'my-downloads',
        'my-cart',
        'edit-profile',
        'events',
        'payments-settings',
        'organization-management',
        'acc_global-headerbanner',
        'add-payment-method',
        'orders',
        'view-order',
        'subscriptions',
        'organization-members',
        'organization-profile',
        'change-password',
    ];

    /**
     * LIKE patterns used to discover ACC Carbon Fields option rows.
     *
     * @return string[]
     */
    private function option_name_patterns(): array
    {
        $patterns = [
            // Current Carbon Fields storage shape for ACC theme options.
            '_ac_localization',
            '_acc_sidebar_location',
            '_acc_profile_picture_size',
            '_acc_profile_picture_default%',
            '_acc_global-headerbanner',
            // Legacy/alternate container-derived keys.
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
            'my_account_posts' => ContentTransferAdapter::exportRows(self::MY_ACCOUNT_POST_TYPE, [
                'orderby' => 'name',
                'order' => 'ASC',
                'post_name__in' => self::MY_ACCOUNT_CURATED_SLUGS,
            ]),
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

        if (isset($payload['my_account_posts']) && !is_array($payload['my_account_posts'])) {
            $errors[] = 'account_centre: "my_account_posts" must be an array when provided.';
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

        } else {
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
        }

        $curated_set = array_fill_keys(self::MY_ACCOUNT_CURATED_SLUGS, true);
        $rows = [];
        foreach ($this->resolve_my_account_rows($payload) as $post_row) {
            if (!is_array($post_row)) {
                continue;
            }

            $slug = (string) ($post_row['post_name'] ?? '');
            $title = (string) ($post_row['post_title'] ?? '');
            $label = $title !== '' ? "{$slug} ({$title})" : $slug;
            if (!isset($curated_set[$slug])) {
                $result->add_skipped($label !== '' ? $label : 'unknown', 'slug not in curated list');
                continue;
            }

            $rows[] = $post_row;
        }

        $content_import = ContentTransferAdapter::importRows(
            rows: $rows,
            options: [
                'default_post_type' => self::MY_ACCOUNT_POST_TYPE,
                'allowed_post_types' => [self::MY_ACCOUNT_POST_TYPE],
                'dry_run' => $dry_run,
                'create_missing' => true,
                'update_existing' => true,
                'include_meta' => true,
                'meta_mode' => 'merge',
                'include_private_meta' => true,
                'normalization_profile' => PrivateContentPlusAttachmentsProfile::profile_key(),
            ]
        );

        foreach (($content_import['errors'] ?? []) as $error) {
            $result->add_error((string) $error);
        }

        $summary = ContentTransferAdapter::summarizeImportActions(
            $content_import,
            static fn (array $action_row, string $slug): string => $slug !== '' ? "my_account:{$slug}" : 'my_account:unknown'
        );
        foreach ($summary['imported'] as $key) {
            $result->add_imported((string) $key);
        }
        foreach ($summary['skipped'] as $skip) {
            $result->add_skipped((string) ($skip['key'] ?? 'my_account:unknown'), (string) ($skip['reason'] ?? 'skipped'));
        }

        return $result;
    }

    /**
     * Resolves account centre my-account rows from new and legacy payload shapes.
     *
     * @param array $payload
     * @return array
     */
    private function resolve_my_account_rows(array $payload): array
    {
        if (is_array($payload['my_account_posts'] ?? null)) {
            return $payload['my_account_posts'];
        }

        if (is_array($payload['posts'] ?? null) && ($payload['post_type'] ?? '') === self::MY_ACCOUNT_POST_TYPE) {
            return $payload['posts'];
        }

        return [];
    }

    /**
     * Returns the curated my-account slug list.
     *
     * @return string[]
     */
    public function curated_slugs(): array
    {
        return self::MY_ACCOUNT_CURATED_SLUGS;
    }

    /**
     * Returns the my-account post type used by this module.
     *
     * @return string
     */
    public function my_account_post_type(): string
    {
        return self::MY_ACCOUNT_POST_TYPE;
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
