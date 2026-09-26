# Migrating Extensions to DevOne Assets

This guide helps existing DevOne themes, plugins, modules, builders, and libraries adopt the official Asset Manager without breaking saved data.

## Migration rule

Do not perform a destructive conversion in one release. Introduce asset IDs while continuing to read existing URLs or paths until saved records have been migrated safely.

## Raw PHP upload: before

```php
$name = basename($_FILES['image']['name']);
$target = __DIR__ . '/uploads/' . $name;
move_uploaded_file($_FILES['image']['tmp_name'], $target);
```

## DevOne Assets: after

```php
DevOne::auth()->requirePermission('upload_media');
DevOne::auth()->verifyCsrf($_POST['_csrf'] ?? '');

$result = DevOne::assets()->upload($_FILES['image'], [
    'folder'  => 'auto',
    'purpose' => 'acme-gallery',
]);

if (empty($result['ok'])) {
    DevOne::notifications()->error($result['message'] ?? 'Upload failed.');
    return;
}

$assetId = (int) $result['asset']['id'];
```

## Independent media picker: before

```javascript
openAcmeUploader();
```

## Shared picker: after

```javascript
DevOneAssets.open({
    types: ['images'],
    multiple: true,
    purpose: 'acme-gallery',
    onSelect(assets) {
        saveGalleryAssets(assets.map((asset) => asset.id));
    }
});
```

## Saving URLs: before

```php
set_setting('hero_image_url', $_POST['hero_image_url']);
```

## Saving IDs: after

```php
set_setting('hero_asset_id', (int) $_POST['hero_asset_id']);
```

Rendering:

```php
$assetId = (int) DevOne::settings()->get('hero_asset_id', 0);
$url = DevOne::assets()->url($assetId, '');
```

## Backward-compatible field migration

```php
$assetId = (int) DevOne::settings()->get('hero_asset_id', 0);
$legacyUrl = (string) DevOne::settings()->get('hero_image_url', '');

$url = $assetId > 0
    ? DevOne::assets()->url($assetId, $legacyUrl)
    : $legacyUrl;
```

When the administrator selects or uploads a new asset, save the new asset ID. Keep the legacy field readable for at least one compatibility cycle before removing it.

## Usage tracking migration

After saving content:

```php
DevOne::assets()->detach($oldAssetId, 'plugin', 'acme-gallery:' . $galleryId);
DevOne::assets()->attach($newAssetId, 'plugin', 'acme-gallery:' . $galleryId, 'Gallery cover');
```

## Database migration checklist

- Add new nullable asset-ID fields rather than deleting URL fields immediately.
- Backfill IDs only when a reliable matching media record exists.
- Do not create duplicate files merely to manufacture a media record.
- Preserve user and site ownership.
- Log ambiguous legacy references for administrator review.
- Keep migrations idempotent so they can run more than once safely.

## Theme migration

Theme settings for logos, hero images, backgrounds, video posters, Open Graph images, and favicons should use the shared picker. Store the asset ID in the theme setting and resolve the current URL when rendering.

## Commerce migration

Products should store asset IDs for featured images and galleries. Do not copy product images into a commerce-specific public media library.

## Builder migration

Visual builders should serialize media references as asset IDs with optional cached presentation metadata. The asset ID remains the source of truth.

## Uninstall behavior

Extensions must not delete shared assets during uninstall unless:

1. the asset was created specifically by the extension,
2. no other usage records exist, and
3. the administrator explicitly chose to remove extension-created media.

The safe default is to preserve shared media.
