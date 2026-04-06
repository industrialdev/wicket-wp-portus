# Portus Access Control

Portus enforces domain-based access on top of WordPress capabilities. This document explains how the gate works and how to configure it.

---

## How It Works

Access is checked by `WicketPortus\Access\DomainGatekeeper` at two points:

1. **Menu registration** — `admin_menu` hook. If the current user fails the check, the Portus submenu is never added. The page does not appear in the Wicket menu.
2. **Page render** — `render_portus_data_tools_page()`. If a user navigates directly to the admin URL without going through the menu, the domain check runs again and calls `wp_die()` with HTTP 403. There is no way to bypass the gate by crafting a URL.

Both checks happen before any capability check (`manage_options`), so the domain gate is the outermost layer.

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

## Behaviour for Unauthorised Users

| Scenario | Result |
|----------|--------|
| User email domain is not in the allowed list | Portus menu item never appears |
| User navigates directly to the Portus admin URL | WordPress `wp_die()` — HTTP 403, "Access Denied — Wicket Portus" error screen with a "Return to Dashboard" link |
| User is not logged in | Redirected to the WordPress login page by WordPress core (before Portus checks anything) |

---

## Implementation Reference

```
src/Access/DomainGatekeeper.php

DomainGatekeeper::current_user_is_allowed(): bool
  — Reads wp_get_current_user()->user_email
  — Extracts the domain after the last '@'
  — Checks against DomainGatekeeper::allowed_domains()

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
- Changing `WICKET_PORTUS_ALLOWED_DOMAINS` takes effect immediately on the next request — no cache flush needed.
- This gate does not replace WordPress capability checks. A user must both pass the domain gate **and** have `manage_options` to use Portus.
- Do not add overly broad domains (e.g. `gmail.com`). The intent is to restrict access to known trusted organisations.
