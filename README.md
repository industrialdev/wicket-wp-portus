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

## Documentation

See the [`docs/`](docs/) folder for full documentation.

- [docs/index.md](docs/index.md) — Documentation index
- [docs/end-user-guide.md](docs/end-user-guide.md) — Operator workflow for export and import
- [docs/developer-guide.md](docs/developer-guide.md) — Architecture, modules, extension points, and development setup
- [docs/add-module-playbook.md](docs/add-module-playbook.md) — How to add a new module
- [docs/manifest-reference.md](docs/manifest-reference.md) — Manifest schema and module payload reference
- [docs/access-control.md](docs/access-control.md) — Domain-based access gate and configuration

## License

GPL v2 or later — Developed by [Wicket](https://wicket.io)

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
