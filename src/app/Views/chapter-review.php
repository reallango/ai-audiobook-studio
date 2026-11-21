<?php
declare(strict_types=1);

$section = $chapter['section'];
$pageTitle = $section['title'];
$backUrl = base_url('chapters', ['id' => $project['id']]);
$backLabel = 'Chapters';
require APP_ROOT . '/app/Views/layout-top.php';

$labels = ['approved' => 'Approved', 'changed' => 'Changed since approval', 'unreviewed' => 'Not reviewed'];
$hidden = static fn (string $action, string $blockId = ''): string => csrf_field()
    . '<input type="hidden" name="id" value="' . e((string) $project['id']) . '"><input type="hidden" name="section" value="' . e((string) $section['id']) . '">'
    . '<input type="hidden" name="action" value="' . e($action) . '">' . ($blockId !== '' ? '<input type="hidden" name="block_id" value="' . e($blockId) . '">' : '');
$archived = $project['status'] === 'ARCHIVED';
$excludedCount = count(array_filter($chapter['items'], static fn (array $i): bool => $i['excluded']));
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/chapters.css')) ?>">
<section class="page-head review-head">
  <div>
    <p class="eyebrow">CHAPTER <?= str_pad((string) $section['position'], 3, '0', STR_PAD_LEFT) ?></p>
    <h1><?= e($section['title']) ?></h1>
    <p class="lead compact">
      <span class="chapter-status <?= e($chapter['status']) ?>"><?= e($labels[$chapter['status']]) ?></span>
      <?= number_format($chapter['words']) ?> words, voice <code><?= e($chapter['voice']) ?></code>,
      <?= count($chapter['items']) ?> paragraphs<?= $excludedCount ? ", $excludedCount skipped" : '' ?>.
      Pronunciation rules from the project's layers are already applied.
    </p>
  </div>
  <div class="review-actions">
    <?php if (!$archived): ?>
      <?php if ($chapter['status'] === 'approved'): ?>
        <form method="post"><?= $hidden('unapprove') ?><button type="submit" class="button secondary">Remove approval</button></form>
      <?php else: ?>
        <form method="post"><?= $hidden('approve') ?><button type="submit"><?= $chapter['status'] === 'changed' ? 'Approve the changed text' : 'Approve chapter' ?></button></form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($prevId): ?><a class="button secondary" href="<?= e(base_url('chapter-review', ['id' => $project['id'], 'section' => $prevId])) ?>">← Previous</a><?php endif; ?>
    <?php if ($nextId): ?><a class="button secondary" href="<?= e(base_url('chapter-review', ['id' => $project['id'], 'section' => $nextId])) ?>">Next →</a><?php endif; ?>
  </div>
</section>

<details class="review-full">
  <summary>The text that will be sent for narration</summary>
  <pre><?= e($chapter['text']) ?></pre>
</details>

<section class="review-list">
  <div class="review-cols"><span>Original from the EPUB</span><span>Narration</span></div>
  <?php foreach ($chapter['items'] as $item): ?>
    <article class="review-item <?= $item['excluded'] ? 'skipped' : '' ?> <?= $focus === $item['id'] ? 'focus' : '' ?>" id="b-<?= e($item['id']) ?>">
      <div class="review-source">
        <span class="block-type"><?= e($item['type']) ?></span>
        <p><?= e($item['source']) ?></p>
      </div>
      <div class="review-narration">
        <?php if ($item['excluded']): ?>
          <p class="muted">Skipped, not narrated.</p>
        <?php else: ?>
          <p><?= e($item['narration']) ?></p>
          <?php if ($item['overridden']): ?><p class="badge-line">Edited by hand</p>
          <?php elseif ($item['hits']): ?><p class="badge-line">Rules: <?php foreach ($item['hits'] as $pattern => $n): ?><code><?= e((string) $pattern) ?></code> <?php endforeach; ?></p><?php endif; ?>
        <?php endif; ?>
        <?php if (!$archived): ?>
          <div class="review-tools">
            <form method="post"><?= $hidden('toggle_block', $item['id']) ?><input type="hidden" name="excluded" value="<?= $item['excluded'] ? '0' : '1' ?>"><button type="submit" class="link-button"><?= $item['excluded'] ? 'narrate this' : 'skip this' ?></button></form>
            <?php if (!$item['excluded']): ?>
              <details class="edit-block"><summary>edit text</summary>
                <form method="post"><?= $hidden('set_override', $item['id']) ?>
                  <textarea name="text" rows="4" maxlength="20000"><?= e($item['narration']) ?></textarea>
                  <button type="submit" class="button secondary">Save</button>
                </form>
                <?php if ($item['overridden']): ?><form method="post"><?= $hidden('clear_override', $item['id']) ?><button type="submit" class="link-button">go back to the automatic text</button></form><?php endif; ?>
              </details>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!$chapter['items']): ?><p class="muted">This chapter has no text blocks.</p><?php endif; ?>
</section>
<?php if ($focus !== ''): ?><script>document.getElementById('b-<?= e($focus) ?>')?.scrollIntoView({block: 'center'});</script><?php endif; ?>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
