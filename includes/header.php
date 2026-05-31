<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';
require_login();
$u = current_user();
$ACTIVE = $ACTIVE ?? '';
$PAGE   = $PAGE ?? APP_NAME;
$TRACK_SUBJECT = $TRACK_SUBJECT ?? '';
$TRACK_CHAPTER = $TRACK_CHAPTER ?? '';
// Phase 1: build nav from permissions, not the legacy hardcoded role.
// Each entry: [label, href, icon-name (from assets/icons/sprite.svg)].
$nav = ['dashboard' => ['Dashboard', 'dashboard.php', 'layout-dashboard']];
$nav['study']        = ['Study Material',  'study.php',         'book-open'];
if (function_exists('has_permission')) {
    if (has_permission('study.view') || has_permission('study.edit'))              $nav['questionbank'] = ['Question Bank', 'questionbank.php', 'file-text'];
    if (has_permission('test.attempt') || has_permission('test.create'))           $nav['examzone']     = ['Exam Zone',     'examzone.php',     'list-checks'];
    if (has_permission('reports.view_self') || has_permission('reports.view_all')) $nav['reports']      = ['Reports',       'reports.php',      'bar-chart'];
    if (has_permission('users.manage'))                                            $nav['users']        = ['Users',         'users.php',        'users'];
} else {
    $nav['questionbank'] = ['Question Bank', 'questionbank.php', 'file-text'];
    $nav['examzone']     = ['Exam Zone',     'examzone.php',     'list-checks'];
    $nav['reports']      = ['Reports',       'reports.php',      'bar-chart'];
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?php echo e($PAGE); ?> · <?php echo APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Spline+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/app.css">
<?php
// Display role: pick the highest-priority role the user holds. Falls back to the
// legacy `users.role` ENUM if the new permission helpers aren't loaded (e.g.
// header.php included by a page that doesn't require lib.php).
$_roleLabels = ['superadmin'=>['ADMIN','Super Admin'], 'org_admin'=>['ADMIN','Organisation Admin'],
                'tutor'=>['TUTOR','Tutor'], 'parent'=>['PARENT','Parent'], 'student'=>['STUDENT','Student']];
$_short = strtoupper($u['role'] === 'superadmin' ? 'ADMIN' : 'STUDENT');
$_long  = $u['role'] === 'superadmin' ? 'Super Admin' : 'Student';
if (function_exists('has_role')) {
    foreach ($_roleLabels as $code => $labels) {
        if (has_role($code)) { $_short = $labels[0]; $_long = $labels[1]; break; }
    }
}
?>
</head><body data-screen="<?php echo e($ACTIVE); ?>" data-role="<?php echo e(strtolower($_short)); ?>" data-subject="<?php echo e($TRACK_SUBJECT); ?>" data-chapter="<?php echo e($TRACK_CHAPTER); ?>">
<header class="topbar">
  <button class="burger" id="burger" aria-label="Menu">☰</button>
  <div class="brand"><?php echo APP_NAME; ?></div>
  <?php if (function_exists('user_streak')): $_streak = (int)user_streak($u['id']); if ($_streak > 0): ?>
    <span class="streak" title="<?php echo $_streak; ?>-day study streak">
      <?php echo icon('flame'); ?><b><?php echo $_streak; ?></b>
    </span>
  <?php endif; endif; ?>
  <div class="who"><?php echo e($u['name']); ?><span class="role"><?php echo e($_short); ?></span></div>
</header>
<div class="scrim" id="scrim"></div>
<nav class="drawer" id="drawer">
  <div class="dhead"><?php echo e($_long); ?></div>
  <?php foreach ($nav as $k=>$item): ?>
    <a href="<?php echo $item[1]; ?>" class="<?php echo $ACTIVE===$k?'on':''; ?>"><span class="ic"><?php echo icon($item[2]); ?></span><?php echo $item[0]; ?></a>
  <?php endforeach; ?>
  <a href="account.php" class="<?php echo $ACTIVE==='account'?'on':''; ?>"><span class="ic"><?php echo icon('settings'); ?></span>Account</a>
  <a href="logout.php" class="logout"><span class="ic"><?php echo icon('log-out'); ?></span>Log out</a>
</nav>
<main class="wrap">
