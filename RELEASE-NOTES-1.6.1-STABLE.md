# DevOne CMS Core 1.6.1 Stable

DevOne Core 1.6.1 is a maintenance/performance release for the 1.6 stable line.

## Performance
- Core schema/table/column repair no longer performs full repair work on every frontend, admin, or API request.
- Schema repair is now performed once per Core release and recorded with a release-specific marker under `storage/cache/`.
- Explicit repair/maintenance paths remain available through `devone_repair_core_schema()` and `devone_maybe_repair_core_schema(true)`.
- Anonymous public requests no longer start a PHP session merely to determine that no user is logged in.
- Existing authenticated sessions continue to resume normally when the PHP session cookie is present.
- CSRF, admin, login, registration, notification, and other stateful flows still start sessions when required.

## Release identity
- Core release identity is 1.6.1 Stable.
- Marketplace bridge and licensing metadata now prefer the official Core release version rather than a potentially stale `CMS_VERSION` value stored in an older installation's `config.php`.

## Upgrade
- Official incremental updater supports DevOne Core 1.6.0 Stable -> 1.6.1 Stable.
- The updater verifies declared SHA-256 checksums, PHP-lints staged PHP files, backs up replaced files, and uses the Core updater rollback path on failure.
- No database tables are deleted and no user data, credentials, media, themes, plugins, modules, settings, or configuration are replaced by this update.
