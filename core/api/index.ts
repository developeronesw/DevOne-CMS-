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

  router.get("/api/core/content",async(_r,c)=>{const s=c.siteId??(await c.services.db.first<{id:number}>("SELECT id FROM sites ORDER BY id ASC LIMIT 1"))?.id??null;if(!s)return ok({ok:true,pages:[]});return ok({ok:true,pages:await c.services.db.all("SELECT id,slug,title,content,template,show_title,status,author_id,created_at,updated_at FROM pages WHERE site_id=?1 ORDER BY updated_at DESC,id DESC",s)})},{permission:"content.read"});
  router.post("/api/core/content",async(_r,c)=>{const b=(c.body??{})as Record<string,unknown>,s=c.siteId??(await c.services.db.first<{id:number}>("SELECT id FROM sites ORDER BY id ASC LIMIT 1"))?.id??null,slug=String(b.slug??"").trim().toLowerCase(),title=String(b.title??"").trim();if(!s||!title||!/^[a-z0-9][a-z0-9-]{0,119}$/.test(slug))return fail("A valid title and slug are required.",400);try{const r=await c.services.db.run("INSERT INTO pages(site_id,slug,title,content,status,author_id) VALUES(?1,?2,?3,?4,?5,?6)",s,slug,title,String(b.content??""),b.status==="draft"?"draft":"published",c.user?.id??null);return ok({ok:true,page_id:r.lastInsertId},201)}catch(e){return fail(e instanceof Error?e.message:"Content could not be created.",400)}},{permission:"content.create"});
  router.get("/api/core/media",async(_r,c)=>{const s=c.siteId??(await c.services.db.first<{id:number}>("SELECT id FROM sites ORDER BY id ASC LIMIT 1"))?.id??null;if(!s)return ok({ok:true,media:[]});return ok({ok:true,media:await c.services.db.all("SELECT id,filename,object_key,mime_type,size_bytes,alt_text,title,caption,folder,media_type,user_id,created_at,updated_at FROM media WHERE site_id=?1 ORDER BY id DESC",s)})},{permission:"media.read"});
  router.post("/api/core/media",async(_r,c)=>{const b=(c.body??{})as Record<string,unknown>,s=c.siteId??(await c.services.db.first<{id:number}>("SELECT id FROM sites ORDER BY id ASC LIMIT 1"))?.id??null,d=String(b.data_base64??""),fn=String(b.filename??"").trim(),mime=String(b.mime_type??"application/octet-stream").slice(0,120);if(!s||!fn||!d)return fail("Filename and file data are required.",400);if(d.length>7000000)return fail("Media upload is too large for this request.",413);let bytes:Uint8Array;try{const raw=atob(d.replace(/^data:[^;]+;base64,/,""));bytes=Uint8Array.from(raw,x=>x.charCodeAt(0))}catch{return fail("Invalid base64 media data.",400)}const safe=fn.replace(/[^a-zA-Z0-9._-]+/g,"-").replace(/^-+|-+$/g,"")||"upload",key=s+"/"+crypto.randomUUID()+"-"+safe;await c.services.media.put(key,bytes,{contentType:mime});const r=await c.services.db.run("INSERT INTO media(site_id,filename,object_key,mime_type,size_bytes,alt_text,title,folder,media_type,user_id,updated_at) VALUES(?1,?2,?3,?4,?5,?6,'','other','other',?7,CURRENT_TIMESTAMP)",s,fn,key,mime,bytes.byteLength,c.user?.id??null);return ok({ok:true,media_id:r.lastInsertId},201)},{permission:"media.create"});
  router.get("/api/core/users",async()=>ok({ok:true,users:await options.services.db.all("SELECT id,username,email,display_name,role,status,created_at,updated_at FROM users ORDER BY id ASC")}),{permission:"users.read"});
  router.post("/api/core/users",async(_r,c)=>{const b=(c.body??{})as Record<string,unknown>,u=String(b.username??"").trim().toLowerCase(),e=String(b.email??"").trim().toLowerCase(),p=String(b.password??""),role=String(b.role??"subscriber");if(!/^[a-z0-9][a-z0-9._-]{2,99}$/.test(u)||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)||p.length<12||p.length>256)return fail("Username, email, and a 12–256 character password are required.",400);if(!["administrator","editor","author","subscriber","site_admin"].includes(role))return fail("Invalid role.",400);if(await c.services.db.first("SELECT id FROM users WHERE lower(username)=lower(?1) LIMIT 1",u))return fail("That username is already in use.",409);const {passwordHash}=await import("../auth/service"),r=await c.services.db.run("INSERT INTO users(username,password_hash,email,display_name,role,status,password_changed_at) VALUES(?1,?2,?3,?4,?5,'active',CURRENT_TIMESTAMP)",u,await passwordHash(p),e,String(b.display_name??"").trim()||u,role);return ok({ok:true,user_id:r.lastInsertId},201)},{permission:"users.create"});
  router.get("/api/core/settings",async(_r,c)=>{const site=await c.services.db.first<Record<string,unknown>>("SELECT id,site_name,site_slug,primary_domain,domain_mode,status,created_at,updated_at FROM sites ORDER BY id ASC LIMIT 1");const s=await c.services.db.all<{setting_key:string;setting_value:string|null}>("SELECT setting_key,setting_value FROM settings ORDER BY setting_key ASC");const siteSettings=site?.id?await c.services.db.all<{setting_key:string;setting_value:string|null}>("SELECT setting_key,setting_value FROM site_settings WHERE site_id=?1 ORDER BY setting_key ASC",Number(site.id)):[];return ok({ok:true,site,settings:Object.fromEntries(s.map(x=>[x.setting_key,x.setting_value??""])),site_settings:Object.fromEntries(siteSettings.map(x=>[x.setting_key,x.setting_value??""]))})},{permission:"system.read"});
  router.patch("/api/core/settings",async(_r,c)=>{const site=await c.services.db.first<{id:number}>("SELECT id FROM sites ORDER BY id ASC LIMIT 1"),b=(c.body??{})as Record<string,unknown>,v=(b.site_settings&&typeof b.site_settings==="object"?b.site_settings:{})as Record<string,unknown>;if(!site)return fail("Site not found.",404);const a=new Set(["site_name","site_tagline","timezone","language"]),st:Array<{sql:string;params:unknown[]}>=[];for(const[k,x]of Object.entries(v))if(a.has(k)&&typeof x==="string")st.push({sql:"INSERT INTO site_settings(site_id,setting_key,setting_value) VALUES(?1,?2,?3) ON CONFLICT(site_id,setting_key) DO UPDATE SET setting_value=excluded.setting_value",params:[site.id,k,x.trim().slice(0,1000)]});if(!st.length)return fail("No editable settings supplied.",400);await c.services.db.batch(st);return ok({ok:true})},{permission:"system.manage"});
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
