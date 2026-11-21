<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Database;

$pageTitle = $project['title'];
$backUrl = base_url();
$backLabel = 'Projects';
$archived = $project['status'] === 'ARCHIVED';

$logicalCount = 0;
$includedCount = 0;
if ($sourceAnalyzed) {
    $statement = Database::connection()->prepare(
        'SELECT COUNT(*) AS total, COALESCE(SUM(included),0) AS included
         FROM logical_sections WHERE project_id=:project'
    );
    $statement->execute(['project' => $project['id']]);
    $logicalSummary = $statement->fetch() ?: [];
    $logicalCount = (int) ($logicalSummary['total'] ?? 0);
    $includedCount = (int) ($logicalSummary['included'] ?? 0);
}

$basePath = rtrim((string) config('app.base_path', ''), '/');
$logicalUrl = $basePath . '/logical_sections.php?project=' . rawurlencode($project['id']);
$settingsEndpoint = $basePath . '/project_settings.php';
$providerValue = (string) ($project['active_provider_profile_id'] ?: 'kokoro');
$voiceValue = (string) ($project['active_voice_profile_id'] ?: '');
$bitrateValue = (int) $project['audio_bitrate_kbps'];
$speedValue = (float) ($project['tts_speed'] ?? 1.0);

require APP_ROOT . '/app/Views/layout-top.php';
?>
<section class="page-head project-heading">
  <div>
    <span class="badge status-<?= e(strtolower($project['status'])) ?>"><?= e(str_replace('_', ' ', $project['status'])) ?></span>
    <h1><?= e($project['title']) ?></h1>
    <p class="lead compact">Project ID: <code><?= e($project['id']) ?></code></p>
  </div>
</section>

<section class="readiness-panel">
  <h2>Project readiness</h2>
  <div class="readiness-grid">
    <div class="readiness-item <?= $sourceAsset ? 'complete' : 'pending' ?>"><span><?= $sourceAsset ? '✓' : '○' ?></span><div><strong>EPUB imported</strong><small><?= $sourceAsset ? e($sourceAsset['original_name']) : 'Waiting for source EPUB' ?></small></div></div>
    <div class="readiness-item <?= $sourceAnalyzed ? 'complete' : 'pending' ?>"><span><?= $sourceAnalyzed ? '✓' : '○' ?></span><div><strong>Source analyzed</strong><small><?= $sourceAnalyzed ? 'Metadata, spine, and source blocks ready' : 'Analysis not complete' ?></small></div></div>
    <div class="readiness-item <?= $logicalCount > 0 ? 'complete' : 'pending' ?>"><span><?= $logicalCount > 0 ? '✓' : '○' ?></span><div><strong>Audiobook structure</strong><small><?= $logicalCount > 0 ? $logicalCount . ' logical sections, ' . $includedCount . ' included' : 'Logical sections not created' ?></small></div></div>
    <div class="readiness-item <?= $voiceValue !== '' ? 'complete' : 'pending' ?>"><span><?= $voiceValue !== '' ? '✓' : '○' ?></span><div><strong>Voice selected</strong><small><?= $voiceValue !== '' ? e($voiceValue) : 'Choose a voice before generation' ?></small></div></div>
  </div>
</section>

<section class="workflow-grid">
  <article class="workflow-card">
    <span class="workflow-step">1</span><h2>Source EPUB</h2>
    <?php if (!$sourceAsset): ?>
      <p>Upload and preserve the original EPUB.</p><?php if (!$archived): ?><a class="button" href="<?= e(base_url('epub-upload', ['id' => $project['id']])) ?>">Import EPUB</a><?php endif; ?>
    <?php elseif (!$sourceAnalyzed): ?>
      <p>The immutable EPUB is ready for metadata and spine analysis.</p><form method="post" action="<?= e(base_url('epub-analyze')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($project['id']) ?>"><button type="submit">Analyze EPUB</button></form>
    <?php else: ?>
      <p>Source analysis is complete.</p><a class="button secondary" href="<?= e(base_url('source-sections', ['id' => $project['id']])) ?>">Review source sections</a>
    <?php endif; ?>
  </article>

  <article class="workflow-card">
    <span class="workflow-step">2</span><h2>Audiobook structure</h2>
    <?php if (!$sourceAnalyzed): ?>
      <p>Analyze the EPUB before building the audiobook structure.</p><button disabled>Edit audiobook structure</button>
    <?php elseif ($logicalCount === 0): ?>
      <p>Create logical chapters, group split chapter files, and choose included sections.</p><a class="button" href="<?= e($logicalUrl) ?>">Build audiobook structure</a>
    <?php else: ?>
      <p><?= number_format($logicalCount) ?> logical sections are ready for editing.</p><a class="button" href="<?= e($logicalUrl) ?>">Edit audiobook structure</a>
    <?php endif; ?>
  </article>

  <article class="workflow-card generation-card">
    <span class="workflow-step">3</span><h2>Generation settings</h2>
    <form id="project-settings" data-endpoint="<?= e($settingsEndpoint) ?>" data-token="<?= e(Csrf::token()) ?>" data-project="<?= e($project['id']) ?>">
      <label>Provider<input data-setting="provider" value="<?= e($providerValue) ?>" maxlength="100" required <?= $archived ? 'disabled' : '' ?>></label>
      <label>Voice<input data-setting="voice" list="kokoro-voices" value="<?= e($voiceValue) ?>" maxlength="100" placeholder="Example: am_michael" <?= $archived ? 'disabled' : '' ?>></label>
      <datalist id="kokoro-voices"><?php foreach (\App\Services\VoiceCatalog::all() as $voiceName): ?><option value="<?= e($voiceName) ?>"><?php endforeach; ?></datalist>
      <label>Speed <small>0.5 to 2.0, 1.0 is normal</small><input data-setting="speed" type="number" min="0.5" max="2" step="0.05" value="<?= e(number_format($speedValue, 2, '.', '')) ?>" required <?= $archived ? 'disabled' : '' ?>></label>
      <label>Audio quality<select data-setting="bitrate" <?= $archived ? 'disabled' : '' ?>><option value="64" <?= $bitrateValue === 64 ? 'selected' : '' ?>>64 kbps AAC, draft</option><option value="96" <?= $bitrateValue === 96 ? 'selected' : '' ?>>96 kbps AAC, default</option><option value="128" <?= $bitrateValue === 128 ? 'selected' : '' ?>>128 kbps AAC, high</option></select></label>
      <?php if (!$archived): ?><div class="settings-actions"><span data-settings-state>All changes saved</span><button type="button" data-save-settings disabled>Save settings</button></div><?php endif; ?>
    </form>
  </article>
</section>

<?php if ($sourceAsset): ?>
<details class="panel source-technical"><summary>Source EPUB details</summary><div class="source-summary"><dl><div><dt>Original filename</dt><dd><?= e($sourceAsset['original_name']) ?></dd></div><div><dt>Size</dt><dd><?= number_format((int) $sourceAsset['byte_size']) ?> bytes</dd></div><div><dt>ZIP entries</dt><dd><?= number_format((int) $sourceAsset['zip_entries']) ?></dd></div><div><dt>Imported</dt><dd><?= e($sourceAsset['imported_at']) ?> UTC</dd></div><div class="wide"><dt>SHA-256</dt><dd><code><?= e($sourceAsset['sha256']) ?></code></dd></div></dl></div></details>
<?php endif; ?>

<section class="danger-zone"><h2>Project lifecycle</h2><?php if (!$archived): ?><p>Archive this project to hide it from the active list. Project files are preserved.</p><form method="post" action="<?= e(base_url('project-archive')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($project['id']) ?>"><button class="danger" type="submit">Archive project</button></form><?php else: ?><p>This project is archived. Restore it, or permanently delete it by entering its exact title.</p><form class="inline-form" method="post" action="<?= e(base_url('project-restore')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($project['id']) ?>"><button type="submit">Restore project</button></form><form method="post" action="<?= e(base_url('project-delete')) ?>" onsubmit="return confirm('Permanently delete this project and its files?');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($project['id']) ?>"><label>Type the exact title to permanently delete<input name="confirmation" required></label><button class="danger" type="submit">Permanently delete project</button></form><?php endif; ?></section>

<div class="toast-region" aria-live="polite" aria-atomic="true" data-project-toast></div>
<?php if (!$archived): ?><script src="<?= e(base_url('assets/js/phase2c4.js')) ?>" defer></script><?php endif; ?>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
