# Wicket Portus

Configuration portability tool for the Wicket WP Stack. Exports and imports Wicket site settings as portable, reviewable JSON manifests.

## Overview

Portus makes it possible to snapshot an entire Wicket stack configuration — plugins, settings, membership rules, page mappings, and more — and replicate it across environments. Manifests are human-readable JSON files that can be reviewed in version control, diffed before applying, and imported in a single operation.

## Features

- **Portable manifests**: JSON snapshots of Wicket stack settings, versioned and environment-tagged
- **Three export modes**: `template` (safe, anonymised), `full` (complete values), and `developer` (full + internal WP options)
- **Dry-run diff**: Preview exactly what will change before committing an import
- **Module system**: Export only the modules you need; disable others via filter
- **Sensitive-data protection**: Confirmation gates and server-side enforcement on full/developer exports
- **Deferred plugin changes**: Plugin activation and deactivation queued from manifest, applied safely on next admin load
- **Access gating**: Only users with an authorised email domain can access Portus

## Requirements

- **WordPress**: 6.0+
- **PHP**: 8.3+
- **Wicket Base Plugin**: active
- **HyperFields**: bundled via Composer

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/industrialdev/wicket-wp-portus.git
cd wicket-wp-portus
composer install
```

Then activate through WordPress Admin → Plugins.

## Access Control

Portus is restricted to users whose WordPress account email belongs to an allowed domain. By default, only `wicket.io` addresses are permitted.

To grant access to additional domains, add the following to `wp-config.php`:

```php
define('WICKET_PORTUS_ALLOWED_DOMAINS', 'example.com,partner.org');
```

`wicket.io` is always allowed, regardless of this constant.

## Usage

Navigate to **Wicket → Portus** in the WordPress admin.

### Exporting

1. Select the modules to include
2. Choose an export mode:
   - **Template** (default): strips sensitive values and numeric database IDs — safe to commit
   - **Full**: exports all values including secrets — handle with care
   - **Developer**: full export plus internal WP options snapshot — for debugging only
3. Click **Export** to download the JSON manifest

### Importing

1. Upload a previously exported manifest
2. Review the diff preview — changed values are shown before anything is written
3. Confirm and apply

Plugin inventory changes (activations/deactivations) are queued and applied on the next page load.

## Modules

| Key | Class | Description |
|-----|-------|-------------|
| `site_inventory` | `PluginInventoryModule` | Active plugin list |
| `wicket_settings` | `WicketSettingsModule` | Core Wicket settings |
| `memberships` | `WicketMembershipsModule` | Membership configuration |
| `account_centre` | `AccCarbonFieldsOptionsModule` | Account Centre options |
| `gravity_forms_wicket_plugin` | `WicketGfOptionsModule` | Gravity Forms / Wicket settings |
| `financial_fields` | `FinancialFieldsModule` | Financial fields configuration |
| `woocommerce_emails` | `WooCommerceEmailModule` | WooCommerce email settings |
| `curated_pages` | `CuratedPagesExportModule` | Curated page mappings |
| `my_account_pages` | `MyAccountPagesExportModule` | My Account page mappings *(disabled by default)* |
| `content_pages` | `PostTypeExportModule` | Page post type export *(disabled by default)* |
| `content_my_account` | `PostTypeExportModule` | My Account post type export *(disabled by default)* |
| `developer_wp_options_snapshot` | `DeveloperWpOptionsSnapshotModule` | Full WP options dump *(developer mode only)* |

## Hooks & Filters

```php
// Register additional modules
add_action('wicket_portus_register_modules', function (ModuleRegistry $registry) {
    $registry->register('my_module', new MyModule());
});

// Disable specific modules
add_filter('wicket_portus_disabled_modules', function (array $keys): array {
    $keys[] = 'financial_fields';
    return $keys;
});

// Extend sensitive field masking (template export)
add_filter('wicket_portus_sensitive_fields', function (array $fields): array {
    $fields['my_option_key'] = ['secret_field'];
    return $fields;
});

// Toggle database ID stripping in template exports
add_filter('wicket_portus/export/template_strip_database_ids', '__return_false');

// Post-import hook
add_action('wicket_portus/import/after', function (ImportResult $result, string $mode): void {
    // Custom logic after a successful import
}, 10, 2);
```

## Manifest Format

```json
{
  "schema_version": 1,
  "type": "wicket_portus_manifest",
  "generated_at": "2025-01-01T00:00:00+00:00",
  "export_mode": "template",
  "site": {
    "url": "https://example.com",
    "environment": "staging"
  },
  "modules": { },
  "errors": []
}
```

See [docs/manifest-reference.md](docs/manifest-reference.md) for the full field-level contract.

## Documentation

- [docs/end-user-guide.md](docs/end-user-guide.md) — Operator workflow for export and import
- [docs/developer-guide.md](docs/developer-guide.md) — Architecture, extension points, and development workflow
- [docs/add-module-playbook.md](docs/add-module-playbook.md) — Step-by-step guide to adding a new module
- [docs/manifest-reference.md](docs/manifest-reference.md) — Manifest schema reference

## Development

```bash
# Install dependencies
composer install

# Install git hooks
composer setup-hooks

# Check code style
composer cs:lint

# Fix code style
composer cs:fix
```

### ⚠️ Before Tagging a New Version

Always run `composer production` before tagging. This removes dev dependencies and optimises the autoloader:

```bash
composer production
```

The pre-push hook will block tag pushes if dev dependencies are still present.

## License

GPL v2 or later

## Credits

Developed by [Wicket](https://wicket.io)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.
