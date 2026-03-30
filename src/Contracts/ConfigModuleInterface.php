<?php

declare(strict_types=1);

namespace WicketPortus\Contracts;

use WicketPortus\Manifest\ImportResult;

interface ConfigModuleInterface {

	/**
	 * Unique manifest key for this module (e.g. 'wicket_settings').
	 */
	public function key(): string;

	/**
	 * Export the module's configuration as a plain array suitable for JSON serialization.
	 */
	public function export(): array;

	/**
	 * Validate a manifest payload for this module before import.
	 *
	 * Returns an array of human-readable error/warning strings.
	 * An empty array means validation passed.
	 *
	 * @param array $payload The module's slice of the manifest.
	 * @return string[]
	 */
	public function validate( array $payload ): array;

	/**
	 * Import a manifest payload into this environment.
	 *
	 * Supported options:
	 *   - dry_run (bool, default true): simulate the import without writing.
	 *
	 * @param array $payload The module's slice of the manifest.
	 * @param array $options Import options.
	 */
	public function import( array $payload, array $options = [] ): ImportResult;
}
