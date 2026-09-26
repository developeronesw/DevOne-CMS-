# DevOne Core 1.7.4 Stable — Market Hardening & Compatibility Release

Release date: 2026-08-25

DevOne Core 1.7.4 is the production-hardening release built from the 1.7.3 Assets bootstrap correction. It keeps the established DevOne extension surface compatible while closing release-blocking authorization, upload, session, registration, update-package, ZIP extraction, API, installer, and storage-boundary gaps found during a full Core review.

## Compatibility first
- DevOne Runtime and Core Services remain additive; legacy procedural helpers are not removed merely because a modern service method exists.
- Established helper/function/class/method signatures from the 1.7.2 and 1.7.3 Stable baselines remain callable.
- `devone_media_upload()` remains available as the legacy Assets upload helper while the official `DevOne::assets()` service remains canonical.
- `devone_ensure_role_exists()` remains available for older integrations, but its historical privilege-overwrite behavior is replaced with a safe zero-permission registration-role façade.
- Existing plugin/theme folder layouts, hooks, shortcodes, media records, settings, and content paths remain supported.
- Existing marketplace packages that predate modern checksum metadata retain a compatibility installation path; official Core updates require cryptographic SHA-256 metadata.

## Assets and upload safety
- Site Logo and User Avatar uploads use the official Assets service and retain lazy procedural Asset Manager fallback compatibility.
- Admin Media no longer maintains a parallel direct-upload implementation; uploads route through the canonical Asset Manager.
- Upload validation uses the real temporary-file size and server-detected MIME data.
- Image uploads are decoded/validated before acceptance.
- Executable extensions and new SVG uploads are excluded from the canonical writable media allowlist.
- Asset update/usage/duplicate API actions enforce CSRF and media ownership/permission boundaries.
- Network-site and main-site media paths use canonical containment checks for file operations and deletion.

## Authentication, registration, and authorization
- Successful login regenerates the PHP session ID and rehashes passwords when the platform algorithm changes.
- Login errors no longer disclose disabled-account state and sign-in attempts are rate-limited by remote address across username rotation.
- Session cookies use strict-mode, cookie-only, HttpOnly, SameSite=Lax, and Secure on HTTPS requests.
- Public registration is disabled by default on fresh installations.
- Public registration accepts only zero-permission roles; the stock subscriber role is low privilege by default.
- The untouched historical stock subscriber role is safely migrated without rewriting administrator-customized roles.
- User passwords are never included in registration or administrator-created-account email messages.
- Privileged admin controllers enforce explicit route-level permissions in addition to authentication and CSRF checks.
- Site switching is POST-only, CSRF-protected, and limited to sites the current user may access.
- Network site presentation settings use the existing `site_settings` overlay with global fallback, so legacy `get_setting()` / `set_setting()` calls remain compatible without leaking site theme/name/menu/front-end settings across sites.
- Network client Site Admins may activate trusted installed/entitled themes but cannot upload executable PHP theme packages; package installation remains Network Super Admin-only.

## Core update and package integrity
- Official Core package downloads require HTTPS and a valid package SHA-256 supplied by release metadata.
- Core update manifests require a valid target version, compatible source version, and SHA-256 for every payload file.
- Redirects from the official updater are restricted to HTTPS.
- ZIP scanning rejects traversal, absolute paths, drive-letter paths, symbolic links, excessive file counts, oversized expansion, per-file size violations, and dangerous compression ratios.
- The package scanner retains a bounded pure-PHP central-directory fallback so validation does not silently disappear when `ZipArchive` is unavailable.
- Staged PHP files are syntax-checked before installation and existing rollback/backups remain intact.
- Application package extraction uses the canonical safe ZIP extraction path.

## API and output hardening
- API secret-bearing fields are stripped from readable and writable field sets even when explicitly configured by an endpoint definition.
- Public Pages API reads force published-only content while authenticated API keys retain configured access.
- API GET fails closed when an endpoint has no safe readable fields instead of falling back to `SELECT *`.
- Public-write idempotency state is caller-scoped to avoid cross-client response replay/collision.
- Script-embedded JSON uses safe hex escaping where user or remote-controlled content can enter an HTML script context.
- Marketplace detail rendering uses DOM text/attribute APIs and safe URL handling instead of injecting remote strings as HTML.

## Installer and fresh-install safety
- Installer and generated `CMS_VERSION` identity are aligned to 1.7.4.
- Suggested site URLs validate the server name/host and respect HTTPS proxy context.
- Installer sessions apply hardened cookie settings before session start.
- Fresh public-registration defaults are closed and the subscriber role has no implicit administration/media permissions.
- Fresh width-mode defaults use valid Core values.
- Schema repair no longer seeds unbundled Ocean/Purple theme records; only the two shipped DevOne themes are registered by default.

## Storage and web-server boundary
- New local backups prefer protected storage outside the public document root when the hosting layout permits; existing legacy backup locations remain discoverable/restorable.
- Backup archives use randomized names, skip symbolic links, validate restore containment, and clean temporary restore extraction directories.
- `storage/.htaccess` denies direct Apache access; writable media/site folders deny executable script extensions while allowing normal static media.
- NGINX deployment requirements are documented because NGINX does not process `.htaccess`; DevOne Server should enforce equivalent protected-storage and non-executable-media rules.

## Runtime and schema performance
- Component schema verification markers prevent repeated normal-request DDL checks for users, media, API, network, Pages Admin, and Assets usage structures.
- Core's existing once-per-release schema verification model remains in place.
- Asset usage is first-class in the Core schema while the legacy lazy helper still creates/verifies it when required by an upgraded older installation.
- Security/rate state is pruned opportunistically without adding remote calls or recurring schema scans to ordinary requests.

## Database changes
- Non-destructive addition/verification of the Core `asset_usage` table.
- Non-destructive hardening of the untouched historical stock subscriber role when it exactly matches the old built-in defaults.
- No destructive reset, table drop, content migration, or user-data deletion.

## Supported upgrade sources
- DevOne Core 1.7.2 Stable → official 1.7.4 incremental package.
- DevOne Core 1.7.3 Stable → official 1.7.4 incremental package.
- PHP 7.4 or newer.
- MySQL/MariaDB.

## Release qualification
The final release package is validated after packaging for PHP/JavaScript/JSON syntax, release-manifest and checksum integrity, legacy callable compatibility, malicious ZIP fixtures, authentication/CSRF/rate-limit harnesses, API secret-field handling, package hygiene, and Network-site isolation.
