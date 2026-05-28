<?php
/* ============================================================
   Anthropic (Claude) client — used by Phase 2 (study material
   from syllabus images) and Phase 3 (question extraction from
   paper images). Plain cURL, vision-capable, with prompt caching
   on the system prompt to keep multi-page jobs cheap.
   ============================================================ */
require_once __DIR__ . '/config.php';

function ai_enabled() {
    return defined('ANTHROPIC_API_KEY') && trim(ANTHROPIC_API_KEY) !== '';
}

/* Build an image content block from an uploaded file path. */
function ai_image_block($path) {
    $bytes = @file_get_contents($path);
    if ($bytes === false) return null;
    $mime = function_exists('mime_content_type') ? @mime_content_type($path) : '';
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($mime, $allowed, true)) {
        // fall back by extension
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
        $mime = $map[$ext] ?? 'image/jpeg';
    }
    return [
        'type'   => 'image',
        'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)],
    ];
}

function ai_text_block($text) {
    return ['type' => 'text', 'text' => $text];
}

/* Low-level call.
   $messages : array of ['role'=>'user'|'assistant','content'=>string|array-of-blocks]
   $system   : string system prompt (cached)
   Returns ['ok'=>bool, 'text'=>string, 'error'=>string]                       */
function ai_call($messages, $system = '', $maxTokens = 8192) {
    if (!ai_enabled()) {
        return ['ok' => false, 'text' => '', 'error' => 'No Anthropic API key set in includes/config.php.'];
    }

    // Normalise plain-string message content into block arrays.
    foreach ($messages as &$m) {
        if (is_string($m['content'])) {
            $m['content'] = [ai_text_block($m['content'])];
        }
    }
    unset($m);

    $body = [
        'model'      => defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-6',
        'max_tokens' => $maxTokens,
        'messages'   => $messages,
    ];
    if ($system !== '') {
        // cache_control on the (large, repeated) system prompt → cheaper multi-page runs
        $body['system'] = [[
            'type' => 'text',
            'text' => $system,
            'cache_control' => ['type' => 'ephemeral'],
        ]];
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: prompt-caching-2024-07-31',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'text' => '', 'error' => 'Network error talking to Claude: ' . $err];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);
    if ($status !== 200 || !is_array($json)) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $status);
        return ['ok' => false, 'text' => '', 'error' => 'Claude API error: ' . $msg];
    }

    $text = '';
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') { $text .= $block['text']; }
    }
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

/* Pull a JSON value out of a model reply, tolerating ```json fences
   and stray prose around it. Returns decoded array/value or null.        */
function ai_json($text) {
    $t = trim($text);
    // strip code fences
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $t, $m)) {
        $t = $m[1];
    }
    $decoded = json_decode($t, true);
    if ($decoded !== null) return $decoded;

    // fall back: grab the outermost { } or [ ]
    $start = strcspn($t, '{[');
    if ($start < strlen($t)) {
        $open = $t[$start];
        $close = $open === '{' ? '}' : ']';
        $end = strrpos($t, $close);
        if ($end !== false && $end > $start) {
            $slice = substr($t, $start, $end - $start + 1);
            $decoded = json_decode($slice, true);
            if ($decoded !== null) return $decoded;
        }
    }
    return null;
}
