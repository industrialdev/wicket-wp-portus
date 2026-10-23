# What This Plugin Does

Wicket Portus is a configuration portability tool for the Wicket WP Stack. It snapshots Wicket site settings into a portable JSON manifest (export), diffs an incoming manifest against the current environment (preview/dry-run), then writes the changes (import). Three export modes: `template` (sanitised), `full` (credentials included), `developer` (full + WP options snapshot).

## Commands

```bash
composer install          # Install all dependencies (including dev)
composer setup-hooks      # Install the pre-push git hook
composer cs:lint          # Check code style (dry run, no writes)
composer cs:fix           # Fix code style
composer production       # Fix style → remove dev deps → optimise autoloader (run before tagging)
php -l <file>             # Quick PHP syntax check on a single file
```

No JS build step. `package.json` only pulls in Playwright for QA (lives in `../../../../../../qa/`).

Tests are in the shared QA suite at `../../../../../../qa/` — read `qa/README.md` and `qa/AGENTS.md` before adding any.

## Architecture

### Bootstrap chain

```
wicket-wp-portus.php          plugins_loaded@99
  Wicket_Portus_Bootstrap     loads autoloader, inits HyperFields
    WicketPortus\Plugin        singleton; boot() wires everything
      ModuleRegistry           holds ConfigModuleInterface instances keyed by string
      TransferOrchestrator     export() / diff() / import() → HyperFields Manager
      Admin UI (HyperFields ExportImportUI)
```

`Plugin::boot()` registers all core modules, applies `wicket_portus_disabled_modules` filter, then fires `do_action('wicket_portus_register_modules', $registry)` — the extension point for third-party modules.

### Module system

Every module implements `ConfigModuleInterface`:

- `key(): string` — stable snake_case key; never reuse for a different payload shape
- `export(): array` — read current environment state
- `validate(array $payload): array` — return `string[]` of errors; empty = valid
- `import(array $payload, array $options = []): ImportResult` — respect `$options['dry_run']` (defaults `true`)

Optional interfaces: `SanitizableModuleInterface` (sanitize sensitive fields in template mode), `OptionGroupProviderInterface` (appear in the export UI).

`ImportResult` is built fluently: `ImportResult::dry_run()` or `::commit()`, then `->add_imported()`, `->add_skipped()`, `->add_warning()`, `->add_error()`.

### Adding a module

Full worked example: `@see atlas://packages/wicket-wp-portus/engineering/add-module-playbook.md`. Key rules:

1. Always validate at the top of `import()` and return early on failure.
2. Check `$options['dry_run']` — never assume false.
3. Use an explicit option allow-list in `import()` — never write keys not in the payload.
4. `sanitize()` must return a new array; do not mutate `$payload`.
5. Register via `wicket_portus_register_modules` action, not by editing `Plugin::register_modules()`.
6. Update `README.md` module table and `docs/engineering/manifest-reference.md` in the same PR.

### Access control

`DomainGatekeeper` blocks at two points: `admin_menu` (menu never added) and `render_portus_data_tools_page()` (`wp_die()` HTTP 403). Both conditions must be true: user email domain is in the allow-list, **and** no active User Switching impersonation session.

Default allowed domain: `wicket.io` (hardcoded). Additional domains via `wp-config.php`:

```php
define('WICKET_PORTUS_ALLOWED_DOMAINS', 'example.com,partner.org');
```

### Deferred plugin changes

Plugin activation/deactivation changes from an import are stored in a transient (`wicket_portus_deferred_plugin_changes`) and applied on the next `admin_init` via `maybe_apply_deferred_plugin_changes()`. They are never applied inline during import.

### Manifest envelope

The manifest is a JSON envelope: `schema_version`, `type: wicket_portus_manifest`, `generated_at`, `export_mode` (template|full|developer), `site` (url, environment), `modules` keyed by module key, and `errors`. Volatile fields excluded from diff: `generated_at`, `errors`, `export_mode`. Full reference: `@see atlas://packages/wicket-wp-portus/engineering/manifest-reference.md`.

## Key Extension Points

| Hook/Filter | Purpose |
|---|---|
| `wicket_portus_register_modules` (action) | Register or replace modules |
| `wicket_portus_disabled_modules` (filter) | Exclude modules from export/import |
| `wicket_portus_sensitive_fields` (filter) | Extend sensitive field map |
| `wicket_portus/import/after` (action) | Post-import hook (`$result`, `$mode`) |
| `wicket_portus/export/template_strip_database_ids` (filter) | Toggle numeric ID stripping |
| `wicket_portus_acc_option_name_patterns` (filter) | SQL LIKE patterns for Account Centre discovery |
| `wicket_portus_theme_acf_option_name_patterns` (filter) | SQL LIKE patterns for Theme ACF discovery |

`ThemeAcfOptionsModule` ships with the plugin but is **not auto-registered** — it must be wired manually via `wicket_portus_register_modules`.

## Code Style

PHP CS Fixer rules: `@PSR12`, `@PER-CS`, `@PHP82Migration`. Config in `.php-cs-fixer.dist.php`. Run `composer cs:lint` before committing; the pre-push hook blocks tag pushes if dev dependencies are present in `vendor/`.

All PHP files: `declare(strict_types=1);`. Namespace root: `WicketPortus\` → `src/`.

## Documentation

Engineering and product documentation lives in the Atlas knowledge base at `@see atlas://packages/wicket-wp-portus.md` (developer guide, manifest reference, product overview). The package docs folder keeps only the public end-user guide. Read `@see atlas://packages/wicket-wp-portus/AGENTS.md` for doc writing rules before authoring Atlas docs for this package.

## Release & Branch Workflow
All work happens on branches. `main` is locked; changes land via peer-reviewed
Pull Request (devs cross-review each other). Never commit to `main` directly, and never push or open a
PR without explicit human approval.

Merging a PR to `main` **auto-releases** via the `wicket-release-bot` GitHub
App: version bump, `CHANGELOG.md` update, git tag. Never bump versions or
create tags by hand. The bump level comes from a marker in the PR title
(squash-merge makes it the commit message): _(none)_ / `#patch` = patch, `#minor`,
`#major`, or `#norelease` (no release; use for docs/tooling-only merges).
Conventional commit prefixes (`feat:`, `fix:`, `docs:`, ...) drive changelog
grouping; a `!` (e.g. `feat!:`) flags a BREAKING change.
