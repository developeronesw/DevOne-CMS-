<?php
/**
 * DevOneCMS database schema + repair helpers.
 * Safe to run repeatedly. Does not drop or delete data.
 */
function devone_schema_clean_prefix($prefix) {
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$prefix);
    return $prefix !== '' ? $prefix : 'cms_';
}

function devone_schema_prefix() {
    if (function_exists('devone_active_table_prefix')) {
        $active = devone_active_table_prefix();
        if ($active !== '') { return devone_schema_clean_prefix($active); }
    }
    return devone_schema_clean_prefix(defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cms_');
}

function devone_schema_table($prefix, $name) {
    return devone_schema_clean_prefix($prefix) . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$name);
}

function devone_schema_required_tables() {
    return array('pages','plugins','libraries','users','roles','settings','media','menus','themes','theme_packages','site_theme_entitlements','api_endpoints','modules','apps','activity_logs','webhooks','sites','site_domains','site_users','site_settings','site_themes');
}

function devone_schema_table_exists_pdo($pdo, $fullTableName) {
    try {
        // INFORMATION_SCHEMA is more reliable than SHOW TABLES LIKE on some MariaDB/XAMPP setups.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($fullTableName));
        if ((int)$stmt->fetchColumn() > 0) { return true; }
    } catch (Exception $e) {}
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($fullTableName));
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function devone_schema_column_exists_pdo($pdo, $fullTableName, $columnName) {
    try {
        // Do not use SHOW COLUMNS ... LIKE ? here. Some MariaDB/PDO builds reject placeholders in SHOW statements.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(array($fullTableName, $columnName));
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        try {
            $safeTable = str_replace('`', '', (string)$fullTableName);
            $safeColumn = str_replace("'", "''", (string)$columnName);
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e2) {
            return false;
        }
    }
}

function devone_schema_sql($prefix) {
    $prefix = devone_schema_clean_prefix($prefix);
    return array(
        "CREATE TABLE IF NOT EXISTS `{$prefix}pages` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`slug` varchar(255) NOT NULL,`title` varchar(255) NOT NULL,`content` longtext NOT NULL,`template` varchar(100) DEFAULT 'default',`show_title` tinyint(1) NOT NULL DEFAULT 1,`status` enum('draft','published') DEFAULT 'published',`author_id` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_slug` (`site_id`,`slug`),KEY `slug` (`slug`),KEY `author_id` (`author_id`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}plugins` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(255) NOT NULL,`folder` varchar(255) NOT NULL,`version` varchar(50) DEFAULT '1.0.0',`description` text,`active` tinyint(1) DEFAULT 1,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `folder` (`folder`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}libraries` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(255) NOT NULL,`folder` varchar(500) NOT NULL,`type` enum('js','css') DEFAULT 'js',`source` enum('local','cdn') DEFAULT 'local',`url` varchar(1000) DEFAULT '',`description` text,`active` tinyint(1) DEFAULT 1,`version` varchar(50) DEFAULT '1.0.0',`manifest` longtext,`scope` varchar(30) DEFAULT 'frontend',`sort_order` int DEFAULT 100,`integrity` varchar(255) DEFAULT '',`crossorigin` varchar(30) DEFAULT 'anonymous',`defer_load` tinyint(1) DEFAULT 1,`async_load` tinyint(1) DEFAULT 0,`module_script` tinyint(1) DEFAULT 0,`last_error` text,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}users` (`id` int NOT NULL AUTO_INCREMENT,`username` varchar(100) NOT NULL,`password` varchar(255) NOT NULL,`email` varchar(255) DEFAULT '',`display_name` varchar(160) DEFAULT '',`avatar` varchar(500) DEFAULT '',`bio` text,`role` varchar(50) DEFAULT 'subscriber',`status` varchar(30) DEFAULT 'active',`permissions_override` longtext,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `username` (`username`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}roles` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(100) NOT NULL,`description` text,`permissions` longtext,PRIMARY KEY (`id`),UNIQUE KEY `name` (`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}settings` (`id` int NOT NULL AUTO_INCREMENT,`setting_key` varchar(120) NOT NULL,`setting_value` longtext,PRIMARY KEY (`id`),UNIQUE KEY `setting_key` (`setting_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}media` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`filename` varchar(255) NOT NULL,`path` varchar(500) NOT NULL,`mime_type` varchar(120) DEFAULT '',`size_bytes` bigint DEFAULT 0,`alt_text` varchar(255) DEFAULT '',`title` varchar(255) DEFAULT '',`caption` text,`metadata_json` longtext,`updated_at` datetime DEFAULT NULL,`folder` varchar(255) DEFAULT 'other',`media_type` varchar(50) DEFAULT 'other',`user_id` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),KEY `user_id` (`user_id`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}menus` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`name` varchar(120) NOT NULL,`slug` varchar(120) NOT NULL,`items` longtext,PRIMARY KEY (`id`),UNIQUE KEY `site_slug` (`site_id`,`slug`),KEY `slug` (`slug`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}themes` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`name` varchar(120) NOT NULL,`folder` varchar(120) NOT NULL,`active` tinyint(1) DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `site_folder` (`site_id`,`folder`),KEY `folder` (`folder`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}theme_packages` (`id` int NOT NULL AUTO_INCREMENT,`theme_slug` varchar(190) NOT NULL,`theme_name` varchar(190) NOT NULL,`theme_version` varchar(50) DEFAULT '1.0.0',`package_hash` char(64) NOT NULL,`storage_path` varchar(700) NOT NULL,`visibility` varchar(30) NOT NULL DEFAULT 'private',`uploaded_by` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `package_hash` (`package_hash`),KEY `theme_slug` (`theme_slug`),KEY `visibility` (`visibility`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_theme_entitlements` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`package_id` int NOT NULL,`theme_slug` varchar(190) NOT NULL,`source` varchar(40) NOT NULL DEFAULT 'upload',`license_owner` varchar(255) DEFAULT '',`status` varchar(30) NOT NULL DEFAULT 'active',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_theme` (`site_id`,`theme_slug`),KEY `package_id` (`package_id`),KEY `site_id` (`site_id`),KEY `status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}api_endpoints` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(120) NOT NULL,`path` varchar(255) NOT NULL,`method` varchar(12) DEFAULT 'GET',`source_table` varchar(120) NOT NULL,`auth_required` tinyint(1) DEFAULT 0,`active` tinyint(1) DEFAULT 1,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),KEY `path_method` (`path`,`method`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}modules` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(120) NOT NULL,`description` text,`folder` varchar(120) NOT NULL,`active` tinyint(1) DEFAULT 1,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `folder` (`folder`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}apps` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`name` varchar(190) NOT NULL,`folder` varchar(120) NOT NULL,`version` varchar(50) DEFAULT '1.0.0',`description` text,`mount_path` varchar(255) NOT NULL,`entry_file` varchar(255) NOT NULL,`capabilities` longtext,`manifest` longtext,`active` tinyint(1) DEFAULT 0,`status` varchar(30) DEFAULT 'installed',`last_error` text,`installed_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_folder` (`site_id`,`folder`),UNIQUE KEY `site_mount` (`site_id`,`mount_path`),KEY `site_active` (`site_id`,`active`),KEY `folder` (`folder`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}activity_logs` (`id` bigint NOT NULL AUTO_INCREMENT,`user_id` int DEFAULT NULL,`action` varchar(160) NOT NULL,`details` text,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}webhooks` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(120) NOT NULL,`event` varchar(120) NOT NULL,`target_url` varchar(500) NOT NULL,`active` tinyint(1) DEFAULT 1,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}sites` (`id` int NOT NULL AUTO_INCREMENT,`site_name` varchar(190) NOT NULL,`site_slug` varchar(190) NOT NULL,`auto_subdomain` varchar(255) DEFAULT '',`primary_domain` varchar(255) DEFAULT '',`domain_mode` varchar(30) DEFAULT 'auto',`owner_user_id` int DEFAULT NULL,`admin_username` varchar(100) DEFAULT '',`admin_email` varchar(255) DEFAULT '',`allow_client_domain` tinyint(1) DEFAULT 1,`allow_client_theme_uploads` tinyint(1) DEFAULT 0,`status` varchar(30) DEFAULT 'active',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_slug` (`site_slug`),KEY `owner_user_id` (`owner_user_id`),KEY `status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_domains` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`domain` varchar(255) NOT NULL,`domain_type` varchar(30) DEFAULT 'custom',`is_primary` tinyint(1) DEFAULT 0,`verification_token` varchar(120) DEFAULT '',`verification_status` varchar(30) DEFAULT 'pending',`ssl_status` varchar(30) DEFAULT 'unknown',`created_by` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`verified_at` datetime DEFAULT NULL,PRIMARY KEY (`id`),UNIQUE KEY `domain` (`domain`),KEY `site_id` (`site_id`),KEY `verification_status` (`verification_status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_users` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`user_id` int NOT NULL,`role` varchar(50) DEFAULT 'site_admin',`status` varchar(30) DEFAULT 'active',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_user` (`site_id`,`user_id`),KEY `user_id` (`user_id`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_settings` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`setting_key` varchar(120) NOT NULL,`setting_value` longtext,PRIMARY KEY (`id`),UNIQUE KEY `site_setting` (`site_id`,`setting_key`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}site_themes` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`theme_name` varchar(190) NOT NULL,`theme_slug` varchar(190) NOT NULL,`theme_version` varchar(50) DEFAULT '1.0.0',`theme_author` varchar(190) DEFAULT '',`theme_path` varchar(500) NOT NULL,`theme_type` varchar(50) DEFAULT 'custom',`visibility` varchar(30) DEFAULT 'private',`status` varchar(30) DEFAULT 'inactive',`uploaded_by` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_theme` (`site_id`,`theme_slug`),KEY `site_id` (`site_id`),KEY `status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function devone_schema_add_missing_columns($pdo, $prefix) {
    $prefix = devone_schema_clean_prefix($prefix);
    $alter = array(
        array("{$prefix}pages", "updated_at", "ALTER TABLE `{$prefix}pages` ADD `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"),
        array("{$prefix}libraries", "url", "ALTER TABLE `{$prefix}libraries` ADD `url` varchar(1000) DEFAULT ''"),
        array("{$prefix}libraries", "description", "ALTER TABLE `{$prefix}libraries` ADD `description` text"),
        array("{$prefix}libraries", "version", "ALTER TABLE `{$prefix}libraries` ADD `version` varchar(50) DEFAULT '1.0.0'"),
        array("{$prefix}libraries", "manifest", "ALTER TABLE `{$prefix}libraries` ADD `manifest` longtext"),
        array("{$prefix}libraries", "scope", "ALTER TABLE `{$prefix}libraries` ADD `scope` varchar(30) DEFAULT 'frontend'"),
        array("{$prefix}libraries", "sort_order", "ALTER TABLE `{$prefix}libraries` ADD `sort_order` int DEFAULT 100"),
        array("{$prefix}libraries", "integrity", "ALTER TABLE `{$prefix}libraries` ADD `integrity` varchar(255) DEFAULT ''"),
        array("{$prefix}libraries", "crossorigin", "ALTER TABLE `{$prefix}libraries` ADD `crossorigin` varchar(30) DEFAULT 'anonymous'"),
        array("{$prefix}libraries", "defer_load", "ALTER TABLE `{$prefix}libraries` ADD `defer_load` tinyint(1) DEFAULT 1"),
        array("{$prefix}libraries", "async_load", "ALTER TABLE `{$prefix}libraries` ADD `async_load` tinyint(1) DEFAULT 0"),
        array("{$prefix}libraries", "module_script", "ALTER TABLE `{$prefix}libraries` ADD `module_script` tinyint(1) DEFAULT 0"),
        array("{$prefix}libraries", "last_error", "ALTER TABLE `{$prefix}libraries` ADD `last_error` text"),
        array("{$prefix}media", "folder", "ALTER TABLE `{$prefix}media` ADD `folder` varchar(255) DEFAULT 'other'"),
        array("{$prefix}media", "media_type", "ALTER TABLE `{$prefix}media` ADD `media_type` varchar(50) DEFAULT 'other'"),
        array("{$prefix}media", "user_id", "ALTER TABLE `{$prefix}media` ADD `user_id` int DEFAULT NULL"),
        array("{$prefix}pages", "author_id", "ALTER TABLE `{$prefix}pages` ADD `author_id` int DEFAULT NULL"),
        array("{$prefix}pages", "site_id", "ALTER TABLE `{$prefix}pages` ADD `site_id` int NOT NULL DEFAULT 1"),
        array("{$prefix}pages", "show_title", "ALTER TABLE `{$prefix}pages` ADD `show_title` tinyint(1) NOT NULL DEFAULT 1 AFTER `template`"),
        array("{$prefix}media", "site_id", "ALTER TABLE `{$prefix}media` ADD `site_id` int NOT NULL DEFAULT 1"),
        array("{$prefix}menus", "site_id", "ALTER TABLE `{$prefix}menus` ADD `site_id` int NOT NULL DEFAULT 1"),
        array("{$prefix}themes", "site_id", "ALTER TABLE `{$prefix}themes` ADD `site_id` int NOT NULL DEFAULT 1"),
        array("{$prefix}users", "display_name", "ALTER TABLE `{$prefix}users` ADD `display_name` varchar(160) DEFAULT ''"),
        array("{$prefix}users", "avatar", "ALTER TABLE `{$prefix}users` ADD `avatar` varchar(500) DEFAULT ''"),
        array("{$prefix}users", "bio", "ALTER TABLE `{$prefix}users` ADD `bio` text"),
        array("{$prefix}users", "status", "ALTER TABLE `{$prefix}users` ADD `status` varchar(30) DEFAULT 'active'"),
        array("{$prefix}users", "permissions_override", "ALTER TABLE `{$prefix}users` ADD `permissions_override` longtext"),
        array("{$prefix}roles", "description", "ALTER TABLE `{$prefix}roles` ADD `description` text")
    );
    foreach ($alter as $item) {
        list($table, $column, $sql) = $item;
        try {
            if (!devone_schema_table_exists_pdo($pdo, $table)) { continue; }
            if (!devone_schema_column_exists_pdo($pdo, $table, $column)) { $pdo->exec($sql); }
        } catch (Exception $e) { /* non-fatal repair */ }
    }
}


function devone_component_schema_marker_path($component) {
    $component = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim((string)$component)));
    $component = trim((string)$component, '-') ?: 'component';
    $version = defined('DEVONE_CORE_VERSION') ? (string)DEVONE_CORE_VERSION : (defined('CMS_VERSION') ? (string)CMS_VERSION : 'current');
    $prefix = function_exists('devone_schema_prefix') ? devone_schema_prefix() : (defined('TABLE_PREFIX') ? (string)TABLE_PREFIX : 'cms_');
    $key = preg_replace('/[^A-Za-z0-9._-]/', '-', $version . '-' . $prefix);
    return dirname(__DIR__) . '/storage/cache/' . $component . '-schema-' . ($key !== '' ? $key : 'current') . '.ok';
}

function devone_component_schema_marker_write($component) {
    $marker = devone_component_schema_marker_path($component);
    $dir = dirname($marker);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return @file_put_contents($marker, gmdate('c') . "
", LOCK_EX) !== false;
}

function devone_component_schema_marker_clear($component) {
    $marker = devone_component_schema_marker_path($component);
    if (is_file($marker)) { @unlink($marker); }
}

function devone_ensure_user_profile_schema() {
    static $done = false;
    $marker = devone_component_schema_marker_path('users');
    if ($done && is_file($marker)) { return array('ok'=>true, 'message'=>'User profile schema OK', 'cached'=>true); }
    if (!$done && is_file($marker)) { $done = true; return array('ok'=>true, 'message'=>'User profile schema OK', 'cached'=>true); }
    $tbl = function_exists('devone_require_table') ? devone_require_table('users', true) : '';
    if ($tbl === '') { return array('ok'=>false, 'message'=>'Users table could not be resolved.'); }
    $required = array(
        'display_name' => "ALTER TABLE `{$tbl}` ADD `display_name` varchar(160) DEFAULT ''",
        'avatar' => "ALTER TABLE `{$tbl}` ADD `avatar` varchar(500) DEFAULT ''",
        'bio' => "ALTER TABLE `{$tbl}` ADD `bio` text",
        'status' => "ALTER TABLE `{$tbl}` ADD `status` varchar(30) DEFAULT 'active'",
        'permissions_override' => "ALTER TABLE `{$tbl}` ADD `permissions_override` longtext",
        'updated_at' => "ALTER TABLE `{$tbl}` ADD `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    );
    $made = array();
    try {
        $pdo = db();
        foreach ($required as $column => $sql) {
            if (!devone_schema_column_exists_pdo($pdo, $tbl, $column)) {
                $pdo->exec($sql);
                $made[] = $column;
            }
        }
        if ($made && function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
        devone_component_schema_marker_write('users');
        $done = true;
        return array('ok'=>true, 'message'=>$made ? ('User profile columns added: ' . implode(', ', $made)) : 'User profile schema OK');
    } catch (Exception $e) {
        return array('ok'=>false, 'message'=>$e->getMessage());
    }
}


function devone_ensure_media_schema() {
    static $done = false;
    $marker = devone_component_schema_marker_path('media');
    if ($done && is_file($marker)) { return array('ok'=>true, 'message'=>'Media schema OK', 'cached'=>true); }
    if (!$done && is_file($marker)) { $done = true; return array('ok'=>true, 'message'=>'Media schema OK', 'cached'=>true); }
    $tbl = function_exists('devone_require_table') ? devone_require_table('media', true) : '';
    if ($tbl === '') { return array('ok'=>false, 'message'=>'Media table could not be resolved.'); }
    $required = array(
        'folder' => "ALTER TABLE `{$tbl}` ADD `folder` varchar(255) DEFAULT 'other'",
        'media_type' => "ALTER TABLE `{$tbl}` ADD `media_type` varchar(50) DEFAULT 'other'",
        'title' => "ALTER TABLE `{$tbl}` ADD `title` varchar(255) DEFAULT ''",
        'caption' => "ALTER TABLE `{$tbl}` ADD `caption` text",
        'metadata_json' => "ALTER TABLE `{$tbl}` ADD `metadata_json` longtext",
        'updated_at' => "ALTER TABLE `{$tbl}` ADD `updated_at` datetime DEFAULT NULL",
        'user_id' => "ALTER TABLE `{$tbl}` ADD `user_id` int DEFAULT NULL",
        'created_at' => "ALTER TABLE `{$tbl}` ADD `created_at` datetime DEFAULT CURRENT_TIMESTAMP"
    );
    $made = array();
    try {
        $pdo = db();
        foreach ($required as $column => $sql) {
            if (!devone_schema_column_exists_pdo($pdo, $tbl, $column)) {
                $pdo->exec($sql);
                $made[] = $column;
            }
        }
        try { $pdo->exec("ALTER TABLE `{$tbl}` ADD INDEX `user_id` (`user_id`)"); } catch (Exception $e) {}
        if ($made && function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
        devone_component_schema_marker_write('media');
        $done = true;
        return array('ok'=>true, 'message'=>$made ? ('Media columns added: ' . implode(', ', $made)) : 'Media schema OK');
    } catch (Exception $e) {
        return array('ok'=>false, 'message'=>$e->getMessage());
    }
}

function devone_schema_seed_defaults($pdo, $prefix) {
    $prefix = devone_schema_clean_prefix($prefix);
    $home = <<<'HTML'
<style>
.devone-launch-home{width:100%;overflow:hidden;background:#070711;color:#fff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.devone-launch-hero{position:relative;min-height:86vh;display:grid;place-items:center;padding:96px 20px;isolation:isolate;overflow:hidden}
.devone-launch-hero:before{content:"";position:absolute;inset:-30%;background:radial-gradient(circle at 20% 20%,rgba(255,122,24,.28),transparent 32%),radial-gradient(circle at 78% 28%,rgba(255,45,117,.22),transparent 30%),radial-gradient(circle at 50% 90%,rgba(87,124,255,.18),transparent 35%);z-index:-3;animation:devoneGlow 12s ease-in-out infinite alternate}
.devone-launch-hero:after{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.055) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.055) 1px,transparent 1px);background-size:72px 72px;mask-image:linear-gradient(to bottom,black,transparent 82%);z-index:-2}
.devone-launch-inner{width:min(1240px,100%);margin:auto;text-align:center}
.devone-launch-badge{display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,211,90,.26);color:#ffd98a;font-size:.78rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
.devone-launch-badge span{width:9px;height:9px;border-radius:999px;background:#42ff9b;box-shadow:0 0 20px #42ff9b}
.devone-launch-title{margin:24px auto 18px;max-width:1040px;font-size:clamp(3.2rem,8vw,8.4rem);line-height:.86;letter-spacing:-.08em;font-weight:950}
.devone-launch-title strong{background:linear-gradient(90deg,#fff,#ffd25a,#ff7a18,#ff2d75);-webkit-background-clip:text;background-clip:text;color:transparent}
.devone-launch-lead{max-width:850px;margin:0 auto;color:#f1dfc1;font-size:clamp(1.05rem,2vw,1.35rem);line-height:1.75}
.devone-launch-actions{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;margin-top:34px}
.devone-launch-btn{display:inline-flex;align-items:center;justify-content:center;min-height:54px;padding:0 24px;border-radius:18px;text-decoration:none!important;color:#fff!important;font-weight:950;border:1px solid rgba(255,211,90,.28);transition:.18s ease}
.devone-launch-btn:hover{transform:translateY(-3px)}
.devone-launch-btn.primary{background:linear-gradient(135deg,#ff7a18,#ff2d75);box-shadow:0 20px 60px rgba(255,89,38,.34)}
.devone-launch-btn.ghost{background:rgba(255,255,255,.08)}
.devone-launch-panel{margin:50px auto 0;display:grid;grid-template-columns:repeat(4,1fr);gap:14px;max-width:1080px}
.devone-launch-stat{border-radius:24px;background:rgba(255,255,255,.075);border:1px solid rgba(255,255,255,.1);padding:22px;text-align:left;box-shadow:0 22px 70px rgba(0,0,0,.22)}
.devone-launch-stat b{display:block;font-size:2rem;line-height:1;color:#ffd25a}.devone-launch-stat span{display:block;margin-top:8px;color:#e8d8c6;font-weight:800}
.devone-launch-section{width:min(1240px,calc(100% - 32px));margin:0 auto;padding:76px 0}
.devone-launch-heading{text-align:center;max-width:820px;margin:0 auto 40px}.devone-launch-heading h2{margin:0 0 12px;font-size:clamp(2.2rem,5vw,4.6rem);line-height:.96;letter-spacing:-.065em}.devone-launch-heading p{margin:0;color:#e8d8c6;line-height:1.75;font-size:1.05rem}
.devone-launch-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.devone-launch-card{border-radius:30px;background:linear-gradient(145deg,rgba(255,255,255,.08),rgba(255,255,255,.025));border:1px solid rgba(255,211,90,.18);padding:28px;box-shadow:0 24px 70px rgba(0,0,0,.26)}
.devone-launch-icon{width:52px;height:52px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,#ff7a18,#ff2d75);font-size:1.45rem;margin-bottom:18px}.devone-launch-card h3{margin:0 0 10px;font-size:1.35rem}.devone-launch-card p{margin:0;color:#eadbc8;line-height:1.72}
.devone-launch-split{display:grid;grid-template-columns:.95fr 1.05fr;gap:22px;align-items:center}.devone-launch-code{border-radius:32px;border:1px solid rgba(255,211,90,.2);background:#0c0b14;padding:24px;box-shadow:0 30px 80px rgba(0,0,0,.3);overflow:hidden}.devone-launch-code pre{margin:0;white-space:pre-wrap;color:#f5d9b0;line-height:1.7;font-size:.94rem}.devone-launch-copy{border-radius:32px;border:1px solid rgba(255,211,90,.2);background:radial-gradient(circle at top right,rgba(255,122,24,.16),transparent 40%),rgba(255,255,255,.055);padding:clamp(28px,4vw,48px)}.devone-launch-copy h2{margin:0 0 14px;font-size:clamp(2.2rem,4.5vw,4.4rem);line-height:.98;letter-spacing:-.06em}.devone-launch-copy p,.devone-launch-copy li{color:#eadbc8;line-height:1.75}.devone-launch-copy ul{margin:18px 0 0;padding-left:20px}
.devone-launch-cta{border-radius:36px;text-align:center;padding:70px 24px;background:linear-gradient(135deg,rgba(255,122,24,.16),rgba(255,45,117,.13));border:1px solid rgba(255,211,90,.22)}.devone-launch-cta h2{margin:0 0 12px;font-size:clamp(2.3rem,5vw,5rem);line-height:.96;letter-spacing:-.065em}.devone-launch-cta p{max-width:760px;margin:0 auto;color:#eadbc8;line-height:1.75}
@keyframes devoneGlow{from{transform:rotate(0deg) scale(1)}to{transform:rotate(8deg) scale(1.08)}}
@media(max-width:1000px){.devone-launch-panel,.devone-launch-grid{grid-template-columns:repeat(2,1fr)}.devone-launch-split{grid-template-columns:1fr}}@media(max-width:650px){.devone-launch-panel,.devone-launch-grid{grid-template-columns:1fr}.devone-launch-title{font-size:3.6rem}.devone-launch-hero{min-height:auto;padding:70px 18px}}
</style>
<div class="devone-launch-home devone-full-bleed alignfull">
<section class="devone-launch-hero">
  <div class="devone-launch-inner">
    <div class="devone-launch-badge"><span></span> Fresh Developer One CMS Install</div>
    <h1 class="devone-launch-title">Build faster with <strong>Developer One CMS.</strong></h1>
    <p class="devone-launch-lead">Your new site is ready. Developer One CMS gives you a clean admin dashboard, pages, media, themes, plugins, frontend scripts, roles, backups, logs, and a marketplace-ready foundation without the bloat.</p>
    <div class="devone-launch-actions">
      <a class="devone-launch-btn primary" href="admin/">Open Admin Dashboard</a>
      <a class="devone-launch-btn ghost" href="/">View Home Page</a>
    </div>
    <div class="devone-launch-panel">
      <div class="devone-launch-stat"><b>01</b><span>Create pages</span></div>
      <div class="devone-launch-stat"><b>02</b><span>Install themes</span></div>
      <div class="devone-launch-stat"><b>03</b><span>Add plugins</span></div>
      <div class="devone-launch-stat"><b>04</b><span>Launch faster</span></div>
    </div>
  </div>
</section>
<section class="devone-launch-section">
  <div class="devone-launch-heading">
    <h2>A clean foundation for real websites.</h2>
    <p>This starter home page is created automatically during installation so every fresh site feels polished from the first load.</p>
  </div>
  <div class="devone-launch-grid">
    <div class="devone-launch-card"><div class="devone-launch-icon">📄</div><h3>Pages & Menus</h3><p>Create public pages, manage menus, choose sticky/off-canvas navigation, and control the frontend experience from the dashboard.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🎨</div><h3>Themes</h3><p>Install, preview, and activate themes built for Developer One CMS. Start with the official presets or build your own.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🔌</div><h3>Plugins</h3><p>Extend the CMS with installable plugins, admin submenu pages, frontend hooks, and marketplace-ready package metadata.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🖼️</div><h3>Media Library</h3><p>Upload and organize images, documents, audio, video, archives, avatars, and user-owned media safely.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🛡️</div><h3>Roles & Security</h3><p>Manage users, roles, permissions, protected admin pages, secure sessions, and CSRF-protected forms.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🛠️</div><h3>Developer Tools</h3><p>Use frontend scripts, API builder, system logs, table debug, backups, and restore tools when building advanced sites.</p></div>
  </div>
</section>
<section class="devone-launch-section">
  <div class="devone-launch-split">
    <div class="devone-launch-code"><pre>&lt;!-- Example plugin metadata --&gt;
{
  "name": "My DevOne Plugin",
  "slug": "my-devone-plugin",
  "folder": "my-devone-plugin",
  "version": "1.0.0",
  "author": "Your Studio"
}</pre></div>
    <div class="devone-launch-copy"><h2>Built for creators, developers, and agencies.</h2><p>Developer One CMS is designed to stay simple at the core while letting themes and plugins do the heavy lifting.</p><ul><li>Launch a business site, artist site, landing page, documentation hub, or client project.</li><li>Install ecommerce, media, marketing, or custom workflow plugins as your site grows.</li><li>Keep your admin clean with grouped developer tools, modern cards, and responsive layouts.</li></ul></div>
  </div>
</section>
<section class="devone-launch-section">
  <div class="devone-launch-cta"><h2>Start building your site.</h2><p>Log in to the admin dashboard, update your site name and logo, create your first page, choose a theme, and install plugins from the Developer One marketplace.</p><div class="devone-launch-actions"><a class="devone-launch-btn primary" href="admin/">Go to Admin</a></div></div>
</section>
</div>
HTML;
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}pages` (slug,title,content,template,status) VALUES ('home','Home',?,'default','published')");
        $stmt->execute(array($home));
    } catch (Exception $e) {}
    try {
        $role = $pdo->prepare("INSERT IGNORE INTO `{$prefix}roles` (name,description,permissions) VALUES (?,?,?)");
        $role->execute(array('admin', 'Full system administrator.', json_encode(array('admin_all','*'))));
        $role->execute(array('developer', 'Developer with access to build tools, content, themes, plugins, libraries, APIs, modules, files, and backups.', json_encode(array('manage_dashboard','create_pages','edit_pages','publish_pages','manage_pages','upload_media','manage_media','view_all_media','manage_themes','manage_plugins','manage_libraries','manage_store','manage_modules','manage_api','manage_files','manage_backups','manage_menus','view_logs'))));
        $role->execute(array('editor', 'Can manage pages, menus, and media, but cannot manage system settings or install code packages.', json_encode(array('manage_dashboard','create_pages','edit_pages','publish_pages','manage_pages','upload_media','manage_media','view_all_media','manage_menus'))));
        $role->execute(array('author', 'Can create and edit own content and upload own media.', json_encode(array('manage_dashboard','create_pages','edit_pages','upload_media','manage_media'))));
        $role->execute(array('media_manager', 'Can upload and manage all media files.', json_encode(array('manage_dashboard','upload_media','manage_media','view_all_media'))));
        $role->execute(array('client', 'Limited user profile and own media access.', json_encode(array('manage_dashboard','upload_media','manage_media'))));
        $role->execute(array('subscriber', 'Default registered user. No privileged permissions are granted until a site administrator explicitly assigns them.', json_encode(array())));
    } catch (Exception $e) {}
    try {
        $set = $pdo->prepare("INSERT IGNORE INTO `{$prefix}settings` (setting_key,setting_value) VALUES (?,?)");
        $set->execute(array('site_name', 'Developer One CMS'));
        $set->execute(array('site_tagline', 'Developer-first CMS'));
        $set->execute(array('footer_text', 'Built with Developer One CMS'));
        $set->execute(array('site_theme', defined('SITE_THEME') ? SITE_THEME : 'devone-dark'));
        $set->execute(array('primary_menu', 'main'));
        $set->execute(array('menu_layout', 'top-sticky'));
        $set->execute(array('menu_style', 'classic'));
        $set->execute(array('site_width_mode', 'full'));
        $set->execute(array('show_admin_link_frontend', '1'));
        $set->execute(array('site_logo', ''));
        $set->execute(array('frontend_scripts_enabled', '1'));
        $set->execute(array('frontend_ajax_enabled', '1'));
        $set->execute(array('frontend_custom_css', ''));
        $set->execute(array('frontend_head_code', ''));
        $set->execute(array('frontend_footer_js', ''));
        $set->execute(array('frontend_page_ready_js', ''));
        $set->execute(array('devone_marketplace_manifest_url', ''));
        $set->execute(array('devone_marketplace_allow_remote_installs', '1'));
        $set->execute(array('default_user_role', 'subscriber'));
        $set->execute(array('allow_public_registration', '0'));
        $set->execute(array('mail_method', 'php_mail'));
        $set->execute(array('mail_from_name', 'Developer One CMS'));
        $set->execute(array('mail_from_email', ''));
        $set->execute(array('smtp_host', ''));
        $set->execute(array('smtp_port', '587'));
        $set->execute(array('smtp_encryption', 'tls'));
        $set->execute(array('smtp_username', ''));
        $set->execute(array('smtp_password', ''));
    } catch (Exception $e) {}
    try {
        $theme = $pdo->prepare("INSERT INTO `{$prefix}themes` (name,folder,active) SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM `{$prefix}themes` WHERE folder=?)");
        $theme->execute(array('DevOne Dark','devone-dark',1,'devone-dark'));
        $theme->execute(array('DevOne Light','devone-light',0,'devone-light'));
    } catch (Exception $e) {}
    try {
        $api = $pdo->prepare("INSERT INTO `{$prefix}api_endpoints` (name,path,method,source_table,auth_required,active) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM `{$prefix}api_endpoints` WHERE path=? AND method=?)");
        $api->execute(array('Pages API','pages','GET','pages',0,1,'pages','GET'));
    } catch (Exception $e) {}
    try {
        $menu = $pdo->prepare("INSERT IGNORE INTO `{$prefix}menus` (name,slug,items) VALUES ('Main Menu','main',?)");
        $menu->execute(array(json_encode(array(array('label'=>'Home','url'=>'/')))));
    } catch (Exception $e) {}
}

function devone_schema_create_dirs() {
    $dirs = array('content/media','content/media/site','content/media/avatars','content/media/images','content/media/documents','content/media/videos','content/media/audio','content/media/archives','content/media/other','content/files','content/plugins','content/libraries','content/modules','content/themes','content/marketplace','content/marketplace/packages','storage/cache','storage/logs');
    $base = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    foreach ($dirs as $dir) {
        $path = $base . '/' . $dir;
        if (!is_dir($path)) { @mkdir($path, 0775, true); }
    }
}

function devone_repair_core_schema($seed = true) {
    try {
        $pdo = db();
        $prefix = devone_schema_prefix();
        foreach (devone_schema_sql($prefix) as $sql) { $pdo->exec($sql); }
        if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
        devone_schema_add_missing_columns($pdo, $prefix);
        // Explicit repair must bypass persistent fast-path markers.
        if (function_exists('devone_component_schema_marker_clear')) {
            devone_component_schema_marker_clear('users');
            devone_component_schema_marker_clear('media');
        }
        if (function_exists('devone_ensure_user_profile_schema')) { devone_ensure_user_profile_schema(); }
        if (function_exists('devone_ensure_media_schema')) { devone_ensure_media_schema(); }
        if (function_exists('devone_asset_ensure_usage_table')) { devone_asset_ensure_usage_table(); }
        devone_schema_create_dirs();
        if ($seed) { devone_schema_seed_defaults($pdo, $prefix); }
        if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
        return array('ok'=>true, 'message'=>'Core tables repaired using prefix ' . $prefix);
    } catch (Exception $e) {
        return array('ok'=>false, 'message'=>$e->getMessage());
    }
}

function devone_missing_core_tables() {
    $missing = array();
    try {
        $pdo = db();
        $prefix = devone_schema_prefix();
        foreach (devone_schema_required_tables() as $name) {
            if (!devone_schema_table_exists_pdo($pdo, devone_schema_table($prefix, $name))) { $missing[] = $name; }
        }
    } catch (Exception $e) {
        $missing[] = 'database connection failed: ' . $e->getMessage();
    }
    return $missing;
}

function devone_core_schema_release_version() {
    if (defined('DEVONE_CORE_VERSION')) { return (string)DEVONE_CORE_VERSION; }
    if (defined('CMS_VERSION')) { return (string)CMS_VERSION; }
    return 'current';
}

function devone_core_schema_marker_path($version = '') {
    $version = $version !== '' ? (string)$version : devone_core_schema_release_version();
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $version);
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    return $root . '/storage/cache/core-schema-' . ($safe !== '' ? $safe : 'current') . '.ok';
}

function devone_maybe_repair_core_schema($force = false) {
    $marker = devone_core_schema_marker_path();
    if (!$force && is_file($marker)) {
        return array('ok'=>true, 'message'=>'Core schema already verified for ' . devone_core_schema_release_version(), 'cached'=>true);
    }

    $result = devone_repair_core_schema(true);
    if (!empty($result['ok'])) {
        $dir = dirname($marker);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents($marker, gmdate('c') . "\n", LOCK_EX);
    }
    return $result;
}

function devone_auto_repair_core_schema() {
    // DevOne 1.7+: normal requests never perform schema repair.
    // Use devone_repair_core_schema(true) from installer/updater/admin maintenance.
    return array('ok'=>true,'message'=>'Automatic Core schema repair disabled; use Admin repair when needed.','cached'=>true);
}
