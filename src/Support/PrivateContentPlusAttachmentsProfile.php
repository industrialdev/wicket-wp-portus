<?php

declare(strict_types=1);

namespace WicketPortus\Support;

/**
 * Normalizes WP Private Content Plus attachment meta during content imports.
 *
 * WPPCP expects `_wppcp_post_attachments` to be a single meta row containing
 * a list of attachment objects. Generic content imports can otherwise treat
 * list payloads as multi-value meta and split rows, which breaks WPPCP reads.
 */
final class PrivateContentPlusAttachmentsProfile
{
    private const PROFILE = 'wppcp_post_attachments_v1';
    private const META_KEY_ATTACHMENTS = '_wppcp_post_attachments';

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
     * Returns the profile key consumed by ContentTransferAdapter import options.
     *
     * @return string
     */
    public static function profile_key(): string
    {
        return self::PROFILE;
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
        unset($postType, $slug, $options);

        if (!is_array($row)) {
            return $row;
        }

        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        if (!array_key_exists(self::META_KEY_ATTACHMENTS, $meta)) {
            return $row;
        }

        $attachments = self::extract_attachments_list($meta[self::META_KEY_ATTACHMENTS]);
        if ($attachments === null) {
            return $row;
        }

        // One meta row, value = list of attachment objects.
        $meta[self::META_KEY_ATTACHMENTS] = [$attachments];
        $row['meta'] = $meta;

        return $row;
    }

    /**
     * Converts input into canonical attachment list or null when unknown shape.
     *
     * @param mixed $value
     * @return array<int, array<string, mixed>>|null
     */
    private static function extract_attachments_list(mixed $value): ?array
    {
        $value = self::decode_scalar_payload($value);
        if (!is_array($value)) {
            return null;
        }

        if (self::is_attachment_list($value)) {
            return $value;
        }

        if (self::is_wrapped_attachment_list($value)) {
            /** @var array<int, array<string, mixed>> $first */
            $first = $value[0];

            return $first;
        }

        if (self::is_attachment_row($value)) {
            return [$value];
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function decode_scalar_payload(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = maybe_unserialize($value);
        if ($decoded !== $value) {
            return $decoded;
        }

        if ($value === '') {
            return $value;
        }

        $json = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $json : $value;
    }

    /**
     * @param array<int, mixed> $value
     * @return bool
     */
    private static function is_wrapped_attachment_list(array $value): bool
    {
        if (!array_is_list($value) || count($value) !== 1 || !isset($value[0]) || !is_array($value[0])) {
            return false;
        }

        return self::is_attachment_list($value[0]);
    }

    /**
     * @param array<int, mixed> $value
     * @return bool
     */
    private static function is_attachment_list(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }

        foreach ($value as $row) {
            if (!is_array($row) || !self::is_attachment_row($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $value
     * @return bool
     */
    private static function is_attachment_row(array $value): bool
    {
        return array_key_exists('attach_id', $value);
    }
}
