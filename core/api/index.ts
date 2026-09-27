export * from "./types";
export * from "./router";

import { ApiRouter, DevOneApi, ok, fail } from "./router";
import type { CoreApiOptions } from "./types";
import { DevOneLicense } from "../license";
import { DevOneSiteService } from "../sites/service";

export function createCoreApi(options: CoreApiOptions): { router: ApiRouter; api: DevOneApi } {
  const router = new ApiRouter();
  const license = new DevOneLicense({
    async get() {
      const row = await options.services.db.first<{ entitlement_json: string }>(
        "SELECT setting_value AS entitlement_json FROM settings WHERE setting_key = 'license_entitlement' LIMIT 1",
      );
      if (!row?.entitlement_json) return null;
      try { return JSON.parse(row.entitlement_json); } catch { return null; }
    },
    async activate() { throw new Error("License activation provider is not configured."); },
    async deactivate() { await options.services.db.run("DELETE FROM settings WHERE setting_key = 'license_entitlement'"); },
  });
  const sites = new DevOneSiteService(options.services.db, license);

  router.get("/api/core/health", async (_request, context) => {
    const installed = await context.services.db.first<{ setting_value: string }>(
      "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1", "installation_complete",
    );
    return ok({ ok: true, product: "DevOne CMS", core_api: "2.0.0", installed: installed?.setting_value === "1" });
  }, { public: true });

  router.get("/api/core/status", async (_request, context) => {
    const version = await context.services.db.first<{ setting_value: string }>(
      "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1", "cms_version",
    );
    const entitlement = await license.get();
    return ok({
      ok: true,
      core_api: "2.0.0",
      cms_version: version?.setting_value ?? "2.0.0",
      runtime: context.services.runtime.name,
      license: entitlement ? { edition: entitlement.edition, max_sites: entitlement.maxSites, features: entitlement.features, expires_at: entitlement.expiresAt }
        : { edition: "single", max_sites: 1, features: [], expires_at: null },
    });
  }, { public: true });

  router.get("/api/core/license", async () => {
    const entitlement = await license.get();
    return ok({ ok: true, licensed: Boolean(entitlement), entitlement });
  }, { public: true });

  router.get("/api/core/sites", async (_request, context) => {
    const rows = await context.services.db.all("SELECT id, site_name, site_slug, primary_domain, owner_user_id, status, created_at, updated_at FROM sites ORDER BY id ASC");
    return ok({ ok: true, sites: rows });
  }, { permission: "sites.read" });

  router.post("/api/core/sites", async (_request, context) => {
    if (!context.user) return fail("Authentication required.", 401);
    if (!(await sites.canCreate())) return fail("This installation is limited to one site. A valid Network license is required for additional sites.", 403);
    const body = context.body as Record<string, unknown>;
    const siteName = String(body.site_name ?? "").trim();
    const siteSlug = String(body.site_slug ?? "").trim().toLowerCase();
    if (siteName.length < 2 || !/^[a-z0-9][a-z0-9-]{1,119}$/.test(siteSlug)) return fail("Invalid site name or slug.", 400);
    try {
      const id = await sites.create({ siteName, siteSlug, ownerUserId: context.user.id, primaryDomain: String(body.primary_domain ?? "").trim() });
      return ok({ ok: true, site_id: id }, 201);
    } catch (error) {
      return fail(error instanceof Error ? error.message : "Site could not be created.", 403);
    }
  }, { permission: "sites.create" });

  return { router, api: new DevOneApi(router, options) };
}
