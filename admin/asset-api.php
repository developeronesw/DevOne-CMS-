<?php
require_once __DIR__ . '/includes/admin_common.php';
header('Content-Type: application/json; charset=utf-8');
function d1_asset_json($data,$status=200){ http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function d1_asset_visible_to_user($id,$uid,$all){
    $record=DevOne::assets()->find((int)$id);
    if(!$record)return false;
    return $all || ((int)($record['user_id']??0)===(int)$uid);
}
$action=(string)($_REQUEST['action']??'list');
$uid=function_exists('devone_current_user_id')?(int)devone_current_user_id():0;
$all=function_exists('devone_user_can_view_all_media')?devone_user_can_view_all_media():(function_exists('devone_has_permission')&&devone_has_permission('view_all_media'));
$canUpload=function_exists('devone_user_can_upload_media')?devone_user_can_upload_media():(function_exists('devone_has_permission')&&(devone_has_permission('upload_media')||devone_has_permission('manage_media')));
if($action==='list'){
    $types=array_filter(explode(',',(string)($_GET['types']??'')));
    $items=DevOne::assets()->search(array('search'=>$_GET['search']??'','folder'=>$_GET['folder']??'all','types'=>$types,'owner_only'=>!$all,'owner_user_id'=>$uid,'limit'=>min(250,max(1,(int)($_GET['limit']??100)))));
    d1_asset_json(array('ok'=>true,'items'=>$items,'folders'=>DevOne::assets()->folders()));
}
if($_SERVER['REQUEST_METHOD']!=='POST') d1_asset_json(array('ok'=>false,'message'=>'POST required.'),405);
$token=(string)($_POST['_csrf']??$_POST['csrf_token']??'');
if(!function_exists('devone_verify_csrf_token') || !devone_verify_csrf_token($token)) d1_asset_json(array('ok'=>false,'message'=>'Security token expired.'),403);
if($action==='upload'){
    if(!$canUpload)d1_asset_json(array('ok'=>false,'message'=>'Upload permission denied.'),403);
    d1_asset_json(DevOne::assets()->upload($_FILES['asset']??array(),array('folder'=>$_POST['folder']??'auto','purpose'=>$_POST['purpose']??'picker','alt_text'=>$_POST['alt_text']??'','user_id'=>$uid)));
}
$id=(int)($_POST['id']??0);
if($id<=0 || !d1_asset_visible_to_user($id,$uid,$all)) d1_asset_json(array('ok'=>false,'message'=>'Asset not found or access denied.'),404);
if($action==='update'){
    if(!function_exists('devone_has_permission') || (!devone_has_permission('manage_media') && !devone_has_permission('upload_media') && !$all)) d1_asset_json(array('ok'=>false,'message'=>'Media permission denied.'),403);
    d1_asset_json(DevOne::assets()->update($id,array('alt_text'=>$_POST['alt_text']??'','caption'=>$_POST['caption']??'','title'=>$_POST['title']??'')));
}
if($action==='usage') d1_asset_json(array('ok'=>true,'items'=>DevOne::assets()->usage($id)));
if($action==='duplicates'){
    $items=DevOne::assets()->duplicates($id);
    if(!$all){$items=array_values(array_filter((array)$items,function($item)use($uid){return (int)($item['user_id']??0)===$uid;}));}
    d1_asset_json(array('ok'=>true,'items'=>$items));
}
d1_asset_json(array('ok'=>false,'message'=>'Unknown Asset Manager action.'),400);
