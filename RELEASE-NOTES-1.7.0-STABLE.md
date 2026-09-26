# DevOne Core 1.7.0 Stable — Runtime Speed Release

## Highlights
- New backward-compatible DevOne Runtime Loader.
- Legacy plugin/theme package structures remain supported.
- Optimized runtime contexts: frontend, admin, API, background, CLI.
- Route-aware and shortcode-aware deferred component loading API.
- Plugin lifecycle manifest for activate/update/repair migrations.
- No automatic Core schema repair during normal requests.
- Explicit Admin repair for Core and optimized plugin schemas.
- Active-plugin registry cache with activation/deactivation/install invalidation.
- Existing Core Services remain compatible; `DevOne::runtime()` is additive.
- Performance rules documented for Marketplace developers.

## Compatibility
Existing plugins without optimized runtime metadata continue using the legacy bootstrap path. No mandatory folder-structure change is introduced.
