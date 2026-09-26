# DevOne Runtime Loader

Introduced in DevOne Core 1.7.0 and expanded with Admin Runtime in 1.7.1. The Runtime Loader is additive and backward-compatible. Existing plugin and theme folder structures remain valid.

## Performance contract

`plugin.php` is bootstrap-only. It may register hooks, routes, shortcodes, admin pages and lightweight metadata. It must not perform schema repair, CREATE/ALTER TABLE work, remote HTTP calls, large reconciliation queries, or start sessions just because the plugin is active.

## Context API

```php
DevOne::runtime()->frontend(function () { require __DIR__ . '/public/frontend.php'; });
DevOne::runtime()->admin(function () { require __DIR__ . '/admin/bootstrap.php'; });
DevOne::runtime()->api(function () { require __DIR__ . '/includes/api.php'; });
DevOne::runtime()->background(function () { require __DIR__ . '/includes/background.php'; });
DevOne::runtime()->route('/checkout', __DIR__ . '/includes/checkout.php');
DevOne::runtime()->shortcode('my_gallery', __DIR__ . '/public/gallery.php');
```

Contexts: `frontend`, `admin`, `api`, `background`, and `cli`.

## Admin Runtime (Core 1.7.1+)

Admin work can be scoped to the smallest screen that needs it:

```php
// All admin requests. Use only for truly global, lightweight admin behavior.
DevOne::runtime()->admin(function () {
    require __DIR__ . '/admin/global.php';
});

// One Core admin page: admin/pages.php
DevOne::runtime()->admin('pages', function () {
    require __DIR__ . '/admin/page-editor-integration.php';
});

// Several Core admin pages.
DevOne::runtime()->admin(['pages', 'media'], function () {
    require __DIR__ . '/admin/content-tools.php';
});

// Any page owned by one plugin.
DevOne::runtime()->admin('plugin:acme-reports', function () {
    require __DIR__ . '/admin/reports-bootstrap.php';
});

// One plugin page only.
DevOne::runtime()->admin('plugin:acme-reports/settings', function () {
    require __DIR__ . '/admin/settings-runtime.php';
});
```

A plugin opts into Core-level lazy admin bootstrapping only when it is ready:

```json
{
  "runtime": {
    "mode": "optimized",
    "admin_mode": "lazy",
    "admin_pages": ["pages", "media"]
  }
}
```

`admin_pages` lists Core administration screens where the plugin must still bootstrap because it integrates with that screen. For example, a visual builder that adds an **Edit with Visual Studio** action to `admin/pages.php` should include `pages`.

Plugins without `runtime.admin_mode` remain on the legacy admin path and continue loading normally. This is intentional backward compatibility.

## Admin menu registry cache

Lazy-admin plugins still need their registered pages to appear in the DevOne navigation. Core stores a safe, path-validated admin page registry under `storage/cache/`. The registry is invalidated when plugins are installed, updated, activated, deactivated or uninstalled. If the registry is missing, Core performs one compatibility discovery load and rebuilds it.

The cache stores menu/page metadata only. It does not cache permissions, sessions, credentials, rendered plugin pages, or user-specific output.

## Lifecycle API

Optimized plugins declare lifecycle files in `plugin.json`:

```json
{
  "runtime": {"mode": "optimized"},
  "lifecycle": {
    "activate": "lifecycle/activate.php",
    "update": "lifecycle/activate.php",
    "repair": "lifecycle/activate.php"
  }
}
```

Schema creation/migrations belong in lifecycle files, not normal runtime. DevOne runs lifecycle files on install/update/activation. Administrators can explicitly run **Repair / Sync Plugin Schemas** from the Dashboard.

Legacy plugins without these manifest fields continue to load normally.

## Sessions and network calls

Anonymous public requests should remain sessionless. Admin requests should not start extra plugin-owned sessions unless a feature needs them. External APIs must be called only from the route/action that needs them, a background task, or explicit admin action. Never block every request on Stripe, Marketplace, licensing, calendar, AI or other network services.

## Security invariants

Lazy loading never bypasses DevOne permissions, CSRF validation, authentication, package path validation, or lifecycle checks. Performance optimizations decide **when code is loaded**, not whether security checks apply.
