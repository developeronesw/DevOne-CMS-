# Getting Started with DevOne Development

## Choose the right extension

| Type | Use it for |
|---|---|
| Theme | Front-end design and templates |
| Plugin | A focused behavior or integration |
| Module | A complete feature area with routes, admin pages, assets, and lifecycle |
| Library | Reusable compiled CSS or JavaScript |

## Official API

```php
$settings = DevOne::settings();
$assets = DevOne::assets();
$mail = DevOne::mail();
$user = DevOne::users()->current();
```

Dynamic access is also supported:

```php
$cache = devone_service('cache');
$db = devone_services()->get('db');
```

Legacy helpers remain supported, but new development should prefer Core Services.

## Minimum security pattern

```php
DevOne::auth()->requirePermission('manage_pages');
DevOne::auth()->requireCsrf();
$title = trim((string)($_POST['title'] ?? ''));
echo e($title);
```
