export * from "./types";
export * from "./router";

import { ApiRouter, DevOneApi, ok } from "./router";
import type { CoreApiOptions } from "./types";

export function createCoreApi(options: CoreApiOptions): { router: ApiRouter; api: DevOneApi } {
  const router = new ApiRouter();

  router.get("/api/health", async (_request, context) => {
    const installed = await context.services.db.first<{ setting_value: string }>(
      "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1",
      "installation_complete",
    );
    return ok({
      ok: true,
      product: "DevOne CMS",
      core_api: "2.0.0",
      installed: installed?.setting_value === "1",
    });
  }, { public: true });

  router.get("/api/core/status", async (_request, context) => {
    const version = await context.services.db.first<{ setting_value: string }>(
      "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1",
      "cms_version",
    );
    return ok({
      ok: true,
      core_api: "2.0.0",
      cms_version: version?.setting_value ?? "2.0.0",
      runtime: "provider-independent",
    });
  }, { public: true });

  return { router, api: new DevOneApi(router, options) };
}
