<?php
declare(strict_types=1);
$pageTitle = 'Import EPUB';
require APP_ROOT . '/app/Views/layout-top.php';
?>
<a class="back" href="<?= e(base_url('project', ['id' => $project['id']])) ?>">← <?= e($project['title']) ?></a>
<section class="page-head">
  <div>
    <p class="eyebrow">PHASE 2A · IMMUTABLE SOURCE</p>
    <h1>Import EPUB</h1>
    <p class="lead compact">This milestone validates and stores the original EPUB. Metadata and reading-order analysis will follow in Phase 2B.</p>
  </div>
</section>
<section class="form-panel">
  <?php if ($errorMessage): ?>
    <div class="error-list"><strong>Upload failed:</strong> <?= e($errorMessage) ?></div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" action="<?= e(base_url('epub-upload', ['id' => $project['id']])) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="536870912">
    <label>EPUB file
      <input type="file" name="epub" accept=".epub,application/epub+zip" required>
    </label>
    <div class="source-warning">
      <strong>Original-source policy</strong>
      <span>After a successful upload, this project will not replace the stored EPUB. Create a new project for a different edition or corrected source file.</span>
    </div>
    <ul class="upload-checks">
      <li>Maximum application size: 512 MB, subject to lower PHP or Nginx limits.</li>
      <li>ZIP archive and EPUB container structure are validated.</li>
      <li>Unsafe archive paths are rejected.</li>
      <li>A SHA-256 checksum and source manifest are created.</li>
    </ul>
    <div class="form-actions">
      <a class="button secondary" href="<?= e(base_url('project', ['id' => $project['id']])) ?>">Cancel</a>
      <button type="submit">Upload immutable EPUB</button>
    </div>
  </form>
</section>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
