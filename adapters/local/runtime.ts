import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { createLocalServices } from "./providers";
import { runLocalMigrations } from "./migrations";

export interface LocalRuntimeOptions {
  dataDir: string;
  mediaDir?: string;
  environment?: string;
  database?: DatabaseSync;
  secretKey?: string;
}

async function loadSecret(dataDir: string, supplied?: string): Promise<string> {
  if (supplied) return supplied;
  const path = resolve(dataDir, "devone.secret");
  try {
    const value = (await readFile(path, "utf8")).trim();
    if (value) return value;
  } catch {}
  const bytes = new Uint8Array(32);
  globalThis.crypto.getRandomValues(bytes);
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  const value = btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  await writeFile(path, value + "\n", { encoding: "utf8", mode: 0o600 });
  return value;
}

export async function createLocalRuntime(options: LocalRuntimeOptions) {
  const dataDir = resolve(options.dataDir);
  await mkdir(dataDir, { recursive: true });
  const mediaDir = resolve(options.mediaDir ?? (dataDir + "/media"));
  await mkdir(mediaDir, { recursive: true });
  const Database = DatabaseSync as unknown as new (path: string) => DatabaseSync;
  const database = options.database ?? new Database(resolve(dataDir, "devone.sqlite"));
  database.exec("PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;");
  await runLocalMigrations(database);
  const secretKey = await loadSecret(dataDir, options.secretKey ?? process.env.DEVONE_SECRET_KEY);
  return createLocalServices({ ...options, dataDir, mediaDir, database, secretKey });
}
