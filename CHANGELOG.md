# Wicket Portus Changelog

# 0.1.2
- Security: deny Portus access to impersonated sessions from the User Switching plugin, even when the switched-to account has a permitted email domain.

# 0.1.1
- Initial release.
- Export and import of Wicket stack configuration as portable JSON manifests.
- Domain-based access gating: only users with a `wicket.io` email (or domains listed in `WICKET_PORTUS_ALLOWED_DOMAINS`) can access Portus.
- Modular export system with support for template, full, and developer export modes.
- Sensitive-data warnings on full and developer exports.
- Pre-push git hook to prevent tagging with dev dependencies present.
