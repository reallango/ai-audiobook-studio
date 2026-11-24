<?php
declare(strict_types=1);

/*
 * Proofread and listen: the chapter list, the chapter's narration text, and the sound test / chapter audio tools.
 * Expects $project, $rows (overview), $summaries (audio per chapter), $chapter, $audio, $job, $workerOnline, $audioToken,
 * $prevId, $nextId, $focus, $todo (approved chapters that need audio).
 */
$section = $chapter['section'];
$pageTitle = $section['title'] . ' · ' . $project['title'];
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';

$pid = (string) $project['id'];
$sid = (string) $section['id'];
$archived = $project['status'] === 'ARCHIVED';
$hidden = static fn (string $action, string $blockId = ''): string => csrf_field()
    . '<input type="hidden" name="id" value="' . e($pid) . '"><input type="hidden" name="section" value="' . e($sid) . '">'
    . '<input type="hidden" name="action" value="' . e($action) . '">' . ($blockId !== '' ? '<input type="hidden" name="block_id" value="' . e($blockId) . '">' : '');
$mmss = static function (?int $ms): string {
    if ($ms === null) return '';
    $s = intdiv($ms, 1000);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
$audioUrl = static fn (string $kind, string $ref, array $extra = []): string => base_url('audio', ['id' => $pid, 'kind' => $kind, 'ref' => $ref] + $extra);

$approvedCount = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'approved'));
$withText = count(array_filter($rows, static fn (array $r): bool => (int) $r['words'] > 0));
$toApprove = count(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'approved' && (int) $r['words'] > 0));
$number = array_search($sid, array_column($rows, 'id'), true);
$number = $number === false ? 0 : $number + 1;
$dotFor = static function (array $r, array $a): string {
    if (in_array($a['state'] ?? '', ['working'], true)) return 'work';
    if (($a['state'] ?? '') === 'failed') return 'bad';
    if ($r['status'] === 'approved') return 'ok';
    if ($r['status'] === 'changed') return 'look';
    return '';
};
$status = $chapter['status'];
$counts = $audio['counts'] ?? ['total' => 0, 'done' => 0, 'failed' => 0];
$chapterAudio = $audio['chapter_audio'] ?? null;
$jobActive = $job && in_array($job['status'], ['QUEUED', 'RUNNING'], true);

$renderPieces = static function (array $item): string {
    $html = '';
    foreach ($item['pieces'] as $p) {
        if ($p['kind'] === 'plain') { $html .= e((string) $p['out']); continue; }
        $label = $p['kind'] === 'rule' ? ($p['label'] !== '' ? $p['label'] : 'Rule') : ($p['kind'] === 'caps' ? 'ALL-CAPS word' : 'Your edit');
        $layer = $p['kind'] === 'rule' && $p['scope'] !== '' ? $p['scope'] . '|' . $p['scope_ref'] : '';
        $title = (string) $p['src'] . ' → ' . ((string) $p['out'] === '' ? '(removed)' : (string) $p['out']) . ' · ' . $label;
        $inner = (string) $p['out'] === '' ? '<s>' . e((string) $p['src']) . '</s>' : e((string) $p['out']);
        $html .= '<mark class="chg ' . e((string) $p['kind']) . ((string) $p['out'] === '' ? ' removed' : '') . '" tabindex="0" data-kind="' . e((string) $p['kind']) . '" data-src="' . e((string) $p['src'])
            . '" data-out="' . e((string) $p['out']) . '" data-layer="' . e($layer) . '" data-label="' . e($label) . '" title="' . e($title) . '">' . $inner . '</mark>';
    }
    return $html;
};
?>
<div class="three">
  <nav class="three-list" aria-label="Chapters">
    <div style="display: flex; justify-content: space-between; align-items: baseline; padding: 0 8px 8px; gap: 8px">
      <h2 style="font-size: 14px">Chapters</h2>
      <span class="quiet"><?= $approvedCount ?> of <?= $withText ?> approved</span>
    </div>
    <?php if ($toApprove > 0 && !$archived): ?>
      <form method="post" action="<?= e(base_url('chapters')) ?>" style="padding: 0 8px 10px" onsubmit="return confirm('Approve <?= (int) $toApprove ?> chapter<?= $toApprove === 1 ? '' : 's' ?> without reading them here? An approval only means the text is ready for audio; you can open any chapter and remove it.');">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="approve_all"><input type="hidden" name="section" value="<?= e($sid) ?>">
        <button type="submit" class="btn-outline btn-sm" style="width: 100%">Approve the other <?= $toApprove ?></button>
      </form>
    <?php endif; ?>
    <ol class="chapter-list">
      <?php foreach ($rows as $r): $a = $summaries[$r['id']] ?? []; $d = $dotFor($r, $a); ?>
        <li><a href="<?= e(base_url('proofread', ['id' => $pid, 'section' => $r['id']])) ?>" <?= $r['id'] === $sid ? 'aria-current="page"' : '' ?>>
          <span class="dot <?= $d ?>" aria-hidden="true"></span>
          <span class="t"><?= e((string) $r['title']) ?></span>
          <span class="len"><?= ($a['state'] ?? '') === 'ready' ? e($mmss($a['duration_ms'] ?? null)) : (($a['state'] ?? '') === 'outdated' ? 'redo' : '') ?></span>
        </a></li>
      <?php endforeach; ?>
    </ol>
    <p class="legend">
      <span><span class="dot ok" aria-hidden="true"></span>approved</span>
      <span><span class="dot look" aria-hidden="true"></span>changed since</span>
      <span><span class="dot" aria-hidden="true"></span>not approved</span>
      <span><span class="dot work" aria-hidden="true"></span>making audio</span>
    </p>
  </nav>

  <div class="three-main">
    <div style="max-width: 760px; margin: 0 auto">
      <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap">
        <div>
          <p class="quiet" style="margin: 0">Chapter <?= (int) $number ?> of <?= count($rows) ?> · <?= number_format((int) $chapter['words']) ?> words · voice <?= e((string) $chapter['voice']) ?></p>
          <h1 style="margin-top: 2px"><?= e((string) $section['title']) ?></h1>
          <?php if ($status === 'changed'): ?><p class="notice" style="margin-top: 8px">The text changed since you approved it. Read it again, then approve.</p><?php endif; ?>
          <?php if ($status === 'approved'): ?><p style="margin: 8px 0 0"><span class="tag green">Approved</span></p><?php endif; ?>
        </div>
        <div class="page-actions">
          <?php if (!$archived): ?>
            <?php if ($status === 'approved'): ?>
              <form method="post" action="<?= e(base_url('chapter-review')) ?>"><?= $hidden('unapprove') ?><button type="submit" class="btn-outline">Remove approval</button></form>
            <?php else: ?>
              <form method="post" action="<?= e(base_url('chapter-review')) ?>"><?= $hidden('approve') ?><input type="hidden" name="then" value="<?= e((string) ($nextId ?? '')) ?>">
                <button type="submit" <?= (int) $chapter['words'] === 0 ? 'disabled' : '' ?>><?= $nextId ? 'Approve and go on' : 'Approve' ?></button></form>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($prevId): ?><a class="btn btn-outline btn-icon" href="<?= e(base_url('proofread', ['id' => $pid, 'section' => $prevId])) ?>" aria-label="Previous chapter" title="Previous chapter"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 6l-6 6 6 6"/></svg></a><?php endif; ?>
          <?php if ($nextId): ?><a class="btn btn-outline btn-icon" href="<?= e(base_url('proofread', ['id' => $pid, 'section' => $nextId])) ?>" aria-label="Next chapter" title="Next chapter"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></a><?php endif; ?>
        </div>
      </div>

      <p class="legend" style="margin: 16px 0 0; font-size: 13px">
        Marked words are read differently from how they are written:
        <mark class="chg rule">a rule</mark> <mark class="chg caps">ALL-CAPS</mark> <mark class="chg edit">your edit</mark>.
        Click one, or select any words, to try them in the sound test.
      </p>

      <article class="book reader card" style="margin-top: 14px; padding: 28px 32px" data-book
               data-endpoint="<?= e(base_url('chapter-text')) ?>" data-token="<?= e($audioToken) ?>" data-preview="<?= e(base_url('voice-preview')) ?>"
               data-voice="<?= e((string) $chapter['voice']) ?>" data-speed="<?= e(number_format((float) ($audio['speed'] ?? $project['tts_speed'] ?? 1.0), 2, '.', '')) ?>"
               data-project="<?= e($pid) ?>" data-section="<?= e($sid) ?>">
        <?php if ($chapter['announced'] !== null): ?><p class="quiet" style="font-family: var(--sans); margin: 0 0 12px">Read first: “<?= e((string) $chapter['announced']) ?>”</p><?php endif; ?>
        <?php foreach ($chapter['items'] as $item):
            $isHeading = in_array($item['type'], ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
            $tag = $isHeading ? 'h3' : 'p';
            $changed = $item['edit_count'] > 0 || $item['overridden']; ?>
          <div class="para <?= $item['excluded'] ? 'skipped' : '' ?> <?= $focus === $item['id'] ? 'focus' : '' ?>" id="b-<?= e((string) $item['id']) ?>"
               data-block="<?= e((string) $item['id']) ?>" data-source="<?= e((string) $item['cleaned']) ?>" data-narration="<?= e((string) $item['narration']) ?>">
            <<?= $tag ?> class="para-text <?= $isHeading ? 'is-heading' : '' ?>" data-para-text><?= $renderPieces($item) ?></<?= $tag ?>>
            <?php if ($item['narration'] === '' && !$item['excluded']): ?><small class="para-note">Nothing is read for this paragraph.</small><?php endif; ?>
            <div class="para-bar">
              <?php if ($item['narration'] !== ''): ?><button type="button" class="link-button" data-listen="narration" title="Hear this paragraph as it will be read">Listen</button>
              <button type="button" class="link-button" data-listen="source" title="Hear this paragraph exactly as the book spells it">As written</button><?php endif; ?>
              <?php if ($item['excluded']): ?>
                <span class="para-note">Skipped, not read.</span>
                <?php if (!$archived): ?><button type="button" class="link-button" data-skip="0">Read it</button><?php endif; ?>
              <?php elseif (!$archived): ?>
                <button type="button" class="link-button" data-edit>Edit text</button>
                <button type="button" class="link-button" data-skip="1">Skip</button>
                <?php if ($changed): ?><button type="button" class="link-button" data-reset-edits>Undo my edits</button><?php endif; ?>
              <?php endif; ?>
              <?php if ($item['stale'] > 0): ?><span class="para-note warn" title="The text these edits belonged to no longer matches the book.">Some edits no longer fit and are ignored.</span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$chapter['items']): ?><p class="muted">This chapter has no text.</p><?php endif; ?>
      </article>
    </div>
  </div>

  <aside class="three-side" aria-label="Tools">
    <div class="segmented" role="tablist" aria-label="Tools" style="margin-bottom: 14px">
      <button type="button" role="tab" aria-selected="true" aria-controls="tool-sound" data-tab="tool-sound">Sound test</button>
      <button type="button" role="tab" aria-selected="false" aria-controls="tool-audio" data-tab="tool-audio">Chapter audio</button>
    </div>
    <div id="tool-sound" role="tabpanel">
      <?php if (!$archived):
          $linkedSets = (new \App\Services\PronunciationService())->projectSets($pid);
          $wbLayers = [['project|' . $pid, 'This book']];
          foreach ($linkedSets as $set) $wbLayers[] = ['set|' . $set['id'], 'Ruleset: ' . $set['name']];
          $wbLayers[] = ['voice|' . $chapter['voice'], 'Voice ' . $chapter['voice'] . ', every book'];
          $wbLayers[] = ['default|', 'Every book and voice'];
          $wbProject = $pid;
          $wbVoice = (string) $chapter['voice'];
          $wbSpeed = (float) ($audio['speed'] ?? $project['tts_speed'] ?? 1.0);
          $wbToken = (string) $audioToken;
          require APP_ROOT . '/app/Views/workbench.php';
      else: ?><p class="muted">This book is archived. Restore it on its Settings page to change it.</p><?php endif; ?>
    </div>
    <div id="tool-audio" role="tabpanel" hidden>
      <?php if ($audio !== null): ?>
      <section data-audio-panel data-endpoint="<?= e(base_url('chapter-audio')) ?>" data-token="<?= e($audioToken) ?>"
               data-project="<?= e($pid) ?>" data-section="<?= e($sid) ?>" data-job-active="<?= $jobActive ? '1' : '0' ?>" data-worker-online="<?= $workerOnline ? '1' : '0' ?>">
        <?php if (!$archived): $own = \App\Services\ChapterSettings::get($sid); $pv = (string) ($project['active_voice_profile_id'] ?: \App\Services\VoiceCatalog::DEFAULT_VOICE); ?>
          <form class="chapter-voice" data-chapter-voice style="display: grid; gap: 10px">
            <h3>Voice for this chapter</h3>
            <label style="display: grid; gap: 4px; font-size: 13px; font-weight: 600">Voice <input name="voice" list="kokoro-voices-ch" value="<?= e((string) ($own['voice'] ?? '')) ?>" placeholder="book: <?= e($pv) ?>" maxlength="100"></label>
            <label style="display: grid; gap: 4px; font-size: 13px; font-weight: 600">Speed <input name="speed" type="number" min="0.5" max="2" step="0.05" value="<?= $own['speed'] !== null ? e(number_format($own['speed'], 2, '.', '')) : '' ?>" placeholder="book: <?= e(number_format((float) ($project['tts_speed'] ?? 1.0), 2)) ?>"></label>
            <button type="submit" class="btn-outline">Save</button>
            <small class="quiet">Leave empty to use the book's voice and speed. Changing them redoes this chapter's audio.</small>
            <datalist id="kokoro-voices-ch"><?php foreach (\App\Services\VoiceCatalog::all() as $voiceName): ?><option value="<?= e($voiceName) ?>"><?php endforeach; ?></datalist>
          </form>
          <hr style="border: 0; border-top: 1px solid var(--rule-2); margin: 16px 0">
        <?php endif; ?>
        <?php if (!$audio['approved']): ?>
          <p class="muted">Approve the chapter first: audio is made from the approved text.</p>
        <?php else: ?>
          <p style="margin: 0 0 10px">
            <?php if ($audio['prepared']): ?><?= (int) $counts['done'] ?> of <?= (int) $counts['total'] ?> parts have audio<?= $counts['failed'] ? ', <strong>' . (int) $counts['failed'] . ' failed</strong>' : '' ?>.<?php else: ?>No audio yet.<?php endif; ?>
            <?php if ($audio['needs_prepare'] && $audio['prepared']): ?><br><span class="quiet">The text or voice changed since; making the audio again redoes only the changed parts.</span><?php endif; ?>
          </p>
          <div style="display: flex; gap: 8px; flex-wrap: wrap">
            <button type="button" data-generate><?= !$audio['prepared'] ? 'Make audio' : ($audio['ready_to_assemble'] && !$audio['needs_prepare'] ? ($chapterAudio && !$audio['audio_stale'] ? 'Rebuild chapter audio' : 'Build chapter audio') : 'Make audio') ?></button>
            <?php if ($counts['failed'] > 0): ?><button type="button" class="btn-outline" data-retry>Retry failed parts</button><?php endif; ?>
            <button type="button" class="btn-outline" data-stop hidden>Stop</button>
            <?php if ($audio['prepared']): ?><button type="button" class="btn-outline" data-regenerate>Redo all of it</button><?php endif; ?>
          </div>
          <div class="audio-progress" hidden><div data-bar></div></div>
          <p class="audio-status quiet" data-audio-status aria-live="polite"><?php
              if ($job && $job['status'] === 'FAILED') echo 'Last run failed: ' . e((string) $job['message']);
              elseif ($job && $job['status'] === 'CANCELLED') echo 'Last run was stopped.';
          ?></p>
          <?php if ($chapterAudio): ?>
            <p class="quiet"><?= e($mmss((int) $chapterAudio['duration_ms'])) ?> · <?= e(number_format($chapterAudio['byte_size'] / 1048576, 1)) ?> MB ·
              <a href="<?= e($audioUrl('chapter', $sid, ['download' => 1, 'name' => 'chapter-' . str_pad((string) $section['position'], 3, '0', STR_PAD_LEFT) . '-' . preg_replace('/[^a-z0-9]+/i', '-', (string) $section['title'])])) ?>">download</a></p>
          <?php endif; ?>
          <?php if ($audio['segments']): ?>
            <details class="audio-segments">
              <summary>Parts (<?= (int) $counts['total'] ?>)</summary>
              <table class="table" style="font-size: 13px">
                <tbody>
                <?php foreach ($audio['segments'] as $seg): ?>
                  <tr>
                    <td class="quiet"><?= (int) $seg['position'] + 1 ?></td>
                    <td><?= e(mb_strlen((string) $seg['text']) > 70 ? mb_substr((string) $seg['text'], 0, 70) . '…' : (string) $seg['text']) ?><?= $seg['error'] ? '<br><small class="text-bad">' . e((string) $seg['error']) . '</small>' : '' ?></td>
                    <td><span class="tag <?= $seg['status'] === 'DONE' ? 'green' : ($seg['status'] === 'FAILED' ? 'red' : '') ?>"><?= e(strtolower((string) $seg['status'])) ?></span></td>
                    <td><button type="button" class="link-button" data-reset="<?= e((string) $seg['id']) ?>">redo</button></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </details>
          <?php endif; ?>
        <?php endif; ?>
      </section>
      <script src="<?= e(base_url('assets/js/chapter-audio.js')) ?>" defer></script>
      <?php endif; ?>
    </div>
  </aside>
</div>

<footer class="player" aria-label="Chapter audio">
  <?php if ($chapterAudio): ?>
    <div class="who"><strong><?= e((string) $section['title']) ?></strong><br><span><?= $audio['audio_stale'] ? 'Out of date: made from older text' : 'Chapter audio' ?></span></div>
    <audio controls preload="none" src="<?= e($audioUrl('chapter', $sid)) ?>"></audio>
  <?php else: ?>
    <div class="who" style="flex: 1"><strong><?= e((string) $section['title']) ?></strong><br><span><?= $jobActive ? 'Audio is being made…' : ($status === 'approved' ? 'No audio yet' : 'Approve the chapter to make its audio') ?></span></div>
  <?php endif; ?>
  <?php if (!$archived && $todo > 0): ?>
    <form method="post" action="<?= e(base_url('chapters')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="generate_all"><input type="hidden" name="section" value="<?= e($sid) ?>">
      <button type="submit" class="primary">Make audio for <?= (int) $todo ?> approved chapter<?= $todo === 1 ? '' : 's' ?></button></form>
  <?php endif; ?>
</footer>
<script>
(function () {
  const tabs = document.querySelectorAll('.three-side [role=tab]');
  const show = id => tabs.forEach(t => { const on = t.dataset.tab === id; t.setAttribute('aria-selected', String(on)); document.getElementById(t.dataset.tab).hidden = !on; });
  tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.tab)));
  if (location.hash === '#audio') show('tool-audio');
  // J / K move between paragraphs
  document.addEventListener('keydown', e => {
    if (e.target.closest('input, textarea, select, [contenteditable]') || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key !== 'j' && e.key !== 'k') return;
    const paras = [...document.querySelectorAll('.para')];
    const at = paras.findIndex(p => p.classList.contains('focus'));
    const next = paras[Math.max(0, Math.min(paras.length - 1, at + (e.key === 'j' ? 1 : -1)))];
    if (!next) return;
    paras.forEach(p => p.classList.toggle('focus', p === next));
    next.scrollIntoView({block: 'center'});
  });
})();
</script>
<?php if ($focus !== ''): ?><script>document.getElementById(<?= json_encode('b-' . $focus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)?.scrollIntoView({block: 'center'});</script><?php endif; ?>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
