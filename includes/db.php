<?php
$__cfg = __DIR__ . '/config.php';
if (!file_exists($__cfg)) {
    http_response_code(500);
    die('Setup needed: copy <code>includes/config.sample.php</code> to <code>includes/config.php</code> and fill in your database details.');
}
require_once $__cfg;
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $ex) {
            http_response_code(500);
            die('Database connection failed. Check includes/config.php (DB settings).');
        }
    }
    return $pdo;
}
