<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();

if (!devone_has_permission('edit_pages') && !devone_has_permission('create_pages') && !devone_has_permission('manage_pages')) {
    devone_require_permission('edit_pages');
}

$msg = $_GET['msg'] ?? '';
$error = '';
$page = null;
$action = $_GET['action'] ?? 'list';
$editingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pagesTable = function_exists('devone_require_table') ? devone_require_table('pages', true) : (table_exists('pages') ? table_name('pages') : '');
if ($pagesTable !== '') {
    try {
        $pageColsPre = function_exists('devone_table_columns') ? devone_table_columns('pages') : array();
        if (!in_array('show_title', $pageColsPre, true)) {
            db()->exec('ALTER TABLE `' . $pagesTable . '` ADD `show_title` tinyint(1) NOT NULL DEFAULT 1 AFTER `template`');
        }
    } catch (Throwable $e) { /* Non-fatal. Older installs can still save pages. */ }
}
$canManageAll = devone_has_permission('manage_pages');
$canCreate = devone_has_permission('create_pages') || $canManageAll;
$canPublish = devone_has_permission('publish_pages') || $canManageAll;
$currentUserId = function_exists('devone_current_user_id') ? devone_current_user_id() : 0;
$currentSiteId = function_exists('devone_admin_current_site_id') ? devone_admin_current_site_id() : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? 'save_page';

    if ($postAction === 'delete_page' || $postAction === 'delete_pages') {
        devone_require_permission('manage_pages');
        $wantsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $ids = array();
        if ($postAction === 'delete_pages') {
            $rawIds = $_POST['ids'] ?? array();
            if (!is_array($rawIds)) { $rawIds = explode(',', (string)$rawIds); }
            foreach ($rawIds as $rawId) {
                $id = (int)$rawId;
                if ($id > 0) { $ids[$id] = $id; }
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) { $ids[$id] = $id; }
        }

        if (!$ids) {
            $message = 'Select at least one page to delete.';
            if ($wantsJson) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('ok'=>false, 'message'=>$message));
                exit;
            }
            $error = $message;
        } elseif ($pagesTable === '') {
            $message = 'Pages table could not be resolved.';
            if ($wantsJson) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('ok'=>false, 'message'=>$message));
                exit;
            }
            $error = $message;
        } else {
            try {
                $deletedIds = array();
                $hasSiteId = function_exists('devone_table_has_site_id') && devone_table_has_site_id('pages');
                $deleteSql = 'DELETE FROM `' . $pagesTable . '` WHERE id=?' . ($hasSiteId ? ' AND site_id=?' : '');
                $stmt = db()->prepare($deleteSql);
                foreach ($ids as $id) {
                    $stmt->execute($hasSiteId ? array($id, $currentSiteId) : array($id));
                    if ($stmt->rowCount() > 0) { $deletedIds[] = $id; }
                }
                if ($deletedIds) {
                    if (function_exists('devone_log')) {
                        devone_log(count($deletedIds) > 1 ? 'pages_deleted' : 'page_deleted', 'Page IDs ' . implode(',', $deletedIds));
                    }
                    if (function_exists('devone_purge_after_delete')) { devone_purge_after_delete('page'); }
                }
                $count = count($deletedIds);
                $message = $count === 1 ? 'Page deleted.' : ($count . ' pages were deleted.');
                if ($count === 0) { $message = 'No matching pages were deleted.'; }
                if ($wantsJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(array('ok'=>true, 'deleted_ids'=>$deletedIds, 'deleted_count'=>$count, 'message'=>$message));
                    exit;
                }
                header('Location: pages.php?msg=' . rawurlencode($message));
                exit;
            } catch (Throwable $e) {
                if ($wantsJson) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(array('ok'=>false, 'message'=>'Pages could not be deleted.'));
                    exit;
                }
                $error = $e->getMessage();
            }
        }
    }

    if ($postAction === 'save_page') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 && !$canCreate) { devone_require_permission('create_pages'); }
        if ($id > 0 && !devone_has_permission('edit_pages') && !$canManageAll) { devone_require_permission('edit_pages'); }

        $title = trim($_POST['title'] ?? 'Untitled');
        $slug = devone_slug($_POST['slug'] ?? $title);
        $content = $_POST['content'] ?? '';
        $template = devone_slug($_POST['template'] ?? 'default');
        $status = in_array($_POST['status'] ?? 'published', ['draft','published'], true) ? $_POST['status'] : 'published';
        $showTitle = !empty($_POST['show_title']) ? 1 : 0;
        if ($status === 'published' && !$canPublish) { $status = 'draft'; }

        if ($id > 0 && !$canManageAll && $pagesTable !== '') {
            $cols = function_exists('devone_table_columns') ? devone_table_columns('pages') : array();
            if (in_array('author_id', $cols, true)) {
                if (function_exists('devone_table_has_site_id') && devone_table_has_site_id('pages')) {
                    $stmt = db()->prepare('SELECT author_id FROM `' . $pagesTable . '` WHERE id=? AND site_id=? LIMIT 1');
                    $stmt->execute(array($id, $currentSiteId));
                } else {
                    $stmt = db()->prepare('SELECT author_id FROM `' . $pagesTable . '` WHERE id=? LIMIT 1');
                    $stmt->execute(array($id));
                }
                $owner = (int)$stmt->fetchColumn();
                if ($owner > 0 && $owner !== $currentUserId) { devone_require_permission('manage_pages'); }
            }
        }

        $result = save_page($id, $title, $slug, $content, $template, $status);
        if (!empty($result['ok'])) {
            try {
                $pageColsAfter = function_exists('devone_table_columns') ? devone_table_columns('pages') : array();
                if ($pagesTable !== '' && in_array('show_title', $pageColsAfter, true)) {
                    $stmtShow = db()->prepare('UPDATE `' . $pagesTable . '` SET show_title=? WHERE id=?');
                    $stmtShow->execute(array((int)$showTitle, (int)$result['id']));
                }
            } catch (Throwable $e) { /* Non-fatal. */ }
            if (function_exists('devone_log')) { devone_log('page_saved', ($result['slug'] ?? $slug) . ' via ' . (function_exists('table_name') ? table_name('pages') : 'pages')); }
            $go = 'pages.php?action=edit&id=' . (int)$result['id'] . '&msg=' . rawurlencode($result['message'] ?? 'Page saved.');
            header('Location: ' . $go);
            exit;
        }
        $error = $result['message'] ?? 'Page could not be saved.';
        $page = array('id'=>$id, 'title'=>$title, 'slug'=>$slug, 'content'=>$content, 'template'=>$template, 'status'=>$status, 'show_title'=>$showTitle);
        $action = $id > 0 ? 'edit' : 'new';
    }
}

if ($action === 'new' && !$canCreate) { devone_require_permission('create_pages'); }
if (($action === 'edit' || $editingId > 0) && $editingId > 0) {
    $page = get_page_by_id($editingId);
    if (!$page) { $error = 'Page not found.'; $action = 'list'; }
    else {
        $cols = function_exists('devone_table_columns') ? devone_table_columns('pages') : array();
        if (!$canManageAll && in_array('author_id', $cols, true)) {
            $owner = (int)($page['author_id'] ?? 0);
            if ($owner > 0 && $owner !== $currentUserId) { devone_require_permission('manage_pages'); }
        }
        $action = 'edit';
    }
}

$pages = array();
$cols = function_exists('devone_table_columns') ? devone_table_columns('pages') : array();
if ($pagesTable !== '') {
    try {
        $hasSiteId = function_exists('devone_table_has_site_id') && devone_table_has_site_id('pages');
        if (!$canManageAll && in_array('author_id', $cols, true)) {
            if ($hasSiteId) {
                $stmt = db()->prepare('SELECT * FROM `' . $pagesTable . '` WHERE site_id=? AND (author_id=? OR author_id IS NULL) ORDER BY id DESC');
                $stmt->execute(array($currentSiteId, $currentUserId));
            } else {
                $stmt = db()->prepare('SELECT * FROM `' . $pagesTable . '` WHERE author_id=? OR author_id IS NULL ORDER BY id DESC');
                $stmt->execute(array($currentUserId));
            }
            $pages = $stmt->fetchAll();
        } else {
            if ($hasSiteId) {
                $stmt = db()->prepare('SELECT * FROM `' . $pagesTable . '` WHERE site_id=? ORDER BY id DESC');
                $stmt->execute(array($currentSiteId));
                $pages = $stmt->fetchAll();
            } else {
                $pages = db()->query('SELECT * FROM `' . $pagesTable . '` ORDER BY id DESC')->fetchAll();
            }
        }
    } catch (Exception $e) { $error = $error ?: $e->getMessage(); }
}

$showEditor = in_array($action, array('new','edit'), true);
$report = function_exists('devone_table_resolver_report') ? devone_table_resolver_report() : [];
devone_admin_header('Pages - DevOneCMS');
?>
<section class="page-manager-hero">
  <div>
    <p class="admin-kicker"><span></span> Content Manager</p>
    <h1>Pages</h1>
    <p class="muted">Manage all pages first. Click Add New Page or Edit to open the page editor, similar to the classic CMS workflow.</p>
  </div>
  <div class="admin-hero-actions">
    <a class="btn" href="pages.php?action=new">Add New Page</a>
    <a class="btn secondary" href="pages.php">All Pages</a>
  </div>
</section>
<?php devone_flash($msg); devone_flash($error, 'card error-card'); ?>

<?php if ($pagesTable === ''): ?>
<div class="card error-card">
    <strong>Pages table could not be resolved.</strong><br>
    Connected database: <code><?= e(defined('DB_NAME') ? DB_NAME : 'unknown') ?></code><br>
    Configured prefix: <code><?= e($report['configured_prefix'] ?? 'unknown') ?></code><br>
    Active prefix detected: <code><?= e($report['active_prefix'] ?? 'unknown') ?></code><br>
    <form method="post" action="dashboard.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="repair_schema"><button class="btn" type="submit">Run Dashboard Repair</button></form>
</div>
<?php elseif ($showEditor): ?>
<div class="pages-editor-layout">
  <form method="post" class="card page-editor-card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_page">
      <input type="hidden" name="id" value="<?= e($page['id']??'') ?>">
      <div class="section-head"><h2><?= !empty($page['id']) ? 'Edit Page' : 'Add New Page' ?></h2><span><?= e($pagesTable) ?></span></div>
      <?php if (!empty($page['id']) && function_exists('do_action')) { do_action('devone_page_editor_toolbar', $page); } ?>
      <label>Title<input name="title" value="<?= e($page['title']??'') ?>" placeholder="Example: About Us" required></label>
      <label>Slug<input name="slug" value="<?= e($page['slug']??'') ?>" placeholder="example: about-us"></label>
      <div class="editor-row">
        <label>Template<input name="template" value="<?= e($page['template']??'default') ?>"></label>
        <label>Status<select name="status"><option value="published" <?= (($page['status']??'published')==='published')?'selected':'' ?> <?= !$canPublish ? 'disabled' : '' ?>>Published</option><option value="draft" <?= (($page['status']??'published')==='draft')?'selected':'' ?>>Draft</option></select></label>
      </div>
      <label class="inline-check"><input type="checkbox" name="show_title" value="1" <?= ((int)($page['show_title'] ?? 1) !== 0) ? 'checked' : '' ?>> Show page title on the frontend</label>
      <p class="muted">Themes such as Neon Commerce use this to hide/show the automatic page heading. Your page content still saves normally.</p>
      <?php if (!$canPublish): ?><p class="muted">Your role can save pages as drafts only. Publishing requires the Publish Pages permission.</p><?php endif; ?>
      <label>Content<textarea name="content" rows="18" placeholder="Write HTML or plain text here..."><?= e($page['content']??'') ?></textarea></label>
      <div class="inline-actions">
        <button>Save Page</button>
        <a class="btn secondary" href="pages.php">Back to All Pages</a>
        <?php if (!empty($page['slug'])): ?><a class="btn secondary" target="_blank" href="<?= e(devone_page_url($page['slug'])) ?>">View Page</a><?php endif; ?>
      </div>
  </form>
  <aside class="card page-help-card">
    <h3>Page Tips</h3>
    <p class="muted">Keep JavaScript in Front-End Scripts when possible so AJAX navigation can reload page behavior cleanly.</p>
    <p><a class="btn secondary" href="front-scripts.php">Open Front-End Scripts</a></p>
  </aside>
</div>
<?php else: ?>
<section class="card pages-list-card">
  <div class="section-head"><h2>All Pages</h2><span id="devone-page-count" data-count="<?= (int)count($pages) ?>"><?= number_format(count($pages)) ?> page<?= count($pages) === 1 ? '' : 's' ?></span></div>
  <?php if ($canManageAll && $pages): ?>
  <div class="devone-page-bulkbar" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px">
    <label class="inline-check" style="margin:0"><input type="checkbox" id="devone-pages-select-all"> Select all</label>
    <button type="button" class="danger" id="devone-pages-bulk-delete" disabled>Delete selected</button>
    <span class="muted" id="devone-pages-selected-count">0 selected</span>
  </div>
  <?php endif; ?>
  <div id="devone-pages-ajax-notice" class="card" role="status" aria-live="polite" style="display:none;margin-bottom:14px"></div>
  <div class="table-scroll devone-desktop-table">
    <table class="table"><tr><?php if ($canManageAll): ?><th><span class="sr-only">Select</span></th><?php endif; ?><th>ID</th><th>Title</th><th>Slug</th><th>Status</th><th>Author</th><th>Updated</th><th>Actions</th></tr>
    <?php foreach($pages as $p):
      $author = !empty($p['author_id']) ? devone_get_user_by_id((int)$p['author_id']) : null;
      $authorName = $author ? (($author['display_name'] ?? '') ?: ($author['username'] ?? 'User')) : 'System';
    ?>
      <tr data-page-id="<?= (int)$p['id'] ?>">
        <?php if ($canManageAll): ?><td><input type="checkbox" class="devone-page-select" data-page-select="<?= (int)$p['id'] ?>" value="<?= (int)$p['id'] ?>" aria-label="Select <?= e($p['title']) ?>"></td><?php endif; ?>
        <td><?= e($p['id']) ?></td>
        <td><strong><?= e($p['title']) ?></strong></td>
        <td><code><?= e($p['slug']) ?></code></td>
        <td><span class="status-pill status-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
        <td><?= e($authorName) ?></td>
        <td><?= e($p['updated_at'] ?? ($p['created_at'] ?? '')) ?></td>
        <td><div class="inline-actions"><a class="btn" href="pages.php?action=edit&id=<?= e($p['id']) ?>">Edit</a> <a class="btn secondary" target="_blank" href="<?= e(devone_page_url($p['slug'])) ?>">View</a><?php if (function_exists('do_action')) { do_action('devone_page_row_actions', $p); } ?><?php if ($canManageAll): ?><form method="post" class="devone-page-delete-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete_page"><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="danger">Delete</button></form><?php endif; ?></div></td>
      </tr>
    <?php endforeach; ?></table>
  </div>

  <div class="devone-mobile-cards">
    <?php foreach($pages as $p):
      $author = !empty($p['author_id']) ? devone_get_user_by_id((int)$p['author_id']) : null;
      $authorName = $author ? (($author['display_name'] ?? '') ?: ($author['username'] ?? 'User')) : 'System';
      $updated = $p['updated_at'] ?? ($p['created_at'] ?? '');
    ?>
      <details class="devone-mobile-card" data-page-id="<?= (int)$p['id'] ?>">
        <summary>
          <?php if ($canManageAll): ?><input type="checkbox" class="devone-page-select" data-page-select="<?= (int)$p['id'] ?>" value="<?= (int)$p['id'] ?>" aria-label="Select <?= e($p['title']) ?>" onclick="event.stopPropagation()"><?php endif; ?>
          <span class="devone-mobile-card-icon">📄</span>
          <span class="devone-mobile-card-title-wrap">
            <strong><?= e($p['title']) ?></strong>
            <small><?= e($p['slug']) ?> · <?= e($p['status']) ?></small>
          </span>
          <span class="devone-mobile-card-arrow">⌄</span>
        </summary>
        <div class="devone-mobile-card-body">
          <div class="devone-mobile-meta-grid">
            <span>ID</span><strong><?= e($p['id']) ?></strong>
            <span>Title</span><strong><?= e($p['title']) ?></strong>
            <span>Slug</span><code><?= e($p['slug']) ?></code>
            <span>Status</span><strong><span class="status-pill status-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></strong>
            <span>Author</span><strong><?= e($authorName) ?></strong>
            <span>Updated</span><strong><?= e($updated) ?></strong>
          </div>
          <div class="devone-mobile-card-actions inline-actions">
            <a class="btn" href="pages.php?action=edit&id=<?= e($p['id']) ?>">Edit</a>
            <a class="btn secondary" target="_blank" href="<?= e(devone_page_url($p['slug'])) ?>">View</a>
            <?php if (function_exists('do_action')) { do_action('devone_page_row_actions', $p); } ?>
            <?php if ($canManageAll): ?>
              <form method="post" class="devone-page-delete-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_page">
                <input type="hidden" name="id" value="<?= e($p['id']) ?>">
                <button class="danger">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
  <?php if (!$pages): ?><div class="card"><strong>No pages yet.</strong> Click Add New Page to create your first page. Use slug <code>home</code> for the homepage.</div><?php endif; ?>
</section>
<?php if ($canManageAll && $pages): ?>
<script>
(function(){
  'use strict';
  var selectAll = document.getElementById('devone-pages-select-all');
  var bulkDelete = document.getElementById('devone-pages-bulk-delete');
  var selectedCount = document.getElementById('devone-pages-selected-count');
  var notice = document.getElementById('devone-pages-ajax-notice');
  var pageCount = document.getElementById('devone-page-count');
  var csrf = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var busy = false;

  function boxes(){ return Array.prototype.slice.call(document.querySelectorAll('.devone-page-select')); }
  function selectedIds(){
    var out = {};
    boxes().forEach(function(box){ if(box.checked){ out[String(box.value)] = true; } });
    return Object.keys(out);
  }
  function syncId(id, checked, source){
    boxes().forEach(function(box){ if(box !== source && String(box.value) === String(id)){ box.checked = checked; } });
  }
  function updateControls(){
    var ids = selectedIds();
    selectedCount.textContent = ids.length + ' selected';
    bulkDelete.disabled = busy || ids.length === 0;
    var unique = {};
    boxes().forEach(function(box){ unique[String(box.value)] = box.checked; });
    var keys = Object.keys(unique);
    selectAll.checked = keys.length > 0 && keys.every(function(id){ return unique[id]; });
    selectAll.indeterminate = !selectAll.checked && ids.length > 0;
  }
  function showNotice(message, error){
    notice.textContent = message;
    notice.className = error ? 'card error-card' : 'card';
    notice.style.display = 'block';
  }
  function removePages(ids){
    ids.forEach(function(id){
      document.querySelectorAll('[data-page-id="' + id + '"]').forEach(function(el){ el.remove(); });
    });
    if(pageCount){
      var count = Math.max(0, parseInt(pageCount.getAttribute('data-count') || '0', 10) - ids.length);
      pageCount.setAttribute('data-count', String(count));
      pageCount.textContent = count + (count === 1 ? ' page' : ' pages');
    }
  }
  function requestDelete(ids){
    if(busy || !ids.length) return;
    var question = ids.length === 1 ? 'Delete this page permanently?' : 'Delete these ' + ids.length + ' pages permanently?';
    if(!window.confirm(question)) return;
    busy = true; updateControls();
    var data = new FormData();
    data.append('csrf_token', csrf);
    data.append('action', ids.length === 1 ? 'delete_page' : 'delete_pages');
    if(ids.length === 1){ data.append('id', ids[0]); }
    else { ids.forEach(function(id){ data.append('ids[]', id); }); }
    fetch('pages.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: data
    }).then(function(response){
      return response.json().catch(function(){ return {ok:false,message:'The server returned an invalid response.'}; })
        .then(function(payload){ if(!response.ok || !payload.ok) throw payload; return payload; });
    }).then(function(payload){
      var deleted = (payload.deleted_ids || []).map(String);
      removePages(deleted);
      showNotice(payload.message || (deleted.length === 1 ? 'Page deleted.' : deleted.length + ' pages were deleted.'), false);
    }).catch(function(err){
      showNotice((err && (err.message || err.error)) || 'Pages could not be deleted.', true);
    }).finally(function(){ busy = false; updateControls(); });
  }

  document.addEventListener('change', function(e){
    if(e.target.classList.contains('devone-page-select')){
      syncId(e.target.value, e.target.checked, e.target);
      updateControls();
    }
  });
  selectAll.addEventListener('change', function(){
    boxes().forEach(function(box){ box.checked = selectAll.checked; });
    updateControls();
  });
  bulkDelete.addEventListener('click', function(){ requestDelete(selectedIds()); });
  document.addEventListener('submit', function(e){
    var form = e.target.closest('.devone-page-delete-form');
    if(!form) return;
    e.preventDefault();
    var idInput = form.querySelector('input[name="id"]');
    if(idInput) requestDelete([String(idInput.value)]);
  });
  updateControls();
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php devone_admin_footer();
