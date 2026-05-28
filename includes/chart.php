<?php
/* ============================================================
   Tiny dependency-free SVG charts for Reports (Phase 5).
   No external JS libraries — renders inline so it works on
   shared hosting and offline.
   ============================================================ */
require_once __DIR__ . '/auth.php';

/* Vertical bar chart.
   $data : [ ['label'=>..,'value'=>float,'sub'=>optional caption], ... ]   */
function chart_bars($data, $opts = []) {
    if (!$data) return '<div class="note">No data yet.</div>';
    $color = $opts['color'] ?? '#3b5bdb';
    $max   = $opts['max']   ?? max(1, max(array_map(fn($d) => $d['value'], $data)));
    $unit  = $opts['unit']  ?? '';
    $h = 150; $bw = 46; $gap = 18; $pad = 24;
    $w = $pad * 2 + count($data) * $bw + (count($data) - 1) * $gap;
    $w = max($w, 240);
    $svg  = '<div class="chart"><svg viewBox="0 0 ' . $w . ' ' . ($h + 46) . '" width="100%" preserveAspectRatio="xMinYMid meet">';
    $x = $pad;
    foreach ($data as $d) {
        $v = (float)$d['value'];
        $bh = $max > 0 ? ($v / $max) * $h : 0;
        if ($bh > 0 && $bh < 2) $bh = 2;
        $y = $h - $bh + 8;
        $svg .= '<rect x="' . $x . '" y="' . round($y, 1) . '" width="' . $bw . '" height="' . round($bh, 1) . '" rx="5" fill="' . e($color) . '"></rect>';
        $cap = rtrim(rtrim(number_format($v, ($v == floor($v) ? 0 : 1)), '0'), '.');
        $svg .= '<text x="' . ($x + $bw / 2) . '" y="' . ($y - 5) . '" text-anchor="middle" font-size="11" font-family="JetBrains Mono,monospace" fill="#6c655a">' . e($cap . $unit) . '</text>';
        $svg .= '<text x="' . ($x + $bw / 2) . '" y="' . ($h + 24) . '" text-anchor="middle" font-size="11" font-family="Spline Sans,sans-serif" fill="#2d2a24">' . e($d['label']) . '</text>';
        if (!empty($d['sub'])) {
            $svg .= '<text x="' . ($x + $bw / 2) . '" y="' . ($h + 38) . '" text-anchor="middle" font-size="9" font-family="JetBrains Mono,monospace" fill="#9c9483">' . e($d['sub']) . '</text>';
        }
        $x += $bw + $gap;
    }
    $svg .= '</svg></div>';
    return $svg;
}

/* Line chart for a single series over time.
   $points : [ ['label'=>..,'value'=>float], ... ]                          */
function chart_line($points, $opts = []) {
    if (count($points) < 1) return '<div class="note">No attempts yet.</div>';
    if (count($points) === 1) {
        return chart_bars($points, $opts);
    }
    $color = $opts['color'] ?? '#0f8a7e';
    $max   = $opts['max']   ?? max(1, max(array_map(fn($p) => $p['value'], $points)));
    $min   = $opts['min']   ?? 0;
    $w = 480; $h = 160; $pad = 30;
    $n = count($points);
    $stepX = ($w - $pad * 2) / ($n - 1);
    $coords = [];
    foreach (array_values($points) as $i => $p) {
        $x = $pad + $i * $stepX;
        $range = ($max - $min) ?: 1;
        $y = $h - $pad - (($p['value'] - $min) / $range) * ($h - $pad * 2);
        $coords[] = [$x, $y, $p];
    }
    $svg = '<div class="chart"><svg viewBox="0 0 ' . $w . ' ' . ($h + 8) . '" width="100%" preserveAspectRatio="xMinYMid meet">';
    // baseline
    $svg .= '<line x1="' . $pad . '" y1="' . ($h - $pad) . '" x2="' . ($w - $pad) . '" y2="' . ($h - $pad) . '" stroke="#e5ddcb" stroke-width="1"/>';
    $path = '';
    foreach ($coords as $i => $c) { $path .= ($i ? ' L ' : 'M ') . round($c[0], 1) . ' ' . round($c[1], 1); }
    $svg .= '<path d="' . $path . '" fill="none" stroke="' . e($color) . '" stroke-width="2.5" stroke-linejoin="round"/>';
    foreach ($coords as $i => $c) {
        $svg .= '<circle cx="' . round($c[0], 1) . '" cy="' . round($c[1], 1) . '" r="4" fill="' . e($color) . '"/>';
        $cap = rtrim(rtrim(number_format($c[2]['value'], 1), '0'), '.');
        $svg .= '<text x="' . round($c[0], 1) . '" y="' . round($c[1] - 9, 1) . '" text-anchor="middle" font-size="10" font-family="JetBrains Mono,monospace" fill="#6c655a">' . e($cap) . '</text>';
        if ($i === 0 || $i === $n - 1 || $n <= 6) {
            $svg .= '<text x="' . round($c[0], 1) . '" y="' . ($h - $pad + 16) . '" text-anchor="middle" font-size="9" font-family="JetBrains Mono,monospace" fill="#9c9483">' . e($c[2]['label']) . '</text>';
        }
    }
    $svg .= '</svg></div>';
    return $svg;
}

/* Horizontal progress/accuracy rows. $rows: [ ['label','pct','sub'] ] */
function chart_hbars($rows, $opts = []) {
    if (!$rows) return '<div class="note">No data yet.</div>';
    $color = $opts['color'] ?? '#4a8c3f';
    $out = '<div class="hbars">';
    foreach ($rows as $r) {
        $pct = max(0, min(100, (float)$r['pct']));
        $out .= '<div class="hbar"><div class="hbar-top"><span>' . e($r['label']) . '</span><b>' . e($r['sub'] ?? (round($pct) . '%')) . '</b></div>'
              . '<div class="hbar-track"><div class="hbar-fill" style="width:' . round($pct, 1) . '%;background:' . e($color) . '"></div></div></div>';
    }
    $out .= '</div>';
    return $out;
}
