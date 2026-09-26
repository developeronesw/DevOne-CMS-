<?php
/**
 * DevOneCMS Database Layer
 * v1.1.4 hardens table-prefix discovery and exact table lookup.
 *
 * Important rule: never hard-code cms_pages/devone_pages in feature files.
 * Call table_name('pages') and table_exists('pages'). This file resolves the
 * real installed table name from config.php first, then from the live DB.
 */

function db() {
    static $pdo = null;
    if ($pdo === null) {
        if (!defined('DB_HOST')) { throw new Exception('DevOneCMS is not installed yet.'); }
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }
    return $pdo;
}

function devone_clean_identifier($value) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$value);
}

function devone_configured_table_prefix() {
    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cms_';
    $prefix = devone_clean_identifier($prefix);
    return $prefix !== '' ? $prefix : 'cms_';
}

function devone_core_table_suffixes() {
    return array('pages','settings','users','roles','media','menus','themes','plugins','libraries','api_endpoints','modules','activity_logs','webhooks','licenses','license_activations','license_events');
}

function devone_required_core_suffixes() {
    return array('pages','settings','users');
}

function devone_refresh_table_cache() {
    $GLOBALS['devone_table_cache_version'] = ($GLOBALS['devone_table_cache_version'] ?? 0) + 1;
    unset($GLOBALS['devone_database_tables_cache'], $GLOBALS['devone_database_table_lookup_cache'], $GLOBALS['devone_detected_table_prefix_cache']);
}

function devone_database_tables($force = false) {
    if (!$force && isset($GLOBALS['devone_database_tables_cache'])) { return $GLOBALS['devone_database_tables_cache']; }

    $tables = array();
    try {
        // INFORMATION_SCHEMA avoids LIKE wildcard issues with underscores in table names.
        $stmt = db()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['TABLE_NAME'])) { $tables[] = (string)$row['TABLE_NAME']; }
        }
    } catch (Exception $e) {
        try {
            $stmt = db()->query('SHOW TABLES');
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                if (!empty($row[0])) { $tables[] = (string)$row[0]; }
            }
        } catch (Exception $ignored) {
            $tables = array();
        }
    }

    $GLOBALS['devone_database_tables_cache'] = array_values(array_unique($tables));
    return $GLOBALS['devone_database_tables_cache'];
}

function devone_database_table_lookup($force = false) {
    if (!$force && isset($GLOBALS['devone_database_table_lookup_cache'])) { return $GLOBALS['devone_database_table_lookup_cache']; }
    $lookup = array();
    foreach (devone_database_tables($force) as $table) { $lookup[$table] = true; }
    $GLOBALS['devone_database_table_lookup_cache'] = $lookup;
    return $lookup;
}

function devone_table_exists_exact($fullTableName, $force = false) {
    $fullTableName = devone_clean_identifier($fullTableName);
    if ($fullTableName === '') { return false; }
    $lookup = devone_database_table_lookup($force);
    if (isset($lookup[$fullTableName])) { return true; }

    // Last exact check via INFORMATION_SCHEMA in case cache is stale.
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($fullTableName));
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

function devone_candidate_prefixes() {
    $configured = devone_configured_table_prefix();
    $candidates = array($configured => true, 'cms_' => true, 'devone_' => true, 'devonecms_' => true, '' => true);
    $suffixes = devone_core_table_suffixes();

    foreach (devone_database_tables() as $table) {
        foreach ($suffixes as $suffix) {
            if ($table === $suffix) { $candidates[''] = true; }
            if (strlen($table) > strlen($suffix) && substr($table, -strlen($suffix)) === $suffix) {
                $prefix = substr($table, 0, strlen($table) - strlen($suffix));
                $prefix = devone_clean_identifier($prefix);
                $candidates[$prefix] = true;
            }
        }
    }
    return array_keys($candidates);
}

function devone_detect_table_prefix($force = false) {
    if (!$force && isset($GLOBALS['devone_detected_table_prefix_cache'])) { return $GLOBALS['devone_detected_table_prefix_cache']; }

    $configured = devone_configured_table_prefix();
    $lookup = devone_database_table_lookup($force);
    $suffixes = devone_core_table_suffixes();
    $required = devone_required_core_suffixes();

    $bestPrefix = $configured;
    $bestScore = -999;

    foreach (devone_candidate_prefixes() as $prefix) {
        $prefix = devone_clean_identifier($prefix);
        $score = 0;
        foreach ($suffixes as $suffix) {
            if (isset($lookup[$prefix . $suffix])) { $score += 1; }
        }
        foreach ($required as $suffix) {
            if (isset($lookup[$prefix . $suffix])) { $score += 10; }
        }
        if ($prefix === $configured) { $score += 2; }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestPrefix = $prefix;
        }
    }

    $GLOBALS['devone_detected_table_prefix_cache'] = devone_clean_identifier($bestPrefix);
    return $GLOBALS['devone_detected_table_prefix_cache'];
}

function devone_active_table_prefix() {
    return devone_detect_table_prefix(false);
}

function devone_find_existing_table_for_suffix($name, $force = false) {
    $name = devone_clean_identifier($name);
    if ($name === '') { return ''; }

    // Exact table passed in.
    if (devone_table_exists_exact($name, $force)) { return $name; }

    // Config/active prefix first.
    $prefixes = array(devone_active_table_prefix(), devone_configured_table_prefix(), 'cms_', 'devone_', 'devonecms_', '');
    foreach (devone_candidate_prefixes() as $p) { $prefixes[] = $p; }
    $prefixes = array_values(array_unique(array_map('devone_clean_identifier', $prefixes)));

    foreach ($prefixes as $prefix) {
        $candidate = $prefix . $name;
        if (devone_table_exists_exact($candidate, $force)) { return $candidate; }
    }

    // If someone passed a prefixed-looking name with the wrong prefix, strip the
    // detected prefix only after the direct checks above.
    foreach (devone_core_table_suffixes() as $suffix) {
        if ($name !== $suffix && substr($name, -strlen($suffix)) === $suffix) {
            return devone_find_existing_table_for_suffix($suffix, $force);
        }
    }

    return '';
}

function table_name($name) {
    $name = devone_clean_identifier($name);
    if ($name === '') { return devone_active_table_prefix(); }

    $existing = devone_find_existing_table_for_suffix($name, false);
    if ($existing !== '') { return $existing; }

    // Refresh once in case the installer/repair created tables earlier in the request.
    $existing = devone_find_existing_table_for_suffix($name, true);
    if ($existing !== '') { return $existing; }

    return devone_clean_identifier(devone_active_table_prefix() . $name);
}

function devone_base_table_name($name) {
    $name = devone_clean_identifier($name);
    foreach (devone_candidate_prefixes() as $prefix) {
        $prefix = devone_clean_identifier($prefix);
        if ($prefix !== '' && strpos($name, $prefix) === 0) {
            return substr($name, strlen($prefix));
        }
    }
    return $name;
}

function table_exists($name) {
    $name = devone_clean_identifier($name);
    if ($name === '') { return false; }
    $existing = devone_find_existing_table_for_suffix($name, false);
    if ($existing !== '') { return true; }
    $existing = devone_find_existing_table_for_suffix($name, true);
    return $existing !== '';
}

function devone_table_resolver_report() {
    $prefix = devone_active_table_prefix();
    $configured = devone_configured_table_prefix();
    $found = array();
    $missing = array();
    $resolved = array();

    foreach (devone_core_table_suffixes() as $suffix) {
        $table = table_name($suffix);
        $resolved[$suffix] = $table;
        if (table_exists($suffix)) { $found[] = $table; }
        else { $missing[] = $table; }
    }

    return array(
        'database' => defined('DB_NAME') ? DB_NAME : '',
        'configured_prefix' => $configured,
        'active_prefix' => $prefix,
        'resolved' => $resolved,
        'found' => array_values(array_unique($found)),
        'missing' => array_values(array_unique($missing)),
        'all_tables' => devone_database_tables(true),
    );
}
