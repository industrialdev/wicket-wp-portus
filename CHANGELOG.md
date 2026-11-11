# Changelog

All notable changes to this plugin are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

<!-- new releases inserted below this line -->

## [0.1.10] - 2026-11-11

### Fixed
- **memberships:** keep WooCommerce API key allowlist out of Portus transfers (WWID-2082)


## [0.1.9] - 2026-11-10

### Maintenance
- **deps:** consume dependencies from wicket-wp-account-centre (WWID-2980)


## [0.1.8] - 2026-11-06

### Added
- **gf:** export the per-field MDP UUID source property

### Documentation
- state the atlas knowledge-base rule in AGENTS.md
- streamline AGENTS.md and point at atlas
- flatten end-user docs and link from README
- add three missing modules to the developer guide tree (WWID-2665)
- drop removed content-transfer modules from the developer guide (WWID-2665)

### Maintenance
- **docs:** move engineering docs to atlas (A0012)
- **ci:** fail closed when main moves before release push


## [0.1.7] - 2026-09-30

### Fixed
- **content-transfer:** prune stale references to removed module keys (WWID-2665)
- **content-transfer:** remove content modules calling dependencies deleted in 2cdcc8d (WWID-2665)


# Wicket Portus Changelog

# 0.1.6
- Dependency: bump HyperFields

# 0.1.2
- Security: deny Portus access to impersonated sessions from the User Switching plugin, even when the switched-to account has a permitted email domain.

# 0.1.1
- Initial release.
- Export and import of Wicket stack configuration as portable JSON manifests.
- Domain-based access gating: only users with a `wicket.io` email (or domains listed in `WICKET_PORTUS_ALLOWED_DOMAINS`) can access Portus.
- Modular export system with support for template, full, and developer export modes.
- Sensitive-data warnings on full and developer exports.
- Pre-push git hook to prevent tagging with dev dependencies present.
