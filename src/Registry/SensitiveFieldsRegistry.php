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
     * @var array<string, string[]>
     */
    private const DEFAULTS = [
        'wicket_settings' => [
            // Wicket API credentials & endpoints
            'app_key',
            'secret_key',
            'api_endpoint',
            'service_id',
            'wicket_admin',
            'jwt_secret',
            'person_id',
            // Event / webhook secrets
            'webhook_secret',
            // OAuth / SSO
            'oauth_client_id',
            'oauth_client_secret',
            'sso_secret',
            // SMTP / mail
            'smtp_password',
            'smtp_user',
            // Licence keys that are environment-specific
            'license_key',
            // Encryption salts or tokens stored in settings
            'encryption_key',
            'auth_token',
        ],
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
     * Returns the sensitive field names for a single module key.
     *
     * @param string $module_key The module's manifest key.
     * @return string[]
     */
    public static function for_module(string $module_key): array
    {
        $all = self::get();
        $list = $all[$module_key] ?? [];

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }
}
