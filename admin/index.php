<?php
if (!is_file(__DIR__ . '/../config.php')) { header('Location: ../install.php'); exit; }
require '../config.php';
if (!defined('DEVONE_INSTALLED') || DEVONE_INSTALLED !== true) { header('Location: ../install.php'); exit; }
require '../core/db.php';
require '../core/schema.php';
require '../core/functions.php';
if (is_file('../core/license.php')) { require_once '../core/license.php'; }
if (is_file('../core/network.php')) { require_once '../core/network.php'; }
require '../core/security.php';
devone_start_session();
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
$error='';
$msg = $_GET['msg'] ?? '';
$siteName = function_exists('get_setting') ? get_setting('site_name', 'DevOneCMS') : 'DevOneCMS';
$siteLogo = function_exists('devone_site_logo_url') ? devone_site_logo_url() : '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $u=trim((string)($_POST['username']??'admin'));
    $p=(string)($_POST['password']??'');
    $rateIdentity='login|' . (string)($_SERVER['REMOTE_ADDR']??'unknown');
    $rate=function_exists('devone_login_rate_state')?devone_login_rate_state($rateIdentity,false,false):array('allowed'=>true,'retry_after'=>0);
    if(empty($rate['allowed'])) { $error='Too many login attempts. Try again in a few minutes.'; }
    else {
        $tbl=table_name('users');
        $stmt=db()->prepare("SELECT * FROM `$tbl` WHERE username=? LIMIT 1");
        $stmt->execute([$u]);
        $user=$stmt->fetch();
        $disabled = $user && isset($user['status']) && strtolower((string)$user['status']) === 'disabled';
        if ($user && !$disabled && password_verify($p,(string)$user['password'])) {
            if (password_needs_rehash((string)$user['password'], PASSWORD_DEFAULT)) {
                try { db()->prepare("UPDATE `$tbl` SET password=? WHERE id=?")->execute(array(password_hash($p,PASSWORD_DEFAULT),(int)$user['id'])); } catch(Throwable $e) {}
            }
            session_regenerate_id(true);
            $_SESSION['loggedin']=true;
            $_SESSION['user_id']=$user['id'];
            if(function_exists('devone_login_rate_state'))devone_login_rate_state($rateIdentity,false,true);
            header('Location: dashboard.php');
            exit;
        }
        if(function_exists('devone_login_rate_state'))devone_login_rate_state($rateIdentity,true,false);
        usleep(random_int(80000,160000));
        $error='Invalid login.';
    }
}
?><!DOCTYPE html>
<html>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width,initial-scale=1'>
  <title><?= e($siteName) ?> Login</title>
  <link rel='stylesheet' href='../assets/css/devone.css'>
  <link rel='stylesheet' href='../assets/css/devone-admin.css'>
</head>
<body class='devone-admin devone-auth-page devone-login-page'>
  <main class='devone-auth-shell'>
    <section class='card devone-auth-card'>
      <div class='devone-auth-brand'>
        <?php if($siteLogo): ?><img src='<?= e($siteLogo) ?>' alt='<?= e($siteName) ?> logo'><?php else: ?><span class='logo'>&lt;/&gt;</span><?php endif; ?>
        <div><h1><?= e($siteName) ?></h1><p class='muted'>Developer Admin Login</p></div>
      </div>
      <?php if($msg): ?><p class='card success-card'><?= e($msg) ?></p><?php endif; ?>
      <?php if($error): ?><p class='card error-card'><?= e($error) ?></p><?php endif; ?>
      <form method='post' class='devone-auth-form'>
        <?= csrf_field() ?>
        <label>Username<input name='username' value='<?= e($_POST['username'] ?? '') ?>' autocomplete='username'></label>
        <label>Password<input type='password' name='password' autocomplete='current-password'></label>
        <button>Login</button>
      </form>
      <p class='devone-auth-links'><a href='register.php'>Register</a> <span>•</span> <a href='../index.php'>View Site</a></p>
    </section>
  </main>
</body>
</html>
