<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_site_domain');
verify_csrf();

$msg = $_GET['msg'] ?? '';
$error = '';
$requestedSiteId = (int)($_GET['site_id'] ?? 0);
$currentUserId = devone_current_user_id();

if ($requestedSiteId > 0 && devone_user_can_access_site($currentUserId, $requestedSiteId)) {
    $_SESSION['devone_admin_site_id'] = $requestedSiteId;
}
$siteId = devone_admin_current_site_id();
$site = devone_network_get_site($siteId);

if (!$site) {
    devone_admin_header('Site Domain - DevOneCMS');
    echo '<h1>Site Domain</h1><div class="card error-card">Site could not be resolved.</div>';
    devone_admin_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_domain') {
        $domain = $_POST['custom_domain'] ?? '';
        $result = devone_network_set_custom_domain($siteId, $domain, $currentUserId);
        if (!empty($result['ok'])) { header('Location: domain.php?site_id=' . $siteId . '&msg=' . rawurlencode($result['message'])); exit; }
        $error = $result['message'] ?? 'Could not save domain.';
    }
    if ($action === 'verify_domain') {
        $domain = $_POST['custom_domain'] ?? '';
        if (!devone_user_can_manage_site_domain($currentUserId, $siteId)) { $error = 'You do not have permission to verify this site domain.'; }
        else {
            // v1 foundation: manual verification button. Automated DNS TXT/A checks come with Enterprise domain tooling later.
            $result = devone_network_verify_domain($siteId, $domain);
            if (!empty($result['ok'])) { header('Location: domain.php?site_id=' . $siteId . '&msg=' . rawurlencode($result['message'])); exit; }
            $error = $result['message'] ?? 'Could not verify domain.';
        }
    }
}

$domains = array();
if (table_exists('site_domains')) {
    try {
        $stmt = db()->prepare('SELECT * FROM `' . table_name('site_domains') . '` WHERE site_id=? ORDER BY is_primary DESC, id ASC');
        $stmt->execute(array($siteId));
        $domains = $stmt->fetchAll();
    } catch (Exception $e) { $error = $error ?: $e->getMessage(); }
}
$canManageDomain = devone_user_can_manage_site_domain($currentUserId, $siteId);
$serverIp = $_SERVER['SERVER_ADDR'] ?? 'YOUR_SERVER_IP';

devone_admin_header('Site Domain - DevOneCMS');
?>
<section class="page-manager-hero">
  <div>
    <p class="admin-kicker"><span></span> Site Settings</p>
    <h1>Site Domain</h1>
    <p class="muted">Change the official URL for <strong><?= e($site['site_name']) ?></strong>. Client admins only see domains for sites they are assigned to.</p>
  </div>
  <div class="admin-hero-actions">
    <?php if (devone_network_is_super_admin()): ?><a class="btn secondary" href="sites.php">Manage Sites</a><?php endif; ?>
    <a class="btn secondary" href="dashboard.php">Dashboard</a>
  </div>
</section>
<?php devone_flash($msg); devone_flash($error, 'card error-card'); ?>

<?php if (!devone_network_enabled()): ?>
<?php if (function_exists('devone_feature_enabled') && devone_feature_enabled('multisite')): ?>
<div class="card"><strong>Domain controls are unlocked but Network Mode is not enabled yet.</strong><br>Go to <a href="sites.php">Sites</a>, enable Network Mode, and save the Network Settings.</div>
<?php else: ?>
<div class="card error-card"><strong>Domain controls are locked.</strong><br>Custom domains are available in DevOne Pro and Enterprise Network Mode. Activate a Pro or Enterprise license from the Upgrade page.</div>
<?php endif; ?>
<?php elseif (!$canManageDomain): ?>
<div class="card error-card"><strong>Domain changes are disabled for this site.</strong><br>A Network Super Admin can enable “Allow client to change domain” from the Sites manager.</div>
<?php else: ?>
<section class="card">
  <div class="section-head"><h2>Change Site URL</h2><span><?= e($site['primary_domain'] ?: $site['auto_subdomain']) ?></span></div>
  <form method="post" class="settings-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_domain">
    <label>Auto Subdomain
      <input value="<?= e($site['auto_subdomain']) ?>" readonly>
      <small>This stays as the fallback/internal DevOne URL.</small>
    </label>
    <label>Custom Domain
      <input name="custom_domain" value="<?= e($site['domain_mode']==='custom' ? $site['primary_domain'] : '') ?>" placeholder="clientdomain.com">
      <small>Do not include http:// or https://.</small>
    </label>
    <button>Save Domain</button>
  </form>
</section>

<section class="card">
  <div class="section-head"><h2>DNS Instructions</h2><span>Client setup</span></div>
  <p class="muted">The domain owner must point DNS to this DevOne server before the domain will work publicly.</p>
  <div class="table-scroll">
    <table class="table"><tr><th>Record</th><th>Name</th><th>Value</th></tr><tr><td>A</td><td>@</td><td><code><?= e($serverIp) ?></code></td></tr><tr><td>A or CNAME</td><td>www</td><td><code><?= e($serverIp) ?></code> or <code>@</code></td></tr></table>
  </div>
  <p class="muted">For automatic subdomains on the network base domain, the Network Super Admin should also add wildcard DNS: <code>*.<?= e(devone_network_base_domain()) ?></code> → <code><?= e($serverIp) ?></code>.</p>
</section>
<?php endif; ?>

<section class="card pages-list-card">
  <div class="section-head"><h2>Domains for this site</h2><span><?= number_format(count($domains)) ?> record<?= count($domains)===1?'':'s' ?></span></div>
  <div class="table-scroll devone-desktop-table">
    <table class="table"><tr><th>Domain</th><th>Type</th><th>Primary</th><th>Status</th><th>SSL</th><th>Actions</th></tr>
    <?php foreach($domains as $d): ?>
      <tr><td><code><?= e($d['domain']) ?></code></td><td><?= e($d['domain_type']) ?></td><td><?= !empty($d['is_primary'])?'Yes':'No' ?></td><td><?= e($d['verification_status']) ?></td><td><?= e($d['ssl_status']) ?></td><td><?php if($canManageDomain && $d['domain_type']==='custom'): ?><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="verify_domain"><input type="hidden" name="custom_domain" value="<?= e($d['domain']) ?>"><button>Mark Verified</button></form><?php else: ?><span class="muted">—</span><?php endif; ?></td></tr>
    <?php endforeach; ?></table>
  </div>
  <div class="devone-mobile-cards">
    <?php foreach($domains as $d): ?>
      <details class="devone-mobile-card"><summary><span class="devone-mobile-card-icon">🔗</span><span class="devone-mobile-card-title-wrap"><strong><?= e($d['domain']) ?></strong><small><?= e($d['domain_type']) ?> · <?= e($d['verification_status']) ?></small></span><span class="devone-mobile-card-arrow">⌄</span></summary><div class="devone-mobile-card-body"><div class="devone-mobile-meta-grid"><span>Primary</span><strong><?= !empty($d['is_primary'])?'Yes':'No' ?></strong><span>SSL</span><strong><?= e($d['ssl_status']) ?></strong><span>Status</span><strong><?= e($d['verification_status']) ?></strong></div></div></details>
    <?php endforeach; ?>
  </div>
</section>
<?php devone_admin_footer();
