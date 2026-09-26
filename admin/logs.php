<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();
devone_require_permission('view_logs');

$logs = array();
$error = '';
$totalLogs = 0;
$perPage = 25;
$page = max(1, (int)($_GET['p'] ?? 1));
$totalPages = 1;
$offset = 0;
try {
    $tbl = devone_require_table('activity_logs', true);
    if ($tbl !== '') {
        $totalLogs = (int)db()->query('SELECT COUNT(*) FROM `' . $tbl . '`')->fetchColumn();
        $totalPages = max(1, (int)ceil($totalLogs / $perPage));
        if ($page > $totalPages) { $page = $totalPages; }
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` ORDER BY id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
        $stmt->execute();
        $logs = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$showingStart = $totalLogs > 0 ? ($offset + 1) : 0;
$showingEnd = min($offset + count($logs), $totalLogs);

function devone_logs_page_url($p) {
    return 'logs.php?p=' . max(1, (int)$p);
}

devone_admin_header('System Logs - DevOneCMS');
?>
<section class="logs-hero dashboard-hero">
  <div>
    <p class="admin-kicker"><span></span> System Activity</p>
    <h1>System Logs</h1>
    <p class="muted">Review admin, user, media, marketplace, plugin, and system actions. Logs are paginated to keep this screen lightweight.</p>
  </div>
  <div class="admin-hero-actions">
    <a class="btn secondary" href="dashboard.php">Dashboard</a>
    <a class="btn secondary" href="settings.php">Settings</a>
  </div>
</section>

<?php devone_flash($error, 'card error-card'); ?>

<section class="logs-summary-grid">
  <div class="dashboard-stat-card">
    <div class="stat-icon">☷</div>
    <b><?= number_format($totalLogs) ?></b>
    <span>Total Entries</span>
  </div>
  <div class="dashboard-stat-card">
    <div class="stat-icon">#</div>
    <b><?= number_format($page) ?> / <?= number_format($totalPages) ?></b>
    <span>Current Page</span>
  </div>
  <div class="dashboard-stat-card">
    <div class="stat-icon">⏱</div>
    <b><?= number_format($perPage) ?></b>
    <span>Rows Per Page</span>
  </div>
</section>

<section class="card logs-table-card">
  <div class="section-head">
    <h2>Recent Activity</h2>
    <span><?= $totalLogs ? ('Showing ' . number_format($showingStart) . '-' . number_format($showingEnd) . ' of ' . number_format($totalLogs)) : '0 rows' ?></span>
  </div>

  <?php if (!$logs): ?>
    <div class="logs-empty-state">
      <strong>No logs yet.</strong>
      <p class="muted">Actions will appear here as users save pages, upload media, install plugins, update settings, and use DevOneCMS features.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table logs-table">
        <thead>
          <tr><th>Date</th><th>User</th><th>Action</th><th>Details</th></tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><?= e($l['created_at'] ?? '') ?></td>
            <td><?= !empty($l['user_id']) ? 'User #' . e($l['user_id']) : '<span class="muted">System</span>' ?></td>
            <td><span class="log-action-badge"><?= e($l['action'] ?? '') ?></span></td>
            <td><?= e($l['details'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <nav class="devone-pagination logs-pagination" aria-label="System logs pagination">
      <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? e(devone_logs_page_url($page - 1)) : '#' ?>">Previous</a>
      <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        if ($start > 1) { echo '<a class="page-link" href="' . e(devone_logs_page_url(1)) . '">1</a><span class="page-dots">…</span>'; }
        for ($i = $start; $i <= $end; $i++):
      ?>
        <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="<?= e(devone_logs_page_url($i)) ?>"><?= number_format($i) ?></a>
      <?php endfor;
        if ($end < $totalPages) { echo '<span class="page-dots">…</span><a class="page-link" href="' . e(devone_logs_page_url($totalPages)) . '">' . number_format($totalPages) . '</a>'; }
      ?>
      <a class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page < $totalPages ? e(devone_logs_page_url($page + 1)) : '#' ?>">Next</a>
    </nav>
  <?php endif; ?>
</section>
<?php devone_admin_footer();
