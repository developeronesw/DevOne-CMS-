<?php
/**
 * DevOne Theme Entitlements
 * Shared package storage with per-site visibility and activation rights.
 */
if (defined('DEVONE_THEME_ENTITLEMENTS_LOADED')) { return; }
define('DEVONE_THEME_ENTITLEMENTS_LOADED', true);

final class DevOne_Theme_Entitlements_Service extends DevOne_Abstract_Service {
    private $schemaReady = false;

    private function dbs() { return DevOne::db(); }
    private function pdo() { return $this->dbs()->connection(); }
    private function table($name) { return $this->dbs()->table($name); }

    public function libraryRoot() {
        $root = dirname(__DIR__) . '/content/theme-library';
        if (!is_dir($root)) { @mkdir($root, 0775, true); }
        // Apache hardening for executable theme source stored below the web root.
        // NGINX deployments must enforce the equivalent rule in server config.
        $guard = $root . '/.htaccess';
        if (is_dir($root) && !is_file($guard)) {
            @file_put_contents($guard, "Options -Indexes -ExecCGI\n<FilesMatch \"(?i)\\.(?:php[0-9]?|phtml|phar|cgi|pl|py|sh|shtml)$\">\n    Require all denied\n</FilesMatch>\n", LOCK_EX);
        }
        return $root;
    }

    private function schemaMarker() {
        $root = dirname(__DIR__);
        $version = defined('DEVONE_CORE_VERSION') ? (string)DEVONE_CORE_VERSION : (defined('CMS_VERSION') ? (string)CMS_VERSION : 'current');
        $prefix = defined('TABLE_PREFIX') ? (string)TABLE_PREFIX : 'cms_';
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $version . '-' . $prefix);
        return $root . '/storage/cache/theme-entitlements-schema-' . ($safe !== '' ? $safe : 'current') . '.ok';
    }

    public function ensureSchema() {
        if ($this->schemaReady) { return true; }
        $marker = $this->schemaMarker();
        if (is_file($marker)) { $this->schemaReady = true; return true; }
        $packages = $this->table('theme_packages');
        $entitlements = $this->table('site_theme_entitlements');
        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$packages}` (
            `id` int NOT NULL AUTO_INCREMENT,
            `theme_slug` varchar(190) NOT NULL,
            `theme_name` varchar(190) NOT NULL,
            `theme_version` varchar(50) DEFAULT '1.0.0',
            `package_hash` char(64) NOT NULL,
            `storage_path` varchar(700) NOT NULL,
            `visibility` varchar(30) NOT NULL DEFAULT 'private',
            `uploaded_by` int DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `package_hash` (`package_hash`),
            KEY `theme_slug` (`theme_slug`),
            KEY `visibility` (`visibility`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$entitlements}` (
            `id` int NOT NULL AUTO_INCREMENT,
            `site_id` int NOT NULL,
            `package_id` int NOT NULL,
            `theme_slug` varchar(190) NOT NULL,
            `source` varchar(40) NOT NULL DEFAULT 'upload',
            `license_owner` varchar(255) DEFAULT '',
            `status` varchar(30) NOT NULL DEFAULT 'active',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `site_theme` (`site_id`,`theme_slug`),
            KEY `package_id` (`package_id`),
            KEY `site_id` (`site_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
        $dir = dirname($marker);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents($marker, gmdate('c') . "\n", LOCK_EX);
        $this->schemaReady = true;
        return true;
    }

    public function hashDirectory($directory) {
        $directory = realpath($directory);
        if (!$directory || !is_dir($directory)) { throw new DevOne_Service_Exception('Theme source directory is missing.'); }
        $files = array();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) { continue; }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files, SORT_STRING);
        $ctx = hash_init('sha256');
        foreach ($files as $relative => $hash) { hash_update($ctx, $relative . "\0" . $hash . "\n"); }
        return hash_final($ctx);
    }

    private function copyDirectory($src, $dst) {
        if (!is_dir($dst) && !@mkdir($dst, 0775, true)) { return false; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0775, true)) { return false; }
            } elseif (!@copy($item->getPathname(), $target)) { return false; }
        }
        return true;
    }

    private function removeDirectory($dir) {
        if (!is_dir($dir)) { return; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
        @rmdir($dir);
    }

    public function installFromDirectory($siteId, $sourceDir, $manifest, $slug, $uploadedBy = 0, $overwrite = false) {
        $this->ensureSchema();
        $siteId = max(1, (int)$siteId);
        $slug = function_exists('devone_slugify') ? devone_slugify($slug, 'custom-theme') : preg_replace('/[^a-z0-9_-]/i', '-', $slug);
        $hash = $this->hashDirectory($sourceDir);
        $name = trim((string)($manifest['name'] ?? '')) ?: ucwords(str_replace('-', ' ', $slug));
        $version = trim((string)($manifest['version'] ?? '1.0.0')) ?: '1.0.0';
        $packages = $this->table('theme_packages');
        $entitlements = $this->table('site_theme_entitlements');

        return $this->dbs()->transaction(function($pdo) use ($siteId,$sourceDir,$slug,$hash,$name,$version,$uploadedBy,$overwrite,$packages,$entitlements) {
            $existingEnt = $pdo->prepare("SELECT id,package_id FROM `{$entitlements}` WHERE site_id=? AND theme_slug=? LIMIT 1");
            $existingEnt->execute(array($siteId,$slug));
            $current = $existingEnt->fetch(PDO::FETCH_ASSOC);
            if ($current && !$overwrite) { throw new DevOne_Service_Exception('This site already has a theme with that folder slug. Enable overwrite to replace its entitlement.'); }

            $find = $pdo->prepare("SELECT * FROM `{$packages}` WHERE package_hash=? LIMIT 1");
            $find->execute(array($hash));
            $package = $find->fetch(PDO::FETCH_ASSOC);
            if (!$package) {
                $relative = 'content/theme-library/' . $hash . '/' . $slug;
                $dest = dirname(__DIR__) . '/' . $relative;
                if (!$this->copyDirectory($sourceDir, $dest)) { throw new DevOne_Service_Exception('Could not copy theme into the shared package library.'); }
                $stmt = $pdo->prepare("INSERT INTO `{$packages}` (theme_slug,theme_name,theme_version,package_hash,storage_path,visibility,uploaded_by) VALUES (?,?,?,?,?,'private',?)");
                $stmt->execute(array($slug,$name,$version,$hash,$relative,$uploadedBy ?: null));
                $packageId = (int)$pdo->lastInsertId();
            } else { $packageId = (int)$package['id']; }

            if ($current) {
                $stmt = $pdo->prepare("UPDATE `{$entitlements}` SET package_id=?,source='upload',status='active',updated_at=NOW() WHERE id=?");
                $stmt->execute(array($packageId,(int)$current['id']));
            } else {
                $stmt = $pdo->prepare("INSERT INTO `{$entitlements}` (site_id,package_id,theme_slug,source,status) VALUES (?,?,?,'upload','active')");
                $stmt->execute(array($siteId,$packageId,$slug));
            }
            return array('ok'=>true,'package_id'=>$packageId,'hash'=>$hash,'deduplicated'=>(bool)$package,'slug'=>$slug,'name'=>$name,'version'=>$version);
        });
    }

    public function packageForSite($siteId, $slug) {
        $this->ensureSchema();
        $sql = "SELECT p.*,e.status AS entitlement_status FROM `{$this->table('site_theme_entitlements')}` e JOIN `{$this->table('theme_packages')}` p ON p.id=e.package_id WHERE e.site_id=? AND e.theme_slug=? AND e.status='active' LIMIT 1";
        $stmt = $this->pdo()->prepare($sql); $stmt->execute(array(max(1,(int)$siteId),(string)$slug));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listForSite($siteId) {
        $this->ensureSchema();
        $sql = "SELECT p.*,e.theme_slug AS entitled_slug,e.source,e.status AS entitlement_status FROM `{$this->table('site_theme_entitlements')}` e JOIN `{$this->table('theme_packages')}` p ON p.id=e.package_id WHERE e.site_id=? AND e.status='active' ORDER BY p.theme_name";
        $stmt = $this->pdo()->prepare($sql); $stmt->execute(array(max(1,(int)$siteId)));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    public function has($siteId, $slug) { return (bool)$this->packageForSite($siteId,$slug); }

    public function revoke($siteId, $slug) {
        $this->ensureSchema();
        $siteId=max(1,(int)$siteId); $slug=(string)$slug;
        $entitlements=$this->table('site_theme_entitlements'); $packages=$this->table('theme_packages');
        return $this->dbs()->transaction(function($pdo) use($siteId,$slug,$entitlements,$packages) {
            $stmt=$pdo->prepare("SELECT package_id FROM `{$entitlements}` WHERE site_id=? AND theme_slug=? LIMIT 1"); $stmt->execute(array($siteId,$slug));
            $packageId=(int)$stmt->fetchColumn();
            if (!$packageId) { return array('ok'=>false,'error'=>'Theme entitlement was not found.'); }
            $pdo->prepare("DELETE FROM `{$entitlements}` WHERE site_id=? AND theme_slug=?")->execute(array($siteId,$slug));
            $count=$pdo->prepare("SELECT COUNT(*) FROM `{$entitlements}` WHERE package_id=?"); $count->execute(array($packageId));
            $remaining=(int)$count->fetchColumn(); $purged=false;
            if ($remaining===0) {
                $p=$pdo->prepare("SELECT storage_path,visibility FROM `{$packages}` WHERE id=? LIMIT 1"); $p->execute(array($packageId)); $package=$p->fetch(PDO::FETCH_ASSOC);
                if ($package && ($package['visibility'] ?? 'private')==='private') {
                    $full=dirname(__DIR__) . '/' . ltrim((string)$package['storage_path'],'/');
                    $this->removeDirectory($full); $pdo->prepare("DELETE FROM `{$packages}` WHERE id=?")->execute(array($packageId)); $purged=true;
                }
            }
            return array('ok'=>true,'purged_package'=>$purged,'remaining_entitlements'=>$remaining);
        });
    }
}

if (class_exists('DevOne')) {
    DevOne::services()->instance('theme-entitlements', new DevOne_Theme_Entitlements_Service());
    DevOne::services()->alias('themes-entitlements', 'theme-entitlements');
}

function devone_theme_entitlements() { return DevOne::service('theme-entitlements'); }
