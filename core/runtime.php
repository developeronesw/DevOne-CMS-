<?php
/**
 * DevOne Runtime Loader
 *
 * Backward-compatible request-aware runtime registry for plugins, themes and modules.
 * Legacy extensions continue to load normally. Optimized extensions may defer
 * frontend/admin/route/shortcode/API/background components until the matching
 * request actually needs them.
 *
 * @since 1.7.0
 * @updated 1.7.1 Admin Runtime
 */
if (defined('DEVONE_RUNTIME_LOADER_LOADED')) { return; }
define('DEVONE_RUNTIME_LOADER_LOADED', true);

final class DevOne_Runtime_Service {
    private $callbacks = array();
    private $adminCallbacks = array();
    private $shortcodes = array();
    private $routes = array();
    private $ran = array();

    public function context() {
        if (PHP_SAPI === 'cli') { return 'cli'; }
        if (defined('DEVONE_BACKGROUND_REQUEST') && DEVONE_BACKGROUND_REQUEST) { return 'background'; }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        if (strpos($path, '/admin/') !== false || preg_match('#/admin$#', $path)) { return 'admin'; }
        if (basename($path) === 'api.php' || !empty($_GET['ajax']) || strpos($path, '/api/') !== false) { return 'api'; }
        return 'frontend';
    }

    public function is($context) { return $this->context() === strtolower((string)$context); }

    public function adminPage() {
        if ($this->context() !== 'admin') { return ''; }
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $page = preg_replace('/\.php$/i', '', $script);
        $page = strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', (string)$page), '-'));
        return $page ?: 'dashboard';
    }

    public function adminPlugin() {
        if ($this->context() !== 'admin') { return ''; }
        return strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', (string)($_GET['plugin'] ?? '')), '-'));
    }

    public function adminPluginPage() {
        if ($this->context() !== 'admin') { return ''; }
        return strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', (string)($_GET['page'] ?? '')), '-'));
    }

    public function defer($context, $callback) {
        $context = strtolower(trim((string)$context));
        if ($context === '') { $context = 'frontend'; }
        $this->callbacks[$context][] = $callback;
        return $this;
    }

    public function frontend($callback) { return $this->defer('frontend', $callback); }
    public function api($callback) { return $this->defer('api', $callback); }
    public function background($callback) { return $this->defer('background', $callback); }
    public function cli($callback) { return $this->defer('cli', $callback); }

    /**
     * Register admin runtime work.
     *
     * Backward-compatible forms:
     *   admin($callback)                      - every admin request
     *   admin('pages', $callback)             - admin/pages.php only
     *   admin(['pages','media'], $callback)   - named Core admin pages
     *   admin('plugin:slug', $callback)       - any page owned by plugin slug
     *   admin('plugin:slug/page', $callback)  - one plugin admin page
     */
    public function admin($pagesOrCallback, $callback = null) {
        if ($callback === null && is_callable($pagesOrCallback)) {
            $this->adminCallbacks[] = array('targets'=>array('*'), 'callback'=>$pagesOrCallback);
            return $this;
        }
        if ($callback === null && is_string($pagesOrCallback) && is_file($pagesOrCallback)) {
            $this->adminCallbacks[] = array('targets'=>array('*'), 'callback'=>$pagesOrCallback);
            return $this;
        }
        $targets = is_array($pagesOrCallback) ? $pagesOrCallback : array($pagesOrCallback);
        $clean = array();
        foreach ($targets as $target) {
            $target = strtolower(trim((string)$target));
            if ($target === '') { continue; }
            $clean[] = preg_replace('/[^a-z0-9\-_:\/\*]+/i', '', $target);
        }
        if (!$clean) { $clean = array('*'); }
        $this->adminCallbacks[] = array('targets'=>$clean, 'callback'=>$callback);
        return $this;
    }

    public function route($path, $callback, $contexts = array('frontend','api')) {
        $this->routes[] = array('path'=>(string)$path, 'callback'=>$callback, 'contexts'=>(array)$contexts);
        return $this;
    }

    public function shortcode($tag, $callback) {
        $tag = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$tag);
        if ($tag !== '') { $this->shortcodes[$tag][] = $callback; }
        return $this;
    }

    private function runCallback($callback, $key = '') {
        if ($key !== '' && !empty($this->ran[$key])) { return; }
        if ($key !== '') { $this->ran[$key] = true; }
        if (is_string($callback) && is_file($callback)) { require_once $callback; return; }
        if (is_callable($callback)) { call_user_func($callback); }
    }

    private function adminTargetMatches($target) {
        $target = strtolower(trim((string)$target));
        if ($target === '*' || $target === 'admin') { return true; }
        $page = $this->adminPage();
        $plugin = $this->adminPlugin();
        $pluginPage = $this->adminPluginPage();
        if (strpos($target, 'plugin:') === 0) {
            $spec = substr($target, 7);
            $parts = explode('/', $spec, 2);
            if (($parts[0] ?? '') !== $plugin) { return false; }
            return empty($parts[1]) || $parts[1] === '*' || $parts[1] === $pluginPage;
        }
        return $target === $page;
    }

    public function bootContext() {
        $context = $this->context();
        foreach ($this->callbacks[$context] ?? array() as $i=>$callback) {
            $this->runCallback($callback, 'context:' . $context . ':' . $i);
        }
        if ($context === 'admin') {
            foreach ($this->adminCallbacks as $i=>$entry) {
                foreach ((array)($entry['targets'] ?? array('*')) as $target) {
                    if ($this->adminTargetMatches($target)) {
                        $this->runCallback($entry['callback'], 'admin:' . $i);
                        break;
                    }
                }
            }
        }
        $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        foreach ($this->routes as $i=>$route) {
            if (!in_array($context, $route['contexts'], true)) { continue; }
            $pattern = (string)$route['path'];
            $match = $pattern === $requestPath || ($pattern !== '' && substr($pattern, -1) === '*' && strpos($requestPath, rtrim($pattern, '*')) === 0);
            if ($match) { $this->runCallback($route['callback'], 'route:' . $i); }
        }
    }

    public function prepareContent($content) {
        $content = (string)$content;
        if ($content === '' || empty($this->shortcodes)) { return $content; }
        foreach ($this->shortcodes as $tag=>$callbacks) {
            if (stripos($content, '[' . $tag) === false) { continue; }
            foreach ($callbacks as $i=>$callback) { $this->runCallback($callback, 'shortcode:' . $tag . ':' . $i); }
        }
        return $content;
    }
}

function devone_runtime() {
    static $runtime = null;
    if (!$runtime) { $runtime = new DevOne_Runtime_Service(); }
    return $runtime;
}
function devone_runtime_context() { return devone_runtime()->context(); }
function devone_runtime_is($context) { return devone_runtime()->is($context); }
function devone_runtime_prepare_content($content) { return devone_runtime()->prepareContent($content); }
function devone_runtime_boot_context() { devone_runtime()->bootContext(); }
