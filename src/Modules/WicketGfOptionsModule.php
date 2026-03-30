<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Handles export/import of Wicket Gravity Forms plugin-level settings.
 *
 * Scope: Wicket GF plugin options only.
 * Native Gravity Forms forms, feeds, confirmations, notifications, and settings
 * are explicitly out of scope for the MVP.
 *
 * Notes:
 * - `wicket_gf_slug_mapping` is stored as a JSON-encoded string. We decode it
 *   on export so the manifest is readable, and re-encode it on import.
 * - Each option is written to its own row in wp_options (not nested under a
 *   single key like wicket_settings).
 */
class WicketGfOptionsModule implements ConfigModuleInterface {

	/**
	 * Options stored as raw values (non-encoded).
	 */
	private const PLAIN_KEYS = [
		'wicket_gf_pagination_sidebar_layout',
		'wicket_gf_member_fields',
	];

	/**
	 * Options stored as JSON-encoded strings in the database.
	 * We decode on export and re-encode on import.
	 */
	private const JSON_ENCODED_KEYS = [
		'wicket_gf_slug_mapping',
	];

	public function __construct(
		private readonly WordPressOptionReader $reader
	) {}

	public function key(): string {
		return 'gravity_forms_wicket_plugin';
	}

	public function export(): array {
		$data = [];

		foreach ( self::PLAIN_KEYS as $key ) {
			$data[ $key ] = $this->reader->get( $key, null );
		}

		foreach ( self::JSON_ENCODED_KEYS as $key ) {
			$raw = $this->reader->get( $key, null );
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				$data[ $key ] = ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $raw;
			} else {
				$data[ $key ] = $raw;
			}
		}

		return $data;
	}

	/**
	 * @return string[]
	 */
	public function validate( array $payload ): array {
		$errors = [];
		$all_keys = array_merge( self::PLAIN_KEYS, self::JSON_ENCODED_KEYS );

		foreach ( $all_keys as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				// Warn but don't hard-fail — individual keys may legitimately be absent
				// if the site never configured them.
				$errors[] = sprintf( 'gravity_forms_wicket_plugin: key "%s" is absent from the manifest.', $key );
			}
		}

		return $errors;
	}

	public function import( array $payload, array $options = [] ): ImportResult {
		$dry_run = $options['dry_run'] ?? true;

		$result = $dry_run ? ImportResult::dry_run() : ImportResult::commit();

		// Validation failures are warnings here, not hard errors, because individual
		// GF option keys may be absent on older sites. We skip missing keys and continue.
		$validation_messages = $this->validate( $payload );
		foreach ( $validation_messages as $message ) {
			$result->add_warning( $message );
		}

		if ( $dry_run ) {
			foreach ( array_merge( self::PLAIN_KEYS, self::JSON_ENCODED_KEYS ) as $key ) {
				if ( array_key_exists( $key, $payload ) ) {
					$result->add_imported( $key );
				} else {
					$result->add_skipped( $key, 'absent from manifest' );
				}
			}

			return $result;
		}

		foreach ( self::PLAIN_KEYS as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				$result->add_skipped( $key, 'absent from manifest' );
				continue;
			}

			$saved = $this->reader->set( $key, $payload[ $key ] );
			if ( $saved ) {
				$result->add_imported( $key );
			} else {
				$result->add_warning( sprintf( 'gravity_forms_wicket_plugin: update_option() returned false for "%s".', $key ) );
			}
		}

		foreach ( self::JSON_ENCODED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				$result->add_skipped( $key, 'absent from manifest' );
				continue;
			}

			// Re-encode to match how the GF plugin reads the option.
			$value = is_array( $payload[ $key ] )
				? wp_json_encode( $payload[ $key ] )
				: $payload[ $key ];

			$saved = $this->reader->set( $key, $value );
			if ( $saved ) {
				$result->add_imported( $key );
			} else {
				$result->add_warning( sprintf( 'gravity_forms_wicket_plugin: update_option() returned false for "%s".', $key ) );
			}
		}

		return $result;
	}
}
