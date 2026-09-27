import type { ApiHandler, ApiRoute, CoreApiContext, CoreApiOptions, CoreRequest, CoreResponse, HttpMethod } from "./types";

const METHODS: HttpMethod[] = ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"];
const STATE_CHANGING_METHODS = new Set<HttpMethod>(["POST", "PUT", "PATCH", "DELETE"]);
const DEFAULT_MAX_BODY_BYTES = 1024 * 1024;

function normalizePath(path: string): string {
  const normalized = path.replace(/\/+$/, "");
  return normalized || "/";
}

function compilePath(path: string): { regex: RegExp; names: string[] } {
  const names: string[] = [];
  const pattern = normalizePath(path).split("/").map((segment) => {
    if (segment.startsWith(":")) {
      names.push(segment.slice(1));
      return "([^/]+)";
    }
    if (segment === "*") {
      names.push("wildcard");
      return "(.*)";
    }
    return segment.replace(/[-/\\^$*+?.()|[\]{}]/g, "\\$&");
  }).join("/");
  return { regex: new RegExp("^" + pattern + "/?$"), names };
}

interface CompiledRoute extends ApiRoute {
  methods: HttpMethod[];
  regex: RegExp;
  names: string[];
}

export class ApiRouter {
  private readonly routes: CompiledRoute[] = [];

  register(route: ApiRoute): this {
    const methods = Array.isArray(route.method) ? route.method : [route.method];
    for (const method of methods) {
      if (!METHODS.includes(method)) throw new Error("Unsupported API method: " + method);
    }
    const compiled = compilePath(route.path);
    this.routes.push({ ...route, methods, ...compiled });
    return this;
  }

  get(path: string, handler: ApiHandler, options: Omit<ApiRoute, "method" | "path" | "handler"> = {}): this {
    return this.register({ ...options, method: "GET", path, handler });
  }

  post(path: string, handler: ApiHandler, options: Omit<ApiRoute, "method" | "path" | "handler"> = {}): this {
    return this.register({ ...options, method: "POST", path, handler });
  }

  put(path: string, handler: ApiHandler, options: Omit<ApiRoute, "method" | "path" | "handler"> = {}): this {
    return this.register({ ...options, method: "PUT", path, handler });
  }

  patch(path: string, handler: ApiHandler, options: Omit<ApiRoute, "method" | "path" | "handler"> = {}): this {
    return this.register({ ...options, method: "PATCH", path, handler });
  }

  delete(path: string, handler: ApiHandler, options: Omit<ApiRoute, "method" | "path" | "handler"> = {}): this {
    return this.register({ ...options, method: "DELETE", path, handler });
  }

  match(method: HttpMethod, path: string): { route: CompiledRoute; params: Record<string, string> } | null {
    const normalized = normalizePath(path);
    for (const route of this.routes) {
      if (!route.methods.includes(method)) continue;
      const match = route.regex.exec(normalized);
      if (!match) continue;
      const params: Record<string, string> = {};
      route.names.forEach((name, index) => {
        params[name] = decodeURIComponent(match[index + 1] ?? "");
      });
      return { route, params };
    }
    return null;
  }
}

export function ok<T>(body: T, status = 200, headers?: HeadersInit): CoreResponse<T> {
  return { status, body, headers };
}

export function fail(error: string, status = 400, code?: string, details?: unknown): CoreResponse {
  return {
    status,
    body: {
      ok: false,
      error,
      ...(code ? { code } : {}),
      ...(details === undefined ? {} : { details }),
    },
  };
}

function hasJsonContentType(request: Request): boolean {
  const contentType = request.headers.get("content-type") ?? "";
  return contentType.toLowerCase().split(";")[0].trim() === "application/json";
}

async function parseBody(request: Request, maxBodyBytes: number): Promise<{ body?: unknown; error?: CoreResponse }> {
  const contentLength = request.headers.get("content-length");
  if (contentLength && Number.isFinite(Number(contentLength)) && Number(contentLength) > maxBodyBytes) {
    return { error: fail("Request body is too large.", 413, "body_too_large") };
  }

  if (!request.body) return { body: undefined };

  const contentType = request.headers.get("content-type") ?? "";
  if (!hasJsonContentType(request)) {
    return { error: fail("State-changing API requests must use application/json.", 415, "unsupported_media_type") };
  }

  const body = await request.arrayBuffer();
  if (body.byteLength > maxBodyBytes) {
    return { error: fail("Request body is too large.", 413, "body_too_large") };
  }

  if (body.byteLength === 0) return { error: fail("Request body is required.", 400, "empty_body") };

  try {
    return { body: JSON.parse(new TextDecoder().decode(body)) };
  } catch {
    return { error: fail("Malformed JSON request body.", 400, "invalid_json") };
  }
}

export class DevOneApi {
  constructor(
    private readonly router: ApiRouter,
    private readonly options: CoreApiOptions,
  ) {}

  async handle(request: Request): Promise<Response> {
    const url = new URL(request.url);
    const method = request.method as HttpMethod;
    if (!METHODS.includes(method)) return this.json(fail("Method not allowed.", 405, "method_not_allowed"));

    const matched = this.router.match(method, url.pathname);
    if (!matched) return this.json(fail("API route not found.", 404, "route_not_found"));

    const route = matched.route;
    const isStateChanging = STATE_CHANGING_METHODS.has(method);
    const bodyResult = isStateChanging
      ? await parseBody(request, this.options.maxBodyBytes ?? DEFAULT_MAX_BODY_BYTES)
      : { body: undefined };

    if (bodyResult.error) return this.json(bodyResult.error);
    const body = bodyResult.body;

    let user: CoreApiContext["user"] = null;
    try {
      user = this.options.authenticate ? await this.options.authenticate(request) : null;
    } catch {
      return this.json(fail("Authentication could not be completed.", 500, "authentication_error"));
    }

    const siteId = this.options.resolveSite ? await this.options.resolveSite(request, user) : null;

    if (!route.public && !user) {
      return this.json(fail("Authentication required.", 401, "authentication_required"));
    }

    if (route.csrf !== false && isStateChanging && user && this.options.validateCsrf) {
      const valid = await this.options.validateCsrf(request, user);
      if (!valid) return this.json(fail("CSRF validation failed.", 403, "csrf_failed"));
    }

    if (route.permission && this.options.authorize) {
      const allowed = await this.options.authorize(user, route.permission, siteId);
      if (!allowed) return this.json(fail("Permission denied.", 403, "permission_denied"));
    }

    const coreRequest: CoreRequest = {
      method,
      path: url.pathname,
      headers: request.headers,
      query: url.searchParams,
      params: matched.params,
      body,
      raw: request,
    };

    const context: CoreApiContext = {
      user,
      siteId,
      services: this.options.services,
      body,
    };

    try {
      return this.json(await route.handler(coreRequest, context));
    } catch (error) {
      console.error("DevOne Core API error", error);
      return this.json(fail("Internal server error.", 500, "internal_error"));
    }
  }

  private json(result: CoreResponse): Response {
    const headers = new Headers(result.headers);
    headers.set("content-type", "application/json; charset=utf-8");
    headers.set("cache-control", "no-store");
    return new Response(JSON.stringify(result.body ?? null), { status: result.status, headers });
  }
}
