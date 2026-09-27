import assert from "node:assert/strict";
import test from "node:test";
import { spawn, type ChildProcess } from "node:child_process";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";

async function waitForServer(url: string, child: ChildProcess): Promise<void> {
  const deadline = Date.now() + 15_000;
  while (Date.now() < deadline) {
    if (child.exitCode !== null) throw new Error("Local runtime exited before becoming ready.");
    try {
      if ((await fetch(url + "/api/health")).ok) return;
    } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error("Timed out waiting for local runtime.");
}

function cookiePair(setCookie: string | null): string {
  assert.ok(setCookie, "Expected a Set-Cookie header.");
  return setCookie.split(";")[0];
}

test("local runtime auth flow survives session restore and logout", async () => {
  const dataDir = await mkdtemp(join(tmpdir(), "devone-runtime-auth-"));
  const port = 18_000 + Math.floor(Math.random() * 2_000);
  const baseUrl = "http://127.0.0.1:" + port;
  const child = spawn(process.execPath, ["--import", "tsx", "runtime/local-server.ts"], {
    cwd: process.cwd(),
    env: { ...process.env, PORT: String(port), HOST: "127.0.0.1", DEVONE_DATA_DIR: dataDir, NODE_ENV: "test" },
    stdio: ["ignore", "pipe", "pipe"],
  });

  let cookie = "";
  try {
    await waitForServer(baseUrl, child);

    const initial = await fetch(baseUrl + "/api/install/status");
    assert.equal(initial.status, 200);
    assert.equal((await initial.json()).installed, false);

    const install = await fetch(baseUrl + "/api/install", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        deployment: "local",
        admin: { username: "admin", email: "admin@example.test", displayName: "Administrator", password: "correct horse battery staple" },
        site: { name: "Runtime Test Site", slug: "runtime-test-site", url: "https://runtime.example.test", timezone: "UTC", language: "en-US" },
        smtp: { enabled: false, host: "", port: 587, encryption: "starttls", username: "", password: "", fromEmail: "admin@example.test", fromName: "Runtime Test Site" },
      }),
    });
    assert.equal(install.status, 201, await install.text());

    const installed = await fetch(baseUrl + "/api/install/status");
    assert.equal((await installed.json()).installed, true);

    const login = await fetch(baseUrl + "/api/auth/login", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ username: "ADMIN", password: "correct horse battery staple" }),
    });
    assert.equal(login.status, 200, await login.text());
    const loginBody = await login.json() as { ok: boolean; csrf_token: string; user: { username: string } };
    assert.equal(loginBody.ok, true);
    assert.equal(loginBody.user.username, "admin");
    assert.ok(loginBody.csrf_token);
    cookie = cookiePair(login.headers.get("set-cookie"));

    const restored = await fetch(baseUrl + "/api/auth/me", { headers: { cookie } });
    assert.equal(restored.status, 200);
    const restoredBody = await restored.json() as { authenticated: boolean; csrf_token: string; user: { id: number } };
    assert.equal(restoredBody.authenticated, true);
    assert.equal(restoredBody.user.id > 0, true);
    assert.ok(restoredBody.csrf_token);
    assert.notEqual(restoredBody.csrf_token, loginBody.csrf_token);

    const logout = await fetch(baseUrl + "/api/auth/logout", {
      method: "POST",
      headers: { "content-type": "application/json", cookie, "x-devone-csrf": restoredBody.csrf_token },
      body: "{}",
    });
    assert.equal(logout.status, 200, await logout.text());
    assert.match(logout.headers.get("set-cookie") ?? "", /Max-Age=0/);

    const afterLogout = await fetch(baseUrl + "/api/auth/me", { headers: { cookie } });
    assert.equal(afterLogout.status, 200);
    const afterLogoutBody = await afterLogout.json() as { authenticated: boolean; user: unknown };
    assert.equal(afterLogoutBody.authenticated, false);
    assert.equal(afterLogoutBody.user, null);
  } finally {
    child.kill("SIGTERM");
    await new Promise<void>(resolve => {
      if (child.exitCode !== null) resolve();
      else {
        child.once("exit", () => resolve());
        setTimeout(() => { child.kill("SIGKILL"); resolve(); }, 2_000);
      }
    });
    await rm(dataDir, { recursive: true, force: true });
  }
});
