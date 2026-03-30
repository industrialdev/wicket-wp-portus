<?php

declare(strict_types=1);

namespace WicketPortus\Manifest;

use HyperFields\Transfer\Manager;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Registry\ModuleRegistry;

/**
 * Bridges ModuleRegistry modules into a single export/diff/import workflow.
 *
 * Uses HyperFields Transfer\Manager for orchestration while preserving Portus'
 * manifest contract and module ownership boundaries.
 */
class TransferOrchestrator
{
    public function __construct(
        private readonly ModuleRegistry $registry
    ) {}

    /**
     * Exports a manifest from selected modules (or all when empty).
     *
     * @param string[] $module_keys
     * @return array<string, mixed>
     */
    public function export(array $module_keys = []): array
    {
        $keys = $this->resolve_module_keys($module_keys);
        $manager = $this->build_manager($keys);
        $bundle = $manager->export($keys);

        $modules = [];
        foreach (($bundle['modules'] ?? []) as $module_key => $module_payload) {
            $modules[$module_key] = is_array($module_payload) ? ($module_payload['payload'] ?? []) : [];
        }

        return [
            'schema_version' => 1,
            'type' => 'wicket_portus_manifest',
            'generated_at' => gmdate('c'),
            'modules' => $modules,
            'errors' => $bundle['errors'] ?? [],
        ];
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
        $keys = $this->resolve_module_keys($module_keys);
        $manager = $this->build_manager($keys);
        $bundle = $this->bundle_from_manifest($manifest, $keys);

        return $manager->diff($bundle, ['dry_run' => true]);
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
        $keys = $this->resolve_module_keys($module_keys);
        $manager = $this->build_manager($keys);
        $bundle = $this->bundle_from_manifest($manifest, $keys);

        return $manager->import($bundle, ['dry_run' => $dry_run]);
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
        $manager = new Manager();

        foreach ($module_keys as $module_key) {
            $module = $this->registry->get($module_key);
            if (!$module instanceof ConfigModuleInterface) {
                continue;
            }

            $manager->registerModule(
                $module_key,
                exporter: static fn (): array => ['payload' => $module->export()],
                importer: static fn (array $payload, array $context): array => $module
                    ->import(
                        is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
                        ['dry_run' => (bool) ($context['dry_run'] ?? false)]
                    )
                    ->to_array(),
                differ: static fn (array $payload): array => $module
                    ->import(
                        is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
                        ['dry_run' => true]
                    )
                    ->to_array()
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
}
