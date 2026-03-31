<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;

/**
 * Export module for a curated list of specific pages by slug.
 *
 * This is export-only — imports are skipped with informational messages.
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
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'name',
            'order' => 'ASC',
            'post_name__in' => self::CURATED_SLUGS,
            'suppress_filters' => false,
        ]);

        $rows = [];
        foreach ($posts as $post) {
            if (!($post instanceof \WP_Post)) {
                continue;
            }

            $rows[] = [
                'id' => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'post_name' => (string) $post->post_name,
                'post_title' => (string) $post->post_title,
                'post_status' => (string) $post->post_status,
                'post_parent' => (int) $post->post_parent,
                'menu_order' => (int) $post->menu_order,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                'meta' => $this->export_post_meta((int) $post->ID),
            ];
        }

        return [
            'post_type' => self::POST_TYPE,
            'curated_slugs' => self::CURATED_SLUGS,
            'posts' => $rows,
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

        $result->add_warning(sprintf(
            '%s: this module is export-only. Content review only — no pages will be created or updated.',
            $this->module_key
        ));

        foreach ($this->validate($payload) as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        foreach (($payload['posts'] ?? []) as $post_row) {
            $slug = is_array($post_row) ? (string) ($post_row['post_name'] ?? '') : '';
            $title = is_array($post_row) ? (string) ($post_row['post_title'] ?? '') : '';
            $label = $title !== '' ? "{$slug} ({$title})" : $slug;
            $result->add_skipped($label, 'export-only module');
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

    /**
     * Exports all public + protected post meta values for a post.
     *
     * @param int $post_id
     * @return array<string, mixed>
     */
    private function export_post_meta(int $post_id): array
    {
        $meta = get_post_meta($post_id);
        if (!is_array($meta)) {
            return [];
        }

        $normalized = [];
        foreach ($meta as $key => $values) {
            if (!is_string($key) || !is_array($values)) {
                continue;
            }

            $mapped = array_map(
                static fn ($value): mixed => maybe_unserialize($value),
                $values
            );

            // Single-valued keys are unwrapped for readability.
            $normalized[$key] = count($mapped) === 1 ? $mapped[0] : $mapped;
        }

        ksort($normalized);

        return $normalized;
    }
}
