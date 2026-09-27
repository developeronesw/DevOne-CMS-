# DevOne CMS 2.0 — Product, License & UI Brief

Status: working brief for implementation planning; not final legal terms.
Branch: `serverless-2.0`

## 1. Product direction

DevOne CMS 2.0 is a provider-independent CMS foundation with two first-run deployment paths:
- **Local:** run on a machine or server the operator controls.
- **Cloudflare:** deploy using Cloudflare services.

The installer should make the deployment choice clear before presenting environment-specific setup. A fresh single-site install should remain usable without a paid license key or successful optional license registration.

## 2. License and service decisions (not yet final)

### Current repository baseline
The current README describes the 1.7.4 core as GPL v3 or later, and the repository includes GPL license text. Before publishing 2.0 license terms, audit the provenance of every retained/copied component and its notices. Do not replace or contradict existing license grants without confirming the applicable rights and obtaining legal review.

### Proposed product separation for review
- **Core software license:** governs rights to install, use, modify, and redistribute the CMS code. Final license choice is blocked on code provenance and counsel review.
- **Network entitlement:** a separate product entitlement for multisite/network features. The default single-site capability is not a paid license.
- **Hosted/online services:** separate service terms and privacy notice where applicable; do not conflate service access with copyright license rights.

### Network entitlement scope currently represented in code
`multisite`, `network_admin`, `network_users`, `network_domains`, and `network_extensions`. Confirm product names, limits, renewal/expiry behavior, support obligations, refund/cancellation terms, and offline grace behavior before publishing commercial terms.

### Registration privacy contract already recorded
The License Server is intended to be the system of record for installation ID, canonical domain, admin contact email, credential verifier, registration/last-seen/de-registration timestamps, and optional Network license association. The CMS must not copy the registration domain or admin email into a local licensing record. The installer must disclose this collection before sending data; registration failure must not block base single-site setup. See `core/license/REGISTRATION-PROTOCOL.md`.

### Legal review checklist
- Trace source ownership and license obligations for retained 1.7.4 code, assets, and third-party packages.
- Confirm whether 2.0 is a continuation/derivative, a clean rewrite, or a mixed distribution; document the basis.
- Confirm copyright holder/legal entity name and contact address for notices.
- Review warranty disclaimer, limitation of liability, governing law, termination, redistribution rights, and consumer-law requirements.
- Draft distinct privacy/service terms for any registration data and hosted service.
- Do not implement key validation, signed entitlements, or compatibility claims until the legacy PHP license server and signing contract have been inspected.

## 3. UI visual direction

Use the supplied installer reference as the style target:
- Light warm-white/soft-gray canvas with a subtle, low-contrast grid.
- White elevated panel, rounded corners, thin neutral borders, soft shadows.
- Restrained forest/emerald green for status and primary actions; muted gray for supporting copy.
- Spacious typography, clear hierarchy, calm concise copy.
- Responsive layout and visible keyboard focus; respect reduced-motion preferences.
- No dark neon purple/orange styling from 1.7.4 in the 2.0 installer.
- The symbol/wordmark shown in the mockup is a placeholder and must not be reused as official branding. Use a neutral text treatment until approved brand assets are supplied.

## 4. First-run screen map

1. **Environment choice:** Install locally / Deploy with Cloudflare.
2. **Environment preflight:** runtime, storage, and prerequisites specific to the selected path.
3. **Site and administrator:** site details, admin account, timezone/language.
4. **Optional email:** SMTP configuration and test, with skip path.
5. **Privacy and registration disclosure:** explain optional License Server registration and data sent before any request.
6. **Review and install:** validate, confirm, run installer, report progress and recoverable errors.
7. **Complete:** confirm successful install and direct the administrator to sign in.

The current UI change implements the first choice screen and a local confirmation state only. It does not yet submit installer data or imply that deployment/setup has completed.

## 5. Delivery order

- Pair A (now): product/license decision brief + light design foundation and environment-choice screen.
- Pair B: installer preflight and step flow + wire those screens to the existing installer API.
- Pair C: auth/session API completion + sign-in and account UI.
- Pair D: admin dashboard shell + settings and responsive/accessibility polish.
- Pair E: cross-runtime acceptance, security/release checks, and legal/notice review.
