# Wicket Portus

Configuration portability tool for the Wicket WP Stack. Exports and imports Wicket site settings as portable, reviewable JSON manifests.

## What It Does

- Snapshots Wicket stack configuration — plugins, settings, membership rules, page mappings, and more — into a single JSON file.
- Three export modes: `template` (sanitised, safe to share), `full` (includes credentials), and `developer` (full + internal WP options).
- Dry-run diff before every import — see exactly what will change before writing anything.
- Defers plugin activation/deactivation changes to the next admin page load.
- Restricts access to users with an authorised email domain (default: `wicket.io`).

## Requirements

- WordPress 6.0+
- PHP 8.3+
- Wicket Base Plugin active
- HyperFields (bundled via Composer)

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/industrialdev/wicket-wp-portus.git
cd wicket-wp-portus
composer install
composer setup-hooks
```

Activate through WordPress Admin → Plugins. The plugin appears under **Wicket → Portus**.

## Modules

Portus ships with the following modules. Each module owns its own export payload and import behaviour.

| Module key | Covers | Enabled by default |
|---|---|---|
| `site_inventory` | Active plugin list | Yes |
| `wicket_settings` | Base plugin connection and feature settings | Yes |
| `memberships` | Membership plugin configuration | Yes |
| `account_centre` | Account Centre Carbon Fields options | Yes |
| `gravity_forms_wicket_plugin` | GF slug mappings and member field settings | Yes |
| `financial_fields` | Finance mapping and deferral configuration | Yes |
| `woocommerce_emails` | WooCommerce email settings | Yes |
| `curated_pages` | Page slug/ID mappings | Yes |
| `my_account_pages` | My Account page mappings | No |
| `content_pages` | Page post type export | No |
| `content_my_account` | My Account post type export | No |
| `developer_wp_options_snapshot` | Full WP options snapshot (developer mode only) | Yes* |
| `theme_acf_options` | Theme ACF options-page rows | Optional† |

\* Included automatically in developer exports; excluded from all other modes regardless of selection.

† `ThemeAcfOptionsModule` ships with the plugin but is not registered automatically. Register it via the `wicket_portus_register_modules` action. See [docs/engineering/developer-guide.md](docs/engineering/developer-guide.md#optional-module-themeacfoptionsmodule).

Disabled-by-default modules can be enabled via the `wicket_portus_disabled_modules` filter.

## Development

```bash
composer cs:lint      # Check code style (dry run)
composer cs:fix       # Fix code style
composer production   # Build for release (strips dev deps, optimises autoloader)
composer setup-hooks  # Install the pre-push git hook
```

Always run `composer production` before tagging a release. The pre-push hook blocks tag pushes if dev dependencies are present in `vendor/`.

For full architecture, module system, and contribution documentation see **[docs/engineering/developer-guide.md](docs/engineering/developer-guide.md)**.

## Documentation

| Audience | Document |
|---|---|
| Operators & Support | [docs/product/portus-overview.md](docs/product/portus-overview.md) |
| End Users | [docs/guides/portus-user-guide.md](docs/guides/portus-user-guide.md) |
| Developers & Agents | [docs/index.md](docs/index.md) |

## License

GPL v2 or later — Developed by [Wicket](https://wicket.io)

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
