<?php
if (is_file(__DIR__ . '/package-integrity.php')) { require_once __DIR__ . '/package-integrity.php'; }
function devone_is_https_request() { $forwarded=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??'')); return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || ((string)($_SERVER['SERVER_PORT']??'')==='443') || $forwarded==='https'; }
function devone_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) { return true; }
    if (session_status() !== PHP_SESSION_NONE) { return false; }
    // Session cookie/INI settings must be applied before any output. If a legacy
    // integration emitted output too early, fail quietly rather than flooding logs
    // with headers-already-sent warnings or weakening the configured cookie policy.
    if (!headers_sent()) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        if (devone_is_https_request()) { @ini_set('session.cookie_secure', '1'); }
        if (PHP_VERSION_ID >= 70300) {
            @session_set_cookie_params(array('lifetime'=>0,'path'=>'/','secure'=>devone_is_https_request(),'httponly'=>true,'samesite'=>'Lax'));
        }
    }
    return @session_start();
}
function csrf_token() {
    devone_start_session();
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf_token'];
}
function csrf_field() { return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">'; }
function devone_request_csrf_token() {
    if (isset($_POST['csrf_token'])) { return (string)$_POST['csrf_token']; }
    if (isset($_POST['_csrf'])) { return (string)$_POST['_csrf']; }
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) { return (string)$_SERVER['HTTP_X_CSRF_TOKEN']; }
    return '';
}
function devone_verify_csrf_token($token = null) {
    devone_start_session();
    $token = $token === null ? devone_request_csrf_token() : (string)$token;
    return $token !== '' && !empty($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}
function verify_csrf() {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, array('POST','PUT','PATCH','DELETE'), true) && !devone_verify_csrf_token()) {
        http_response_code(403);
        if (strpos(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok'=>false,'error'=>'Invalid or expired security token.'));
        } else { echo 'Invalid or expired security token.'; }
        exit;
    }
    return true;
}
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function devone_admin_required() {
    devone_start_session();
    if (empty($_SESSION['loggedin']) || empty($_SESSION['user_id'])) {
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE) { @session_destroy(); }
        header('Location: index.php?msg=' . rawurlencode('Please log in again.'));
        exit;
    }
    if (function_exists('devone_require_table')) {
        try {
            $tbl = devone_require_table('users', false);
            if ($tbl !== '') {
                $stmt = db()->prepare('SELECT status FROM `' . $tbl . '` WHERE id=? LIMIT 1');
                $stmt->execute(array((int)$_SESSION['user_id']));
                $status = $stmt->fetchColumn();
                if ($status === false || strtolower((string)$status) === 'disabled') {
                    $_SESSION = array();
                    if (session_status() === PHP_SESSION_ACTIVE) { @session_destroy(); }
                    header('Location: index.php?msg=' . rawurlencode('Your session expired or your account is disabled.'));
                    exit;
                }
            }
        } catch (Exception $e) { /* do not fatal during early install/repair */ }
    }
}

function devone_session_cookie_present() {
    if (session_status() === PHP_SESSION_ACTIVE) { return true; }
    $name = session_name();
    return $name !== '' && isset($_COOKIE[$name]) && (string)$_COOKIE[$name] !== '';
}

function devone_current_user_id() {
    // Anonymous public requests must stay sessionless. Starting a PHP session merely
    // to discover that no user is logged in emits PHPSESSID and prevents safe edge
    // caching of otherwise public pages. Existing authenticated sessions are still
    // resumed whenever their session cookie is present.
    if (session_status() !== PHP_SESSION_ACTIVE && !devone_session_cookie_present()) { return 0; }
    devone_start_session();
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
}

function devone_current_user() {
    $uid = devone_current_user_id();
    if ($uid <= 0 || !function_exists('devone_require_table')) { return null; }
    $tbl = devone_require_table('users', false);
    if ($tbl === '') { return null; }
    try {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE id=? LIMIT 1');
        $stmt->execute(array($uid));
        $user = $stmt->fetch();
        return $user ?: null;
    } catch (Exception $e) { return null; }
}

function devone_permissions_catalog() {
    return array(
        'admin_all' => 'Super Admin / all permissions',
        'manage_dashboard' => 'View dashboard',
        'create_pages' => 'Create pages',
        'edit_pages' => 'Edit pages',
        'publish_pages' => 'Publish pages',
        'manage_pages' => 'Manage all pages',
        'create_posts' => 'Create posts',
        'edit_posts' => 'Edit posts',
        'publish_posts' => 'Publish posts',
        'upload_media' => 'Upload media',
        'manage_media' => 'Manage own media',
        'view_all_media' => 'View/manage all users media',
        'manage_menus' => 'Manage menus',
        'manage_themes' => 'Manage themes',
        'manage_plugins' => 'Manage plugins',
        'manage_libraries' => 'Manage libraries and CDN assets',
        'manage_store' => 'Use DevOne Store / Marketplace',
        'manage_modules' => 'Manage modules',
        'manage_api' => 'Manage API builder',
        'manage_files' => 'Manage file manager',
        'manage_backups' => 'Backup and restore',
        'manage_users' => 'Manage users, roles, and permissions',
        'manage_settings' => 'Manage settings',
        'manage_sites' => 'Manage DevOne Network sites',
        'manage_site_domain' => 'Manage assigned site domain',
        'view_logs' => 'View system logs',
    );
}

function devone_normalize_permissions($value) {
    if (is_array($value)) { $perms = $value; }
    else {
        $decoded = json_decode((string)$value, true);
        if (is_array($decoded)) { $perms = $decoded; }
        else { $perms = array_filter(array_map('trim', explode(',', (string)$value))); }
    }
    $out = array();
    foreach ($perms as $perm) {
        $perm = trim((string)$perm);
        if ($perm !== '') { $out[] = $perm; }
    }
    return array_values(array_unique($out));
}

function devone_role_permissions($roleName) {
    $roleName = trim((string)$roleName);
    if ($roleName === '') { return array(); }
    $tbl = function_exists('devone_require_table') ? devone_require_table('roles', false) : '';
    if ($tbl === '') { return $roleName === 'admin' ? array('admin_all','*') : array(); }
    try {
        $stmt = db()->prepare('SELECT permissions FROM `' . $tbl . '` WHERE name=? LIMIT 1');
        $stmt->execute(array($roleName));
        $raw = $stmt->fetchColumn();
        if ($raw === false && $roleName === 'admin') { return array('admin_all','*'); }
        return devone_normalize_permissions($raw);
    } catch (Exception $e) { return $roleName === 'admin' ? array('admin_all','*') : array(); }
}

function devone_user_permissions($user = null) {
    if (!$user) { $user = devone_current_user(); }
    if (!$user) { return array(); }
    $rolePerms = devone_role_permissions($user['role'] ?? '');
    $overrides = array();
    if (isset($user['permissions_override'])) { $overrides = devone_normalize_permissions($user['permissions_override']); }
    return array_values(array_unique(array_merge($rolePerms, $overrides)));
}

function devone_has_permission($permission, $user = null) {
    if (!$user) { $user = devone_current_user(); }
    if (!$user) { return false; }
    $permission = trim((string)$permission);

    // Resolve true installation-wide super administrators before applying any
    // client-site boundary. This preserves the legacy admin/admin_all contract.
    $role = strtolower((string)($user['role'] ?? ''));
    $perms = devone_user_permissions($user);
    $isSuperAdmin = $role === 'admin' || in_array('*', $perms, true) || in_array('admin_all', $perms, true);
    if ($isSuperAdmin) { return true; }

    // A user explicitly assigned as a Network client Site Admin is constrained
    // to the site's safe capability contract even if their older/global custom
    // role happens to carry an installation-wide capability such as
    // manage_plugins. This makes the Sites UI promise (assigned site only)
    // enforceable instead of advisory.
    if ($permission !== '' && function_exists('devone_network_enabled') && devone_network_enabled()
        && function_exists('devone_user_is_direct_site_admin')
        && function_exists('devone_admin_current_site_id')) {
        $uid = (int)($user['id'] ?? 0);
        $siteId = (int)devone_admin_current_site_id();
        if ($uid > 0 && $siteId > 0 && devone_user_is_direct_site_admin($uid, $siteId)) {
            if (function_exists('devone_site_admin_blocked_permissions')
                && in_array($permission, devone_site_admin_blocked_permissions(), true)) {
                return false;
            }
            if (function_exists('devone_site_admin_has_permission')
                && devone_site_admin_has_permission($permission, $user)) {
                return true;
            }
            // Assigned client Site Admins must not inherit unrelated global/custom
            // role capabilities that have no proven site-scoped storage boundary.
            // Installation-wide admins were resolved above and are unaffected.
            return false;
        }
    }

    return in_array($permission, $perms, true);
}

function devone_require_permission($permission) {
    devone_admin_required();
    if (!devone_has_permission($permission)) {
        http_response_code(403);
        $title = 'Permission required';
        if (function_exists('devone_admin_header')) { devone_admin_header($title . ' - DevOneCMS'); }
        echo '<h1>Permission required</h1><div class="card error-card"><strong>You do not have permission to access this section.</strong><br>Required permission: <code>' . e($permission) . '</code></div>';
        if (function_exists('devone_admin_footer')) { devone_admin_footer(); }
        exit;
    }
}

function devone_user_can_view_all_media() { return devone_has_permission('view_all_media') || devone_has_permission('admin_all'); }
function devone_user_can_upload_media() { return devone_has_permission('upload_media') || devone_has_permission('manage_media') || devone_has_permission('view_all_media'); }


function devone_role_is_safe_for_public_registration($roleName) {
    $roleName = devone_slugify((string)$roleName, '');
    if ($roleName === '' || in_array(strtolower($roleName), array('admin','developer'), true)) { return false; }
    return count(devone_role_permissions($roleName)) === 0;
}
function devone_safe_public_registration_role($candidate = 'subscriber') {
    $candidate = devone_slugify((string)$candidate, 'subscriber');
    return devone_role_is_safe_for_public_registration($candidate) ? $candidate : '';
}
function devone_login_rate_state($identity, $recordFailure = false, $clear = false) {
    $identity = trim((string)$identity);
    $dir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/storage/security/login-rate';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $file = $dir . '/' . hash('sha256', $identity !== '' ? $identity : 'unknown') . '.json';
    if ($clear) { @unlink($file); return array('allowed'=>true,'remaining'=>10,'retry_after'=>0); }
    $now = time(); $window = 900; $limit = 10; $row = array('started'=>$now,'count'=>0);
    $fh = @fopen($file, 'c+');
    if (!$fh) { return array('allowed'=>true,'remaining'=>$limit,'retry_after'=>0); }
    @flock($fh, LOCK_EX); rewind($fh); $raw = stream_get_contents($fh); $old = json_decode((string)$raw, true);
    if (is_array($old) && (int)($old['started']??0) > ($now-$window)) { $row=$old; }
    else { $row=array('started'=>$now,'count'=>0); }
    if ($recordFailure) { $row['count']=(int)$row['count']+1; }
    $retry=max(0, ((int)$row['started']+$window)-$now); $allowed=((int)$row['count']<$limit);
    ftruncate($fh,0); rewind($fh); fwrite($fh,json_encode($row)); fflush($fh); @flock($fh,LOCK_UN); fclose($fh); @chmod($file,0640);
    return array('allowed'=>$allowed,'remaining'=>max(0,$limit-(int)$row['count']),'retry_after'=>$allowed?0:$retry);
}

function safe_zip_extract($zipPath, $dest) {
    $error = '';
    return safe_zip_extract_with_error($zipPath, $dest, $error);
}

function safe_zip_extract_with_error($zipPath, $dest, &$error = '') {
    return safe_zip_extract_with_limits($zipPath, $dest, function_exists('devone_package_limits') ? devone_package_limits() : array(), $error);
}

function safe_zip_extract_with_limits($zipPath, $dest, $limits, &$error = '') {
    $error = '';
    $zipPath = (string)$zipPath;
    $dest = rtrim((string)$dest, DIRECTORY_SEPARATOR);

    if ($zipPath === '' || !is_file($zipPath)) { $error = 'ZIP file was not found.'; return false; }
    if (filesize($zipPath) < 4) { $error = 'Downloaded package is empty or too small to be a ZIP file.'; return false; }

    $fh = @fopen($zipPath, 'rb');
    $sig = $fh ? @fread($fh, 4) : '';
    if ($fh) { @fclose($fh); }
    if ($sig !== "PK\x03\x04" && $sig !== "PK\x05\x06" && $sig !== "PK\x07\x08") {
        $preview = @file_get_contents($zipPath, false, null, 0, 180);
        $preview = trim(preg_replace('/\s+/', ' ', (string)$preview));
        $error = 'Downloaded package is not a valid ZIP file. The marketplace may have returned an error page instead of a package.';
        if ($preview !== '') { $error .= ' Response preview: ' . substr($preview, 0, 140); }
        return false;
    }

    if (function_exists('devone_package_scan_zip_with_limits')) { $scan=array(); if (!devone_package_scan_zip_with_limits($zipPath, (array)$limits, $error, $scan)) { return false; } }
    elseif (function_exists('devone_package_scan_zip')) { $scan=array(); if (!devone_package_scan_zip($zipPath, $error, $scan)) { return false; } }

    if (!is_dir($dest) && !@mkdir($dest, 0775, true)) { $error = 'Unable to create extraction folder: ' . $dest; return false; }
    @chmod($dest, 0775);
    if (!is_writable($dest)) { $error = 'Extraction folder is not writable by PHP: ' . $dest; return false; }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== TRUE) { $error = 'PHP ZipArchive could not open this package.'; return false; }
        for ($i=0; $i<$zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $reason = '';
            if (!devone_zip_entry_is_safe($name, $reason)) {
                $zip->close();
                $error = 'Unsafe ZIP path detected: ' . $name . ($reason ? ' (' . $reason . ')' : '');
                return false;
            }
            if (function_exists('devone_ziparchive_entry_is_symlink') && devone_ziparchive_entry_is_symlink($zip, $i)) {
                $zip->close();
                $error = 'Symbolic links are not allowed in installable ZIP packages: ' . $name;
                return false;
            }
        }
        $ok = $zip->extractTo($dest);
        $zip->close();
        if (!$ok) { $error = 'ZIP extraction failed. Check folder permissions and package validity.'; return false; }
        if (function_exists('devone_verify_extracted_manifest') && !devone_verify_extracted_manifest($dest, $error)) { return false; }
        return true;
    }

    $ok = devone_zip_extract_fallback($zipPath, $dest, $error);
    if ($ok && function_exists('devone_verify_extracted_manifest') && !devone_verify_extracted_manifest($dest, $error)) { return false; }
    return $ok;
}

function devone_zip_entry_is_safe($name, &$reason = '') {
    $reason = '';
    $name = str_replace('\\', '/', (string)$name);
    if ($name === '') { $reason = 'empty path'; return false; }
    if (strpos($name, "\0") !== false) { $reason = 'null byte'; return false; }
    if (substr($name, 0, 1) === '/') { $reason = 'absolute path'; return false; }
    if (preg_match('/^[a-zA-Z]:/', $name)) { $reason = 'drive path'; return false; }
    $parts = explode('/', $name);
    foreach ($parts as $part) {
        if ($part === '..') { $reason = 'parent directory traversal'; return false; }
    }
    return true;
}

function devone_zip_read_le16($data, $offset) {
    $part = substr($data, $offset, 2);
    if (strlen($part) !== 2) { return 0; }
    $u = unpack('v', $part);
    return (int)$u[1];
}

function devone_zip_read_le32($data, $offset) {
    $part = substr($data, $offset, 4);
    if (strlen($part) !== 4) { return 0; }
    $u = unpack('V', $part);
    return (int)$u[1];
}

function devone_zip_extract_fallback($zipPath, $dest, &$error = '') {
    $blob = @file_get_contents($zipPath);
    if ($blob === false || strlen($blob) < 22) { $error = 'ZIP fallback could not read package data.'; return false; }

    $eocdPos = strrpos($blob, "PK\x05\x06");
    if ($eocdPos === false) { $error = 'ZIP central directory was not found.'; return false; }

    $entries = devone_zip_read_le16($blob, $eocdPos + 10);
    $centralSize = devone_zip_read_le32($blob, $eocdPos + 12);
    $centralOffset = devone_zip_read_le32($blob, $eocdPos + 16);
    if ($centralOffset <= 0 || $centralOffset >= strlen($blob)) { $error = 'ZIP central directory offset is invalid.'; return false; }
    if ($centralSize <= 0) { $error = 'ZIP central directory size is invalid.'; return false; }

    $pos = $centralOffset;
    $madeAny = false;

    for ($i = 0; $i < $entries; $i++) {
        if (substr($blob, $pos, 4) !== "PK\x01\x02") { $error = "ZIP fallback central directory entry was invalid."; return false; }

        $method = devone_zip_read_le16($blob, $pos + 10);
        $compressedSize = devone_zip_read_le32($blob, $pos + 20);
        $uncompressedSize = devone_zip_read_le32($blob, $pos + 24);
        $nameLen = devone_zip_read_le16($blob, $pos + 28);
        $extraLen = devone_zip_read_le16($blob, $pos + 30);
        $commentLen = devone_zip_read_le16($blob, $pos + 32);
        $externalAttributes = devone_zip_read_le32($blob, $pos + 38);
        $localOffset = devone_zip_read_le32($blob, $pos + 42);
        $name = substr($blob, $pos + 46, $nameLen);
        $name = str_replace('\\', '/', $name);

        $reason = ''; if (!devone_zip_entry_is_safe($name, $reason)) { $error = 'Unsafe ZIP path detected: ' . $name . ($reason ? ' (' . $reason . ')' : ''); return false; }
        if (function_exists('devone_zip_external_attributes_is_symlink') && devone_zip_external_attributes_is_symlink($externalAttributes)) { $error = 'Symbolic links are not allowed in installable ZIP packages: ' . $name; return false; }

        $target = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);

        if (substr($name, -1) === '/') {
            if (!is_dir($target) && !@mkdir($target, 0775, true)) { $error = 'Could not create folder during ZIP extraction.'; return false; }
            $pos += 46 + $nameLen + $extraLen + $commentLen;
            continue;
        }

        if (substr($blob, $localOffset, 4) !== "PK\x03\x04") { $error = "ZIP fallback local header was invalid."; return false; }
        $localNameLen = devone_zip_read_le16($blob, $localOffset + 26);
        $localExtraLen = devone_zip_read_le16($blob, $localOffset + 28);
        $dataOffset = $localOffset + 30 + $localNameLen + $localExtraLen;
        $compressedData = substr($blob, $dataOffset, $compressedSize);

        if ($method === 0) {
            $fileData = $compressedData;
        } elseif ($method === 8) {
            $fileData = @gzinflate($compressedData);
            if ($fileData === false) { $fileData = @gzinflate($compressedData, $uncompressedSize ?: 0); }
            if ($fileData === false) { $error = 'ZIP fallback could not inflate compressed file data.'; return false; }
        } else { $error = 'ZIP compression method is not supported: ' . $method; return false; }

        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true)) { $error = 'Could not create parent folder during ZIP extraction.'; return false; }
        if (@file_put_contents($target, $fileData) === false) { $error = 'Could not write extracted file. Check permissions.'; return false; }
        $madeAny = true;

        $pos += 46 + $nameLen + $extraLen + $commentLen;
    }

    if (!$madeAny) { $error = 'ZIP package had no extractable files.'; }
    return $madeAny;
}
