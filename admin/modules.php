<?php
require_once dirname(__DIR__) . '/core/storage_preflight.php';
require __DIR__ . '/includes/admin_common.php';
verify_csrf();
devone_require_permission('manage_modules');

$msg = $_GET['msg'] ?? '';
$error = '';
$moduleBase = function_exists('devone_module_base_path') ? devone_module_base_path() : (__DIR__ . '/../content/modules');
$cacheBase = __DIR__ . '/../storage/cache';
if (!is_dir($moduleBase)) { @mkdir($moduleBase, 0775, true); }
if (!is_dir($cacheBase)) { @mkdir($cacheBase, 0775, true); }

function devone_admin_module_upload_error($file) {
    if (empty($file) || !is_array($file)) { return 'Choose a module ZIP file first.'; }
    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_OK) { return ''; }
    $map = array(
        UPLOAD_ERR_INI_SIZE => 'The uploaded module is larger than the server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded module is larger than the form limit.',
        UPLOAD_ERR_PARTIAL => 'The module upload only partially completed.',
        UPLOAD_ERR_NO_FILE => 'Choose a module ZIP file first.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload temp folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded module to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
    );
    return $map[$code] ?? ('Upload failed with PHP error code ' . $code . '.');
}

function devone_admin_module_remove_temp($dir, $base) {
    $real = realpath($dir); $baseReal = realpath($base);
    if (!$real || !$baseReal) { return; }
    $norm = str_replace('\\', '/', $real); $baseNorm = rtrim(str_replace('\\', '/', $baseReal), '/');
    if ($norm === $baseNorm || strpos($norm, $baseNorm . '/') !== 0) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) { $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
    @rmdir($real);
}

function devone_admin_module_manifest_from_root($root, &$error = '') {
    $error = '';
    if (!is_dir($root) || !is_file($root . '/module.json')) { $error = 'Module package is missing module.json.'; return array(); }
    $json = json_decode((string)@file_get_contents($root . '/module.json'), true);
    if (!is_array($json)) { $error = 'module.json contains invalid JSON.'; return array(); }
    $json['name'] = trim((string)($json['name'] ?? ''));
    if ($json['name'] === '') { $error = 'module.json must include a module name.'; return array(); }
    $json['slug'] = function_exists('devone_module_clean_slug') ? devone_module_clean_slug($json['slug'] ?? $json['name'], '') : '';
    if ($json['slug'] === '') { $error = 'module.json must include a valid slug or name.'; return array(); }
    $json['main'] = trim((string)($json['main'] ?? 'module.php')) ?: 'module.php';
    $main = str_replace('\\', '/', $json['main']);
    if (strpos($main, "\0") !== false || substr($main, 0, 1) === '/' || preg_match('/^[a-zA-Z]:/', $main) || in_array('..', explode('/', $main), true)) {
        $error = 'The declared module main file is unsafe.'; return array();
    }
    if (!is_file($root . '/' . $main) && !is_file($root . '/controller.php')) {
        $error = 'Module package is missing its declared main file (' . $main . ') and legacy controller.php.'; return array();
    }
    $json['version'] = trim((string)($json['version'] ?? '1.0.0')) ?: '1.0.0';
    $json['description'] = (string)($json['description'] ?? '');
    $json['author'] = (string)($json['author'] ?? '');
    return $json;
}

function devone_admin_module_redirect($message) {
    header('Location: modules.php?msg=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $folder = function_exists('devone_module_clean_slug') ? devone_module_clean_slug($_POST['folder'] ?? '', '') : '';

    if ($action === 'upload_module') {
        $uploadError = devone_admin_module_upload_error($_FILES['module_zip'] ?? array());
        if ($uploadError !== '') {
            $error = $uploadError;
        } elseif (!is_uploaded_file($_FILES['module_zip']['tmp_name'] ?? '')) {
            $error = 'No valid module ZIP was uploaded.';
        } elseif (!is_writable($moduleBase) || !is_writable($cacheBase)) {
            $error = 'The module or cache directory is not writable by PHP. Fix server ownership and permissions.';
        } else {
            $tmp = $cacheBase . '/module-upload-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
            @mkdir($tmp, 0775, true);
            $extractError = '';
            $ok = function_exists('safe_zip_extract_with_error')
                ? safe_zip_extract_with_error($_FILES['module_zip']['tmp_name'], $tmp, $extractError)
                : safe_zip_extract($_FILES['module_zip']['tmp_name'], $tmp);
            if (!$ok) {
                $error = $extractError ?: 'The module ZIP could not be extracted safely.';
            } else {
                $root = function_exists('devone_module_find_package_root') ? devone_module_find_package_root($tmp) : '';
                if ($root === '') {
                    $error = 'No module.json file was found in the uploaded package.';
                } else {
                    $manifestError = '';
                    $manifest = devone_admin_module_manifest_from_root($root, $manifestError);
                    if (!$manifest) {
                        $error = $manifestError;
                    } else {
                        $manualFolder = trim((string)($_POST['module_folder'] ?? ''));
                        $folder = devone_module_clean_slug($manualFolder !== '' ? $manualFolder : ($manifest['slug'] ?? $manifest['name']), '');
                        $dest = rtrim($moduleBase, '/\\') . DIRECTORY_SEPARATOR . $folder;
                        $exists = is_dir($dest);
                        if ($exists && empty($_POST['overwrite_module'])) {
                            $error = 'This module folder already exists. Check overwrite to replace its files.';
                        } else {
                            $wasActive = 0;
                            if (table_exists('modules')) {
                                $check = db()->prepare('SELECT active FROM `' . table_name('modules') . '` WHERE folder=? LIMIT 1');
                                $check->execute(array($folder));
                                $wasActive = (int)($check->fetchColumn() ?: 0);
                            }
                            if ($exists && $wasActive) {
                                $lifeError = '';
                                devone_module_run_lifecycle($folder, 'deactivate', array('reason'=>'upgrade'), $lifeError);
                            }
                            if ($exists && !devone_module_rrmdir($dest)) {
                                $error = 'The existing module folder could not be removed. Check file ownership and permissions.';
                            } elseif (!devone_module_rcopy($root, $dest)) {
                                $error = 'The module files could not be copied into content/modules.';
                            } else {
                                unset($GLOBALS['devone_module_manifests'][$folder]);
                                $installedManifest = devone_module_manifest($folder, true);
                                $validationError = '';
                                if (!devone_module_validate($folder, $validationError)) {
                                    devone_module_rrmdir($dest);
                                    $error = 'Installed module validation failed: ' . $validationError;
                                } else {
                                    devone_module_upsert($folder, $installedManifest, 0, 'installed', '');
                                    $phase = $exists ? 'update' : 'install';
                                    $lifeError = '';
                                    if (!devone_module_run_lifecycle($folder, $phase, array('upgrade'=>$exists,'previously_active'=>$wasActive), $lifeError)) {
                                        devone_module_update_record($folder, array('active'=>0,'status'=>'error','last_error'=>$lifeError));
                                        $error = 'Module files were installed, but ' . $phase . '.php failed: ' . $lifeError;
                                    } else {
                                        $activate = !empty($_POST['activate_module']) || $wasActive;
                                        if ($activate) {
                                            $activateError = '';
                                            if (!devone_module_run_lifecycle($folder, 'activate', array('after_install'=>true), $activateError)) {
                                                devone_module_update_record($folder, array('active'=>0,'status'=>'error','last_error'=>$activateError));
                                                $error = 'Module installed, but activation failed: ' . $activateError;
                                            } else {
                                                devone_module_update_record($folder, array('active'=>1,'status'=>'active','last_error'=>''));
                                            }
                                        }
                                        if ($error === '') {
                                            devone_log($exists ? 'module_updated' : 'module_installed', $folder . ' v' . ($installedManifest['version'] ?? '1.0.0'));
                                            $msg = ($exists ? 'Module updated' : 'Module installed') . ($activate ? ' and activated' : '') . ': ' . ($installedManifest['name'] ?? $folder);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            devone_admin_module_remove_temp($tmp, $cacheBase);
        }
    }

    if ($action === 'activate_module' && $folder !== '') {
        $validationError = '';
        if (!devone_module_validate($folder, $validationError)) {
            $error = $validationError;
            devone_module_update_record($folder, array('active'=>0,'status'=>'error','last_error'=>$validationError));
        } else {
            $lifeError = '';
            if (!devone_module_run_lifecycle($folder, 'activate', array('manual'=>true), $lifeError)) {
                $error = 'Activation failed: ' . $lifeError;
                devone_module_update_record($folder, array('active'=>0,'status'=>'error','last_error'=>$lifeError));
            } else {
                devone_module_update_record($folder, array('active'=>1,'status'=>'active','last_error'=>''));
                devone_log('module_activated', $folder);
                $msg = 'Module activated: ' . $folder;
            }
        }
    }

    if ($action === 'deactivate_module' && $folder !== '') {
        $lifeError = '';
        if (!devone_module_run_lifecycle($folder, 'deactivate', array('manual'=>true), $lifeError)) {
            $error = 'Deactivation routine failed: ' . $lifeError;
        } else {
            devone_module_update_record($folder, array('active'=>0,'status'=>'inactive','last_error'=>''));
            devone_log('module_deactivated', $folder);
            $msg = 'Module deactivated: ' . $folder;
        }
    }

    if ($action === 'uninstall_module' && $folder !== '') {
        $record = null;
        try { $stmt = db()->prepare('SELECT * FROM `' . table_name('modules') . '` WHERE folder=? LIMIT 1'); $stmt->execute(array($folder)); $record = $stmt->fetch(); } catch (Throwable $e) {}
        $packageValidError = '';
        $packageValid = devone_module_validate($folder, $packageValidError);
        if ($packageValid && $record && !empty($record['active'])) {
            $deactivateError = '';
            if (!devone_module_run_lifecycle($folder, 'deactivate', array('reason'=>'uninstall'), $deactivateError)) {
                $error = 'Module could not be uninstalled because its deactivation routine failed: ' . $deactivateError;
            }
        }
        if ($error === '') {
            $lifeError = '';
            $purge = !empty($_POST['purge_module_data']);
            if ($packageValid && !devone_module_run_lifecycle($folder, 'uninstall', array('purge_data'=>$purge), $lifeError)) {
                $error = 'Uninstall routine failed: ' . $lifeError;
            } else {
                $dir = devone_module_root($folder);
                if ($dir !== '' && !devone_module_rrmdir($dir)) {
                    $error = 'The module files could not be removed. Check file ownership and permissions.';
                } else {
                    db()->prepare('DELETE FROM `' . table_name('modules') . '` WHERE folder=?')->execute(array($folder));
                    devone_log('module_uninstalled', $folder . ' purge_data=' . ($purge ? '1' : '0') . ($packageValid ? '' : ' invalid_package_removed=1'));
                    $msg = 'Module uninstalled: ' . $folder;
                }
            }
        }
    }

    if ($action === 'sync_modules') {
        $count = function_exists('devone_module_sync_disk') ? devone_module_sync_disk() : 0;
        $msg = 'Module registry synchronized. Packages discovered: ' . $count;
    }

    if ($action === 'create_module') {
        $starterError = '';
        $created = function_exists('devone_create_module_starter') ? devone_create_module_starter($_POST['name'] ?? '', $_POST['description'] ?? '', $starterError) : '';
        if ($created === '') { $error = $starterError ?: 'The working module could not be created.'; }
        else {
            $msg = 'Working module created: ' . $created . '. Activate it to enable its admin page and front-end route.';
            if ($starterError !== '') { $error = $starterError; }
        }
    }
}

$modules = table_exists('modules') ? db()->query('SELECT * FROM `' . table_name('modules') . '` ORDER BY name ASC, id ASC')->fetchAll() : array();
$loadErrors = $GLOBALS['devone_module_load_errors'] ?? array();
devone_admin_header('Modules - DevOne CMS');
?>
<style>
.module-manager-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:20px}.module-list{display:grid;gap:14px}.module-card{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:start}.module-meta{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.module-meta span{padding:6px 10px;border:1px solid rgba(130,95,20,.18);border-radius:999px;background:rgba(255,248,225,.72);font-size:.78rem;font-weight:800}.module-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}.module-error{margin-top:12px;padding:12px 14px;border-radius:12px;background:rgba(184,48,39,.08);color:#8c241c}.module-status-active{color:#14764b}.module-status-error{color:#a12820}.module-status-inactive{color:#756b58}@media(max-width:900px){.module-manager-grid{grid-template-columns:1fr}.module-card{grid-template-columns:1fr}.module-actions{justify-content:flex-start}}
</style>
<h1>Modules</h1>
<p class="muted">Install complete application packages with routes, admin pages, assets, permissions, and safe lifecycle routines. Active modules load automatically on public and admin requests.</p>
<?= function_exists('devone_storage_preflight_notice_html') ? devone_storage_preflight_notice_html(true) : '' ?>
<?php devone_flash($msg); devone_flash($error, 'card error-card'); ?>

<div class="module-manager-grid">
  <div>
    <form method="post" enctype="multipart/form-data" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_module">
      <h2>Install Module ZIP</h2>
      <p class="muted">A valid package includes <code>module.json</code> and its declared main PHP file. Nested ZIP folders are detected automatically.</p>
      <label>Module ZIP<input type="file" name="module_zip" accept=".zip,application/zip" required></label>
      <label>Install folder <small>(optional)</small><input name="module_folder" placeholder="Auto-detect from module.json"></label>
      <label class="checkbox-row"><input type="checkbox" name="activate_module" value="1" checked> Activate after a successful install</label>
      <label class="checkbox-row"><input type="checkbox" name="overwrite_module" value="1"> Replace an existing module with the same folder</label>
      <button>Install Module</button>
    </form>

    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="create_module">
      <h2>Create Working Module</h2>
      <p class="muted">Creates a complete runnable module with a front-end route, admin page, CSS, JavaScript, and lifecycle files—not an inactive placeholder.</p>
      <label>Name<input name="name" placeholder="Booking Manager" required></label>
      <label>Description<textarea name="description" placeholder="What this module will do"></textarea></label>
      <button>Create Working Module</button>
    </form>
  </div>

  <div class="card">
    <h2>Module Package Standard</h2>
    <pre><code>booking-manager/
├── module.json
├── module.php
├── install.php
├── activate.php
├── deactivate.php
├── uninstall.php
├── admin/
├── views/
└── assets/</code></pre>
    <p>Manifest features supported now:</p>
    <ul>
      <li>Front-end routes with URL parameters and HTTP method controls</li>
      <li>Permission-aware admin pages and automatic sidebar groups</li>
      <li>Front-end and admin CSS/JavaScript assets</li>
      <li>Install, update, activate, deactivate, and uninstall routines</li>
      <li>Legacy <code>controller.php</code> and <code>Name_boot()</code> compatibility</li>
    </ul>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_modules"><button class="secondary">Synchronize Installed Folders</button></form>
  </div>
</div>

<section class="card">
  <div class="section-head"><h2>Installed Modules</h2><span><?= number_format(count($modules)) ?> package<?= count($modules) === 1 ? '' : 's' ?></span></div>
  <div class="module-list">
    <?php if (!$modules): ?><p class="muted">No modules are installed yet.</p><?php endif; ?>
    <?php foreach ($modules as $module):
      $folder = (string)($module['folder'] ?? '');
      $manifest = function_exists('devone_module_manifest') ? devone_module_manifest($folder, true) : array();
      $validationError = '';
      $valid = function_exists('devone_module_validate') ? devone_module_validate($folder, $validationError) : false;
      $active = !empty($module['active']);
      $status = $valid ? ($active ? 'active' : 'inactive') : 'error';
      $storedError = (string)($module['last_error'] ?? '');
      $runtimeError = (string)($loadErrors[$folder] ?? '');
      $displayError = $runtimeError ?: ($validationError ?: $storedError);
      $adminPages = array_filter(function_exists('devone_module_admin_pages') ? devone_module_admin_pages() : array(), function($page) use ($folder){ return ($page['module'] ?? '') === $folder; });
    ?>
      <article class="card module-card">
        <div>
          <h3><?= e($manifest['name'] ?? $module['name'] ?? $folder) ?></h3>
          <p><?= e($manifest['description'] ?? $module['description'] ?? '') ?></p>
          <div class="module-meta">
            <span>v<?= e($manifest['version'] ?? $module['version'] ?? '1.0.0') ?></span>
            <span><?= e($folder) ?></span>
            <?php if (!empty($manifest['author'])): ?><span><?= e($manifest['author']) ?></span><?php endif; ?>
            <span class="module-status-<?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
            <span><?= count((array)($manifest['routes'] ?? array())) ?> route<?= count((array)($manifest['routes'] ?? array())) === 1 ? '' : 's' ?></span>
            <span><?= count((array)($manifest['admin_pages'] ?? array())) ?> admin page<?= count((array)($manifest['admin_pages'] ?? array())) === 1 ? '' : 's' ?></span>
          </div>
          <?php if ($displayError !== ''): ?><div class="module-error"><strong>Module error:</strong> <?= e($displayError) ?></div><?php endif; ?>
          <?php if ($active && $adminPages): $first = reset($adminPages); ?><p><a href="<?= e(devone_module_admin_url($folder, $first['slug'])) ?>">Open module dashboard →</a></p><?php endif; ?>
        </div>
        <div class="module-actions">
          <?php if ($active): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate_module"><input type="hidden" name="folder" value="<?= e($folder) ?>"><button class="secondary">Deactivate</button></form>
          <?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="activate_module"><input type="hidden" name="folder" value="<?= e($folder) ?>"><button>Activate</button></form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Uninstall this module and remove its files?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="uninstall_module"><input type="hidden" name="folder" value="<?= e($folder) ?>">
            <label class="checkbox-row"><input type="checkbox" name="purge_module_data" value="1"> Purge module data</label>
            <button class="danger">Uninstall</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php devone_admin_footer();
