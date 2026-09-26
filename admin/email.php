<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_settings');
verify_csrf();

$msg = '';
$error = '';
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mailMethod = $_POST['mail_method'] ?? 'php_mail';
    if (!in_array($mailMethod, array('php_mail','smtp'), true)) { $mailMethod = 'php_mail'; }

    $fromName = trim((string)($_POST['mail_from_name'] ?? get_setting('site_name', 'DevOneCMS')));
    if ($fromName === '') { $fromName = get_setting('site_name', 'DevOneCMS'); }

    $fromEmail = trim((string)($_POST['mail_from_email'] ?? ''));
    $smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
    $smtpPort = trim((string)($_POST['smtp_port'] ?? '587'));
    $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';
    if (!in_array($smtpEncryption, array('none','tls','ssl'), true)) { $smtpEncryption = 'tls'; }
    $smtpUsername = trim((string)($_POST['smtp_username'] ?? ''));

    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'From Email must be a valid email address.';
    } elseif ($mailMethod === 'smtp' && $smtpHost === '') {
        $error = 'SMTP Host is required when Mail Method is set to SMTP.';
    } else {
        set_setting('mail_method', $mailMethod);
        set_setting('mail_from_name', $fromName);
        set_setting('mail_from_email', $fromEmail);
        set_setting('smtp_host', $smtpHost);
        set_setting('smtp_port', $smtpPort !== '' ? $smtpPort : '587');
        set_setting('smtp_encryption', $smtpEncryption);
        set_setting('smtp_username', $smtpUsername);

        if (!empty($_POST['clear_smtp_password'])) {
            set_setting('smtp_password', '');
        } elseif (array_key_exists('smtp_password', $_POST) && trim((string)$_POST['smtp_password']) !== '') {
            set_setting('smtp_password', (string)$_POST['smtp_password']);
        }

        if (function_exists('devone_log')) { devone_log('smtp_settings_saved', 'Core email transport settings updated.'); }
        $msg = 'SMTP settings saved.';

        if (isset($_POST['send_test'])) {
            $testTo = trim((string)($_POST['test_email'] ?? ''));
            if ($testTo === '') {
                $currentUser = function_exists('devone_current_user') ? devone_current_user() : null;
                $testTo = trim((string)($currentUser['email'] ?? ''));
            }
            if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                $error = 'Settings were saved, but a valid Test Email recipient is required.';
            } elseif (function_exists('devone_send_email')) {
                $siteName = get_setting('site_name', 'DevOneCMS');
                $testResult = devone_send_email(
                    $testTo,
                    'DevOne SMTP Test - ' . $siteName,
                    '<p>This is a test email from <strong>' . e($siteName) . '</strong>.</p><p>If you received this, DevOne Core email is working through the selected mail method.</p>',
                    "This is a test email from " . $siteName . ".\nIf you received this, DevOne Core email is working."
                );
                if (!empty($testResult['ok'])) {
                    $msg = 'SMTP settings saved. Test email sent to ' . $testTo . '.';
                } else {
                    $error = 'Settings were saved, but the test email failed: ' . ($testResult['message'] ?? 'Unknown mail error.');
                }
            } else {
                $error = 'Settings were saved, but the DevOne Core mailer is not loaded.';
            }
        }
    }
}

$diag = function_exists('devone_mail_diagnostics') ? devone_mail_diagnostics() : array();
$currentUser = function_exists('devone_current_user') ? devone_current_user() : null;
$defaultTestEmail = trim((string)($currentUser['email'] ?? get_setting('mail_from_email', '')));

devone_admin_header('SMTP Settings - DevOneCMS');
?>
<h1>SMTP Settings</h1>
<p class="muted">Configure DevOne Core email delivery. Installation emails, user registration emails, admin-created user emails, commerce receipts, and future license emails all route through <code>devone_send_email()</code>.</p>
<?php devone_flash($msg); ?>
<?php if ($error): ?><p class="card error-card"><?= e($error) ?></p><?php endif; ?>

<div class="settings-grid">
    <section class="card smtp-card">
        <h2>Core Email Transport</h2>
        <form method="post">
            <?= csrf_field() ?>
            <label>Mail Method
                <select name="mail_method">
                    <option value="php_mail" <?= get_setting('mail_method', 'php_mail') !== 'smtp' ? 'selected' : '' ?>>PHP mail() / Server Mail</option>
                    <option value="smtp" <?= get_setting('mail_method', 'php_mail') === 'smtp' ? 'selected' : '' ?>>SMTP</option>
                </select>
            </label>
            <label>From Name
                <input name="mail_from_name" value="<?= e(get_setting('mail_from_name', get_setting('site_name', 'DevOneCMS'))) ?>" placeholder="<?= e(get_setting('site_name', 'DevOneCMS')) ?>">
            </label>
            <label>From Email
                <input type="email" name="mail_from_email" value="<?= e(get_setting('mail_from_email', '')) ?>" placeholder="noreply@example.com">
            </label>
            <div class="editor-row">
                <label>SMTP Host
                    <input name="smtp_host" value="<?= e(get_setting('smtp_host', '')) ?>" placeholder="smtp.example.com">
                </label>
                <label>SMTP Port
                    <input name="smtp_port" value="<?= e(get_setting('smtp_port', '587')) ?>" placeholder="587">
                </label>
            </div>
            <label>SMTP Encryption
                <select name="smtp_encryption">
                    <option value="tls" <?= get_setting('smtp_encryption', 'tls') === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS</option>
                    <option value="ssl" <?= get_setting('smtp_encryption', 'tls') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="none" <?= get_setting('smtp_encryption', 'tls') === 'none' ? 'selected' : '' ?>>None</option>
                </select>
            </label>
            <label>SMTP Username
                <input name="smtp_username" value="<?= e(get_setting('smtp_username', '')) ?>" placeholder="SMTP username or email">
            </label>
            <label>SMTP Password
                <input type="password" name="smtp_password" value="" placeholder="Leave blank to keep current SMTP password">
            </label>
            <label class="inline-check"><input type="checkbox" name="clear_smtp_password" value="1"> Clear saved SMTP password</label>
            <hr>
            <label>Send Test Email To
                <input type="email" name="test_email" value="<?= e($defaultTestEmail) ?>" placeholder="you@example.com">
            </label>
            <div class="settings-actions">
                <button name="save_only" value="1">Save SMTP Settings</button>
                <button name="send_test" value="1" class="btn">Save & Send Test</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Mail Diagnostics</h2>
        <p class="muted">This confirms whether DevOne Core can load its official PHPMailer mail layer and whether the server has the pieces needed for PHP mail or SMTP.</p>
        <div class="mini-summary" style="text-align:left">
            <div><small>PHPMailer Loaded</small><strong><?= !empty($diag['phpmailer_loaded']) ? 'Yes' : 'No' ?></strong></div>
            <div><small>PHPMailer Version</small><strong><?= e((string)(($diag['phpmailer_version'] ?? '') !== '' ? $diag['phpmailer_version'] : 'Unknown')) ?></strong></div>
            <div><small>Mail Method</small><strong><?= e((string)($diag['mail_method'] ?? 'php_mail')) ?></strong></div>
            <div><small>PHP mail()</small><strong><?= !empty($diag['php_mail_function']) ? 'Available' : 'Unavailable' ?></strong></div>
            <div><small>OpenSSL</small><strong><?= !empty($diag['openssl_loaded']) ? 'Loaded' : 'Missing' ?></strong></div>
            <div><small>SMTP Host</small><strong><?= e((string)(($diag['smtp_host'] ?? '') !== '' ? $diag['smtp_host'] : 'Not set')) ?></strong></div>
            <div><small>SMTP Login</small><strong><?= !empty($diag['smtp_username_set']) ? 'Username set' : 'No username' ?></strong></div>
            <div><small>SMTP Password</small><strong><?= !empty($diag['smtp_password_set']) ? 'Saved' : 'Not saved' ?></strong></div>
            <div><small>From Email</small><strong><?= e((string)($diag['from_email'] ?? '')) ?></strong></div>
        </div>
        <?php if (!empty($diag['phpmailer_source'])): ?>
            <p class="muted"><strong>Mailer source:</strong><br><code><?= e((string)$diag['phpmailer_source']) ?></code></p>
        <?php endif; ?>
        <p class="muted">For live VPS installs, SMTP is recommended. PHP mail may return success even when the server never delivers the message.</p>
    </section>
</div>
<?php devone_admin_footer();
