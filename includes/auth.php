<?php
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function current_user() { return $_SESSION['user'] ?? null; }
function is_admin()     { return current_user() && has_role('superadmin'); }

function require_login() {
    if (!current_user()) { header('Location: login.php'); exit; }
    // Phase 1: a user with a temp password (just created or just reset) is forced
    // through account.php until they pick a new one. Skip the gate for the
    // account/logout/login pages themselves and for /api endpoints (which expect
    // JSON, not a 302 to a HTML form).
    if (!empty(current_user()['must_change_password'])) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $page   = basename($script);
        $isApi  = strpos($script, '/api/') !== false;
        if (!$isApi && !in_array($page, ['account.php', 'logout.php', 'login.php'], true)) {
            header('Location: account.php'); exit;
        }
    }
}
function require_role($role) {
    require_login();
    if (current_user()['role'] !== $role) { header('Location: dashboard.php'); exit; }
}
function attempt_login($username, $password) {
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim($username)]);
    $row = $st->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) return false;
    // Phase 1: disabled / pending accounts cannot start a session
    if (isset($row['status']) && $row['status'] !== 'active') return false;
    $_SESSION['user'] = [
        'id'       => (int)$row['id'],
        'role'     => $row['role'],
        'username' => $row['username'],
        'name'     => $row['name'],
        'must_change_password' => isset($row['must_change_password']) ? (int)$row['must_change_password'] : 0,
    ];
    if (function_exists('clear_perm_cache')) clear_perm_cache();
    return true;
}
function do_logout() { $_SESSION = []; session_destroy(); }
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- role & permission helpers (Phase 1 RBAC) ----------
   Permissions live in role_permissions; a user holds zero or more roles
   via user_roles. has_permission()/has_role() are session-cached for the
   request; clear_perm_cache() runs after a user's own role/scope changes. */
function load_user_roles_cached() {
    if (!current_user()) return [];
    if (!isset($_SESSION['roles_codes'])) {
        $st = db()->prepare("SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=?");
        $st->execute([current_user()['id']]);
        $_SESSION['roles_codes'] = array_column($st->fetchAll(), 'code');
    }
    return $_SESSION['roles_codes'];
}
function load_user_permissions_cached() {
    if (!current_user()) return [];
    if (!isset($_SESSION['perm_codes'])) {
        $st = db()->prepare(
            "SELECT DISTINCT p.code
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?");
        $st->execute([current_user()['id']]);
        $_SESSION['perm_codes'] = array_column($st->fetchAll(), 'code');
    }
    return $_SESSION['perm_codes'];
}
function has_role($code)       { return in_array($code, load_user_roles_cached(), true); }
function has_permission($code) { return in_array($code, load_user_permissions_cached(), true); }
function clear_perm_cache()    { unset($_SESSION['perm_codes'], $_SESSION['roles_codes']); }

function require_permission($code) {
    require_login();
    if (!has_permission($code)) {
        // Soft deny — bounce to dashboard without leaking that the resource exists.
        header('Location: dashboard.php'); exit;
    }
}

/* ---------- role guard (legacy shim) ----------
   require_admin remains the entry-point guard used across existing pages.
   It now checks the modern superadmin role OR the users.manage permission
   so any role granted that permission counts as admin going forward. */
function require_admin() {
    require_login();
    if (!has_role('superadmin') && !has_permission('users.manage')) {
        header('Location: dashboard.php'); exit;
    }
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
