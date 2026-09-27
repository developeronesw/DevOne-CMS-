import type { Env } from "./types";
import { json } from "./lib/crypto";
import { adminResetPassword, changePassword, completePasswordReset, getCurrentUser, login, logout, logoutAll, requestPasswordReset, revokeMySessions } from "./lib/auth";
import { isInstalled, setSetting, setting } from "./lib/db";
import { createCoreApi } from "../core/api";
import { DevOnePermissions } from "../core/auth/permissions";
import { DevOneAuthService } from "../core/auth/service";
import { createCloudflareServices } from "../adapters/cloudflare/providers";

function securityHeaders(): HeadersInit {
  return {"x-content-type-options":"nosniff","x-frame-options":"SAMEORIGIN","referrer-policy":"strict-origin-when-cross-origin","permissions-policy":"camera=(), microphone=(), geolocation=()"};
}
async function api(request: Request, env: Env): Promise<Response> {
  const url=new URL(request.url), path=url.pathname.replace(/\/+$/,"")||"/";
  if (path.startsWith("/api/core/") || path.startsWith("/api/install")) {
    const services=createCloudflareServices(env);
    const permissions=new DevOnePermissions(services.db);
    const auth=new DevOneAuthService(services.db);
    const core=createCoreApi({
      services,
      authenticate:req=>auth.getCurrentUser(req),
      authorize:(user,permission,siteId)=>permissions.has(user,permission,siteId),
      resolveSite:async req=>{const value=req.headers.get("x-devone-site-id");return value&&/^\d+$/.test(value)?Number(value):null;},
      validateCsrf:async req=>{try{await auth.requireCsrf(req);return true;}catch{return false;}},
      maxBodyBytes:2*1024*1024,
    });
    return core.api.handle(request);
  }
  if (path==="/api/health"&&request.method==="GET") return json({ok:true,product:"DevOne CMS",version:"2.0.0-alpha.2",installed:await isInstalled(env),runtime:"cloudflare-workers"});
  if (path==="/api/system/status"&&request.method==="GET") return json({ok:true,installed:await isInstalled(env),version:await setting(env,"cms_version","2.0.0-alpha.2")});
  if (path==="/api/auth/login"&&request.method==="POST") return login(request,env);
  if (path==="/api/auth/logout"&&request.method==="POST") return logout(request,env);
  if (path==="/api/auth/logout-all"&&request.method==="POST") return logoutAll(request,env);
  if (path==="/api/auth/sessions/revoke"&&request.method==="POST") return revokeMySessions(request,env);
  if (path==="/api/auth/password/change"&&request.method==="POST") return changePassword(request,env);
  if (path==="/api/auth/password/reset/request"&&request.method==="POST") return requestPasswordReset(request,env);
  if (path==="/api/auth/password/reset/complete"&&request.method==="POST") return completePasswordReset(request,env);
  if (path==="/api/auth/password/reset/admin"&&request.method==="POST") return adminResetPassword(request,env);
  if (path==="/api/auth/me"&&request.method==="GET"){const user=await getCurrentUser(request,env);return json({ok:true,authenticated:Boolean(user),user});}
  if (path==="/api/settings"&&request.method==="GET"){const auth=await (async()=>{const u=await new DevOneAuthService(createCloudflareServices(env).db).getCurrentUser(request);return u;})();if(!auth)return json({ok:false,error:"Authentication required."},401);const rows=await env.DB.prepare("SELECT setting_key,setting_value FROM settings ORDER BY setting_key").all();return json({ok:true,settings:rows.results});}
  if (path==="/api/settings"&&["POST","PUT","PATCH"].includes(request.method)){const auth=new DevOneAuthService(createCloudflareServices(env).db);try{await auth.requireCsrf(request);}catch(error){return json({ok:false,error:error instanceof Error?error.message:"CSRF validation failed."},403);}const user=await auth.getCurrentUser(request);if(!user)return json({ok:false,error:"Authentication required."},401);if(user.role!=="administrator")return json({ok:false,error:"Permission denied."},403);const body=await request.json().catch(()=>null) as {key?:string;value?:string}|null;const key=String(body?.key??"").trim();if(!/^[a-zA-Z0-9_.-]{1,120}$/.test(key))return json({ok:false,error:"Invalid setting key."},400);await setSetting(env,key,String(body?.value??""));return json({ok:true});}
  return json({ok:false,error:"API route not found."},404);
}
export default {async fetch(request:Request,env:Env):Promise<Response>{try{const url=new URL(request.url);if(url.pathname.startsWith("/api/")){const response=await api(request,env),headers=new Headers(response.headers);for(const [key,value] of Object.entries(securityHeaders()))headers.set(key,value);return new Response(response.body,{status:response.status,headers});}return env.ASSETS.fetch(request);}catch(error){console.error("DevOne Worker error",error);return json({ok:false,error:"Internal server error."},500,securityHeaders());}}};
