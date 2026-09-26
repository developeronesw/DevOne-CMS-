<?php
/**
 * Minimal DevOne Assets integration example for a plugin admin page.
 */

DevOne::auth()->requirePermission('manage_plugins');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    DevOne::auth()->verifyCsrf($_POST['_csrf'] ?? '');

    $oldId = (int) DevOne::settings()->get('acme_feature_asset_id', 0);
    $newId = (int) ($_POST['feature_asset_id'] ?? 0);

    if ($oldId > 0 && $oldId !== $newId) {
        DevOne::assets()->detach($oldId, 'plugin', 'acme-example:feature');
    }

    DevOne::settings()->set('acme_feature_asset_id', $newId);

    if ($newId > 0) {
        DevOne::assets()->attach($newId, 'plugin', 'acme-example:feature', 'Feature image');
    }

    DevOne::notifications()->success('Feature image saved.');
}

$assetId = (int) DevOne::settings()->get('acme_feature_asset_id', 0);
$asset = $assetId > 0 ? DevOne::assets()->find($assetId) : null;
?>

<input type="hidden" id="feature_asset_id" name="feature_asset_id" value="<?= (int) $assetId ?>">

<button
    type="button"
    data-devone-asset-picker
    data-target="#feature_asset_id"
    data-types="images"
    data-purpose="acme-example"
>
    Choose feature image
</button>

<?php if ($asset): ?>
    <img src="<?= htmlspecialchars($asset['url'], ENT_QUOTES, 'UTF-8') ?>"
         alt="<?= htmlspecialchars($asset['alt_text'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
<?php endif;
