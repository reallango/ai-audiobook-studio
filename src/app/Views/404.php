<?php
declare(strict_types=1);

/* Not found: the same shell as every page, so the way back is the usual one. */
$pageTitle = 'Not found';
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';
?>
<div class="page" style="max-width: 640px; text-align: center; padding-top: 64px">
  <div class="card" style="display: grid; gap: 12px; justify-items: center; padding: 32px">
    <h1>That page isn't here</h1>
    <p class="muted" style="margin: 0">The link may be old, or the book or chapter was removed.</p>
    <a class="btn" href="<?= e(base_url()) ?>">Go to the Library</a>
  </div>
</div>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
