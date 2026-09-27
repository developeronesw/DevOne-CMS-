export type DeploymentMode = "local" | "cloudflare";
export type SmtpEncryption = "none" | "starttls" | "tls";

export interface InstallerInput {
  deployment: DeploymentMode;
  admin: {
    username: string;
    email: string;
    displayName: string;
    password: string;
  };
  site: {
    name: string;
    slug: string;
    url?: string;
    timezone?: string;
    language?: string;
  };
  smtp?: {
    enabled: boolean;
    host?: string;
    port?: number;
    encryption?: SmtpEncryption;
    username?: string;
    password?: string;
    fromEmail?: string;
    fromName?: string;
  };
}

export interface InstallerStatus {
  installed: boolean;
  deployment: DeploymentMode | null;
  runtime: string;
  siteName: string | null;
  siteUrl: string | null;
}

export interface InstallerResult {
  siteId: number;
  userId: number;
  deployment: DeploymentMode;
  smtpConfigured: boolean;
}
