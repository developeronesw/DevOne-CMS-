# DevOne Assets API

**Status:** Official Core Service  
**Introduced:** DevOne CMS 1.2.8  
**Primary API:** `DevOne::assets()`  
**Compatibility alias:** `devone_service('assets')`

DevOne Assets is the required shared media layer for DevOne Core, official themes, official plugins, modules, builders, and Marketplace-certified extensions. It exists to prevent duplicate upload systems, fragmented media databases, repeated files, and unnecessary front-end or administration bloat.

## Core principle

> One asset library. Every plugin. Every theme. One place.

Public-facing media must be uploaded, queried, selected, updated, replaced, and tracked through DevOne Assets. Operational files such as plugin ZIPs, theme ZIPs, backups, source packages, temporary imports, and private configuration files do not belong in the public Asset Manager.

## Getting the service

```php
$assets = DevOne::assets();
```

Equivalent access:

```php
$assets = devone_service('assets');
$assets = devone_services()->get('assets');
```

## Uploading media

```php
$result = DevOne::assets()->upload($_FILES['hero_image'], [
    'folder'   => 'auto',
    'purpose'  => 'theme-hero',
    'alt_text' => 'Developer working at a workstation',
]);

if (empty($result['ok'])) {
    throw new RuntimeException($result['message'] ?? 'Upload failed.');
}

$assetId = (int) $result['asset']['id'];
```

### Upload options

| Option | Type | Purpose |
|---|---:|---|
| `folder` | string | Use `auto` for normal type-based sorting or a valid approved folder. |
| `purpose` | string | Identifies the feature that initiated the upload, such as `one-slider`, `commerce-product`, or `site-logo`. |
| `alt_text` | string | Accessible alternative text for images. |
| `caption` | string | Optional human-readable caption. |
| `owner_user_id` | int | Explicit owner when the caller has permission to assign ownership. |

The Core validates the upload, determines the actual MIME type, creates a safe unique filename, moves the file into site-aware media storage, creates the database record, clears related cache, and fires lifecycle hooks.

## Automatic folders

Normal public media is sorted automatically:

| Type | Default folder |
|---|---|
| Images | `images` |
| Video | `videos` |
| Audio | `audio` |
| Documents | `documents` |
| Archives | `archives` |
| Other supported media | `other` |

Purpose-specific Core assets may use approved folders such as `avatars` or `site` while remaining registered in the shared library.

## Searching assets

```php
$items = DevOne::assets()->search([
    'types'  => ['images', 'videos'],
    'folder' => 'all',
    'search' => 'homepage',
    'limit'  => 50,
]);
```

Searches remain site-aware and permission-aware. Do not query the media table directly in marketplace extensions.

## Reading one asset

```php
$asset = DevOne::assets()->find(42);

if ($asset) {
    echo htmlspecialchars($asset['url'], ENT_QUOTES, 'UTF-8');
}
```

Resolve a URL directly:

```php
$url = DevOne::assets()->url(42, '/assets/img/fallback.jpg');
```

## Updating metadata

```php
DevOne::assets()->update(42, [
    'alt_text' => 'Team reviewing the DevOne dashboard',
    'caption'  => 'Developer One workflow',
]);
```

Only update supported metadata fields. Never change stored paths directly.

## Replacing an asset

```php
$result = DevOne::assets()->replace(42, $_FILES['replacement'], [
    'purpose' => 'asset-replacement',
]);
```

Replacement preserves the asset identity so references using the asset ID continue to resolve through the same record.

## Usage tracking

Attach an asset to the feature using it:

```php
DevOne::assets()->attach(
    42,
    'plugin',
    'one-slider:12',
    'Homepage hero background'
);
```

Read usage:

```php
$usage = DevOne::assets()->usage(42);
```

Detach a reference when content is removed or changed:

```php
DevOne::assets()->detach(42, 'plugin', 'one-slider:12');
```

Recommended context types include `page`, `theme`, `plugin`, `module`, `commerce-product`, `user-avatar`, and `site-setting`. Prefix custom context IDs with the extension slug.

## Duplicate detection

```php
$duplicates = DevOne::assets()->duplicates(42);
```

Duplicate detection is based on file characteristics and content hashes. Extensions may display duplicate warnings but should not automatically delete or merge files without explicit administrator confirmation.

## Deleting assets

```php
$deleted = DevOne::assets()->delete(
    42,
    DevOne::users()->currentId(),
    DevOne::auth()->can('delete_all_media')
);
```

Before deleting, check usage and warn when references exist. Destructive actions must include permission and CSRF validation.

## Shared JavaScript picker

The reusable Asset Picker is the official UI for selecting existing media or uploading new media from an extension.

```javascript
DevOneAssets.open({
    types: ['images', 'videos'],
    multiple: false,
    purpose: 'one-slider',
    onSelect(asset) {
        if (!asset) return;
        console.log(asset.id, asset.url);
    }
});
```

Multiple selection:

```javascript
DevOneAssets.open({
    types: ['images'],
    multiple: true,
    purpose: 'product-gallery',
    onSelect(assets) {
        const ids = assets.map((asset) => asset.id);
        document.querySelector('#gallery_asset_ids').value = JSON.stringify(ids);
    }
});
```

Compatibility alias:

```javascript
DevOneMedia.open({
    types: ['images'],
    onSelect(asset) {
        console.log(asset);
    }
});
```

## Declarative picker buttons

```html
<input type="hidden" id="hero_asset_id" name="hero_asset_id">

<button
    type="button"
    data-devone-asset-picker
    data-target="#hero_asset_id"
    data-types="images"
    data-purpose="theme-hero"
>
    Choose image
</button>
```

For multiple selection, add `data-multiple`.

## Lifecycle hooks

```php
add_action('devone_asset_uploaded', function ($asset) {
    // React after an asset has been stored and registered.
});

add_action('devone_asset_updated', function ($asset) {
    // React after metadata changes.
});

add_action('devone_asset_replaced', function ($asset) {
    // React after the underlying file is replaced.
});
```

Hook callbacks must remain fast. Defer expensive work to a queue or scheduled process when available.

## Security rules

Every upload or mutation must:

1. Verify the current user and required permission.
2. Validate CSRF tokens for browser-originated requests.
3. Use the Core upload service rather than `move_uploaded_file()`.
4. Trust the detected MIME type, not only the filename extension.
5. Escape output at render time.
6. Store asset IDs rather than untrusted raw paths.
7. Respect site and owner boundaries.
8. Avoid arbitrary executable formats in public media storage.

## Performance rules

- Store asset IDs in extension data, not duplicate URLs or physical paths.
- Do not copy an existing asset into an extension-specific folder.
- Query only the media types and number of records required.
- Lazy-load thumbnails and large previews when practical.
- Do not bundle a second media browser or uploader library.
- Attach usage records when content references an asset.
- Resolve URLs at render time so replacements and future storage adapters continue to work.

## What belongs outside DevOne Assets

The following are operational files and should use protected extension or Core storage instead:

- Plugin, theme, module, framework, and library packages
- Backup archives
- Source-code uploads
- Temporary import files
- Private keys and configuration files
- Marketplace installation packages
- Runtime caches and generated logs

## Legacy compatibility

Existing media helpers remain available and delegate into the established media layer. New development must use `DevOne::assets()` as the documented API.

See `ASSETS-MIGRATION.md` for migration examples.
