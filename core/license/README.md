# DevOne 2.0 Licensing

DevOne ships with **single-site capability by default**.

A valid signed Network entitlement unlocks additional sites and network capabilities. The license key is an activation credential; runtime authorization uses the verified entitlement, not a plaintext feature flag.

Default behavior:
- No entitlement: one site.
- Network entitlement: additional sites up to maxSites.
- Network-only features are checked through LicenseService.
- Expired/invalid entitlements fall back to single-site capability.
- Core owns license enforcement; extensions cannot bypass it.

The license provider is intentionally abstract so the future DevOne License Server can be upgraded without changing site/business logic.
