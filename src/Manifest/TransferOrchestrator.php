<?php

declare(strict_types=1);

namespace WicketPortus\Manifest;

use HyperFields\Transfer\Manager;
use HyperFields\Transfer\SchemaConfig;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Contracts\SanitizableModuleInterface;
use WicketPortus\Registry\ModuleRegistry;

/**
 * Bridges ModuleRegistry modules into a single export/diff/import workflow.
 *
 * Uses HyperFields Transfer\Manager for orchestration while preserving Portus'
 * manifest contract and module ownership boundaries.
 */
class TransferOrchestrator
{
    private const LOG_SOURCE = 'wicket-portus';

    /**
     * @param ModuleRegistry $registry Registered Portus modules.
     */
    public function __construct(
        private readonly ModuleRegistry $registry
    ) {}

    /**
     * Exports a manifest from selected modules (or all when empty).
     *
     * The envelope shape is driven by SchemaConfig in build_manager() and matches
     * the designed Portus manifest contract:
     *
     * {
     *   "schema_version": 1,
     *   "type": "wicket_portus_manifest",
     *   "generated_at": "...",
     *   "site": { "url": "...", "environment": "..." },
     *   "modules": { ... },
     *   "errors": []
     * }
     *
     * @param string[] $module_keys
     * @param string $mode 'full' (default) or 'template'
     * @return array<string, mixed>
     */
    public function export(array $module_keys = [], string $mode = 'full'): array
    {
        $started = microtime(true);
        $keys    = $this->resolve_module_keys($module_keys);
        $this->log('info', 'Portus export started.', [
            'operation' => 'export',
            'export_mode' => $mode,
            'requested_module_keys' => array_values(array_map('strval', $module_keys)),
            'resolved_module_keys' => $keys,
            'module_count' => count($keys),
        ]);

        $manager = $this->build_manager($keys);
        $bundle  = $manager->export($keys, ['export_mode' => $mode]);

        // Unwrap the inner ['payload'] wrapper added by build_manager() exporters.
        $modules = [];
        foreach (($bundle['modules'] ?? []) as $module_key => $module_payload) {
            $payload = is_array($module_payload) ? ($module_payload['payload'] ?? []) : [];

            // Template mode: sanitize modules that implement SanitizableModuleInterface.
            if ($mode === 'template') {
                $module = $this->registry->get($module_key);
                if ($module instanceof SanitizableModuleInterface) {
                    $payload = $module->sanitize($payload);
                }

                if ($this->strip_database_ids_for_template_exports()) {
                    $payload = $this->strip_database_ids($payload);
                }
            }

            $modules[$module_key] = $payload;
        }

        // Add export_mode to the envelope.
        $bundle['export_mode'] = $mode;

        // Return the full Manager envelope (which carries site, type, schema_version
        // from SchemaConfig) with the unwrapped module payloads substituted in.
        $result = array_merge($bundle, ['modules' => $modules]);
        $errors = is_array($bundle['errors'] ?? null) ? $bundle['errors'] : [];

        $this->log(empty($errors) ? 'info' : 'warning', 'Portus export completed.', [
            'operation' => 'export',
            'export_mode' => $mode,
            'module_count' => count($keys),
            'error_count' => count($errors),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $result;
    }

    /**
     * Returns whether template exports should remove numeric DB identifiers.
     *
     * @return bool
     */
    private function strip_database_ids_for_template_exports(): bool
    {
        return (bool) apply_filters('wicket_portus/export/template_strip_database_ids', true);
    }

    /**
     * Removes numeric database IDs from post-like rows in a payload.
     *
     * @param mixed $value
     * @return mixed
     */
    private function strip_database_ids(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->strip_database_ids($item);
        }

        if (
            array_key_exists('id', $value)
            && isset($value['post_type'])
            && isset($value['post_name'])
            && isset($value['post_title'])
            && is_numeric($value['id'])
        ) {
            unset($value['id']);
        }

        return $value;
    }

    /**
     * Runs dry-run diff/import across selected modules using an incoming manifest.
     *
     * @param array<string, mixed> $manifest
     * @param string[] $module_keys
     * @return array<string, mixed>
     */
    public function diff(array $manifest, array $module_keys = []): array
    {
        $started = microtime(true);
        $keys = $this->resolve_module_keys($module_keys);
        $this->log('info', 'Portus diff started.', [
            'operation' => 'diff',
            'requested_module_keys' => array_values(array_map('strval', $module_keys)),
            'resolved_module_keys' => $keys,
            'module_count' => count($keys),
        ]);

        $manager = $this->build_manager($keys);
        $bundle = $this->bundle_from_manifest($manifest, $keys);
        $result = $manager->diff($bundle, ['dry_run' => true]);
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $modules = is_array($result['modules'] ?? null) ? $result['modules'] : [];

        $this->log(empty($errors) ? 'info' : 'warning', 'Portus diff completed.', [
            'operation' => 'diff',
            'module_count' => count($keys),
            'result_module_count' => count($modules),
            'error_count' => count($errors),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $result;
    }

    /**
     * Imports selected modules from a manifest.
     *
     * @param array<string, mixed> $manifest
     * @param bool $dry_run
     * @param string[] $module_keys
     * @return array<string, mixed>
     */
    public function import(array $manifest, bool $dry_run = true, array $module_keys = []): array
    {
        $started = microtime(true);
        $keys = $this->resolve_module_keys($module_keys);
        $this->log('info', 'Portus import started.', [
            'operation' => 'import',
            'dry_run' => $dry_run,
            'requested_module_keys' => array_values(array_map('strval', $module_keys)),
            'resolved_module_keys' => $keys,
            'module_count' => count($keys),
        ]);

        $manager = $this->build_manager($keys);
        $bundle = $this->bundle_from_manifest($manifest, $keys);
        $result = $manager->import($bundle, ['dry_run' => $dry_run]);
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $modules = is_array($result['modules'] ?? null) ? $result['modules'] : [];

        $this->log(empty($errors) ? 'info' : 'warning', 'Portus import completed.', [
            'operation' => 'import',
            'dry_run' => $dry_run,
            'module_count' => count($keys),
            'result_module_count' => count($modules),
            'error_count' => count($errors),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $result;
    }

    /**
     * @param string[] $module_keys
     * @return string[]
     */
    private function resolve_module_keys(array $module_keys): array
    {
        if (!empty($module_keys)) {
            return array_values(
                array_filter(
                    array_map('strval', $module_keys),
                    fn (string $key): bool => $this->registry->has($key)
                )
            );
        }

        return array_keys($this->registry->all());
    }

    /**
     * @param string[] $module_keys
     * @return Manager
     */
    private function build_manager(array $module_keys): Manager
    {
        $manager = (new Manager())->withSchema(new SchemaConfig(
            type: 'wicket_portus_manifest',
            schema_version: 1,
            extra: [
                'site' => [
                    'url'         => function_exists('get_site_url') ? get_site_url() : '',
                    'environment' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production',
                ],
            ],
        ));

        foreach ($module_keys as $module_key) {
            $module = $this->registry->get($module_key);
            if (!$module instanceof ConfigModuleInterface) {
                continue;
            }

            $manager->registerModule(
                $module_key,
                exporter: function (array $context = []) use ($module_key, $module): array {
                    $started = microtime(true);
                    $this->log('debug', 'Portus module export started.', [
                        'operation' => 'module_export',
                        'module_key' => $module_key,
                        'context_keys' => array_keys($context),
                    ]);

                    try {
                        $payload = $module->export();
                        $this->log('debug', 'Portus module export completed.', [
                            'operation' => 'module_export',
                            'module_key' => $module_key,
                            'payload_keys' => is_array($payload) ? array_keys($payload) : [],
                            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                        ]);

                        return ['payload' => $payload];
                    } catch (\Throwable $e) {
                        $this->log('error', 'Portus module export failed.', [
                            'operation' => 'module_export',
                            'module_key' => $module_key,
                            'exception' => $e->getMessage(),
                            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                        ]);
                        throw $e;
                    }
                },
                importer: function (array $payload, array $context) use ($module_key, $module): array {
                    $started = microtime(true);
                    $dry_run = (bool) ($context['dry_run'] ?? false);
                    $module_payload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

                    $this->log('debug', 'Portus module import started.', [
                        'operation' => 'module_import',
                        'module_key' => $module_key,
                        'dry_run' => $dry_run,
                        'payload_keys' => array_keys($module_payload),
                    ]);

                    try {
                        $result = $module->import($module_payload, ['dry_run' => $dry_run])->to_array();
                        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
                        $imported = is_array($result['imported'] ?? null) ? $result['imported'] : [];
                        $skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];

                        $this->log(empty($errors) ? 'debug' : 'warning', 'Portus module import completed.', [
                            'operation' => 'module_import',
                            'module_key' => $module_key,
                            'dry_run' => $dry_run,
                            'imported_count' => count($imported),
                            'skipped_count' => count($skipped),
                            'error_count' => count($errors),
                            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                        ]);

                        return $result;
                    } catch (\Throwable $e) {
                        $this->log('error', 'Portus module import failed.', [
                            'operation' => 'module_import',
                            'module_key' => $module_key,
                            'dry_run' => $dry_run,
                            'exception' => $e->getMessage(),
                            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                        ]);
                        throw $e;
                    }
                },
                differ: function (array $payload, array $context = []) use ($module_key, $module): array {
                    $started = microtime(true);
                    $module_payload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
                    $this->log('debug', 'Portus module diff started.', [
                        'operation' => 'module_diff',
                        'module_key' => $module_key,
                        'context_keys' => array_keys($context),
                        'payload_keys' => array_keys($module_payload),
                    ]);

                    try {
                        $result = $module->import($module_payload, ['dry_run' => true])->to_array();
                        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
                        $this->log(empty($errors) ? 'debug' : 'warning', 'Portus module diff completed.', [
                            'operation' => 'module_diff',
                            'module_key' => $module_key,
                            'error_count' => count($errors),
                            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                        ]);

                        return $result;
                    } catch (\Throwable $e) {
                        $this->log('error', 'Portus module diff failed.', [
                            'operation' => 'module_diff',
                            'module_key' => $module_key,
                            'exception' => $e->getMessage(),
                            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                        ]);
                        throw $e;
                    }
                }
            );
        }

        return $manager;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param string[] $module_keys
     * @return array<string, mixed>
     */
    private function bundle_from_manifest(array $manifest, array $module_keys): array
    {
        $manifest_modules = $manifest['modules'] ?? [];
        $manifest_modules = is_array($manifest_modules) ? $manifest_modules : [];

        $modules = [];
        foreach ($module_keys as $module_key) {
            $modules[$module_key] = [
                'payload' => is_array($manifest_modules[$module_key] ?? null) ? $manifest_modules[$module_key] : [],
            ];
        }

        return [
            'schema_version' => 1,
            'type' => 'wicket_portus_manifest',
            'generated_at' => gmdate('c'),
            'modules' => $modules,
            'errors' => [],
        ];
    }

    /**
     * Writes Portus logs via Wicket base-plugin logger.
     *
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (!function_exists('Wicket')) {
            return;
        }

        try {
            $logger = Wicket()->log();
            if (!is_object($logger)) {
                return;
            }

            $context['source'] = self::LOG_SOURCE;
            $context['component'] = 'transfer-orchestrator';

            if (method_exists($logger, $level)) {
                $logger->{$level}($message, $context);
                return;
            }

            if (method_exists($logger, 'log')) {
                $logger->log($level, $message, $context);
            }
        } catch (\Throwable) {
            // Never let logging failures affect transfer behavior.
        }
    }
}
