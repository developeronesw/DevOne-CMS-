<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_settings');
verify_csrf();

$msg = '';
$errors = array();

function devone_scripts_post_value($key) {
    return trim((string)($_POST[$key] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'clear') {
        set_setting('frontend_custom_css', '');
        set_setting('frontend_head_code', '');
        set_setting('frontend_footer_js', '');
        set_setting('frontend_page_ready_js', '');
        devone_log('frontend_scripts_cleared', 'Front-end custom scripts were cleared');
        $msg = 'Front-end script fields cleared.';
    } else {
        set_setting('frontend_scripts_enabled', !empty($_POST['frontend_scripts_enabled']) ? '1' : '0');
        set_setting('frontend_ajax_enabled', !empty($_POST['frontend_ajax_enabled']) ? '1' : '0');
        set_setting('frontend_custom_css', devone_scripts_post_value('frontend_custom_css'));
        set_setting('frontend_head_code', devone_scripts_post_value('frontend_head_code'));
        set_setting('frontend_footer_js', devone_scripts_post_value('frontend_footer_js'));
        set_setting('frontend_page_ready_js', devone_scripts_post_value('frontend_page_ready_js'));
        devone_log('frontend_scripts_saved', 'Front-end scripts manager updated');
        $msg = 'Front-end scripts saved. Hard refresh the public site and test the page again.';
    }
}

$enabled = get_setting('frontend_scripts_enabled', '1') === '1';
$ajaxEnabled = get_setting('frontend_ajax_enabled', '1') === '1';
$customCss = get_setting('frontend_custom_css', '');
$headCode = get_setting('frontend_head_code', '');
$footerJs = get_setting('frontend_footer_js', '');
$pageReadyJs = get_setting('frontend_page_ready_js', '');

$exampleReady = "// This runs on initial page load AND after DevOneCMS AJAX page changes.\n// Available variables: devone, container, url, reason\n\nconst cards = container.querySelectorAll('.devone-feature-card, .dv-difference-card');\ncards.forEach((card, index) => {\n  setTimeout(() => card.classList.add('show'), index * 60);\n});\n\nconsole.log('DevOne page ready:', reason, url);";

devone_admin_header('Front-End Scripts - DevOneCMS');
?>
<h1>Front-End Scripts</h1>
<p class="muted">Move page JavaScript out of the page body and into one controlled admin area. This prevents AJAX-loaded pages from missing scripts until you refresh.</p>
<?php devone_flash($msg); ?>
<?php foreach ($errors as $error): ?><p class="card error-card"><?= e($error) ?></p><?php endforeach; ?>

<div class="script-manager-grid">
    <form method="post" class="script-manager-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">

        <section class="card">
            <h2>Script Engine</h2>
            <label class="inline-check"><input type="checkbox" name="frontend_scripts_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Enable custom front-end scripts</label>
            <label class="inline-check"><input type="checkbox" name="frontend_ajax_enabled" value="1" <?= $ajaxEnabled ? 'checked' : '' ?>> Enable AJAX navigation on public pages</label>
            <p class="muted">Keep AJAX enabled for the app-like feel. Disable it temporarily if a third-party script refuses to reinitialize after page changes.</p>
        </section>

        <section class="card full-row">
            <h2>Custom CSS</h2>
            <p class="muted">Optional. Paste CSS only. Do not include <code>&lt;style&gt;</code> tags.</p>
            <textarea class="code-textarea" name="frontend_custom_css" spellcheck="false" placeholder=".my-slider { min-height: 80vh; }\n.my-card.show { opacity: 1; }"><?= e($customCss) ?></textarea>
        </section>

        <section class="card full-row">
            <h2>Head Code</h2>
            <p class="muted">Optional advanced field for analytics, preload tags, or external scripts that must load in the document head. This field can contain full HTML tags.</p>
            <textarea class="code-textarea short" name="frontend_head_code" spellcheck="false" placeholder="&lt;script src=&quot;https://cdn.example.com/library.js&quot;&gt;&lt;/script&gt;"><?= e($headCode) ?></textarea>
        </section>

        <section class="card full-row">
            <h2>Global Footer JavaScript</h2>
            <p class="muted">Runs once when the public site loads. Paste JavaScript only. Do not include <code>&lt;script&gt;</code> tags.</p>
            <textarea class="code-textarea" name="frontend_footer_js" spellcheck="false" placeholder="console.log('DevOneCMS global JS loaded');"><?= e($footerJs) ?></textarea>
        </section>

        <section class="card full-row">
            <h2>Page Ready JavaScript</h2>
            <p class="muted">Best place for sliders, grids, counters, animations, and page widgets. It runs on initial load and every DevOneCMS AJAX page load.</p>
            <textarea class="code-textarea tall" name="frontend_page_ready_js" spellcheck="false" placeholder="<?= e($exampleReady) ?>"><?= e($pageReadyJs) ?></textarea>
            <div class="script-help-box">
                <strong>Available inside this box:</strong>
                <code>devone</code>, <code>container</code>, <code>url</code>, and <code>reason</code>.
                <p>Use <code>container.querySelector(...)</code> instead of <code>document.querySelector(...)</code> when possible. That keeps scripts focused on the current page content after AJAX navigation.</p>
            </div>
        </section>

        <div class="settings-actions full-row">
            <button type="submit">Save Front-End Scripts</button>
            <a class="btn" href="../index.php" target="_blank" rel="noopener">View Site</a>
            <a class="btn" href="pages.php">Back to Pages</a>
        </div>
    </form>

    <aside class="script-sidebar">
        <section class="card">
            <h2>Why this exists</h2>
            <p class="muted">When DevOneCMS loads page content with AJAX, browser-inserted inline scripts inside page content may not always run the way a normal full page refresh does.</p>
            <p class="muted">This manager gives the CMS one reliable JavaScript loading zone for the whole front end.</p>
        </section>

        <section class="card">
            <h2>Recommended workflow</h2>
            <ol class="script-steps">
                <li>Put HTML in the page editor.</li>
                <li>Put CSS here or in the theme.</li>
                <li>Put page behavior in <strong>Page Ready JavaScript</strong>.</li>
                <li>Use classes/IDs in the HTML so the script can find them.</li>
            </ol>
        </section>

        <section class="card">
            <h2>Example Page Ready JS</h2>
            <pre><?= e($exampleReady) ?></pre>
        </section>

        <form method="post" class="card" onsubmit="return confirm('Clear all custom front-end CSS and JavaScript fields?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear">
            <h2>Reset</h2>
            <p class="muted">Clears only these custom script fields. It does not delete pages, themes, media, or libraries.</p>
            <button type="submit">Clear Script Fields</button>
        </form>
    </aside>
</div>
<?php devone_admin_footer();
