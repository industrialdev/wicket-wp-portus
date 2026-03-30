<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Handles export/import of Wicket Memberships plugin-level settings.
 *
 * Scope: `wicket_membership_plugin_options` only.
 * CPT-backed records (membership configs, tiers) are handled by MembershipConfigPostsModule (stretch).
 * Live membership records created by customer transactions are never in scope.
 *
 * Import replaces the entire option. No merging.
 */
class MembershipOptionsModule implements ConfigModuleInterface
{
    private const OPTION_KEY = 'wicket_membership_plugin_options';

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
        return 'memberships';
    }

    /**
     * Reads wicket_membership_plugin_options and wraps it under the
     * 'plugin_options' key so the manifest shape is explicit about what
     * this module owns vs. the CPT-backed records in MembershipConfigPostsModule.
     *
     * {@inheritdoc}
     *
     * @return array
     */
    public function export(): array
    {
        $value = $this->reader->get(self::OPTION_KEY, []);

        return [
            'plugin_options' => is_array($value) ? $value : [],
        ];
    }

    /**
     * Checks that the payload contains a 'plugin_options' key and that its
     * value is an array. Both checks are hard errors because a missing or
     * malformed plugin_options would produce a broken import.
     *
     * @param array $payload
     * @return string[]
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (!isset($payload['plugin_options'])) {
            $errors[] = 'memberships: manifest is missing "plugin_options" key.';

            return $errors;
        }

        if (!is_array($payload['plugin_options'])) {
            $errors[] = 'memberships: "plugin_options" must be an array.';
        }

        return $errors;
    }

    /**
     * Imports wicket_membership_plugin_options into this environment.
     *
     * Validates the payload structure first. Aborts on errors. In dry-run
     * mode, reports what would be written without touching the database.
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

        $validation_errors = $this->validate($payload);
        foreach ($validation_errors as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        $plugin_options = $payload['plugin_options'];

        if ($dry_run) {
            $result->add_imported(self::OPTION_KEY);

            return $result;
        }

        $saved = $this->reader->set(self::OPTION_KEY, $plugin_options);

        if ($saved) {
            $result->add_imported(self::OPTION_KEY);
        } else {
            $result->add_error('memberships: update_option() returned false — option may be unchanged or the write failed.');
        }

        return $result;
    }
}
