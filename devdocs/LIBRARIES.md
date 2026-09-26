# DevOne Libraries

Libraries are reusable compiled CSS and JavaScript packages. They may be loaded on the public frontend, inside Admin, or in both contexts.

## Package structure

```text
bootstrap-5/
├── library.json
├── css/bootstrap.min.css
└── js/bootstrap.bundle.min.js
```

## library.json

```json
{
  "name": "Bootstrap 5",
  "version": "5.3.3",
  "description": "Bootstrap production bundle",
  "scope": "frontend",
  "sort_order": 100,
  "assets": [
    {"path":"css/bootstrap.min.css","type":"css","order":10,"placement":"head"},
    {"path":"js/bootstrap.bundle.min.js","type":"js","order":20,"placement":"footer","defer":true}
  ]
}
```

Supported asset properties: `path`, `type`, `order`, `placement`, `defer`, `async`, `module`, `integrity`, and `crossorigin`.

CDN assets must use HTTPS. Subresource Integrity hashes are supported.

Tailwind packages must contain already compiled CSS. DevOne does not execute Node.js, npm, PostCSS, or Tailwind CLI on a production web server.
