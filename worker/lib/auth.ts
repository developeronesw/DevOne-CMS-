import type { Env, AuthUser, SessionRecord } from "../types";
import { clearSessionCookie, json, parseCookies, randomToken, sessionCookie, sha256, verifyPassword } from "./crypto";

const SESSION_DAYS = 7;

export async function getSession(request: Request, env: Env): Promise<SessionRecord | null> {
  const token = parseCookies(request).devone_session;
  if (!token) return null;

  const tokenHash = await sha256(token);
  const row = await env.DB.prepare(
    "SELECT id, user_id, expires_at, csrf_token_hash FROM sessions WHERE token_hash = ?1 AND expires_at > datetime('now') LIMIT 1",
  ).bind(tokenHash).first<SessionRecord>();
  return row ?? null;
}

export async function getCurrentUser(request: Request, env: Env): Promise<AuthUser | null> {
  const session = await getSession(request, env);
  if (!session) return null;
  return await env.DB.prepare(
    "SELECT id, username, email, display_name, role, status FROM users WHERE id = ?1 AND status = 'active' LIMIT 1",
  ).bind(session.user_id).first<AuthUser>() ?? null;
}

export async function requireUser(request: Request, env: Env): Promise<{ user: AuthUser } | Response> {
  const user = await getCurrentUser(request, env);
  return user ? { user } : json({ ok: false, error: "Authentication required." }, 401);
}

async function loginRateKey(request: Request, username: string): Promise<string> {
  const ip = request.headers.get("cf-connecting-ip") ?? request.headers.get("x-forwarded-for") ?? "unknown";
  return sha256(`login:${ip}:${username}`);
}

async function checkLoginRateLimit(request: Request, env: Env, username: string): Promise<Response | null> {
  const key = await loginRateKey(request, username);
  const row = await env.DB.prepare(
    "SELECT attempts, window_started_at, blocked_until FROM auth_rate_limits WHERE key = ?1 LIMIT 1",
  ).bind(key).first<{ attempts: number; window_started_at: string; blocked_until: string | null }>();

  const now = Date.now();
  if (row?.blocked_until && Date.parse(row.blocked_until.replace(" ", "T") + "Z") > now) {
    return json({ ok: false, error: "Too many login attempts. Try again later." }, 429, { "retry-after": "900" });
  }

  if (row && now - Date.parse(row.window_started_at.replace(" ", "T") + "Z") < 15 * 60_000 && row.attempts >= 10) {
    await env.DB.prepare(
      "UPDATE auth_rate_limits SET blocked_until = datetime('now', '+15 minutes') WHERE key = ?1",
    ).bind(key).run();
    return json({ ok: false, error: "Too many login attempts. Try again later." }, 429, { "retry-after": "900" });
  }

  return null;
}

async function recordLoginFailure(request: Request, env: Env, username: string): Promise<void> {
  const key = await loginRateKey(request, username);
  await env.DB.prepare(
    "INSERT INTO auth_rate_limits (key, attempts, window_started_at) VALUES (?1, 1, datetime('now')) " +
    "ON CONFLICT(key) DO UPDATE SET attempts = CASE " +
    "WHEN (julianday('now') - julianday(window_started_at)) * 86400 >= 900 THEN 1 ELSE attempts + 1 END, " +
    "window_started_at = CASE " +
    "WHEN (julianday('now') - julianday(window_started_at)) * 86400 >= 900 THEN datetime('now') ELSE window_started_at END",
  ).bind(key).run();
}

async function clearLoginRateLimit(request: Request, env: Env, username: string): Promise<void> {
  await env.DB.prepare("DELETE FROM auth_rate_limits WHERE key = ?1").bind(await loginRateKey(request, username)).run();
}

export async function login(request: Request, env: Env): Promise<Response> {
  const body = await request.json().catch(() => null) as { username?: string; password?: string } | null;
  const username = String(body?.username ?? "").trim().toLowerCase();
  const password = String(body?.password ?? "");

  if (!/^[a-z0-9][a-z0-9._-]{2,99}$/.test(username) || password.length < 1 || password.length > 256) {
    return json({ ok: false, error: "Invalid credentials." }, 400);
  }

  const limited = await checkLoginRateLimit(request, env, username);
  if (limited) return limited;

  const user = await env.DB.prepare(
    "SELECT id, username, email, display_name, role, status, password_hash FROM users WHERE lower(username) = ?1 LIMIT 1",
  ).bind(username).first<AuthUser & { password_hash: string }>();

  if (!user || user.status !== "active" || !(await verifyPassword(password, user.password_hash))) {
    await recordLoginFailure(request, env, username);
    return json({ ok: false, error: "Invalid credentials." }, 401);
  }

  await clearLoginRateLimit(request, env, username);
  const token = randomToken(32);
  const csrfToken = randomToken(32);
  const tokenHash = await sha256(token);
  const csrfHash = await sha256(csrfToken);
  const sessionId = crypto.randomUUID();
  const expiresAt = new Date(Date.now() + SESSION_DAYS * 86400_000).toISOString().replace("T", " ").replace(/\\.\\d{3}Z$/, "");

  await env.DB.prepare(
    "INSERT INTO sessions (id, user_id, token_hash, csrf_token_hash, expires_at, created_at, last_seen_at) VALUES (?1, ?2, ?3, ?4, ?5, datetime('now'), datetime('now'))",
  ).bind(sessionId, user.id, tokenHash, csrfHash, expiresAt).run();

  return json(
    {
      ok: true,
      user: {
        id: user.id,
        username: user.username,
        email: user.email,
        display_name: user.display_name,
        role: user.role,
      },
      csrf_token: csrfToken,
      expires_at: expiresAt,
    },
    200,
    { "set-cookie": sessionCookie(token, SESSION_DAYS * 86400) },
  );
}

export async function logout(request: Request, env: Env): Promise<Response> {
  const session = await getSession(request, env);
  if (!session) return json({ ok: true }, 200, { "set-cookie": clearSessionCookie });
  if (!(await csrfValid(request, env, session))) {
    return json({ ok: false, error: "CSRF validation failed." }, 403);
  }
  const token = parseCookies(request).devone_session;
  if (token) await env.DB.prepare("DELETE FROM sessions WHERE token_hash = ?1").bind(await sha256(token)).run();
  return json({ ok: true }, 200, { "set-cookie": clearSessionCookie });
}

export async function csrfValid(request: Request, env: Env, session: SessionRecord): Promise<boolean> {
  const provided = request.headers.get("x-devone-csrf") ?? "";
  if (!provided) return false;
  const providedHash = await sha256(provided);
  return crypto.subtle.timingSafeEqual(
    Uint8Array.from(atob(providedHash), (c) => c.charCodeAt(0)),
    Uint8Array.from(atob(session.csrf_token_hash), (c) => c.charCodeAt(0)),
  );
}

export async function requireCsrf(request: Request, env: Env): Promise<Response | null> {
  const session = await getSession(request, env);
  if (!session) return json({ ok: false, error: "Authentication required." }, 401);
  if (!(await csrfValid(request, env, session))) {
    return json({ ok: false, error: "CSRF validation failed." }, 403);
  }
  await env.DB.prepare("UPDATE sessions SET last_seen_at = datetime('now') WHERE id = ?1").bind(session.id).run();
  return null;
}
