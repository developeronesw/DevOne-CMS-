<?php
require_once dirname(__DIR__) . '/core/storage_preflight.php';
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_plugins');
verify_csrf();
$msg = '';
$error = '';
$plugin_base = __DIR__ . '/../content/plugins';
if (!is_dir($plugin_base)) { @mkdir($plugin_base, 0775, true); }

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


function devone_admin_plugin_meta($dir, $folder) {
    $meta = array('name'=>ucwords(str_replace('-', ' ', $folder)), 'version'=>'1.0.0', 'description'=>'Installed plugin');
    if (is_file($dir . '/plugin.json')) {
        $json = json_decode((string)file_get_contents($dir . '/plugin.json'), true);
        if (is_array($json)) { $meta = array_merge($meta, $json); }
    }
    return $meta;
}

function devone_admin_plugin_folder_slug($manualFolder, $meta, $root, $zipName) {
    $manual = trim((string)$manualFolder);
    if ($manual !== '') { return devone_slug($manual); }

    // DevOne-style: package metadata wins over the ZIP root folder.
    foreach (array('install_folder','folder','slug','id') as $key) {
        if (!empty($meta[$key])) { return devone_slug($meta[$key]); }
    }
    if (!empty($meta['name'])) { return devone_slug($meta['name']); }

    // Then use the root folder if it looks intentional. Avoid generic extractor roots like "item".
    $rootBase = basename(rtrim((string)$root, '/\\'));
    if ($rootBase !== '' && !in_array(strtolower($rootBase), array('item','plugin','package','upload','extract'), true)) {
        return devone_slug($rootBase);
    }

    $zipBase = pathinfo((string)$zipName, PATHINFO_FILENAME);
    $zipBase = preg_replace('/^(devonecms[-_ ]*)?(plugin|package)[-_ ]*/i', '', $zipBase);
    return devone_slug($zipBase ?: 'plugin');
}

function devone_admin_upsert_plugin($name, $folder, $version, $description, $active = 1) {
    if (!table_exists('plugins')) { return false; }
    try {
        $check = db()->prepare('SELECT id FROM `' . table_name('plugins') . '` WHERE folder=? LIMIT 1');
        $check->execute(array($folder));
        $id = (int)$check->fetchColumn();
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE `' . table_name('plugins') . '` SET name=?, version=?, description=?, active=? WHERE id=?');
            return $stmt->execute(array($name, $version, $description, (int)$active, $id));
        }
        $stmt = db()->prepare('INSERT INTO `' . table_name('plugins') . '` (name,folder,version,description,active) VALUES (?,?,?,?,?)');
        return $stmt->execute(array($name, $folder, $version, $description, (int)$active));
    } catch (Throwable $e) { return false; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $folder = devone_slug($_POST['folder'] ?? '');

    if ($action === 'upload_plugin') {
        $uploadError = devone_admin_upload_file_error($_FILES['plugin_zip'] ?? array(), 'plugin ZIP');
        if ($uploadError !== '') {
            $error = $uploadError;
        } elseif (!is_uploaded_file($_FILES['plugin_zip']['tmp_name'] ?? '')) {
            $error = 'No valid plugin ZIP was uploaded.';
        } elseif (!devone_admin_ensure_writable_dir(__DIR__ . '/../storage/cache', 'storage/cache', $error)) {
            // $error set by helper.
        } elseif (!devone_admin_ensure_writable_dir($plugin_base, 'content/plugins', $error)) {
            // $error set by helper.
        } else {
            $tmp = __DIR__ . '/../storage/cache/plugin-upload-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            if (devone_admin_ensure_writable_dir($tmp, 'temporary plugin extraction folder', $error)) {
                $extractError = '';
                $extractOk = function_exists('safe_zip_extract_with_error') ? safe_zip_extract_with_error($_FILES['plugin_zip']['tmp_name'], $tmp, $extractError) : safe_zip_extract($_FILES['plugin_zip']['tmp_name'], $tmp);
                if (!$extractOk) {
                    $error = $extractError ?: 'ZIP extraction failed or unsafe paths were detected.';
                } else {
                    list($root, $detectedType) = function_exists('devone_store_find_package_root') ? devone_store_find_package_root($tmp, 'plugin') : array($tmp, 'plugin');
                    if ($root === '') { $root = $tmp; }
                    $meta = devone_admin_plugin_meta($root, devone_slug(pathinfo($_FILES['plugin_zip']['name'], PATHINFO_FILENAME)));
                    $folder = devone_admin_plugin_folder_slug($_POST['plugin_folder'] ?? '', $meta, $root, $_FILES['plugin_zip']['name'] ?? 'plugin.zip');
                    if ($folder === '' || $folder === 'item') { $folder = devone_slug($meta['name'] ?? pathinfo($_FILES['plugin_zip']['name'], PATHINFO_FILENAME)); }
                    $dest = $plugin_base . '/' . $folder;
                    if (!is_file($root . '/plugin.php')) {
                        $error = 'Plugin package is missing plugin.php. A valid DevOneCMS plugin must include plugin.php and optional plugin.json.';
                    } elseif (is_dir($dest) && empty($_POST['overwrite_plugin'])) {
                        $error = 'Plugin folder already exists. Check overwrite to replace it.';
                    } else {
                        if (is_dir($dest) && function_exists('devone_store_rrmdir')) { devone_store_rrmdir($dest); }
                        if (!function_exists('devone_store_rcopy') || !devone_store_rcopy($root, $dest)) {
                            $error = 'Could not copy plugin files. Check content/plugins permissions.';
                        } else {
                            $meta['folder'] = $folder;
                            $meta['slug'] = !empty($meta['slug']) ? $meta['slug'] : $folder;
                            $meta['type'] = !empty($meta['type']) ? $meta['type'] : 'plugin';
                            $meta['main'] = !empty($meta['main']) ? $meta['main'] : 'plugin.php';
                            if (!is_file($dest . '/plugin.json')) { @file_put_contents($dest . '/plugin.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
                            devone_admin_upsert_plugin($meta['name'] ?? ucwords(str_replace('-', ' ', $folder)), $folder, $meta['version'] ?? '1.0.0', $meta['description'] ?? '', 1);
                            if (function_exists('devone_invalidate_active_plugin_cache')) { devone_invalidate_active_plugin_cache(); }
                            if (function_exists('devone_invalidate_plugin_admin_registry')) { devone_invalidate_plugin_admin_registry(); }
                            if (function_exists('devone_plugin_lifecycle_run')) { devone_plugin_lifecycle_run($folder, 'update'); }
                            devone_log('plugin_uploaded', $folder);
                            $msg = 'Plugin installed and activated: ' . ($meta['name'] ?? $folder);
                        }
                    }
                }
            }
            if (function_exists('devone_store_rrmdir')) { devone_store_rrmdir($tmp); }
        }
    }

    if ($action === 'activate_plugin' && $folder !== '') {
        db()->prepare('UPDATE `' . table_name('plugins') . '` SET active=1 WHERE folder=?')->execute(array($folder));
        if (function_exists('devone_invalidate_active_plugin_cache')) { devone_invalidate_active_plugin_cache(); }
        if (function_exists('devone_invalidate_plugin_admin_registry')) { devone_invalidate_plugin_admin_registry(); }
        if (function_exists('devone_plugin_lifecycle_run')) { devone_plugin_lifecycle_run($folder, 'activate'); }
        devone_log('plugin_activated', $folder);
        $msg = 'Plugin activated: ' . $folder;
    }

    if ($action === 'deactivate_plugin' && $folder !== '') {
        if (function_exists('devone_plugin_lifecycle_run')) { devone_plugin_lifecycle_run($folder, 'deactivate'); }
        db()->prepare('UPDATE `' . table_name('plugins') . '` SET active=0 WHERE folder=?')->execute(array($folder));
        if (function_exists('devone_invalidate_active_plugin_cache')) { devone_invalidate_active_plugin_cache(); }
        if (function_exists('devone_invalidate_plugin_admin_registry')) { devone_invalidate_plugin_admin_registry(); }
        devone_log('plugin_deactivated', $folder);
        $msg = 'Plugin deactivated: ' . $folder;
    }

    if ($action === 'uninstall_plugin' && $folder !== '') {
        $dir = $plugin_base . '/' . $folder;
        if (is_dir($dir) && function_exists('devone_store_rrmdir')) { devone_store_rrmdir($dir); }
        db()->prepare('DELETE FROM `' . table_name('plugins') . '` WHERE folder=?')->execute(array($folder));
        if (function_exists('devone_invalidate_active_plugin_cache')) { devone_invalidate_active_plugin_cache(); }
        if (function_exists('devone_invalidate_plugin_admin_registry')) { devone_invalidate_plugin_admin_registry(); }
        if (function_exists('devone_purge_after_delete')) { devone_purge_after_delete('plugin'); }
        devone_log('plugin_uninstalled', $folder);
        $msg = 'Plugin uninstalled and files removed: ' . $folder;
    }
}

$plugins = table_exists('plugins') ? db()->query('SELECT * FROM `' . table_name('plugins') . '` ORDER BY id DESC')->fetchAll() : array();
devone_admin_header('Plugins - DevOneCMS');
?>
<h1>Plugins</h1><?php echo devone_storage_preflight_notice_html(true); ?>
<p class="muted">Install, activate, deactivate, and uninstall plugins so DevOneCMS stays clean instead of collecting unused files.</p>
<?php devone_flash($msg); ?>
<?php devone_flash($error, 'card error-card'); ?>

<form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_plugin">
    <h3>Upload Plugin ZIP</h3>
    <p>Include an optional <code>plugin.json</code> file for name, slug, version, and description.</p>
    <label>Plugin ZIP<input type="file" name="plugin_zip" accept=".zip" required></label>
    <label>Folder slug, optional<input name="plugin_folder" placeholder="Leave blank for auto-detect"></label>
    <label class="inline-check"><input type="checkbox" name="overwrite_plugin" value="1"> Overwrite if folder exists</label>
    <button>Upload Plugin ZIP</button>
</form>

<div class="devone-desktop-table">
<table class="table">
    <tr class="table-head-row"><th>Name</th><th>Folder</th><th>Version</th><th>Status</th><th>Actions</th></tr>
    <?php foreach($plugins as $p): $folder = devone_slug($p['folder'] ?? ''); ?>
    <tr>
        <td data-label="Name"><?= e($p['name'] ?? '') ?></td>
        <td data-label="Folder"><code><?= e($folder) ?></code></td>
        <td data-label="Version"><?= e($p['version'] ?? '') ?></td>
        <td data-label="Status"><?= !empty($p['active']) ? 'Active' : 'Inactive' ?></td>
        <td data-label="Actions" class="inline-actions">
            <?php if (!empty($p['active'])): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate_plugin"><input type="hidden" name="folder" value="<?= e($folder) ?>"><button>Deactivate</button></form>
            <?php else: ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="activate_plugin"><input type="hidden" name="folder" value="<?= e($folder) ?>"><button>Activate</button></form>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Uninstall this plugin and remove its files?');"><?= csrf_field() ?><input type="hidden" name="action" value="uninstall_plugin"><input type="hidden" name="folder" value="<?= e($folder) ?>"><button class="danger">Uninstall</button></form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>

<div class="devone-mobile-cards devone-plugin-mobile-cards" aria-label="Installed plugins">
    <?php foreach($plugins as $p): $folder = devone_slug($p['folder'] ?? ''); ?>
    <details class="devone-mobile-card">
        <summary>
            <span class="devone-mobile-card-icon">🔌</span>
            <span class="devone-mobile-card-title-wrap">
                <strong><?= e($p['name'] ?? '') ?></strong>
                <small><?= !empty($p['active']) ? 'Active' : 'Inactive' ?> · v<?= e($p['version'] ?? '') ?></small>
            </span>
            <span class="devone-mobile-card-arrow">⌄</span>
        </summary>
        <div class="devone-mobile-card-body">
            <div class="devone-mobile-meta-grid">
                <span>Folder</span><code><?= e($folder) ?></code>
                <span>Version</span><strong><?= e($p['version'] ?? '') ?></strong>
                <span>Status</span><strong><?= !empty($p['active']) ? 'Active' : 'Inactive' ?></strong>
            </div>
            <div class="devone-mobile-card-actions inline-actions">
                <?php if (!empty($p['active'])): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate_plugin"><input type="hidden" name="folder" value="<?= e($folder) ?>"><button>Deactivate</button></form>
                <?php else: ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="activate_plugin"><input type="hidden" name="folder" value="<?= e($folder) ?>"><button>Activate</button></form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Uninstall this plugin and remove its files?');"><?= csrf_field() ?><input type="hidden" name="action" value="uninstall_plugin"><input type="hidden" name="folder" value="<?= e($folder) ?>"><button class="danger">Uninstall</button></form>
            </div>
        </div>
    </details>
    <?php endforeach; ?>
</div>
<?php devone_admin_footer();
