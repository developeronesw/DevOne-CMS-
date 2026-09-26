<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_settings');
if (function_exists('devone_network_is_super_admin') && !devone_network_is_super_admin()) { http_response_code(403); exit('SuperAdmin access required.'); }
if (is_file(__DIR__ . '/../core/core-updates.php')) { require_once __DIR__ . '/../core/core-updates.php'; }
$message=''; $error='';
$release = devone_core_update_check(isset($_GET['refresh']));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $release=devone_core_update_check(true);
        if (empty($release['update_available'])) { throw new RuntimeException('This DevOne installation is already current.'); }
        $zip=devone_core_update_download($release);
        $report=devone_core_update_install($zip,$release);
        $installedVersion = (string)($report['version'] ?: $release['latest_version']);
        $message='DevOne was updated successfully to '.$installedVersion.'.';

        // The current PHP request still has the pre-update version function in
        // memory. Do not query and cache Marketplace metadata with that stale
        // identity. Seed a current response; the next request performs a fresh check.
        @unlink(devone_core_update_storage('latest.json'));
        clearstatcache(true, devone_core_update_storage('latest.json'));
        if (function_exists('opcache_invalidate') && is_file(__DIR__ . '/../core/version.php')) {
            @opcache_invalidate(__DIR__ . '/../core/version.php', true);
        }
        $release = array(
            'installed_version' => $installedVersion,
            'latest_version' => $installedVersion,
            'update_available' => false,
            'checked_at' => gmdate('c'),
        );
    } catch (Throwable $e) { $error=$e->getMessage(); }
}
devone_admin_header('Latest DevOne Core Update');
$installed=function_exists('devone_core_version')?devone_core_version():(defined('CMS_VERSION')?CMS_VERSION:'Unknown');
$latest=(string)($release['latest_version'] ?? $installed);
$changes=(array)($release['changelog'] ?? $release['changes'] ?? array());
?>
<h1>Latest Core Update</h1>
<p>Official Stable DevOne Core releases are delivered securely by <strong>One Marketplace</strong>.</p>
<?php devone_flash($message,'card success-card'); ?>
<?php if ($error !== ''): ?><div class="card error-card" style="white-space:pre-wrap"><?=e($error)?></div><?php endif; ?>
<?php if (!empty($release['error'])): ?><div class="card error-card"><strong>Update check unavailable.</strong><p><?=e($release['error'])?></p><a class="btn" href="core-updates.php?refresh=1">Try again</a></div>
<?php elseif (empty($release['update_available'])): ?>
<div class="card devone-latest-card"><h2>DevOne installation is the latest and the greatest.</h2><p>Installed version: <strong><?=e($installed)?></strong> · Stable channel</p><a class="btn" href="core-updates.php?refresh=1">Check again</a></div>
<?php else: ?>
<div class="card devone-latest-card">
<h2><?=e($release['title'] ?? ('DevOne '.$latest))?></h2>
<p><?=e($release['summary'] ?? 'A new official Stable update is ready to install.')?></p>
<table class="widefat"><tbody>
<tr><th>Installed version</th><td><?=e($installed)?></td></tr><tr><th>Latest version</th><td><?=e($latest)?></td></tr>
<tr><th>Release channel</th><td>Stable</td></tr><tr><th>Release date</th><td><?=e($release['release_date'] ?? '—')?></td></tr>
<tr><th>Package checksum</th><td><code><?=e($release['sha256'] ?? $release['package_sha256'] ?? 'Verified during download')?></code></td></tr>
</tbody></table>
<?php if($changes): ?><h3>What is included</h3><ul><?php foreach($changes as $change): ?><li><?=e(is_array($change)?($change['text']??json_encode($change)):$change)?></li><?php endforeach;?></ul><?php endif;?>
<form method="post" onsubmit="return confirm('Create a restore point and install DevOne <?=e($latest)?> now?');"><?=csrf_field()?><button class="btn" type="submit">Back Up and Install</button> <a class="btn secondary" href="core-updates.php?refresh=1">Refresh details</a></form>
<p class="muted">DevOne runs a complete permission preflight, stages and verifies the package, backs up affected files, applies only declared paths, writes a diagnostic log, and restores the backup if installation fails.</p>
</div>
<?php endif; ?>
<?php devone_admin_footer();
