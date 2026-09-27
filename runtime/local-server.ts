import { createServer } from "node:http";
import { createLocalRuntime } from "../adapters/local/runtime";
const port=Number(process.env.PORT??8080),host=process.env.HOST??"127.0.0.1";
await createLocalRuntime({dataDir:process.env.DEVONE_DATA_DIR??"./data",mediaDir:process.env.DEVONE_MEDIA_DIR,environment:process.env.NODE_ENV??"development"});
const server=createServer((req,res)=>{const url=new URL(req.url??"/","http://"+(req.headers.host??"localhost"));if(url.pathname==="/api/health"){res.writeHead(200,{"content-type":"application/json","cache-control":"no-store"});res.end(JSON.stringify({ok:true,product:"DevOne CMS",version:"2.0.0-alpha.2",runtime:"local"}));return;}res.writeHead(404,{"content-type":"application/json"});res.end(JSON.stringify({ok:false,error:"Route not found."}));});
server.listen(port,host,()=>console.log("DevOne local runtime: http://"+host+":"+port));
