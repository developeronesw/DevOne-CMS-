<?php
if (!is_file(__DIR__ . '/config.php')) { header('Location: install.php'); exit; }
require 'config.php';
if (is_file(__DIR__ . '/core/version.php')) { require_once __DIR__ . '/core/version.php'; }
if (!defined('DEVONE_INSTALLED') || DEVONE_INSTALLED !== true) { header('Location: install.php'); exit; }
require 'core/db.php';
require 'core/hooks.php';
require 'core/schema.php';
require 'core/functions.php';
if (is_file(__DIR__ . '/core/runtime.php')) { require_once __DIR__ . '/core/runtime.php'; }
if (is_file(__DIR__ . '/core/license.php')) { require 'core/license.php'; }
if (is_file(__DIR__ . '/core/network.php')) { require 'core/network.php'; }
require 'core/security.php';
require 'core/assets.php';
if (is_file(__DIR__ . '/core/plugins.php')) { require 'core/plugins.php'; }
if (is_file(__DIR__ . '/core/modules.php')) { require 'core/modules.php'; }
if (is_file(__DIR__ . '/core/themes_runtime.php')) { require 'core/themes_runtime.php'; }

// Schema repair is intentionally excluded from normal request execution.
if (function_exists('devone_load_active_plugins')) { devone_load_active_plugins(); }
if (function_exists('devone_load_active_modules')) { devone_load_active_modules(); }
if (function_exists('devone_load_active_theme')) { devone_load_active_theme(); }
if (function_exists('devone_runtime_boot_context')) { devone_runtime_boot_context(); }

$ajax = isset($_GET['ajax']) && $_GET['ajax'] == 1;
$slug = devone_current_slug();

// Canonicalize legacy ?page= URLs for normal browser GET requests while keeping
// AJAX and preview requests backward compatible.
if (!$ajax && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['page']) && devone_permalink_style() !== 'legacy' && !isset($_GET['preview_theme'])) {
    $target = devone_page_url($slug);
    $extra = $_GET;
    unset($extra['page']);
    if ($extra) { $target .= (strpos($target, '?') === false ? '?' : '&') . http_build_query($extra); }
    header('Location: ' . $target, true, 301);
    exit;
}

do_action('before_route', $slug);
$moduleRoute = function_exists('devone_dispatch_module_route') ? devone_dispatch_module_route() : array('matched'=>false);
if (!empty($moduleRoute['matched']) && function_exists('devone_module_send_route_response') && devone_module_send_route_response($moduleRoute)) { exit; }
if (!empty($moduleRoute['matched'])) {
    http_response_code((int)($moduleRoute['status'] ?? 200));
    $page = array(
        'title' => (string)($moduleRoute['title'] ?? 'Module'),
        'content' => (string)($moduleRoute['content'] ?? ''),
        'template' => (string)($moduleRoute['template'] ?? 'default'),
        'show_title' => array_key_exists('show_title', $moduleRoute) ? (int)$moduleRoute['show_title'] : 0,
    );
} else {
    $page = ($slug === 'home') ? get_home_page() : get_page($slug);
    if (!$page && $slug !== 'home') {
        http_response_code(404);
        $page = array(
            'title' => 'Page Not Found',
            'content' => '<section class="hero devone-404"><p class="muted">404</p><h1>Page Not Found</h1><p>The page you requested does not exist or is not published.</p><p><a class="button" href="' . e(devone_site_url('/')) . '">Return Home</a></p></section>',
            'template' => '404',
            'show_title' => 0,
        );
    }
}
if (!$page && table_exists('pages')) { devone_seed_home_page(); $page = get_home_page(); }
if (!$page) {
    http_response_code(404);
    $page = array(
        'title' => 'DevOneCMS Setup Needed',
        'content' => '<section class="hero"><h1>DevOneCMS</h1><p>No published pages were found. Open Admin and create a published page with the slug <code>home</code>.</p><p><a class="button" href="admin/">Open Admin</a></p></section>'
    );
}
$runtimeContent = function_exists('devone_runtime_prepare_content') ? devone_runtime_prepare_content($page['content']) : $page['content'];
$content = apply_filters('page_content', $runtimeContent, $page);
do_action('before_render', $page);
if ($ajax) {
    header('Content-Type: text/html; charset=utf-8');
    echo $content;
    exit;
}

$previewTheme = isset($_GET['preview_theme']) ? devone_slugify($_GET['preview_theme'], '') : '';
$theme = $previewTheme && is_dir(__DIR__ . '/content/themes/' . $previewTheme) ? $previewTheme : devone_theme();
$site_name = get_setting('site_name', 'DevOneCMS');
$tagline = get_setting('site_tagline', 'Developer-first CMS');
$logo = devone_site_logo_url();
$menuLayout = devone_menu_layout();
$menuStyle = function_exists('devone_menu_style') ? devone_menu_style() : 'classic';
$menuSlug = devone_active_menu_slug();
$showAdmin = get_setting('show_admin_link_frontend', '1') === '1';
$footerText = get_setting('footer_text', 'Built with DevOneCMS');
$navClass = 'site-nav menu-' . $menuLayout . ' menu-style-' . $menuStyle;
$siteWidthMode = function_exists('devone_site_width_mode') ? devone_site_width_mode() : 'boxed';
$bodyClass = 'layout-' . $menuLayout . ' site-width-' . $siteWidthMode . ' menu-style-' . $menuStyle;
$mainClass = 'site-main site-main-' . $siteWidthMode;
$frontendAjaxEnabled = get_setting('frontend_ajax_enabled', '1') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e(($page['title'] ?? 'Home') . ' - ' . $site_name) ?></title>
    <meta name="description" content="<?= e($tagline) ?>">
    <?php $canonicalUrl = (!empty($moduleRoute['matched']) || http_response_code() === 404) ? devone_current_public_url() : devone_page_url($slug); ?>
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <?php
    $themeCssUrl = function_exists('devone_theme_css_url') ? devone_theme_css_url($theme) : devone_site_url('content/themes/' . $theme . '/theme.css');
    $themeDir = function_exists('devone_find_theme_dir') ? devone_find_theme_dir($theme) : '';
    $themeCssVersion = ($themeDir && is_file($themeDir . '/theme.css')) ? (string)@filemtime($themeDir . '/theme.css') : '1';
    $coreCssVersion = is_file(__DIR__ . '/assets/css/devone.css') ? (string)@filemtime(__DIR__ . '/assets/css/devone.css') : '1.0.10';
    ?>
    <link rel="stylesheet" href="<?= e($themeCssUrl . (strpos($themeCssUrl, '?') === false ? '?' : '&') . 'v=' . $themeCssVersion) ?>">
    <link rel="stylesheet" href="<?= e(devone_site_url('assets/css/devone.css?v=' . $coreCssVersion)) ?>">
    <?php devone_render_frontend_head_extras(); ?>
    <?php do_action('devone_head'); ?>
</head>
<body class="<?= e($bodyClass) ?>" data-devone-width-mode="<?= e($siteWidthMode) ?>" data-devone-menu-style="<?= e($menuStyle) ?>">
<div class="site-shell site-shell-<?= e($siteWidthMode) ?>" data-devone-shell>
<?php if ($menuLayout !== 'none'): ?>
<header class="<?= e($navClass) ?>">
    <div class="nav-container">
        <a class="brand" href="<?= e(devone_site_url('/')) ?>" data-ajax>
            <?php if ($logo): ?>
                <img class="brand-logo-img" src="<?= e($logo) ?>" alt="<?= e($site_name) ?> logo">
            <?php else: ?>
                <span class="logo"><?= $menuStyle === 'corporate-glass-gold' ? 'D1' : '&lt;/&gt;' ?></span>
            <?php endif; ?>
            <span class="brand-text"><strong><?= e($site_name) ?></strong><small><?= e($tagline) ?></small></span>
        </a>

        <?php if (strpos($menuLayout, 'offcanvas') !== 0): ?>
            <nav class="menu-inline" aria-label="Primary menu">
                <?= devone_render_menu($menuSlug, 'primary-menu') ?>
            </nav>
            <?php if ($showAdmin): ?><a class="adminlink adminlink-inline" href="<?= e(ADMIN_URL) ?>">Admin</a><?php endif; ?>
        <?php endif; ?>

        <button class="nav-toggle" type="button" aria-label="Open menu" aria-controls="devoneMenuPanel" aria-expanded="false">☰</button>
        <div class="menu-overlay" data-close-menu></div>
        <nav id="devoneMenuPanel" class="menu-panel" aria-label="Primary menu">
            <button class="menu-close" type="button" aria-label="Close menu" data-close-menu>×</button>
            <?= devone_render_menu($menuSlug, 'primary-menu vertical-menu') ?>
            <?php if ($showAdmin): ?><a class="adminlink adminlink-panel" href="<?= e(ADMIN_URL) ?>">Admin</a><?php endif; ?>
        </nav>
    </div>
</header>
<?php endif; ?>

<main id="cms-main" class="<?= e($mainClass) ?>" tabindex="-1"><?= $content ?></main>
<footer class="footer"><div class="site-container"><?= e($footerText) ?></div></footer>
</div>

<script>
(function(){
    const siteBase = <?= json_encode(rtrim(SITE_URL, '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const ajaxEnabled = <?= $frontendAjaxEnabled ? 'true' : 'false' ?>;
    const body = document.body;
    const siteNav = document.querySelector('.site-nav');

    function parseCssColor(value){
        const match = String(value || '').match(/rgba?\(([^)]+)\)/i);
        if (match) {
            const parts = match[1].split(',').map(v => parseFloat(v.trim()));
            if (parts.length >= 3 && (parts.length < 4 || parts[3] > 0.06)) {
                return {r:parts[0], g:parts[1], b:parts[2]};
            }
        }
        const hex = String(value || '').match(/#([0-9a-f]{6}|[0-9a-f]{3})(?![0-9a-f])/i);
        if (hex) {
            let raw = hex[1];
            if (raw.length === 3) raw = raw.split('').map(c => c + c).join('');
            return {r:parseInt(raw.slice(0,2),16), g:parseInt(raw.slice(2,4),16), b:parseInt(raw.slice(4,6),16)};
        }
        return null;
    }

    function elementSurfaceColor(element){
        let node = element;
        while (node && node !== document.documentElement) {
            const css = getComputedStyle(node);
            let color = parseCssColor(css.backgroundColor);
            if (color) return color;
            if (css.backgroundImage && css.backgroundImage !== 'none') {
                color = parseCssColor(css.backgroundImage);
                if (color) return color;
            }
            node = node.parentElement;
        }
        const bodyCss = getComputedStyle(document.body);
        return parseCssColor(bodyCss.backgroundColor) || parseCssColor(bodyCss.backgroundImage) || {r:255,g:255,b:255};
    }

    function colorLuminance(color){
        const channels = [color.r, color.g, color.b].map(value => {
            const c = Math.max(0, Math.min(255, value)) / 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
    }

    function syncMenuContrast(){
        if (!siteNav) return;
        if (siteNav.classList.contains('menu-style-corporate-glass-gold')) {
            siteNav.dataset.devoneContrast = 'dark';
            return;
        }
        const rect = siteNav.getBoundingClientRect();
        const x = Math.max(1, Math.min(window.innerWidth - 2, rect.left + Math.min(rect.width * 0.5, window.innerWidth * 0.5)));
        const y = Math.max(1, Math.min(window.innerHeight - 2, rect.top + Math.min(rect.height * 0.5, 42)));
        const oldPointerEvents = siteNav.style.pointerEvents;
        siteNav.style.pointerEvents = 'none';
        const behind = document.elementFromPoint(x, y) || document.body;
        siteNav.style.pointerEvents = oldPointerEvents;
        const navContainer = siteNav.querySelector('.nav-container');
        const containerCss = navContainer ? getComputedStyle(navContainer) : null;
        const navCss = getComputedStyle(siteNav);
        const containerSurface = containerCss ? (parseCssColor(containerCss.backgroundColor) || parseCssColor(containerCss.backgroundImage)) : null;
        const navSurface = parseCssColor(navCss.backgroundColor) || parseCssColor(navCss.backgroundImage);
        const surface = containerSurface || navSurface || elementSurfaceColor(behind);
        siteNav.dataset.devoneContrast = colorLuminance(surface) > 0.46 ? 'dark' : 'light';
    }

    function syncMenuScrollState(){
        if (!siteNav) return;
        siteNav.classList.toggle('is-scrolled', window.scrollY > 18);
        syncMenuContrast();
    }
    syncMenuScrollState();
    window.addEventListener('scroll', syncMenuScrollState, {passive:true});
    window.addEventListener('resize', syncMenuContrast, {passive:true});
    document.addEventListener('devone:ready', () => setTimeout(syncMenuContrast, 30));

    function devoneMain(){ return document.getElementById('cms-main'); }

    function fireDevOneReady(reason, url, container){
        const detail = {
            reason: reason || 'initial',
            url: url || location.href,
            container: container || devoneMain() || document
        };
        document.dispatchEvent(new CustomEvent('devone:ready', {detail: detail}));
        if (reason === 'ajax') {
            document.dispatchEvent(new CustomEvent('devone:pageLoaded', {detail: detail}));
        }
    }

    function executeEmbeddedScripts(container){
        if (!container) return;
        const scripts = Array.from(container.querySelectorAll('script'));
        scripts.forEach(oldScript => {
            const script = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => script.setAttribute(attr.name, attr.value));
            if (oldScript.src) {
                script.src = oldScript.src;
                script.async = oldScript.async;
            } else {
                script.textContent = oldScript.textContent || '';
            }
            oldScript.parentNode.replaceChild(script, oldScript);
        });
    }

    function devoneDocumentKey(url){
        try {
            const parsed = new URL(url, location.href);
            return parsed.origin + parsed.pathname + parsed.search;
        } catch(e){ return ''; }
    }

    function devoneHashTarget(hash){
        if (!hash || hash === '#') return null;
        let id = String(hash).replace(/^#/, '');
        try { id = decodeURIComponent(id); } catch(e) {}
        return id ? document.getElementById(id) : null;
    }

    function devoneScrollToHash(hash, behavior){
        const target = devoneHashTarget(hash);
        if (!target) return false;
        const mode = behavior || 'smooth';
        requestAnimationFrame(() => requestAnimationFrame(() => {
            target.scrollIntoView({behavior: mode, block:'start'});
        }));
        return true;
    }

    let devoneActiveDocumentKey = devoneDocumentKey(location.href);

    function bindChrome(root){
        (root || document).querySelectorAll('.nav-toggle').forEach(button => {
            if (button.dataset.bound) return;
            button.dataset.bound = '1';
            button.addEventListener('click', () => {
                const isOpen = body.classList.toggle('menu-open');
                document.querySelectorAll('.nav-toggle').forEach(t => t.setAttribute('aria-expanded', isOpen ? 'true' : 'false'));
            });
        });
        (root || document).querySelectorAll('[data-close-menu]').forEach(button => {
            if (button.dataset.bound) return;
            button.dataset.bound = '1';
            button.addEventListener('click', () => {
                body.classList.remove('menu-open');
                document.querySelectorAll('.nav-toggle').forEach(t => t.setAttribute('aria-expanded', 'false'));
            });
        });
    }
    function isInternalUrl(url){
        try {
            const parsed = new URL(url, location.href);
            return parsed.origin === location.origin && parsed.pathname.indexOf('/admin/') === -1 && !parsed.pathname.endsWith('/install.php');
        } catch(e){ return false; }
    }
    function ajaxLoad(href, push){
        const destination = new URL(href, location.href);
        const requestedHash = destination.hash;
        const historyUrl = destination.pathname + destination.search + requestedHash;

        // URL fragments are browser-side state and must never be sent to PHP.
        const requestUrl = new URL(destination.toString());
        requestUrl.hash = '';
        requestUrl.searchParams.set('ajax', '1');

        const main = devoneMain();
        if (main) main.classList.add('is-loading');
        fetch(requestUrl.toString(), {headers:{'X-DevOne-Ajax':'1'}})
            .then(r => { if (!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
            .then(html => {
                if (main) {
                    main.innerHTML = html;
                    executeEmbeddedScripts(main);
                    main.classList.remove('is-loading');
                    main.focus({preventScroll:true});
                }
                if (push !== false) history.pushState(null, '', historyUrl);
                devoneActiveDocumentKey = devoneDocumentKey(destination.toString());
                body.classList.remove('menu-open');
                document.querySelectorAll('.nav-toggle').forEach(t => t.setAttribute('aria-expanded', 'false'));

                // Let page scripts/animation hooks initialize before moving the viewport.
                fireDevOneReady('ajax', destination.toString(), main);
                if (!requestedHash || !devoneScrollToHash(requestedHash, 'smooth')) {
                    window.scrollTo({top:0, behavior:'smooth'});
                }
            })
            .catch(() => { location.href = href; });
    }
    document.addEventListener('click', function(event){
        if (!ajaxEnabled) return;
        const link = event.target.closest('a[data-ajax]');
        if (!link) return;
        const href = link.getAttribute('href') || '';
        if (!href || href === '#' || link.target === '_blank' || !isInternalUrl(href)) return;

        const destination = new URL(href, location.href);
        const sameDocument = devoneDocumentKey(destination.toString()) === devoneActiveDocumentKey;

        // A same-page #anchor is not page navigation. Never AJAX-reload the page.
        if (sameDocument && destination.hash) {
            const target = devoneHashTarget(destination.hash);
            if (target) {
                event.preventDefault();
                const oldUrl = location.href;
                const newUrl = destination.pathname + destination.search + destination.hash;
                history.pushState(null, '', newUrl);
                devoneScrollToHash(destination.hash, 'smooth');
                try {
                    window.dispatchEvent(new HashChangeEvent('hashchange', {oldURL:oldUrl, newURL:location.href}));
                } catch(e) {}
                body.classList.remove('menu-open');
                document.querySelectorAll('.nav-toggle').forEach(t => t.setAttribute('aria-expanded', 'false'));
                return;
            }
        }

        event.preventDefault();
        ajaxLoad(destination.toString(), true);
    });
    window.addEventListener('popstate', () => {
        if (!ajaxEnabled) return;
        const currentKey = devoneDocumentKey(location.href);
        if (currentKey === devoneActiveDocumentKey) {
            if (!location.hash || !devoneScrollToHash(location.hash, 'auto')) {
                window.scrollTo({top:0, behavior:'auto'});
            }
            return;
        }
        ajaxLoad(location.href, false);
    });
    bindChrome(document);

    window.DevOneFrontend = window.DevOneFrontend || {};
    window.DevOneFrontend.ready = fireDevOneReady;
    window.DevOneFrontend.executeScripts = executeEmbeddedScripts;
    window.DevOneFrontend.ajaxLoad = ajaxLoad;

    function devoneInitialReady(){
        fireDevOneReady('initial', location.href, devoneMain());
        // Re-align initial #anchors after page animation/ready hooks have initialized.
        if (location.hash) devoneScrollToHash(location.hash, 'auto');
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', devoneInitialReady, {once:true});
    } else {
        setTimeout(devoneInitialReady, 0);
    }
})();
</script>
<?php devone_render_frontend_footer_scripts(); ?>
<?php do_action('devone_footer'); do_action('after_render', $page); ?>
</body>
</html>
