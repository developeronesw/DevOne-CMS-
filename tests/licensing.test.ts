import assert from "node:assert/strict";
import test from "node:test";

import { DevOneLicense } from "../core/license/service";
import type { LicenseEntitlement, LicenseProvider } from "../core/license/types";

const networkEntitlement: LicenseEntitlement = {
  licenseId: "DEV-NETWORK-TEST",
  product: "devone-cms",
  edition: "network",
  maxSites: null,
  features: ["multisite", "network_admin", "network_users", "network_domains", "network_extensions"],
  issuedAt: "2026-09-27T00:00:00.000Z",
  expiresAt: null,
  signature: "test-signature",
};

function provider(entitlement: LicenseEntitlement | null): LicenseProvider {
  return {
    getEntitlement: async () => entitlement,
    activate: async () => networkEntitlement,
    deactivate: async () => undefined,
  };
}

test("Network entitlement allows unlimited Sites within one Installation", async () => {
  const license = new DevOneLicense(provider(networkEntitlement));
  assert.equal(await license.isFeatureEnabled("multisite"), true);
  assert.equal(await license.canCreateSite(0), true);
  assert.equal(await license.canCreateSite(1), true);
  assert.equal(await license.canCreateSite(1000), true);
});

test("expired Network entitlement falls back to the single-site limit", async () => {
  const expired = { ...networkEntitlement, expiresAt: "2020-01-01T00:00:00.000Z" };
  const license = new DevOneLicense(provider(expired));
  assert.equal(await license.canCreateSite(0), true);
  assert.equal(await license.canCreateSite(1), false);
});

test("non-network entitlement cannot unlock additional Sites", async () => {
  const single = { ...networkEntitlement, edition: "single" as const, maxSites: 999 };
  const license = new DevOneLicense(provider(single));
  assert.equal(await license.canCreateSite(0), true);
  assert.equal(await license.canCreateSite(1), false);
});
