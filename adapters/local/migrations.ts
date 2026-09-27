import { readdir, readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import type { DatabaseSync } from "node:sqlite";

export async function runLocalMigrations(db:DatabaseSync,migrationsDir=new URL("../../migrations/",import.meta.url)){
 db.exec("CREATE TABLE IF NOT EXISTS devone_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);");
 const dirPath=resolve(fileURLToPath(migrationsDir));
 const names=(await readdir(dirPath)).filter(n=>/^\d+_.+\.sql$/.test(n)).sort();
 for(const name of names){
  const exists=db.prepare("SELECT name FROM devone_migrations WHERE name=?1").get(name) as {name:string}|undefined;
  if(exists)continue;
  const sql=await readFile(resolve(dirPath,name),"utf8");
  db.exec("BEGIN IMMEDIATE;");
  try{db.exec(sql);db.prepare("INSERT INTO devone_migrations(name) VALUES(?1)").run(name);db.exec("COMMIT;");}
  catch(error){try{db.exec("ROLLBACK;")}catch{};throw new Error("Migration failed: "+name,{cause:error});}
 }
}
