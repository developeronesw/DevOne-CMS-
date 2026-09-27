import type { Env, AuthUser, SessionRecord } from "../types";
import { clearSessionCookie, hashPassword, json, parseCookies, passwordNeedsRehash, PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH, randomToken, sessionCookie, sha256, validPassword, verifyPassword } from "./crypto";

const SESSION_DAYS = 7;
const RESET_MINUTES = 30;

function futureDate(days: number): string {
  return new Date(Date.now() + days * 86400_000).toISOString().replace("T"," ").replace(/\.\d{3}Z$/,"");
}
async function audit(env: Env, userId: number | null, action: string, details?: Record<string, unknown>): Promise<void> {
  try {
    await env.DB.prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?1, ?2, ?3)")
      .bind(userId, action, details ? JSON.stringify(details) : null).run();
  } catch (error) { console.error("DevOne auth audit error", error); }
}
export async function cleanupAuthData(env: Env): Promise<void> {
  await env.DB.batch([
    env.DB.prepare("DELETE FROM sessions WHERE expires_at <= datetime('now') OR revoked_at IS NOT NULL"),
    env.DB.prepare("DELETE FROM password_reset_tokens WHERE expires_at <= datetime('now') OR used_at IS NOT NULL"),
    env.DB.prepare("DELETE FROM auth_rate_limits WHERE blocked_until IS NOT NULL AND blocked_until <= datetime('now')"),
  ]);
}
export async function getSession(request: Request, env: Env): Promise<SessionRecord | null> {
  const token = parseCookies(request).devone_session;
  if (!token) return null;
  const tokenHash = await sha256(token);
  return await env.DB.prepare(
    "SELECT id, user_id, expires_at, csrf_token_hash FROM sessions WHERE token_hash = ?1 AND expires_at > datetime('now') AND revoked_at IS NULL LIMIT 1"
  ).bind(tokenHash).first<SessionRecord>() ?? null;
}
export async function getCurrentUser(request: Request, env: Env): Promise<AuthUser | null> {
  const session = await getSession(request, env);
  if (!session) return null;
  const user = await env.DB.prepare(
    "SELECT id, username, email, display_name, role, status FROM users WHERE id = ?1 AND status = 'active' LIMIT 1"
  ).bind(session.user_id).first<AuthUser>();
  if (!user) return null;
  await env.DB.prepare("UPDATE sessions SET last_seen_at = datetime('now') WHERE id = ?1").bind(session.id).run();
  return user;
}
export async function requireUser(request: Request, env: Env): Promise<{ user: AuthUser } | Response> {
  const user = await getCurrentUser(request, env);
  return user ? { user } : json({ ok:false, error:"Authentication required." },401);
}
async function loginRateKey(request: Request, username: string): Promise<string> {
  const ip = request.headers.get("cf-connecting-ip") ?? request.headers.get("x-forwarded-for") ?? "unknown";
  return sha256(`login:${ip}:${username}`);
}
async function checkLoginRateLimit(request: Request, env: Env, username: string): Promise<Response | null> {
  const key = await loginRateKey(request, username);
  const row = await env.DB.prepare("SELECT attempts, window_started_at, blocked_until FROM auth_rate_limits WHERE key = ?1 LIMIT 1")
    .bind(key).first<{attempts:number;window_started_at:string;blocked_until:string|null}>();
  const now = Date.now();
  if (row?.blocked_until && Date.parse(row.blocked_until.replace(" ","T")+"Z") > now)
    return json({ok:false,error:"Too many login attempts. Try again later."},429,{"retry-after":"900"});
  if (row && now-Date.parse(row.window_started_at.replace(" ","T")+"Z") < 15*60_000 && row.attempts >= 10) {
    await env.DB.prepare("UPDATE auth_rate_limits SET blocked_until = datetime('now','+15 minutes') WHERE key = ?1").bind(key).run();
    return json({ok:false,error:"Too many login attempts. Try again later."},429,{"retry-after":"900"});
  }
  return null;
}
async function recordLoginFailure(request: Request, env: Env, username: string): Promise<void> {
  const key = await loginRateKey(request, username);
  await env.DB.prepare(
    "INSERT INTO auth_rate_limits (key,attempts,window_started_at) VALUES (?1,1,datetime('now')) ON CONFLICT(key) DO UPDATE SET attempts=CASE WHEN (julianday('now')-julianday(window_started_at))*86400>=900 THEN 1 ELSE attempts+1 END, window_started_at=CASE WHEN (julianday('now')-julianday(window_started_at))*86400>=900 THEN datetime('now') ELSE window_started_at END"
  ).bind(key).run();
}
async function clearLoginRateLimit(request: Request, env: Env, username: string): Promise<void> {
  await env.DB.prepare("DELETE FROM auth_rate_limits WHERE key = ?1").bind(await loginRateKey(request,username)).run();
}
async function revokeAllSessionsForUser(env: Env, userId: number, exceptSessionId?: string): Promise<void> {
  if (exceptSessionId) await env.DB.prepare("UPDATE sessions SET revoked_at=datetime('now') WHERE user_id=?1 AND id!=?2 AND revoked_at IS NULL").bind(userId,exceptSessionId).run();
  else await env.DB.prepare("UPDATE sessions SET revoked_at=datetime('now') WHERE user_id=?1 AND revoked_at IS NULL").bind(userId).run();
}

export async function login(request: Request, env: Env): Promise<Response> {
  await cleanupAuthData(env);
  const body = await request.json().catch(()=>null) as {username?:string;password?:string}|null;
  const username = String(body?.username??"").trim().toLowerCase();
  const password = String(body?.password??"");
  if (!/^[a-z0-9][a-z0-9._-]{2,99}$/.test(username) || password.length<1 || password.length>PASSWORD_MAX_LENGTH)
    return json({ok:false,error:"Invalid credentials."},400);
  const limited = await checkLoginRateLimit(request,env,username);
  if (limited) return limited;
  const user = await env.DB.prepare("SELECT id,username,email,display_name,role,status,password_hash FROM users WHERE lower(username)=?1 LIMIT 1")
    .bind(username).first<AuthUser & {password_hash:string}>();
  if (!user || user.status!=="active" || !(await verifyPassword(password,user.password_hash))) {
    await recordLoginFailure(request,env,username);
    await audit(env,null,"auth.login_failed",{username});
    return json({ok:false,error:"Invalid credentials."},401);
  }
  await clearLoginRateLimit(request,env,username);
  if (passwordNeedsRehash(user.password_hash)) {
    await env.DB.prepare("UPDATE users SET password_hash=?1,password_changed_at=datetime('now'),updated_at=datetime('now') WHERE id=?2")
      .bind(await hashPassword(password),user.id).run();
  }
  const token=randomToken(32), csrfToken=randomToken(32), sessionId=crypto.randomUUID();
  const sessionExpiresAt=futureDate(SESSION_DAYS);
  await env.DB.prepare("INSERT INTO sessions (id,user_id,token_hash,csrf_token_hash,expires_at,created_at,last_seen_at) VALUES (?1,?2,?3,?4,?5,datetime('now'),datetime('now'))")
    .bind(sessionId,user.id,await sha256(token),await sha256(csrfToken),sessionExpiresAt).run();
  await audit(env,user.id,"auth.login",{session_id:sessionId});
  return json({ok:true,user:{id:user.id,username:user.username,email:user.email,display_name:user.display_name,role:user.role},csrf_token:csrfToken,expires_at:sessionExpiresAt},200,{"set-cookie":sessionCookie(token,SESSION_DAYS*86400)});
}

export async function logout(request: Request, env: Env): Promise<Response> {
  const session=await getSession(request,env);
  if (!session) return json({ok:true},200,{"set-cookie":clearSessionCookie});
  if (!(await csrfValid(request,env,session))) return json({ok:false,error:"CSRF validation failed."},403);
  await env.DB.prepare("UPDATE sessions SET revoked_at=datetime('now') WHERE id=?1").bind(session.id).run();
  await audit(env,session.user_id,"auth.logout",{session_id:session.id});
  return json({ok:true},200,{"set-cookie":clearSessionCookie});
}
export async function logoutAll(request: Request, env: Env): Promise<Response> {
  const session=await getSession(request,env);
  if (!session) return json({ok:false,error:"Authentication required."},401);
  if (!(await csrfValid(request,env,session))) return json({ok:false,error:"CSRF validation failed."},403);
  await revokeAllSessionsForUser(env,session.user_id);
  await audit(env,session.user_id,"auth.logout_all");
  return json({ok:true},200,{"set-cookie":clearSessionCookie});
}
export async function revokeMySessions(request: Request, env: Env): Promise<Response> {
  const session=await getSession(request,env);
  if (!session) return json({ok:false,error:"Authentication required."},401);
  if (!(await csrfValid(request,env,session))) return json({ok:false,error:"CSRF validation failed."},403);
  await revokeAllSessionsForUser(env,session.user_id,session.id);
  await audit(env,session.user_id,"auth.sessions_revoked");
  return json({ok:true});
}
export async function changePassword(request: Request, env: Env): Promise<Response> {
  const session=await getSession(request,env);
  if (!session) return json({ok:false,error:"Authentication required."},401);
  if (!(await csrfValid(request,env,session))) return json({ok:false,error:"CSRF validation failed."},403);
  const body=await request.json().catch(()=>null) as {current_password?:string;new_password?:string}|null;
  const currentPassword=String(body?.current_password??""), newPassword=String(body?.new_password??"");
  if (!validPassword(newPassword) || currentPassword.length>PASSWORD_MAX_LENGTH)
    return json({ok:false,error:`Password must be ${PASSWORD_MIN_LENGTH}-${PASSWORD_MAX_LENGTH} characters.`},400);
  const user=await env.DB.prepare("SELECT id,password_hash FROM users WHERE id=?1 AND status='active' LIMIT 1").bind(session.user_id).first<{id:number;password_hash:string}>();
  if (!user || !(await verifyPassword(currentPassword,user.password_hash))) {
    await audit(env,session.user_id,"auth.password_change_failed");
    return json({ok:false,error:"Current password is incorrect."},403);
  }
  if (await verifyPassword(newPassword,user.password_hash)) return json({ok:false,error:"Choose a different password."},400);
  const newCsrfToken=randomToken(32);
  await env.DB.batch([
    env.DB.prepare("UPDATE users SET password_hash=?1,password_changed_at=datetime('now'),updated_at=datetime('now') WHERE id=?2").bind(await hashPassword(newPassword),user.id),
    env.DB.prepare("UPDATE sessions SET revoked_at=datetime('now') WHERE user_id=?1 AND id!=?2").bind(user.id,session.id),
    env.DB.prepare("UPDATE sessions SET csrf_token_hash=?1,last_seen_at=datetime('now') WHERE id=?2").bind(await sha256(newCsrfToken),session.id),
  ]);
  await audit(env,user.id,"auth.password_changed");
  return json({ok:true,csrf_token:newCsrfToken});
}
export async function adminResetPassword(request: Request, env: Env): Promise<Response> {
  const auth=await requireUser(request,env);
  if (auth instanceof Response) return auth;
  if (auth.user.role!=="administrator") return json({ok:false,error:"Permission denied."},403);
  const csrf=await requireCsrf(request,env); if (csrf) return csrf;
  const body=await request.json().catch(()=>null) as {user_id?:number;new_password?:string}|null;
  const userId=Number(body?.user_id), newPassword=String(body?.new_password??"");
  if (!Number.isSafeInteger(userId) || userId<1 || !validPassword(newPassword))
    return json({ok:false,error:`Password must be ${PASSWORD_MIN_LENGTH}-${PASSWORD_MAX_LENGTH} characters and user_id must be valid.`},400);
  const exists=await env.DB.prepare("SELECT id FROM users WHERE id=?1 LIMIT 1").bind(userId).first<{id:number}>();
  if (!exists) return json({ok:false,error:"User not found."},404);
  await env.DB.batch([
    env.DB.prepare("UPDATE users SET password_hash=?1,password_changed_at=datetime('now'),updated_at=datetime('now') WHERE id=?2").bind(await hashPassword(newPassword),userId),
    env.DB.prepare("UPDATE sessions SET revoked_at=datetime('now') WHERE user_id=?1").bind(userId),
    env.DB.prepare("UPDATE password_reset_tokens SET used_at=datetime('now') WHERE user_id=?1 AND used_at IS NULL").bind(userId),
  ]);
  await audit(env,auth.user.id,"auth.admin_password_reset",{target_user_id:userId});
  return json({ok:true,sessions_revoked:true});
}
export async function requestPasswordReset(request: Request, env: Env): Promise<Response> {
  await cleanupAuthData(env);
  const body=await request.json().catch(()=>null) as {username?:string;email?:string}|null;
  const username=String(body?.username??"").trim().toLowerCase(), email=String(body?.email??"").trim().toLowerCase();
  if (username.length>100 || email.length>254) return json({ok:true,message:"If the account exists, password reset instructions will be sent."});
  const user=await env.DB.prepare("SELECT id FROM users WHERE lower(username)=?1 AND lower(email)=?2 AND status='active' LIMIT 1").bind(username,email).first<{id:number}>();
  if (user) {
    const token=randomToken(32);
    await env.DB.batch([
      env.DB.prepare("UPDATE password_reset_tokens SET used_at=datetime('now') WHERE user_id=?1 AND used_at IS NULL").bind(user.id),
      env.DB.prepare("INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?1,?2,datetime('now','+30 minutes'))").bind(user.id,await sha256(token)),
    ]);
    await audit(env,user.id,"auth.password_reset_requested");
    // Delivery is intentionally delegated to the future mail provider; never return the token to the client.
  }
  return json({ok:true,message:"If the account exists, password reset instructions will be sent."});
}
export async function completePasswordReset(request: Request, env: Env): Promise<Response> {
  await cleanupAuthData(env);
  const body=await request.json().catch(()=>null) as {token?:string;new_password?:string}|null;
  const token=String(body?.token??""), newPassword=String(body?.new_password??"");
  if (!token || !validPassword(newPassword)) return json({ok:false,error:"Invalid or expired reset request."},400);
  const row=await env.DB.prepare("SELECT id,user_id FROM password_reset_tokens WHERE token_hash=?1 AND used_at IS NULL AND expires_at>datetime('now') LIMIT 1")
    .bind(await sha256(token)).first<{id:number;user_id:number}>();
  if (!row) return json({ok:false,error:"Invalid or expired reset request."},400);
  await env.DB.batch([
    env.DB.prepare("UPDATE users SET password_hash=?1,password_changed_at=datetime('now'),updated_at=datetime('now') WHERE id=?2 AND status='active'").bind(await hashPassword(newPassword),row.user_id),
    env.DB.prepare("UPDATE sessions SET revoked_at=datetime('now') WHERE user_id=?1").bind(row.user_id),
    env.DB.prepare("UPDATE password_reset_tokens SET used_at=datetime('now') WHERE id=?1").bind(row.id),
  ]);
  await audit(env,row.user_id,"auth.password_reset_completed");
  return json({ok:true,sessions_revoked:true});
}
export async function csrfValid(request: Request, env: Env, session: SessionRecord): Promise<boolean> {
  const providedHash=await sha256(request.headers.get("x-devone-csrf")??"");
  const a=Uint8Array.from(atob(providedHash),(c)=>c.charCodeAt(0)), b=Uint8Array.from(atob(session.csrf_token_hash),(c)=>c.charCodeAt(0));
  if (a.byteLength!==b.byteLength) return !crypto.subtle.timingSafeEqual(a,a);
  return crypto.subtle.timingSafeEqual(a,b);
}
export async function requireCsrf(request: Request, env: Env): Promise<Response|null> {
  const session=await getSession(request,env);
  if (!session) return json({ok:false,error:"Authentication required."},401);
  if (!(await csrfValid(request,env,session))) return json({ok:false,error:"CSRF validation failed."},403);
  await env.DB.prepare("UPDATE sessions SET last_seen_at=datetime('now') WHERE id=?1").bind(session.id).run();
  return null;
}
