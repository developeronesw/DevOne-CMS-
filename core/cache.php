<?php
/**
 * DevOneCMS Lightweight Cache Layer
 * v1.1.25
 *
 * File-based object cache for settings, theme scans, manifests, and other
 * small computed values. This is intentionally dependency-free so DevOneCMS
 * stays VPS/shared-host friendly.
 */

if (!defined('DEVONE_ROOT')) {
    define('DEVONE_ROOT', dirname(__DIR__));
}

function devone_cache_base_dir() {
    $dir = DEVONE_ROOT . '/storage/cache/devone';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}

function devone_cache_default_ttl() {
    if (defined('DEVONE_CACHE_TTL')) { return max(1, (int)DEVONE_CACHE_TTL); }
    $ttl = 300;
    try {
        if (function_exists('db') && function_exists('table_exists') && table_exists('settings')) {
            $tbl = table_name('settings');
            $stmt = db()->prepare('SELECT setting_value FROM `' . $tbl . '` WHERE setting_key=? LIMIT 1');
            $stmt->execute(array('devone_cache_ttl'));
            $raw = $stmt->fetchColumn();
            if ($raw !== false) { $ttl = max(30, min(86400, (int)$raw)); }
        }
    } catch (Throwable $e) { $ttl = 300; }
    return $ttl;
}

function devone_cache_enabled() {
    if (defined('DEVONE_CACHE_ENABLED') && DEVONE_CACHE_ENABLED === false) { return false; }
    static $enabled = null;
    if ($enabled !== null) { return $enabled; }
    $enabled = true;
    try {
        if (function_exists('db') && function_exists('table_exists') && table_exists('settings')) {
            $tbl = table_name('settings');
            $stmt = db()->prepare('SELECT setting_value FROM `' . $tbl . '` WHERE setting_key=? LIMIT 1');
            $stmt->execute(array('devone_cache_enabled'));
            $raw = $stmt->fetchColumn();
            if ($raw !== false) { $enabled = ((string)$raw) === '1'; }
        }
    } catch (Throwable $e) { $enabled = true; }
    return $enabled;
}

function devone_cache_key($group, $key) {
    $group = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$group) ?: 'default';
    return $group . '-' . sha1((string)$key) . '.cache.php';
}

function devone_cache_path($group, $key) {
    return rtrim(devone_cache_base_dir(), '/\\') . '/' . devone_cache_key($group, $key);
}

function devone_cache_get($group, $key, $default = null) {
    if (!devone_cache_enabled()) { return $default; }
    $file = devone_cache_path($group, $key);
    if (!is_file($file)) { return $default; }
    $payload = @include $file;
    if (!is_array($payload) || !array_key_exists('expires', $payload) || !array_key_exists('value', $payload)) {
        @unlink($file);
        return $default;
    }
    if ((int)$payload['expires'] !== 0 && (int)$payload['expires'] < time()) {
        @unlink($file);
        return $default;
    }
    return $payload['value'];
}

function devone_cache_set($group, $key, $value, $ttl = null) {
    if (!devone_cache_enabled()) { return false; }
    $ttl = $ttl === null ? devone_cache_default_ttl() : (int)$ttl;
    $expires = $ttl > 0 ? time() + $ttl : 0;
    $dir = devone_cache_base_dir();
    if (!is_dir($dir) || !is_writable($dir)) { return false; }
    $file = devone_cache_path($group, $key);
    $tmp = $file . '.' . getmypid() . '.tmp';
    $php = "<?php\nreturn " . var_export(array('expires'=>$expires, 'value'=>$value), true) . ";\n";
    $ok = @file_put_contents($tmp, $php, LOCK_EX) !== false;
    if (!$ok) { return false; }
    @chmod($tmp, 0664);
    return @rename($tmp, $file);
}

function devone_cache_delete($group, $key) {
    $file = devone_cache_path($group, $key);
    return is_file($file) ? @unlink($file) : true;
}

function devone_cache_remember($group, $key, $ttl, $callback) {
    $cached = devone_cache_get($group, $key, null);
    if ($cached !== null) { return $cached; }
    $value = is_callable($callback) ? $callback() : null;
    devone_cache_set($group, $key, $value, $ttl);
    return $value;
}

function devone_cache_flush($groupPrefix = '') {
    $dir = devone_cache_base_dir();
    if (!is_dir($dir)) { return 0; }
    $count = 0;
    $prefix = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$groupPrefix);
    foreach (glob($dir . '/*.cache.php') ?: array() as $file) {
        if ($prefix !== '' && strpos(basename($file), $prefix . '-') !== 0) { continue; }
        if (@unlink($file)) { $count++; }
    }
    return $count;
}

function devone_cache_stats() {
    $dir = devone_cache_base_dir();
    $files = glob($dir . '/*.cache.php') ?: array();
    $bytes = 0;
    foreach ($files as $file) { $bytes += (int)@filesize($file); }
    return array('dir'=>$dir, 'files'=>count($files), 'bytes'=>$bytes, 'enabled'=>devone_cache_enabled(), 'ttl'=>devone_cache_default_ttl());
}

function devone_cache_human_bytes($bytes) {
    $bytes = (float)$bytes;
    $units = array('B','KB','MB','GB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$bytes : number_format($bytes, 2)) . ' ' . $units[$i];
}
