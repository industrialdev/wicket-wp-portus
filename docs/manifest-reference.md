# Portus Manifest Reference

This file describes the manifest and module payloads as implemented in `src/` today.

## Envelope

Produced by `WicketPortus\Manifest\TransferOrchestrator::export()`.

- `schema_version`: `1`
- `type`: `wicket_portus_manifest`
- `generated_at`: ISO-8601 timestamp
- `site`:
  - `url`: `get_site_url()`
  - `environment`: `WP_ENVIRONMENT_TYPE` if defined, else `production`
- `modules`: module payload map
- `errors`: array
- `export_mode`: `template` or `full`

## Export Modes

- `template`:
  - Applies only to modules implementing `SanitizableModuleInterface`
  - Currently this is `wicket_settings`
  - Fields removed come from `SensitiveFieldsRegistry` (+ `wicket_portus_sensitive_fields` filter)
- `full`:
  - Exports module payloads without sanitization

## Module Payloads

### `site_inventory`

Export payload:

- `plugins`: array of rows:
  - `plugin` (plugin file path)
  - `name`
  - `version`
  - `active` (bool)

Import behavior:

- Read-only checks only
- Warns on missing plugin or version mismatch
- Marks each as skipped with reason: activation/deactivation deferred

### `wicket_settings`

Export payload:

- Raw value of WordPress option `wicket_settings` (array)

Import behavior:

- Validates payload is a non-empty array
- Uses HyperFields option transfer in `replace` mode
- Adds sensitive-data warning in result messages

Template mode sanitization:

- Removes configured sensitive keys for module key `wicket_settings`

### `memberships`

Export payload:

- `plugin_options`: value of option `wicket_membership_plugin_options` (array)

Import behavior:

- Requires `plugin_options` key and array value
- Uses HyperFields option transfer in `replace` mode on `wicket_membership_plugin_options`

### `gravity_forms_wicket_plugin`

Export payload:

- `wicket_gf_pagination_sidebar_layout`
- `wicket_gf_member_fields`
- `wicket_gf_slug_mapping`
  - If stored as JSON string, it is decoded to array when valid JSON

Import behavior:

- Missing keys are warnings (not hard errors)
- If `wicket_gf_slug_mapping` is array, it is JSON-encoded before write
- Uses HyperFields option transfer in `replace` mode

### `account_centre`

Export payload:

- `option_names`: discovered names matching:
  - `carbon_fields_container_wicket_acc_options%`
  - `_carbon_fields_container_wicket_acc_options%`
  - filterable via `wicket_portus_acc_option_name_patterns`
- `options`: exported values for those names

Import behavior:

- Requires `options` array
- Allowed keys come from `option_names` when provided; otherwise `options` keys
- Uses HyperFields option transfer in `replace` mode

### `theme_acf_options`

Export payload:

- `option_names`: discovered names matching:
  - `options_%`
  - `_options_%`
  - filterable via `wicket_portus_theme_acf_option_name_patterns`
- `options`: exported values for those names

Import behavior:

- Requires `options` array
- Allowed keys come from `option_names` when provided; otherwise `options` keys
- Uses HyperFields option transfer in `replace` mode

## Admin Page Wiring

`WicketPortus\Plugin` registers:

- submenu page under `wicket-settings`:
  - page title: `Portus Export / Import`
  - menu label: `Portus`
  - slug: `wicket-portus-data-tools`
- page capability: `manage_options`
- HyperFields page assets enqueued only on this page

If HyperFields is unavailable, Portus shows admin notices and does not render the data tools UI.

## Import Result Shape

Module imports return `WicketPortus\Manifest\ImportResult::to_array()`:

- `dry_run` (bool)
- `success` (bool)
- `imported` (string[])
- `skipped` (array of `{ key, reason }`)
- `warnings` (string[])
- `errors` (string[])

