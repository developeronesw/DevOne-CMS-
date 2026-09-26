<?php
require __DIR__ . '/includes/admin_common.php';
if (is_file(__DIR__ . '/../core/mailer.php')) { require_once __DIR__ . '/../core/mailer.php'; }
verify_csrf();

$msg = $_GET['msg'] ?? '';
$error = '';
$catalog = function_exists('devone_permissions_catalog') ? devone_permissions_catalog() : array();
$currentUserId = function_exists('devone_current_user_id') ? devone_current_user_id() : 0;
$canManageUsers = devone_has_permission('manage_users');
$profileMode = isset($_GET['profile']) && $_GET['profile'] === 'me';
if (!$canManageUsers) { $profileMode = true; }

if (function_exists('devone_ensure_user_profile_schema')) { devone_ensure_user_profile_schema(); }
$usersTable = devone_require_table('users', true);
$rolesTable = devone_require_table('roles', true);
$userCols = function_exists('devone_table_columns') ? devone_table_columns('users') : array();
if (function_exists('devone_ensure_user_profile_schema')) { devone_ensure_user_profile_schema(); $userCols = function_exists('devone_table_columns') ? devone_table_columns('users') : $userCols; }
$roleCols = function_exists('devone_table_columns') ? devone_table_columns('roles') : array();

function devone_post_permissions_array() {
    $raw = $_POST['permissions'] ?? array();
    if (!is_array($raw)) { $raw = array(); }
    return array_values(array_unique(array_filter(array_map(function($v){ return preg_replace('/[^a-z0-9_\*\-]/i', '', (string)$v); }, $raw))));
}

function devone_default_registered_role() {
    return devone_slugify(function_exists('get_setting') ? get_setting('default_user_role', 'subscriber') : 'subscriber', 'subscriber');
}

function devone_profile_user_id_from_post($canManageUsers, $currentUserId) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$canManageUsers || (isset($_POST['profile_mode']) && $_POST['profile_mode'] === '1')) {
        return (int)$currentUserId;
    }
    return $id;
}


function devone_users_role_permissions_map($roles) {
    $map = array();
    foreach ((array)$roles as $role) {
        $name = (string)($role['name'] ?? '');
        if ($name === '') { continue; }
        $map[$name] = function_exists('devone_normalize_permissions') ? devone_normalize_permissions($role['permissions'] ?? '') : array();
    }
    return $map;
}

function devone_users_is_admin_power_user($user, $roles) {
    $role = (string)($user['role'] ?? '');
    if (in_array($role, array('admin','developer'), true)) { return true; }
    $map = devone_users_role_permissions_map($roles);
    $perms = $map[$role] ?? array();
    return in_array('*', $perms, true) || in_array('admin_all', $perms, true);
}

function devone_users_count_admin_power_users($users, $roles) {
    $count = 0;
    foreach ((array)$users as $user) {
        if (strtolower((string)($user['status'] ?? 'active')) === 'disabled') { continue; }
        if (devone_users_is_admin_power_user($user, $roles)) { $count++; }
    }
    return $count;
}

function devone_users_delete_user($deleteId, $currentUserId, $usersTable, $roles) {
    $deleteId = (int)$deleteId;
    $currentUserId = (int)$currentUserId;
    if ($deleteId <= 0) { throw new Exception('No user was selected for deletion.'); }
    if ($deleteId === $currentUserId) { throw new Exception('You cannot delete the account you are currently logged in with.'); }
    $target = function_exists('devone_get_user_by_id') ? devone_get_user_by_id($deleteId) : null;
    if (!$target) { throw new Exception('That user could not be found.'); }
    $allUsers = function_exists('devone_list_users') ? devone_list_users() : array();
    if (devone_users_is_admin_power_user($target, $roles) && devone_users_count_admin_power_users($allUsers, $roles) <= 1) {
        throw new Exception('You cannot delete the last admin/developer account.');
    }
    $mediaTable = function_exists('devone_require_table') ? devone_require_table('media', false) : '';
    $mediaCols = ($mediaTable !== '' && function_exists('devone_table_columns')) ? devone_table_columns('media') : array();
    if ($mediaTable !== '' && in_array('user_id', $mediaCols, true)) {
        db()->prepare('UPDATE `' . $mediaTable . '` SET user_id=NULL WHERE user_id=?')->execute(array($deleteId));
    }
    db()->prepare('DELETE FROM `' . $usersTable . '` WHERE id=? LIMIT 1')->execute(array($deleteId));
    if (function_exists('devone_log')) { devone_log('user_deleted', 'Deleted user ID ' . $deleteId . ' (' . ($target['username'] ?? '') . ')'); }
    return true;
}

function devone_users_delete_role($roleName, $rolesTable, $usersTable) {
    $roleName = devone_slugify($roleName, '');
    if ($roleName === '') { throw new Exception('No role was selected for deletion.'); }
    $protected = array('admin','developer','subscriber');
    if (in_array($roleName, $protected, true)) { throw new Exception('The ' . $roleName . ' role is protected and cannot be deleted.'); }
    $defaultRole = devone_default_registered_role();
    if ($roleName === $defaultRole) { throw new Exception('This role is currently the default registration role. Change the default role in Settings before deleting it.'); }
    $stmt = db()->prepare('SELECT COUNT(*) FROM `' . $usersTable . '` WHERE role=?');
    $stmt->execute(array($roleName));
    $assigned = (int)$stmt->fetchColumn();
    if ($assigned > 0) { throw new Exception('This role is assigned to ' . $assigned . ' user' . ($assigned === 1 ? '' : 's') . '. Reassign those users before deleting the role.'); }
    $stmt = db()->prepare('DELETE FROM `' . $rolesTable . '` WHERE name=? LIMIT 1');
    $stmt->execute(array($roleName));
    if ($stmt->rowCount() < 1) { throw new Exception('That role could not be found.'); }
    if (function_exists('devone_log')) { devone_log('role_deleted', $roleName); }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_user') {
        devone_require_permission('manage_users');
        try {
            $rolesForDelete = function_exists('devone_list_roles') ? devone_list_roles() : array();
            devone_users_delete_user((int)($_POST['id'] ?? 0), $currentUserId, $usersTable, $rolesForDelete);
            header('Location: users.php?msg=' . rawurlencode('User deleted. Their media ownership was safely released.'));
            exit;
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    if ($action === 'delete_role') {
        devone_require_permission('manage_users');
        try {
            devone_users_delete_role((string)($_POST['role'] ?? ''), $rolesTable, $usersTable);
            header('Location: users.php?msg=' . rawurlencode('Role deleted.'));
            exit;
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    if ($action === 'save_user') {
        $id = devone_profile_user_id_from_post($canManageUsers, $currentUserId);
        if (!$canManageUsers && $id !== $currentUserId) { devone_require_permission('manage_users'); }
        if (($profileMode || !$canManageUsers) && $id <= 0) { $error = 'Your profile user ID could not be resolved. Log out, log back in, then try again.'; }

        $username = devone_slugify($_POST['username'] ?? '', 'user');
        $email = trim((string)($_POST['email'] ?? ''));
        $display = trim((string)($_POST['display_name'] ?? ''));
        $bio = trim((string)($_POST['bio'] ?? ''));
        $role = $canManageUsers ? devone_slugify($_POST['role'] ?? devone_default_registered_role(), devone_default_registered_role()) : (devone_current_user()['role'] ?? devone_default_registered_role());
        $status = $canManageUsers && in_array($_POST['status'] ?? 'active', array('active','disabled'), true) ? $_POST['status'] : 'active';
        $overrides = $canManageUsers ? json_encode(devone_post_permissions_array(), JSON_UNESCAPED_SLASHES) : null;
        $password = (string)($_POST['password'] ?? '');

        if ($error === '') {
            try {
                if (function_exists('devone_ensure_user_profile_schema')) {
                    $schema = devone_ensure_user_profile_schema();
                    if (empty($schema['ok'])) { throw new Exception('Profile schema check failed: ' . ($schema['message'] ?? 'Unknown schema error.')); }
                }
                if (function_exists('devone_ensure_media_schema')) { devone_ensure_media_schema(); }
                if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }

                $usersTable = devone_require_table('users', true);
                $rolesTable = devone_require_table('roles', true);
                $userCols = function_exists('devone_table_columns') ? devone_table_columns('users') : array();
                $roleCols = function_exists('devone_table_columns') ? devone_table_columns('roles') : array();

                if ($usersTable === '') { throw new Exception('Users table could not be resolved.'); }

                if ($id > 0) {
                    if (function_exists('devone_update_user_profile_fields')) {
                        $save = devone_update_user_profile_fields($id, array(
                            'username' => $username,
                            'email' => $email,
                            'display_name' => $display,
                            'bio' => $bio,
                            'role' => $role,
                            'status' => $status,
                            'permissions_override' => $overrides,
                            'password' => $password,
                        ), $canManageUsers);
                        if (empty($save['ok'])) { throw new Exception($save['message'] ?? 'Profile could not be saved.'); }
                        $saveId = $id;
                        $msg = ($profileMode || !$canManageUsers) ? 'Profile updated and verified.' : 'User updated and verified.';
                    } else {
                        throw new Exception('Profile save helper is missing. Make sure core/functions.php was uploaded.');
                    }
                } else {
                    if (!$canManageUsers) { devone_require_permission('manage_users'); }
                    if (strlen($password) < 8) { throw new Exception('A password of at least 8 characters is required when creating a user.'); }
                    $fields = array('username','password','email','role');
                    $vals = array($username,password_hash($password, PASSWORD_DEFAULT),$email,$role);
                    if (in_array('display_name', $userCols, true)) { $fields[]='display_name'; $vals[]=$display; }
                    if (in_array('bio', $userCols, true)) { $fields[]='bio'; $vals[]=$bio; }
                    if (in_array('status', $userCols, true)) { $fields[]='status'; $vals[]=$status; }
                    if (in_array('permissions_override', $userCols, true)) { $fields[]='permissions_override'; $vals[]=$overrides; }
                    $sql = 'INSERT INTO `' . $usersTable . '` (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
                    db()->prepare($sql)->execute($vals);
                    $saveId = (int)db()->lastInsertId();
                    $msg = 'User created.';
                    if ($email !== '' && function_exists('devone_send_email')) {
                        $siteName = function_exists('get_setting') ? get_setting('site_name', 'DevOneCMS') : 'DevOneCMS';
                        $loginUrl = function_exists('devone_site_url') ? devone_site_url('admin/index.php') : 'admin/index.php';
                        $displayName = $display !== '' ? $display : $username;
                        $mail = devone_send_email(
                            $email,
                            'Welcome to ' . $siteName,
                            '<p>Welcome <strong>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>' .
                            '<p>An account has been created for you on <strong>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>' .
                            '<p><strong>Login information</strong></p>' .
                            '<p>Username: <strong>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong></p>' .
                            '<p>Your administrator has created your account. Obtain the password through the secure method your administrator provides.</p>' .
                            '<p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#28b8ff;">Login to your account</a></p>',
                            "Welcome to " . $siteName . "\n\nUsername: " . $username . "\nYour administrator will provide your password securely.\nLogin: " . $loginUrl
                        );
                        $msg .= !empty($mail['ok']) ? ' Welcome email sent.' : ' Welcome email could not be sent yet.';
                    }
                }

                if (!empty($_FILES['avatar']['tmp_name'])) {
                    if (function_exists('devone_ensure_user_profile_schema')) { devone_ensure_user_profile_schema(); }
                    if (function_exists('devone_ensure_media_schema')) { devone_ensure_media_schema(); }
                    if (function_exists('devone_refresh_table_cache')) { devone_refresh_table_cache(); }
                    $avatar = devone_upload_user_avatar($_FILES['avatar'], $saveId);
                    if (empty($avatar['ok'])) { throw new Exception($avatar['message'] ?? 'Avatar upload failed.'); }
                    $freshCols = function_exists('devone_table_columns') ? devone_table_columns('users') : array();
                    if (!in_array('avatar', $freshCols, true)) { throw new Exception('Avatar column is missing from the users table after schema repair.'); }
                    db()->prepare('UPDATE `' . $usersTable . '` SET avatar=? WHERE id=? LIMIT 1')->execute(array($avatar['path'], $saveId));
                    $checkAvatarUser = function_exists('devone_get_user_by_id') ? devone_get_user_by_id($saveId) : null;
                    if (!$checkAvatarUser || (string)($checkAvatarUser['avatar'] ?? '') !== (string)$avatar['path']) {
                        throw new Exception('Avatar upload succeeded, but the avatar path did not save back to the users table.');
                    }
                    $msg .= ' Avatar saved to Media Library.';
                }

                if ((int)$saveId === (int)$currentUserId && function_exists('devone_start_session')) {
                    $freshUser = function_exists('devone_get_user_by_id') ? devone_get_user_by_id($saveId) : null;
                    devone_start_session();
                    if ($freshUser) {
                        $_SESSION['username'] = $freshUser['username'] ?? $username;
                        $_SESSION['email'] = $freshUser['email'] ?? $email;
                        $_SESSION['display_name'] = $freshUser['display_name'] ?? $display;
                        $_SESSION['avatar'] = $freshUser['avatar'] ?? '';
                        $_SESSION['role'] = $freshUser['role'] ?? $role;
                    }
                }
                if (function_exists('devone_log')) { devone_log('user_saved', 'User ID ' . $saveId); }
                $dest = ($profileMode || !$canManageUsers) ? 'users.php?profile=me&msg=' . rawurlencode($msg) : 'users.php?edit=' . $saveId . '&msg=' . rawurlencode($msg);
                header('Location: ' . $dest);
                exit;
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }

    if ($action === 'save_role') {
        devone_require_permission('manage_users');
        $name = devone_slugify($_POST['name'] ?? 'custom_role', 'custom_role');
        $description = trim((string)($_POST['description'] ?? ''));
        $perms = json_encode(devone_post_permissions_array(), JSON_UNESCAPED_SLASHES);
        try {
            if ($rolesTable === '') { throw new Exception('Roles table could not be resolved.'); }
            if (in_array('description', $roleCols, true)) {
                $stmt = db()->prepare('INSERT INTO `' . $rolesTable . '` (name,description,permissions) VALUES (?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description), permissions=VALUES(permissions)');
                $stmt->execute(array($name,$description,$perms));
            } else {
                $stmt = db()->prepare('INSERT INTO `' . $rolesTable . '` (name,permissions) VALUES (?,?) ON DUPLICATE KEY UPDATE permissions=VALUES(permissions)');
                $stmt->execute(array($name,$perms));
            }
            if (function_exists('devone_log')) { devone_log('role_saved', $name); }
            header('Location: users.php?msg=' . rawurlencode('Role saved: ' . $name));
            exit;
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}

$roles = function_exists('devone_list_roles') ? devone_list_roles() : array();
$users = $canManageUsers && !$profileMode ? (function_exists('devone_list_users') ? devone_list_users() : array()) : array();
$editingUser = null;
if ($profileMode) {
    // Always load the profile form from the database, not from stale session values.
    $editingUser = function_exists('devone_get_user_by_id') ? devone_get_user_by_id($currentUserId) : devone_current_user();
}
elseif (isset($_GET['edit'])) { $editingUser = devone_get_user_by_id((int)$_GET['edit']); }
$editingRole = null;
if ($canManageUsers && isset($_GET['role'])) {
    $roleName = devone_slugify($_GET['role'], '');
    foreach ($roles as $roleRow) { if (($roleRow['name'] ?? '') === $roleName) { $editingRole = $roleRow; break; } }
}
$rolePerms = $editingRole ? devone_normalize_permissions($editingRole['permissions'] ?? '') : array();
$userOverridePerms = $editingUser ? devone_normalize_permissions($editingUser['permissions_override'] ?? '') : array();
devone_admin_header('Users & Roles - DevOneCMS');
?>
<section class="users-hero">
  <div>
    <p class="admin-kicker"><span></span> People & Permissions</p>
    <h1><?= $profileMode ? 'My Profile' : 'Users & Roles' ?></h1>
    <p class="muted">DevOne-style users, roles, profile avatars, and permissions. Media ownership is respected so users without all-media permission only see their own uploads.</p>
  </div>
  <div class="admin-hero-actions">
    <?php if ($canManageUsers): ?><a class="btn" href="users.php?new=1">Add User</a><a class="btn secondary" href="users.php?role=new">Add Role</a><?php endif; ?>
    <a class="btn secondary" href="users.php?profile=me">My Profile</a>
  </div>
</section>
<?php devone_flash($msg); devone_flash($error, 'card error-card'); ?>
<?php if ($editingUser || isset($_GET['new']) || $profileMode):
    $isNew = !$editingUser && isset($_GET['new']);
    $u = $editingUser ?: array('id'=>0,'username'=>'','email'=>'','display_name'=>'','role'=>devone_default_registered_role(),'status'=>'active','bio'=>'','avatar'=>'');
    $avatarUrl = function_exists('devone_user_avatar_url') ? devone_user_avatar_url($u) : '';
?>
<div class="user-editor-layout">
  <form method="post" enctype="multipart/form-data" class="card user-editor-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="<?= e($u['id'] ?? 0) ?>"><input type="hidden" name="profile_mode" value="<?= $profileMode ? '1' : '0' ?>">
    <div class="section-head"><h2><?= $isNew ? 'Add New User' : 'Edit User' ?></h2><span><?= e($u['role'] ?? 'client') ?></span></div>
    <div class="user-profile-row">
      <div class="avatar-preview"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="Avatar"><?php else: ?><span><?= e(strtoupper(substr(($u['display_name'] ?? $u['username'] ?? 'U'), 0, 1))) ?></span><?php endif; ?></div>
      <label>Avatar<input type="file" name="avatar" accept="image/*"></label>
    </div>
    <div class="editor-row">
      <label>Username<input name="username" value="<?= e($u['username'] ?? '') ?>" required></label>
      <label>Email<input type="email" name="email" value="<?= e($u['email'] ?? '') ?>"></label>
    </div>
    <div class="editor-row">
      <label>Display Name<input name="display_name" value="<?= e($u['display_name'] ?? '') ?>"></label>
      <label>Password<input type="password" name="password" placeholder="Leave blank to keep current password"></label>
    </div>
    <label>Bio<textarea name="bio" rows="5" placeholder="Short profile bio..."><?= e($u['bio'] ?? '') ?></textarea></label>
    <?php if ($canManageUsers): ?>
    <div class="editor-row">
      <label>Role<select name="role"><?php foreach ($roles as $r): ?><option value="<?= e($r['name']) ?>" <?= (($u['role'] ?? '') === ($r['name'] ?? '')) ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
      <label>Status<select name="status"><option value="active" <?= (($u['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option><option value="disabled" <?= (($u['status'] ?? 'active') === 'disabled') ? 'selected' : '' ?>>Disabled</option></select></label>
    </div>
    <h3>Extra User Permissions</h3>
    <p class="muted">These permissions are added on top of the selected role.</p>
    <div class="permission-grid">
      <?php foreach ($catalog as $perm => $label): ?>
        <label><input type="checkbox" name="permissions[]" value="<?= e($perm) ?>" <?= in_array($perm, $userOverridePerms, true) ? 'checked' : '' ?>> <span><strong><?= e($perm) ?></strong><small><?= e($label) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="inline-actions"><button><?= $profileMode ? 'Save Profile' : 'Save User' ?></button><?php if ($canManageUsers && !$profileMode): ?><a class="btn secondary" href="users.php">Back to Users</a><?php else: ?><a class="btn secondary" href="dashboard.php">Back to Dashboard</a><?php endif; ?></div>
  </form>
</div>
<?php elseif ($canManageUsers && (isset($_GET['role']) || $editingRole)):
    $r = $editingRole ?: array('name'=>'','description'=>'','permissions'=>'[]');
?>
<form method="post" class="card role-editor-card">
  <?= csrf_field() ?><input type="hidden" name="action" value="save_role">
  <div class="section-head"><h2><?= $editingRole ? 'Edit Role' : 'Add Role' ?></h2><span>Permission Set</span></div>
  <label>Role Slug<input name="name" value="<?= e($r['name'] ?? '') ?>" placeholder="example: content_editor" required></label>
  <label>Description<textarea name="description" rows="3"><?= e($r['description'] ?? '') ?></textarea></label>
  <div class="permission-grid">
    <?php foreach ($catalog as $perm => $label): ?>
      <label><input type="checkbox" name="permissions[]" value="<?= e($perm) ?>" <?= in_array($perm, $rolePerms, true) ? 'checked' : '' ?>> <span><strong><?= e($perm) ?></strong><small><?= e($label) ?></small></span></label>
    <?php endforeach; ?>
  </div>
  <div class="inline-actions"><button>Save Role</button><a class="btn secondary" href="users.php">Back to Users</a></div>
</form>
<?php else: ?>
<section class="users-grid-layout">
  <div class="card">
    <div class="section-head"><h2>All Users</h2><span><?= number_format(count($users)) ?> users</span></div>
    <div class="table-scroll devone-desktop-table"><table class="table"><tr class="table-head-row"><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
      <?php foreach ($users as $u): $avatarUrl = devone_user_avatar_url($u); ?>
      <tr>
        <td data-label="User"><div class="user-cell"><span class="tiny-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><?= e(strtoupper(substr(($u['display_name'] ?: $u['username']),0,1))) ?><?php endif; ?></span><strong><?= e(($u['display_name'] ?? '') ?: ($u['username'] ?? '')) ?></strong><small>@<?= e($u['username'] ?? '') ?></small></div></td>
        <td data-label="Email"><?= e($u['email'] ?? '') ?></td><td data-label="Role"><code><?= e($u['role'] ?? '') ?></code></td><td data-label="Status"><?= e($u['status'] ?? 'active') ?></td><td data-label="Created"><?= e($u['created_at'] ?? '') ?></td>
        <td data-label="Actions">
          <div class="user-action-row">
            <a class="btn" href="users.php?edit=<?= e($u['id']) ?>">Edit</a>
            <?php if ((int)($u['id'] ?? 0) !== (int)$currentUserId): ?>
            <form method="post" class="inline-delete-form" onsubmit="return confirm('Delete this user? This cannot be undone. Their media files will remain, but ownership will be released.');">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= e($u['id']) ?>">
              <button type="submit" class="btn danger-btn">Delete</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

    <div class="devone-mobile-cards devone-user-mobile-cards" aria-label="All users">
      <?php foreach ($users as $u): $avatarUrl = devone_user_avatar_url($u); ?>
      <details class="devone-mobile-card">
        <summary>
          <span class="tiny-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><?= e(strtoupper(substr((($u['display_name'] ?? '') ?: ($u['username'] ?? '')),0,1))) ?><?php endif; ?></span>
          <span class="devone-mobile-card-title-wrap">
            <strong><?= e(($u['display_name'] ?? '') ?: ($u['username'] ?? '')) ?></strong>
            <small>@<?= e($u['username'] ?? '') ?> · <?= e($u['role'] ?? '') ?></small>
          </span>
          <span class="devone-mobile-card-arrow">⌄</span>
        </summary>
        <div class="devone-mobile-card-body">
          <div class="devone-mobile-meta-grid">
            <span>Email</span><strong><?= e($u['email'] ?? '') ?></strong>
            <span>Role</span><code><?= e($u['role'] ?? '') ?></code>
            <span>Status</span><strong><?= e($u['status'] ?? 'active') ?></strong>
            <span>Created</span><strong><?= e($u['created_at'] ?? '') ?></strong>
          </div>
          <div class="devone-mobile-card-actions user-action-row">
            <a class="btn" href="users.php?edit=<?= e($u['id']) ?>">Edit</a>
            <?php if ((int)($u['id'] ?? 0) !== (int)$currentUserId): ?>
            <form method="post" class="inline-delete-form" onsubmit="return confirm('Delete this user? This cannot be undone. Their media files will remain, but ownership will be released.');">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= e($u['id']) ?>">
              <button type="submit" class="btn danger-btn">Delete</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="section-head"><h2>Roles</h2><span><?= number_format(count($roles)) ?></span></div>
    <div class="role-list">
      <?php foreach ($roles as $r): $perms = devone_normalize_permissions($r['permissions'] ?? ''); ?>
        <?php
          $roleName = (string)($r['name'] ?? '');
          $isProtectedRole = in_array($roleName, array('admin','developer','subscriber'), true) || $roleName === devone_default_registered_role();
        ?>
        <article>
          <strong><?= e($roleName) ?></strong>
          <p><?= e($r['description'] ?? '') ?></p>
          <small><?= count($perms) ?> permission<?= count($perms) === 1 ? '' : 's' ?></small>
          <div class="role-action-row">
            <a class="btn secondary" href="users.php?role=<?= e($roleName) ?>">Edit Role</a>
            <?php if (!$isProtectedRole): ?>
            <form method="post" class="inline-delete-form" onsubmit="return confirm('Delete this role? It can only be deleted if no users are assigned to it.');">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_role"><input type="hidden" name="role" value="<?= e($roleName) ?>">
              <button type="submit" class="btn danger-btn">Delete</button>
            </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php devone_admin_footer();
