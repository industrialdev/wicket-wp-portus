# Repository Guidelines

Wicket Portus is a configuration portability tool for the Wicket WP Stack. It snapshots Wicket plugin settings into a portable JSON manifest (export), diffs an incoming manifest against the current environment (preview/dry-run), then writes the changes (import). See [docs/product/portus-overview.md](docs/product/portus-overview.md) for export modes and modules, or [docs/engineering/developer-guide.md](docs/engineering/developer-guide.md) for architecture.

## Project Structure & Module Organization
This is a WordPress plugin rooted at `wicket-wp-portus.php`.
- `src/`: PSR-4 PHP code under `WicketPortus\` — `Plugin` (singleton bootstrap), `Access/` (domain gate), `Contracts/` (module interfaces), `Manifest/` (export/import orchestration), `Modules/` (one class per Wicket plugin integration), `Registry/` (module + sensitive-field registries), `Support/` (option transfer helpers).
- `assets/`: admin UI assets (JS, audio).
- `docs/`: product/engineering/guides documentation tree — see `docs/AGENTS.md` for the schema.
- `.ci/`: pre-push git hook source.

## Build, Test, and Development Commands
- `composer install`: install PHP dependencies (including dev).
- `composer setup-hooks`: install the pre-push git hook.
- `composer cs:lint`: check code style (dry run, no writes).
- `composer cs:fix`: fix code style.
- `composer production`: fix style → remove dev deps → optimise autoloader (run before tagging).
- `php -l <file>`: quick PHP syntax check on a single file.

No JS build step. `package.json` only pulls in Playwright for the shared QA suite.

## Coding Style & Naming Conventions
- PHP 8.3+, `declare(strict_types=1);`, PSR-12 (`@PSR12`, `@PER-CS`, `@PHP82Migration` via `.php-cs-fixer.dist.php`).
- PSR-4 namespace `WicketPortus\` → `src/`. Classes `PascalCase`, methods `camelCase`.
- Favor small methods, early returns, and WordPress-native APIs/hooks.

## Architecture
Module registry + transfer orchestrator pattern wrapping the HyperFields Export/Import library. Every module implements `ConfigModuleInterface` (`key()`, `export()`, `validate()`, `import()`); optional `SanitizableModuleInterface` and `OptionGroupProviderInterface`. Access to the admin page is gated by email domain (`WICKET_PORTUS_ALLOWED_DOMAINS`).

- [docs/engineering/developer-guide.md](docs/engineering/developer-guide.md) — bootstrap flow, module system, export/import pipelines, extension points (hooks/filters), debugging
- [docs/engineering/manifest-reference.md](docs/engineering/manifest-reference.md) — manifest envelope and per-module payload shapes
- [docs/engineering/access-control.md](docs/engineering/access-control.md) — domain-based access gate
- [docs/engineering/add-module-playbook.md](docs/engineering/add-module-playbook.md) — worked example for adding a new module

## Testing Guidelines
Tests are in the shared QA suite at `../../../../../../qa/` (wicket-warden) — read `qa/README.md` and `qa/AGENTS.md` before adding any. No local `tests/` directory in this repo.

## Commit & Pull Request Guidelines
Git history favors conventional-commit-style, scope-specific messages (for example `feat: extend WicketGfOptionsModule with per-form and per-field GF settings`, `docs: restructure portus docs to match wicket-wp-base-plugin template`).
- Keep commits focused; avoid mixed refactor/feature changes.
- PRs should include: purpose, risk notes, and manual QA evidence (see the Testing Checklist in `docs/engineering/developer-guide.md`).
- Link related issue/ticket and call out breaking or release-impacting changes.
- Update `docs/` in the same PR as any hook/filter/module change — see `docs/AGENTS.md`.

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


## Security & WordPress-Specific Requirements
- Sanitize, validate, and escape all input/output (`sanitize_text_field`, `esc_html`, etc.).
- Enforce capability checks (`manage_options`) and nonces for admin actions.
- Access is additionally gated by email domain — see [docs/engineering/access-control.md](docs/engineering/access-control.md).
- Manifests exported in `full`/`developer` mode contain credentials; never commit them to version control.
