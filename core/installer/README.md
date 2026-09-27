# DevOne Installer

The 2.0 installer is provider-independent. The same installer service runs on Local SQLite and Cloudflare D1.

## Deployment selection

The installer records one of:

- `local`
- `cloudflare`

The runtime remains responsible for the actual storage/provider. The selection is installation metadata, not a mechanism for converting a running Local process into Cloudflare infrastructure.

## Setup

The installer creates:

- administrator account
- core roles
- first site
- site membership
- home page
- site/runtime settings
- SMTP configuration
- installation completion marker

The administrator password is stored as a PBKDF2-SHA256 hash.

SMTP passwords are encrypted with the runtime's 32-byte DevOne secret key. The encrypted value is never returned by the API.

For Cloudflare, set `DEVONE_SECRET_KEY` as a Worker secret. For Local, the local runtime persists a generated key in the data directory when one is not supplied.

The installer uses the provider's atomic `batch()` operation so the installation does not intentionally leave a half-created core when a statement fails.
