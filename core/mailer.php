<?php
/**
 * Developer One CMS Core Mailer.
 *
 * DevOne-style core mail layer for DevOne. All CMS emails should use
 * devone_send_email() / devone_mail() so installation, registration, users,
 * commerce receipts, license emails, and future system emails share the same
 * official PHPMailer transport and SMTP settings.
 */

if (!function_exists('devone_phpmailer_base_path')) {
function devone_phpmailer_base_path() {
    return __DIR__ . '/vendor/phpmailer';
}
}

if (!function_exists('devone_phpmailer_load')) {
function devone_phpmailer_load() {
    static $loaded = null;
    if ($loaded !== null) { return $loaded; }

    // Prefer Composer autoload if a developer later installs dependencies that way.
    $composer = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($composer)) { require_once $composer; }

    // DevOne-bundled official PHPMailer source package.
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $base = devone_phpmailer_base_path() . '/src';
        $files = array(
            $base . '/Exception.php',
            $base . '/SMTP.php',
            $base . '/PHPMailer.php',
        );
        foreach ($files as $file) {
            if (is_file($file)) { require_once $file; }
        }
    }

    $loaded = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    return $loaded;
}
}

if (!function_exists('devone_phpmailer_version')) {
function devone_phpmailer_version() {
    $versionFile = devone_phpmailer_base_path() . '/VERSION';
    if (is_file($versionFile)) {
        $version = trim((string)@file_get_contents($versionFile));
        if ($version !== '') { return $version; }
    }
    return '';
}
}

if (!function_exists('devone_mail_setting')) {
function devone_mail_setting($key, $default = '') {
    return function_exists('get_setting') ? get_setting($key, $default) : $default;
}
}

if (!function_exists('devone_mail_site_name')) {
function devone_mail_site_name() {
    $name = trim((string)devone_mail_setting('mail_from_name', ''));
    if ($name === '') { $name = trim((string)devone_mail_setting('site_name', 'DevOneCMS')); }
    return $name !== '' ? $name : 'DevOneCMS';
}
}

if (!function_exists('devone_mail_site_url')) {
function devone_mail_site_url() {
    if (function_exists('devone_site_url')) { return rtrim(devone_site_url('/'), '/'); }
    if (defined('SITE_URL')) { return rtrim((string)SITE_URL, '/'); }
    return '';
}
}

if (!function_exists('devone_mail_default_domain')) {
function devone_mail_default_domain() {
    $url = devone_mail_site_url();
    $host = $url ? (parse_url($url, PHP_URL_HOST) ?: '') : '';
    $host = preg_replace('/^www\./i', '', $host);
    return $host ?: 'localhost';
}
}

if (!function_exists('devone_mail_from_email')) {
function devone_mail_from_email() {
    $candidates = array(
        devone_mail_setting('mail_from_email', ''),
        devone_mail_setting('site_email', ''),
        devone_mail_setting('admin_email', ''),
    );
    foreach ($candidates as $email) {
        $email = trim((string)$email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) { return $email; }
    }
    $domain = devone_mail_default_domain();
    return $domain === 'localhost' ? 'noreply@localhost' : ('noreply@' . $domain);
}
}

if (!function_exists('devone_mail_parse_address')) {
function devone_mail_parse_address($value) {
    $value = trim((string)$value);
    if ($value === '') { return null; }
    $name = '';
    $email = $value;
    if (preg_match('/^(.*?)<([^>]+)>$/', $value, $m)) {
        $name = trim(trim((string)$m[1]), "\"' ");
        $email = trim((string)$m[2]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return null; }
    return array('email' => $email, 'name' => $name);
}
}

if (!function_exists('devone_mail_parse_address_list')) {
function devone_mail_parse_address_list($list) {
    $out = array();
    if (is_array($list)) {
        foreach ($list as $key => $value) {
            if (is_string($key) && filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $out[] = array('email' => $key, 'name' => trim((string)$value));
                continue;
            }
            $parsed = devone_mail_parse_address($value);
            if ($parsed) { $out[] = $parsed; }
        }
        return $out;
    }

    $parts = preg_split('/,(?=(?:[^<]*<[^>]*>)*[^>]*$)/', (string)$list);
    foreach ($parts as $part) {
        $parsed = devone_mail_parse_address($part);
        if ($parsed) { $out[] = $parsed; }
    }
    return $out;
}
}

if (!function_exists('devone_mail_parse_headers')) {
function devone_mail_parse_headers($headers) {
    $parsed = array(
        'from' => null,
        'reply_to' => array(),
        'cc' => array(),
        'bcc' => array(),
        'content_type' => '',
        'charset' => '',
        'custom' => array(),
    );
    if (empty($headers)) { return $parsed; }
    $lines = is_array($headers) ? $headers : preg_split('/\r\n|\r|\n/', (string)$headers);
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || strpos($line, ':') === false) { continue; }
        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $lname = strtolower($name);
        if ($lname === 'from') { $parsed['from'] = devone_mail_parse_address($value); }
        elseif ($lname === 'reply-to') { $parsed['reply_to'] = array_merge($parsed['reply_to'], devone_mail_parse_address_list($value)); }
        elseif ($lname === 'cc') { $parsed['cc'] = array_merge($parsed['cc'], devone_mail_parse_address_list($value)); }
        elseif ($lname === 'bcc') { $parsed['bcc'] = array_merge($parsed['bcc'], devone_mail_parse_address_list($value)); }
        elseif ($lname === 'content-type') {
            $parsed['content_type'] = $value;
            if (preg_match('/charset=([^;]+)/i', $value, $m)) { $parsed['charset'] = trim((string)$m[1], " \t\n\r\0\x0B\""); }
        } else {
            $parsed['custom'][] = array($name, $value);
        }
    }
    return $parsed;
}
}

if (!function_exists('devone_mail_wrap_html')) {
function devone_mail_wrap_html($title, $bodyHtml) {
    $site = htmlspecialchars(devone_mail_site_name(), ENT_QUOTES, 'UTF-8');
    $titleEsc = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars(devone_mail_site_url(), ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $titleEsc . '</title></head>' .
        '<body style="margin:0;background:#050814;color:#eef3ff;font-family:Arial,Helvetica,sans-serif;">' .
        '<div style="max-width:680px;margin:0 auto;padding:28px;">' .
        '<div style="border:1px solid rgba(120,150,255,.28);border-radius:24px;background:linear-gradient(145deg,#0b1022,#121936);padding:28px;">' .
        '<h1 style="margin:0 0 14px;font-size:28px;line-height:1.1;color:#fff;">' . $titleEsc . '</h1>' .
        '<div style="font-size:16px;line-height:1.65;color:#d9e2ff;">' . $bodyHtml . '</div>' .
        '<p style="margin:24px 0 0;color:#99a8e8;font-size:13px;">Sent by ' . $site . ($url ? ' — <a href="' . $url . '" style="color:#28b8ff;">' . $url . '</a>' : '') . '</p>' .
        '</div></div></body></html>';
}
}

if (!function_exists('devone_mail_plain_from_html')) {
function devone_mail_plain_from_html($html) {
    $plain = preg_replace('/<br\s*\/?>/i', "\n", (string)$html);
    $plain = preg_replace('/<\/p>/i', "\n\n", $plain);
    $plain = trim(html_entity_decode(strip_tags($plain), ENT_QUOTES, 'UTF-8'));
    return $plain !== '' ? $plain : 'Message from ' . devone_mail_site_name();
}
}

if (!function_exists('devone_mail_log_result')) {
function devone_mail_log_result($ok, $subject, $to, $message = '') {
    $recipient = is_array($to) ? implode(', ', array_map('strval', $to)) : (string)$to;
    if (function_exists('devone_log')) {
        devone_log($ok ? 'email_sent' : 'email_failed', $subject . ' → ' . $recipient . ($message !== '' ? ' (' . $message . ')' : ''));
    }
}
}

if (!function_exists('devone_send_email')) {
function devone_send_email($to, $subject, $htmlBody, $plainBody = '', $options = array()) {
    $subject = trim((string)$subject);
    if ($subject === '') { $subject = devone_mail_site_name(); }

    if (!devone_phpmailer_load()) {
        return array('ok' => false, 'message' => 'Official PHPMailer could not be loaded from DevOne Core.');
    }

    $recipients = devone_mail_parse_address_list($to);
    if (!$recipients) {
        return array('ok' => false, 'message' => 'Invalid recipient email.');
    }

    $headerData = devone_mail_parse_headers($options['headers'] ?? array());
    $method = strtolower(trim((string)devone_mail_setting('mail_method', 'php_mail')));
    $fromName = trim((string)($options['from_name'] ?? devone_mail_site_name()));
    $fromEmail = trim((string)($options['from_email'] ?? devone_mail_from_email()));
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) { $fromEmail = devone_mail_from_email(); }
    if (!empty($headerData['from'])) {
        $fromEmail = $headerData['from']['email'];
        if ($headerData['from']['name'] !== '') { $fromName = $headerData['from']['name']; }
    }

    $isHtml = (bool)($options['is_html'] ?? true);
    if (!empty($headerData['content_type']) && stripos($headerData['content_type'], 'text/plain') !== false) { $isHtml = false; }
    $body = (string)$htmlBody;
    $html = $isHtml ? devone_mail_wrap_html($subject, $body) : $body;
    $plain = $plainBody !== '' ? (string)$plainBody : ($isHtml ? devone_mail_plain_from_html($body) : $body);

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = !empty($headerData['charset']) ? $headerData['charset'] : 'UTF-8';
        $mail->Timeout = (int)($options['timeout'] ?? 20);

        if ($method === 'smtp') {
            $host = trim((string)devone_mail_setting('smtp_host', ''));
            if ($host === '') {
                throw new \RuntimeException('SMTP is selected, but the SMTP host is empty.');
            }
            $encryption = strtolower(trim((string)devone_mail_setting('smtp_encryption', 'tls')));
            if (!in_array($encryption, array('none','tls','ssl'), true)) { $encryption = 'tls'; }
            $port = (int)devone_mail_setting('smtp_port', $encryption === 'ssl' ? '465' : '587');
            if ($port <= 0) { $port = $encryption === 'ssl' ? 465 : 587; }
            $username = trim((string)devone_mail_setting('smtp_username', ''));
            $password = (string)devone_mail_setting('smtp_password', '');

            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = ($username !== '');
            $mail->Username = $username;
            $mail->Password = $password;
            if ($encryption === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAutoTLS = true;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->SMTPAutoTLS = true;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
        } else {
            $mail->isMail();
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);

        foreach (($options['reply_to'] ?? array()) as $replyTo) {
            $parsed = devone_mail_parse_address($replyTo);
            if ($parsed) { $mail->addReplyTo($parsed['email'], $parsed['name']); }
        }
        foreach ($headerData['reply_to'] as $replyTo) { $mail->addReplyTo($replyTo['email'], $replyTo['name']); }

        foreach ($recipients as $recipient) { $mail->addAddress($recipient['email'], $recipient['name']); }

        foreach (array_merge(devone_mail_parse_address_list($options['cc'] ?? array()), $headerData['cc']) as $cc) {
            $mail->addCC($cc['email'], $cc['name']);
        }
        foreach (array_merge(devone_mail_parse_address_list($options['bcc'] ?? array()), $headerData['bcc']) as $bcc) {
            $mail->addBCC($bcc['email'], $bcc['name']);
        }

        foreach ($headerData['custom'] as $customHeader) {
            $name = (string)$customHeader[0];
            $value = (string)$customHeader[1];
            if ($name !== '' && $value !== '') { $mail->addCustomHeader($name, $value); }
        }

        $attachments = $options['attachments'] ?? array();
        if (!is_array($attachments)) { $attachments = array($attachments); }
        foreach ($attachments as $attachment) {
            $path = is_array($attachment) ? (string)($attachment['path'] ?? '') : (string)$attachment;
            $name = is_array($attachment) ? (string)($attachment['name'] ?? '') : '';
            if ($path !== '' && is_file($path)) {
                $name !== '' ? $mail->addAttachment($path, $name) : $mail->addAttachment($path);
            }
        }

        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        if ($isHtml) {
            $mail->Body = $html;
            $mail->AltBody = $plain;
        } else {
            $mail->Body = $plain;
        }

        $sent = $mail->send();
        $provider = $method === 'smtp' ? 'SMTP / PHPMailer' : 'PHP mail() / PHPMailer';
        devone_mail_log_result((bool)$sent, $subject, array_column($recipients, 'email'), $provider);
        return array(
            'ok' => (bool)$sent,
            'message' => $sent ? ('Email sent by ' . $provider . '.') : ($mail->ErrorInfo ?: 'Email could not be sent.'),
            'provider' => $provider,
        );
    } catch (\Throwable $e) {
        devone_mail_log_result(false, $subject, array_column($recipients, 'email'), $e->getMessage());
        return array('ok' => false, 'message' => $e->getMessage(), 'provider' => $method === 'smtp' ? 'SMTP / PHPMailer' : 'PHP mail() / PHPMailer');
    }
}
}

if (!function_exists('devone_mail')) {
function devone_mail($to, $subject, $message, $headers = array(), $attachments = array()) {
    // DevOne-style alias. Returns true/false like devone_mail().
    $result = devone_send_email($to, $subject, $message, '', array(
        'headers' => $headers,
        'attachments' => $attachments,
    ));
    return !empty($result['ok']);
}
}

if (!function_exists('devone_mail_diagnostics')) {
function devone_mail_diagnostics() {
    $loaded = devone_phpmailer_load();
    $source = '';
    if ($loaded) {
        try { $source = (new \ReflectionClass('PHPMailer\\PHPMailer\\PHPMailer'))->getFileName() ?: ''; }
        catch (\Throwable $e) { $source = ''; }
    }
    $method = strtolower(trim((string)devone_mail_setting('mail_method', 'php_mail')));
    return array(
        'phpmailer_loaded' => $loaded,
        'phpmailer_source' => $source,
        'phpmailer_version' => devone_phpmailer_version(),
        'php_mail_function' => function_exists('mail'),
        'openssl_loaded' => extension_loaded('openssl'),
        'mail_method' => $method,
        'from_name' => devone_mail_site_name(),
        'from_email' => devone_mail_from_email(),
        'smtp_host' => trim((string)devone_mail_setting('smtp_host', '')),
        'smtp_port' => trim((string)devone_mail_setting('smtp_port', '587')),
        'smtp_encryption' => trim((string)devone_mail_setting('smtp_encryption', 'tls')),
        'smtp_username_set' => trim((string)devone_mail_setting('smtp_username', '')) !== '',
        'smtp_password_set' => (string)devone_mail_setting('smtp_password', '') !== '',
    );
}
}
