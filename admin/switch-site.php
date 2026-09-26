<?php
require __DIR__ . '/includes/admin_common.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('POST required.');
}
verify_csrf();

$siteId = (int)($_POST['site_id'] ?? 0);
$back = (string)($_POST['back'] ?? 'dashboard.php');
$back = preg_replace('/[^a-zA-Z0-9_\-.?=&]/', '', $back) ?: 'dashboard.php';
if ($siteId > 0 && function_exists('devone_user_can_access_site') && devone_user_can_access_site(devone_current_user_id(), $siteId)) {
    $_SESSION['devone_admin_site_id'] = $siteId;
}
header('Location: ' . $back);
exit;
