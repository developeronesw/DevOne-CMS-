import type { Env } from "../types";

export async function setting(env: Env, key: string, fallback: string | null = null): Promise<string | null> {
  const row = await env.DB
    .prepare("SELECT setting_value FROM settings WHERE setting_key = ?1 LIMIT 1")
    .bind(key)
    .first<{ setting_value: string }>();
  return row?.setting_value ?? fallback;
}

export async function setSetting(env: Env, key: string, value: string): Promise<void> {
  await env.DB
    .prepare(
      "INSERT INTO settings (setting_key, setting_value) VALUES (?1, ?2) " +
      "ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value",
    )
    .bind(key, value)
    .run();
}

export async function isInstalled(env: Env): Promise<boolean> {
  return (await setting(env, "installation_complete", "0")) === "1";
}
