<?php
require_once dirname(__DIR__) . '/core/storage_preflight.php';
require __DIR__ . '/includes/admin_common.php';
if (!function_exists('devone_library_manifest_from_dir')) require_once dirname(__DIR__) . '/core/assets.php';
devone_require_permission('manage_libraries');
verify_csrf();
$msg = $error = '';
$base = dirname(__DIR__) . '/content/libraries';
if (!is_dir($base)) @mkdir($base, 0775, true);

function d1lib_cols() {
    return function_exists('devone_table_columns') ? devone_table_columns('libraries') : array('id','name','folder','type','source','url','description','active');
}
function d1lib_data($input) {
    $cols = d1lib_cols(); $out = array();
    foreach ($input as $k => $v) if (in_array($k, $cols, true)) $out[$k] = $v;
    return $out;
}
function d1lib_insert($data) {
    $data = d1lib_data($data); if (!$data) return false;
    $keys = array_keys($data); $sql = 'INSERT INTO `' . table_name('libraries') . '` (`' . implode('`,`',$keys) . '`) VALUES (' . implode(',',array_fill(0,count($keys),'?')) . ')';
    return db()->prepare($sql)->execute(array_values($data));
}
function d1lib_update($id, $data) {
    $data = d1lib_data($data); if (!$data) return false;
    $set = array(); foreach ($data as $k=>$v) $set[]='`'.$k.'`=?';
    $vals=array_values($data); $vals[]=(int)$id;
    return db()->prepare('UPDATE `'.table_name('libraries').'` SET '.implode(',',$set).' WHERE id=?')->execute($vals);
}
function d1lib_manifest($dir) {
    $m = devone_library_manifest_from_dir($dir, '');
    if (empty($m['name'])) $m['name']=ucwords(str_replace(array('-','_'),' ',basename($dir)));
    if (empty($m['version'])) $m['version']='1.0.0';
    if (empty($m['scope'])) $m['scope']='frontend';
    return $m;
}
function d1lib_types($manifest) {
    $types=array(); foreach (($manifest['assets']??array()) as $a) {
        if (is_string($a)) $a=array('path'=>$a); $p=(string)($a['path']??'');
        $t=strtolower((string)($a['type']??pathinfo($p,PATHINFO_EXTENSION))); $t=$t==='css'?'css':'js'; $types[$t]=true;
    }
    return array_keys($types);
}
function d1lib_remove_dir($dir) {
    if (!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());} @rmdir($dir);
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['action']??''); $id=(int)($_POST['id']??0);
    try {
        if ($action==='upload_library') {
            $f=$_FILES['lib_zip']??array();
            if (($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']??'')) throw new RuntimeException('Choose a valid library ZIP.');
            $tmp=dirname(__DIR__).'/storage/cache/library-'.bin2hex(random_bytes(6)); @mkdir($tmp,0775,true);
            $zipError=''; $ok=function_exists('safe_zip_extract_with_error')?safe_zip_extract_with_error($f['tmp_name'],$tmp,$zipError):safe_zip_extract($f['tmp_name'],$tmp);
            if(!$ok) throw new RuntimeException($zipError?:'Unsafe or invalid ZIP.');
            list($root)=function_exists('devone_store_find_package_root')?devone_store_find_package_root($tmp,'library'):array($tmp);
            if(!$root||!is_dir($root))$root=$tmp;
            $slug=devone_slug($_POST['library_folder']??pathinfo($f['name'],PATHINFO_FILENAME)); if(!$slug) throw new RuntimeException('Invalid library folder name.');
            $dest=$base.'/'.$slug; if(is_dir($dest)&&empty($_POST['overwrite_library'])) throw new RuntimeException('Library already exists. Enable overwrite to replace it.');
            if(is_dir($dest))d1lib_remove_dir($dest); @mkdir($dest,0775,true);
            if(function_exists('devone_store_rcopy')){$copied=devone_store_rcopy($root,$dest);}else{$copied=false;}
            if(!$copied) throw new RuntimeException('Could not install library files.');
            $manifest=d1lib_manifest($dest); $types=d1lib_types($manifest); if(!$types) throw new RuntimeException('No compiled CSS or JavaScript assets were found. Tailwind source projects must be compiled before packaging.');
            db()->prepare('DELETE FROM `'.table_name('libraries').'` WHERE folder=? AND source="local"')->execute(array($slug));
            foreach($types as $type) d1lib_insert(array('name'=>$manifest['name'].' '.strtoupper($type),'folder'=>$slug,'type'=>$type,'source'=>'local','description'=>$manifest['description']??'','active'=>1,'version'=>$manifest['version'],'manifest'=>json_encode($manifest,JSON_UNESCAPED_SLASHES),'scope'=>$manifest['scope'],'sort_order'=>(int)($manifest['sort_order']??100),'last_error'=>''));
            d1lib_remove_dir($tmp); devone_log('library_uploaded',$slug); $msg='Library installed and activated with '.count($manifest['assets']).' ordered asset(s).';
        }
        if ($action==='add_cdn') {
            $url=trim((string)($_POST['url']??'')); if(!preg_match('#^https://#i',$url)) throw new RuntimeException('CDN URLs must use HTTPS.');
            $type=($_POST['type']??'js')==='css'?'css':'js';
            d1lib_insert(array('name'=>trim((string)($_POST['name']??'CDN Library')),'folder'=>$url,'url'=>$url,'type'=>$type,'source'=>'cdn','description'=>trim((string)($_POST['description']??'')),'active'=>1,'scope'=>in_array($_POST['scope']??'',array('frontend','admin','both'),true)?$_POST['scope']:'frontend','sort_order'=>(int)($_POST['sort_order']??100),'integrity'=>trim((string)($_POST['integrity']??'')),'crossorigin'=>'anonymous','defer_load'=>!empty($_POST['defer_load'])?1:0,'async_load'=>!empty($_POST['async_load'])?1:0,'module_script'=>!empty($_POST['module_script'])?1:0));
            $msg='Secure CDN asset registered.';
        }
        if(in_array($action,array('activate','deactivate'),true)&&$id)d1lib_update($id,array('active'=>$action==='activate'?1:0));
        if($action==='save'&&$id)d1lib_update($id,array('scope'=>in_array($_POST['scope']??'',array('frontend','admin','both'),true)?$_POST['scope']:'frontend','sort_order'=>(int)($_POST['sort_order']??100),'integrity'=>trim((string)($_POST['integrity']??'')),'defer_load'=>!empty($_POST['defer_load'])?1:0,'async_load'=>!empty($_POST['async_load'])?1:0,'module_script'=>!empty($_POST['module_script'])?1:0));
        if($action==='uninstall'&&$id){$st=db()->prepare('SELECT * FROM `'.table_name('libraries').'` WHERE id=?');$st->execute(array($id));$r=$st->fetch(PDO::FETCH_ASSOC);if($r){if(($r['source']??'')==='local'){$folder=(string)$r['folder'];d1lib_remove_dir($base.'/'.$folder);db()->prepare('DELETE FROM `'.table_name('libraries').'` WHERE folder=?')->execute(array($folder));}else db()->prepare('DELETE FROM `'.table_name('libraries').'` WHERE id=?')->execute(array($id));$msg='Library removed.';}}
    } catch(Throwable $e){$error=$e->getMessage();}
}
$libs=table_exists('libraries')?db()->query('SELECT * FROM `'.table_name('libraries').'` ORDER BY '.(in_array('sort_order',d1lib_cols(),true)?'sort_order,id':'id').' ASC')->fetchAll(PDO::FETCH_ASSOC):array();
devone_admin_header('Libraries - DevOneCMS');
?>
<h1>Libraries</h1>
<p class="muted">Install production-ready CSS and JavaScript packages. DevOne loads every declared asset in manifest order and supports frontend, admin, or both scopes.</p>
<?php devone_flash($msg); devone_flash($error,'card error-card'); ?>
<div class="grid two-col">
<form method="post" enctype="multipart/form-data" class="card"><?=csrf_field()?><input type="hidden" name="action" value="upload_library"><h3>Install Library ZIP</h3><p>Supports <code>library.json</code> or <code>devone-library.json</code>. Without a manifest, compiled CSS/JS files are safely auto-discovered.</p><label>ZIP<input type="file" name="lib_zip" accept=".zip" required></label><label>Folder slug<input name="library_folder" placeholder="Optional"></label><label class="inline-check"><input type="checkbox" name="overwrite_library" value="1"> Replace existing package</label><button>Install & Activate</button></form>
<form method="post" class="card"><?=csrf_field()?><input type="hidden" name="action" value="add_cdn"><h3>Register Secure CDN Asset</h3><label>Name<input name="name" required></label><label>HTTPS URL<input type="url" name="url" required></label><div class="grid"><label>Type<select name="type"><option value="css">CSS</option><option value="js">JavaScript</option></select></label><label>Scope<select name="scope"><option value="frontend">Frontend</option><option value="admin">Admin</option><option value="both">Both</option></select></label><label>Order<input type="number" name="sort_order" value="100"></label></div><label>SRI integrity hash<input name="integrity" placeholder="sha384-..."></label><label class="inline-check"><input type="checkbox" name="defer_load" value="1" checked> Defer JavaScript</label><label class="inline-check"><input type="checkbox" name="module_script" value="1"> ES module</label><button>Add CDN Asset</button></form>
</div>
<div class="card"><h3>Package format</h3><pre>{
  "name": "Bootstrap 5",
  "version": "5.3.3",
  "scope": "frontend",
  "assets": [
    {"path":"css/bootstrap.min.css","type":"css","order":10},
    {"path":"js/bootstrap.bundle.min.js","type":"js","order":20,"defer":true}
  ]
}</pre><p class="muted"><strong>Tailwind:</strong> package a compiled CSS output such as <code>tailwind.min.css</code>. DevOne does not run Node.js builds on production servers.</p></div>
<h2>Installed Libraries</h2>
<div class="table-wrap"><table class="table"><tr><th>Name</th><th>Type</th><th>Source</th><th>Scope / Order</th><th>Status</th><th>Actions</th></tr><?php foreach($libs as $l):?><tr><td><strong><?=e($l['name']??'')?></strong><br><small><?=e($l['version']??'')?></small></td><td><?=e(strtoupper($l['type']??''))?></td><td><code><?=e($l['source']==='cdn'?($l['url']??$l['folder']):$l['folder'])?></code></td><td><form method="post" class="inline-actions"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)$l['id']?>"><select name="scope"><option value="frontend" <?=($l['scope']??'frontend')==='frontend'?'selected':''?>>Frontend</option><option value="admin" <?=($l['scope']??'')==='admin'?'selected':''?>>Admin</option><option value="both" <?=($l['scope']??'')==='both'?'selected':''?>>Both</option></select><input type="number" name="sort_order" value="<?=(int)($l['sort_order']??100)?>" style="width:80px"><button>Save</button></form></td><td><?=!empty($l['active'])?'Active':'Inactive'?></td><td class="inline-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="<?=!empty($l['active'])?'deactivate':'activate'?>"><input type="hidden" name="id" value="<?=(int)$l['id']?>"><button><?=!empty($l['active'])?'Deactivate':'Activate'?></button></form><form method="post" onsubmit="return confirm('Remove this library?');"><?=csrf_field()?><input type="hidden" name="action" value="uninstall"><input type="hidden" name="id" value="<?=(int)$l['id']?>"><button class="danger">Uninstall</button></form></td></tr><?php endforeach;?></table></div>
<?php devone_admin_footer();
