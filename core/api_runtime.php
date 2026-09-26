<?php
/** DevOne CMS API Runtime v1.2.8 */

function devone_api_json_decode($value, $default = array()) {
    if (is_array($value)) { return $value; }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $default;
}

function devone_api_clean_list($value) {
    if (is_array($value)) { $parts = $value; }
    else { $parts = preg_split('/[\r\n,]+/', (string)$value); }
    $out = array();
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') { $out[] = $part; }
    }
    return array_values(array_unique($out));
}

function devone_api_schema_install() {
    static $done = false;
    if ($done) { return; }
    $done = true;
    $markerRoot=defined('BASE_PATH')?BASE_PATH:dirname(__DIR__);
    $version=defined('DEVONE_CORE_VERSION')?(string)DEVONE_CORE_VERSION:(defined('CMS_VERSION')?(string)CMS_VERSION:'current');
    $prefixKey=defined('TABLE_PREFIX')?(string)TABLE_PREFIX:'cms_';
    $marker=$markerRoot.'/storage/cache/api-schema-'.preg_replace('/[^A-Za-z0-9._-]/','-', $version.'-'.$prefixKey).'.ok';
    if(is_file($marker))return;
    $pdo = db();
    $prefix = function_exists('devone_active_table_prefix') ? devone_active_table_prefix() : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cms_');
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$prefix);
    $endpoints = table_name('api_endpoints');

    $columns = array(
        'description' => "ALTER TABLE `{$endpoints}` ADD `description` text",
        'allowed_fields' => "ALTER TABLE `{$endpoints}` ADD `allowed_fields` longtext",
        'writable_fields' => "ALTER TABLE `{$endpoints}` ADD `writable_fields` longtext",
        'required_fields' => "ALTER TABLE `{$endpoints}` ADD `required_fields` longtext",
        'required_permission' => "ALTER TABLE `{$endpoints}` ADD `required_permission` varchar(120) DEFAULT ''",
        'allow_public_write' => "ALTER TABLE `{$endpoints}` ADD `allow_public_write` tinyint(1) DEFAULT 0",
        'max_page_size' => "ALTER TABLE `{$endpoints}` ADD `max_page_size` int DEFAULT 100",
        'rate_limit_per_minute' => "ALTER TABLE `{$endpoints}` ADD `rate_limit_per_minute` int DEFAULT 60",
        'cors_origins' => "ALTER TABLE `{$endpoints}` ADD `cors_origins` longtext",
        'site_scoped' => "ALTER TABLE `{$endpoints}` ADD `site_scoped` tinyint(1) DEFAULT 1",
        'idempotency_required' => "ALTER TABLE `{$endpoints}` ADD `idempotency_required` tinyint(1) DEFAULT 1",
        'updated_at' => "ALTER TABLE `{$endpoints}` ADD `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    );
    foreach ($columns as $name => $sql) {
        if (!devone_schema_column_exists_pdo($pdo, $endpoints, $name)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }
    try { $pdo->exec("ALTER TABLE `{$endpoints}` ADD UNIQUE KEY `unique_path_method` (`path`,`method`)"); } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}api_keys` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(160) NOT NULL,
        `key_prefix` varchar(24) NOT NULL,
        `key_hash` varchar(255) NOT NULL,
        `permissions` longtext,
        `allowed_endpoints` longtext,
        `active` tinyint(1) DEFAULT 1,
        `expires_at` datetime DEFAULT NULL,
        `last_used_at` datetime DEFAULT NULL,
        `created_by` int DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `key_prefix` (`key_prefix`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}api_request_logs` (
        `id` bigint NOT NULL AUTO_INCREMENT,
        `endpoint_id` int DEFAULT NULL,
        `api_key_id` int DEFAULT NULL,
        `method` varchar(12) NOT NULL,
        `path` varchar(255) NOT NULL,
        `status_code` int NOT NULL,
        `ip_address` varchar(64) DEFAULT '',
        `duration_ms` int DEFAULT 0,
        `request_id` varchar(80) DEFAULT '',
        `details` text,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `endpoint_id` (`endpoint_id`), KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}api_idempotency` (
        `id` bigint NOT NULL AUTO_INCREMENT,
        `endpoint_id` int NOT NULL,
        `api_key_id` int DEFAULT 0,
        `idempotency_key` varchar(190) NOT NULL,
        `request_hash` char(64) NOT NULL,
        `status_code` int NOT NULL,
        `response_body` longtext,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `unique_request` (`endpoint_id`,`api_key_id`,`idempotency_key`), KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
    $dir=dirname($marker);if(!is_dir($dir))@mkdir($dir,0775,true);@file_put_contents($marker,gmdate('c')."\n",LOCK_EX);
}

function devone_api_table($suffix) { return table_name($suffix); }
function devone_api_request_id() { return 'd1_' . bin2hex(random_bytes(12)); }
function devone_api_client_ip() { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }

function devone_api_headers() {
    if (function_exists('getallheaders')) { return array_change_key_case(getallheaders(), CASE_LOWER); }
    $out = array();
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) { $out[strtolower(str_replace('_', '-', substr($key, 5)))] = $value; }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) { $out['content-type'] = $_SERVER['CONTENT_TYPE']; }
    return $out;
}

function devone_api_input() {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $raw = file_get_contents('php://input');
    if (strpos($contentType, 'application/json') !== false) {
        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? $data : array();
    }
    if (!empty($_POST)) { return $_POST; }
    parse_str($raw, $data);
    return is_array($data) ? $data : array();
}

function devone_api_respond($status, $payload, $requestId = '') {
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if ($requestId !== '') { header('X-Request-ID: ' . $requestId); }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function devone_api_bearer_token() {
    $headers = devone_api_headers();
    $auth = trim((string)($headers['authorization'] ?? ''));
    return preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : '';
}

function devone_api_authenticate() {
    $token = devone_api_bearer_token();
    if ($token === '' || strlen($token) < 24) { return null; }
    $prefix = substr($token, 0, 16);
    $table = devone_api_table('api_keys');
    $stmt = db()->prepare("SELECT * FROM `{$table}` WHERE key_prefix=? AND active=1 LIMIT 1");
    $stmt->execute(array($prefix));
    $row = $stmt->fetch();
    if (!$row || !password_verify($token, $row['key_hash'])) { return null; }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) <= time()) { return null; }
    try { db()->prepare("UPDATE `{$table}` SET last_used_at=NOW() WHERE id=?")->execute(array((int)$row['id'])); } catch (Exception $e) {}
    return $row;
}

function devone_api_key_allows($key, $endpoint) {
    if (!$key) { return false; }
    $allowed = devone_api_json_decode($key['allowed_endpoints'] ?? '', array());
    if ($allowed && !in_array('*', $allowed, true) && !in_array((string)$endpoint['path'], $allowed, true) && !in_array((string)$endpoint['id'], $allowed, true)) { return false; }
    $required = trim((string)($endpoint['required_permission'] ?? ''));
    if ($required === '') { return true; }
    $permissions = devone_api_json_decode($key['permissions'] ?? '', array());
    return in_array('*', $permissions, true) || in_array($required, $permissions, true);
}

function devone_api_rate_limit($identity, $limit) {
    $limit=max(1,min(5000,(int)$limit));$dir=defined('BASE_PATH')?BASE_PATH.'/storage/cache/api-rate':__DIR__.'/../storage/cache/api-rate';if(!is_dir($dir))@mkdir($dir,0755,true);
    $bucket=date('YmdHi');$file=$dir.'/'.hash('sha256',$identity.'|'.$bucket).'.json';$count=0;$fh=@fopen($file,'c+');
    if($fh){@flock($fh,LOCK_EX);rewind($fh);$row=json_decode((string)stream_get_contents($fh),true);$count=(int)($row['count']??0)+1;ftruncate($fh,0);rewind($fh);fwrite($fh,json_encode(array('count'=>$count,'expires'=>time()+120)));fflush($fh);@flock($fh,LOCK_UN);fclose($fh);}else{$count=1;}
    return array('allowed'=>$count<=$limit,'limit'=>$limit,'remaining'=>max(0,$limit-$count),'reset'=>strtotime('+1 minute',strtotime(date('Y-m-d H:i:00'))));
}

function devone_api_table_columns($table) {
    $out = array();
    $stmt = db()->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($stmt->fetchAll() as $col) { $out[$col['Field']] = $col; }
    return $out;
}

function devone_api_field_is_secret($field) {
    $field=strtolower((string)$field);
    if(in_array($field,array('password','permissions_override','key_hash','secret','token','private_key','api_key','access_token','refresh_token','smtp_password'),true))return true;
    return (bool)preg_match('/(?:^|_)(?:password|passwd|secret|private_key|access_token|refresh_token|key_hash)(?:$|_)/i',$field);
}
function devone_api_safe_fields($endpoint, $columns, $write = false) {
    $configured=devone_api_json_decode($write?($endpoint['writable_fields']??''):($endpoint['allowed_fields']??''),array());
    $available=array_keys((array)$columns);$fields=$configured?:$available;$out=array();
    foreach($fields as$field){$field=preg_replace('/[^a-zA-Z0-9_]/','',(string)$field);if($field===''||!in_array($field,$available,true)||devone_api_field_is_secret($field))continue;$out[]=$field;}
    return array_values(array_unique($out));
}

function devone_api_filter_payload($input, $fields) {
    $out = array();
    foreach ($fields as $field) { if (array_key_exists($field, $input)) { $out[$field] = is_array($input[$field]) ? json_encode($input[$field]) : $input[$field]; } }
    return $out;
}

function devone_api_apply_cors($endpoint) {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') { return; }
    $allowed = devone_api_clean_list($endpoint['cors_origins'] ?? '');
    if (in_array('*', $allowed, true)) { header('Access-Control-Allow-Origin: *'); }
    elseif (in_array($origin, $allowed, true)) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); }
    else { return; }
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
    header('Access-Control-Max-Age: 600');
}

function devone_api_log_request($endpointId, $keyId, $method, $path, $status, $started, $requestId, $details = '') {
    try {
        $duration = (int)round((microtime(true) - $started) * 1000);
        $tbl = devone_api_table('api_request_logs');
        $stmt = db()->prepare("INSERT INTO `{$tbl}` (endpoint_id,api_key_id,method,path,status_code,ip_address,duration_ms,request_id,details) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute(array($endpointId ?: null, $keyId ?: null, $method, $path, $status, devone_api_client_ip(), $duration, $requestId, substr((string)$details,0,2000)));
    } catch (Exception $e) {}
}

function devone_api_clean_path($path) {
    $segments = array_filter(explode('/', trim((string)$path, '/')), 'strlen');
    $safe = array();
    foreach ($segments as $segment) { $safe[] = preg_replace('/[^a-zA-Z0-9._-]/', '', $segment); }
    return implode('/', $safe);
}
