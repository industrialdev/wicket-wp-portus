# Portus Manifest Reference

Complete specification for the Portus manifest format. Use this as the source of truth when building tooling that reads or produces Portus manifests.

---

## Envelope

Every Portus manifest shares the same top-level envelope, regardless of which modules it contains.

```json
{
  "schema_version": 1,
  "type": "wicket_portus_manifest",
  "generated_at": "2025-01-01T12:00:00+00:00",
  "export_mode": "template",
  "site": {
    "url": "https://example.com",
    "environment": "staging"
  },
  "modules": { },
  "errors": []
}
```

| Field | Type | Description |
|-------|------|-------------|
| `schema_version` | integer | Always `1`. Increment only on breaking payload changes. |
| `type` | string | Always `wicket_portus_manifest`. Used to reject non-Portus files. |
| `generated_at` | string | ISO-8601 timestamp of when the manifest was produced. |
| `export_mode` | string | `template`, `full`, or `developer`. |
| `site.url` | string | `get_site_url()` of the source environment. |
| `site.environment` | string | Value of `WP_ENVIRONMENT_TYPE`, or `production` if not defined. |
| `modules` | object | Module payloads, keyed by module key. |
| `errors` | array | Errors encountered during export. Normally empty. |

**Volatile fields** — excluded from diff comparisons because they change every export and carry no semantic meaning:
- `generated_at`
- `errors`
- `export_mode`

---

## Export Modes

### `template`

- Sensitive fields are removed by each module's `SanitizableModuleInterface::sanitize()`.
- Post-like numeric `id` fields are stripped from records that have `post_type`, `post_name`, and `post_title` siblings (filterable via `wicket_portus/export/template_strip_database_ids`).
- Safe to store in version control or share cross-client.

### `full`

- Module payloads are exported without sanitisation.
- All values including credentials, API keys, and environment URLs are present.
- Requires operator confirmation before download.

### `developer`

- Same as `full`, plus developer-only modules (e.g. `developer_wp_options_snapshot`) are included regardless of selection.
- Requires two confirmation checkboxes.

---

## Module Payloads

### `site_inventory`

```json
{
  "plugins": [
    {
      "plugin": "wicket-wp-base-plugin/wicket.php",
      "name": "Wicket Base Plugin",
      "version": "2.2.52",
      "active": true
    }
  ]
}
```

**Import behaviour:**
- Read-only comparison; no direct writes.
- Warns on missing plugins or version mismatches.
- Plugin activation/deactivation is queued as deferred changes (applied on next `admin_init`).

---

### `wicket_settings`

```json
{
  "wicket_admin_settings_app_name": "My Wicket Site",
  "wicket_admin_settings_environment": "production",
  "wicket_admin_settings_prod_api_endpoint": "[REDACTED]"
}
```

**Import behaviour:**
- Validates payload is a non-empty array.
- Writes via HyperFields option transfer in `replace` mode.
- Adds a sensitive-data warning to the import result.

**Template mode:** removes all fields listed under `wicket_settings` in `SensitiveFieldsRegistry` plus the global credential patterns. See [developer-guide.md → Sensitive Fields](developer-guide.md#sensitive-fields--template-mode) for the complete list.

---

### `memberships`

```json
{
  "plugin_options": {
    "tiers_enabled": true,
    "renewal_grace_days": 30
  }
}
```

**Import behaviour:**
- Requires `plugin_options` key with an array value.
- Writes `wicket_membership_plugin_options` via HyperFields in `replace` mode.

---

### `gravity_forms_wicket_plugin`

```json
{
  "wicket_gf_pagination_sidebar_layout": "default",
  "wicket_gf_member_fields": { },
  "wicket_gf_slug_mapping": {
    "registration": 3,
    "renewal": 7
  }
}
```

**Import behaviour:**
- Missing keys produce warnings, not errors.
- `wicket_gf_slug_mapping` is JSON-encoded before write if supplied as an array.
- Writes each option via HyperFields in `replace` mode.

---

### `account_centre`

```json
{
  "option_names": [
    "carbon_fields_container_wicket_acc_options_field_one"
  ],
  "options": {
    "carbon_fields_container_wicket_acc_options_field_one": "value"
  }
}
```

**Export:** discovers option names via SQL `LIKE` patterns:
- `carbon_fields_container_wicket_acc_options%`
- `_carbon_fields_container_wicket_acc_options%`

Patterns are filterable via `wicket_portus_acc_option_name_patterns`.

**Import behaviour:**
- Requires `options` array.
- Allowed keys are `option_names` when provided; otherwise all keys in `options`.
- Writes via HyperFields in `replace` mode.

---

### `financial_fields`

```json
{
  "wicket_finance_enable_system": true,
  "wicket_finance_customer_visible_categories": ["membership"],
  "wicket_finance_display_order_confirmation": true
}
```

**Import behaviour:**
- Writes each recognised option via HyperFields in `replace` mode.

---

### `woocommerce_emails`

```json
{
  "woocommerce_email_options": { }
}
```

**Import behaviour:**
- Writes WooCommerce email option values via HyperFields in `replace` mode.

---

### `curated_pages`

```json
{
  "pages": [
    {
      "post_type": "page",
      "post_name": "about-us",
      "post_title": "About Us",
      "post_status": "publish"
    }
  ]
}
```

**Template mode:** numeric `id` fields are stripped from each record.

---

### `my_account_pages`

Same shape as `curated_pages`. Disabled by default.

---

### `content_pages` / `content_my_account`

Post type export modules. Disabled by default. Shape matches `curated_pages`.

---

### `developer_wp_options_snapshot`

Available in developer export mode only.

```json
{
  "options": {
    "blogname": "My Site",
    "active_plugins": ["..."]
  }
}
```

**Import behaviour:**
- This module is export-only by default. Import writes are explicitly gated.
- Treat this payload as a diagnostic reference, not an import target.

---

## ImportResult Shape

Each module import returns a result that is serialised and displayed in the admin UI:

```json
{
  "dry_run": true,
  "success": true,
  "imported": ["option_key_one", "option_key_two"],
  "skipped": [
    { "key": "option_key_three", "reason": "no changes detected" }
  ],
  "warnings": ["Sensitive data is present in this module."],
  "errors": []
}
```

| Field | Type | Description |
|-------|------|-------------|
| `dry_run` | bool | `true` for preview runs, `false` for real imports. |
| `success` | bool | `true` when `errors` is empty. |
| `imported` | string[] | Option keys that were (or would be) written. |
| `skipped` | array | Keys skipped with a `key` + `reason` pair each. |
| `warnings` | string[] | Non-fatal operator notices. |
| `errors` | string[] | Fatal problems. If any present, `success` is `false`. |
