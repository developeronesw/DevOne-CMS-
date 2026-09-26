<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_backups');
verify_csrf();

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$backupDir = $root . '/storage/backups';
@mkdir($backupDir, 0775, true);
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

function devone_backup_bytes($bytes) {
    $bytes = (float)$bytes;
    $units = array('B','KB','MB','GB','TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? number_format($bytes, 0) : number_format($bytes, 2)) . ' ' . $units[$i];
}

function devone_backup_slug($name) {
    $name = strtolower((string)$name);
    $name = preg_replace('/[^a-z0-9._-]+/', '-', $name);
    return trim($name, '-') ?: 'backup';
}

function devone_backup_sql_dump() {
    $pdo = db();
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    $out = "-- DevOneCMS database backup\n";
    $out .= "-- Created: " . date('c') . "\n";
    $out .= "-- Database: " . $dbName . "\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $safeTable = str_replace('`', '``', $table);
        $createRow = $pdo->query('SHOW CREATE TABLE `' . $safeTable . '`')->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? array_values($createRow)[1] ?? '';
        $out .= "DROP TABLE IF EXISTS `{$safeTable}`;\n";
        $out .= $createSql . ";\n\n";
        $rows = $pdo->query('SELECT * FROM `' . $safeTable . '`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = array_map(function($c){ return '`' . str_replace('`', '``', $c) . '`'; }, array_keys($row));
            $vals = array_map(function($v) use ($pdo){ return $v === null ? 'NULL' : $pdo->quote((string)$v); }, array_values($row));
            $out .= 'INSERT INTO `' . $safeTable . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
        }
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

function devone_zip_dos_time($time) {
    $d = getdate($time ?: time());
    $year = max(1980, (int)$d['year']);
    $dosTime = ((int)$d['hours'] << 11) | ((int)$d['minutes'] << 5) | ((int)($d['seconds'] / 2));
    $dosDate = (($year - 1980) << 9) | ((int)$d['mon'] << 5) | (int)$d['mday'];
    return array($dosTime, $dosDate);
}

function devone_zip_create_stored($zipPath, $entries) {
    $fp = @fopen($zipPath, 'wb');
    if (!$fp) { return false; }
    $central = '';
    $count = 0;
    foreach ($entries as $entry) {
        $name = str_replace('\\', '/', ltrim((string)$entry['name'], '/'));
        if ($name === '' || strpos($name, '..') !== false) { continue; }
        $data = isset($entry['data']) ? (string)$entry['data'] : (is_file($entry['path'] ?? '') ? (string)file_get_contents($entry['path']) : '');
        $mtime = isset($entry['path']) && is_file($entry['path']) ? filemtime($entry['path']) : time();
        [$dosTime, $dosDate] = devone_zip_dos_time($mtime);
        $crc = crc32($data);
        $size = strlen($data);
        $offset = ftell($fp);
        $nameLen = strlen($name);
        fwrite($fp, pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0));
        fwrite($fp, $name);
        fwrite($fp, $data);
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 32, $offset) . $name;
        $count++;
    }
    $centralOffset = ftell($fp);
    fwrite($fp, $central);
    $centralSize = strlen($central);
    fwrite($fp, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0));
    fclose($fp);
    return $count > 0;
}

function devone_backup_collect_files($root, $backupDir) {
    $entries = array();
    $root = rtrim(str_replace('\\', '/', realpath($root)), '/');
    $backupReal = rtrim(str_replace('\\', '/', realpath($backupDir)), '/');
    $skipNames = array('.git', 'node_modules');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        if (!$file->isFile()) { continue; }
        $path = str_replace('\\', '/', $file->getPathname());
        foreach ($skipNames as $skip) {
            if (strpos($path, '/' . $skip . '/') !== false) { continue 2; }
        }
        if ($backupReal && strpos($path, $backupReal . '/') === 0) { continue; }
        $rel = ltrim(substr($path, strlen($root)), '/');
        if ($rel === '') { continue; }
        $entries[] = array('name' => 'site-files/' . $rel, 'path' => $path);
    }
    return $entries;
}

function devone_backup_make($root, $backupDir) {
    $stamp = date('Ymd-His');
    $file = 'devonecms-backup-' . $stamp . '-' . bin2hex(random_bytes(4)) . '.zip';
    $zipPath = $backupDir . '/' . $file;
    $entries = array();
    $entries[] = array('name' => 'meta.json', 'data' => json_encode(array(
        'name' => 'DevOneCMS Backup',
        'created' => date('c'),
        'site_url' => defined('SITE_URL') ? SITE_URL : '',
        'db_name' => defined('DB_NAME') ? DB_NAME : '',
        'php' => PHP_VERSION,
        'os' => PHP_OS_FAMILY,
    ), JSON_PRETTY_PRINT));
    $entries[] = array('name' => 'database/devonecms.sql', 'data' => devone_backup_sql_dump());
    $entries = array_merge($entries, devone_backup_collect_files($root, $backupDir));

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { return array(false, 'Could not create ZIP archive.'); }
        foreach ($entries as $entry) {
            if (isset($entry['data'])) { $zip->addFromString($entry['name'], $entry['data']); }
            elseif (!empty($entry['path']) && is_file($entry['path'])) { $zip->addFile($entry['path'], $entry['name']); }
        }
        $zip->close();
        return array(is_file($zipPath), $file);
    }

    $ok = devone_zip_create_stored($zipPath, $entries);
    return array($ok, $ok ? $file : 'Could not create fallback ZIP archive.');
}

function devone_sql_split($sql) {
    $statements = array();
    $buffer = '';
    $quote = null;
    $escape = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $buffer .= $ch;
        if ($escape) { $escape = false; continue; }
        if ($ch === '\\') { $escape = true; continue; }
        if ($quote !== null) { if ($ch === $quote) { $quote = null; } continue; }
        if ($ch === "'" || $ch === '"') { $quote = $ch; continue; }
        if ($ch === ';') {
            $stmt = trim($buffer);
            if ($stmt !== '') { $statements[] = $stmt; }
            $buffer = '';
        }
    }
    $tail = trim($buffer);
    if ($tail !== '') { $statements[] = $tail; }
    return $statements;
}

function devone_backup_copy_dir($src, $dst, $restoreConfig = false) {
    $src = rtrim($src, DIRECTORY_SEPARATOR);
    $dst = rtrim($dst, DIRECTORY_SEPARATOR);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $target = $dst . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($src) + 1);
        if (!$restoreConfig && basename($target) === 'config.php') { continue; }
        if ($item->isDir()) { if (!is_dir($target)) { @mkdir($target, 0775, true); } }
        else { $parent = dirname($target); if (!is_dir($parent)) { @mkdir($parent, 0775, true); } @copy($item->getPathname(), $target); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_backup') {
    [$ok, $result] = devone_backup_make($root, $backupDir);
    if (function_exists('devone_log')) { devone_log('backup_create', $ok ? 'Backup created: ' . $result : 'Backup failed: ' . $result); }
    header('Location: backups.php?' . ($ok ? 'msg=' : 'error=') . rawurlencode($ok ? 'Backup created: ' . $result : $result));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_backup') {
    $file = devone_backup_slug($_POST['file'] ?? '');
    $path = $backupDir . '/' . $file;
    if (is_file($path) && preg_match('/\.zip$/i', $file)) { @unlink($path); $msg = 'Backup deleted.'; }
    else { $error = 'Backup not found.'; }
    header('Location: backups.php?' . ($error ? 'error=' . rawurlencode($error) : 'msg=' . rawurlencode($msg)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_backup') {
    $tmp = '';
    if (!empty($_FILES['restore_zip']['tmp_name']) && is_uploaded_file($_FILES['restore_zip']['tmp_name'])) {
        $tmp = $_FILES['restore_zip']['tmp_name'];
    } elseif (!empty($_POST['existing_file'])) {
        $candidate = $backupDir . '/' . devone_backup_slug($_POST['existing_file']);
        if (is_file($candidate)) { $tmp = $candidate; }
    }
    if ($tmp === '') {
        header('Location: backups.php?error=' . rawurlencode('Choose a backup ZIP to restore.'));
        exit;
    }
    $extractTo = $backupDir . '/restore-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    @mkdir($extractTo, 0775, true);
    $backupZipLimits = array(
        'max_archive_bytes' => 2147483648,
        'max_files' => 50000,
        'max_uncompressed_bytes' => 8589934592,
        'max_single_file_bytes' => 2147483648,
        'max_ratio' => 250.0,
    );
    $extractError = '';
    $okExtract = function_exists('safe_zip_extract_with_limits')
        ? safe_zip_extract_with_limits($tmp, $extractTo, $backupZipLimits, $extractError)
        : (function_exists('safe_zip_extract_with_error') ? safe_zip_extract_with_error($tmp, $extractTo, $extractError) : false);
    if (!$okExtract) {
        header('Location: backups.php?error=' . rawurlencode($extractError !== '' ? $extractError : 'Could not extract backup ZIP.'));
        exit;
    }
    $restoreFiles = !empty($_POST['restore_files']);
    $restoreDb = !empty($_POST['restore_db']);
    $restoreConfig = !empty($_POST['restore_config']);
    $restored = array();
    try {
        if ($restoreDb && is_file($extractTo . '/database/devonecms.sql')) {
            $sql = file_get_contents($extractTo . '/database/devonecms.sql');
            foreach (devone_sql_split($sql) as $stmt) { if (trim($stmt) !== '') { db()->exec($stmt); } }
            $restored[] = 'database';
        }
        if ($restoreFiles && is_dir($extractTo . '/site-files')) {
            devone_backup_copy_dir($extractTo . '/site-files', $root, $restoreConfig);
            $restored[] = 'files';
        }
        if (function_exists('devone_log')) { devone_log('backup_restore', 'Restored: ' . implode(', ', $restored)); }
        header('Location: backups.php?msg=' . rawurlencode('Restore complete: ' . (implode(', ', $restored) ?: 'nothing selected')));
        exit;
    } catch (Throwable $e) {
        header('Location: backups.php?error=' . rawurlencode('Restore failed: ' . $e->getMessage()));
        exit;
    }
}

if (isset($_GET['download'])) {
    $file = devone_backup_slug($_GET['download']);
    $path = $backupDir . '/' . $file;
    if (is_file($path) && preg_match('/\.zip$/i', $file)) {
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($path);
        exit;
    }
    http_response_code(404);
    die('Backup not found.');
}

$backups = glob($backupDir . '/*.zip') ?: array();
usort($backups, function($a, $b){ return filemtime($b) <=> filemtime($a); });

devone_admin_header('Backup & Restore - DevOneCMS');
?>
<section class="admin-dashboard-hero backup-hero">
  <div>
    <p class="admin-kicker"><span></span> DevOneCMS Protection</p>
    <h1>Backup & Restore</h1>
    <p class="admin-hero-copy">Download a portable archive of your site files plus a SQL dump of the full database. Restore from local ZIP now; backups are stored outside the public content tree and can be downloaded by authorized administrators.</p>
  </div>
  <div class="admin-hero-actions"><a class="btn secondary" href="dashboard.php">Back to Dashboard</a></div>
</section>
<?php if ($msg): ?><p class="card"><?= e($msg) ?></p><?php endif; ?>
<?php if ($error): ?><p class="card error-card"><?= e($error) ?></p><?php endif; ?>

<section class="dashboard-two-col">
  <div class="card backup-create-card">
    <div class="section-head"><h2>Create Backup</h2><span>Files + DB</span></div>
    <p class="muted">Creates a ZIP containing <code>site-files/</code>, <code>database/devonecms.sql</code>, and <code>meta.json</code>. The backup excludes the protected backup directory to prevent recursive archives.</p>
    <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="create_backup"><button>Create Full Backup</button></form>
  </div>
</section>

<section class="card restore-card">
  <div class="section-head"><h2>Restore Backup</h2><span>Use carefully</span></div>
  <form method="post" enctype="multipart/form-data" class="restore-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="restore_backup">
    <label>Upload DevOneCMS backup ZIP</label>
    <input type="file" name="restore_zip" accept=".zip">
    <label>Or restore an existing backup</label>
    <select name="existing_file"><option value="">Choose existing backup...</option><?php foreach($backups as $b): ?><option value="<?= e(basename($b)) ?>"><?= e(basename($b)) ?></option><?php endforeach; ?></select>
    <div class="backup-checkboxes">
      <label><input type="checkbox" name="restore_files" value="1" checked> Restore files</label>
      <label><input type="checkbox" name="restore_db" value="1"> Restore database</label>
      <label><input type="checkbox" name="restore_config" value="1"> Include config.php overwrite</label>
    </div>
    <button class="danger" onclick="return confirm('Restore can overwrite files and database tables. Continue?')">Restore Selected Backup</button>
  </form>
</section>

<section class="card">
  <div class="section-head"><h2>Local Backups</h2><span><?= count($backups) ?> archive<?= count($backups) === 1 ? '' : 's' ?></span></div>
  <table class="table">
    <tr><th>Backup</th><th>Size</th><th>Created</th><th>Actions</th></tr>
    <?php if (!$backups): ?><tr><td colspan="4">No backups created yet.</td></tr><?php endif; ?>
    <?php foreach($backups as $backup): $file = basename($backup); ?>
      <tr>
        <td><strong><?= e($file) ?></strong></td>
        <td><?= e(devone_backup_bytes(filesize($backup))) ?></td>
        <td><?= e(date('M j, Y g:i A', filemtime($backup))) ?></td>
        <td class="inline-actions">
          <a class="btn secondary" href="backups.php?download=<?= rawurlencode($file) ?>">Download</a>
          <form method="post" onsubmit="return confirm('Delete this backup?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="file" value="<?= e($file) ?>"><button class="danger">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>
<?php devone_admin_footer();
