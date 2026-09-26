# DevOne CMS Core 1.6.0 Stable — Official Release

Release date: 2026-08-11

This release was cross-referenced from the previous Core RC and the current canonical SuperAdmin build. It intentionally preserves stronger RC security/API behavior where the live SuperAdmin contained older implementations, while carrying forward verified current platform updates.

## Major updates
- New Developer One gold/silver brand logo included and used as the built-in administration/installer fallback.
- Administration sidebar redesign with configured Site Logo priority, search, grouped navigation, user profile block and current responsive styling.
- Clean permalink routing, canonical legacy redirects, proper 404 rendering and canonical URL output.
- Native hierarchical menus with stable item IDs, parent/child relationships, nested rendering and improved menu-builder UI.
- Font files added to shared Media Library typing/folders and Media administration filters.
- DevOne Apps engine, Apps admin surface and Apps database schema.
- Built-in Core update client/admin surface with release identity separated from installation configuration.
- Theme entitlement foundation carried from current SuperAdmin.
- Current Settings, Pages, Themes and Dashboard administration improvements.
- Updated front-end/admin CSS from the canonical SuperAdmin build.
- Hardened RC Core Services retained, including the full shared Assets API (`search`, `find`, `update`, `replace`, usage/attachment helpers), CSRF verification helpers and reserved-service protection.
- Site logo and avatar uploads remain routed through DevOne Assets rather than a parallel upload implementation.
- DevOne Apps registered as an official Core Service without weakening reserved Core Service protections.

## Public Core cleanup
- All bundled plugins removed, including DevOne Commerce and all SuperAdmin-only plugins.
- No plugin ZIP archives included.
- No installed config.php or live credentials.
- No update staging/backups, logs, cache, marketplace feedback, site data, user uploads or generated downloads.
- SuperAdmin-only Marketplace administration, submissions, table debugger and File Manager are not included.
- GPL v3+ Core licensing files from the release candidate are preserved; proprietary SuperAdmin/plugin license text was not substituted into Core.

## Compatibility
Fresh installer package. Existing extension APIs and backward helper bridges are retained. Optional products can be installed from the Developer One Marketplace after installation.

### Marketplace privacy
- Marketplace registry endpoints, install identifiers, bridge status, and private bridge-key diagnostics are no longer displayed in the customer-facing Store administration screen.
- Marketplace connectivity and automatic bridge registration remain internal platform behavior.
