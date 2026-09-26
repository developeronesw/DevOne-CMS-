<?php
if (!file_exists(__DIR__.'/config.php')) { http_response_code(503); exit('DevOneCMS is not installed.'); }
require_once __DIR__.'/config.php';
if (is_file(__DIR__ . '/core/version.php')) { require_once __DIR__ . '/core/version.php'; }
require_once __DIR__.'/core/db.php';
require_once __DIR__.'/core/schema.php';
require_once __DIR__.'/core/functions.php';
if (is_file(__DIR__.'/core/license.php')) { require_once __DIR__.'/core/license.php'; }
if (is_file(__DIR__.'/core/network.php')) { require_once __DIR__.'/core/network.php'; }
require_once __DIR__.'/core/api_runtime.php';
// Automatic schema repair removed from normal request paths.
devone_api_schema_install();
$started = microtime(true);
$requestId = devone_api_request_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = trim((string)($_GET['route'] ?? ''), '/');
if ($path === '') {
    $uri = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/');
    $parts = explode('/', $uri);
    $apiPos = array_search('api', $parts, true);
    if ($apiPos !== false) { $path = implode('/', array_slice($parts, $apiPos + 1)); }
}
$path = devone_api_clean_path($path);
$segments = array_values(array_filter(explode('/', $path), 'strlen'));
$recordId = null;
if ($segments && ctype_digit((string)end($segments))) { $recordId = (int)array_pop($segments); }
$route = implode('/', $segments);
if ($route === '') {
    devone_api_log_request(0,0,$method,$path,404,$started,$requestId,'Missing route');
    devone_api_respond(404,array('ok'=>false,'error'=>'Endpoint not found','request_id'=>$requestId),$requestId);
}

$lookupMethod = $method === 'HEAD' ? 'GET' : $method;
$endpointTable = table_name('api_endpoints');
if ($method === 'OPTIONS') {
    $stmt = db()->prepare("SELECT * FROM `{$endpointTable}` WHERE path=? AND active=1 ORDER BY id ASC LIMIT 1");
    $stmt->execute(array($route));
} else {
    $stmt = db()->prepare("SELECT * FROM `{$endpointTable}` WHERE path=? AND method=? AND active=1 LIMIT 1");
    $stmt->execute(array($route,$lookupMethod));
}
$endpoint = $stmt->fetch();
if (!$endpoint) {
    devone_api_log_request(0,0,$method,$path,404,$started,$requestId,'No active endpoint');
    devone_api_respond(404,array('ok'=>false,'error'=>'Endpoint not found','request_id'=>$requestId),$requestId);
}

devone_api_apply_cors($endpoint);
if ($method === 'OPTIONS') { http_response_code(204); exit; }

$key = devone_api_authenticate();
$keyId = (int)($key['id'] ?? 0);
$isWrite = in_array($method,array('POST','PUT','PATCH','DELETE'),true);
$requiresAuth = !empty($endpoint['auth_required']) || ($isWrite && empty($endpoint['allow_public_write']));
if ($requiresAuth && !$key) {
    devone_api_log_request((int)$endpoint['id'],0,$method,$path,401,$started,$requestId,'Authentication required');
    devone_api_respond(401,array('ok'=>false,'error'=>'Valid Bearer token required','request_id'=>$requestId),$requestId);
}
if ($key && !devone_api_key_allows($key,$endpoint)) {
    devone_api_log_request((int)$endpoint['id'],$keyId,$method,$path,403,$started,$requestId,'Permission denied');
    devone_api_respond(403,array('ok'=>false,'error'=>'API key is not permitted to use this endpoint','request_id'=>$requestId),$requestId);
}

$identity = $key ? 'key:'.$keyId : 'ip:'.devone_api_client_ip();
$rate = devone_api_rate_limit($identity.'|'.$endpoint['id'], (int)($endpoint['rate_limit_per_minute'] ?? 60));
header('X-RateLimit-Limit: '.$rate['limit']);
header('X-RateLimit-Remaining: '.$rate['remaining']);
header('X-RateLimit-Reset: '.$rate['reset']);
if (!$rate['allowed']) {
    devone_api_log_request((int)$endpoint['id'],$keyId,$method,$path,429,$started,$requestId,'Rate limit exceeded');
    devone_api_respond(429,array('ok'=>false,'error'=>'Rate limit exceeded','request_id'=>$requestId),$requestId);
}

$baseTable = devone_base_table_name($endpoint['source_table']);
if (!$baseTable || !table_exists($baseTable)) {
    devone_api_log_request((int)$endpoint['id'],$keyId,$method,$path,500,$started,$requestId,'Missing source table');
    devone_api_respond(500,array('ok'=>false,'error'=>'Endpoint source is unavailable','request_id'=>$requestId),$requestId);
}
$source = table_name($baseTable);
$columns = devone_api_table_columns($source);
$readFields = devone_api_safe_fields($endpoint,$columns,false);
$writeFields = devone_api_safe_fields($endpoint,$columns,true);
if(!$readFields){ devone_api_log_request((int)$endpoint['id'],$keyId,$method,$path,500,$started,$requestId,'No safe readable fields configured'); devone_api_respond(500,array('ok'=>false,'error'=>'Endpoint has no safe readable fields configured','request_id'=>$requestId),$requestId); }
$siteId = function_exists('devone_current_site_id') ? max(1,(int)devone_current_site_id()) : 1;
$siteScoped = !empty($endpoint['site_scoped']) && isset($columns['site_id']);

try {
    if ($method === 'GET' || $method === 'HEAD') {
        $select = implode(',',array_map(fn($f)=>"`{$f}`",$readFields));
        $where = array(); $params = array();
        if ($recordId !== null) { $where[]='`id`=?'; $params[]=$recordId; }
        elseif (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) { $where[]='`id`=?'; $params[]=(int)$_GET['id']; }
        if ($siteScoped) { $where[]='`site_id`=?'; $params[]=$siteId; }
        if (!$key && $baseTable==='pages' && isset($columns['status'])) { $where[]="`status`='published'"; }
        foreach ($_GET as $field=>$value) {
            $field = preg_replace('/[^a-zA-Z0-9_]/','',(string)$field);
            if (in_array($field,array('route','id','page','per_page','sort','order','search'),true)) { continue; }
            if (in_array($field,$readFields,true) && !is_array($value)) { $where[]="`{$field}`=?"; $params[]=$value; }
        }
        $search = trim((string)($_GET['search'] ?? ''));
        if ($search !== '') {
            $searchable = array_slice(array_values(array_filter($readFields,fn($f)=>preg_match('/name|title|slug|description|content|email/i',$f))),0,8);
            if ($searchable) { $where[]='('.implode(' OR ',array_map(fn($f)=>"`{$f}` LIKE ?",$searchable)).')'; foreach($searchable as $_){$params[]='%'.$search.'%';} }
        }
        $sql="SELECT {$select} FROM `{$source}`".($where?' WHERE '.implode(' AND ',$where):'');
        if ($recordId !== null || isset($_GET['id'])) { $sql.=' LIMIT 1'; $q=db()->prepare($sql); $q->execute($params); $data=$q->fetch(); $status=$data?200:404; }
        else {
            $sort=preg_replace('/[^a-zA-Z0-9_]/','',(string)($_GET['sort'] ?? 'id')); if(!in_array($sort,$readFields,true)){$sort=in_array('id',$readFields,true)?'id':$readFields[0];}
            $order=strtoupper((string)($_GET['order'] ?? 'DESC')); if(!in_array($order,array('ASC','DESC'),true)){$order='DESC';}
            $max=max(1,min(500,(int)($endpoint['max_page_size'] ?? 100))); $per=max(1,min($max,(int)($_GET['per_page'] ?? 25))); $page=max(1,(int)($_GET['page'] ?? 1)); $offset=($page-1)*$per;
            $countSql="SELECT COUNT(*) FROM `{$source}`".($where?' WHERE '.implode(' AND ',$where):''); $cq=db()->prepare($countSql); $cq->execute($params); $total=(int)$cq->fetchColumn();
            $sql.=" ORDER BY `{$sort}` {$order} LIMIT {$per} OFFSET {$offset}"; $q=db()->prepare($sql); $q->execute($params); $data=$q->fetchAll(); $status=200;
        }
        devone_api_log_request((int)$endpoint['id'],$keyId,$method,$path,$status,$started,$requestId,'');
        if ($method==='HEAD') { http_response_code($status); header('X-Request-ID: '.$requestId); exit; }
        if (isset($total)) { devone_api_respond(200,array('ok'=>true,'data'=>$data,'pagination'=>array('page'=>$page,'per_page'=>$per,'total'=>$total,'pages'=>(int)ceil($total/$per)),'request_id'=>$requestId),$requestId); }
        devone_api_respond($status,array('ok'=>$status===200,'data'=>$data,'request_id'=>$requestId),$requestId);
    }

    if (!$writeFields) { throw new RuntimeException('No writable fields are configured for this endpoint.'); }
    $input=devone_api_input();
    $payload=devone_api_filter_payload($input,$writeFields);
    $required=devone_api_json_decode($endpoint['required_fields'] ?? '',array());
    foreach($required as $field){ if(($method==='POST' || array_key_exists($field,$payload)) && (!array_key_exists($field,$payload) || $payload[$field]==='')) { devone_api_log_request((int)$endpoint['id'],$keyId,$method,$path,422,$started,$requestId,'Missing required field'); devone_api_respond(422,array('ok'=>false,'error'=>'Missing required field: '.$field,'request_id'=>$requestId),$requestId); } }
    if ($siteScoped) { $payload['site_id']=$siteId; }

    $headers=devone_api_headers(); $idemKey=trim((string)($headers['idempotency-key'] ?? ''));
    if($idemKey!=='' && !$key){ $idemKey='public:'.substr(hash('sha256',devone_api_client_ip()),0,24).':'.$idemKey; }
    if (!empty($endpoint['idempotency_required']) && in_array($method,array('POST','PUT','PATCH'),true) && $idemKey==='') { devone_api_respond(400,array('ok'=>false,'error'=>'Idempotency-Key header is required','request_id'=>$requestId),$requestId); }
    if ($idemKey!=='') {
        if(strlen($idemKey)>190){devone_api_respond(400,array('ok'=>false,'error'=>'Idempotency key is too long','request_id'=>$requestId),$requestId);}
        $idemTable=devone_api_table('api_idempotency'); $iq=db()->prepare("SELECT * FROM `{$idemTable}` WHERE endpoint_id=? AND api_key_id=? AND idempotency_key=? LIMIT 1"); $iq->execute(array((int)$endpoint['id'],$keyId,$idemKey)); $old=$iq->fetch();
        $requestHash=hash('sha256',$method.'|'.$path.'|'.json_encode($payload));
        if($old){ if(!hash_equals($old['request_hash'],$requestHash)){devone_api_respond(409,array('ok'=>false,'error'=>'Idempotency key was already used with different data','request_id'=>$requestId),$requestId);} http_response_code((int)$old['status_code']); header('Content-Type: application/json'); echo $old['response_body']; exit; }
    }

    if ($method==='POST') {
        if(!$payload){devone_api_respond(422,array('ok'=>false,'error'=>'No permitted fields were supplied','request_id'=>$requestId),$requestId);}
        $fields=array_keys($payload); $sql="INSERT INTO `{$source}` (`".implode('`,`',$fields)."`) VALUES (".implode(',',array_fill(0,count($fields),'?')).")"; $q=db()->prepare($sql); $q->execute(array_values($payload)); $id=(int)db()->lastInsertId(); $status=201; $response=array('ok'=>true,'id'=>$id,'request_id'=>$requestId);
    } elseif ($method==='PUT' || $method==='PATCH') {
        $id=$recordId ?: (int)($input['id'] ?? $_GET['id'] ?? 0); if($id<=0){devone_api_respond(400,array('ok'=>false,'error'=>'Record ID is required','request_id'=>$requestId),$requestId);} unset($payload['id']); if(!$payload){devone_api_respond(422,array('ok'=>false,'error'=>'No permitted fields were supplied','request_id'=>$requestId),$requestId);}
        $sets=array_map(fn($f)=>"`{$f}`=?",array_keys($payload)); $params=array_values($payload); $where='`id`=?'; $params[]=$id; if($siteScoped){$where.=' AND `site_id`=?';$params[]=$siteId;} $q=db()->prepare("UPDATE `{$source}` SET ".implode(',',$sets)." WHERE {$where}"); $q->execute($params); $status=200; $response=array('ok'=>true,'id'=>$id,'updated'=>$q->rowCount(),'request_id'=>$requestId);
    } elseif ($method==='DELETE') {
        $id=$recordId ?: (int)($_GET['id'] ?? $input['id'] ?? 0); if($id<=0){devone_api_respond(400,array('ok'=>false,'error'=>'Record ID is required','request_id'=>$requestId),$requestId);} $params=array($id); $where='`id`=?'; if($siteScoped){$where.=' AND `site_id`=?';$params[]=$siteId;} $q=db()->prepare("DELETE FROM `{$source}` WHERE {$where} LIMIT 1"); $q->execute($params); $status=$q->rowCount()?200:404; $response=array('ok'=>$status===200,'deleted'=>$q->rowCount(),'id'=>$id,'request_id'=>$requestId);
    } else { devone_api_respond(405,array('ok'=>false,'error'=>'Method not allowed','request_id'=>$requestId),$requestId); }

    $body=json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($idemKey!==''){ $it=devone_api_table('api_idempotency'); $st=db()->prepare("INSERT INTO `{$it}` (endpoint_id,api_key_id,idempotency_key,request_hash,status_code,response_body) VALUES (?,?,?,?,?,?)"); $st->execute(array((int)$endpoint['id'],$keyId,$idemKey,$requestHash,$status,$body)); }
    devone_api_log_request((int)$endpoint['id'],$keyId,$method,$path,$status,$started,$requestId,''); http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('X-Request-ID: '.$requestId); echo $body; exit;
} catch (Throwable $e) {
    devone_api_log_request((int)$endpoint['id'],$keyId,$method,$path,500,$started,$requestId,get_class($e).': '.$e->getMessage());
    devone_api_respond(500,array('ok'=>false,'error'=>'The API request could not be completed','request_id'=>$requestId),$requestId);
}
