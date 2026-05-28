<?php $ACTIVE='account'; $PAGE='Account'; require __DIR__.'/includes/header.php';
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $cur=$_POST['current']??''; $new=$_POST['new']??''; $cf=$_POST['confirm']??'';
    $st=db()->prepare("SELECT password_hash FROM users WHERE id=?"); $st->execute([$u['id']]); $row=$st->fetch();
    if (!$row || !password_verify($cur,$row['password_hash'])) { $err='Current password is incorrect.'; }
    elseif (strlen($new) < 5) { $err='New password must be at least 5 characters.'; }
    elseif ($new !== $cf) { $err='New passwords do not match.'; }
    else {
        $up=db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $up->execute([password_hash($new,PASSWORD_DEFAULT), $u['id']]);
        $msg='Password updated.';
    }
}
?>
<div class="phead"><h1>⚙️ Account</h1><p>Signed in as <b><?php echo e($u['username']); ?></b> (<?php echo $u['role']==='superadmin'?'Super Admin':'Student'; ?>).</p></div>
<div style="max-width:420px">
  <form method="post" autocomplete="off">
    <?php if($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>
    <?php if($msg): ?><div class="err" style="background:rgba(47,158,110,.12);border-color:#2f9e6e;color:#2f9e6e"><?php echo e($msg); ?></div><?php endif; ?>
    <label>Current password</label><input type="password" name="current" required>
    <label>New password</label><input type="password" name="new" required>
    <label>Confirm new password</label><input type="password" name="confirm" required>
    <button class="btn full" type="submit" style="margin-top:18px">Update password</button>
  </form>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
