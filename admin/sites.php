<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();
devone_require_permission('admin_all');

$msg = $_GET['msg'] ?? '';
$error = '';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save_network_settings') {
        if (!devone_feature_enabled('multisite')) {
            $error = 'Network Mode requires an active DevOne Pro or Enterprise license.';
        } else {
            set_setting('network_base_domain', devone_network_clean_domain($_POST['network_base_domain'] ?? devone_network_base_domain()));
            set_setting('network_mode_enabled', !empty($_POST['network_mode_enabled']) ? '1' : '0');
            devone_ensure_network_schema(true);
            header('Location: sites.php?msg=' . rawurlencode('Network settings saved.'));
            exit;
        }
    }

    if ($action === 'create_site') {
        if (!devone_network_enabled()) {
            $error = 'Network Mode must be enabled before creating sites.';
        } else {
            $ownerId = (int)($_POST['owner_user_id'] ?? 0);
            $result = devone_network_create_site(array(
                'site_name' => $_POST['site_name'] ?? '',
                'site_slug' => $_POST['site_slug'] ?? '',
                'owner_user_id' => $ownerId,
                'admin_username' => $_POST['admin_username'] ?? '',
                'admin_email' => $_POST['admin_email'] ?? '',
                'allow_client_domain' => !empty($_POST['allow_client_domain']),
                'allow_client_theme_uploads' => !empty($_POST['allow_client_theme_uploads']),
            ));
            if (!empty($result['ok'])) {
                header('Location: sites.php?manage=' . (int)($result['site_id'] ?? 0) . '&msg=' . rawurlencode('Site created: ' . ($result['domain'] ?? '')));
                exit;
            }
            $error = $result['message'] ?? 'Site could not be created.';
        }
    }

    if ($action === 'update_site') {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $result = function_exists('devone_network_update_site') ? devone_network_update_site($siteId, array(
            'site_name' => $_POST['site_name'] ?? '',
            'owner_user_id' => $_POST['owner_user_id'] ?? 0,
            'admin_username' => $_POST['admin_username'] ?? '',
            'admin_email' => $_POST['admin_email'] ?? '',
            'allow_client_domain' => !empty($_POST['allow_client_domain']),
            'allow_client_theme_uploads' => !empty($_POST['allow_client_theme_uploads']),
            'status' => $_POST['status'] ?? 'active',
        )) : array('ok'=>false, 'message'=>'Network update helper is missing.');
        if (!empty($result['ok'])) {
            header('Location: sites.php?manage=' . $siteId . '&msg=' . rawurlencode($result['message'] ?? 'Site updated.'));
            exit;
        }
        $error = $result['message'] ?? 'Site could not be updated.';
    }

    if ($action === 'delete_site') {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $confirm = $_POST['delete_confirm'] ?? '';
        $result = function_exists('devone_network_delete_site') ? devone_network_delete_site($siteId, $confirm) : array('ok'=>false, 'message'=>'Network delete helper is missing.');
        if (!empty($result['ok'])) {
            if (!empty($_SESSION['devone_admin_site_id']) && (int)$_SESSION['devone_admin_site_id'] === $siteId) {
                $_SESSION['devone_admin_site_id'] = 1;
            }
            header('Location: sites.php?msg=' . rawurlencode($result['message'] ?? 'Site deleted.'));
            exit;
        }
        $error = $result['message'] ?? 'Site could not be deleted.';
    }
}

$networkLicenseUnlocked = function_exists('devone_feature_enabled') && devone_feature_enabled('multisite');
$plan = function_exists('devone_license_plan') ? devone_license_plan() : 'free';
$licenseStatus = function_exists('devone_license_status') ? devone_license_status() : array('plan_label'=>'DevOne Free');
$networkEnabled = false;
$baseDomain = function_exists('devone_network_base_domain') ? devone_network_base_domain() : '';
$sites = array();
$users = table_exists('users') ? db()->query('SELECT id,username,email,display_name,role FROM `' . table_name('users') . '` ORDER BY username ASC')->fetchAll() : array();
if ($networkLicenseUnlocked) {
    devone_ensure_network_schema();
    $networkEnabled = devone_network_enabled();
    $baseDomain = devone_network_base_domain();
    if (table_exists('sites')) {
        try { $sites = db()->query('SELECT * FROM `' . table_name('sites') . '` ORDER BY id ASC')->fetchAll(); } catch (Exception $e) { $error = $error ?: $e->getMessage(); }
    }
}

$manageId = (int)($_GET['manage'] ?? 0);
$manageSite = $manageId > 0 ? devone_network_get_site($manageId) : null;
$manageDomains = array();
if ($manageSite && table_exists('site_domains')) {
    try {
        $stmt = db()->prepare('SELECT * FROM `' . table_name('site_domains') . '` WHERE site_id=? ORDER BY is_primary DESC, id ASC');
        $stmt->execute(array((int)$manageSite['id']));
        $manageDomains = $stmt->fetchAll();
    } catch (Exception $e) { $error = $error ?: $e->getMessage(); }
}

function devone_site_user_option_label($u) {
    return ($u['display_name'] ?: $u['username']) . ' — ' . $u['email'];
}

devone_admin_header('Network Sites - DevOneCMS');
?>
<section class="page-manager-hero">
  <div>
    <p class="admin-kicker"><span></span> Pro / Enterprise Network</p>
    <h1>Sites</h1>
    <p class="muted">Manage one DevOne install with multiple client sites, auto subdomains, private site access, and custom domain mapping.</p>
  </div>
  <div class="admin-hero-actions">
    <a class="btn secondary" href="domain.php">Current Site Domain</a>
    <a class="btn secondary" href="dashboard.php">Dashboard</a>
  </div>
</section>
<?php devone_flash($msg); devone_flash($error, 'card error-card'); ?>

<?php if (!$networkLicenseUnlocked): ?>
<section class="card error-card">
  <div class="section-head"><h2>Network Mode is locked</h2><span>DevOne Pro / Enterprise</span></div>
  <p>Multisite is available in <strong>DevOne Pro</strong> and <strong>DevOne Enterprise</strong>. Activate a valid license from the Upgrade page to unlock Network Sites, custom domains, and private per-site themes.</p>
  <div class="inline-actions"><a class="btn" href="upgrade.php">Enter License Key</a></div>
</section>
<?php devone_admin_footer(); exit; ?>
<?php elseif (!$networkEnabled): ?>
<section class="card" style="border-color:rgba(66,255,155,.25);background:rgba(66,255,155,.07);">
  <div class="section-head"><h2>Network Mode is unlocked</h2><span><?= e($licenseStatus['plan_label'] ?? 'DevOne Pro') ?></span></div>
  <p>Your license is active and Network Mode is available. Turn on <strong>Enable Network Mode</strong> below, save, and DevOne will prepare the multisite tables and private site folders.</p>
</section>
<?php endif; ?>

<section class="card">
  <div class="section-head"><h2>Network Settings</h2><span><?= e($licenseStatus['plan_label'] ?? strtoupper($plan)) ?></span></div>
  <form method="post" class="settings-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_network_settings">
    <label>Network Base Domain
      <input name="network_base_domain" value="<?= e($baseDomain) ?>" placeholder="example.com">
      <small>Auto subdomains generate from this, like client.example.com. Add wildcard DNS: *.<?= e($baseDomain ?: 'example.com') ?> → this server IP.</small>
    </label>
    <label class="check-row"><input type="checkbox" name="network_mode_enabled" value="1" <?= get_setting('network_mode_enabled','0')==='1'?'checked':'' ?>> Enable Network Mode</label>
    <button>Save Network Settings</button>
  </form>
</section>

<?php if ($networkEnabled && $manageSite): ?>
<section class="card" id="manage-site">
  <div class="section-head"><h2>Manage Site: <?= e($manageSite['site_name']) ?></h2><span><?= e($manageSite['primary_domain'] ?: $manageSite['auto_subdomain']) ?></span></div>
  <form method="post" class="settings-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_site">
    <input type="hidden" name="site_id" value="<?= e($manageSite['id']) ?>">
    <input type="hidden" name="back" value="dashboard.php">
    <label>Site Name<input name="site_name" required value="<?= e($manageSite['site_name']) ?>"></label>
    <label>Site Slug<input value="<?= e($manageSite['site_slug']) ?>" readonly><small>Slug controls the generated subdomain and cannot be changed in this build.</small></label>
    <label>Official URL<input value="<?= e($manageSite['primary_domain'] ?: $manageSite['auto_subdomain']) ?>" readonly></label>
    <label>Assign Site Admin
      <select name="owner_user_id">
        <option value="0">No assigned user</option>
        <?php foreach($users as $u): ?>
          <option value="<?= e($u['id']) ?>" <?= (int)$manageSite['owner_user_id']===(int)$u['id']?'selected':'' ?>><?= e(devone_site_user_option_label($u)) ?></option>
        <?php endforeach; ?>
      </select>
      <small>The assigned user becomes the Site Admin and can only access this site unless assigned elsewhere.</small>
    </label>
    <label>Admin Username<input name="admin_username" value="<?= e($manageSite['admin_username']) ?>" placeholder="clientadmin"></label>
    <label>Admin Email<input type="email" name="admin_email" value="<?= e($manageSite['admin_email']) ?>" placeholder="admin@client.com"></label>
    <label>Status
      <select name="status">
        <option value="active" <?= $manageSite['status']==='active'?'selected':'' ?>>Active</option>
        <option value="disabled" <?= $manageSite['status']==='disabled'?'selected':'' ?>>Disabled</option>
      </select>
    </label>
    <label class="check-row"><input type="checkbox" name="allow_client_domain" value="1" <?= !empty($manageSite['allow_client_domain'])?'checked':'' ?>> Allow this client to change domain</label>
    <input type="hidden" name="allow_client_theme_uploads" value="<?= !empty($manageSite['allow_client_theme_uploads']) ? '1' : '0' ?>">
    <p class="muted">Executable theme package installation is Network Super Admin-only in Core 1.7.4. Existing client-theme-upload metadata is preserved for compatibility.</p>
    <div class="inline-actions">
      <button>Save Site Changes</button>
      <a class="btn secondary" href="domain.php?site_id=<?= e($manageSite['id']) ?>">Manage Domain</a>
      <button type="submit" class="btn secondary" formaction="switch-site.php" formmethod="post" formnovalidate>Open Site Dashboard</button>
      <a class="btn secondary" href="sites.php">Cancel</a>
    </div>
  </form>
</section>

<section class="card pages-list-card">
  <div class="section-head"><h2>Site Domains</h2><span><?= number_format(count($manageDomains)) ?> record<?= count($manageDomains)===1?'':'s' ?></span></div>
  <div class="table-scroll">
    <table class="table"><tr><th>Domain</th><th>Type</th><th>Primary</th><th>Status</th><th>SSL</th></tr>
    <?php foreach($manageDomains as $d): ?>
      <tr><td><code><?= e($d['domain']) ?></code></td><td><?= e($d['domain_type']) ?></td><td><?= !empty($d['is_primary'])?'Yes':'No' ?></td><td><?= e($d['verification_status']) ?></td><td><?= e($d['ssl_status']) ?></td></tr>
    <?php endforeach; ?>
    </table>
  </div>
</section>

<section class="card error-card" id="danger">
  <div class="section-head"><h2>Delete Site + Data</h2><span>Permanent</span></div>
  <?php if ((int)$manageSite['id'] === 1): ?>
    <p>The main DevOne site cannot be deleted.</p>
  <?php else: ?>
    <p>This permanently deletes this site from the database and removes its private folder:</p>
    <pre style="white-space:pre-wrap;">content/sites/<?= e($manageSite['id']) ?>/</pre>
    <p class="muted">Database purge includes rows from all DevOne tables that have <code>site_id</code>, including pages, media records, menus, themes, site domains, site settings, site users, and future site-aware plugin tables. User accounts are not deleted; only their assignment to this site is removed.</p>
    <form method="post" class="settings-grid" onsubmit="return confirm('Delete this site and purge its data permanently? This cannot be undone.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_site">
      <input type="hidden" name="site_id" value="<?= e($manageSite['id']) ?>">
      <label>Type DELETE to confirm
        <input name="delete_confirm" placeholder="DELETE" autocomplete="off">
      </label>
      <button style="background:linear-gradient(135deg,#ff4b5f,#ff8a3d);">Delete Site + Purge Data</button>
    </form>
  <?php endif; ?>
</section>
<?php elseif ($networkEnabled && $manageId > 0): ?>
<section class="card error-card"><h2>Site not found</h2><p>The requested site could not be found or has already been deleted.</p><a class="btn" href="sites.php">Back to Sites</a></section>
<?php endif; ?>

<?php if ($networkEnabled): ?>
<section class="card">
  <div class="section-head"><h2>Create Client Site</h2><span>Auto subdomain + private folders</span></div>
  <form method="post" class="settings-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_site">
    <label>Site Name<input name="site_name" required placeholder="Big Step Media"></label>
    <label>Site Slug<input name="site_slug" placeholder="big-step-media"><small>Used for auto subdomain. Leave blank to generate.</small></label>
    <label>Assign Site Admin
      <select name="owner_user_id">
        <option value="0">No assigned user yet</option>
        <?php foreach($users as $u): ?>
          <option value="<?= e($u['id']) ?>"><?= e(devone_site_user_option_label($u)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Admin Username<input name="admin_username" placeholder="clientadmin"></label>
    <label>Admin Email<input type="email" name="admin_email" placeholder="admin@client.com"></label>
    <label class="check-row"><input type="checkbox" name="allow_client_domain" value="1" checked> Allow this client to change domain</label>
    <input type="hidden" name="allow_client_theme_uploads" value="0"><p class="muted">Theme packages are installed by the Network Super Admin, then activated per site.</p>
    <button>Create Site</button>
  </form>
</section>
<?php endif; ?>

<section class="card pages-list-card">
  <div class="section-head"><h2>All Sites</h2><span><?= number_format(count($sites)) ?> site<?= count($sites)===1?'':'s' ?></span></div>
  <div class="table-scroll devone-desktop-table">
    <table class="table"><tr><th>ID</th><th>Site</th><th>Official URL</th><th>Admin Username</th><th>Admin Email</th><th>Status</th><th>Actions</th></tr>
    <?php foreach($sites as $site): ?>
      <tr>
        <td><?= e($site['id']) ?></td>
        <td><strong><?= e($site['site_name']) ?></strong><br><small><?= e($site['site_slug']) ?></small></td>
        <td><code><?= e($site['primary_domain'] ?: $site['auto_subdomain']) ?></code></td>
        <td><?= e($site['admin_username']) ?></td>
        <td><?= e($site['admin_email']) ?></td>
        <td><span class="status-pill status-<?= e($site['status']) ?>"><?= e($site['status']) ?></span></td>
        <td><div class="inline-actions"><a class="btn" href="sites.php?manage=<?= e($site['id']) ?>#manage-site">Manage</a><form method="post" action="switch-site.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="site_id" value="<?= e($site['id']) ?>"><input type="hidden" name="back" value="dashboard.php"><button type="submit" class="btn secondary">Open</button></form><a class="btn secondary" href="domain.php?site_id=<?= e($site['id']) ?>">Domain</a><?php if((int)$site['id']!==1): ?><a class="btn secondary" href="sites.php?manage=<?= e($site['id']) ?>#danger">Delete</a><?php endif; ?></div></td>
      </tr>
    <?php endforeach; ?></table>
  </div>
  <div class="devone-mobile-cards">
    <?php foreach($sites as $site): ?>
      <details class="devone-mobile-card">
        <summary><span class="devone-mobile-card-icon">🌐</span><span class="devone-mobile-card-title-wrap"><strong><?= e($site['site_name']) ?></strong><small><?= e($site['primary_domain'] ?: $site['auto_subdomain']) ?> · <?= e($site['status']) ?></small></span><span class="devone-mobile-card-arrow">⌄</span></summary>
        <div class="devone-mobile-card-body">
          <div class="devone-mobile-meta-grid">
            <span>ID</span><strong><?= e($site['id']) ?></strong>
            <span>URL</span><code><?= e($site['primary_domain'] ?: $site['auto_subdomain']) ?></code>
            <span>Admin</span><strong><?= e($site['admin_username'] ?: 'Not assigned') ?></strong>
            <span>Email</span><strong><?= e($site['admin_email'] ?: 'Not assigned') ?></strong>
            <span>Client domain</span><strong><?= !empty($site['allow_client_domain']) ? 'Allowed' : 'Locked' ?></strong>
          </div>
          <div class="devone-mobile-card-actions inline-actions"><a class="btn" href="sites.php?manage=<?= e($site['id']) ?>#manage-site">Manage</a><form method="post" action="switch-site.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="site_id" value="<?= e($site['id']) ?>"><input type="hidden" name="back" value="dashboard.php"><button type="submit" class="btn secondary">Open</button></form><a class="btn secondary" href="domain.php?site_id=<?= e($site['id']) ?>">Domain</a><?php if((int)$site['id']!==1): ?><a class="btn secondary" href="sites.php?manage=<?= e($site['id']) ?>#danger">Delete</a><?php endif; ?></div>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
</section>
<?php devone_admin_footer();
