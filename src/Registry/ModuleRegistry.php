<?php

declare(strict_types=1);

namespace WicketPortus\Registry;

use WicketPortus\Contracts\ConfigModuleInterface;

/**
 * Holds all registered Portus config modules.
 *
 * Modules are keyed by the string returned from ConfigModuleInterface::key().
 * Registering a module with a key that already exists silently replaces the
 * previous one — this is intentional so teams can override core modules.
 *
 * Esteban's ManifestBuilder calls all() to iterate every module for export.
 * The import controller calls get() to look up the correct module for each
 * key found in an incoming manifest.
 */
class ModuleRegistry
{
    /** @var ConfigModuleInterface[] Keyed by module key. */
    private array $modules = [];

    /**
     * Adds a module to the registry.
     * If a module with the same key already exists it is replaced.
     *
     * @param ConfigModuleInterface $module
     * @return void
     */
    public function register(ConfigModuleInterface $module): void
    {
        $this->modules[$module->key()] = $module;
    }

    /**
     * Returns all registered modules, keyed by their module key.
     *
     * @return ConfigModuleInterface[]
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Returns the module registered under the given key, or null if not found.
     *
     * @param string $key
     * @return ConfigModuleInterface|null
     */
    public function get(string $key): ?ConfigModuleInterface
    {
        return $this->modules[$key] ?? null;
    }

    /**
     * Returns true when a module is registered under the given key.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }
}
