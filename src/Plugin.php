<?php

declare(strict_types=1);

namespace WicketPortus;

use WicketPortus\Modules\MembershipOptionsModule;
use WicketPortus\Modules\WicketGfOptionsModule;
use WicketPortus\Modules\WicketSettingsModule;
use WicketPortus\Registry\ModuleRegistry;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Main plugin class.
 *
 * Bootstraps Portus on the plugins_loaded hook, builds the module registry,
 * and registers the core settings adapters. Additional modules (ACC, theme,
 * plugin inventory) are registered by other team members via the
 * wicket_portus_register_modules action.
 */
class Plugin {

	private static ?Plugin $instance = null;

	private ModuleRegistry $registry;

	private function __construct() {}

	/**
	 * Returns the single plugin instance, creating and booting it on first call.
	 *
	 * Uses late static binding (new static()) so subclasses can extend without
	 * overriding this method.
	 *
	 * @return static
	 */
	public static function get_instance(): static {
		if ( static::$instance === null ) {
			static::$instance = new static();
			static::$instance->boot();
		}

		return static::$instance;
	}

	/**
	 * Initialises the module registry, registers core modules, then fires the
	 * extension hook so other plugin files can add their own adapters.
	 */
	private function boot(): void {
		$this->registry = new ModuleRegistry();
		$this->register_modules();

		/**
		 * Fires after core modules are registered.
		 * Other plugins (Marlon's ACC/theme adapters, Esteban's inventory module)
		 * use this hook to register additional modules.
		 *
		 * @param ModuleRegistry $registry
		 */
		do_action( 'wicket_portus_register_modules', $this->registry );
	}

	/**
	 * Registers the settings adapters owned by Alex:
	 * wicket_settings, memberships, and Wicket GF plugin options.
	 */
	private function register_modules(): void {
		$reader = new WordPressOptionReader();

		$this->registry->register( new WicketSettingsModule( $reader ) );
		$this->registry->register( new MembershipOptionsModule( $reader ) );
		$this->registry->register( new WicketGfOptionsModule( $reader ) );
	}

	/**
	 * Returns the module registry.
	 *
	 * Used by Esteban's ManifestBuilder and admin page controller to iterate
	 * all registered modules for export, validate, and import operations.
	 *
	 * @return ModuleRegistry
	 */
	public function registry(): ModuleRegistry {
		return $this->registry;
	}
}
