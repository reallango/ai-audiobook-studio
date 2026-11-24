</main>
<footer>Audiobook Studio <?= e(trim((string) @file_get_contents(APP_ROOT . '/VERSION'))) ?></footer>
<script src="<?= e(base_url('assets/js/activity.js')) ?>" defer></script>
<script>
// Theme: System, Light or Dark, remembered in this browser. The head script applies it before the page is drawn.
(function () {
  const btn = document.querySelector('[data-theme-toggle]');
  if (!btn) return;
  const names = {system: 'same as the system', light: 'light', dark: 'dark'};
  const order = ['system', 'light', 'dark'];
  const show = mode => {
    btn.dataset.mode = mode;
    btn.setAttribute('aria-label', 'Theme: ' + names[mode] + '. Change it.');
    btn.title = 'Theme: ' + names[mode];
    if (mode === 'system') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', mode);
  };
  let mode = 'system';
  try { const t = localStorage.getItem('theme'); if (t === 'light' || t === 'dark') mode = t; } catch (e) { /* storage unavailable */ }
  show(mode);
  btn.addEventListener('click', () => {
    mode = order[(order.indexOf(mode) + 1) % order.length];
    show(mode);
    try { if (mode === 'system') localStorage.removeItem('theme'); else localStorage.setItem('theme', mode); } catch (e) { /* storage unavailable */ }
  });
})();
</script>
</body>
</html>
