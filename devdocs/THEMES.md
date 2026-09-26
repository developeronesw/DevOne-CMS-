# Themes

A theme controls front-end presentation. A minimum theme contains:

```text
my-theme/
├── theme.css
├── theme.json
└── templates/
```

Example metadata:

```json
{"name":"Acme Corporate","version":"1.0.0","author":"Acme","license":"GPL-3.0-or-later"}
```

Use Core Services for settings and assets. Escape output with `e()`. Do not store credentials in theme files.
