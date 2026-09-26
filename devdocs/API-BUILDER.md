# DevOne API Builder Runtime 1.2.8

## Endpoint URL

`https://example.com/api.php?route=pages`

Clean `/api/pages` routing also works when the server rewrite configuration forwards that path to `api.php`.

## Authentication

Create a key in **Admin → API Builder → Create API Key**. Send it once per request:

```http
Authorization: Bearer d1_live_...
```

Only a password hash is stored. The raw key is displayed once.

## Writes

POST, PUT, and PATCH endpoints should require an `Idempotency-Key` header. Reusing the same key with the same payload returns the stored response; reusing it with different data returns HTTP 409.

```bash
curl -X POST "https://example.com/api.php?route=pages" \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: page-create-001" \
  -d '{"title":"API Page","slug":"api-page","content":"Hello"}'
```

Update and delete requests may identify a record using `/route/123` or `?id=123`.

## Read controls

GET endpoints support:

- `page`
- `per_page`
- `sort`
- `order=ASC|DESC`
- `search`
- Exact-match filtering by configured readable fields

## Security model

- Source tables must exist in the DevOne database.
- Only configured writable fields can be changed.
- Password, token, secret, hash, and private-key fields are excluded from automatic readable fields.
- Write endpoints require Bearer authentication unless an administrator explicitly enables public writes.
- API keys can be limited by endpoint and custom permission.
- Per-minute request limits apply to authenticated keys or client IP addresses.
- CORS is same-origin by default.
- Public errors do not expose SQL or stack details.
- Requests are recorded in the API request log.
- Site-aware tables are restricted to the current site when Site Scope is enabled.
