import type { CoreUser, DatabaseProvider } from "../api/types";
import type { UserService } from "../auth/types";

export class DevOneUsers implements UserService {
  constructor(private readonly db: DatabaseProvider) {}

  async getById(id: number): Promise<CoreUser | null> {
    return this.db.first<CoreUser>("SELECT id, username, email, display_name, role, status FROM users WHERE id = ?1 LIMIT 1", id);
  }

  async getByUsername(username: string): Promise<CoreUser | null> {
    return this.db.first<CoreUser>("SELECT id, username, email, display_name, role, status FROM users WHERE lower(username) = lower(?1) LIMIT 1", username);
  }

  async create(input: { username: string; email: string; displayName: string; passwordHash: string; role?: string }): Promise<CoreUser> {
    const result = await this.db.run(
      "INSERT INTO users (username, password_hash, email, display_name, role, status) VALUES (?1, ?2, ?3, ?4, ?5, 'active')",
      input.username.toLowerCase(), input.passwordHash, input.email.toLowerCase(), input.displayName, input.role ?? "subscriber",
    );
    const user = await this.getById(result.lastInsertId ?? 0);
    if (!user) throw new Error("User could not be created.");
    return user;
  }

  async disable(id: number): Promise<void> {
    await this.db.run("UPDATE users SET status = 'disabled', updated_at = CURRENT_TIMESTAMP WHERE id = ?1", id);
  }
}
