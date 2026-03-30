<?php

declare(strict_types=1);

namespace WicketPortus\Registry;

use WicketPortus\Contracts\ConfigModuleInterface;

class ModuleRegistry {

	/** @var ConfigModuleInterface[] Keyed by module key. */
	private array $modules = [];

	public function register( ConfigModuleInterface $module ): void {
		$this->modules[ $module->key() ] = $module;
	}

	/** @return ConfigModuleInterface[] */
	public function all(): array {
		return $this->modules;
	}

	public function get( string $key ): ?ConfigModuleInterface {
		return $this->modules[ $key ] ?? null;
	}

	public function has( string $key ): bool {
		return isset( $this->modules[ $key ] );
	}
}
