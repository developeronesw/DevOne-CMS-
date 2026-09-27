# DevOne 2.0 Installation Registration Protocol

Status: implementation contract for the Cloudflare License Server integration.

## Product rule

A fresh DevOne installation is a single-site installation by default. It does not need a paid license key to register or run. A paid Network license key is required only to enable the multisite/network feature set.

The automatically generated installation credential is an installation identity, not a license key and grants no paid features.

## Data ownership and storage

The License Server is the system of record for installation registration metadata:
- installation ID;
- canonical installation domain;
- administrator contact email;
- credential verifier (a cryptographic hash, never the raw credential);
- registration, last-seen, and de-registration timestamps;
- association to a Network license, if one is activated.

The CMS must not persist the administrator email or registered domain in its local license/registration record. These values are submitted to the server during registration and are retained there under the published privacy/retention policy. The CMS may naturally retain its own site URL and admin account email for normal site operation; those are not to be copied into a local licensing cache.

## First-install flow

1. During installation, the CMS creates a cryptographically random installation ID and a high-entropy installation secret using the platform's secure random source.
2. The CMS sends the ID, secret, canonical domain, and admin email to the configured License Server over HTTPS. The secret is sent only over TLS and is not logged.
3. The server validates the request, stores the installation metadata and a one-way credential verifier, and returns a registration receipt.
4. The CMS retains only the installation ID and the secret needed for future authenticated server checks, protected by the runtime's secret storage. It discards transient copies of domain and email after the request.
5. Registration failure must not silently enable Network features. The base single-site installation remains usable; registration can be retried later.

## Network license activation

1. An administrator submits a paid Network license key to the CMS.
2. The CMS sends the key and authenticated installation proof to the License Server over HTTPS.
3. The server validates key status, product, edition, site limit, feature set, expiry, and any server-side activation policy.
4. The server associates the license with the installation and returns a signed entitlement.
5. The CMS verifies the entitlement signature against a pinned public verification key and checks product, issue/expiry times, feature names, and site limit before caching/using it.
6. Invalid, expired, revoked, or unverifiable entitlements must fail closed to the default single-site capability. The CMS must never accept a client-provided unsigned entitlement.

## Required API behavior

The License Server implementation must provide equivalent operations for:
- register installation (idempotent by installation ID);
- authenticate/refresh installation registration;
- activate a Network license key;
- refresh/revalidate an active entitlement;
- deactivate a license without deleting the installation record;
- de-register/uninstall an installation;
- update domain/admin contact metadata after an administrator-confirmed change;
- revoke credentials and licenses.

Exact routes and signed entitlement wire format are intentionally deferred until the legacy PHP license server behavior and the cryptographic signing contract are inspected and ported. Do not invent compatibility with the old PHP service or ship a fake activation endpoint.

## Privacy and operational safeguards

- Disclose the domain and administrator email collection in the installer and privacy notice before registration.
- Send registration data only to the configured HTTPS License Server.
- Never include the raw installation secret or paid license key in logs, analytics, error messages, or public status endpoints.
- Avoid exposing stored admin email/domain in CMS diagnostics, API status, or exported configuration.
- Provide a clear uninstall/de-registration action and document what server-side records are retained after de-registration.
- Rate-limit registration and activation attempts server-side; use generic client-facing errors for credential/key failures.
- Bind registration to an installation proof, not merely a caller-supplied installation ID.
- Domain changes require authenticated update flow; do not treat an arbitrary Host header as verified ownership.

## Acceptance criteria

- A new install can complete without a paid license key and is limited to one site.
- Registration generates a distinct random installation identity and does not grant Network features.
- The server is the sole licensing record of the installation domain and admin contact.
- A valid, server-signed Network entitlement is required to unlock Network features.
- Failed registration, invalid signatures, expired licenses, and revoked licenses never unlock Network features.
- The legacy PHP license server's actual routes, data model, and key validation rules are preserved where compatible, based on source inspection—not assumptions.
