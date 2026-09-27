import type { DatabaseProvider } from "../api/types";
import type { LicenseService } from "../license";

export interface CreateSiteInput {
  siteName: string;
  siteSlug: string;
  ownerUserId: number;
  primaryDomain?: string;
}

export class DevOneSiteService {
  constructor(
    private readonly db: DatabaseProvider,
    private readonly license: LicenseService,
  ) {}

  async count(): Promise<number> {
    const row = await this.db.first<{ count: number }>("SELECT COUNT(*) AS count FROM sites");
    return Number(row?.count ?? 0);
  }

  async canCreate(): Promise<boolean> {
    return this.license.canCreateSite(await this.count());
  }

  async create(input: CreateSiteInput): Promise<number> {
    const current = await this.count();
    if (!(await this.license.canCreateSite(current))) {
      throw new Error("This installation is limited to one site. A valid Network license is required for additional sites.");
    }

    const result = await this.db.run(
      "INSERT INTO sites (site_name, site_slug, primary_domain, owner_user_id, admin_username, status) VALUES (?1, ?2, ?3, ?4, '', 'active')",
      input.siteName, input.siteSlug, input.primaryDomain ?? "", input.ownerUserId,
    );
    if (!result.lastInsertId) throw new Error("Site could not be created.");
    return result.lastInsertId;
  }
}
