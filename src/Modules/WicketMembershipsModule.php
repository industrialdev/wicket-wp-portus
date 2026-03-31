<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

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

        // Export membership config CPT posts
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        $post_rows = [];
        foreach ($posts as $post) {
            if (!($post instanceof \WP_Post)) {
                continue;
            }

            $post_rows[] = [
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
            'plugin_options' => $plugin_options,
            'config_posts' => $post_rows,
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
        } else {
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
        }

        // Config posts are export-only
        foreach (($payload['config_posts'] ?? []) as $post_row) {
            $slug = is_array($post_row) ? (string) ($post_row['post_name'] ?? '') : '';
            $key = $slug !== '' ? $slug : 'unknown';
            $result->add_skipped("config_post:{$key}", 'export-only (review in manifest)');
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
