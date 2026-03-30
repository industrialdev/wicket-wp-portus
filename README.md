# Wicket Portus

Wicket Portus makes Wicket site configuration portable, reviewable, and repeatable.

This README documents the plugin as currently implemented in code.

## Current Status

- Plugin version: `0.1.0`
- PHP requirement: `>= 8.3`
- Runtime dependency: `estebanforge/hyperfields:^1`
- Admin UI: `Wicket Settings -> Portus` (`wicket-settings` parent, `wicket-portus-data-tools` slug)

## What Portus Does Today

- Exports a JSON manifest (`type: wicket_portus_manifest`, `schema_version: 1`) with:
  - site metadata (`site.url`, `site.environment`)
  - module payloads under `modules`
  - `generated_at`, `errors`, and `export_mode`
- Supports two export modes:
  - `template` (default): strips configured sensitive fields from modules implementing `SanitizableModuleInterface`
  - `full`: keeps full payloads
- Uses HyperFields Export/Import UI for file upload, diff preview, and import flow.
- Performs dry-run diffs before import.

## What Portus Does Not Do Today

- No WP-CLI command surface in this plugin.
- No automatic plugin activation/deactivation during import (inventory module reports only).
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
- `export_mode` (string): `template` or `full`

See [Manifest Reference](docs/manifest-reference.md) for field-level details.

## Registered Modules (Current)

1. `site_inventory` (`PluginInventoryModule`)
2. `wicket_settings` (`WicketSettingsModule`)
3. `memberships` (`MembershipOptionsModule`)
4. `gravity_forms_wicket_plugin` (`WicketGfOptionsModule`)
5. `account_centre` (`AccCarbonFieldsOptionsModule`)
6. `theme_acf_options` (`ThemeAcfOptionsModule`)

## Extension Hooks

- `wicket_portus_register_modules` (action): register/override modules using `ModuleRegistry`.
- `wicket_portus_data_tools_options` (filter): mutate option groups shown in admin UI.
- `wicket_portus_sensitive_fields` (filter): extend sensitive field map used by template export sanitization.
- `wicket_portus_acc_option_name_patterns` (filter): adjust Account Centre option discovery SQL `LIKE` patterns.
- `wicket_portus_theme_acf_option_name_patterns` (filter): adjust theme ACF option discovery SQL `LIKE` patterns.

## Safety Notes

- Export/import page requires `manage_options`.
- UI warns operators about sensitive data.
- Full export mode checkbox gate is enforced client-side in the export form.
- Template sanitization is currently implemented for `wicket_settings` via `SensitiveFieldsRegistry`.

## Historical Planning Docs

The following files are retained as planning history and are not runtime source of truth:

- [docs/project-portus-hackweek-plan.md](docs/project-portus-hackweek-plan.md)
- [docs/project-portus-accelerated-execution-plan.md](docs/project-portus-accelerated-execution-plan.md)

