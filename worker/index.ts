import type { Env } from "./types";
import { hashPassword, json } from "./lib/crypto";
import { getCurrentUser, login, logout, requireCsrf, requireUser } from "./lib/auth";
import { isInstalled, setSetting, setting } from "./lib/db";
import { createCoreApi } from "../core/api";
import { DevOnePermissions } from "../core/auth/permissions";
import { createCloudflareServices } from "../adapters/cloudflare/providers";

function securityHeaders(): HeadersInit {
  return {
    "x-content-type-options": "nosniff",
    "x-frame-options": "SAMEORIGIN",
    "referrer-policy": "strict-origin-when-cross-origin",
    "permissions-policy": "camera=(), microphone=(), geolocation=()",
  };
}

async function install(request: Request, env: Env): Promise<Response> {
  if (await isInstalled(env)) return json({ ok: false, error: "DevOne is already installed." }, 409);

  const body = await request.json().catch(() => null) as {
    site_name?: string;
    site_slug?: string;
    username?: string;
    email?: string;
    password?: string;
  } | null;

  const siteName = String(body?.site_name ?? "").trim().slice(0, 190);
  const siteSlug = String(body?.site_slug ?? siteName).trim().toLowerCase().replace(/[^a-z0-9-]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 190);
  const username = String(body?.username ?? "").trim().toLowerCase();
  const email = String(body?.email ?? "").trim().toLowerCase();
  const password = String(body?.password ?? "");

  if (siteName.length < 2 || !siteSlug || !/^[a-z0-9][a-z0-9._-]{2,99}$/.test(username) ||
      !/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(email) || password.length < 12 || password.length > 256) {
    return json({ ok: false, error: "Invalid installation details. Use a site name, valid account, and password of at least 12 characters." }, 400);
  }

  const existing = await env.DB.prepare("SELECT id FROM users LIMIT 1").first<{ id: number }>();
  if (existing) return json({ ok: false, error: "An administrator already exists." }, 409);

  const passwordHash = await hashPassword(password);
  const adminPermissions = JSON.stringify(["*"]);
  const roles = [
    ["administrator", "Full DevOne administration", adminPermissions],
    ["editor", "Manage site content", JSON.stringify(["content.*", "media.*"])],
    ["author", "Create and manage authored content", JSON.stringify(["content.create", "content.read", "content.update", "media.create"])],
    ["subscriber", "Basic authenticated account", JSON.stringify([])],
    ["site_admin", "Manage an assigned site", JSON.stringify(["site.*", "content.*", "media.*"])],
  ];

  const statements = [
    env.DB.prepare("INSERT INTO roles (name, description, permissions) VALUES (?1, ?2, ?3)").bind(...roles[0]),
    env.DB.prepare("INSERT INTO roles (name, description, permissions) VALUES (?1, ?2, ?3)").bind(...roles[1]),
    env.DB.prepare("INSERT INTO roles (name, description, permissions) VALUES (?1, ?2, ?3)").bind(...roles[2]),
    env.DB.prepare("INSERT INTO roles (name, description, permissions) VALUES (?1, ?2, ?3)").bind(...roles[3]),
    env.DB.prepare("INSERT INTO roles (name, description, permissions) VALUES (?1, ?2, ?3)").bind(...roles[4]),
    env.DB.prepare("INSERT INTO users (username, password_hash, email, display_name, role, status) VALUES (?1, ?2, ?3, ?4, 'administrator', 'active')").bind(username, passwordHash, email, username),
    env.DB.prepare("INSERT INTO sites (site_name, site_slug, owner_user_id, admin_username, admin_email, status) VALUES (?1, ?2, last_insert_rowid(), ?3, ?4, 'active')").bind(siteName, siteSlug, username, email),
    env.DB.prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_name', ?1)").bind(siteName),
    env.DB.prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_title', ?1)").bind(siteName),
    env.DB.prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_tagline', 'Build. Manage. Evolve.')"),
    env.DB.prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('cms_version', '2.0.0-alpha.2')"),
    env.DB.prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_theme', 'devone-glass')"),
    env.DB.prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('installation_complete', '1')"),
  ];

  const result = await env.DB.batch(statements);
  if (!result.length) return json({ ok: false, error: "Installation could not be completed." }, 500);

  const site = await env.DB.prepare("SELECT id, owner_user_id FROM sites WHERE site_slug = ?1 LIMIT 1").bind(siteSlug).first<{ id: number; owner_user_id: number }>();
  if (!site) return json({ ok: false, error: "Installation completed without a site record." }, 500);

  await env.DB.batch([
    env.DB.prepare("INSERT INTO site_users (site_id, user_id, role, status) VALUES (?1, ?2, 'site_admin', 'active')").bind(site.id, site.owner_user_id),
    env.DB.prepare("INSERT INTO pages (site_id, slug, title, content, template, show_title, status, author_id) VALUES (?1, 'home', ?2, ?3, 'default', 0, 'published', ?4)").bind(site.id, siteName, "<h1>Welcome to DevOne CMS 2.0</h1><p>Performance, Security, Design.</p>", site.owner_user_id),
  ]);

  return json({ ok: true, installed: true, site_id: site.id, message: "DevOne CMS 2.0 foundation installed." }, 201);
}

async function api(request: Request, env: Env): Promise<Response> {
  const url = new URL(request.url);
  const path = url.pathname.replace(/\\/+$/, "") || "/";

  if (path.startsWith("/api/core/")) {
    const services = createCloudflareServices(env);
    const permissions = new DevOnePermissions(services.db);
    const core = createCoreApi({
      services,
      authenticate: async (req) => await getCurrentUser(req, env),
      authorize: async (user, permission, siteId) => await permissions.has(user, permission, siteId),
      resolveSite: async (req) => {
        const value = req.headers.get("x-devone-site-id");
        if (!value || !/^\\d+$/.test(value)) return null;
        return Number(value);
      },
    });
    return core.api.handle(request);
  }

  if (path === "/api/health" && request.method === "GET") {
    const installed = await isInstalled(env);
    return json({ ok: true, product: "DevOne CMS", version: "2.0.0-alpha.2", installed, runtime: "cloudflare-workers" });
  }

  if (path === "/api/system/status" && request.method === "GET") {
    const installed = await isInstalled(env);
    return json({ ok: true, installed, version: await setting(env, "cms_version", "2.0.0-alpha.2") });
  }

  if (path === "/api/install" && request.method === "POST") return install(request, env);
  if (path === "/api/auth/login" && request.method === "POST") return login(request, env);
  if (path === "/api/auth/logout" && request.method === "POST") return logout(request, env);

  if (path === "/api/auth/me" && request.method === "GET") {
    const user = await getCurrentUser(request, env);
    return json({ ok: true, authenticated: Boolean(user), user });
  }

  if (path === "/api/settings" && request.method === "GET") {
    const auth = await requireUser(request, env);
    if (auth instanceof Response) return auth;
    const rows = await env.DB.prepare("SELECT setting_key, setting_value FROM settings ORDER BY setting_key").all();
    return json({ ok: true, settings: rows.results });
  }

  if (path === "/api/settings" && ["POST", "PUT", "PATCH"].includes(request.method)) {
    const csrf = await requireCsrf(request, env);
    if (csrf) return csrf;
    const auth = await requireUser(request, env);
    if (auth instanceof Response) return auth;
    if (auth.user.role !== "administrator") return json({ ok: false, error: "Permission denied." }, 403);

    const body = await request.json().catch(() => null) as { key?: string; value?: string } | null;
    const key = String(body?.key ?? "").trim();
    if (!/^[a-zA-Z0-9_.-]{1,120}$/.test(key)) return json({ ok: false, error: "Invalid setting key." }, 400);
    await setSetting(env, key, String(body?.value ?? ""));
    return json({ ok: true });
  }

  return json({ ok: false, error: "API route not found." }, 404);
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    try {
      const url = new URL(request.url);
      if (url.pathname.startsWith("/api/")) {
        const response = await api(request, env);
        const headers = new Headers(response.headers);
        for (const [key, value] of Object.entries(securityHeaders())) headers.set(key, value);
        return new Response(response.body, { status: response.status, headers });
      }
      return env.ASSETS.fetch(request);
    } catch (error) {
      console.error("DevOne Worker error", error);
      return json({ ok: false, error: "Internal server error." }, 500, securityHeaders());
    }
  },
};
