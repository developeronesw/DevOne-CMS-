<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_settings');
verify_csrf();
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_cache_settings') {
        set_setting('devone_cache_enabled', !empty($_POST['devone_cache_enabled']) ? '1' : '0');
        $ttl = max(30, min(86400, (int)($_POST['devone_cache_ttl'] ?? 300)));
        set_setting('devone_cache_ttl', (string)$ttl);
        if (function_exists('devone_cache_flush')) { devone_cache_flush(); }
        $msg = 'Performance cache settings saved and cache flushed.';
    }
    if ($action === 'flush_cache') {
        $count = function_exists('devone_cache_flush') ? devone_cache_flush() : 0;
        $msg = 'DevOne cache flushed. Removed ' . (int)$count . ' cache files.';
    }
    if ($action === 'reset_opcache' && function_exists('opcache_reset')) {
        @opcache_reset();
        $msg = 'PHP OPcache reset requested.';
    }
}

$stats = function_exists('devone_cache_stats') ? devone_cache_stats() : array('enabled'=>false,'ttl'=>300,'dir'=>'storage/cache/devone','files'=>0,'bytes'=>0);
$opcacheLoaded = extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
$opcacheEnabled = false;
$opcacheMemory = '';
if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(false);
    if (is_array($status)) {
        $opcacheEnabled = !empty($status['opcache_enabled']);
        if (!empty($status['memory_usage'])) {
            $used = (int)($status['memory_usage']['used_memory'] ?? 0);
            $free = (int)($status['memory_usage']['free_memory'] ?? 0);
            $opcacheMemory = function_exists('devone_cache_human_bytes') ? devone_cache_human_bytes($used) . ' used / ' . devone_cache_human_bytes($free) . ' free' : $used . ' used';
        }
    }
}

devone_admin_header('Performance - DevOneCMS');
?>
<h1>Performance</h1>
<p class="muted">Speed up DevOneCMS by caching small repeated lookups like settings and theme/library scans. This does not cache logged-in admin pages or checkout/cart actions.</p>
<?php devone_flash($msg); ?>
<?php devone_flash($error, 'card error-card'); ?>

<div class="theme-manager-grid">
  <section class="card">
    <h3>DevOne File Cache</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_cache_settings">
      <label class="inline-check"><input type="checkbox" name="devone_cache_enabled" value="1" <?= !empty($stats['enabled']) ? 'checked' : '' ?>> Enable DevOne file cache</label>
      <label>Default cache TTL, seconds
        <input type="number" name="devone_cache_ttl" min="30" max="86400" value="<?= e($stats['ttl'] ?? 300) ?>">
      </label>
      <button>Save Performance Settings</button>
    </form>
  </section>
  <section class="card">
    <h3>Current Cache Status</h3>
    <p><strong>Status:</strong> <?= !empty($stats['enabled']) ? 'Enabled' : 'Disabled' ?></p>
    <p><strong>Files:</strong> <?= e($stats['files'] ?? 0) ?></p>
    <p><strong>Size:</strong> <?= e(function_exists('devone_cache_human_bytes') ? devone_cache_human_bytes($stats['bytes'] ?? 0) : ($stats['bytes'] ?? 0)) ?></p>
    <p><strong>Folder:</strong> <code><?= e($stats['dir'] ?? 'storage/cache/devone') ?></code></p>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="flush_cache">
      <button class="secondary">Flush DevOne Cache</button>
    </form>
  </section>
</div>

<div class="theme-manager-grid">
  <section class="card">
    <h3>PHP OPcache</h3>
    <p><strong>Extension:</strong> <?= $opcacheLoaded ? 'Installed' : 'Not detected' ?></p>
    <p><strong>Status:</strong> <?= $opcacheEnabled ? 'Enabled' : 'Disabled / unavailable to this PHP process' ?></p>
    <?php if ($opcacheMemory): ?><p><strong>Memory:</strong> <?= e($opcacheMemory) ?></p><?php endif; ?>
    <?php if (function_exists('opcache_reset')): ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_opcache">
      <button class="secondary">Reset OPcache</button>
    </form>
    <?php endif; ?>
    <p class="muted">For best production speed, enable OPcache in PHP-FPM/CloudPanel and keep file cache enabled here.</p>
  </section>
  <section class="card">
    <h3>Recommended Next Boosts</h3>
    <ul>
      <li>Enable PHP OPcache at the server level.</li>
      <li>Use Cloudflare cache rules for static assets.</li>
      <li>Keep theme/plugin images compressed.</li>
      <li>Cache public pages later, but bypass cache for admin, cart, checkout, login, and user dashboards.</li>
    </ul>
  </section>
</div>
<?php devone_admin_footer();
