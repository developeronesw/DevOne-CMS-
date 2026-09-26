<?php
/**
 * DevOneCMS plugin runtime.
 * Loads active plugins and lets plugins register backend pages safely.
 */

$GLOBALS['devone_loaded_plugins'] = $GLOBALS['devone_loaded_plugins'] ?? array();
$GLOBALS['devone_plugin_admin_pages'] = $GLOBALS['devone_plugin_admin_pages'] ?? array();

if (!function_exists('devone_plugin_path_norm')) {
    function devone_plugin_path_norm($path) {
        $path = str_replace('\\', '/', (string)$path);
        $path = preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); }
        return $path;
    }
}

function devone_plugin_base_path() {
    $root = realpath(__DIR__ . '/..');
    $base = ($root ?: dirname(__DIR__)) . '/content/plugins';
    if (!is_dir($base)) { @mkdir($base, 0755, true); }
    $real = realpath($base);
    return $real ?: $base;
}

function devone_plugin_base_url() {
    if (function_exists('devone_site_url')) { return rtrim(devone_site_url('content/plugins'), '/'); }
    return '../content/plugins';
}

function devone_plugin_clean_slug($value) {
    if (function_exists('devone_slugify')) { return devone_slugify($value, ''); }
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value);
    return trim($value, '-');
}

function devone_plugin_resolve_root_from_file($file) {
    $realFile = realpath($file);
    if (!$realFile) { return false; }

    $pluginsBase = realpath(devone_plugin_base_path());
    $fileNorm = devone_plugin_path_norm($realFile);
    $baseNorm = devone_plugin_path_norm($pluginsBase ?: devone_plugin_base_path());

    // If the file is inside content/plugins/<plugin-folder>/..., use the first folder under content/plugins.
    if ($baseNorm !== '' && ($fileNorm === $baseNorm || strpos($fileNorm, $baseNorm . '/') === 0)) {
        $relative = ltrim(substr($fileNorm, strlen($baseNorm)), '/');
        $parts = explode('/', $relative);
        if (!empty($parts[0])) {
            $root = rtrim($pluginsBase ?: devone_plugin_base_path(), DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $parts[0];
            $rootReal = realpath($root);
            if ($rootReal) { return $rootReal; }
        }
    }

    // Fallback for common plugin structure: <plugin>/admin/file.php.
    $parent = dirname($realFile);
    if (basename($parent) === 'admin' || basename($parent) === 'includes') {
        $parent = dirname($parent);
    }
    return realpath($parent) ?: $parent;
}

function devone_register_plugin_admin_page($plugin, $slug, $title, $file, $permission = 'manage_plugins', $menu_title = '') {
    $plugin = devone_plugin_clean_slug($plugin);
    $slug = devone_plugin_clean_slug($slug);
    if ($plugin === '' || $slug === '' || $file === '') { return false; }

    $real = realpath($file);
    if ($real === false || !is_file($real)) { return false; }

    // IMPORTANT: The safe base must be the actual installed plugin folder,
    // not necessarily the plugin slug. This supports DevOne-style ZIPs where
    // the folder name can differ from the plugin's internal slug/name.
    $base = devone_plugin_resolve_root_from_file($real);
    if (!$base) { return false; }

    $GLOBALS['devone_plugin_admin_pages'][$plugin . ':' . $slug] = array(
        'plugin' => $plugin,
        'slug' => $slug,
        'title' => (string)$title,
        'menu_title' => $menu_title !== '' ? (string)$menu_title : (string)$title,
        'file' => $real,
        'permission' => (string)$permission,
        'base' => $base,
        'installed_folder' => basename($base),
    );
    return true;
}

function devone_plugin_admin_pages() {
    return array_values($GLOBALS['devone_plugin_admin_pages'] ?? array());
}

function devone_get_plugin_admin_page($plugin, $slug) {
    $key = devone_plugin_clean_slug($plugin) . ':' . devone_plugin_clean_slug($slug);
    return $GLOBALS['devone_plugin_admin_pages'][$key] ?? null;
}


function devone_active_plugin_cache_path() {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    return $root . '/storage/cache/active-plugins.json';
}

function devone_invalidate_active_plugin_cache() {
    $file = devone_active_plugin_cache_path();
    if (is_file($file)) { @unlink($file); }
    if (function_exists('devone_invalidate_plugin_admin_registry')) { devone_invalidate_plugin_admin_registry(); }
    return true;
}

function devone_active_plugin_folders($force = false) {
    $file = devone_active_plugin_cache_path();
    if (!$force && is_file($file)) {
        $data = json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && !empty($data['folders']) && is_array($data['folders']) && (time() - (int)($data['created_at'] ?? 0)) < 300) {
            return array_values(array_filter(array_map('devone_plugin_clean_slug', $data['folders'])));
        }
        if (is_array($data) && isset($data['folders']) && is_array($data['folders']) && (time() - (int)($data['created_at'] ?? 0)) < 300) { return array(); }
    }
    if (!function_exists('db') || !function_exists('table_exists') || !function_exists('table_name') || !table_exists('plugins')) { return array(); }
    try {
        $stmt = db()->query('SELECT folder FROM `' . table_name('plugins') . '` WHERE active=1 ORDER BY id ASC');
        $folders = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : array();
    } catch (Throwable $e) { $folders = array(); }
    $folders = array_values(array_filter(array_map('devone_plugin_clean_slug', $folders)));
    $dir = dirname($file); if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents($file, json_encode(array('created_at'=>time(),'folders'=>$folders), JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $folders;
}

function devone_plugin_manifest($folder) {
    $folder = devone_plugin_clean_slug($folder); if ($folder === '') { return array(); }
    $file = rtrim(devone_plugin_base_path(), DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'plugin.json';
    if (!is_file($file)) { return array(); }
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : array();
}

function devone_plugin_lifecycle_run($folder, $event = 'activate') {
    $folder = devone_plugin_clean_slug($folder); if ($folder === '') { return true; }
    $manifest = devone_plugin_manifest($folder);
    $lifecycle = isset($manifest['lifecycle']) && is_array($manifest['lifecycle']) ? $manifest['lifecycle'] : array();
    $event = preg_replace('/[^a-z_\-]/', '', strtolower((string)$event));
    $relative = isset($lifecycle[$event]) ? ltrim((string)$lifecycle[$event], '/\\') : '';
    if ($relative === '') { return true; } // Legacy plugin: no lifecycle contract, preserve compatibility.
    $base = realpath(rtrim(devone_plugin_base_path(), DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $folder);
    $target = $base ? realpath($base . DIRECTORY_SEPARATOR . $relative) : false;
    if (!$base || !$target || !is_file($target) || strpos(devone_plugin_path_norm($target), devone_plugin_path_norm($base) . '/') !== 0) { return false; }
    try { require $target; return true; }
    catch (Throwable $e) { if (function_exists('devone_log')) { devone_log('plugin_lifecycle_error', $folder . ':' . $event . ': ' . $e->getMessage()); } return false; }
}

function devone_repair_active_plugin_schemas() {
    $results = array();
    foreach (devone_active_plugin_folders(true) as $folder) {
        $manifest = devone_plugin_manifest($folder);
        $lifecycle = isset($manifest['lifecycle']) && is_array($manifest['lifecycle']) ? $manifest['lifecycle'] : array();
        if (empty($lifecycle['repair']) && empty($lifecycle['activate'])) { continue; }
        $event = !empty($lifecycle['repair']) ? 'repair' : 'activate';
        $results[$folder] = devone_plugin_lifecycle_run($folder, $event);
    }
    return $results;
}


function devone_plugin_runtime_metadata($folder) {
    $manifest = devone_plugin_manifest($folder);
    return isset($manifest['runtime']) && is_array($manifest['runtime']) ? $manifest['runtime'] : array();
}

function devone_plugin_lazy_admin_enabled($folder) {
    $runtime = devone_plugin_runtime_metadata($folder);
    $mode = strtolower(trim((string)($runtime['admin_mode'] ?? '')));
    return in_array($mode, array('lazy','optimized','route-aware'), true);
}

function devone_plugin_admin_integration_pages($folder) {
    $runtime = devone_plugin_runtime_metadata($folder);
    $pages = $runtime['admin_pages'] ?? array();
    if (is_string($pages)) { $pages = array($pages); }
    $out = array();
    foreach ((array)$pages as $page) {
        $page = strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', (string)$page), '-'));
        if ($page !== '') { $out[] = $page; }
    }
    return array_values(array_unique($out));
}

function devone_plugin_load_one($folder) {
    $folder = devone_plugin_clean_slug($folder);
    if ($folder === '' || !empty($GLOBALS['devone_loaded_plugins'][$folder])) { return true; }
    $file = rtrim(devone_plugin_base_path(), DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'plugin.php';
    if (!is_file($file)) { return false; }
    $GLOBALS['devone_loaded_plugins'][$folder] = true;
    try { require_once $file; return true; }
    catch (Throwable $e) {
        if (function_exists('devone_log')) { devone_log('plugin_load_error', $folder . ': ' . $e->getMessage()); }
        return false;
    }
}

function devone_plugin_admin_registry_cache_path() {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    return $root . '/storage/cache/plugin-admin-registry.json';
}

function devone_invalidate_plugin_admin_registry() {
    $file = devone_plugin_admin_registry_cache_path();
    if (is_file($file)) { @unlink($file); }
    return true;
}

function devone_plugin_admin_registry_export() {
    $out = array();
    $base = realpath(devone_plugin_base_path()) ?: devone_plugin_base_path();
    foreach (devone_plugin_admin_pages() as $page) {
        $file = realpath((string)($page['file'] ?? ''));
        $root = realpath((string)($page['base'] ?? ''));
        if (!$file || !$root) { continue; }
        $rootNorm = devone_plugin_path_norm($root);
        $fileNorm = devone_plugin_path_norm($file);
        if ($rootNorm === '' || strpos($fileNorm, $rootNorm . '/') !== 0) { continue; }
        $relative = ltrim(substr($fileNorm, strlen($rootNorm)), '/');
        $out[] = array(
            'plugin'=>(string)($page['plugin'] ?? ''),
            'slug'=>(string)($page['slug'] ?? ''),
            'title'=>(string)($page['title'] ?? ''),
            'menu_title'=>(string)($page['menu_title'] ?? ''),
            'permission'=>(string)($page['permission'] ?? 'manage_plugins'),
            'installed_folder'=>(string)($page['installed_folder'] ?? basename($root)),
            'file_relative'=>$relative,
        );
    }
    return $out;
}

function devone_plugin_admin_registry_write() {
    $file = devone_plugin_admin_registry_cache_path();
    $dir = dirname($file); if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $payload = array('created_at'=>time(), 'pages'=>devone_plugin_admin_registry_export());
    return @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function devone_plugin_admin_registry_load() {
    $file = devone_plugin_admin_registry_cache_path();
    if (!is_file($file)) { return false; }
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || !isset($data['pages']) || !is_array($data['pages'])) { return false; }
    foreach ($data['pages'] as $page) {
        $folder = devone_plugin_clean_slug($page['installed_folder'] ?? '');
        $plugin = devone_plugin_clean_slug($page['plugin'] ?? '');
        $slug = devone_plugin_clean_slug($page['slug'] ?? '');
        $relative = ltrim((string)($page['file_relative'] ?? ''), '/\\');
        if ($folder === '' || $plugin === '' || $slug === '' || $relative === '' || strpos($relative, '..') !== false) { continue; }
        $base = realpath(rtrim(devone_plugin_base_path(), DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $folder);
        $target = $base ? realpath($base . DIRECTORY_SEPARATOR . $relative) : false;
        if (!$base || !$target || !is_file($target) || strpos(devone_plugin_path_norm($target), devone_plugin_path_norm($base) . '/') !== 0) { continue; }
        $GLOBALS['devone_plugin_admin_pages'][$plugin . ':' . $slug] = array(
            'plugin'=>$plugin,
            'slug'=>$slug,
            'title'=>(string)($page['title'] ?? ''),
            'menu_title'=>(string)($page['menu_title'] ?? ''),
            'file'=>$target,
            'permission'=>(string)($page['permission'] ?? 'manage_plugins'),
            'base'=>$base,
            'installed_folder'=>$folder,
        );
    }
    return true;
}

/**
 * Admin-aware plugin bootstrap.
 *
 * Legacy plugins continue to load on every admin request for compatibility.
 * A plugin opts into lazy admin loading only by declaring runtime.admin_mode in
 * plugin.json. Lazy plugins load on their own plugin-page.php requests and on
 * explicitly declared Core admin integration pages in runtime.admin_pages.
 */
function devone_load_admin_plugins() {
    $folders = devone_active_plugin_folders(false);
    $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $currentPage = strtolower(trim(preg_replace('/\.php$/i', '', $currentScript)));
    $targetPlugin = $currentScript === 'plugin-page.php' ? devone_plugin_clean_slug($_GET['plugin'] ?? '') : '';

    $hasLazy = false;
    foreach ($folders as $folder) {
        if (devone_plugin_lazy_admin_enabled($folder)) { $hasLazy = true; continue; }
        devone_plugin_load_one($folder);
    }

    if ($hasLazy) {
        if (!devone_plugin_admin_registry_load()) {
            // One-time compatibility discovery. Subsequent requests use the cached registry.
            foreach ($folders as $folder) {
                if (devone_plugin_lazy_admin_enabled($folder)) { devone_plugin_load_one($folder); }
            }
            devone_plugin_admin_registry_write();
        }
        foreach ($folders as $folder) {
            if (!devone_plugin_lazy_admin_enabled($folder)) { continue; }
            if ($targetPlugin !== '' && $targetPlugin === devone_plugin_clean_slug($folder)) {
                devone_plugin_load_one($folder);
                continue;
            }
            if (in_array($currentPage, devone_plugin_admin_integration_pages($folder), true)) {
                devone_plugin_load_one($folder);
            }
        }
    }

    if (function_exists('do_action')) { do_action('devone_plugins_loaded'); }
    if (function_exists('devone_runtime_boot_context')) { devone_runtime_boot_context(); }
    if (function_exists('do_action')) { do_action('devone_admin_plugins_loaded'); }
}

function devone_load_active_plugins() {
    $folders = function_exists('devone_active_plugin_folders') ? devone_active_plugin_folders(false) : array();
    foreach ($folders as $folder) {
        $folder = devone_plugin_clean_slug($folder);
        if ($folder === '' || !empty($GLOBALS['devone_loaded_plugins'][$folder])) { continue; }
        $file = rtrim(devone_plugin_base_path(), DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'plugin.php';
        if (is_file($file)) {
            $GLOBALS['devone_loaded_plugins'][$folder] = true;
            try { require_once $file; }
            catch (Throwable $e) { if (function_exists('devone_log')) { devone_log('plugin_load_error', $folder . ': ' . $e->getMessage()); } }
        }
    }
    if (function_exists('do_action')) { do_action('devone_plugins_loaded'); }
}

function devone_plugin_admin_url($plugin, $page) {
    $plugin = rawurlencode(devone_plugin_clean_slug($plugin));
    $page = rawurlencode(devone_plugin_clean_slug($page));
    return 'plugin-page.php?plugin=' . $plugin . '&page=' . $page;
}
