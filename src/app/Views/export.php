<?php
declare(strict_types=1);

/* Export. Expects $project, $rows, $summaries, $exportRow, $exportCurrent, $ready, $building, $author. */
$pageTitle = 'Export · ' . $project['title'];
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';

$pid = (string) $project['id'];
$archived = ($project['status'] ?? '') === 'ARCHIVED';
$hms = static function (int $ms): string {
    $s = intdiv($ms, 1000);
    return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
};
$withText = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['words'] > 0));
$totalMs = 0;
foreach ($withText as $r) if (($summaries[$r['id']]['state'] ?? '') === 'ready') $totalMs += (int) ($summaries[$r['id']]['duration_ms'] ?? 0);
$missing = count($withText) - $ready;
$state = $building ? 'building' : ($exportRow === null ? 'none' : ($exportCurrent ? 'current' : 'stale'));
$badge = ['building' => ['Building…', 'blue'], 'none' => ['Not built yet', ''], 'current' => ['Up to date', 'green'], 'stale' => ['Out of date', 'amber']][$state];
$build = static fn (string $label, string $class = ''): string => '<form method="post" action="' . e(base_url('chapters')) . '">' . csrf_field()
    . '<input type="hidden" name="id" value="' . e($pid) . '"><input type="hidden" name="action" value="build_m4b"><button type="submit"' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $label . '</button></form>';
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1>Export</h1>
      <p>The finished audiobook, as one file with chapters or as one file per chapter.</p>
    </div>
  </div>

  <div class="split-view">
    <div class="split-main" style="display: grid; gap: 20px">
      <section class="card" aria-label="Audiobook file" style="display: flex; gap: 22px; flex-wrap: wrap">
        <div aria-hidden="true" style="width: 132px; height: 190px; flex: none; border-radius: 8px; background: #22364F; color: #F2E6C9; display: flex; flex-direction: column; justify-content: flex-end; padding: 14px; box-sizing: border-box; font-family: var(--serif)">
          <span style="font-size: 17px; line-height: 1.2"><?= e((string) $project['title']) ?></span>
          <?php if ($author !== ''): ?><span style="font-size: 12px; margin-top: 6px; opacity: .85"><?= e($author) ?></span><?php endif; ?>
        </div>
        <div style="flex: 1; min-width: 260px; display: grid; gap: 12px; align-content: start">
          <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
            <h2>Audiobook (M4B with chapters)</h2>
            <span class="tag <?= $badge[1] ?>"><?= e($badge[0]) ?></span>
          </div>
          <p class="muted" style="margin: 0">
            <?php if ($state === 'building'): ?>Being built in the background. The Activity button at the top shows how it is going; this page updates when it is done.
            <?php elseif ($state === 'none'): ?><?= $ready > 0 ? "$ready chapters have audio and go in, in order, with a chapter marker each." : 'No chapter has audio yet. Make the audio on Proofread and listen first.' ?>
            <?php elseif ($state === 'current'): ?><?= (int) $exportRow['chapter_count'] ?> chapter markers · <?= e($hms((int) $exportRow['duration_ms'])) ?> · <?= e(number_format($exportRow['byte_size'] / 1048576, 1)) ?> MB · built <?= e((string) $exportRow['built_at']) ?> UTC
            <?php else: ?>Built <?= e((string) $exportRow['built_at']) ?> UTC. Chapters changed since, so the file is missing those changes.
            <?php endif; ?>
            <?php if ($missing > 0 && $ready > 0 && $state !== 'building'): ?><br><?= $missing ?> chapter<?= $missing === 1 ? ' has' : 's have' ?> no finished audio yet and will be left out.<?php endif; ?>
          </p>
          <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
            <?php if ($state === 'current'): ?>
              <a class="btn" href="<?= e(base_url('export', ['id' => $pid, 'kind' => 'm4b'])) ?>">Download the audiobook</a>
              <?php if (!$archived): ?><?= $build('Rebuild', 'btn-link') ?><?php endif; ?>
            <?php elseif ($state === 'stale'): ?>
              <?php if (!$archived): ?><?= $build('Rebuild with the changes') ?><?php endif; ?>
              <a href="<?= e(base_url('export', ['id' => $pid, 'kind' => 'm4b'])) ?>" style="font-weight: 600">Download the older file</a>
            <?php elseif ($state === 'none' && $ready > 0 && !$archived): ?>
              <?= $build('Build the audiobook') ?>
            <?php elseif ($state === 'building'): ?>
              <button type="button" disabled>Building…</button>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="card" aria-label="Chapter files" style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap">
        <div style="flex: 1; min-width: 240px">
          <h2 style="font-size: 16px">One file per chapter</h2>
          <p class="muted" style="margin: 2px 0 0"><?= $ready ?> M4A file<?= $ready === 1 ? '' : 's' ?> in a ZIP, numbered in reading order.</p>
        </div>
        <?php if ($ready > 0): ?><a class="btn btn-outline" href="<?= e(base_url('export', ['id' => $pid, 'kind' => 'zip'])) ?>">Download ZIP</a><?php endif; ?>
      </section>

      <section class="table-box" aria-label="Chapters">
        <h2 style="font-size: 16px; padding: 16px 16px 4px">Chapters</h2>
        <table class="table">
          <thead><tr><th>Starts at</th><th>Chapter</th><th>Audio</th><th class="num">Length</th></tr></thead>
          <tbody>
          <?php $cursor = 0; foreach ($withText as $r): $s = $summaries[$r['id']] ?? ['state' => 'none']; $isReady = $s['state'] === 'ready'; ?>
            <tr class="<?= $isReady ? '' : 'is-off' ?>">
              <td class="quiet" style="font-variant-numeric: tabular-nums"><?= $isReady ? e($hms($cursor)) : '' ?></td>
              <td><a href="<?= e(base_url('proofread', ['id' => $pid, 'section' => $r['id']])) ?>"><?= e((string) $r['title']) ?></a></td>
              <td><?php
                echo match ($s['state']) {
                    'ready' => '<span class="tag green">Ready</span>', 'outdated' => '<span class="tag amber">Out of date</span>', 'working' => '<span class="tag blue">Being made</span>',
                    'failed' => '<span class="tag red">Failed</span>', 'partial' => '<span class="tag">Partly made</span>', default => $r['status'] === 'approved' ? '<span class="tag">No audio yet</span>' : '<span class="tag">Not approved</span>',
                }; ?></td>
              <td class="num"><?= $isReady ? e($hms((int) ($s['duration_ms'] ?? 0))) : '' ?></td>
            </tr>
          <?php if ($isReady) $cursor += (int) ($s['duration_ms'] ?? 0); endforeach; ?>
          </tbody>
        </table>
      </section>
    </div>

    <aside class="split-side" style="display: grid; gap: 14px; position: static; max-height: none">
      <div class="card">
        <h2 style="font-size: 15px; margin-bottom: 10px">This book</h2>
        <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; font-size: 14px">
          <dt class="muted">Length</dt><dd style="margin: 0; font-weight: 600"><?= e($hms($totalMs)) ?></dd>
          <dt class="muted">Chapters</dt><dd style="margin: 0; font-weight: 600"><?= $ready ?> of <?= count($withText) ?> ready</dd>
          <dt class="muted">Voice</dt><dd style="margin: 0; font-weight: 600"><?= e((string) ($project['active_voice_profile_id'] ?? '')) ?>, <?= e(number_format((float) ($project['tts_speed'] ?? 1.0), 2)) ?>×</dd>
          <dt class="muted">Quality</dt><dd style="margin: 0; font-weight: 600"><?= (int) $project['audio_bitrate_kbps'] ?> kbps mono</dd>
        </dl>
        <p class="quiet" style="margin: 12px 0 0">Change the quality on the <a href="<?= e(base_url('project', ['id' => $pid]) . '#settings') ?>">Settings</a> page. That re-encodes the chapters; the voice is not made again.</p>
      </div>
    </aside>
  </div>
</div>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
