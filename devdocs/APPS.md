# DevOne Apps Engine — SuperAdmin / Network

DevOne Apps are route-mounted PHP applications that run beside DevOne Core.

## Scope
- **Site Apps** are assigned to the currently selected `site_id`.
- **Network Apps** use `site_id = 0` and are available to every site.
- A site-specific app route takes precedence over a network app route.
- App storage is isolated beneath `content/app-storage/site-{site_id}/`.

## Package
Every ZIP must contain `app.json` and the declared entry file. Composer dependencies should be prebuilt in the app package under `vendor/`.

```json
{
  "name": "Customer Portal",
  "slug": "customer-portal",
  "version": "1.0.0",
  "mount": "/portal",
  "entry": "public/index.php",
  "capabilities": ["auth.read", "content.read"]
}
```

The runtime exposes `$devone_app`, including `site_id`, `scope_site_id`, `network_scope`, `storage`, `root`, `mount`, `path`, and the DevOne Core service container.
