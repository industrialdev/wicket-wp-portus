<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use HyperFields\ContentTransferAdapter;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\PrivateContentPlusAttachmentsProfile;

/**
 * Export module for a curated list of specific pages by slug.
 *
 * The curated slug list is hardcoded to ensure only approved pages are included.
 */
class CuratedPagesExportModule implements ConfigModuleInterface
{
    private const POST_TYPE = 'page';

    /**
     * Curated list of page slugs to export.
     *
     * @var string[]
     */
    private const CURATED_SLUGS = [
        'shop',
        'checkout',
        'my-account',
        'create-account',
        'verify-account',
        'basic-page',
        'basic-page-side-nav',
        'grid-landing',
        'flex-landing-page',
        'communication-preferences',
        'membership',
        'donate',
    ];

    /**
     * @param string $module_key Module key used in the manifest envelope.
     */
    public function __construct(string $module_key = 'curated_pages')
    {
        $this->module_key = $module_key;
    }

    /**
     * @var string
     */
    private string $module_key;

    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return $this->module_key;
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        return [
            'post_type' => self::POST_TYPE,
            'curated_slugs' => self::CURATED_SLUGS,
            'posts' => ContentTransferAdapter::exportRows(self::POST_TYPE, [
                'orderby' => 'name',
                'order' => 'ASC',
                'post_name__in' => self::CURATED_SLUGS,
            ]),
        ];
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (($payload['post_type'] ?? '') !== self::POST_TYPE) {
            $errors[] = sprintf('%s: payload post_type must be "%s".', $this->module_key, self::POST_TYPE);
        }

        if (!isset($payload['posts']) || !is_array($payload['posts'])) {
            $errors[] = sprintf('%s: payload must contain a "posts" array.', $this->module_key);
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

        $curated_set = array_fill_keys(self::CURATED_SLUGS, true);
        $rows = [];
        foreach (($payload['posts'] ?? []) as $post_row) {
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

        $import = ContentTransferAdapter::importRows(
            rows: $rows,
            options: [
                'default_post_type' => self::POST_TYPE,
                'allowed_post_types' => [self::POST_TYPE],
                'dry_run' => $dry_run,
                'create_missing' => true,
                'update_existing' => true,
                'include_meta' => true,
                'meta_mode' => 'merge',
                'include_private_meta' => true,
                'normalization_profile' => PrivateContentPlusAttachmentsProfile::profile_key(),
            ]
        );
        foreach (($import['errors'] ?? []) as $error) {
            $result->add_error((string) $error);
        }

        $summary = ContentTransferAdapter::summarizeImportActions($import);
        foreach ($summary['imported'] as $key) {
            $result->add_imported((string) $key);
        }
        foreach ($summary['skipped'] as $skip) {
            $result->add_skipped((string) ($skip['key'] ?? 'unknown'), (string) ($skip['reason'] ?? 'skipped'));
        }

        return $result;
    }

    /**
     * Returns the curated slug list.
     *
     * @return string[]
     */
    public function curated_slugs(): array
    {
        return self::CURATED_SLUGS;
    }

}
