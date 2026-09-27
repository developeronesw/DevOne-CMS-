import { connect as tlsConnect, type TLSSocket } from "node:tls";
import { connect as tcpConnect, type Socket } from "node:net";
import type { MailConfig, MailMessage, MailTransport } from "../../core/mail";

type Connection = Socket | TLSSocket;

function readResponse(socket: Connection): Promise<string> {
  return new Promise((resolve, reject) => {
    let buffer = "";
    const onData = (chunk: Buffer) => {
      buffer += chunk.toString("utf8");
      const lines = buffer.split(/\\r?\\n/).filter(Boolean);
      const last = lines[lines.length - 1];
      if (last && /^\\d{3} /.test(last)) { cleanup(); resolve(lines.join("\\n")); }
    };
    const onError = (error: Error) => { cleanup(); reject(error); };
    const cleanup = () => { socket.off("data", onData); socket.off("error", onError); };
    socket.on("data", onData);
    socket.once("error", onError);
  });
}

async function command(socket: Connection, value: string): Promise<string> {
  socket.write(value + "\\r\\n");
  return readResponse(socket);
}

function header(value: string): string { return value.replace(/[\\r\\n]/g, ""); }

function body(message: MailMessage): string {
  const from = message.fromName
    ? '"' + message.fromName.replace(/"/g, "'") + '" <' + (message.fromEmail ?? "") + ">"
    : message.fromEmail ?? "";
  const lines = [
    "From: " + header(from),
    "To: " + header(message.to),
    "Subject: " + header(message.subject),
    "MIME-Version: 1.0",
    "Content-Type: text/plain; charset=UTF-8",
  ];
  if (message.replyTo) lines.push("Reply-To: " + header(message.replyTo));
  return lines.join("\\r\\n") + "\\r\\n\\r\\n" + message.text.replace(/\\r?\\n/g, "\\r\\n");
}

export class LocalSmtpTransport implements MailTransport {
  constructor(private readonly configSource: MailConfig | (() => Promise<MailConfig>)) {}

  private async config(): Promise<MailConfig> {
    return typeof this.configSource === "function" ? await this.configSource() : this.configSource;
  }

  private async connection(): Promise<Connection> {
    const config = await this.config();
    if (config.encryption === "tls") {
      return new Promise((resolve, reject) => {
        const socket = tlsConnect({ host: config.host, port: config.port, servername: config.host });
        socket.once("secureConnect", () => resolve(socket));
        socket.once("error", reject);
      });
    }
    return new Promise((resolve, reject) => {
      const socket = tcpConnect(config.port, config.host, () => resolve(socket));
      socket.once("error", reject);
    });
  }

  private async authenticate(socket: Connection, config: MailConfig): Promise<void> {
    if (!config.username) return;
    const value = Buffer.from("\\0" + config.username + "\\0" + config.password).toString("base64");
    const response = await command(socket, "AUTH PLAIN " + value);
    if (!/^2/.test(response)) throw new Error("SMTP authentication failed.");
  }

  private async sendOn(socket: Connection, message: MailMessage, config: MailConfig): Promise<{ messageId: string }> {
    let response = await readResponse(socket);
    if (!/^2/.test(response)) throw new Error("SMTP server rejected the connection.");
    response = await command(socket, "EHLO devone.local");
    if (!/^2/.test(response)) throw new Error("SMTP EHLO failed.");
    await this.authenticate(socket, config);
    response = await command(socket, "MAIL FROM:<" + header(message.fromEmail ?? config.fromEmail) + ">");
    if (!/^2/.test(response)) throw new Error("SMTP MAIL FROM failed.");
    response = await command(socket, "RCPT TO:<" + header(message.to) + ">");
    if (!/^2/.test(response)) throw new Error("SMTP recipient rejected.");
    response = await command(socket, "DATA");
    if (!/^3/.test(response)) throw new Error("SMTP DATA failed.");
    socket.write(body(message).replace(/^\\./gm, "..") + "\\r\\n.\\r\\n");
    response = await readResponse(socket);
    if (!/^2/.test(response)) throw new Error("SMTP message rejected.");
    await command(socket, "QUIT");
    socket.end();
    return { messageId: crypto.randomUUID() };
  }

  async send(message: MailMessage): Promise<{ messageId: string }> {
    const config = await this.config();
    const socket = await this.connection();
    try {
      if (config.encryption === "starttls") {
        let response = await readResponse(socket);
        if (!/^2/.test(response)) throw new Error("SMTP server rejected the connection.");
        response = await command(socket, "EHLO devone.local");
        if (!/^2/.test(response)) throw new Error("SMTP EHLO failed.");
        response = await command(socket, "STARTTLS");
        if (!/^2/.test(response)) throw new Error("SMTP STARTTLS failed.");
        const secure = await new Promise<TLSSocket>((resolve, reject) => {
          const tls = tlsConnect({ socket, servername: config.host });
          tls.once("secureConnect", () => resolve(tls));
          tls.once("error", reject);
        });
        return await this.sendOn(secure, message, config);
      }
      return await this.sendOn(socket, message, config);
    } catch (error) {
      socket.destroy();
      throw error;
    }
  }

  async test(): Promise<{ ok: true }> {
    const config = await this.config();
    const socket = await this.connection();
    try {
      let response = await readResponse(socket);
      if (!/^2/.test(response)) throw new Error("SMTP server rejected the connection.");
      response = await command(socket, "EHLO devone.local");
      if (!/^2/.test(response)) throw new Error("SMTP EHLO failed.");
      if (config.encryption === "starttls") {
        response = await command(socket, "STARTTLS");
        if (!/^2/.test(response)) throw new Error("SMTP STARTTLS failed.");
      }
      await command(socket, "QUIT");
      socket.end();
      return { ok: true };
    } catch (error) {
      socket.destroy();
      throw error;
    }
  }
}
