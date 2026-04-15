---
title: "Portus Module Playbook"
audience: [developer]
source_files: ["src/Contracts/ConfigModuleInterface.php", "src/Contracts/SanitizableModuleInterface.php", "src/Contracts/OptionGroupProviderInterface.php", "src/Manifest/ImportResult.php"]
---

# Portus Module Playbook

Step-by-step guide to adding a new module to Portus, with a complete worked example.

The fictional plugin used throughout:

- Plugin name: **Wicket Events**
- WordPress option key: `wicket_events_options`
- Portus module key: `wicket_events`

---

## Step 1 — Define the Module Contract

Before writing code, lock down four things:

1. **Module key** — stable, unique, snake_case. Once a manifest is shipped with this key, it cannot be renamed without a breaking change.
2. **Payload shape** — exact array keys the manifest will contain under `modules.wicket_events`.
3. **Import mode** — `merge` (preserve existing keys not in payload) or `replace` (overwrite entirely).
4. **Sensitive fields policy** — which keys must be absent from `template` exports.

Example contract for `wicket_events`:

```
Export payload:
  settings (array) — raw value of wicket_events_options

Import:
  Validate: 'settings' must be present and an array.
  Mode: merge
  Write: wicket_events_options

Template sanitization:
  Remove: api_key, webhook_secret
```

---

## Step 2 — Create the Module Class

Create `src/Modules/WicketEventsModule.php`:

```php
<?php

declare(strict_types=1);

namespace WicketPortus\Modules;

use WicketPortus\Contracts\ConfigModuleInterface;
use WicketPortus\Contracts\OptionGroupProviderInterface;
use WicketPortus\Contracts\SanitizableModuleInterface;
use WicketPortus\Manifest\ImportResult;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

final class WicketEventsModule implements ConfigModuleInterface, OptionGroupProviderInterface, SanitizableModuleInterface
{
    private const OPTION_KEY = 'wicket_events_options';

    public function __construct(
        private readonly WordPressOptionReader $reader,
        private readonly HyperfieldsOptionTransfer $transfer,
    ) {}

    public function key(): string
    {
        return 'wicket_events';
    }

    public function option_groups(): array
    {
        return [
            self::OPTION_KEY => 'Wicket Events Settings',
        ];
    }

    public function export(): array
    {
        $settings = $this->reader->get(self::OPTION_KEY, []);

        return [
            'settings' => is_array($settings) ? $settings : [],
        ];
    }

    public function validate(array $payload): array
    {
        $errors = [];

        if (!array_key_exists('settings', $payload)) {
            $errors[] = 'wicket_events: payload must include "settings".';

            return $errors;
        }

        if (!is_array($payload['settings'])) {
            $errors[] = 'wicket_events: "settings" must be an array.';
        }

        return $errors;
    }

    public function import(array $payload, array $options = []): ImportResult
    {
        $dry_run = (bool) ($options['dry_run'] ?? true);
        $result = $dry_run ? ImportResult::dry_run() : ImportResult::commit();

        foreach ($this->validate($payload) as $error) {
            $result->add_error($error);
        }

        if (!$result->is_successful()) {
            return $result;
        }

        $option_values = [self::OPTION_KEY => $payload['settings']];
        $allowed = [self::OPTION_KEY];

        if ($dry_run) {
            $diff = $this->transfer->diff_option_values($option_values, $allowed, '', 'merge');

            if (!($diff['success'] ?? false)) {
                $result->add_error((string) ($diff['message'] ?? 'wicket_events: dry-run diff failed.'));

                return $result;
            }

            $changes = $diff['changes'] ?? [];
            if (is_array($changes) && array_key_exists(self::OPTION_KEY, $changes)) {
                $result->add_imported(self::OPTION_KEY);
            } else {
                $result->add_skipped(self::OPTION_KEY, 'no changes detected');
            }

            return $result;
        }

        $import = $this->transfer->import_option_values($option_values, $allowed, '', 'merge');
        if ($import['success'] ?? false) {
            $result->add_imported(self::OPTION_KEY);
        } else {
            $result->add_error((string) ($import['message'] ?? 'wicket_events: import failed.'));
        }

        return $result;
    }

    public function sanitize(array $payload): array
    {
        if (!isset($payload['settings']) || !is_array($payload['settings'])) {
            return $payload;
        }

        $sanitized = $payload;
        unset($sanitized['settings']['api_key'], $sanitized['settings']['webhook_secret']);

        return $sanitized;
    }
}
```

**Key patterns to follow:**

- Always call `validate()` at the top of `import()` and return early if it fails.
- Check `$options['dry_run']` (defaults to `true` — never assume false).
- Use an explicit allow-list for option keys — never write keys that weren't in the payload.
- `sanitize()` must return a new array. Do not mutate `$payload` in place.

---

## Step 3 — Register the Module

The preferred approach is registration via the `wicket_portus_register_modules` action. This avoids modifying Portus core.

Add to your plugin's bootstrap (or an `mu-plugin`):

```php
<?php

declare(strict_types=1);

use WicketPortus\Modules\WicketEventsModule;
use WicketPortus\Registry\ModuleRegistry;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WordPressOptionReader;

add_action('wicket_portus_register_modules', static function (ModuleRegistry $registry): void {
    $registry->register(
        new WicketEventsModule(
            new WordPressOptionReader(),
            new HyperfieldsOptionTransfer(),
        )
    );
});
```

If you're adding a core Portus module (not an extension), add it to `Plugin::register_modules()` instead.

**Replacing a core module:** register a new class with the same `key()` return value. The registry silently overwrites the previous entry.

---

## Step 4 — Verify in the UI

1. Open **Wicket → Portus**.
2. Confirm the module label (from `option_groups()`) appears in the export selection list.
3. Export in `template` mode. Inspect `modules.wicket_events` — sensitive keys must be absent.
4. Export in `full` mode. Confirm sensitive keys are present.
5. Upload the `full` export via Preview. Confirm the dry-run diff shows the expected changes.
6. Import. Confirm the result summary lists the option key as imported.
7. Re-import the same file. Confirm the key appears in `skipped` (no changes detected).

Expected manifest section:

```json
{
  "modules": {
    "wicket_events": {
      "settings": {
        "default_timezone": "America/Toronto",
        "sync_enabled": true
      }
    }
  }
}
```

---

## Step 5 — Update Documentation

When shipping a new module, update these files in the same PR:

- `README.md` → add a row to the module table.
- `docs/manifest-reference.md` → add the payload shape and import behaviour.
- `docs/developer-guide.md` → update the module key list if it is a core module.

---

## Developer Checklist

- [ ] Module key is unique, stable, and snake_case.
- [ ] `key()` return value is documented and never reused for a different payload shape.
- [ ] `validate()` returns clear, user-readable error strings for every invalid state.
- [ ] `import()` correctly handles `dry_run: true` (no writes) and `dry_run: false` (real writes).
- [ ] `sanitize()` covers all sensitive fields and does not mutate its argument.
- [ ] Option allow-list in `import()` is explicit — no wildcard or dynamic key writes.
- [ ] Module is selectable in the export UI via `OptionGroupProviderInterface` (if applicable).
- [ ] Full export → preview → import → re-import round-trip tested end-to-end.
- [ ] Docs updated in the same PR.

---

## Common Mistakes

**Reusing a module key for a new payload shape.** Old manifests with the original shape will break silently on import. Add a new key instead.

**Trusting the manifest payload blindly.** Always call `validate()` before touching `$payload` keys in `import()`. Manifests can be hand-edited or corrupted.

**Assuming `dry_run` is false.** The preview step calls `import()` with `dry_run: true`. If your code writes unconditionally, data is corrupted during preview.

**Writing options not in the allow-list.** This can clobber unrelated settings. Build the allow-list from `$payload` keys, not from `get_option()` discovery.

**Putting environment secrets in template exports.** Implement `SanitizableModuleInterface` and call `SensitiveFieldsRegistry::for_module($this->key())` to get the full field list to strip.

**Editing `Plugin::register_modules()` for a third-party module.** Use the `wicket_portus_register_modules` action instead.
