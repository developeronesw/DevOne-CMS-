import { createServer } from "node:http";
import { createLocalRuntime } from "../adapters/local/runtime";
import { createCoreApi } from "../core/api";
import { DevOneAuthService, authErrorStatus } from "../core/auth";
import { DevOnePermissions } from "../core/auth/permissions";

const port = Number(process.env.PORT ?? 8080);
const host = process.env.HOST ?? "127.0.0.1";
const services = await createLocalRuntime({
  dataDir: process.env.DEVONE_DATA_DIR ?? "./data",
  mediaDir: process.env.DEVONE_MEDIA_DIR,
  environment: process.env.NODE_ENV ?? "development",
});
const auth = new DevOneAuthService(services.db, { secureCookies: false });
const permissions = new DevOnePermissions(services.db);
const core = createCoreApi({
  services,
  authenticate: request => auth.getCurrentUser(request),
  authorize: (user, permission, siteId) => permissions.has(user, permission, siteId),
  resolveSite: async request => {
    const value = request.headers.get("x-devone-site-id");
    return value && /^\d+$/.test(value) ? Number(value) : null;
  },
  validateCsrf: async request => {
    try { await auth.requireCsrf(request); return true; } catch { return false; }
  },
});

function json(value: unknown, status = 200, headers: HeadersInit = {}) {
  return new Response(JSON.stringify(value), {
    status,
    headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store", ...headers },
  });
}
async function readBody(request: Request): Promise<Record<string, unknown>> {
  const value = await request.json().catch(() => null);
  return value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : {};
}
async function routeAuth(request: Request, path: string): Promise<Response | null> {
  if (path === "/api/auth/login" && request.method === "POST") {
    const body = await readBody(request);
    try {
      const result = await auth.login(request, String(body.username ?? ""), String(body.password ?? ""));
      return json({ ok: true, user: result.user, csrf_token: result.csrfToken, expires_at: result.expiresAt }, 200, { "set-cookie": result.sessionCookie });
    } catch (error) {
      return json({ ok: false, error: error instanceof Error ? error.message : "Invalid credentials." }, authErrorStatus(error));
    }
  }
  if (path === "/api/auth/logout" && request.method === "POST") {
    try {
      const cookie = await auth.logout(request);
      return json({ ok: true }, 200, { "set-cookie": cookie });
    } catch (error) {
      return json({ ok: false, error: error instanceof Error ? error.message : "Authentication failed." }, authErrorStatus(error));
    }
  }
  if (path === "/api/auth/me" && request.method === "GET") {
    const user = await auth.getCurrentUser(request);
    return json({ ok: true, authenticated: Boolean(user), user });
  }
  return null;
}
async function handle(request: Request): Promise<Response> {
  const url = new URL(request.url);
  const path = url.pathname.replace(/\/+$/, "") || "/";
  const authResponse = await routeAuth(request, path);
  if (authResponse) return authResponse;
  if (path.startsWith("/api/core/")) return core.api.handle(request);
  if (path === "/api/health" && request.method === "GET") return json({ ok: true, product: "DevOne CMS", version: "2.0.0-alpha.2", runtime: "local", database: "sqlite", media: "filesystem", cache: "memory" });
  if (path === "/api/runtime/status" && request.method === "GET") {
    const migrations = await services.db.all("SELECT name, applied_at FROM devone_migrations ORDER BY name");
    return json({ ok: true, runtime: services.config.runtime, migrations });
  }
  return json({ ok: false, error: "API route not found." }, 404);
}
const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url ?? "/", "http://" + (req.headers.host ?? "localhost"));
    const chunks: Buffer[] = [];
    for await (const chunk of req) chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
    const request = new Request(url, {
      method: req.method,
      headers: Object.fromEntries(Object.entries(req.headers).flatMap(([key, value]) => value === undefined ? [] : [[key, Array.isArray(value) ? value.join(", ") : value]])),
      body: chunks.length && req.method !== "GET" && req.method !== "HEAD" ? Buffer.concat(chunks) : undefined,
    });
    const response = await handle(request);
    res.writeHead(response.status, Object.fromEntries(response.headers.entries()));
    res.end(response.body ? Buffer.from(await response.arrayBuffer()) : undefined);
  } catch (error) {
    console.error(error);
    res.writeHead(500, { "content-type": "application/json" });
    res.end(JSON.stringify({ ok: false, error: "Internal server error." }));
  }
});
server.listen(port, host, () => console.log("DevOne local runtime: http://" + host + ":" + port));
