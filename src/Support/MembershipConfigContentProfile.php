<?php

declare(strict_types=1);

namespace WicketPortus\Support;

/**
 * Content normalization profile for legacy wicket_mship_config manifest rows.
 *
 * This profile converts older tuple-like meta payloads into canonical object
 * shapes expected by the memberships admin UI.
 */
final class MembershipConfigContentProfile
{
    private const PROFILE = 'wicket_memberships_config_v1';
    private const POST_TYPE = 'wicket_mship_config';

    /**
     * Registers HyperFields row-normalization profile hook.
     *
     * @return void
     */
    public static function register(): void
    {
        add_filter(
            'hyperfields/content_import/normalize_row/profile_' . self::PROFILE,
            [self::class, 'normalize_row'],
            10,
            4
        );
    }

    /**
     * @param mixed $row
     * @param string $postType
     * @param string $slug
     * @param array<string, mixed> $options
     * @return mixed
     */
    public static function normalize_row(mixed $row, string $postType, string $slug, array $options): mixed
    {
        unset($slug, $options);

        if (!is_array($row) || $postType !== self::POST_TYPE) {
            return $row;
        }

        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $row['meta'] = self::normalize_meta($meta);

        return $row;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function normalize_meta(array $meta): array
    {
        if (array_key_exists('cycle_data', $meta)) {
            $meta['cycle_data'] = self::normalize_cycle_data($meta['cycle_data']);
        }

        if (array_key_exists('late_fee_window_data', $meta)) {
            $meta['late_fee_window_data'] = self::normalize_late_fee_window_data($meta['late_fee_window_data']);
        }

        if (array_key_exists('renewal_window_data', $meta)) {
            $meta['renewal_window_data'] = self::normalize_renewal_window_data($meta['renewal_window_data']);
        }

        return $meta;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_cycle_data(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists('cycle_type', $value)) {
            return $value;
        }

        if (!isset($value[0]) && !isset($value[1]) && !isset($value[2])) {
            return $value;
        }

        $anniversary = isset($value[1]) && is_array($value[1]) ? $value[1] : [];
        $calendarItems = isset($value[2]) && is_array($value[2]) ? $value[2] : [];

        return [
            'cycle_type' => isset($value[0]) ? (string) $value[0] : 'calendar',
            'anniversary_data' => $anniversary,
            'calendar_items' => $calendarItems,
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_late_fee_window_data(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists('days_count', $value)) {
            return $value;
        }

        if (!isset($value[0]) && !isset($value[1]) && !isset($value[2])) {
            return $value;
        }

        $locales = isset($value[2]) && is_array($value[2]) ? $value[2] : [];

        return [
            'days_count' => isset($value[0]) ? (int) $value[0] : 0,
            'product_id' => isset($value[1]) ? (int) $value[1] : -1,
            'locales' => $locales,
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_renewal_window_data(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists('days_count', $value)) {
            return $value;
        }

        if (!isset($value[0]) && !isset($value[1])) {
            return $value;
        }

        $locales = isset($value[1]) && is_array($value[1]) ? $value[1] : [];

        return [
            'days_count' => isset($value[0]) ? (int) $value[0] : 0,
            'locales' => $locales,
        ];
    }
}
