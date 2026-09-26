#!/usr/bin/env node

import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, resolve } from "node:path";

const root = resolve(import.meta.dirname, "..");
const configPath = resolve(root, "wrangler.jsonc");

const project = process.env.DEVONE_CF_PROJECT || "devone-cms";
const resourcePrefix = process.env.DEVONE_CF_PREFIX || project;
const dbName = process.env.DEVONE_CF_D1 || `${resourcePrefix}-db`;
const kvName = process.env.DEVONE_CF_KV || `${resourcePrefix}-cache`;
const bucketName = process.env.DEVONE_CF_R2 || `${resourcePrefix}-media`;
const pagesProject = process.env.DEVONE_CF_PAGES || project;

function run(args, options = {}) {
  console.log(`$ wrangler ${args.join(" ")}`);
  return execFileSync("npx", ["wrangler", ...args], {
    cwd: root,
    encoding: "utf8",
    stdio: options.capture ? ["inherit", "pipe", "pipe"] : "inherit"
  });
}

function runCapture(args) {
  return run(args, { capture: true });
}

function ensureWranglerAuth() {
  try {
    run(["whoami"]);
  } catch {
    console.error("\nDevOne needs Cloudflare authorization once. Starting Wrangler login...\n");
    run(["login"]);
    run(["whoami"]);
  }
}

function findJson(text) {
  const start = text.indexOf("{");
  const end = text.lastIndexOf("}");
  if (start < 0 || end < start) return null;
  try { return JSON.parse(text.slice(start, end + 1)); } catch { return null; }
}

function findId(text, keys = ["database_id", "id"]) {
  const json = findJson(text);
  if (json) {
    const queue = [json];
    while (queue.length) {
      const value = queue.shift();
      if (!value || typeof value !== "object") continue;
      for (const key of keys) {
        if (typeof value[key] === "string" && value[key].length > 8) return value[key];
      }
      for (const child of Object.values(value)) {
        if (child && typeof child === "object") queue.push(child);
      }
    }
  }
  const match = text.match(/["'](?:database_id|id)["']\\s*:\\s*["']([^"']+)["']/);
  return match?.[1] ?? null;
}

function findExistingD1(name) {
  try {
    const out = runCapture(["d1", "list", "--json"]);
    const list = JSON.parse(out);
    const item = Array.isArray(list) ? list.find(x => x.name === name) : null;
    return item?.uuid || item?.database_id || item?.id || null;
  } catch {
    return null;
  }
}

function findExistingKV(name) {
  try {
    const out = runCapture(["kv", "namespace", "list", "--json"]);
    const list = JSON.parse(out);
    const item = Array.isArray(list) ? list.find(x => x.title === name || x.name === name) : null;
    return item?.id || null;
  } catch {
    return null;
  }
}

function findExistingR2(name) {
  try {
    const out = runCapture(["r2", "bucket", "list", "--json"]);
    const list = JSON.parse(out);
    const item = Array.isArray(list) ? list.find(x => x.name === name) : null;
    return item?.name || null;
  } catch {
    return null;
  }
}

function createD1() {
  const existing = findExistingD1(dbName);
  if (existing) {
    console.log(`✓ D1 already exists: ${dbName}`);
    return existing;
  }
  const out = runCapture(["d1", "create", dbName, "--json"]);
  const id = findId(out);
  if (!id) throw new Error(`Could not determine the D1 database ID for ${dbName}.\n${out}`);
  console.log(`✓ Created D1: ${dbName}`);
  return id;
}

function createKV() {
  const existing = findExistingKV(kvName);
  if (existing) {
    console.log(`✓ KV already exists: ${kvName}`);
    return existing;
  }
  const out = runCapture(["kv", "namespace", "create", kvName, "--json"]);
  const id = findId(out);
  if (!id) throw new Error(`Could not determine the KV namespace ID for ${kvName}.\n${out}`);
  console.log(`✓ Created KV: ${kvName}`);
  return id;
}

function createR2() {
  const existing = findExistingR2(bucketName);
  if (existing) {
    console.log(`✓ R2 already exists: ${bucketName}`);
    return existing;
  }
  run(["r2", "bucket", "create", bucketName]);
  console.log(`✓ Created R2: ${bucketName}`);
  return bucketName;
}

function writeConfig({ d1Id, kvId }) {
  const config = {
    "$schema": "./node_modules/wrangler/config-schema.json",
    "name": project,
    "pages_build_output_dir": "./dist",
    "compatibility_date": "2026-09-25",
    "compatibility_flags": ["nodejs_compat"],
    "d1_databases": [
      {
        "binding": "DB",
        "database_name": dbName,
        "database_id": d1Id
      }
    ],
    "kv_namespaces": [
      {
        "binding": "CACHE",
        "id": kvId
      }
    ],
    "r2_buckets": [
      {
        "binding": "MEDIA",
        "bucket_name": bucketName
      }
    ]
  };
  writeFileSync(configPath, JSON.stringify(config, null, 2) + "\n");
  console.log(`✓ Wrote ${configPath}`);
}

function ensurePagesProject() {
  try {
    const out = runCapture(["pages", "project", "list", "--json"]);
    const list = JSON.parse(out);
    if (Array.isArray(list) && list.some(x => x.name === pagesProject)) {
      console.log(`✓ Pages project already exists: ${pagesProject}`);
      return;
    }
  } catch {}
  run(["pages", "project", "create", pagesProject, "--production-branch", "main"]);
  console.log(`✓ Created Pages project: ${pagesProject}`);
}

function main() {
  console.log("\nDevOne CMS — Cloudflare Serverless Bootstrap\n");
  ensureWranglerAuth();

  const d1Id = createD1();
  const kvId = createKV();
  createR2();
  writeConfig({ d1Id, kvId });
  ensurePagesProject();

  console.log("\nCloudflare resources are ready.");
  console.log(`  D1: ${dbName}`);
  console.log(`  KV: ${kvName}`);
  console.log(`  R2: ${bucketName}`);
  console.log(`  Pages: ${pagesProject}`);
  console.log("\nNext deployment:");
  console.log("  npm run build");
  console.log("  npx wrangler pages deploy dist");
}

main();
