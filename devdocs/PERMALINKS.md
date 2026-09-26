# DevOne Clean Permalinks

DevOne CMS 1.3 supports three page permalink styles:

- Clean (recommended): `/about-us`
- Page Prefix: `/page/about-us`
- Legacy Query: `/?page=about-us`

The public front controller keeps real files/directories untouched and resolves page requests through `index.php`. Legacy query URLs remain supported for backward compatibility. When Clean or Page Prefix is active, normal browser requests using `?page=` receive a 301 redirect to the canonical page URL.

## Nginx / CloudPanel

Inside the site server block, the public location must use a front-controller fallback equivalent to:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Do not replace CloudPanel's PHP/FastCGI blocks. Only ensure the existing public `location /` fallback sends unknown paths to `index.php`.

## Apache

The bundled `.htaccess` implements the front-controller fallback when `mod_rewrite` is enabled.

## Developer contract

Use `devone_page_url($slug)` for page links. Do not construct `?page=` links manually. Use `devone_clean_url()` for stored custom internal URLs.
