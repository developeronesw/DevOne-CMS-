import type {
  CacheProvider,
  DatabaseProvider,
  MediaProvider,
  MediaObject,
} from "../../core/api/types";
import type { Env } from "../../worker/types";

export class CloudflareDatabase implements DatabaseProvider {
  constructor(private readonly db: D1Database) {}

  async first<T = Record<string, unknown>>(sql: string, ...params: unknown[]): Promise<T | null> {
    return await this.db.prepare(sql).bind(...params).first<T>();
  }

  async all<T = Record<string, unknown>>(sql: string, ...params: unknown[]): Promise<T[]> {
    const result = await this.db.prepare(sql).bind(...params).all<T>();
    return result.results ?? [];
  }

  async run(sql: string, ...params: unknown[]): Promise<{ changes: number; lastInsertId?: number }> {
    const result = await this.db.prepare(sql).bind(...params).run();
    return {
      changes: result.meta?.changes ?? 0,
      lastInsertId: result.meta?.last_row_id,
    };
  }

  async batch(statements: Array<{ sql: string; params?: unknown[] }>): Promise<void> {
    const prepared = statements.map((statement) =>
      this.db.prepare(statement.sql).bind(...(statement.params ?? [])),
    );
    await this.db.batch(prepared);
  }

}

export class CloudflareCache implements CacheProvider {
  constructor(private readonly kv: KVNamespace) {}

  async get<T = unknown>(key: string): Promise<T | null> {
    return await this.kv.get<T>(key, "json");
  }

  async set<T = unknown>(key: string, value: T, ttlSeconds?: number): Promise<void> {
    if (ttlSeconds && ttlSeconds > 0) {
      await this.kv.put(key, JSON.stringify(value), { expirationTtl: Math.floor(ttlSeconds) });
      return;
    }
    await this.kv.put(key, JSON.stringify(value));
  }

  async delete(key: string): Promise<void> {
    await this.kv.delete(key);
  }
}

export class CloudflareMedia implements MediaProvider {
  constructor(private readonly bucket: R2Bucket) {}

  async put(
    key: string,
    body: ReadableStream | ArrayBuffer | Uint8Array | string,
    options: { contentType?: string; metadata?: Record<string, string> } = {},
  ): Promise<MediaObject> {
    const object = await this.bucket.put(key, body, {
      httpMetadata: options.contentType ? { contentType: options.contentType } : undefined,
      customMetadata: options.metadata,
    });
    if (!object) throw new Error("Media object could not be stored.");
    return {
      key,
      contentType: options.contentType ?? "",
      size: object.size,
      etag: object.etag,
      metadata: options.metadata,
    };
  }

  async get(key: string): Promise<Response | null> {
    const object = await this.bucket.get(key);
    if (!object) return null;
    const headers = new Headers();
    object.writeHttpMetadata(headers);
    headers.set("etag", object.httpEtag);
    headers.set("content-length", String(object.size));
    return new Response(object.body, { headers });
  }

  async delete(key: string): Promise<void> {
    await this.bucket.delete(key);
  }

  async exists(key: string): Promise<boolean> {
    return Boolean(await this.bucket.head(key));
  }
}

export function createCloudflareServices(env: Env) {
  return {
    db: new CloudflareDatabase(env.DB),
    cache: new CloudflareCache(env.CACHE),
    media: new CloudflareMedia(env.MEDIA),
    config: {
      runtime: "cloudflare",
      environment: env.DEVONE_ENVIRONMENT ?? "production",
    },
  };
}
