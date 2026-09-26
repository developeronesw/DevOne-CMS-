<?php
/* DevOneCMS v1.1.16 Locked Official Marketplace */

if (!function_exists('devone_store_slug')) {
    function devone_store_slug($value, $fallback = 'package') {
        if (function_exists('devone_slugify')) { return devone_slugify($value, $fallback); }
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value);
        $value = trim($value, '-');
        return $value !== '' ? $value : $fallback;
    }
}

function devone_store_base_path() {
    return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
}

function devone_store_cache_dir() {
    $dir = devone_store_base_path() . '/storage/cache/store';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}

function devone_store_ensure_writable_dir($dir, $label = 'folder', &$error = '') {
    $error = '';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        $error = 'Could not create required folder: ' . $label;
        return false;
    }
    @chmod($dir, 0775);
    if (!is_writable($dir)) {
        $error = 'Required folder is not writable by PHP: ' . $label . '. Fix server ownership/permissions.';
        return false;
    }
    return true;
}



function devone_store_official_repo_url() {
    return 'https://marketplace.devonecms.com/';
}

function devone_store_official_manifest_url() {
    return 'https://marketplace.devonecms.com/api/manifest.php';
}

function devone_store_official_public_url() {
    return 'https://marketplace.devonecms.com/';
}

function devone_store_official_submit_url() {
    return 'https://marketplace.devonecms.com/author/register.php';
}

function devone_store_locked_marketplace_config() {
    return array(
        'manifest_url' => devone_store_official_manifest_url(),
        'public_url' => devone_store_official_public_url(),
        'submit_url' => devone_store_official_submit_url(),
    );
}

function devone_store_enforce_official_marketplace_settings() {
    if (!function_exists('set_setting')) { return; }
    $locked = devone_store_locked_marketplace_config();
    try {
        set_setting('devone_marketplace_manifest_url', $locked['manifest_url']);
        set_setting('devone_store_public_url', $locked['public_url']);
        set_setting('devone_store_submit_url', $locked['submit_url']);
    } catch (Throwable $e) {}
}

function devone_store_normalize_manifest_source($source) {
    $source = trim((string)$source);
    if ($source === '') { return ''; }

    // Allow users to paste a GitHub repo URL instead of the final manifest URL.
    // Prefer GitHub Pages when available so thumbnails, relative package URLs, and the public storefront share one base URL.
    $repo = function_exists('devone_github_parse_repo') ? devone_github_parse_repo($source) : null;
    if ($repo && preg_match('/github\.com/i', $source)) {
        return 'https://' . strtolower($repo['owner']) . '.github.io/' . rawurlencode($repo['repo']) . '/manifest.json';
    }

    // Allow users to paste a GitHub Pages folder URL and auto-append manifest.json.
    if (preg_match('/^https?:\/\/[^\/]+\.github\.io\/[^\/]+\/?$/i', $source)) {
        return rtrim($source, '/') . '/manifest.json';
    }

    return $source;
}

function devone_store_manifest_source_candidates($source) {
    $source = trim((string)$source);
    if ($source === '') { return array(); }

    $repo = function_exists('devone_github_parse_repo') ? devone_github_parse_repo($source) : null;
    if ($repo && preg_match('/github\.com/i', $source)) {
        $owner = $repo['owner'];
        $repoName = $repo['repo'];
        return array_values(array_unique(array(
            'https://' . strtolower($owner) . '.github.io/' . rawurlencode($repoName) . '/manifest.json',
            'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repoName) . '/main/manifest.json',
            'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repoName) . '/master/manifest.json',
        )));
    }

    if (preg_match('/^https?:\/\/[^\/]+\.github\.io\/[^\/]+\/?$/i', $source)) {
        return array(rtrim($source, '/') . '/manifest.json');
    }

    return array($source);
}


function devone_store_url_base($source) {
    $source = trim((string)$source);
    if ($source === '' || !devone_store_is_url($source)) { return ''; }
    $parts = parse_url($source);
    if (empty($parts['scheme']) || empty($parts['host'])) { return ''; }
    $path = $parts['path'] ?? '/';
    if (substr($path, -1) !== '/') { $path = preg_replace('#/[^/]*$#', '/', $path); }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port . $path;
}

function devone_store_resolve_url($url, $source = '') {
    $url = trim((string)$url);
    if ($url === '') { return ''; }
    if (preg_match('/^(https?:|data:)/i', $url)) { return $url; }
    if (strpos($url, '//') === 0) { return 'https:' . $url; }
    if ($url[0] === '/') { return $url; }
    $base = devone_store_url_base($source);
    if ($base !== '') { return $base . ltrim($url, '/'); }
    return $url;
}

function devone_store_admin_asset_url($url, $source = '') {
    $resolved = devone_store_resolve_url($url, $source);
    if ($resolved === '') { return ''; }
    if (preg_match('/^(https?:|data:|\/)/i', $resolved)) { return $resolved; }
    return '../' . ltrim($resolved, '/');
}

function devone_store_default_manifest_path() {
    return devone_store_base_path() . '/content/marketplace/devone-store.json';
}

function devone_store_default_manifest_url() {
    // Locked production marketplace: do not allow local/admin override.
    if (function_exists('devone_store_enforce_official_marketplace_settings')) { devone_store_enforce_official_marketplace_settings(); }
    return devone_store_official_manifest_url();
}

function devone_store_is_url($value) {
    return (bool)preg_match('/^https?:\/\//i', (string)$value);
}

function devone_store_allowed_url($url) {
    $url = trim((string)$url);
    if (!devone_store_is_url($url)) { return false; }
    $parts = parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) { return false; }
    return strtolower((string)$parts['scheme']) === 'https';
}

function devone_store_stream_get_https($url, $timeout = 25, $userAgent = 'DevOneCMS Marketplace', &$error = '') {
    $error = '';
    $current = trim((string)$url);
    for ($redirects = 0; $redirects <= 5; $redirects++) {
        if (!devone_store_allowed_url($current)) { $error = 'Remote marketplace URLs must use HTTPS.'; return false; }
        $context = stream_context_create(array(
            'http' => array('timeout'=>(int)$timeout,'follow_location'=>0,'ignore_errors'=>true,'header'=>'User-Agent: '.str_replace(array("\r","\n"),'',(string)$userAgent)."\r\n"),
            'ssl' => array('verify_peer'=>true,'verify_peer_name'=>true),
        ));
        $body = @file_get_contents($current, false, $context);
        $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : array();
        $status = 0;
        if (!empty($headers[0]) && preg_match('#\s([0-9]{3})(?:\s|$)#', (string)$headers[0], $m)) { $status=(int)$m[1]; }
        if ($body !== false && $status >= 200 && $status < 300) { return $body; }
        if ($status >= 300 && $status < 400 && $redirects < 5) {
            $location=''; foreach($headers as $header){ if(stripos((string)$header,'Location:')===0){$location=trim(substr((string)$header,9));break;} }
            if ($location !== '') {
                if (strpos($location,'//')===0) { $location='https:'.$location; }
                elseif (strpos($location,'/')===0) { $parts=parse_url($current);$location='https://'.(string)($parts['host']??'').(!empty($parts['port'])?':'.(int)$parts['port']:'').$location; }
                if (!devone_store_allowed_url($location)) { $error='Marketplace redirect was blocked because it did not use HTTPS.'; return false; }
                $current=$location; continue;
            }
        }
        $error = 'HTTP error loading marketplace resource' . ($status ? ': '.$status : '.');
        return false;
    }
    $error='Marketplace request exceeded the redirect limit.'; return false;
}

function devone_store_fetch_text($source, &$error = '') {
    $source = trim((string)$source);
    $error = '';
    if ($source === '') { $error = 'No marketplace source provided.'; return ''; }

    $candidates = devone_store_manifest_source_candidates($source);
    if (count($candidates) > 1) {
        $lastError = '';
        foreach ($candidates as $candidate) {
            $candidateError = '';
            $body = devone_store_fetch_text($candidate, $candidateError);
            if ($body !== '') { return $body; }
            if ($candidateError !== '') { $lastError = $candidateError; }
        }
        $error = 'Could not load the locked DevOne Marketplace manifest. Check marketplace.devonecms.com and the API endpoint. Last error: ' . $lastError;
        return '';
    }
    if (count($candidates) === 1) { $source = $candidates[0]; }

    if (!devone_store_is_url($source)) {
        $path = $source;
        if (!is_file($path)) {
            $path = devone_store_base_path() . '/' . ltrim($source, '/');
        }
        if (!is_file($path)) { $error = 'Local marketplace manifest was not found: ' . $source; return ''; }
        return (string)file_get_contents($path);
    }

    if (!devone_store_allowed_url($source)) { $error = 'Marketplace source URL must use HTTPS.'; return ''; }

    if (function_exists('curl_init')) {
        $ch = curl_init($source);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'DevOneCMS/1.1.21 Marketplace Loader',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            CURLOPT_REDIR_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            $error = $curlError ?: 'HTTP error loading marketplace manifest: ' . $status;
            return '';
        }
        if (strlen((string)$body) > 5242880) { $error='Marketplace manifest exceeded the 5 MB safety limit.'; return ''; }
        return (string)$body;
    }

    $body = devone_store_stream_get_https($source, 25, 'DevOneCMS/1.7.4 Marketplace Loader', $error);
    if ($body === false) { if ($error === '') { $error='Unable to load remote marketplace manifest. Enable cURL or allow_url_fopen.'; } return ''; }
    if (strlen((string)$body) > 5242880) { $error='Marketplace manifest exceeded the 5 MB safety limit.'; return ''; }
    return (string)$body;
}

function devone_store_load_manifest($source = '', &$error = '') {
    $source = trim((string)$source);
    if ($source === '') { $source = devone_store_default_manifest_url(); }
    $source = devone_store_normalize_manifest_source($source);
    $fetchError = '';
    $raw = devone_store_fetch_text($source, $fetchError);

    // During marketplace setup, keep the admin useful if the remote repo exists but manifest.json has not been pushed yet.
    if ($raw === '' && devone_store_is_url($source)) {
        $localPath = devone_store_default_manifest_path();
        $localError = '';
        $localRaw = is_file($localPath) ? devone_store_fetch_text($localPath, $localError) : '';
        if ($localRaw !== '') {
            $raw = $localRaw;
            $error = trim(($fetchError ? $fetchError . ' ' : '') . 'Showing bundled local marketplace until the remote manifest is available.');
        } else {
            $error = $fetchError ?: $localError;
        }
    } else {
        $error = $fetchError;
    }

    if ($raw === '') { return array('items' => array(), 'source' => $source); }
    $data = json_decode($raw, true);
    if (!is_array($data)) { $error = 'Marketplace manifest is not valid JSON.'; return array('items' => array(), 'source' => $source); }
    if (!isset($data['items']) || !is_array($data['items'])) { $data['items'] = array(); }
    $data['source'] = $source;
    $data['_manifest_source'] = $source;
    $data['_manifest_base_url'] = devone_store_url_base($source);
    foreach ($data['items'] as $idx => $item) {
        if (is_array($item)) {
            $item['_manifest_source'] = $source;
            $data['items'][$idx] = $item;
        }
    }
    return $data;
}

function devone_store_download_file($source, $dest, &$error = '') {
    $error = '';
    $source = trim((string)$source);
    if ($source === '') { $error = 'Package URL is missing.'; return false; }
    if (!is_dir(dirname($dest))) { @mkdir(dirname($dest), 0775, true); }

    if (!devone_store_is_url($source)) {
        $path = $source;
        if (!is_file($path)) { $path = devone_store_base_path() . '/' . ltrim($source, '/'); }
        if (!is_file($path)) { $error = 'Local package file was not found: ' . $source; return false; }
        return @copy($path, $dest);
    }

    if (!devone_store_allowed_url($source)) { $error = 'Remote package URL must use HTTPS.'; return false; }

    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) { $error = 'Unable to write package to cache. Check storage/cache permissions.'; return false; }
        $ch = curl_init($source);
        curl_setopt_array($ch, array(
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'DevOneCMS/1.1.14 Package Installer',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            CURLOPT_REDIR_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
        ));
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $status >= 400 || !is_file($dest) || filesize($dest) < 20) {
            @unlink($dest);
            $error = $curlError ?: 'HTTP error downloading package: ' . $status;
            return false;
        }
        $maxPackage=function_exists('devone_package_limits')?(int)(devone_package_limits()['max_archive_bytes']??104857600):104857600;
        if((int)filesize($dest)>$maxPackage){@unlink($dest);$error='Marketplace package exceeded the allowed archive size.';return false;}
        return true;
    }

    $body = devone_store_stream_get_https($source, 120, 'DevOneCMS/1.7.4 Package Installer', $error);
    if ($body === false || strlen((string)$body) < 20) { if($error==='')$error='Unable to download package. Enable cURL or allow_url_fopen.'; return false; }
    $maxPackage=function_exists('devone_package_limits')?(int)(devone_package_limits()['max_archive_bytes']??104857600):104857600;
    if(strlen((string)$body)>$maxPackage){$error='Marketplace package exceeded the allowed archive size.';return false;}
    return @file_put_contents($dest, $body, LOCK_EX) !== false;
}

function devone_store_rrmdir($dir) {
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
    @rmdir($dir);
}

function devone_store_rcopy($src, $dst) {
    if (!is_dir($src)) { return false; }
    if (!is_dir($dst)) { @mkdir($dst, 0775, true); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
        if ($item->isDir()) { if (!is_dir($target)) { @mkdir($target, 0775, true); } }
        else { @copy($item->getPathname(), $target); }
    }
    return true;
}

function devone_store_find_package_root($dir, $type) {
    $type = strtolower((string)$type);
    $markers = array(
        'theme' => array('theme.css', 'theme.json'),
        'plugin' => array('plugin.json', 'plugin.php'),
        'library' => array('package.json', 'dist', 'src'),
    );
    if (is_file($dir . '/theme.css') && ($type === 'auto' || $type === 'theme')) { return array($dir, 'theme'); }
    if ((is_file($dir . '/plugin.json') || is_file($dir . '/plugin.php')) && ($type === 'auto' || $type === 'plugin')) { return array($dir, 'plugin'); }

    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: array() as $child) {
        if (is_file($child . '/theme.css') && ($type === 'auto' || $type === 'theme')) { return array($child, 'theme'); }
        if ((is_file($child . '/plugin.json') || is_file($child . '/plugin.php')) && ($type === 'auto' || $type === 'plugin')) { return array($child, 'plugin'); }
    }

    if ($type === 'library' || $type === 'auto') {
        $candidates = array_merge(array($dir), (glob($dir . '/*', GLOB_ONLYDIR) ?: array()));
        foreach ($candidates as $candidate) {
            $hasAsset = false;
            $scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS));
            foreach ($scan as $file) {
                if (!$file->isFile()) { continue; }
                $ext = strtolower($file->getExtension());
                if (in_array($ext, array('css','js','mjs'), true)) { $hasAsset = true; break; }
            }
            if ($hasAsset) { return array($candidate, 'library'); }
        }
    }

    return array('', $type === 'auto' ? '' : $type);
}

function devone_store_detect_library_types($dir) {
    $types = array();
    if (!is_dir($dir)) { return array('js'); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) { continue; }
        $ext = strtolower($file->getExtension());
        if ($ext === 'css') { $types['css'] = true; }
        if ($ext === 'js' || $ext === 'mjs') { $types['js'] = true; }
    }
    return $types ? array_keys($types) : array('js');
}

function devone_store_insert_library($name, $folderOrUrl, $type = 'js', $source = 'local', $active = 1, $description = '') {
    $tbl = devone_require_table('libraries', true);
    if ($tbl === '') { return false; }
    $cols = function_exists('devone_table_columns') ? devone_table_columns('libraries') : array('name','folder','type','source','active');
    $payload = array(
        'name' => $name,
        'folder' => $folderOrUrl,
        'type' => in_array($type, array('css','js'), true) ? $type : 'js',
        'source' => $source === 'cdn' ? 'cdn' : 'local',
        'active' => (int)$active,
        'url' => devone_store_is_url($folderOrUrl) ? $folderOrUrl : '',
        'description' => $description,
    );
    try {
        $check = db()->prepare('SELECT id FROM `' . $tbl . '` WHERE name=? AND folder=? AND type=? LIMIT 1');
        $check->execute(array($payload['name'], $payload['folder'], $payload['type']));
        $existingId = (int)$check->fetchColumn();
        if ($existingId > 0) {
            $fields = array_values(array_intersect(array_keys($payload), $cols));
            $sets = array(); $values = array();
            foreach ($fields as $field) { if ($field === 'name' || $field === 'folder' || $field === 'type') { continue; } $sets[] = '`' . $field . '`=?'; $values[] = $payload[$field]; }
            if ($sets) { $values[] = $existingId; db()->prepare('UPDATE `' . $tbl . '` SET ' . implode(',', $sets) . ' WHERE id=?')->execute($values); }
            return true;
        }
        $fields = array_values(array_intersect(array_keys($payload), $cols));
        $sql = 'INSERT INTO `' . $tbl . '` (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
        $values = array(); foreach ($fields as $field) { $values[] = $payload[$field]; }
        return db()->prepare($sql)->execute($values);
    } catch (Exception $e) { return false; }
}

function devone_store_insert_plugin($name, $folder, $version = '1.0.0', $description = '', $active = 1) {
    $tbl = devone_require_table('plugins', true);
    if ($tbl === '') { return false; }
    try {
        $check = db()->prepare('SELECT id FROM `' . $tbl . '` WHERE folder=? LIMIT 1');
        $check->execute(array($folder));
        $id = (int)$check->fetchColumn();
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE `' . $tbl . '` SET name=?, version=?, description=?, active=? WHERE id=?');
            return $stmt->execute(array($name, $version, $description, (int)$active, $id));
        }
        $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (name,folder,version,description,active) VALUES (?,?,?,?,?)');
        return $stmt->execute(array($name, $folder, $version, $description, (int)$active));
    } catch (Exception $e) { return false; }
}

function devone_store_install_cdn_item($item) {
    $assets = array();
    if (!empty($item['assets']) && is_array($item['assets'])) { $assets = $item['assets']; }
    elseif (!empty($item['cdn'])) { $assets[] = array('url' => $item['cdn'], 'type' => $item['asset_type'] ?? $item['type'] ?? 'js'); }
    elseif (!empty($item['url'])) { $assets[] = array('url' => $item['url'], 'type' => $item['asset_type'] ?? 'js'); }
    if (!empty($item['css']) && is_array($item['css'])) { foreach ($item['css'] as $url) { $assets[] = array('url' => $url, 'type' => 'css'); } }
    if (!empty($item['js']) && is_array($item['js'])) { foreach ($item['js'] as $url) { $assets[] = array('url' => $url, 'type' => 'js'); } }
    if (!$assets) { return array('ok'=>false, 'message'=>'No CDN assets were defined for this item.'); }

    $name = trim((string)($item['name'] ?? 'CDN Asset'));
    foreach ($assets as $index => $asset) {
        $url = trim((string)($asset['url'] ?? ''));
        if (!devone_store_allowed_url($url)) { continue; }
        $type = strtolower((string)($asset['type'] ?? 'js')) === 'css' ? 'css' : 'js';
        $label = trim((string)($asset['label'] ?? '')) ?: ($name . ($type === 'css' ? ' CSS' : ' JS'));
        devone_store_insert_library($label, $url, $type, 'cdn', 1, (string)($item['description'] ?? $item['desc'] ?? ''));
    }
    if (function_exists('devone_log')) { devone_log('marketplace_cdn_installed', $name); }
    return array('ok'=>true, 'message'=>$name . ' CDN assets installed.', 'package_type'=>'cdn', 'folder'=>'', 'name'=>$name);
}

function devone_github_parse_repo($repoUrl) {
    $repoUrl = trim((string)$repoUrl);
    if (preg_match('/github\.com[\/:]([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)(?:\.git)?(?:\/.*)?$/i', $repoUrl, $m)) {
        return array('owner' => $m[1], 'repo' => preg_replace('/\.git$/', '', $m[2]));
    }
    if (preg_match('/^([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)$/', $repoUrl, $m)) {
        return array('owner' => $m[1], 'repo' => $m[2]);
    }
    return null;
}

function devone_github_zip_url($repoUrl, $ref = 'main') {
    $repo = devone_github_parse_repo($repoUrl);
    if (!$repo) { return ''; }
    $ref = trim((string)$ref) ?: 'main';
    $ref = preg_replace('/[^A-Za-z0-9._\-\/]/', '', $ref);
    return 'https://codeload.github.com/' . rawurlencode($repo['owner']) . '/' . rawurlencode($repo['repo']) . '/zip/refs/heads/' . str_replace('%2F', '/', rawurlencode($ref));
}


function devone_store_read_json_file($path) {
    if (!is_file($path)) { return array(); }
    $json = json_decode((string)file_get_contents($path), true);
    return is_array($json) ? $json : array();
}

function devone_store_package_manifest($root, $detectedType, $item) {
    $manifest = array(
        'name' => trim((string)($item['name'] ?? 'DevOne Package')),
        'description' => (string)($item['description'] ?? $item['desc'] ?? ''),
        'version' => (string)($item['version'] ?? '1.0.0'),
        'slug' => (string)($item['slug'] ?? ''),
        'folder' => (string)($item['install_folder'] ?? $item['folder'] ?? ''),
        'id' => '',
    );
    $root = rtrim((string)$root, '/\\');
    if ($detectedType === 'theme') {
        $json = devone_store_read_json_file($root . '/theme.json');
        if ($json) { $manifest = array_merge($manifest, $json); }
    }
    if ($detectedType === 'plugin') {
        $json = devone_store_read_json_file($root . '/plugin.json');
        if ($json) { $manifest = array_merge($manifest, $json); }
    }
    if ($detectedType === 'library') {
        $json = devone_store_read_json_file($root . '/package.json');
        if ($json) { $manifest = array_merge($manifest, array_intersect_key($json, array('name'=>1,'description'=>1,'version'=>1))); }
    }
    return $manifest;
}

function devone_store_repo_name_from_item($item) {
    if (empty($item['repo'])) { return ''; }
    $parsed = devone_github_parse_repo($item['repo']);
    return $parsed ? $parsed['repo'] : '';
}

function devone_store_zip_name_slug($zipSource) {
    $path = parse_url((string)$zipSource, PHP_URL_PATH);
    $base = basename($path ?: (string)$zipSource);
    $base = preg_replace('/\.zip$/i', '', $base);
    $base = preg_replace('/^(devonecms[-_ ]*)?(theme|plugin|library|package)[-_ ]*/i', '', $base);
    return devone_store_slug($base, 'package');
}

function devone_store_auto_folder_slug($item, $manifest, $root, $detectedType, $zipSource) {
    // Explicit marketplace/install values always win.
    foreach (array('install_folder', 'folder', 'slug') as $key) {
        if (!empty($item[$key])) { return devone_store_slug($item[$key], 'package'); }
    }
    // Then package metadata.
    foreach (array('slug', 'folder', 'id') as $key) {
        if (!empty($manifest[$key])) { return devone_store_slug($manifest[$key], 'package'); }
    }
    if (!empty($manifest['name'])) { return devone_store_slug($manifest['name'], 'package'); }

    // GitHub codeload ZIPs usually extract to repo-branch. Prefer the repo name.
    $repoName = devone_store_repo_name_from_item($item);
    if ($repoName !== '') { return devone_store_slug($repoName, 'package'); }

    // Local or Release asset filename is usually useful.
    $zipSlug = devone_store_zip_name_slug($zipSource);
    if ($zipSlug !== 'package') { return $zipSlug; }

    $base = basename(rtrim((string)$root, '/\\'));
    if ($base !== '' && stripos($base, 'extract-') !== 0 && stripos($base, 'package-') !== 0) { return devone_store_slug($base, 'package'); }

    return devone_store_slug($item['name'] ?? ($detectedType . '-package'), 'package');
}


function devone_store_setting($key, $default = '') {
    return function_exists('get_setting') ? get_setting($key, $default) : $default;
}


function devone_store_bridge_secret_file() {
    return devone_store_base_path() . '/storage/security/marketplace-bridge.php';
}

function devone_store_read_bridge_secret_file() {
    $file = devone_store_bridge_secret_file();
    if (!is_file($file)) { return ''; }
    $data = @include $file;
    if (is_array($data) && !empty($data['secret'])) { return trim((string)$data['secret']); }
    return '';
}

function devone_store_write_bridge_secret_file($secret) {
    $secret = trim((string)$secret);
    if ($secret === '') { return false; }
    $dir = dirname(devone_store_bridge_secret_file());
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir) || !is_writable($dir)) { return false; }
    $php = "<?php\nreturn " . var_export(array('secret'=>$secret, 'created_at'=>gmdate('c')), true) . ";\n";
    $ok = @file_put_contents(devone_store_bridge_secret_file(), $php, LOCK_EX);
    if ($ok !== false) { @chmod(devone_store_bridge_secret_file(), 0640); return true; }
    return false;
}

function devone_store_marketplace_api_secret() {
    if (defined('DEVONE_MARKETPLACE_API_SECRET') && trim((string)DEVONE_MARKETPLACE_API_SECRET) !== '') { return trim((string)DEVONE_MARKETPLACE_API_SECRET); }

    // Prefer a hidden server-side file so public/customer installs do not need manually pasted bridge secrets.
    $fileSecret = function_exists('devone_store_read_bridge_secret_file') ? devone_store_read_bridge_secret_file() : '';
    if ($fileSecret !== '') { return $fileSecret; }

    // Backward compatibility for older installs that already generated the key in settings.
    $saved = trim((string)devone_store_setting('devone_marketplace_api_secret', ''));
    if ($saved !== '') {
        if (function_exists('devone_store_write_bridge_secret_file')) { devone_store_write_bridge_secret_file($saved); }
        return $saved;
    }

    $secret = 'devone_site_' . bin2hex(random_bytes(32));
    if (function_exists('devone_store_write_bridge_secret_file') && devone_store_write_bridge_secret_file($secret)) {
        if (function_exists('set_setting')) { set_setting('devone_marketplace_api_secret_status', 'generated_file'); }
        return $secret;
    }

    // Final fallback when storage/security is not writable.
    if (function_exists('set_setting')) { set_setting('devone_marketplace_api_secret', $secret); set_setting('devone_marketplace_api_secret_status', 'generated_db_fallback'); }
    return $secret;
}

function devone_store_cms_install_id() {
    $saved = trim((string)devone_store_setting('devone_marketplace_cms_install_id', ''));
    if ($saved !== '') { return $saved; }

    // Prefer the official DevOne license install ID when available.
    // This is a true per-install random ID, and it exists for Free, Pro, and Enterprise installs.
    if (function_exists('devone_license_install_id')) {
        $id = (string)devone_license_install_id();
        if ($id !== '') {
            if (function_exists('set_setting')) { set_setting('devone_marketplace_cms_install_id', $id); }
            return $id;
        }
    }

    // Safe fallback for older installs without the license client loaded.
    $host=(string)($_SERVER['HTTP_HOST']??'devone');if(!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/',$host))$host='devone';
    $base = defined('SITE_URL') ? (string)SITE_URL : ($host . '/');
    $id = 'devone_' . substr(hash('sha256', $base . '|' . __DIR__), 0, 24);
    if (function_exists('set_setting')) { set_setting('devone_marketplace_cms_install_id', $id); }
    return $id;
}

function devone_store_current_user_context() {
    $user = function_exists('devone_current_user') ? devone_current_user() : null;
    $id = function_exists('devone_current_user_id') ? (int)devone_current_user_id() : (int)($user['id'] ?? 0);
    $display = function_exists('devone_current_user_display_name') ? devone_current_user_display_name() : trim((string)($user['display_name'] ?? ($user['username'] ?? 'Developer')));
    $email = strtolower(trim((string)($user['email'] ?? '')));
    return array(
        'cms_install_id' => devone_store_cms_install_id(),
        'cms_user_id' => (string)$id,
        'email' => $email,
        'display_name' => $display,
        'roles' => array((string)($user['role'] ?? 'admin')),
    );
}

function devone_store_license_bridge_meta() {
    $meta = array('license_plan' => 'free', 'license_status' => 'unverified', 'license_public_id' => '', 'activation_id' => '');
    if (function_exists('devone_license_status')) {
        $status = devone_license_status();
        if (is_array($status)) {
            $meta['license_plan'] = (string)($status['plan'] ?? 'free');
            $meta['license_status'] = !empty($status['verified']) ? (string)($status['status'] ?? 'active') : 'free';
            $meta['license_public_id'] = (string)($status['license_id'] ?? '');
            $meta['activation_id'] = (string)($status['activation_id'] ?? '');
        }
    }
    return $meta;
}
function devone_store_register_bridge_url_from_manifest($manifest) {
    $url = devone_store_api_url_from_manifest($manifest, 'register_cms_bridge', '');
    if ($url !== '') { return $url; }
    $base = devone_store_marketplace_base_from_manifest($manifest);
    return $base ? rtrim($base, '/') . '/api/register-cms-bridge.php' : '';
}
function devone_store_bridge_fingerprint($secret, $ctx, $licenseMeta) {
    $site = defined('SITE_URL') ? (string)SITE_URL : '';
    $admin = defined('ADMIN_URL') ? (string)ADMIN_URL : '';
    $version = function_exists('devone_core_version') ? devone_core_version() : (defined('DEVONE_CORE_VERSION') ? (string)DEVONE_CORE_VERSION : (defined('CMS_VERSION') ? (string)CMS_VERSION : ''));
    return hash('sha256', implode('|', array(
        (string)($ctx['cms_install_id'] ?? ''),
        (string)$secret,
        $site,
        $admin,
        $version,
        (string)($licenseMeta['license_plan'] ?? 'free'),
        (string)($licenseMeta['license_status'] ?? 'unverified')
    )));
}

function devone_store_ensure_marketplace_bridge($manifest, &$message = '') {
    $message = '';
    $secret = devone_store_marketplace_api_secret();
    $ctx = devone_store_current_user_context();
    $registerUrl = devone_store_register_bridge_url_from_manifest($manifest);
    if ($registerUrl === '') { $message = 'Marketplace bridge register endpoint was not found.'; return false; }

    $licenseMeta = function_exists('devone_store_license_bridge_meta') ? devone_store_license_bridge_meta() : array('license_plan'=>'free','license_status'=>'unverified');
    $fingerprint = function_exists('devone_store_bridge_fingerprint') ? devone_store_bridge_fingerprint($secret, $ctx, $licenseMeta) : hash('sha256', $ctx['cms_install_id'] . '|' . $secret);
    $already = (string)devone_store_setting('devone_marketplace_bridge_registered_for', '');
    $savedFingerprint = (string)devone_store_setting('devone_marketplace_bridge_fingerprint', '');
    if ($already === $ctx['cms_install_id'] && $savedFingerprint === $fingerprint) { return true; }

    $payload = array(
        'cms_install_id' => $ctx['cms_install_id'],
        'shared_secret' => $secret,
        'site_url' => defined('SITE_URL') ? SITE_URL : '',
        'admin_url' => defined('ADMIN_URL') ? ADMIN_URL : '',
        'cms_version' => function_exists('devone_core_version') ? devone_core_version() : (defined('DEVONE_CORE_VERSION') ? DEVONE_CORE_VERSION : (defined('CMS_VERSION') ? CMS_VERSION : '')),
        'license_plan' => $licenseMeta['license_plan'] ?? 'free',
        'license_status' => $licenseMeta['license_status'] ?? 'unverified',
        'license_public_id' => $licenseMeta['license_public_id'] ?? '',
        'activation_id' => $licenseMeta['activation_id'] ?? '',
    );
    $headers = array('Authorization: Bearer ' . $secret, 'X-DevOne-CMS-Install-ID: ' . $ctx['cms_install_id']);
    $err = '';
    $data = devone_store_http_json($registerUrl, $payload, $headers, $err);
    if (!$data || empty($data['ok'])) {
        $message = $err ?: ($data['message'] ?? 'Marketplace bridge auto-registration failed.');
        if (function_exists('set_setting')) { set_setting('devone_marketplace_bridge_last_error', $message); }
        return false;
    }
    if (function_exists('set_setting')) {
        set_setting('devone_marketplace_bridge_registered_for', $ctx['cms_install_id']);
        set_setting('devone_marketplace_bridge_secret_hash', hash('sha256', $secret));
        set_setting('devone_marketplace_bridge_fingerprint', $fingerprint);
        set_setting('devone_marketplace_bridge_last_error', '');
        if (!empty($data['bridge']['id'])) { set_setting('devone_marketplace_bridge_id', (string)$data['bridge']['id']); }
    }
    $message = 'Marketplace bridge connected automatically.';
    return true;
}

function devone_store_http_json($url, $payload = null, $headers = array(), &$error = '') {
    $error = '';
    $url = trim((string)$url);
    if ($url === '') { $error = 'API URL is missing.'; return null; }
    if (!devone_store_allowed_url($url)) { $error='Marketplace API URLs must use HTTPS.'; return null; }
    $body = null;

    $headers = array_values((array)$headers);
    $headers[] = 'Accept: application/json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            CURLOPT_HTTPHEADER => $headers,
        ));
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status >= 400) { $error = $curlError ?: 'Marketplace API request failed: HTTP ' . $status; return null; }
    } else {
        $opts = array(
            'http' => array('timeout'=>60,'header'=>implode("\r\n",$headers),'follow_location'=>0,'ignore_errors'=>true),
            'ssl' => array('verify_peer'=>true,'verify_peer_name'=>true),
        );
        if ($payload !== null) {
            $opts['http']['method'] = 'POST';
            $opts['http']['header'] .= "\r\nContent-Type: application/json";
            $opts['http']['content'] = json_encode($payload);
        }
        $body = @file_get_contents($url, false, stream_context_create($opts));
        if ($body === false) { $error = 'Marketplace API request failed.'; return null; }
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) { $error = 'Marketplace API returned invalid JSON.'; return null; }
    return $decoded;
}

function devone_store_api_url_from_manifest($manifest, $key, $fallback = '') {
    if (!empty($manifest['rules'][$key])) { return (string)$manifest['rules'][$key]; }
    foreach ((array)($manifest['items'] ?? array()) as $item) {
        if (!empty($item[$key])) { return (string)$item[$key]; }
    }
    return $fallback;
}

function devone_store_marketplace_base_from_manifest($manifest) {
    foreach (array('embedded_checkout_api','user_purchases_api','cms_user_bridge') as $k) {
        $url = devone_store_api_url_from_manifest($manifest, $k, '');
        if ($url !== '') { return preg_replace('#/api/[^/?]+.*$#', '/', $url); }
    }
    $source = (string)($manifest['source'] ?? '');
    if ($source !== '') { return preg_replace('#/api/[^/?]+.*$#', '/', $source); }
    return '';
}

function devone_store_finalize_marketplace_session($manifest, $sessionId, &$notice = '') {
    $notice = '';
    $url = devone_store_api_url_from_manifest($manifest, 'embedded_checkout_api', '');
    if ($url === '') { return null; }
    $statusUrl = preg_replace('#/api/create-embedded-checkout\.php.*$#', '/api/stripe-session-status.php', $url);
    if ($statusUrl === $url) { $statusUrl = devone_store_marketplace_base_from_manifest($manifest) . 'api/stripe-session-status.php'; }
    $error = '';
    $data = devone_store_http_json($statusUrl . (strpos($statusUrl, '?') === false ? '?' : '&') . 'session_id=' . rawurlencode($sessionId), null, array(), $error);
    if (!$data || empty($data['ok'])) { $notice = $error ?: ($data['message'] ?? 'Checkout verification failed.'); return null; }
    $notice = !empty($data['paid']) ? 'Payment verified. Premium install access has been unlocked.' : 'Payment is still processing.';
    return $data;
}

function devone_store_fetch_marketplace_purchases($manifest, &$error = '') {
    $error = '';
    $secret = devone_store_marketplace_api_secret();
    if ($secret === '') { $error = 'Marketplace API shared secret is missing.'; return array(); }

    $bridgeMsg = '';
    if (function_exists('devone_store_ensure_marketplace_bridge')) { devone_store_ensure_marketplace_bridge($manifest, $bridgeMsg); }
    $url = devone_store_api_url_from_manifest($manifest, 'user_purchases_api', '');
    if ($url === '') { $error = 'Marketplace purchase history API was not found in the manifest.'; return array(); }

    $ctx = devone_store_current_user_context();
    $query = http_build_query(array(
        'cms_install_id' => $ctx['cms_install_id'],
        'cms_user_id' => $ctx['cms_user_id'],
        'email' => $ctx['email'],
    ));
    $headers = array('Authorization: Bearer ' . $secret, 'X-DevOne-CMS-Install-ID: ' . $ctx['cms_install_id']);
    $data = devone_store_http_json($url . (strpos($url, '?') === false ? '?' : '&') . $query, null, $headers, $error);
    if (!$data || empty($data['ok'])) { $error = $error ?: ($data['message'] ?? 'Could not fetch marketplace purchases.'); return array(); }

    $map = array();
    foreach ((array)($data['orders'] ?? array()) as $order) {
        if (!in_array((string)($order['status'] ?? ''), array('paid','test_paid'), true)) { continue; }
        foreach ((array)($order['items'] ?? array()) as $it) {
            $slug = (string)($it['slug'] ?? '');
            if ($slug === '') { continue; }
            $map[$slug] = array(
                'order_number' => (string)($order['order_number'] ?? ''),
                'download_url' => (string)($it['download_url'] ?? ''),
                'install_allowed' => !empty($it['install_allowed']),
                'activate_allowed' => !empty($it['activate_allowed']),
                'name' => (string)($it['name'] ?? ''),
                'type' => (string)($it['type'] ?? ''),
            );
        }
    }
    return $map;
}

function devone_store_item_slug_for_access($item) {
    return (string)($item['slug'] ?? devone_store_slug($item['name'] ?? 'item', 'item'));
}

function devone_store_item_pricing($item) {
    $pricing = strtolower((string)($item['pricing'] ?? $item['price_type'] ?? 'free'));
    if (in_array($pricing, array('paid','premium'), true)) { return 'paid'; }
    if (!empty($item['purchase_required'])) { return 'paid'; }
    return 'free';
}

function devone_store_item_download_url($item) {
    foreach (array('zip_url','download_url','optional_download_url','package_url') as $key) {
        $value = trim((string)($item[$key] ?? ''));
        if ($value !== '') { return $value; }
    }
    return '';
}

function devone_store_item_with_download_url($item, $downloadUrl = '') {
    $downloadUrl = trim((string)$downloadUrl);
    if ($downloadUrl === '') { $downloadUrl = devone_store_item_download_url($item); }
    if ($downloadUrl !== '') {
        $item['zip_url'] = $downloadUrl;
        $item['download_url'] = $downloadUrl;
    }
    return $item;
}

function devone_store_install_zip_item($item, $overwrite = false) {
    $type = strtolower((string)($item['package_type'] ?? $item['type'] ?? 'auto'));
    if (!in_array($type, array('theme','plugin','library','auto'), true)) { $type = 'auto'; }
    $name = trim((string)($item['name'] ?? 'DevOne Package'));
    $zipSource = trim((string)($item['zip_url'] ?? $item['download_url'] ?? $item['optional_download_url'] ?? $item['local_zip'] ?? ''));
    if ($zipSource !== '' && empty($item['local_zip'])) {
        $zipSource = devone_store_resolve_url($zipSource, (string)($item['_manifest_source'] ?? ''));
    }
    if ($zipSource === '' && !empty($item['repo'])) { $zipSource = devone_github_zip_url($item['repo'], $item['ref'] ?? 'main'); }
    if ($zipSource === '') { return array('ok'=>false, 'message'=>'No ZIP source or GitHub repository was provided.'); }

    $cache = devone_store_cache_dir();
    $error = '';
    if (!devone_store_ensure_writable_dir($cache, 'storage/cache/store', $error)) {
        return array('ok'=>false, 'message'=>$error);
    }
    $workId = date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $zipPath = $cache . '/package-' . $workId . '.zip';
    $extractDir = $cache . '/extract-' . $workId;
    if (!devone_store_ensure_writable_dir($extractDir, 'temporary marketplace extraction folder', $error)) {
        return array('ok'=>false, 'message'=>$error);
    }


    if (!devone_store_download_file($zipSource, $zipPath, $error)) {
        devone_store_rrmdir($extractDir);
        return array('ok'=>false, 'message'=>$error ?: 'Package download failed.');
    }
    $declaredHash=strtolower(trim((string)($item['sha256']??$item['package_sha256']??'')));
    if($declaredHash!=='' && (!preg_match('/^[a-f0-9]{64}$/',$declaredHash) || !hash_equals($declaredHash,strtolower((string)hash_file('sha256',$zipPath))))){
        @unlink($zipPath);devone_store_rrmdir($extractDir);return array('ok'=>false,'message'=>'Marketplace package failed its declared SHA-256 verification.');
    }
    $extractError = '';
    $extractOk = function_exists('safe_zip_extract_with_error') ? safe_zip_extract_with_error($zipPath, $extractDir, $extractError) : safe_zip_extract($zipPath, $extractDir);
    if (!$extractOk) {
        @unlink($zipPath); devone_store_rrmdir($extractDir);
        return array('ok'=>false, 'message'=>$extractError ?: 'ZIP extraction failed or unsafe paths were detected.');
    }

    list($root, $detectedType) = devone_store_find_package_root($extractDir, $type);
    if ($root === '' || $detectedType === '') {
        @unlink($zipPath); devone_store_rrmdir($extractDir);
        return array('ok'=>false, 'message'=>'DevOneCMS could not detect whether this package is a theme, plugin, or library.');
    }

    $manifest = devone_store_package_manifest($root, $detectedType, $item);
    $name = trim((string)($manifest['name'] ?? $name)) ?: $name;
    $folder = devone_store_auto_folder_slug($item, $manifest, $root, $detectedType, $zipSource);

    $base = devone_store_base_path();
    $destBase = array(
        'theme' => $base . '/content/themes',
        'plugin' => $base . '/content/plugins',
        'library' => $base . '/content/libraries',
    );
    if (!devone_store_ensure_writable_dir($destBase[$detectedType], 'content/' . ($detectedType === 'theme' ? 'themes' : ($detectedType === 'plugin' ? 'plugins' : 'libraries')), $error)) {
        @unlink($zipPath); devone_store_rrmdir($extractDir);
        return array('ok'=>false, 'message'=>$error);
    }
    $dest = $destBase[$detectedType] . '/' . $folder;
    if (is_dir($dest) && !$overwrite) {
        @unlink($zipPath); devone_store_rrmdir($extractDir);
        return array('ok'=>false, 'message'=>ucfirst($detectedType) . ' folder already exists. Check overwrite to replace it.');
    }
    if (is_dir($dest)) { devone_store_rrmdir($dest); }
    if (!devone_store_rcopy($root, $dest)) {
        @unlink($zipPath); devone_store_rrmdir($extractDir);
        return array('ok'=>false, 'message'=>'Could not copy package into the DevOneCMS content folder. Check permissions.');
    }

    if ($detectedType === 'theme') {
        if (!is_file($dest . '/theme.css')) {
            devone_store_rrmdir($dest); @unlink($zipPath); devone_store_rrmdir($extractDir);
            return array('ok'=>false, 'message'=>'Theme packages must contain theme.css.');
        }
        if (function_exists('devone_register_theme')) { devone_register_theme($manifest['name'] ?: $name, $folder, 0); }
    }

    if ($detectedType === 'plugin') {
        $meta = array_merge(array('name' => $name, 'version' => '1.0.0', 'description' => 'Installed from DevOne Store'), $manifest);
        if (!is_file($dest . '/plugin.json')) {
            @file_put_contents($dest . '/plugin.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        devone_store_insert_plugin($meta['name'] ?: $name, $folder, $meta['version'] ?: '1.0.0', $meta['description'] ?: '', 1);
    }

    if ($detectedType === 'library') {
        $types = devone_store_detect_library_types($dest);
        foreach ($types as $assetType) {
            $label = $name . ' ' . strtoupper($assetType);
            devone_store_insert_library($label, $folder, $assetType, 'local', 1, (string)($item['description'] ?? $item['desc'] ?? ''));
        }
    }

    if (function_exists('devone_log')) { devone_log('package_installed', $detectedType . ':' . $folder); }
    @unlink($zipPath); devone_store_rrmdir($extractDir);
    return array('ok'=>true, 'message'=>ucfirst($detectedType) . ' installed: ' . $name . ' → ' . $folder, 'package_type'=>$detectedType, 'folder'=>$folder, 'name'=>$name);
}

function devone_store_install_item($item, $overwrite = false) {
    $installMode = strtolower((string)($item['install_mode'] ?? ''));
    $type = strtolower((string)($item['type'] ?? ''));
    if ($installMode === 'cdn' || $type === 'cdn' || !empty($item['cdn']) || !empty($item['assets']) || !empty($item['css']) || !empty($item['js'])) {
        return devone_store_install_cdn_item($item);
    }
    return devone_store_install_zip_item($item, $overwrite);
}

function devone_store_item_is_cdn($item) {
    $installMode = strtolower((string)($item['install_mode'] ?? ''));
    $type = strtolower((string)($item['type'] ?? ''));
    return $installMode === 'cdn' || $type === 'cdn' || !empty($item['cdn']) || !empty($item['assets']) || !empty($item['css']) || !empty($item['js']);
}

function devone_store_item_package_type($item) {
    if (devone_store_item_is_cdn($item)) { return 'cdn'; }
    $type = strtolower((string)($item['package_type'] ?? $item['type'] ?? ''));
    $category = strtolower((string)($item['category'] ?? ''));
    if (in_array($type, array('theme','plugin','library'), true)) { return $type; }
    if (in_array($category, array('themes','theme'), true)) { return 'theme'; }
    if (in_array($category, array('plugins','plugin'), true)) { return 'plugin'; }
    if (in_array($category, array('libraries','library','frameworks','framework','js-plugins','editors'), true)) { return 'library'; }
    return 'package';
}

function devone_store_expected_folder($item) {
    foreach (array('install_folder', 'folder', 'slug', 'id') as $key) {
        if (!empty($item[$key])) { return devone_store_slug($item[$key], 'package'); }
    }
    if (!empty($item['repo'])) {
        $parsed = function_exists('devone_github_parse_repo') ? devone_github_parse_repo($item['repo']) : null;
        if ($parsed && !empty($parsed['repo'])) { return devone_store_slug($parsed['repo'], 'package'); }
    }
    if (!empty($item['zip_url'])) {
        $zipSlug = devone_store_zip_name_slug($item['zip_url']);
        if ($zipSlug !== 'package') { return $zipSlug; }
    }
    return devone_store_slug($item['name'] ?? 'package', 'package');
}

function devone_store_installed_status($item) {
    $type = devone_store_item_package_type($item);
    $folder = devone_store_expected_folder($item);
    $base = devone_store_base_path();
    $status = array('installed'=>false, 'active'=>false, 'type'=>$type, 'folder'=>$folder, 'path'=>'');

    if ($type === 'theme') {
        $status['path'] = $base . '/content/themes/' . $folder;
        $status['installed'] = is_file($status['path'] . '/theme.css');
        $active = function_exists('get_setting') ? (string)get_setting('site_theme', '') : '';
        $status['active'] = $status['installed'] && $active === $folder;
        return $status;
    }

    if ($type === 'plugin') {
        $status['path'] = $base . '/content/plugins/' . $folder;
        $status['installed'] = is_dir($status['path']);
        if ($status['installed'] && function_exists('table_exists') && table_exists('plugins')) {
            try {
                $stmt = db()->prepare('SELECT active FROM `' . table_name('plugins') . '` WHERE folder=? LIMIT 1');
                $stmt->execute(array($folder));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $status['active'] = $row ? ((int)($row['active'] ?? 0) === 1) : false;
            } catch (Throwable $e) { $status['active'] = false; }
        }
        return $status;
    }

    if ($type === 'library') {
        $status['path'] = $base . '/content/libraries/' . $folder;
        $status['installed'] = is_dir($status['path']);
        $status['active'] = false;
        if (function_exists('table_exists') && table_exists('libraries')) {
            try {
                $stmt = db()->prepare('SELECT COUNT(*) FROM `' . table_name('libraries') . '` WHERE folder=?');
                $stmt->execute(array($folder));
                $status['installed'] = $status['installed'] || ((int)$stmt->fetchColumn() > 0);
                $stmt = db()->prepare('SELECT COUNT(*) FROM `' . table_name('libraries') . '` WHERE folder=? AND active=1');
                $stmt->execute(array($folder));
                $status['active'] = ((int)$stmt->fetchColumn() > 0);
            } catch (Throwable $e) { $status['active'] = $status['installed']; }
        } else { $status['active'] = $status['installed']; }
        return $status;
    }

    if ($type === 'cdn') {
        $status['folder'] = devone_store_slug($item['name'] ?? 'cdn-asset', 'cdn-asset');
        $status['installed'] = false;
        $status['active'] = false;
        if (function_exists('table_exists') && table_exists('libraries')) {
            try {
                $name = trim((string)($item['name'] ?? ''));
                if ($name !== '') {
                    $stmt = db()->prepare('SELECT COUNT(*) FROM `' . table_name('libraries') . '` WHERE name LIKE ? AND source="cdn"');
                    $stmt->execute(array('%' . $name . '%'));
                    $status['installed'] = ((int)$stmt->fetchColumn()) > 0;
                    $stmt = db()->prepare('SELECT COUNT(*) FROM `' . table_name('libraries') . '` WHERE name LIKE ? AND source="cdn" AND active=1');
                    $stmt->execute(array('%' . $name . '%'));
                    $status['active'] = ((int)$stmt->fetchColumn()) > 0;
                }
            } catch (Throwable $e) { $status['installed'] = false; }
        }
        return $status;
    }

    return $status;
}

function devone_store_activate_theme_folder($folder, $name = '') {
    $folder = devone_store_slug($folder, '');
    if ($folder === '') { return array('ok'=>false, 'message'=>'Theme folder is missing.'); }
    $themeDir = devone_store_base_path() . '/content/themes/' . $folder;
    if (!is_file($themeDir . '/theme.css')) { return array('ok'=>false, 'message'=>'Theme is not installed or is missing theme.css.'); }
    $name = trim((string)$name) ?: ucwords(str_replace('-', ' ', $folder));
    if (function_exists('set_setting')) { set_setting('site_theme', $folder); }
    if (function_exists('table_exists') && table_exists('themes')) {
        try {
            $themesTable = table_name('themes');
            $themeCols = function_exists('devone_table_columns') ? devone_table_columns('themes') : array();
            if (in_array('site_id', $themeCols, true)) {
                $siteId = function_exists('devone_content_site_id') ? max(1, (int)devone_content_site_id()) : 1;
                $stmt = db()->prepare('UPDATE `' . $themesTable . '` SET active=0 WHERE site_id=?');
                $stmt->execute(array($siteId));
            } else {
                db()->exec('UPDATE `' . $themesTable . '` SET active=0');
            }
            if (function_exists('devone_register_theme')) { devone_register_theme($name, $folder, 1); }
        } catch (Throwable $e) {}
    }
    if (function_exists('devone_log')) { devone_log('marketplace_theme_activated', $folder); }
    return array('ok'=>true, 'message'=>'Theme activated: ' . $name);
}

function devone_store_activate_plugin_folder($folder) {
    $folder = devone_store_slug($folder, '');
    if ($folder === '') { return array('ok'=>false, 'message'=>'Plugin folder is missing.'); }
    $pluginDir = devone_store_base_path() . '/content/plugins/' . $folder;
    if (!is_dir($pluginDir)) { return array('ok'=>false, 'message'=>'Plugin is not installed yet.'); }
    if (function_exists('table_exists') && table_exists('plugins')) {
        try {
            $stmt = db()->prepare('UPDATE `' . table_name('plugins') . '` SET active=1 WHERE folder=?');
            $stmt->execute(array($folder));
            if (function_exists('devone_invalidate_active_plugin_cache')) { devone_invalidate_active_plugin_cache(); }
            if (function_exists('devone_plugin_lifecycle_run')) { devone_plugin_lifecycle_run($folder, 'activate'); }
        } catch (Throwable $e) {}
    }
    if (function_exists('devone_log')) { devone_log('marketplace_plugin_activated', $folder); }
    return array('ok'=>true, 'message'=>'Plugin activated: ' . $folder);
}

function devone_store_activate_installed_item($item) {
    $type = devone_store_item_package_type($item);
    $folder = devone_store_expected_folder($item);
    if ($type === 'theme') { return devone_store_activate_theme_folder($folder, (string)($item['name'] ?? '')); }
    if ($type === 'plugin') { return devone_store_activate_plugin_folder($folder); }
    if ($type === 'library') {
        if (function_exists('table_exists') && table_exists('libraries')) {
            $stmt = db()->prepare('UPDATE `' . table_name('libraries') . '` SET active=1 WHERE folder=?');
            $stmt->execute(array($folder));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_library_activated', $folder); }
        return array('ok'=>true, 'message'=>'Library activated: ' . $folder);
    }
    if ($type === 'cdn') {
        $name = trim((string)($item['name'] ?? ''));
        if ($name !== '' && function_exists('table_exists') && table_exists('libraries')) {
            $stmt = db()->prepare('UPDATE `' . table_name('libraries') . '` SET active=1 WHERE name LIKE ? AND source="cdn"');
            $stmt->execute(array('%' . $name . '%'));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_cdn_activated', $name); }
        return array('ok'=>true, 'message'=>'CDN assets activated: ' . ($name ?: 'asset'));
    }
    return array('ok'=>false, 'message'=>'This package type does not support activation yet.');
}


/* DevOneCMS v1.1.23 cleanup helpers: deactivate and uninstall marketplace items. */
if (!function_exists('devone_store_default_theme_folder')) {
function devone_store_default_theme_folder($exclude = '') {
    $base = devone_store_base_path() . '/content/themes';
    $exclude = devone_store_slug($exclude, '');
    foreach (array('devone-dark','devone-light','default','classic') as $folder) {
        if ($folder !== $exclude && is_file($base . '/' . $folder . '/theme.css')) { return $folder; }
    }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
        $folder = basename($dir);
        if ($folder !== $exclude && is_file($dir . '/theme.css')) { return $folder; }
    }
    return '';
}}

if (!function_exists('devone_store_deactivate_installed_item')) {
function devone_store_deactivate_installed_item($item) {
    $type = devone_store_item_package_type($item);
    $folder = devone_store_expected_folder($item);
    $name = trim((string)($item['name'] ?? $folder));

    if ($type === 'theme') {
        $current = function_exists('get_setting') ? (string)get_setting('site_theme', '') : '';
        if ($current !== $folder) { return array('ok'=>true, 'message'=>'Theme is already inactive.'); }
        $fallback = devone_store_default_theme_folder($folder);
        if ($fallback === '') { return array('ok'=>false, 'message'=>'No alternate theme is available. Install or activate another theme before deactivating this one.'); }
        return devone_store_activate_theme_folder($fallback, ucwords(str_replace('-', ' ', $fallback)));
    }

    if ($type === 'plugin') {
        if (function_exists('table_exists') && table_exists('plugins')) {
            $stmt = db()->prepare('UPDATE `' . table_name('plugins') . '` SET active=0 WHERE folder=?');
            $stmt->execute(array($folder));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_plugin_deactivated', $folder); }
        return array('ok'=>true, 'message'=>'Plugin deactivated: ' . $folder);
    }

    if ($type === 'library') {
        if (function_exists('table_exists') && table_exists('libraries')) {
            $stmt = db()->prepare('UPDATE `' . table_name('libraries') . '` SET active=0 WHERE folder=?');
            $stmt->execute(array($folder));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_library_deactivated', $folder); }
        return array('ok'=>true, 'message'=>'Library deactivated: ' . $folder);
    }

    if ($type === 'cdn') {
        $name = trim((string)($item['name'] ?? ''));
        if ($name !== '' && function_exists('table_exists') && table_exists('libraries')) {
            $stmt = db()->prepare('UPDATE `' . table_name('libraries') . '` SET active=0 WHERE name LIKE ? AND source="cdn"');
            $stmt->execute(array('%' . $name . '%'));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_cdn_deactivated', $name); }
        return array('ok'=>true, 'message'=>'CDN assets deactivated: ' . ($name ?: 'asset'));
    }

    return array('ok'=>false, 'message'=>'This package type does not support deactivation yet.');
}}

if (!function_exists('devone_store_uninstall_installed_item')) {
function devone_store_uninstall_installed_item($item, $force = false) {
    $type = devone_store_item_package_type($item);
    $folder = devone_store_expected_folder($item);
    $base = devone_store_base_path();
    $name = trim((string)($item['name'] ?? $folder));

    if ($type === 'theme') {
        $current = function_exists('get_setting') ? (string)get_setting('site_theme', '') : '';
        if ($current === $folder) {
            $fallback = devone_store_default_theme_folder($folder);
            if ($fallback === '') { return array('ok'=>false, 'message'=>'Cannot uninstall the active theme because no alternate theme is available.'); }
            devone_store_activate_theme_folder($fallback, ucwords(str_replace('-', ' ', $fallback)));
        }
        $dir = $base . '/content/themes/' . $folder;
        if (is_dir($dir)) { devone_store_rrmdir($dir); }
        if (function_exists('table_exists') && table_exists('themes')) {
            $stmt = db()->prepare('DELETE FROM `' . table_name('themes') . '` WHERE folder=?');
            $stmt->execute(array($folder));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_theme_uninstalled', $folder); }
        return array('ok'=>true, 'message'=>'Theme uninstalled and files removed: ' . $folder);
    }

    if ($type === 'plugin') {
        $dir = $base . '/content/plugins/' . $folder;
        if (is_dir($dir)) { devone_store_rrmdir($dir); }
        if (function_exists('table_exists') && table_exists('plugins')) {
            $stmt = db()->prepare('DELETE FROM `' . table_name('plugins') . '` WHERE folder=?');
            $stmt->execute(array($folder));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_plugin_uninstalled', $folder); }
        return array('ok'=>true, 'message'=>'Plugin uninstalled and files removed: ' . $folder);
    }

    if ($type === 'library') {
        $dir = $base . '/content/libraries/' . $folder;
        if (is_dir($dir)) { devone_store_rrmdir($dir); }
        if (function_exists('table_exists') && table_exists('libraries')) {
            $stmt = db()->prepare('DELETE FROM `' . table_name('libraries') . '` WHERE folder=?');
            $stmt->execute(array($folder));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_library_uninstalled', $folder); }
        return array('ok'=>true, 'message'=>'Library uninstalled and files removed: ' . $folder);
    }

    if ($type === 'cdn') {
        $name = trim((string)($item['name'] ?? ''));
        if ($name !== '' && function_exists('table_exists') && table_exists('libraries')) {
            $stmt = db()->prepare('DELETE FROM `' . table_name('libraries') . '` WHERE name LIKE ? AND source="cdn"');
            $stmt->execute(array('%' . $name . '%'));
        }
        if (function_exists('devone_log')) { devone_log('marketplace_cdn_uninstalled', $name); }
        return array('ok'=>true, 'message'=>'CDN asset registrations removed: ' . ($name ?: 'asset'));
    }

    return array('ok'=>false, 'message'=>'This package type does not support uninstall yet.');
}}





function devone_store_feedback_slug($slug) {
    $slug = strtolower(trim((string)$slug));
    $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug);
    return trim($slug, '-_.');
}

function devone_store_marketplace_feedback_api_url($manifest = array()) {
    $url = is_array($manifest) ? devone_store_api_url_from_manifest($manifest, 'item_feedback_api', '') : '';
    if ($url !== '') { return $url; }
    $base = is_array($manifest) ? devone_store_marketplace_base_from_manifest($manifest) : '';
    if ($base === '') { $base = function_exists('devone_store_official_public_url') ? devone_store_official_public_url() : 'https://marketplace.devonecms.com/'; }
    return rtrim($base, '/') . '/api/item-feedback.php';
}

function devone_store_marketplace_api_headers() {
    $headers = array();
    $secret = function_exists('devone_store_marketplace_api_secret') ? devone_store_marketplace_api_secret() : '';
    $installId = function_exists('devone_store_cms_install_id') ? devone_store_cms_install_id() : '';
    if ($secret !== '') { $headers[] = 'Authorization: Bearer ' . $secret; }
    if ($installId !== '') { $headers[] = 'X-DevOne-CMS-Install-ID: ' . $installId; }
    return $headers;
}

function devone_store_fetch_feedback_batch($manifest, $slugs, &$error = '') {
    $error = '';
    $clean = array();
    foreach ((array)$slugs as $slug) {
        $slug = devone_store_feedback_slug($slug);
        if ($slug !== '') { $clean[$slug] = $slug; }
    }
    $fallback = array();
    foreach ($clean as $slug) { $fallback[$slug] = array('entries'=>array(), 'average'=>0, 'count'=>0); }
    if (!$clean) { return $fallback; }
    $url = devone_store_marketplace_feedback_api_url($manifest);
    $url .= (strpos($url, '?') === false ? '?' : '&') . 'slugs=' . rawurlencode(implode(',', array_values($clean)));
    $data = devone_store_http_json($url, null, devone_store_marketplace_api_headers(), $error);
    if (!$data || empty($data['ok']) || !is_array($data['feedback'] ?? null)) { return $fallback; }
    foreach ($data['feedback'] as $slug => $payload) {
        $slug = devone_store_feedback_slug($slug);
        if ($slug !== '' && isset($fallback[$slug]) && is_array($payload)) {
            $fallback[$slug] = array(
                'entries' => array_values((array)($payload['entries'] ?? array())),
                'average' => (float)($payload['average'] ?? 0),
                'count' => (int)($payload['count'] ?? 0),
            );
        }
    }
    return $fallback;
}

function devone_store_feedback_for_item($slug) {
    global $devoneStoreFeedbackMap;
    $slug = devone_store_feedback_slug($slug);
    if ($slug !== '' && is_array($devoneStoreFeedbackMap ?? null) && isset($devoneStoreFeedbackMap[$slug])) {
        return $devoneStoreFeedbackMap[$slug];
    }
    return array('entries' => array(), 'average' => 0, 'count' => 0);
}

function devone_store_save_feedback($slug, $rating, $comment, &$error = '') {
    $error = '';
    $slug = devone_store_feedback_slug($slug);
    if ($slug === '') { $error = 'Missing marketplace item slug.'; return false; }
    $rating = max(0, min(5, (int)$rating));
    $comment = trim((string)$comment);
    if ($rating <= 0 && $comment === '') { $error = 'Add a rating or comment before saving.'; return false; }
    if (function_exists('mb_substr')) { $comment = mb_substr($comment, 0, 1200); } else { $comment = substr($comment, 0, 1200); }
    $ctx = function_exists('devone_store_current_user_context') ? devone_store_current_user_context() : array();
    $payload = array(
        'item_slug' => $slug,
        'rating' => $rating,
        'comment' => $comment,
        'cms_install_id' => (string)($ctx['cms_install_id'] ?? ''),
        'cms_user_id' => (string)($ctx['cms_user_id'] ?? ''),
        'email' => (string)($ctx['email'] ?? ''),
        'display_name' => (string)($ctx['display_name'] ?? 'DevOne User'),
    );
    $url = devone_store_marketplace_feedback_api_url(array());
    $data = devone_store_http_json($url, $payload, devone_store_marketplace_api_headers(), $error);
    if (!$data || empty($data['ok'])) {
        $error = $error ?: (string)($data['message'] ?? 'Marketplace feedback API did not accept the comment.');
        return false;
    }
    return true;
}
