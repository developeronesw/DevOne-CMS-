import { mkdir, readFile, writeFile } from "node:fs/promises";
import { randomBytes } from "node:crypto";
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
  const value = randomBytes(32).toString("base64url");
  await writeFile(path, value + "\n", { encoding: "utf8", mode: 0o600 });
  return value;
}

export async function createLocalRuntime(options: LocalRuntimeOptions) {
  const dataDir = resolve(options.dataDir);
  await mkdir(dataDir, { recursive: true });
  const mediaDir = resolve(options.mediaDir ?? (dataDir + "/media"));
  await mkdir(mediaDir, { recursive: true });
  const database = options.database ?? new DatabaseSync(resolve(dataDir, "devone.sqlite"));
  database.exec("PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;");
  await runLocalMigrations(database);
  const secretKey = await loadSecret(dataDir, options.secretKey ?? process.env.DEVONE_SECRET_KEY);
  return createLocalServices({ ...options, dataDir, mediaDir, database, secretKey });
}
