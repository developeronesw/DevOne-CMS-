<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();
devone_require_permission('manage_settings');

$msg = '';
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_setting('site_name', trim($_POST['site_name'] ?? 'DevOneCMS') ?: 'DevOneCMS');
    set_setting('site_tagline', trim($_POST['site_tagline'] ?? 'Developer-first CMS'));
    set_setting('footer_text', trim($_POST['footer_text'] ?? 'Built with DevOneCMS'));
    set_setting('primary_menu', devone_slug($_POST['primary_menu'] ?? 'main'));

    $layout = $_POST['menu_layout'] ?? 'top-sticky';
    $allowedLayouts = array('none', 'top-static', 'top-sticky', 'bottom-sticky', 'offcanvas-left', 'offcanvas-right');
    if (!in_array($layout, $allowedLayouts, true)) { $layout = 'top-sticky'; }
    set_setting('menu_layout', $layout);

    $menuStyle = $_POST['menu_style'] ?? 'classic';
    $allowedMenuStyles = array('classic', 'corporate-glass-gold', 'prism-flux', 'orbit-rail', 'kinetic-cards');
    if (!in_array($menuStyle, $allowedMenuStyles, true)) { $menuStyle = 'classic'; }
    set_setting('menu_style', $menuStyle);

    $widthMode = $_POST['site_width_mode'] ?? 'boxed';
    $allowedWidthModes = array('boxed', 'full', 'edge2edge');
    if (!in_array($widthMode, $allowedWidthModes, true)) { $widthMode = 'boxed'; }
    set_setting('site_width_mode', $widthMode);

    $permalinkStyle = $_POST['permalink_style'] ?? 'clean';
    $allowedPermalinkStyles = array('clean', 'page-prefix', 'legacy');
    if (!in_array($permalinkStyle, $allowedPermalinkStyles, true)) { $permalinkStyle = 'clean'; }
    set_setting('permalink_style', $permalinkStyle);

    set_setting('show_admin_link_frontend', !empty($_POST['show_admin_link_frontend']) ? '1' : '0');
    $defaultRole = devone_slug($_POST['default_user_role'] ?? 'subscriber');
    if ($defaultRole === '') { $defaultRole = 'subscriber'; }
    if (function_exists('devone_safe_public_registration_role')) {
        $safeRole = devone_safe_public_registration_role($defaultRole);
        if ($safeRole === '') { $errors[] = 'Default registration role was not changed because that role has privileged permissions.'; }
        else { set_setting('default_user_role', $safeRole); }
    } else { set_setting('default_user_role', $defaultRole); }
    set_setting('allow_public_registration', !empty($_POST['allow_public_registration']) ? '1' : '0');
    set_setting('debug_mode', !empty($_POST['debug_mode']) ? '1' : '0');

    $adminThemeMode = !empty($_POST['admin_theme_light']) ? 'light' : 'dark';
    set_setting('admin_theme_mode', $adminThemeMode);

    if (!empty($_POST['remove_logo'])) {
        set_setting('site_logo', '');
    } elseif (!empty($_FILES['site_logo']['name'])) {
        $upload = devone_upload_site_logo($_FILES['site_logo']);
        if (!empty($upload['ok'])) {
            set_setting('site_logo', $upload['path']);
        } else {
            $errors[] = $upload['message'] ?? 'Logo upload failed.';
        }
    }

    devone_log('settings_saved', 'Site customization settings updated');
    $msg = $errors ? 'Settings saved, but the logo upload needs attention.' : 'Settings saved. Refresh the front end to see the new branding.';
}

$menus = function_exists('devone_list_menus') ? devone_list_menus() : array();
if (!$menus && table_exists('menus')) {
    devone_save_menu('Main Menu', 'main', devone_default_menu_items());
    $menus = devone_list_menus();
}

$currentLogo = devone_site_logo_url();
$currentMenu = devone_active_menu_slug();
$currentLayout = devone_menu_layout();
$currentMenuStyle = function_exists('devone_menu_style') ? devone_menu_style() : 'classic';
$currentWidthMode = function_exists('devone_site_width_mode') ? devone_site_width_mode() : 'boxed';
$currentPermalinkStyle = function_exists('devone_permalink_style') ? devone_permalink_style() : 'clean';
$currentAdminThemeMode = get_setting('admin_theme_mode', 'dark');
$roles = function_exists('devone_list_roles') ? devone_list_roles() : array();
$registrationRoles = array_values(array_filter($roles, function($r){ return function_exists('devone_role_is_safe_for_public_registration') ? devone_role_is_safe_for_public_registration($r['name'] ?? '') : true; }));
$currentDefaultRole = get_setting('default_user_role', 'subscriber');
devone_admin_header('Site Settings - DevOneCMS');
?>
<section class="devone-settings-hero">
  <div>
    <p class="admin-kicker"><span></span> Site Configuration</p>
    <h1>Site Settings</h1>
    <p class="muted">Configure DevOne from top to bottom. Each area now has its own full-width section so settings stay readable as the CMS grows.</p>
  </div>
  <div class="devone-settings-hero-meta">
    <span>8 Sections</span>
    <strong>One clean workflow</strong>
  </div>
</section>
<?php devone_flash($msg); ?>
<?php foreach ($errors as $error): ?>
    <p class="card error-card"><?= e($error) ?></p>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="settings-grid devone-settings-stack">
    <?= csrf_field() ?>
    <section class="card devone-settings-section" data-settings-section="branding">
        <div class="devone-settings-section-head"><span class="devone-settings-section-index">01</span><div><h2>Branding</h2><p>Define the public identity visitors see across the site.</p></div></div>
        <div class="devone-settings-section-content">
        <label>Site Name
            <input name="site_name" value="<?= e(get_setting('site_name', 'DevOneCMS')) ?>" placeholder="DevOneCMS">
        </label>
        <label>Site Tagline
            <input name="site_tagline" value="<?= e(get_setting('site_tagline', 'Developer-first CMS')) ?>" placeholder="Developer-first CMS">
        </label>
        <label>Footer Text
            <input name="footer_text" value="<?= e(get_setting('footer_text', 'Built with DevOneCMS')) ?>" placeholder="Built with DevOneCMS">
        </label>
        </div>
    </section>

    <section class="card devone-settings-section" data-settings-section="logo">
        <div class="devone-settings-section-head"><span class="devone-settings-section-index">02</span><div><h2>Site Logo</h2><p>Upload or remove the primary brand mark used by the front end.</p></div></div>
        <div class="devone-settings-section-content">
        <?php if ($currentLogo): ?>
            <div class="logo-preview-wrap">
                <img src="<?= e($currentLogo) ?>" alt="Current site logo" class="logo-preview">
                <label class="inline-check"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
            </div>
        <?php else: ?>
            <p class="muted">No custom logo uploaded yet. The front end will show the DevOne icon until you upload one.</p>
        <?php endif; ?>
        <label>Upload Logo
            <input type="file" name="site_logo" accept="image/png,image/jpeg,image/webp,image/gif">
        </label>
        <p class="muted">Recommended: transparent PNG or WEBP, under 3MB.</p>
        </div>
    </section>

    <section class="card devone-settings-section" data-settings-section="menu">
        <div class="devone-settings-section-head"><span class="devone-settings-section-index">03</span><div><h2>Menu Display</h2><p>Choose the active menu, position, visual system, and public admin link.</p></div></div>
        <div class="devone-settings-section-content">
        <label>Active Menu
            <select name="primary_menu">
                <?php foreach ($menus as $menu): ?>
                    <option value="<?= e($menu['slug']) ?>" <?= $currentMenu === $menu['slug'] ? 'selected' : '' ?>><?= e($menu['name']) ?> — <?= e($menu['slug']) ?></option>
                <?php endforeach; ?>
                <?php if (!$menus): ?><option value="main">Main Menu</option><?php endif; ?>
            </select>
        </label>
        <label>Menu Layout
            <select name="menu_layout">
                <option value="none" <?= $currentLayout === 'none' ? 'selected' : '' ?>>No menu — content only</option>
                <option value="top-static" <?= $currentLayout === 'top-static' ? 'selected' : '' ?>>Top - normal</option>
                <option value="top-sticky" <?= $currentLayout === 'top-sticky' ? 'selected' : '' ?>>Top - sticky</option>
                <option value="bottom-sticky" <?= $currentLayout === 'bottom-sticky' ? 'selected' : '' ?>>Bottom - sticky</option>
                <option value="offcanvas-left" <?= $currentLayout === 'offcanvas-left' ? 'selected' : '' ?>>Off canvas - slide from left</option>
                <option value="offcanvas-right" <?= $currentLayout === 'offcanvas-right' ? 'selected' : '' ?>>Off canvas - slide from right</option>
            </select>
        </label>
        <label>Menu Style
            <select name="menu_style" id="devoneMenuStyleSelect">
                <option value="classic" <?= $currentMenuStyle === 'classic' ? 'selected' : '' ?>>Classic / Theme-Compatible</option>
                <option value="corporate-glass-gold" <?= $currentMenuStyle === 'corporate-glass-gold' ? 'selected' : '' ?>>Corporate Glass — Adaptive Luxe</option>
                <option value="prism-flux" <?= $currentMenuStyle === 'prism-flux' ? 'selected' : '' ?>>Prism Flux — Holographic Rail</option>
                <option value="orbit-rail" <?= $currentMenuStyle === 'orbit-rail' ? 'selected' : '' ?>>Orbit Rail — Floating Capsules</option>
                <option value="kinetic-cards" <?= $currentMenuStyle === 'kinetic-cards' ? 'selected' : '' ?>>Kinetic Cards — Stacked Motion</option>
            </select>
        </label>
        <div class="devone-menu-style-previews" aria-label="Menu style previews">
            <button class="devone-menu-style-preview classic-preview<?= $currentMenuStyle === 'classic' ? ' selected' : '' ?>" type="button" data-menu-style-choice="classic">
                <span class="preview-brand">D1</span><span class="preview-links"><i></i><i></i><i></i></span>
                <strong>Classic</strong><small>Uses the active theme's navigation colors.</small>
            </button>
            <button class="devone-menu-style-preview corporate-gold-preview<?= $currentMenuStyle === 'corporate-glass-gold' ? ' selected' : '' ?>" type="button" data-menu-style-choice="corporate-glass-gold">
                <span class="preview-brand">D1</span><span class="preview-links"><i></i><i></i><i></i></span>
                <strong>Corporate Glass</strong><small>Theme-aware glass with premium metallic accents.</small>
            </button>
            <button class="devone-menu-style-preview prism-flux-preview<?= $currentMenuStyle === 'prism-flux' ? ' selected' : '' ?>" type="button" data-menu-style-choice="prism-flux">
                <span class="preview-brand">✦</span><span class="preview-links"><i></i><i></i><i></i></span>
                <strong>Prism Flux</strong><small>Holographic edge, luminous rail, layered glass links.</small>
            </button>
            <button class="devone-menu-style-preview orbit-rail-preview<?= $currentMenuStyle === 'orbit-rail' ? ' selected' : '' ?>" type="button" data-menu-style-choice="orbit-rail">
                <span class="preview-brand">◉</span><span class="preview-links"><i></i><i></i><i></i></span>
                <strong>Orbit Rail</strong><small>Floating capsule navigation with orbital accents.</small>
            </button>
            <button class="devone-menu-style-preview kinetic-cards-preview<?= $currentMenuStyle === 'kinetic-cards' ? ' selected' : '' ?>" type="button" data-menu-style-choice="kinetic-cards">
                <span class="preview-brand">◆</span><span class="preview-links"><i></i><i></i><i></i></span>
                <strong>Kinetic Cards</strong><small>Layered menu cards with punchy motion and depth.</small>
            </button>
        </div>
        <p class="muted">Menu position and visual style remain independent. Choose No Menu for content-only pages, or combine any visual style with top, bottom, left, or right navigation. All new styles inherit the active front-end theme tokens.</p>
        <label class="inline-check"><input type="checkbox" name="show_admin_link_frontend" value="1" <?= get_setting('show_admin_link_frontend', '1') === '1' ? 'checked' : '' ?>> Show Admin link on public menu bar</label>
        </div>
    </section>

    <section class="card devone-settings-section" data-settings-section="width">
        <div class="devone-settings-section-head"><span class="devone-settings-section-index">04</span><div><h2>Site Width</h2><p>Control how much of the viewport the active theme and page content may use.</p></div></div>
        <div class="devone-settings-section-content">
        <label>Layout Mode
            <select name="site_width_mode">
                <option value="boxed" <?= $currentWidthMode === 'boxed' ? 'selected' : '' ?>>Boxed / centered theme content</option>
                <option value="full" <?= $currentWidthMode === 'full' ? 'selected' : '' ?>>Full width / theme-integrated content</option>
                <option value="edge2edge" <?= $currentWidthMode === 'edge2edge' ? 'selected' : '' ?>>Edge2Edge / raw HTML canvas</option>
            </select>
        </label>
        <p class="muted"><strong>Boxed</strong> keeps theme-rendered pages inside a centered container. <strong>Full width</strong> removes the Core page-width limit while still allowing the active theme to provide its normal page template. <strong>Edge2Edge</strong> removes the CMS content wrapper and bypasses supported theme page templates so self-contained HTML controls its own typography, sections, colors, spacing, and internal containers.</p>
        <p class="muted"><strong>Use Edge2Edge</strong> for custom landing pages, full HTML designs, visual-builder canvases, documentation experiences, and application-style pages. The selected DevOne navigation and plugin hooks remain available; the Core footer is omitted so the page can supply its own footer.</p>
        <p class="muted">Developer utility classes for Boxed and Full Width modes: <code>.devone-boxed-content</code>, <code>.boxed-content</code>, <code>.alignwide</code>, <code>.devone-page-gutter</code>, <code>.devone-full-bleed</code>, <code>.full-bleed</code>, and <code>.alignfull</code>.</p>
        </div>
    </section>

    <section class="card devone-settings-section" data-settings-section="permalinks">
        <div class="devone-settings-section-head"><span class="devone-settings-section-index">05</span><div><h2>Permalinks</h2><p>Choose how DevOne publishes human-readable page URLs.</p></div></div>
        <div class="devone-settings-section-content">
        <label>Permalink Style
            <select name="permalink_style">
                <option value="clean" <?= $currentPermalinkStyle === 'clean' ? 'selected' : '' ?>>Clean URLs — /about-us (Recommended)</option>
                <option value="page-prefix" <?= $currentPermalinkStyle === 'page-prefix' ? 'selected' : '' ?>>Page Prefix — /page/about-us</option>
                <option value="legacy" <?= $currentPermalinkStyle === 'legacy' ? 'selected' : '' ?>>Legacy Query — /?page=about-us</option>
            </select>
        </label>
        <div class="devone-permalink-example">
            <strong>Current example</strong>
            <code><?= e(devone_page_url('about-us')) ?></code>
        </div>
        <p class="muted">Clean URLs are recommended for new public sites. Existing <code>?page=slug</code> links remain compatible; when Clean or Page Prefix is active, normal legacy page requests are permanently redirected to the canonical URL.</p>
        <p class="muted"><strong>Nginx:</strong> the virtual host must fall back unknown public paths to <code>/index.php?$query_string</code>. Apache installs use the bundled <code>.htaccess</code> front-controller rules.</p>
        </div>
    </section>

    <section class="card devone-admin-appearance-card devone-settings-section" data-settings-section="appearance">
        <div class="devone-settings-section-head"><span class="devone-settings-section-index">06</span><div><h2>Admin Appearance</h2><p>Set the visual appearance of the DevOne administration experience.</p></div></div>
        <div class="devone-settings-section-content">
        <p class="muted">Switch the Developer One CMS admin between the original dark command-center theme and the new Corporate Glass white-and-gold dashboard.</p>
        <label class="devone-theme-toggle">
            <input type="checkbox" name="admin_theme_light" value="1" <?= $currentAdminThemeMode === 'light' ? 'checked' : '' ?>>
            <span class="devone-theme-toggle-ui" aria-hidden="true"></span>
            <span>
                <strong>Use Corporate Glass — White & Gold</strong>
                <small>Turn off to return to the original dark DevOne command-center style.</small>
            </span>
        </label>
        <div class="devone-theme-preview-grid">
            <div class="devone-theme-preview dark-preview">
                <strong>Dark</strong>
                <small>Original DevOne command center.</small>
            </div>
            <div class="devone-theme-preview light-preview">
                <strong>White &amp; Gold</strong>
                <small>Corporate Glass executive dashboard.</small>
            </div>
        </div>
        </div>
    </section>

    <section class="card devone-settings-section" data-settings-section="registration">
        <div class="devone-settings-section-head"><span class="devone-settings-section-index">07</span><div><h2>Registration</h2><p>Manage public registration and the role assigned to new accounts.</p></div></div>
        <div class="devone-settings-section-content">
        <label class="inline-check"><input type="checkbox" name="allow_public_registration" value="1" <?= get_setting('allow_public_registration', '0') === '1' ? 'checked' : '' ?>> Allow public user registration</label>
        <label>Default New User Role
            <select name="default_user_role">
                <?php foreach ($registrationRoles as $roleRow): ?>
                    <option value="<?= e($roleRow['name']) ?>" <?= $currentDefaultRole === ($roleRow['name'] ?? '') ? 'selected' : '' ?>><?= e($roleRow['name']) ?></option>
                <?php endforeach; ?>
                <?php if (!$registrationRoles): ?><option value="subscriber">subscriber (configure as zero-permission role)</option><?php endif; ?>
            </select>
        </label>
        <p class="muted">New users from <code>/admin/register.php</code> are assigned this role. Keep <strong>subscriber</strong> as the safe default until an admin/developer grants more access.</p>
        </div>
    </section>

    <section class="card devone-settings-section" data-settings-section="developer">
        <div class="devone-settings-section-head"><span class="devone-settings-section-index">08</span><div><h2>Developer</h2><p>Developer diagnostics, CLI shortcuts, and SMTP access live here.</p></div></div>
        <div class="devone-settings-section-content">
        <label class="inline-check"><input type="checkbox" name="debug_mode" value="1" <?= get_setting('debug_mode', '0') === '1' ? 'checked' : '' ?>> Enable Debug Mode</label>
        <p class="muted">Keep debug mode off on live production sites once testing is complete.</p>
        <pre>php devone make:plugin MyPlugin
php devone make:theme MyTheme
php devone make:module Blog
php devone cache:clear</pre>
        <p><a class="btn" href="email.php">Open SMTP Settings</a></p>
        </div>
    </section>

    <div class="settings-actions">
        <button>Save Settings</button>
        <a class="btn" href="../index.php" target="_blank" rel="noopener">View Site</a>
        <a class="btn" href="menus.php">Edit Menus</a>
    </div>
</form>
<script>
(function(){
    var select = document.getElementById('devoneMenuStyleSelect');
    var choices = document.querySelectorAll('[data-menu-style-choice]');
    if (!select || !choices.length) return;
    function sync(value){
        choices.forEach(function(choice){
            choice.classList.toggle('selected', choice.getAttribute('data-menu-style-choice') === value);
        });
    }
    choices.forEach(function(choice){
        choice.addEventListener('click', function(){
            select.value = choice.getAttribute('data-menu-style-choice') || 'classic';
            sync(select.value);
        });
    });
    select.addEventListener('change', function(){ sync(select.value); });
})();
</script>
<?php devone_admin_footer();
