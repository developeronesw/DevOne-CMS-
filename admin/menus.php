<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_menus');
verify_csrf();

$msg = '';
$errors = array();

if (!table_exists('menus')) { devone_repair_core_schema(true); }

$selectedSlug = devone_slug($_GET['menu'] ?? get_setting('primary_menu', 'main'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_menu';
    $name = trim($_POST['menu_name'] ?? 'Main Menu') ?: 'Main Menu';
    $slug = devone_slug($_POST['menu_slug'] ?? $name);
    $items = array();

    $ids = $_POST['item_id'] ?? array();
    $parents = $_POST['item_parent_id'] ?? array();
    $labels = $_POST['item_label'] ?? array();
    $types = $_POST['item_type'] ?? array();
    $urls = $_POST['item_url'] ?? array();
    $pageSlugs = $_POST['item_page_slug'] ?? array();
    $targets = $_POST['item_target'] ?? array();

    foreach ($labels as $i => $label) {
        $label = trim((string)$label);
        if ($label === '') { continue; }
        $type = (($types[$i] ?? 'custom') === 'page') ? 'page' : 'custom';
        $pageSlug = devone_slugify($pageSlugs[$i] ?? '', '');
        $url = trim((string)($urls[$i] ?? ''));
        if ($type === 'page') { $url = $pageSlug ? devone_page_url($pageSlug) : '#'; }
        $items[] = array(
            'id' => (string)($ids[$i] ?? ''),
            'parent_id' => (string)($parents[$i] ?? ''),
            'label' => $label,
            'type' => $type,
            'page_slug' => $pageSlug,
            'url' => $url,
            'target' => (($targets[$i] ?? '_self') === '_blank') ? '_blank' : '_self',
            'enabled' => 1,
        );
    }

    $result = devone_save_menu($name, $slug, $items);
    $selectedSlug = $slug;
    if (!empty($_POST['make_primary']) && !empty($result['ok'])) {
        set_setting('primary_menu', $slug);
    }
    devone_log('menu_saved', $slug);
    $msg = $result['message'] ?? 'Menu saved.';
    if (empty($result['ok'])) { $errors[] = $msg; $msg = ''; }
}

$menus = devone_list_menus();
if (!$menus) {
    devone_save_menu('Main Menu', 'main', devone_default_menu_items());
    $menus = devone_list_menus();
}

$selectedMenu = devone_get_menu($selectedSlug);
if (!$selectedMenu && $menus) { $selectedMenu = $menus[0]; $selectedSlug = $selectedMenu['slug']; }
$currentItems = $selectedMenu ? devone_decode_menu_items($selectedMenu['items'] ?? '[]') : devone_default_menu_items();
$pages = list_pages(false);
$primaryMenu = devone_active_menu_slug();

$pageChoices = array();
foreach ($pages as $page) {
    $pageChoices[] = array(
        'title' => $page['title'] ?? 'Untitled',
        'slug' => $page['slug'] ?? '',
        'status' => $page['status'] ?? 'published',
        'url' => devone_page_url($page['slug'] ?? 'home'),
    );
}

devone_admin_header('Menu Builder - DevOneCMS');
?>
<style>
.devone-menu-hint{padding:14px 16px;border:1px solid rgba(255,190,60,.28);background:rgba(240,184,75,.08);border-radius:14px;margin:0 0 18px}
.devone-menu-hint strong{color:#f0b84b}
.menu-items{display:grid;gap:10px;margin:16px 0}
.menu-item-row{--menu-depth:0;position:relative;display:grid;grid-template-columns:42px minmax(0,1fr) auto;gap:12px;align-items:start;padding:13px 14px 13px calc(14px + (var(--menu-depth) * 28px));border:1px solid rgba(255,255,255,.11);border-radius:14px;background:rgba(255,255,255,.035);transition:border-color .16s ease,background .16s ease,transform .16s ease,box-shadow .16s ease}
.menu-item-row[data-depth="1"]{border-left:3px solid rgba(240,184,75,.65)}
.menu-item-row[data-depth="2"],.menu-item-row[data-depth="3"],.menu-item-row[data-depth="4"]{border-left:3px solid rgba(240,184,75,.38)}
.menu-item-row.is-dragging{opacity:.45}
.menu-item-row.drop-child{border-color:#f0b84b;background:rgba(240,184,75,.10);box-shadow:0 0 0 2px rgba(240,184,75,.12) inset}
.menu-item-row.drop-after{border-bottom-color:#f0b84b;box-shadow:0 3px 0 #f0b84b}
.menu-item-handle{cursor:grab;display:grid;place-items:center;width:36px;height:36px;border-radius:10px;border:1px solid rgba(255,255,255,.1);font-size:18px;user-select:none;color:#f0b84b}.menu-item-handle:active{cursor:grabbing}
.menu-item-fields{min-width:0}.menu-item-titleline{display:grid;grid-template-columns:minmax(0,1fr) minmax(170px,.55fr);gap:10px}.menu-item-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.menu-item-fields label{margin:0 0 8px}.menu-item-parent-note{font-size:11px;color:#93a1c5;margin-top:-3px}
.menu-item-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;max-width:230px}.menu-item-actions .btn{min-width:38px;padding:8px 10px}
.menu-depth-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;background:rgba(240,184,75,.1);color:#f0b84b;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin:0 0 8px}
@media(max-width:860px){.menu-item-row{grid-template-columns:36px minmax(0,1fr)}.menu-item-actions{grid-column:1/-1;justify-content:flex-start;max-width:none;padding-left:48px}.menu-item-titleline,.menu-item-two{grid-template-columns:1fr}.menu-item-row{padding-left:calc(12px + (var(--menu-depth) * 18px))}}
</style>
<h1>Menu Builder</h1>
<p class="muted">Build the public navigation structure for this site. Pages are added manually and can now be nested under other menu items.</p>
<div class="devone-menu-hint"><strong>NEW: Hierarchical menus.</strong> Drag an item onto another item to make it a child, use the Parent dropdown directly, or use the indent/outdent buttons. SideNav and any hierarchy-aware frontend menu can use this structure automatically.</div>
<?php devone_flash($msg); ?>
<?php foreach ($errors as $error): ?><p class="card error-card"><?= e($error) ?></p><?php endforeach; ?>

<div class="menu-builder-grid">
    <section class="card">
        <h2>Select Menu</h2>
        <form method="get" class="compact-form">
            <label>Editing
                <select name="menu" onchange="this.form.submit()">
                    <?php foreach ($menus as $menu): ?>
                        <option value="<?= e($menu['slug']) ?>" <?= $selectedSlug === $menu['slug'] ? 'selected' : '' ?>><?= e($menu['name']) ?><?= $primaryMenu === $menu['slug'] ? ' — Primary' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button>Load Menu</button></noscript>
        </form>
        <hr>
        <h2>Available Pages</h2>
        <p class="muted">Choose pages, add them to the menu, then organize them into parents and children on the right.</p>
        <div class="page-picker">
            <?php foreach ($pageChoices as $page): ?>
                <label class="page-choice">
                    <input type="checkbox" data-page-title="<?= e($page['title']) ?>" data-page-slug="<?= e($page['slug']) ?>" data-page-url="<?= e($page['url']) ?>">
                    <span><?= e($page['title']) ?></span>
                    <small><?= e($page['slug']) ?> · <?= e($page['status']) ?></small>
                </label>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn" id="addSelectedPages">Add Selected Pages</button>
        <hr>
        <h2>Custom Link</h2>
        <label>Label<input id="customLabel" placeholder="Docs"></label>
        <label>URL<input id="customUrl" placeholder="https://example.com or /custom-page"></label>
        <button type="button" class="btn" id="addCustomLink">Add Custom Link</button>
    </section>

    <section class="card menu-editor-card">
        <h2>Current Menu</h2>
        <form method="post" id="menuForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_menu">
            <label>Menu Name
                <input name="menu_name" value="<?= e($selectedMenu['name'] ?? 'Main Menu') ?>" placeholder="Main Menu">
            </label>
            <label>Menu Slug
                <input name="menu_slug" value="<?= e($selectedMenu['slug'] ?? 'main') ?>" placeholder="main">
            </label>
            <label class="inline-check"><input type="checkbox" name="make_primary" value="1" <?= $primaryMenu === ($selectedMenu['slug'] ?? 'main') ? 'checked' : '' ?>> Use this as the primary front-end menu</label>
            <div id="menuItems" class="menu-items"></div>
            <div class="settings-actions">
                <button>Save Menu Structure</button>
                <a class="btn" href="settings.php">Menu Display Settings</a>
                <a class="btn" href="../index.php" target="_blank" rel="noopener">View Site</a>
            </div>
        </form>
    </section>
</div>

<script>
const initialMenuItems = <?= json_encode($currentItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const menuItemsWrap = document.getElementById('menuItems');
let draggedRow = null;

function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
}
function newMenuId(){
    if (window.crypto && crypto.getRandomValues) {
        const b = new Uint32Array(2); crypto.getRandomValues(b); return 'mi_' + b[0].toString(36) + b[1].toString(36);
    }
    return 'mi_' + Date.now().toString(36) + Math.random().toString(36).slice(2,9);
}
function rowId(row){ return row ? row.dataset.itemId || '' : ''; }
function parentInput(row){ return row && row.querySelector('input[name="item_parent_id[]"]'); }
function getParent(row){ const i=parentInput(row); return i ? i.value : ''; }
function setParent(row,id){ const i=parentInput(row); if(i)i.value=id||''; }
function rows(){ return Array.from(menuItemsWrap.querySelectorAll('.menu-item-row')); }
function rowById(id){ return rows().find(r=>rowId(r)===id) || null; }
function descendantsOf(id){
    const out=[]; let changed=true;
    while(changed){ changed=false; rows().forEach(r=>{ if(out.includes(rowId(r)))return; const p=getParent(r); if(p===id || out.includes(p)){out.push(rowId(r));changed=true;} }); }
    return out;
}
function wouldCycle(childId,parentId){ return childId===parentId || descendantsOf(childId).includes(parentId); }
function depthFor(row){
    let depth=0,p=getParent(row),seen={};
    while(p && rowById(p) && !seen[p] && depth<6){seen[p]=1;depth++;p=getParent(rowById(p));}
    return depth;
}
function labelOf(row){ const i=row && row.querySelector('input[name="item_label[]"]'); return i ? i.value.trim() || 'Untitled' : 'Untitled'; }
function refreshHierarchy(){
    const all=rows();
    all.forEach(row=>{
        const id=rowId(row), sel=row.querySelector('.item-parent-select'), current=getParent(row);
        if(sel){
            sel.innerHTML='<option value="">— Top Level —</option>';
            const blocked=new Set([id,...descendantsOf(id)]);
            all.forEach(candidate=>{
                const cid=rowId(candidate); if(blocked.has(cid))return;
                const opt=document.createElement('option'); opt.value=cid; opt.textContent=labelOf(candidate); opt.selected=current===cid; sel.appendChild(opt);
            });
        }
        const depth=depthFor(row); row.dataset.depth=depth; row.style.setProperty('--menu-depth',depth);
        const badge=row.querySelector('[data-depth-badge]'); if(badge) badge.textContent=depth ? ('Submenu · Level '+depth) : 'Top Level';
    });
}
function addMenuItem(item) {
    item = Object.assign({id:'',parent_id:'',label:'New Item', type:'custom', page_slug:'', url:'#', target:'_self'}, item || {});
    if(!item.id)item.id=newMenuId();
    const row = document.createElement('div');
    row.className = 'menu-item-row'; row.draggable=true; row.dataset.itemId=item.id;
    row.innerHTML = `
        <div class="menu-item-handle" title="Drag to reorder or drop onto another item">⋮⋮</div>
        <div class="menu-item-fields">
            <span class="menu-depth-badge" data-depth-badge>Top Level</span>
            <input type="hidden" name="item_id[]" value="${escapeHtml(item.id)}">
            <input type="hidden" name="item_parent_id[]" value="${escapeHtml(item.parent_id || '')}">
            <div class="menu-item-titleline">
                <label>Label<input name="item_label[]" value="${escapeHtml(item.label || '')}"></label>
                <label>Parent
                    <select class="item-parent-select"><option value="">— Top Level —</option></select>
                </label>
            </div>
            <div class="menu-item-parent-note">Choose a parent or drag this row onto another menu item to create a submenu.</div>
            <div class="menu-item-two">
                <label>Type
                    <select name="item_type[]" class="item-type">
                        <option value="page" ${item.type === 'page' ? 'selected' : ''}>Page</option>
                        <option value="custom" ${item.type !== 'page' ? 'selected' : ''}>Custom Link</option>
                    </select>
                </label>
                <label>Open
                    <select name="item_target[]">
                        <option value="_self" ${item.target !== '_blank' ? 'selected' : ''}>Same tab</option>
                        <option value="_blank" ${item.target === '_blank' ? 'selected' : ''}>New tab</option>
                    </select>
                </label>
            </div>
            <input type="hidden" name="item_page_slug[]" value="${escapeHtml(item.page_slug || '')}">
            <label>URL<input name="item_url[]" value="${escapeHtml(item.url || '#')}" ${item.type === 'page' ? 'readonly' : ''}></label>
        </div>
        <div class="menu-item-actions">
            <button type="button" class="btn move-up" title="Move up">↑</button>
            <button type="button" class="btn move-down" title="Move down">↓</button>
            <button type="button" class="btn indent-item" title="Nest under previous item">→</button>
            <button type="button" class="btn outdent-item" title="Move one level out">←</button>
            <button type="button" class="btn remove-item">Remove</button>
        </div>`;
    menuItemsWrap.appendChild(row); refreshHierarchy();
}

initialMenuItems.forEach(addMenuItem);
if (!initialMenuItems.length) addMenuItem({id:'mi_home',label:'Home', type:'page', page_slug:'home', url:'<?= e(devone_page_url('home')) ?>'});
refreshHierarchy();

document.getElementById('addSelectedPages').addEventListener('click', () => {
    document.querySelectorAll('.page-choice input:checked').forEach(box => {
        addMenuItem({label: box.dataset.pageTitle, type:'page', page_slug: box.dataset.pageSlug, url: box.dataset.pageUrl, target:'_self'});
        box.checked = false;
    });
});

document.getElementById('addCustomLink').addEventListener('click', () => {
    const label = document.getElementById('customLabel').value.trim();
    const url = document.getElementById('customUrl').value.trim();
    if (!label || !url) return;
    addMenuItem({label, type:'custom', url, target:'_self'});
    document.getElementById('customLabel').value = '';
    document.getElementById('customUrl').value = '';
});

menuItemsWrap.addEventListener('input', e=>{ if(e.target.matches('input[name="item_label[]"]')) refreshHierarchy(); });
menuItemsWrap.addEventListener('change', (event) => {
    const row = event.target.closest('.menu-item-row'); if(!row)return;
    if (event.target.classList.contains('item-type')) {
        const url = row.querySelector('input[name="item_url[]"]'); url.readOnly = event.target.value === 'page';
    }
    if (event.target.classList.contains('item-parent-select')) {
        const candidate=event.target.value; if(!wouldCycle(rowId(row),candidate))setParent(row,candidate); else event.target.value=getParent(row);
        refreshHierarchy();
    }
});
menuItemsWrap.addEventListener('click', (event) => {
    const row = event.target.closest('.menu-item-row'); if (!row) return;
    if (event.target.classList.contains('remove-item')) {
        const id=rowId(row); rows().forEach(r=>{if(getParent(r)===id)setParent(r,getParent(row));}); row.remove(); refreshHierarchy(); return;
    }
    if (event.target.classList.contains('move-up') && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
    if (event.target.classList.contains('move-down') && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
    if (event.target.classList.contains('indent-item')) {
        const prev=row.previousElementSibling; if(prev && !wouldCycle(rowId(row),rowId(prev)))setParent(row,rowId(prev));
    }
    if (event.target.classList.contains('outdent-item')) {
        const p=rowById(getParent(row)); setParent(row,p?getParent(p):'');
    }
    refreshHierarchy();
});

menuItemsWrap.addEventListener('dragstart', e=>{
    const row=e.target.closest('.menu-item-row'); if(!row)return; draggedRow=row; row.classList.add('is-dragging');
    if(e.dataTransfer){e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',rowId(row));}
});
menuItemsWrap.addEventListener('dragend', ()=>{ rows().forEach(r=>r.classList.remove('is-dragging','drop-child','drop-after')); draggedRow=null; });
menuItemsWrap.addEventListener('dragover', e=>{
    const target=e.target.closest('.menu-item-row'); if(!draggedRow || !target || target===draggedRow)return; e.preventDefault();
    rows().forEach(r=>r.classList.remove('drop-child','drop-after'));
    const rect=target.getBoundingClientRect(); const asChild=(e.clientX-rect.left) > Math.min(150, rect.width*.30);
    target.classList.add(asChild?'drop-child':'drop-after'); if(e.dataTransfer)e.dataTransfer.dropEffect='move';
});
menuItemsWrap.addEventListener('drop', e=>{
    const target=e.target.closest('.menu-item-row'); if(!draggedRow || !target || target===draggedRow)return; e.preventDefault();
    const asChild=target.classList.contains('drop-child');
    const did=rowId(draggedRow),tid=rowId(target); if(wouldCycle(did,tid))return;
    if(asChild){ setParent(draggedRow,tid); target.after(draggedRow); }
    else { setParent(draggedRow,getParent(target)); target.after(draggedRow); }
    refreshHierarchy();
});

document.getElementById('menuForm').addEventListener('submit', refreshHierarchy);
</script>
<?php devone_admin_footer();
