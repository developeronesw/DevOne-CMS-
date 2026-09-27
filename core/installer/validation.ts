import type { InstallerInput } from "./types";

const emailPattern = /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/;
const usernamePattern = /^[a-z0-9][a-z0-9._-]{2,99}$/;
const slugPattern = /^[a-z0-9][a-z0-9-]{1,119}$/;

export function normalizeInstallerInput(input: InstallerInput): InstallerInput {
  const siteName = String(input.site?.name ?? "").trim().slice(0, 190);
  const generatedSlug = siteName.toLowerCase().replace(/[^a-z0-9-]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 120);
  return {
    deployment: input.deployment,
    admin: {
      username: String(input.admin?.username ?? "").trim().toLowerCase(),
      email: String(input.admin?.email ?? "").trim().toLowerCase(),
      displayName: String(input.admin?.displayName ?? "").trim().slice(0, 190),
      password: String(input.admin?.password ?? ""),
    },
    site: {
      name: siteName,
      slug: String(input.site?.slug ?? generatedSlug).trim().toLowerCase(),
      url: String(input.site?.url ?? "").trim(),
      timezone: String(input.site?.timezone ?? "UTC").trim() || "UTC",
      language: String(input.site?.language ?? "en-US").trim() || "en-US",
    },
    smtp: {
      enabled: Boolean(input.smtp?.enabled),
      host: String(input.smtp?.host ?? "").trim(),
      port: Number(input.smtp?.port ?? 587),
      encryption: input.smtp?.encryption ?? "starttls",
      username: String(input.smtp?.username ?? "").trim(),
      password: String(input.smtp?.password ?? ""),
      fromEmail: String(input.smtp?.fromEmail ?? "").trim().toLowerCase(),
      fromName: String(input.smtp?.fromName ?? "").trim().slice(0, 190),
    },
  };
}

export function validateInstallerInput(input: InstallerInput): string | null {
  if (input.deployment !== "local" && input.deployment !== "cloudflare") return "Choose Local or Cloudflare deployment.";
  if (input.site.name.length < 2) return "Site name must be at least 2 characters.";
  if (!slugPattern.test(input.site.slug)) return "Site slug must use 2-120 lowercase letters, numbers, and hyphens.";
  if (!usernamePattern.test(input.admin.username)) return "Invalid administrator username.";
  if (!emailPattern.test(input.admin.email)) return "Invalid administrator email.";
  if (input.admin.password.length < 12 || input.admin.password.length > 256) return "Administrator password must be 12-256 characters.";
  if (input.site.url) {
    try {
      const url = new URL(input.site.url);
      if (!["http:", "https:"].includes(url.protocol)) return "Site URL must use HTTP or HTTPS.";
    } catch {
      return "Invalid site URL.";
    }
  }
  const smtp = input.smtp;
  if (!smtp?.enabled) return null;
  const port = smtp.port ?? 587;
  const password = smtp.password ?? "";
  const port = smtp.port ?? 587;
  const password = smtp.password ?? "";
  if (!smtp.host || smtp.host.length > 255) return "SMTP host is required.";
  if (!Number.isInteger(port) || port < 1 || port > 65535) return "SMTP port must be between 1 and 65535.";
  if (!["none", "starttls", "tls"].includes(smtp.encryption ?? "")) return "Invalid SMTP encryption mode.";
  if (smtp.username && password.length > 256) return "SMTP password is too long.";
  if (!emailPattern.test(smtp.fromEmail ?? "")) return "Invalid SMTP from email.";
  if (!smtp.fromName) return "SMTP from name is required.";
  return null;
}
