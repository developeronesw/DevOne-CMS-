# DevOne 2.0 Installation Registration Protocol

Status: implementation contract for the post-2.0.0 Cloudflare License Server integration.

## Product rule

A fresh DevOne CMS 2.0 installation is a single-site installation by default. It does not need a paid license key to register or run. A paid Network License Key is required only to enable Network/Multi-Site functionality.

The automatically generated installation credential is an installation identity, not a license key and grants no paid features.

## License binding rule

A commercial Network License Key is single-activation.

After successful activation, the License Server permanently associates the license with the authorized Installation. A subsequent attempt to activate that same key on another independent Installation must be rejected.

A Network entitlement has no per-site ceiling. Its site scope is unlimited within the one Installation to which the entitlement is bound.

## License terms

- Annual Network License: valid for the purchased annual term.
- 99-Year Network License: valid for 99 years beginning at successful activation.
- Expiration disables the licensed Network/Multi-Site capability while the base single-site CMS remains subject to the applicable product terms.
- A license is not transferable between independent Installations.
- Legitimate disaster recovery or reconstruction may use a controlled recovery process; recovery must not permit simultaneous use of one license on multiple independent Installations.

## Data ownership and storage

The future License Server is the system of record for installation registration metadata:
- installation ID;
- canonical installation domain;
- administrator contact email;
- credential verifier (a cryptographic hash, never the raw credential);
- registration, last-seen, and de-registration timestamps;
- association to a Network License, if one is activated.

The CMS must not persist administrator email or registered domain in its local licensing record solely for licensing purposes. The CMS may naturally retain its own site URL and administrator account email for normal site operation.

## First-install flow

1. During installation, the CMS creates a cryptographically random installation ID and a high-entropy installation secret.
2. The CMS sends the ID, secret, canonical domain, and admin email to the configured License Server over HTTPS.
3. The server validates the request, stores the installation metadata and one-way credential verifier, and returns a registration receipt.
4. The CMS retains only the installation ID and secret needed for future authenticated server checks, protected by the runtime's secret storage.
5. Registration failure never silently enables Network features. The base single-site installation remains usable.

## Network license activation

1. An administrator submits a paid Network License Key to the CMS.
2. The CMS sends the key and authenticated installation proof to the License Server over HTTPS.
3. The server validates the key, product, Network edition, activation state, term, and server-side security policy.
4. If the key has never been activated, the server atomically binds it to the authenticated Installation.
5. The server returns a digitally signed Network entitlement.
6. The CMS verifies the entitlement signature against a pinned public verification key and checks product, issue/expiry times, feature names, and unlimited site scope before caching/using it.
7. Any key that has already been activated for another Installation is rejected.
8. Invalid, expired, revoked, or unverifiable entitlements fail closed to the default single-site capability.

## Required future API behavior

The future License Server must provide equivalent operations for:
- register installation;
- authenticate/refresh installation registration;
- activate a Network License Key exactly once;
- refresh/revalidate an active entitlement;
- deactivate a license without silently making the key reusable;
- controlled installation recovery;
- de-register/uninstall an installation;
- update domain/admin contact metadata after an administrator-confirmed change;
- revoke credentials and licenses.

Exact routes and signed entitlement wire format remain deferred until the legacy PHP licensing implementation and cryptographic contract are inspected and ported. DevOne CMS 2.0 must not ship a fake activation endpoint.

## Privacy and operational safeguards

- Disclose domain and administrator email collection before registration.
- Send registration data only to the configured HTTPS License Server.
- Never log the raw installation secret or paid License Key.
- Rate-limit registration and activation attempts server-side.
- Use generic client-facing errors for credential/key failures.
- Bind registration and activation to authenticated Installation proof, not merely a caller-supplied Installation ID.
- Domain changes require an authenticated update flow.
