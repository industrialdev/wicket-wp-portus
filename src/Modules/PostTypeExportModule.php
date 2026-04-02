<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use HyperFields\ContentTransferAdapter;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;

/**
 * Export/import module for a single post type.
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
        return [
            'post_type' => $this->post_type,
            'posts' => ContentTransferAdapter::exportRows($this->post_type),
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

        foreach ($this->validate($payload) as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        $import = ContentTransferAdapter::importRows(
            rows: is_array($payload['posts'] ?? null) ? $payload['posts'] : [],
            options: [
                'default_post_type' => $this->post_type,
                'allowed_post_types' => [$this->post_type],
                'dry_run' => $dry_run,
                'create_missing' => true,
                'update_existing' => true,
                'include_meta' => true,
                'meta_mode' => 'replace',
                'include_private_meta' => true,
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
}
