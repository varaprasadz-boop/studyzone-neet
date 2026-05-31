<?php
/* ============================================================
   icon() — emit an <svg> that references a symbol in the cached
   sprite. Uses currentColor + .icn so size & color follow CSS.
   $extra is appended to the class attribute (e.g. icon('flame','lg').
   ============================================================ */
function icon($name, $extra = '') {
    $class = trim('icn ' . $extra);
    return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" aria-hidden="true">'
         . '<use href="assets/icons/sprite.svg#' . htmlspecialchars($name, ENT_QUOTES) . '"></use></svg>';
}

/* Inline an illustration SVG (one of the files in assets/illus/) wrapped in
   a sized container. Returns '' silently if the file doesn't exist so the
   caller doesn't need to guard. */
function illus($name, $maxWidth = '220px') {
    $svg = @file_get_contents(__DIR__ . '/../assets/illus/' . basename($name) . '.svg');
    if (!$svg) return '';
    return '<div class="illus" style="max-width:' . htmlspecialchars($maxWidth, ENT_QUOTES) . '">' . $svg . '</div>';
}
