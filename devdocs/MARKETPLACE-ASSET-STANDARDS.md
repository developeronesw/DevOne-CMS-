# DevOne Marketplace Asset Standards

These standards apply to extensions submitted for DevOne Marketplace certification.

## Required

A marketplace extension that accepts or selects public media must:

- Use `DevOne::assets()` for server-side media operations.
- Use `DevOneAssets.open()` for the standard media-selection experience unless an approved specialized interface is necessary.
- Register all public media in the shared Media Library.
- Store asset IDs as the primary reference.
- Respect site, owner, role, and permission boundaries.
- Validate CSRF tokens for administrative mutations.
- Track meaningful asset usage.
- Support active admin light and dark themes.
- Remain responsive on mobile administration screens.
- Preserve shared assets by default during uninstall.

## Prohibited

Marketplace extensions may not:

- Create a second public media database or independent media library.
- Copy selected media into plugin-specific public folders without a documented technical requirement.
- Call `move_uploaded_file()` directly for public content media.
- Trust a filename extension as proof of MIME type.
- Store absolute server paths in public extension data.
- Delete shared assets automatically during deactivation or uninstall.
- Bundle an unnecessary duplicate uploader, image-browser, or file-manager framework.
- Bypass DevOne permissions, site ownership, or user ownership.
- expose executable uploads in public media storage.

## Allowed exceptions

Separate protected storage is appropriate for:

- plugin and theme installation packages,
- backups,
- private imports and exports,
- temporary processing files,
- generated caches,
- logs,
- compiled source artifacts,
- confidential documents that are not public site media.

An extension claiming an exception must document its storage purpose, access controls, cleanup behavior, and why DevOne Assets is not appropriate.

## Automated review indicators

The Marketplace scanner should flag these patterns for review:

```text
move_uploaded_file(
$_FILES[
file_put_contents(
mkdir(
copy(
rename(
content/media/
content/uploads/
uploads/
media_library
media_table
```

A match is not automatically a rejection. It triggers manual review because legitimate operational storage may use some of these functions.

## Certification checklist

- [ ] Public uploads pass through DevOne Assets.
- [ ] Shared picker is used for media selection.
- [ ] Asset IDs are stored instead of copied files.
- [ ] Usage records are attached and detached correctly.
- [ ] Delete actions warn about existing usage.
- [ ] Upload actions verify permission and CSRF.
- [ ] Media output is escaped.
- [ ] Multisite boundaries are respected.
- [ ] No duplicate media subsystem is bundled.
- [ ] Uninstall preserves shared assets by default.
- [ ] Documentation explains every requested storage exception.

## Recommended marketplace declaration

Include an asset declaration in extension metadata or submission notes:

```json
{
  "devone_assets": {
    "uses_shared_library": true,
    "uses_shared_picker": true,
    "stores_asset_ids": true,
    "tracks_usage": true,
    "protected_storage": []
  }
}
```
