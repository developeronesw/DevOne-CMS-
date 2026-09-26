<?php
/**
 * DevOne Network / Multisite foundation.
 * v1.0.4 preview foundation for Pro/Enterprise license gates.
 *
 * This file intentionally keeps feature locking behind devone_feature_enabled().
 * When the license server is added, replace the local setting checks in that
 * function with the signed-license feature map.
 */

function devone_network_clean_domain($domain) {
    $domain = strtolower(trim((string)$domain));
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    $domain = preg_replace('/:\d+$/', '', $domain);
    $domain = preg_replace('/[^a-z0-9._-]/', '', $domain);
    return trim($domain, '.');
}

function devone_network_current_host() {
    return devone_network_clean_domain($_SERVER['HTTP_HOST'] ?? '');
}

function devone_network_base_domain() {
    $stored = function_exists('get_setting') ? get_setting('network_base_domain', '') : '';
    $stored = devone_network_clean_domain($stored);
    if ($stored !== '') { return $stored; }
    $site = defined('SITE_URL') ? SITE_URL : '';
    $host = parse_url($site, PHP_URL_HOST);
    return devone_network_clean_domain($host ?: devone_network_current_host());
}

if (!function_exists('devone_license_plan')) {
function devone_license_plan() {
    return 'free';
}
}

function devone_feature_enabled($feature) {
    $feature = strtolower(trim((string)$feature));
    if ($feature === '') { return false; }

    // Local developer override for internal testing only. Do not enable in production builds.
    if (defined('DEVONE_UNLOCK_PRO_FEATURES') && DEVONE_UNLOCK_PRO_FEATURES) { return true; }

    $proFeatures = array(
        'multisite', 'network_sites', 'auto_subdomains', 'custom_domains',
        'domain_mapping', 'site_domain_settings', 'private_site_themes',
        'site_theme_uploads'
    );
    $enterpriseFeatures = array(
        'enterprise_white_label', 'agency_dashboard', 'audit_logs',
        'private_marketplace', 'advanced_site_roles', 'ssl_status_tools'
    );

    if (in_array($feature, $proFeatures, true) || in_array($feature, $enterpriseFeatures, true)) {
        return function_exists('devone_license_feature_enabled') && devone_license_feature_enabled($feature);
    }
    return true;
}

function devone_network_enabled() {
    if (!function_exists('get_setting')) { return false; }
    if (get_setting('network_mode_enabled', '0') !== '1') { return false; }
    return devone_feature_enabled('multisite');
}

function devone_network_tables_exist() {
    return function_exists('table_exists') && table_exists('sites') && table_exists('site_domains') && table_exists('site_users');
}

function devone_network_sql($prefix) {
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$prefix);
    return array(
        "CREATE TABLE IF NOT EXISTS `{$prefix}sites` (`id` int NOT NULL AUTO_INCREMENT,`site_name` varchar(190) NOT NULL,`site_slug` varchar(190) NOT NULL,`auto_subdomain` varchar(255) DEFAULT '',`primary_domain` varchar(255) DEFAULT '',`domain_mode` varchar(30) DEFAULT 'auto',`owner_user_id` int DEFAULT NULL,`admin_username` varchar(100) DEFAULT '',`admin_email` varchar(255) DEFAULT '',`allow_client_domain` tinyint(1) DEFAULT 1,`allow_client_theme_uploads` tinyint(1) DEFAULT 0,`status` varchar(30) DEFAULT 'active',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_slug` (`site_slug`),KEY `owner_user_id` (`owner_user_id`),KEY `status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_domains` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`domain` varchar(255) NOT NULL,`domain_type` varchar(30) DEFAULT 'custom',`is_primary` tinyint(1) DEFAULT 0,`verification_token` varchar(120) DEFAULT '',`verification_status` varchar(30) DEFAULT 'pending',`ssl_status` varchar(30) DEFAULT 'unknown',`created_by` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`verified_at` datetime DEFAULT NULL,PRIMARY KEY (`id`),UNIQUE KEY `domain` (`domain`),KEY `site_id` (`site_id`),KEY `verification_status` (`verification_status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_users` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`user_id` int NOT NULL,`role` varchar(50) DEFAULT 'site_admin',`status` varchar(30) DEFAULT 'active',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_user` (`site_id`,`user_id`),KEY `user_id` (`user_id`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_settings` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`setting_key` varchar(120) NOT NULL,`setting_value` longtext,PRIMARY KEY (`id`),UNIQUE KEY `site_setting` (`site_id`,`setting_key`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_themes` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`theme_name` varchar(190) NOT NULL,`theme_slug` varchar(190) NOT NULL,`theme_version` varchar(50) DEFAULT '1.0.0',`theme_author` varchar(190) DEFAULT '',`theme_path` varchar(500) NOT NULL,`theme_type` varchar(50) DEFAULT 'custom',`visibility` varchar(30) DEFAULT 'private',`status` varchar(30) DEFAULT 'inactive',`uploaded_by` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_theme` (`site_id`,`theme_slug`),KEY `site_id` (`site_id`),KEY `status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function devone_network_add_column($table, $column, $sql) {
    if (!function_exists('devone_schema_column_exists_pdo') || !function_exists('db')) { return; }
    try {
        if (function_exists('devone_schema_table_exists_pdo') && !devone_schema_table_exists_pdo(db(), $table)) { return; }
        if (!devone_schema_column_exists_pdo(db(), $table, $column)) { db()->exec($sql); }
    } catch (Exception $e) {}
}

function devone_ensure_network_schema($force = false) {
    static $done = false;
    if ($done && !$force) { return; }
    if (!function_exists('db') || !function_exists('table_name')) { return; }
    try { db(); } catch (Exception $e) { return; }

    $prefix = function_exists('devone_active_table_prefix') ? devone_active_table_prefix() : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cms_');
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$prefix);
    foreach (devone_network_sql($prefix) as $sql) {
        try { db()->exec($sql); } catch (Exception $e) {}
    }
    if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }

    $siteAware = array('pages', 'media', 'menus', 'themes');
    foreach ($siteAware as $base) {
        if (!function_exists('table_exists') || !table_exists($base)) { continue; }
        $tbl = table_name($base);
        devone_network_add_column($tbl, 'site_id', "ALTER TABLE `{$tbl}` ADD `site_id` int NOT NULL DEFAULT 1");
        try { db()->exec("UPDATE `{$tbl}` SET site_id=1 WHERE site_id IS NULL OR site_id=0"); } catch (Exception $e) {}
        try { db()->exec("CREATE INDEX `idx_{$base}_site_id` ON `{$tbl}` (`site_id`)"); } catch (Exception $e) {}
        if (in_array($base, array('pages','menus'), true)) {
            try { db()->exec("ALTER TABLE `{$tbl}` DROP INDEX `slug`"); } catch (Exception $e) {}
            try { db()->exec("CREATE UNIQUE INDEX `site_slug` ON `{$tbl}` (`site_id`,`slug`)"); } catch (Exception $e) {}
        }
        if ($base === 'themes') {
            try { db()->exec("ALTER TABLE `{$tbl}` DROP INDEX `folder`"); } catch (Exception $e) {}
            try { db()->exec("CREATE UNIQUE INDEX `site_folder` ON `{$tbl}` (`site_id`,`folder`)"); } catch (Exception $e) {}
        }
    }

    devone_network_seed_main_site();
    devone_network_ensure_site_folders(1);
    $done = true;
}

function devone_network_seed_main_site() {
    if (!function_exists('table_exists') || !table_exists('sites') || !table_exists('site_domains')) { return; }
    $sites = table_name('sites');
    $domains = table_name('site_domains');
    $siteName = function_exists('get_setting') ? get_setting('site_name', 'Main Site') : 'Main Site';
    $domain = devone_network_clean_domain(parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST) ?: devone_network_current_host());
    if ($domain === '') { $domain = 'localhost'; }

    try {
        $exists = (int)db()->query("SELECT COUNT(*) FROM `{$sites}`")->fetchColumn();
        if ($exists <= 0) {
            $admin = devone_network_first_admin_user();
            $stmt = db()->prepare("INSERT INTO `{$sites}` (id, site_name, site_slug, auto_subdomain, primary_domain, domain_mode, owner_user_id, admin_username, admin_email, status) VALUES (1,?,?,?,?,?,?,?,?,?)");
            $stmt->execute(array($siteName, 'main', $domain, $domain, 'custom', $admin['id'] ?? null, $admin['username'] ?? '', $admin['email'] ?? '', 'active'));
        }
        $stmt = db()->prepare("SELECT id FROM `{$domains}` WHERE domain=? LIMIT 1");
        $stmt->execute(array($domain));
        if (!$stmt->fetchColumn()) {
            $stmt = db()->prepare("INSERT INTO `{$domains}` (site_id, domain, domain_type, is_primary, verification_status, verified_at) VALUES (?,?,?,?,?,NOW())");
            $stmt->execute(array(1, $domain, 'primary', 1, 'verified'));
        }
        devone_network_assign_all_admins_to_main_site();
    } catch (Exception $e) {}
}

function devone_network_first_admin_user() {
    if (!function_exists('table_exists') || !table_exists('users')) { return null; }
    try {
        $users = table_name('users');
        $stmt = db()->query("SELECT id,username,email FROM `{$users}` WHERE role='admin' ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch();
        return $user ?: null;
    } catch (Exception $e) { return null; }
}

function devone_network_assign_all_admins_to_main_site() {
    if (!function_exists('table_exists') || !table_exists('users') || !table_exists('site_users')) { return; }
    try {
        $users = table_name('users');
        $siteUsers = table_name('site_users');
        $admins = db()->query("SELECT id FROM `{$users}` WHERE role='admin'")->fetchAll();
        $stmt = db()->prepare("INSERT INTO `{$siteUsers}` (site_id,user_id,role,status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role), status=VALUES(status)");
        foreach ($admins as $admin) { $stmt->execute(array(1, (int)$admin['id'], 'network_admin', 'active')); }
    } catch (Exception $e) {}
}

function devone_network_ensure_site_folders($siteId) {
    $siteId = max(1, (int)$siteId);
    $base = dirname(__DIR__) . '/content/sites/' . $siteId;
    foreach (array('themes','uploads','cache') as $sub) {
        if (!is_dir($base . '/' . $sub)) { @mkdir($base . '/' . $sub, 0775, true); }
    }
    return $base;
}

function devone_network_is_super_admin($user = null) {
    if (!$user && function_exists('devone_current_user')) { $user = devone_current_user(); }
    if (!$user) { return false; }
    $role = strtolower((string)($user['role'] ?? ''));
    if ($role === 'admin') { return true; }
    return function_exists('devone_has_permission') && (devone_has_permission('admin_all', $user) || devone_has_permission('*', $user));
}



function devone_network_user_site_role($userId = 0, $siteId = 0) {
    devone_ensure_network_schema();
    $userId = $userId ?: (function_exists('devone_current_user_id') ? devone_current_user_id() : 0);
    $siteId = $siteId ?: (function_exists('devone_admin_current_site_id') ? devone_admin_current_site_id() : 0);
    if ($userId <= 0 || $siteId <= 0 || !function_exists('table_exists') || !table_exists('site_users')) { return ''; }
    try {
        $tbl = table_name('site_users');
        $stmt = db()->prepare("SELECT role FROM `{$tbl}` WHERE site_id=? AND user_id=? AND status='active' LIMIT 1");
        $stmt->execute(array((int)$siteId, (int)$userId));
        return strtolower(trim((string)$stmt->fetchColumn()));
    } catch (Exception $e) { return ''; }
}

function devone_user_is_direct_site_admin($userId = 0, $siteId = 0) {
    devone_ensure_network_schema();
    $userId = $userId ?: (function_exists('devone_current_user_id') ? devone_current_user_id() : 0);
    $siteId = $siteId ?: (function_exists('devone_admin_current_site_id') ? devone_admin_current_site_id() : 0);
    if ($userId <= 0 || $siteId <= 0) { return false; }

    $role = devone_network_user_site_role($userId, $siteId);
    if (in_array($role, array('site_admin', 'site_owner', 'network_admin'), true)) { return true; }

    $site = devone_network_get_site($siteId);
    return $site && !empty($site['owner_user_id']) && (int)$site['owner_user_id'] === (int)$userId;
}

function devone_user_is_assigned_site_admin($userId = 0, $siteId = 0) {
    devone_ensure_network_schema();
    $userId = $userId ?: (function_exists('devone_current_user_id') ? devone_current_user_id() : 0);
    $siteId = $siteId ?: (function_exists('devone_admin_current_site_id') ? devone_admin_current_site_id() : 0);
    if ($userId <= 0 || $siteId <= 0) { return false; }

    $user = function_exists('devone_get_user_by_id') ? devone_get_user_by_id((int)$userId) : null;
    if (devone_network_is_super_admin($user)) { return true; }

    return devone_user_is_direct_site_admin($userId, $siteId);
}

function devone_site_admin_safe_permissions() {
    // Only capabilities whose storage and effects are already isolated to the
    // currently assigned site belong here. Executable package installation,
    // full-site backups, shared libraries/Marketplace installs, and the legacy
    // global settings table remain network-super-admin responsibilities until
    // those subsystems have a complete site-scoped storage contract.
    return array(
        'manage_dashboard',
        'create_pages', 'edit_pages', 'publish_pages', 'manage_pages',
        'create_posts', 'edit_posts', 'publish_posts',
        'upload_media', 'manage_media', 'view_all_media',
        'manage_menus', 'manage_themes',
        'manage_site_domain'
    );
}

function devone_site_admin_blocked_permissions() {
    return array(
        '*', 'admin_all', 'manage_sites', 'manage_api', 'manage_files',
        'view_logs', 'manage_network', 'manage_license', 'manage_upgrade',
        'manage_global_email', 'manage_table_debug',
        // These currently touch shared executable files, shared libraries,
        // the global settings table, or whole-install/database backups.
        'manage_plugins', 'manage_libraries', 'manage_store',
        'manage_modules', 'manage_settings', 'manage_backups'
    );
}

function devone_site_admin_has_permission($permission, $user = null) {
    $permission = trim((string)$permission);
    if ($permission === '' || in_array($permission, devone_site_admin_blocked_permissions(), true)) { return false; }
    if (!$user && function_exists('devone_current_user')) { $user = devone_current_user(); }
    if (!$user) { return false; }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) { return false; }
    if (!function_exists('devone_network_enabled') || !devone_network_enabled()) { return false; }

    $siteId = function_exists('devone_admin_current_site_id') ? (int)devone_admin_current_site_id() : 0;
    if ($siteId <= 0) { return false; }
    if (!devone_user_is_assigned_site_admin($userId, $siteId)) { return false; }

    if ($permission === 'manage_site_domain') {
        return devone_user_can_manage_site_domain($userId, $siteId);
    }

    return in_array($permission, devone_site_admin_safe_permissions(), true);
}

function devone_network_domain_exists($domain, $excludeSiteId = 0) {
    $domain = devone_network_clean_domain($domain);
    if ($domain === '' || !function_exists('table_exists') || !table_exists('site_domains')) { return false; }
    try {
        $tbl = table_name('site_domains');
        $sql = "SELECT site_id FROM `{$tbl}` WHERE domain=? LIMIT 1";
        $stmt = db()->prepare($sql);
        $stmt->execute(array($domain));
        $siteId = (int)$stmt->fetchColumn();
        return $siteId > 0 && $siteId !== (int)$excludeSiteId;
    } catch (Exception $e) { return false; }
}

function devone_network_generate_site_slug($siteName) {
    $slug = function_exists('devone_slugify') ? devone_slugify($siteName, 'site') : strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$siteName));
    $slug = trim($slug, '-') ?: 'site';
    if (!function_exists('table_exists') || !table_exists('sites')) { return $slug; }
    $base = $slug;
    $i = 2;
    try {
        $tbl = table_name('sites');
        while (true) {
            $stmt = db()->prepare("SELECT id FROM `{$tbl}` WHERE site_slug=? LIMIT 1");
            $stmt->execute(array($slug));
            if (!$stmt->fetchColumn()) { return $slug; }
            $slug = $base . '-' . $i;
            $i++;
        }
    } catch (Exception $e) { return $slug; }
}

function devone_network_create_site($args) {
    devone_ensure_network_schema();
    if (function_exists('devone_current_user') && devone_current_user() && !devone_network_is_super_admin()) {
        return array('ok'=>false, 'message'=>'Only Network Super Admins can create network sites.');
    }
    if (!table_exists('sites') || !table_exists('site_domains') || !table_exists('site_users')) {
        return array('ok'=>false, 'message'=>'Network tables are not available yet.');
    }
    $name = trim((string)($args['site_name'] ?? '')) ?: 'New Site';
    $ownerId = (int)($args['owner_user_id'] ?? 0);
    $adminUsername = trim((string)($args['admin_username'] ?? ''));
    $adminEmail = trim((string)($args['admin_email'] ?? ''));
    $allowDomain = !empty($args['allow_client_domain']) ? 1 : 0;
    $allowThemes = !empty($args['allow_client_theme_uploads']) ? 1 : 0;
    $slug = devone_network_generate_site_slug($args['site_slug'] ?? $name);
    $baseDomain = devone_network_base_domain();
    $autoDomain = $baseDomain !== '' && $baseDomain !== 'localhost' ? $slug . '.' . $baseDomain : $slug . '.localhost';

    try {
        $sites = table_name('sites');
        $domains = table_name('site_domains');
        $siteUsers = table_name('site_users');
        $stmt = db()->prepare("INSERT INTO `{$sites}` (site_name,site_slug,auto_subdomain,primary_domain,domain_mode,owner_user_id,admin_username,admin_email,allow_client_domain,allow_client_theme_uploads,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute(array($name, $slug, $autoDomain, $autoDomain, 'auto', $ownerId ?: null, $adminUsername, $adminEmail, $allowDomain, $allowThemes, 'active'));
        $siteId = (int)db()->lastInsertId();
        $token = bin2hex(random_bytes(16));
        $stmt = db()->prepare("INSERT INTO `{$domains}` (site_id,domain,domain_type,is_primary,verification_token,verification_status,verified_at) VALUES (?,?,?,?,?,?,NOW())");
        $stmt->execute(array($siteId, devone_network_clean_domain($autoDomain), 'auto_subdomain', 1, $token, 'verified'));
        if ($ownerId > 0) {
            $stmt = db()->prepare("INSERT INTO `{$siteUsers}` (site_id,user_id,role,status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role), status=VALUES(status)");
            $stmt->execute(array($siteId, $ownerId, 'site_admin', 'active'));
        }
        devone_network_ensure_site_folders($siteId);
        if (function_exists('table_exists') && table_exists('pages')) {
            try {
                $pages = table_name('pages');
                $cols = function_exists('devone_table_columns') ? devone_table_columns('pages') : array();
                $homeHtml = '<section class="hero"><h1>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</h1><p>This DevOne Network site is ready. Edit this home page from the assigned site dashboard.</p></section>';
                if (in_array('site_id', $cols, true)) {
                    $stmtHome = db()->prepare("INSERT IGNORE INTO `{$pages}` (site_id,slug,title,content,template,status,author_id) VALUES (?,?,?,?,?,?,?)");
                    $stmtHome->execute(array($siteId, 'home', 'Home', $homeHtml, 'default', 'published', $ownerId ?: null));
                }
            } catch (Exception $e) {}
        }
        return array('ok'=>true, 'message'=>'Site created.', 'site_id'=>$siteId, 'domain'=>$autoDomain);
    } catch (Exception $e) { return array('ok'=>false, 'message'=>$e->getMessage()); }
}

function devone_network_get_site($siteId) {
    devone_ensure_network_schema();
    if (!table_exists('sites')) { return null; }
    try {
        $tbl = table_name('sites');
        $stmt = db()->prepare("SELECT * FROM `{$tbl}` WHERE id=? LIMIT 1");
        $stmt->execute(array((int)$siteId));
        $site = $stmt->fetch();
        return $site ?: null;
    } catch (Exception $e) { return null; }
}

function devone_network_resolve_site_by_domain($domain) {
    devone_ensure_network_schema();
    $domain = devone_network_clean_domain($domain);
    if ($domain === '' || !table_exists('site_domains') || !table_exists('sites')) { return null; }
    try {
        $domains = table_name('site_domains');
        $sites = table_name('sites');
        $stmt = db()->prepare("SELECT s.* FROM `{$domains}` d INNER JOIN `{$sites}` s ON s.id=d.site_id WHERE d.domain=? AND d.verification_status IN ('verified','active') AND s.status='active' LIMIT 1");
        $stmt->execute(array($domain));
        $site = $stmt->fetch();
        return $site ?: null;
    } catch (Exception $e) { return null; }
}

function devone_current_site() {
    static $site = null;
    if ($site !== null) { return $site; }
    if (!devone_network_enabled()) {
        $site = array('id'=>1, 'site_name'=>function_exists('get_setting') ? get_setting('site_name','Main Site') : 'Main Site', 'primary_domain'=>devone_network_current_host(), 'site_slug'=>'main');
        return $site;
    }
    $resolved = devone_network_resolve_site_by_domain(devone_network_current_host());
    $site = $resolved ?: devone_network_get_site(1);
    if (!$site) { $site = array('id'=>1, 'site_name'=>'Main Site', 'primary_domain'=>devone_network_current_host(), 'site_slug'=>'main'); }
    return $site;
}

function devone_current_site_id() {
    $site = devone_current_site();
    return max(1, (int)($site['id'] ?? 1));
}

function devone_admin_current_site_id() {
    if (!devone_network_enabled()) { return devone_current_site_id(); }
    if (function_exists('devone_start_session')) { devone_start_session(); }
    $userId = function_exists('devone_current_user_id') ? devone_current_user_id() : 0;
    $sessionSite = !empty($_SESSION['devone_admin_site_id']) ? (int)$_SESSION['devone_admin_site_id'] : 0;
    if ($sessionSite > 0 && devone_user_can_access_site($userId, $sessionSite)) { return $sessionSite; }
    $sites = devone_user_accessible_sites($userId);
    if ($sites) { return (int)$sites[0]['id']; }
    return devone_current_site_id();
}

function devone_user_accessible_sites($userId = 0) {
    devone_ensure_network_schema();
    $userId = $userId ?: (function_exists('devone_current_user_id') ? devone_current_user_id() : 0);
    if ($userId <= 0 || !table_exists('sites')) { return array(); }
    $user = function_exists('devone_get_user_by_id') ? devone_get_user_by_id($userId) : null;
    try {
        $sites = table_name('sites');
        if (devone_network_is_super_admin($user)) {
            return db()->query("SELECT * FROM `{$sites}` ORDER BY id ASC")->fetchAll();
        }
        if (!table_exists('site_users')) { return array(); }
        $siteUsers = table_name('site_users');
        $stmt = db()->prepare("SELECT s.* FROM `{$sites}` s INNER JOIN `{$siteUsers}` su ON su.site_id=s.id WHERE su.user_id=? AND su.status='active' AND s.status='active' ORDER BY s.site_name ASC");
        $stmt->execute(array($userId));
        return $stmt->fetchAll();
    } catch (Exception $e) { return array(); }
}

function devone_user_can_access_site($userId, $siteId) {
    $siteId = (int)$siteId;
    if ($siteId <= 0) { return false; }
    $user = function_exists('devone_get_user_by_id') ? devone_get_user_by_id((int)$userId) : null;
    if (devone_network_is_super_admin($user)) { return true; }
    if (!table_exists('site_users')) { return $siteId === 1; }
    try {
        $tbl = table_name('site_users');
        $stmt = db()->prepare("SELECT id FROM `{$tbl}` WHERE site_id=? AND user_id=? AND status='active' LIMIT 1");
        $stmt->execute(array($siteId, (int)$userId));
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) { return false; }
}

function devone_user_can_manage_site_domain($userId, $siteId) {
    if (!devone_feature_enabled('site_domain_settings') || !devone_network_enabled()) { return false; }
    if (!devone_user_can_access_site($userId, $siteId)) { return false; }
    $user = function_exists('devone_get_user_by_id') ? devone_get_user_by_id((int)$userId) : null;
    if (devone_network_is_super_admin($user)) { return true; }
    $site = devone_network_get_site($siteId);
    return !empty($site['allow_client_domain']);
}

function devone_network_set_custom_domain($siteId, $domain, $userId = 0) {
    devone_ensure_network_schema();
    $siteId = (int)$siteId;
    $domain = devone_network_clean_domain($domain);
    $userId = $userId ?: (function_exists('devone_current_user_id') ? devone_current_user_id() : 0);
    if ($siteId <= 0 || $domain === '') { return array('ok'=>false, 'message'=>'A valid domain is required.'); }
    if (!devone_user_can_manage_site_domain($userId, $siteId)) { return array('ok'=>false, 'message'=>'You do not have permission to manage this site domain.'); }
    if (devone_network_domain_exists($domain, $siteId)) { return array('ok'=>false, 'message'=>'This domain is already assigned to another DevOne site.'); }
    try {
        $domains = table_name('site_domains');
        $sites = table_name('sites');
        $token = 'devone-' . bin2hex(random_bytes(12));
        $stmt = db()->prepare("INSERT INTO `{$domains}` (site_id,domain,domain_type,is_primary,verification_token,verification_status,created_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE verification_token=VALUES(verification_token), verification_status=VALUES(verification_status), created_by=VALUES(created_by)");
        $stmt->execute(array($siteId, $domain, 'custom', 0, $token, 'pending', $userId ?: null));
        $stmt = db()->prepare("UPDATE `{$sites}` SET primary_domain=?, domain_mode='custom' WHERE id=?");
        $stmt->execute(array($domain, $siteId));
        return array('ok'=>true, 'message'=>'Domain saved. Point DNS to this server, then verify it.', 'token'=>$token, 'domain'=>$domain);
    } catch (Exception $e) { return array('ok'=>false, 'message'=>$e->getMessage()); }
}

function devone_network_verify_domain($siteId, $domain) {
    devone_ensure_network_schema();
    $siteId = (int)$siteId;
    $domain = devone_network_clean_domain($domain);
    if ($siteId <= 0 || $domain === '') { return array('ok'=>false, 'message'=>'A valid domain is required.'); }
    try {
        $domains = table_name('site_domains');
        $sites = table_name('sites');
        $stmt = db()->prepare("UPDATE `{$domains}` SET verification_status='verified', is_primary=0, verified_at=NOW() WHERE site_id=? AND domain=?");
        $stmt->execute(array($siteId, $domain));
        $stmt = db()->prepare("UPDATE `{$domains}` SET is_primary=0 WHERE site_id=?");
        $stmt->execute(array($siteId));
        $stmt = db()->prepare("UPDATE `{$domains}` SET is_primary=1 WHERE site_id=? AND domain=?");
        $stmt->execute(array($siteId, $domain));
        $stmt = db()->prepare("UPDATE `{$sites}` SET primary_domain=?, domain_mode='custom' WHERE id=?");
        $stmt->execute(array($domain, $siteId));
        return array('ok'=>true, 'message'=>'Domain marked verified and set as primary. Make sure DNS/SSL are configured on the server.');
    } catch (Exception $e) { return array('ok'=>false, 'message'=>$e->getMessage()); }
}



function devone_network_safe_delete_path($path) {
    $path = (string)$path;
    if ($path === '' || !file_exists($path)) { return array('ok'=>true, 'message'=>'Path does not exist.'); }

    $root = realpath(dirname(__DIR__) . '/content/sites');
    $real = realpath($path);
    if (!$root || !$real || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        return array('ok'=>false, 'message'=>'Refusing to delete unsafe path.');
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            if ($file->isDir()) { @rmdir($filePath); }
            else { @unlink($filePath); }
        }
        @rmdir($real);
        return array('ok'=>true, 'message'=>'Site files deleted.');
    } catch (Throwable $e) {
        return array('ok'=>false, 'message'=>$e->getMessage());
    }
}

function devone_network_update_site($siteId, $args = array()) {
    devone_ensure_network_schema();
    $siteId = (int)$siteId;
    if ($siteId <= 0 || !table_exists('sites')) { return array('ok'=>false, 'message'=>'Site could not be found.'); }
    if (!devone_network_is_super_admin()) { return array('ok'=>false, 'message'=>'Only Network Super Admins can manage network sites.'); }

    $siteName = trim((string)($args['site_name'] ?? 'Site')) ?: 'Site';
    $ownerId = (int)($args['owner_user_id'] ?? 0);
    $adminUsername = trim((string)($args['admin_username'] ?? ''));
    $adminEmail = trim((string)($args['admin_email'] ?? ''));
    $allowDomain = !empty($args['allow_client_domain']) ? 1 : 0;
    $allowThemes = !empty($args['allow_client_theme_uploads']) ? 1 : 0;
    $status = in_array(($args['status'] ?? 'active'), array('active','disabled'), true) ? $args['status'] : 'active';

    try {
        $sites = table_name('sites');
        $stmt = db()->prepare("UPDATE `{$sites}` SET site_name=?, owner_user_id=?, admin_username=?, admin_email=?, allow_client_domain=?, allow_client_theme_uploads=?, status=? WHERE id=?");
        $stmt->execute(array($siteName, $ownerId ?: null, $adminUsername, $adminEmail, $allowDomain, $allowThemes, $status, $siteId));

        if (table_exists('site_users')) {
            $siteUsers = table_name('site_users');
            $stmt = db()->prepare("DELETE FROM `{$siteUsers}` WHERE site_id=? AND role='site_admin'");
            $stmt->execute(array($siteId));
            if ($ownerId > 0) {
                $stmt = db()->prepare("INSERT INTO `{$siteUsers}` (site_id,user_id,role,status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role), status=VALUES(status)");
                $stmt->execute(array($siteId, $ownerId, 'site_admin', 'active'));
            }
        }
        return array('ok'=>true, 'message'=>'Site updated.');
    } catch (Exception $e) {
        return array('ok'=>false, 'message'=>$e->getMessage());
    }
}

function devone_network_delete_site($siteId, $confirm = '') {
    devone_ensure_network_schema();
    $siteId = (int)$siteId;
    $confirm = strtoupper(trim((string)$confirm));
    if (!devone_network_is_super_admin()) { return array('ok'=>false, 'message'=>'Only Network Super Admins can delete network sites.'); }
    if ($siteId <= 1) { return array('ok'=>false, 'message'=>'The main site cannot be deleted.'); }
    if ($confirm !== 'DELETE') { return array('ok'=>false, 'message'=>'Type DELETE to confirm permanent site deletion.'); }

    $site = devone_network_get_site($siteId);
    if (!$site) { return array('ok'=>false, 'message'=>'Site could not be found.'); }

    $deletedRows = 0;
    try {
        db()->beginTransaction();

        $prefix = function_exists('devone_active_table_prefix') ? devone_active_table_prefix() : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cms_');
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$prefix);
        $tables = array();
        try {
            $stmtTables = db()->query('SHOW TABLES LIKE ' . db()->quote($prefix . '%'));
            $tables = $stmtTables ? $stmtTables->fetchAll(PDO::FETCH_COLUMN) : array();
        } catch (Exception $e) { $tables = array(); }

        foreach ($tables as $table) {
            $table = (string)$table;
            if ($table === table_name('sites')) { continue; }
            $hasSiteId = false;
            try {
                $stmtCol = db()->prepare("SHOW COLUMNS FROM `{$table}` LIKE 'site_id'");
                $stmtCol->execute();
                $hasSiteId = (bool)$stmtCol->fetch();
            } catch (Exception $e) { $hasSiteId = false; }
            if ($hasSiteId) {
                $stmtDel = db()->prepare("DELETE FROM `{$table}` WHERE site_id=?");
                $stmtDel->execute(array($siteId));
                $deletedRows += (int)$stmtDel->rowCount();
            }
        }

        if (table_exists('sites')) {
            $stmt = db()->prepare('DELETE FROM `' . table_name('sites') . '` WHERE id=?');
            $stmt->execute(array($siteId));
            $deletedRows += (int)$stmt->rowCount();
        }

        db()->commit();
    } catch (Exception $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        return array('ok'=>false, 'message'=>'Database purge failed: ' . $e->getMessage());
    }

    $folderResult = devone_network_safe_delete_path(dirname(__DIR__) . '/content/sites/' . $siteId);
    if (function_exists('devone_log')) {
        devone_log('network_site_deleted', 'Deleted site #' . $siteId . ' (' . ($site['site_name'] ?? '') . '), rows removed: ' . $deletedRows . ', files: ' . ($folderResult['ok'] ? 'ok' : $folderResult['message']));
    }
    if (!$folderResult['ok']) {
        return array('ok'=>true, 'message'=>'Site database data was deleted, but files need manual cleanup: ' . $folderResult['message']);
    }
    return array('ok'=>true, 'message'=>'Site and site data deleted. Removed approximately ' . $deletedRows . ' database row(s).');
}

function devone_network_site_url($site = null, $path = '') {
    if (!$site) { $site = devone_current_site(); }
    $domain = devone_network_clean_domain($site['primary_domain'] ?? ($site['auto_subdomain'] ?? ''));
    if ($domain === '') { return function_exists('devone_site_url') ? devone_site_url($path) : '/' . ltrim((string)$path, '/'); }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url = $scheme . '://' . $domain . '/';
    return rtrim($url, '/') . '/' . ltrim((string)$path, '/');
}

function devone_network_admin_site_context() {
    $siteId = devone_admin_current_site_id();
    $site = devone_network_get_site($siteId);
    return $site ?: devone_current_site();
}

// Verify network schema once per Core release instead of performing DDL checks on every request.
$devoneNetworkRoot=realpath(__DIR__.'/..')?:dirname(__DIR__);
$devoneNetworkVersion=defined('DEVONE_CORE_VERSION')?(string)DEVONE_CORE_VERSION:(defined('CMS_VERSION')?(string)CMS_VERSION:'current');
$devoneNetworkMarker=$devoneNetworkRoot.'/storage/cache/network-schema-'.preg_replace('/[^A-Za-z0-9._-]/','-',$devoneNetworkVersion).'.ok';
if(!is_file($devoneNetworkMarker)){
    devone_ensure_network_schema(true);
    $devoneNetworkDir=dirname($devoneNetworkMarker);if(!is_dir($devoneNetworkDir))@mkdir($devoneNetworkDir,0775,true);@file_put_contents($devoneNetworkMarker,gmdate('c')."\n",LOCK_EX);
}
