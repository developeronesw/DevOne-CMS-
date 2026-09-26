<?php
/**
 * Developer One CMS - Branded Installation Wizard
 * GPLv3-or-later installer with User Agreement and GPL notice acknowledgments.
 * Copyright © 2026 Developer One Inc.
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $installerHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443')
        || $forwardedProto === 'https';
    if ($installerHttps) { ini_set('session.cookie_secure', '1'); }
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array('lifetime'=>0,'path'=>'/','secure'=>$installerHttps,'httponly'=>true,'samesite'=>'Lax'));
    }
    session_start();
}
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

$themes = [
    'devone-dark'  => 'Developer One Dark',
    'devone-light' => 'Developer One Light',
];

$step = max(1, min(7, (int)($_GET['step'] ?? 1)));
$error = '';
$success = '';
$configInstalled = false;
$configSiteUrl = '';
$configAdminUrl = '';

if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    $configInstalled = defined('DEVONE_INSTALLED') && DEVONE_INSTALLED === true;
    $configSiteUrl = defined('SITE_URL') ? (string)SITE_URL : '';
    $configAdminUrl = defined('ADMIN_URL') ? (string)ADMIN_URL : '';
}

if ($configInstalled) {
    $_SESSION['devone_install_done'] = true;
    if ($configSiteUrl !== '') { $_SESSION['devone_install_site_url'] = $configSiteUrl; }
    if ($configAdminUrl !== '') { $_SESSION['devone_install_admin_url'] = $configAdminUrl; }
    if ((int)($_GET['step'] ?? 7) !== 7) {
        header('Location: install.php?step=7&installed=1');
        exit;
    }
}

if (empty($_SESSION['devone_installer_csrf'])) {
    $_SESSION['devone_installer_csrf'] = bin2hex(random_bytes(32));
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    return $_SESSION['devone_installer_csrf'] ?? '';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), (string)$token)) {
        throw new RuntimeException('Security check failed. Please refresh the installer and try again.');
    }
}

function redirect_step(int $step): void {
    header('Location: ?step=' . $step);
    exit;
}

function guess_url(): string {
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || $forwardedProto === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) { $host = 'localhost'; }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/install.php';
    $path = preg_replace('#/[^/]*$#', '/', $script);
    return rtrim($scheme . '://' . $host . $path, '/') . '/';
}

function clean_db_identifier(string $value, string $field): string {
    $value = trim($value);
    if ($value === '' || !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new RuntimeException($field . ' may only contain letters, numbers, and underscores.');
    }
    return $value;
}

function clean_prefix(string $value): string {
    $value = trim($value) !== '' ? trim($value) : 'cms_';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new RuntimeException('Table prefix may only contain letters, numbers, and underscores.');
    }
    if (substr($value, -1) !== '_') {
        $value .= '_';
    }
    return $value;
}

function mysql_dsn_from_host(string $host): string {
    $host = trim($host);
    if ($host === '') {
        throw new RuntimeException('Database host is required.');
    }
    if (strpos($host, ';') !== false) {
        throw new RuntimeException('Database host cannot contain semicolons.');
    }
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
        return 'mysql:host=' . $m[1] . ';port=' . $m[2] . ';charset=utf8mb4';
    }
    return 'mysql:host=' . $host . ';charset=utf8mb4';
}

function devone_install_schema(PDO $pdo, string $prefix): void {
    $tables = [];
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}pages` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`slug` varchar(255) NOT NULL,`title` varchar(255) NOT NULL,`content` longtext NOT NULL,`template` varchar(100) DEFAULT 'default',`show_title` tinyint(1) NOT NULL DEFAULT 1,`status` enum('draft','published') DEFAULT 'published',`author_id` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_slug` (`site_id`,`slug`),KEY `slug` (`slug`),KEY `author_id` (`author_id`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}plugins` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(255) NOT NULL,`folder` varchar(255) NOT NULL,`version` varchar(50) DEFAULT '1.0.0',`description` text,`active` tinyint(1) DEFAULT 1,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `folder` (`folder`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}libraries` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(255) NOT NULL,`folder` varchar(500) NOT NULL,`type` enum('js','css') DEFAULT 'js',`source` enum('local','cdn') DEFAULT 'local',`url` varchar(1000) DEFAULT '',`description` text,`active` tinyint(1) DEFAULT 1,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}users` (`id` int NOT NULL AUTO_INCREMENT,`username` varchar(100) NOT NULL,`password` varchar(255) NOT NULL,`email` varchar(255) DEFAULT '',`display_name` varchar(160) DEFAULT '',`avatar` varchar(500) DEFAULT '',`bio` text,`role` varchar(50) DEFAULT 'subscriber',`status` varchar(30) DEFAULT 'active',`permissions_override` longtext,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `username` (`username`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}roles` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(100) NOT NULL,`description` text,`permissions` longtext,PRIMARY KEY (`id`),UNIQUE KEY `name` (`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}settings` (`id` int NOT NULL AUTO_INCREMENT,`setting_key` varchar(120) NOT NULL,`setting_value` longtext,PRIMARY KEY (`id`),UNIQUE KEY `setting_key` (`setting_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}media` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`filename` varchar(255) NOT NULL,`path` varchar(500) NOT NULL,`mime_type` varchar(120) DEFAULT '',`size_bytes` bigint DEFAULT 0,`alt_text` varchar(255) DEFAULT '',`folder` varchar(255) DEFAULT 'other',`media_type` varchar(50) DEFAULT 'other',`user_id` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),KEY `user_id` (`user_id`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}menus` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`name` varchar(120) NOT NULL,`slug` varchar(120) NOT NULL,`items` longtext,PRIMARY KEY (`id`),UNIQUE KEY `site_slug` (`site_id`,`slug`),KEY `slug` (`slug`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}themes` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`name` varchar(120) NOT NULL,`folder` varchar(120) NOT NULL,`active` tinyint(1) DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `site_folder` (`site_id`,`folder`),KEY `folder` (`folder`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}theme_packages` (`id` int NOT NULL AUTO_INCREMENT,`theme_slug` varchar(190) NOT NULL,`theme_name` varchar(190) NOT NULL,`theme_version` varchar(50) DEFAULT '1.0.0',`package_hash` char(64) NOT NULL,`storage_path` varchar(700) NOT NULL,`visibility` varchar(30) NOT NULL DEFAULT 'private',`uploaded_by` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `package_hash` (`package_hash`),KEY `theme_slug` (`theme_slug`),KEY `visibility` (`visibility`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}site_theme_entitlements` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`package_id` int NOT NULL,`theme_slug` varchar(190) NOT NULL,`source` varchar(40) NOT NULL DEFAULT 'upload',`license_owner` varchar(255) DEFAULT '',`status` varchar(30) NOT NULL DEFAULT 'active',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_theme` (`site_id`,`theme_slug`),KEY `package_id` (`package_id`),KEY `site_id` (`site_id`),KEY `status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}api_endpoints` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(120) NOT NULL,`path` varchar(255) NOT NULL,`method` varchar(12) DEFAULT 'GET',`source_table` varchar(120) NOT NULL,`auth_required` tinyint(1) DEFAULT 0,`active` tinyint(1) DEFAULT 1,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),KEY `path_method` (`path`,`method`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}modules` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(120) NOT NULL,`description` text,`folder` varchar(120) NOT NULL,`active` tinyint(1) DEFAULT 1,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `folder` (`folder`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}activity_logs` (`id` bigint NOT NULL AUTO_INCREMENT,`user_id` int DEFAULT NULL,`action` varchar(160) NOT NULL,`details` text,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}webhooks` (`id` int NOT NULL AUTO_INCREMENT,`name` varchar(120) NOT NULL,`event` varchar(120) NOT NULL,`target_url` varchar(500) NOT NULL,`active` tinyint(1) DEFAULT 1,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}sites` (`id` int NOT NULL AUTO_INCREMENT,`site_name` varchar(190) NOT NULL,`site_slug` varchar(190) NOT NULL,`auto_subdomain` varchar(255) DEFAULT '',`primary_domain` varchar(255) DEFAULT '',`domain_mode` varchar(30) DEFAULT 'auto',`owner_user_id` int DEFAULT NULL,`admin_username` varchar(100) DEFAULT '',`admin_email` varchar(255) DEFAULT '',`allow_client_domain` tinyint(1) DEFAULT 1,`allow_client_theme_uploads` tinyint(1) DEFAULT 0,`status` varchar(30) DEFAULT 'active',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_slug` (`site_slug`),KEY `owner_user_id` (`owner_user_id`),KEY `status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}site_domains` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`domain` varchar(255) NOT NULL,`domain_type` varchar(30) DEFAULT 'custom',`is_primary` tinyint(1) DEFAULT 0,`verification_token` varchar(120) DEFAULT '',`verification_status` varchar(30) DEFAULT 'pending',`ssl_status` varchar(30) DEFAULT 'unknown',`created_by` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`verified_at` datetime DEFAULT NULL,PRIMARY KEY (`id`),UNIQUE KEY `domain` (`domain`),KEY `site_id` (`site_id`),KEY `verification_status` (`verification_status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}site_users` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`user_id` int NOT NULL,`role` varchar(50) DEFAULT 'site_admin',`status` varchar(30) DEFAULT 'active',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_user` (`site_id`,`user_id`),KEY `user_id` (`user_id`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}site_settings` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`setting_key` varchar(120) NOT NULL,`setting_value` longtext,PRIMARY KEY (`id`),UNIQUE KEY `site_setting` (`site_id`,`setting_key`),KEY `site_id` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}site_themes` (`id` int NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL,`theme_name` varchar(190) NOT NULL,`theme_slug` varchar(190) NOT NULL,`theme_version` varchar(50) DEFAULT '1.0.0',`theme_author` varchar(190) DEFAULT '',`theme_path` varchar(500) NOT NULL,`theme_type` varchar(50) DEFAULT 'custom',`visibility` varchar(30) DEFAULT 'private',`status` varchar(30) DEFAULT 'inactive',`uploaded_by` int DEFAULT NULL,`created_at` datetime DEFAULT CURRENT_TIMESTAMP,`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `site_theme` (`site_id`,`theme_slug`),KEY `site_id` (`site_id`),KEY `status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
}

function devone_license_text(): string {
    $file = __DIR__ . '/LICENSE.txt';
    if (is_file($file)) {
        $text = file_get_contents($file);
        if (is_string($text) && trim($text) !== '') { return $text; }
    }
    return "GNU GENERAL PUBLIC LICENSE
Version 3, 29 June 2007

The complete GPL text should be present in LICENSE.txt.";
}

function devone_eula_text(): string {
    $file = __DIR__ . '/USER-AGREEMENT.txt';
    if (is_file($file)) {
        $text = file_get_contents($file);
        if (is_string($text) && trim($text) !== '') { return $text; }
    }
    return "DevOne CMS User Agreement and Online Services Notice

The complete notice should be present in USER-AGREEMENT.txt.";
}

function devone_do_install(): array {
    $db = $_SESSION['devone_install_db'] ?? [];
    $site = $_SESSION['devone_install_site'] ?? [];
    $adminData = $_SESSION['devone_install_admin'] ?? [];
    $mailData = $_SESSION['devone_install_mail'] ?? [];

    if (empty($_SESSION['devone_eula_accepted']) || empty($_SESSION['devone_license_accepted'])) {
        throw new RuntimeException('The User Agreement and GNU GPL notices must be acknowledged before installation.');
    }
    if (!$db || !$site || !$adminData) {
        throw new RuntimeException('Installer information is incomplete. Please return to the database and admin steps.');
    }

    $host = (string)$db['db_host'];
    $name = clean_db_identifier((string)$db['db_name'], 'Database name');
    $user = (string)$db['db_user'];
    $pass = (string)$db['db_pass'];
    $prefix = clean_prefix((string)$db['table_prefix']);
    $theme = (string)($site['theme'] ?? 'devone-dark');
    $siteName = trim((string)($site['site_name'] ?? 'Developer One CMS')) ?: 'Developer One CMS';
    $siteUrl = rtrim((string)($site['site_url'] ?? guess_url()), '/') . '/';
    $admin = trim((string)$adminData['admin_user']);
    $adminEmail = trim((string)($adminData['admin_email'] ?? ''));
    $adminPass = (string)$adminData['admin_pass'];
    $mailMethod = (string)($mailData['mail_method'] ?? 'php_mail');
    if (!in_array($mailMethod, ['php_mail','smtp'], true)) { $mailMethod = 'php_mail'; }
    $mailFromName = trim((string)($mailData['mail_from_name'] ?? $siteName)) ?: $siteName;
    $mailFromEmail = trim((string)($mailData['mail_from_email'] ?? $adminEmail));
    $smtpHost = trim((string)($mailData['smtp_host'] ?? ''));
    $smtpPort = trim((string)($mailData['smtp_port'] ?? '587'));
    $smtpEncryption = (string)($mailData['smtp_encryption'] ?? 'tls');
    if (!in_array($smtpEncryption, ['none','tls','ssl'], true)) { $smtpEncryption = 'tls'; }
    $smtpUsername = trim((string)($mailData['smtp_username'] ?? ''));
    $smtpPassword = (string)($mailData['smtp_password'] ?? '');

    if ($admin === '') {
        throw new RuntimeException('Admin username is required.');
    }
    if (strlen($adminPass) < 8) {
        throw new RuntimeException('Admin password must be at least 8 characters.');
    }

    $pdo = new PDO(mysql_dsn_from_host($host), $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$name}`");
    devone_install_schema($pdo, $prefix);

    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}users` (username,password,email,display_name,role,status) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE password=VALUES(password), email=VALUES(email), display_name=VALUES(display_name), role=VALUES(role), status=VALUES(status)");
    $stmt->execute([$admin, $hash, $adminEmail, $admin, 'admin', 'active']);
    $adminIdStmt = $pdo->prepare("SELECT id FROM `{$prefix}users` WHERE username=? LIMIT 1");
    $adminIdStmt->execute([$admin]);
    $adminUserId = (int)$adminIdStmt->fetchColumn();

    $role = $pdo->prepare("INSERT INTO `{$prefix}roles` (name,description,permissions) VALUES (?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description), permissions=VALUES(permissions)");
    $role->execute(['admin', 'Full system administrator.', json_encode(['admin_all','*'])]);
    $role->execute(['developer', 'Developer with access to build tools, content, themes, plugins, libraries, APIs, modules, files, and backups.', json_encode(['manage_dashboard','create_pages','edit_pages','publish_pages','manage_pages','upload_media','manage_media','view_all_media','manage_themes','manage_plugins','manage_libraries','manage_store','manage_modules','manage_api','manage_files','manage_backups','manage_menus','view_logs'])]);
    $role->execute(['editor', 'Can manage pages, menus, and media, but cannot manage system settings or install code packages.', json_encode(['manage_dashboard','create_pages','edit_pages','publish_pages','manage_pages','upload_media','manage_media','view_all_media','manage_menus'])]);
    $role->execute(['author', 'Can create and edit own content and upload own media.', json_encode(['manage_dashboard','create_pages','edit_pages','upload_media','manage_media'])]);
    $role->execute(['media_manager', 'Can upload and manage all media files.', json_encode(['manage_dashboard','upload_media','manage_media','view_all_media'])]);
    $role->execute(['client', 'Limited user profile and own media access.', json_encode(['manage_dashboard','upload_media','manage_media'])]);
    $role->execute(['subscriber', 'Default registered user. No privileged permissions are granted until a site administrator explicitly assigns them.', json_encode([])]);

    $siteHost = parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost';
    $siteHost = strtolower(preg_replace('/:\d+$/', '', (string)$siteHost));
    $networkBaseDomain = $siteHost ?: 'localhost';
    $siteSeed = $pdo->prepare("INSERT INTO `{$prefix}sites` (id,site_name,site_slug,auto_subdomain,primary_domain,domain_mode,owner_user_id,admin_username,admin_email,status) VALUES (1,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE site_name=VALUES(site_name), primary_domain=VALUES(primary_domain), admin_username=VALUES(admin_username), admin_email=VALUES(admin_email)");
    $siteSeed->execute([$siteName, 'main', $siteHost, $siteHost, 'custom', $adminUserId ?: null, $admin, $adminEmail, 'active']);
    $domainSeed = $pdo->prepare("INSERT INTO `{$prefix}site_domains` (site_id,domain,domain_type,is_primary,verification_status,verified_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE site_id=VALUES(site_id), is_primary=VALUES(is_primary), verification_status=VALUES(verification_status)");
    $domainSeed->execute([1, $siteHost, 'primary', 1, 'verified']);
    $siteUserSeed = $pdo->prepare("INSERT INTO `{$prefix}site_users` (site_id,user_id,role,status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role), status=VALUES(status)");
    $siteUserSeed->execute([1, $adminUserId ?: 1, 'network_admin', 'active']);
    $siteSettingsSeed = $pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $siteSettingsSeed->execute(['network_base_domain', $networkBaseDomain]);
    $siteSettingsSeed->execute(['network_mode_enabled', '0']);
    $siteSettingsSeed->execute(['devone_license_plan', 'free']);

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
      <a class="devone-launch-btn ghost" href="?page=home">View Home Page</a>
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
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}pages` (slug,title,content,template,status) VALUES ('home','Home',?,'default','published') ON DUPLICATE KEY UPDATE title='Home', content=VALUES(content), template='default', status='published'");
    $stmt->execute([$home]);

    $api = $pdo->prepare("INSERT IGNORE INTO `{$prefix}api_endpoints` (name,path,method,source_table,auth_required,active) VALUES ('Pages API','pages','GET','pages',0,1)");
    $api->execute();

    $menu = $pdo->prepare("INSERT INTO `{$prefix}menus` (name,slug,items) VALUES ('Main Menu','main',?) ON DUPLICATE KEY UPDATE items=VALUES(items)");
    $menu->execute([json_encode([['label'=>'Home','url'=>'/']])]);

    $themeStmt = $pdo->prepare("INSERT INTO `{$prefix}themes` (name,folder,active) VALUES (?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active)");
    foreach (['devone-dark' => 'Developer One Dark', 'devone-light' => 'Developer One Light'] as $folder => $label) {
        $themeStmt->execute([$label, $folder, $folder === $theme ? 1 : 0]);
    }

    $set = $pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $settings = [
        'site_name' => $siteName,
        'site_url' => $siteUrl,
        'site_theme' => $theme,

        'site_tagline' => 'Developer-first CMS',
        'footer_text' => 'Built with Developer One CMS',
        'primary_menu' => 'main',
        'menu_layout' => 'top-sticky',
        'site_width_mode' => 'full',
        'show_admin_link_frontend' => '1',
        'site_logo' => '',
        'frontend_scripts_enabled' => '1',
        'frontend_ajax_enabled' => '1',
        'frontend_custom_css' => '',
        'frontend_head_code' => '',
        'frontend_footer_js' => '',
        'frontend_page_ready_js' => '',
        'allow_public_registration' => '0',
        'default_user_role' => 'subscriber',
        'mail_method' => $mailMethod,
        'mail_from_name' => $mailFromName,
        'mail_from_email' => $mailFromEmail,
        'smtp_host' => $smtpHost,
        'smtp_port' => $smtpPort !== '' ? $smtpPort : '587',
        'smtp_encryption' => $smtpEncryption,
        'smtp_username' => $smtpUsername,
        'smtp_password' => $smtpPassword,
        'devone_marketplace_manifest_url' => '',
        'devone_marketplace_allow_remote_installs' => '1',
        'installer_version' => '1.7.4',
        'license_model' => 'GNU GPL v3 or later',
        'user_agreement_acknowledged_at' => date('c', (int)$_SESSION['devone_eula_accepted']),
        'gpl_notice_acknowledged_at' => date('c', (int)$_SESSION['devone_license_accepted']),
    ];
    foreach ($settings as $k => $v) {
        $set->execute([$k, $v]);
    }

    $cfg = "<?php\n";
    $cfg .= "define('DB_HOST', " . var_export($host, true) . ");\n";
    $cfg .= "define('DB_NAME', " . var_export($name, true) . ");\n";
    $cfg .= "define('DB_USER', " . var_export($user, true) . ");\n";
    $cfg .= "define('DB_PASS', " . var_export($pass, true) . ");\n";
    $cfg .= "define('SITE_URL', " . var_export($siteUrl, true) . ");\n";
    $cfg .= "define('ADMIN_URL', SITE_URL . 'admin/');\n";
    $cfg .= "define('CMS_VERSION', '1.7.4');\n";
    $cfg .= "define('SITE_THEME', " . var_export($theme, true) . ");\n";
    $cfg .= "define('TABLE_PREFIX', " . var_export($prefix, true) . ");\n";
    $cfg .= "define('DEVONE_INSTALLED', true);\n";
    

    if (@file_put_contents(__DIR__ . '/config.php', $cfg, LOCK_EX) === false) {
        throw new RuntimeException('Could not write config.php. Make sure the CMS root folder is writable, then run the installer again.');
    }
    @chmod(__DIR__ . '/config.php', 0640);

    $_SESSION['devone_install_mail_result'] = 'No admin email was supplied, so no installation email was sent.';
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            require_once __DIR__ . '/config.php';
            require_once __DIR__ . '/core/db.php';
            require_once __DIR__ . '/core/functions.php';
            require_once __DIR__ . '/core/mailer.php';
            if (function_exists('devone_send_email')) {
                $mail = devone_send_email(
                    $adminEmail,
                    'Developer One CMS installation complete',
                    '<p>Your <strong>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</strong> installation is complete.</p>' .
                    '<p><strong>Admin URL:</strong> <a href="' . htmlspecialchars($siteUrl . 'admin/', ENT_QUOTES, 'UTF-8') . '" style="color:#28b8ff;">' . htmlspecialchars($siteUrl . 'admin/', ENT_QUOTES, 'UTF-8') . '</a></p>' .
                    '<p><strong>Username:</strong> ' . htmlspecialchars($admin, ENT_QUOTES, 'UTF-8') . '</p>' .
                    '<p>You can now sign in and finish configuring your site.</p>',
                    "Developer One CMS installation complete

Admin URL: " . $siteUrl . "admin/
Username: " . $admin
                );
                $_SESSION['devone_install_mail_result'] = !empty($mail['ok']) ? 'Installation email sent to ' . $adminEmail . '.' : 'Installation email could not be sent: ' . ($mail['message'] ?? 'Unknown mail error.');
            }
        } catch (Throwable $mailError) {
            $_SESSION['devone_install_mail_result'] = 'Installation email could not be sent: ' . $mailError->getMessage();
        }
    }

    unset($_SESSION['devone_install_db'], $_SESSION['devone_install_site'], $_SESSION['devone_install_admin'], $_SESSION['devone_install_mail']);
    $_SESSION['devone_install_done'] = true;
    $_SESSION['devone_install_site_url'] = $siteUrl;
    $_SESSION['devone_install_admin_url'] = $siteUrl . 'admin/';

    return ['ok' => true, 'message' => 'Developer One CMS installed successfully.', 'redirect' => 'install.php?step=7&installed=1'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        if ($step === 2) {
            if (empty($_POST['accept_eula'])) {
                throw new RuntimeException('You must acknowledge the DevOne CMS User Agreement before continuing.');
            }
            $_SESSION['devone_eula_accepted'] = time();
            redirect_step(3);
        }

        if ($step === 3) {
            if (empty($_POST['accept_license'])) {
                throw new RuntimeException('You must acknowledge that you received and reviewed the GNU GPL before continuing.');
            }
            $_SESSION['devone_license_accepted'] = time();
            redirect_step(4);
        }

        if ($step === 4) {
            $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
            $dbName = clean_db_identifier((string)($_POST['db_name'] ?? 'devonecms'), 'Database name');
            $dbUser = trim((string)($_POST['db_user'] ?? 'root'));
            $dbPass = (string)($_POST['db_pass'] ?? '');
            $tablePrefix = clean_prefix((string)($_POST['table_prefix'] ?? 'cms_'));
            $siteName = trim((string)($_POST['site_name'] ?? 'Developer One CMS')) ?: 'Developer One CMS';
            $siteUrl = trim((string)($_POST['site_url'] ?? guess_url())) ?: guess_url();
            $theme = array_key_exists((string)($_POST['theme'] ?? 'devone-dark'), $GLOBALS['themes']) ? (string)$_POST['theme'] : 'devone-dark';

            if ($dbHost === '' || $dbUser === '') {
                throw new RuntimeException('Database host and database user are required.');
            }
            $siteParts = parse_url($siteUrl);
            $siteScheme = strtolower((string)($siteParts['scheme'] ?? ''));
            if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !in_array($siteScheme, array('http','https'), true) || empty($siteParts['host']) || isset($siteParts['user']) || isset($siteParts['pass'])) {
                throw new RuntimeException('Please enter a valid HTTP or HTTPS Site URL without embedded credentials.');
            }

            $_SESSION['devone_install_db'] = [
                'db_host' => $dbHost,
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'db_pass' => $dbPass,
                'table_prefix' => $tablePrefix,
            ];
            $_SESSION['devone_install_site'] = [
                'site_name' => $siteName,
                'site_url' => rtrim($siteUrl, '/') . '/',
                'theme' => $theme,
            ];
            redirect_step(5);
        }

        if ($step === 5) {
            $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
            $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
            $adminPass = (string)($_POST['admin_pass'] ?? '');
            $adminPass2 = (string)($_POST['admin_pass_confirm'] ?? '');

            if ($adminUser === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $adminUser)) {
                throw new RuntimeException('Admin username may only contain letters, numbers, underscores, periods, and dashes.');
            }
            if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid admin email address.');
            }
            if (strlen($adminPass) < 8) {
                throw new RuntimeException('Admin password must be at least 8 characters.');
            }
            if ($adminPass !== $adminPass2) {
                throw new RuntimeException('Admin password confirmation does not match.');
            }

            $mailMethod = $_POST['mail_method'] ?? 'php_mail';
            if (!in_array($mailMethod, ['php_mail','smtp'], true)) { $mailMethod = 'php_mail'; }
            $mailFromName = trim((string)($_POST['mail_from_name'] ?? ($_SESSION['devone_install_site']['site_name'] ?? 'Developer One CMS')));
            if ($mailFromName === '') { $mailFromName = (string)($_SESSION['devone_install_site']['site_name'] ?? 'Developer One CMS'); }
            $mailFromEmail = trim((string)($_POST['mail_from_email'] ?? $adminEmail));
            if ($mailFromEmail !== '' && !filter_var($mailFromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid From Email address for outgoing mail.');
            }
            $smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
            if ($mailMethod === 'smtp' && $smtpHost === '') {
                throw new RuntimeException('SMTP Host is required when SMTP is selected.');
            }
            $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';
            if (!in_array($smtpEncryption, ['none','tls','ssl'], true)) { $smtpEncryption = 'tls'; }

            $_SESSION['devone_install_admin'] = [
                'admin_user' => $adminUser,
                'admin_email' => $adminEmail,
                'admin_pass' => $adminPass,
            ];
            $_SESSION['devone_install_mail'] = [
                'mail_method' => $mailMethod,
                'mail_from_name' => $mailFromName,
                'mail_from_email' => $mailFromEmail,
                'smtp_host' => $smtpHost,
                'smtp_port' => trim((string)($_POST['smtp_port'] ?? '587')) ?: '587',
                'smtp_encryption' => $smtpEncryption,
                'smtp_username' => trim((string)($_POST['smtp_username'] ?? '')),
                'smtp_password' => (string)($_POST['smtp_password'] ?? ''),
            ];
            redirect_step(6);
        }

        if ($step === 6 && isset($_GET['run'])) {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $result = devone_do_install();
                echo json_encode($result);
            } catch (Throwable $installError) {
                $_SESSION['devone_install_error'] = $installError->getMessage();
                echo json_encode(['ok' => false, 'message' => $installError->getMessage()]);
            }
            exit;
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

if ($step >= 3 && empty($_SESSION['devone_eula_accepted'])) {
    redirect_step(2);
}
if ($step >= 4 && empty($_SESSION['devone_license_accepted'])) {
    redirect_step(3);
}
if ($step >= 5 && empty($_SESSION['devone_install_db']) && empty($_SESSION['devone_install_done'])) {
    redirect_step(4);
}
if ($step >= 6 && empty($_SESSION['devone_install_admin']) && empty($_SESSION['devone_install_done'])) {
    redirect_step(5);
}

$logoCandidates = [
    'assets/img/developer-one-cms-logo.png',
    'assets/images/developer-one-cms-logo.png',
    'assets/img/devone-logo.png',
    'Developer_One_CMS_official_Logo glow.png',
];
$logoPath = '';
foreach ($logoCandidates as $candidate) {
    if (is_file(__DIR__ . '/' . $candidate)) {
        $logoPath = $candidate;
        break;
    }
}

function step_class(int $current, int $item): string {
    if ($item < $current) return 'done';
    if ($item === $current) return 'active';
    return '';
}

$stepTitles = [
    1 => 'Welcome',
    2 => 'User Agreement',
    3 => 'GNU GPL',
    4 => 'Database & Site',
    5 => 'Admin Account',
    6 => 'Install',
    7 => 'Complete',
];

$dbDefaults = $_SESSION['devone_install_db'] ?? [];
$siteDefaults = $_SESSION['devone_install_site'] ?? [];
$adminDefaults = $_SESSION['devone_install_admin'] ?? [];
$mailDefaults = $_SESSION['devone_install_mail'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Developer One CMS Installation Wizard</title>
<style>
:root{
    --bg:#030815;
    --panel:rgba(9,18,38,.78);
    --panel2:rgba(9,18,38,.56);
    --border:rgba(92,179,255,.22);
    --border-strong:rgba(161,83,255,.55);
    --text:#f7fbff;
    --muted:#aeb9cf;
    --blue:#33b7ff;
    --violet:#b737ff;
    --green:#29e48d;
    --red:#ff4e77;
    --shadow:0 30px 90px rgba(0,0,0,.48);
}
*{box-sizing:border-box}
html,body{min-height:100%}
body{
    margin:0;
    font-family:Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    color:var(--text);
    background:
        radial-gradient(circle at 20% 0%, rgba(88,45,255,.33), transparent 28%),
        radial-gradient(circle at 90% 22%, rgba(41,183,255,.25), transparent 25%),
        radial-gradient(circle at 50% 100%, rgba(152,45,255,.18), transparent 32%),
        linear-gradient(145deg,#01040d 0%,#071225 48%,#030815 100%);
    overflow-x:hidden;
}
body:before{
    content:"";
    position:fixed; inset:0;
    pointer-events:none;
    background-image:radial-gradient(rgba(255,255,255,.48) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.12;
    mask-image:linear-gradient(to bottom, rgba(0,0,0,.85), transparent 78%);
}
a{color:inherit}
.wrap{width:min(1160px, calc(100% - 36px)); margin:28px auto; min-height:calc(100vh - 56px); display:grid; grid-template-columns:260px 1fr; border:1px solid rgba(92,179,255,.28); border-radius:28px; background:linear-gradient(180deg, rgba(8,16,35,.82), rgba(5,10,23,.74)); box-shadow:var(--shadow); overflow:hidden; position:relative}
.wrap:before{content:""; position:absolute; inset:-1px; pointer-events:none; border-radius:28px; background:linear-gradient(135deg, rgba(51,183,255,.25), transparent 32%, rgba(183,55,255,.28)); opacity:.9; z-index:0}
.sidebar,.stage{position:relative; z-index:1}
.sidebar{padding:30px 22px; border-right:1px solid rgba(92,179,255,.16); background:rgba(3,8,21,.58)}
.brand-mini{display:flex; gap:13px; align-items:center; margin-bottom:30px}
.brand-mark{width:44px;height:44px;border-radius:16px; display:grid; place-items:center; background:linear-gradient(135deg, rgba(51,183,255,.22), rgba(183,55,255,.28)); border:1px solid rgba(255,255,255,.16); box-shadow:0 0 34px rgba(88,86,255,.22); font-weight:900; letter-spacing:-.08em}
.brand-mini strong{display:block; font-size:19px; line-height:1.05}.brand-mini span{display:block;color:var(--muted);font-size:12px;margin-top:3px}
.steps{display:grid; gap:18px; position:relative}.steps:before{content:""; position:absolute; left:18px; top:20px; bottom:20px; width:1px; background:linear-gradient(to bottom, rgba(51,183,255,.6), rgba(183,55,255,.25))}
.step{display:flex; align-items:center; gap:12px; color:var(--muted); font-size:13px; position:relative}.bubble{width:36px;height:36px;border-radius:50%; display:grid; place-items:center; border:1px solid rgba(255,255,255,.32); background:rgba(5,11,26,.88); color:#dce7ff; font-size:13px; flex:0 0 36px}.step.active{color:#fff}.step.active .bubble{border-color:rgba(51,183,255,.85); background:linear-gradient(135deg,var(--blue),var(--violet)); box-shadow:0 0 24px rgba(51,183,255,.35)}.step.done .bubble{border-color:rgba(41,228,141,.65); background:rgba(41,228,141,.12); color:var(--green)}
.stage{padding:34px clamp(22px, 4vw, 54px) 44px}.top{display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:28px}.logo-block{display:flex; align-items:center; gap:18px}.logo-img{width:96px; height:auto; filter:drop-shadow(0 0 20px rgba(111,77,255,.38))}.logo-fallback{width:70px;height:70px;border-radius:24px;display:grid;place-items:center;background:linear-gradient(135deg, rgba(51,183,255,.16), rgba(183,55,255,.2));border:1px solid rgba(255,255,255,.14);font-weight:900;font-size:26px;color:#fff}.top h1{font-size:clamp(30px, 4vw, 52px);margin:0;letter-spacing:-.05em}.grad{background:linear-gradient(90deg,#fff 0%,#39c7ff 47%,#b83cff 100%); -webkit-background-clip:text; background-clip:text; color:transparent}.top p{margin:6px 0 0;color:var(--muted)}.code-corner{width:38px;height:38px;border-radius:14px;border:1px solid rgba(255,255,255,.13);display:grid;place-items:center;color:#c9d7f6;background:rgba(255,255,255,.04)}
.card{border:1px solid var(--border); background:linear-gradient(180deg, rgba(12,25,52,.76), rgba(5,12,28,.72)); border-radius:24px; padding:clamp(22px, 4vw, 44px); box-shadow:0 18px 70px rgba(0,0,0,.28); position:relative; overflow:hidden}.card:before{content:""; position:absolute; inset:0; background:radial-gradient(circle at 80% 0%, rgba(183,55,255,.13), transparent 30%), radial-gradient(circle at 5% 90%, rgba(51,183,255,.11), transparent 27%); pointer-events:none}.card>*{position:relative}.center{text-align:center; max-width:720px; margin-inline:auto}.eyebrow{text-transform:uppercase; letter-spacing:.22em; color:var(--blue); font-weight:800; font-size:12px; margin:0 0 10px}.card h2{font-size:clamp(28px,3.4vw,46px); margin:0 0 12px; letter-spacing:-.04em}.lead{font-size:17px;color:#d8e4fb;line-height:1.65; margin:0 auto 24px; max-width:760px}.feature-box{display:grid; gap:13px; width:min(420px, 100%); margin:28px auto; padding:22px; border:1px solid rgba(255,255,255,.11); border-radius:18px; background:rgba(0,0,0,.18); text-align:left}.feature{display:flex; align-items:center; gap:12px; color:#e8efff}.check{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;background:rgba(41,228,141,.12);color:var(--green);border:1px solid rgba(41,228,141,.6);font-size:13px}.btns{display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:center; margin-top:26px}.btn,button.btn{border:0; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:10px; min-height:50px; padding:0 22px; border-radius:12px; color:white; font-weight:800; font-size:15px; background:linear-gradient(135deg,var(--blue),var(--violet)); box-shadow:0 13px 34px rgba(83,71,255,.32)}.btn.secondary{background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.14); box-shadow:none; color:#e4ecff}.btn[disabled],button[disabled]{opacity:.45; cursor:not-allowed; filter:saturate(.3)}
.legal-box{height:390px; overflow:auto; padding:22px; border-radius:18px; border:1px solid rgba(255,255,255,.13); background:rgba(0,0,0,.28); color:#d9e3f8; line-height:1.58; font-size:13px; white-space:pre-wrap; text-align:left}.accept-row{display:flex; gap:13px; align-items:flex-start; text-align:left; margin-top:20px; padding:16px; border:1px solid rgba(255,255,255,.12); border-radius:16px; background:rgba(255,255,255,.04)}.accept-row input{width:22px;height:22px; accent-color:#40bdff; margin-top:2px}.accept-row strong{display:block;margin-bottom:4px}.accept-row span{color:var(--muted); font-size:13px; line-height:1.5}
.form-grid{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px}.field{display:grid; gap:7px; text-align:left}.field label{font-weight:750; color:#edf4ff; font-size:13px}.field input,.field select{width:100%; min-height:48px; border-radius:13px; border:1px solid rgba(255,255,255,.14); background:rgba(0,0,0,.26); color:#fff; padding:0 14px; outline:none}.field input:focus,.field select:focus{border-color:rgba(51,183,255,.75); box-shadow:0 0 0 4px rgba(51,183,255,.12)}.hint{font-size:12px; color:var(--muted); line-height:1.4}.theme-grid{display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:16px}.theme-card{border:1px solid rgba(255,255,255,.13); border-radius:16px; padding:14px; background:rgba(0,0,0,.18); cursor:pointer; text-align:left}.theme-card input{accent-color:#45bdff}.theme-card strong{display:block;margin:8px 0 4px}.theme-card span{font-size:12px;color:var(--muted)}.alert{border:1px solid rgba(255,78,119,.42); color:#ffe9ef; background:rgba(255,78,119,.12); padding:14px 16px; border-radius:15px; margin-bottom:18px}.notice{border:1px solid rgba(41,228,141,.35); color:#e8fff4; background:rgba(41,228,141,.10); padding:14px 16px; border-radius:15px; margin-bottom:18px}.mini-summary{display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin:20px 0}.mini-summary div{padding:14px;border-radius:15px;background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.1); text-align:left}.mini-summary small{display:block;color:var(--muted);margin-bottom:5px}.mini-summary strong{font-size:14px;word-break:break-word}.loader-wrap{max-width:650px;margin:32px auto 0}.progress-shell{height:18px;border-radius:999px; background:rgba(255,255,255,.09); border:1px solid rgba(255,255,255,.12); overflow:hidden}.progress-bar{width:8%; height:100%; border-radius:999px; background:linear-gradient(90deg,var(--blue),var(--violet)); box-shadow:0 0 30px rgba(65,188,255,.48); transition:width .35s ease}.install-log{margin-top:18px; text-align:left; padding:16px; border-radius:16px; background:rgba(0,0,0,.28); border:1px solid rgba(255,255,255,.11); color:#cdd9f3; min-height:80px}.complete-logo{max-width:340px;width:70%;height:auto;margin:0 auto 20px;display:block;filter:drop-shadow(0 0 34px rgba(106,74,255,.42))}.footer-note{color:var(--muted);font-size:12px;text-align:center;margin-top:18px;line-height:1.5}
@media(max-width:920px){.wrap{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid rgba(92,179,255,.16)}.steps{grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px}.steps:before{display:none}.step{font-size:11px;align-items:flex-start}.bubble{width:30px;height:30px;flex-basis:30px}.top{align-items:flex-start}.theme-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:660px){.wrap{width:calc(100% - 20px);margin:10px auto;border-radius:18px}.stage,.sidebar{padding:20px 16px}.top{display:block}.code-corner{display:none}.logo-block{align-items:flex-start}.logo-img{width:80px}.form-grid,.theme-grid,.mini-summary{grid-template-columns:1fr}.btns{justify-content:stretch}.btn,button.btn{width:100%}.legal-box{height:320px}.steps{grid-template-columns:1fr 1fr}.step span:last-child{display:block}}
</style>
</head>
<body>
<div class="wrap">
    <aside class="sidebar">
        <div class="brand-mini">
            <div class="brand-mark">D1</div>
            <div><strong>Developer One</strong><span>CMS Installer v1.7.4</span></div>
        </div>
        <nav class="steps" aria-label="Installation steps">
            <?php foreach ($stepTitles as $num => $label): ?>
                <div class="step <?= e(step_class($step, $num)) ?>"><span class="bubble"><?= $num < $step ? '✓' : $num ?></span><span><?= e($label) ?></span></div>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="stage">
        <div class="top">
            <div class="logo-block">
                <?php if ($logoPath): ?>
                    <img class="logo-img" src="<?= e($logoPath) ?>" alt="Developer One CMS Logo">
                <?php else: ?>
                    <div class="logo-fallback">&lt;/&gt;</div>
                <?php endif; ?>
                <div>
                    <h1>Developer One <span class="grad">CMS</span></h1>
                    <p>Installation Wizard • GNU GPL v3 or later</p>
                </div>
            </div>
            <div class="code-corner">&lt;/&gt;</div>
        </div>

        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

        <?php if ($step === 1): ?>
            <section class="card center">
                <p class="eyebrow">Welcome</p>
                <h2>Welcome to <span class="grad">Developer One CMS</span></h2>
                <p class="lead">The modern, lightweight CMS built for developers, agencies, creators, and businesses that want control without the bloat.</p>
                <div class="feature-box">
                    <div class="feature"><span class="check">✓</span><span>Step-by-step branded installation</span></div>
                    <div class="feature"><span class="check">✓</span><span>GPLv3-or-later license notice and user agreement</span></div>
                    <div class="feature"><span class="check">✓</span><span>Database, site, and admin setup</span></div>
                    <div class="feature"><span class="check">✓</span><span>Theme and plugin-ready architecture</span></div>
                </div>
                <div class="btns"><a class="btn" href="?step=2">Start Installation <span>→</span></a></div>
                <p class="footer-note">DevOne CMS Core is free software under GNU GPL v3 or later. Optional Pro and Enterprise services remain license-gated in the official distribution.</p>
            </section>
        <?php elseif ($step === 2): ?>
            <section class="card">
                <p class="eyebrow">Step 2</p>
                <h2>User Agreement & Online Services Notice</h2>
                <p class="lead">Review the installer, trademark, and optional online-services notice before continuing.</p>
                <div class="legal-box"><?= e(devone_eula_text()) ?></div>
                <form method="post" id="eulaForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label class="accept-row">
                        <input type="checkbox" name="accept_eula" id="acceptEula" value="1">
                        <span><strong>I acknowledge the DevOne CMS User Agreement and Online Services Notice.</strong><span>I understand that the GPL governs the Core, while optional marketplace, licensing, updates, and hosted services may have separate terms.</span></span>
                    </label>
                    <div class="btns"><a class="btn secondary" href="?step=1">← Back</a><button class="btn" id="eulaNext" disabled>Acknowledge & Continue →</button></div>
                </form>
            </section>
        <?php elseif ($step === 3): ?>
            <section class="card">
                <p class="eyebrow">Step 3</p>
                <h2>GNU General Public License</h2>
                <p class="lead">DevOne CMS Core is licensed under GNU GPL version 3, or at your option, any later version.</p>
                <div class="legal-box"><?= e(devone_license_text()) ?></div>
                <form method="post" id="licenseForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label class="accept-row">
                        <input type="checkbox" name="accept_license" id="acceptLicense" value="1">
                        <span><strong>I have received and reviewed GNU GPL version 3.</strong><span>I understand that the GPL permits use, study, modification, and redistribution under its terms, and that it does not grant Developer One trademark rights.</span></span>
                    </label>
                    <div class="btns"><a class="btn secondary" href="?step=2">← Back</a><button class="btn" id="licenseNext" disabled>Acknowledge & Continue →</button></div>
                </form>
            </section>
        <?php elseif ($step === 4): ?>
            <section class="card">
                <p class="eyebrow">Step 4</p>
                <h2>Database Setup & Site Info</h2>
                <p class="lead">Connect Developer One CMS to your database and set the first site details.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="form-grid">
                        <div class="field"><label for="db_host">Database Host</label><input id="db_host" name="db_host" value="<?= e((string)($dbDefaults['db_host'] ?? 'localhost')) ?>" required><div class="hint">Usually localhost on XAMPP/cPanel.</div></div>
                        <div class="field"><label for="db_name">Database Name</label><input id="db_name" name="db_name" value="<?= e((string)($dbDefaults['db_name'] ?? 'devonecms')) ?>" required><div class="hint">Letters, numbers, and underscores only.</div></div>
                        <div class="field"><label for="db_user">Database User</label><input id="db_user" name="db_user" value="<?= e((string)($dbDefaults['db_user'] ?? 'root')) ?>" required></div>
                        <div class="field"><label for="db_pass">Database Password</label><input id="db_pass" name="db_pass" type="password" value="<?= e((string)($dbDefaults['db_pass'] ?? '')) ?>"></div>
                        <div class="field"><label for="table_prefix">Table Prefix</label><input id="table_prefix" name="table_prefix" value="<?= e((string)($dbDefaults['table_prefix'] ?? 'cms_')) ?>" required><div class="hint">Example: cms_ or d1_</div></div>
                        <div class="field"><label for="site_url">Site URL</label><input id="site_url" name="site_url" value="<?= e((string)($siteDefaults['site_url'] ?? guess_url())) ?>" required></div>
                        <div class="field"><label for="site_name">Site Name</label><input id="site_name" name="site_name" value="<?= e((string)($siteDefaults['site_name'] ?? 'Developer One CMS')) ?>" required></div>
                        <div class="field"><label for="theme">Default Theme</label><select id="theme" name="theme"><?php foreach ($themes as $k => $label): ?><option value="<?= e($k) ?>" <?= (($siteDefaults['theme'] ?? 'devone-dark') === $k) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="theme-grid">
                        <?php foreach ($themes as $k => $label): ?>
                            <label class="theme-card"><input type="radio" name="theme" value="<?= e($k) ?>" <?= (($siteDefaults['theme'] ?? 'devone-dark') === $k) ? 'checked' : '' ?>> <strong><?= e($label) ?></strong><span>Official Developer One CMS theme preset.</span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="btns"><a class="btn secondary" href="?step=3">← Back</a><button class="btn">Save & Continue →</button></div>
                </form>
            </section>
        <?php elseif ($step === 5): ?>
            <section class="card">
                <p class="eyebrow">Step 5</p>
                <h2>Admin User Information</h2>
                <p class="lead">Create the first administrator account for this Developer One CMS installation.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="form-grid">
                        <div class="field"><label for="admin_user">Admin Username</label><input id="admin_user" name="admin_user" value="<?= e((string)($adminDefaults['admin_user'] ?? 'admin')) ?>" required></div>
                        <div class="field"><label for="admin_email">Admin Email</label><input id="admin_email" name="admin_email" type="email" value="<?= e((string)($adminDefaults['admin_email'] ?? '')) ?>"></div>
                        <div class="field"><label for="admin_pass">Admin Password</label><input id="admin_pass" name="admin_pass" type="password" required><div class="hint">Minimum 8 characters.</div></div>
                        <div class="field"><label for="admin_pass_confirm">Confirm Password</label><input id="admin_pass_confirm" name="admin_pass_confirm" type="password" required></div>
                    </div>
                    <div class="mini-summary" style="margin-top:18px;text-align:left">
                        <div><small>Email System</small><strong>Core PHPMailer Transport</strong></div>
                        <div><small>Purpose</small><strong>Install, users, receipts</strong></div>
                    </div>
                    <h3 style="margin-top:22px">Optional Email Setup</h3>
                    <p class="lead" style="font-size:1rem">Use SMTP here if your server does not support PHP mail. These settings can also be changed later in Admin → Developer Tools → SMTP Settings.</p>
                    <div class="form-grid">
                        <div class="field"><label for="mail_method">Mail Method</label><select id="mail_method" name="mail_method"><option value="php_mail" <?= (($mailDefaults['mail_method'] ?? 'php_mail') !== 'smtp') ? 'selected' : '' ?>>PHP mail() / Server Mail</option><option value="smtp" <?= (($mailDefaults['mail_method'] ?? 'php_mail') === 'smtp') ? 'selected' : '' ?>>SMTP</option></select></div>
                        <div class="field"><label for="mail_from_name">From Name</label><input id="mail_from_name" name="mail_from_name" value="<?= e((string)($mailDefaults['mail_from_name'] ?? ($siteDefaults['site_name'] ?? 'Developer One CMS'))) ?>"></div>
                        <div class="field"><label for="mail_from_email">From Email</label><input id="mail_from_email" name="mail_from_email" type="email" value="<?= e((string)($mailDefaults['mail_from_email'] ?? ($adminDefaults['admin_email'] ?? ''))) ?>" placeholder="noreply@example.com"></div>
                        <div class="field"><label for="smtp_host">SMTP Host</label><input id="smtp_host" name="smtp_host" value="<?= e((string)($mailDefaults['smtp_host'] ?? '')) ?>" placeholder="smtp.example.com"></div>
                        <div class="field"><label for="smtp_port">SMTP Port</label><input id="smtp_port" name="smtp_port" value="<?= e((string)($mailDefaults['smtp_port'] ?? '587')) ?>" placeholder="587"></div>
                        <div class="field"><label for="smtp_encryption">SMTP Encryption</label><select id="smtp_encryption" name="smtp_encryption"><option value="tls" <?= (($mailDefaults['smtp_encryption'] ?? 'tls') === 'tls') ? 'selected' : '' ?>>TLS / STARTTLS</option><option value="ssl" <?= (($mailDefaults['smtp_encryption'] ?? 'tls') === 'ssl') ? 'selected' : '' ?>>SSL</option><option value="none" <?= (($mailDefaults['smtp_encryption'] ?? 'tls') === 'none') ? 'selected' : '' ?>>None</option></select></div>
                        <div class="field"><label for="smtp_username">SMTP Username</label><input id="smtp_username" name="smtp_username" value="<?= e((string)($mailDefaults['smtp_username'] ?? '')) ?>" placeholder="SMTP username or email"></div>
                        <div class="field"><label for="smtp_password">SMTP Password</label><input id="smtp_password" name="smtp_password" type="password" value="<?= e((string)($mailDefaults['smtp_password'] ?? '')) ?>" placeholder="SMTP password"></div>
                    </div>
                    <div class="mini-summary">
                        <div><small>Site</small><strong><?= e((string)($siteDefaults['site_name'] ?? 'Developer One CMS')) ?></strong></div>
                        <div><small>Database</small><strong><?= e((string)($dbDefaults['db_name'] ?? 'devonecms')) ?></strong></div>
                        <div><small>Theme</small><strong><?= e((string)($themes[$siteDefaults['theme'] ?? 'devone-dark'] ?? 'Developer One Dark')) ?></strong></div>
                    </div>
                    <div class="btns"><a class="btn secondary" href="?step=4">← Back</a><button class="btn">Begin Installation →</button></div>
                </form>
            </section>
        <?php elseif ($step === 6): ?>
            <section class="card center">
                <p class="eyebrow">Step 6</p>
                <h2>Installing <span class="grad">Developer One CMS</span></h2>
                <p class="lead">Creating the database schema, admin account, core records, theme settings, core email settings, legal acceptance records, and config file.</p>
                <div class="loader-wrap">
                    <div class="progress-shell"><div class="progress-bar" id="progressBar"></div></div>
                    <div class="install-log" id="installLog">Preparing installer…</div>
                </div>
                <form id="fallbackRun" method="post" action="?step=6&run=1" style="display:none"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"></form>
                <div class="btns" id="installActions" style="display:none"><a class="btn secondary" href="?step=4">Back to Setup</a><a class="btn" href="install.php?step=7&installed=1">Continue</a></div>
            </section>
            <script>
            (function(){
                const bar = document.getElementById('progressBar');
                const log = document.getElementById('installLog');
                const actions = document.getElementById('installActions');
                let pct = 8;
                const messages = [
                    'Checking license acceptance…',
                    'Connecting to database…',
                    'Creating Developer One CMS tables…',
                    'Creating admin account…',
                    'Seeding fresh home page, roles, menus, API endpoint, and settings…',
                    'Writing secure config.php…',
                    'Finalizing installation…'
                ];
                let i = 0;
                const timer = setInterval(() => {
                    pct = Math.min(92, pct + Math.floor(Math.random() * 12) + 4);
                    bar.style.width = pct + '%';
                    log.textContent = messages[Math.min(i, messages.length - 1)];
                    i++;
                }, 520);

                fetch('?step=6&run=1', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: new URLSearchParams({csrf_token: '<?= e(csrf_token()) ?>'})
                }).then(r => r.json()).then(data => {
                    clearInterval(timer);
                    if (data.ok) {
                        bar.style.width = '100%';
                        log.textContent = data.message || 'Developer One CMS installed successfully.';
                        setTimeout(() => { window.location.href = data.redirect || 'install.php?step=7&installed=1'; }, 900);
                    } else {
                        bar.style.width = '100%';
                        log.textContent = 'Installation stopped: ' + (data.message || 'Unknown error.');
                        actions.style.display = 'flex';
                    }
                }).catch(err => {
                    clearInterval(timer);
                    bar.style.width = '100%';
                    log.textContent = 'Installation stopped: ' + err.message;
                    actions.style.display = 'flex';
                });
            })();
            </script>
        <?php else: ?>
            <?php $installError = $_SESSION['devone_install_error'] ?? ''; unset($_SESSION['devone_install_error']); ?>
            <section class="card center">
                <?php if ($installError): ?>
                    <p class="eyebrow">Install Error</p>
                    <h2>Installation Needs Attention</h2>
                    <div class="alert"><?= e((string)$installError) ?></div>
                    <div class="btns"><a class="btn secondary" href="?step=4">Back to Setup</a><a class="btn" href="?step=6">Try Again</a></div>
                <?php else: ?>
                    <?php
                        $finalSiteUrl = (string)($_SESSION['devone_install_site_url'] ?? ($configSiteUrl ?: 'index.php'));
                        $finalAdminUrl = (string)($_SESSION['devone_install_admin_url'] ?? ($configAdminUrl ?: 'admin/'));
                        $alreadyInstalled = !empty($_GET['installed']) || $configInstalled;
                    ?>
                    <?php if ($logoPath): ?><img class="complete-logo" src="<?= e($logoPath) ?>" alt="Developer One CMS Logo"><?php endif; ?>
                    <p class="eyebrow"><?= $alreadyInstalled ? 'Installed' : 'Complete' ?></p>
                    <h2><span class="grad">Developer One CMS</span> is Ready</h2>
                    <p class="lead">Installation is complete. Your database, admin account, fresh default home page, core records, theme settings, EULA acceptance, and license acceptance records have been created.</p>
                    <div class="notice">Your site is installed. Visit the homepage or log in to the Admin Dashboard. For security, delete or rename <strong>install.php</strong> after verifying your site and admin dashboard.</div>
                    <?php if (!empty($_SESSION['devone_install_mail_result'])): ?><div class="notice" style="margin-top:12px"><?= e((string)$_SESSION['devone_install_mail_result']) ?></div><?php endif; ?>
                    <div class="mini-summary" style="margin-top:18px;text-align:left">
                        <div><small>Install Status</small><strong>Installed</strong></div>
                        <div><small>Frontend</small><strong><?= e($finalSiteUrl) ?></strong></div>
                        <div><small>Admin</small><strong><?= e($finalAdminUrl) ?></strong></div>
                    </div>
                    <div class="btns">
                        <a class="btn" href="<?= e($finalSiteUrl) ?>">View Site</a>
                        <a class="btn secondary" href="<?= e($finalAdminUrl) ?>">Admin Dashboard</a>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<script>
(function(){
    function gate(boxId, btnId){
        const box = document.getElementById(boxId);
        const btn = document.getElementById(btnId);
        if(!box || !btn) return;
        box.addEventListener('change', function(){ btn.disabled = !box.checked; });
    }
    gate('acceptEula','eulaNext');
    gate('acceptLicense','licenseNext');

    document.querySelectorAll('.theme-card input[type="radio"]').forEach(function(radio){
        radio.addEventListener('change', function(){
            const select = document.getElementById('theme');
            if (select) select.value = this.value;
        });
    });
    const themeSelect = document.getElementById('theme');
    if (themeSelect) {
        themeSelect.addEventListener('change', function(){
            document.querySelectorAll('.theme-card input[type="radio"]').forEach(function(input){
                input.checked = input.value === themeSelect.value;
            });
        });
    }
})();
</script>
</body>
</html>
