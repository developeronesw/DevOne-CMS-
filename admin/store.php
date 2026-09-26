<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_store');
verify_csrf();

$msg = '';
$error = '';
$source = devone_store_default_manifest_url();
if (function_exists('devone_store_enforce_official_marketplace_settings')) { devone_store_enforce_official_marketplace_settings(); }
$manifest = array('items' => array());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_source') {
        $source = devone_store_default_manifest_url();
        $msg = 'Marketplace source is locked to the official DevOne Marketplace and cannot be changed.';
    }

    if ($action === 'connect_official_marketplace') {
        if (function_exists('devone_store_enforce_official_marketplace_settings')) { devone_store_enforce_official_marketplace_settings(); }
        $source = devone_store_default_manifest_url();
        $msg = 'Official DevOne Marketplace is already locked and connected.';
    }

    if ($action === 'save_store_links') {
        if (function_exists('devone_store_enforce_official_marketplace_settings')) { devone_store_enforce_official_marketplace_settings(); }
        $msg = 'Marketplace public links are locked and cannot be changed.';
    }

    if ($action === 'save_marketplace_bridge') {
        $msg = 'Marketplace bridge editing is locked on this page. Define DEVONE_MARKETPLACE_API_SECRET in config.php or keep the existing saved secret.';
    }



    if ($action === 'save_store_item_feedback') {
        $slug = $_POST['item_slug'] ?? '';
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim((string)($_POST['comment'] ?? ''));
        $feedbackError = '';
        if (function_exists('devone_store_save_feedback') && devone_store_save_feedback($slug, $rating, $comment, $feedbackError)) {
            $msg = 'Marketplace rating/comment saved and published to the shared marketplace feedback feed.';
        } else {
            $error = $feedbackError ?: 'Could not save marketplace rating/comment.';
        }
    }

    if (in_array($action, array('install_item', 'install_activate_item', 'activate_item', 'deactivate_item', 'uninstall_item'), true)) {
        $source = devone_store_default_manifest_url();
        $manifest = devone_store_load_manifest($source, $error);
        $index = (int)($_POST['item_index'] ?? -1);
        if ($index < 0 || empty($manifest['items'][$index]) || !is_array($manifest['items'][$index])) {
            $error = 'The selected marketplace item could not be found.';
        } else {
            $item = $manifest['items'][$index];
            if (is_array($item)) { $item['_manifest_source'] = $manifest['source'] ?? $source; }
            $kindForCheckout = function_exists('devone_store_item_package_type') ? devone_store_item_package_type($item) : strtolower((string)($item['type'] ?? ''));
            $pricingForCheckout = function_exists('devone_store_item_pricing') ? devone_store_item_pricing($item) : strtolower((string)($item['pricing'] ?? $item['price_type'] ?? 'free'));
            $purchaseError = '';
            $purchaseAccessMap = function_exists('devone_store_fetch_marketplace_purchases') ? devone_store_fetch_marketplace_purchases($manifest, $purchaseError) : array();
            $itemAccessSlug = function_exists('devone_store_item_slug_for_access') ? devone_store_item_slug_for_access($item) : (string)($item['slug'] ?? '');
            $paidAccess = ($itemAccessSlug !== '' && !empty($purchaseAccessMap[$itemAccessSlug]['download_url'])) ? $purchaseAccessMap[$itemAccessSlug] : null;
            if ($paidAccess && function_exists('devone_store_item_with_download_url')) { $item = devone_store_item_with_download_url($item, $paidAccess['download_url']); }
            $hasInstallSource = !empty($item['local_zip']) || !empty($item['zip_url']) || !empty($item['download_url']) || !empty($item['repo']) || $kindForCheckout === 'cdn';
            $requiresCheckout = ($pricingForCheckout === 'paid' && !$paidAccess && $kindForCheckout !== 'cdn');

            if ($action === 'deactivate_item') {
                $result = function_exists('devone_store_deactivate_installed_item') ? devone_store_deactivate_installed_item($item) : array('ok'=>false, 'message'=>'Deactivation helper is missing.');
                if (!empty($result['ok'])) { $msg = $result['message']; }
                else { $error = $result['message'] ?? 'Deactivation failed.'; }
            } elseif ($action === 'uninstall_item') {
                $result = function_exists('devone_store_uninstall_installed_item') ? devone_store_uninstall_installed_item($item, true) : array('ok'=>false, 'message'=>'Uninstall helper is missing.');
                if (!empty($result['ok'])) { $msg = $result['message']; }
                else { $error = $result['message'] ?? 'Uninstall failed.'; }
            } elseif ($requiresCheckout) {
                $error = 'Purchase required before this premium item can be installed. Use the embedded marketplace checkout, then return to install and activate.';
            } elseif ($action === 'activate_item') {
                $result = function_exists('devone_store_activate_installed_item') ? devone_store_activate_installed_item($item) : array('ok'=>false, 'message'=>'Activation helper is missing.');
                if (!empty($result['ok'])) { $msg = $result['message']; }
                else { $error = $result['message'] ?? 'Activation failed.'; }
            } else {
                $result = devone_store_install_item($item, !empty($_POST['overwrite']));
                if (!empty($result['ok'])) {
                    $msg = $result['message'];
                    if ($action === 'install_activate_item') {
                        $type = $result['package_type'] ?? (function_exists('devone_store_item_package_type') ? devone_store_item_package_type($item) : '');
                        if ($type === 'theme' && !empty($result['folder'])) {
                            $activate = devone_store_activate_theme_folder($result['folder'], $result['name'] ?? ($item['name'] ?? ''));
                            $msg .= !empty($activate['ok']) ? ' ' . $activate['message'] : ' Activation note: ' . ($activate['message'] ?? 'Theme activation failed.');
                        } elseif ($type === 'plugin' && !empty($result['folder'])) {
                            $activate = devone_store_activate_plugin_folder($result['folder']);
                            $msg .= !empty($activate['ok']) ? ' ' . $activate['message'] : ' Activation note: ' . ($activate['message'] ?? 'Plugin activation failed.');
                        }
                    }
                } else {
                    $error = $result['message'] ?? 'Install failed.';
                }
            }
        }
    }
}

$manifestError = '';
$manifest = devone_store_load_manifest($source, $manifestError);
if ($manifestError && !$error) { $error = $manifestError; }
$items = is_array($manifest['items'] ?? null) ? $manifest['items'] : array();
$storeMeta = is_array($manifest['store'] ?? null) ? $manifest['store'] : array();
$source = $manifest['source'] ?? $source;
$manifestSourceForAssets = $manifest['source'] ?? $source;
$bridgeAutoMessage = '';
$bridgeConnected = function_exists('devone_store_ensure_marketplace_bridge') ? devone_store_ensure_marketplace_bridge($manifest, $bridgeAutoMessage) : false;

$publicUrl = devone_store_official_public_url();
$submitUrl = devone_store_official_submit_url();
$livePublicUrl = $publicUrl;
$liveSubmitUrl = $submitUrl;

function devone_store_admin_item_kind($item) {
    if (function_exists('devone_store_item_package_type')) { return devone_store_item_package_type($item); }
    if (!empty($item['install_mode']) && $item['install_mode'] === 'cdn') { return 'cdn'; }
    if (!empty($item['cdn']) || !empty($item['assets'])) { return 'cdn'; }
    return strtolower((string)($item['type'] ?? $item['package_type'] ?? 'package'));
}
function devone_store_admin_item_category($item) {
    $cat = strtolower((string)($item['category'] ?? ''));
    if ($cat !== '') { return $cat; }
    $kind = devone_store_admin_item_kind($item);
    if ($kind === 'theme') { return 'themes'; }
    if ($kind === 'plugin') { return 'plugins'; }
    if ($kind === 'cdn') { return 'frameworks'; }
    if ($kind === 'library') { return 'libraries'; }
    return 'packages';
}
function devone_store_admin_thumb($thumb, $source = '') {
    if (function_exists('devone_store_admin_asset_url')) { return devone_store_admin_asset_url($thumb, $source); }
    $thumb = trim((string)$thumb);
    if ($thumb === '') { return ''; }
    if (preg_match('/^(https?:|data:)/i', $thumb)) { return $thumb; }
    if ($thumb[0] === '/') { return $thumb; }
    return '../' . ltrim($thumb, '/');
}
function devone_store_status_for_admin($item) {
    if (function_exists('devone_store_installed_status')) { return devone_store_installed_status($item); }
    return array('installed'=>false, 'active'=>false, 'type'=>devone_store_admin_item_kind($item), 'folder'=>'', 'path'=>'');
}

function devone_store_admin_item_slug($item, $fallback = '') {
    $slug = (string)($item['slug'] ?? $item['id'] ?? $fallback);
    if (function_exists('devone_store_item_slug_for_access')) { $candidate = devone_store_item_slug_for_access($item); if ($candidate !== '') { $slug = $candidate; } }
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug);
    return trim($slug, '-_.');
}
function devone_store_admin_pick($source, $keys, $default = '') {
    foreach ((array)$keys as $key) { if (is_array($source) && array_key_exists($key, $source) && trim((string)$source[$key]) !== '') { return $source[$key]; } }
    return $default;
}
function devone_store_admin_author_profile($item, $source = '', $publicUrl = '') {
    $author = (!empty($item['author']) && is_array($item['author'])) ? $item['author'] : array();
    $name = devone_store_admin_pick($author, array('name','display_name','author_name'), '');
    if ($name === '') { $name = devone_store_admin_pick($item, array('author_name','author','author_display_name','developer','developer_name'), 'Developer One Author'); }
    if (is_array($name)) { $name = 'Developer One Author'; }
    $slug = devone_store_admin_pick($author, array('slug','author_slug'), '');
    if ($slug === '') { $slug = devone_store_admin_pick($item, array('author_slug','developer_slug'), ''); }
    $id = devone_store_admin_pick($author, array('id','author_id'), '');
    if ($id === '') { $id = devone_store_admin_pick($item, array('author_id','developer_id'), ''); }
    $avatar = devone_store_admin_pick($author, array('avatar','avatar_url','image','photo'), '');
    if ($avatar === '') { $avatar = devone_store_admin_pick($item, array('author_avatar','author_avatar_url','developer_avatar'), ''); }
    $website = devone_store_admin_pick($author, array('website','website_url','url'), '');
    if ($website === '') { $website = devone_store_admin_pick($item, array('author_website','author_website_url','developer_website'), ''); }
    $page = devone_store_admin_pick($author, array('marketplace_url','profile_url','page_url'), '');
    if ($page === '') { $page = devone_store_admin_pick($item, array('author_url','author_page','author_marketplace_url','developer_url'), ''); }
    if ($page === '' && $slug !== '' && $publicUrl !== '') { $page = rtrim($publicUrl, '/') . '/author.php?slug=' . rawurlencode((string)$slug); }
    if ($page === '' && $publicUrl !== '') { $page = rtrim($publicUrl, '/') . '/'; }
    return array('id'=>(string)$id,'slug'=>(string)$slug,'name'=>(string)$name,'avatar'=>devone_store_admin_thumb($avatar, $source),'website'=>(string)$website,'marketplace_page'=>(string)$page);
}
function devone_store_admin_author_match($a, $b) {
    if (!empty($a['id']) && !empty($b['id']) && (string)$a['id'] === (string)$b['id']) { return true; }
    if (!empty($a['slug']) && !empty($b['slug']) && strtolower((string)$a['slug']) === strtolower((string)$b['slug'])) { return true; }
    if (!empty($a['name']) && !empty($b['name']) && strtolower((string)$a['name']) === strtolower((string)$b['name'])) { return true; }
    return false;
}
function devone_store_admin_item_public_url($item, $slug, $publicUrl = '') {
    $url = devone_store_admin_pick($item, array('marketplace_url','item_url','public_url','url'), '');
    if ($url !== '') { return (string)$url; }
    if ($publicUrl !== '' && $slug !== '') { return rtrim($publicUrl, '/') . '/item.php?slug=' . rawurlencode($slug); }
    return $publicUrl;
}
function devone_store_admin_modal_payload($item, $allItems, $index, $manifestSource, $publicUrl) {
    $slug = devone_store_admin_item_slug($item, (string)$index);
    $kind = devone_store_admin_item_kind($item);
    $category = devone_store_admin_item_category($item);
    $pricing = function_exists('devone_store_item_pricing') ? devone_store_item_pricing($item) : strtolower((string)($item['pricing'] ?? $item['price_type'] ?? 'free'));
    $price = $item['price'] ?? ($pricing === 'paid' ? (!empty($item['price_cents']) ? '$' . number_format(((int)$item['price_cents'])/100, 2) : 'Paid') : 'Free');
    $thumb = devone_store_admin_thumb($item['thumbnail'] ?? ($item['screenshot'] ?? ($item['logo'] ?? '')), $manifestSource);
    $author = devone_store_admin_author_profile($item, $manifestSource, $publicUrl);
    $recent = array();
    foreach ((array)$allItems as $idx => $candidate) {
        if (!is_array($candidate)) { continue; }
        $candidateAuthor = devone_store_admin_author_profile($candidate, $manifestSource, $publicUrl);
        if (!devone_store_admin_author_match($author, $candidateAuthor)) { continue; }
        $candidateSlug = devone_store_admin_item_slug($candidate, (string)$idx);
        $recent[] = array('slug'=>$candidateSlug,'name'=>(string)($candidate['name'] ?? 'Marketplace Item'),'type'=>devone_store_admin_item_kind($candidate),'url'=>devone_store_admin_item_public_url($candidate, $candidateSlug, $publicUrl));
        if (count($recent) >= 5) { break; }
    }
    $feedback = function_exists('devone_store_feedback_for_item') ? devone_store_feedback_for_item($slug) : array('entries'=>array(), 'average'=>0, 'count'=>0);
    $comments = array();
    foreach (array_slice((array)($feedback['entries'] ?? array()), 0, 12) as $entry) {
        $comments[] = array('rating'=>(int)($entry['rating'] ?? 0),'comment'=>(string)($entry['comment'] ?? ''),'display_name'=>(string)($entry['display_name'] ?? 'DevOne User'),'created_at'=>(string)($entry['created_at'] ?? ''));
    }
    return array('slug'=>$slug,'name'=>(string)($item['name'] ?? 'Marketplace Item'),'description'=>(string)($item['description'] ?? ($item['desc'] ?? 'DevOneCMS marketplace item.')),'kind'=>$kind,'category'=>$category,'version'=>(string)($item['version'] ?? ''),'price'=>(string)$price,'thumbnail'=>$thumb,'marketplace_url'=>devone_store_admin_item_public_url($item, $slug, $publicUrl),'author'=>$author,'recent'=>$recent,'feedback'=>array('average'=>(float)($feedback['average'] ?? 0),'count'=>(int)($feedback['count'] ?? 0)),'comments'=>$comments);
}


$featured = array_values(array_filter($items, function($item){ return !empty($item['featured']); }));
if (!$featured) { $featured = array_slice($items, 0, 5); }
$countThemes = count(array_filter($items, fn($i) => devone_store_admin_item_category($i) === 'themes'));
$countPlugins = count(array_filter($items, fn($i) => devone_store_admin_item_category($i) === 'plugins'));
$countCdn = count(array_filter($items, fn($i) => devone_store_admin_item_kind($i) === 'cdn'));
$countPaid = count(array_filter($items, fn($i) => ($i['pricing'] ?? 'free') === 'paid'));
$isRemote = devone_store_is_url($source);

$storeFeedbackSlugs = array();
foreach ($items as $feedbackIndex => $feedbackItem) {
    if (is_array($feedbackItem)) { $storeFeedbackSlugs[] = devone_store_admin_item_slug($feedbackItem, (string)$feedbackIndex); }
}
$storeFeedbackError = '';
$devoneStoreFeedbackMap = function_exists('devone_store_fetch_feedback_batch') ? devone_store_fetch_feedback_batch($manifest, $storeFeedbackSlugs, $storeFeedbackError) : array();

$categories = array(
    'all' => 'All',
    'themes' => 'Themes',
    'plugins' => 'Plugins',
    'frameworks' => 'Frameworks',
    'js-plugins' => 'JS Plugins',
    'libraries' => 'Libraries',
    'editors' => 'Editors',
    'packages' => 'Packages'
);

$storeDetailPayload = array();
foreach ($items as $detailIndex => $detailItem) {
    if (is_array($detailItem)) {
        $payload = devone_store_admin_modal_payload($detailItem, $items, $detailIndex, $manifestSourceForAssets, $livePublicUrl);
        $storeDetailPayload[$payload['slug']] = $payload;
    }
}
$storeDetailJson = json_encode($storeDetailPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($storeDetailJson === false) { $storeDetailJson = '{}'; }


devone_admin_header('DevOne Store - DevOneCMS');
?>
<div class="devone-native-store">
  <section class="store-shell-hero">
    <div class="store-shell-glow"></div>
    <div class="store-hero-copy">
      <div class="store-kicker"><span></span> <?= $isRemote ? 'Connected Remote Marketplace' : 'Bundled Local Marketplace' ?></div>
      <h1>DevOne Store</h1>
      <p>Install themes, plugins, frameworks, JavaScript libraries, editors, and CDN assets directly from the official DevOne Marketplace — no iframe, no separate preview, just a native CMS store like a real platform.</p>
      <div class="store-hero-actions">
        <form method="post" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="connect_official_marketplace">
          <button type="submit">Connect Official Store</button>
        </form>
        <a class="button-like secondary" href="<?= e($livePublicUrl) ?>" target="_blank" rel="noopener">Open Public Store</a>
        <a class="button-like secondary" href="<?= e($liveSubmitUrl) ?>" target="_blank" rel="noopener">Submit Package</a>
      </div>
    </div>

    <div class="store-featured-stage" id="storeFeaturedStage">
      <?php foreach ($featured as $idx => $item):
        $thumb = devone_store_admin_thumb($item['thumbnail'] ?? ($item['screenshot'] ?? ($item['logo'] ?? '')), $manifestSourceForAssets);
        $kind = devone_store_admin_item_kind($item);
        $cat = devone_store_admin_item_category($item);
      ?>
      <article class="store-featured-slide <?= $idx === 0 ? 'active' : '' ?>">
        <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="<?= e($item['name'] ?? 'Featured') ?> thumbnail" loading="lazy"><?php else: ?><div class="store-featured-placeholder"><?= e(strtoupper(substr($kind,0,2))) ?></div><?php endif; ?>
        <div class="store-featured-copy">
          <b><?= e($cat) ?> · <?= e($item['price'] ?? 'Free') ?></b>
          <h2><?= e($item['name'] ?? 'Featured Package') ?></h2>
          <p><?= e($item['description'] ?? ($item['desc'] ?? 'Marketplace package.')) ?></p>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <?php devone_flash($msg); ?>
  <?php devone_flash($error, 'card error-card'); ?>

  <section class="store-stats-native">
    <div><strong><?= (int)count($items) ?></strong><span>Total Items</span></div>
    <div><strong><?= (int)$countThemes ?></strong><span>Themes</span></div>
    <div><strong><?= (int)$countPlugins ?></strong><span>Plugins</span></div>
    <div><strong><?= (int)$countCdn ?></strong><span>CDN / Libraries</span></div>
    <div><strong><?= (int)$countPaid ?></strong><span>Paid Ready</span></div>
  </section>

  <section class="store-toolbar-native">
    <input id="devoneStoreSearch" class="devone-search-input" placeholder="Search themes, plugins, Bootstrap, Swiper, SEO, editors, tools...">
    <div class="store-tabs" id="devoneStoreTabs">
      <?php foreach ($categories as $slug => $label): ?>
        <button type="button" class="<?= $slug === 'all' ? 'active' : '' ?>" data-category="<?= e($slug) ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="native-store-grid" id="devoneStoreGrid">
    <?php foreach ($items as $i => $item):
      $kind = devone_store_admin_item_kind($item);
      $category = devone_store_admin_item_category($item);
      $name = $item['name'] ?? 'Unnamed Package';
      $desc = $item['description'] ?? ($item['desc'] ?? 'DevOneCMS marketplace item.');
      $thumb = devone_store_admin_thumb($item['thumbnail'] ?? ($item['screenshot'] ?? ($item['logo'] ?? '')), $manifestSourceForAssets);
      $repo = $item['repo'] ?? '';
      $versionLabel = $item['version'] ?? '';
      $pricing = function_exists('devone_store_item_pricing') ? devone_store_item_pricing($item) : strtolower((string)($item['pricing'] ?? $item['price_type'] ?? 'free'));
      $price = $item['price'] ?? ($pricing === 'paid' ? (!empty($item['price_cents']) ? '$' . number_format(((int)$item['price_cents'])/100, 2) : 'Paid') : 'Free');
      $accessSlug = function_exists('devone_store_item_slug_for_access') ? devone_store_item_slug_for_access($item) : (string)($item['slug'] ?? '');
      $paidAccess = ($accessSlug !== '' && !empty($purchaseAccessMap[$accessSlug]['download_url'])) ? $purchaseAccessMap[$accessSlug] : null;
      if ($paidAccess && function_exists('devone_store_item_with_download_url')) { $item = devone_store_item_with_download_url($item, $paidAccess['download_url']); }
      $optionalDownloadUrl = function_exists('devone_store_item_download_url') ? devone_store_item_download_url($item) : (string)($item['download_url'] ?? '');
      $requiresCheckout = ($pricing === 'paid' && !$paidAccess && $kind !== 'cdn');
      $status = devone_store_status_for_admin($item);
      $installed = !empty($status['installed']);
      $active = !empty($status['active']);
      $folder = $status['folder'] ?? '';
      $search = strtolower($name . ' ' . $desc . ' ' . $kind . ' ' . $category . ' ' . $repo . ' ' . implode(' ', (array)($item['tags'] ?? array())));
      $buttonLabel = 'Install';
      if ($kind === 'theme') { $buttonLabel = 'Install Theme'; }
      elseif ($kind === 'plugin') { $buttonLabel = 'Install Plugin'; }
      elseif ($kind === 'cdn') { $buttonLabel = 'Install CDN'; }
      elseif ($kind === 'library') { $buttonLabel = 'Install Library'; }
    ?>
      <article class="native-store-card" data-kind="<?= e($kind) ?>" data-category="<?= e($category) ?>" data-search="<?= e($search) ?>">
        <div class="native-card-thumb">
          <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="<?= e($name) ?> thumbnail" loading="lazy"><?php else: ?><span><?= e(strtoupper(substr($kind, 0, 2))) ?></span><?php endif; ?>
          <?php if (!empty($item['featured'])): ?><em class="store-featured-badge">Featured</em><?php endif; ?>
          <strong class="store-price-badge <?= $pricing === 'paid' ? 'paid' : 'free' ?>"><?= e($price) ?></strong>
        </div>
        <div class="native-card-body">
          <div class="native-card-meta">
            <span><?= e($category) ?></span>
            <?php if ($versionLabel): ?><code>v<?= e($versionLabel) ?></code><?php endif; ?>
          </div>
          <h3><a href="#" class="store-card-title-link" data-store-detail="<?= e($accessSlug ?: devone_store_admin_item_slug($item, (string)$i)) ?>"><?= e($name) ?></a></h3>
          <p><?= e($desc) ?></p>
          <?php if ($folder): ?><p class="muted"><code><?= e($folder) ?></code></p><?php endif; ?>
          <div class="native-card-actions">
            <?php if ($requiresCheckout): ?>
              <button type="button" data-marketplace-buy="1" data-item-id="<?= (int)($item['id'] ?? $i) ?>" data-item-name="<?= e($name) ?>">Buy / Checkout</button>
              <span class="store-mini-note">Premium · installs after payment</span>
            <?php elseif ($active): ?>
              <button disabled>Active</button>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="deactivate_item">
                <input type="hidden" name="item_index" value="<?= (int)$i ?>">
                <input type="hidden" name="manifest_source" value="<?= e($source) ?>">
                <button class="btn secondary"><?= $kind === 'theme' ? 'Switch Off' : 'Deactivate' ?></button>
              </form>
              <form method="post" onsubmit="return confirm('Uninstall this package and remove its files/registrations?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="uninstall_item">
                <input type="hidden" name="item_index" value="<?= (int)$i ?>">
                <input type="hidden" name="manifest_source" value="<?= e($source) ?>">
                <button class="btn secondary danger">Uninstall</button>
              </form>
            <?php elseif ($installed): ?>
              <?php if (in_array($kind, array('theme','plugin'), true)): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="activate_item">
                <input type="hidden" name="item_index" value="<?= (int)$i ?>">
                <input type="hidden" name="manifest_source" value="<?= e($source) ?>">
                <button><?= $kind === 'theme' ? 'Activate Theme' : 'Activate Plugin' ?></button>
              </form>
              <?php elseif (in_array($kind, array('library','cdn'), true)): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="activate_item">
                <input type="hidden" name="item_index" value="<?= (int)$i ?>">
                <input type="hidden" name="manifest_source" value="<?= e($source) ?>">
                <button>Activate</button>
              </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Uninstall this package and remove its files/registrations?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="uninstall_item">
                <input type="hidden" name="item_index" value="<?= (int)$i ?>">
                <input type="hidden" name="manifest_source" value="<?= e($source) ?>">
                <button class="btn secondary danger">Uninstall</button>
              </form>
            <?php else: ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= in_array($kind, array('theme','plugin'), true) ? 'install_activate_item' : 'install_item' ?>">
                <input type="hidden" name="item_index" value="<?= (int)$i ?>">
                <input type="hidden" name="manifest_source" value="<?= e($source) ?>">
                <?php if ($kind !== 'cdn'): ?><label class="inline-check"><input type="checkbox" name="overwrite" value="1"> overwrite</label><?php endif; ?>
                <button><?= e(in_array($kind, array('theme','plugin'), true) ? $buttonLabel . ' + Activate' : $buttonLabel) ?></button>
              </form>
            <?php endif; ?>
            <?php if (!empty($optionalDownloadUrl)): ?><a class="btn secondary" href="<?= e($optionalDownloadUrl) ?>" target="_blank" rel="noopener">Optional Download</a><?php endif; ?>
            <?php if ($repo): ?><a class="btn secondary" href="<?= e($repo) ?>" target="_blank" rel="noopener">Repo</a><?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <?php if (!$items): ?><div class="card">No marketplace items found. Check the manifest source or publish <code>manifest.json</code> to the remote marketplace.</div><?php endif; ?>


  <div class="devone-store-detail-overlay" id="devoneStoreDetailOverlay" hidden aria-hidden="true">
    <div class="devone-store-detail-modal" role="dialog" aria-modal="true" aria-labelledby="devoneStoreDetailTitle">
      <button type="button" class="devone-store-detail-close" id="devoneStoreDetailClose" aria-label="Close item details">&times;</button>
      <div class="store-detail-layout">
        <section class="store-detail-main">
          <div class="store-detail-media" id="storeDetailMedia"></div>
          <div class="store-detail-copy">
            <div class="store-detail-meta" id="storeDetailMeta"></div>
            <h2 id="devoneStoreDetailTitle">Marketplace Item</h2>
            <p id="storeDetailDesc"></p>
            <a class="button-like secondary" id="storeDetailMarketplaceLink" href="#" target="_blank" rel="noopener">Open marketplace page</a>
          </div>
          <section class="store-detail-feedback">
            <h3>Ratings & Comments</h3>
            <div class="store-detail-rating-summary" id="storeDetailRatingSummary"></div>
            <form method="post" class="store-detail-feedback-form" id="storeDetailFeedbackForm">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_store_item_feedback">
              <input type="hidden" name="item_slug" id="storeDetailFeedbackSlug" value="">
              <input type="hidden" name="rating" id="storeDetailFeedbackRating" value="0">
              <div class="store-detail-stars" id="storeDetailStars" aria-label="Select rating">
                <button type="button" data-rating="1">★</button>
                <button type="button" data-rating="2">★</button>
                <button type="button" data-rating="3">★</button>
                <button type="button" data-rating="4">★</button>
                <button type="button" data-rating="5">★</button>
              </div>
              <textarea name="comment" rows="4" maxlength="1200" placeholder="Leave a comment about this item..."></textarea>
              <button type="submit">Save Rating / Comment</button>
            </form>
            <div class="store-detail-comments" id="storeDetailComments"></div>
          </section>
        </section>
        <aside class="store-detail-author-card">
          <h3>Author Information</h3>
          <div class="store-detail-author-head">
            <div class="store-detail-avatar" id="storeDetailAuthorAvatar"></div>
            <div><strong id="storeDetailAuthorName">Marketplace Author</strong><p id="storeDetailAuthorSub" class="muted">DevOne Marketplace contributor</p></div>
          </div>
          <table class="store-detail-author-table">
            <tr><th>Website</th><td><a id="storeDetailAuthorWebsite" href="#" target="_blank" rel="noopener">—</a></td></tr>
            <tr><th>Marketplace</th><td><a id="storeDetailAuthorPage" href="#" target="_blank" rel="noopener">View profile</a></td></tr>
          </table>
          <h4>Recent Contributions</h4>
          <ul class="store-detail-recent" id="storeDetailRecent"></ul>
        </aside>
      </div>
    </div>
  </div>

  <script type="application/json" id="devoneStoreDetailData"><?= $storeDetailJson ?></script>
</div>

<div class="devone-store-checkout-overlay" id="devoneStoreCheckoutOverlay" hidden>
  <div class="devone-store-checkout-modal">
    <button type="button" class="devone-store-checkout-close" data-store-checkout-close>&times;</button>
    <h2>Secure Marketplace Checkout</h2>
    <p id="devoneStoreCheckoutStatus">Preparing embedded checkout...</p>
    <div id="devoneStoreEmbeddedCheckout"></div>
  </div>
</div>
<script>
window.DevOneMarketplaceBridge = <?= json_encode(array(
  'embedded_checkout_api' => $embeddedCheckoutApi,
  'marketplace_base_url' => $marketplaceBaseUrl,
  'return_url' => (defined('ADMIN_URL') ? ADMIN_URL : '') . 'store.php?d1m_market_session={CHECKOUT_SESSION_ID}',
  'user' => $cmsUserContext,
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script>
(function(){
  var bridge = window.DevOneMarketplaceBridge || {};
  var overlay = document.getElementById('devoneStoreCheckoutOverlay');
  var mount = document.getElementById('devoneStoreEmbeddedCheckout');
  var status = document.getElementById('devoneStoreCheckoutStatus');
  function loadStripe(){
    return new Promise(function(resolve,reject){
      if(window.Stripe){ resolve(); return; }
      var s=document.createElement('script');
      s.src='https://js.stripe.com/v3/';
      s.onload=resolve;
      s.onerror=function(){ reject(new Error('Stripe.js could not be loaded.')); };
      document.head.appendChild(s);
    });
  }
  function openModal(){ if(overlay){ overlay.hidden=false; overlay.classList.add('open'); } }
  function closeModal(){ if(overlay){ overlay.classList.remove('open'); overlay.hidden=true; if(mount){ mount.innerHTML=''; } } }
  document.addEventListener('click', function(e){
    if(e.target.closest('[data-store-checkout-close]')){ closeModal(); return; }
    var buy=e.target.closest('[data-marketplace-buy]');
    if(!buy){ return; }
    e.preventDefault();
    if(!bridge.embedded_checkout_api){ alert('Marketplace embedded checkout API was not found. Check the remote manifest source.'); return; }
    openModal();
    status.textContent='Creating embedded checkout...';
    mount.innerHTML='';
    var id=parseInt(buy.getAttribute('data-item-id'),10);
    var name=buy.getAttribute('data-item-name') || 'Marketplace Item';
    fetch(bridge.embedded_checkout_api, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({cart:[{id:id,name:name,qty:1}],user:bridge.user,return_url:bridge.return_url})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data.ok){ throw new Error(data.message || 'Checkout could not be created.'); }
      return loadStripe().then(function(){
        var stripe = Stripe(data.publishable_key);
        return stripe.initEmbeddedCheckout({clientSecret:data.client_secret});
      });
    }).then(function(checkout){
      status.textContent='Complete payment below. When payment is complete, you will return to DevOne Store and install access will unlock.';
      checkout.mount('#devoneStoreEmbeddedCheckout');
    }).catch(function(err){
      status.textContent = err.message || 'Checkout failed.';
    });
  });
})();
</script>

<style>
/* DevOneCMS v1.1.21 native remote marketplace admin page */
.devone-native-store{--store-bg:#050814;--store-panel:#0b1022;--store-panel2:#121936;--store-text:#fff;--store-muted:#b7c4ff;--store-blue:#27b7ff;--store-purple:#9543ff;--store-pink:#ff3deb;--store-green:#31f3ad;--store-border:rgba(120,150,255,.24);width:100%;max-width:none;margin:0;padding:0 0 40px;color:var(--store-text)}
.devone-native-store h1,.devone-native-store h2,.devone-native-store h3{letter-spacing:-.04em}.devone-native-store .inline-form{display:inline}.devone-native-store .button-like,.devone-native-store button,.devone-native-store .btn{border:0;border-radius:14px;padding:12px 16px;font-weight:900;text-decoration:none;cursor:pointer;color:#fff!important;background:linear-gradient(135deg,var(--store-blue),var(--store-purple));box-shadow:0 14px 34px rgba(87,86,255,.22)}.devone-native-store .button-like.secondary,.devone-native-store .btn.secondary{background:rgba(255,255,255,.08);border:1px solid var(--store-border);box-shadow:none}.devone-native-store .btn.danger,.devone-native-store button.danger{background:linear-gradient(135deg,#ff5c39,#b31b1b)!important;border:1px solid rgba(255,128,92,.45);box-shadow:0 12px 28px rgba(255,70,40,.22)}.devone-native-store button:disabled{opacity:.58;cursor:not-allowed;background:rgba(255,255,255,.14);box-shadow:none}.store-shell-hero{position:relative;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(440px,.95fr);gap:22px;min-height:420px;padding:34px;border:1px solid var(--store-border);border-radius:34px;background:radial-gradient(circle at top left,rgba(39,183,255,.20),transparent 34%),radial-gradient(circle at top right,rgba(149,67,255,.22),transparent 34%),linear-gradient(145deg,#060a18,#0b1024 56%,#060817);overflow:hidden;box-shadow:0 30px 95px rgba(0,0,0,.38);margin-bottom:22px}.store-shell-glow{position:absolute;inset:-20%;background:radial-gradient(circle at 20% 30%,rgba(39,183,255,.16),transparent 25%),radial-gradient(circle at 78% 34%,rgba(255,61,235,.13),transparent 30%);filter:blur(10px);pointer-events:none}.store-hero-copy,.store-featured-stage{position:relative;z-index:1}.store-kicker{display:inline-flex;align-items:center;gap:10px;min-height:36px;padding:0 14px;border-radius:999px;border:1px solid var(--store-border);background:rgba(255,255,255,.06);color:var(--store-muted);font-size:.78rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em}.store-kicker span{width:9px;height:9px;border-radius:999px;background:var(--store-green);box-shadow:0 0 18px var(--store-green)}.store-hero-copy h1{font-size:clamp(3.2rem,7vw,6.8rem);line-height:.88;margin:22px 0 14px;background:linear-gradient(90deg,#fff,var(--store-blue),var(--store-purple),var(--store-pink));-webkit-background-clip:text;background-clip:text;color:transparent}.store-hero-copy p{max-width:820px;font-size:1.12rem;line-height:1.75;color:#dce5ff}.store-hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}.store-featured-stage{min-height:390px;border-radius:28px;border:1px solid rgba(255,255,255,.12);background:linear-gradient(145deg,rgba(255,255,255,.08),rgba(255,255,255,.025));overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.28)}.store-featured-slide{position:absolute;inset:0;opacity:0;transform:translateX(18px);transition:.45s ease;padding:22px;display:grid;grid-template-rows:1fr auto;gap:16px}.store-featured-slide.active{opacity:1;transform:translateX(0)}.store-featured-slide img,.store-featured-placeholder{width:100%;height:230px;object-fit:cover;border-radius:22px;border:1px solid rgba(255,255,255,.10);background:#070c1d}.store-featured-placeholder{display:grid;place-items:center;font-size:3rem;font-weight:950;color:var(--store-blue)}.store-featured-copy b{color:var(--store-green);text-transform:uppercase;letter-spacing:.06em;font-size:.78rem}.store-featured-copy h2{margin:8px 0 8px;font-size:2rem}.store-featured-copy p{margin:0;color:#d6dfff;line-height:1.62}.store-stats-native{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin:22px 0}.store-stats-native>div{border:1px solid var(--store-border);border-radius:22px;padding:20px;background:linear-gradient(145deg,rgba(255,255,255,.075),rgba(255,255,255,.025));text-align:center}.store-stats-native strong{display:block;font-size:2rem;background:linear-gradient(90deg,var(--store-blue),var(--store-purple));-webkit-background-clip:text;background-clip:text;color:transparent}.store-stats-native span{color:var(--store-muted);font-weight:800}.store-advanced-card{border:1px solid var(--store-border);border-radius:24px;background:rgba(255,255,255,.04);padding:0;margin:22px 0;overflow:hidden}.store-advanced-card summary{cursor:pointer;padding:18px 20px;font-weight:950;color:#fff;background:linear-gradient(135deg,rgba(39,183,255,.08),rgba(149,67,255,.10))}.store-advanced-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:20px}.store-advanced-grid section{padding:18px;border-radius:20px;background:rgba(0,0,0,.16);border:1px solid rgba(255,255,255,.08)}.store-toolbar-native{position:sticky;top:0;z-index:5;display:grid;grid-template-columns:minmax(260px,1fr) auto;gap:14px;align-items:center;padding:14px 0;margin:8px 0 22px;background:linear-gradient(180deg,rgba(5,8,20,.96),rgba(5,8,20,.78));backdrop-filter:blur(16px)}.devone-search-input{min-height:52px;border-radius:16px;border:1px solid var(--store-border);background:rgba(255,255,255,.06);color:#fff;padding:0 16px;font-weight:700}.store-tabs{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.store-tabs button{box-shadow:none;background:rgba(255,255,255,.07);border:1px solid var(--store-border);padding:10px 13px}.store-tabs button.active,.store-tabs button:hover{background:linear-gradient(135deg,var(--store-blue),var(--store-purple))}.native-store-grid{display:grid;grid-template-columns:repeat(4,minmax(240px,1fr));gap:18px}.native-store-card{display:flex;flex-direction:column;min-height:100%;border-radius:26px;border:1px solid var(--store-border);background:linear-gradient(160deg,rgba(255,255,255,.075),rgba(255,255,255,.025));overflow:hidden;box-shadow:0 22px 70px rgba(0,0,0,.24);transition:.2s ease}.native-store-card:hover{transform:translateY(-4px);border-color:rgba(39,183,255,.55)}.native-card-thumb{position:relative;height:190px;background:#070c1d;overflow:hidden}.native-card-thumb img{width:100%;height:100%;object-fit:cover;display:block}.native-card-thumb>span{height:100%;display:grid;place-items:center;font-size:2.6rem;font-weight:950;color:var(--store-blue);background:radial-gradient(circle,rgba(39,183,255,.20),transparent 44%)}.store-featured-badge{position:absolute;top:12px;left:12px;border-radius:999px;padding:7px 10px;background:linear-gradient(135deg,var(--store-pink),var(--store-purple));font-style:normal;font-size:.72rem;font-weight:950}.store-price-badge{position:absolute;right:12px;top:12px;border-radius:999px;padding:7px 10px;font-size:.72rem}.store-price-badge.free{background:rgba(49,243,173,.18);color:#bfffe8;border:1px solid rgba(49,243,173,.32)}.store-price-badge.paid{background:rgba(255,179,71,.18);color:#ffe0ae;border:1px solid rgba(255,179,71,.32)}.native-card-body{display:flex;flex-direction:column;gap:10px;flex:1;padding:20px}.native-card-meta{display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--store-muted);font-weight:900;text-transform:uppercase;font-size:.76rem;letter-spacing:.06em}.native-card-meta code{font-size:.72rem;text-transform:none}.native-card-body h3{margin:0;font-size:1.28rem}.native-card-body p{margin:0;color:#d7e0ff;line-height:1.6}.store-tags{display:flex;gap:7px;flex-wrap:wrap}.store-tags span{display:inline-flex;border-radius:999px;padding:5px 8px;background:rgba(255,255,255,.07);font-size:.72rem;color:#dce5ff}.native-card-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:auto}.native-card-actions form{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0}.native-card-actions button,.native-card-actions .btn{padding:10px 12px;font-size:.88rem}.inline-check{width:auto!important;display:inline-flex!important;align-items:center;gap:6px;color:var(--store-muted);font-size:.82rem;white-space:nowrap}.inline-check input{width:auto!important}.native-store-card.is-hidden{display:none!important}@media(max-width:1450px){.native-store-grid{grid-template-columns:repeat(3,minmax(240px,1fr))}.store-shell-hero{grid-template-columns:1fr}}@media(max-width:980px){.native-store-grid,.store-stats-native,.store-advanced-grid{grid-template-columns:1fr 1fr}.store-toolbar-native{grid-template-columns:1fr}.store-tabs{justify-content:flex-start}.store-featured-stage{min-height:420px}}@media(max-width:680px){.native-store-grid,.store-stats-native,.store-advanced-grid{grid-template-columns:1fr}.store-shell-hero{padding:22px}.store-hero-copy h1{font-size:3.4rem}}

.devone-store-checkout-overlay{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.68);backdrop-filter:blur(8px);display:grid;place-items:center;padding:20px}
.devone-store-checkout-overlay[hidden]{display:none}
.devone-store-checkout-modal{position:relative;width:min(880px,100%);max-height:92vh;overflow:auto;border:1px solid var(--store-border);border-radius:28px;background:#0b1022;color:#fff;padding:24px;box-shadow:0 30px 120px rgba(0,0,0,.5)}
.devone-store-checkout-close{position:absolute;right:18px;top:18px;width:42px;height:42px;border-radius:14px;padding:0}
#devoneStoreEmbeddedCheckout{margin-top:18px}
.store-mini-note{display:inline-flex;align-items:center;border:1px solid var(--store-border);border-radius:999px;padding:8px 11px;color:var(--store-muted);font-size:.82rem;font-weight:900}

.store-locked-marketplace-card{border:1px solid var(--store-border);border-radius:28px;padding:22px;background:rgba(255,255,255,.045);margin:20px 0;color:var(--store-text)}
.store-status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:7px 10px;font-weight:900;font-size:.8rem}
.store-status-pill.ok{background:rgba(49,243,173,.14);color:var(--store-green);border:1px solid rgba(49,243,173,.32)}
.store-status-pill.warn{background:rgba(255,190,80,.12);color:#ffd68a;border:1px solid rgba(255,190,80,.32)}

.store-card-title-link{display:inline;color:#fff!important;font:inherit;font-weight:950;line-height:1.18;text-decoration:none;cursor:pointer}.store-card-title-link:hover{color:var(--store-green)!important;text-decoration:underline;text-decoration-thickness:2px;text-underline-offset:4px}.native-card-actions button,.native-card-actions .btn{padding:9px 12px!important;border-radius:13px!important;font-size:.82rem!important;min-height:0!important;line-height:1.15!important}.native-card-actions{gap:8px!important}.store-tags{display:none!important}.devone-store-detail-overlay{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.72);backdrop-filter:blur(10px);display:grid;place-items:center;padding:22px}.devone-store-detail-overlay[hidden]{display:none!important}.devone-store-detail-modal{position:relative;width:min(1180px,100%);max-height:92vh;overflow:auto;border:1px solid var(--store-border);border-radius:30px;background:radial-gradient(circle at top left,rgba(39,183,255,.16),transparent 28%),radial-gradient(circle at top right,rgba(149,67,255,.18),transparent 32%),#080d1d;color:#fff;box-shadow:0 36px 140px rgba(0,0,0,.58);padding:24px}.devone-store-detail-close{position:absolute;right:18px;top:18px;width:44px;height:44px;border-radius:16px;padding:0!important;font-size:1.5rem;z-index:2}.store-detail-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:22px}.store-detail-main{display:grid;gap:18px}.store-detail-media{min-height:320px;border-radius:24px;border:1px solid rgba(255,255,255,.11);background:#060a16;overflow:hidden;display:grid;place-items:center}.store-detail-media img{width:100%;height:100%;min-height:320px;object-fit:cover;display:block}.store-detail-media span{font-size:4rem;font-weight:950;color:var(--store-blue)}.store-detail-copy,.store-detail-feedback,.store-detail-author-card{border:1px solid rgba(255,255,255,.11);border-radius:24px;background:rgba(255,255,255,.045);padding:20px}.store-detail-copy h2{font-size:2.25rem;margin:8px 0 10px}.store-detail-copy p{line-height:1.72;color:#dbe4ff}.store-detail-meta{display:flex;gap:8px;flex-wrap:wrap}.store-detail-meta span,.store-detail-rating-summary span{display:inline-flex;border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:7px 10px;background:rgba(255,255,255,.06);font-size:.78rem;font-weight:900;color:#dfe8ff;text-transform:uppercase;letter-spacing:.04em}.store-detail-author-head{display:flex;gap:14px;align-items:center;margin:12px 0 18px}.store-detail-avatar{width:64px;height:64px;border-radius:20px;background:linear-gradient(135deg,var(--store-blue),var(--store-purple));display:grid;place-items:center;overflow:hidden;font-weight:950}.store-detail-avatar img{width:100%;height:100%;object-fit:cover}.store-detail-author-table{width:100%;border-collapse:collapse;margin:12px 0 20px}.store-detail-author-table th,.store-detail-author-table td{border-bottom:1px solid rgba(255,255,255,.10);padding:10px 0;text-align:left;vertical-align:top}.store-detail-author-table th{color:var(--store-muted);font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;width:110px}.store-detail-author-table a,.store-detail-recent a{color:#fff;font-weight:900}.store-detail-recent{display:grid;gap:10px;margin:0;padding:0;list-style:none}.store-detail-recent li{border:1px solid rgba(255,255,255,.09);border-radius:16px;padding:10px 12px;background:rgba(0,0,0,.16)}.store-detail-recent small{display:block;color:var(--store-green);text-transform:uppercase;font-weight:900;letter-spacing:.06em}.store-detail-stars{display:flex;gap:6px;margin:12px 0}.store-detail-stars button{background:rgba(255,255,255,.06)!important;border:1px solid rgba(255,255,255,.14)!important;box-shadow:none!important;padding:8px 10px!important;border-radius:12px;color:#788299!important;font-size:1.15rem}.store-detail-stars button.is-active{color:#ffd166!important;border-color:#ffd166!important;background:rgba(255,209,102,.12)!important}.store-detail-feedback-form textarea{width:100%;border-radius:16px;border:1px solid var(--store-border);background:rgba(0,0,0,.24);color:#fff;padding:12px;margin:4px 0 12px;resize:vertical}.store-detail-comments{display:grid;gap:10px;margin-top:16px}.store-detail-comment{border:1px solid rgba(255,255,255,.10);border-radius:18px;padding:13px;background:rgba(0,0,0,.18)}.store-detail-comment strong{display:flex;justify-content:space-between;gap:12px}.store-detail-comment .stars{color:#ffd166;letter-spacing:1px}.store-detail-empty{color:var(--store-muted);border:1px dashed rgba(255,255,255,.15);border-radius:16px;padding:14px}@media(max-width:1050px){.store-detail-layout{grid-template-columns:1fr}.store-detail-author-card{order:3}}


/* DevOne CMS 1.2.5 — Store modals inherit the selected admin appearance. */
body.devone-admin.devone-admin-theme-light .devone-store-detail-overlay,
body.devone-admin.devone-admin-theme-light .devone-store-checkout-overlay{
  background:rgba(39,33,22,.42)!important;
  backdrop-filter:blur(12px) saturate(.9);
}
body.devone-admin.devone-admin-theme-light .devone-store-detail-modal,
body.devone-admin.devone-admin-theme-light .devone-store-checkout-modal{
  --store-modal-surface:rgba(255,255,255,.98);
  --store-modal-panel:rgba(255,252,244,.94);
  --store-modal-panel-strong:#fff8df;
  --store-modal-text:#211d17;
  --store-modal-muted:#6a6256;
  --store-modal-line:rgba(108,78,14,.19);
  --store-modal-link:#76550d;
  --store-modal-shadow:0 36px 120px rgba(66,47,8,.22);
  color:var(--store-modal-text)!important;
  border-color:var(--store-modal-line)!important;
  background:
    radial-gradient(circle at 4% 0%,rgba(255,243,196,.88),transparent 31%),
    radial-gradient(circle at 100% 4%,rgba(245,222,164,.62),transparent 34%),
    linear-gradient(145deg,var(--store-modal-surface),#fffaf0)!important;
  box-shadow:var(--store-modal-shadow)!important;
}
body.devone-admin.devone-admin-theme-light .devone-store-detail-modal :is(h1,h2,h3,h4,strong,b,label),
body.devone-admin.devone-admin-theme-light .devone-store-checkout-modal :is(h1,h2,h3,h4,strong,b,label){
  color:#17130d!important;
}
body.devone-admin.devone-admin-theme-light .devone-store-detail-modal :is(p,small,li,td),
body.devone-admin.devone-admin-theme-light .devone-store-checkout-modal :is(p,small,li,td){
  color:var(--store-modal-muted)!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-media{
  border-color:var(--store-modal-line)!important;
  background:linear-gradient(145deg,#f7f2e7,#fff)!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-copy,
body.devone-admin.devone-admin-theme-light .store-detail-feedback,
body.devone-admin.devone-admin-theme-light .store-detail-author-card,
body.devone-admin.devone-admin-theme-light .store-detail-comment,
body.devone-admin.devone-admin-theme-light .store-detail-recent li{
  color:var(--store-modal-text)!important;
  border-color:var(--store-modal-line)!important;
  background:var(--store-modal-panel)!important;
  box-shadow:0 12px 32px rgba(72,52,9,.07)!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-copy p,
body.devone-admin.devone-admin-theme-light .store-detail-comment p,
body.devone-admin.devone-admin-theme-light .store-detail-author-head span{
  color:var(--store-modal-muted)!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-meta span,
body.devone-admin.devone-admin-theme-light .store-detail-rating-summary span{
  color:#4e431f!important;
  border-color:rgba(108,78,14,.20)!important;
  background:var(--store-modal-panel-strong)!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-author-table th,
body.devone-admin.devone-admin-theme-light .store-detail-author-table td{
  border-bottom-color:var(--store-modal-line)!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-author-table th{
  color:#756b5c!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-author-table a,
body.devone-admin.devone-admin-theme-light .store-detail-recent a,
body.devone-admin.devone-admin-theme-light .devone-store-detail-modal a:not(.button-like):not(.btn){
  color:var(--store-modal-link)!important;
  text-decoration-color:rgba(118,85,13,.35)!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-recent small{
  color:#16794c!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-feedback-form textarea{
  color:#211d17!important;
  background:#fff!important;
  border-color:rgba(108,78,14,.24)!important;
  caret-color:#76550d;
}
body.devone-admin.devone-admin-theme-light .store-detail-feedback-form textarea::placeholder{
  color:#8b8376!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-stars button{
  color:#8c806e!important;
  border-color:rgba(108,78,14,.22)!important;
  background:#fff!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-stars button.is-active{
  color:#9c7014!important;
  border-color:#d7aa3e!important;
  background:#fff5d5!important;
}
body.devone-admin.devone-admin-theme-light .store-detail-empty{
  color:#746b5e!important;
  border-color:rgba(108,78,14,.25)!important;
  background:rgba(255,248,223,.62)!important;
}
body.devone-admin.devone-admin-theme-light .devone-store-detail-close,
body.devone-admin.devone-admin-theme-light .devone-store-checkout-close{
  color:#fff!important;
  border:1px solid rgba(118,85,13,.28)!important;
  background:linear-gradient(135deg,#d7aa3e,#8a6615)!important;
  box-shadow:0 10px 24px rgba(108,78,14,.22)!important;
}
body.devone-admin.devone-admin-theme-light .devone-store-detail-modal .button-like.secondary,
body.devone-admin.devone-admin-theme-light .devone-store-checkout-modal .button-like.secondary,
body.devone-admin.devone-admin-theme-light .devone-store-detail-modal .btn.secondary,
body.devone-admin.devone-admin-theme-light .devone-store-checkout-modal .btn.secondary{
  color:#2c261d!important;
  background:#fff!important;
  border-color:rgba(108,78,14,.22)!important;
}

/* Preserve a coherent dark modal when the original dark admin appearance is selected. */
body.devone-admin.devone-admin-theme-dark .devone-store-detail-modal,
body.devone-admin.devone-admin-theme-dark .devone-store-checkout-modal{
  color:#f6f8ff!important;
}
body.devone-admin.devone-admin-theme-dark .devone-store-detail-modal :is(h1,h2,h3,h4,strong,b,label),
body.devone-admin.devone-admin-theme-dark .devone-store-checkout-modal :is(h1,h2,h3,h4,strong,b,label){
  color:#fff!important;
}
body.devone-admin.devone-admin-theme-dark .devone-store-detail-modal :is(p,small,li,td),
body.devone-admin.devone-admin-theme-dark .devone-store-checkout-modal :is(p,small,li,td){
  color:#dbe4ff!important;
}

</style>

<script>
(function(){
  const search = document.getElementById('devoneStoreSearch');
  const tabs = document.getElementById('devoneStoreTabs');
  const cards = Array.from(document.querySelectorAll('.native-store-card'));
  let category = 'all';
  function filterStore(){
    const term = (search && search.value ? search.value : '').toLowerCase().trim();
    cards.forEach(card => {
      const okCategory = category === 'all' || card.dataset.category === category;
      const okSearch = !term || (card.dataset.search || '').includes(term);
      card.classList.toggle('is-hidden', !(okCategory && okSearch));
    });
  }
  search && search.addEventListener('input', filterStore);
  tabs && tabs.addEventListener('click', function(e){
    const btn = e.target.closest('button[data-category]');
    if (!btn) return;
    category = btn.dataset.category;
    tabs.querySelectorAll('button').forEach(b => b.classList.toggle('active', b === btn));
    filterStore();
  });
  const slides = Array.from(document.querySelectorAll('.store-featured-slide'));
  let index = 0;
  if (slides.length > 1) {
    setInterval(() => {
      slides[index].classList.remove('active');
      index = (index + 1) % slides.length;
      slides[index].classList.add('active');
    }, 5200);
  }
})();
</script>

<script>
(function(){
  const dataEl=document.getElementById('devoneStoreDetailData'); let details={};
  try{details=dataEl?JSON.parse(dataEl.textContent||'{}'):{};}catch(e){details={};}
  const overlay=document.getElementById('devoneStoreDetailOverlay'); if(!overlay)return;
  const closeBtn=document.getElementById('devoneStoreDetailClose'), title=document.getElementById('devoneStoreDetailTitle'), desc=document.getElementById('storeDetailDesc'), media=document.getElementById('storeDetailMedia'), meta=document.getElementById('storeDetailMeta'), marketLink=document.getElementById('storeDetailMarketplaceLink'), authorAvatar=document.getElementById('storeDetailAuthorAvatar'), authorName=document.getElementById('storeDetailAuthorName'), authorSub=document.getElementById('storeDetailAuthorSub'), authorWebsite=document.getElementById('storeDetailAuthorWebsite'), authorPage=document.getElementById('storeDetailAuthorPage'), recent=document.getElementById('storeDetailRecent'), summary=document.getElementById('storeDetailRatingSummary'), comments=document.getElementById('storeDetailComments'), slugInput=document.getElementById('storeDetailFeedbackSlug'), ratingInput=document.getElementById('storeDetailFeedbackRating'), starWrap=document.getElementById('storeDetailStars');
  function text(n,v){if(n)n.textContent=v||'';} function safeUrl(u){return u&&/^https?:\/\//i.test(u)?u:'#';} function stars(n){n=Math.round(Number(n)||0);return '★★★★★'.split('').map((s,i)=>i<n?'★':'☆').join('');}
  function setRating(n){if(ratingInput)ratingInput.value=String(n||0); if(starWrap)starWrap.querySelectorAll('button').forEach(b=>b.classList.toggle('is-active',Number(b.dataset.rating)<=n));}
  function openDetail(slug){const item=details[slug]; if(!item)return; text(title,item.name||'Marketplace Item'); text(desc,item.description||''); meta.innerHTML=''; [item.kind,item.category,item.version?'v'+item.version:'',item.price].filter(Boolean).forEach(v=>{const s=document.createElement('span');s.textContent=v;meta.appendChild(s);}); media.innerHTML=''; if(item.thumbnail){const img=document.createElement('img');img.alt='';img.src=safeUrl(item.thumbnail);media.appendChild(img);}else{const sp=document.createElement('span');sp.textContent=String(item.kind||'item').slice(0,2).toUpperCase();media.appendChild(sp);} marketLink.href=safeUrl(item.marketplace_url); const author=item.author||{}; text(authorName,author.name||'Marketplace Author'); text(authorSub,item.kind?'DevOne '+item.kind+' contributor':'DevOne Marketplace contributor'); authorAvatar.innerHTML=''; if(author.avatar){const img=document.createElement('img');img.alt='';img.src=safeUrl(author.avatar);authorAvatar.appendChild(img);}else{authorAvatar.textContent=String((author.name||'A').slice(0,1)).toUpperCase();} authorWebsite.href=safeUrl(author.website); authorWebsite.textContent=author.website?'Visit website':'—'; authorPage.href=safeUrl(author.marketplace_page); authorPage.textContent=author.marketplace_page?'View profile':'—'; recent.innerHTML=''; (item.recent||[]).slice(0,5).forEach(r=>{const li=document.createElement('li'),a=document.createElement('a'),small=document.createElement('small'); a.href=safeUrl(r.url); a.target='_blank'; a.rel='noopener'; a.textContent=r.name||'Marketplace Item'; small.textContent=r.type||'item'; li.appendChild(small); li.appendChild(a); recent.appendChild(li);}); if(!recent.children.length)recent.innerHTML='<li class="store-detail-empty">No recent contributions were included in the manifest yet.</li>'; const fb=item.feedback||{average:0,count:0}; summary.innerHTML=''; [stars(fb.average), Number(fb.average||0).toFixed(1)+' average', String(Number(fb.count)||0)+' ratings'].forEach((v,i)=>{const sp=document.createElement('span'); if(i===0)sp.className='stars'; sp.textContent=v; summary.appendChild(sp);}); comments.innerHTML=''; (item.comments||[]).forEach(c=>{const div=document.createElement('div'); div.className='store-detail-comment'; div.innerHTML='<strong><span></span><span class="stars"></span></strong><p></p><small></small>'; div.querySelector('strong span:first-child').textContent=c.display_name||'DevOne User'; div.querySelector('.stars').textContent=stars(c.rating||0); div.querySelector('p').textContent=c.comment||''; div.querySelector('small').textContent=c.created_at||''; comments.appendChild(div);}); if(!comments.children.length)comments.innerHTML='<div class="store-detail-empty">No marketplace comments yet. Be the first to leave one for every CMS install to see.</div>'; if(slugInput)slugInput.value=item.slug||slug; setRating(0); overlay.hidden=false; overlay.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden';}
  function closeDetail(){overlay.hidden=true;overlay.setAttribute('aria-hidden','true');document.body.style.overflow='';}
  document.addEventListener('click',e=>{const titleLink=e.target.closest('[data-store-detail]'); if(titleLink){e.preventDefault();openDetail(titleLink.dataset.storeDetail);}}); closeBtn&&closeBtn.addEventListener('click',closeDetail); overlay.addEventListener('click',e=>{if(e.target===overlay)closeDetail();}); document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!overlay.hidden)closeDetail();}); starWrap&&starWrap.addEventListener('click',e=>{const b=e.target.closest('button[data-rating]'); if(b)setRating(Number(b.dataset.rating)||0);});
})();
</script>

<?php devone_admin_footer();
