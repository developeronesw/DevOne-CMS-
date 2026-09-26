<?php
/** DevOne theme setting example. */
$logoId = (int) DevOne::settings()->get('theme_logo_asset_id', 0);
$logoUrl = DevOne::assets()->url($logoId, rtrim(SITE_URL, '/') . '/assets/img/default-logo.png');
?>

<img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars(get_setting('site_title', 'DevOne CMS'), ENT_QUOTES, 'UTF-8') ?>">
