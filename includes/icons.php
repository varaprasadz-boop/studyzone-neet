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
