<?php
/**
 * DevOne Assets — shared media upload, query, metadata and usage service.
 * @since 1.2.8
 */
if (defined('DEVONE_ASSET_MANAGER_LOADED')) { return; }
define('DEVONE_ASSET_MANAGER_LOADED', true);

function devone_asset_allowed_extensions() {
    return array('jpg','jpeg','png','gif','webp','mp4','webm','mov','m4v','mp3','wav','ogg','m4a','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','json','woff','woff2','ttf','otf');
}
function devone_asset_max_bytes($type='other') {
    $map=array('images'=>12*1024*1024,'videos'=>250*1024*1024,'audio'=>80*1024*1024,'documents'=>40*1024*1024,'archives'=>100*1024*1024,'fonts'=>20*1024*1024,'other'=>40*1024*1024);
    return isset($map[$type]) ? $map[$type] : $map['other'];
}
function devone_asset_mime($tmp, $fallback='') {
    if (function_exists('finfo_open')) { $f=@finfo_open(FILEINFO_MIME_TYPE); if ($f) { $m=@finfo_file($f,$tmp); @finfo_close($f); if ($m) return strtolower((string)$m); } }
    return strtolower(trim((string)$fallback));
}
function devone_asset_record($id) {
    $id=(int)$id; if ($id<=0) return null;
    $tbl=devone_require_table('media',true); if ($tbl==='') return null;
    $cols=devone_table_columns('media'); $where='id=?'; $vals=array($id);
    if (in_array('site_id',$cols,true)) { $where.=' AND site_id=?'; $vals[]=function_exists('devone_content_site_id')?devone_content_site_id():1; }
    try { $s=db()->prepare('SELECT * FROM `'.$tbl.'` WHERE '.$where.' LIMIT 1'); $s->execute($vals); $r=$s->fetch(); return $r?:null; } catch(Throwable $e){ return null; }
}
function devone_asset_public_record($record) {
    if (!is_array($record)) return null;
    $record['id']=(int)($record['id']??0); $record['size_bytes']=(int)($record['size_bytes']??0); $record['user_id']=(int)($record['user_id']??0);
    $record['url']=function_exists('devone_site_url')?devone_site_url((string)($record['path']??'')):(string)($record['path']??'');
    $record['type']=function_exists('devone_media_record_type')?devone_media_record_type($record):(string)($record['media_type']??'other');
    $record['folder']=function_exists('devone_media_record_folder')?devone_media_record_folder($record):(string)($record['folder']??'other');
    return $record;
}
function devone_asset_search($args=array()) {
    $args=array_merge(array('search'=>'','folder'=>'all','types'=>array(),'owner_only'=>false,'owner_user_id'=>0,'limit'=>100,'offset'=>0), (array)$args);
    $items=devone_media_query((string)$args['folder'],(bool)$args['owner_only'],(int)$args['owner_user_id']);
    $search=strtolower(trim((string)$args['search'])); $types=array_values(array_filter(array_map('strval',(array)$args['types'])));
    $out=array(); foreach($items as $r){ $type=devone_media_record_type($r); if($types && !in_array($type,$types,true) && !in_array((string)($r['mime_type']??''),$types,true)) continue;
        if($search!==''){ $hay=strtolower(implode(' ',array($r['filename']??'',$r['alt_text']??'',$r['folder']??'',$r['mime_type']??''))); if(strpos($hay,$search)===false) continue; }
        $out[]=devone_asset_public_record($r);
    }
    return array_slice($out,max(0,(int)$args['offset']),max(1,min(500,(int)$args['limit'])));
}
function devone_media_upload($file,$options=array()) {
    $o=array_merge(array('folder'=>'auto','purpose'=>'content','alt_text'=>'','user_id'=>0,'allowed_types'=>array(),'max_bytes'=>0), (array)$options);
    if (empty($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return array('ok'=>false,'message'=>'No valid uploaded file was received.');
    if (!empty($file['error'])) return array('ok'=>false,'message'=>'Upload failed with PHP error #'.(int)$file['error'].'.');
    $original=basename((string)($file['name']??'asset')); $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
    if (!in_array($ext,devone_asset_allowed_extensions(),true)) return array('ok'=>false,'message'=>'This file type is not allowed in the DevOne Asset Manager.');
    $mime=devone_asset_mime($file['tmp_name'],$file['type']??''); $type=devone_media_type_from_file($original,$mime);
    if ((array)$o['allowed_types'] && !in_array($type,(array)$o['allowed_types'],true) && !in_array($mime,(array)$o['allowed_types'],true)) return array('ok'=>false,'message'=>'This asset type is not allowed for this field.');
    $actualSize=@filesize($file['tmp_name']); if($actualSize===false) return array('ok'=>false,'message'=>'The uploaded file size could not be verified.');
    $max=(int)$o['max_bytes']>0?(int)$o['max_bytes']:devone_asset_max_bytes($type); if((int)$actualSize>$max) return array('ok'=>false,'message'=>'The uploaded file exceeds the allowed size.');
    if(preg_match('/(?:php|phtml|phar|cgi|pl|py|sh|shtml|htaccess)/i',$ext)) return array('ok'=>false,'message'=>'Executable uploads are not allowed.');
    if(in_array($mime,array('text/x-php','application/x-httpd-php','application/x-php','application/x-executable','application/x-sharedlib'),true)) return array('ok'=>false,'message'=>'Executable content is not allowed in the Asset Manager.');
    if($type==='images' && !@getimagesize($file['tmp_name'])) return array('ok'=>false,'message'=>'The uploaded image is invalid.');
    $folder=(string)$o['folder']; if($folder===''||$folder==='auto') $folder=$type; $folder=devone_media_folder_slug($folder);
    $dir=devone_media_folder_path($folder); if(!is_dir($dir)&&!@mkdir($dir,0775,true)) return array('ok'=>false,'message'=>'Could not create the asset folder.');
    if(!is_writable($dir)) return array('ok'=>false,'message'=>'Asset folder is not writable: '.$folder);
    $base=devone_slugify(pathinfo($original,PATHINFO_FILENAME),'asset'); $name=$base.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).($ext?'.'.$ext:''); $dest=$dir.'/'.$name;
    if(!move_uploaded_file($file['tmp_name'],$dest)) return array('ok'=>false,'message'=>'Could not move the uploaded asset into storage.');
    $rel=(function_exists('devone_network_enabled')&&devone_network_enabled())?('content/sites/'.(function_exists('devone_content_site_id')?devone_content_site_id():1).'/uploads/'.$folder.'/'.$name):('content/media/'.$folder.'/'.$name);
    $owner=(int)$o['user_id']; if($owner<=0&&function_exists('devone_current_user_id')) $owner=(int)devone_current_user_id();
    $id=devone_insert_media_record(array('filename'=>$name,'path'=>$rel,'mime_type'=>$mime,'size_bytes'=>@filesize($dest)?:0,'alt_text'=>$o['alt_text'],'folder'=>$folder,'media_type'=>$type,'user_id'=>$owner));
    if(!$id){ @unlink($dest); return array('ok'=>false,'message'=>'The file was received, but its Asset Manager record could not be created.'); }
    $record=devone_asset_record($id); if(function_exists('do_action')) do_action('devone_asset_uploaded',$record,$o);
    if(function_exists('devone_cache_flush')) @devone_cache_flush('assets');
    return array('ok'=>true,'id'=>(int)$id,'media_id'=>(int)$id,'path'=>$rel,'url'=>function_exists('devone_site_url')?devone_site_url($rel):$rel,'record'=>devone_asset_public_record($record),'message'=>'Asset uploaded and sorted into '.$folder.'.');
}
function devone_asset_update($id,$data=array()) {
    $r=devone_asset_record($id); if(!$r) return array('ok'=>false,'message'=>'Asset not found.');
    $tbl=devone_require_table('media',true); $cols=devone_table_columns('media'); $allowed=array('alt_text','caption','title','metadata_json','folder'); $set=array();$vals=array();
    foreach($allowed as $k){ if(array_key_exists($k,$data)&&in_array($k,$cols,true)){ $v=$data[$k]; if($k==='metadata_json'&&is_array($v))$v=json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); if($k==='folder')$v=devone_media_folder_slug($v); $set[]='`'.$k.'`=?';$vals[]=$v; }}
    if(!$set) return array('ok'=>true,'record'=>devone_asset_public_record($r),'message'=>'No asset fields required an update.');
    $where='id=?'; $vals[]=(int)$id;
    if(in_array('site_id',$cols,true)){ $where.=' AND site_id=?'; $vals[]=function_exists('devone_content_site_id')?devone_content_site_id():1; }
    try{$s=db()->prepare('UPDATE `'.$tbl.'` SET '.implode(',',$set).' WHERE '.$where);$s->execute($vals);$u=devone_asset_record($id);if(function_exists('do_action'))do_action('devone_asset_updated',$u,$r);return array('ok'=>true,'record'=>devone_asset_public_record($u),'message'=>'Asset details updated.');}catch(Throwable $e){return array('ok'=>false,'message'=>'Asset update failed.');}
}
function devone_asset_usage_table(){ $prefix=defined('DB_PREFIX')?DB_PREFIX:'cms_'; return $prefix.'asset_usage'; }
function devone_asset_ensure_usage_table(){ static $done=false;if($done)return true;try{$t=devone_asset_usage_table();db()->exec("CREATE TABLE IF NOT EXISTS `{$t}` (`id` bigint NOT NULL AUTO_INCREMENT,`site_id` int NOT NULL DEFAULT 1,`asset_id` int NOT NULL,`context_type` varchar(80) NOT NULL,`context_id` varchar(190) DEFAULT '',`context_label` varchar(255) DEFAULT '',`created_at` datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `asset_context` (`site_id`,`asset_id`,`context_type`,`context_id`),KEY `asset_id` (`asset_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$done=true;return true;}catch(Throwable $e){return false;} }
function devone_asset_attach($assetId,$contextType,$contextId='',$label=''){ devone_asset_ensure_usage_table();try{$t=devone_asset_usage_table();$s=db()->prepare("INSERT INTO `{$t}` (site_id,asset_id,context_type,context_id,context_label) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE context_label=VALUES(context_label)");return $s->execute(array(function_exists('devone_content_site_id')?devone_content_site_id():1,(int)$assetId,devone_slugify($contextType,'content'),(string)$contextId,(string)$label));}catch(Throwable $e){return false;} }
function devone_asset_detach($assetId,$contextType='',$contextId=''){ devone_asset_ensure_usage_table();try{$t=devone_asset_usage_table();$w=array('site_id=?','asset_id=?');$v=array(function_exists('devone_content_site_id')?devone_content_site_id():1,(int)$assetId);if($contextType!==''){$w[]='context_type=?';$v[]=devone_slugify($contextType,'content');}if($contextId!==''){$w[]='context_id=?';$v[]=(string)$contextId;}$s=db()->prepare("DELETE FROM `{$t}` WHERE ".implode(' AND ',$w));return $s->execute($v);}catch(Throwable $e){return false;} }
function devone_asset_usage($assetId){ devone_asset_ensure_usage_table();try{$t=devone_asset_usage_table();$s=db()->prepare("SELECT * FROM `{$t}` WHERE site_id=? AND asset_id=? ORDER BY id DESC");$s->execute(array(function_exists('devone_content_site_id')?devone_content_site_id():1,(int)$assetId));return $s->fetchAll();}catch(Throwable $e){return array();} }
function devone_asset_duplicates($assetId){ $r=devone_asset_record($assetId);if(!$r)return array();$path=__DIR__.'/../'.ltrim((string)$r['path'],'/');if(!is_file($path))return array();$hash=@hash_file('sha256',$path);if(!$hash)return array();$all=devone_media_query('all',false,0);$out=array();foreach($all as $x){if((int)$x['id']===(int)$assetId)continue;$p=__DIR__.'/../'.ltrim((string)$x['path'],'/');if(is_file($p)&&@filesize($p)===@filesize($path)&&@hash_file('sha256',$p)===$hash)$out[]=devone_asset_public_record($x);}return $out; }
function devone_asset_replace($id,$file,$options=array()) {
    $old=devone_asset_record($id); if(!$old)return array('ok'=>false,'message'=>'Asset not found.');
    $options['folder']=$old['folder']??'auto';$options['alt_text']=$old['alt_text']??'';$new=devone_media_upload($file,$options);if(empty($new['ok']))return $new;
    $newr=$new['record'];$tbl=devone_require_table('media',true);$cols=devone_table_columns('media');try{$siteId=function_exists('devone_content_site_id')?devone_content_site_id():1;$where='id=?';$vals=array($newr['filename'],$newr['path'],$newr['mime_type'],$newr['size_bytes'],$newr['media_type']??$newr['type'],(int)$id);if(in_array('site_id',$cols,true)){$where.=' AND site_id=?';$vals[]=$siteId;}$s=db()->prepare('UPDATE `'.$tbl.'` SET filename=?,path=?,mime_type=?,size_bytes=?,media_type=? WHERE '.$where);$s->execute($vals);$tmpTbl=devone_require_table('media',true);if($tmpTbl!==''){ $tmpWhere='id=?';$tmpVals=array((int)$new['id']);if(in_array('site_id',$cols,true)){$tmpWhere.=' AND site_id=?';$tmpVals[]=$siteId;}$tmpDel=db()->prepare('DELETE FROM `'.$tmpTbl.'` WHERE '.$tmpWhere.' LIMIT 1');$tmpDel->execute($tmpVals); }$oldPath=__DIR__.'/../'.ltrim((string)$old['path'],'/');if(is_file($oldPath))@unlink($oldPath);$updated=devone_asset_record($id);if(function_exists('do_action'))do_action('devone_asset_replaced',$updated,$old);return array('ok'=>true,'record'=>devone_asset_public_record($updated),'message'=>'Asset replaced everywhere that references its ID.');}catch(Throwable $e){return array('ok'=>false,'message'=>'Asset replacement failed.');}
}
