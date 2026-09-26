# DevOne Native Hooks Reference

Developer One CMS uses a lightweight native hooks and filters system.

## Actions

```php
add_action('devone_head', 'my_callback');
add_action('devone_footer', 'my_callback');
add_action('before_route', 'my_callback');
add_action('before_render', 'my_callback');
add_action('after_render', 'my_callback');
```

## Filters

```php
add_filter('page_content', 'my_content_filter');
```

## Example

```php
function my_content_filter($content) {
    return str_replace('[hello_devone]', '<strong>Hello DevOne</strong>', $content);
}

add_filter('page_content', 'my_content_filter');
```

Use DevOne-native hook names in all official packages and docs.
