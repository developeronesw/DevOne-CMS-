# DevOne CMS Modules

Modules are installable application packages. Unlike a small plugin that adds a hook or filter, a module can provide a complete feature area with front-end routes, admin pages, assets, permissions, and installation lifecycle routines.

## Minimum package

```text
my-module/
├── module.json
└── module.php
```

A legacy `controller.php` is accepted when `module.php` is absent.

## Full package

```text
my-module/
├── module.json
├── module.php
├── install.php
├── update.php
├── activate.php
├── deactivate.php
├── uninstall.php
├── admin/
│   └── overview.php
├── views/
│   └── index.php
└── assets/
    ├── css/module.css
    └── js/module.js
```

## Manifest example

```json
{
  "name": "Booking Manager",
  "slug": "booking-manager",
  "version": "1.0.0",
  "description": "Appointments, staff, and booking management.",
  "author": "Your Studio",
  "license": "GPL-3.0-or-later",
  "main": "module.php",
  "admin_pages": [
    {
      "slug": "overview",
      "title": "Booking Manager",
      "menu_title": "Bookings",
      "file": "admin/overview.php",
      "permission": "manage_modules"
    }
  ],
  "routes": [
    {
      "path": "bookings",
      "methods": ["GET"],
      "title": "Book an Appointment",
      "file": "views/index.php"
    },
    {
      "path": "bookings/{id}",
      "methods": ["GET", "POST"],
      "file": "views/booking.php",
      "auth_required": true
    }
  ],
  "assets": {
    "frontend": {
      "css": ["assets/css/module.css"],
      "js": [{"file": "assets/js/module.js", "defer": true}]
    },
    "admin": {
      "css": ["assets/css/module.css"]
    }
  },
  "lifecycle": {
    "install": "install.php",
    "update": "update.php",
    "activate": "activate.php",
    "deactivate": "deactivate.php",
    "uninstall": "uninstall.php"
  }
}
```

## Main module file

```php
<?php

final class BookingManagerModule
{
    public function boot(): void
    {
        add_action('before_render', [$this, 'beforeRender']);
    }

    public function beforeRender(array $page): void
    {
        // Module startup logic.
    }
}

return new BookingManagerModule();
```

The main file may return an object with a `boot()` method or a callable. Modules can also register pages, routes, and assets programmatically:

```php
devone_register_module_admin_page(
    'booking-manager',
    'settings',
    'Booking Settings',
    __DIR__ . '/admin/settings.php',
    'manage_modules',
    'Bookings'
);

devone_register_module_route(
    'booking-manager',
    'bookings/{id}',
    ['GET', 'POST'],
    __DIR__ . '/views/booking.php',
    ['auth_required' => true, 'title' => 'Booking']
);

Authenticated or permission-protected `POST`, `PUT`, `PATCH`, and `DELETE` routes are CSRF-protected automatically. Public webhook-style routes remain compatible and may explicitly set `csrf_protected` to `false`; a public mutating browser route can opt in with `['csrf_protected' => true]`. Tokens may be supplied as `csrf_token`, `_csrf`, or the `X-CSRF-Token` header.

devone_register_module_asset(
    'booking-manager',
    'css',
    'assets/css/module.css',
    ['scope' => 'both', 'location' => 'head']
);
```

## Route handlers

A route file receives:

- `$devone_module_request`
- `$devone_module_params`
- `$devone_module_route`

Return HTML:

```php
<?php
$id = $devone_module_params['id'] ?? '';
return [
    'title' => 'Booking ' . $id,
    'content' => '<h1>Booking ' . e($id) . '</h1>'
];
```

Return JSON:

```php
<?php
return [
    'format' => 'json',
    'data' => ['ok' => true]
];
```

## Lifecycle files

Lifecycle files receive `$devone_module_context`, `$devone_module_manifest`, and `$devone_module_phase`. Return `false` or throw an exception to stop the operation.

The uninstall context includes `purge_data`. Modules should only remove persistent database data when it is true.

## Installation and activation

Use **Admin → Modules** to upload a ZIP, activate or deactivate a module, synchronize folders, or uninstall it. The manager validates package paths, blocks traversal, runs lifecycle routines, records runtime errors, and safely loads only active modules.

The command line can create a complete working starter:

```bash
php devone make:module BookingManager
```
