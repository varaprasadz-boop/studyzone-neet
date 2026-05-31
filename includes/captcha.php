<?php
/* ============================================================
   hCaptcha helpers — no-op if HCAPTCHA_SITE_KEY/SECRET are blank.
   Used by register.php (and could be added to forgot.php / login).
   ============================================================ */

function captcha_enabled() {
    return defined('HCAPTCHA_SITE_KEY') && defined('HCAPTCHA_SECRET')
        && HCAPTCHA_SITE_KEY !== '' && HCAPTCHA_SECRET !== '';
}

/* Emit the hCaptcha widget HTML + script tag (or empty if disabled). */
function captcha_widget() {
    if (!captcha_enabled()) return '';
    return '<div class="h-captcha" data-sitekey="' . htmlspecialchars(HCAPTCHA_SITE_KEY, ENT_QUOTES) . '"></div>'
         . '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>';
}

/* Verify the response token from POST. Returns true when disabled. */
function captcha_verify($token, $ip = null) {
    if (!captcha_enabled()) return true;
    if (!is_string($token) || $token === '') return false;
    $ch = curl_init('https://hcaptcha.com/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => HCAPTCHA_SECRET,
            'response' => $token,
            'remoteip' => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return false;
    $j = json_decode($raw, true);
    return is_array($j) && !empty($j['success']);
}
