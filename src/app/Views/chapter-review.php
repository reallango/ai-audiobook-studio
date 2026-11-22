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


<?php
$mmss = static fn (int $ms): string => sprintf('%d:%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000));
$audioUrl = static fn (string $kind, string $ref, array $extra = []): string => base_url('audio', ['id' => $project['id'], 'kind' => $kind, 'ref' => $ref] + $extra);
?>
<?php if ($audio !== null): $counts = $audio['counts']; ?>
<section class="audio-panel" data-audio-panel
         data-endpoint="<?= e(base_url('chapter-audio')) ?>" data-token="<?= e($audioToken) ?>"
         data-project="<?= e((string) $project['id']) ?>" data-section="<?= e((string) $section['id']) ?>"
         data-job-active="<?= $job && in_array($job['status'], ['QUEUED', 'RUNNING'], true) ? '1' : '0' ?>"
         data-worker-online="<?= $workerOnline ? '1' : '0' ?>">
  <h2>Audio</h2>
  <?php if (!$archived): $own = \App\Services\ChapterSettings::get((string) $section['id']); $pv = (string) ($project['active_voice_profile_id'] ?: \App\Services\VoiceCatalog::DEFAULT_VOICE); ?>
    <form class="chapter-voice" data-chapter-voice>
      <label>Voice for this chapter <input name="voice" list="kokoro-voices-ch" value="<?= e((string) ($own['voice'] ?? '')) ?>" placeholder="project: <?= e($pv) ?>" maxlength="100"></label>
      <label>Speed <input name="speed" type="number" min="0.5" max="2" step="0.05" value="<?= $own['speed'] !== null ? e(number_format($own['speed'], 2, '.', '')) : '' ?>" placeholder="project: <?= e(number_format((float) ($project['tts_speed'] ?? 1.0), 2)) ?>"></label>
      <button type="submit" class="button secondary">Save</button>
      <small>Leave empty to use the project's voice and speed. A change makes this chapter's audio be generated again.</small>
      <datalist id="kokoro-voices-ch"><?php foreach (\App\Services\VoiceCatalog::all() as $voiceName): ?><option value="<?= e($voiceName) ?>"><?php endforeach; ?></datalist>
    </form>
  <?php endif; ?>
  <?php if (!$audio['approved']): ?>
    <p class="muted">Approve the chapter first. Audio is made from the approved text, so it can be reviewed before any generation time is spent.
      Voice <code><?= e($audio['voice']) ?></code> at <?= e(number_format($audio['speed'], 2)) ?>x (change it in the project settings).</p>
  <?php else: ?>
    <p>Voice <code><?= e($audio['voice']) ?></code> at <?= e(number_format($audio['speed'], 2)) ?>x.
      <?php if ($audio['prepared']): ?>
        <?= (int) $counts['done'] ?> of <?= (int) $counts['total'] ?> segments have audio<?= $counts['failed'] ? ', <strong>' . (int) $counts['failed'] . ' failed</strong>' : '' ?>.
      <?php else: ?>Not started.<?php endif; ?>
      <?php if ($audio['needs_prepare'] && $audio['prepared']): ?><br><strong>The text, voice or speed changed since the segments were made.</strong> Generating again keeps every unchanged segment and redoes only the rest.<?php endif; ?>
    </p>
    <div class="audio-actions">
      <button type="button" data-generate><?= !$audio['prepared'] ? 'Generate audio' : ($audio['ready_to_assemble'] && !$audio['needs_prepare'] ? ($audio['chapter_audio'] && !$audio['audio_stale'] ? 'Rebuild chapter audio' : 'Build chapter audio') : 'Generate audio') ?></button>
      <?php if ($counts['failed'] > 0): ?><button type="button" class="button secondary" data-retry>Retry failed segments</button><?php endif; ?>
      <button type="button" class="button secondary" data-stop hidden>Cancel</button>
      <?php if ($audio['prepared']): ?><button type="button" class="button secondary" data-regenerate>Regenerate the whole chapter</button><?php endif; ?>
    </div>
    <div class="audio-progress" hidden><div data-bar></div></div>
    <p class="audio-status" data-audio-status aria-live="polite"><?php
        if ($job && $job['status'] === 'FAILED') echo 'Last run failed: ' . e((string) $job['message']);
        elseif ($job && $job['status'] === 'CANCELLED') echo 'Last run was cancelled.';
    ?></p>
    <?php if (!$workerOnline): ?><p class="muted">The background worker has not been seen recently, so a started job will wait until it is running.</p><?php endif; ?>

    <?php if ($audio['chapter_audio']): $ca = $audio['chapter_audio']; ?>
      <div class="audio-chapter">
        <h3>Chapter audio <?= $audio['audio_stale'] ? '<span class="chapter-status changed">out of date, rebuild it</span>' : '' ?></h3>
        <audio controls preload="none" src="<?= e($audioUrl('chapter', (string) $section['id'])) ?>"></audio>
        <p><?= e($mmss((int) $ca['duration_ms'])) ?> long, <?= e(number_format($ca['byte_size'] / 1048576, 1)) ?> MB <?= $ca['format'] === 'm4a' ? 'AAC mono, ' . (int) $ca['bitrate_kbps'] . ' kbps' : 'WAV (older audio, generate again to get AAC)' ?>,
          <a href="<?= e($audioUrl('chapter', (string) $section['id'], ['download' => 1, 'name' => 'chapter-' . str_pad((string) $section['position'], 3, '0', STR_PAD_LEFT) . '-' . preg_replace('/[^a-z0-9]+/i', '-', (string) $section['title'])])) ?>">download</a></p>
      </div>
    <?php endif; ?>

    <?php if ($audio['segments']): ?>
      <details class="audio-segments">
        <summary>Segments (<?= (int) $counts['total'] ?>)</summary>
        <table class="chapters-table">
          <thead><tr><th>#</th><th>Text</th><th>Status</th><th>Length</th><th>Listen</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($audio['segments'] as $seg): ?>
            <tr>
              <td><?= (int) $seg['position'] + 1 ?></td>
              <td><?= e(mb_strlen((string) $seg['text']) > 110 ? mb_substr((string) $seg['text'], 0, 110) . '…' : (string) $seg['text']) ?></td>
              <td><span class="seg-status <?= e(strtolower((string) $seg['status'])) ?>"><?= e(strtolower((string) $seg['status'])) ?></span><?= $seg['error'] ? '<br><small>' . e((string) $seg['error']) . '</small>' : '' ?></td>
              <td><?= $seg['duration_ms'] !== null ? e($mmss((int) $seg['duration_ms'])) : '' ?></td>
              <td><?php if ($seg['status'] === 'DONE'): ?><audio controls preload="none" src="<?= e($audioUrl('segment', (string) $seg['id'])) ?>"></audio><?php endif; ?></td>
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

<div class="review-layout">
<?php if (!$archived): $linkedSets = (new \App\Services\PronunciationService())->projectSets((string) $project['id']); ?>
<aside class="review-side">
<section class="workbench" data-workbench
         data-preview="<?= e(base_url('voice-preview')) ?>" data-rule="<?= e(base_url('pronunciation-rule')) ?>" data-token="<?= e($audioToken) ?>"
         data-project="<?= e((string) $project['id']) ?>" data-voice="<?= e($chapter['voice']) ?>" data-speed="<?= e(number_format((float) ($audio['speed'] ?? $project['tts_speed'] ?? 1.0), 2, '.', '')) ?>">
  <h2>Try a pronunciation</h2>
  <p class="muted">Pick "try this" on a paragraph below, or select a word in the original text. Type how it should sound, listen with this project's voice and rules, then save it as a rule. Nothing changes until you save.</p>
  <label>Text to hear <textarea data-wb-text rows="3" maxlength="3000" placeholder="Choose a paragraph below, or type here"></textarea></label>
  <div class="wb-row">
    <label>Written <input data-wb-written maxlength="100" placeholder="Bajor"></label>
    <label>Spoken <input data-wb-spoken maxlength="200" placeholder="Bay-jor"></label>
    <button type="button" data-wb-hear>Hear it</button>
  </div>
  <audio controls hidden data-wb-audio></audio>
  <p class="wb-status" data-wb-status aria-live="polite"></p>
  <div class="wb-row">
    <label>Save as a rule in
      <select data-wb-layer>
        <option value="project|<?= e((string) $project['id']) ?>">This project only</option>
        <?php foreach ($linkedSets as $set): ?><option value="set|<?= e((string) $set['id']) ?>">Series set: <?= e($set['name']) ?></option><?php endforeach; ?>
        <option value="voice|<?= e($chapter['voice']) ?>">Voice <?= e($chapter['voice']) ?>, every book</option>
        <option value="default|">Default, every book and voice</option>
      </select>
    </label>
    <label class="check"><input type="checkbox" data-wb-whole checked> whole word</label>
    <button type="button" class="button secondary" data-wb-save>Save rule</button>
  </div>
</section>
</aside>
<script src="<?= e(base_url('assets/js/workbench.js')) ?>" defer></script>
<?php endif; ?>

<div class="review-main">
<details class="review-full">
  <summary>The text that will be sent for narration</summary>
  <pre><?= e($chapter['text']) ?></pre>
</details>
<section class="review-list">
  <div class="review-cols"><span>Original from the EPUB</span><span>Narration</span></div>
  <?php foreach ($chapter['items'] as $item): ?>
    <article class="review-item <?= $item['excluded'] ? 'skipped' : '' ?> <?= $focus === $item['id'] ? 'focus' : '' ?>" id="b-<?= e($item['id']) ?>">
      <div class="review-source">
        <span class="block-type"><?= e($item['type']) ?></span><?php if (!$archived): ?> <button type="button" class="link-button" data-try="<?= e($item['cleaned']) ?>">try this</button><?php endif; ?>
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
</div>
</div>
<?php if ($focus !== ''): ?><script>document.getElementById('b-<?= e($focus) ?>')?.scrollIntoView({block: 'center'});</script><?php endif; ?>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
