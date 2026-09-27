export type MailEncryption = "none" | "starttls" | "tls";

export interface MailMessage {
  to: string;
  subject: string;
  text: string;
  html?: string;
  fromEmail?: string;
  fromName?: string;
  replyTo?: string;
}

export interface MailTransport {
  send(message: MailMessage): Promise<{ messageId: string }>;
  test(): Promise<{ ok: true }>;
}

export interface MailConfig {
  enabled: boolean;
  host: string;
  port: number;
  encryption: MailEncryption;
  username: string;
  password: string;
  fromEmail: string;
  fromName: string;
}
