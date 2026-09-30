# Changelog

All notable changes to this plugin are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

<!-- new releases inserted below this line -->

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
