# DevOne Core 2.0

DevOne Core is the provider-independent application layer for DevOne CMS 2.0.

## Deployment modes

1. Local — Android/Termux, desktop development, or offline testing.
2. Self-hosted — home server, mini PC, NAS, VPS, or other supported host.
3. Cloudflare — Workers + D1 + KV + R2.

Core code must not depend directly on a hosting provider. Database, cache, and media access are exposed through provider interfaces.

## Extension boundary

Themes, plugins, and apps integrate through public DevOne APIs. They do not modify Core source files.

## API

The 2.0 Core API is defined in core/api:

- types.ts — provider and request/response contracts.
- router.ts — provider-neutral HTTP routing, authentication and authorization boundary.
- index.ts — Core API bootstrap and system endpoints.

Cloudflare, self-hosted, and local runtimes adapt their infrastructure to these interfaces.
