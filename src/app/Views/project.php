<?php
declare(strict_types=1);

use App\Core\Csrf;

/* A book's Settings: the EPUB, the voice and reading settings, and archive or delete. Expects $project, $sourceAsset, $sourceAnalyzed. */
$pageTitle = 'Settings · ' . $project['title'];
$layout = 'bare';
$archived = $project['status'] === 'ARCHIVED';
$pid = (string) $project['id'];
$settingsEndpoint = rtrim((string) config('app.base_path', ''), '/') . '/project_settings.php';
$providerValue = (string) ($project['active_provider_profile_id'] ?: 'kokoro');
$voiceValue = (string) ($project['active_voice_profile_id'] ?: '');
$bitrateValue = (int) $project['audio_bitrate_kbps'];
$speedValue = (float) ($project['tts_speed'] ?? 1.0);
$capsValue = (int) ($project['normalize_caps'] ?? 1) === 1;
$flow = \App\Services\ProjectWorkflow::build($project, (bool) $sourceAsset, (bool) $sourceAnalyzed, $sourceAsset['original_name'] ?? null);
$stage = $flow['stage']['key'] ?? 'import';
require APP_ROOT . '/app/Views/layout-top.php';
?>
<div class="page" style="max-width: 920px">
  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <p>The book's EPUB, how it is read, and what happens to the project.</p>
    </div>
  </div>

  <?php if ($stage !== 'import' && !$archived): ?>
    <p class="card" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px 18px; background: var(--blue-wash); border-color: var(--blue-line)">
      <span><strong>Next:</strong> <?= e((string) $flow['next']['label']) ?>. <span class="muted"><?= e((string) $flow['next']['reason']) ?></span></span>
      <?php if (($flow['next']['type'] ?? '') === 'link'): ?><a class="btn btn-sm" href="<?= e((string) $flow['next']['href']) ?>">Go</a><?php endif; ?>
    </p>
  <?php endif; ?>

  <div style="display: grid; gap: 20px; margin-top: 16px">
    <section class="card" aria-labelledby="h-source">
      <h2 id="h-source">The book</h2>
      <?php if (!$sourceAsset): ?>
        <p class="muted">No EPUB yet. Add one to start; it is read and split into chapters for you to check.</p>
        <?php if (!$archived): ?>
          <form method="post" action="<?= e(base_url('epub-upload', ['id' => $pid])) ?>" enctype="multipart/form-data" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>">
            <label class="visually-hidden" for="epub-file">EPUB file</label><input id="epub-file" type="file" name="epub" accept=".epub,application/epub+zip" required>
            <button type="submit">Add the EPUB</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p style="margin: 8px 0"><strong><?= e((string) $sourceAsset['original_name']) ?></strong> <span class="quiet">· <?= e(number_format((int) $sourceAsset['byte_size'] / 1048576, 1)) ?> MB · added <?= e((string) $sourceAsset['imported_at']) ?> UTC</span></p>
        <?php if (!$sourceAnalyzed): ?>
          <?php if (!$archived): ?><form method="post" action="<?= e(base_url('epub-analyze')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><button type="submit">Read the EPUB</button></form><?php endif; ?>
        <?php else: ?>
          <p class="muted" style="margin: 0"><?= (int) $flow['counts']['included'] ?> of <?= (int) $flow['counts']['total'] ?> sections are read aloud. <a href="<?= e(base_url('arrange', ['id' => $pid])) ?>">Arrange them</a>.</p>
          <?php if (!$archived): ?>
          <details style="margin-top: 12px">
            <summary style="cursor: pointer" class="muted">Read the EPUB again</summary>
            <p class="muted">Reads the same file again from the start. The chapters, and every edit, approval and audio file made for them, are replaced. Only do this if the import went wrong.</p>
            <form method="post" action="<?= e(base_url('epub-analyze')) ?>" onsubmit="return confirm('Replace the chapters and everything made for them?');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="replace" value="yes"><button type="submit" class="btn-danger">Read it again and replace the chapters</button></form>
          </details>
          <?php endif; ?>
        <?php endif; ?>
        <details style="margin-top: 10px"><summary style="cursor: pointer" class="quiet">File details</summary>
          <p class="quiet" style="word-break: break-all">Project <?= e($pid) ?> · <?= number_format((int) $sourceAsset['zip_entries']) ?> files in the EPUB · SHA-256 <?= e((string) $sourceAsset['sha256']) ?></p></details>
      <?php endif; ?>
    </section>

    <section class="card" id="settings" aria-labelledby="h-reading">
      <h2 id="h-reading">Voice and reading</h2>
      <form id="project-settings" data-endpoint="<?= e($settingsEndpoint) ?>" data-token="<?= e(Csrf::token()) ?>" data-project="<?= e($pid) ?>"
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-top: 12px">
        <label style="display: grid; gap: 4px; font-weight: 600; font-size: 14px">Voice <input data-setting="voice" list="kokoro-voices" value="<?= e($voiceValue) ?>" maxlength="100" placeholder="am_michael" <?= $archived ? 'disabled' : '' ?>>
          <span class="quiet" style="font-weight: 400">Try voices in <a href="<?= e(base_url('voice-lab')) ?>">Voices</a>.</span></label>
        <datalist id="kokoro-voices"><?php foreach (\App\Services\VoiceCatalog::all() as $voiceName): ?><option value="<?= e($voiceName) ?>"><?php endforeach; ?></datalist>
        <label style="display: grid; gap: 4px; font-weight: 600; font-size: 14px">Speed <input data-setting="speed" type="number" min="0.5" max="2" step="0.05" value="<?= e(number_format($speedValue, 2, '.', '')) ?>" required <?= $archived ? 'disabled' : '' ?>>
          <span class="quiet" style="font-weight: 400">0.5 to 2.0; 1.0 is normal.</span></label>
        <label style="display: grid; gap: 4px; font-weight: 600; font-size: 14px">Audio quality <select data-setting="bitrate" <?= $archived ? 'disabled' : '' ?>><?php foreach (\App\Services\AudioEncoder::BITRATES as $kbps => $label): ?><option value="<?= (int) $kbps ?>" <?= $bitrateValue === $kbps ? 'selected' : '' ?>><?= (int) $kbps ?> kbps mono, <?= e(strtolower($label)) ?></option><?php endforeach; ?></select></label>
        <label style="display: grid; gap: 4px; font-weight: 600; font-size: 14px">Voice service <input data-setting="provider" value="<?= e($providerValue) ?>" maxlength="100" required <?= $archived ? 'disabled' : '' ?>></label>
        <label style="grid-column: 1 / -1; display: flex; gap: 10px; align-items: center; font-size: 14px"><input type="checkbox" data-setting="normalize_caps" <?= $capsValue ? 'checked' : '' ?> <?= $archived ? 'disabled' : '' ?>> Read ALL-CAPS words as normal words (STARBASE as Starbase). A rule always wins.</label>
        <?php if (!$archived): ?><div style="grid-column: 1 / -1; display: flex; gap: 12px; align-items: center; justify-content: flex-end"><span class="quiet" data-settings-state>All changes saved</span><button type="button" data-save-settings disabled>Save settings</button></div><?php endif; ?>
      </form>
    </section>

    <?php
      $introSvc = new \App\Services\IntroService();
      $introOn = (int) ($project['intro_enabled'] ?? 0) === 1;
      $standard = \App\Services\IntroService::defaultText($project, $introSvc->author($pid));
      $introText = trim((string) ($project['intro_text'] ?? '')) !== '' ? (string) $project['intro_text'] : $standard;
    ?>
    <section class="card" id="intro" aria-labelledby="h-intro">
      <h2 id="h-intro">Spoken title</h2>
      <p class="muted" style="margin: 6px 0 12px">Read before the first chapter: the title and author, how the audiobook was made, and that it is for personal use only. It goes first in the audiobook file and the ZIP, as a chapter called “Title”, so it never mixes up with an introduction the book has itself.</p>
      <form method="post" action="<?= e(base_url('intro-settings')) ?>" style="display: grid; gap: 12px" data-intro>
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>">
        <label style="display: flex; gap: 10px; align-items: center; font-size: 14px"><input type="checkbox" name="enabled" value="1" <?= $introOn ? 'checked' : '' ?> <?= $archived ? 'disabled' : '' ?>> Start the audiobook with this spoken title</label>
        <label style="display: grid; gap: 4px; font-weight: 600; font-size: 14px">What is read
          <textarea name="text" rows="4" maxlength="<?= \App\Services\IntroService::MAX_CHARS ?>" style="font-family: var(--serif); font-size: 16px; line-height: 1.55" <?= $archived ? 'disabled' : '' ?>><?= e($introText) ?></textarea>
          <span class="quiet" style="font-weight: 400">The standard text follows the book's title, author and voice when they change. Edited text stays as you wrote it. The book's pronunciation rules apply.</span></label>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
          <?php if (!$archived): ?><button type="submit">Save</button><?php endif; ?>
          <button type="button" class="btn-outline" data-intro-hear data-endpoint="<?= e(base_url('voice-preview')) ?>" data-token="<?= e(Csrf::token()) ?>"
                  data-voice="<?= e($voiceValue !== '' ? $voiceValue : \App\Services\VoiceCatalog::DEFAULT_VOICE) ?>" data-speed="<?= e(number_format($speedValue, 2, '.', '')) ?>" data-project="<?= e($pid) ?>">Hear it</button>
          <?php if (!$archived && $introText !== $standard): ?><button type="submit" name="reset" value="1" class="btn-link" formnovalidate>Use the standard text</button><?php endif; ?>
          <span class="quiet" data-intro-status aria-live="polite"></span>
        </div>
        <audio controls hidden data-intro-audio></audio>
      </form>
    </section>

    <section class="card" aria-labelledby="h-life" style="border-color: <?= $archived ? 'var(--rule)' : '#F1D5D7' ?>">
      <h2 id="h-life">Archive or delete</h2>
      <?php if (!$archived): ?>
        <p class="muted">Archiving hides the book from the Library and stops changes. Nothing is deleted; you can restore it.</p>
        <form method="post" action="<?= e(base_url('project-archive')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><button class="btn-outline" type="submit">Archive this book</button></form>
      <?php else: ?>
        <p class="muted">This book is archived. Restore it to work on it, or delete it for good by typing its exact title.</p>
        <div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: end">
          <form method="post" action="<?= e(base_url('project-restore')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><button type="submit">Restore</button></form>
          <form method="post" action="<?= e(base_url('project-delete')) ?>" onsubmit="return confirm('Delete this book and all its files for good?');" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: end">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>">
            <label style="display: grid; gap: 4px; font-size: 13px; font-weight: 600">Type “<?= e((string) $project['title']) ?>” to delete <input name="confirmation" required></label>
            <button class="btn-danger" type="submit">Delete for good</button>
          </form>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<div class="toast-region" aria-live="polite" aria-atomic="true" data-project-toast></div>
<?php if (!$archived): ?><script src="<?= e(base_url('assets/js/phase2c4.js')) ?>" defer></script><?php endif; ?>
<script>
(function () {
  const btn = document.querySelector('[data-intro-hear]');
  if (!btn) return;
  const form = btn.closest('form');
  const status = form.querySelector('[data-intro-status]');
  const audio = form.querySelector('[data-intro-audio]');
  btn.addEventListener('click', async () => {
    const text = form.elements.text.value.replace(/\s*\n\s*/g, ' ').trim();
    if (!text) { status.textContent = 'Write the text first.'; return; }
    btn.disabled = true;
    status.textContent = 'Making a sample…';
    try {
      const r = await fetch(btn.dataset.endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_token: btn.dataset.token, text, voice: btn.dataset.voice, speed: Number(btn.dataset.speed), apply_pronunciation: true, project_id: btn.dataset.project})});
      if (!r.ok) { const x = await r.json().catch(() => ({})); throw new Error(x.message || 'The voice server did not answer.'); }
      if (audio.dataset.url) URL.revokeObjectURL(audio.dataset.url);
      audio.dataset.url = URL.createObjectURL(await r.blob());
      audio.src = audio.dataset.url;
      audio.hidden = false;
      audio.play().catch(() => {});
      status.textContent = '';
    } catch (error) { status.textContent = error.message; } finally { btn.disabled = false; }
  });
})();
</script>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
