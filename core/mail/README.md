# DevOne Mail

DevOne Mail is provider-independent. Core owns message validation and the mail service contract; runtime adapters own delivery.

## Runtime transports

- **Local and self-hosted:** direct SMTP through the Local SMTP adapter. SMTP credentials are encrypted at rest and never returned by the settings API.
- **Cloudflare Workers:** native Cloudflare Email Service through the Wrangler `EMAIL` binding. No SMTP host, password, or third-party mail API token is required.

The Cloudflare Worker declares a remote `send_email` binding in `wrangler.jsonc`. Cloudflare Email Sending still requires the sending domain to be onboarded in the Cloudflare account using Cloudflare DNS. Cloudflare's onboarding flow can add the required authentication and bounce DNS records. DevOne cannot complete account-level domain onboarding without explicit Cloudflare authorization.

For Cloudflare, set the general mail enabled setting (`mail_enabled=1`, or the legacy `smtp_enabled=1`) and a valid sender address (`mail_from_email`, falling back to `smtp_from_email`). The Core Mail test endpoint validates the sender configuration and that the binding is present; it does not send a test message or prove that domain onboarding and DNS propagation are complete.

The installer UI is not part of this backend phase. The current installer fields are still SMTP-shaped; the UI phase should present Cloudflare Email as the automatic delivery option for Cloudflare deployments and show onboarding status/instructions when needed.

## Delivery behavior

The Cloudflare adapter forwards recipient, sender, subject, text, optional HTML, and optional reply-to to `EMAIL.send()`. The provider's returned message ID is passed back to Core. Sending failures are surfaced as delivery errors and must not be reported as successful.

Password reset and other account emails should call Core Mail; authentication email flows are wired in the separate authentication-lifecycle phase.
