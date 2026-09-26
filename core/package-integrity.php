<?php
if (defined('DEVONE_PACKAGE_INTEGRITY_LOADED')) { return; }
define('DEVONE_PACKAGE_INTEGRITY_LOADED', true);

function devone_package_limits() {
    return array(
        'max_archive_bytes' => defined('DEVONE_MAX_PACKAGE_BYTES') ? (int)DEVONE_MAX_PACKAGE_BYTES : 104857600,
        'max_files' => defined('DEVONE_MAX_PACKAGE_FILES') ? (int)DEVONE_MAX_PACKAGE_FILES : 5000,
        'max_uncompressed_bytes' => defined('DEVONE_MAX_PACKAGE_UNCOMPRESSED_BYTES') ? (int)DEVONE_MAX_PACKAGE_UNCOMPRESSED_BYTES : 536870912,
        'max_single_file_bytes' => defined('DEVONE_MAX_PACKAGE_FILE_BYTES') ? (int)DEVONE_MAX_PACKAGE_FILE_BYTES : 134217728,
        'max_ratio' => defined('DEVONE_MAX_PACKAGE_RATIO') ? (float)DEVONE_MAX_PACKAGE_RATIO : 250.0,
    );
}
function devone_package_public_key() {
    if (defined('DEVONE_UPDATE_PUBLIC_KEY') && trim((string)DEVONE_UPDATE_PUBLIC_KEY) !== '') { return trim((string)DEVONE_UPDATE_PUBLIC_KEY); }
    $path = __DIR__ . '/update-public-key.pem';
    return is_file($path) ? trim((string)@file_get_contents($path)) : '';
}
function devone_package_manifest_payload($manifest) { $copy=(array)$manifest; unset($copy['signature']); ksort($copy); return json_encode($copy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
function devone_verify_signed_manifest($manifest, &$error = '') {
    $error=''; if(!is_array($manifest)){$error='Package manifest is invalid.';return false;}
    $signature=base64_decode((string)($manifest['signature']??''),true);$key=devone_package_public_key();
    if($key===''){$error='No official update verification public key is configured.';return false;}
    if($signature===false||$signature===''){$error='Official package signature is missing or malformed.';return false;}
    if(!function_exists('openssl_verify')){$error='OpenSSL is required to verify official package signatures.';return false;}
    if(@openssl_verify(devone_package_manifest_payload($manifest),$signature,$key,OPENSSL_ALGO_SHA256)!==1){$error='Official package signature verification failed.';return false;}return true;
}
function devone_package_read_le16($data,$offset){$p=substr($data,$offset,2);if(strlen($p)!==2)return 0;$u=unpack('v',$p);return (int)$u[1];}
function devone_package_read_le32($data,$offset){$p=substr($data,$offset,4);if(strlen($p)!==4)return 0;$u=unpack('V',$p);return (int)$u[1];}
function devone_zip_external_attributes_is_symlink($attrs){$mode=((int)$attrs>>16)&0xFFFF;return ($mode & 0170000)===0120000;}
function devone_ziparchive_entry_is_symlink($zip,$index){
    $opsys=0;$attrs=0;
    if(method_exists($zip,'getExternalAttributesIndex') && @$zip->getExternalAttributesIndex((int)$index,$opsys,$attrs)){ return devone_zip_external_attributes_is_symlink($attrs); }
    $st=@$zip->statIndex((int)$index); return is_array($st)&&isset($st['external_attributes'])&&devone_zip_external_attributes_is_symlink((int)$st['external_attributes']);
}
function devone_package_path_safe($name,&$reason=''){
    if(function_exists('devone_zip_entry_is_safe'))return devone_zip_entry_is_safe($name,$reason);
    $reason='';$name=str_replace('\\','/',(string)$name);if($name===''||strpos($name,"\0")!==false){$reason='empty/null path';return false;}if($name[0]==='/'||preg_match('/^[A-Za-z]:/',$name)){$reason='absolute path';return false;}foreach(explode('/',$name)as$p){if($p==='..'){$reason='parent traversal';return false;}}return true;
}
function devone_package_entry_policy($name,$u,$c,$attrs,&$files,&$total,&$compressed,$limits,&$error){
    $name=str_replace('\\','/',(string)$name);$reason='';if(!devone_package_path_safe($name,$reason)){$error='Unsafe package path: '.$name;return false;}
    if(devone_zip_external_attributes_is_symlink($attrs)){$error='Symbolic links are not allowed in installable packages: '.$name;return false;}
    if(preg_match('#(^|/)(\.git|\.svn|node_modules|vendor/bin)(/|$)#i',$name)){$error='Development-only directory is not allowed in installable packages: '.$name;return false;}
    if(substr($name,-1)==='/')return true;
    $files++;$u=(int)$u;$c=max(1,(int)$c);$total+=$u;$compressed+=$c;
    if($files>$limits['max_files']){$error='Package contains too many files.';return false;}
    if($u>$limits['max_single_file_bytes']){$error='Package contains a file larger than the allowed limit: '.$name;return false;}
    if($total>$limits['max_uncompressed_bytes']){$error='Package expands beyond the allowed total size.';return false;}
    if($u>1048576&&($u/$c)>$limits['max_ratio']){$error='Suspicious ZIP compression ratio blocked: '.$name;return false;}return true;
}
function devone_package_scan_zip($zipPath,&$error='',&$report=array()){
    return devone_package_scan_zip_with_limits($zipPath,devone_package_limits(),$error,$report);
}
function devone_package_scan_zip_with_limits($zipPath,$limits,&$error='',&$report=array()){
    $error='';$report=array('files'=>0,'compressed_bytes'=>0,'uncompressed_bytes'=>0,'sha256'=>'');if(!is_file($zipPath)){$error='Package file was not found.';return false;}
    $limits=array_merge(devone_package_limits(),(array)$limits);$size=(int)filesize($zipPath);if($size<=0||$size>(int)$limits['max_archive_bytes']){$error='Package exceeds the allowed archive size.';return false;}$report['sha256']=hash_file('sha256',$zipPath);$files=0;$total=0;$compressed=0;
    if(class_exists('ZipArchive')){$zip=new ZipArchive();if($zip->open($zipPath)!==true){$error='Package cannot be opened as ZIP.';return false;}for($i=0;$i<$zip->numFiles;$i++){$st=$zip->statIndex($i);if(!is_array($st)){$zip->close();$error='Package contains an unreadable ZIP entry.';return false;}$attrs=0;$opsys=0;if(method_exists($zip,'getExternalAttributesIndex')){@$zip->getExternalAttributesIndex($i,$opsys,$attrs);}elseif(isset($st['external_attributes'])){$attrs=(int)$st['external_attributes'];}if(!devone_package_entry_policy($st['name']??'',(int)($st['size']??0),(int)($st['comp_size']??0),$attrs,$files,$total,$compressed,$limits,$error)){$zip->close();return false;}}$zip->close();}
    else{if($size>104857600){$error='ZipArchive is required to inspect archives larger than 100 MB safely.';return false;}$blob=@file_get_contents($zipPath);if($blob===false||strlen($blob)<22){$error='Package ZIP could not be inspected.';return false;}$eocd=strrpos($blob,"PK\x05\x06");if($eocd===false){$error='ZIP central directory was not found.';return false;}$entries=devone_package_read_le16($blob,$eocd+10);$offset=devone_package_read_le32($blob,$eocd+16);if($entries<=0||$entries>(int)$limits['max_files']||$offset<=0||$offset>=strlen($blob)){$error='ZIP central directory is invalid.';return false;}$pos=$offset;for($i=0;$i<$entries;$i++){if(substr($blob,$pos,4)!=="PK\x01\x02"){$error='ZIP central directory entry is invalid.';return false;}$c=devone_package_read_le32($blob,$pos+20);$u=devone_package_read_le32($blob,$pos+24);if($c===0xFFFFFFFF||$u===0xFFFFFFFF){$error='ZIP64 packages are not supported by the fallback security scanner.';return false;}$nl=devone_package_read_le16($blob,$pos+28);$el=devone_package_read_le16($blob,$pos+30);$cl=devone_package_read_le16($blob,$pos+32);$attrs=devone_package_read_le32($blob,$pos+38);$name=substr($blob,$pos+46,$nl);if(!devone_package_entry_policy($name,$u,$c,$attrs,$files,$total,$compressed,$limits,$error))return false;$pos+=46+$nl+$el+$cl;if($pos>strlen($blob)){$error='ZIP central directory extends beyond archive bounds.';return false;}}}
    $report['files']=$files;$report['compressed_bytes']=$compressed;$report['uncompressed_bytes']=$total;return true;
}
function devone_verify_extracted_manifest($root,&$error=''){
    $error='';$manifestPath=rtrim($root,'/\\').'/devone-package.json';if(!is_file($manifestPath))return true;$manifest=json_decode((string)file_get_contents($manifestPath),true);if(!is_array($manifest)){$error='devone-package.json is invalid JSON.';return false;}
    foreach((array)($manifest['files']??array())as$relative=>$expected){$relative=str_replace('\\','/',ltrim((string)$relative,'/'));$reason='';if(!devone_package_path_safe($relative,$reason)){$error='Unsafe manifest file path.';return false;}if(!preg_match('/^[a-f0-9]{64}$/i',(string)$expected)){$error='Package manifest contains an invalid SHA-256 declaration: '.$relative;return false;}$file=rtrim($root,'/\\').'/'.$relative;if(!is_file($file)||!hash_equals(strtolower((string)$expected),strtolower((string)hash_file('sha256',$file)))){$error='Package file integrity check failed: '.$relative;return false;}}
    if(!empty($manifest['official']))return devone_verify_signed_manifest($manifest,$error);return true;
}
