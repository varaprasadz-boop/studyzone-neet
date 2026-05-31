<?php
/* ============================================================
   Admin — User Management.
   Super Admin can create users, assign role(s), restrict each
   user's access to specific classes / subjects, reset passwords
   and disable/enable accounts.

   Page lifecycle: all POST handlers and redirect()s run BEFORE
   the header include.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = ''; $PAGE = 'Users';
require_permission('users.manage');

$me = current_user();
$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$tempPasswordToShow = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $a = $_POST['action'] ?? '';

    if ($a === 'create' || $a === 'update') {
        $isCreate = ($a === 'create');
        $uid      = $isCreate ? 0 : (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $status   = in_array(($_POST['status'] ?? 'active'), ['active','disabled','pending'], true) ? $_POST['status'] : 'active';
        $roleIds  = array_map('intval', (array)($_POST['roles'] ?? []));
        $classIds = array_map('intval', (array)($_POST['scope_class'] ?? []));
        $subjIds  = array_map('intval', (array)($_POST['scope_subject'] ?? []));

        $err = '';
        if ($username === '' || $name === '') $err = 'Username and name are required.';
        if (!$err && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Email is not valid.';
        if (!$err && !$roleIds) $err = 'Pick at least one role.';

        // username / email uniqueness
        if (!$err) {
            $clash = $isCreate
                ? q1("SELECT id FROM users WHERE username=?", [$username])
                : q1("SELECT id FROM users WHERE username=? AND id<>?", [$username, $uid]);
            if ($clash) $err = 'That username is taken.';
        }
        if (!$err && $email !== '') {
            $clash = $isCreate
                ? q1("SELECT id FROM users WHERE email=?", [$email])
                : q1("SELECT id FROM users WHERE email=? AND id<>?", [$email, $uid]);
            if ($clash) $err = 'That email is already in use.';
        }
        if ($err) { flash($err, 'err'); redirect('users.php?action=' . ($isCreate ? 'new' : 'edit') . ($isCreate ? '' : '&id=' . $uid)); }

        // pick a primary legacy role code for users.role (kept for backward compat)
        $primaryRow = q1("SELECT code FROM roles WHERE id=? AND code IN ('superadmin','student','tutor','org_admin','parent')", [$roleIds[0]]);
        $primaryCode = $primaryRow ? $primaryRow['code'] : 'student';
        // legacy users.role ENUM only allows 'superadmin' / 'student' today — map unknown roles to 'student'
        if (!in_array($primaryCode, ['superadmin','student'], true)) $primaryCode = 'student';

        db()->beginTransaction();
        try {
            if ($isCreate) {
                $tmp  = gen_temp_password();
                $hash = password_hash($tmp, PASSWORD_DEFAULT);
                db()->prepare("INSERT INTO users (role, username, password_hash, name, email, status, must_change_password, created_by)
                               VALUES (?,?,?,?,?,?,1,?)")
                    ->execute([$primaryCode, $username, $hash, $name, ($email ?: null), $status, $me['id']]);
                $uid = (int)db()->lastInsertId();
                $tempPasswordToShow = ['username' => $username, 'password' => $tmp];
            } else {
                db()->prepare("UPDATE users SET role=?, username=?, name=?, email=?, status=? WHERE id=?")
                    ->execute([$primaryCode, $username, $name, ($email ?: null), $status, $uid]);
            }
            // roles: replace
            db()->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$uid]);
            $ins = db()->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)");
            foreach ($roleIds as $rid) $ins->execute([$uid, $rid]);
            // scopes: replace class + subject scopes (chapter scopes untouched in Phase 1 UI)
            db()->prepare("DELETE FROM user_scopes WHERE user_id=? AND scope_type IN ('class','subject')")->execute([$uid]);
            $insS = db()->prepare("INSERT INTO user_scopes (user_id, scope_type, scope_id) VALUES (?,?,?)");
            foreach ($classIds as $cid) $insS->execute([$uid, 'class', $cid]);
            foreach ($subjIds  as $sid) $insS->execute([$uid, 'subject', $sid]);
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('Save failed: ' . $ex->getMessage(), 'err');
            redirect('users.php');
        }

        audit($isCreate ? 'user.create' : 'user.update', 'user', $uid,
              ['roles' => $roleIds, 'scopes' => ['class' => $classIds, 'subject' => $subjIds]]);
        if ($uid === (int)$me['id']) clear_perm_cache();   // editing self → refresh own session
        flash($isCreate ? 'User created.' : 'User updated.');
        // Show temp password on the list page once via session.
        if ($tempPasswordToShow) $_SESSION['_temp_pw'] = $tempPasswordToShow;
        redirect('users.php');
    }

    if ($a === 'reset_password') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid) {
            $tmp = gen_temp_password();
            db()->prepare("UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?")
                ->execute([password_hash($tmp, PASSWORD_DEFAULT), $uid]);
            audit('user.password_reset', 'user', $uid);
            $row = q1("SELECT username FROM users WHERE id=?", [$uid]);
            $_SESSION['_temp_pw'] = ['username' => $row['username'] ?? '?', 'password' => $tmp];
            flash('Password reset. Share the new temporary password.');
        }
        redirect('users.php');
    }

    if ($a === 'toggle_status') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid && $uid !== (int)$me['id']) {            // can't disable yourself
            $row = q1("SELECT status FROM users WHERE id=?", [$uid]);
            if ($row) {
                $next = $row['status'] === 'active' ? 'disabled' : 'active';
                db()->prepare("UPDATE users SET status=? WHERE id=?")->execute([$next, $uid]);
                audit('user.' . ($next === 'active' ? 'enable' : 'disable'), 'user', $uid);
                flash('User ' . $next . '.');
            }
        }
        redirect('users.php');
    }

    redirect('users.php');
}

/* one-shot temp password from session */
if (!empty($_SESSION['_temp_pw'])) { $tempPasswordToShow = $_SESSION['_temp_pw']; unset($_SESSION['_temp_pw']); }

/* load data needed by both list and form */
$allRoles = qa("SELECT * FROM roles ORDER BY id");
$allClasses = qa("SELECT * FROM classes ORDER BY sort");
// canonical subjects only: one row per (class, subject name), preferring NCERT
$rows = qa("SELECT s.*, c.name AS class_name, y.sort AS ysort
            FROM subjects s JOIN classes c ON c.id=s.class_id JOIN syllabi y ON y.id=s.syllabus_id
            ORDER BY c.sort, y.sort, s.sort, s.id");
$seen = []; $allSubjects = [];
foreach ($rows as $r) {
    $k = $r['class_id'] . '|' . $r['name'];
    if (!isset($seen[$k])) { $seen[$k] = true; $allSubjects[] = $r; }
}

require __DIR__ . '/includes/header.php';
?>
<div class="phead"><h1><?php echo icon('users','lg'); ?> Users</h1>
  <p>Create accounts and restrict each user's access to specific classes &amp; subjects.</p></div>
<?php echo flash_render(); ?>
<?php if ($tempPasswordToShow): ?>
  <div class="ok-msg">
    Temporary password for <b><?php echo e($tempPasswordToShow['username']); ?></b>:
    <code style="background:var(--panel2);padding:3px 8px;border-radius:6px;font-family:var(--mono);font-size:1rem"><?php echo e($tempPasswordToShow['password']); ?></code>
    — share it manually. The user will be required to change it on first login.
  </div>
<?php endif; ?>

<?php
/* ===== CREATE / EDIT FORM ===== */
if ($action === 'new' || $action === 'edit'):
    $editing = ($action === 'edit');
    $u = ['id'=>0,'username'=>'','name'=>'','email'=>'','status'=>'active'];
    $userRoleIds = []; $userClassIds = []; $userSubjIds = [];
    if ($editing && $editId) {
        $u = q1("SELECT * FROM users WHERE id=?", [$editId]) ?: $u;
        $userRoleIds = array_map('intval', array_column(qa("SELECT role_id FROM user_roles WHERE user_id=?", [$editId]), 'role_id'));
        $userClassIds = array_map('intval', array_column(qa("SELECT scope_id FROM user_scopes WHERE user_id=? AND scope_type='class' AND scope_id IS NOT NULL", [$editId]), 'scope_id'));
        $userSubjIds  = array_map('intval', array_column(qa("SELECT scope_id FROM user_scopes WHERE user_id=? AND scope_type='subject' AND scope_id IS NOT NULL", [$editId]), 'scope_id'));
    }
?>
<div class="crumbs"><a href="users.php">Users</a> › <span><?php echo $editing ? 'Edit' : 'New'; ?></span></div>
<form method="post" class="qcard">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'create'; ?>">
  <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><?php endif; ?>

  <h3 style="font-family:var(--disp);margin-bottom:10px"><?php echo $editing ? 'Edit user' : 'New user'; ?></h3>
  <div class="row2">
    <div class="field"><label>Username</label><input type="text" name="username" value="<?php echo e($u['username']); ?>" required></div>
    <div class="field"><label>Full name</label><input type="text" name="name" value="<?php echo e($u['name']); ?>" required></div>
  </div>
  <div class="row2">
    <div class="field"><label>Email <span class="hint">(optional, used for Phase 2 password reset)</span></label>
      <input type="text" name="email" value="<?php echo e($u['email'] ?? ''); ?>"></div>
    <div class="field"><label>Status</label>
      <select name="status">
        <?php foreach (['active','disabled','pending'] as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $u['status']===$s?'selected':''; ?>><?php echo $s; ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>

  <div class="field">
    <label>Roles</label>
    <div style="display:flex;flex-wrap:wrap;gap:14px">
      <?php foreach ($allRoles as $r): ?>
        <label style="display:flex;align-items:center;gap:6px">
          <input type="checkbox" name="roles[]" value="<?php echo (int)$r['id']; ?>" <?php echo in_array((int)$r['id'], $userRoleIds, true)?'checked':''; ?>>
          <span><?php echo e($r['name']); ?> <span class="hint">(<?php echo e($r['code']); ?>)</span></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="field">
    <label>Restrict access <span class="hint">(leave everything unchecked = full access; check boxes to narrow)</span></label>
    <div class="note" style="padding:12px">
      <b>Classes</b>
      <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:6px">
        <?php foreach ($allClasses as $c): ?>
          <label style="display:flex;align-items:center;gap:6px">
            <input type="checkbox" name="scope_class[]" value="<?php echo (int)$c['id']; ?>" <?php echo in_array((int)$c['id'], $userClassIds, true)?'checked':''; ?>>
            <span><?php echo e($c['name']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:14px"><b>Subjects</b>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-top:6px">
          <?php foreach ($allSubjects as $s): ?>
            <label style="display:flex;align-items:center;gap:6px">
              <input type="checkbox" name="scope_subject[]" value="<?php echo (int)$s['id']; ?>" <?php echo in_array((int)$s['id'], $userSubjIds, true)?'checked':''; ?>>
              <span><?php echo $s['icon'].' '.e($s['name']); ?> <span class="hint">(<?php echo e($s['class_name']); ?>)</span></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="hint" style="margin-top:8px">Class + Subject scopes intersect — e.g. "Class 12" + "Physics, Chemistry" means just those two subjects of Class 12.</div>
    </div>
  </div>

  <div class="btnrow">
    <button class="btn" type="submit"><?php echo $editing ? 'Save changes' : 'Create user'; ?></button>
    <a class="btn ghost" href="users.php">Cancel</a>
  </div>
</form>

<?php
/* ===== LIST ===== */
else:
$users = qa(
   "SELECT u.*,
           GROUP_CONCAT(DISTINCT r.code ORDER BY r.id SEPARATOR ', ') AS role_codes,
           (SELECT COUNT(*) FROM user_scopes us WHERE us.user_id=u.id) AS scope_count
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id=u.id
    LEFT JOIN roles r ON r.id=ur.role_id
    GROUP BY u.id
    ORDER BY u.id");
?>
<div class="toolbar">
  <a class="btn" href="users.php?action=new">+ New user</a>
  <span class="spacer"></span>
  <span class="hint"><?php echo count($users); ?> user<?php echo count($users)==1?'':'s'; ?></span>
</div>
<div class="tbl-wrap"><table class="tbl">
  <tr><th>User</th><th>Email</th><th>Roles</th><th>Scope</th><th>Status</th><th></th></tr>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><b><?php echo e($u['username']); ?></b><br><span class="hint"><?php echo e($u['name']); ?></span></td>
      <td><?php echo e($u['email'] ?? ''); ?></td>
      <td><?php echo e($u['role_codes'] ?? $u['role']); ?></td>
      <td><?php echo (int)$u['scope_count'] === 0 ? '<span class="hint">unrestricted</span>' : ((int)$u['scope_count'] . ' rule' . ($u['scope_count']==1?'':'s')); ?></td>
      <?php $st = $u['status'] ?? 'active'; ?>
      <td><span class="pill <?php echo $st==='active'?'pub':'draft'; ?>"><?php echo e($st); ?></span></td>
      <td style="white-space:nowrap">
        <a class="btn sm ghost" href="users.php?action=edit&id=<?php echo (int)$u['id']; ?>">Edit</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Reset this user’s password to a new temp value?')"><?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
          <button class="btn sm ghost" type="submit">Reset PW</button></form>
        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('<?php echo $u['status']==='active'?'Disable':'Enable'; ?> this user?')"><?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
          <button class="btn sm <?php echo $u['status']==='active'?'danger':'green'; ?>" type="submit"><?php echo $u['status']==='active'?'Disable':'Enable'; ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
