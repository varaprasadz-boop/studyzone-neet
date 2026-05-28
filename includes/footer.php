</main>
<footer class="foot"><?php echo APP_NAME; ?> · private study app</footer>
<script src="assets/js/app.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js" crossorigin="anonymous"></script>
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js" crossorigin="anonymous" onload="window.__renderMath&&window.__renderMath(document.body)"></script>
<script>
window.__renderMath = function(el){
  if (!window.renderMathInElement) return;
  renderMathInElement(el || document.body, {
    delimiters: [
      {left:'$$', right:'$$', display:true},
      {left:'\\[', right:'\\]', display:true},
      {left:'$', right:'$', display:false},
      {left:'\\(', right:'\\)', display:false}
    ],
    throwOnError:false
  });
};
</script>
</body></html>
