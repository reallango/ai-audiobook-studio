<?php
declare(strict_types=1);

/* Add the EPUB to a book created without one. Expects $project, $errorMessage. */
$pageTitle = 'Add the EPUB · ' . $project['title'];
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';
?>
<div class="page" style="max-width: 720px">
  <div class="page-head">
    <div>
      <h1>Add the EPUB</h1>
      <p>The file is checked and kept as it is. Once it is added, this book always uses it; for a different edition, start a new audiobook.</p>
    </div>
  </div>
  <?php if ($errorMessage): ?><p class="flash error" style="margin: 0 0 16px"><strong>The upload did not work:</strong> <?= e($errorMessage) ?></p><?php endif; ?>
  <form class="card" method="post" enctype="multipart/form-data" action="<?= e(base_url('epub-upload', ['id' => $project['id']])) ?>" style="display: grid; gap: 16px">
    <?= csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="536870912">
    <label style="display: grid; gap: 6px; font-weight: 600; font-size: 14px">EPUB file <input type="file" name="epub" accept=".epub,application/epub+zip" required></label>
    <p class="quiet" style="margin: 0">Up to 512 MB (the server may allow less; see Diagnostics). The file is checked to be a real EPUB with safe paths, and a checksum is kept.</p>
    <div style="display: flex; gap: 8px; justify-content: flex-end"><a class="btn btn-outline" href="<?= e(base_url('project', ['id' => $project['id']])) ?>">Cancel</a><button type="submit">Add the EPUB</button></div>
  </form>
</div>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
