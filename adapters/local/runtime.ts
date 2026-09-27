import { mkdir } from "node:fs/promises";
import { resolve } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { createLocalServices } from "./providers";
export interface LocalRuntimeOptions {dataDir:string;mediaDir?:string;environment?:string;database?:DatabaseSync;}
export async function createLocalRuntime(options:LocalRuntimeOptions){
 const dataDir=resolve(options.dataDir);await mkdir(dataDir,{recursive:true});const mediaDir=resolve(options.mediaDir??(dataDir+"/media"));await mkdir(mediaDir,{recursive:true});
 const database=options.database??new DatabaseSync(resolve(dataDir,"devone.sqlite"));database.exec("PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;");
 return createLocalServices({...options,dataDir,mediaDir,database});
}
