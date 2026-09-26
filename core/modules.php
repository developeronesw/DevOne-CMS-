<?php
/**
 * DevOne CMS module runtime.
 *
 * Modules are trusted, installable application packages. They can register
 * front-end routes, admin pages, lifecycle routines, permissions, and assets.
 */

$GLOBALS['devone_loaded_modules'] = $GLOBALS['devone_loaded_modules'] ?? array();
$GLOBALS['devone_module_instances'] = $GLOBALS['devone_module_instances'] ?? array();
$GLOBALS['devone_module_manifests'] = $GLOBALS['devone_module_manifests'] ?? array();
$GLOBALS['devone_module_admin_pages'] = $GLOBALS['devone_module_admin_pages'] ?? array();
$GLOBALS['devone_module_routes'] = $GLOBALS['devone_module_routes'] ?? array();
$GLOBALS['devone_module_assets'] = $GLOBALS['devone_module_assets'] ?? array();
$GLOBALS['devone_module_load_errors'] = $GLOBALS['devone_module_load_errors'] ?? array();

if (!function_exists('devone_module_path_norm')) {
    function devone_module_path_norm($path) {
        $path = str_replace('\\', '/', (string)$path);
        $path = preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); }
        return $path;
    }
}

function devone_module_base_path() {
    $root = realpath(__DIR__ . '/..');
    $base = ($root ?: dirname(__DIR__)) . '/content/modules';
    if (!is_dir($base)) { @mkdir($base, 0775, true); }
    $real = realpath($base);
    return $real ?: $base;
}

function devone_module_base_url() {
    if (function_exists('devone_site_url')) { return rtrim(devone_site_url('content/modules'), '/'); }
    return '../content/modules';
}

function devone_module_clean_slug($value, $fallback = '') {
    if (function_exists('devone_slugify')) { return devone_slugify($value, $fallback); }
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : $fallback;
}

function devone_module_is_inside($path, $base) {
    $realPath = realpath($path);
    $realBase = realpath($base);
    if (!$realPath || !$realBase) { return false; }
    $pathNorm = devone_module_path_norm($realPath);
    $baseNorm = devone_module_path_norm($realBase);
    return $pathNorm === $baseNorm || strpos($pathNorm, $baseNorm . '/') === 0;
}

function devone_module_root($folder) {
    $folder = devone_module_clean_slug($folder, '');
    if ($folder === '') { return ''; }
    $base = devone_module_base_path();
    $candidate = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($candidate)) { return ''; }
    $real = realpath($candidate);
    return ($real && devone_module_is_inside($real, $base)) ? $real : '';
}

function devone_module_resolve_file($folder, $relative, $mustExist = true) {
    $root = devone_module_root($folder);
    if ($root === '') { return ''; }
    $relative = str_replace('\\', '/', trim((string)$relative));
    $relative = ltrim($relative, '/');
    if ($relative === '' || strpos($relative, "\0") !== false) { return ''; }
    foreach (explode('/', $relative) as $part) {
        if ($part === '..') { return ''; }
    }
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if ($mustExist) {
        $real = realpath($candidate);
        if (!$real || !is_file($real) || !devone_module_is_inside($real, $root)) { return ''; }
        return $real;
    }
    $parent = realpath(dirname($candidate));
    if (!$parent || !devone_module_is_inside($parent, $root)) { return ''; }
    return $candidate;
}

function devone_module_manifest($folder, $refresh = false) {
    $folder = devone_module_clean_slug($folder, '');
    if ($folder === '') { return array(); }
    if (!$refresh && isset($GLOBALS['devone_module_manifests'][$folder])) {
        return $GLOBALS['devone_module_manifests'][$folder];
    }

    $root = devone_module_root($folder);
    if ($root === '' || !is_file($root . '/module.json')) { return array(); }
    $raw = @file_get_contents($root . '/module.json');
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) { return array(); }

    $manifest = array_merge(array(
        'name' => ucwords(str_replace(array('-', '_'), ' ', $folder)),
        'slug' => $folder,
        'folder' => $folder,
        'version' => '1.0.0',
        'description' => '',
        'author' => '',
        'license' => 'GPL-3.0-or-later',
        'main' => 'module.php',
        'admin_pages' => array(),
        'routes' => array(),
        'assets' => array(),
        'permissions' => array(),
        'lifecycle' => array(),
    ), $json);

    $manifest['folder'] = $folder;
    $manifest['slug'] = devone_module_clean_slug($manifest['slug'] ?? $folder, $folder);
    $manifest['name'] = trim((string)($manifest['name'] ?? '')) ?: ucwords(str_replace('-', ' ', $folder));
    $manifest['version'] = trim((string)($manifest['version'] ?? '1.0.0')) ?: '1.0.0';
    $manifest['main'] = trim((string)($manifest['main'] ?? 'module.php')) ?: 'module.php';
    foreach (array('admin_pages','routes','assets','permissions','lifecycle') as $key) {
        if (!is_array($manifest[$key] ?? null)) { $manifest[$key] = array(); }
    }

    $GLOBALS['devone_module_manifests'][$folder] = $manifest;
    return $manifest;
}

function devone_module_validate($folder, &$error = '') {
    $error = '';
    $root = devone_module_root($folder);
    if ($root === '') { $error = 'Module folder was not found.'; return false; }
    if (!is_file($root . '/module.json')) { $error = 'Module package is missing module.json.'; return false; }
    $manifest = devone_module_manifest($folder, true);
    if (!$manifest) { $error = 'module.json is invalid JSON.'; return false; }

    $main = (string)($manifest['main'] ?? 'module.php');
    $mainFile = devone_module_resolve_file($folder, $main);
    if ($mainFile === '' && is_file($root . '/controller.php')) {
        $mainFile = realpath($root . '/controller.php') ?: '';
    }
    if ($mainFile === '') {
        $error = 'Module package must include the declared main file (' . $main . ') or legacy controller.php.';
        return false;
    }
    return true;
}

function devone_module_table_columns() {
    if (function_exists('devone_table_columns')) { return devone_table_columns('modules'); }
    return array('id','name','description','folder','active','created_at');
}

function devone_module_update_record($folder, $values) {
    if (!function_exists('table_exists') || !table_exists('modules')) { return false; }
    $folder = devone_module_clean_slug($folder, '');
    if ($folder === '') { return false; }
    $columns = devone_module_table_columns();
    $sets = array();
    $params = array();
    foreach ((array)$values as $key => $value) {
        if (!in_array($key, $columns, true) || in_array($key, array('id','folder'), true)) { continue; }
        $sets[] = '`' . $key . '`=?';
        $params[] = $value;
    }
    if (!$sets) { return false; }
    $params[] = $folder;
    try {
        $stmt = db()->prepare('UPDATE `' . table_name('modules') . '` SET ' . implode(',', $sets) . ' WHERE folder=?');
        return $stmt->execute($params);
    } catch (Throwable $e) { return false; }
}

function devone_module_upsert($folder, $manifest, $active = 0, $status = 'installed', $lastError = '') {
    if (!function_exists('table_exists') || !table_exists('modules')) { return false; }
    $folder = devone_module_clean_slug($folder, '');
    if ($folder === '') { return false; }
    $manifest = is_array($manifest) ? $manifest : array();
    $columns = devone_module_table_columns();
    $data = array(
        'name' => (string)($manifest['name'] ?? ucwords(str_replace('-', ' ', $folder))),
        'description' => (string)($manifest['description'] ?? ''),
        'folder' => $folder,
        'active' => (int)$active,
        'version' => (string)($manifest['version'] ?? '1.0.0'),
        'author' => (string)($manifest['author'] ?? ''),
        'manifest' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
        'status' => $status,
        'last_error' => $lastError,
    );
    $data = array_intersect_key($data, array_flip($columns));

    try {
        $check = db()->prepare('SELECT id FROM `' . table_name('modules') . '` WHERE folder=? LIMIT 1');
        $check->execute(array($folder));
        $id = (int)$check->fetchColumn();
        if ($id > 0) {
            $sets = array(); $params = array();
            foreach ($data as $key => $value) {
                if ($key === 'folder') { continue; }
                $sets[] = '`' . $key . '`=?'; $params[] = $value;
            }
            if (in_array('updated_at', $columns, true)) { $sets[] = '`updated_at`=CURRENT_TIMESTAMP'; }
            $params[] = $id;
            $stmt = db()->prepare('UPDATE `' . table_name('modules') . '` SET ' . implode(',', $sets) . ' WHERE id=?');
            return $stmt->execute($params);
        }
        $keys = array_keys($data);
        if (in_array('installed_at', $columns, true)) { $keys[] = 'installed_at'; }
        $quoted = array_map(function($key){ return '`' . $key . '`'; }, $keys);
        $placeholders = array_fill(0, count($data), '?');
        if (in_array('installed_at', $columns, true)) { $placeholders[] = 'CURRENT_TIMESTAMP'; }
        $stmt = db()->prepare('INSERT INTO `' . table_name('modules') . '` (' . implode(',', $quoted) . ') VALUES (' . implode(',', $placeholders) . ')');
        return $stmt->execute(array_values($data));
    } catch (Throwable $e) {
        if (function_exists('devone_log')) { devone_log('module_record_error', $folder . ': ' . $e->getMessage()); }
        return false;
    }
}

function devone_register_module_admin_page($module, $slug, $title, $file, $permission = 'manage_modules', $menuTitle = '') {
    $module = devone_module_clean_slug($module, '');
    $slug = devone_module_clean_slug($slug, '');
    if ($module === '' || $slug === '' || $file === '') { return false; }

    $root = devone_module_root($module);
    if ($root === '') { return false; }
    $real = realpath($file);
    if (!$real && !preg_match('#^([a-zA-Z]:[\\/]|/)#', (string)$file)) {
        $real = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$file));
    }
    if (!$real || !is_file($real) || !devone_module_is_inside($real, $root)) { return false; }

    $GLOBALS['devone_module_admin_pages'][$module . ':' . $slug] = array(
        'module' => $module,
        'slug' => $slug,
        'title' => (string)$title,
        'menu_title' => $menuTitle !== '' ? (string)$menuTitle : (string)$title,
        'file' => $real,
        'permission' => (string)$permission,
        'base' => $root,
    );
    return true;
}

function devone_module_admin_pages() {
    return array_values($GLOBALS['devone_module_admin_pages'] ?? array());
}

function devone_get_module_admin_page($module, $slug) {
    $key = devone_module_clean_slug($module, '') . ':' . devone_module_clean_slug($slug, '');
    return $GLOBALS['devone_module_admin_pages'][$key] ?? null;
}

function devone_module_admin_url($module, $page) {
    return 'module-page.php?module=' . rawurlencode(devone_module_clean_slug($module, '')) . '&page=' . rawurlencode(devone_module_clean_slug($page, ''));
}

function devone_register_module_route($module, $path, $methods, $handler, $options = array()) {
    $module = devone_module_clean_slug($module, '');
    $path = trim(str_replace('\\', '/', (string)$path), '/');
    if ($module === '' || $path === '') { return false; }
    if (!preg_match('#^[a-zA-Z0-9_\-/{}/.*]+$#', $path)) { return false; }

    if (is_string($methods)) { $methods = preg_split('/[,|\s]+/', strtoupper($methods), -1, PREG_SPLIT_NO_EMPTY); }
    $methods = array_values(array_unique(array_map('strtoupper', (array)$methods)));
    if (!$methods) { $methods = array('GET'); }
    $allowedMethods = array('GET','POST','PUT','PATCH','DELETE','OPTIONS','HEAD');
    $methods = array_values(array_intersect($methods, $allowedMethods));
    if (!$methods) { return false; }

    if (is_string($handler) && !is_callable($handler)) {
        $resolved = devone_module_resolve_file($module, $handler);
        if ($resolved === '') { return false; }
        $handler = $resolved;
    }
    if (!is_callable($handler) && !(is_string($handler) && is_file($handler))) { return false; }

    $route = array_merge(array(
        'module' => $module,
        'path' => $path,
        'methods' => $methods,
        'handler' => $handler,
        'title' => ucwords(str_replace(array('-', '/'), ' ', $path)),
        'permission' => '',
        'auth_required' => false,
        // null = automatically protect authenticated/permissioned mutating routes.
        // Public webhook-style routes can remain CSRF-free; callers may opt in explicitly.
        'csrf_protected' => null,
        'priority' => 10,
    ), is_array($options) ? $options : array());
    $GLOBALS['devone_module_routes'][] = $route;
    usort($GLOBALS['devone_module_routes'], function($a, $b){ return ((int)($a['priority'] ?? 10)) <=> ((int)($b['priority'] ?? 10)); });
    return true;
}

function devone_module_request_path() {
    if (isset($_GET['page']) && trim((string)$_GET['page']) !== '') {
        return trim(preg_replace('#/+#', '/', str_replace('\\', '/', (string)$_GET['page'])), '/');
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = parse_url(defined('SITE_URL') ? SITE_URL : '/', PHP_URL_PATH) ?: '/';
    $base = rtrim($base, '/');
    if ($base !== '' && strpos($path, $base) === 0) { $path = substr($path, strlen($base)); }
    $path = trim($path, '/');
    if ($path === '' || $path === 'index.php') { return 'home'; }
    if (strpos($path, 'index.php/') === 0) { $path = substr($path, strlen('index.php/')); }
    return trim($path, '/');
}

function devone_module_route_regex($path, &$parameterNames = array()) {
    $parameterNames = array();
    $parts = explode('/', trim((string)$path, '/'));
    $regex = array();
    foreach ($parts as $part) {
        if ($part === '*') {
            $parameterNames[] = 'wildcard';
            $regex[] = '(?P<wildcard>.*)';
        } elseif (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $m)) {
            $parameterNames[] = $m[1];
            $regex[] = '(?P<' . $m[1] . '>[^/]+)';
        } else {
            $regex[] = preg_quote($part, '#');
        }
    }
    return '#^' . implode('/', $regex) . '/?$#i';
}

function devone_module_request_payload() {
    $raw = @file_get_contents('php://input');
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $json = null;
    if ($raw !== '' && strpos($contentType, 'application/json') !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $json = $decoded; }
    }
    $body = $json !== null ? $json : $_POST;
    return array(
        'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'path' => devone_module_request_path(),
        'query' => $_GET,
        'body' => is_array($body) ? $body : array(),
        'json' => $json,
        'raw_body' => (string)$raw,
        'files' => $_FILES,
    );
}

function devone_module_normalize_route_result($result, $route, $request, $params) {
    $base = array(
        'matched' => true,
        'status' => 200,
        'title' => (string)($route['title'] ?? 'Module'),
        'content' => '',
        'headers' => array(),
        'format' => 'html',
        'data' => null,
        'module' => (string)($route['module'] ?? ''),
        'route' => $route,
        'request' => $request,
        'params' => $params,
    );
    if (is_string($result) || is_numeric($result)) { $base['content'] = (string)$result; return $base; }
    if ($result === null) { return $base; }
    if (is_array($result)) {
        if (array_key_exists('json', $result)) {
            $base['format'] = 'json';
            $base['data'] = $result['json'];
            unset($result['json']);
        }
        if (array_key_exists('data', $result) && (($result['format'] ?? '') === 'json')) { $base['data'] = $result['data']; }
        return array_merge($base, $result);
    }
    if (is_object($result) && method_exists($result, '__toString')) { $base['content'] = (string)$result; }
    return $base;
}

function devone_dispatch_module_route($path = null) {
    $path = $path === null ? devone_module_request_path() : trim((string)$path, '/');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $request = devone_module_request_payload();

    foreach (($GLOBALS['devone_module_routes'] ?? array()) as $route) {
        if (!in_array($method, (array)($route['methods'] ?? array('GET')), true)) { continue; }
        $names = array();
        $regex = devone_module_route_regex($route['path'] ?? '', $names);
        if (!preg_match($regex, $path, $matches)) { continue; }
        $params = array();
        foreach ($names as $name) { if (isset($matches[$name])) { $params[$name] = rawurldecode((string)$matches[$name]); } }

        if (!empty($route['auth_required'])) {
            $uid = function_exists('devone_current_user_id') ? devone_current_user_id() : 0;
            if ($uid <= 0) {
                return array('matched'=>true,'status'=>401,'title'=>'Authentication Required','content'=>'<section class="card"><h1>Authentication required</h1><p>Please sign in to access this module route.</p></section>','headers'=>array());
            }
        }
        $permission = (string)($route['permission'] ?? '');
        if ($permission !== '' && function_exists('devone_has_permission') && !devone_has_permission($permission)) {
            return array('matched'=>true,'status'=>403,'title'=>'Access Denied','content'=>'<section class="card"><h1>Access denied</h1><p>You do not have permission to access this module route.</p></section>','headers'=>array());
        }

        $mutating = in_array($method, array('POST','PUT','PATCH','DELETE'), true);
        $csrfSetting = array_key_exists('csrf_protected', $route) ? $route['csrf_protected'] : null;
        $csrfRequired = $csrfSetting === null ? (!empty($route['auth_required']) || $permission !== '') : (bool)$csrfSetting;
        if ($mutating && $csrfRequired) {
            if (!function_exists('devone_verify_csrf_token') || !devone_verify_csrf_token()) {
                return array('matched'=>true,'status'=>403,'title'=>'Invalid Security Token','content'=>'<section class="card"><h1>Request blocked</h1><p>The security token is invalid or expired.</p></section>','headers'=>array());
            }
        }

        try {
            $handler = $route['handler'] ?? null;
            if (is_callable($handler)) {
                $result = call_user_func($handler, $request, $params, $route);
            } elseif (is_string($handler) && is_file($handler)) {
                $devone_module_request = $request;
                $devone_module_params = $params;
                $devone_module_route = $route;
                ob_start();
                try {
                    $result = (static function($file, $devone_module_request, $devone_module_params, $devone_module_route) {
                        return include $file;
                    })($handler, $devone_module_request, $devone_module_params, $devone_module_route);
                    $rendered = (string)ob_get_clean();
                } catch (Throwable $viewError) {
                    ob_end_clean();
                    throw $viewError;
                }
                if ($rendered !== '') {
                    if ($result === 1 || $result === true || $result === null) { $result = $rendered; }
                    elseif (is_array($result) && empty($result['content']) && (($result['format'] ?? 'html') === 'html')) { $result['content'] = $rendered; }
                }
            } else {
                throw new RuntimeException('Module route handler is unavailable.');
            }
            return devone_module_normalize_route_result($result, $route, $request, $params);
        } catch (Throwable $e) {
            if (function_exists('devone_log')) { devone_log('module_route_error', ($route['module'] ?? '') . '/' . ($route['path'] ?? '') . ': ' . $e->getMessage()); }
            return array('matched'=>true,'status'=>500,'title'=>'Module Error','content'=>'<section class="card"><h1>Module route error</h1><p>The requested module could not complete this request.</p></section>','headers'=>array());
        }
    }
    return array('matched' => false);
}

function devone_module_send_route_response($response) {
    if (empty($response['matched'])) { return false; }
    http_response_code(max(100, min(599, (int)($response['status'] ?? 200))));
    foreach ((array)($response['headers'] ?? array()) as $name => $value) {
        $name = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$name);
        if ($name !== '') { header($name . ': ' . str_replace(array("\r","\n"), '', (string)$value)); }
    }
    if (($response['format'] ?? 'html') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return true;
    }
    if (!empty($response['raw'])) {
        echo (string)($response['content'] ?? '');
        return true;
    }
    return false;
}

function devone_register_module_asset($module, $type, $source, $options = array()) {
    $module = devone_module_clean_slug($module, '');
    $type = strtolower((string)$type) === 'css' ? 'css' : 'js';
    $source = trim((string)$source);
    if ($module === '' || $source === '') { return false; }

    $isRemote = preg_match('#^https?://#i', $source) === 1;
    if (!$isRemote) {
        $file = devone_module_resolve_file($module, $source);
        if ($file === '') { return false; }
        $source = rtrim(devone_module_base_url(), '/') . '/' . rawurlencode($module) . '/' . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', trim((string)($options['relative'] ?? $source), '/'))));
    }
    $asset = array_merge(array(
        'module' => $module,
        'type' => $type,
        'url' => $source,
        'scope' => 'frontend',
        'location' => $type === 'css' ? 'head' : 'footer',
        'defer' => $type === 'js',
        'async' => false,
        'module_script' => false,
        'integrity' => '',
        'crossorigin' => '',
        'priority' => 10,
    ), is_array($options) ? $options : array());
    $key = sha1($module . '|' . $type . '|' . $asset['url'] . '|' . $asset['scope'] . '|' . $asset['location']);
    $GLOBALS['devone_module_assets'][$key] = $asset;
    return true;
}

function devone_module_asset_definitions($module, $manifest) {
    $assets = $manifest['assets'] ?? array();
    if (!is_array($assets)) { return; }

    $registerList = function($scope, $type, $items) use ($module) {
        foreach ((array)$items as $item) {
            if (is_string($item)) {
                devone_register_module_asset($module, $type, $item, array('scope'=>$scope));
            } elseif (is_array($item)) {
                $source = (string)($item['file'] ?? $item['url'] ?? $item['src'] ?? '');
                if ($source !== '') {
                    $options = $item;
                    unset($options['file'], $options['url'], $options['src']);
                    $options['scope'] = $options['scope'] ?? $scope;
                    devone_register_module_asset($module, $type, $source, $options);
                }
            }
        }
    };

    if (isset($assets['css']) || isset($assets['js'])) {
        $registerList('frontend', 'css', $assets['css'] ?? array());
        $registerList('frontend', 'js', $assets['js'] ?? array());
    }
    foreach (array('frontend','admin','both') as $scope) {
        if (!isset($assets[$scope]) || !is_array($assets[$scope])) { continue; }
        $registerList($scope, 'css', $assets[$scope]['css'] ?? array());
        $registerList($scope, 'js', $assets[$scope]['js'] ?? array());
    }
}

function devone_module_render_assets($scope, $location) {
    $assets = array_values($GLOBALS['devone_module_assets'] ?? array());
    usort($assets, function($a, $b){ return ((int)($a['priority'] ?? 10)) <=> ((int)($b['priority'] ?? 10)); });
    foreach ($assets as $asset) {
        $assetScope = (string)($asset['scope'] ?? 'frontend');
        if ($assetScope !== 'both' && $assetScope !== $scope) { continue; }
        if (($asset['location'] ?? '') !== $location) { continue; }
        $url = htmlspecialchars((string)$asset['url'], ENT_QUOTES, 'UTF-8');
        if (($asset['type'] ?? 'js') === 'css') {
            echo '<link rel="stylesheet" href="' . $url . '">\n';
        } else {
            $attrs = array();
            if (!empty($asset['defer'])) { $attrs[] = 'defer'; }
            if (!empty($asset['async'])) { $attrs[] = 'async'; }
            if (!empty($asset['module_script'])) { $attrs[] = 'type="module"'; }
            if (!empty($asset['integrity'])) { $attrs[] = 'integrity="' . htmlspecialchars((string)$asset['integrity'], ENT_QUOTES, 'UTF-8') . '"'; }
            if (!empty($asset['crossorigin'])) { $attrs[] = 'crossorigin="' . htmlspecialchars((string)$asset['crossorigin'], ENT_QUOTES, 'UTF-8') . '"'; }
            echo '<script src="' . $url . '"' . ($attrs ? ' ' . implode(' ', $attrs) : '') . '></script>\n';
        }
    }
}

function devone_module_render_frontend_head_assets() { devone_module_render_assets('frontend', 'head'); }
function devone_module_render_frontend_footer_assets() { devone_module_render_assets('frontend', 'footer'); }
function devone_module_render_admin_head_assets() { devone_module_render_assets('admin', 'head'); }
function devone_module_render_admin_footer_assets() { devone_module_render_assets('admin', 'footer'); }

function devone_module_process_manifest($folder, $manifest) {
    foreach ((array)($manifest['admin_pages'] ?? array()) as $page) {
        if (!is_array($page)) { continue; }
        $slug = (string)($page['slug'] ?? 'overview');
        $file = (string)($page['file'] ?? '');
        if ($file === '') { continue; }
        devone_register_module_admin_page(
            $folder,
            $slug,
            (string)($page['title'] ?? ucwords(str_replace('-', ' ', $slug))),
            $file,
            (string)($page['permission'] ?? 'manage_modules'),
            (string)($page['menu_title'] ?? '')
        );
    }
    foreach ((array)($manifest['routes'] ?? array()) as $route) {
        if (!is_array($route)) { continue; }
        $path = (string)($route['path'] ?? '');
        $handler = (string)($route['file'] ?? $route['handler'] ?? '');
        if ($path === '' || $handler === '') { continue; }
        $options = $route;
        unset($options['path'], $options['file'], $options['handler'], $options['methods'], $options['method']);
        devone_register_module_route($folder, $path, $route['methods'] ?? ($route['method'] ?? 'GET'), $handler, $options);
    }
    devone_module_asset_definitions($folder, $manifest);
}

function devone_module_main_file($folder, $manifest) {
    $main = devone_module_resolve_file($folder, (string)($manifest['main'] ?? 'module.php'));
    if ($main !== '') { return $main; }
    $legacy = devone_module_resolve_file($folder, 'controller.php');
    return $legacy;
}

function devone_boot_module($folder) {
    $folder = devone_module_clean_slug($folder, '');
    if ($folder === '') { return false; }
    if (array_key_exists($folder, $GLOBALS['devone_loaded_modules'])) {
        return $GLOBALS['devone_loaded_modules'][$folder];
    }

    $error = '';
    if (!devone_module_validate($folder, $error)) {
        $GLOBALS['devone_loaded_modules'][$folder] = false;
        $GLOBALS['devone_module_load_errors'][$folder] = $error;
        devone_module_update_record($folder, array('status'=>'error','last_error'=>$error));
        return false;
    }

    $manifest = devone_module_manifest($folder, true);
    $main = devone_module_main_file($folder, $manifest);
    $GLOBALS['devone_loaded_modules'][$folder] = true;
    devone_module_process_manifest($folder, $manifest);

    try {
        ob_start();
        try {
            $result = require_once $main;
            $unexpectedOutput = (string)ob_get_clean();
        } catch (Throwable $mainError) {
            ob_end_clean();
            throw $mainError;
        }
        if ($unexpectedOutput !== '' && function_exists('devone_log')) { devone_log('module_boot_output', $folder . ': module main file produced output during boot'); }
        $instance = null;
        if (is_object($result)) { $instance = $result; }
        elseif (is_callable($result)) { $result = call_user_func($result, $manifest); if (is_object($result)) { $instance = $result; } }
        if ($instance && method_exists($instance, 'boot')) { $instance->boot(); }

        // Backward compatibility for early DevOne module scaffolds.
        $legacyCandidates = array(
            str_replace('-', '_', $folder) . '_boot',
            preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($manifest['name'] ?? '')) . '_boot',
        );
        foreach (array_unique($legacyCandidates) as $legacyFn) {
            if ($legacyFn !== '_boot' && function_exists($legacyFn)) { call_user_func($legacyFn); break; }
        }

        if ($instance) { $GLOBALS['devone_module_instances'][$folder] = $instance; }
        devone_module_update_record($folder, array('status'=>'active','last_error'=>''));
        if (function_exists('do_action')) { do_action('devone_module_loaded', $folder, $manifest, $instance); }
        return true;
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $GLOBALS['devone_loaded_modules'][$folder] = false;
        $GLOBALS['devone_module_load_errors'][$folder] = $message;
        devone_module_update_record($folder, array('status'=>'error','last_error'=>$message));
        if (function_exists('devone_log')) { devone_log('module_load_error', $folder . ': ' . $message); }
        return false;
    }
}

function devone_load_active_modules() {
    if (!function_exists('db') || !function_exists('table_exists') || !function_exists('table_name') || !table_exists('modules')) { return; }
    try {
        $stmt = db()->query('SELECT folder FROM `' . table_name('modules') . '` WHERE active=1 ORDER BY id ASC');
        $folders = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : array();
    } catch (Throwable $e) { $folders = array(); }
    foreach ($folders as $folder) { devone_boot_module($folder); }
    if (function_exists('do_action')) { do_action('devone_modules_loaded'); }
}

function devone_module_lifecycle_file($folder, $phase, $manifest) {
    $phase = devone_module_clean_slug($phase, '');
    if ($phase === '') { return ''; }
    $declared = (string)(($manifest['lifecycle'][$phase] ?? ''));
    if ($declared !== '') { return devone_module_resolve_file($folder, $declared); }
    return devone_module_resolve_file($folder, $phase . '.php');
}

function devone_module_run_lifecycle($folder, $phase, $context = array(), &$error = '') {
    $error = '';
    $manifest = devone_module_manifest($folder, true);
    if (!$manifest) { $error = 'Module manifest could not be loaded.'; return false; }
    $file = devone_module_lifecycle_file($folder, $phase, $manifest);
    if ($file === '') { return true; }
    try {
        ob_start();
        try {
            $result = (static function($file, $devone_module_context, $devone_module_manifest, $devone_module_phase) {
                return include $file;
            })($file, (array)$context, $manifest, $phase);
            $lifecycleOutput = (string)ob_get_clean();
        } catch (Throwable $lifecycleError) {
            ob_end_clean();
            throw $lifecycleError;
        }
        if ($lifecycleOutput !== '' && function_exists('devone_log')) { devone_log('module_' . $phase . '_output', $folder . ': lifecycle file produced output'); }
        if (is_callable($result)) { $result = call_user_func($result, (array)$context, $manifest); }
        if ($result === false) { throw new RuntimeException(ucfirst($phase) . ' lifecycle returned false.'); }
        if (function_exists('devone_log')) { devone_log('module_' . $phase, $folder); }
        return true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (function_exists('devone_log')) { devone_log('module_' . $phase . '_error', $folder . ': ' . $error); }
        return false;
    }
}

function devone_module_rcopy($source, $dest) {
    if (is_file($source)) {
        $parent = dirname($dest);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true)) { return false; }
        return @copy($source, $dest);
    }
    if (!is_dir($source)) { return false; }
    if (!is_dir($dest) && !@mkdir($dest, 0775, true)) { return false; }
    $items = scandir($source);
    if ($items === false) { return false; }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') { continue; }
        if (!devone_module_rcopy($source . DIRECTORY_SEPARATOR . $item, $dest . DIRECTORY_SEPARATOR . $item)) { return false; }
    }
    return true;
}

function devone_module_rrmdir($dir) {
    $base = realpath(devone_module_base_path());
    $real = realpath($dir);
    if (!$real || !$base || $real === $base || !devone_module_is_inside($real, $base)) { return false; }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) { @rmdir($item->getPathname()); }
        else { @unlink($item->getPathname()); }
    }
    return @rmdir($real);
}

function devone_module_find_package_root($dir, $depth = 5) {
    $dir = rtrim((string)$dir, '/\\');
    if ($dir === '' || !is_dir($dir)) { return ''; }
    if (is_file($dir . '/module.json')) { return $dir; }
    if ($depth <= 0) { return ''; }
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: array() as $child) {
        $found = devone_module_find_package_root($child, $depth - 1);
        if ($found !== '') { return $found; }
    }
    return '';
}

function devone_module_sync_disk() {
    $synced = 0;
    foreach (glob(rtrim(devone_module_base_path(), '/\\') . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
        $folder = devone_module_clean_slug(basename($dir), '');
        if ($folder === '') { continue; }
        $manifest = devone_module_manifest($folder, true);
        if (!$manifest) { continue; }
        $active = 0;
        try {
            $stmt = db()->prepare('SELECT active FROM `' . table_name('modules') . '` WHERE folder=? LIMIT 1');
            $stmt->execute(array($folder));
            $found = $stmt->fetchColumn();
            $active = $found === false ? 0 : (int)$found;
        } catch (Throwable $e) {}
        if (devone_module_upsert($folder, $manifest, $active, $active ? 'active' : 'installed')) { $synced++; }
    }
    return $synced;
}

function devone_create_module_starter($name, $description = '', &$error = '') {
    $error = '';
    $name = trim((string)$name);
    $slug = devone_module_clean_slug($name, '');
    if ($slug === '') { $error = 'Module name is required.'; return ''; }
    $dir = rtrim(devone_module_base_path(), '/\\') . DIRECTORY_SEPARATOR . $slug;
    if (is_dir($dir)) { $error = 'A module with this folder already exists.'; return ''; }
    $dirs = array($dir, $dir . '/admin', $dir . '/views', $dir . '/assets/css', $dir . '/assets/js');
    foreach ($dirs as $path) { if (!is_dir($path) && !@mkdir($path, 0775, true)) { $error = 'Could not create module folders.'; return ''; } }

    $class = str_replace(' ', '', ucwords(str_replace(array('-','_'), ' ', $slug))) . 'Module';
    $manifest = array(
        'name' => $name,
        'slug' => $slug,
        'version' => '1.0.0',
        'description' => (string)$description,
        'author' => 'Developer One CMS Developer',
        'license' => 'GPL-3.0-or-later',
        'main' => 'module.php',
        'admin_pages' => array(array('slug'=>'overview','title'=>$name,'menu_title'=>$name,'file'=>'admin/overview.php','permission'=>'manage_modules')),
        'routes' => array(array('path'=>$slug,'methods'=>array('GET'),'title'=>$name,'file'=>'views/index.php')),
        'assets' => array(
            'frontend' => array('css'=>array('assets/css/module.css'),'js'=>array(array('file'=>'assets/js/module.js','defer'=>true))),
            'admin' => array('css'=>array('assets/css/module.css')),
        ),
        'lifecycle' => array('install'=>'install.php','activate'=>'activate.php','deactivate'=>'deactivate.php','uninstall'=>'uninstall.php'),
    );
    $files = array(
        'module.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        'module.php' => "<?php\nfinal class {$class} {\n    public function boot(): void {\n        // Register extra hooks, routes, or services here.\n    }\n}\nreturn new {$class}();\n",
        'admin/overview.php' => "<?php\n?><div class=\"card\"><h1>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</h1><p>This is a live DevOne module admin page. Edit <code>admin/overview.php</code> to build your application.</p><p><a class=\"btn\" href=\"../" . rawurlencode($slug) . "\" target=\"_blank\" rel=\"noopener\">Open front-end route</a></p></div>\n",
        'views/index.php' => "<?php\nreturn array(\n    'title' => " . var_export($name, true) . ",\n    'content' => '<section class=\"devone-module-starter\"><h1>" . addslashes(htmlspecialchars($name, ENT_QUOTES, 'UTF-8')) . "</h1><p>Your DevOne module route is active and ready for development.</p></section>'\n);\n",
        'assets/css/module.css' => ".devone-module-starter{max-width:960px;margin:80px auto;padding:42px;border:1px solid rgba(17,17,17,.12);border-radius:24px;background:#fff;box-shadow:0 24px 70px rgba(0,0,0,.08)}\n",
        'assets/js/module.js' => "document.addEventListener('devone:ready',function(){console.debug('DevOne module ready: " . addslashes($slug) . "');});\n",
        'install.php' => "<?php\n// Create module tables or default settings here. Return false to cancel installation.\nreturn true;\n",
        'activate.php' => "<?php\nreturn true;\n",
        'deactivate.php' => "<?php\nreturn true;\n",
        'uninstall.php' => "<?php\n// Use !empty(\$devone_module_context['purge_data']) before deleting persistent module data.\nreturn true;\n",
    );
    foreach ($files as $relative => $content) {
        if (@file_put_contents($dir . '/' . $relative, $content) === false) { devone_module_rrmdir($dir); $error = 'Could not write module starter files.'; return ''; }
    }
    $GLOBALS['devone_module_manifests'][$slug] = $manifest;
    devone_module_upsert($slug, $manifest, 0, 'installed');
    $lifeError = '';
    if (!devone_module_run_lifecycle($slug, 'install', array('new_install'=>true), $lifeError)) {
        devone_module_update_record($slug, array('status'=>'error','last_error'=>$lifeError));
        $error = 'Module files were created, but install.php failed: ' . $lifeError;
        return $slug;
    }
    return $slug;
}

if (function_exists('add_action')) {
    add_action('devone_head', 'devone_module_render_frontend_head_assets');
    add_action('devone_footer', 'devone_module_render_frontend_footer_assets');
    add_action('devone_admin_head', 'devone_module_render_admin_head_assets');
    add_action('devone_admin_footer', 'devone_module_render_admin_footer_assets');
}
