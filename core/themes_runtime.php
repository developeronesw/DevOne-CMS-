<?php
/**
 * DevOneCMS Theme Runtime
 *
 * Loads active-theme PHP only for the currently active theme and lets themes
 * register admin pages that are available only while that theme is active.
 */

$GLOBALS['devone_loaded_theme_runtime'] = $GLOBALS['devone_loaded_theme_runtime'] ?? '';
$GLOBALS['devone_theme_admin_pages'] = $GLOBALS['devone_theme_admin_pages'] ?? array();

if (!function_exists('devone_theme_runtime_clean_slug')) {
    function devone_theme_runtime_clean_slug($value) {
        if (function_exists('devone_slugify')) { return devone_slugify($value, ''); }
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value);
        return trim($value, '-');
    }
}

if (!function_exists('devone_theme_runtime_path_norm')) {
    function devone_theme_runtime_path_norm($path) {
        $path = str_replace('\\', '/', (string)$path);
        $path = preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); }
        return $path;
    }
}

function devone_active_theme_folder() {
    return function_exists('devone_theme') ? devone_theme() : (function_exists('get_setting') ? (string)get_setting('site_theme', 'devone-dark') : 'devone-dark');
}

function devone_active_theme_root() {
    $theme = devone_theme_runtime_clean_slug(devone_active_theme_folder());
    if ($theme === '') { return ''; }
    if (function_exists('devone_find_theme_dir')) { return devone_find_theme_dir($theme); }
    $root = dirname(__DIR__);
    $candidate = $root . '/content/themes/' . $theme;
    return (is_dir($candidate) && is_file($candidate . '/theme.css')) ? $candidate : '';
}

function devone_theme_url($path = '', $theme = '') {
    $theme = devone_theme_runtime_clean_slug($theme ?: devone_active_theme_folder());
    $path = ltrim((string)$path, '/');
    if ($theme === '') { return function_exists('devone_site_url') ? devone_site_url($path) : $path; }
    $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
    $private = function_exists('devone_site_private_theme_dir') ? devone_site_private_theme_dir($siteId) . '/' . $theme : '';
    if ($private && is_dir($private)) { return devone_site_url('content/sites/' . $siteId . '/themes/' . $theme . ($path !== '' ? '/' . $path : '')); }
    return function_exists('devone_site_url') ? devone_site_url('content/themes/' . $theme . ($path !== '' ? '/' . $path : '')) : '../content/themes/' . $theme . ($path !== '' ? '/' . $path : '');
}

function devone_theme_asset_url($path = '') { return devone_theme_url($path); }

function devone_register_theme_admin_page($theme, $slug, $title, $file, $permission = 'manage_themes', $menu_title = '', $parent_title = '') {
    $theme = devone_theme_runtime_clean_slug($theme);
    $slug = devone_theme_runtime_clean_slug($slug);
    if ($theme === '' || $slug === '' || $file === '') { return false; }

    $active = devone_theme_runtime_clean_slug(devone_active_theme_folder());
    if ($theme !== $active) { return false; }

    $real = realpath($file);
    $base = realpath(devone_active_theme_root());
    if (!$real || !$base || !is_file($real)) { return false; }

    $realNorm = devone_theme_runtime_path_norm($real);
    $baseNorm = devone_theme_runtime_path_norm($base);
    if ($realNorm !== $baseNorm && strpos($realNorm, $baseNorm . '/') !== 0) { return false; }

    $GLOBALS['devone_theme_admin_pages'][$theme . ':' . $slug] = array(
        'theme' => $theme,
        'slug' => $slug,
        'title' => (string)$title,
        'menu_title' => $menu_title !== '' ? (string)$menu_title : (string)$title,
        'parent_title' => $parent_title !== '' ? (string)$parent_title : '',
        'file' => $real,
        'permission' => (string)$permission,
        'base' => $base,
    );
    return true;
}

function devone_theme_admin_pages() { return array_values($GLOBALS['devone_theme_admin_pages'] ?? array()); }

function devone_get_theme_admin_page($theme, $slug) {
    $key = devone_theme_runtime_clean_slug($theme) . ':' . devone_theme_runtime_clean_slug($slug);
    return $GLOBALS['devone_theme_admin_pages'][$key] ?? null;
}

function devone_theme_admin_url($theme, $page) {
    return 'theme-page.php?theme=' . rawurlencode(devone_theme_runtime_clean_slug($theme)) . '&page=' . rawurlencode(devone_theme_runtime_clean_slug($page));
}

function devone_load_active_theme($force = false) {
    $theme = devone_theme_runtime_clean_slug(devone_active_theme_folder());
    if ($theme === '') { return; }
    if (!$force && $GLOBALS['devone_loaded_theme_runtime'] === $theme) { return; }
    $root = devone_active_theme_root();
    if ($root === '') { return; }

    $files = array('theme-functions.php', 'functions.php', 'includes/theme-functions.php');
    foreach ($files as $file) {
        $candidate = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $file;
        if (is_file($candidate)) {
            try {
                require_once $candidate;
                $GLOBALS['devone_loaded_theme_runtime'] = $theme;
            }
            catch (Throwable $e) { if (function_exists('devone_log')) { devone_log('theme_load_error', $theme . ': ' . $e->getMessage()); } }
            break;
        }
    }
    if (function_exists('do_action')) { do_action('devone_theme_loaded', $theme, $root); }
}

function devone_admin_theme_child_label($page, $parentLabel) {
    $parentLabel = trim((string)$parentLabel);
    $menuTitle = trim((string)($page['menu_title'] ?? ''));
    $title = trim((string)($page['title'] ?? ''));
    $slug = trim((string)($page['slug'] ?? ''));

    // If menu_title is the same as the parent group label, use the real page title
    // so child items show General, Hero Slider, Sections, Filters, Footer, etc.
    if ($menuTitle !== '' && $parentLabel !== '' && strcasecmp($menuTitle, $parentLabel) !== 0) {
        $label = $menuTitle;
    } elseif ($title !== '') {
        $label = $title;
    } elseif ($menuTitle !== '') {
        $label = $menuTitle;
    } else {
        $label = $slug !== '' ? ucwords(str_replace(array('-', '_'), ' ', $slug)) : 'Options';
    }

    if ($parentLabel !== '') {
        $patterns = array(
            '/^' . preg_quote($parentLabel, '/') . '\s*[—–-]\s*/iu',
            '/^' . preg_quote($parentLabel, '/') . '\s+/iu',
        );
        $label = preg_replace($patterns, '', $label);
    }

    $label = trim((string)$label);
    $label = preg_replace('/^[—–-]\s*/u', '', $label);
    $label = trim((string)$label);
    return $label !== '' ? $label : 'Options';
}

function devone_admin_nav_theme_groups() {
    if (!function_exists('devone_theme_admin_pages') || !function_exists('devone_theme_admin_url')) { return; }
    $pages = devone_theme_admin_pages();
    if (!$pages) { return; }

    $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $currentTheme = devone_theme_runtime_clean_slug($_GET['theme'] ?? '');
    $currentPage = devone_theme_runtime_clean_slug($_GET['page'] ?? '');
    $groups = array();

    foreach ($pages as $page) {
        $permission = (string)($page['permission'] ?? 'manage_themes');
        if ($permission !== '' && function_exists('devone_has_permission') && !devone_has_permission($permission)) { continue; }
        $theme = devone_theme_runtime_clean_slug($page['theme'] ?? '');
        if ($theme === '') { continue; }
        if (!isset($groups[$theme])) { $groups[$theme] = array(); }
        $groups[$theme][] = $page;
    }

    foreach ($groups as $theme => $themePages) {
        $first = $themePages[0];
        $parentLabel = trim((string)($first['parent_title'] ?? '')) ?: (trim((string)($first['menu_title'] ?? '')) ?: 'Theme Options');
        $firstUrl = devone_theme_admin_url($theme, (string)($first['slug'] ?? 'options'));
        $groupActive = ($currentScript === 'theme-page.php' && $currentTheme === $theme);
        $groupClasses = 'admin-nav-group admin-nav-theme-group' . ($groupActive ? ' active open' : '');
        echo '<div class="' . e($groupClasses) . '">';
        echo '<a class="admin-nav-parent' . ($groupActive ? ' active' : '') . '" href="' . e($firstUrl) . '" data-admin-parent="1" aria-haspopup="true" aria-expanded="' . ($groupActive ? 'true' : 'false') . '">';
        echo devone_admin_nav_label_html($firstUrl, $parentLabel) . '<b aria-hidden="true">▾</b></a>';
        echo '<div class="admin-nav-submenu" role="menu">';
        foreach ($themePages as $page) {
            $slug = (string)($page['slug'] ?? '');
            if ($slug === '') { continue; }
            $childLabel = devone_admin_theme_child_label($page, $parentLabel);
            $safeSlug = devone_theme_runtime_clean_slug($slug);
            $active = ($groupActive && $currentPage === $safeSlug) ? ' active' : '';
            echo '<a class="admin-nav-subitem' . e($active) . '" href="' . e(devone_theme_admin_url($theme, $slug)) . '" data-admin-close="1" role="menuitem">' . e($childLabel) . '</a>';
        }
        echo '</div></div>';
    }
}
