# Hooks and Events

```php
DevOne::events()->on('before_route', function ($slug) {});
DevOne::events()->filter('page_content', function ($content) { return $content; });
DevOne::events()->emit('acme_report_created', $reportId);
```

Legacy `add_action()`, `do_action()`, `add_filter()`, and `apply_filters()` remain supported.

Core hooks include `before_route`, `before_render`, `after_render`, `devone_head`, and `devone_footer`. Extension-specific events must use a vendor prefix.
