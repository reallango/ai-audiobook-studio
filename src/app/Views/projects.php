<?php
declare(strict_types=1);

/* The Library. Expects $cards (each: project, flow, author), $showArchived, $archivedCount. */
$pageTitle = 'Library';
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';

$covers = ['#22364F', '#5B2A2A', '#2F4A3A', '#4A3B6B', '#6B4A1F', '#1F4A5A'];
$initials = static function (string $title): string {
    $words = preg_split('/\s+/u', trim((string) preg_replace('/^(the|a|an)\s+/iu', '', $title))) ?: [];
    $letters = array_map(static fn (string $w): string => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(array_values(array_filter($words)), 0, 2));
    return implode('', $letters) ?: '?';
};
$stageOrder = ['import' => 0, 'arrange' => 1, 'proofread' => 2, 'export' => 3, 'done' => 4];
$stageNames = ['Import', 'Arrange', 'Proofread', 'Export'];
$hms = static function (int $ms): string {
    $s = intdiv($ms, 1000);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1>Library</h1>
      <p><?= count($cards) ?> audiobook<?= count($cards) === 1 ? '' : 's' ?><?= $showArchived ? ', archived ones included' : '' ?>.
        <?php if ($archivedCount > 0): ?><a href="<?= e(base_url('projects', $showArchived ? [] : ['archived' => '1'])) ?>"><?= $showArchived ? 'Hide archived' : 'Show ' . $archivedCount . ' archived' ?></a><?php endif; ?></p>
    </div>
    <div class="page-actions"><a class="btn btn-outline" href="<?= e(base_url('project-create')) ?>">New audiobook without a file</a></div>
  </div>

  <div class="grid-cards">
    <?php foreach ($cards as $i => $card):
        $p = $card['project'];
        $flow = $card['flow'];
        $stage = $flow['stage'] ?? ['key' => 'import', 'progress' => 0];
        $at = $stageOrder[$stage['key']] ?? 0;
        $c = $flow['counts'] ?? [];
        $archived = ($p['status'] ?? '') === 'ARCHIVED';
        $next = $flow['next'] ?? null;
        $note = '';
        if (!empty($c['failed'])) $note = $c['failed'] . ' chapter' . ($c['failed'] === 1 ? '' : 's') . ' failed';
        elseif (($c['reviewable'] ?? 0) > 0 && ($c['approved'] ?? 0) < $c['reviewable'] && $at >= 2) $note = ($c['reviewable'] - $c['approved']) . ' chapter' . (($c['reviewable'] - $c['approved']) === 1 ? '' : 's') . ' to approve';
        elseif (!empty($c['working'])) $note = 'Making audio';
    ?>
      <article class="card book-card">
        <div class="book-top">
          <div class="book-cover" aria-hidden="true" style="background: <?= e($covers[abs(crc32((string) $p['id'])) % count($covers)]) ?>"><?= e($initials((string) $p['title'])) ?></div>
          <div style="min-width: 0">
            <h2><a href="<?= e($next !== null && ($next['type'] ?? '') === 'link' && !$archived ? (string) $next['href'] : base_url('project', ['id' => $p['id']])) ?>"><?= e((string) $p['title']) ?></a></h2>
            <p class="book-meta"><?= e(trim(($card['author'] !== '' ? $card['author'] . ' · ' : '') . ((string) ($p['active_voice_profile_id'] ?? '') ?: 'no voice yet'))) ?></p>
            <p class="quiet" style="margin: 8px 0 0">
              <?php if (($c['included'] ?? 0) > 0): ?><?= (int) $c['included'] ?> chapters<?= !empty($c['audio_ms']) ? ' · ' . e($hms((int) $c['audio_ms'])) . ' of audio' : '' ?><?php else: ?>No chapters yet<?php endif; ?>
              <?php if ($archived): ?> · <span class="tag">Archived</span><?php endif; ?>
            </p>
          </div>
        </div>
        <ol class="stages" aria-label="Progress">
          <?php foreach ($stageNames as $n => $label): ?>
            <li class="<?= $n < $at ? 'done' : ($n === $at ? 'now' : '') ?>" <?= $n === $at ? 'style="--p: ' . (int) round(100 * (float) $stage['progress']) . '%"' : '' ?>>
              <?= e($label) ?><?php if ($n === $at && $label === 'Proofread' && ($c['reviewable'] ?? 0) > 0): ?> <?= (int) $c['approved'] ?>/<?= (int) $c['reviewable'] ?><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
        <div class="book-foot">
          <span class="quiet" style="<?= $note !== '' ? 'color: var(--amber); font-weight: 600' : '' ?>"><?= e($note !== '' ? $note : (string) ($next['reason'] ?? '')) ?></span>
          <?php if ($archived): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(base_url('project', ['id' => $p['id']])) ?>">Open</a>
          <?php elseif ($next !== null && ($next['type'] ?? '') === 'link'): ?>
            <a class="btn btn-sm" href="<?= e((string) $next['href']) ?>"><?= e($stage['key'] === 'done' ? 'Download' : 'Continue') ?></a>
          <?php else: ?>
            <a class="btn btn-sm" href="<?= e(base_url('project', ['id' => $p['id']])) ?>">Continue</a>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>

    <form class="drop" id="quick-import" method="post" action="<?= e(base_url('quick-import')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <svg aria-hidden="true" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
      <strong style="color: var(--ink)">Drop an EPUB to start a new audiobook</strong>
      <span class="quiet">It is read, split into chapters and named for you to check.</span>
      <label class="btn btn-outline btn-sm" style="color: var(--ink)">Choose a file<input class="visually-hidden" type="file" name="epub" accept=".epub,application/epub+zip" required></label>
      <span class="quiet" data-drop-status aria-live="polite"></span>
    </form>
  </div>
</div>
<script>
(function () {
  const form = document.getElementById('quick-import');
  if (!form) return;
  const input = form.querySelector('input[type=file]');
  const status = form.querySelector('[data-drop-status]');
  const send = () => { if (!input.files.length) return; status.textContent = 'Reading ' + input.files[0].name + '… this can take a minute.'; form.classList.add('over'); form.submit(); };
  input.addEventListener('change', send);
  ['dragenter', 'dragover'].forEach(n => form.addEventListener(n, e => { e.preventDefault(); form.classList.add('over'); }));
  form.addEventListener('dragleave', () => form.classList.remove('over'));
  form.addEventListener('drop', e => { e.preventDefault(); if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; send(); } });
})();
</script>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
