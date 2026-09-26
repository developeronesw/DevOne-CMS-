<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();

$plugin = function_exists('devone_plugin_clean_slug') ? devone_plugin_clean_slug($_GET['plugin'] ?? '') : preg_replace('/[^a-z0-9\-_]+/', '', strtolower($_GET['plugin'] ?? ''));
$page = function_exists('devone_plugin_clean_slug') ? devone_plugin_clean_slug($_GET['page'] ?? '') : preg_replace('/[^a-z0-9\-_]+/', '', strtolower($_GET['page'] ?? ''));

// Admin Common has prepared the registry. Load only the requested plugin if it has not already loaded.
if (function_exists('devone_plugin_load_one') && $plugin !== '') { devone_plugin_load_one($plugin); }

$registered = function_exists('devone_get_plugin_admin_page') ? devone_get_plugin_admin_page($plugin, $page) : null;

if (!$registered) {
    devone_admin_header('Plugin Page Not Found - DevOneCMS');
    echo '<h1>Plugin page not found</h1><p class="card error-card">This plugin page is not registered or the plugin is inactive.</p>';
    devone_admin_footer();
    exit;
}

$permission = $registered['permission'] ?? 'manage_plugins';
if ($permission !== '') { devone_require_permission($permission); }

$file = $registered['file'] ?? '';
$base = $registered['base'] ?? '';
$real = realpath($file);
$baseReal = realpath($base);

if (!$baseReal && function_exists('devone_plugin_resolve_root_from_file') && $real) {
    $baseReal = devone_plugin_resolve_root_from_file($real);
}

$realNorm = function_exists('devone_plugin_path_norm') ? devone_plugin_path_norm($real ?: '') : str_replace('\\', '/', (string)$real);
$baseNorm = function_exists('devone_plugin_path_norm') ? devone_plugin_path_norm($baseReal ?: '') : str_replace('\\', '/', (string)$baseReal);
$insideRegisteredBase = ($realNorm !== '' && $baseNorm !== '' && ($realNorm === $baseNorm || strpos($realNorm, $baseNorm . '/') === 0));

// Final fallback: allow only files physically under the CMS content/plugins directory.
$pluginsBase = function_exists('devone_plugin_base_path') ? realpath(devone_plugin_base_path()) : realpath(__DIR__ . '/../content/plugins');
$pluginsBaseNorm = function_exists('devone_plugin_path_norm') ? devone_plugin_path_norm($pluginsBase ?: '') : str_replace('\\', '/', (string)$pluginsBase);
$insidePluginsBase = ($realNorm !== '' && $pluginsBaseNorm !== '' && ($realNorm === $pluginsBaseNorm || strpos($realNorm, $pluginsBaseNorm . '/') === 0));

if (!$real || !is_file($real) || (!$insideRegisteredBase && !$insidePluginsBase)) {
    devone_admin_header('Plugin Page Error - DevOneCMS');
    echo '<h1>Plugin page error</h1><p class="card error-card">The registered plugin page file could not be loaded safely.</p>';
    if (function_exists('devone_has_permission') && devone_has_permission('admin_all')) {
        echo '<div class="card"><strong>Debug:</strong><br>Plugin: ' . e($plugin) . '<br>Page: ' . e($page) . '<br>File: ' . e($file) . '<br>Resolved file: ' . e((string)$real) . '<br>Base: ' . e((string)$base) . '<br>Resolved base: ' . e((string)$baseReal) . '</div>';
    }
    devone_admin_footer();
    exit;
}

define('DEVONE_PLUGIN_ADMIN_ROUTER', true);
define('DEVONE_PLUGIN_ADMIN_PLUGIN', $plugin);
define('DEVONE_PLUGIN_ADMIN_PAGE', $page);
$GLOBALS['devone_plugin_admin_current'] = $registered;

devone_admin_header(($registered['title'] ?? 'Plugin Page') . ' - DevOneCMS');
include $real;
devone_admin_footer();
