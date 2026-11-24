<?php
declare(strict_types=1);

/* New audiobook without a file. Expects $errors, $values. */
$pageTitle = 'New audiobook';
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';
$field = 'display: grid; gap: 4px; font-weight: 600; font-size: 14px';
?>
<div class="page" style="max-width: 720px">
  <div class="page-head">
    <div>
      <h1>New audiobook</h1>
      <p>Name the book and choose a voice; you add the EPUB on the next page. To start straight from a file, drop it on the <a href="<?= e(base_url()) ?>">Library</a> instead.</p>
    </div>
  </div>
  <?php if ($errors): ?>
    <div class="flash error" style="margin: 0 0 16px"><strong>Please fix this:</strong><ul style="margin: 6px 0 0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form class="card" method="post" action="<?= e(base_url('project-create')) ?>" style="display: grid; gap: 16px">
    <?= csrf_field() ?>
    <label style="<?= $field ?>">Title <input name="title" maxlength="200" required autofocus value="<?= e((string) $values['title']) ?>" placeholder="The book's title"></label>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px">
      <label style="<?= $field ?>">Voice <input name="voice" list="kokoro-voices-new" maxlength="100" value="<?= e((string) $values['voice']) ?>" placeholder="am_michael">
        <span class="quiet" style="font-weight: 400">Try voices on the <a href="<?= e(base_url('voice-lab')) ?>">Voices</a> page.</span></label>
      <datalist id="kokoro-voices-new"><?php foreach (\App\Services\VoiceCatalog::all() as $voiceName): ?><option value="<?= e($voiceName) ?>"><?php endforeach; ?></datalist>
      <label style="<?= $field ?>">Voice service <select name="provider"><option value="kokoro" <?= $values['provider'] === 'kokoro' ? 'selected' : '' ?>>Kokoro</option></select></label>
      <label style="<?= $field ?>">Audio quality <select name="bitrate"><?php foreach (\App\Services\AudioEncoder::BITRATES as $kbps => $label): ?><option value="<?= (int) $kbps ?>" <?= (int) $values['bitrate'] === $kbps ? 'selected' : '' ?>><?= (int) $kbps ?> kbps mono, <?= e(strtolower($label)) ?></option><?php endforeach; ?></select></label>
    </div>
    <div style="display: flex; gap: 8px; justify-content: flex-end"><a class="btn btn-outline" href="<?= e(base_url()) ?>">Cancel</a><button type="submit">Create</button></div>
  </form>
</div>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
