export * from "./types";
export * from "./router";

import { ApiRouter, DevOneApi, ok, fail } from "./router";
import type { CoreApiOptions } from "./types";
import type { MailConfig } from "../mail";
import { DevOneLicense } from "../license";
import { DevOneSiteService } from "../sites/service";
import { DevOneInstaller } from "../installer";
import { DevOneMailService } from "../mail";
import { OfflineLicenseProvider } from "../license/offline";

export function createCoreApi(options: CoreApiOptions): { router: ApiRouter; api: DevOneApi } {
  const router = new ApiRouter();

  const license = new DevOneLicense(options.licenseProvider ?? new OfflineLicenseProvider());

  const sites = new DevOneSiteService(options.services.db, license);
  const installer = new DevOneInstaller(options.services);
  const mail = new DevOneMailService(options.services, options.mailTransport ?? null);

  router.get("/api/install/status", async () => ok({ ok: true, ...(await installer.status()) }), { public: true });

  router.get("/api/install/prerequisites", async () => {
    const checks = options.installerPrerequisites
      ? await options.installerPrerequisites()
      : [{ id: "runtime-secret", label: "Runtime secret key", required: true, ok: Boolean(String(options.services.config.secret_key ?? "")), detail: "Used to protect stored secrets and sessions." }];
    return ok({ ok: checks.every(check => !check.required || check.ok), checks });
  }, { public: true });

  router.post("/api/install/test-smtp", async (_request, context) => {
    try {
      const status = await installer.status();
      if (status.installed) return fail("Mail testing through the installer is disabled after installation.", 409);
      const body = (context.body ?? {}) as Record<string, unknown>;
      const hasDraft = Object.keys(body).length > 0;
      if (hasDraft) {
        const config: MailConfig = {
          enabled: Boolean(body.enabled),
          host: String(body.host ?? "").trim(),
          port: Number(body.port ?? 587),
          encryption: String(body.encryption ?? "starttls") as MailConfig["encryption"],
          username: String(body.username ?? "").trim(),
          password: String(body.password ?? ""),
          fromEmail: String(body.fromEmail ?? "").trim().toLowerCase(),
          fromName: String(body.fromName ?? "").trim(),
        };
        if (!options.mailTransportFactory) return fail("This runtime does not support draft mail testing.", 400);
        const draftMail = new DevOneMailService(options.services, options.mailTransportFactory(config));
        await draftMail.test(config);
      } else {
        await mail.test();
      }
      return ok({ ok: true, message: "Mail transport configuration test succeeded." });
    } catch (error) {
      return fail(error instanceof Error ? error.message : "Mail transport test failed.", 400);
    }
  }, { public: true, csrf: false });

  router.post("/api/install", async (_request, context) => {
    try {
      const body = (context.body ?? {}) as Record<string, unknown>;
      const result = await installer.install(body as never);
      return ok({ ok: true, installed: true, ...result, message: "DevOne CMS 2.0 foundation installed." }, 201);
    } catch (error) {
      const message = error instanceof Error ? error.message : "Installation could not be completed.";
      const status = /already installed|administrator already exists/.test(message) ? 409 : 400;
      return fail(message, status);
    }
  }, { public: true, csrf: false });

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
      runtime: String(context.services.config.runtime ?? "provider-independent"),
      license: entitlement
        ? { edition: entitlement.edition, max_sites: entitlement.maxSites, features: entitlement.features, expires_at: entitlement.expiresAt }
        : { edition: "single", max_sites: 1, features: [], expires_at: null },
    });
  }, { public: true });

  router.get("/api/core/license", async () => {
    const entitlement = await license.get();
    return ok({ ok: true, licensed: Boolean(entitlement), entitlement });
  }, { public: true });

  router.get("/api/core/sites", async (_request, context) => {
    const rows = await context.services.db.all(
      "SELECT id, site_name, site_slug, primary_domain, owner_user_id, status, created_at, updated_at FROM sites ORDER BY id ASC",
    );
    return ok({ ok: true, sites: rows });
  }, { permission: "sites.read" });

  router.post("/api/core/sites", async (_request, context) => {
    if (!context.user) return fail("Authentication required.", 401);
    if (!(await sites.canCreate())) {
      return fail("This installation is limited to one site. A valid Network license is required for additional sites.", 403);
    }

    const body = (context.body ?? {}) as Record<string, unknown>;
    const siteName = String(body.site_name ?? "").trim();
    const siteSlug = String(body.site_slug ?? "").trim().toLowerCase();
    const primaryDomain = String(body.primary_domain ?? "").trim();

    if (siteName.length < 2 || !/^[a-z0-9][a-z0-9-]{1,119}$/.test(siteSlug)) {
      return fail("Invalid site name or slug.", 400);
    }

    try {
      const id = await sites.create({ siteName, siteSlug, ownerUserId: context.user.id, primaryDomain });
      return ok({ ok: true, site_id: id }, 201);
    } catch (error) {
      return fail(error instanceof Error ? error.message : "Site could not be created.", 403);
    }
  }, { permission: "sites.create" });

  return { router, api: new DevOneApi(router, options) };
}
