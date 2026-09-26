# Plugins

A plugin adds focused behavior to DevOne.

```text
acme-seo/
├── plugin.json
├── plugin.php
├── lifecycle/          optional, recommended for optimized plugins
├── admin/
├── includes/
└── assets/
```

```php
<?php
DevOne::events()->filter('page_title', function ($title) {
    return $title . ' | Acme';
});
```

Use vendor-prefixed hooks, functions, classes, routes, and custom services.

## Runtime performance (Core 1.7+)

See `RUNTIME-LOADER.md`. Existing package structure remains supported. New plugins should use the optimized runtime and lifecycle manifest fields.

Keep `plugin.php` lightweight. Put schema creation and repair in lifecycle actions. Use frontend/admin/route/shortcode/API/background runtime contexts to load heavy components only where they are required.

## Admin Runtime (Core 1.7.1+)

Do not opt a plugin into lazy admin loading until all of its admin integration points are declared. A plugin that only owns its own admin pages can use:

```json
"runtime": {
  "mode": "optimized",
  "admin_mode": "lazy"
}
```

A plugin that also extends Core pages must declare those page slugs:

```json
"runtime": {
  "mode": "optimized",
  "admin_mode": "lazy",
  "admin_pages": ["pages", "media"]
}
```

Then use `DevOne::runtime()->admin(...)` to include page-specific heavy code. Global admin behavior should stay lightweight.
