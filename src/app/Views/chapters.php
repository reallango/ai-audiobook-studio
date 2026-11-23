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
    <h1>Chapters and audio</h1>
    <p class="lead compact">Check what will be narrated, fix problems, then approve each chapter and generate its audio. An approval is tied to the exact text, so a later change to a rule or a paragraph asks for another look.</p>
  </div>
</section>

<?php if (!$rows): ?>
  <section class="empty"><h2>No included chapters yet</h2><p>Build the audiobook structure first, and include at least one section.</p>
    <a class="button" href="<?= e(base_url('project', ['id' => $project['id']])) ?>">Back to the project</a></section>
<?php else: ?>
  <div class="stats">
    <div class="stat"><b><?= $approved ?> / <?= count($rows) ?></b><span>chapters approved</span></div>
    <div class="stat"><b><?= $ready ?></b><span>with finished audio<?= $working ? ', ' . $working . ' in progress' : '' ?></span></div>
    <div class="stat"><b><?= $totalMs > 0 ? e($mmss($totalMs)) : '0:00' ?></b><span>audio so far</span></div>
    <div class="stat"><b><?= number_format($words) ?></b><span>words in the included chapters</span></div>
  </div>
  <?php if ($approved > 0 && $project['status'] !== 'ARCHIVED'): ?>
    <form method="post" class="chapters-generate"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $project['id']) ?>"><input type="hidden" name="action" value="generate_all">
      <button type="submit" <?= $todo === 0 ? 'disabled' : '' ?>>Generate audio for <?= $todo ?> approved chapter<?= $todo === 1 ? '' : 's' ?></button>
      <span class="muted">Only approved chapters that have no up-to-date audio. Finished audio is never redone.</span>
    </form>
    <?php if (!$workerOnline): ?><p class="muted">The background worker has not been seen recently; queued chapters wait until it is running.</p><?php endif; ?>
  <?php endif; ?>
  <?php if ($readyCount > 0): ?>
  <section class="export-panel" id="export">
    <h2>Audiobook files</h2>
    <p class="muted">Each chapter is AAC mono at <b><?= (int) $project['audio_bitrate_kbps'] ?> kbps</b> (change it in the project settings). <?= (int) $readyCount ?> of <?= count($rows) ?> chapters have finished audio. The m4b joins the finished chapters, in order, with a chapter marker for each.</p>
    <?php $m4bUrl = base_url('export', ['id' => $project['id'], 'kind' => 'm4b']); $build = static fn (string $label, string $class = ''): string => '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="id" value="' . e((string) $project['id']) . '"><input type="hidden" name="action" value="build_m4b"><button type="submit"' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $label . '</button></form>'; ?>
    <div class="export-actions">
      <?php if (!$exportRow): ?>
        <?= $build('Build the chaptered M4B') ?>
      <?php elseif ($exportCurrent): ?>
        <a class="button" href="<?= e($m4bUrl) ?>">Download the chaptered M4B</a>
      <?php else: ?>
        <?= $build('Rebuild the chaptered M4B') ?>
      <?php endif; ?>
      <a class="button secondary" href="<?= e(base_url('export', ['id' => $project['id'], 'kind' => 'zip'])) ?>">Download all chapters (M4A in a ZIP)</a>
    </div>
    <?php if ($exportRow): ?>
      <p class="export-file">
        <strong>M4B:</strong> <?= (int) $exportRow['chapter_count'] ?> chapter markers, <?= e($mmss((int) $exportRow['duration_ms'])) ?>, <?= e(number_format($exportRow['byte_size'] / 1048576, 1)) ?> MB, built <?= e((string) $exportRow['built_at']) ?> UTC.
        <?php if ($exportCurrent): ?>
          <span class="chapter-status approved">up to date</span>
          <?= $build('Rebuild', 'link-button') ?>
        <?php else: ?>
          <span class="chapter-status changed">out of date: chapters changed since</span>
          <a href="<?= e($m4bUrl) ?>">Download the old M4B</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <div class="table-wrap"><table class="chapters-table">
    <thead><tr><th>#</th><th>Chapter</th><th class="right">Words</th><th class="right">Edits</th><th>Text</th><th>Audio</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $a = $audio[$r['id']] ?? ['state' => 'n/a', 'done' => 0, 'total' => 0, 'duration_ms' => null]; ?>
      <tr>
        <td><?= str_pad((string) $r['position'], 3, '0', STR_PAD_LEFT) ?></td>
        <td><a href="<?= e(base_url('chapter-review', ['id' => $project['id'], 'section' => $r['id']])) ?>"><?= e($r['title']) ?></a><?php if (!empty($a['custom'])): ?> <small class="custom-voice" title="This chapter has its own voice or speed"><?= e((string) $a['voice']) ?> <?= e(number_format((float) $a['speed'], 2)) ?>x</small><?php endif; ?></td>
        <td class="right"><?= number_format((int) $r['words']) ?></td>
        <td class="right"><?= $r['edited'] ? (int) $r['edited'] : '' ?></td>
        <td><span class="chapter-status <?= e($r['status']) ?>"><?= e($labels[$r['status']]) ?></span></td>
        <td><?php if ($a['state'] !== 'n/a'): ?><span class="audio-state <?= e($a['state']) ?>"><?= e($audioLabels[$a['state']]) ?></span>
          <?php if (in_array($a['state'], ['working', 'partial'], true) && $a['total'] > 0): ?><small><?= (int) $a['done'] ?>/<?= (int) $a['total'] ?></small><?php elseif ($a['state'] === 'ready'): ?><small><?= e($mmss($a['duration_ms'])) ?> <a href="<?= e(base_url('audio', ['id' => $project['id'], 'kind' => 'chapter', 'ref' => $r['id'], 'download' => 1, 'name' => str_pad((string) $r['position'], 3, '0', STR_PAD_LEFT) . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $r['title'])])) ?>">m4a</a></small><?php endif; ?><?php endif; ?></td>
        <td><a class="button secondary" href="<?= e(base_url('chapter-review', ['id' => $project['id'], 'section' => $r['id']])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($working): ?><script>setTimeout(() => location.reload(), 5000);</script><?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
