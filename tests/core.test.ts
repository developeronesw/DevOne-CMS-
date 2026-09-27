import assert from "node:assert/strict";
import test from "node:test";
import { DatabaseSync } from "node:sqlite";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";

import { ApiRouter, DevOneApi, fail, ok } from "../core/api/router";
import { createCoreApi } from "../core/api";
import type { CoreServices, DatabaseProvider } from "../core/api/types";
import { DevOneInstaller } from "../core/installer";
import { OfflineLicenseProvider } from "../core/license/offline";
import { DevOneLicense } from "../core/license/service";
import { DevOnePermissions } from "../core/auth/permissions";
import { DevOneAuthService, passwordHash, authErrorStatus } from "../core/auth/service";
import { LocalDatabase, LocalMedia } from "../adapters/local/providers";

function services(db: DatabaseProvider): CoreServices {
  return {
    db,
    cache: { get: async () => null, set: async () => undefined, delete: async () => undefined },
    media: { put: async () => ({ key: "", contentType: "", size: 0 }), get: async () => null, delete: async () => undefined, exists: async () => false },
    config: { runtime: "test", environment: "test", secret_key: "test-secret" },
  };
}

test("offline licensing defaults to no paid entitlement and one site", async () => {
  const license = new DevOneLicense(new OfflineLicenseProvider());
  assert.equal(await license.get(), null);
  assert.equal(await license.isFeatureEnabled("multisite"), false);
  assert.equal(await license.canCreateSite(0), true);
  assert.equal(await license.canCreateSite(1), false);
  await assert.rejects(() => license.requireFeature("network_admin"));
});

test("API router reports allowed methods", async () => {
  const router = new ApiRouter();
  router.get("/api/test", async () => ok({ ok: true }));
  const db: DatabaseProvider = {
    first: async () => null, all: async () => [], run: async () => ({ changes: 0 }), batch: async () => undefined,
  };
  const api = new DevOneApi(router, { services: services(db) });
  const response = await api.handle(new Request("http://localhost/api/test", { method: "POST", headers: { "content-type": "application/json" }, body: "{}" }));
  assert.equal(response.status, 405);
  assert.equal(response.headers.get("allow"), "GET");
});

test("installer prerequisite and draft mail test endpoints use public pre-install checks", async () => {
  const db: DatabaseProvider = {
    first: async <T>(sql: string) => sql.includes("installation_complete") ? null as T : null,
    all: async () => [],
    run: async () => ({ changes: 0 }),
    batch: async () => undefined,
  };
  let testedConfig: unknown = null;
  const api = createCoreApi({
    services: services(db),
    mailTransport: { send: async () => ({ messageId: "test" }), test: async () => ({ ok: true }) },
    mailTransportFactory: config => {
      testedConfig = config;
      return { send: async () => ({ messageId: "draft" }), test: async () => ({ ok: true }) };
    },
    installerPrerequisites: () => [
      { id: "database", label: "Database", required: true, ok: true, detail: "ready" },
      { id: "secret", label: "Secret", required: true, ok: true, detail: "ready" },
    ],
  });
  const prereq = await api.api.handle(new Request("http://localhost/api/install/prerequisites"));
  assert.equal(prereq.status, 200);
  assert.equal((await prereq.json()).ok, true);

  const response = await api.api.handle(new Request("http://localhost/api/install/test-smtp", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({
      enabled: true, host: "smtp.example.test", port: 587, encryption: "starttls",
      username: "mailer", password: "secret", fromEmail: "noreply@example.test", fromName: "DevOne",
    }),
  }));
  assert.equal(response.status, 200);
  assert.equal((await response.json()).ok, true);
  assert.deepEqual(testedConfig, {
    enabled: true, host: "smtp.example.test", port: 587, encryption: "starttls",
    username: "mailer", password: "secret", fromEmail: "noreply@example.test", fromName: "DevOne",
  });
});

test("installer can complete against a clean provider without license-server access", async () => {
  const calls: string[] = [];
  const db: DatabaseProvider = {
    first: async <T>(sql: string) => {
      if (sql.includes("installation_complete")) return null as T;
      if (sql.includes("FROM users LIMIT")) return null as T;
      if (sql.includes("FROM sites WHERE site_slug")) return { id: 1, owner_user_id: 1 } as T;
      if (sql.includes("FROM users WHERE username")) return { id: 1 } as T;
      return null;
    },
    all: async () => [],
    run: async () => ({ changes: 1 }),
    batch: async statements => { calls.push(...statements.map(s => `${s.sql} ${JSON.stringify(s.params ?? [])}`)); },
  };
  const installer = new DevOneInstaller(services(db));
  const result = await installer.install({
    deployment: "local",
    admin: { username: "admin", email: "admin@example.com", password: "correct horse battery staple", displayName: "Administrator" },
    site: { name: "Test Site", slug: "test-site", url: "https://example.test", timezone: "UTC", language: "en-US" },
    smtp: { enabled: false, host: "", port: 587, encryption: "starttls", username: "", password: "", fromEmail: "admin@example.com", fromName: "Test Site" },
  });
  assert.deepEqual(result, { siteId: 1, userId: 1, deployment: "local", smtpConfigured: false });
  assert.ok(calls.some(sql => sql.includes("INSERT INTO users")));
  assert.ok(calls.some(sql => sql.includes("installation_complete")));
  assert.equal(calls.some(sql => sql.includes("license_entitlement")), false);
});

test("permissions fail closed when stored permission JSON is malformed", async () => {
  const db: DatabaseProvider = {
    first: async <T>(sql: string) => {
      if (sql.includes("FROM roles")) return { permissions: "{bad json" } as T;
      if (sql.includes("FROM users")) return { permissions_override: "[\"sites.read\"]" } as T;
      return null;
    },
    all: async () => [], run: async () => ({ changes: 0 }), batch: async () => undefined,
  };
  const permissions = new DevOnePermissions(db);
  const user = { id: 2, username: "editor", email: "e@example.com", display_name: "Editor", role: "editor", status: "active" };
  assert.equal(await permissions.has(user, "sites.read"), true);
  assert.equal(await permissions.has(user, "sites.create"), false);
});

test("local database batches roll back atomically", async () => {
  const raw = new DatabaseSync(":memory:");
  const db = new LocalDatabase(raw);
  await db.run("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)");
  await db.batch([{ sql: "INSERT INTO items(name) VALUES(?)", params: ["one"] }]);
  await assert.rejects(() => db.batch([
    { sql: "INSERT INTO items(name) VALUES(?)", params: ["two"] },
    { sql: "INSERT INTO items(name) VALUES(?)", params: ["two"] },
  ]));
  assert.equal((await db.first<{ count: number }>("SELECT COUNT(*) AS count FROM items"))?.count, 1);
  raw.close();
});

test("local media rejects path traversal", async () => {
  const dir = await mkdtemp(join(tmpdir(), "devone-media-"));
  try {
    const media = new LocalMedia(dir);
    await assert.rejects(() => media.put("../escape.txt", "blocked"));
    await media.put("safe/file.txt", "ok");
    assert.equal(await media.exists("safe/file.txt"), true);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});


test("auth login creates a session, returns safe user data, and logout revokes it", async () => {
  const raw = new DatabaseSync(":memory:");
  const db = new LocalDatabase(raw);
  raw.exec(`
    CREATE TABLE users (
      id INTEGER PRIMARY KEY, username TEXT NOT NULL, email TEXT NOT NULL,
      display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL,
      password_hash TEXT NOT NULL, password_changed_at TEXT, updated_at TEXT
    );
    CREATE TABLE sessions (
      id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL,
      csrf_token_hash TEXT NOT NULL, expires_at TEXT NOT NULL,
      revoked_at TEXT, last_seen_at TEXT
    );
    CREATE TABLE activity_logs (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT NOT NULL);
  `);
  await db.run("INSERT INTO users(id,username,email,display_name,role,status,password_hash) VALUES(1,?,?,?,?,?,?)",
    "admin","admin@example.test","Admin","administrator","active",await passwordHash("correct horse battery staple"));
  const auth = new DevOneAuthService(db,{secureCookies:false});
  try {
    const result = await auth.login(new Request("http://localhost/api/auth/login"),"ADMIN","correct horse battery staple");
    assert.equal(result.user.username,"admin");
    assert.equal("password_hash" in result.user,false);
    assert.equal(authErrorStatus(new Error("Invalid credentials.")),401);
    const cookie = result.sessionCookie.split(";")[0];
    const authenticatedRequest = new Request("http://localhost/api/auth/me",{headers:{cookie}});
    assert.equal((await auth.getCurrentUser(authenticatedRequest))?.id,1);
    const restored = await auth.restoreSession(authenticatedRequest);
    assert.equal(restored.user?.id,1);
    assert.ok(restored.csrfToken);
    await assert.rejects(()=>auth.requireCsrf(new Request("http://localhost/api/auth/logout",{headers:{cookie,"x-devone-csrf":result.csrfToken}})));
    await assert.rejects(()=>auth.requireCsrf(new Request("http://localhost/api/auth/logout",{headers:{cookie,"x-devone-csrf":"wrong"}})));
    const logoutCookie = await auth.logout(new Request("http://localhost/api/auth/logout",{headers:{cookie,"x-devone-csrf":restored.csrfToken!}}));
    assert.match(logoutCookie,/Max-Age=0/);
    assert.equal(await auth.getCurrentUser(authenticatedRequest),null);
  } finally {
    raw.close();
  }
});

test("Phase 3 admin API routes return protected live data",async()=>{const db:DatabaseProvider={first:async<T>(sql:string)=>sql.includes("FROM sites")?{id:1,site_name:"Demo"}as T:null,all:async<T>(sql:string)=>sql.includes("FROM sites")?[{id:1,site_name:"Demo"}]as T[]:[],run:async()=>({changes:1,lastInsertId:2}),batch:async()=>undefined};const user:CoreUser={id:1,username:"admin",email:"a@example.test",display_name:"Admin",role:"administrator",status:"active"};const api=createCoreApi({services:services(db),authenticate:async()=>user,authorize:async()=>true,validateCsrf:async()=>true});for(const p of ["/api/core/sites","/api/core/content","/api/core/media","/api/core/users","/api/core/settings"]){const r=await api.api.handle(new Request("http://localhost"+p));assert.equal(r.status,200,p);assert.equal((await r.json()).ok,true,p)}});