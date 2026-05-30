<?php
/* ============================================================
   Login-gated image streamer for uploaded paper pages.
   Direct access to /uploads is blocked by .htaccess; this is the
   only way to view a page image, and only when signed in.
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$file  = $_GET['f'] ?? '';
$paper = (int)($_GET['paper'] ?? 0);
$study = (int)($_GET['study'] ?? 0);

if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $file) || strpbrk($file, "/\\") !== false) {
    http_response_code(400); exit('Bad request');
}
$file = basename($file);

if ($study) {
    // study-item images: any safe image filename under uploads/study/{chapter}
    $base = realpath(__DIR__ . '/../uploads/study/' . $study);
} elseif ($paper) {
    // paper page images are named p1.jpg, p2.png …
    if (!preg_match('/^p\d+\.(jpg|jpeg|png|webp|gif)$/i', $file)) { http_response_code(400); exit('Bad request'); }
    $base = realpath(__DIR__ . '/../uploads/papers/' . $paper);
} else {
    http_response_code(400); exit('Bad request');
}

$path = $base ? realpath($base . '/' . $file) : false;

// guard against path traversal — resolved path must stay inside the folder
if (!$path || !$base || strpos($path, $base) !== 0 || !is_file($path)) {
    http_response_code(404); exit('Not found');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($path));
readfile($path);
