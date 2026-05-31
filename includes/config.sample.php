<?php
/* ============================================================
   NEET Study Zone — configuration TEMPLATE.
   COPY this file to  config.php  and fill in the real values.
   config.php is git-ignored so your secrets never reach the repo.
   ============================================================ */

// ----- Database -----
define('DB_HOST', 'localhost');           // usually 'localhost' on Hostinger
define('DB_NAME', 'CHANGE_ME_dbname');
define('DB_USER', 'CHANGE_ME_dbuser');
define('DB_PASS', 'CHANGE_ME_dbpassword');

// ----- Anthropic API (study-material generation & question extraction) -----
// Create a key at console.anthropic.com. This is SEPARATE from your Claude.ai chat plan.
define('ANTHROPIC_API_KEY', '');                       // e.g. sk-ant-...
define('ANTHROPIC_MODEL',   'claude-sonnet-4-6');      // vision+text model; confirm latest at docs.claude.com

// ----- App -----
define('APP_NAME',      'NEET Study Zone');
define('IDLE_LIMIT_SEC', 300);                         // lock student screen after 5 min idle
define('APP_TZ',        'Asia/Kolkata');
date_default_timezone_set(APP_TZ);

// Absolute base URL — used to build links inside outbound emails. If left
// blank, links fall back to scheme + HTTP_HOST from the current request.
define('APP_URL', '');                                 // e.g. https://study.yourdomain.com

// ----- hCaptcha (optional — used by public registration) -----
// Get free keys at https://www.hcaptcha.com — leave both blank to disable
// the captcha (helper is then a no-op).
define('HCAPTCHA_SITE_KEY', '');
define('HCAPTCHA_SECRET',   '');

// ----- Email (Phase 2) -----
// Driver: 'mail' uses PHP mail() (works on Hostinger); 'log' only writes to
// the mail_log table without sending — useful in dev or before MAIL_FROM is set.
define('MAIL_DRIVER', 'mail');                         // 'mail' | 'log'
define('MAIL_FROM',   '');                             // e.g. study@yourdomain.com  (leave blank to force 'log' driver)
define('MAIL_FROM_NAME', APP_NAME);
define('MAIL_REPLY_TO', '');                           // optional

if (session_status() === PHP_SESSION_NONE) {
    session_name('NSZSESSID');
}
