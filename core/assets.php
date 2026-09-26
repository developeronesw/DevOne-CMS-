<?php
/**
 * DevOne library runtime.
 * Loads active local/CDN CSS and JavaScript assets in deterministic order.
 */

if (!function_exists('devone_library_json')) {
    function devone_library_json($value, $default = array()) {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return $default;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }
}

if (!function_exists('devone_library_safe_relative_path')) {
    function devone_library_safe_relative_path($path) {
        $path = str_replace('\\', '/', trim((string)$path));
        if ($path === '' || strpos($path, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $path)) return '';
        return ltrim($path, '/');
    }
}

if (!function_exists('devone_library_manifest_from_dir')) {
    function devone_library_manifest_from_dir($baseDir, $type = '') {
        foreach (array('library.json', 'devone-library.json') as $manifestName) {
            $file = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $manifestName;
            if (!is_file($file)) continue;
            $decoded = json_decode((string)file_get_contents($file), true);
            if (is_array($decoded)) return $decoded;
        }

        $assets = array();
        if (!is_dir($baseDir)) return array('assets' => $assets);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, array('css', 'js', 'mjs'), true)) continue;
            if ($type === 'css' && $ext !== 'css') continue;
            if ($type === 'js' && !in_array($ext, array('js', 'mjs'), true)) continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($baseDir, '/\\')) + 1));
            if (preg_match('#(^|/)(src|source|node_modules|tests?|examples?)/#i', $rel)) continue;
            $assets[] = array(
                'path' => $rel,
                'type' => $ext === 'css' ? 'css' : 'js',
                'order' => devone_library_asset_priority($rel),
                'defer' => $ext !== 'css',
                'module' => $ext === 'mjs',
            );
        }
        usort($assets, function ($a, $b) {
            $cmp = ((int)($a['order'] ?? 1000)) <=> ((int)($b['order'] ?? 1000));
            return $cmp ?: strcmp((string)$a['path'], (string)$b['path']);
        });
        return array('assets' => $assets);
    }
}

if (!function_exists('devone_library_asset_priority')) {
    function devone_library_asset_priority($path) {
        $name = strtolower(basename((string)$path));
        $priority = array(
            'bootstrap.min.css' => 10, 'bootstrap.css' => 11,
            'tailwind.min.css' => 12, 'tailwind.css' => 13,
            'normalize.css' => 20, 'reset.css' => 21,
            'style.css' => 40, 'main.css' => 41, 'app.css' => 42, 'index.css' => 43,
            'jquery.min.js' => 100, 'jquery.js' => 101,
            'bootstrap.bundle.min.js' => 120, 'bootstrap.bundle.js' => 121,
            'bootstrap.min.js' => 122, 'bootstrap.js' => 123,
            'main.js' => 160, 'app.js' => 161, 'index.js' => 162,
        );
        return $priority[$name] ?? 500;
    }
}

if (!function_exists('devone_library_scope_matches')) {
    function devone_library_scope_matches($scope, $context) {
        $scope = strtolower(trim((string)$scope));
        if ($scope === '' || $scope === 'global' || $scope === 'both') return true;
        return $scope === $context;
    }
}

if (!function_exists('devone_library_assets')) {
    function devone_library_assets($lib, $context = 'frontend') {
        if (!devone_library_scope_matches($lib['scope'] ?? 'frontend', $context)) return array();
        $source = strtolower((string)($lib['source'] ?? 'local'));
        $type = strtolower((string)($lib['type'] ?? 'js'));
        $manifest = devone_library_json($lib['manifest'] ?? '', array());

        if ($source === 'cdn') {
            $url = trim((string)($lib['url'] ?? $lib['folder'] ?? ''));
            if (!preg_match('#^https://#i', $url)) return array();
            return array(array(
                'url' => $url,
                'type' => $type === 'css' ? 'css' : 'js',
                'order' => (int)($lib['sort_order'] ?? 100),
                'defer' => !empty($lib['defer_load']),
                'async' => !empty($lib['async_load']),
                'module' => !empty($lib['module_script']),
                'integrity' => trim((string)($lib['integrity'] ?? '')),
                'crossorigin' => trim((string)($lib['crossorigin'] ?? 'anonymous')),
                'placement' => $type === 'css' ? 'head' : 'footer',
            ));
        }

        $folder = devone_library_safe_relative_path($lib['folder'] ?? '');
        if ($folder === '') return array();
        $baseDir = dirname(__DIR__) . '/content/libraries/' . $folder;
        $baseUrl = rtrim(SITE_URL, '/') . '/content/libraries/' . rawurlencode($folder);
        if (!is_dir($baseDir)) return array();
        if (!$manifest) $manifest = devone_library_manifest_from_dir($baseDir, $type);
        $items = isset($manifest['assets']) && is_array($manifest['assets']) ? $manifest['assets'] : array();
        $out = array();
        foreach ($items as $index => $asset) {
            if (is_string($asset)) $asset = array('path' => $asset);
            if (!is_array($asset)) continue;
            $path = devone_library_safe_relative_path($asset['path'] ?? $asset['file'] ?? '');
            if ($path === '') continue;
            $full = $baseDir . '/' . $path;
            if (!is_file($full)) continue;
            $assetType = strtolower((string)($asset['type'] ?? pathinfo($path, PATHINFO_EXTENSION)));
            $assetType = $assetType === 'css' ? 'css' : 'js';
            if ($type === 'css' && $assetType !== 'css') continue;
            if ($type === 'js' && $assetType !== 'js') continue;
            $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
            $out[] = array(
                'url' => $baseUrl . '/' . $encoded,
                'type' => $assetType,
                'order' => (int)($asset['order'] ?? devone_library_asset_priority($path)),
                'defer' => array_key_exists('defer', $asset) ? (bool)$asset['defer'] : ($assetType === 'js'),
                'async' => !empty($asset['async']),
                'module' => !empty($asset['module']) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mjs',
                'integrity' => trim((string)($asset['integrity'] ?? '')),
                'crossorigin' => trim((string)($asset['crossorigin'] ?? 'anonymous')),
                'placement' => strtolower((string)($asset['placement'] ?? ($assetType === 'css' ? 'head' : 'footer'))) === 'head' ? 'head' : 'footer',
                '_index' => $index,
            );
        }
        usort($out, function ($a, $b) {
            $cmp = ((int)$a['order']) <=> ((int)$b['order']);
            return $cmp ?: ((int)($a['_index'] ?? 0) <=> (int)($b['_index'] ?? 0));
        });
        return $out;
    }
}

if (!function_exists('devone_render_library_asset')) {
    function devone_render_library_asset($asset) {
        $url = htmlspecialchars((string)$asset['url'], ENT_QUOTES, 'UTF-8');
        $integrity = trim((string)($asset['integrity'] ?? ''));
        $crossorigin = trim((string)($asset['crossorigin'] ?? 'anonymous'));
        $security = '';
        if ($integrity !== '') {
            $security .= ' integrity="' . htmlspecialchars($integrity, ENT_QUOTES, 'UTF-8') . '"';
            $security .= ' crossorigin="' . htmlspecialchars($crossorigin ?: 'anonymous', ENT_QUOTES, 'UTF-8') . '"';
        }
        if (($asset['type'] ?? '') === 'css') return '<link rel="stylesheet" href="' . $url . '"' . $security . '>' . "\n";
        $attrs = !empty($asset['module']) ? ' type="module"' : '';
        if (!empty($asset['async'])) $attrs .= ' async';
        elseif (!empty($asset['defer'])) $attrs .= ' defer';
        return '<script src="' . $url . '"' . $attrs . $security . '></script>' . "\n";
    }
}

if (!function_exists('devone_enqueue_libraries')) {
    function devone_enqueue_libraries($context = 'frontend', $placement = 'head') {
        if (!function_exists('table_exists') || !table_exists('libraries')) return;
        $order = function_exists('devone_table_columns') && in_array('sort_order', devone_table_columns('libraries'), true)
            ? 'sort_order ASC, id ASC' : 'type ASC, name ASC';
        $libs = db()->query('SELECT * FROM `' . table_name('libraries') . '` WHERE active=1 ORDER BY ' . $order)->fetchAll(PDO::FETCH_ASSOC);
        $seen = array();
        foreach ($libs as $lib) {
            foreach (devone_library_assets($lib, $context) as $asset) {
                if (($asset['placement'] ?? 'head') !== $placement) continue;
                $key = strtolower((string)$asset['type'] . '|' . (string)$asset['url']);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                echo devone_render_library_asset($asset);
            }
        }
    }
}

function enqueue_global_libraries() { devone_enqueue_libraries('frontend', 'head'); }
function enqueue_global_libraries_footer() { devone_enqueue_libraries('frontend', 'footer'); }
add_action('devone_head', 'enqueue_global_libraries');
add_action('devone_footer', 'enqueue_global_libraries_footer');
