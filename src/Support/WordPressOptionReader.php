<?php

declare(strict_types=1);

namespace WicketPortus\Support;

/**
 * Thin wrapper around get_option/update_option.
 *
 * Keeps option I/O out of module bodies so modules stay unit-testable
 * by accepting a mock reader in tests.
 */
class WordPressOptionReader {

	public function get( string $key, mixed $default = [] ): mixed {
		return get_option( $key, $default );
	}

	public function set( string $key, mixed $value ): bool {
		return (bool) update_option( $key, $value );
	}

	public function exists( string $key ): bool {
		return get_option( $key, null ) !== null;
	}
}
