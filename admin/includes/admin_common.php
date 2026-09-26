<?php
if (!is_file(__DIR__ . '/../../config.php')) { header('Location: ../install.php'); exit; }
require_once __DIR__ . '/../../config.php';
if (is_file(__DIR__ . '/../../core/version.php')) { require_once __DIR__ . '/../../core/version.php'; }
if (is_file(__DIR__ . '/../../core/core-updates.php')) { require_once __DIR__ . '/../../core/core-updates.php'; }
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/hooks.php';
require_once __DIR__ . '/../../core/schema.php';
require_once __DIR__ . '/../../core/functions.php';
if (is_file(__DIR__ . '/../../core/runtime.php')) { require_once __DIR__ . '/../../core/runtime.php'; }
if (is_file(__DIR__ . '/../../core/license.php')) { require_once __DIR__ . '/../../core/license.php'; }
if (is_file(__DIR__ . '/../../core/network.php')) { require_once __DIR__ . '/../../core/network.php'; }
require_once __DIR__ . '/../../core/security.php';
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
if (is_file(__DIR__ . '/../../core/devone_store.php')) {
    require_once __DIR__ . '/../../core/devone_store.php';
}
if (is_file(__DIR__ . '/../../core/plugins.php')) {
    require_once __DIR__ . '/../../core/plugins.php';
}
if (is_file(__DIR__ . '/../../core/apps.php')) { require_once __DIR__ . '/../../core/apps.php'; }
if (is_file(__DIR__ . '/../../core/themes_runtime.php')) {
    require_once __DIR__ . '/../../core/themes_runtime.php';
}
if (is_file(__DIR__ . '/../../core/mailer.php')) {
    require_once __DIR__ . '/../../core/mailer.php';
}
// Automatic schema repair removed from normal request paths.
devone_admin_required();
if (function_exists('devone_start_session')) { devone_start_session(); }
if (function_exists('devone_load_admin_plugins')) { devone_load_admin_plugins(); }
elseif (function_exists('devone_load_active_plugins')) { devone_load_active_plugins(); }
if (function_exists('devone_load_active_theme')) { devone_load_active_theme(); }

function devone_admin_page_slug() {
    $file = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
    $slug = preg_replace('/\.php$/', '', $file);
    $slug = preg_replace('/[^a-z0-9\-_]+/i', '-', $slug);
    return strtolower(trim($slug, '-')) ?: 'dashboard';
}

function devone_admin_nav_icon($href = '', $label = '') {
    $key = strtolower(trim((string)$href));
    $base = basename(parse_url($key, PHP_URL_PATH) ?: $key);
    $labelKey = strtolower(trim((string)$label));
    $map = array(
        'dashboard.php' => '⌂',
        'upgrade.php' => '⬆',
        'core-updates.php' => '⬆',
        'sites.php' => '🌐',
        'domain.php' => '🔗',
        'pages.php' => '▤',
        'media.php' => '◉',
        'themes.php' => '◒',
        'plugins.php' => '⚙',
        'libraries.php' => '▦',
        'store.php' => '◈',
        'github-importer.php' => '⌁',
        'modules.php' => '▧',
        'apps.php' => 'APP',
        'menus.php' => '☰',
        'users.php' => '👤',
        'settings.php' => '⚙',
        'front-scripts.php' => '</>',
        'email.php' => '✉',
        'api-builder.php' => 'API',
        'logs.php' => '☷',
        'table-debug.php' => '▥',
        'backups.php' => '↺',
        'performance.php' => '⚡',
        'logout.php' => '⎋',
        'index.php' => '↗',
        'plugin-page.php' => '⊞',
        'register.php' => '＋',
        'marketplace.php' => '◇',
        'store-submissions.php' => '✉',
    );
    if (isset($map[$base])) { return $map[$base]; }
    if (strpos($labelKey, 'developer') !== false) { return '🛠'; }
    if (strpos($labelKey, 'commerce') !== false) { return '🛒'; }
    if (strpos($labelKey, 'profile') !== false) { return '👤'; }
    if (strpos($labelKey, 'view site') !== false) { return '↗'; }
    if (strpos($labelKey, 'logout') !== false) { return '⎋'; }
    return '•';
}

function devone_admin_nav_description($href = '', $label = '') {
    $base = strtolower(basename(parse_url((string)$href, PHP_URL_PATH) ?: (string)$href));
    $labelKey = strtolower(trim((string)$label));
    $map = array(
        'dashboard.php' => 'System overview',
        'core-updates.php' => 'Updates & releases',
        'sites.php' => 'Network management',
        'pages.php' => 'Build & manage content',
        'media.php' => 'Images, video & files',
        'apps.php' => 'Installed applications',
        'themes.php' => 'Control your design',
        'plugins.php' => 'Extend DevOne',
        'libraries.php' => 'Shared code libraries',
        'modules.php' => 'Reusable modules',
        'store.php' => 'Themes, plugins & more',
        'github-importer.php' => 'Import from repositories',
        'front-scripts.php' => 'Front-end code injection',
        'email.php' => 'Mail delivery settings',
        'api-builder.php' => 'Endpoints & integrations',
        'asset-api.php' => 'Asset delivery tools',
        'backups.php' => 'Protect your installation',
        'logs.php' => 'System activity & events',
        'performance.php' => 'Speed & diagnostics',
        'domain.php' => 'Domain configuration',
        'menus.php' => 'Navigation management',
        'users.php' => 'Accounts & permissions',
        'settings.php' => 'Configure DevOne',
        'logout.php' => 'End this session',
        'index.php' => 'Open the live website',
        'plugin-page.php' => 'Plugin workspace',
    );
    if (isset($map[$base])) { return $map[$base]; }
    if (strpos($labelKey, 'commerce') !== false) { return 'Products, orders & payments'; }
    if (strpos($labelKey, 'guardian') !== false) { return 'Security & monitoring'; }
    if (strpos($labelKey, 'cloud') !== false) { return 'Hosting & sites'; }
    if (strpos($labelKey, 'visual') !== false) { return 'Visual design workspace'; }
    if (strpos($labelKey, 'profile') !== false) { return 'Account preferences'; }
    if (strpos($labelKey, 'developer') !== false) { return 'Developer workspace'; }
    return 'DevOne workspace';
}

function devone_admin_nav_label_html($href, $label, $extraClass = '') {
    $icon = devone_admin_nav_icon($href, $label);
    $description = devone_admin_nav_description($href, $label);
    $iconClass = 'admin-nav-icon';
    if ($icon === '</>' || $icon === 'API') { $iconClass .= ' admin-nav-icon-code'; }
    $wrapClass = trim('admin-nav-label ' . $extraClass);
    return '<span class="' . e($wrapClass) . '"><span class="' . e($iconClass) . '" aria-hidden="true">' . e($icon) . '</span><span class="admin-nav-copy"><span class="admin-nav-text">' . e($label) . '</span><span class="admin-nav-meta">' . e($description) . '</span></span></span>';
}

function devone_admin_nav_link($href, $label, $permission = '') {
    if ($permission !== '' && function_exists('devone_has_permission') && !devone_has_permission($permission)) { return; }
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $active = $current === basename(parse_url((string)$href, PHP_URL_PATH) ?: $href) ? ' class="active"' : '';
    echo '<a' . $active . ' href="' . e($href) . '" data-admin-close="1">' . devone_admin_nav_label_html($href, $label) . '</a>';
}


function devone_admin_nav_group($label, $links, $fallbackPermission = '') {
    $visible = array();
    foreach ((array)$links as $link) {
        $href = (string)($link['href'] ?? '');
        $text = (string)($link['label'] ?? '');
        $permission = (string)($link['permission'] ?? $fallbackPermission);
        if ($href === '' || $text === '') { continue; }
        if ($permission !== '' && function_exists('devone_has_permission') && !devone_has_permission($permission)) { continue; }
        $visible[] = array('href' => $href, 'label' => $text, 'permission' => $permission);
    }
    if (!$visible) { return; }

    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $groupActive = false;
    foreach ($visible as $link) {
        if ($current === basename($link['href'])) { $groupActive = true; break; }
    }

    $firstHref = $visible[0]['href'];
    $groupClasses = 'admin-nav-group' . ($groupActive ? ' active open' : '');
    $groupId = 'devone-nav-group-' . substr(sha1($label . '|' . $firstHref), 0, 12);
    echo '<div class="' . e($groupClasses) . '" data-admin-nav-group="' . e($groupId) . '">';
    echo '<div class="admin-nav-parent-row">';
    echo '<a class="admin-nav-parent' . ($groupActive ? ' active' : '') . '" href="' . e($firstHref) . '" data-admin-close="1">';
    echo devone_admin_nav_label_html($firstHref, $label) . '</a>';
    echo '<button type="button" class="admin-nav-toggle" data-admin-parent="1" aria-expanded="' . ($groupActive ? 'true' : 'false') . '" aria-controls="' . e($groupId) . '-submenu" aria-label="Toggle ' . e($label) . ' submenu"><span class="admin-nav-plus" aria-hidden="true"></span></button>';
    echo '</div>';
    echo '<div class="admin-nav-submenu" id="' . e($groupId) . '-submenu" role="menu">';
    foreach ($visible as $link) {
        $active = $current === basename($link['href']) ? ' active' : '';
        echo '<a class="admin-nav-subitem' . e($active) . '" href="' . e($link['href']) . '" data-admin-close="1" role="menuitem">' . e($link['label']) . '</a>';
    }
    echo '</div></div>';
}


function devone_admin_plugin_label_from_slug($slug) {
    $slug = trim((string)$slug);
    if ($slug === '') { return 'Plugin'; }
    $slug = preg_replace('/^devone[\-_]?/i', '', $slug);
    $slug = str_replace(array('-', '_'), ' ', $slug);
    return trim(ucwords($slug)) ?: 'Plugin';
}

function devone_admin_plugin_child_label($page, $parentLabel) {
    $slug = strtolower((string)($page['slug'] ?? ''));
    $label = trim((string)($page['menu_title'] ?? $page['title'] ?? $slug));
    if ($label === '') { $label = devone_admin_plugin_label_from_slug($slug); }

    if (strcasecmp($label, $parentLabel) === 0) {
        if ($slug === 'dashboard') { return 'Dashboard'; }
        if ($slug === 'products') { return 'Products'; }
        if ($slug === 'settings') { return 'Settings'; }
        $known = array('dashboard'=>'Dashboard','sites'=>'My Sites','plans'=>'Plans','billing'=>'Billing','domains'=>'Domains','usage'=>'Usage','activity'=>'Activity','updates'=>'Platform Updates','account'=>'Account','settings'=>'Settings','backups'=>'Backups','health'=>'Site Health','support'=>'Support');
        if (isset($known[$slug])) { return $known[$slug]; }
        if ($slug === 'orders') { return 'Orders'; }
        return devone_admin_plugin_label_from_slug($slug);
    }

    if ($parentLabel !== '' && stripos($label, $parentLabel . ' ') === 0) {
        $label = trim(substr($label, strlen($parentLabel)));
    }
    return $label !== '' ? $label : 'Overview';
}

function devone_admin_nav_plugin_groups($onlyPlugins = array(), $excludePlugins = array()) {
    if (!function_exists('devone_plugin_admin_pages') || !function_exists('devone_plugin_admin_url')) { return; }
    $pages = devone_plugin_admin_pages();
    if (!$pages) { return; }

    $onlyPlugins = array_values(array_filter(array_map('strtolower', (array)$onlyPlugins)));
    $excludePlugins = array_values(array_filter(array_map('strtolower', (array)$excludePlugins)));

    $groups = array();
    foreach ($pages as $page) {
        $permission = (string)($page['permission'] ?? 'manage_plugins');
        if ($permission !== '' && function_exists('devone_has_permission') && !devone_has_permission($permission)) { continue; }
        $plugin = preg_replace('/[^a-z0-9\-_]+/i', '-', (string)($page['plugin'] ?? ''));
        $plugin = strtolower(trim($plugin, '-'));
        if ($plugin === '') { continue; }
        if ($onlyPlugins && !in_array($plugin, $onlyPlugins, true)) { continue; }
        if ($excludePlugins && in_array($plugin, $excludePlugins, true)) { continue; }
        if (!isset($groups[$plugin])) { $groups[$plugin] = array(); }
        $groups[$plugin][] = $page;
    }

    if (!$groups) { return; }

    $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $currentPlugin = strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', (string)($_GET['plugin'] ?? '')), '-'));
    $currentPage = strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', (string)($_GET['page'] ?? '')), '-'));

    foreach ($groups as $plugin => $pluginPages) {
        $first = $pluginPages[0];
        $parentLabel = trim((string)($first['menu_title'] ?? ''));
        if ($parentLabel === '') { $parentLabel = devone_admin_plugin_label_from_slug($plugin); }
        $firstUrl = devone_plugin_admin_url($plugin, (string)($first['slug'] ?? ''));
        $groupActive = ($currentScript === 'plugin-page.php' && $currentPlugin === $plugin);
        $groupClasses = 'admin-nav-group admin-nav-plugin-group' . ($groupActive ? ' active open' : '');

        $groupId = 'devone-nav-plugin-' . substr(sha1($plugin), 0, 12);
        echo '<div class="' . e($groupClasses) . '" data-admin-nav-group="' . e($groupId) . '">';
        echo '<div class="admin-nav-parent-row">';
        echo '<a class="admin-nav-parent' . ($groupActive ? ' active' : '') . '" href="' . e($firstUrl) . '" data-admin-close="1">';
        echo devone_admin_nav_label_html($firstUrl, $parentLabel) . '</a>';
        echo '<button type="button" class="admin-nav-toggle" data-admin-parent="1" aria-expanded="' . ($groupActive ? 'true' : 'false') . '" aria-controls="' . e($groupId) . '-submenu" aria-label="Toggle ' . e($parentLabel) . ' submenu"><span class="admin-nav-plus" aria-hidden="true"></span></button>';
        echo '</div>';
        echo '<div class="admin-nav-submenu" id="' . e($groupId) . '-submenu" role="menu">';
        foreach ($pluginPages as $page) {
            $slug = (string)($page['slug'] ?? '');
            if ($slug === '') { continue; }
            $childLabel = devone_admin_plugin_child_label($page, $parentLabel);
            $safeSlug = strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', $slug), '-'));
            $active = ($groupActive && $currentPage === $safeSlug) ? ' active' : '';
            echo '<a class="admin-nav-subitem' . e($active) . '" href="' . e(devone_plugin_admin_url($plugin, $slug)) . '" data-admin-close="1" role="menuitem">' . e($childLabel) . '</a>';
        }
        echo '</div></div>';
    }
}


function devone_admin_sites_bar() {
    if (!function_exists('devone_feature_enabled') || !devone_feature_enabled('multisite')) { return; }
    if (!function_exists('devone_network_enabled') || !devone_network_tables_exist()) { return; }
    if (!(function_exists('devone_network_is_super_admin') && devone_network_is_super_admin())) { return; }
    $enabled = devone_network_enabled();
    $currentUserId = function_exists('devone_current_user_id') ? devone_current_user_id() : 0;
    $sites = function_exists('devone_user_accessible_sites') ? devone_user_accessible_sites($currentUserId) : array();
    if (!$enabled) { return; }
    if (!$sites && $enabled) { return; }
    $currentSite = function_exists('devone_network_admin_site_context') ? devone_network_admin_site_context() : null;
    $currentName = $currentSite['site_name'] ?? 'Main Site';
    $back = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
    if (!empty($_SERVER['QUERY_STRING'])) { $back .= '?' . preg_replace('/[^a-zA-Z0-9_\-.?=&]/', '', $_SERVER['QUERY_STRING']); }
    echo '<div class="devone-sites-topbar">';
    echo '<div class="devone-sites-current"><span>Sites</span><strong>' . e($currentName) . '</strong></div>';
    if ($enabled && $sites) {
        echo '<form method="post" action="switch-site.php" class="devone-sites-switcher"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
        echo '<input type="hidden" name="back" value="' . e($back) . '">';
        echo '<select name="site_id" onchange="this.form.submit()">';
        foreach ($sites as $site) {
            $selected = ((int)($site['id'] ?? 0) === (int)($currentSite['id'] ?? 0)) ? ' selected' : '';
            echo '<option value="' . e($site['id']) . '"' . $selected . '>' . e($site['site_name']) . ' — ' . e($site['primary_domain'] ?: $site['auto_subdomain']) . '</option>';
        }
        echo '</select></form>';
    }
    echo '<div class="devone-sites-actions">';
    if (function_exists('devone_network_is_super_admin') && devone_network_is_super_admin()) { echo '<a class="btn secondary" href="sites.php">Manage Sites</a>'; }
    if ($enabled) { echo '<a class="btn secondary" href="domain.php">Site Domain</a>'; }
    echo '</div></div>';
}


function devone_admin_theme_mode() {
    $mode = function_exists('get_setting') ? strtolower(trim((string)get_setting('admin_theme_mode', 'dark'))) : 'dark';
    return in_array($mode, array('dark','light'), true) ? $mode : 'dark';
}

function devone_admin_official_logo_url() {
    $local = __DIR__ . '/../../assets/img/developer-one-cms-logo.png';
    if (is_file($local)) { return '../assets/img/developer-one-cms-logo.png'; }
    return '';
}

function devone_admin_header($title = 'DevOneCMS Admin') {
    $adminSiteName = function_exists('get_setting') ? get_setting('site_name', 'DevOneCMS') : 'DevOneCMS';
    // The admin sidebar should follow the site's configured branding first.
    // Fall back to the bundled Developer One mark only when no Site Logo is configured.
    $adminLogo = function_exists('devone_site_logo_url') ? devone_site_logo_url() : '';
    if ($adminLogo === '' && function_exists('devone_admin_official_logo_url')) { $adminLogo = devone_admin_official_logo_url(); }
    $adminThemeMode = function_exists('devone_admin_theme_mode') ? devone_admin_theme_mode() : 'dark';
    $pageSlug = devone_admin_page_slug();
    $currentUser = function_exists('devone_current_user') ? devone_current_user() : null;
    $currentName = function_exists('devone_current_user_display_name') ? devone_current_user_display_name() : ($currentUser['username'] ?? 'Developer');
    $currentAvatar = ($currentUser && function_exists('devone_user_avatar_url')) ? devone_user_avatar_url($currentUser) : '';
    $currentInitial = strtoupper(substr(trim((string)$currentName) ?: 'U', 0, 1));
    ?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="devone-csrf" content="<?= e(function_exists('csrf_token') ? csrf_token() : '') ?>">
  <?php
    $devoneFrontendCssVersion = is_file(__DIR__ . '/../../assets/css/devone.css') ? (string)@filemtime(__DIR__ . '/../../assets/css/devone.css') : '1.0.13';
    $devoneAdminCssVersion = is_file(__DIR__ . '/../../assets/css/devone-admin.css') ? (string)@filemtime(__DIR__ . '/../../assets/css/devone-admin.css') : '1.0.13';
  ?>
  <link rel="stylesheet" href="../assets/css/devone.css?v=<?= e($devoneFrontendCssVersion) ?>">
  <link rel="stylesheet" href="../assets/css/devone-admin.css?v=<?= e($devoneAdminCssVersion) ?>">
  <link rel="stylesheet" href="../assets/css/devone-assets.css?v=<?= e(is_file(__DIR__ . '/../../assets/css/devone-assets.css') ? @filemtime(__DIR__ . '/../../assets/css/devone-assets.css') : '1.2.8') ?>">
  <?php
    if (!function_exists('devone_enqueue_libraries')) { @require_once __DIR__ . '/../../core/assets.php'; }
    if (function_exists('devone_enqueue_libraries')) { devone_enqueue_libraries('admin', 'head'); }
  ?>
</head>
<body class="devone-admin devone-admin-theme-marketplace devone-admin-theme-<?= e($adminThemeMode) ?> devone-admin-page-<?= e($pageSlug) ?>">
<div class="admin-mobile-bar">
  <button type="button" class="admin-menu-toggle" id="devoneAdminMenuToggle" aria-controls="devoneAdminSidebar" aria-expanded="false">☰</button>
  <strong><?= e($adminSiteName) ?></strong>
  <a href="../index.php" target="_blank" rel="noopener">View Site</a>
</div>
<div class="admin-sidebar-overlay" id="devoneAdminOverlay"></div>
<div class="admin-wrap">
  <aside class="sidebar" id="devoneAdminSidebar">
    <h2 class="admin-brand admin-brand-official">
      <?php if ($adminLogo): ?>
        <img class="admin-brand-logo admin-brand-logo-official" src="<?= e($adminLogo) ?>" alt="<?= e($adminSiteName) ?> logo">
      <?php else: ?>
        <span class="logo">&lt;/&gt;</span>
      <?php endif; ?>
      <span><?= e($adminSiteName) ?></span>
    </h2>
    <div class="devone-admin-sidebar-search">
      <span class="devone-admin-sidebar-search-icon" aria-hidden="true">⌕</span>
      <input type="search" id="devoneAdminNavSearch" placeholder="Search DevOne..." autocomplete="off" aria-label="Search admin navigation">
      <kbd>/</kbd>
    </div>
    <nav class="admin-nav" id="devoneAdminNav">
      <span class="devone-admin-nav-section">Workspace</span>
      <?php devone_admin_nav_link('dashboard.php', 'Dashboard', 'manage_dashboard'); ?>
      <?php if (function_exists('devone_network_is_super_admin') && devone_network_is_super_admin()) { devone_admin_nav_link('core-updates.php', 'Core Updates', 'manage_settings'); } ?>
      <?php
      $devonePriorityPlugins = array('one-cloud', 'one-guardian', 'devone-guardian', 'devone-guardian-core', 'devone-guardian-network');
      devone_admin_nav_plugin_groups($devonePriorityPlugins);
      ?>
      <?php if (function_exists('devone_network_is_super_admin') && devone_network_is_super_admin() && function_exists('devone_feature_enabled') && devone_feature_enabled('multisite')) { devone_admin_nav_link('sites.php', 'Sites', 'admin_all'); } ?>
      <?php devone_admin_nav_link('pages.php', 'Pages', 'edit_pages'); ?>
      <?php devone_admin_nav_link('media.php', 'Media', 'manage_media'); ?>
      <span class="devone-admin-nav-section">Development</span>
      <?php
      devone_admin_nav_group('Packages', array(
          array('href' => 'apps.php', 'label' => 'Apps', 'permission' => 'manage_modules'),
          array('href' => 'themes.php', 'label' => 'Themes', 'permission' => 'manage_themes'),
          array('href' => 'plugins.php', 'label' => 'Plugins', 'permission' => 'manage_plugins'),
          array('href' => 'libraries.php', 'label' => 'Libraries', 'permission' => 'manage_libraries'),
          array('href' => 'modules.php', 'label' => 'Modules', 'permission' => 'manage_modules'),
      ));
      ?>
      <?php if (function_exists('devone_admin_nav_theme_groups')) { devone_admin_nav_theme_groups(); } ?>
      <?php devone_admin_nav_plugin_groups(array(), $devonePriorityPlugins); ?>
      <?php devone_admin_nav_link('store.php', 'One Marketplace', 'manage_store'); ?>
      <?php
      $devoneToolLinks = array();
      $devoneToolLinks[] = array('href' => 'github-importer.php', 'label' => 'GitHub Importer', 'permission' => 'manage_store');
      if (function_exists('devone_network_is_super_admin') && devone_network_is_super_admin()) {
        $devoneToolLinks[] = array('href' => 'front-scripts.php', 'label' => 'Front-End Scripts', 'permission' => 'manage_settings');
        $devoneToolLinks[] = array('href' => 'email.php', 'label' => 'SMTP Settings', 'permission' => 'manage_settings');
        $devoneToolLinks[] = array('href' => 'api-builder.php', 'label' => 'API Builder', 'permission' => 'manage_api');
        $devoneToolLinks[] = array('href' => 'asset-api.php', 'label' => 'Asset API', 'permission' => 'manage_api');
        $devoneToolLinks[] = array('href' => 'backups.php', 'label' => 'Backups', 'permission' => 'manage_backups');
        $devoneToolLinks[] = array('href' => 'logs.php', 'label' => 'Logs', 'permission' => 'view_logs');
        $devoneToolLinks[] = array('href' => 'performance.php', 'label' => 'Performance', 'permission' => 'manage_settings');
      }
      $devoneToolLinks[] = array('href' => 'domain.php', 'label' => 'Site Domain', 'permission' => 'manage_site_domain');
      devone_admin_nav_group('Developer Tools', $devoneToolLinks); ?>
      <span class="devone-admin-nav-section">System</span>
      <?php devone_admin_nav_link('menus.php', 'Menus', 'manage_menus'); ?>
      <?php devone_admin_nav_link('users.php', 'Users & Roles', 'manage_users'); ?>
      <?php devone_admin_nav_link('settings.php', 'Settings', 'manage_settings'); ?>
      <a href="users.php?profile=me" data-admin-close="1"><?= devone_admin_nav_label_html('users.php', 'My Profile') ?></a>
      <a href="../index.php" target="_blank" rel="noopener"><?= devone_admin_nav_label_html('../index.php', 'View Site') ?></a>
      <div class="devone-admin-system-status"><span aria-hidden="true"></span><div><strong>System Online</strong><small>DevOne CMS</small></div></div>
      <div class="admin-user-mini admin-user-mini-bottom">
        <div class="admin-user-mini-avatar">
          <?php if ($currentAvatar): ?><img src="<?= e($currentAvatar) ?>" alt="<?= e($currentName) ?> avatar"><?php else: ?><span><?= e($currentInitial) ?></span><?php endif; ?>
        </div>
        <div class="admin-user-mini-info">
          <span>Logged in as</span>
          <strong><?= e($currentName) ?></strong>
          <small><?= e($currentUser['role'] ?? 'admin') ?></small>
        </div>
      </div>
      <?php devone_admin_nav_link('logout.php', 'Logout'); ?>
    </nav>
  </aside>
  <main class="panel admin-panel">
    <?php if (function_exists('devone_admin_sites_bar')) { devone_admin_sites_bar(); } ?>
    <?php if (function_exists('devone_core_update_alert_html')) { echo devone_core_update_alert_html(); } ?>
    <?php
}

function devone_admin_footer() {
    if (!function_exists('devone_enqueue_libraries')) { @require_once __DIR__ . '/../../core/assets.php'; }
    if (function_exists('devone_enqueue_libraries')) { devone_enqueue_libraries('admin', 'footer'); }
    ?>
  </main>
</div>
<script src="../assets/js/devone-assets.js?v=<?= e(is_file(__DIR__ . '/../../assets/js/devone-assets.js') ? @filemtime(__DIR__ . '/../../assets/js/devone-assets.js') : '1.2.8') ?>"></script>
<script>
(function(){
  var body = document.body;
  var btn = document.getElementById('devoneAdminMenuToggle');
  var overlay = document.getElementById('devoneAdminOverlay');
  var sidebar = document.getElementById('devoneAdminSidebar');
  function openMenu(){ body.classList.add('admin-menu-open'); if(btn){ btn.setAttribute('aria-expanded','true'); } }
  function closeMenu(){ body.classList.remove('admin-menu-open'); if(btn){ btn.setAttribute('aria-expanded','false'); } }
  if(btn){ btn.addEventListener('click', function(){ body.classList.contains('admin-menu-open') ? closeMenu() : openMenu(); }); }
  if(overlay){ overlay.addEventListener('click', closeMenu); }
  if(sidebar){
    var navSearch = document.getElementById('devoneAdminNavSearch');
    var nav = document.getElementById('devoneAdminNav');
    if(navSearch && nav){
      function filterAdminNav(){
        var term = String(navSearch.value || '').trim().toLowerCase();
        nav.querySelectorAll(':scope > a, :scope > .admin-nav-group').forEach(function(item){
          var haystack = String(item.textContent || '').toLowerCase();
          var visible = !term || haystack.indexOf(term) !== -1;
          item.classList.toggle('devone-nav-search-hidden', !visible);
          if(term && visible && item.classList.contains('admin-nav-group')){
            item.classList.add('open');
            var searchToggle = item.querySelector(':scope > .admin-nav-parent-row > [data-admin-parent]');
            if(searchToggle) searchToggle.setAttribute('aria-expanded', 'true');
          }
        });
        nav.querySelectorAll('.devone-admin-nav-section').forEach(function(section){
          if(!term){ section.classList.remove('devone-nav-search-hidden'); return; }
          var next = section.nextElementSibling;
          var anyVisible = false;
          while(next && !next.classList.contains('devone-admin-nav-section')){
            if((next.matches('a') || next.classList.contains('admin-nav-group')) && !next.classList.contains('devone-nav-search-hidden')){ anyVisible = true; break; }
            next = next.nextElementSibling;
          }
          section.classList.toggle('devone-nav-search-hidden', !anyVisible);
        });
      }
      navSearch.addEventListener('input', filterAdminNav);
      document.addEventListener('keydown', function(e){
        if(e.key === '/' && !/^(input|textarea|select)$/i.test((e.target && e.target.tagName) || '')){
          e.preventDefault(); navSearch.focus(); navSearch.select();
        }
      });
    }
    var storageKey = 'devoneAdminOpenNavGroups';
    var savedGroups = [];
    try { savedGroups = JSON.parse(window.localStorage.getItem(storageKey) || '[]'); } catch(err) { savedGroups = []; }
    if(!Array.isArray(savedGroups)){ savedGroups = []; }

    function saveOpenGroups(){
      var openGroups = [];
      sidebar.querySelectorAll('.admin-nav-group.open[data-admin-nav-group]').forEach(function(group){
        if(!group.classList.contains('active')) openGroups.push(group.getAttribute('data-admin-nav-group'));
      });
      try { window.localStorage.setItem(storageKey, JSON.stringify(openGroups)); } catch(err) {}
    }

    sidebar.querySelectorAll('.admin-nav-group[data-admin-nav-group]').forEach(function(group){
      var id = group.getAttribute('data-admin-nav-group');
      var toggle = group.querySelector(':scope > .admin-nav-parent-row > [data-admin-parent]');
      if(group.classList.contains('active') || savedGroups.indexOf(id) !== -1){
        group.classList.add('open');
        if(toggle) toggle.setAttribute('aria-expanded', 'true');
      } else if(toggle){
        toggle.setAttribute('aria-expanded', 'false');
      }
    });

    sidebar.addEventListener('click', function(e){
      var toggle = e.target.closest('[data-admin-parent]');
      if(toggle){
        var group = toggle.closest('.admin-nav-group');
        if(group && group.querySelector('.admin-nav-submenu')){
          e.preventDefault();
          var isOpen = group.classList.toggle('open');
          toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          saveOpenGroups();
          return;
        }
      }
      if(window.innerWidth <= 980 && e.target.closest('[data-admin-close]')) closeMenu();
    });
  }
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeMenu(); });
})();
</script>
</body></html>
    <?php
}

function devone_flash($message, $class = 'card') {
    if ($message !== '') { echo '<p class="' . e($class) . '">' . e($message) . '</p>'; }
}

function devone_slug($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value);
    return trim($value, '-') ?: 'item';
}

if (!function_exists('devone_safe_name')) {
    function devone_safe_name($value) {
        return preg_replace('/[^a-zA-Z0-9._-]/', '-', basename((string)$value));
    }
}

function devone_table_rows($table, $order = 'id DESC') {
    if (!table_exists($table)) { return []; }
    $order = preg_replace('/[^a-zA-Z0-9_ ,.-]/', '', $order);
    return db()->query('SELECT * FROM ' . table_name($table) . ' ORDER BY ' . $order)->fetchAll();
}
