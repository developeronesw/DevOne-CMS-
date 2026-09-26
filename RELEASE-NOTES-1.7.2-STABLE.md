# DevOne Core 1.7.2 Stable — Anchor & AJAX Navigation Stability Release

Release date: 2026-08-18

DevOne Core 1.7.2 promotes the verified 1.7.1 Anchor / AJAX Router Hotfix into the official Stable Core line. It preserves the 1.7.1 Runtime Loader and Admin Runtime contracts while correcting frontend hash-anchor behavior during AJAX navigation.

## Fixed
- Same-page `#anchor` links no longer trigger an unnecessary AJAX page reload.
- Same-page anchor navigation no longer scrolls to the requested section and then gets forced back to the top.
- Cross-page links such as `/about#team` load the destination page, initialize DevOne page hooks, and then scroll to the requested target.
- URL fragments are stripped from PHP/AJAX origin requests and remain browser-side navigation state.
- `devone:ready` / `devone:pageLoaded` execute before fragment scrolling so animation and layout hooks initialize first.
- Direct visits containing a hash target re-align after DevOne page-ready hooks initialize.
- Browser Back/Forward navigation between hash targets no longer causes unnecessary AJAX page loads.
- Normal non-hash AJAX navigation retains the existing scroll-to-top behavior.

## Page Manager Improvements
- Added bulk page selection for administrators with Manage Pages permission.
- Added AJAX deletion for individual pages so deleting a page no longer reloads the Pages screen.
- Added AJAX bulk deletion for multiple selected pages in one operation.
- Desktop table and mobile card selections stay synchronized for the same page.
- Deleted page rows/cards are removed from the interface immediately after a successful purge.
- Added an inline success/error notification with an accessible live status region after deletion.
- Page count and selected-page controls update immediately without a full page refresh.
- Existing CSRF verification, site scoping, Manage Pages permission checks, delete logging, and post-delete purge hooks are preserved.

## Compatibility
- Upgrade source: DevOne Core 1.7.1 Stable
- PHP: 7.4 or newer
- Database: MySQL/MariaDB
- Database migration: none
- Core API changes: none
- Runtime Loader API changes: none
- Plugin/theme structure changes: none
- Existing configuration, content, plugins, themes, uploads, and database data are preserved by the incremental updater.
