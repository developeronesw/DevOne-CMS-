<?php
if (is_file(__DIR__ . '/cache.php')) { require_once __DIR__ . '/cache.php'; }
function devone_installed() { return file_exists(__DIR__ . '/../config.php') && defined('DEVONE_INSTALLED') && DEVONE_INSTALLED; }

function devone_sql_table($base) {
    return '`' . str_replace('`', '', table_name($base)) . '`';
}

function devone_require_table($base, $repair = true) {
    if (table_exists($base)) { return table_name($base); }
    if ($repair && function_exists('devone_repair_core_schema')) {
        devone_repair_core_schema(true);
        if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
    }
    return table_exists($base) ? table_name($base) : '';
}

function devone_all_settings($force = false) {
    static $settings = null;
    if (!$force && is_array($settings)) { return $settings; }
    $tbl = devone_require_table('settings', false);
    if ($tbl === '') { $settings = array(); return $settings; }

    $cacheKey = 'all-settings-' . (defined('DB_NAME') ? DB_NAME : 'default') . '-' . table_name('settings');
    if (!$force && function_exists('devone_cache_get')) {
        $cached = devone_cache_get('settings', $cacheKey, null);
        if (is_array($cached)) { $settings = $cached; return $settings; }
    }

    $settings = array();
    try {
        $stmt = db()->query('SELECT setting_key, setting_value FROM `' . $tbl . '`');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['setting_key'])) { $settings[(string)$row['setting_key']] = $row['setting_value']; }
        }
        if (function_exists('devone_cache_set')) { devone_cache_set('settings', $cacheKey, $settings, 300); }
    } catch (Throwable $e) { $settings = array(); }
    return $settings;
}

function devone_settings_cache_reset() {
    static $dummy = null;
    $dummy = null;
    if (function_exists('devone_cache_flush')) { devone_cache_flush('settings'); }
}

function devone_site_local_setting_keys() {
    // Presentation/content settings may vary by Network site. Security, licensing,
    // Marketplace, SMTP, cache, debug, and Network configuration remain installation-wide.
    return array(
        'site_name','site_title','site_tagline','site_logo','site_theme','footer_text',
        'primary_menu','menu_layout','menu_style','site_width_mode','permalink_style',
        'show_admin_link_frontend','frontend_scripts_enabled','frontend_ajax_enabled',
        'frontend_custom_css','frontend_head_code','frontend_footer_js','frontend_page_ready_js'
    );
}

function devone_setting_site_context_id() {
    if (!function_exists('devone_network_enabled') || !devone_network_enabled()) { return 1; }
    if (function_exists('devone_admin_current_site_id') && php_sapi_name() !== 'cli' && strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false) {
        return max(1, (int)devone_admin_current_site_id());
    }
    if (function_exists('devone_current_site_id')) { return max(1, (int)devone_current_site_id()); }
    return 1;
}

function devone_site_settings_all($siteId, $force = false) {
    static $settingsBySite = array();
    $siteId = max(1, (int)$siteId);
    if ($siteId <= 1) { return array(); }
    if (!$force && isset($settingsBySite[$siteId]) && is_array($settingsBySite[$siteId])) { return $settingsBySite[$siteId]; }
    $tbl = devone_require_table('site_settings', false);
    if ($tbl === '') { $settingsBySite[$siteId] = array(); return array(); }
    $cacheKey = 'site-settings-' . (defined('DB_NAME') ? DB_NAME : 'default') . '-' . $siteId;
    if (!$force && function_exists('devone_cache_get')) {
        $cached = devone_cache_get('settings', $cacheKey, null);
        if (is_array($cached)) { $settingsBySite[$siteId] = $cached; return $cached; }
    }
    $settings = array();
    try {
        $stmt = db()->prepare('SELECT setting_key, setting_value FROM `' . $tbl . '` WHERE site_id=?');
        $stmt->execute(array($siteId));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['setting_key'])) { $settings[(string)$row['setting_key']] = $row['setting_value']; }
        }
        if (function_exists('devone_cache_set')) { devone_cache_set('settings', $cacheKey, $settings, 300); }
    } catch (Throwable $e) { $settings = array(); }
    $settingsBySite[$siteId] = $settings;
    return $settings;
}

function get_setting($key, $default = null) {
    $key = (string)$key;
    $global = devone_all_settings(false);
    $value = array_key_exists($key, $global) ? $global[$key] : $default;
    if (in_array($key, devone_site_local_setting_keys(), true)) {
        $siteId = devone_setting_site_context_id();
        if ($siteId > 1) {
            $site = devone_site_settings_all($siteId, false);
            if (array_key_exists($key, $site)) { return $site[$key]; }
        }
    }
    return $value;
}

function set_setting($key, $value) {
    $key = (string)$key;
    if (in_array($key, devone_site_local_setting_keys(), true)) {
        $siteId = devone_setting_site_context_id();
        if ($siteId > 1) {
            $tbl = devone_require_table('site_settings', true);
            if ($tbl === '') { return false; }
            $sql = 'INSERT INTO `' . $tbl . '` (site_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
            $stmt = db()->prepare($sql);
            $ok = $stmt->execute(array($siteId, $key, $value));
            if ($ok) {
                if (function_exists('devone_cache_flush')) { devone_cache_flush('settings'); }
                devone_site_settings_all($siteId, true);
            }
            return $ok;
        }
    }
    $tbl = devone_require_table('settings', true);
    if ($tbl === '') return false;
    $sql = 'INSERT INTO `' . $tbl . '` (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
    $stmt = db()->prepare($sql);
    $ok = $stmt->execute(array($key, $value));
    if ($ok) {
        if (function_exists('devone_cache_flush')) { devone_cache_flush('settings'); }
        devone_all_settings(true);
    }
    return $ok;
}

function devone_slugify($value, $fallback = 'item') {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : $fallback;
}

function devone_content_site_id() {
    if (function_exists('devone_admin_current_site_id') && php_sapi_name() !== 'cli' && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false) {
        return max(1, (int)devone_admin_current_site_id());
    }
    if (function_exists('devone_current_site_id')) { return max(1, (int)devone_current_site_id()); }
    return 1;
}

function devone_table_has_site_id($base) {
    return function_exists('devone_table_columns') && in_array('site_id', devone_table_columns($base), true);
}

function devone_request_path() {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = parse_url(defined('SITE_URL') ? SITE_URL : '/', PHP_URL_PATH) ?: '/';
    $base = rtrim($base, '/');
    if ($base !== '' && $base !== '/' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . ltrim((string)$path, '/');
    return $path === '//' ? '/' : $path;
}

function devone_current_slug() {
    $slug = $_GET['page'] ?? '';
    if ($slug !== '') { return devone_slugify($slug, 'home'); }
    $path = trim(devone_request_path(), '/');
    if ($path === '' || $path === 'index.php' || $path === 'install.php') { return 'home'; }
    $parts = array_values(array_filter(explode('/', $path), 'strlen'));
    if (!$parts) { return 'home'; }
    if (count($parts) >= 2 && $parts[0] === 'page') { return devone_slugify($parts[1], 'home'); }
    return devone_slugify(end($parts), 'home');
}

function devone_permalink_style() {
    $style = (string)get_setting('permalink_style', 'clean');
    return in_array($style, array('clean','page-prefix','legacy'), true) ? $style : 'clean';
}

function devone_reserved_page_slugs() {
    return array('admin','api','assets','uploads','content','plugins','themes','install','install.php','index.php','page');
}

function devone_is_reserved_page_slug($slug) {
    $slug = devone_slugify($slug, '');
    return $slug !== '' && in_array($slug, devone_reserved_page_slugs(), true);
}

function get_page($slug) {
    $tbl = devone_require_table('pages', false);
    if ($tbl === '') return null;
    $cleanSlug = devone_slugify($slug, 'home');
    if (devone_table_has_site_id('pages')) {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE site_id = ? AND slug = ? AND status = ? LIMIT 1');
        $stmt->execute([devone_content_site_id(), $cleanSlug, 'published']);
    } else {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE slug = ? AND status = ? LIMIT 1');
        $stmt->execute([$cleanSlug, 'published']);
    }
    return $stmt->fetch() ?: null;
}

function get_page_any_status($slug) {
    $tbl = devone_require_table('pages', false);
    if ($tbl === '') return null;
    $cleanSlug = devone_slugify($slug, 'home');
    if (devone_table_has_site_id('pages')) {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE site_id = ? AND slug = ? LIMIT 1');
        $stmt->execute([devone_content_site_id(), $cleanSlug]);
    } else {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE slug = ? LIMIT 1');
        $stmt->execute([$cleanSlug]);
    }
    return $stmt->fetch() ?: null;
}

function get_home_page() {
    $tbl = devone_require_table('pages', true);
    if ($tbl === '') return null;
    $page = get_page('home');
    if ($page) return $page;
    if (devone_table_has_site_id('pages')) {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . "` WHERE site_id=? AND status='published' ORDER BY id ASC LIMIT 1");
        $stmt->execute([devone_content_site_id()]);
        $page = $stmt->fetch();
        if ($page) return $page;
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE site_id=? ORDER BY id ASC LIMIT 1');
        $stmt->execute([devone_content_site_id()]);
        return $stmt->fetch() ?: null;
    }
    $stmt = db()->query('SELECT * FROM `' . $tbl . "` WHERE status='published' ORDER BY id ASC LIMIT 1");
    $page = $stmt->fetch();
    if ($page) return $page;
    $stmt = db()->query('SELECT * FROM `' . $tbl . '` ORDER BY id ASC LIMIT 1');
    return $stmt->fetch() ?: null;
}

function get_page_by_id($id) {
    $tbl = devone_require_table('pages', true);
    if ($tbl === '') return null;
    if (devone_table_has_site_id('pages')) {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE id = ? AND site_id = ? LIMIT 1');
        $stmt->execute([(int)$id, devone_content_site_id()]);
    } else {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$id]);
    }
    return $stmt->fetch() ?: null;
}

function list_pages($published_only = true) {
    $tbl = devone_require_table('pages', true);
    if ($tbl === '') return [];
    $siteSql = devone_table_has_site_id('pages') ? ' site_id=' . (int)devone_content_site_id() . ' AND ' : ' ';
    if ($published_only) {
        return db()->query('SELECT id, slug, title FROM `' . $tbl . '` WHERE' . $siteSql . "status='published' ORDER BY title")->fetchAll();
    }
    if (devone_table_has_site_id('pages')) {
        return db()->query('SELECT id, slug, title, status FROM `' . $tbl . '` WHERE site_id=' . (int)devone_content_site_id() . ' ORDER BY title')->fetchAll();
    }
    return db()->query('SELECT id, slug, title, status FROM `' . $tbl . '` ORDER BY title')->fetchAll();
}

function save_page($id, $title, $slug, $content, $template = 'default', $status = 'published') {
    $tbl = devone_require_table('pages', true);
    if ($tbl === '') {
        $report = function_exists('devone_table_resolver_report') ? devone_table_resolver_report() : [];
        $found = !empty($report['all_tables']) ? implode(', ', $report['all_tables']) : 'no tables returned from DB';
        return [
            'ok'=>false,
            'message'=>'Pages table could not be resolved in database `' . (defined('DB_NAME') ? DB_NAME : 'unknown') . '`. Expected table like `cms_pages`. Tables seen: ' . $found,
            'id'=>null
        ];
    }

    $id = (int)$id;
    $title = trim((string)$title) ?: 'Untitled';
    $slug = devone_slugify($slug ?: $title, 'page');
    if ($slug !== 'home' && devone_is_reserved_page_slug($slug)) {
        return ['ok'=>false, 'message'=>'The slug `' . $slug . '` is reserved by DevOne Core. Choose another permalink slug.', 'id'=>$id ?: null, 'slug'=>$slug];
    }
    $conflict = get_page_any_status($slug);
    if ($conflict && (int)($conflict['id'] ?? 0) !== $id) {
        return ['ok'=>false, 'message'=>'Another page already uses the permalink slug `' . $slug . '`.', 'id'=>$id ?: null, 'slug'=>$slug];
    }
    $template = devone_slugify($template ?: 'default', 'default');
    $status = in_array($status, ['draft','published'], true) ? $status : 'published';

    try {
        $cols = function_exists('devone_table_columns') ? devone_table_columns('pages') : array();
        $uid = function_exists('devone_current_user_id') ? devone_current_user_id() : 0;
        $siteId = in_array('site_id', $cols, true) ? devone_content_site_id() : null;
        if ($id > 0) {
            if ($siteId !== null) {
                $stmt = db()->prepare('UPDATE `' . $tbl . '` SET title=?, slug=?, content=?, template=?, status=? WHERE id=? AND site_id=?');
                $stmt->execute([$title,$slug,$content,$template,$status,$id,$siteId]);
            } else {
                $stmt = db()->prepare('UPDATE `' . $tbl . '` SET title=?, slug=?, content=?, template=?, status=? WHERE id=?');
                $stmt->execute([$title,$slug,$content,$template,$status,$id]);
            }
            return ['ok'=>true, 'message'=>'Page updated in `' . $tbl . '`.', 'id'=>$id, 'slug'=>$slug];
        }

        $existing = get_page_any_status($slug);
        if ($existing && !empty($existing['id'])) {
            $stmt = db()->prepare('UPDATE `' . $tbl . '` SET title=?, content=?, template=?, status=? WHERE slug=?');
            $stmt->execute([$title,$content,$template,$status,$slug]);
            return ['ok'=>true, 'message'=>'Existing page updated in `' . $tbl . '`.', 'id'=>(int)$existing['id'], 'slug'=>$slug];
        }

        if ($siteId !== null && in_array('author_id', $cols, true)) {
            $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (site_id, title, slug, content, template, status, author_id) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$siteId,$title,$slug,$content,$template,$status,$uid ?: null]);
        } elseif ($siteId !== null) {
            $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (site_id, title, slug, content, template, status) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$siteId,$title,$slug,$content,$template,$status]);
        } elseif (in_array('author_id', $cols, true)) {
            $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (title, slug, content, template, status, author_id) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$title,$slug,$content,$template,$status,$uid ?: null]);
        } else {
            $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (title, slug, content, template, status) VALUES (?,?,?,?,?)');
            $stmt->execute([$title,$slug,$content,$template,$status]);
        }
        return ['ok'=>true, 'message'=>'Page created in `' . $tbl . '`.', 'id'=>(int)db()->lastInsertId(), 'slug'=>$slug];
    } catch (Exception $e) {
        return ['ok'=>false, 'message'=>$e->getMessage(), 'id'=>$id ?: null, 'slug'=>$slug];
    }
}

function devone_seed_home_page() {
    $tbl = devone_require_table('pages', true);
    if ($tbl === '') return false;
    $home = <<<'HTML'
<style>
.devone-launch-home{width:100%;overflow:hidden;background:#070711;color:#fff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.devone-launch-hero{position:relative;min-height:86vh;display:grid;place-items:center;padding:96px 20px;isolation:isolate;overflow:hidden}
.devone-launch-hero:before{content:"";position:absolute;inset:-30%;background:radial-gradient(circle at 20% 20%,rgba(255,122,24,.28),transparent 32%),radial-gradient(circle at 78% 28%,rgba(255,45,117,.22),transparent 30%),radial-gradient(circle at 50% 90%,rgba(87,124,255,.18),transparent 35%);z-index:-3;animation:devoneGlow 12s ease-in-out infinite alternate}
.devone-launch-hero:after{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.055) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.055) 1px,transparent 1px);background-size:72px 72px;mask-image:linear-gradient(to bottom,black,transparent 82%);z-index:-2}
.devone-launch-inner{width:min(1240px,100%);margin:auto;text-align:center}
.devone-launch-badge{display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,211,90,.26);color:#ffd98a;font-size:.78rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
.devone-launch-badge span{width:9px;height:9px;border-radius:999px;background:#42ff9b;box-shadow:0 0 20px #42ff9b}
.devone-launch-title{margin:24px auto 18px;max-width:1040px;font-size:clamp(3.2rem,8vw,8.4rem);line-height:.86;letter-spacing:-.08em;font-weight:950}
.devone-launch-title strong{background:linear-gradient(90deg,#fff,#ffd25a,#ff7a18,#ff2d75);-webkit-background-clip:text;background-clip:text;color:transparent}
.devone-launch-lead{max-width:850px;margin:0 auto;color:#f1dfc1;font-size:clamp(1.05rem,2vw,1.35rem);line-height:1.75}
.devone-launch-actions{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;margin-top:34px}
.devone-launch-btn{display:inline-flex;align-items:center;justify-content:center;min-height:54px;padding:0 24px;border-radius:18px;text-decoration:none!important;color:#fff!important;font-weight:950;border:1px solid rgba(255,211,90,.28);transition:.18s ease}
.devone-launch-btn:hover{transform:translateY(-3px)}
.devone-launch-btn.primary{background:linear-gradient(135deg,#ff7a18,#ff2d75);box-shadow:0 20px 60px rgba(255,89,38,.34)}
.devone-launch-btn.ghost{background:rgba(255,255,255,.08)}
.devone-launch-panel{margin:50px auto 0;display:grid;grid-template-columns:repeat(4,1fr);gap:14px;max-width:1080px}
.devone-launch-stat{border-radius:24px;background:rgba(255,255,255,.075);border:1px solid rgba(255,255,255,.1);padding:22px;text-align:left;box-shadow:0 22px 70px rgba(0,0,0,.22)}
.devone-launch-stat b{display:block;font-size:2rem;line-height:1;color:#ffd25a}.devone-launch-stat span{display:block;margin-top:8px;color:#e8d8c6;font-weight:800}
.devone-launch-section{width:min(1240px,calc(100% - 32px));margin:0 auto;padding:76px 0}
.devone-launch-heading{text-align:center;max-width:820px;margin:0 auto 40px}.devone-launch-heading h2{margin:0 0 12px;font-size:clamp(2.2rem,5vw,4.6rem);line-height:.96;letter-spacing:-.065em}.devone-launch-heading p{margin:0;color:#e8d8c6;line-height:1.75;font-size:1.05rem}
.devone-launch-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.devone-launch-card{border-radius:30px;background:linear-gradient(145deg,rgba(255,255,255,.08),rgba(255,255,255,.025));border:1px solid rgba(255,211,90,.18);padding:28px;box-shadow:0 24px 70px rgba(0,0,0,.26)}
.devone-launch-icon{width:52px;height:52px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,#ff7a18,#ff2d75);font-size:1.45rem;margin-bottom:18px}.devone-launch-card h3{margin:0 0 10px;font-size:1.35rem}.devone-launch-card p{margin:0;color:#eadbc8;line-height:1.72}
.devone-launch-split{display:grid;grid-template-columns:.95fr 1.05fr;gap:22px;align-items:center}.devone-launch-code{border-radius:32px;border:1px solid rgba(255,211,90,.2);background:#0c0b14;padding:24px;box-shadow:0 30px 80px rgba(0,0,0,.3);overflow:hidden}.devone-launch-code pre{margin:0;white-space:pre-wrap;color:#f5d9b0;line-height:1.7;font-size:.94rem}.devone-launch-copy{border-radius:32px;border:1px solid rgba(255,211,90,.2);background:radial-gradient(circle at top right,rgba(255,122,24,.16),transparent 40%),rgba(255,255,255,.055);padding:clamp(28px,4vw,48px)}.devone-launch-copy h2{margin:0 0 14px;font-size:clamp(2.2rem,4.5vw,4.4rem);line-height:.98;letter-spacing:-.06em}.devone-launch-copy p,.devone-launch-copy li{color:#eadbc8;line-height:1.75}.devone-launch-copy ul{margin:18px 0 0;padding-left:20px}
.devone-launch-cta{border-radius:36px;text-align:center;padding:70px 24px;background:linear-gradient(135deg,rgba(255,122,24,.16),rgba(255,45,117,.13));border:1px solid rgba(255,211,90,.22)}.devone-launch-cta h2{margin:0 0 12px;font-size:clamp(2.3rem,5vw,5rem);line-height:.96;letter-spacing:-.065em}.devone-launch-cta p{max-width:760px;margin:0 auto;color:#eadbc8;line-height:1.75}
@keyframes devoneGlow{from{transform:rotate(0deg) scale(1)}to{transform:rotate(8deg) scale(1.08)}}
@media(max-width:1000px){.devone-launch-panel,.devone-launch-grid{grid-template-columns:repeat(2,1fr)}.devone-launch-split{grid-template-columns:1fr}}@media(max-width:650px){.devone-launch-panel,.devone-launch-grid{grid-template-columns:1fr}.devone-launch-title{font-size:3.6rem}.devone-launch-hero{min-height:auto;padding:70px 18px}}
</style>
<div class="devone-launch-home devone-full-bleed alignfull">
<section class="devone-launch-hero">
  <div class="devone-launch-inner">
    <div class="devone-launch-badge"><span></span> Fresh Developer One CMS Install</div>
    <h1 class="devone-launch-title">Build faster with <strong>Developer One CMS.</strong></h1>
    <p class="devone-launch-lead">Your new site is ready. Developer One CMS gives you a clean admin dashboard, pages, media, themes, plugins, frontend scripts, roles, backups, logs, and a marketplace-ready foundation without the bloat.</p>
    <div class="devone-launch-actions">
      <a class="devone-launch-btn primary" href="admin/">Open Admin Dashboard</a>
      <a class="devone-launch-btn ghost" href="/">View Home Page</a>
    </div>
    <div class="devone-launch-panel">
      <div class="devone-launch-stat"><b>01</b><span>Create pages</span></div>
      <div class="devone-launch-stat"><b>02</b><span>Install themes</span></div>
      <div class="devone-launch-stat"><b>03</b><span>Add plugins</span></div>
      <div class="devone-launch-stat"><b>04</b><span>Launch faster</span></div>
    </div>
  </div>
</section>
<section class="devone-launch-section">
  <div class="devone-launch-heading">
    <h2>A clean foundation for real websites.</h2>
    <p>This starter home page is created automatically during installation so every fresh site feels polished from the first load.</p>
  </div>
  <div class="devone-launch-grid">
    <div class="devone-launch-card"><div class="devone-launch-icon">📄</div><h3>Pages & Menus</h3><p>Create public pages, manage menus, choose sticky/off-canvas navigation, and control the frontend experience from the dashboard.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🎨</div><h3>Themes</h3><p>Install, preview, and activate themes built for Developer One CMS. Start with the official presets or build your own.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🔌</div><h3>Plugins</h3><p>Extend the CMS with installable plugins, admin submenu pages, frontend hooks, and marketplace-ready package metadata.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🖼️</div><h3>Media Library</h3><p>Upload and organize images, documents, audio, video, archives, avatars, and user-owned media safely.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🛡️</div><h3>Roles & Security</h3><p>Manage users, roles, permissions, protected admin pages, secure sessions, and CSRF-protected forms.</p></div>
    <div class="devone-launch-card"><div class="devone-launch-icon">🛠️</div><h3>Developer Tools</h3><p>Use frontend scripts, API builder, system logs, table debug, backups, and restore tools when building advanced sites.</p></div>
  </div>
</section>
<section class="devone-launch-section">
  <div class="devone-launch-split">
    <div class="devone-launch-code"><pre>&lt;!-- Example plugin metadata --&gt;
{
  "name": "My DevOne Plugin",
  "slug": "my-devone-plugin",
  "folder": "my-devone-plugin",
  "version": "1.0.0",
  "author": "Your Studio"
}</pre></div>
    <div class="devone-launch-copy"><h2>Built for creators, developers, and agencies.</h2><p>Developer One CMS is designed to stay simple at the core while letting themes and plugins do the heavy lifting.</p><ul><li>Launch a business site, artist site, landing page, documentation hub, or client project.</li><li>Install ecommerce, media, marketing, or custom workflow plugins as your site grows.</li><li>Keep your admin clean with grouped developer tools, modern cards, and responsive layouts.</li></ul></div>
  </div>
</section>
<section class="devone-launch-section">
  <div class="devone-launch-cta"><h2>Start building your site.</h2><p>Log in to the admin dashboard, update your site name and logo, create your first page, choose a theme, and install plugins from the Developer One marketplace.</p><div class="devone-launch-actions"><a class="devone-launch-btn primary" href="admin/">Go to Admin</a></div></div>
</section>
</div>
HTML;
    $result = save_page(0, 'Home', 'home', $home, 'default', 'published');
    return !empty($result['ok']);
}

function devone_theme() {
    $theme = get_setting('site_theme', defined('SITE_THEME') ? SITE_THEME : 'devone-dark');
    $theme = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$theme) ?: 'devone-dark';
    if (!devone_find_theme_dir($theme)) { $theme = 'devone-dark'; }
    return $theme;
}

function devone_site_private_theme_dir($siteId = 0) {
    $siteId = $siteId > 0 ? (int)$siteId : (function_exists('devone_content_site_id') ? devone_content_site_id() : 1);
    return __DIR__ . '/../content/sites/' . max(1, $siteId) . '/themes';
}

function devone_global_theme_dir() { return __DIR__ . '/../content/themes'; }

function devone_find_theme_dir($theme, $siteId = 0) {
    $theme = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$theme);
    if ($theme === '') { return ''; }
    $siteId = $siteId > 0 ? (int)$siteId : (function_exists('devone_content_site_id') ? devone_content_site_id() : 1);
    $private = devone_site_private_theme_dir($siteId) . '/' . $theme;
    if (is_dir($private) && is_file($private . '/theme.css')) { return $private; }
    if (class_exists('DevOne') && DevOne::services()->has('theme-entitlements')) {
        try {
            $package = DevOne::service('theme-entitlements')->packageForSite($siteId, $theme);
            if ($package && !empty($package['storage_path'])) {
                $shared = dirname(__DIR__) . '/' . ltrim((string)$package['storage_path'], '/');
                if (is_dir($shared) && is_file($shared . '/theme.css')) { return $shared; }
            }
        } catch (Throwable $e) {}
    }
    $global = devone_global_theme_dir() . '/' . $theme;
    if (is_dir($global) && is_file($global . '/theme.css')) { return $global; }
    return '';
}

function devone_theme_css_url($theme = '') {
    $theme = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($theme ?: devone_theme()));
    $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
    $dir = devone_find_theme_dir($theme, $siteId);
    if ($dir !== '') {
        $root = realpath(dirname(__DIR__));
        $real = realpath($dir . '/theme.css');
        if ($root && $real) {
            $rootN = str_replace('\\','/',rtrim($root,'/\\'));
            $realN = str_replace('\\','/',$real);
            if (strpos($realN,$rootN.'/')===0) { return devone_site_url(ltrim(substr($realN,strlen($rootN)),'/')); }
        }
    }
    return devone_site_url('content/themes/' . $theme . '/theme.css');
}


function devone_site_url($path = '') {
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    if (function_exists('devone_network_enabled') && devone_network_enabled()) {
        $site = null;
        if (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false && function_exists('devone_network_admin_site_context')) {
            $site = devone_network_admin_site_context();
        } elseif (function_exists('devone_current_site')) {
            $site = devone_current_site();
        }
        $domain = $site && function_exists('devone_network_clean_domain') ? devone_network_clean_domain($site['primary_domain'] ?? ($site['auto_subdomain'] ?? '')) : '';
        if ($domain !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . $domain;
        }
    }
    $path = (string)$path;
    if ($path === '') { return $base . '/'; }
    if (preg_match('/^https?:\/\//i', $path) || strpos($path, '//') === 0) { return $path; }
    return $base . '/' . ltrim($path, '/');
}

function devone_page_url($slug) {
    $slug = devone_slugify($slug, 'home');
    if ($slug === 'home') { return devone_site_url('/'); }
    $style = devone_permalink_style();
    if ($style === 'legacy') { return devone_site_url('/?page=' . rawurlencode($slug)); }
    if ($style === 'page-prefix') { return devone_site_url('/page/' . rawurlencode($slug)); }
    return devone_site_url('/' . rawurlencode($slug));
}

function devone_clean_url($url) {
    $url = trim((string)$url);
    if ($url === '') { return '#'; }
    if (preg_match('/^https?:\/\//i', $url) || strpos($url, '//') === 0 || strpos($url, 'mailto:') === 0 || strpos($url, 'tel:') === 0 || strpos($url, '#') === 0) {
        return $url;
    }
    if (preg_match('/^\/?(?:index\.php)?\?page=([^&]+)/', $url, $m)) {
        return devone_page_url(rawurldecode($m[1]));
    }
    return devone_site_url('/' . ltrim($url, '/'));
}

function devone_current_public_url() {
    $path = devone_request_path();
    $query = $_GET;
    unset($query['page'], $query['ajax']);
    $url = devone_site_url($path);
    return $query ? $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($query) : $url;
}

function devone_setting($key, $default = '') {
    $value = get_setting($key, $default);
    return $value === null ? $default : $value;
}

function devone_site_logo_url() {
    $logo = trim((string)get_setting('site_logo', ''));
    if ($logo === '') { return ''; }
    if (preg_match('/^https?:\/\//i', $logo)) { return $logo; }
    return devone_site_url($logo);
}

function devone_upload_site_logo($file) {
    $options = array('folder'=>'site','purpose'=>'site-logo','allowed_types'=>array('images'),'max_bytes'=>3*1024*1024,'alt_text'=>'Site logo');

    // Prefer the official Core Assets service. Its lazy loader makes sure the
    // Asset Manager implementation is available on admin-only request paths.
    if (class_exists('DevOne') && method_exists('DevOne', 'assets')) {
        try {
            $result = DevOne::assets()->upload($file, $options);
            if (!empty($result['ok'])) { $result['message'] = 'Logo uploaded through DevOne Assets.'; }
            return $result;
        } catch (Throwable $e) {
            // Fall through to the compatibility loader below.
        }
    }

    // Admin Settings does not load core/assets.php. Load the shared Asset
    // Manager directly when the service layer is unavailable or incomplete.
    if (!function_exists('devone_media_upload') && is_file(__DIR__ . '/asset-manager.php')) {
        require_once __DIR__ . '/asset-manager.php';
    }
    if (function_exists('devone_media_upload')) {
        $result = devone_media_upload($file, $options);
        if (!empty($result['ok'])) { $result['message'] = 'Logo uploaded through DevOne Assets.'; }
        return $result;
    }
    return array('ok'=>false,'message'=>'DevOne Assets is unavailable.');
}

function devone_menu_layout() {
    $layout = get_setting('menu_layout', 'top-sticky');
    $allowed = array('none', 'top-static', 'top-sticky', 'bottom-sticky', 'offcanvas-left', 'offcanvas-right');
    return in_array($layout, $allowed, true) ? $layout : 'top-sticky';
}

/**
 * Return the selected public navigation visual style.
 *
 * Layout controls where the menu appears. Style controls how it looks.
 * Keeping these settings separate lets themes and site owners combine the
 * corporate glass treatment with sticky, static, bottom, or off-canvas menus.
 */
function devone_menu_style() {
    $style = strtolower(trim((string)get_setting('menu_style', 'classic')));
    $allowed = array('classic', 'corporate-glass-gold', 'prism-flux', 'orbit-rail', 'kinetic-cards');
    return in_array($style, $allowed, true) ? $style : 'classic';
}

function devone_active_menu_slug() {
    return devone_slugify(get_setting('primary_menu', 'main'), 'main');
}

function devone_site_width_mode() {
    $mode = strtolower(trim((string)get_setting('site_width_mode', 'boxed')));
    $allowed = array('boxed', 'full', 'edge2edge');
    return in_array($mode, $allowed, true) ? $mode : 'boxed';
}

function devone_layout_body_class($extra = '') {
    $classes = array_filter(array_map('trim', explode(' ', (string)$extra)));
    $classes[] = 'site-width-' . devone_site_width_mode();
    return trim(implode(' ', array_unique($classes)));
}

function devone_menu_item_id($item, $index = 0, $used = array()) {
    $candidate = strtolower(trim((string)($item['id'] ?? $item['item_id'] ?? '')));
    $candidate = preg_replace('/[^a-z0-9_-]/', '-', $candidate);
    $candidate = trim((string)$candidate, '-_');
    if ($candidate !== '' && !isset($used[$candidate])) { return substr($candidate, 0, 64); }

    $seed = strtolower(trim((string)($item['type'] ?? 'custom'))) . '|'
        . strtolower(trim((string)($item['page_slug'] ?? ''))) . '|'
        . strtolower(trim((string)($item['url'] ?? ''))) . '|'
        . strtolower(trim((string)($item['label'] ?? 'item'))) . '|'
        . (int)$index;
    $base = 'mi_' . substr(sha1($seed), 0, 16);
    $id = $base; $n = 2;
    while (isset($used[$id])) { $id = substr($base, 0, 58) . '_' . $n++; }
    return $id;
}

function devone_decode_menu_items($items) {
    if (is_array($items)) { $raw = $items; }
    else {
        $raw = json_decode((string)$items, true);
        if (!is_array($raw)) { $raw = array(); }
    }

    $clean = array(); $used = array(); $index = 0;
    foreach ($raw as $item) {
        if (!is_array($item)) { continue; }
        $label = trim((string)($item['label'] ?? ''));
        $type = trim((string)($item['type'] ?? 'custom'));
        $pageSlug = devone_slugify($item['page_slug'] ?? '', '');
        $url = trim((string)($item['url'] ?? ''));
        $target = !empty($item['target']) && $item['target'] === '_blank' ? '_blank' : '_self';
        $enabled = !isset($item['enabled']) || (string)$item['enabled'] !== '0';
        if (!$enabled || $label === '') { continue; }
        if ($type === 'page' && $pageSlug !== '') { $url = devone_page_url($pageSlug); }
        if ($url === '') { $url = '#'; }

        $id = devone_menu_item_id($item, $index++, $used);
        $used[$id] = true;
        $parentId = strtolower(trim((string)($item['parent_id'] ?? '')));
        $parentId = preg_replace('/[^a-z0-9_-]/', '-', $parentId);
        $parentId = trim((string)$parentId, '-_');

        $clean[] = array(
            'id' => $id,
            'parent_id' => $parentId,
            'label' => $label,
            'type' => $type === 'page' ? 'page' : 'custom',
            'page_slug' => $pageSlug,
            'url' => $url,
            'target' => $target,
            'enabled' => 1,
        );
    }

    // Validate parents after all item IDs are known. Invalid/self/cyclic parents are promoted to top level.
    $byId = array();
    foreach ($clean as $i => $item) { $byId[$item['id']] = $i; }
    foreach ($clean as $i => $item) {
        $parent = (string)$item['parent_id'];
        if ($parent === '' || $parent === $item['id'] || !isset($byId[$parent])) {
            $clean[$i]['parent_id'] = '';
            continue;
        }
        $seen = array($item['id'] => true); $cursor = $parent; $cycle = false; $guard = 0;
        while ($cursor !== '' && isset($byId[$cursor]) && $guard++ < 64) {
            if (isset($seen[$cursor])) { $cycle = true; break; }
            $seen[$cursor] = true;
            $cursor = (string)$clean[$byId[$cursor]]['parent_id'];
        }
        if ($cycle) { $clean[$i]['parent_id'] = ''; }
    }
    return $clean;
}

function devone_default_menu_items() {
    return array(array('id' => 'mi_home', 'parent_id' => '', 'label' => 'Home', 'type' => 'page', 'page_slug' => 'home', 'url' => devone_page_url('home'), 'target' => '_self', 'enabled' => 1));
}

function devone_get_menu($slug = '') {
    $tbl = devone_require_table('menus', true);
    if ($tbl === '') { return null; }
    $slug = devone_slugify($slug ?: devone_active_menu_slug(), 'main');
    $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE slug = ? LIMIT 1');
    $stmt->execute(array($slug));
    $menu = $stmt->fetch();
    if (!$menu && $slug !== 'main') {
        $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE slug = ? LIMIT 1');
        $stmt->execute(array('main'));
        $menu = $stmt->fetch();
    }
    return $menu ?: null;
}

function devone_get_menu_items($slug = '') {
    $menu = devone_get_menu($slug);
    if (!$menu) { return devone_default_menu_items(); }
    $items = devone_decode_menu_items($menu['items'] ?? '[]');
    return $items ?: devone_default_menu_items();
}

function devone_save_menu($name, $slug, $items) {
    $tbl = devone_require_table('menus', true);
    if ($tbl === '') { return array('ok' => false, 'message' => 'Menus table could not be resolved.'); }
    $name = trim((string)$name) ?: 'Main Menu';
    $slug = devone_slugify($slug ?: $name, 'main');
    $items = devone_decode_menu_items($items);
    $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (name, slug, items) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), items = VALUES(items)');
        $stmt->execute(array($name, $slug, $json));
        return array('ok' => true, 'message' => 'Menu saved.', 'slug' => $slug);
    } catch (Exception $e) {
        return array('ok' => false, 'message' => $e->getMessage(), 'slug' => $slug);
    }
}

function devone_list_menus() {
    $tbl = devone_require_table('menus', true);
    if ($tbl === '') { return array(); }
    return db()->query('SELECT * FROM `' . $tbl . '` ORDER BY name ASC')->fetchAll();
}

function devone_menu_tree($items) {
    $items = devone_decode_menu_items($items);
    if (!$items) { return array(); }
    $nodes = array();
    foreach ($items as $item) {
        $item['children'] = array();
        $nodes[$item['id']] = $item;
    }
    $roots = array();
    foreach ($items as $item) {
        $id = $item['id']; $parent = (string)$item['parent_id'];
        if ($parent !== '' && isset($nodes[$parent])) { $nodes[$parent]['children'][] = &$nodes[$id]; }
        else { $roots[] = &$nodes[$id]; }
    }
    return $roots;
}

function devone_render_menu_branch($items, $class = 'sub-menu', $depth = 1) {
    if (!$items) { return ''; }
    $html = '<ul class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" data-menu-depth="' . (int)$depth . '">';
    foreach ($items as $item) {
        $url = ($item['type'] ?? 'custom') === 'page' ? devone_page_url($item['page_slug'] ?? 'home') : devone_clean_url($item['url'] ?? '#');
        $target = ($item['target'] ?? '_self') === '_blank' ? ' target="_blank" rel="noopener"' : '';
        $ajax = (($item['type'] ?? 'custom') === 'page') ? ' data-ajax' : '';
        $children = is_array($item['children'] ?? null) ? $item['children'] : array();
        $hasChildren = !empty($children);
        $html .= '<li class="menu-item' . ($hasChildren ? ' menu-item-has-children' : '') . '" data-menu-item-id="' . htmlspecialchars($item['id'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $target . $ajax . '>' . htmlspecialchars($item['label'] ?? '', ENT_QUOTES, 'UTF-8') . '</a>';
        if ($hasChildren) { $html .= devone_render_menu_branch($children, 'sub-menu', $depth + 1); }
        $html .= '</li>';
    }
    return $html . '</ul>';
}

function devone_render_menu($slug = '', $class = 'primary-menu') {
    return devone_render_menu_branch(devone_menu_tree(devone_get_menu_items($slug)), $class, 0);
}

function devone_log($action, $details = '') {
    $tbl = devone_require_table('activity_logs', false);
    if ($tbl === '') return false;
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (user_id, action, details) VALUES (?, ?, ?)');
    return $stmt->execute([$uid, $action, $details]);
}


/* DevOneCMS v1.1.6 Theme + Media helpers */
if (!function_exists('devone_safe_name')) {
    function devone_safe_name($value) {
        return preg_replace('/[^a-zA-Z0-9._-]/', '-', basename((string)$value));
    }
}

function devone_media_type_from_file($filename, $mime = '') {
    $mime = strtolower((string)$mime);
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    if (strpos($mime, 'image/') === 0 || in_array($ext, array('jpg','jpeg','png','gif','webp','svg','bmp'), true)) { return 'images'; }
    if (strpos($mime, 'video/') === 0 || in_array($ext, array('mp4','webm','mov','avi','mkv','m4v'), true)) { return 'videos'; }
    if (strpos($mime, 'audio/') === 0 || in_array($ext, array('mp3','wav','ogg','m4a','flac'), true)) { return 'audio'; }
    if (strpos($mime, 'font/') === 0 || in_array($mime, array('application/font-sfnt','application/x-font-ttf','application/x-font-truetype','application/x-font-opentype','application/vnd.ms-opentype','application/octet-stream'), true) && in_array($ext, array('woff','woff2','ttf','otf'), true) || in_array($ext, array('woff','woff2','ttf','otf'), true)) { return 'fonts'; }
    if (in_array($ext, array('pdf','doc','docx','xls','xlsx','ppt','pptx','txt','rtf','csv'), true)) { return 'documents'; }
    if (in_array($ext, array('zip','rar','7z','tar','gz'), true)) { return 'archives'; }
    return 'other';
}

function devone_media_default_folders() {
    return array('images','documents','videos','audio','fonts','archives','other','avatars','site');
}

function devone_media_folder_slug($folder) {
    $folder = trim((string)$folder, "/\\ \t\n\r\0\x0B");
    $folder = preg_replace('/[^a-zA-Z0-9_\/-]/', '-', $folder);
    $folder = preg_replace('#/+#', '/', $folder);
    $folder = trim($folder, '/');
    return $folder !== '' ? $folder : 'other';
}

function devone_media_base_dir() {
    if (function_exists('devone_network_enabled') && devone_network_enabled()) {
        $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
        $dir = __DIR__ . '/../content/sites/' . max(1, (int)$siteId) . '/uploads';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return realpath($dir) ?: $dir;
    }
    return realpath(__DIR__ . '/../content/media') ?: (__DIR__ . '/../content/media');
}

function devone_media_folder_path($folder) {
    $base = devone_media_base_dir();
    $folder = devone_media_folder_slug($folder);
    return $base . '/' . $folder;
}

function devone_ensure_media_folders() {
    $base = devone_media_base_dir();
    if (!is_dir($base)) { @mkdir($base, 0775, true); }
    foreach (devone_media_default_folders() as $folder) {
        $path = $base . '/' . $folder;
        if (!is_dir($path)) { @mkdir($path, 0775, true); }
    }
}

function devone_list_media_folders() {
    devone_ensure_media_folders();
    $base = devone_media_base_dir();
    $folders = devone_media_default_folders();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        if (!$file->isDir()) { continue; }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
        if ($rel !== '' && strpos($rel, '..') === false) { $folders[] = $rel; }
    }
    $folders = array_values(array_unique(array_map('devone_media_folder_slug', $folders)));
    sort($folders);
    return $folders;
}

function devone_table_columns($base) {
    $tbl = devone_require_table($base, true);
    if ($tbl === '') { return array(); }
    try {
        $stmt = db()->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $stmt->execute(array($tbl));
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && $cols) { return array_values(array_map('strval', $cols)); }
    } catch (Exception $e) {}
    try {
        $cols = array();
        $stmt = db()->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tbl) . '`');
        foreach ($stmt->fetchAll() as $row) { if (!empty($row['Field'])) { $cols[] = $row['Field']; } }
        return $cols;
    } catch (Exception $e) { return array(); }
}

function devone_current_user_display_name() {
    $user = function_exists('devone_current_user') ? devone_current_user() : null;
    if (!$user) { return 'Developer'; }
    $display = trim((string)($user['display_name'] ?? ''));
    if ($display !== '') { return $display; }
    return trim((string)($user['username'] ?? 'Developer')) ?: 'Developer';
}

function devone_user_avatar_url($user) {
    $avatar = trim((string)($user['avatar'] ?? ''));
    if ($avatar === '') { return ''; }
    if (preg_match('/^https?:\/\//i', $avatar)) { return $avatar; }
    return devone_site_url($avatar);
}

function devone_get_user_by_id($id) {
    $tbl = devone_require_table('users', true);
    if ($tbl === '') { return null; }
    $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE id=? LIMIT 1');
    $stmt->execute(array((int)$id));
    return $stmt->fetch() ?: null;
}


function devone_profile_required_columns() {
    return array('display_name','avatar','bio','status','permissions_override','updated_at');
}

function devone_profile_diagnostics($userId = 0) {
    $out = array(
        'users_table' => '',
        'media_table' => '',
        'user_id' => (int)$userId,
        'user_columns' => array(),
        'media_columns' => array(),
        'missing_user_columns' => array(),
        'missing_media_columns' => array(),
        'avatar_dir' => '',
        'avatar_dir_exists' => false,
        'avatar_dir_writable' => false,
        'current_user_found' => false,
        'current_avatar' => '',
    );
    try {
        if (function_exists('devone_ensure_user_profile_schema')) { devone_ensure_user_profile_schema(); }
        if (function_exists('devone_ensure_media_schema')) { devone_ensure_media_schema(); }
        if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
        $out['users_table'] = devone_require_table('users', true);
        $out['media_table'] = devone_require_table('media', true);
        $out['user_columns'] = function_exists('devone_table_columns') ? devone_table_columns('users') : array();
        $out['media_columns'] = function_exists('devone_table_columns') ? devone_table_columns('media') : array();
        $out['missing_user_columns'] = array_values(array_diff(devone_profile_required_columns(), $out['user_columns']));
        $out['missing_media_columns'] = array_values(array_diff(array('folder','media_type','user_id','created_at'), $out['media_columns']));
        $out['avatar_dir'] = str_replace('\\', '/', realpath(__DIR__ . '/../content/media/avatars') ?: (__DIR__ . '/../content/media/avatars'));
        $out['avatar_dir_exists'] = is_dir(__DIR__ . '/../content/media/avatars');
        $out['avatar_dir_writable'] = is_dir(__DIR__ . '/../content/media/avatars') && is_writable(__DIR__ . '/../content/media/avatars');
        if ((int)$userId > 0) {
            $u = devone_get_user_by_id((int)$userId);
            $out['current_user_found'] = (bool)$u;
            if ($u) { $out['current_avatar'] = (string)($u['avatar'] ?? ''); }
        }
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

function devone_update_user_profile_fields($userId, $data, $canManageUsers = false) {
    $userId = (int)$userId;
    if ($userId <= 0) { return array('ok'=>false, 'message'=>'No valid user ID was supplied for profile save.'); }
    if (function_exists('devone_ensure_user_profile_schema')) {
        $schema = devone_ensure_user_profile_schema();
        if (empty($schema['ok'])) { return array('ok'=>false, 'message'=>'Profile schema repair failed: ' . ($schema['message'] ?? 'Unknown schema error.')); }
    }
    if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }

    $tbl = devone_require_table('users', true);
    if ($tbl === '') { return array('ok'=>false, 'message'=>'Users table could not be resolved while saving profile.'); }

    $cols = function_exists('devone_table_columns') ? devone_table_columns('users') : array();
    $required = array('display_name','avatar','bio');
    $missing = array_values(array_diff($required, $cols));
    if ($missing) {
        return array('ok'=>false, 'message'=>'Profile columns are still missing from `' . $tbl . '`: ' . implode(', ', $missing) . '. Check MySQL ALTER TABLE permissions.');
    }

    $allowed = array('username','email','display_name','bio');
    if ($canManageUsers) { $allowed = array_merge($allowed, array('role','status','permissions_override')); }
    $sets = array();
    $values = array();
    foreach ($allowed as $field) {
        if (!array_key_exists($field, $data) || !in_array($field, $cols, true)) { continue; }
        $sets[] = '`' . $field . '`=?';
        $values[] = $data[$field];
    }
    if (!empty($data['password']) && in_array('password', $cols, true)) {
        $sets[] = '`password`=?';
        $values[] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
    }
    if (in_array('updated_at', $cols, true)) { $sets[] = '`updated_at`=NOW()'; }
    if (!$sets) { return array('ok'=>false, 'message'=>'There were no writable profile fields to save.'); }
    $values[] = $userId;

    try {
        $stmt = db()->prepare('UPDATE `' . $tbl . '` SET ' . implode(',', $sets) . ' WHERE id=? LIMIT 1');
        $stmt->execute($values);
        $fresh = devone_get_user_by_id($userId);
        if (!$fresh) { return array('ok'=>false, 'message'=>'Profile save ran, but user #' . $userId . ' could not be reloaded from `' . $tbl . '`.'); }
        foreach (array('display_name','bio') as $field) {
            if (array_key_exists($field, $data) && (string)($fresh[$field] ?? '') !== (string)$data[$field]) {
                return array('ok'=>false, 'message'=>'Profile save verification failed for `' . $field . '`. The database still contains a different value. Resolved table: `' . $tbl . '`.');
            }
        }
        return array('ok'=>true, 'message'=>'Profile fields saved.', 'user'=>$fresh, 'table'=>$tbl, 'columns'=>$cols);
    } catch (Exception $e) {
        return array('ok'=>false, 'message'=>'Profile save database error: ' . $e->getMessage());
    }
}

function devone_list_roles() {
    $tbl = devone_require_table('roles', true);
    if ($tbl === '') { return array(); }
    return db()->query('SELECT * FROM `' . $tbl . '` ORDER BY name ASC')->fetchAll();
}

function devone_list_users() {
    $tbl = devone_require_table('users', true);
    if ($tbl === '') { return array(); }
    return db()->query('SELECT * FROM `' . $tbl . '` ORDER BY id DESC')->fetchAll();
}

function devone_upload_user_avatar($file, $userId) {
    $options = array('folder'=>'avatars','purpose'=>'avatar','allowed_types'=>array('images'),'max_bytes'=>5*1024*1024,'user_id'=>(int)$userId,'alt_text'=>'Avatar for user #'.(int)$userId);

    // Keep avatar uploads on the same official Assets contract as site logos.
    if (class_exists('DevOne') && method_exists('DevOne', 'assets')) {
        try {
            $result = DevOne::assets()->upload($file, $options);
            if (!empty($result['ok'])) { $result['message'] = 'Avatar uploaded through DevOne Assets.'; }
            return $result;
        } catch (Throwable $e) {
            // Fall through to the compatibility loader below.
        }
    }

    if (!function_exists('devone_media_upload') && is_file(__DIR__ . '/asset-manager.php')) {
        require_once __DIR__ . '/asset-manager.php';
    }
    if (function_exists('devone_media_upload')) {
        $result = devone_media_upload($file, $options);
        if (!empty($result['ok'])) { $result['message'] = 'Avatar uploaded through DevOne Assets.'; }
        return $result;
    }
    return array('ok'=>false,'message'=>'DevOne Assets is unavailable.');
}

function devone_insert_media_record($data) {
    if (function_exists('devone_repair_core_schema')) {
        try { devone_repair_core_schema(false); } catch (Exception $e) {}
    }
    if (function_exists('devone_ensure_media_schema')) {
        try { devone_ensure_media_schema(); } catch (Exception $e) {}
    }
    if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }

    $tbl = devone_require_table('media', true);
    if ($tbl === '') { return false; }
    $cols = devone_table_columns('media');

    $path = trim((string)($data['path'] ?? ''));
    $filename = trim((string)($data['filename'] ?? ''));
    if ($filename === '' && $path !== '') { $filename = basename($path); }
    if ($filename === '') { $filename = 'media-' . date('Ymd-His'); }

    $folder = trim((string)($data['folder'] ?? ''));
    if ($folder === '' && $path !== '' && preg_match('#content/media/([^/]+)/#', $path, $m)) { $folder = $m[1]; }
    $folder = devone_media_folder_slug($folder ?: 'other');

    $mediaType = trim((string)($data['media_type'] ?? ''));
    if ($mediaType === '') { $mediaType = devone_media_type_from_file($filename, (string)($data['mime_type'] ?? '')); }

    $ownerId = isset($data['user_id']) ? (int)$data['user_id'] : (function_exists('devone_current_user_id') ? (int)devone_current_user_id() : 0);
    if ($ownerId <= 0) { $ownerId = null; }

    $map = array(
        'filename' => $filename,
        'path' => $path,
        'mime_type' => $data['mime_type'] ?? '',
        'size_bytes' => (int)($data['size_bytes'] ?? 0),
        'alt_text' => $data['alt_text'] ?? '',
        'folder' => $folder,
        'media_type' => $mediaType,
        'user_id' => $ownerId,
        'site_id' => function_exists('devone_content_site_id') ? devone_content_site_id() : 1,
    );
    $fields = array_values(array_intersect(array_keys($map), $cols));
    if (!$fields || !in_array('path', $fields, true) || !in_array('filename', $fields, true)) { return false; }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO `' . $tbl . '` (`' . implode('`,`', $fields) . '`) VALUES (' . $placeholders . ')';
    $values = array();
    foreach ($fields as $field) { $values[] = $map[$field]; }

    try {
        $stmt = db()->prepare($sql);
        $ok = $stmt->execute($values);
        return $ok ? (int)db()->lastInsertId() : false;
    } catch (Exception $e) {
        if (function_exists('devone_log')) { @devone_log('media_record_error', $e->getMessage()); }
        return false;
    }
}

function devone_media_query($folder = '', $ownerOnly = false, $ownerUserId = 0) {
    if (function_exists('devone_ensure_media_schema')) {
        try { devone_ensure_media_schema(); } catch (Exception $e) {}
    }
    $tbl = devone_require_table('media', true);
    if ($tbl === '') { return array(); }
    $folder = devone_media_folder_slug($folder);
    $cols = devone_table_columns('media');
    try {
        $where = array();
        $values = array();
        if (in_array('site_id', $cols, true)) { $where[] = 'site_id = ?'; $values[] = function_exists('devone_content_site_id') ? devone_content_site_id() : 1; }
        if ($folder !== 'all') {
            $filterWhere = array();
            if (in_array('folder', $cols, true)) { $filterWhere[] = 'folder = ?'; $values[] = $folder; }
            if (in_array('media_type', $cols, true)) { $filterWhere[] = 'media_type = ?'; $values[] = $folder; }
            if (in_array('path', $cols, true)) { $filterWhere[] = 'path LIKE ?'; $values[] = '%/' . $folder . '/%'; }
            if ($filterWhere) { $where[] = '(' . implode(' OR ', $filterWhere) . ')'; }
        }
        if ($ownerOnly) {
            if (!in_array('user_id', $cols, true) || (int)$ownerUserId <= 0) {
                return array();
            }
            $where[] = 'user_id = ?';
            $values[] = (int)$ownerUserId;
        }
        $sql = 'SELECT * FROM `' . $tbl . '`';
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY id DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($values);
        return $stmt->fetchAll();
    } catch (Exception $e) { return array(); }
}


function devone_cleanup_media_references($path) {
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '') { return array('pages'=>0, 'settings'=>0, 'menus'=>0, 'users'=>0); }
    $targets = array_values(array_unique(array_filter(array(
        $path,
        '/' . ltrim($path, '/'),
        function_exists('devone_site_url') ? devone_site_url($path) : '',
    ))));
    $counts = array('pages'=>0, 'settings'=>0, 'menus'=>0, 'users'=>0);
    $siteId = function_exists('devone_content_site_id') ? max(1, (int)devone_content_site_id()) : 1;
    $networkClient = false;
    $currentUserId = function_exists('devone_current_user_id') ? (int)devone_current_user_id() : 0;
    if ($currentUserId > 0 && function_exists('devone_network_enabled') && devone_network_enabled()
        && function_exists('devone_user_is_direct_site_admin') && devone_user_is_direct_site_admin($currentUserId, $siteId)) {
        $isSuper = function_exists('devone_network_is_super_admin') && devone_network_is_super_admin();
        $networkClient = !$isSuper;
    }

    try {
        $pages = devone_require_table('pages', false);
        $pageCols = function_exists('devone_table_columns') ? devone_table_columns('pages') : array();
        if ($pages !== '' && in_array('content', $pageCols, true)) {
            foreach ($targets as $target) {
                $sql = 'UPDATE `' . $pages . '` SET `content` = REPLACE(`content`, ?, ?) WHERE `content` LIKE ?';
                $values = array($target, '', '%' . $target . '%');
                if (in_array('site_id', $pageCols, true)) { $sql .= ' AND `site_id`=?'; $values[] = $siteId; }
                $stmt = db()->prepare($sql);
                $stmt->execute($values);
                $counts['pages'] += (int)$stmt->rowCount();
            }
        }
    } catch (Exception $e) {}

    // Core settings are installation-wide in the current compatibility model.
    // A Network client Site Admin must never mutate them as a side-effect of
    // deleting a site-owned media item. SuperAdmins/non-network installs retain
    // the legacy cleanup behavior.
    if (!$networkClient) {
        try {
            $settings = devone_require_table('settings', false);
            $settingCols = function_exists('devone_table_columns') ? devone_table_columns('settings') : array();
            if ($settings !== '' && in_array('setting_value', $settingCols, true)) {
                foreach ($targets as $target) {
                    $stmt = db()->prepare('UPDATE `' . $settings . '` SET `setting_value` = REPLACE(`setting_value`, ?, ?) WHERE `setting_value` LIKE ?');
                    $stmt->execute(array($target, '', '%' . $target . '%'));
                    $counts['settings'] += (int)$stmt->rowCount();
                }
            }
        } catch (Exception $e) {}
    }

    // Network-specific settings are always safe to scope to the active site.
    try {
        $siteSettings = devone_require_table('site_settings', false);
        $siteSettingCols = function_exists('devone_table_columns') ? devone_table_columns('site_settings') : array();
        if ($siteSettings !== '' && in_array('setting_value', $siteSettingCols, true) && in_array('site_id', $siteSettingCols, true)) {
            foreach ($targets as $target) {
                $stmt = db()->prepare('UPDATE `' . $siteSettings . '` SET `setting_value` = REPLACE(`setting_value`, ?, ?) WHERE `setting_value` LIKE ? AND `site_id`=?');
                $stmt->execute(array($target, '', '%' . $target . '%', $siteId));
                $counts['settings'] += (int)$stmt->rowCount();
            }
        }
    } catch (Exception $e) {}

    try {
        $menus = devone_require_table('menus', false);
        $menuCols = function_exists('devone_table_columns') ? devone_table_columns('menus') : array();
        if ($menus !== '' && in_array('items', $menuCols, true)) {
            foreach ($targets as $target) {
                $sql = 'UPDATE `' . $menus . '` SET `items` = REPLACE(`items`, ?, ?) WHERE `items` LIKE ?';
                $values = array($target, '', '%' . $target . '%');
                if (in_array('site_id', $menuCols, true)) { $sql .= ' AND `site_id`=?'; $values[] = $siteId; }
                $stmt = db()->prepare($sql);
                $stmt->execute($values);
                $counts['menus'] += (int)$stmt->rowCount();
            }
        }
    } catch (Exception $e) {}

    try {
        $users = devone_require_table('users', false);
        $userCols = function_exists('devone_table_columns') ? devone_table_columns('users') : array();
        if ($users !== '' && in_array('avatar', $userCols, true)) {
            $placeholders = implode(',', array_fill(0, count($targets), '?'));
            $sql = 'UPDATE `' . $users . '` SET `avatar` = ? WHERE `avatar` IN (' . $placeholders . ')';
            $values = array_merge(array(''), $targets);
            if ($networkClient) { $sql .= ' AND `id`=?'; $values[] = $currentUserId; }
            $stmt = db()->prepare($sql);
            $stmt->execute($values);
            $counts['users'] += (int)$stmt->rowCount();
        }
    } catch (Exception $e) {}

    return $counts;
}

function devone_delete_media_record($mediaId, $actorUserId = 0, $canDeleteAll = false) {
    $mediaId = (int)$mediaId;
    $actorUserId = (int)$actorUserId;
    if ($mediaId <= 0) { return array('ok'=>false, 'message'=>'No media item was selected.'); }
    if (function_exists('devone_ensure_media_schema')) { try { devone_ensure_media_schema(); } catch (Exception $e) {} }
    if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
    $tbl = devone_require_table('media', true);
    if ($tbl === '') { return array('ok'=>false, 'message'=>'Media table could not be resolved.'); }
    $cols = function_exists('devone_table_columns') ? devone_table_columns('media') : array();
    if (!in_array('id', $cols, true)) { return array('ok'=>false, 'message'=>'Media table is missing the id column.'); }

    try {
        if (in_array('site_id', $cols, true)) {
            $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE id=? AND site_id=? LIMIT 1');
            $stmt->execute(array($mediaId, function_exists('devone_content_site_id') ? devone_content_site_id() : 1));
        } else {
            $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE id=? LIMIT 1');
            $stmt->execute(array($mediaId));
        }
        $record = $stmt->fetch();
        if (!$record) { return array('ok'=>false, 'message'=>'That media item no longer exists.'); }

        if (!$canDeleteAll) {
            if (!in_array('user_id', $cols, true)) { return array('ok'=>false, 'message'=>'Media ownership is missing, so this item cannot be safely deleted by this user.'); }
            if ($actorUserId <= 0 || (int)($record['user_id'] ?? 0) !== $actorUserId) {
                return array('ok'=>false, 'message'=>'You can only delete media that you uploaded.');
            }
        }

        $rel = trim(str_replace('\\', '/', (string)($record['path'] ?? '')));
        $deletedFile = false;
        if ($rel !== '' && strpos($rel, '..') === false) {
            $projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
            $mediaRoot = function_exists('devone_media_base_dir') ? devone_media_base_dir() : (__DIR__ . '/../content/media');
            $realMediaRoot = realpath($mediaRoot) ?: $mediaRoot;
            $candidate = $projectRoot . '/' . ltrim($rel, '/');
            $realCandidate = realpath($candidate);
            $rootNorm = rtrim(str_replace('\\','/',$realMediaRoot),'/') . '/';
            $candidateNorm = $realCandidate ? str_replace('\\','/',$realCandidate) : '';
            if ($realCandidate && is_file($realCandidate) && strpos($candidateNorm, $rootNorm) === 0) { $deletedFile = @unlink($realCandidate); }
        }

        $cleanup = $rel !== '' ? devone_cleanup_media_references($rel) : array('pages'=>0, 'settings'=>0, 'menus'=>0, 'users'=>0);
        if (in_array('site_id', $cols, true)) {
            $del = db()->prepare('DELETE FROM `' . $tbl . '` WHERE id=? AND site_id=? LIMIT 1');
            $del->execute(array($mediaId, function_exists('devone_content_site_id') ? devone_content_site_id() : 1));
        } else {
            $del = db()->prepare('DELETE FROM `' . $tbl . '` WHERE id=? LIMIT 1');
            $del->execute(array($mediaId));
        }
        if (function_exists('devone_log')) { devone_log('media_deleted', 'Media ID ' . $mediaId . ' ' . $rel); }
        $message = 'Media deleted from the library.';
        if ($deletedFile) { $message .= ' File removed from storage.'; }
        $touched = array_sum(array_map('intval', $cleanup));
        if ($touched > 0) { $message .= ' Cleared ' . $touched . ' saved reference' . ($touched === 1 ? '' : 's') . '.'; }
        return array('ok'=>true, 'message'=>$message, 'record'=>$record, 'file_deleted'=>$deletedFile, 'references'=>$cleanup);
    } catch (Exception $e) {
        return array('ok'=>false, 'message'=>'Media delete failed: ' . $e->getMessage());
    }
}

function devone_media_record_type($record) {
    $mime = (string)($record['mime_type'] ?? '');
    $filename = (string)($record['filename'] ?? '');
    if ($filename === '' && !empty($record['path'])) { $filename = basename((string)$record['path']); }
    return devone_media_type_from_file($filename, $mime);
}

function devone_media_record_folder($record) {
    $folder = trim((string)($record['folder'] ?? ''));
    if ($folder !== '') { return devone_media_folder_slug($folder); }
    $path = str_replace('\\', '/', (string)($record['path'] ?? ''));
    if (preg_match('#content/media/([^/]+)/#', $path, $m)) { return devone_media_folder_slug($m[1]); }
    return devone_media_record_type($record);
}

function devone_media_should_preserve_custom_folder($folder) {
    $folder = devone_media_folder_slug($folder);
    $typeFolders = array('images','documents','videos','audio','archives','other');
    return $folder !== '' && !in_array($folder, $typeFolders, true);
}

function devone_media_autosort_existing($preserveCustom = true) {
    $tbl = devone_require_table('media', true);
    if ($tbl === '') { return array('moved'=>0, 'updated'=>0, 'errors'=>array('Media table is missing.')); }
    devone_ensure_media_folders();
    $cols = devone_table_columns('media');
    $siteScoped = in_array('site_id', $cols, true);
    $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
    $items = array();
    try {
        if ($siteScoped) {
            $stmt = db()->prepare('SELECT * FROM `' . $tbl . '` WHERE site_id=? ORDER BY id DESC');
            $stmt->execute(array($siteId));
            $items = $stmt->fetchAll();
        } else {
            $items = db()->query('SELECT * FROM `' . $tbl . '` ORDER BY id DESC')->fetchAll();
        }
    } catch (Exception $e) { return array('moved'=>0, 'updated'=>0, 'errors'=>array($e->getMessage())); }

    $cmsBase = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $canonicalBase = realpath(devone_media_base_dir()) ?: devone_media_base_dir();
    $allowedRoots = array($canonicalBase);
    $networkEnabled = function_exists('devone_network_enabled') && devone_network_enabled();
    $networkClient = false;
    if ($networkEnabled && function_exists('devone_current_user_id') && function_exists('devone_user_is_direct_site_admin')) {
        $uid = (int)devone_current_user_id();
        $networkClient = $uid > 0 && devone_user_is_direct_site_admin($uid, $siteId)
            && !(function_exists('devone_network_is_super_admin') && devone_network_is_super_admin());
    }
    // Legacy shared media roots remain available to SuperAdmins and non-network
    // installations for migration/repair. Client Site Admins are confined to
    // their canonical content/sites/<id>/uploads tree.
    if (!$networkEnabled || !$networkClient) {
        $allowedRoots[] = $cmsBase . '/content/media';
        $allowedRoots[] = $cmsBase . '/content/uploads';
    }
    $allowedRoots = array_values(array_unique(array_filter(array_map(function($root){
        $real = realpath($root);
        return rtrim(str_replace('\\','/', $real ?: $root), '/');
    }, $allowedRoots))));
    $isAllowedFile = function($path) use ($allowedRoots) {
        $real = realpath((string)$path);
        if (!$real || !is_file($real)) { return ''; }
        $norm = str_replace('\\','/', $real);
        foreach ($allowedRoots as $root) {
            if ($norm === $root || strpos($norm, $root . '/') === 0) { return $real; }
        }
        return '';
    };

    $moved = 0; $updated = 0; $errors = array();
    foreach ($items as $m) {
        $id = (int)($m['id'] ?? 0);
        if ($id <= 0) { continue; }
        $type = devone_media_record_type($m);
        $oldFolder = devone_media_record_folder($m);
        if ($preserveCustom && devone_media_should_preserve_custom_folder($oldFolder)) {
            if (in_array('media_type', $cols, true) || in_array('folder', $cols, true)) {
                $sets = array(); $vals = array();
                if (in_array('folder', $cols, true) && empty($m['folder'])) { $sets[] = 'folder=?'; $vals[] = $oldFolder; }
                if (in_array('media_type', $cols, true) && ($m['media_type'] ?? '') !== $type) { $sets[] = 'media_type=?'; $vals[] = $type; }
                if ($sets) {
                    $where = 'id=?'; $vals[] = $id;
                    if ($siteScoped) { $where .= ' AND site_id=?'; $vals[] = $siteId; }
                    db()->prepare('UPDATE `' . $tbl . '` SET ' . implode(',', $sets) . ' WHERE ' . $where)->execute($vals);
                    $updated++;
                }
            }
            continue;
        }

        $targetFolder = $type;
        $oldPath = str_replace('\\', '/', (string)($m['path'] ?? ''));
        $filename = (string)($m['filename'] ?? basename($oldPath));
        $currentAbs = $isAllowedFile($cmsBase . '/' . ltrim($oldPath, '/'));
        if ($currentAbs === '') {
            $guesses = array(
                devone_media_folder_path($oldFolder) . '/' . $filename,
                devone_media_base_dir() . '/' . $filename,
            );
            foreach ($guesses as $guess) {
                $resolved = $isAllowedFile($guess);
                if ($resolved !== '') { $currentAbs = $resolved; break; }
            }
        }

        $newDir = devone_media_folder_path($targetFolder);
        if (!is_dir($newDir)) { @mkdir($newDir, 0775, true); }
        $newName = $filename ?: ('media-' . $id);
        $newAbs = $newDir . '/' . $newName;
        if ($currentAbs !== '' && realpath(dirname($currentAbs)) !== realpath($newDir)) {
            $base = pathinfo($newName, PATHINFO_FILENAME);
            $ext = pathinfo($newName, PATHINFO_EXTENSION);
            $counter = 1;
            while (is_file($newAbs)) {
                $newName = $base . '-' . $counter . ($ext ? '.' . $ext : '');
                $newAbs = $newDir . '/' . $newName;
                $counter++;
            }
            if (@rename($currentAbs, $newAbs)) { $moved++; }
            else { $errors[] = 'Could not move ' . $filename . ' to ' . $targetFolder; $newAbs = $currentAbs; }
        }
        $newReal = realpath($newAbs) ?: $newAbs;
        $rootNorm = rtrim(str_replace('\\','/', $cmsBase), '/') . '/';
        $newNorm = str_replace('\\','/', $newReal);
        if (strpos($newNorm, $rootNorm) !== 0) { $errors[] = 'Media path left the site storage boundary: ' . $filename; continue; }
        $newRel = ltrim(substr($newNorm, strlen($rootNorm)), '/');
        $sets = array(); $vals = array();
        if (in_array('filename', $cols, true) && ($m['filename'] ?? '') !== basename($newAbs)) { $sets[] = 'filename=?'; $vals[] = basename($newAbs); }
        if (in_array('path', $cols, true) && ($m['path'] ?? '') !== $newRel) { $sets[] = 'path=?'; $vals[] = $newRel; }
        if (in_array('folder', $cols, true) && ($m['folder'] ?? '') !== $targetFolder) { $sets[] = 'folder=?'; $vals[] = $targetFolder; }
        if (in_array('media_type', $cols, true) && ($m['media_type'] ?? '') !== $type) { $sets[] = 'media_type=?'; $vals[] = $type; }
        if ($sets) {
            $where = 'id=?'; $vals[] = $id;
            if ($siteScoped) { $where .= ' AND site_id=?'; $vals[] = $siteId; }
            db()->prepare('UPDATE `' . $tbl . '` SET ' . implode(',', $sets) . ' WHERE ' . $where)->execute($vals);
            $updated++;
        }
    }
    return array('moved'=>$moved, 'updated'=>$updated, 'errors'=>$errors);
}

function devone_theme_manifest($folder, $baseDir = '', $scope = 'system') {
    $folder = devone_slugify($folder, 'theme');
    $dir = $baseDir !== '' ? rtrim((string)$baseDir, '/\\') . '/' . $folder : devone_find_theme_dir($folder);
    $manifest = array('name' => ucwords(str_replace('-', ' ', $folder)), 'folder' => $folder, 'description' => 'DevOneCMS theme.', 'version' => '1.0.0', 'screenshot' => '', 'scope' => $scope);
    $json = $dir . '/theme.json';
    if (is_file($json)) {
        $data = json_decode((string)file_get_contents($json), true);
        if (is_array($data)) { $manifest = array_merge($manifest, array_intersect_key($data, $manifest)); }
    }
    $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
    foreach (array('screenshot.png','screenshot.jpg','screenshot.webp') as $shot) {
        if (is_file($dir . '/' . $shot)) {
            if ($scope === 'private') { $manifest['screenshot'] = 'content/sites/' . $siteId . '/themes/' . $folder . '/' . $shot; }
            elseif ($scope === 'entitled') {
                $root = realpath(dirname(__DIR__)); $real = realpath($dir . '/' . $shot);
                if ($root && $real) { $manifest['screenshot'] = ltrim(str_replace('\\','/',substr($real,strlen($root))),'/'); }
            } else { $manifest['screenshot'] = 'content/themes/' . $folder . '/' . $shot; }
            break;
        }
    }
    return $manifest;
}

function devone_theme_scan_version($dirs) {
    $parts = array();
    foreach ((array)$dirs as $dir) {
        $dir = rtrim((string)$dir, '/\\');
        $parts[] = $dir . ':' . (is_dir($dir) ? (string)@filemtime($dir) : 'missing');
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: array() as $child) {
            $parts[] = basename($child) . ':' . (string)@filemtime($child);
            foreach (array('theme.css','theme.json','screenshot.png','screenshot.jpg','screenshot.webp') as $file) {
                $path = $child . '/' . $file;
                if (is_file($path)) { $parts[] = basename($child) . '/' . $file . ':' . (string)@filemtime($path); }
            }
        }
    }
    return sha1(implode('|', $parts));
}

function devone_scan_themes($force = false) {
    $global_dir = devone_global_theme_dir();
    $private_dir = devone_site_private_theme_dir();
    if (!is_dir($global_dir)) { @mkdir($global_dir, 0775, true); }
    if (!is_dir($private_dir)) { @mkdir($private_dir, 0775, true); }

    $siteId = function_exists('devone_content_site_id') ? devone_content_site_id() : 1;
    $version = devone_theme_scan_version(array($private_dir, $global_dir));
    $cacheKey = 'themes-' . $siteId . '-' . $version;
    if (!$force && function_exists('devone_cache_get')) {
        $cached = devone_cache_get('themes', $cacheKey, null);
        if (is_array($cached)) { return $cached; }
    }

    $out = array();
    $seen = array();
    foreach (glob($private_dir . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
        if (!is_file($dir . '/theme.css')) { continue; }
        $folder = basename($dir);
        $out[] = devone_theme_manifest($folder, $private_dir, 'private');
        $seen[$folder] = true;
    }
    if (class_exists('DevOne') && DevOne::services()->has('theme-entitlements')) {
        try {
            foreach (DevOne::service('theme-entitlements')->listForSite($siteId) as $package) {
                $folder = (string)($package['entitled_slug'] ?? $package['theme_slug'] ?? '');
                if ($folder === '' || isset($seen[$folder]) || empty($package['storage_path'])) { continue; }
                $dir = dirname(__DIR__) . '/' . ltrim((string)$package['storage_path'], '/');
                if (!is_file($dir . '/theme.css')) { continue; }
                $manifest = devone_theme_manifest($folder, dirname($dir), 'entitled');
                $manifest['name'] = (string)($package['theme_name'] ?? $manifest['name']);
                $manifest['version'] = (string)($package['theme_version'] ?? $manifest['version']);
                $manifest['package_hash'] = (string)($package['package_hash'] ?? '');
                $out[] = $manifest;
                $seen[$folder] = true;
            }
        } catch (Throwable $e) {}
    }
    foreach (glob($global_dir . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
        if (!is_file($dir . '/theme.css')) { continue; }
        $folder = basename($dir);
        if (isset($seen[$folder])) { continue; }
        $out[] = devone_theme_manifest($folder, $global_dir, 'system');
    }
    usort($out, function($a,$b){ return strcasecmp($a['name'], $b['name']); });
    if (function_exists('devone_cache_set')) { devone_cache_set('themes', $cacheKey, $out, 600); }
    return $out;
}

function devone_register_theme($name, $folder, $active = 0) {
    $tbl = devone_require_table('themes', true);
    if ($tbl === '') { return false; }
    $folder = devone_slugify($folder, 'theme');
    $name = trim((string)$name) ?: ucwords(str_replace('-', ' ', $folder));
    $cols = function_exists('devone_table_columns') ? devone_table_columns('themes') : array();
    $siteId = in_array('site_id', $cols, true) ? devone_content_site_id() : null;
    if ($siteId !== null) {
        $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (site_id,name,folder,active) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), active=VALUES(active)');
        return $stmt->execute(array($siteId, $name, $folder, (int)$active));
    }
    $stmt = db()->prepare('INSERT INTO `' . $tbl . '` (name,folder,active) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), active=VALUES(active)');
    return $stmt->execute(array($name, $folder, (int)$active));
}


/* DevOneCMS v1.1.12 Front-End Scripts Manager helpers */
function devone_code_setting($key, $default = '') {
    $value = get_setting($key, $default);
    return is_string($value) ? $value : (string)$value;
}

function devone_strip_wrapping_tag($code, $tag) {
    $code = trim((string)$code);
    if ($code === '') { return ''; }
    $tag = preg_quote($tag, '/');
    $code = preg_replace('/^\s*<' . $tag . '\b[^>]*>/i', '', $code);
    $code = preg_replace('/<\/' . $tag . '>\s*$/i', '', $code);
    return trim((string)$code);
}

function devone_render_frontend_head_extras() {
    if (get_setting('frontend_scripts_enabled', '1') !== '1') { return; }
    $css = devone_strip_wrapping_tag(devone_code_setting('frontend_custom_css', ''), 'style');
    if (trim($css) !== '') {
        echo "\n<style id=\"devone-custom-css\">\n" . $css . "\n</style>\n";
    }
    $head = devone_code_setting('frontend_head_code', '');
    if (trim($head) !== '') {
        echo "\n<!-- DevOneCMS Custom Head Code -->\n" . $head . "\n<!-- /DevOneCMS Custom Head Code -->\n";
    }
}

function devone_render_frontend_footer_scripts() {
    if (get_setting('frontend_scripts_enabled', '1') !== '1') { return; }
    $global = devone_strip_wrapping_tag(devone_code_setting('frontend_footer_js', ''), 'script');
    $ready = devone_strip_wrapping_tag(devone_code_setting('frontend_page_ready_js', ''), 'script');

    if (trim($global) !== '') {
        echo "\n<script id=\"devone-custom-global-js\">\ntry {\n" . $global . "\n} catch (error) { console.error('[DevOneCMS Global JS]', error); }\n</script>\n";
    }

    if (trim($ready) !== '') {
        echo "\n<script id=\"devone-custom-page-ready-js\">\n(function(){\n";
        echo "  function runDevOnePageReady(event){\n";
        echo "    const devone = event && event.detail ? event.detail : {reason:'manual', url:location.href, container:document.getElementById('cms-main') || document};\n";
        echo "    const container = devone.container || document;\n";
        echo "    const url = devone.url || location.href;\n";
        echo "    const reason = devone.reason || 'manual';\n";
        echo "    try {\n" . $ready . "\n    } catch (error) { console.error('[DevOneCMS Page Ready JS]', error); }\n";
        echo "  }\n";
        echo "  document.addEventListener('devone:ready', runDevOnePageReady);\n";
        echo "})();\n</script>\n";
    }
}



function devone_purge_after_delete($area = 'all') {
    if (function_exists('devone_cache_flush')) { @devone_cache_flush(); }
    if (function_exists('devone_settings_cache_reset')) { @devone_settings_cache_reset(); }
    if (function_exists('devone_log')) { @devone_log('purge_after_delete', (string)$area); }
    return true;
}


if (is_file(__DIR__ . '/services.php')) { require_once __DIR__ . '/services.php'; }
