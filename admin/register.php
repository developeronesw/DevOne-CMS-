<?php
require '../config.php';
require '../core/db.php';
require '../core/schema.php';
require '../core/functions.php';
if (is_file('../core/license.php')) { require_once '../core/license.php'; }
if (is_file('../core/network.php')) { require_once '../core/network.php'; }
require '../core/security.php';
if (is_file('../core/mailer.php')) { require '../core/mailer.php'; }

devone_start_session();
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
verify_csrf();

function devone_public_registration_enabled() {
    return get_setting('allow_public_registration', '0') === '1';
}

function devone_ensure_role_exists($roleName) {
    $roleName = devone_slugify($roleName ?: 'subscriber', 'subscriber');
    $tbl = devone_require_table('roles', true);
    if ($tbl === '') { return false; }
    $cols = function_exists('devone_table_columns') ? devone_table_columns('roles') : array();
    try {
        $check = db()->prepare('SELECT id FROM `' . $tbl . '` WHERE name=? LIMIT 1');
        $check->execute(array($roleName));
        if ($check->fetchColumn()) { return true; } // Legacy helper preserved; never rewrites an existing role.
        $description = 'Registered user role. No privileged permissions are granted by default.';
        $permissions = json_encode(array(), JSON_UNESCAPED_SLASHES);
        if (in_array('description', $cols, true)) {
            $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (name,description,permissions) VALUES (?,?,?)');
            return $stmt->execute(array($roleName, $description, $permissions));
        }
        $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (name,permissions) VALUES (?,?)');
        return $stmt->execute(array($roleName, $permissions));
    } catch (Exception $e) { return false; }
}

$siteName = function_exists('get_setting') ? get_setting('site_name', 'DevOneCMS') : 'DevOneCMS';
$siteLogo = function_exists('devone_site_logo_url') ? devone_site_logo_url() : '';
$error = '';
$success = '';

if (!devone_public_registration_enabled()) {
    $error = 'Registration is currently closed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && devone_public_registration_enabled()) {
    $username = devone_slugify($_POST['username'] ?? '', '');
    $email = trim((string)($_POST['email'] ?? ''));
    $display = trim((string)($_POST['display_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($username === '' || strlen($username) < 3) { $error = 'Username must be at least 3 characters.'; }
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Enter a valid email address.'; }
    elseif (strlen($password) < 8) { $error = 'Password must be at least 8 characters.'; }
    elseif ($password !== $confirm) { $error = 'Passwords do not match.'; }
    else {
        try {
            $usersTable = devone_require_table('users', true);
            if ($usersTable === '') { throw new Exception('Users table could not be resolved.'); }
            $cols = function_exists('devone_table_columns') ? devone_table_columns('users') : array();
            $exists = db()->prepare('SELECT id FROM `' . $usersTable . '` WHERE username=? LIMIT 1');
            $exists->execute(array($username));
            if ($exists->fetchColumn()) { throw new Exception('That username is already taken.'); }
            if ($email !== '') {
                $exists = db()->prepare('SELECT id FROM `' . $usersTable . '` WHERE email=? LIMIT 1');
                $exists->execute(array($email));
                if ($exists->fetchColumn()) { throw new Exception('That email address is already registered.'); }
            }

            $role = devone_slugify(get_setting('default_user_role', 'subscriber'), 'subscriber');
            devone_ensure_role_exists($role);
            $role = function_exists('devone_safe_public_registration_role') ? devone_safe_public_registration_role($role) : $role;
            if ($role === '') { throw new Exception('Public registration is not configured with a safe zero-permission role. Ask a site administrator to choose a safe registration role.'); }

            $fields = array('username','password','email','role');
            $values = array($username, password_hash($password, PASSWORD_DEFAULT), $email, $role);
            if (in_array('display_name', $cols, true)) { $fields[] = 'display_name'; $values[] = ($display !== '' ? $display : $username); }
            if (in_array('status', $cols, true)) { $fields[] = 'status'; $values[] = 'active'; }
            if (in_array('permissions_override', $cols, true)) { $fields[] = 'permissions_override'; $values[] = json_encode(array(), JSON_UNESCAPED_SLASHES); }

            $sql = 'INSERT INTO `' . $usersTable . '` (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
            db()->prepare($sql)->execute($values);
            $newId = (int)db()->lastInsertId();
            if (function_exists('devone_log')) { devone_log('user_registered', 'New public registration ID ' . $newId . ' as ' . $role); }
            if ($email !== '' && function_exists('devone_send_email')) {
                $displayName = $display !== '' ? $display : $username;
                $loginUrl = function_exists('devone_site_url') ? devone_site_url('admin/index.php') : 'admin/index.php';
                devone_send_email(
                    $email,
                    'Welcome to ' . $siteName,
                    '<p>Welcome <strong>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>' .
                    '<p>Your account was created successfully with the <strong>' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . '</strong> role.</p>' .
                    '<p><strong>Login information</strong></p>' .
                    '<p>Username: <strong>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong></p>' .
                    '<p>You can sign in using the password you chose during registration:</p>' .
                    '<p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#28b8ff;">Login to your account</a></p>' .
                    '<p style="color:#99a8e8;font-size:13px;">For security, you may change your password after logging in.</p>',
                    "Welcome to " . $siteName . "

Username: " . $username . "
Use the password you chose during registration.
Login: " . $loginUrl
                );
            }
            $success = 'Registration complete. Your account was created as ' . $role . '. You can sign in now.';
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}
?><!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Register - <?= e($siteName) ?></title>
  <link rel="stylesheet" href="../assets/css/devone.css">
  <link rel="stylesheet" href="../assets/css/devone-admin.css">
</head>
<body class="devone-admin devone-auth-page devone-register-page">
  <main class="devone-auth-shell">
    <section class="card devone-auth-card">
      <div class="devone-auth-brand">
        <?php if ($siteLogo): ?><img src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?> logo"><?php else: ?><span class="logo">&lt;/&gt;</span><?php endif; ?>
        <div><h1>Create Account</h1><p class="muted">Register for <?= e($siteName) ?></p></div>
      </div>
      <?php if ($error): ?><p class="card error-card"><?= e($error) ?></p><?php endif; ?>
      <?php if ($success): ?><p class="card success-card"><?= e($success) ?></p><?php endif; ?>
      <?php if (!$success && devone_public_registration_enabled()): ?>
      <form method="post" class="devone-auth-form">
        <?= csrf_field() ?>
        <label>Username<input name="username" value="<?= e($_POST['username'] ?? '') ?>" required></label>
        <label>Email<input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Display Name<input name="display_name" value="<?= e($_POST['display_name'] ?? '') ?>"></label>
        <label>Password<input type="password" name="password" required></label>
        <label>Confirm Password<input type="password" name="confirm_password" required></label>
        <button>Create Account</button>
      </form>
      <?php endif; ?>
      <p class="devone-auth-links"><a href="index.php">Back to Login</a></p>
    </section>
  </main>
</body>
</html>
