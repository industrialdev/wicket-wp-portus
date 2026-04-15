---
title: "Portus Access Control"
audience: [developer, implementer]
php_class: WicketPortus\Access\DomainGatekeeper
source_files: ["src/Access/DomainGatekeeper.php"]
---

# Portus Access Control

Portus enforces domain-based access on top of WordPress capabilities. This document explains how the gate works and how to configure it.

---

## How It Works

Access is checked by `WicketPortus\Access\DomainGatekeeper` at two points:

1. **Menu registration** — `admin_menu` hook. If the current user fails the check, the Portus submenu is never added. The page does not appear in the Wicket menu.
2. **Page render** — `render_portus_data_tools_page()`. If a user navigates directly to the admin URL without going through the menu, the domain check runs again and calls `wp_die()` with HTTP 403. There is no way to bypass the gate by crafting a URL.

Both checks happen before any capability check (`manage_options`), so the domain gate is the outermost layer.

The gate evaluates two independent conditions. **Both must be true** for access to be granted:

1. The current user's email domain is in the allowed list.
2. The current session is not an impersonated User Switching session.

---

## Default Allowed Domain

`wicket.io` is always permitted. This is hardcoded and cannot be removed without modifying the plugin source.

---

## Adding Allowed Domains

Third-party teams who need access to Portus on their own environments can add their email domain(s) via `wp-config.php`:

```php
define('WICKET_PORTUS_ALLOWED_DOMAINS', 'example.com');
```

Multiple domains, comma-separated:

```php
define('WICKET_PORTUS_ALLOWED_DOMAINS', 'example.com,partner.org,agency.io');
```

Rules:
- Domains are case-insensitive (`Example.COM` and `example.com` are treated identically).
- Leading/trailing whitespace around commas is stripped.
- `wicket.io` is always included in the allowed list regardless of this constant — defining the constant does not remove it.
- Duplicates are silently deduplicated.

---

## Impersonation Protection

When the [User Switching](https://wordpress.org/plugins/user-switching/) plugin is active and an administrator has switched into another user's account, that session is always denied access to Portus — even if the switched-to user has an otherwise permitted email domain.

This prevents privilege escalation via impersonation: an admin cannot use User Switching to gain Portus access on behalf of a non-permitted user, nor can a non-permitted user trick an admin into switching to an account that would pass the domain check.

**Detection strategy** (in order of preference):

1. `user_switching::get_old_user()` — the plugin's own public API. Returns a `WP_User` when a switch is in progress.
2. `$_COOKIE[USER_SWITCHING_OLDUSER_COOKIE]` — falls back to the cookie check directly when the plugin class is unavailable (e.g. plugin deactivated mid-session while cookie persists).
3. Derives `wordpress_user_sw_olduser_{COOKIEHASH}` independently when the constant is not defined.

If none of these signals are present, the session is assumed genuine.

## Behaviour for Unauthorised Users

| Scenario | Result |
|----------|--------|
| User email domain is not in the allowed list | Portus menu item never appears |
| User navigates directly to the Portus admin URL | WordPress `wp_die()` — HTTP 403, "Access Denied — Wicket Portus" error screen with a "Return to Dashboard" link |
| User is not logged in | Redirected to the WordPress login page by WordPress core (before Portus checks anything) |
| Active User Switching impersonation session | Same as domain-denied — menu hidden, direct URL returns HTTP 403 |

---

## Implementation Reference

```
src/Access/DomainGatekeeper.php

DomainGatekeeper::current_user_is_allowed(): bool
  — Returns false immediately if is_switched_session() is true
  — Reads wp_get_current_user()->user_email
  — Extracts the domain after the last '@'
  — Checks against DomainGatekeeper::allowed_domains()

DomainGatekeeper::is_switched_session(): bool
  — Returns true if user_switching::get_old_user() returns a WP_User
  — Falls back to $_COOKIE[USER_SWITCHING_OLDUSER_COOKIE] check
  — Falls back to derived cookie name if constant is absent

DomainGatekeeper::allowed_domains(): string[]
  — Always includes 'wicket.io'
  — Merges WICKET_PORTUS_ALLOWED_DOMAINS if defined

DomainGatekeeper::deny(): never
  — Calls wp_die() with HTTP 403
  — Never returns
```

---

## Security Notes

- The domain check is enforced server-side in two independent locations. There is no client-side bypass.
- Impersonation detection runs before the domain check — a switched session is always denied, regardless of the email on the target account.
- Changing `WICKET_PORTUS_ALLOWED_DOMAINS` takes effect immediately on the next request — no cache flush needed.
- This gate does not replace WordPress capability checks. A user must both pass the domain gate **and** have `manage_options` to use Portus.
- Do not add overly broad domains (e.g. `gmail.com`). The intent is to restrict access to known trusted organisations.
