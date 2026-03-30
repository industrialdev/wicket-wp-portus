<?php

declare(strict_types=1);

namespace WicketPortus\Manifest;

/**
 * Immutable-ish value object that accumulates the outcome of a single module import.
 *
 * Created via the named constructors dry_run() or commit(), then populated
 * fluently by the module's import() method before being returned to the caller.
 * The admin page controller and CLI layer read this object to build their output.
 */
class ImportResult
{
    /** @var string[] Option keys that were (or would be, in dry-run) written. */
    private array $imported = [];

    /** @var array<int, array{key: string, reason: string}> Keys that were skipped and why. */
    private array $skipped = [];

    /** @var string[] Non-fatal notices for the operator (e.g. sensitive-data reminders). */
    private array $warnings = [];

    /** @var string[] Fatal problems that prevented part or all of the import. */
    private array $errors = [];

    private bool $is_dry_run = true;

    private function __construct(bool $is_dry_run)
    {
        $this->is_dry_run = $is_dry_run;
    }

    /**
     * Creates a result for a simulated import — no writes will occur.
     *
     * @return self
     */
    public static function dry_run(): self
    {
        return new self(true);
    }

    /**
     * Creates a result for a real import that will write to the database.
     *
     * @return self
     */
    public static function commit(): self
    {
        return new self(false);
    }

    /**
     * Records an option key as successfully imported (or would-be imported in dry-run).
     *
     * @param string $key
     * @return self
     */
    public function add_imported(string $key): self
    {
        $this->imported[] = $key;

        return $this;
    }

    /**
     * Records an option key that was intentionally skipped, with a reason.
     *
     * @param string $key
     * @param string $reason
     * @return self
     */
    public function add_skipped(string $key, string $reason): self
    {
        $this->skipped[] = ['key' => $key, 'reason' => $reason];

        return $this;
    }

    /**
     * Records a non-fatal operator notice (e.g. sensitive-data reminder, version mismatch).
     *
     * @param string $message
     * @return self
     */
    public function add_warning(string $message): self
    {
        $this->warnings[] = $message;

        return $this;
    }

    /**
     * Records a fatal error that prevented this module from importing correctly.
     *
     * @param string $message
     * @return self
     */
    public function add_error(string $message): self
    {
        $this->errors[] = $message;

        return $this;
    }

    /**
     * Returns true when this result represents a simulated import.
     *
     * @return bool
     */
    public function is_dry_run(): bool
    {
        return $this->is_dry_run;
    }

    /**
     * Returns true when no errors were recorded.
     * Warnings do not affect success — they are informational only.
     *
     * @return bool
     */
    public function is_successful(): bool
    {
        return empty($this->errors);
    }

    /** @return string[] */
    public function imported(): array
    {
        return $this->imported;
    }

    /** @return array<int, array{key: string, reason: string}> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Serialises the result to a plain array suitable for JSON output or admin display.
     *
     * @return array{dry_run: bool, success: bool, imported: string[], skipped: array<int, array{key: string, reason: string}>, warnings: string[], errors: string[]}
     */
    public function to_array(): array
    {
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
