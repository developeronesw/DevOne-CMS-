# DevOne Core Services

**Introduced:** Developer One CMS 1.2.7  
**Status:** Official developer API  
**Compatibility:** Existing procedural helpers remain supported

DevOne Core Services provide one consistent API for plugins, themes, modules, libraries, and future marketplace products. Developers no longer need to search through unrelated helper functions to discover common platform capabilities.

## Quick start

```php
$value = DevOne::settings()->get('site_name', 'Developer One CMS');
DevOne::settings()->set('my_plugin_enabled', '1');

$currentUser = DevOne::users()->current();
if (DevOne::auth()->can('manage_plugins')) {
    // Protected action.
}

$media = DevOne::assets()->query('images');
DevOne::mail()->send('customer@example.com', 'Hello', '<p>Welcome.</p>');
```

The container syntax is also available:

```php
$assets = devone_service('assets');
$settings = devone_services()->get('settings');
```

## Available services

| Service | Accessor | Purpose |
|---|---|---|
| Settings | `DevOne::settings()` | Site and extension configuration |
| Assets | `DevOne::assets()` | Shared Media Library records and folders |
| Mail | `DevOne::mail()` | SMTP-aware platform email |
| Users | `DevOne::users()` | Current user, user lookup, roles and permissions |
| Auth | `DevOne::auth()` | Authentication, permissions and CSRF helpers |
| Cache | `DevOne::cache()` | Cache storage, retrieval, remember and flush |
| Events | `DevOne::events()` | Actions, filters and platform events |
| Database | `DevOne::db()` | PDO connection, table names and transactions |
| API | `DevOne::api()` | API input, route and response helpers |
| Notifications | `DevOne::notifications()` | Session-based admin notices |
| Search | `DevOne::search()` | Shared page and media search |
| Log | `DevOne::log()` | Core activity logging |

Aliases are available for `media`, `mailer`, `database`, `hooks`, and `notify` through `devone_service()`.

## Settings

```php
$name = DevOne::settings()->get('site_name', 'DevOne CMS');
DevOne::settings()->set('my_plugin_color', '#c89b2c');
$enabled = DevOne::settings()->getBool('my_plugin_enabled', true);
$count = DevOne::settings()->getInt('my_plugin_limit', 10);
$options = DevOne::settings()->getJson('my_plugin_options', array());
DevOne::settings()->setJson('my_plugin_options', array('layout' => 'grid'));
```

Prefix extension settings to avoid collisions:

```text
one_slider.autoplay
one_slider.default_duration
vendor_plugin.setting_name
```

## Assets

Use DevOne Assets instead of creating an independent media database or uploader.

```php
$images = DevOne::assets()->query('images');
$folders = DevOne::assets()->folders();
$type = DevOne::assets()->typeFromFile('intro.mp4', 'video/mp4');
```

The dedicated Assets wave will add the central upload service and shared picker modal:

```php
$result = DevOne::assets()->upload($_FILES['media'], array(
    'folder' => 'auto',
    'purpose' => 'one-slider',
));
```

Until that wave is installed, `upload()` returns a clear unavailable response rather than writing files outside the Media Library.

## Mail

```php
$html = DevOne::mail()->wrapHtml('Order received', '<p>Thank you.</p>');
$sent = DevOne::mail()->send(
    'customer@example.com',
    'Order received',
    $html,
    array('Content-Type: text/html; charset=UTF-8')
);
```

Do not call PHP `mail()` directly in marketplace extensions.

## Users and Auth

```php
$user = DevOne::users()->current();
$userId = DevOne::users()->currentId();
$roles = DevOne::users()->roles();

if (!DevOne::auth()->check()) {
    // User is not signed in.
}

if (!DevOne::auth()->can('manage_media')) {
    http_response_code(403);
    exit('Permission denied.');
}

DevOne::auth()->requirePermission('manage_plugins');
echo DevOne::auth()->csrfField();
```

Every write operation must enforce permission and CSRF checks.

## Cache

```php
$items = DevOne::cache()->remember('my-plugin:items', function () {
    return load_expensive_items();
}, 300);

DevOne::cache()->delete('my-plugin:items');
```

Prefix cache keys with the extension slug.

## Events

```php
DevOne::events()->on('devone_head', function () {
    echo '<link rel="stylesheet" href="...">';
});

DevOne::events()->filter('page_content', function ($content) {
    return str_replace('[example]', '<strong>Example</strong>', $content);
});

DevOne::events()->emit('my_plugin_saved', $recordId);
$value = DevOne::events()->apply('my_plugin_value', $value);
```

The existing `add_action()`, `do_action()`, `add_filter()`, and `apply_filters()` helpers remain valid.

## Database and transactions

```php
DevOne::db()->transaction(function ($pdo, $database) {
    $table = $database->table('my_plugin_records');
    $statement = $pdo->prepare("INSERT INTO `{$table}` (`title`) VALUES (?)");
    $statement->execute(array('New record'));
});
```

Always use prepared statements. Never concatenate untrusted values into SQL.

## Notifications

```php
DevOne::notifications()->success('Slider saved.');
DevOne::notifications()->warning('The image is very large.');
DevOne::notifications()->error('The import could not be completed.');

$messages = DevOne::notifications()->consume();
```

`consume()` returns notices once and clears them from the session.

## Search

```php
$pages = DevOne::search()->pages('pricing', 20);
$media = DevOne::search()->media('hero', 50);
```

## Backward compatibility

Existing extensions do not need immediate rewrites. These helpers remain supported:

```php
get_setting();
set_setting();
devone_media_query();
devone_mail();
devone_current_user();
devone_has_permission();
devone_cache_get();
add_action();
add_filter();
db();
table_name();
```

The service layer is a compatibility bridge over established Core behavior. This means:

1. Existing extensions continue to run.
2. New extensions use the service API.
3. Core internals can migrate behind the services over time.
4. Future implementation changes do not require third-party developers to relearn the platform.

A diagnostic map is available:

```php
$status = devone_core_services_status();
$legacyMap = devone_core_service_legacy_map();
```

## Registering a custom service

Advanced extensions may register a service after Core has loaded:

```php
class My_Plugin_Reports_Service {
    public function monthly() {
        return array();
    }
}

DevOne::services()->instance('my-plugin-reports', new My_Plugin_Reports_Service());
$reports = devone_service('my-plugin-reports')->monthly();
```

Use a vendor or plugin prefix for custom service names. Do not replace official Core Services.

## Marketplace requirements

Marketplace-certified packages should:

- Use DevOne Assets for public media.
- Use DevOne Mail for email.
- Use DevOne Auth and Users for permissions.
- Use DevOne Settings for configuration.
- Use DevOne Cache for reusable cached data.
- Use DevOne Events for extension points.
- Use prepared statements through the database service.
- Inherit active theme variables.
- Support light and dark administration.
- Support mobile administration.
- Uninstall cleanly.
- Avoid duplicate bundled libraries when DevOne Libraries already provides them.

## Migration example

Legacy code remains valid:

```php
$enabled = get_setting('my_plugin_enabled', '0');
if (devone_has_permission('manage_plugins')) {
    devone_log('my_plugin_opened');
}
```

Recommended new code:

```php
$enabled = DevOne::settings()->getBool('my_plugin_enabled');
if (DevOne::auth()->can('manage_plugins')) {
    DevOne::log()->write('my_plugin_opened');
}
```
