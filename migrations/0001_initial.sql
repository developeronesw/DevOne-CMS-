-- DevOne CMS 2.0.0-alpha.2
-- Cloudflare D1 / SQLite foundation.
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  setting_key TEXT NOT NULL UNIQUE,
  setting_value TEXT
);

CREATE TABLE IF NOT EXISTS roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  description TEXT,
  permissions TEXT NOT NULL DEFAULT '[]'
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL,
  email TEXT NOT NULL DEFAULT '',
  display_name TEXT NOT NULL DEFAULT '',
  avatar TEXT NOT NULL DEFAULT '',
  bio TEXT,
  role TEXT NOT NULL DEFAULT 'subscriber',
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','pending')),
  permissions_override TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
  id TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  csrf_token_hash TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions(expires_at);

CREATE TABLE IF NOT EXISTS sites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_name TEXT NOT NULL,
  site_slug TEXT NOT NULL UNIQUE,
  auto_subdomain TEXT NOT NULL DEFAULT '',
  primary_domain TEXT NOT NULL DEFAULT '',
  domain_mode TEXT NOT NULL DEFAULT 'auto',
  owner_user_id INTEGER,
  admin_username TEXT NOT NULL DEFAULT '',
  admin_email TEXT NOT NULL DEFAULT '',
  allow_client_domain INTEGER NOT NULL DEFAULT 1 CHECK(allow_client_domain IN (0,1)),
  allow_client_theme_uploads INTEGER NOT NULL DEFAULT 0 CHECK(allow_client_theme_uploads IN (0,1)),
  status TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_sites_owner ON sites(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_sites_status ON sites(status);

CREATE TABLE IF NOT EXISTS site_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  role TEXT NOT NULL DEFAULT 'site_admin',
  status TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(site_id,user_id),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_site_users_user ON site_users(user_id);

CREATE TABLE IF NOT EXISTS site_domains (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL,
  domain TEXT NOT NULL UNIQUE,
  domain_type TEXT NOT NULL DEFAULT 'custom',
  is_primary INTEGER NOT NULL DEFAULT 0 CHECK(is_primary IN (0,1)),
  verification_token TEXT NOT NULL DEFAULT '',
  verification_status TEXT NOT NULL DEFAULT 'pending',
  ssl_status TEXT NOT NULL DEFAULT 'unknown',
  created_by INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at TEXT,
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_site_domains_site ON site_domains(site_id);

CREATE TABLE IF NOT EXISTS site_settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL,
  setting_key TEXT NOT NULL,
  setting_value TEXT,
  UNIQUE(site_id,setting_key),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL DEFAULT 1,
  slug TEXT NOT NULL,
  title TEXT NOT NULL,
  content TEXT NOT NULL DEFAULT '',
  template TEXT NOT NULL DEFAULT 'default',
  show_title INTEGER NOT NULL DEFAULT 1 CHECK(show_title IN (0,1)),
  status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('draft','published')),
  author_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(site_id,slug),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
  FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_pages_site_status ON pages(site_id,status);
CREATE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug);

CREATE TABLE IF NOT EXISTS media (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL DEFAULT 1,
  filename TEXT NOT NULL,
  object_key TEXT NOT NULL UNIQUE,
  mime_type TEXT NOT NULL DEFAULT '',
  size_bytes INTEGER NOT NULL DEFAULT 0,
  alt_text TEXT NOT NULL DEFAULT '',
  title TEXT NOT NULL DEFAULT '',
  caption TEXT,
  metadata_json TEXT,
  updated_at TEXT,
  folder TEXT NOT NULL DEFAULT 'other',
  media_type TEXT NOT NULL DEFAULT 'other',
  user_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_media_site ON media(site_id);
CREATE INDEX IF NOT EXISTS idx_media_user ON media(user_id);

CREATE TABLE IF NOT EXISTS menus (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL DEFAULT 1,
  name TEXT NOT NULL,
  slug TEXT NOT NULL,
  items TEXT NOT NULL DEFAULT '[]',
  UNIQUE(site_id,slug),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS themes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL DEFAULT 1,
  name TEXT NOT NULL,
  folder TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 0 CHECK(active IN (0,1)),
  UNIQUE(site_id,folder),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS theme_packages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  theme_slug TEXT NOT NULL,
  theme_name TEXT NOT NULL,
  theme_version TEXT NOT NULL DEFAULT '1.0.0',
  package_hash TEXT NOT NULL UNIQUE,
  storage_key TEXT NOT NULL,
  visibility TEXT NOT NULL DEFAULT 'private',
  uploaded_by INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS site_theme_entitlements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL,
  package_id INTEGER NOT NULL,
  theme_slug TEXT NOT NULL,
  source TEXT NOT NULL DEFAULT 'upload',
  license_owner TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(site_id,theme_slug),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
  FOREIGN KEY(package_id) REFERENCES theme_packages(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS site_themes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL,
  theme_name TEXT NOT NULL,
  theme_slug TEXT NOT NULL,
  theme_version TEXT NOT NULL DEFAULT '1.0.0',
  theme_author TEXT NOT NULL DEFAULT '',
  theme_path TEXT NOT NULL,
  theme_type TEXT NOT NULL DEFAULT 'custom',
  visibility TEXT NOT NULL DEFAULT 'private',
  status TEXT NOT NULL DEFAULT 'inactive',
  uploaded_by INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(site_id,theme_slug),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
  FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS plugins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  folder TEXT NOT NULL UNIQUE,
  version TEXT NOT NULL DEFAULT '1.0.0',
  description TEXT,
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS libraries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  folder TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'js' CHECK(type IN ('js','css')),
  source TEXT NOT NULL DEFAULT 'local' CHECK(source IN ('local','cdn')),
  url TEXT NOT NULL DEFAULT '',
  description TEXT,
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  version TEXT NOT NULL DEFAULT '1.0.0',
  manifest TEXT,
  scope TEXT NOT NULL DEFAULT 'frontend',
  sort_order INTEGER NOT NULL DEFAULT 100,
  integrity TEXT NOT NULL DEFAULT '',
  crossorigin TEXT NOT NULL DEFAULT 'anonymous',
  defer_load INTEGER NOT NULL DEFAULT 1 CHECK(defer_load IN (0,1)),
  async_load INTEGER NOT NULL DEFAULT 0 CHECK(async_load IN (0,1)),
  module_script INTEGER NOT NULL DEFAULT 0 CHECK(module_script IN (0,1)),
  last_error TEXT
);

CREATE TABLE IF NOT EXISTS api_endpoints (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  path TEXT NOT NULL,
  method TEXT NOT NULL DEFAULT 'GET',
  source_table TEXT NOT NULL,
  auth_required INTEGER NOT NULL DEFAULT 0 CHECK(auth_required IN (0,1)),
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_api_endpoints_route ON api_endpoints(path,method);

CREATE TABLE IF NOT EXISTS modules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  description TEXT,
  folder TEXT NOT NULL UNIQUE,
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS apps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL DEFAULT 1,
  name TEXT NOT NULL,
  folder TEXT NOT NULL,
  version TEXT NOT NULL DEFAULT '1.0.0',
  description TEXT,
  mount_path TEXT NOT NULL,
  entry_file TEXT NOT NULL,
  capabilities TEXT NOT NULL DEFAULT '[]',
  manifest TEXT NOT NULL DEFAULT '{}',
  active INTEGER NOT NULL DEFAULT 0 CHECK(active IN (0,1)),
  status TEXT NOT NULL DEFAULT 'installed',
  last_error TEXT,
  installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(site_id,folder),
  UNIQUE(site_id,mount_path),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activity_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER,
  action TEXT NOT NULL,
  details TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs(created_at);

CREATE TABLE IF NOT EXISTS webhooks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  event TEXT NOT NULL,
  target_url TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1))
);

CREATE TABLE IF NOT EXISTS auth_rate_limits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key TEXT NOT NULL UNIQUE,
  attempts INTEGER NOT NULL DEFAULT 0,
  window_started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  blocked_until TEXT
);
