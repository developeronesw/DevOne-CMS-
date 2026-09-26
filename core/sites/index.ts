import type { DatabaseProvider } from "../api/types";

export interface SiteRecord {
  id: number;
  siteName: string;
  siteSlug: string;
  primaryDomain: string;
  ownerUserId: number | null;
  status: string;
}

export class DevOneSites {
  constructor(private readonly db: DatabaseProvider) {}

  async getById(id: number): Promise<SiteRecord | null> {
    const row = await this.db.first<any>("SELECT id, site_name, site_slug, primary_domain, owner_user_id, status FROM sites WHERE id = ?1 LIMIT 1", id);
    return row ? { id: row.id, siteName: row.site_name, siteSlug: row.site_slug, primaryDomain: row.primary_domain, ownerUserId: row.owner_user_id, status: row.status } : null;
  }

  async canAccess(userId: number, siteId: number): Promise<boolean> {
    const row = await this.db.first<{ id: number }>(
      "SELECT id FROM site_users WHERE user_id = ?1 AND site_id = ?2 AND status = 'active' LIMIT 1", userId, siteId,
    );
    return Boolean(row);
  }
}
