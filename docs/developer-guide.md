# Portus Developer Guide

This guide is for developers extending or debugging Wicket Portus.

## Scope and Runtime

- Bootstrap file: `wicket-wp-portus.php`
- Main runtime class: `WicketPortus\Plugin`
- Transfer orchestration: `WicketPortus\Manifest\TransferOrchestrator`
- Registry: `WicketPortus\Registry\ModuleRegistry`

Portus uses HyperFields Export/Import UI for admin flow and wraps module export/import logic in a manifest contract (`type: wicket_portus_manifest`, `schema_version: 1`).

## High-Level Architecture

1. `Plugin::boot()` registers modules into `ModuleRegistry`.
2. `Plugin` wires admin page + HyperFields configured callbacks:
   - exporter
   - previewer (dry-run diff)
   - importer
3. `TransferOrchestrator` bridges modules into HyperFields `Transfer\Manager`.
4. Module classes implement `ConfigModuleInterface` and own their payload contract.

## Export Modes

Portus supports three export modes:

- `template`
  - default mode
  - applies `SanitizableModuleInterface::sanitize()` for supported modules
  - strips post-like numeric `id` fields when filter allows it
- `full`
  - exports full payloads
- `developer`
  - full payloads
  - forces export of all registered module keys (including developer-only modules)

Server-side gate enforcement requires confirmation checkboxes for `full` and `developer` modes. If missing, mode falls back to `template`.

## Module Registration

Core module registration lives in `Plugin::register_modules()`.

Current keys:

- `site_inventory`
- `developer_wp_options_snapshot`
- `content_pages`
- `content_my_account`
- `curated_pages`
- `my_account_pages`
- `wicket_settings`
- `memberships`
- `gravity_forms_wicket_plugin`
- `account_centre`
- `financial_fields`
- `woocommerce_emails`

Default disabled keys (filterable):

- `content_pages`
- `content_my_account`
- `my_account_pages`

## Post-Import Side Effects

After successful import, `Plugin::on_import_after()` runs:

1. Queue plugin activation/deactivation changes from `site_inventory`.
2. Flush caches (object cache + common caching plugins when available).

Deferred plugin changes are applied on next `admin_init` in `maybe_apply_deferred_plugin_changes()` and require `activate_plugins` capability.

## Extension Points

### Actions

- `wicket_portus_register_modules`
  - Register or override modules in `ModuleRegistry`.
- `wicket_portus/import/after`
  - Hook additional post-import side effects.

### Filters

- `wicket_portus_disabled_modules`
- `wicket_portus_data_tools_options`
- `wicket_portus_data_tools_option_groups`
- `wicket_portus_sensitive_fields`
- `wicket_portus_acc_option_name_patterns`
- `wicket_portus_theme_acf_option_name_patterns`
- `wicket_portus/export/template_strip_database_ids`

## Adding a New Module

1. Create module class in `src/Modules/` implementing `ConfigModuleInterface`.
2. Define a stable module key (`key()`).
3. Implement `export()`, `validate()`, and `import()` returning `ImportResult`.
4. If sensitive values exist, implement `SanitizableModuleInterface`.
5. Register module in `Plugin::register_modules()` or via `wicket_portus_register_modules` action.
6. If module should appear in export UI option groups, implement `OptionGroupProviderInterface`.

## Debugging Notes

- Both `Plugin` and `TransferOrchestrator` log through `Wicket()->log()` when available.
- Invalid or unsupported import manifests are rejected in preview/import callbacks.
- Manifest diffs normalize away volatile keys before comparison:
  - `generated_at`
  - `errors`
  - `export_mode`

## Testing Checklist (Manual)

1. Open `Wicket Settings -> Portus` as an admin with `manage_options`.
2. Export in `template` mode and verify sensitive field sanitization.
3. Export in `full` and `developer` modes and verify server-side confirmation gates.
4. Upload manifest and run preview to confirm dry-run diff output.
5. Import and verify post-import notice includes module count and queued plugin changes.
6. Trigger next admin request and verify deferred plugin sync applies.
