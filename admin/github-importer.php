<?php
require __DIR__ . '/includes/admin_common.php';
devone_require_permission('manage_store');
verify_csrf();

$msg = '';
$error = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $repo = trim((string)($_POST['repo'] ?? ''));
    $ref = trim((string)($_POST['ref'] ?? 'main')) ?: 'main';
    $packageType = $_POST['package_type'] ?? 'auto';
    $folder = trim((string)($_POST['folder'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $parsed = devone_github_parse_repo($repo);

    if (!$parsed) {
        $error = 'Enter a valid GitHub repository URL like https://github.com/owner/repo or owner/repo.';
    } else {
        if ($name === '') { $name = $parsed['repo']; }
        if ($folder === '') { $folder = $parsed['repo']; }
        $item = array(
            'type' => $packageType,
            'package_type' => $packageType,
            'name' => $name,
            'repo' => 'https://github.com/' . $parsed['owner'] . '/' . $parsed['repo'],
            'ref' => $ref,
            'install_folder' => $folder,
            'description' => 'Imported from GitHub repository ' . $parsed['owner'] . '/' . $parsed['repo'],
        );
        $result = devone_store_install_zip_item($item, !empty($_POST['overwrite']));
        if (!empty($result['ok'])) { $msg = $result['message']; }
        else { $error = $result['message'] ?? 'GitHub import failed.'; }
    }
}

devone_admin_header('GitHub Importer - DevOneCMS');
?>
<h1>GitHub Importer</h1>
<p class="muted">Import public GitHub repositories or forks as DevOneCMS themes, plugins, or libraries. DevOneCMS downloads the repository ZIP, detects the package, and installs it into the right content folder.</p>
<?php devone_flash($msg); ?>
<?php devone_flash($error, 'card error-card'); ?>

<div class="store-admin-grid">
  <section class="card">
    <h3>Import From GitHub</h3>
    <form method="post">
      <?= csrf_field() ?>
      <label>GitHub Repository
        <input name="repo" placeholder="https://github.com/owner/repo or owner/repo" required>
      </label>
      <label>Branch / Ref
        <input name="ref" value="main" placeholder="main">
      </label>
      <label>Package Type
        <select name="package_type">
          <option value="auto">Auto Detect</option>
          <option value="theme">Theme</option>
          <option value="plugin">Plugin</option>
          <option value="library">Library</option>
        </select>
      </label>
      <label>Display Name, optional
        <input name="name" placeholder="Neon Slider Plugin">
      </label>
      <label>Install Folder Slug, optional
        <input name="folder" placeholder="neon-slider">
      </label>
      <label class="inline-check"><input type="checkbox" name="overwrite" value="1"> overwrite existing folder</label>
      <button>Import Repository</button>
    </form>
  </section>

  <section class="card">
    <h3>Package Rules</h3>
    <p><strong>Theme:</strong> repository must include <code>theme.css</code>. Optional: <code>theme.json</code>, <code>screenshot.png</code>.</p>
    <p><strong>Plugin:</strong> repository should include <code>plugin.json</code> or <code>plugin.php</code>.</p>
    <p><strong>Library:</strong> repository should include CSS/JS files, usually in <code>dist</code>, <code>src</code>, or the repo root.</p>
  </section>
</div>

<section class="card">
  <h3>Recommended GitHub Marketplace Flow</h3>
  <pre>devone-store/
  manifest.json
  screenshots/
    theme-name.png

plugin-repo/
  plugin.json
  plugin.php

theme-repo/
  theme.json
  theme.css
  screenshot.png</pre>
</section>
<?php devone_admin_footer();
