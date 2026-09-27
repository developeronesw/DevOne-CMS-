import type { LicenseEntitlement, LicenseFeature, LicenseProvider, LicenseService } from "./types";

const DEFAULT_SINGLE_SITE_LIMIT = 1;

export class DevOneLicense implements LicenseService {
  constructor(private readonly provider: LicenseProvider) {}

  async get(): Promise<LicenseEntitlement | null> {
    return this.provider.getEntitlement();
  }

  async isFeatureEnabled(feature: LicenseFeature): Promise<boolean> {
    const entitlement = await this.get();
    return Boolean(entitlement?.features.includes(feature));
  }

  async canCreateSite(currentSiteCount: number): Promise<boolean> {
    const entitlement = await this.get();
    if (!entitlement) return currentSiteCount < DEFAULT_SINGLE_SITE_LIMIT;

    if (entitlement.expiresAt && Date.parse(entitlement.expiresAt) <= Date.now()) {
      return currentSiteCount < DEFAULT_SINGLE_SITE_LIMIT;
    }

    if (entitlement.edition !== "network") {
      return currentSiteCount < DEFAULT_SINGLE_SITE_LIMIT;
    }

    // A Network entitlement is intentionally unlimited within its bound Installation.
    if (entitlement.maxSites === null) return true;

    // Retain a defensive finite limit for future signed entitlement formats.
    return currentSiteCount < Math.max(1, entitlement.maxSites);
  }

  async requireFeature(feature: LicenseFeature): Promise<void> {
    if (!(await this.isFeatureEnabled(feature))) {
      throw new Error("This DevOne feature requires a valid license entitlement.");
    }
  }
}
