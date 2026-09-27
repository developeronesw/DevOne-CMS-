import type { CoreUser } from "../api/types";

export interface AuthSession {
  id: string;
  userId: number;
  expiresAt: string;
  csrfTokenHash: string;
}

export interface AuthService {
  getCurrentUser(request: Request): Promise<CoreUser | null>;
  login(request: Request, username: string, password: string): Promise<{ user: CoreUser; csrfToken: string; expiresAt: string; sessionCookie: string }>;
  logout(request: Request): Promise<string>;
  requireCsrf(request: Request): Promise<void>;
  revokeUserSessions(userId: number): Promise<void>;
}

export interface PermissionService {
  has(user: CoreUser | null, permission: string, siteId?: number | null): Promise<boolean>;
  require(user: CoreUser | null, permission: string, siteId?: number | null): Promise<void>;
}

export interface UserService {
  getById(id: number): Promise<CoreUser | null>;
  getByUsername(username: string): Promise<CoreUser | null>;
  create(input: { username: string; email: string; displayName: string; passwordHash: string; role?: string }): Promise<CoreUser>;
  disable(id: number): Promise<void>;
}
