---
title: "Portus End-User Guide"
audience: [end-user, implementer]
wp_admin_path: "Wicket → Portus"
---

# Portus End-User Guide

Portus is the configuration portability tool for the Wicket WP Stack. It lets you snapshot a site's Wicket settings into a JSON manifest, move that manifest to another environment, and apply it — all from a single WordPress admin page.

## Who This Guide Is For

Operators and site administrators who export and import Wicket configuration through WordPress Admin. No coding required.

## Before You Start

- **Required capability:** `manage_options`
- **Required email domain:** your WordPress account email must belong to an allowed domain (default: `wicket.io`). See [access-control.md](../engineering/access-control.md) if you need to grant access to a third-party team.
- **Menu path:** Wicket → Portus
- **Always take a backup** and verify a rollback path before importing into a production environment.
- If Portus does not appear in the menu, your email address is not permitted. Contact a Wicket team member.

---

## Export Modes

Choose the right mode for your situation. You cannot change it after the file is downloaded.

### Template (default)

Use when setting up a new client site or sharing configuration as a starting point.

- Sensitive values (API keys, credentials, environment URLs) are removed.
- Database-specific numeric IDs are stripped from post-like records.
- Safe to share, store in version control, or hand off to a third party.

### Full

Use when syncing the same client between environments (staging → production) and credentials must be preserved.

- All values are exported, including secrets.
- Requires an explicit confirmation checkbox before the export button becomes active.
- **Do not commit full exports to version control or share over insecure channels.**

### Developer

Use only for engineering diagnostics or deep migration analysis.

- Everything in Full mode, plus developer-only modules (e.g. a full WordPress options snapshot).
- Requires two explicit confirmation checkboxes.
- Handle with the same care as Full exports.

---

## Exporting

1. Open **Wicket → Portus**.
2. Check the modules you want to include. Modules that are disabled by your administrator will not appear.
3. Select the export mode in the dropdown.
4. If you chose Full or Developer, check the confirmation checkbox(es) that appear.
5. Click **Export**.
6. Save the downloaded JSON file somewhere secure.

---

## Importing

### Step 1 — Upload

1. Open **Wicket → Portus** on the destination site.
2. Click **Choose File** and select the JSON manifest.
3. Click **Preview** (do not click Import yet).

### Step 2 — Review the diff

Portus runs a dry-run before writing anything. You will see a summary of:

- **What will be written** — option keys whose values will change.
- **What will be skipped** — keys with no detected change.
- **Warnings** — non-fatal notices such as version mismatches or sensitive-data reminders.
- **Errors** — problems that will prevent a module from importing.

Read this carefully. If the diff shows unexpected changes, stop and investigate the source manifest before proceeding.

### Step 3 — Confirm

If the diff looks correct, click **Import**.

### Step 4 — Wait for deferred changes

Plugin activations and deactivations from the `site_inventory` module are queued and applied on the next WordPress admin page load. After the import confirmation screen, visit any other admin page to trigger this step. The admin notice will confirm what was applied.

---

## What Happens After a Successful Import

1. Option values are written for each module that reported changes.
2. Plugin activation/deactivation changes from `site_inventory` are queued.
3. WordPress object cache is flushed.
4. Common page cache plugins are flushed when detected.
5. On the next admin page load, queued plugin changes are applied (requires `activate_plugins` capability).

---

## Module Overview

| Module | What it covers |
|--------|---------------|
| Site Inventory | Active plugin list. Import warns on mismatches and queues activation changes. |
| Wicket Settings | Core Wicket connection and feature settings. |
| Memberships | Membership plugin configuration. |
| Account Centre | Account Centre Carbon Fields options. |
| Gravity Forms / Wicket | GF slug mappings and member field settings. |
| Financial Fields | Finance mapping and deferral configuration. |
| WooCommerce Emails | WooCommerce email templates and settings. |
| Curated Pages | Curated page slug/ID mappings. |
| My Account Pages | My Account page mappings. *(disabled by default)* |
| Content Pages | Page post type export. *(disabled by default)* |
| Content My Account | My Account post type export. *(disabled by default)* |
| Developer WP Options | Full WordPress options snapshot. *(developer mode only)* |

Modules marked *disabled by default* can be enabled by a developer via the `wicket_portus_disabled_modules` filter. See [developer-guide.md](developer-guide.md).

---

## Safety Reminders

- Full and developer manifests contain credentials. Store them like passwords.
- Never import a manifest you did not export yourself, or that you have not fully reviewed.
- Always run the preview step. Never skip it.
- If the import page shows errors, resolve them before retrying. Do not import a partially-valid manifest.
- The `site_inventory` module can trigger plugin deactivations. Verify the plugin list in the diff before confirming.

---

## Troubleshooting

### The Portus menu item does not appear

Your user account email domain is not on the allowed list. Contact your Wicket account manager or a developer to add your domain via `WICKET_PORTUS_ALLOWED_DOMAINS` in `wp-config.php`. See [access-control.md](../engineering/access-control.md).

### The Portus page shows a HyperFields error

The HyperFields library is not available. This usually means:
- The plugin was installed without running `composer install`.
- The `vendor/` directory is missing or incomplete.

Fix: run `composer install` in the plugin directory.

### Import says "no supported modules found"

- The uploaded file is not a Portus manifest.
- The manifest was exported from an incompatible version.
- The manifest has no `modules` payload keys that Portus recognises.

Fix: re-export from the source environment and retry.

### Plugin state did not change after import

This is expected. Plugin changes are deferred until the next `admin_init` request. Visit any other WordPress admin page; the changes will apply then. You need `activate_plugins` capability for this step.

### The import changed more options than expected

This can happen when the source environment had options that differ from the destination. Use the diff preview carefully and compare the source and destination environments if needed. You can re-run the export in Template mode to get a sanitised, ID-stripped version that is safer to apply broadly.
