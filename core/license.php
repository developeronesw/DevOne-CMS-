<?php
/**
 * DevOne License Client
 *
 * This file lives inside every DevOne CMS install. It does NOT contain the
 * private license-server signing key. It activates yearly Pro/Enterprise
 * licenses against the official DevOne License Server and stores a signed
 * local certificate so paid features can work offline until expiration.
 */

if (!defined('DEVONE_LICENSE_DEFAULT_SERVER')) {
    define('DEVONE_LICENSE_DEFAULT_SERVER', 'https://license.devonecms.com');
}

if (!defined('DEVONE_LICENSE_LOCK_SERVER_CONFIG')) {
    define('DEVONE_LICENSE_LOCK_SERVER_CONFIG', true);
}

if (!defined('DEVONE_LICENSE_PUBLIC_KEY')) {
    define('DEVONE_LICENSE_PUBLIC_KEY', <<<'DEVONE_PUBLIC_KEY'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAjYOokzv4eRvQfv/nv49O
oy5MTAsKPyxpCzPMdFUDG9OTd9tTShjpUINGJ/2jAHU6OWl2UWbqr2efR826ZnuU
a7M96SwDy7lyvyVLYvOvLJtYAvtOEb/AABizth+GfhuwR8Z0E+EO7THt4C5jUAqy
W00vj2NQRg596bAWrpU+fSwqJhUTO9XMqbaX9VzVAJgFKluTlILIACQEO7bS8uwA
EhWHq1P2NFPeoCnjqt+4piMcOGvbFmei7IgSo2PROCPPILZcXQxH6XxREwjDmyAB
0SdMndqxMFvd/uaLArRfXY8E2FB1sYNYVetb0EitNlSLjw/sQoFTeYPiwBwjnTB+
PQIDAQAB
-----END PUBLIC KEY-----
DEVONE_PUBLIC_KEY
    );
}

function devone_license_secure_random($bytes = 32) {
    return bin2hex(random_bytes(max(16, (int)$bytes)));
}

function devone_license_normalize_plan($plan) {
    $plan = strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string)$plan));
    if (in_array($plan, array('devone_pro','pro'), true)) { return 'pro'; }
    if (in_array($plan, array('devone_enterprise','enterprise'), true)) { return 'enterprise'; }
    return 'free';
}

function devone_license_plan_label($plan = null) {
    $plan = devone_license_normalize_plan($plan ?? devone_license_plan());
    if ($plan === 'enterprise') { return 'DevOne Enterprise'; }
    if ($plan === 'pro') { return 'DevOne Pro'; }
    return 'DevOne Free';
}

function devone_license_server_url() {
    if (defined('DEVONE_LICENSE_LOCK_SERVER_CONFIG') && DEVONE_LICENSE_LOCK_SERVER_CONFIG) {
        $url = defined('DEVONE_LICENSE_SERVER_URL') ? DEVONE_LICENSE_SERVER_URL : DEVONE_LICENSE_DEFAULT_SERVER;
        return rtrim(trim((string)$url), '/');
    }
    $url = function_exists('get_setting') ? get_setting('devone_license_server_url', '') : '';
    if ($url === '' && defined('DEVONE_LICENSE_SERVER_URL')) { $url = DEVONE_LICENSE_SERVER_URL; }
    if ($url === '') { $url = DEVONE_LICENSE_DEFAULT_SERVER; }
    return rtrim(trim((string)$url), '/');
}

function devone_license_public_key() {
    if (defined('DEVONE_LICENSE_LOCK_SERVER_CONFIG') && DEVONE_LICENSE_LOCK_SERVER_CONFIG && defined('DEVONE_LICENSE_PUBLIC_KEY')) {
        return trim((string)DEVONE_LICENSE_PUBLIC_KEY);
    }
    $key = function_exists('get_setting') ? (string)get_setting('devone_license_public_key', '') : '';
    if (trim($key) === '' && defined('DEVONE_LICENSE_PUBLIC_KEY')) { $key = (string)DEVONE_LICENSE_PUBLIC_KEY; }
    return trim($key);
}

function devone_license_install_id() {
    $id = function_exists('get_setting') ? (string)get_setting('devone_install_id', '') : '';
    if (preg_match('/^ins_[a-f0-9]{32,128}$/', $id)) { return $id; }
    $id = 'ins_' . devone_license_secure_random(32);
    if (function_exists('set_setting')) { set_setting('devone_install_id', $id); }
    return $id;
}

function devone_license_site_url() {
    if (defined('SITE_URL')) { return rtrim((string)SITE_URL, '/'); }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host=(string)$_SERVER['HTTP_HOST'];if(!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/',$host))$host='localhost';
        return $scheme . '://' . $host;
    }
    return '';
}

function devone_license_domain_from_url($url = '') {
    $url = trim((string)$url);
    if ($url === '') { $url = devone_license_site_url(); }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host && strpos($url, '://') === false) { $host = parse_url('http://' . $url, PHP_URL_HOST); }
    $host = strtolower((string)$host);
    $host = preg_replace('/:\d+$/', '', $host);
    $host = preg_replace('/[^a-z0-9._-]/', '', $host);
    return trim($host, '.');
}

function devone_license_cms_version() {
    if (function_exists('devone_core_version')) { return (string)devone_core_version(); }
    if (defined('DEVONE_CORE_VERSION')) { return (string)DEVONE_CORE_VERSION; }
    if (defined('CMS_VERSION')) { return (string)CMS_VERSION; }
    return 'unknown';
}

function devone_license_certificate_raw() {
    $raw = function_exists('get_setting') ? (string)get_setting('devone_license_certificate', '') : '';
    return trim($raw);
}

function devone_license_certificate() {
    $raw = devone_license_certificate_raw();
    if ($raw === '') { return null; }
    $cert = json_decode($raw, true);
    return is_array($cert) ? $cert : null;
}

function devone_license_signature_payload($payload) {
    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function devone_license_verify_certificate($certificate = null, &$reason = '') {
    $reason = '';
    $certificate = $certificate ?: devone_license_certificate();
    if (!is_array($certificate)) { $reason = 'missing_certificate'; return false; }
    if (empty($certificate['payload']) || !is_array($certificate['payload'])) { $reason = 'missing_payload'; return false; }
    if (empty($certificate['signature'])) { $reason = 'missing_signature'; return false; }

    $publicKey = devone_license_public_key();
    if ($publicKey === '') { $reason = 'missing_public_key'; return false; }
    if (!function_exists('openssl_verify')) { $reason = 'openssl_missing'; return false; }

    $payloadJson = devone_license_signature_payload($certificate['payload']);
    $signature = base64_decode((string)$certificate['signature'], true);
    if ($payloadJson === false || $signature === false) { $reason = 'invalid_signature_data'; return false; }

    $verify = @openssl_verify($payloadJson, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($verify !== 1) { $reason = 'signature_invalid'; return false; }

    $payload = $certificate['payload'];
    $installId = (string)($payload['install_id'] ?? '');
    if ($installId !== '' && !hash_equals($installId, devone_license_install_id())) { $reason = 'install_mismatch'; return false; }

    $status = strtolower((string)($payload['status'] ?? ''));
    if (!in_array($status, array('active','grace'), true)) { $reason = 'status_' . ($status ?: 'missing'); return false; }

    $expiresAt = (string)($payload['expires_at'] ?? '');
    if ($expiresAt !== '') {
        $expiresTs = strtotime($expiresAt);
        if ($expiresTs !== false && time() > $expiresTs) {
            $graceUntil = (string)($payload['grace_until'] ?? '');
            $graceTs = $graceUntil !== '' ? strtotime($graceUntil) : false;
            if ($graceTs === false || time() > $graceTs) { $reason = 'expired'; return false; }
        }
    }

    return true;
}

function devone_license_payload() {
    $reason = '';
    $cert = devone_license_certificate();
    if (!$cert || !devone_license_verify_certificate($cert, $reason)) { return null; }
    return $cert['payload'];
}

function devone_license_plan() {
    $payload = devone_license_payload();
    if ($payload && !empty($payload['plan'])) { return devone_license_normalize_plan($payload['plan']); }
    return 'free';
}

function devone_license_status() {
    $cert = devone_license_certificate();
    $reason = '';
    $verified = $cert ? devone_license_verify_certificate($cert, $reason) : false;
    $payload = $cert['payload'] ?? array();
    $plan = $verified ? devone_license_normalize_plan($payload['plan'] ?? 'free') : 'free';
    return array(
        'verified' => $verified,
        'reason' => $reason,
        'plan' => $plan,
        'plan_label' => devone_license_plan_label($plan),
        'status' => $verified ? (string)($payload['status'] ?? 'active') : 'free',
        'license_id' => (string)($payload['license_id'] ?? ''),
        'activation_id' => (string)($payload['activation_id'] ?? ''),
        'features' => $verified && !empty($payload['features']) && is_array($payload['features']) ? $payload['features'] : array(),
        'activated_at' => (string)($payload['activated_at'] ?? ''),
        'expires_at' => (string)($payload['expires_at'] ?? ''),
        'grace_until' => (string)($payload['grace_until'] ?? ''),
        'server_url' => devone_license_server_url(),
        'install_id' => devone_license_install_id(),
        'site_url' => devone_license_site_url(),
        'domain' => devone_license_domain_from_url(),
    );
}


function devone_license_is_active() {
    $status = devone_license_status();
    return !empty($status['verified']) && in_array(($status['plan'] ?? 'free'), array('pro','enterprise'), true);
}

function devone_license_plan_at_least($requiredPlan) {
    $order = array('free' => 0, 'pro' => 1, 'enterprise' => 2);
    $current = devone_license_normalize_plan(devone_license_plan());
    $required = devone_license_normalize_plan($requiredPlan);
    return ($order[$current] ?? 0) >= ($order[$required] ?? 0);
}

function devone_license_feature_enabled($feature) {
    $feature = strtolower(trim((string)$feature));
    if ($feature === '') { return false; }
    $payload = devone_license_payload();
    if (!$payload) { return false; }
    $features = !empty($payload['features']) && is_array($payload['features']) ? array_map('strtolower', $payload['features']) : array();
    return in_array($feature, $features, true) || in_array('*', $features, true);
}

function devone_license_http_post_json($url, $payload, $timeout = 20) {
    $url=trim((string)$url);$parts=parse_url($url);
    if(strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])){return array('ok'=>false,'message'=>'License server URL must use HTTPS.');}
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return array('ok'=>false, 'message'=>'Could not encode license request.'); }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
        ));
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $body === null) { return array('ok'=>false, 'message'=>'License server request failed: ' . $err); }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) { return array('ok'=>false, 'message'=>'License server returned invalid JSON. HTTP ' . $code); }
        $decoded['_http_code'] = $code;
        return $decoded;
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",'content'=>$json,'timeout'=>$timeout,'ignore_errors'=>true,'follow_location'=>0,
        ),
        'ssl' => array('verify_peer'=>true,'verify_peer_name'=>true),
    ));
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { return array('ok'=>false, 'message'=>'License server request failed. Enable cURL or allow URL fopen.'); }
    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) { return array('ok'=>false, 'message'=>'License server returned invalid JSON.'); }
    return $decoded;
}

function devone_license_activate($licenseKey) {
    $licenseKey = strtoupper(trim((string)$licenseKey));
    if ($licenseKey === '') { return array('ok'=>false, 'message'=>'Enter a license key.'); }
    if (!function_exists('set_setting')) { return array('ok'=>false, 'message'=>'Settings storage is not available.'); }

    $server = devone_license_server_url();
    if ($server === '') { return array('ok'=>false, 'message'=>'License server URL is missing.'); }
    $endpoint = rtrim($server, '/') . '/api/activate.php';
    $request = array(
        'license_key' => $licenseKey,
        'install_id' => devone_license_install_id(),
        'site_url' => devone_license_site_url(),
        'domain' => devone_license_domain_from_url(),
        'cms_version' => devone_license_cms_version(),
    );
    $response = devone_license_http_post_json($endpoint, $request);
    if (empty($response['ok'])) { return array('ok'=>false, 'message'=>$response['message'] ?? 'License activation failed.', 'response'=>$response); }
    if (empty($response['certificate']) || !is_array($response['certificate'])) { return array('ok'=>false, 'message'=>'License server did not return a certificate.'); }

    $reason = '';
    if (!devone_license_verify_certificate($response['certificate'], $reason)) {
        return array('ok'=>false, 'message'=>'License certificate could not be verified: ' . $reason);
    }

    set_setting('devone_license_key_hash', hash('sha256', $licenseKey));
    set_setting('devone_license_certificate', json_encode($response['certificate'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    set_setting('devone_license_last_check', gmdate('c'));
    if (function_exists('devone_log')) { devone_log('license_activated', 'DevOne license activated: ' . devone_license_plan_label($response['certificate']['payload']['plan'] ?? 'free')); }
    return array('ok'=>true, 'message'=>$response['message'] ?? 'License activated.', 'certificate'=>$response['certificate']);
}

function devone_license_refresh() {
    $hash = function_exists('get_setting') ? get_setting('devone_license_key_hash', '') : '';
    if ($hash === '') { return array('ok'=>false, 'message'=>'No license has been activated on this installation.'); }
    $status = devone_license_status();
    $endpoint = devone_license_server_url() . '/api/validate.php';
    $response = devone_license_http_post_json($endpoint, array(
        'install_id' => devone_license_install_id(),
        'license_id' => $status['license_id'],
        'activation_id' => $status['activation_id'],
        'site_url' => devone_license_site_url(),
        'domain' => devone_license_domain_from_url(),
        'cms_version' => devone_license_cms_version(),
    ));
    if (empty($response['ok'])) { return array('ok'=>false, 'message'=>$response['message'] ?? 'License refresh failed.', 'response'=>$response); }
    if (empty($response['certificate']) || !is_array($response['certificate'])) { return array('ok'=>false, 'message'=>'License server did not return a certificate.'); }
    $reason = '';
    if (!devone_license_verify_certificate($response['certificate'], $reason)) { return array('ok'=>false, 'message'=>'License certificate could not be verified: ' . $reason); }
    set_setting('devone_license_certificate', json_encode($response['certificate'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    set_setting('devone_license_last_check', gmdate('c'));
    return array('ok'=>true, 'message'=>$response['message'] ?? 'License refreshed.', 'certificate'=>$response['certificate']);
}

function devone_license_deactivate_local() {
    if (function_exists('set_setting')) {
        set_setting('devone_license_key_hash', '');
        set_setting('devone_license_certificate', '');
        set_setting('devone_license_last_check', '');
    }
    if (function_exists('devone_log')) { devone_log('license_deactivated_local', 'DevOne license cleared from this installation.'); }
    return true;
}
