<?php
/** DevOne official release identity. */
if (!defined('DEVONE_CORE_VERSION')) { define('DEVONE_CORE_VERSION', '1.7.4'); }
if (!defined('DEVONE_RELEASE_CHANNEL')) { define('DEVONE_RELEASE_CHANNEL', 'stable'); }
if (!defined('DEVONE_RELEASE_NAME')) { define('DEVONE_RELEASE_NAME', 'DevOne Core 1.7.4 Stable'); }
if (!function_exists('devone_core_version')) { function devone_core_version() { return (string)DEVONE_CORE_VERSION; } }
