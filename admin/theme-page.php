<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();

$theme = function_exists('devone_theme_runtime_clean_slug') ? devone_theme_runtime_clean_slug($_GET['theme'] ?? '') : preg_replace('/[^a-z0-9\-_]+/', '', strtolower($_GET['theme'] ?? ''));
$page = function_exists('devone_theme_runtime_clean_slug') ? devone_theme_runtime_clean_slug($_GET['page'] ?? '') : preg_replace('/[^a-z0-9\-_]+/', '', strtolower($_GET['page'] ?? ''));

if (function_exists('devone_load_active_theme')) { devone_load_active_theme(); }
$activeTheme = function_exists('devone_active_theme_folder') ? devone_theme_runtime_clean_slug(devone_active_theme_folder()) : '';
$registered = function_exists('devone_get_theme_admin_page') ? devone_get_theme_admin_page($theme, $page) : null;

if (!$registered || $theme !== $activeTheme) {
    devone_admin_header('Theme Page Not Found - DevOneCMS');
    echo '<h1>Theme page not found</h1><p class="card error-card">This theme page is not registered, the theme is inactive, or the page only belongs to another active theme.</p>';
    devone_admin_footer();
    exit;
}

$permission = $registered['permission'] ?? 'manage_themes';
if ($permission !== '') { devone_require_permission($permission); }

$file = $registered['file'] ?? '';
$base = $registered['base'] ?? '';
$real = realpath($file);
$baseReal = realpath($base);
$realNorm = function_exists('devone_theme_runtime_path_norm') ? devone_theme_runtime_path_norm($real ?: '') : str_replace('\\', '/', (string)$real);
$baseNorm = function_exists('devone_theme_runtime_path_norm') ? devone_theme_runtime_path_norm($baseReal ?: '') : str_replace('\\', '/', (string)$baseReal);
$insideThemeBase = ($realNorm !== '' && $baseNorm !== '' && ($realNorm === $baseNorm || strpos($realNorm, $baseNorm . '/') === 0));

if (!$real || !is_file($real) || !$insideThemeBase) {
    devone_admin_header('Theme Page Error - DevOneCMS');
    echo '<h1>Theme page error</h1><p class="card error-card">The registered theme page file could not be loaded safely.</p>';
    if (function_exists('devone_has_permission') && devone_has_permission('admin_all')) {
        echo '<div class="card"><strong>Debug:</strong><br>Theme: ' . e($theme) . '<br>Page: ' . e($page) . '<br>File: ' . e($file) . '<br>Resolved file: ' . e((string)$real) . '<br>Base: ' . e((string)$base) . '<br>Resolved base: ' . e((string)$baseReal) . '</div>';
    }
    devone_admin_footer();
    exit;
}

define('DEVONE_THEME_ADMIN_ROUTER', true);
define('DEVONE_THEME_ADMIN_THEME', $theme);
define('DEVONE_THEME_ADMIN_PAGE', $page);
$GLOBALS['devone_theme_admin_current'] = $registered;

devone_admin_header(($registered['title'] ?? 'Theme Page') . ' - DevOneCMS');
include $real;
devone_admin_footer();
