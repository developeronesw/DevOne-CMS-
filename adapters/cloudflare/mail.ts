import type { MailMessage, MailTransport } from "../../core/mail";

export interface CloudflareEmailBinding {
  send(message: {
    to: string;
    from: string;
    subject: string;
    text?: string;
    html?: string;
    replyTo?: string;
  }): Promise<{ messageId: string }>;
}

export interface CloudflareMailOptions {
  email: CloudflareEmailBinding | undefined;
  fromEmail: string;
  fromName?: string;
}

/**
 * Outbound transactional email through Cloudflare Email Service.
 * The binding itself is provisioned by Wrangler; the sender domain must
 * separately be onboarded to Email Sending in the Cloudflare account.
 */
export class CloudflareMailTransport implements MailTransport {
  constructor(private readonly options: CloudflareMailOptions) {}

  async send(message: MailMessage): Promise<{ messageId: string }> {
    if (!this.options.email) throw new Error("Cloudflare Email Service binding EMAIL is not configured.");
    const sender = message.fromEmail ?? this.options.fromEmail;
    if (!sender) throw new Error("Cloudflare sender email is not configured.");
    const from = message.fromName ?? this.options.fromName
      ? `"${(message.fromName ?? this.options.fromName ?? "").replace(/["\\r\\n]/g, "")}" <${sender}>`
      : sender;
    return this.options.email.send({
      to: message.to,
      from,
      subject: message.subject,
      text: message.text,
      ...(message.html ? { html: message.html } : {}),
      ...(message.replyTo ? { replyTo: message.replyTo } : {}),
    });
  }

  async test(): Promise<{ ok: true }> {
    if (!this.options.email) throw new Error("Cloudflare Email Service binding EMAIL is not configured.");
    return { ok: true };
  }
}
