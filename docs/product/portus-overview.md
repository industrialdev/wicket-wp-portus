---
title: "Portus Overview"
audience: [implementer, support]
php_class: WicketPortus\Plugin
source_files: ["src/Plugin.php", "src/Manifest/TransferOrchestrator.php"]
---

# Portus Overview

Portus is the configuration portability tool for the Wicket WP Stack. It snapshots Wicket plugin settings into a JSON manifest, which can be moved between environments and applied on the destination site.

## What It Does

- Exports settings from Wicket plugins into a structured JSON manifest
- Supports three export modes: `template` (sanitised), `full` (all values preserved), `developer` (includes WP options snapshot)
- Previews changes as a diff before importing — dry-run by default
- Defers plugin activation/deactivation changes to the next admin page load
- Domain-based access control restricts the tool to allowed email domains

## Requirements

- WordPress 6.0+
- PHP 8.3+
- `wicket-wp-base-plugin`
- HyperFields library (loaded via composer)

## Access Control

Portus is gated by email domain. Only users whose account email matches an allowed domain see the menu item.

| | |
|---|---|
| Default allowed domain | `wicket.io` |
| Additional domains | `WICKET_PORTUS_ALLOWED_DOMAINS` in `wp-config.php` |
| Menu path | **Wicket → Portus** |
| Required capability | `manage_options` |

Users without an allowed domain see no menu item. Direct URL access returns HTTP 403.

## Export Modes

| Mode | Use case | Sensitive data | Numeric IDs |
|---|---|---|---|
| `template` | Sharing config, new client setups | Removed | Stripped |
| `full` | Same client, staging → production | Preserved | Preserved |
| `developer` | Engineering diagnostics | Preserved | Preserved |

## Modules

Portus ships with modules for each Wicket plugin:

| Module key | Covers |
|---|---|
| `site_inventory` | Active plugin list |
| `wicket_settings` | Base plugin connection and feature settings |
| `memberships` | Membership plugin configuration |
| `account_centre` | Account Centre Carbon Fields options |
| `gravity_forms_wicket_plugin` | GF slug mappings and member field settings |
| `financial_fields` | Finance mapping and deferral configuration |
| `woocommerce_emails` | WooCommerce email settings |
| `curated_pages` | Page slug/ID mappings |
| `my_account_pages` | My Account page mappings *(disabled by default)* |
| `content_pages` | Page post type export *(disabled by default)* |
| `developer_wp_options_snapshot` | Full WP options *(developer mode only)* |

Disabled modules can be enabled via the `wicket_portus_disabled_modules` filter.

## Documentation Links

- [End-User Guide](../guides/portus-user-guide.md) — Export and import walkthrough for operators
- [Developer Guide](../engineering/developer-guide.md) — Architecture, module system, extension points
- [Manifest Reference](../engineering/manifest-reference.md) — Full manifest envelope and payload shapes
- [Access Control](../engineering/access-control.md) — Domain gatekeeper details
