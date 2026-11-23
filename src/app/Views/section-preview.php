<?php
declare(strict_types=1);

/* One section read through. Expects $project, $section, $blocks, $prev, $next, $number, $total. */
$pageTitle = (string) $section['title'];
require APP_ROOT . '/app/Views/layout-top.php';
$setupUrl = rtrim((string) config('app.base_path', ''), '/') . '/logical_sections.php?project=' . rawurlencode((string) $project['id']);
$here = static fn (string $id): string => base_url('section-preview', ['id' => $project['id'], 'section' => $id]);
$kept = (int) $section['included'] === 1;
$post = static fn (string $action, string $label, string $class = '', string $go = ''): string => '<form method="post" class="inline-form">' . csrf_field()
    . '<input type="hidden" name="id" value="' . e((string) $project['id']) . '"><input type="hidden" name="section" value="' . e((string) $section['id']) . '"><input type="hidden" name="action" value="' . $action . '">'
    . ($go !== '' ? '<input type="hidden" name="go" value="' . $go . '">' : '') . '<button type="submit"' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $label . '</button></form>';
?>
<a class="back" href="<?= e($setupUrl) ?>">&larr; Source and structure</a>
<section class="page-head">
  <div>
    <p class="eyebrow">SECTION <?= (int) $number ?> OF <?= (int) $total ?></p>
    <h1><?= e((string) $section['title']) ?></h1>
    <p class="lead compact"><?= number_format((int) $section['word_count']) ?> words &middot; <span class="chapter-status <?= $kept ? 'approved' : 'changed' ?>"><?= $kept ? 'kept in the audiobook' : 'left out' ?></span></p>
  </div>
</section>
<section class="form-panel preview-controls">
  <div class="preview-actions">
    <?= $prev ? '<a class="button secondary" href="' . e($here($prev)) . '">&larr; Previous</a>' : '<span></span>' ?>
    <?= $kept ? $post('skip', 'Leave out', 'secondary') : $post('keep', 'Keep in', '') ?>
    <?php if ($next): ?>
      <?= $kept ? $post('skip', 'Leave out and go next &rarr;', 'secondary', 'next') : $post('keep', 'Keep in and go next &rarr;', '', 'next') ?>
      <a class="button secondary" href="<?= e($here($next)) ?>">Next &rarr;</a>
    <?php endif; ?>
  </div>
  <form method="post" class="rename-row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $project['id']) ?>"><input type="hidden" name="section" value="<?= e((string) $section['id']) ?>"><input type="hidden" name="action" value="rename">
    <label>Name <input name="title" maxlength="200" value="<?= e((string) $section['title']) ?>"></label>
    <button type="submit" class="secondary">Rename</button>
  </form>
</section>
<section class="source-reader">
  <?php if (!$blocks): ?><p><em>No narratable text was found in this section.</em></p><?php endif; ?>
  <?php foreach ($blocks as $block): $tag = in_array($block['block_type'], ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $block['block_type'] : 'p'; ?>
    <<?= $tag ?>><?= e((string) $block['source_text']) ?></<?= $tag ?>>
  <?php endforeach; ?>
</section>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
