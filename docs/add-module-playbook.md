# Portus Module Playbook (Fictional Plugin Example)

This guide shows exactly how to add a new Portus module for a fictional plugin, with copy-paste examples and a step-by-step workflow.

Fictional plugin used in this walkthrough:

- Plugin name: `Wicket Events`
- WordPress option key: `wicket_events_options`
- Portus module key: `wicket_events`

## Step 1. Define the module contract first

Before writing code, lock these 4 items:

1. Module key: stable, snake_case, never reused for another meaning.
2. Payload shape: exact array keys the manifest will contain.
3. Import mode: `merge` or `replace`.
4. Sensitive fields policy: which keys must be removed in `template` mode.

Example contract for `wicket_events`:

- Export payload:
  - `settings` (array) -> raw value from `wicket_events_options`
- Import behavior:
  - Validate `settings` is present and is an array.
  - Use HyperFields transfer adapter in `merge` mode.
- Template sanitization:
  - Remove `api_key` and `webhook_secret`.

## Step 2. Create the module class

Create file:

- `src/Modules/WicketEventsModule.php`

Example implementation:

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

## Step 3. Register the module with Portus

Recommended: register from integration code using `wicket_portus_register_modules`.

Example (`mu-plugin` or your plugin bootstrap):

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

This avoids editing Portus core just to add one new module.

## Step 4. Verify it appears in UI and manifest

In wp-admin:

1. Open `Wicket Settings -> Portus`.
2. Confirm module appears in export options (label from `option_groups()`).
3. Export in `template` mode and inspect `modules.wicket_events` in JSON.
4. Export in `full` mode and confirm sensitive keys are present only when expected.
5. Import same file via preview first, then import.

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

## Step 5. Add documentation updates

When adding a real module, update these docs in the same PR:

- `README.md` -> module list and behavior summary.
- `docs/manifest-reference.md` -> payload shape + import behavior.
- `docs/developer-guide.md` -> module inventory / extension notes if needed.

## Step 6. Use this developer checklist

- [ ] Module key is unique and stable.
- [ ] `validate()` rejects malformed payloads with clear messages.
- [ ] `import()` handles `dry_run` correctly.
- [ ] Sensitive fields are removed in `template` exports.
- [ ] Option allow-list is strict (no wildcard writes).
- [ ] Module is visible in Portus UI (if intended).
- [ ] Export -> preview -> import path tested end-to-end.

## Common mistakes to avoid

- Reusing a module key for a new payload contract.
- Writing options that are not explicitly allow-listed.
- Skipping validation and trusting manifest payload blindly.
- Putting environment secrets in template exports.
- Editing Portus core registration when an action hook is enough.
