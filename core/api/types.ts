export type HttpMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE" | "OPTIONS";

export interface CoreRequest {
  method: HttpMethod;
  path: string;
  headers: Headers;
  query: URLSearchParams;
  params: Record<string, string>;
  body?: unknown;
  raw: Request;
}

export interface CoreResponse<T = unknown> {
  status: number;
  body?: T;
  headers?: HeadersInit;
}

export type ApiHandler<T = unknown> = (
  request: CoreRequest,
  context: CoreApiContext,
) => Promise<CoreResponse<T>> | CoreResponse<T>;

export interface ApiRoute {
  method: HttpMethod | HttpMethod[];
  path: string;
  handler: ApiHandler;
  permission?: string;
  public?: boolean;
}

export interface CoreUser {
  id: number;
  username: string;
  email: string;
  display_name: string;
  role: string;
  status: string;
}

export interface DatabaseProvider {
  first<T = Record<string, unknown>>(sql: string, ...params: unknown[]): Promise<T | null>;
  all<T = Record<string, unknown>>(sql: string, ...params: unknown[]): Promise<T[]>;
  run(sql: string, ...params: unknown[]): Promise<{ changes: number; lastInsertId?: number }>;
  batch(statements: Array<{ sql: string; params?: unknown[] }>): Promise<void>;
  transaction<T>(callback: (db: DatabaseProvider) => Promise<T>): Promise<T>;
}

export interface CacheProvider {
  get<T = unknown>(key: string): Promise<T | null>;
  set<T = unknown>(key: string, value: T, ttlSeconds?: number): Promise<void>;
  delete(key: string): Promise<void>;
}

export interface MediaObject {
  key: string;
  contentType: string;
  size: number;
  etag?: string;
  metadata?: Record<string, string>;
}

export interface MediaProvider {
  put(key: string, body: ReadableStream | ArrayBuffer | Uint8Array | string, options?: { contentType?: string; metadata?: Record<string, string> }): Promise<MediaObject>;
  get(key: string): Promise<Response | null>;
  delete(key: string): Promise<void>;
  exists(key: string): Promise<boolean>;
}

export interface CoreServices {
  db: DatabaseProvider;
  cache: CacheProvider;
  media: MediaProvider;
  config: Record<string, unknown>;
}

export interface CoreApiContext {
  user: CoreUser | null;
  siteId: number | null;
  services: CoreServices;
}

export interface CoreApiOptions {
  services: CoreServices;
  authenticate?: (request: Request) => Promise<CoreUser | null>;
  resolveSite?: (request: Request, user: CoreUser | null) => Promise<number | null>;
  authorize?: (user: CoreUser | null, permission: string, siteId: number | null) => Promise<boolean>;
}
