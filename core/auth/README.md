# DevOne Core Authentication

DevOne CMS 2.0 uses server-side sessions with opaque random cookies. Only SHA-256 hashes of session and CSRF tokens are stored in the database.

## Lifecycle

- Sessions expire after seven days and can be explicitly revoked.
- Password changes revoke every other active session and rotate the current CSRF token.
- Disabling a user revokes all sessions and invalidates outstanding reset tokens.
- Successful login upgrades an older supported PBKDF2-SHA256 password hash to the current iteration count.
- Password reset tokens are random, hashed before storage, single-use, and expire after 30 minutes.
- Reset requests use a generic response so account existence is not disclosed through the API response.
- Password-reset delivery is intentionally an adapter concern; the Worker never returns a reset token to the browser.
- Authentication events are written to `activity_logs` without storing passwords or raw session tokens.
- Expired/revoked sessions and used/expired reset tokens are cleaned during authentication operations.

## Passwords

The current Worker format is:

`pbkdf2$sha256$310000$<salt>$<derived-key>`

New passwords must be 12-256 characters. Verification uses Web Crypto PBKDF2 and constant-time comparison.

## Endpoints

- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/logout-all`
- `POST /api/auth/sessions/revoke`
- `POST /api/auth/password/change`
- `POST /api/auth/password/reset/request`
- `POST /api/auth/password/reset/complete`
- `POST /api/auth/password/reset/admin`

State-changing authenticated endpoints require the `x-devone-csrf` token issued at login or password change.

The production mail provider will later consume the reset-token workflow without moving secrets or password-reset tokens into client-side code.
