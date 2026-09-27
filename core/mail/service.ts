import type { CoreServices } from "../api/types";
import { decryptSecret } from "../security/secrets";
import type { MailConfig, MailMessage, MailTransport } from "./types";

const emailPattern = /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/;

export class DevOneMailService {
  constructor(private readonly services: CoreServices, private readonly transport: MailTransport | null) {}

  async config(): Promise<MailConfig> {
    const value = async (key: string, fallback = "") =>
      (await this.services.db.first<{ setting_value: string | null }>(
        "SELECT setting_value FROM settings WHERE setting_key = ?1 LIMIT 1", key,
      ))?.setting_value ?? fallback;
    const enabled = (await value("mail_enabled", await value("smtp_enabled", "0"))) === "1";
    const encrypted = await value("smtp_password_encrypted");
    let password = "";
    if (encrypted) {
      const secret = String(this.services.config.secret_key ?? "");
      if (!secret) throw new Error("Mail secret key is not configured.");
      password = await decryptSecret(encrypted, secret);
    }
    return {
      enabled,
      host: await value("smtp_host"),
      port: Number(await value("smtp_port", "587")),
      encryption: (await value("smtp_encryption", "starttls")) as MailConfig["encryption"],
      username: await value("smtp_username"),
      password,
      fromEmail: await value("mail_from_email", await value("smtp_from_email")),
      fromName: await value("mail_from_name", await value("smtp_from_name")),
    };
  }

  async send(message: MailMessage): Promise<{ messageId: string }> {
    const config = await this.config();
    if (!config.enabled) throw new Error("Mail delivery is disabled.");
    if (!this.transport) throw new Error("No mail transport is available for this runtime.");
    if (!emailPattern.test(message.to)) throw new Error("Invalid recipient email.");
    if (!message.subject.trim()) throw new Error("Email subject is required.");
    if (!message.text.trim() && !message.html?.trim()) throw new Error("Email body is required.");
    return this.transport.send({
      ...message,
      fromEmail: message.fromEmail ?? config.fromEmail,
      fromName: message.fromName ?? config.fromName,
    });
  }

  async test(): Promise<{ ok: true }> {
    const config = await this.config();
    if (!config.enabled) throw new Error("Mail delivery is disabled.");
    if (!this.transport) throw new Error("No mail transport is available for this runtime.");
    if (!config.fromEmail || !emailPattern.test(config.fromEmail)) {
      throw new Error("A valid sender email address is required.");
    }
    if (this.services.config.runtime !== "cloudflare-workers" &&
        (!config.host || !Number.isInteger(config.port) || config.port < 1 || config.port > 65535)) {
      throw new Error("SMTP configuration is incomplete.");
    }
    return this.transport.test();
  }
}
