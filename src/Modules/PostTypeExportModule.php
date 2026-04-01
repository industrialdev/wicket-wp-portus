<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;

/**
 * Read-only export module for a single post type.
 *
 * Imports are intentionally deferred for now; this module exists to include
 * structural/content records in manifest exports for reviewability.
 */
class PostTypeExportModule implements ConfigModuleInterface
{
    /**
     * @param string $module_key Manifest module key.
     * @param string $post_type  WordPress post type to export.
     */
    public function __construct(
        private readonly string $module_key,
        private readonly string $post_type
    ) {}

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
            'post_type' => $this->post_type,
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        $rows = [];
        foreach ($posts as $post) {
            if (!($post instanceof \WP_Post)) {
                continue;
            }

            $rows[] = [
                '__strategy' => 'replace',
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
            'post_type' => $this->post_type,
            'posts' => $rows,
        ];
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (($payload['post_type'] ?? '') !== $this->post_type) {
            $errors[] = sprintf('%s: payload post_type must be "%s".', $this->module_key, $this->post_type);
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
            '%s: this module is export-only. No data will be written regardless of import mode.',
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
            $key = $slug !== '' ? $slug : 'unknown';
            $result->add_skipped($key, 'export-only module');
        }

        return $result;
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
