<?php
declare(strict_types=1);

/* One file from inside the EPUB, as it was read. Expects $project, $section, $blocks. */
$pageTitle = ($section['title_hint'] ?: $section['href']) . ' · ' . $project['title'];
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';
?>
<div class="page" style="max-width: 820px">
  <p style="margin: 0 0 10px"><a href="<?= e(base_url('arrange', ['id' => $project['id']])) ?>">&larr; Arrange</a></p>
  <div class="page-head">
    <div>
      <h1><?= e((string) ($section['title_hint'] ?: $section['href'])) ?></h1>
      <p><?= e((string) $section['href']) ?> · <?= number_format((int) $section['word_count']) ?> words · <?= number_format((int) $section['block_count']) ?> paragraphs. This is the EPUB's own file, as it was read; it is never changed.</p>
    </div>
  </div>
  <article class="card reader" style="padding: 28px 32px">
    <?php if (!$blocks): ?><p class="muted" style="font-family: var(--sans)">No text was found in this file.</p><?php endif; ?>
    <?php foreach ($blocks as $block): $heading = in_array($block['block_type'], ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true); ?>
      <p class="<?= $heading ? 'h' : '' ?>"><?= e((string) $block['source_text']) ?></p>
    <?php endforeach; ?>
  </article>
</div>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
