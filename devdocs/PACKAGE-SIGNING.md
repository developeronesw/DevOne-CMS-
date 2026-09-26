# Package Signing and Integrity

Installable packages may include `devone-package.json`:

```json
{
  "name": "Acme Package",
  "version": "1.0.0",
  "official": false,
  "files": {
    "plugin.php": "SHA256_HEX"
  }
}
```

Official Developer One packages set `official` to `true` and include a base64 RSA SHA-256 signature over the canonical manifest without the `signature` property. The private signing key must remain offline. The Core contains only the public verification key.
