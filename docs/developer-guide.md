# Portus Developer Guide

Everything a developer needs to understand, extend, debug, and contribute to Wicket Portus.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Directory Structure](#directory-structure)
3. [Bootstrap Flow](#bootstrap-flow)
4. [Module System](#module-system)
5. [Export Pipeline](#export-pipeline)
6. [Import Pipeline](#import-pipeline)
7. [Sensitive Fields & Template Mode](#sensitive-fields--template-mode)
8. [Access Control](#access-control)
9. [Extension Points](#extension-points)
10. [Adding a New Module](#adding-a-new-module)
11. [Development Setup](#development-setup)
12. [Debugging](#debugging)
13. [Testing Checklist](#testing-checklist)

---

## Architecture Overview

Portus wraps a module registry + transfer orchestrator pattern around the HyperFields Export/Import library. Each registered module owns its own export payload, validation rules, and import behaviour. The admin UI is rendered by HyperFields; Portus drives it in manual mode so it can prepend warnings and control messaging.

```
wicket-wp-portus.php          Bootstrap + autoload
└── Wicket_Portus_Bootstrap
    └── WicketPortus\Plugin   Singleton. Registers modules, wires admin hooks.
        ├── ModuleRegistry    Holds ConfigModuleInterface instances, keyed by string.
        ├── TransferOrchestrator
        │   └── HyperFields\Transfer\Manager  Low-level export/diff/import runner.
        └── Admin UI (HyperFields ExportImportUI)
```

**Key principle:** modules are self-contained units. Each module knows how to export its own data, validate an incoming payload, and import it — with or without a dry run. The orchestrator just wires them together.

---

## Directory Structure

```
wicket-wp-portus/
├── wicket-wp-portus.php              # Plugin header + bootstrap class
├── composer.json
├── src/
│   ├── Plugin.php                    # Main runtime class
│   ├── Access/
│   │   └── DomainGatekeeper.php      # Email-domain access enforcement
│   ├── Contracts/
│   │   ├── ConfigModuleInterface.php # Required: key(), export(), validate(), import()
│   │   ├── SanitizableModuleInterface.php  # Optional: sanitize() for template mode
│   │   └── OptionGroupProviderInterface.php # Optional: option_groups() for UI labels
│   ├── Manifest/
│   │   ├── TransferOrchestrator.php  # export(), diff(), import() → HyperFields Manager
│   │   └── ImportResult.php          # Value object returned by module import()
│   ├── Modules/                      # One file per module
│   │   ├── PluginInventoryModule.php
│   │   ├── WicketSettingsModule.php
│   │   ├── WicketMembershipsModule.php
│   │   ├── AccCarbonFieldsOptionsModule.php
│   │   ├── WicketGfOptionsModule.php
│   │   ├── FinancialFieldsModule.php
│   │   ├── WooCommerceEmailModule.php
│   │   ├── CuratedPagesExportModule.php
│   │   ├── MyAccountPagesExportModule.php
│   │   ├── PostTypeExportModule.php
│   │   ├── ThemeAcfOptionsModule.php
│   │   └── DeveloperWpOptionsSnapshotModule.php
│   ├── Registry/
│   │   ├── ModuleRegistry.php        # Register, disable, look up modules
│   │   └── SensitiveFieldsRegistry.php # Built-in + filterable sensitive field map
│   └── Support/
│       ├── HyperfieldsOptionTransfer.php  # Wraps HyperFields option read/write
│       ├── WordPressOptionReader.php      # Thin wrapper around get_option()
│       ├── WarningPrinter.php             # Produces admin-notice HTML strings
│       ├── MembershipConfigContentProfile.php
│       └── PrivateContentPlusAttachmentsProfile.php
├── docs/
├── assets/
└── vendor/
```

---

## Bootstrap Flow

1. `wicket-wp-portus.php` fires on `plugins_loaded` (priority 99).
2. `Wicket_Portus_Bootstrap::plugin_setup()` loads the autoloader and initialises HyperFields.
3. `WicketPortus\Plugin::get_instance()` is called, which runs `Plugin::boot()`.
4. `boot()`:
   - Instantiates `ModuleRegistry`.
   - Calls `register_modules()` — all core modules are added here.
   - Applies `wicket_portus_disabled_modules` filter.
   - Instantiates `TransferOrchestrator`.
   - Wires `admin_menu`, `admin_enqueue_scripts`, `admin_init`, and import hooks.
   - Calls `do_action('wicket_portus_register_modules', $registry)` — extension point.
5. On `admin_menu` (if domain check passes), registers the submenu under `wicket-settings`.
6. On `admin_init`, runs `maybe_apply_deferred_plugin_changes()`.

---

## Module System

### ConfigModuleInterface (required)

Every module must implement:

```php
public function key(): string;
// Stable, unique, snake_case string. Never reuse for a different payload shape.

public function export(): array;
// Read current environment state and return a serialisable array.

public function validate(array $payload): array;
// Return string[] of errors. Empty = valid. Called before import().

public function import(array $payload, array $options = []): ImportResult;
// Write $payload to this environment. Respect $options['dry_run'] (bool, default true).
```

### SanitizableModuleInterface (optional)

Implement when your module has sensitive fields that should be removed in template mode:

```php
public function sanitize(array $payload): array;
// Return a copy of $payload with sensitive values removed. Do NOT mutate $payload.
```

### OptionGroupProviderInterface (optional)

Implement when you want the module to appear as a selectable item in the export UI:

```php
public function option_groups(): array;
// Return ['wp_option_name' => 'Human Label'] pairs.
```

### ModuleRegistry

```php
$registry->register(ConfigModuleInterface $module);   // Adds or replaces by key()
$registry->disable(string $key);                       // Excludes from export iteration
$registry->all(bool $include_disabled = false): array; // All enabled (or all) modules
$registry->get(string $key): ?ConfigModuleInterface;   // Fetch by key
$registry->has(string $key): bool;
$registry->is_disabled(string $key): bool;
$registry->disabled_keys(): string[];
```

Registering a module with a key that already exists **silently replaces** the previous one. This is intentional — it lets third parties override core modules.

### ImportResult

Modules return this from `import()`. Build it fluently:

```php
$result = ImportResult::dry_run();   // or ImportResult::commit()

$result->add_imported('option_key');           // Key was (or would be) written
$result->add_skipped('option_key', 'reason');  // Key intentionally skipped
$result->add_warning('Non-fatal message');      // Operator notice
$result->add_error('Fatal message');            // Prevents success

$result->is_successful();  // true if no errors
$result->to_array();       // Serialise for admin display or logs
```

---

## Export Pipeline

1. `Plugin::render_portus_data_tools_page()` reads POST data and resolves `$export_mode`.
2. Server-side: confirmation checkboxes are validated for `full`/`developer` modes. Missing confirmation falls back to `template`.
3. `TransferOrchestrator::export(string[] $module_keys, string $mode)` is called.
4. For each module key: `$module->export()` is called via a HyperFields Manager exporter closure.
5. In `template` mode:
   - If module implements `SanitizableModuleInterface`, `sanitize()` is applied to the payload.
   - If `wicket_portus/export/template_strip_database_ids` returns true (default), numeric `id` fields are stripped from post-like records.
6. The final manifest envelope is assembled:
   ```json
   {
     "schema_version": 1,
     "type": "wicket_portus_manifest",
     "generated_at": "<ISO-8601>",
     "export_mode": "template|full|developer",
     "site": { "url": "...", "environment": "..." },
     "modules": { "<key>": { ... }, ... },
     "errors": []
   }
   ```
7. Manifest is JSON-encoded and returned as a downloadable file.

---

## Import Pipeline

1. User uploads a manifest and clicks Preview (dry run) or Import.
2. `TransferOrchestrator::diff()` or `::import()` is called.
3. The manifest `modules` map is sliced into per-module payloads, each wrapped in `{ "payload": { ... } }` for HyperFields.
4. For each module: HyperFields Manager calls the importer closure, which calls `$module->import($payload, ['dry_run' => $dry_run])`.
5. Each module runs `validate()` internally and returns an `ImportResult`.
6. Results are aggregated; the admin UI renders the summary.
7. On a real import (not dry run), `Plugin::on_import_after()` fires `wicket_portus/import/after` and:
   - Reads `site_inventory` result to queue plugin activation/deactivation changes.
   - Stores changes in transient `wicket_portus_deferred_plugin_changes`.
   - Flushes object cache and common page-cache plugins.
8. On next `admin_init`, `maybe_apply_deferred_plugin_changes()` reads the transient and applies it (requires `activate_plugins`).

**Manifest keys excluded from diff comparison** (volatile, not meaningful for change detection):
- `generated_at`
- `errors`
- `export_mode`

---

## Sensitive Fields & Template Mode

`SensitiveFieldsRegistry::DEFAULTS` contains two layers:

- **`global`** — field name patterns applied to every module (e.g. `api_key`, `secret`, `password`, `license_key`).
- **Per-module keys** — exact field names for specific modules (e.g. `wicket_admin_settings_prod_secret_key` under `wicket_settings`).

A module's `sanitize()` method is expected to call `SensitiveFieldsRegistry::for_module($this->key())` and unset matching keys from its payload.

**Extend via filter:**

```php
add_filter('wicket_portus_sensitive_fields', function (array $fields): array {
    // Add a field to the global list (applies to all modules)
    $fields['global'][] = 'my_custom_secret';

    // Add fields for a specific module
    $fields['my_module_key'][] = 'internal_token';

    return $fields;
});
```

---

## Access Control

The admin menu and page render are both guarded by `DomainGatekeeper`. See [access-control.md](access-control.md) for full documentation.

Summary:
- `wicket.io` is always allowed.
- Additional domains can be added via `WICKET_PORTUS_ALLOWED_DOMAINS` in `wp-config.php`.
- Menu is hidden and direct URL access returns HTTP 403 for unauthorised users.

---

## Extension Points

### Actions

**`wicket_portus_register_modules`** — fired at end of `boot()`, after all core modules are registered.

```php
add_action('wicket_portus_register_modules', function (WicketPortus\Registry\ModuleRegistry $registry): void {
    $registry->register(new MyCustomModule());
    // Or replace a core module:
    $registry->register(new MyWicketSettingsOverride()); // same key() as core
});
```

**`wicket_portus/import/after`** — fired after a successful real import.

```php
add_action('wicket_portus/import/after', function (array $result, string $mode): void {
    // $result: aggregated import result array
    // $mode: 'template', 'full', or 'developer'
}, 10, 2);
```

### Filters

**`wicket_portus_disabled_modules`** — control which modules are excluded from export/import.

```php
add_filter('wicket_portus_disabled_modules', function (array $keys): array {
    $keys[] = 'financial_fields'; // Disable a core module
    return $keys;
});
```

Default disabled: `content_pages`, `content_my_account`, `my_account_pages`.

**`wicket_portus_data_tools_options`** — mutate the option groups shown in the admin UI.

**`wicket_portus_data_tools_option_groups`** — mutate the option-group → module-group mapping.

**`wicket_portus_sensitive_fields`** — extend the sensitive field map (see above).

**`wicket_portus_acc_option_name_patterns`** — adjust the SQL `LIKE` patterns used to discover Account Centre option names.

**`wicket_portus_theme_acf_option_name_patterns`** — adjust SQL `LIKE` patterns for Theme ACF options discovery.

**`wicket_portus/export/template_strip_database_ids`** — toggle numeric DB `id` stripping in template exports.

```php
// Disable ID stripping (e.g. when you need IDs preserved for reference)
add_filter('wicket_portus/export/template_strip_database_ids', '__return_false');
```

**`hyperfields/export/filename_prefix`** — controls the downloaded filename prefix (set to `wicket-portus-export` in the plugin bootstrap).

---

## Adding a New Module

See [add-module-playbook.md](add-module-playbook.md) for a complete worked example with copy-paste code.

**Checklist summary:**

- [ ] Module key is unique, stable, and snake_case.
- [ ] `validate()` rejects malformed payloads with clear, user-readable messages.
- [ ] `import()` reads and honours `$options['dry_run']` (default `true`).
- [ ] Implement `SanitizableModuleInterface` if the module contains secrets.
- [ ] Implement `OptionGroupProviderInterface` if the module should appear in the UI.
- [ ] Option allow-list in `import()` is strict — never write keys that weren't in the payload.
- [ ] Register via `wicket_portus_register_modules` action (avoid editing Plugin.php core directly).
- [ ] Update `docs/manifest-reference.md` with the new payload shape.
- [ ] Update the module table in `README.md`.

---

## Development Setup

```bash
# Install all dependencies (including dev)
composer install

# Install the pre-push git hook (blocks tagging with dev deps present)
composer setup-hooks

# Check code style (dry run)
composer cs:lint

# Fix code style
composer cs:fix

# Build for production (removes dev deps, optimises autoloader)
composer production
```

PHP CS Fixer is configured in `.php-cs-fixer.dist.php`. Rules: `@PSR12`, `@PER-CS`, `@PHP82Migration`.

### Production release

Always run `composer production` before tagging a release. The pre-push hook will block tag pushes if `vendor/phpunit`, `vendor/brain`, `vendor/mockery`, or `vendor/bin/phpunit` are present.

---

## Debugging

Portus logs through the Wicket base-plugin logger (`Wicket()->log()`). Log entries carry:

- `source`: `wicket-portus`
- `component`: `transfer-orchestrator`
- Operation-specific context: `operation`, `module_key`, `dry_run`, `duration_ms`, `error_count`, etc.

Log levels used:
- `debug` — per-module start/complete events during normal operation.
- `info` — boot complete, export/import started and completed without errors.
- `warning` — export/import completed with errors.
- `error` — module-level exception caught.

If the Wicket base plugin is not active, logging is silently skipped.

**Admin notice for missing HyperFields:** if HyperFields is unavailable, `render_missing_hyperfields_notice()` fires an `admin_notices` action with the error. No export/import UI is rendered.

---

## Testing Checklist (Manual)

1. Log in as a user with a `wicket.io` email. Confirm Portus menu appears.
2. Log in as a user with a non-allowed domain. Confirm menu is hidden and direct URL returns 403.
3. Use User Switching to switch into a `wicket.io` account from a non-permitted admin. Confirm Portus menu is hidden and direct URL returns 403.
3. Export in `template` mode. Verify `modules.wicket_settings` has sensitive keys removed.
4. Export in `full` mode without confirming the checkbox. Verify it falls back to `template`.
5. Export in `full` mode with confirmation. Verify sensitive keys are present.
6. Export in `developer` mode. Verify `developer_wp_options_snapshot` appears in the manifest.
7. Upload a manifest and run preview. Confirm dry-run diff output is shown without writing.
8. Run import. Confirm post-import notice shows module count and queued plugin changes.
9. Visit another admin page. Confirm deferred plugin changes are applied.
10. Import a manifest with an unrecognised `type`. Confirm it is rejected cleanly.
