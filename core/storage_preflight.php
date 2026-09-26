<?php
/**
 * DevOne CMS Storage Preflight
 *
 * This file intentionally does NOT try to chown root-owned folders because PHP usually cannot
 * and should not do that. It creates required folders, chmods when PHP has permission, detects
 * owner/PHP-user mismatch, and returns exact SSH instructions when ownership is wrong.
 */

if (!defined('DEVONE_ROOT')) {
    define('DEVONE_ROOT', dirname(__DIR__));
}

function devone_storage_required_paths() {
    return array(
        'storage',
        'storage/cache',
        'storage/cache/store',
        'storage/logs',
        'content',
        'content/plugins',
        'content/themes',
        'content/libraries',
        'content/uploads',
    );
}

function devone_storage_php_user_label() {
    $labels = array();
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(@posix_geteuid());
        if (is_array($pw) && !empty($pw['name'])) { $labels[] = $pw['name']; }
    }
    $current = @get_current_user();
    if ($current) { $labels[] = 'file-owner-context:' . $current; }
    return implode(' / ', array_unique($labels));
}

function devone_storage_owner_label($path) {
    if (!file_exists($path)) { return 'missing'; }
    $uid = @fileowner($path);
    $gid = @filegroup($path);
    $user = $uid;
    $group = $gid;
    if (function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid($uid);
        if (is_array($pw) && isset($pw['name'])) { $user = $pw['name']; }
    }
    if (function_exists('posix_getgrgid')) {
        $gr = @posix_getgrgid($gid);
        if (is_array($gr) && isset($gr['name'])) { $group = $gr['name']; }
    }
    return $user . ':' . $group;
}

function devone_storage_path_status($relative, $autoFix = false) {
    $path = rtrim(DEVONE_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
    $created = false;
    $chmod = false;
    $writeOk = false;
    $writeError = '';
    $existsBefore = file_exists($path);

    if (!$existsBefore && $autoFix) {
        $created = @mkdir($path, 0775, true);
        if ($created) { @chmod($path, 0775); }
    }

    if (is_dir($path) && $autoFix) {
        $chmod = @chmod($path, 0775);
    }

    if (is_dir($path) && is_writable($path)) {
        $testFile = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.devone-write-test-' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($testFile, 'devone') !== false) {
            $writeOk = true;
            @unlink($testFile);
        } else {
            $writeError = 'file_put_contents failed';
        }
    }

    return array(
        'relative' => $relative,
        'path' => $path,
        'exists' => is_dir($path),
        'writable' => is_dir($path) && is_writable($path),
        'write_ok' => $writeOk,
        'perms' => file_exists($path) ? substr(sprintf('%o', @fileperms($path)), -4) : 'missing',
        'owner' => devone_storage_owner_label($path),
        'created' => $created,
        'chmod' => $chmod,
        'error' => $writeError,
    );
}

function devone_storage_preflight($autoFix = true) {
    $rows = array();
    $ok = true;
    foreach (devone_storage_required_paths() as $rel) {
        $row = devone_storage_path_status($rel, $autoFix);
        if (!$row['exists'] || !$row['writable'] || !$row['write_ok']) { $ok = false; }
        $rows[] = $row;
    }

    $root = rtrim(DEVONE_ROOT, DIRECTORY_SEPARATOR);
    $siteUser = 'SITEUSER';
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw=@posix_getpwuid(@posix_geteuid());
        if(is_array($pw)&&!empty($pw['name'])&&$pw['name']!=='root'){$siteUser=(string)$pw['name'];}
    }
    if($siteUser==='SITEUSER' && file_exists($root) && function_exists('posix_getpwuid')){
        $pw=@posix_getpwuid(@fileowner($root));if(is_array($pw)&&!empty($pw['name'])&&$pw['name']!=='root'){$siteUser=(string)$pw['name'];}
    }

    $commands = array(
        'cd ' . escapeshellarg($root),
        'mkdir -p storage/cache/store storage/logs content/plugins content/themes content/libraries content/uploads',
        'chown -R ' . $siteUser . ':' . $siteUser . ' storage content',
        'find storage content -type d -exec chmod 775 {} \\;',
        'find storage content -type f -exec chmod 664 {} \\;',
    );

    return array(
        'ok' => $ok,
        'rows' => $rows,
        'php_user' => devone_storage_php_user_label(),
        'root' => $root,
        'suggested_site_user' => $siteUser,
        'ssh_commands' => implode("\n", $commands),
        'message' => $ok
            ? 'Storage folders are writable.'
            : 'Some storage/content folders are not writable by PHP. This is usually Linux ownership, not chmod.',
    );
}

function devone_storage_preflight_notice_html($autoFix = true) {
    $report = devone_storage_preflight($autoFix);
    if ($report['ok']) { return ''; }

    ob_start();
    ?>
    <div class="devone-alert devone-alert-danger" style="margin:16px 0;padding:16px;border:1px solid #ef4444;border-radius:14px;background:rgba(239,68,68,.10);">
        <strong>Storage ownership needs attention.</strong>
        <p>DevOne tried to create and chmod the required folders, but PHP still cannot write to one or more locations. This usually happens when the folders are owned by <code>root:root</code> or a different server user.</p>
        <p><strong>PHP user:</strong> <code><?php echo htmlspecialchars($report['php_user'] ?: 'unknown', ENT_QUOTES, 'UTF-8'); ?></code></p>
        <details open>
            <summary>Show failing folders and SSH fix</summary>
            <div style="overflow:auto;margin-top:10px;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid rgba(255,255,255,.2);">Folder</th><th style="text-align:left;padding:6px;border-bottom:1px solid rgba(255,255,255,.2);">Writable</th><th style="text-align:left;padding:6px;border-bottom:1px solid rgba(255,255,255,.2);">Write Test</th><th style="text-align:left;padding:6px;border-bottom:1px solid rgba(255,255,255,.2);">Perms</th><th style="text-align:left;padding:6px;border-bottom:1px solid rgba(255,255,255,.2);">Owner</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['rows'] as $row): if ($row['writable'] && $row['write_ok']) { continue; } ?>
                        <tr>
                            <td style="padding:6px;border-bottom:1px solid rgba(255,255,255,.12);"><code><?php echo htmlspecialchars($row['relative'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td style="padding:6px;border-bottom:1px solid rgba(255,255,255,.12);"><?php echo $row['writable'] ? 'PASS' : 'FAIL'; ?></td>
                            <td style="padding:6px;border-bottom:1px solid rgba(255,255,255,.12);"><?php echo $row['write_ok'] ? 'PASS' : 'FAIL'; ?></td>
                            <td style="padding:6px;border-bottom:1px solid rgba(255,255,255,.12);"><?php echo htmlspecialchars($row['perms'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:6px;border-bottom:1px solid rgba(255,255,255,.12);"><?php echo htmlspecialchars($row['owner'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p>Run this once as root or from your hosting terminal:</p>
            <pre style="white-space:pre-wrap;background:rgba(0,0,0,.35);padding:12px;border-radius:10px;"><code><?php echo htmlspecialchars($report['ssh_commands'], ENT_QUOTES, 'UTF-8'); ?></code></pre>
        </details>
    </div>
    <?php
    return ob_get_clean();
}
