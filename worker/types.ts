import type { CloudflareEmailBinding } from "../adapters/cloudflare/mail";
import type { D1Database, KVNamespace, R2Bucket, Fetcher } from "@cloudflare/workers-types";

export interface Env {
  DB: D1Database;
  CACHE: KVNamespace;
  MEDIA: R2Bucket;
  ASSETS: Fetcher;
  EMAIL?: CloudflareEmailBinding;
  DEVONE_SESSION_SECRET?: string;
  DEVONE_ENVIRONMENT?: string;
  DEVONE_SECRET_KEY?: string;
}

export interface AuthUser {
  id: number;
  username: string;
  email: string;
  display_name: string;
  role: string;
  status: string;
}

export interface SessionRecord {
  id: string;
  user_id: number;
  expires_at: string;
  csrf_token_hash: string;
}

export interface ApiContext {
  request: Request;
  env: Env;
  user: AuthUser | null;
}
