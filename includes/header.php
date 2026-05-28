<?php
require_once __DIR__ . '/auth.php';
require_login();
$u = current_user();
$ACTIVE = $ACTIVE ?? '';
$PAGE   = $PAGE ?? APP_NAME;
$TRACK_SUBJECT = $TRACK_SUBJECT ?? '';
$TRACK_CHAPTER = $TRACK_CHAPTER ?? '';
$nav = [
  'dashboard'    => ['Dashboard',       'dashboard.php',     '🏠'],
  'study'        => ['Study Material',  'study.php',         '📘'],
  'questionbank' => ['Question Bank',   'questionbank.php',  '🗂️'],
  'examzone'     => ['Exam Zone',       'examzone.php',      '📝'],
  'reports'      => ['Reports',         'reports.php',       '📊'],
];
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?php echo e($PAGE); ?> · <?php echo APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Spline+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="assets/css/app.css">
</head><body data-screen="<?php echo e($ACTIVE); ?>" data-role="<?php echo e($u['role']); ?>" data-subject="<?php echo e($TRACK_SUBJECT); ?>" data-chapter="<?php echo e($TRACK_CHAPTER); ?>">
<header class="topbar">
  <button class="burger" id="burger" aria-label="Menu">☰</button>
  <div class="brand"><?php echo APP_NAME; ?></div>
  <div class="who"><?php echo e($u['name']); ?><span class="role"><?php echo $u['role']==='superadmin'?'ADMIN':'STUDENT'; ?></span></div>
</header>
<div class="scrim" id="scrim"></div>
<nav class="drawer" id="drawer">
  <div class="dhead"><?php echo $u['role']==='superadmin'?'Super Admin':'Student'; ?></div>
  <?php foreach ($nav as $k=>$item): ?>
    <a href="<?php echo $item[1]; ?>" class="<?php echo $ACTIVE===$k?'on':''; ?>"><span class="ic"><?php echo $item[2]; ?></span><?php echo $item[0]; ?></a>
  <?php endforeach; ?>
  <a href="account.php" class="<?php echo $ACTIVE==='account'?'on':''; ?>"><span class="ic">⚙️</span>Account</a>
  <a href="logout.php" class="logout"><span class="ic">↪</span>Log out</a>
</nav>
<main class="wrap">
