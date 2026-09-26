<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();

$module = function_exists('devone_module_clean_slug') ? devone_module_clean_slug($_GET['module'] ?? '', '') : '';
$page = function_exists('devone_module_clean_slug') ? devone_module_clean_slug($_GET['page'] ?? '', '') : '';

if (function_exists('devone_load_active_modules')) { devone_load_active_modules(); }
$registered = function_exists('devone_get_module_admin_page') ? devone_get_module_admin_page($module, $page) : null;

if (!$registered) {
    devone_admin_header('Module Page Not Found - DevOne CMS');
    echo '<h1>Module page not found</h1><p class="card error-card">This module page is not registered, the module is inactive, or its manifest is invalid.</p>';
    devone_admin_footer();
    exit;
}

$permission = (string)($registered['permission'] ?? 'manage_modules');
if ($permission !== '') { devone_require_permission($permission); }

$file = (string)($registered['file'] ?? '');
$base = (string)($registered['base'] ?? '');
$real = realpath($file);
$baseReal = realpath($base);
$inside = $real && $baseReal && is_file($real) && function_exists('devone_module_is_inside') && devone_module_is_inside($real, $baseReal);

if (!$inside) {
    devone_admin_header('Module Page Error - DevOne CMS');
    echo '<h1>Module page error</h1><p class="card error-card">The registered module page could not be loaded safely.</p>';
    devone_admin_footer();
    exit;
}

devone_admin_header(($registered['title'] ?? 'Module') . ' - DevOne CMS');
try {
    $devone_module_slug = $module;
    $devone_module_page = $page;
    $devone_module_manifest = function_exists('devone_module_manifest') ? devone_module_manifest($module) : array();
    include $real;
} catch (Throwable $e) {
    if (function_exists('devone_log')) { devone_log('module_admin_page_error', $module . '/' . $page . ': ' . $e->getMessage()); }
    echo '<div class="card error-card"><h2>Module page error</h2><p>The module page could not complete this request.</p></div>';
}
devone_admin_footer();
