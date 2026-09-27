import type { LicenseEntitlement, LicenseProvider } from "./types";

/**
 * Safe default provider.
 *
 * A fresh installation is a valid single-site CMS without contacting any
 * licensing service. Paid/network entitlements must be supplied by an
 * explicitly configured provider in a later licensing integration.
 */
export class OfflineLicenseProvider implements LicenseProvider {
  async getEntitlement(): Promise<LicenseEntitlement | null> {
    return null;
  }

  async activate(_licenseKey: string): Promise<LicenseEntitlement> {
    throw new Error("License activation is unavailable until a license provider is configured.");
  }

  async deactivate(): Promise<void> {
    // Nothing is stored locally by the offline provider.
  }
}
