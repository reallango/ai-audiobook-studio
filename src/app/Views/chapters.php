<?php
declare(strict_types=1);

$pageTitle = 'Chapters';
$backUrl = base_url('project', ['id' => $project['id']]);
$backLabel = $project['title'];
require APP_ROOT . '/app/Views/layout-top.php';

$labels = ['approved' => 'Approved', 'changed' => 'Changed since approval', 'unreviewed' => 'Not reviewed'];
$audioLabels = ['n/a' => '', 'none' => 'No audio yet', 'partial' => 'Partly generated', 'working' => 'Generating', 'failed' => 'Failed', 'outdated' => 'Out of date', 'ready' => 'Ready'];
$approved = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'approved'));
$ready = count(array_filter($audio, static fn (array $a): bool => $a['state'] === 'ready'));
$working = count(array_filter($audio, static fn (array $a): bool => $a['state'] === 'working'));
$todo = count(array_filter($audio, static fn (array $a): bool => in_array($a['state'], ['none', 'partial', 'outdated', 'failed'], true)));
$words = array_sum(array_column($rows, 'words'));
$mmss = static function (?int $ms): string {
    if ($ms === null) return '';
    $h = intdiv($ms, 3600000);
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000)) : sprintf('%d:%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000));
};
$totalMs = array_sum(array_map(static fn (array $a): int => (int) ($a['duration_ms'] ?? 0), $audio));
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
  <p class="chapters-summary"><strong><?= $approved ?></strong> of <?= count($rows) ?> chapters approved, about <?= number_format($words) ?> words.
    <strong><?= $ready ?></strong> have finished audio<?= $totalMs > 0 ? ' (' . e($mmss($totalMs)) . ' in total)' : '' ?><?= $working ? ", $working in progress" : '' ?>.</p>
  <?php if ($approved > 0 && $project['status'] !== 'ARCHIVED'): ?>
    <form method="post" class="chapters-generate"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $project['id']) ?>"><input type="hidden" name="action" value="generate_all">
      <button type="submit" <?= $todo === 0 ? 'disabled' : '' ?>>Generate audio for <?= $todo ?> approved chapter<?= $todo === 1 ? '' : 's' ?></button>
      <span class="muted">Only approved chapters that have no up-to-date audio. Finished audio is never redone.</span>
    </form>
    <?php if (!$workerOnline): ?><p class="muted">The background worker has not been seen recently; queued chapters wait until it is running.</p><?php endif; ?>
  <?php endif; ?>
  <table class="chapters-table">
    <thead><tr><th>#</th><th>Chapter</th><th>Words</th><th>Edits</th><th>Text</th><th>Audio</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $a = $audio[$r['id']] ?? ['state' => 'n/a', 'done' => 0, 'total' => 0, 'duration_ms' => null]; ?>
      <tr>
        <td><?= str_pad((string) $r['position'], 3, '0', STR_PAD_LEFT) ?></td>
        <td><a href="<?= e(base_url('chapter-review', ['id' => $project['id'], 'section' => $r['id']])) ?>"><?= e($r['title']) ?></a></td>
        <td><?= number_format((int) $r['words']) ?></td>
        <td><?= $r['edited'] ? (int) $r['edited'] : '' ?></td>
        <td><span class="chapter-status <?= e($r['status']) ?>"><?= e($labels[$r['status']]) ?></span></td>
        <td><?php if ($a['state'] !== 'n/a'): ?><span class="audio-state <?= e($a['state']) ?>"><?= e($audioLabels[$a['state']]) ?></span>
          <?php if (in_array($a['state'], ['working', 'partial'], true) && $a['total'] > 0): ?><small><?= (int) $a['done'] ?>/<?= (int) $a['total'] ?></small><?php elseif ($a['state'] === 'ready'): ?><small><?= e($mmss($a['duration_ms'])) ?></small><?php endif; ?><?php endif; ?></td>
        <td><a class="button secondary" href="<?= e(base_url('chapter-review', ['id' => $project['id'], 'section' => $r['id']])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($working): ?><script>setTimeout(() => location.reload(), 5000);</script><?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
