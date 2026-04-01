<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use HyperFields\Validation\SchemaValidator;
use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Export/import adapter for Wicket Financial Fields plugin settings.
 *
 * All options are stored as individual wp_options with wicket_finance_ prefix.
 *
 * Every exported field is wrapped in a typed-node envelope with full schema
 * rules. On import, values are validated via SchemaValidator.
 */
class FinancialFieldsModule implements ConfigModuleInterface
{
    /**
     * Schema rules for all financial fields options.
     *
     * @var array<string, array<string, mixed>>
     */
    private const OPTION_SCHEMA = [
        'wicket_finance_enable_system' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_customer_visible_categories' => ['type' => 'array', 'fields' => []],
        'wicket_finance_display_order_confirmation' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_display_emails' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_display_my_account' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_display_subscriptions' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_display_pdf_invoices' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_trigger_draft' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_trigger_pending' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_trigger_on_hold' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_trigger_processing' => ['type' => 'string', 'enum' => ['0', '1']],
        'wicket_finance_trigger_completed' => ['type' => 'string', 'enum' => ['0', '1']],
    ];

    /**
     * @return string[]
     */
    private static function option_names(): array
    {
        return array_keys(self::OPTION_SCHEMA);
    }

    /**
     * @param WordPressOptionReader     $reader   WordPress options reader.
     * @param HyperfieldsOptionTransfer $transfer HyperFields transfer adapter.
     */
    public function __construct(
        private readonly WordPressOptionReader $reader,
        private readonly HyperfieldsOptionTransfer $transfer
    ) {}

    /**
     * @inheritdoc
     */
    public function key(): string
    {
        return 'financial_fields';
    }

    /**
     * @inheritdoc
     */
    public function export(): array
    {
        $options = [];

        foreach (self::option_names() as $option_name) {
            $value = $this->reader->get($option_name, null);
            $schema = self::OPTION_SCHEMA[$option_name];

            $options[$option_name] = [
                'value'   => $value,
                '_schema' => $schema,
            ];
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function validate(array $payload): array
    {
        $errors = [];

        if (!is_array($payload)) {
            $errors[] = 'financial_fields: payload must be an array.';
            return $errors;
        }

        return [];
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

        $option_values = [];

        foreach ($payload as $option_name => $node) {
            $option_name = (string) $option_name;

            if (!self::is_typed_node($node)) {
                $result->add_error(sprintf(
                    'financial_fields: "%s" is missing typed-node envelope (value + _schema).',
                    $option_name
                ));
                continue;
            }

            if (!isset(self::OPTION_SCHEMA[$option_name])) {
                $result->add_skipped($option_name, 'not in allowed schema');
                continue;
            }

            // Verify manifest _schema.type matches static schema
            $schema_mismatch = $this->verify_schema_type_match(
                $option_name,
                $node['_schema'],
                self::OPTION_SCHEMA[$option_name]
            );

            if ($schema_mismatch !== null) {
                $result->add_error($schema_mismatch);
                continue;
            }

            // Validate value against static schema
            $error = SchemaValidator::validate($option_name, $node['value'], self::OPTION_SCHEMA[$option_name]);

            if ($error !== null) {
                $result->add_error('financial_fields: ' . $error);
                continue;
            }

            $option_values[$option_name] = $node['value'];
        }

        if (!$result->is_successful()) {
            return $result;
        }

        if (empty($option_values)) {
            return $result;
        }

        $allowed = array_keys($option_values);

        if ($dry_run) {
            $diff = $this->transfer->diff_option_values($option_values, $allowed, '', 'replace');

            if (!($diff['success'] ?? false)) {
                $result->add_error((string) ($diff['message'] ?? 'financial_fields: dry-run diff failed.'));
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

        $import = $this->transfer->import_option_values($option_values, $allowed, '', 'replace');

        if ($import['success'] ?? false) {
            foreach ($allowed as $key) {
                $result->add_imported($key);
            }
        } else {
            $result->add_error((string) ($import['message'] ?? 'financial_fields: import failed.'));
        }

        return $result;
    }

    /**
     * Checks if a node has the typed-node envelope structure.
     *
     * @param mixed $node
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
     * Verifies manifest _schema.type matches static schema type.
     *
     * @param string $field_name
     * @param array $manifest_schema
     * @param array $static_schema
     * @return string|null Error on mismatch, null on success.
     */
    private function verify_schema_type_match(string $field_name, array $manifest_schema, array $static_schema): ?string
    {
        $manifest_type = $manifest_schema['type'] ?? null;
        $static_type = $static_schema['type'] ?? null;

        if ($manifest_type !== $static_type) {
            return sprintf(
                'financial_fields: "%s" _schema.type mismatch — manifest declares "%s", expected "%s".',
                $field_name,
                $manifest_type ?? 'missing',
                $static_type ?? 'missing'
            );
        }

        return null;
    }
}
