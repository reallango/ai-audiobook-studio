<?php
declare(strict_types=1);

$pageTitle = 'Chapters';
$backUrl = base_url('project', ['id' => $project['id']]);
$backLabel = $project['title'];
require APP_ROOT . '/app/Views/layout-top.php';

$labels = ['approved' => 'Approved', 'changed' => 'Changed since approval', 'unreviewed' => 'Not reviewed'];
$approved = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'approved'));
$words = array_sum(array_column($rows, 'words'));
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/chapters.css')) ?>">
<section class="page-head">
  <div>
    <p class="eyebrow">REVIEW</p>
    <h1>Chapters</h1>
    <p class="lead compact"><?= e($project['title']) ?>. Check what will be narrated, fix problems, then approve each chapter. An approval is tied to the exact text, so a later change to the dictionary or the text asks for another look.</p>
  </div>
</section>

<?php if (!$rows): ?>
  <section class="empty"><h2>No included chapters yet</h2><p>Build the audiobook structure first, and include at least one section.</p>
    <a class="button" href="<?= e(base_url('project', ['id' => $project['id']])) ?>">Back to the project</a></section>
<?php else: ?>
  <p class="chapters-summary"><strong><?= $approved ?></strong> of <?= count($rows) ?> chapters approved, about <?= number_format($words) ?> words in total.</p>
  <table class="chapters-table">
    <thead><tr><th>#</th><th>Chapter</th><th>Words</th><th>Edits</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= str_pad((string) $r['position'], 3, '0', STR_PAD_LEFT) ?></td>
        <td><a href="<?= e(base_url('chapter-review', ['id' => $project['id'], 'section' => $r['id']])) ?>"><?= e($r['title']) ?></a></td>
        <td><?= number_format((int) $r['words']) ?></td>
        <td><?= $r['edited'] ? (int) $r['edited'] : '' ?></td>
        <td><span class="chapter-status <?= e($r['status']) ?>"><?= e($labels[$r['status']]) ?></span></td>
        <td><a class="button secondary" href="<?= e(base_url('chapter-review', ['id' => $project['id'], 'section' => $r['id']])) ?>">Review</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
