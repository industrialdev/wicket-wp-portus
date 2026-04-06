<?php

declare(strict_types=1);

namespace WicketPortus\Access;

/**
 * Restricts Portus access to users whose WP account email belongs
 * to an allowed domain.
 *
 * The default allowed domain is wicket.io. Third-party operators can
 * extend (not replace) that list by defining WICKET_PORTUS_ALLOWED_DOMAINS
 * in wp-config.php as a comma-separated string of additional domains:
 *
 *   define('WICKET_PORTUS_ALLOWED_DOMAINS', 'example.com,partner.org');
 *
 * wicket.io is always implicitly allowed regardless of that constant.
 */
class DomainGatekeeper
{
    private const DEFAULT_DOMAIN = 'wicket.io';

    /**
     * Returns true when the current user's email domain is in the allowed list.
     */
    public static function current_user_is_allowed(): bool
    {
        $user = wp_get_current_user();

        if (!$user || !$user->exists()) {
            return false;
        }

        $email = (string) $user->user_email;
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));

        return in_array($domain, self::allowed_domains(), true);
    }

    /**
     * Renders a full-page access-denied error and halts execution.
     *
     * Uses wp_die() so it respects WP's error-page infrastructure
     * (custom error templates, test harnesses, etc.).
     */
    public static function deny(): never
    {
        wp_die(
            sprintf(
                '<p>%s</p><p><a href="%s">%s</a></p>',
                esc_html__('You do not have permission to access Portus. Only users with an authorised email domain may use this tool.', 'wicket-portus'),
                esc_url(admin_url()),
                esc_html__('Return to Dashboard', 'wicket-portus')
            ),
            esc_html__('Access Denied — Wicket Portus', 'wicket-portus'),
            ['response' => 403, 'back_link' => false]
        );
    }

    /**
     * Returns the merged list of allowed domains.
     *
     * wicket.io is always present. If WICKET_PORTUS_ALLOWED_DOMAINS is
     * defined, its entries are added after deduplication.
     *
     * @return string[]
     */
    public static function allowed_domains(): array
    {
        $domains = [self::DEFAULT_DOMAIN];

        if (defined('WICKET_PORTUS_ALLOWED_DOMAINS') && is_string(WICKET_PORTUS_ALLOWED_DOMAINS)) {
            $extra = array_filter(
                array_map(
                    fn (string $d): string => strtolower(trim($d)),
                    explode(',', WICKET_PORTUS_ALLOWED_DOMAINS)
                )
            );
            $domains = array_values(array_unique(array_merge($domains, $extra)));
        }

        return $domains;
    }
}
