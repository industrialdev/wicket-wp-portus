<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use HyperFields\Validation\SchemaValidator;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Export/import adapter for WooCommerce email settings.
 *
 * Covers two categories of options stored in wp_options:
 *
 * 1. Global email options — individual scalar rows (from/sender, colours, template).
 * 2. Per-email-type settings — serialised arrays keyed as `woocommerce_{email_id}_settings`.
 *
 * Every exported field is wrapped in a typed-node envelope with full schema
 * rules.  On import, the manifest `_schema` is verified against the static
 * schema in this class (trust boundary), and values are validated via
 * {@see SchemaValidator}.
 *
 * Does NOT implement OptionGroupProviderInterface — this module presents as a
 * single "WooCommerce Emails" row in the export UI, not as individual option rows.
 */
class WooCommerceEmailModule implements ConfigModuleInterface
{
    // ──────────────────────────────────────────────────────────────────
    //  Static schema — the authoritative type contract for import.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Schema rules for global (scalar) email options.
     *
     * @var array<string, array<string, mixed>>
     */
    private const GLOBAL_SCHEMA = [
        'woocommerce_email_from_name'            => ['type' => 'string', 'max' => 255],
        'woocommerce_email_from_address'          => ['type' => 'string', 'max' => 320, 'format' => 'email'],
        'woocommerce_email_reply_to_enabled'      => ['type' => 'string', 'enum' => ['yes', 'no']],
        'woocommerce_email_reply_to_name'         => ['type' => 'string', 'max' => 255],
        'woocommerce_email_reply_to_address'      => ['type' => 'string', 'max' => 320, 'format' => 'email_or_empty'],
        'woocommerce_email_header_image'           => ['type' => 'string', 'max' => 2083, 'format' => 'url_or_empty'],
        'woocommerce_email_header_image_width'     => ['type' => 'string', 'pattern' => '/^\d{1,4}$/'],
        'woocommerce_email_header_alignment'       => ['type' => 'string', 'enum' => ['left', 'center', 'right']],
        'woocommerce_email_font_family'            => ['type' => 'string', 'max' => 255],
        'woocommerce_email_footer_text'            => ['type' => 'string', 'max' => 5000],
        'woocommerce_email_base_color'             => ['type' => 'string', 'format' => 'hex_color'],
        'woocommerce_email_background_color'       => ['type' => 'string', 'format' => 'hex_color'],
        'woocommerce_email_body_background_color'  => ['type' => 'string', 'format' => 'hex_color'],
        'woocommerce_email_text_color'             => ['type' => 'string', 'format' => 'hex_color'],
        'woocommerce_email_footer_text_color'      => ['type' => 'string', 'format' => 'hex_color'],
        'woocommerce_email_auto_sync_with_theme'   => ['type' => 'string', 'enum' => ['yes', 'no']],
    ];

    /**
     * Schema rules applied to every field inside a per-email settings array.
     *
     * @var array<string, array<string, mixed>>
     */
    private const EMAIL_SETTINGS_FIELD_SCHEMA = [
        'enabled'            => ['type' => 'string', 'enum' => ['yes', 'no']],
        'recipient'          => ['type' => 'string', 'max' => 2000, 'format' => 'email_csv_or_empty'],
        'subject'            => ['type' => 'string', 'max' => 500],
        'heading'            => ['type' => 'string', 'max' => 500],
        'additional_content' => ['type' => 'string', 'max' => 5000],
        'email_type'         => ['type' => 'string', 'enum' => ['html', 'plain', 'multipart']],
        'cc'                 => ['type' => 'string', 'max' => 2000, 'format' => 'email_csv_or_empty'],
        'bcc'                => ['type' => 'string', 'max' => 2000, 'format' => 'email_csv_or_empty'],
        'preheader'          => ['type' => 'string', 'max' => 500],
    ];

    /**
     * @return string[]
     */
    private static function global_option_names(): array
    {
        return array_keys(self::GLOBAL_SCHEMA);
    }

    /**
     * Returns all option names managed by this module for allow-listing.
     *
     * @return string[]
     */
    public function managed_option_names(): array
    {
        $names = self::global_option_names();

        foreach ($this->discover_email_ids() as $email_id) {
            $names[] = $this->email_option_name($email_id);
        }

        return $names;
    }

    /**
     * @param WordPressOptionReader     $reader   WordPress options reader.
     * @param HyperfieldsOptionTransfer $transfer HyperFields transfer adapter.
     */
    public function __construct(
        private readonly WordPressOptionReader $reader,
        private readonly HyperfieldsOptionTransfer $transfer,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    //  ConfigModuleInterface
    // ──────────────────────────────────────────────────────────────────

    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return 'woocommerce_emails';
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        // ── Global options (each wrapped with its full schema) ──────
        $global_options = [];

        foreach (self::GLOBAL_SCHEMA as $option_name => $schema) {
            $value = $this->reader->get($option_name, null);

            $global_options[$option_name] = [
                'value'   => $value,
                '_schema' => $schema,
            ];
        }

        // ── Per-email settings ──────────────────────────────────────
        $email_settings = [];
        $email_ids = $this->discover_email_ids();

        foreach ($email_ids as $email_id) {
            $option_name = $this->email_option_name($email_id);
            $raw_value = $this->reader->get($option_name, null);

            $email_settings[$email_id] = [
                'value'   => $raw_value,
                '_schema' => [
                    'type'   => 'array',
                    'fields' => self::EMAIL_SETTINGS_FIELD_SCHEMA,
                ],
            ];
        }

        return [
            'global_options' => $global_options,
            'email_settings' => $email_settings,
            'email_ids'      => $email_ids,
        ];
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (!isset($payload['global_options']) || !is_array($payload['global_options'])) {
            $errors[] = 'woocommerce_emails: payload must include a "global_options" array.';
        }

        if (!isset($payload['email_settings']) || !is_array($payload['email_settings'])) {
            $errors[] = 'woocommerce_emails: payload must include an "email_settings" array.';
        }

        return $errors;
    }

    /**
     * @inheritdoc
     */
    public function import(array $payload, array $options = []): ImportResult
    {
        $dry_run = (bool) ($options['dry_run'] ?? true);
        $result = $dry_run ? ImportResult::dry_run() : ImportResult::commit();

        foreach ($this->validate($payload) as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        // ── Global options ──────────────────────────────────────────
        $global_values = [];

        foreach ($payload['global_options'] as $option_name => $node) {
            $option_name = (string) $option_name;

            if (!self::is_typed_node($node)) {
                $result->add_error(sprintf(
                    'woocommerce_emails: "%s" is missing typed-node envelope (value + _schema).',
                    $option_name
                ));
                continue;
            }

            if (!isset(self::GLOBAL_SCHEMA[$option_name])) {
                $result->add_skipped($option_name, 'not in allowed global schema');
                continue;
            }

            // Verify manifest _schema.type matches static schema.
            $schema_mismatch = $this->verify_schema_type_match(
                $option_name,
                $node['_schema'],
                self::GLOBAL_SCHEMA[$option_name]
            );

            if ($schema_mismatch !== null) {
                $result->add_error($schema_mismatch);
                continue;
            }

            // Validate value against static schema via SchemaValidator.
            $error = SchemaValidator::validate($option_name, $node['value'], self::GLOBAL_SCHEMA[$option_name]);

            if ($error !== null) {
                $result->add_error('woocommerce_emails: ' . $error);
                continue;
            }

            $global_values[$option_name] = $node['value'];
        }

        // ── Per-email settings ──────────────────────────────────────
        $email_values = [];

        foreach ($payload['email_settings'] as $email_id => $node) {
            $email_id = (string) $email_id;
            $option_name = $this->email_option_name($email_id);

            if (!$this->is_valid_email_id($email_id)) {
                $result->add_skipped($option_name, 'email_id contains invalid characters');
                continue;
            }

            if (!self::is_typed_node($node)) {
                $result->add_error(sprintf(
                    'woocommerce_emails: "%s" is missing typed-node envelope (value + _schema).',
                    $option_name
                ));
                continue;
            }

            // Verify the declared type is 'array'.
            if (($node['_schema']['type'] ?? null) !== 'array') {
                $result->add_error(sprintf(
                    'woocommerce_emails: "%s" _schema.type must be "array", got "%s".',
                    $option_name,
                    $node['_schema']['type'] ?? 'missing'
                ));
                continue;
            }

            $settings = $node['value'];

            if ($settings === null) {
                $result->add_skipped($option_name, 'null value (option absent on source)');
                continue;
            }

            if (!is_array($settings)) {
                $result->add_error(sprintf('woocommerce_emails: "%s" value must be an array.', $option_name));
                continue;
            }

            // Validate known fields via SchemaValidator.
            $field_errors = SchemaValidator::validateMap($settings, self::EMAIL_SETTINGS_FIELD_SCHEMA, $option_name);

            foreach ($field_errors as $field_error) {
                $result->add_error('woocommerce_emails: ' . $field_error);
            }

            // Basic type enforcement on unknown fields.
            foreach ($settings as $field_name => $value) {
                if (isset(self::EMAIL_SETTINGS_FIELD_SCHEMA[(string) $field_name])) {
                    continue;
                }

                if (!is_string($value) && !is_array($value) && !is_bool($value) && !is_int($value) && $value !== null) {
                    $result->add_error(sprintf(
                        'woocommerce_emails: "%s.%s" has unsupported type %s.',
                        $option_name,
                        $field_name,
                        gettype($value)
                    ));
                }
            }

            if (!$result->is_successful()) {
                continue;
            }

            $email_values[$option_name] = $settings;
        }

        if (!$result->is_successful()) {
            return $result;
        }

        // ── Merge into a single option map for HyperFields transfer ─
        $all_values = array_merge($global_values, $email_values);

        if (empty($all_values)) {
            return $result;
        }

        $allowed = array_keys($all_values);

        if ($dry_run) {
            $diff = $this->transfer->diff_option_values($all_values, $allowed, '', 'merge');

            if (!($diff['success'] ?? false)) {
                $result->add_error((string) ($diff['message'] ?? 'woocommerce_emails: dry-run diff failed.'));

                return $result;
            }

            $changes = $diff['changes'] ?? [];
            if (is_array($changes)) {
                foreach (array_keys($changes) as $changed_key) {
                    $result->add_imported((string) $changed_key);
                }
            }

            if (empty($changes)) {
                foreach ($allowed as $key) {
                    $result->add_skipped($key, 'no changes detected');
                }
            }

            return $result;
        }

        $import = $this->transfer->import_option_values($all_values, $allowed, '', 'merge');

        if ($import['success'] ?? false) {
            foreach ($allowed as $key) {
                $result->add_imported($key);
            }

            if (!empty($import['backup_keys']) && is_array($import['backup_keys'])) {
                $result->add_warning(
                    'Backup transients created: ' . implode(', ', array_keys($import['backup_keys']))
                );
            }
        } else {
            $result->add_error((string) ($import['message'] ?? 'woocommerce_emails: import failed.'));
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Typed-node + schema helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Returns whether a value uses the typed-node envelope format.
     *
     * @param mixed $node Candidate manifest node.
     * @return bool
     */
    private static function is_typed_node(mixed $node): bool
    {
        return is_array($node)
            && array_key_exists('value', $node)
            && isset($node['_schema'])
            && is_array($node['_schema']);
    }

    /**
     * Verifies a manifest _schema.type matches the static schema type.
     *
     * @return string|null Error on mismatch, null on success.
     */
    private function verify_schema_type_match(string $field_name, array $manifest_schema, array $static_schema): ?string
    {
        $manifest_type = $manifest_schema['type'] ?? null;
        $static_type = $static_schema['type'] ?? null;

        if ($manifest_type !== $static_type) {
            return sprintf(
                'woocommerce_emails: "%s" _schema.type mismatch — manifest declares "%s", expected "%s".',
                $field_name,
                $manifest_type ?? 'missing',
                $static_type ?? 'missing'
            );
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Email discovery
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return string[]
     */
    private function discover_email_ids(): array
    {
        if (function_exists('WC') && class_exists('WC_Emails')) {
            $wc_emails = \WC_Emails::instance();
            $emails = $wc_emails->get_emails();

            if (is_array($emails) && !empty($emails)) {
                $ids = [];
                foreach ($emails as $email) {
                    if (is_object($email) && isset($email->id) && is_string($email->id)) {
                        $ids[] = $email->id;
                    }
                }

                if (!empty($ids)) {
                    sort($ids);

                    return $ids;
                }
            }
        }

        return self::fallback_core_email_ids();
    }

    /**
     * @return string[]
     */
    private static function fallback_core_email_ids(): array
    {
        return [
            'cancelled_order',
            'customer_cancelled_order',
            'customer_completed_order',
            'customer_failed_order',
            'customer_invoice',
            'customer_new_account',
            'customer_note',
            'customer_on_hold_order',
            'customer_partially_refunded_order',
            'customer_processing_order',
            'customer_refunded_order',
            'customer_reset_password',
            'failed_order',
            'new_order',
        ];
    }

    /**
     * Builds the wp_options key used for a specific WooCommerce email ID.
     *
     * @param string $email_id WooCommerce email identifier.
     * @return string
     */
    private function email_option_name(string $email_id): string
    {
        return 'woocommerce_' . $email_id . '_settings';
    }

    /**
     * Validates that an email ID is safe for option-name composition.
     *
     * @param string $email_id Candidate ID.
     * @return bool
     */
    private function is_valid_email_id(string $email_id): bool
    {
        return (bool) preg_match('/^[a-z0-9_]+$/', $email_id);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Utility
    // ──────────────────────────────────────────────────────────────────

    /**
     * Converts an option name into a human-readable label.
     *
     * @param string $option_name Option key.
     * @return string
     */
    private function humanize_option_name(string $option_name): string
    {
        $name = str_replace('woocommerce_email_', '', $option_name);

        return ucwords(str_replace('_', ' ', $name));
    }

    /**
     * Converts an email ID into a human-readable label.
     *
     * @param string $email_id Email identifier.
     * @return string
     */
    private function humanize_email_id(string $email_id): string
    {
        return ucwords(str_replace('_', ' ', $email_id));
    }
}
