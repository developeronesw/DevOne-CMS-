# DevOne Local Runtime

The local runtime is provider-independent. It uses SQLite for persistence, the local filesystem for media, and an in-process TTL cache.

## Start

`npm install`

`npm run local`

Default: `http://127.0.0.1:8080`

Set `HOST=0.0.0.0` to expose the runtime to a trusted LAN. Set `DEVONE_DATA_DIR` and `DEVONE_MEDIA_DIR` to choose storage locations.

The runtime automatically applies ordered SQL migrations and records them in `devone_migrations`. Migration execution is transactional.

Node.js 22.5+ is required for the built-in `node:sqlite` API; Node.js 24 LTS is recommended.

The local provider deliberately contains no Cloudflare API dependency.
