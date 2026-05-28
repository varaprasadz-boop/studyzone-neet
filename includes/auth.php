<?php
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function current_user() { return $_SESSION['user'] ?? null; }
function is_admin()     { $u = current_user(); return $u && $u['role'] === 'superadmin'; }

function require_login() {
    if (!current_user()) { header('Location: login.php'); exit; }
}
function require_role($role) {
    require_login();
    if (current_user()['role'] !== $role) { header('Location: dashboard.php'); exit; }
}
function attempt_login($username, $password) {
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim($username)]);
    $row = $st->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['user'] = [
            'id'       => (int)$row['id'],
            'role'     => $row['role'],
            'username' => $row['username'],
            'name'     => $row['name'],
        ];
        return true;
    }
    return false;
}
function do_logout() { $_SESSION = []; session_destroy(); }
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- role guard ---------- */
function require_admin() {
    require_login();
    if (current_user()['role'] !== 'superadmin') { header('Location: dashboard.php'); exit; }
}

/* ---------- CSRF ---------- */
function csrf_token() {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_ok() {
    $t = $_POST['_csrf'] ?? '';
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function require_csrf() {
    if (!csrf_ok()) { http_response_code(400); die('Invalid or expired form token. Go back and try again.'); }
}

/* ---------- JSON response (for /api endpoints) ---------- */
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
