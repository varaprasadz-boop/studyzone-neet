<?php
/* ============================================================
   NEET Study Zone — configuration
   Fill the DB_* values from Hostinger hPanel → MySQL Databases.
   Paste the Anthropic API key in Phase 2 (never share it).
   ============================================================ */

// ----- Database -----
define('DB_HOST', 'localhost');           // usually 'localhost' on Hostinger
define('DB_NAME', 'CHANGE_ME_dbname');
define('DB_USER', 'CHANGE_ME_dbuser');
define('DB_PASS', 'CHANGE_ME_dbpassword');

// ----- Anthropic API (used from Phase 2 onward) -----
// Create a key at console.anthropic.com. This is SEPARATE from your Claude.ai chat plan.
define('ANTHROPIC_API_KEY', '');                       // e.g. sk-ant-...
define('ANTHROPIC_MODEL',   'claude-sonnet-4-6');      // vision+text model; confirm latest at docs.claude.com

// ----- App -----
define('APP_NAME',      'NEET Study Zone');
define('IDLE_LIMIT_SEC', 300);                         // lock student screen after 5 min idle
define('APP_TZ',        'Asia/Kolkata');
date_default_timezone_set(APP_TZ);

if (session_status() === PHP_SESSION_NONE) {
    session_name('NSZSESSID');
}
