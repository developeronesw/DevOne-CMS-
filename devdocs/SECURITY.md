# Secure Extension Development

- Require the narrowest permission before admin actions.
- Include `csrf_field()` in forms and call `DevOne::auth()->requireCsrf()` for mutations.
- Use prepared statements through `DevOne::db()->connection()`.
- Escape HTML with `e()` and encode JSON with `json_encode()`.
- Never trust filenames, MIME headers, ZIP paths, URLs, or marketplace metadata.
- Keep credentials in server configuration, not packages.
- Do not use `eval`, shell execution, PHP object unserialization, or writable executable upload folders.
