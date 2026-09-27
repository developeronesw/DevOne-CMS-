import type { CoreServices } from "../api/types";
import { passwordHash } from "../auth/service";
import { encryptSecret } from "../security/secrets";
import type { InstallerInput, InstallerResult, InstallerStatus } from "./types";
import { normalizeInstallerInput, validateInstallerInput } from "./validation";

const DEFAULT_ROLES = [
  ["administrator", "Full DevOne administration", JSON.stringify(["*"])],
  ["editor", "Manage site content", JSON.stringify(["content.*", "media.*"])],
  ["author", "Create and manage authored content", JSON.stringify(["content.create", "content.read", "content.update", "media.create"])],
  ["subscriber", "Basic authenticated account", JSON.stringify([])],
  ["site_admin", "Manage an assigned site", JSON.stringify(["site.*", "content.*", "media.*"])],
] as const;

export class DevOneInstaller {
  constructor(private readonly services: CoreServices) {}

  async status(): Promise<InstallerStatus> {
    const installed = await this.services.db.first<{ setting_value: string }>(
      "SELECT setting_value FROM settings WHERE setting_key = 'installation_complete' LIMIT 1",
    );
    const deployment = await this.services.db.first<{ setting_value: string }>(
      "SELECT setting_value FROM settings WHERE setting_key = 'deployment_mode' LIMIT 1",
    );
    const site = await this.services.db.first<{ site_name: string; primary_domain: string }>(
      "SELECT site_name, primary_domain FROM sites ORDER BY id ASC LIMIT 1",
    );
    return {
      installed: installed?.setting_value === "1",
      deployment: deployment?.setting_value === "local" || deployment?.setting_value === "cloudflare" ? deployment.setting_value : null,
      runtime: String(this.services.config.runtime ?? "provider-independent"),
      siteName: site?.site_name ?? null,
      siteUrl: site?.primary_domain ?? null,
    };
  }

  async install(rawInput: InstallerInput): Promise<InstallerResult> {
    const input = normalizeInstallerInput(rawInput);
    const validationError = validateInstallerInput(input);
    if (validationError) throw new Error(validationError);

    const current = await this.status();
    if (current.installed) throw new Error("DevOne is already installed.");

    const existingUser = await this.services.db.first<{ id: number }>("SELECT id FROM users LIMIT 1");
    if (existingUser) throw new Error("An administrator already exists.");

    const hashedPassword = await passwordHash(input.admin.password);
    let smtpPasswordEncrypted = "";
    if (input.smtp?.enabled && input.smtp.password) {
      const secret = String(this.services.config.secret_key ?? "");
      if (!secret) throw new Error("SMTP secrets cannot be stored because the runtime secret key is not configured.");
      smtpPasswordEncrypted = await encryptSecret(input.smtp.password, secret);
    }

    const primaryDomain = input.site.url ? new URL(input.site.url).hostname : "";
    const smtp = input.smtp ?? { enabled: false, host: "", port: 587, encryption: "starttls", username: "", password: "", fromEmail: input.admin.email, fromName: input.site.name };
    const settings: Array<[string, string]> = [
      ["deployment_mode", input.deployment],
      ["site_name", input.site.name],
      ["site_title", input.site.name],
      ["site_tagline", "Build. Manage. Evolve."],
      ["site_url", input.site.url ?? ""],
      ["timezone", input.site.timezone ?? "UTC"],
      ["language", input.site.language ?? "en-US"],
      ["cms_version", "2.0.0-alpha.3"],
      ["site_theme", "devone-glass"],
      ["smtp_enabled", smtp.enabled ? "1" : "0"],
      ["smtp_host", smtp.host ?? ""],
      ["smtp_port", String(smtp.port ?? 587)],
      ["smtp_encryption", smtp.encryption ?? "starttls"],
      ["smtp_username", smtp.username ?? ""],
      ["smtp_password_encrypted", smtpPasswordEncrypted],
      ["smtp_from_email", smtp.fromEmail ?? input.admin.email],
      ["smtp_from_name", smtp.fromName ?? input.site.name],
      ["installation_complete", "1"],
    ];

    const statements: Array<{ sql: string; params?: unknown[] }> = DEFAULT_ROLES.map(([name, description, permissions]) => ({
      sql: "INSERT OR IGNORE INTO roles (name, description, permissions) VALUES (?1, ?2, ?3)",
      params: [name, description, permissions],
    }));

    statements.push(
      {
        sql: "INSERT INTO users (username, password_hash, email, display_name, role, status, password_changed_at) VALUES (?1, ?2, ?3, ?4, 'administrator', 'active', CURRENT_TIMESTAMP)",
        params: [input.admin.username, hashedPassword, input.admin.email, input.admin.displayName || input.admin.username],
      },
      {
        sql: "INSERT INTO sites (site_name, site_slug, primary_domain, owner_user_id, admin_username, admin_email, status) VALUES (?1, ?2, ?3, (SELECT id FROM users WHERE username = ?4 LIMIT 1), ?4, ?5, 'active')",
        params: [input.site.name, input.site.slug, primaryDomain, input.admin.username, input.admin.email],
      },
      {
        sql: "INSERT INTO site_users (site_id, user_id, role, status) VALUES ((SELECT id FROM sites WHERE site_slug = ?1 LIMIT 1), (SELECT id FROM users WHERE username = ?2 LIMIT 1), 'site_admin', 'active')",
        params: [input.site.slug, input.admin.username],
      },
      {
        sql: "INSERT INTO pages (site_id, slug, title, content, template, show_title, status, author_id) VALUES ((SELECT id FROM sites WHERE site_slug = ?1 LIMIT 1), 'home', ?2, ?3, 'default', 0, 'published', (SELECT id FROM users WHERE username = ?4 LIMIT 1))",
        params: [input.site.slug, input.site.name, "<h1>Welcome to DevOne CMS 2.0</h1><p>Performance, Security, Design.</p>", input.admin.username],
      },
    );

    for (const [key, value] of settings) {
      statements.push({
        sql: "INSERT INTO settings (setting_key, setting_value) VALUES (?1, ?2) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value",
        params: [key, value],
      });
    }

    await this.services.db.batch(statements);
    const site = await this.services.db.first<{ id: number; owner_user_id: number }>(
      "SELECT id, owner_user_id FROM sites WHERE site_slug = ?1 LIMIT 1", input.site.slug,
    );
    const user = await this.services.db.first<{ id: number }>(
      "SELECT id FROM users WHERE username = ?1 LIMIT 1", input.admin.username,
    );
    if (!site?.id || !user?.id) throw new Error("Installation completed without required records.");

    return {
      siteId: site.id,
      userId: user.id,
      deployment: input.deployment,
      smtpConfigured: Boolean(input.smtp?.enabled),
    };
  }
}
