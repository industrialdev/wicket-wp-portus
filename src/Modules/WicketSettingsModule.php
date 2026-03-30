<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Handles export/import of the shared `wicket_settings` option.
 *
 * This is the closest thing to a configuration nucleus in the Wicket stack.
 * It contains API credentials, environment endpoints, and feature flags used
 * by the base plugin and extended by Account Centre, Financial Fields, and
 * Guest Checkout. It always contains sensitive data.
 *
 * Import replaces the entire option. No merging. Operator must review
 * the diff before forcing an import into a live environment.
 */
class WicketSettingsModule implements ConfigModuleInterface
{
    private const OPTION_KEY = 'wicket_settings';

    public function __construct(
        private readonly WordPressOptionReader $reader
    ) {}

    /**
     * @inheritdoc
     *
     * @return string
     */
    public function key(): string
    {
        return 'wicket_settings';
    }

    /**
     * Reads the full wicket_settings array from the options table.
     * Returns an empty array when the option has never been saved.
     *
     * {@inheritdoc}
     *
     * @return array
     */
    public function export(): array
    {
        $value = $this->reader->get(self::OPTION_KEY, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Checks that the payload is a non-empty array and that the two
     * environment-critical keys (API endpoint and API key) are present.
     *
     * Missing environment keys are returned as errors because importing a
     * wicket_settings without them would break the site's Wicket connection.
     *
     * @param array $payload
     * @return string[]
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (empty($payload)) {
            $errors[] = 'wicket_settings: payload is empty — nothing would be imported.';

            return $errors;
        }

        if (!is_array($payload)) {
            $errors[] = 'wicket_settings: payload must be an array.';

            return $errors;
        }

        // Warn if key environment settings are absent — these are not hard errors
        // but operators should know they are missing before confirming an import.
        $expected_keys = ['wicket_admin_settings_api_endpoint', 'wicket_admin_settings_api_key'];
        foreach ($expected_keys as $key) {
            if (!array_key_exists($key, $payload)) {
                $errors[] = sprintf('wicket_settings: expected key "%s" is absent from the manifest.', $key);
            }
        }

        return $errors;
    }

    /**
     * Imports wicket_settings into this environment.
     *
     * Always emits a sensitive-data warning because the option contains API
     * credentials. Validates the payload first and aborts on errors. In
     * dry-run mode, reports what would happen without writing anything.
     *
     * {@inheritdoc}
     *
     * @param array $payload
     * @param array $options
     * @return ImportResult
     */
    public function import(array $payload, array $options = []): ImportResult
    {
        $dry_run = $options['dry_run'] ?? true;

        $result = $dry_run ? ImportResult::dry_run() : ImportResult::commit();

        $result->add_warning(
            'wicket_settings contains API credentials and environment secrets. '
            . 'Importing will overwrite the entire option on this environment.'
        );

        $validation_errors = $this->validate($payload);
        foreach ($validation_errors as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        if ($dry_run) {
            $result->add_imported(self::OPTION_KEY);

            return $result;
        }

        $saved = $this->reader->set(self::OPTION_KEY, $payload);

        if ($saved) {
            $result->add_imported(self::OPTION_KEY);
        } else {
            $result->add_error('wicket_settings: update_option() returned false — option may be unchanged or the write failed.');
        }

        return $result;
    }
}
