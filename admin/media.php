<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();
if (!devone_has_permission('manage_media') && !devone_has_permission('upload_media') && !devone_has_permission('view_all_media')) { devone_require_permission('manage_media'); }
$canUploadMedia = function_exists('devone_user_can_upload_media') ? devone_user_can_upload_media() : true;
$canViewAllMedia = function_exists('devone_user_can_view_all_media') ? devone_user_can_view_all_media() : true;
$canDeleteMedia = $canViewAllMedia || devone_has_permission('manage_media') || devone_has_permission('upload_media');
$currentUserId = function_exists('devone_current_user_id') ? devone_current_user_id() : 0;
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
devone_ensure_media_folders();
if (function_exists('devone_ensure_media_schema')) { devone_ensure_media_schema(); }
if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
$mediaCols = function_exists('devone_table_columns') ? devone_table_columns('media') : array();
if (!$canViewAllMedia && !in_array('user_id', $mediaCols, true)) {
    $error = 'Media ownership column user_id is missing. Open the Dashboard once as an admin to repair tables, then try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'upload_media';

    if ($action === 'create_folder' && !$canViewAllMedia) { $error = 'Only users with all-media permission can create global media folders.'; }

    if ($action === 'create_folder' && $canViewAllMedia) {
        $folder = devone_media_folder_slug($_POST['folder_name'] ?? '');
        if ($folder === '') { $error = 'Folder name is required.'; }
        else {
            $path = devone_media_folder_path($folder);
            if (!is_dir($path) && !@mkdir($path, 0775, true)) { $error = 'Could not create folder. Check content/media permissions.'; }
            else { $msg = 'Folder ready: ' . $folder; devone_log('media_folder_created', $folder); }
        }
    }

    if ($action === 'auto_sort_media' && !$canViewAllMedia) { $error = 'Only users with all-media permission can auto-sort the full media library.'; }

    if ($action === 'auto_sort_media' && $canViewAllMedia) {
        $result = devone_media_autosort_existing(true);
        $msg = 'Media auto-sort complete. Moved ' . (int)$result['moved'] . ' file(s), updated ' . (int)$result['updated'] . ' record(s). Custom folders were preserved.';
        if (!empty($result['errors'])) { $error = implode(' ', array_slice($result['errors'], 0, 4)); }
        devone_log('media_autosorted', $msg);
    }


    if ($action === 'delete_media') {
        if (!$canDeleteMedia) {
            $error = 'Your role cannot delete media.';
        } else {
            $deleteId = (int)($_POST['media_id'] ?? 0);
            $delete = function_exists('devone_delete_media_record') ? devone_delete_media_record($deleteId, $currentUserId, $canViewAllMedia) : array('ok'=>false, 'message'=>'Media delete helper is missing.');
            $returnType = trim((string)($_POST['return_type'] ?? ''));
            $returnFolder = devone_media_folder_slug($_POST['return_folder'] ?? 'all');
            $returnUrl = $returnType !== '' ? ('media.php?type=' . rawurlencode(devone_media_folder_slug($returnType))) : ('media.php?folder=' . rawurlencode($returnFolder));
            if (!empty($delete['ok'])) {
                header('Location: ' . $returnUrl . '&msg=' . rawurlencode($delete['message'] ?? 'Media deleted.'));
                exit;
            }
            header('Location: ' . $returnUrl . '&error=' . rawurlencode($delete['message'] ?? 'Media could not be deleted.'));
            exit;
        }
    }

    if ($action === 'upload_media' && !$canUploadMedia) { $error = 'Your role cannot upload media.'; }

    if ($action === 'upload_media' && $canUploadMedia && isset($_FILES['media_file']) && !empty($_FILES['media_file']['name'])) {
        $selectedFolder = trim((string)($_POST['folder'] ?? 'auto'));
        $options = array(
            'folder' => ($selectedFolder === '' ? 'auto' : $selectedFolder),
            'purpose' => 'media-library',
            'alt_text' => trim((string)($_POST['alt_text'] ?? '')),
            'user_id' => $currentUserId,
        );
        try {
            if (class_exists('DevOne') && method_exists('DevOne', 'assets')) {
                $upload = DevOne::assets()->upload($_FILES['media_file'], $options);
            } elseif (function_exists('devone_media_upload')) {
                $upload = devone_media_upload($_FILES['media_file'], $options);
            } else {
                $upload = array('ok'=>false,'message'=>'DevOne Assets is unavailable.');
            }
        } catch (Throwable $e) {
            $upload = array('ok'=>false,'message'=>'Media upload could not be completed.');
        }
        if (empty($upload['ok'])) {
            $error = $upload['message'] ?? 'Upload failed.';
        } else {
            $folder = (string)($upload['record']['folder'] ?? ($selectedFolder === 'auto' ? 'other' : $selectedFolder));
            if (function_exists('devone_log')) { devone_log('media_uploaded', (string)($upload['path'] ?? '')); }
            $msg = ($selectedFolder === 'auto' || $selectedFolder === '') ? 'Media uploaded and auto-sorted to ' . $folder . '.' : 'Media uploaded to custom folder ' . $folder . '.';
            header('Location: media.php?folder=' . rawurlencode($folder) . '&msg=' . rawurlencode($msg));
            exit;
        }
    }
}

$currentFolder = devone_media_folder_slug($_GET['folder'] ?? 'all');
$rawType = trim((string)($_GET['type'] ?? ''));
$currentType = $rawType !== '' ? devone_media_folder_slug($rawType) : '';
$filterKey = $currentType !== '' && in_array($currentType, array('images','documents','videos','audio','fonts','archives','other'), true) ? $currentType : $currentFolder;
$folders = devone_list_media_folders();
$types = array('images','documents','videos','audio','fonts','archives','other');
$ownerOnly = !$canViewAllMedia;
$items = $filterKey === 'all' ? devone_media_query('all', $ownerOnly, $currentUserId) : devone_media_query($filterKey, $ownerOnly, $currentUserId);
$totalItems = count($items);
$baseMediaUrl = devone_site_url((function_exists('devone_network_enabled') && devone_network_enabled()) ? ('content/sites/' . (function_exists('devone_content_site_id') ? devone_content_site_id() : 1) . '/uploads/') : 'content/media/');
devone_admin_header('Media Library - DevOneCMS');
?>
<h1>Media Library</h1>
<p class="muted">Upload, organize, and copy file URLs. Auto by type places new media in images, documents, videos, audio, fonts, archives, or other unless the user chooses a specific folder. Users without <code>view_all_media</code> only see their own uploads.</p>
<?php devone_flash($msg); ?>
<?php devone_flash($error, 'card error-card'); ?>
<div class="media-library-layout">
    <aside class="card media-sidebar">
        <h3>Folders</h3>
        <a class="folder-link <?= $currentFolder === 'all' && $currentType === '' ? 'active' : '' ?>" href="media.php?folder=all">All Media</a>
        <?php foreach ($folders as $folder): ?>
            <a class="folder-link <?= $currentFolder === $folder && $currentType === '' ? 'active' : '' ?>" href="media.php?folder=<?= e($folder) ?>"><?= e(ucwords(str_replace(array('-', '_', '/'), ' ', $folder))) ?></a>
        <?php endforeach; ?>
        <hr>
        <h3>Type Filters</h3>
        <?php foreach ($types as $type): ?>
            <a class="folder-link <?= $currentType === $type ? 'active' : '' ?>" href="media.php?type=<?= e($type) ?>"><?= e(ucwords($type)) ?></a>
        <?php endforeach; ?>
        <?php if ($canViewAllMedia): ?>
        <hr>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_folder">
            <label>Create Folder</label>
            <input name="folder_name" placeholder="clients/logos or downloads">
            <button>Create Folder</button>
        </form>
        <form method="post" style="margin-top:14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="auto_sort_media">
            <button>Auto-Sort Existing Media</button>
            <p class="muted">Moves only default/unsorted media into type folders. Custom folders are preserved.</p>
        </form>
        <?php else: ?><p class="muted">Folder creation and global auto-sort are administrator/media-manager tools.</p><?php endif; ?>
    </aside>

    <section>
        <?php if ($canUploadMedia): ?>
        <form method="post" enctype="multipart/form-data" class="card media-upload-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_media">
            <h3>Upload Media</h3>
            <div class="media-upload-grid">
                <label>File<input type="file" name="media_file" required></label>
                <label>Folder
                    <select name="folder">
                        <option value="auto" selected>Auto by type</option>
                        <?php foreach ($folders as $folder): ?><option value="<?= e($folder) ?>"><?= e($folder) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Alt text / Description<input name="alt_text" placeholder="Describe this file"></label>
            </div>
            <button>Upload</button>
        </form>
        <?php else: ?><div class="card">Your role can browse assigned media, but cannot upload new files.</div><?php endif; ?>

        <div class="media-toolbar card">
            <strong><?= e($currentType !== '' ? 'Type: ' . $currentType : ($currentFolder === 'all' ? 'All Media' : 'Folder: ' . $currentFolder)) ?></strong>
            <span><?= number_format($totalItems) ?> item<?= $totalItems === 1 ? '' : 's' ?><?= !$canViewAllMedia ? ' · showing your uploads only' : '' ?></span>
        </div>

        <div class="media-grid">
            <?php foreach($items as $m):
                $mime = (string)($m['mime_type'] ?? '');
                $isImage = strpos($mime,'image/') === 0 || preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', (string)$m['path']);
                $url = devone_site_url($m['path'] ?? '');
                $folder = devone_media_record_folder($m);
                $type = devone_media_record_type($m);
            ?>
            <article class="media-card">
                <div class="media-thumb">
                    <?php if($isImage): ?><img src="<?= e($url) ?>" alt="<?= e($m['alt_text'] ?? '') ?>"><?php else: ?><span class="file-icon"><?= e(strtoupper(pathinfo($m['filename'] ?? 'file', PATHINFO_EXTENSION) ?: 'FILE')) ?></span><?php endif; ?>
                </div>
                <div class="media-meta">
                    <strong><?= e($m['filename'] ?? '') ?></strong>
                    <small><?= e($folder) ?> · <?= e($type) ?> · <?= e($mime ?: 'unknown') ?> · <?= number_format((int)($m['size_bytes'] ?? 0)) ?> bytes<?= !empty($m['user_id']) ? ' · User #' . e($m['user_id']) : '' ?></small>
                    <input readonly value="<?= e($url) ?>" onclick="this.select();navigator.clipboard&&navigator.clipboard.writeText(this.value);">
                    <?php if ($canDeleteMedia): ?>
                    <form method="post" class="media-delete-form" onsubmit="return confirm('Delete this media item permanently? This removes the file from storage, the database record, and clears saved core references.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_media">
                        <input type="hidden" name="media_id" value="<?= e($m['id'] ?? 0) ?>">
                        <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                        <input type="hidden" name="return_type" value="<?= e($currentType) ?>">
                        <button class="danger media-delete-btn" type="submit">Delete Media</button>
                    </form>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
            <?php if (!$items): ?><p class="card">No media found for this filter yet.</p><?php endif; ?>
        </div>
    </section>
</div>
<?php devone_admin_footer();
