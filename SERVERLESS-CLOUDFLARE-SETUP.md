# DevOne CMS Serverless — Cloudflare Setup

## Zero-manual-resource deployment

The serverless edition is designed for Cloudflare Workers + Static Assets rather than the older Pages-only deployment model.

**You do not manually create or bind D1, KV, or R2.**

The Wrangler configuration intentionally declares the bindings without resource IDs/names. Current Wrangler supports automatic provisioning for D1, KV, and R2: when the Worker is deployed, Cloudflare creates the missing resources and attaches them to the Worker.

### Install/deploy

From the repository root:

```bash
npm install
npx wrangler login
npm run cloudflare:deploy
```

That's the entire Cloudflare setup flow.

On first deployment Wrangler provisions:

- **D1** → `DB`
- **KV** → `CACHE`
- **R2** → `MEDIA`
- **Worker + static assets** → one deployment

Static React/Vite output is deployed with the Worker as Cloudflare Static Assets. This is the current recommended architecture for new full-stack Cloudflare applications.

### Why this is better than a setup script

We originally considered a script that explicitly called:

```text
wrangler d1 create
wrangler kv namespace create
wrangler r2 bucket create
```

That works, but it creates unnecessary installer state and requires us to parse resource IDs.

Wrangler now has native automatic provisioning. The repository can simply declare the bindings and let Cloudflare create the resources during deployment.

This also means a customer can deploy the repository from a clean machine without first opening the Cloudflare dashboard to create infrastructure.

### Authentication

The installer/deployer authenticates **the customer's own Cloudflare account**. DevOne never needs a master Cloudflare API token embedded in the CMS.

For CI/CD, a customer can instead provide a scoped Cloudflare API token through the CI provider's secret store.

### Future browser installer

We can later add a DevOne hosted "Deploy to Cloudflare" flow using Cloudflare OAuth. That can provide the same zero-dashboard experience from a browser while still provisioning resources inside the customer's Cloudflare account.

## Resource ownership

The resources belong to the Cloudflare account that deploys the Worker. DevOne does not take custody of:

- database data
- uploaded media
- cache data
- Cloudflare credentials

This is important for the self-hosted/serverless edition.
