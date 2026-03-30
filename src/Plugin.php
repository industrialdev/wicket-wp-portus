<?php

declare(strict_types=1);

namespace WicketPortus;

use WicketPortus\Modules\MembershipOptionsModule;
use WicketPortus\Modules\WicketGfOptionsModule;
use WicketPortus\Modules\WicketSettingsModule;
use WicketPortus\Registry\ModuleRegistry;
use WicketPortus\Support\WordPressOptionReader;

class Plugin {

	private static ?Plugin $instance = null;

	private ModuleRegistry $registry;

	private function __construct() {}

	public static function get_instance(): static {
		if ( static::$instance === null ) {
			static::$instance = new static();
			static::$instance->boot();
		}

		return static::$instance;
	}

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

	private function register_modules(): void {
		$reader = new WordPressOptionReader();

		$this->registry->register( new WicketSettingsModule( $reader ) );
		$this->registry->register( new MembershipOptionsModule( $reader ) );
		$this->registry->register( new WicketGfOptionsModule( $reader ) );
	}

	public function registry(): ModuleRegistry {
		return $this->registry;
	}
}
