# DevOne Core 1.7.1 Stable — Admin Runtime Release

## Highlights
- Adds backward-compatible Admin Runtime routing to the DevOne 1.7 Runtime Loader.
- Adds page-scoped `DevOne::runtime()->admin($targets, $callback)` registration while preserving `admin($callback)`.
- Adds safe plugin admin-registry caching for plugins that explicitly opt into lazy admin loading.
- Legacy plugins continue loading through the existing admin path until their developers opt in.
- Lazy-admin plugins load on their own plugin pages and declared Core admin integration pages only.
- Active-plugin/admin-registry caches invalidate on install, activation, deactivation, update and uninstall.
- Removes automatic Core schema repair from admin login/registration request paths.
- Removes generic user-profile schema repair from every admin request; explicit repair/sync remains available.
- No plugin/theme package structure changes are required.
- Existing Core Services, permissions, CSRF, authentication and package-integrity behavior remain intact.

## Lazy Admin Manifest Contract
Plugins may opt in with `runtime.admin_mode` set to `lazy`, `optimized`, or `route-aware`.
Optional `runtime.admin_pages` lists Core admin page slugs where cross-plugin integration is required, such as `pages` or `media`.

## Compatibility
Existing plugins without `runtime.admin_mode` are intentionally treated as legacy admin plugins and continue loading normally. This prevents the Admin Runtime upgrade from silently removing menu items, dashboard hooks, page editor actions, or other existing integrations.
