import { mkdir, readFile, writeFile, unlink, stat, access } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import type { DatabaseProvider, CacheProvider, MediaProvider, MediaObject } from "../../core/api/types";
import type { LocalRuntimeOptions } from "./runtime";
export class LocalDatabase implements DatabaseProvider {
 constructor(private readonly db:import("node:sqlite").DatabaseSync){}
 async first<T=Record<string,unknown>>(sql:string,...params:unknown[]):Promise<T|null>{return (this.db.prepare(sql).get(...params) as T|undefined)??null;}
 async all<T=Record<string,unknown>>(sql:string,...params:unknown[]):Promise<T[]>{return this.db.prepare(sql).all(...params) as T[];}
 async run(sql:string,...params:unknown[]):Promise<{changes:number;lastInsertId?:number}>{const r=this.db.prepare(sql).run(...params) as {changes:bigint;lastInsertRowid:bigint};return {changes:Number(r.changes),lastInsertId:Number(r.lastInsertRowid)};}
 async batch(statements:Array<{sql:string;params?:unknown[]}>):Promise<void>{this.db.exec("BEGIN IMMEDIATE;");try{for(const s of statements)this.db.prepare(s.sql).run(...(s.params??[]));this.db.exec("COMMIT;");}catch(error){try{this.db.exec("ROLLBACK;")}catch{};throw error;}}
}
export class LocalCache implements CacheProvider {
 private readonly values=new Map<string,{value:unknown;expires:number|null}>();
 async get<T>(key:string):Promise<T|null>{const hit=this.values.get(key);if(!hit)return null;if(hit.expires!==null&&hit.expires<=Date.now()){this.values.delete(key);return null;}return hit.value as T;}
 async set<T>(key:string,value:T,ttlSeconds?:number):Promise<void>{this.values.set(key,{value,expires:ttlSeconds&&ttlSeconds>0?Date.now()+ttlSeconds*1000:null});}
 async delete(key:string):Promise<void>{this.values.delete(key);}
}
export class LocalMedia implements MediaProvider {
 private readonly root:string;constructor(root:string){this.root=resolve(root);}
 private safe(key:string):string{const normalized=key.replace(/\\/g,"/").replace(/^\/+/, "");const path=resolve(this.root,normalized);if(path!==this.root&&!path.startsWith(this.root+"/"))throw new Error("Invalid media key.");return path;}
 async put(key:string,body:ReadableStream|ArrayBuffer|Uint8Array|string,options:{contentType?:string;metadata?:Record<string,string>}={}):Promise<MediaObject>{const path=this.safe(key);await mkdir(dirname(path),{recursive:true});let data:Uint8Array;if(typeof body==="string")data=new TextEncoder().encode(body);else if(body instanceof Uint8Array)data=body;else if(body instanceof ArrayBuffer)data=new Uint8Array(body);else data=new Uint8Array(await new Response(body).arrayBuffer());await writeFile(path,data);const info=await stat(path);return {key,contentType:options.contentType??"",size:info.size,metadata:options.metadata};}
 async get(key:string):Promise<Response|null>{try{return new Response(await readFile(this.safe(key)));}catch(e){if((e as NodeJS.ErrnoException).code==="ENOENT")return null;throw e;}}
 async delete(key:string):Promise<void>{try{await unlink(this.safe(key));}catch(e){if((e as NodeJS.ErrnoException).code!=="ENOENT")throw e;}}
 async exists(key:string):Promise<boolean>{try{await access(this.safe(key));return true;}catch{return false;}}
}
export function createLocalServices(options:LocalRuntimeOptions){return {db:new LocalDatabase(options.database!),cache:new LocalCache(),media:new LocalMedia(options.mediaDir!),config:{runtime:"local",environment:options.environment??"development",media_dir:options.mediaDir}};}
