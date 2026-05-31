<?php
/* ============================================================
   Phase 2 — outbound email abstraction.

   send_email($to, $subject, $bodyHtml, $bodyText='', $userId=null)
     - Drives by MAIL_DRIVER ('mail' | 'log').
     - If MAIL_FROM is blank, drops to 'log' driver (records to mail_log,
       does not actually send) so flows still work in dev / unset hosts.
     - Always writes a mail_log row for audit + later debugging.

   app_url($path)
     - Builds an absolute URL. APP_URL constant if set; otherwise
       scheme + HTTP_HOST. Used to make email links clickable.

   gen_token()
     - 64-char hex random token for password reset / email verify.

   render_html_email($title, $bodyHtml, $cta=[label,url]?)
     - Wraps a snippet of HTML in a simple branded shell.
   ============================================================ */
require_once __DIR__ . '/lib.php';

function ensure_mail_tables() {
    db()->exec("CREATE TABLE IF NOT EXISTS password_resets (
      id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
      token CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL,
      used_at DATETIME DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(user_id), INDEX(expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS email_verifications (
      id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
      token CHAR(64) NOT NULL UNIQUE,
      kind ENUM('signup','change','guardian') NOT NULL DEFAULT 'signup',
      expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL,
      meta_json TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(user_id), INDEX(kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS mail_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      to_email VARCHAR(190) NOT NULL, subject VARCHAR(255) NOT NULL,
      driver VARCHAR(20) NOT NULL,
      status ENUM('queued','sent','failed','logged') NOT NULL DEFAULT 'queued',
      body_preview TEXT, error TEXT, user_id INT DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(to_email), INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function gen_token() { return bin2hex(random_bytes(32)); }

function app_url($path = '') {
    $base = defined('APP_URL') && APP_URL !== ''
        ? rtrim(APP_URL, '/')
        : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
           . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
           . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\'));
    if ($path === '') return $base;
    return $base . '/' . ltrim($path, '/');
}

function render_html_email($title, $bodyHtml, $cta = null) {
    $ctaBlock = '';
    if ($cta && !empty($cta[0]) && !empty($cta[1])) {
        $ctaBlock = '<p style="margin:24px 0"><a href="' . htmlspecialchars($cta[1], ENT_QUOTES)
                  . '" style="display:inline-block;background:#1a9479;color:#fff;text-decoration:none;'
                  . 'padding:12px 22px;border-radius:10px;font-weight:600;font-family:Arial,sans-serif">'
                  . htmlspecialchars($cta[0], ENT_QUOTES) . '</a></p>';
    }
    $app = defined('APP_NAME') ? APP_NAME : 'Study app';
    return '<!DOCTYPE html><html><body style="margin:0;background:#f3eee3;font-family:Arial,sans-serif;color:#2d2a24">'
         . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3eee3"><tr><td align="center">'
         . '<table width="540" cellpadding="0" cellspacing="0" border="0" style="background:#fffdf8;border:1px solid #e5ddcb;'
         . 'border-radius:14px;margin:30px 20px;padding:28px"><tr><td>'
         . '<div style="font-family:Georgia,serif;font-size:18px;color:#0d5d4d;margin-bottom:18px">' . htmlspecialchars($app) . '</div>'
         . '<h1 style="font-family:Georgia,serif;font-size:22px;color:#2d2a24;margin:0 0 14px">' . htmlspecialchars($title) . '</h1>'
         . '<div style="font-size:15px;line-height:1.55;color:#2d2a24">' . $bodyHtml . '</div>'
         . $ctaBlock
         . '<hr style="border:none;border-top:1px solid #e5ddcb;margin:24px 0">'
         . '<div style="font-size:12px;color:#9c9483">If you weren\'t expecting this email, just ignore it.</div>'
         . '</td></tr></table></td></tr></table></body></html>';
}

function send_email($to, $subject, $bodyHtml, $bodyText = '', $userId = null) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    ensure_mail_tables();

    $fromAddr = defined('MAIL_FROM') ? MAIL_FROM : '';
    $driver   = defined('MAIL_DRIVER') ? MAIL_DRIVER : 'log';
    if ($fromAddr === '') $driver = 'log';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : (defined('APP_NAME') ? APP_NAME : 'Study');
    $replyTo  = defined('MAIL_REPLY_TO') ? MAIL_REPLY_TO : '';

    // body_preview is just the first ~200 visible chars for the audit log
    $preview = trim(preg_replace('/\s+/u', ' ', strip_tags($bodyText !== '' ? $bodyText : $bodyHtml)));
    if (function_exists('mb_substr')) $preview = mb_substr($preview, 0, 200);
    else                              $preview = substr($preview, 0, 200);

    if ($driver === 'log') {
        db()->prepare("INSERT INTO mail_log (to_email, subject, driver, status, body_preview, user_id)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$to, $subject, 'log', 'logged', $preview, $userId]);
        return true;
    }

    // 'mail' driver — PHP mail() with HTML headers
    $boundary = 'b' . bin2hex(random_bytes(8));
    $fromHdr  = sprintf('"%s" <%s>', addslashes($fromName), $fromAddr);
    $headers  = "From: $fromHdr\r\n"
              . "MIME-Version: 1.0\r\n"
              . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    if ($replyTo) $headers .= "Reply-To: $replyTo\r\n";

    $textPart = $bodyText !== '' ? $bodyText : trim(preg_replace('/\s+/u', ' ', strip_tags($bodyHtml)));
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$textPart\r\n"
           . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$bodyHtml\r\n"
           . "--$boundary--\r\n";

    $ok = @mail($to, $subject, $body, $headers);
    db()->prepare("INSERT INTO mail_log (to_email, subject, driver, status, body_preview, error, user_id)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$to, $subject, 'mail', $ok ? 'sent' : 'failed', $preview,
                   $ok ? null : (error_get_last()['message'] ?? 'mail() returned false'), $userId]);
    return $ok;
}
