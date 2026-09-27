export type LicenseFeature =
  | "multisite"
  | "network_admin"
  | "network_users"
  | "network_domains"
  | "network_extensions";

export interface LicenseEntitlement {
  licenseId: string;
  product: "devone-cms";
  edition: "single" | "network";
  maxSites: number;
  features: LicenseFeature[];
  issuedAt: string;
  expiresAt: string | null;
  signature: string;
}

export interface LicenseProvider {
  getEntitlement(): Promise<LicenseEntitlement | null>;
  activate(licenseKey: string): Promise<LicenseEntitlement>;
  deactivate(): Promise<void>;
}

export interface LicenseService {
  get(): Promise<LicenseEntitlement | null>;
  isFeatureEnabled(feature: LicenseFeature): Promise<boolean>;
  canCreateSite(currentSiteCount: number): Promise<boolean>;
  requireFeature(feature: LicenseFeature): Promise<void>;
}
