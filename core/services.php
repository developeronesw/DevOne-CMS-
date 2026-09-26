<?php
/**
 * Developer One CMS Core Services
 *
 * Stable, discoverable service API for plugins, themes, modules and libraries.
 * Existing procedural helpers remain supported and are used internally by the
 * services so older packages continue to run without modification.
 *
 * @since 1.2.7
 */

if (defined('DEVONE_CORE_SERVICES_LOADED')) { return; }
define('DEVONE_CORE_SERVICES_LOADED', true);

class DevOne_Service_Exception extends RuntimeException {}

abstract class DevOne_Abstract_Service {
    protected function loadCoreFile($file) {
        $path = __DIR__ . '/' . ltrim((string)$file, '/');
        if (is_file($path)) { require_once $path; return true; }
        return false;
    }

    protected function unavailable($capability) {
        throw new DevOne_Service_Exception('DevOne Core capability is unavailable: ' . (string)$capability);
    }
}

final class DevOne_Settings_Service extends DevOne_Abstract_Service {
    public function get($key, $default = null) { return function_exists('get_setting') ? get_setting($key, $default) : $default; }
    public function set($key, $value) { return function_exists('set_setting') ? set_setting($key, $value) : false; }
    public function all() { return function_exists('devone_all_settings') ? devone_all_settings() : array(); }
    public function has($key) {
        $marker = new stdClass();
        return $this->get($key, $marker) !== $marker;
    }
    public function getBool($key, $default = false) {
        $value = $this->get($key, $default ? '1' : '0');
        return in_array(strtolower(trim((string)$value)), array('1','true','yes','on','enabled'), true);
    }
    public function getInt($key, $default = 0) { return (int)$this->get($key, $default); }
    public function getJson($key, $default = array()) {
        $value = $this->get($key, '');
        if (is_array($value)) { return $value; }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : $default;
    }
    public function setJson($key, $value) { return $this->set($key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}

final class DevOne_Assets_Service extends DevOne_Abstract_Service {
    private function ensure() { if (!function_exists('devone_media_upload')) { $this->loadCoreFile('asset-manager.php'); } }
    public function query($folder = '', $ownerOnly = false, $ownerUserId = 0) { $this->ensure(); return devone_media_query($folder,$ownerOnly,$ownerUserId); }
    public function search($args = array()) { $this->ensure(); return devone_asset_search($args); }
    public function find($id) { $this->ensure(); return devone_asset_public_record(devone_asset_record((int)$id)); }
    public function folders() { $this->ensure(); return devone_list_media_folders(); }
    public function ensureFolders() { $this->ensure(); return devone_ensure_media_folders(); }
    public function typeFromFile($name, $mime = '') { $this->ensure(); return devone_media_type_from_file($name,$mime); }
    public function insert($data) { $this->ensure(); return devone_insert_media_record((array)$data); }
    public function upload($file, $options = array()) { $this->ensure(); return devone_media_upload($file,$options); }
    public function update($id,$data=array()) { $this->ensure(); return devone_asset_update($id,$data); }
    public function replace($id,$file,$options=array()) { $this->ensure(); return devone_asset_replace($id,$file,$options); }
    public function delete($id,$actorUserId=0,$canDeleteAll=false) { $this->ensure(); return devone_delete_media_record((int)$id,(int)$actorUserId,(bool)$canDeleteAll); }
    public function attach($id,$contextType,$contextId='',$label='') { $this->ensure(); return devone_asset_attach($id,$contextType,$contextId,$label); }
    public function detach($id,$contextType='',$contextId='') { $this->ensure(); return devone_asset_detach($id,$contextType,$contextId); }
    public function usage($id) { $this->ensure(); return devone_asset_usage($id); }
    public function duplicates($id) { $this->ensure(); return devone_asset_duplicates($id); }
    public function baseDir() { $this->ensure(); return devone_media_base_dir(); }
    public function url($record, $default = '') { $this->ensure(); $r=is_numeric($record)?$this->find((int)$record):$record; return is_array($r)&&!empty($r['url'])?(string)$r['url']:$default; }
}

final class DevOne_Mail_Service extends DevOne_Abstract_Service {
    private function ensure() { if (!function_exists('devone_mail')) { $this->loadCoreFile('mailer.php'); } }
    public function send($to, $subject, $message, $headers = array(), $attachments = array()) {
        $this->ensure();
        return function_exists('devone_mail') ? devone_mail($to, $subject, $message, $headers, $attachments) : false;
    }
    public function diagnostics() { $this->ensure(); return function_exists('devone_mail_diagnostics') ? devone_mail_diagnostics() : array('ok'=>false); }
    public function wrapHtml($title, $bodyHtml) { $this->ensure(); return function_exists('devone_mail_wrap_html') ? devone_mail_wrap_html($title, $bodyHtml) : (string)$bodyHtml; }
}

final class DevOne_Users_Service extends DevOne_Abstract_Service {
    private function ensureSecurity() { if (!function_exists('devone_current_user')) { $this->loadCoreFile('security.php'); } }
    public function current() { $this->ensureSecurity(); return function_exists('devone_current_user') ? devone_current_user() : null; }
    public function currentId() { $this->ensureSecurity(); return function_exists('devone_current_user_id') ? (int)devone_current_user_id() : 0; }
    public function find($id) { return function_exists('devone_get_user_by_id') ? devone_get_user_by_id((int)$id) : null; }
    public function all() { return function_exists('devone_list_users') ? devone_list_users() : array(); }
    public function roles() { return function_exists('devone_list_roles') ? devone_list_roles() : array(); }
    public function can($permission, $user = null) { $this->ensureSecurity(); return function_exists('devone_has_permission') && devone_has_permission($permission, $user); }
    public function requirePermission($permission) { $this->ensureSecurity(); return function_exists('devone_require_permission') ? devone_require_permission($permission) : false; }
}

final class DevOne_Auth_Service extends DevOne_Abstract_Service {
    private function ensure() { if (!function_exists('devone_current_user')) { $this->loadCoreFile('security.php'); } }
    public function user() { $this->ensure(); return function_exists('devone_current_user') ? devone_current_user() : null; }
    public function id() { $this->ensure(); return function_exists('devone_current_user_id') ? (int)devone_current_user_id() : 0; }
    public function check() { return $this->id() > 0; }
    public function can($permission) { $this->ensure(); return function_exists('devone_has_permission') && devone_has_permission($permission); }
    public function requirePermission($permission) { $this->ensure(); return function_exists('devone_require_permission') ? devone_require_permission($permission) : false; }
    public function csrfToken() { $this->ensure(); return function_exists('csrf_token') ? csrf_token() : ''; }
    public function csrfField() { $this->ensure(); return function_exists('csrf_field') ? csrf_field() : ''; }
    public function verifyCsrf($token = null) { $this->ensure(); return function_exists('devone_verify_csrf_token') ? devone_verify_csrf_token($token) : false; }
    public function requireCsrf() { $this->ensure(); return function_exists('verify_csrf') ? verify_csrf() : false; }
}

final class DevOne_Cache_Service extends DevOne_Abstract_Service {
    private function ensure() { if (!function_exists('devone_cache_get')) { $this->loadCoreFile('cache.php'); } }
    public function get($key, $default = null) { $this->ensure(); return function_exists('devone_cache_get') ? devone_cache_get($key, $default) : $default; }
    public function set($key, $value, $ttl = null) { $this->ensure(); return function_exists('devone_cache_set') ? devone_cache_set($key, $value, $ttl) : false; }
    public function delete($key) { $this->ensure(); return function_exists('devone_cache_delete') ? devone_cache_delete($key) : false; }
    public function remember($key, $callback, $ttl = null) { $this->ensure(); return function_exists('devone_cache_remember') ? devone_cache_remember($key, $callback, $ttl) : call_user_func($callback); }
    public function flush() { $this->ensure(); return function_exists('devone_cache_flush') ? devone_cache_flush() : false; }
    public function stats() { $this->ensure(); return function_exists('devone_cache_stats') ? devone_cache_stats() : array(); }
}

final class DevOne_Events_Service extends DevOne_Abstract_Service {
    public function on($hook, $callback, $priority = 10) { return function_exists('add_action') ? add_action($hook, $callback, $priority) : false; }
    public function listen($hook, $callback, $priority = 10) { return $this->on($hook, $callback, $priority); }
    public function emit($hook) {
        $args = func_get_args();
        return function_exists('do_action') ? call_user_func_array('do_action', $args) : null;
    }
    public function filter($hook, $callback, $priority = 10) { return function_exists('add_filter') ? add_filter($hook, $callback, $priority) : false; }
    public function apply($hook, $value) {
        $args = func_get_args();
        return function_exists('apply_filters') ? call_user_func_array('apply_filters', $args) : $value;
    }
}

final class DevOne_Database_Service extends DevOne_Abstract_Service {
    public function connection() { return function_exists('db') ? db() : $this->unavailable('database'); }
    public function table($name) { return function_exists('table_name') ? table_name($name) : (string)$name; }
    public function exists($name) { return function_exists('table_exists') ? table_exists($name) : false; }
    public function transaction($callback) {
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $result = call_user_func($callback, $pdo, $this);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        }
    }
}

final class DevOne_API_Service extends DevOne_Abstract_Service {
    private function ensure() { if (!function_exists('devone_api_clean_path')) { $this->loadCoreFile('api_runtime.php'); } }
    public function cleanPath($path) { $this->ensure(); return function_exists('devone_api_clean_path') ? devone_api_clean_path($path) : trim((string)$path, '/'); }
    public function input() { $this->ensure(); return function_exists('devone_api_input') ? devone_api_input() : array(); }
    public function respond($status, $payload, $requestId = '') { $this->ensure(); return function_exists('devone_api_respond') ? devone_api_respond($status, $payload, $requestId) : null; }
    public function bearerToken() { $this->ensure(); return function_exists('devone_api_bearer_token') ? devone_api_bearer_token() : ''; }
}

final class DevOne_Notifications_Service extends DevOne_Abstract_Service {
    private $sessionKey = 'devone_core_notifications';
    private function start() {
        if (function_exists('devone_start_session')) { devone_start_session(); }
        elseif (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) { @session_start(); }
    }
    public function add($type, $message, $context = array()) {
        $this->start();
        if (session_status() !== PHP_SESSION_ACTIVE) { return false; }
        if (!isset($_SESSION[$this->sessionKey]) || !is_array($_SESSION[$this->sessionKey])) { $_SESSION[$this->sessionKey] = array(); }
        $_SESSION[$this->sessionKey][] = array(
            'type'=>preg_replace('/[^a-z0-9_-]/i','',(string)$type) ?: 'info',
            'message'=>(string)$message,
            'context'=>(array)$context,
            'created_at'=>gmdate('c'),
        );
        return true;
    }
    public function success($message, $context = array()) { return $this->add('success', $message, $context); }
    public function error($message, $context = array()) { return $this->add('error', $message, $context); }
    public function warning($message, $context = array()) { return $this->add('warning', $message, $context); }
    public function info($message, $context = array()) { return $this->add('info', $message, $context); }
    public function all() { $this->start(); return (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[$this->sessionKey])) ? $_SESSION[$this->sessionKey] : array(); }
    public function consume() { $items = $this->all(); if (session_status() === PHP_SESSION_ACTIVE) { unset($_SESSION[$this->sessionKey]); } return $items; }
}

final class DevOne_Search_Service extends DevOne_Abstract_Service {
    public function pages($term, $limit = 20) {
        $term = trim((string)$term); if ($term === '' || !function_exists('db') || !function_exists('table_name')) { return array(); }
        $table = table_name('pages');
        $stmt = db()->prepare("SELECT id,title,slug,status FROM `{$table}` WHERE title LIKE ? OR slug LIKE ? ORDER BY title ASC LIMIT " . max(1, min(100, (int)$limit)));
        $needle = '%' . $term . '%'; $stmt->execute(array($needle,$needle));
        return $stmt->fetchAll();
    }
    public function media($term, $limit = 50) {
        $records = DevOne::assets()->query(); $term = strtolower(trim((string)$term));
        if ($term === '') { return array_slice($records, 0, max(1,(int)$limit)); }
        $matched = array_filter($records, function($record) use ($term) {
            $haystack = strtolower(implode(' ', array_map('strval', array_intersect_key((array)$record, array_flip(array('name','filename','title','alt_text','folder','mime_type','type'))))));
            return strpos($haystack, $term) !== false;
        });
        return array_slice(array_values($matched), 0, max(1,(int)$limit));
    }
}

final class DevOne_Log_Service extends DevOne_Abstract_Service {
    public function write($action, $details = '') { return function_exists('devone_log') ? devone_log($action, $details) : false; }
}

final class DevOne_Service_Container {
    private $instances = array();
    private $factories = array();
    private $aliases = array();
    private $booting = true;
    private $reserved = array('apps','settings','assets','mail','users','auth','cache','events','db','api','notifications','search','log','media','database','mailer','hooks','notify');

    public function __construct() {
        $this->singleton('apps', function(){ if (is_file(__DIR__ . '/apps.php')) { require_once __DIR__ . '/apps.php'; } return new DevOne_Apps_Service(); });
        $this->singleton('settings', function(){ return new DevOne_Settings_Service(); });
        $this->singleton('runtime', function(){ if (is_file(__DIR__ . '/runtime.php')) { require_once __DIR__ . '/runtime.php'; } return devone_runtime(); });
        $this->singleton('assets', function(){ return new DevOne_Assets_Service(); });
        $this->singleton('mail', function(){ return new DevOne_Mail_Service(); });
        $this->singleton('users', function(){ return new DevOne_Users_Service(); });
        $this->singleton('auth', function(){ return new DevOne_Auth_Service(); });
        $this->singleton('cache', function(){ return new DevOne_Cache_Service(); });
        $this->singleton('events', function(){ return new DevOne_Events_Service(); });
        $this->singleton('db', function(){ return new DevOne_Database_Service(); });
        $this->singleton('api', function(){ return new DevOne_API_Service(); });
        $this->singleton('notifications', function(){ return new DevOne_Notifications_Service(); });
        $this->singleton('search', function(){ return new DevOne_Search_Service(); });
        $this->singleton('log', function(){ return new DevOne_Log_Service(); });
        $this->alias('media', 'assets');
        $this->alias('database', 'db');
        $this->alias('mailer', 'mail');
        $this->alias('hooks', 'events');
        $this->alias('notify', 'notifications');
        $this->booting = false;
    }

    public function singleton($name, $factory) {
        $name = $this->clean($name);
        $this->assertRegistrationAllowed($name);
        $this->factories[$name] = $factory;
        return $this;
    }
    public function instance($name, $instance) {
        $name = $this->clean($name);
        $this->assertRegistrationAllowed($name);
        if (!is_object($instance)) { throw new DevOne_Service_Exception('Custom services must be objects.'); }
        $this->instances[$name] = $instance;
        return $this;
    }
    public function alias($alias, $target) {
        $alias = $this->clean($alias); $target = $this->clean($target);
        if ($alias === '' || $target === '') { throw new DevOne_Service_Exception('Service aliases require valid names.'); }
        if (!$this->booting && in_array($alias, $this->reserved, true) && !isset($this->aliases[$alias])) { throw new DevOne_Service_Exception('Official DevOne Core Services cannot be replaced or re-aliased.'); }
        $this->aliases[$alias] = $target;
        return $this;
    }
    public function has($name) { $name = $this->resolve($name); return isset($this->instances[$name]) || isset($this->factories[$name]); }
    public function get($name) {
        $name = $this->resolve($name);
        if (isset($this->instances[$name])) { return $this->instances[$name]; }
        if (!isset($this->factories[$name])) { throw new DevOne_Service_Exception('Unknown DevOne Core Service: ' . $name); }
        $factory = $this->factories[$name];
        $this->instances[$name] = is_callable($factory) ? call_user_func($factory, $this) : $factory;
        return $this->instances[$name];
    }
    public function names() { return array_values(array_unique(array_merge(array_keys($this->factories), array_keys($this->instances), array_keys($this->aliases)))); }
    private function clean($name) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)$name)); }
    private function assertRegistrationAllowed($name) {
        if ($name === '') { throw new DevOne_Service_Exception('Service name is required.'); }
        if (!$this->booting && in_array($name, $this->reserved, true)) {
            throw new DevOne_Service_Exception('Official DevOne Core Services cannot be replaced: ' . $name);
        }
        if (!$this->booting && strpos($name, '-') === false && strpos($name, '_') === false) {
            throw new DevOne_Service_Exception('Third-party service names must use a unique vendor prefix, for example acme-reports.');
        }
    }
    private function resolve($name) { $name = $this->clean($name); return isset($this->aliases[$name]) ? $this->aliases[$name] : $name; }
}

final class DevOne {
    private static $container;
    public static function services() { if (!self::$container) { self::$container = new DevOne_Service_Container(); } return self::$container; }
    public static function service($name) { return self::services()->get($name); }
    public static function apps() { return self::service('apps'); }
    public static function settings() { return self::service('settings'); }
    public static function runtime() { return self::service('runtime'); }
    public static function assets() { return self::service('assets'); }
    public static function mail() { return self::service('mail'); }
    public static function users() { return self::service('users'); }
    public static function auth() { return self::service('auth'); }
    public static function cache() { return self::service('cache'); }
    public static function events() { return self::service('events'); }
    public static function db() { return self::service('db'); }
    public static function api() { return self::service('api'); }
    public static function notifications() { return self::service('notifications'); }
    public static function search() { return self::service('search'); }
    public static function log() { return self::service('log'); }
}

function devone_services() { return DevOne::services(); }
function devone_service($name) { return DevOne::service($name); }

/**
 * Returns the compatibility map between established helpers and Core Services.
 * This is informational and is used by diagnostics and developer tooling.
 */
function devone_core_service_legacy_map() {
    return array(
        'apps'=>array('devone_app_manifest','devone_app_storage_path','devone_app_match_active','devone_app_sync_disk'),
        'settings'=>array('get_setting','set_setting','devone_all_settings'),
        'assets'=>array('devone_media_upload','devone_asset_search','devone_asset_record','devone_asset_update','devone_asset_replace','devone_asset_attach','devone_asset_usage','devone_media_query','devone_list_media_folders','devone_insert_media_record','devone_delete_media_record','devone_media_type_from_file'),
        'mail'=>array('devone_mail','devone_mail_diagnostics','devone_mail_wrap_html'),
        'users'=>array('devone_current_user','devone_current_user_id','devone_get_user_by_id','devone_list_users','devone_list_roles'),
        'auth'=>array('devone_has_permission','devone_require_permission','csrf_token','csrf_field'),
        'cache'=>array('devone_cache_get','devone_cache_set','devone_cache_delete','devone_cache_remember','devone_cache_flush'),
        'events'=>array('add_action','do_action','add_filter','apply_filters'),
        'db'=>array('db','table_name','table_exists'),
        'api'=>array('devone_api_clean_path','devone_api_input','devone_api_respond','devone_api_bearer_token'),
        'log'=>array('devone_log'),
    );
}

function devone_core_services_status() {
    $status = array('loaded'=>true,'services'=>array(),'legacy'=>array());
    foreach (DevOne::services()->names() as $name) { $status['services'][$name] = DevOne::services()->has($name); }
    foreach (devone_core_service_legacy_map() as $service=>$helpers) {
        foreach ($helpers as $helper) { $status['legacy'][$helper] = function_exists($helper); }
    }
    return $status;
}

if (is_file(__DIR__ . '/theme_entitlements.php')) { require_once __DIR__ . '/theme_entitlements.php'; }
