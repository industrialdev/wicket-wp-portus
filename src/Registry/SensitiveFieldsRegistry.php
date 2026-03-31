<?php

declare(strict_types=1);

namespace WicketPortus\Registry;

/**
 * Central registry of per-module fields that are stripped in template export mode.
 *
 * Template mode is intended for new-client onboarding: credentials, API keys,
 * and environment-specific URLs are removed so the manifest can be safely shared
 * across organisations.
 *
 * Extend the list via the `wicket_portus_sensitive_fields` filter:
 *
 * ```php
 * add_filter('wicket_portus_sensitive_fields', function (array $fields): array {
 *     $fields['my_module_key'][] = 'my_secret_field';
 *     return $fields;
 * });
 * ```
 */
class SensitiveFieldsRegistry
{
    /**
     * Built-in sensitive fields, keyed by module key.
     *
     * The 'global' key contains patterns that apply to ALL modules.
     *
     * @var array<string, string[]>
     */
    private const DEFAULTS = [
        'global' => [
            // Common credential patterns
            'api_key',
            'apikey',
            'api_endpoint',
            'apiendpoint',
            'api_url',
            'apiurl',
            'secret',
            'secret_key',
            'secretkey',
            'password',
            'private_key',
            'privatekey',
            'auth_token',
            'authtoken',
            'access_token',
            'accesstoken',
            'jwt',
            'jwt_secret',
            'webhook_secret',
            'webhooksecret',
            // Environment/credentials
            'username',
            'user',
            'host',
            'port',
            // License keys
            'license_key',
            'licensekey',
        ],
        'wicket_settings' => [
            // Environment connection credentials
            'wicket_admin_settings_prod_api_endpoint',
            'wicket_admin_settings_prod_secret_key',
            'wicket_admin_settings_prod_person_id',
            'wicket_admin_settings_prod_parent_org',
            'wicket_admin_settings_prod_wicket_admin',
            'wicket_admin_settings_stage_api_endpoint',
            'wicket_admin_settings_stage_secret_key',
            'wicket_admin_settings_stage_person_id',
            'wicket_admin_settings_stage_parent_org',
            'wicket_admin_settings_stage_wicket_admin',
            // Google Captcha
            'wicket_admin_settings_google_captcha_enable',
            'wicket_admin_settings_google_captcha_key',
            'wicket_admin_settings_google_captcha_secret_key',
            // WP Cassify sync settings
            'wicket_admin_settings_wpcassify_sync_tags_as_roles',
            'wicket_admin_settings_wpcassify_ignore_roles',
            // Group assignment
            'wicket_admin_settings_group_assignment_subscription_products',
            'wicket_admin_settings_group_assignment_product_category',
            'wicket_admin_settings_group_assignment_role_entity_object',
            // Finance system enable
            'wicket_finance_enable_system',
            // Mailtrap credentials (from earlier list)
            'wicket_admin_settings_mailtrap_username',
            'wicket_admin_settings_mailtrap_password',
        ],
        'financial_fields' => [],
        'gravity_forms_wicket_plugin' => [],
        'account_centre' => [],
        'curated_pages' => [],
        'my_account_pages' => [],
        'woocommerce_emails' => [],
        'site_inventory' => [],
        'memberships' => [
            'wicket_mship_membership_merge_key',
        ],
        'developer_wp_options_snapshot' => [],
    ];

    /**
     * Returns the sensitive fields map, merged with any registered via WP filter.
     *
     * The filter receives and must return `array<string, string[]>`.
     *
     * @return array<string, string[]>
     */
    public static function get(): array
    {
        $fields = self::DEFAULTS;

        /**
         * Filters the per-module list of fields stripped during template export.
         *
         * @param array<string, string[]> $fields Module key => list of field names.
         */
        $filtered = apply_filters('wicket_portus_sensitive_fields', $fields);

        return is_array($filtered) ? $filtered : $fields;
    }

    /**
     * Returns the sensitive field names for a single module key,
     * including global wildcards that apply to all modules.
     *
     * @param string $module_key The module's manifest key.
     * @return string[]
     */
    public static function for_module(string $module_key): array
    {
        $all = self::get();

        // Start with module-specific fields
        $moduleFields = $all[$module_key] ?? [];
        $moduleFields = is_array($moduleFields) ? $moduleFields : [];

        // Add global wildcards
        $globalFields = $all['global'] ?? [];
        $globalFields = is_array($globalFields) ? $globalFields : [];

        return array_values(array_map('strval', array_merge($moduleFields, $globalFields)));
    }
}
