<?php
$_devone_storage_preflight = dirname(__DIR__) . '/core/storage_preflight.php';
if (is_file($_devone_storage_preflight)) { require_once $_devone_storage_preflight; }
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_themes');
verify_csrf();
$msg = '';
$error = '';
$themes_dir = function_exists('devone_site_private_theme_dir') ? devone_site_private_theme_dir(function_exists('devone_admin_current_site_id') ? devone_admin_current_site_id() : 1) : (__DIR__ . '/../content/themes');
$system_themes_dir = function_exists('devone_global_theme_dir') ? devone_global_theme_dir() : (__DIR__ . '/../content/themes');
if (!is_dir($themes_dir)) { @mkdir($themes_dir, 0775, true); }
if (!is_dir($system_themes_dir)) { @mkdir($system_themes_dir, 0775, true); }
$themeEntitlements = (class_exists('DevOne') && DevOne::services()->has('theme-entitlements')) ? DevOne::service('theme-entitlements') : null;
if (!$themeEntitlements) { throw new RuntimeException('DevOne Theme Entitlements Core Service is required.'); }
$themeEntitlements->ensureSchema();

$currentThemeSiteId = function_exists('devone_content_site_id') ? (int)devone_content_site_id() : 1;
$isNetworkClientAdmin = function_exists('devone_network_enabled') && devone_network_enabled()
    && function_exists('devone_network_is_super_admin') && !devone_network_is_super_admin();
// Theme packages may contain PHP that is loaded by the active-theme runtime.
// In Network mode only the installation Super Admin may introduce executable
// theme packages. Client Site Admins may still activate/deactivate themes that
// the Super Admin has already installed/entitled for their site.
$canUploadThemePackages = !$isNetworkClientAdmin;

if (!function_exists('devone_admin_upload_file_error')) {
    function devone_admin_upload_file_error($file, $label = 'ZIP') {
        if (empty($file) || !is_array($file)) { return 'Choose a ' . $label . ' file first.'; }
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_OK) { return ''; }
        $map = array(
            UPLOAD_ERR_INI_SIZE => 'The uploaded file is larger than the server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file is larger than the form limit.',
            UPLOAD_ERR_PARTIAL => 'The upload only partially completed. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a ' . $label . ' file first.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        );
        return $map[$code] ?? ('Upload failed with PHP error code ' . $code . '.');
    }
}
if (!function_exists('devone_admin_ensure_writable_dir')) {
    function devone_admin_ensure_writable_dir($dir, $label, &$error = '') {
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
}


function devone_admin_rrmdir($dir) {
    if (function_exists('devone_store_rrmdir')) { return devone_store_rrmdir($dir); }
    if (!is_dir($dir)) { return; }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
    @rmdir($dir);
}
function devone_admin_rcopy($src, $dst) {
    if (function_exists('devone_store_rcopy')) { return devone_store_rcopy($src, $dst); }
    if (!is_dir($src)) { return false; }
    if (!is_dir($dst)) { @mkdir($dst, 0775, true); }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
        if ($item->isDir()) { if (!is_dir($target)) { @mkdir($target, 0775, true); } }
        else { @copy($item->getPathname(), $target); }
    }
    return true;
}
function devone_admin_read_theme_manifest($root) {
    $manifest = array('name' => '', 'description' => '', 'folder' => '', 'slug' => '', 'id' => '', 'version' => '1.0.0');
    $jsonFile = rtrim((string)$root, '/\\') . '/theme.json';
    if (is_file($jsonFile)) {
        $json = json_decode((string)file_get_contents($jsonFile), true);
        if (is_array($json)) { $manifest = array_merge($manifest, $json); }
    }
    return $manifest;
}
function devone_admin_theme_slug_from_zip_name($filename) {
    $filename = basename((string)$filename);
    $filename = preg_replace('/\.zip$/i', '', $filename);
    $filename = preg_replace('/^(devonecms[-_ ]*)?theme[-_ ]*/i', '', $filename);
    return devone_slugify($filename, 'custom-theme');
}
function devone_admin_theme_folder_from_install($manual, $manifest, $root, $zipFilename = '') {
    $manual = trim((string)$manual);
    if ($manual !== '') { return devone_slugify($manual, 'custom-theme'); }
    foreach (array('slug', 'folder', 'id') as $key) { if (!empty($manifest[$key])) { return devone_slugify($manifest[$key], 'custom-theme'); } }
    if (!empty($manifest['name'])) { return devone_slugify($manifest['name'], 'custom-theme'); }
    $base = basename(rtrim((string)$root, '/\\'));
    if ($base !== '' && stripos($base, 'theme-upload-') !== 0 && stripos($base, 'extract-') !== 0) { return devone_slugify($base, 'custom-theme'); }
    if ($zipFilename !== '') { return devone_admin_theme_slug_from_zip_name($zipFilename); }
    return 'custom-theme';
}
function devone_admin_find_theme_root($dir, $maxDepth = 4) {
    $dir = rtrim((string)$dir, '/\\');
    if ($dir === '' || !is_dir($dir)) { return ''; }
    if (is_file($dir . '/theme.css')) { return $dir; }
    if ($maxDepth <= 0) { return ''; }
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: array() as $child) { if (is_file($child . '/theme.css')) { return $child; } }
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: array() as $child) { $found = devone_admin_find_theme_root($child, $maxDepth - 1); if ($found !== '') { return $found; } }
    return '';
}
function devone_admin_repair_theme_folder($themeDir) {
    $themeDir = rtrim((string)$themeDir, '/\\');
    if ($themeDir === '' || !is_dir($themeDir)) { return false; }
    if (is_file($themeDir . '/theme.css')) { return true; }
    $root = devone_admin_find_theme_root($themeDir, 4);
    if ($root === '' || realpath($root) === realpath($themeDir)) { return false; }
    devone_admin_rcopy($root, $themeDir);
    return is_file($themeDir . '/theme.css');
}
function devone_admin_repair_all_theme_folders($themes_dir) {
    foreach (glob(rtrim($themes_dir, '/\\') . '/*', GLOB_ONLYDIR) ?: array() as $dir) { devone_admin_repair_theme_folder($dir); }
}
function devone_admin_fallback_theme($exclude = '') {
    if (function_exists('devone_store_default_theme_folder')) { return devone_store_default_theme_folder($exclude); }
    foreach (devone_scan_themes() as $t) { if (($t['folder'] ?? '') !== $exclude) { return $t['folder']; } }
    return '';
}

function devone_admin_theme_delete_targets($theme) {
    $theme = devone_slugify($theme, '');
    if ($theme === '') { return array(); }
    $targets = array();
    $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
    $privateBase = function_exists('devone_site_private_theme_dir') ? devone_site_private_theme_dir($siteId) : (__DIR__ . '/../content/sites/' . $siteId . '/themes');
    $systemBase = function_exists('devone_global_theme_dir') ? devone_global_theme_dir() : (__DIR__ . '/../content/themes');
    $private = rtrim($privateBase, '/\\') . '/' . $theme;
    $system = rtrim($systemBase, '/\\') . '/' . $theme;
    if (is_dir($private)) { $targets[] = array('scope'=>'private', 'base'=>$privateBase, 'path'=>$private); }
    if (is_dir($system)) { $targets[] = array('scope'=>'system', 'base'=>$systemBase, 'path'=>$system); }
    return $targets;
}
function devone_admin_safe_delete_dir($dir, $base, &$error = '') {
    $error = '';
    $realDir = realpath($dir);
    $realBase = realpath($base);
    if (!$realDir || !$realBase || !is_dir($realDir)) { return true; }
    $normDir = str_replace('\\', '/', rtrim($realDir, '/\\'));
    $normBase = str_replace('\\', '/', rtrim($realBase, '/\\'));
    if ($normDir === $normBase || strpos($normDir, $normBase . '/') !== 0) {
        $error = 'Unsafe theme delete path blocked: ' . $dir;
        return false;
    }
    devone_admin_rrmdir($realDir);
    if (is_dir($realDir)) {
        $error = 'Theme folder could not be fully deleted. Check ownership/permissions: ' . $dir;
        return false;
    }
    return true;
}
function devone_admin_purge_theme_records($theme) {
    if (!table_exists('themes')) { return; }
    $tt = table_name('themes');
    $sid = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
    if (function_exists('devone_table_has_site_id') && devone_table_has_site_id('themes')) {
        db()->prepare('DELETE FROM `' . $tt . '` WHERE folder=? AND site_id=?')->execute(array($theme, $sid));
    } else {
        db()->prepare('DELETE FROM `' . $tt . '` WHERE folder=?')->execute(array($theme));
    }
}
function devone_admin_after_theme_library_change() {
    if (function_exists('devone_cache_flush')) { devone_cache_flush('themes'); devone_cache_flush('settings'); }
}

devone_admin_repair_all_theme_folders($themes_dir);
if (!$isNetworkClientAdmin) { devone_admin_repair_all_theme_folders($system_themes_dir); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'activate_theme';
    $theme = devone_slugify($_POST['theme'] ?? '', '');

    if ($action === 'repair_theme_folders') {
        devone_admin_repair_all_theme_folders($themes_dir);
        if (!$isNetworkClientAdmin) { devone_admin_repair_all_theme_folders($system_themes_dir); }
        $msg = 'Theme folders repaired.';
    }

    if ($action === 'activate_theme') {
        $themeDir = $theme ? (function_exists('devone_find_theme_dir') ? devone_find_theme_dir($theme, function_exists('devone_content_site_id') ? devone_content_site_id() : 1) : ($themes_dir . '/' . $theme)) : '';
        if ($theme && is_dir($themeDir)) { devone_admin_repair_theme_folder($themeDir); }
        if ($theme && is_dir($themeDir) && is_file($themeDir . '/theme.css')) {
            set_setting('site_theme', $theme);
            if (table_exists('themes')) { $tt=table_name('themes'); $sid=function_exists('devone_content_site_id')?devone_content_site_id():1; $hasSite=function_exists('devone_table_has_site_id')&&devone_table_has_site_id('themes'); db()->exec('UPDATE `' . $tt . '` SET active=0' . ($hasSite ? ' WHERE site_id=' . (int)$sid : '')); devone_register_theme(ucwords(str_replace('-',' ',$theme)), $theme, 1); }
            devone_log('theme_activated', $theme);
            devone_admin_after_theme_library_change();
            $msg = 'Theme activated: ' . $theme;
        } else { $error = 'Theme folder is missing theme.css. Expected: content/sites/current-site/themes/' . e($theme ?: 'theme-folder') . '/theme.css'; }
    }

    if ($action === 'deactivate_theme') {
        $current = (string)get_setting('site_theme', '');
        if ($theme === '' || $theme !== $current) { $msg = 'Theme is already inactive.'; }
        else {
            $fallback = devone_admin_fallback_theme($theme);
            if ($fallback === '') { $error = 'No alternate theme exists. Install or activate another theme before deactivating this one.'; }
            else {
                set_setting('site_theme', $fallback);
                if (table_exists('themes')) { $tt=table_name('themes'); $sid=function_exists('devone_content_site_id')?devone_content_site_id():1; $hasSite=function_exists('devone_table_has_site_id')&&devone_table_has_site_id('themes'); db()->exec('UPDATE `' . $tt . '` SET active=0' . ($hasSite ? ' WHERE site_id=' . (int)$sid : '')); devone_register_theme(ucwords(str_replace('-',' ',$fallback)), $fallback, 1); }
                devone_log('theme_deactivated', $theme);
                devone_admin_after_theme_library_change();
                $msg = 'Theme switched off. Active theme is now: ' . $fallback;
            }
        }
    }

    if ($action === 'uninstall_theme') {
        $current = (string)DevOne::settings()->get('site_theme', '');
        $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
        if ($theme === '') { $error = 'Theme folder is missing.'; }
        elseif ($themeEntitlements->has($siteId, $theme)) {
            if ($current === $theme) {
                $fallback = devone_admin_fallback_theme($theme);
                if ($fallback === '') { $error = 'Cannot uninstall the active theme because no alternate theme exists.'; }
                else { DevOne::settings()->set('site_theme', $fallback); }
            }
            if ($error === '') {
                try {
                    $result = $themeEntitlements->revoke($siteId, $theme);
                    devone_admin_purge_theme_records($theme);
                    DevOne::cache()->flush();
                    DevOne::log()->write('theme_entitlement_revoked', $theme . ' site_id=' . $siteId);
                    $msg = !empty($result['purged_package'])
                        ? 'Theme removed from this site. Its shared package was also purged because no other site uses it.'
                        : 'Theme removed from this site. The shared package remains protected for other entitled sites.';
                } catch (Throwable $e) { $error = $e->getMessage(); }
            }
        } else {
            // Legacy private theme compatibility. Global system themes are never deleted by a site administrator.
            $privateBase = function_exists('devone_site_private_theme_dir') ? devone_site_private_theme_dir($siteId) : (__DIR__ . '/../content/sites/' . $siteId . '/themes');
            $privatePath = rtrim($privateBase, '/\\') . '/' . $theme;
            if (!is_dir($privatePath)) { $error = 'This theme is a network system theme and cannot be uninstalled from an individual site.'; }
            elseif ($current === $theme && ($fallback = devone_admin_fallback_theme($theme)) === '') { $error = 'Cannot uninstall the active theme because no alternate theme exists.'; }
            else {
                if ($current === $theme) { DevOne::settings()->set('site_theme', $fallback); }
                $deleteError='';
                if (!devone_admin_safe_delete_dir($privatePath,$privateBase,$deleteError)) { $error=$deleteError; }
                else { devone_admin_purge_theme_records($theme); DevOne::cache()->flush(); $msg='Legacy private theme removed from this site.'; }
            }
        }
    }

    if ($action === 'upload_theme') {
        if (!$canUploadThemePackages) {
            $error = 'Theme package uploads are restricted to the Network Super Admin because themes may contain executable PHP.';
        }
        $uploadError = $error === '' ? devone_admin_upload_file_error($_FILES['theme_zip'] ?? array(), 'theme ZIP') : '';
        if ($error === '' && $uploadError !== '') { $error = $uploadError; }
        if ($error !== '') { /* Network policy or upload validation blocked installation. */ }
        elseif (!is_uploaded_file($_FILES['theme_zip']['tmp_name'] ?? '')) { $error = 'No valid theme ZIP was uploaded.'; }
        elseif (!devone_admin_ensure_writable_dir(__DIR__ . '/../storage/cache', 'storage/cache', $error)) {}
        elseif (!devone_admin_ensure_writable_dir($themeEntitlements->libraryRoot(), 'shared theme package library', $error)) {}
        else {
            $tmpBase = __DIR__ . '/../storage/cache/theme-upload-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            if (devone_admin_ensure_writable_dir($tmpBase, 'temporary theme extraction folder', $error)) {
                $extractError = '';
                $extractOk = function_exists('safe_zip_extract_with_error') ? safe_zip_extract_with_error($_FILES['theme_zip']['tmp_name'], $tmpBase, $extractError) : safe_zip_extract($_FILES['theme_zip']['tmp_name'], $tmpBase);
                if (!$extractOk) { $error = $extractError ?: 'Theme ZIP could not be extracted safely.'; }
                else {
                    $root = devone_admin_find_theme_root($tmpBase);
                    if (!$root) { $error = 'Theme ZIP must contain a theme.css file at the root or inside one top-level folder.'; }
                    else {
                        $manifest = devone_admin_read_theme_manifest($root);
                        $folder = devone_admin_theme_folder_from_install($_POST['theme_folder'] ?? '', $manifest, $root, $_FILES['theme_zip']['name'] ?? '');
                        try {
                            $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
                            $userId = DevOne::auth()->id();
                            $result = $themeEntitlements->installFromDirectory($siteId, $root, $manifest, $folder, $userId, !empty($_POST['overwrite_theme']));
                            devone_register_theme($result['name'], $folder, 0);
                            DevOne::cache()->flush();
                            DevOne::events()->emit('devone_theme_entitlement_created', $siteId, $result);
                            DevOne::log()->write('theme_entitlement_created', $folder . ' hash=' . $result['hash'] . ' site_id=' . $siteId);
                            $msg = !empty($result['deduplicated'])
                                ? 'Theme installed and privately entitled to this site. An identical package already existed, so no duplicate files were stored.'
                                : 'Theme installed once in shared storage and privately entitled to this site.';
                        } catch (Throwable $e) { $error = $e->getMessage(); }
                    }
                }
            }
            devone_admin_rrmdir($tmpBase);
        }
    }

}

$current = devone_theme();
$themes = devone_scan_themes();
foreach ($themes as $themeInfo) { devone_register_theme($themeInfo['name'], $themeInfo['folder'], $themeInfo['folder'] === $current ? 1 : 0); }
devone_admin_header('Theme Library - DevOneCMS');
?>
<h1>Theme Library</h1><?php if (function_exists('devone_storage_preflight_notice_html')) { echo devone_storage_preflight_notice_html(true); } ?>
<p class="muted">Install, preview, activate, deactivate, and uninstall front-end themes. Uploaded themes are stored once by package hash and privately entitled to the current site. Other sites cannot see or activate premium themes without their own entitlement.</p>
<?php devone_flash($msg); ?>
<?php devone_flash($error, 'card error-card'); ?>

<form method="post" class="card" style="margin-bottom:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="repair_theme_folders">
    <strong>Theme ZIP Repair</strong>
    <p class="muted">Repairs nested theme ZIP folders so <code>theme.css</code> sits at the expected theme root.</p>
    <button>Repair Theme Folders</button>
</form>

<div class="theme-manager-grid">
    <section class="card">
        <h3>Install Theme ZIP</h3>
        <?php if ($canUploadThemePackages): ?>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_theme">
            <label>Theme ZIP<input type="file" name="theme_zip" accept=".zip" required></label>
            <label>Folder slug, optional<input name="theme_folder" placeholder="Leave blank for auto-detect from theme.json or ZIP name"></label>
            <label class="inline-check"><input type="checkbox" name="overwrite_theme" value="1"> Overwrite if folder exists</label>
            <button>Install Theme</button>
        </form>
        <?php else: ?>
        <p class="muted">Theme package uploads are restricted to the Network Super Admin because theme packages may contain executable PHP. You can still activate themes already installed or entitled to this site.</p>
        <?php endif; ?>
    </section>
    <section class="card">
        <h3>Keep DevOneCMS Lean</h3>
        <p class="muted">Uninstall revokes only this site’s entitlement. Shared package files are deleted automatically only when no site remains entitled. Network system themes cannot be deleted by site administrators.</p>
    </section>
</div>

<div class="theme-grid">
    <?php foreach($themes as $theme): $active = $theme['folder'] === $current; ?>
        <article class="theme-card <?= $active ? 'active-theme' : '' ?>">
            <div class="theme-shot">
                <?php if(!empty($theme['screenshot'])): ?><img src="../<?= e($theme['screenshot']) ?>" alt="<?= e($theme['name']) ?> screenshot"><?php else: ?><span><?= e($theme['name']) ?></span><?php endif; ?>
            </div>
            <div class="theme-body">
                <h3><?= e($theme['name']) ?> <?= $active ? '<small>Active</small>' : '' ?></h3>
                <p><?= e($theme['description'] ?? '') ?></p>
                <p class="muted"><code><?= e($theme['folder']) ?></code> · v<?= e($theme['version'] ?? '1.0.0') ?> · <?= e(ucfirst($theme['scope'] ?? 'theme')) ?></p>
                <div class="inline-actions">
                    <?php if(!$active): ?>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="activate_theme"><input type="hidden" name="theme" value="<?= e($theme['folder']) ?>"><button>Activate</button></form>
                    <?php else: ?>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate_theme"><input type="hidden" name="theme" value="<?= e($theme['folder']) ?>"><button>Switch Off</button></form>
                    <?php endif; ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Uninstall this theme, delete its files, and purge its database record? This cannot be undone.');"><?= csrf_field() ?><input type="hidden" name="action" value="uninstall_theme"><input type="hidden" name="theme" value="<?= e($theme['folder']) ?>"><button class="danger">Uninstall</button></form>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php devone_admin_footer();
