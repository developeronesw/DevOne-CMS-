# DevOne Core 1.7.3 Stable — Assets Bootstrap Reliability Release

Release date: 2026-08-25

DevOne Core 1.7.3 is a focused maintenance release based directly on the official 1.7.2 Stable package. It fixes a Core bootstrap defect that could make Site Logo and User Avatar uploads report **“DevOne Assets is unavailable.”** even though the official Assets service and Asset Manager were present and healthy.

## Fixed
- Site Logo uploads now prefer the official `DevOne::assets()->upload()` service.
- User Avatar uploads now use the same official Assets service path.
- Both upload paths lazily load `core/asset-manager.php` as a compatibility fallback when the service layer has not initialized the procedural Asset Manager helpers yet.
- Admin-only request paths no longer depend on `devone_media_upload()` already being loaded before Settings/User upload helpers are called.
- Existing upload validation, allowed image types, maximum size rules, media records, folders, ownership, and public asset paths remain unchanged.

## Fresh-install identity consistency
- `core/version.php` reports 1.7.3 Stable.
- `config.sample.php` now writes `CMS_VERSION` 1.7.3 instead of carrying the stale 1.7.1 value.
- `install.php` now writes `CMS_VERSION` 1.7.3 for new installations.

## Compatibility
- Upgrade source: DevOne Core 1.7.2 Stable
- PHP: 7.4 or newer
- Database: MySQL/MariaDB
- Database migration: none
- Core Assets API changes: none; this release fixes bootstrap/use of the existing API
- Runtime Loader API changes: none
- Plugin/theme structure changes: none
- Existing configuration, content, plugins, themes, uploads, and database data are preserved by the incremental updater.
