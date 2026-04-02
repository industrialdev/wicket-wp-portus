# Portus End-User Guide

This guide is for operators using Portus in wp-admin.

## Before You Start

- Required capability: `manage_options`
- Menu path: `Wicket Settings -> Portus`
- You should have a backup and a rollback path before importing into production.

## What Portus Is For

Use Portus to move Wicket configuration between environments using a JSON manifest.

Common uses:

- Move stable settings from staging to production.
- Clone baseline configuration for a new client environment (template mode).
- Compare incoming config with current environment before applying changes.

## Export Modes

### Template (Default)

Use when sharing or reusing config safely.

- Sensitive fields are sanitized for supported modules.
- Post-like numeric IDs are removed from payloads.
- Best mode for cross-client setup templates.

### Full

Use for same-client environment sync when credentials must be preserved.

- Includes sensitive values.
- Requires explicit confirmation checkbox.

### Developer

Use only for engineering diagnostics or deep migration analysis.

- Includes full payloads.
- Includes developer-only snapshot modules.
- Requires explicit confirmation checkboxes.

## Export Steps

1. Open `Wicket Settings -> Portus`.
2. Select the option groups/modules you want to export.
3. Choose export mode (`template`, `full`, `developer`).
4. If prompted, check the required confirmation checkbox(es).
5. Click export and download the generated JSON manifest.
6. Store manifest securely if `full` or `developer` mode was used.

## Import Steps

1. Open `Wicket Settings -> Portus` on the destination environment.
2. Upload the manifest JSON file.
3. Review the preview/diff output carefully.
4. Confirm import.
5. Review the import notice and warnings.
6. Reload or visit another admin page once more so deferred plugin sync can apply.

## What Happens After Import

On successful import, Portus:

1. Queues plugin activation/deactivation changes from the manifest plugin inventory.
2. Flushes WordPress object cache and supported page-cache integrations.
3. Applies deferred plugin changes on the next admin request (if user has `activate_plugins`).

## Safety Notes

- `full` and `developer` exports can contain credentials and environment-specific secrets.
- Do not share sensitive manifests over insecure channels.
- Always run preview/diff before importing into production.
- If import reports errors, stop and resolve them before retrying.

## Troubleshooting

### Portus page does not load

Possible cause: HyperFields dependency is unavailable.

- Check plugin dependencies and activation state.
- Confirm the Portus admin notice content for exact error details.

### Import says no supported modules

Possible causes:

- File is not a Portus manifest.
- Manifest has no recognized `modules` payload keys.

Action:

- Re-export from source environment using Portus and retry.

### Plugin state did not change immediately after import

Expected behavior.

- Plugin activation/deactivation is deferred until next `admin_init` request.
- Visit another admin page as a user with `activate_plugins` capability.
