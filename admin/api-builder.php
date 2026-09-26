<?php
require __DIR__ . '/includes/admin_common.php';
require_once __DIR__ . '/../core/api_runtime.php';
devone_require_permission('manage_api');
devone_api_schema_install();
verify_csrf();
$msg = trim((string)($_GET['msg'] ?? ''));
$error = '';
$endpointTable = table_name('api_endpoints');
$keyTable = table_name('api_keys');
$logTable = table_name('api_request_logs');

function d1api_redirect($message) { header('Location: api-builder.php?msg='.rawurlencode($message)); exit; }
function d1api_bool($name) { return !empty($_POST[$name]) ? 1 : 0; }
function d1api_json_list($name) { return json_encode(devone_api_clean_list($_POST[$name] ?? ''), JSON_UNESCAPED_SLASHES); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save_endpoint');
    try {
        if ($action === 'save_endpoint') {
            $id = (int)($_POST['endpoint_id'] ?? 0);
            $path = devone_api_clean_path($_POST['path'] ?? '');
            $method = strtoupper(trim((string)($_POST['method'] ?? 'GET')));
            $allowedMethods = array('GET','POST','PUT','PATCH','DELETE');
            if (!in_array($method,$allowedMethods,true)) { throw new RuntimeException('Choose a supported HTTP method.'); }
            if ($path === '') { throw new RuntimeException('Endpoint path is required.'); }
            $table = devone_base_table_name(preg_replace('/[^a-zA-Z0-9_]/','',(string)($_POST['source_table'] ?? '')));
            if (!$table || !table_exists($table)) { throw new RuntimeException('The selected source table does not exist.'); }
            $columns = devone_api_table_columns(table_name($table));
            $allowed = devone_api_clean_list($_POST['allowed_fields'] ?? '');
            $writable = devone_api_clean_list($_POST['writable_fields'] ?? '');
            $required = devone_api_clean_list($_POST['required_fields'] ?? '');
            foreach (array_merge($allowed,$writable,$required) as $field) { if (!isset($columns[$field])) { throw new RuntimeException('Unknown table field: '.$field); } }
            $name = trim((string)($_POST['name'] ?? '')) ?: ucwords(str_replace(array('-','/'),' ',$path)).' API';
            $values = array(
                $name, trim((string)($_POST['description'] ?? '')), $path, $method, $table,
                d1api_bool('auth_required'), d1api_bool('allow_public_write'), trim((string)($_POST['required_permission'] ?? '')),
                json_encode($allowed), json_encode($writable), json_encode($required), max(1,min(500,(int)($_POST['max_page_size'] ?? 100))),
                max(1,min(5000,(int)($_POST['rate_limit_per_minute'] ?? 60))), d1api_json_list('cors_origins'),
                d1api_bool('site_scoped'), d1api_bool('idempotency_required'), d1api_bool('active')
            );
            if ($id > 0) {
                $sql="UPDATE `{$endpointTable}` SET name=?,description=?,path=?,method=?,source_table=?,auth_required=?,allow_public_write=?,required_permission=?,allowed_fields=?,writable_fields=?,required_fields=?,max_page_size=?,rate_limit_per_minute=?,cors_origins=?,site_scoped=?,idempotency_required=?,active=? WHERE id=?";
                $values[]=$id; db()->prepare($sql)->execute($values); devone_log('api_endpoint_updated',$method.' /api/'.$path); d1api_redirect('API endpoint updated.');
            } else {
                $sql="INSERT INTO `{$endpointTable}` (name,description,path,method,source_table,auth_required,allow_public_write,required_permission,allowed_fields,writable_fields,required_fields,max_page_size,rate_limit_per_minute,cors_origins,site_scoped,idempotency_required,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                db()->prepare($sql)->execute($values); devone_log('api_endpoint_created',$method.' /api/'.$path); d1api_redirect('API endpoint created.');
            }
        } elseif ($action === 'toggle_endpoint') {
            $id=(int)($_POST['endpoint_id']??0); db()->prepare("UPDATE `{$endpointTable}` SET active=IF(active=1,0,1) WHERE id=?")->execute(array($id)); d1api_redirect('Endpoint status updated.');
        } elseif ($action === 'delete_endpoint') {
            $id=(int)($_POST['endpoint_id']??0); db()->prepare("DELETE FROM `{$endpointTable}` WHERE id=? LIMIT 1")->execute(array($id)); devone_log('api_endpoint_deleted','Endpoint ID '.$id); d1api_redirect('Endpoint deleted.');
        } elseif ($action === 'create_key') {
            $name=trim((string)($_POST['key_name']??'')); if($name===''){throw new RuntimeException('API key name is required.');}
            $token='d1_live_'.bin2hex(random_bytes(32)); $prefix=substr($token,0,16); $hash=password_hash($token,PASSWORD_DEFAULT);
            $expires=trim((string)($_POST['expires_at']??'')); if($expires!==''){$expires=date('Y-m-d H:i:s',strtotime($expires));}else{$expires=null;}
            $stmt=db()->prepare("INSERT INTO `{$keyTable}` (name,key_prefix,key_hash,permissions,allowed_endpoints,active,expires_at,created_by) VALUES (?,?,?,?,?,1,?,?)");
            $stmt->execute(array($name,$prefix,$hash,d1api_json_list('key_permissions'),d1api_json_list('allowed_endpoints'),$expires,devone_current_user_id()));
            devone_start_session(); $_SESSION['devone_new_api_key']=$token; $_SESSION['devone_new_api_key_name']=$name; d1api_redirect('API key created. Copy it now; it will not be shown again.');
        } elseif ($action === 'revoke_key') {
            $id=(int)($_POST['key_id']??0); db()->prepare("UPDATE `{$keyTable}` SET active=0 WHERE id=?")->execute(array($id)); devone_log('api_key_revoked','API key ID '.$id); d1api_redirect('API key revoked.');
        }
    } catch (Throwable $e) { $error=$e->getMessage(); }
}

$editId=(int)($_GET['edit']??0); $edit=null;
if($editId){$st=db()->prepare("SELECT * FROM `{$endpointTable}` WHERE id=?");$st->execute(array($editId));$edit=$st->fetch();}
$eps=db()->query("SELECT * FROM `{$endpointTable}` ORDER BY path,method")->fetchAll();
$keys=db()->query("SELECT id,name,key_prefix,permissions,allowed_endpoints,active,expires_at,last_used_at,created_at FROM `{$keyTable}` ORDER BY id DESC")->fetchAll();
$logs=db()->query("SELECT * FROM `{$logTable}` ORDER BY id DESC LIMIT 50")->fetchAll();
$rawKey='';$rawKeyName='';devone_start_session();if(!empty($_SESSION['devone_new_api_key'])){$rawKey=$_SESSION['devone_new_api_key'];$rawKeyName=$_SESSION['devone_new_api_key_name']??'';unset($_SESSION['devone_new_api_key'],$_SESSION['devone_new_api_key_name']);}
$availableTables=array();foreach(array('pages','media','menus','themes','plugins','libraries','modules') as $suffix){if(table_exists($suffix)){$availableTables[]=$suffix;}}
function d1api_value($row,$key,$default=''){return e($row[$key]??$default);} function d1api_checked($row,$key,$default=false){$v=$row? !empty($row[$key]):$default;return $v?' checked':'';}
devone_admin_header('API Builder - DevOneCMS');
?>
<style>
.d1api-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.d1api-grid .full{grid-column:1/-1}.d1api-actions{display:flex;gap:8px;flex-wrap:wrap}.d1api-key{word-break:break-all;font-family:ui-monospace,monospace;padding:14px;border:1px solid #c89b2c;border-radius:12px;background:#fff8df;color:#111}.d1api-note{padding:12px;border-left:4px solid #c89b2c;background:rgba(200,155,44,.1);border-radius:8px}.d1api-table-wrap{overflow:auto}.d1api-table-wrap table{min-width:900px}@media(max-width:760px){.d1api-grid{grid-template-columns:1fr}.d1api-grid .full{grid-column:auto}}
</style>
<h1>API Builder</h1>
<p>Create secure REST endpoints backed by selected DevOne tables. Write endpoints require Bearer authentication unless explicitly marked public.</p>
<?php devone_flash($msg); if($error)devone_flash($error,'card error'); ?>
<?php if($rawKey):?><div class="card"><h3>New API Key: <?=e($rawKeyName)?></h3><p><strong>Copy this key now.</strong> DevOne stores only its password hash.</p><div class="d1api-key"><?=e($rawKey)?></div></div><?php endif;?>

<form method="post" class="card">
<?=csrf_field()?><input type="hidden" name="action" value="save_endpoint"><input type="hidden" name="endpoint_id" value="<?=d1api_value($edit,'id','0')?>">
<h2><?=$edit?'Edit':'Create'?> REST Endpoint</h2>
<div class="d1api-grid">
<label>Name<input name="name" required value="<?=d1api_value($edit,'name')?>" placeholder="Published Pages"></label>
<label>Path<input name="path" required value="<?=d1api_value($edit,'path')?>" placeholder="pages"></label>
<label>Method<select name="method"><?php foreach(array('GET','POST','PUT','PATCH','DELETE') as $m):?><option<?=$edit&&$edit['method']===$m?' selected':''?>><?=$m?></option><?php endforeach;?></select></label>
<label>Source Table<select name="source_table"><?php foreach($availableTables as $t):?><option value="<?=e($t)?>"<?=$edit&&devone_base_table_name($edit['source_table'])===$t?' selected':''?>><?=e($t)?></option><?php endforeach;?></select></label>
<label class="full">Description<textarea name="description" rows="2"><?=d1api_value($edit,'description')?></textarea></label>
<label>Readable fields <small>Comma or line separated. Blank safely exposes non-secret fields.</small><textarea name="allowed_fields" rows="4"><?=e(implode("\n",devone_api_json_decode($edit['allowed_fields']??'',array())))?></textarea></label>
<label>Writable fields <small>Required for POST, PUT, PATCH.</small><textarea name="writable_fields" rows="4"><?=e(implode("\n",devone_api_json_decode($edit['writable_fields']??'',array())))?></textarea></label>
<label>Required input fields<textarea name="required_fields" rows="3"><?=e(implode("\n",devone_api_json_decode($edit['required_fields']??'',array())))?></textarea></label>
<label>Required API permission<input name="required_permission" value="<?=d1api_value($edit,'required_permission')?>" placeholder="content.write"></label>
<label>Maximum page size<input type="number" min="1" max="500" name="max_page_size" value="<?=d1api_value($edit,'max_page_size','100')?>"></label>
<label>Requests per minute<input type="number" min="1" max="5000" name="rate_limit_per_minute" value="<?=d1api_value($edit,'rate_limit_per_minute','60')?>"></label>
<label class="full">Allowed CORS origins <small>One per line. Leave blank for same-origin only. Use * only for intentionally public APIs.</small><textarea name="cors_origins" rows="3"><?=e(implode("\n",devone_api_json_decode($edit['cors_origins']??'',array())))?></textarea></label>
<label><input type="checkbox" name="auth_required"<?=d1api_checked($edit,'auth_required',true)?>> Require Bearer authentication</label>
<label><input type="checkbox" name="allow_public_write"<?=d1api_checked($edit,'allow_public_write',false)?>> Allow unauthenticated writes <strong>(dangerous)</strong></label>
<label><input type="checkbox" name="site_scoped"<?=d1api_checked($edit,'site_scoped',true)?>> Restrict records to current site when table supports site_id</label>
<label><input type="checkbox" name="idempotency_required"<?=d1api_checked($edit,'idempotency_required',true)?>> Require Idempotency-Key for writes</label>
<label><input type="checkbox" name="active"<?=d1api_checked($edit,'active',true)?>> Endpoint active</label>
</div>
<div class="d1api-actions" style="margin-top:18px"><button><?=$edit?'Update':'Create'?> Endpoint</button><?php if($edit):?><a class="button" href="api-builder.php">Cancel</a><?php endif;?></div>
</form>

<div class="card"><h2>Endpoints</h2><div class="d1api-table-wrap"><table class="table"><tr><th>Name</th><th>Method</th><th>URL</th><th>Table</th><th>Auth</th><th>Status</th><th>Actions</th></tr><?php foreach($eps as $ep):?><tr><td><?=e($ep['name'])?></td><td><code><?=e($ep['method'])?></code></td><td><code><?=e(rtrim(SITE_URL,'/'))?>/api.php?route=<?=e($ep['path'])?></code></td><td><?=e($ep['source_table'])?></td><td><?=$ep['auth_required']?'Required':'Optional'?></td><td><?=$ep['active']?'Active':'Disabled'?></td><td><div class="d1api-actions"><a class="button" href="?edit=<?=(int)$ep['id']?>">Edit</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle_endpoint"><input type="hidden" name="endpoint_id" value="<?=(int)$ep['id']?>"><button type="submit"><?=$ep['active']?'Disable':'Enable'?></button></form><form method="post" onsubmit="return confirm('Delete this endpoint?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_endpoint"><input type="hidden" name="endpoint_id" value="<?=(int)$ep['id']?>"><button type="submit">Delete</button></form></div></td></tr><?php endforeach;?></table></div></div>

<form method="post" class="card"><?=csrf_field()?><input type="hidden" name="action" value="create_key"><h2>Create API Key</h2><div class="d1api-grid"><label>Name<input name="key_name" required placeholder="Production integration"></label><label>Expiration <input type="datetime-local" name="expires_at"></label><label>Permissions <small>Use * for all endpoint permissions.</small><textarea name="key_permissions" rows="3">*</textarea></label><label>Allowed endpoints <small>Paths, endpoint IDs, or *.</small><textarea name="allowed_endpoints" rows="3">*</textarea></label></div><button>Create API Key</button></form>

<div class="card"><h2>API Keys</h2><div class="d1api-table-wrap"><table class="table"><tr><th>Name</th><th>Prefix</th><th>Status</th><th>Expires</th><th>Last Used</th><th></th></tr><?php foreach($keys as $key):?><tr><td><?=e($key['name'])?></td><td><code><?=e($key['key_prefix'])?>…</code></td><td><?=$key['active']?'Active':'Revoked'?></td><td><?=e($key['expires_at']?:'Never')?></td><td><?=e($key['last_used_at']?:'Never')?></td><td><?php if($key['active']):?><form method="post" onsubmit="return confirm('Revoke this API key?')"><?=csrf_field()?><input type="hidden" name="action" value="revoke_key"><input type="hidden" name="key_id" value="<?=(int)$key['id']?>"><button>Revoke</button></form><?php endif;?></td></tr><?php endforeach;?></table></div></div>

<div class="card"><h2>Recent API Requests</h2><div class="d1api-table-wrap"><table class="table"><tr><th>Time</th><th>Method</th><th>Path</th><th>Status</th><th>Duration</th><th>Request ID</th></tr><?php foreach($logs as $log):?><tr><td><?=e($log['created_at'])?></td><td><?=e($log['method'])?></td><td><?=e($log['path'])?></td><td><?=e($log['status_code'])?></td><td><?=e($log['duration_ms'])?> ms</td><td><code><?=e($log['request_id'])?></code></td></tr><?php endforeach;?></table></div></div>

<div class="card d1api-note"><strong>Write request example</strong><pre>curl -X POST "<?=e(rtrim(SITE_URL,'/'))?>/api.php?route=pages" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: unique-operation-id" \
  -d '{"title":"API Page","slug":"api-page","content":"Created through DevOne"}'</pre></div>
<?php devone_admin_footer();
