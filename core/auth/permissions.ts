import type { CoreUser, DatabaseProvider } from "../api/types";
import type { PermissionService } from "./types";

function parsePermissionList(value: string | null | undefined): string[] {
  if (!value) return [];
  try {
    const parsed: unknown = JSON.parse(value);
    return Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === "string") : [];
  } catch {
    return [];
  }
}

function permissionMatches(granted: string, required: string): boolean {
  if (granted === "*") return true;
  if (granted === required) return true;
  if (granted.endsWith(".*")) return required.startsWith(granted.slice(0, -1));
  return false;
}

export class DevOnePermissions implements PermissionService {
  constructor(private readonly db: DatabaseProvider) {}

  async has(user: CoreUser | null, permission: string, siteId: number | null = null): Promise<boolean> {
    if (!user || user.status !== "active") return false;
    if (user.role === "administrator") return true;

    const role = await this.db.first<{ permissions: string }>(
      "SELECT permissions FROM roles WHERE name = ?1 LIMIT 1", user.role,
    );
    const granted: string[] = parsePermissionList(role?.permissions);
    const override = await this.db.first<{ permissions_override: string | null }>(
      "SELECT permissions_override FROM users WHERE id = ?1 LIMIT 1", user.id,
    );
    const extra: string[] = parsePermissionList(override?.permissions_override);
    const allowed = [...granted, ...extra].some((item) => permissionMatches(item, permission));
    if (!allowed) return false;
    if (!siteId || user.role === "administrator") return true;

    const membership = await this.db.first<{ id: number }>(
      "SELECT id FROM site_users WHERE site_id = ?1 AND user_id = ?2 AND status = 'active' LIMIT 1",
      siteId, user.id,
    );
    return Boolean(membership);
  }

  async require(user: CoreUser | null, permission: string, siteId: number | null = null): Promise<void> {
    if (!(await this.has(user, permission, siteId))) throw new Error("Permission denied.");
  }
}

export const SYSTEM_PERMISSIONS = [
  "system.read","system.manage","users.read","users.create","users.update","users.disable",
  "roles.read","roles.manage","sites.read","sites.create","sites.update","sites.manage",
  "content.read","content.create","content.update","content.delete",
  "media.read","media.create","media.update","media.delete",
  "themes.read","themes.manage","plugins.read","plugins.manage","apps.read","apps.manage",
] as const;
