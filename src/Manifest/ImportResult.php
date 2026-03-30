<?php

declare(strict_types=1);

namespace WicketPortus\Manifest;

class ImportResult {

	private array $imported  = [];
	private array $skipped   = [];
	private array $warnings  = [];
	private array $errors    = [];
	private bool  $is_dry_run = true;

	private function __construct( bool $is_dry_run ) {
		$this->is_dry_run = $is_dry_run;
	}

	public static function dry_run(): self {
		return new self( true );
	}

	public static function commit(): self {
		return new self( false );
	}

	public function add_imported( string $key ): self {
		$this->imported[] = $key;
		return $this;
	}

	public function add_skipped( string $key, string $reason ): self {
		$this->skipped[] = [ 'key' => $key, 'reason' => $reason ];
		return $this;
	}

	public function add_warning( string $message ): self {
		$this->warnings[] = $message;
		return $this;
	}

	public function add_error( string $message ): self {
		$this->errors[] = $message;
		return $this;
	}

	public function is_dry_run(): bool {
		return $this->is_dry_run;
	}

	public function is_successful(): bool {
		return empty( $this->errors );
	}

	public function imported(): array {
		return $this->imported;
	}

	public function skipped(): array {
		return $this->skipped;
	}

	public function warnings(): array {
		return $this->warnings;
	}

	public function errors(): array {
		return $this->errors;
	}

	public function to_array(): array {
		return [
			'dry_run'  => $this->is_dry_run,
			'success'  => $this->is_successful(),
			'imported' => $this->imported,
			'skipped'  => $this->skipped,
			'warnings' => $this->warnings,
			'errors'   => $this->errors,
		];
	}
}
