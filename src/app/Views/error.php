<?php
declare(strict_types=1);

/* Something went wrong. Expects $message. */
$pageTitle = 'Something went wrong';
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';
?>
<div class="page" style="max-width: 640px; text-align: center; padding-top: 64px">
  <div class="card" style="display: grid; gap: 12px; justify-items: center; padding: 32px">
    <span class="dot bad" aria-hidden="true" style="width: 14px; height: 14px; border-radius: 7px"></span>
    <h1>Something went wrong</h1>
    <p class="muted" style="margin: 0"><?= e((string) $message) ?></p>
    <div style="display: flex; gap: 8px"><a class="btn btn-outline" href="javascript:history.back()">Go back</a><a class="btn" href="<?= e(base_url()) ?>">Library</a></div>
  </div>
</div>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
