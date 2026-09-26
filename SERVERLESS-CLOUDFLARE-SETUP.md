# DevOne CMS Serverless — Cloudflare Setup

The serverless edition is designed so a new deployment does **not** require manually creating D1, KV, R2, or Pages bindings in the Cloudflare dashboard.

## One-time bootstrap

From the repository root:

```bash
npm install
npm run cloudflare:setup
```

The bootstrap:

1. Authenticates with Cloudflare through Wrangler when needed.
2. Creates the DevOne D1 database if it does not already exist.
3. Creates the DevOne KV namespace if it does not already exist.
4. Creates the DevOne R2 media bucket if it does not already exist.
5. Writes the generated resource IDs into `wrangler.jsonc`.
6. Creates the Cloudflare Pages project if it does not already exist.

Resource names can be customized:

```bash
DEVONE_CF_PROJECT=my-client-site npm run cloudflare:setup
```

Or independently:

```bash
DEVONE_CF_D1=my-client-db \
DEVONE_CF_KV=my-client-cache \
DEVONE_CF_R2=my-client-media \
DEVONE_CF_PAGES=my-client-site \
npm run cloudflare:setup
```

## Important security model

Cloudflare resource creation is a **deployment-time operation**, not something the public CMS runtime should perform. The site itself never receives a Cloudflare API token.

Wrangler uses the installer's Cloudflare authentication to provision the resources. The resulting D1/KV/R2 identifiers are configuration, not secrets.

The application will still enforce least-privilege access through Cloudflare bindings and server-side authorization.

## Future one-click installer

A hosted DevOne installer can eventually replace the terminal step with Cloudflare OAuth. That installer would authorize the user's own Cloudflare account, create the same resources, generate the deployment configuration, and then hand off to Cloudflare deployment. No DevOne master API token should ever be embedded in the CMS.
