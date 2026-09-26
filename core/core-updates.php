<?php
if (!defined('DEVONE_ROOT')) { define('DEVONE_ROOT', dirname(__DIR__)); }
if (is_file(__DIR__ . '/version.php')) { require_once __DIR__ . '/version.php'; }

function devone_core_update_endpoint() {
    return defined('DEVONE_CORE_UPDATE_ENDPOINT')
        ? (string)DEVONE_CORE_UPDATE_ENDPOINT
        : 'https://marketplace.devonecms.com/api/core/latest.php';
}

function devone_core_update_storage($suffix = '') {
    $base = DEVONE_ROOT . '/storage/updates';
    if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
        throw new RuntimeException('Unable to create update storage: ' . $base);
    }
    return $suffix === '' ? $base : $base . '/' . ltrim((string)$suffix, '/');
}

function devone_core_update_mkdir($path) {
    if (is_dir($path)) { return; }
    if (!@mkdir($path, 0755, true) && !is_dir($path)) {
        $last = error_get_last();
        throw new RuntimeException('Unable to create directory ' . $path . ($last ? ': ' . $last['message'] : ''));
    }
}

function devone_core_update_log($event, array $context = array()) {
    try {
        $line = json_encode(array(
            'time' => gmdate('c'),
            'event' => (string)$event,
            'context' => $context,
        ), JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents(devone_core_update_storage('update.log'), $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) {
        // Logging must never make an update fail.
    }
}

function devone_core_update_http_get($url, $timeout = 12) {
    $parts=parse_url((string)$url); if(strtolower((string)($parts['scheme']??''))!=='https'){ throw new RuntimeException('Core updater only accepts HTTPS URLs.'); }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'DevOne-Core-Updater/' . (function_exists('devone_core_version') ? devone_core_version() : 'unknown'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            CURLOPT_REDIR_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('Marketplace request failed' . ($error ? ': ' . $error : ' (HTTP ' . $code . ')'));
        }
        return $body;
    }

    $currentUrl = (string)$url;
    for ($redirects = 0; $redirects <= 3; $redirects++) {
        $context = stream_context_create(array(
            'http' => array('timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => 'DevOne-Core-Updater'),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $body = @file_get_contents($currentUrl, false, $context);
        $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : array();
        $status = 0;
        if (!empty($headers[0]) && preg_match('#\s([0-9]{3})(?:\s|$)#', (string)$headers[0], $m)) { $status = (int)$m[1]; }
        if ($body !== false && $status >= 200 && $status < 300) { return $body; }
        if ($status >= 300 && $status < 400 && $redirects < 3) {
            $location = '';
            foreach ($headers as $header) { if (stripos((string)$header, 'Location:') === 0) { $location = trim(substr((string)$header, 9)); break; } }
            if ($location !== '') {
                if (strpos($location, '/') === 0) {
                    $parts = parse_url($currentUrl);
                    $location = 'https://' . (string)($parts['host'] ?? '') . (!empty($parts['port']) ? ':' . (int)$parts['port'] : '') . $location;
                }
                $redirectParts = parse_url($location);
                if (strtolower((string)($redirectParts['scheme'] ?? '')) !== 'https') { throw new RuntimeException('Core updater blocked a non-HTTPS redirect.'); }
                $currentUrl = $location;
                continue;
            }
        }
        throw new RuntimeException('Marketplace request failed' . ($status ? ' (HTTP ' . $status . ')' : '. Enable cURL or allow_url_fopen.'));
    }
    throw new RuntimeException('Marketplace request exceeded the redirect limit.');
}

function devone_core_update_check($force = false) {
    $cache = devone_core_update_storage('latest.json');
    $installed = function_exists('devone_core_version') ? devone_core_version() : (defined('CMS_VERSION') ? CMS_VERSION : '0.0.0');

    if (!$force && is_file($cache) && (time() - (int)@filemtime($cache)) < 21600) {
        $cached = json_decode((string)@file_get_contents($cache), true);
        if (is_array($cached)) {
            $cachedInstalled = (string)($cached['installed_version'] ?? '');
            $cachedLatest = (string)($cached['latest_version'] ?? $cached['version'] ?? $installed);

            // An update is installed during the same PHP request that loaded the
            // previous version identity. That request can write a stale cache.
            // Never reuse cached metadata when the actual installed version changed.
            if ($cachedInstalled === $installed) {
                $cached['update_available'] = version_compare($installed, $cachedLatest, '<');
                return $cached;
            }

            @unlink($cache);
            clearstatcache(true, $cache);
        }
    }
    $url = devone_core_update_endpoint() . (strpos(devone_core_update_endpoint(), '?') === false ? '?' : '&') . http_build_query(array(
        'installed_version' => $installed,
        'channel' => 'stable',
        'product' => 'devone-superadmin',
    ));

    try {
        $data = json_decode(devone_core_update_http_get($url), true);
        if (!is_array($data)) { throw new RuntimeException('Marketplace returned invalid JSON.'); }
        $data['installed_version'] = $installed;
        $latest = (string)($data['latest_version'] ?? $data['version'] ?? $installed);
        $data['latest_version'] = $latest;
        $data['update_available'] = array_key_exists('update_available', $data)
            ? (bool)$data['update_available']
            : version_compare($installed, $latest, '<');
        $data['checked_at'] = gmdate('c');
        @file_put_contents($cache, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $data;
    } catch (Throwable $e) {
        return array(
            'ok' => false,
            'installed_version' => $installed,
            'latest_version' => $installed,
            'update_available' => false,
            'error' => $e->getMessage(),
            'checked_at' => gmdate('c'),
        );
    }
}

function devone_core_update_safe_relative_path($path) {
    $path = str_replace('\\', '/', trim((string)$path));
    if ($path === '' || $path[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $path) || preg_match('#^[A-Za-z]:/#', $path)) { return false; }
    return preg_match('#^[A-Za-z0-9._/\-]+$#', $path) === 1;
}

function devone_core_update_protected($path) {
    $path = ltrim(str_replace('\\', '/', (string)$path), '/');
    foreach (array('config.php', '.env', 'storage/', 'content/sites/', 'content/files/', 'content/backups/', 'content/downloads/') as $protected) {
        if ($path === rtrim($protected, '/') || strpos($path, $protected) === 0) { return true; }
    }
    return false;
}

function devone_core_update_download($release) {
    $url = (string)($release['package_url'] ?? $release['download_url'] ?? '');
    if (!preg_match('#^https://#i', $url)) { throw new RuntimeException('The official update package must use HTTPS.'); }
    $dir = devone_core_update_storage('downloads');
    devone_core_update_mkdir($dir);
    $target = $dir . '/devone-' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string)($release['latest_version'] ?? 'update')) . '.zip';
    $body = devone_core_update_http_get($url, 90);
    $maxPackage = function_exists('devone_package_limits') ? (int)(devone_package_limits()['max_archive_bytes'] ?? 104857600) : 104857600;
    if (strlen((string)$body) <= 0 || strlen((string)$body) > $maxPackage) { throw new RuntimeException('Downloaded Core update package exceeds the allowed archive size.'); }
    if (@file_put_contents($target, $body, LOCK_EX) === false) { throw new RuntimeException('Unable to save update package to ' . $target); }
    $expected = strtolower(trim((string)($release['sha256'] ?? $release['package_sha256'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $expected)) { @unlink($target); throw new RuntimeException('Official Core update metadata is missing a valid SHA-256 checksum.'); }
    if (!hash_equals($expected, strtolower((string)hash_file('sha256', $target)))) {
        @unlink($target);
        throw new RuntimeException('Update checksum verification failed.');
    }
    return $target;
}

function devone_core_update_php_cli() {
    $candidates = array();
    if (defined('PHP_BINARY') && PHP_BINARY) { $candidates[] = PHP_BINARY; }
    $majorMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $candidates[] = '/usr/bin/php' . $majorMinor;
    $candidates[] = '/usr/bin/php' . PHP_MAJOR_VERSION;
    $candidates[] = '/usr/bin/php';
    $candidates[] = '/usr/local/bin/php';

    foreach (array_unique($candidates) as $candidate) {
        if (!is_string($candidate) || $candidate === '' || !is_executable($candidate)) { continue; }
        $base = strtolower(basename($candidate));
        if (strpos($base, 'fpm') !== false || strpos($base, 'cgi') !== false) { continue; }
        $output = array(); $status = 1;
        @exec(escapeshellarg($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>&1', $output, $status);
        if ($status === 0 && trim(implode("\n", $output)) === 'cli') { return $candidate; }
    }

    $output = array(); $status = 1;
    @exec('command -v php 2>/dev/null', $output, $status);
    if ($status === 0 && !empty($output[0]) && is_executable(trim($output[0]))) { return trim($output[0]); }
    return '';
}

function devone_core_update_lint_php($file, $relativePath) {
    $cli = devone_core_update_php_cli();
    if ($cli === '') {
        devone_core_update_log('lint_skipped', array('file' => $relativePath, 'reason' => 'PHP CLI unavailable'));
        return;
    }
    $output = array(); $status = 0;
    @exec(escapeshellarg($cli) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('PHP syntax check failed: ' . $relativePath . '. ' . trim(implode(' ', $output)));
    }
}

function devone_core_update_permission_details($target) {
    $parent = dirname($target);
    $details = array(
        'target' => $target,
        'target_exists' => file_exists($target),
        'target_writable' => file_exists($target) ? is_writable($target) : null,
        'parent' => $parent,
        'parent_exists' => is_dir($parent),
        'parent_writable' => is_dir($parent) ? is_writable($parent) : false,
        'php_user' => function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? ((posix_getpwuid(posix_geteuid())['name'] ?? '') ?: (string)posix_geteuid()) : get_current_user(),
    );
    if (file_exists($target)) {
        $details['target_mode'] = substr(sprintf('%o', @fileperms($target)), -4);
        if (function_exists('posix_getpwuid')) { $details['target_owner'] = (posix_getpwuid(@fileowner($target))['name'] ?? (string)@fileowner($target)); }
    }
    if (is_dir($parent)) {
        $details['parent_mode'] = substr(sprintf('%o', @fileperms($parent)), -4);
        if (function_exists('posix_getpwuid')) { $details['parent_owner'] = (posix_getpwuid(@fileowner($parent))['name'] ?? (string)@fileowner($parent)); }
    }
    return $details;
}

function devone_core_update_format_permission_error($path, array $details) {
    $parts = array('Unable to install ' . $path . '.');
    $parts[] = 'Destination: ' . ($details['target'] ?? 'unknown');
    $parts[] = 'PHP user: ' . ($details['php_user'] ?? 'unknown');
    $parts[] = 'Destination writable: ' . (!empty($details['target_writable']) ? 'yes' : 'no');
    $parts[] = 'Parent writable: ' . (!empty($details['parent_writable']) ? 'yes' : 'no');
    if (!empty($details['target_owner'])) { $parts[] = 'Destination owner/mode: ' . $details['target_owner'] . '/' . ($details['target_mode'] ?? '?'); }
    if (!empty($details['parent_owner'])) { $parts[] = 'Parent owner/mode: ' . $details['parent_owner'] . '/' . ($details['parent_mode'] ?? '?'); }
    $parts[] = 'Fix ownership so the PHP site user owns the DevOne installation, then retry.';
    return implode(' ', $parts);
}

function devone_core_update_preflight(array $manifest, $stageDir) {
    $problems = array();
    foreach ((array)($manifest['files'] ?? array()) as $file) {
        $path = (string)($file['path'] ?? '');
        if (!devone_core_update_safe_relative_path($path) || devone_core_update_protected($path)) {
            $problems[] = 'Protected or invalid path: ' . $path;
            continue;
        }
        $source = $stageDir . '/' . $path;
        if (!is_file($source)) {
            $problems[] = 'Declared file is missing: ' . $path;
            continue;
        }
        $target = DEVONE_ROOT . '/' . $path;
        $parent = dirname($target);
        $canReplace = is_file($target) ? (is_writable($target) || (is_dir($parent) && is_writable($parent))) : (is_dir($parent) ? is_writable($parent) : is_writable(dirname($parent)));
        if (!$canReplace) {
            $problems[] = devone_core_update_format_permission_error($path, devone_core_update_permission_details($target));
        }
    }
    if ($problems) {
        throw new RuntimeException("Update preflight failed:\n- " . implode("\n- ", $problems));
    }
}

function devone_core_update_write_file($source, $target, $relativePath) {
    $parent = dirname($target);
    devone_core_update_mkdir($parent);
    $oldMode = is_file($target) ? (@fileperms($target) & 0777) : 0644;
    $errors = array();

    // Preferred atomic replacement when the parent directory permits it.
    if (is_writable($parent)) {
        $temp = $parent . '/.' . basename($target) . '.devone-' . bin2hex(random_bytes(4));
        if (@copy($source, $temp)) {
            @chmod($temp, $oldMode ?: 0644);
            if (@rename($temp, $target)) { return; }
            $last = error_get_last();
            $errors[] = 'atomic rename failed' . ($last ? ': ' . $last['message'] : '');
            @unlink($temp);
        } else {
            $last = error_get_last();
            $errors[] = 'staging copy failed' . ($last ? ': ' . $last['message'] : '');
        }
    }

    // Fallback for hosts where the existing file is writable but its directory is not.
    if (is_file($target) && is_writable($target)) {
        $data = @file_get_contents($source);
        if ($data !== false && @file_put_contents($target, $data, LOCK_EX) !== false) {
            @chmod($target, $oldMode ?: 0644);
            return;
        }
        $last = error_get_last();
        $errors[] = 'direct write failed' . ($last ? ': ' . $last['message'] : '');
    }

    // Last portable fallback.
    if (@copy($source, $target)) {
        @chmod($target, $oldMode ?: 0644);
        return;
    }
    $last = error_get_last();
    $errors[] = 'copy failed' . ($last ? ': ' . $last['message'] : '');

    $details = devone_core_update_permission_details($target);
    devone_core_update_log('install_file_failed', array('file' => $relativePath, 'errors' => $errors, 'permissions' => $details));
    throw new RuntimeException(devone_core_update_format_permission_error($relativePath, $details) . ' Technical detail: ' . implode('; ', $errors));
}

function devone_core_update_install($zipPath, $release = array()) {
    $lock = devone_core_update_storage('update.lock');
    $handle = @fopen($lock, 'x');
    if (!$handle) { throw new RuntimeException('Another DevOne update is already running.'); }
    fwrite($handle, json_encode(array('started_at' => gmdate('c'), 'version' => $release['latest_version'] ?? 'unknown')));
    fclose($handle);

    $backupDir = devone_core_update_storage('backups/' . gmdate('Ymd-His') . '-' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string)($release['latest_version'] ?? 'update')));
    $stageDir = devone_core_update_storage('staging/' . bin2hex(random_bytes(6)));
    $applied = array();
    $created = array();

    try {
        devone_core_update_mkdir($stageDir);
        devone_core_update_mkdir($backupDir);
        devone_core_update_log('install_started', array('zip' => $zipPath, 'release' => $release['latest_version'] ?? 'unknown'));

        $scanError=''; $scanReport=array();
        if (function_exists('devone_package_scan_zip') && !devone_package_scan_zip($zipPath,$scanError,$scanReport)) { throw new RuntimeException($scanError ?: 'Update ZIP failed security validation.'); }
        if (!function_exists('safe_zip_extract_with_error') || !safe_zip_extract_with_error($zipPath,$stageDir,$scanError)) { throw new RuntimeException($scanError ?: 'Unable to stage update package.'); }

        $manifestPath = $stageDir . '/update.json';
        if (!is_file($manifestPath)) { throw new RuntimeException('update.json is missing.'); }
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest) || ($manifest['type'] ?? '') !== 'devone-core-update') { throw new RuntimeException('Invalid update manifest.'); }
        if (($manifest['channel'] ?? 'stable') !== 'stable') { throw new RuntimeException('Only Stable DevOne updates are accepted.'); }
        $targetVersion=trim((string)($manifest['version']??''));
        if($targetVersion==='') { throw new RuntimeException('Update manifest target version is missing.'); }
        if(empty($manifest['files']) || !is_array($manifest['files'])) { throw new RuntimeException('Update manifest does not declare any files.'); }

        $current = function_exists('devone_core_version') ? devone_core_version() : '0.0.0';
        $allowed = (array)($manifest['from_versions'] ?? array());
        if ($allowed && !in_array($current, $allowed, true)) { throw new RuntimeException('This patch does not support installed version ' . $current . '.'); }
        if (!version_compare($targetVersion,$current,'>')) { throw new RuntimeException('Update target must be newer than installed Core ' . $current . '.'); }

        foreach ((array)($manifest['files'] ?? array()) as $file) {
            $path = (string)($file['path'] ?? '');
            if (!devone_core_update_safe_relative_path($path) || devone_core_update_protected($path)) { throw new RuntimeException('Protected or invalid update path: ' . $path); }
            $source = $stageDir . '/' . $path;
            if (!is_file($source)) { throw new RuntimeException('Declared update file is missing: ' . $path); }
            $expected = strtolower(trim((string)($file['sha256'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $expected)) { throw new RuntimeException('File checksum is missing or invalid: ' . $path); }
            if (!hash_equals($expected, hash_file('sha256', $source))) { throw new RuntimeException('File checksum failed: ' . $path); }
            if (substr($path, -4) === '.php') { devone_core_update_lint_php($source, $path); }
        }

        devone_core_update_preflight($manifest, $stageDir);

        foreach ((array)($manifest['files'] ?? array()) as $file) {
            $path = (string)$file['path'];
            $source = $stageDir . '/' . $path;
            $target = DEVONE_ROOT . '/' . $path;
            $existed = is_file($target);
            if ($existed) {
                $backup = $backupDir . '/' . $path;
                devone_core_update_mkdir(dirname($backup));
                if (!@copy($target, $backup)) {
                    $last = error_get_last();
                    throw new RuntimeException('Unable to back up ' . $path . ($last ? ': ' . $last['message'] : ''));
                }
            } else {
                $created[] = $path;
            }
            devone_core_update_write_file($source, $target, $path);
            $applied[] = $path;
        }

        foreach ((array)($manifest['delete'] ?? array()) as $path) {
            if (!devone_core_update_safe_relative_path($path) || devone_core_update_protected($path)) { continue; }
            $target = DEVONE_ROOT . '/' . $path;
            if (is_file($target)) {
                $backup = $backupDir . '/' . $path;
                devone_core_update_mkdir(dirname($backup));
                if (!@copy($target, $backup)) { throw new RuntimeException('Unable to back up file scheduled for deletion: ' . $path); }
                if (!@unlink($target)) { throw new RuntimeException('Unable to delete obsolete file: ' . $path); }
                $applied[] = $path . ' [deleted]';
            }
        }

        $success = array(
            'installed_at' => gmdate('c'),
            'from' => $current,
            'to' => $manifest['version'] ?? '',
            'backup' => $backupDir,
            'files' => $applied,
            'created' => $created,
        );
        @file_put_contents(devone_core_update_storage('last-success.json'), json_encode($success, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @unlink(devone_core_update_storage('latest.json'));
        devone_core_update_log('install_succeeded', $success);
        return array('ok' => true, 'version' => $manifest['version'] ?? '', 'backup' => $backupDir, 'files' => $applied);
    } catch (Throwable $e) {
        devone_core_update_log('install_failed', array('message' => $e->getMessage(), 'backup' => $backupDir, 'applied' => $applied));

        foreach (array_reverse($created) as $path) { @unlink(DEVONE_ROOT . '/' . $path); }
        if (is_dir($backupDir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($it as $item) {
                if (!$item->isFile()) { continue; }
                $rel = substr($item->getPathname(), strlen($backupDir) + 1);
                $target = DEVONE_ROOT . '/' . $rel;
                try { devone_core_update_write_file($item->getPathname(), $target, $rel . ' [rollback]'); } catch (Throwable $ignored) { @copy($item->getPathname(), $target); }
            }
        }
        throw $e;
    } finally {
        @unlink($lock);
    }
}

function devone_core_update_alert_html() {
    if (function_exists('devone_network_is_super_admin') && !devone_network_is_super_admin()) { return ''; }
    $release = devone_core_update_check(false);
    $installed = function_exists('devone_core_version') ? devone_core_version() : (defined('CMS_VERSION') ? CMS_VERSION : '0.0.0');
    $version = (string)($release['latest_version'] ?? $release['version'] ?? $installed);

    // The banner must be based on the versions themselves, not a potentially
    // stale update_available flag returned or cached before the install completed.
    if (!version_compare($installed, $version, '<')) { return ''; }
    return '<div class="devone-core-update-alert"><strong>DevOne Core ' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . ' is available.</strong><span>Official Stable update from One Marketplace.</span><a class="btn" href="core-updates.php">View and install</a></div>';
}
