# Wicket Portus

Wicket Portus makes Wicket site configuration portable, reviewable, and repeatable.

This README reflects the current implementation in `src/`.

## Current Status

- Plugin version: `0.1.0`
- PHP requirement: `>= 8.3`
- Admin UI: `Wicket Settings -> Portus` (`wicket-settings` parent, `wicket-portus-data-tools` slug)

## Documentation

- [docs/end-user-guide.md](docs/end-user-guide.md) - Operator workflow for export/import.
- [docs/developer-guide.md](docs/developer-guide.md) - Architecture, extension points, and development workflow.
- [docs/add-module-playbook.md](docs/add-module-playbook.md) - Step-by-step guide to add a new Portus module with a fictional plugin example.
- [docs/manifest-reference.md](docs/manifest-reference.md) - Manifest envelope and module payload contract.

## What Portus Does Today

- Exports a JSON manifest (`type: wicket_portus_manifest`, `schema_version: 1`) with:
  - site metadata (`site.url`, `site.environment`)
  - module payloads under `modules`
  - `generated_at`, `errors`, and `export_mode`
- Supports three export modes:
  - `template` (default): applies module sanitization (`SanitizableModuleInterface`) and strips post-like numeric `id` fields
  - `full`: exports full payloads
  - `developer`: full payloads + includes developer-only modules in export selection
- Uses HyperFields Export/Import UI for file upload, diff preview, and import flow.
- Performs dry-run diffs before import.
- Queues plugin activation/deactivation from `site_inventory` after successful import, then applies changes on next `admin_init`.
- Flushes common caches after successful import.

## What Portus Does Not Do Today

- No WP-CLI command surface in this plugin.
- No built-in module result code taxonomy (`ok/warn/skip/error`) outside module result arrays.

## Core Manifest Contract

Current envelope shape produced by `TransferOrchestrator`:

- `schema_version` (int): `1`
- `type` (string): `wicket_portus_manifest`
- `generated_at` (ISO-8601 string)
- `site` (object):
  - `url`
  - `environment` (`WP_ENVIRONMENT_TYPE` or `production`)
- `modules` (object): keyed by module key
- `errors` (array)
- `export_mode` (string): `template`, `full`, or `developer`

See [Manifest Reference](docs/manifest-reference.md) for field-level details.

## Registered Modules (Current)

Core registered modules in `Plugin::register_modules()`:

1. `site_inventory` (`PluginInventoryModule`)
2. `developer_wp_options_snapshot` (`DeveloperWpOptionsSnapshotModule`) - developer-only export mode
3. `content_pages` (`PostTypeExportModule`, post type `page`)
4. `content_my_account` (`PostTypeExportModule`, post type `my-account`)
5. `curated_pages` (`CuratedPagesExportModule`)
6. `my_account_pages` (`MyAccountPagesExportModule`)
7. `wicket_settings` (`WicketSettingsModule`)
8. `memberships` (`WicketMembershipsModule`)
9. `gravity_forms_wicket_plugin` (`WicketGfOptionsModule`)
10. `account_centre` (`AccCarbonFieldsOptionsModule`)
11. `financial_fields` (`FinancialFieldsModule`)
12. `woocommerce_emails` (`WooCommerceEmailModule`)

Default disabled module keys (via `wicket_portus_disabled_modules`):

- `content_pages`
- `content_my_account`
- `my_account_pages`

## Extension Hooks

- `wicket_portus_register_modules` (action): register/override modules using `ModuleRegistry`.
- `wicket_portus_disabled_modules` (filter): disable module keys from export/import UI + processing.
- `wicket_portus_data_tools_options` (filter): mutate option groups shown in admin UI.
- `wicket_portus_data_tools_option_groups` (filter): mutate option-group to module-group mapping in admin UI.
- `wicket_portus_sensitive_fields` (filter): extend sensitive field map used by template export sanitization.
- `wicket_portus_acc_option_name_patterns` (filter): adjust Account Centre option discovery SQL `LIKE` patterns.
- `wicket_portus/export/template_strip_database_ids` (filter): toggle numeric DB `id` stripping in template exports.
- `wicket_portus/import/after` (action): post-import extension point.

## Safety Notes

- Export/import page requires `manage_options`.
- UI warns operators about sensitive data.
- Full/developer export modes are gated by confirmation checkboxes and enforced server-side.
- Template sanitization is currently implemented for `wicket_settings` via `SensitiveFieldsRegistry`.
- Deferred plugin changes are only applied for users with `activate_plugins`.

## Historical Planning Docs

The following files are retained as planning history and are not runtime source of truth:

- [docs/project-portus-hackweek-plan.md](docs/project-portus-hackweek-plan.md)
- [docs/project-portus-accelerated-execution-plan.md](docs/project-portus-accelerated-execution-plan.md)
