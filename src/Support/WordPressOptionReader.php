<?php

declare(strict_types=1);

namespace WicketPortus\Support;

/**
 * Thin wrapper around get_option/update_option.
 *
 * Keeps option I/O out of module bodies so modules stay unit-testable
 * by accepting a mock reader in tests.
 */
class WordPressOptionReader
{
    /**
     * Reads a WordPress option, returning $default when the option is not set.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = []): mixed
    {
        return get_option($key, $default);
    }

    /**
     * Writes a WordPress option.
     *
     * Returns true on success, false on failure or when the value is unchanged
     * (update_option() returns false in both cases — callers should not treat
     * false as a hard failure without checking context).
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function set(string $key, mixed $value): bool
    {
        return (bool) update_option($key, $value);
    }

    /**
     * Returns true when an option row exists in the database (value is not null).
     *
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool
    {
        return get_option($key, null) !== null;
    }

    /**
     * Returns option names matching a SQL LIKE pattern.
     *
     * Example patterns:
     * - options_%
     * - carbon_fields_container_wicket_acc_options%
     *
     * @param string $like_pattern
     * @return string[]
     */
    public function find_option_names_by_like(string $like_pattern): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
            $like_pattern
        );

        $rows = $wpdb->get_col($sql);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map('strval', $rows));
    }
}
