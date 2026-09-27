import type { CoreUser, DatabaseProvider } from "../api/types";
import type { AuthService } from "./types";

export const PASSWORD_HASH_ITERATIONS=310_000;
export const PASSWORD_MIN_LENGTH=12;
export const PASSWORD_MAX_LENGTH=256;

const enc=new TextEncoder();
const b64=(b:Uint8Array)=>{let s="";for(const x of b)s+=String.fromCharCode(x);return btoa(s);};
const unb64=(s:string)=>Uint8Array.from(atob(s),c=>c.charCodeAt(0));
const eq=(a:Uint8Array,b:Uint8Array)=>{if(a.length!==b.length)return false;let d=0;for(let i=0;i<a.length;i++)d|=a[i]^b[i];return d===0;};
const hash=async(s:string)=>b64(new Uint8Array(await crypto.subtle.digest("SHA-256",enc.encode(s))));
const token=(n=32)=>{const b=new Uint8Array(n);crypto.getRandomValues(b);return b64(b).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/g,"");};
const cookies=(r:Request)=>Object.fromEntries((r.headers.get("cookie")??"").split(";").map(x=>x.trim()).filter(Boolean).map(x=>{const i=x.indexOf("=");return i<0?[x,""]:[x.slice(0,i),decodeURIComponent(x.slice(i+1))];}));
const valid=(p:string)=>p.length>=PASSWORD_MIN_LENGTH&&p.length<=PASSWORD_MAX_LENGTH;
export async function passwordHash(p:string){if(!valid(p))throw new Error("Invalid password length.");const salt=new Uint8Array(16);crypto.getRandomValues(salt);const k=await crypto.subtle.importKey("raw",enc.encode(p),"PBKDF2",false,["deriveBits"]);const bits=await crypto.subtle.deriveBits({name:"PBKDF2",salt,iterations:PASSWORD_HASH_ITERATIONS,hash:"SHA-256"},k,256);return ["pbkdf2","sha256",String(PASSWORD_HASH_ITERATIONS),b64(salt),b64(new Uint8Array(bits))].join("$");}
async function passwordVerify(p:string,stored:string){const a=stored.split("$");if(a.length!==5||a[0]!=="pbkdf2"||a[1]!=="sha256")return false;const n=Number(a[2]);if(!Number.isSafeInteger(n)||n<100000||n>2000000)return false;try{const salt=unb64(a[3]),expected=unb64(a[4]),k=await crypto.subtle.importKey("raw",enc.encode(p),"PBKDF2",false,["deriveBits"]),bits=await crypto.subtle.deriveBits({name:"PBKDF2",salt,iterations:n,hash:"SHA-256"},k,expected.length*8);return eq(new Uint8Array(bits),expected);}catch{return false;}}
const future=(days:number)=>new Date(Date.now()+days*86400000).toISOString().replace("T"," ").replace(/\.\d{3}Z$/,"");
const setCookie=(t:string,age:number,secure:boolean)=>["devone_session="+encodeURIComponent(t),"Path=/","Max-Age="+age,"HttpOnly",...(secure?["Secure"]:[]),"SameSite=Lax"].join("; ");
const clearCookie=(secure:boolean)=>"devone_session=; Path=/; Max-Age=0; HttpOnly"+(secure?"; Secure":"")+"; SameSite=Lax";

export interface AuthResult{user:CoreUser;csrfToken:string;expiresAt:string;sessionCookie:string;}
export interface DevOneAuthOptions{secureCookies?:boolean;sessionDays?:number;}

export class DevOneAuthService implements AuthService{
  private secure:boolean; private days:number;
  constructor(private db:DatabaseProvider,o:DevOneAuthOptions={}){this.secure=o.secureCookies??true;this.days=o.sessionDays??7;}
  private async session(r:Request){const c=cookies(r).devone_session;if(!c)return null;return this.db.first<{id:string;user_id:number;expires_at:string;csrf_token_hash:string}>("SELECT id,user_id,expires_at,csrf_token_hash FROM sessions WHERE token_hash=?1 AND expires_at>datetime('now') AND revoked_at IS NULL LIMIT 1",await hash(c));}
  async getCurrentUser(r:Request){const s=await this.session(r);if(!s)return null;const u=await this.db.first<CoreUser>("SELECT id,username,email,display_name,role,status FROM users WHERE id=?1 AND status='active' LIMIT 1",s.user_id);if(u)await this.db.run("UPDATE sessions SET last_seen_at=datetime('now') WHERE id=?1",s.id);return u;}
  private async audit(id:number|null,action:string){try{await this.db.run("INSERT INTO activity_logs(user_id,action) VALUES(?1,?2)",id,action);}catch{}}
  async login(r:Request,username:string,password:string):Promise<AuthResult>{
    username=username.trim().toLowerCase();
    if(!/^[a-z0-9][a-z0-9._-]{2,99}$/.test(username)||password.length<1||password.length>PASSWORD_MAX_LENGTH)throw new Error("Invalid credentials.");
    const u=await this.db.first<CoreUser&{password_hash:string}>("SELECT id,username,email,display_name,role,status,password_hash FROM users WHERE lower(username)=?1 LIMIT 1",username);
    if(!u||u.status!=="active"||!(await passwordVerify(password,u.password_hash))){await this.audit(null,"auth.login_failed");throw new Error("Invalid credentials.");}
    const parts=u.password_hash.split("$");if(parts.length!==5||Number(parts[2])!==PASSWORD_HASH_ITERATIONS)await this.db.run("UPDATE users SET password_hash=?,password_changed_at=datetime('now'),updated_at=datetime('now') WHERE id=?",await passwordHash(password),u.id);
    const sessionToken=token(),csrfToken=token(),id=crypto.randomUUID(),expiresAt=future(this.days);
    await this.db.run("INSERT INTO sessions(id,user_id,token_hash,csrf_token_hash,expires_at) VALUES(?,?,?,?,?)",id,u.id,await hash(sessionToken),await hash(csrfToken),expiresAt);
    await this.audit(u.id,"auth.login");
    return {user:u,csrfToken,expiresAt,sessionCookie:setCookie(sessionToken,this.days*86400,this.secure)};
  }
  async requireCsrf(r:Request){const s=await this.session(r);if(!s)throw new Error("Authentication required.");const supplied=unb64(await hash(r.headers.get("x-devone-csrf")??"")),expected=unb64(s.csrf_token_hash);if(!eq(supplied,expected))throw new Error("CSRF validation failed.");}
  async logout(r:Request){const s=await this.session(r);if(!s)return clearCookie(this.secure);await this.requireCsrf(r);await this.db.run("UPDATE sessions SET revoked_at=datetime('now') WHERE id=?",s.id);await this.audit(s.user_id,"auth.logout");return clearCookie(this.secure);}
  async revokeUserSessions(userId:number){await this.db.run("UPDATE sessions SET revoked_at=datetime('now') WHERE user_id=? AND revoked_at IS NULL",userId);}
}
export const authErrorStatus=(e:unknown)=>{const x=e as {message?:string};return x.message==="Authentication required."?401:x.message==="CSRF validation failed."?403:400;};
