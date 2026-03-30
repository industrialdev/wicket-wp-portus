# HyperFields

HyperFields is a Composer library for WordPress custom fields.

It provides:
- options pages
- post/user/term field containers
- field validation/sanitization
- conditional logic
- JSON export/import for options
- JSON export/import for pages/CPT content
- pluggable transfer-module orchestration
- customisable export envelope via `Transfer\SchemaConfig`

## Installation

```bash
composer require estebanforge/hyperfields
```

Load your project Composer autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

HyperFields bootstrap is registered via Composer `autoload.files`.

## Basic usage

```php
use HyperFields\Field;
use HyperFields\OptionsPage;

$page = OptionsPage::make('My Settings', 'my-settings');

$page->addField(
    Field::make('text', 'site_title', 'Site Title')
        ->setDefault('My Site')
        ->setRequired()
);

$page->register();
```

## Helper functions

Procedural helpers are available with `hf_` prefix (for example: `hf_field`, `hf_get_field`, `hf_update_field`, `hf_option_page`).

## Requirements

- PHP 8.1+

## Testing

HyperFields uses Pest v4.

```bash
composer run test
composer run test:unit
composer run test:integration
composer run test:coverage
composer run test:xdebug
```

## Custom export envelope

Use `Transfer\SchemaConfig` with `Transfer\Manager::withSchema()` to control the JSON bundle shape emitted by `export()`:

```php
use HyperFields\Transfer\Manager;
use HyperFields\Transfer\SchemaConfig;

$bundle = (new Manager())
    ->withSchema(new SchemaConfig(
        type: 'my_plugin_manifest',
        schema_version: 2,
        extra: ['site' => ['url' => get_site_url(), 'environment' => 'staging']],
    ))
    ->export();
```

See `docs/transfer-and-content-export-import.md` for the full reference.

## License

GPL-2.0-or-later
