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
$capsValue = (int) ($project['normalize_caps'] ?? 1) === 1;
$flow = \App\Services\ProjectWorkflow::build($project, (bool) $sourceAsset, (bool) $sourceAnalyzed, $sourceAsset['original_name'] ?? null);

require APP_ROOT . '/app/Views/layout-top.php';
?>
<?php
$next = $flow['next'];
$c = $flow['counts'];
$actions = static function (string $key) use ($project, $sourceAsset, $sourceAnalyzed, $logicalUrl, $archived, $flow): string {
    $link = static fn (string $label, string $href, bool $primary = false): string => '<a class="button' . ($primary ? '' : ' secondary') . '" href="' . e($href) . '">' . e($label) . '</a>';
    $id = $project['id'];
    $out = '';
    switch ($key) {
        case 'source':
            if (!$sourceAsset) { if (!$archived) $out .= $link('Import EPUB', base_url('epub-upload', ['id' => $id]), true); }
            elseif (!$sourceAnalyzed) { if (!$archived) $out .= '<form method="post" action="' . e(base_url('epub-analyze')) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><button type="submit">Analyze EPUB</button></form>'; }
            else $out .= $link('Source and structure', $logicalUrl);
            break;
        case 'structure':
            if ($sourceAnalyzed) $out .= $link($flow['counts']['total'] > 0 ? 'Edit structure' : 'Build structure', $logicalUrl, $flow['counts']['total'] === 0);
            break;
        case 'voice':
            $out .= $link('Rulesets', base_url('rulesets', ['layer' => 'project', 'ref' => $id])) . $link('Voice Lab', base_url('voice-lab')) . $link('Settings', '#settings');
            break;
        case 'review':
            if ($flow['counts']['included'] > 0) $out .= $link('Review chapters', base_url('chapters', ['id' => $id]));
            break;
        case 'generate':
            if ($flow['counts']['approved'] > 0) $out .= $link('Chapters and audio', base_url('chapters', ['id' => $id]));
            break;
        case 'export':
            if ($flow['export'] !== null) $out .= $link('Download M4B', base_url('export', ['id' => $id, 'kind' => 'm4b'])) . $link('Download M4A files (ZIP)', base_url('export', ['id' => $id, 'kind' => 'zip']));
            if ($flow['counts']['ready'] > 0) $out .= $link($flow['export'] !== null ? 'Rebuild' : 'Export options', base_url('chapters', ['id' => $id]) . '#export');
            break;
    }
    return $out;
};
?>
<section class="next-step">
  <div class="next-copy">
    <small>Next step</small>
    <h2><?= e($next['label']) ?></h2>
    <p><?= e($next['reason']) ?></p>
  </div>
  <?php if ($next['type'] === 'link'): ?>
    <a class="button next-go" href="<?= e($next['href']) ?>">Go &rarr;</a>
  <?php elseif ($next['type'] === 'post'): ?>
    <form method="post" action="<?= e(base_url($next['action'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($project['id']) ?>"><button type="submit" class="next-go">Go &rarr;</button></form>
  <?php endif; ?>
</section>

<div class="overview-layout">
  <ol class="stepper" aria-label="Workflow">
    <?php foreach ($flow['steps'] as $i => $step): ?>
      <li class="step <?= e($step['state']) ?>">
        <span class="step-num"><?= $step['state'] === 'done' ? '&#10003;' : $i + 1 ?></span>
        <div class="step-body">
          <h3><?= e($step['title']) ?></h3>
          <p><?= e($step['summary']) ?></p>
          <div class="step-actions"><?= $actions($step['key']) ?></div>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>

  <aside class="overview-side">
    <section class="side-card" id="settings">
      <h2>Settings</h2>
      <form id="project-settings" data-endpoint="<?= e($settingsEndpoint) ?>" data-token="<?= e(Csrf::token()) ?>" data-project="<?= e($project['id']) ?>">
      <label>Provider<input data-setting="provider" value="<?= e($providerValue) ?>" maxlength="100" required <?= $archived ? 'disabled' : '' ?>></label>
      <label>Voice<input data-setting="voice" list="kokoro-voices" value="<?= e($voiceValue) ?>" maxlength="100" placeholder="Example: am_michael" <?= $archived ? 'disabled' : '' ?>></label>
      <datalist id="kokoro-voices"><?php foreach (\App\Services\VoiceCatalog::all() as $voiceName): ?><option value="<?= e($voiceName) ?>"><?php endforeach; ?></datalist>
      <label>Speed <small>0.5 to 2.0, 1.0 is normal</small><input data-setting="speed" type="number" min="0.5" max="2" step="0.05" value="<?= e(number_format($speedValue, 2, '.', '')) ?>" required <?= $archived ? 'disabled' : '' ?>></label>
      <label class="check"><input type="checkbox" data-setting="normalize_caps" <?= $capsValue ? 'checked' : '' ?> <?= $archived ? 'disabled' : '' ?>> Say ALL-CAPS words as normal words (STARBASE as Starbase). A rule always wins.</label>
      <label>Audio quality <small>mono AAC (m4a, m4b)</small><select data-setting="bitrate" <?= $archived ? 'disabled' : '' ?>><?php foreach (\App\Services\AudioEncoder::BITRATES as $kbps => $label): ?><option value="<?= (int) $kbps ?>" <?= $bitrateValue === $kbps ? 'selected' : '' ?>><?= (int) $kbps ?> kbps, <?= e(strtolower($label)) ?></option><?php endforeach; ?></select></label>
      <?php if (!$archived): ?><div class="settings-actions"><span data-settings-state>All changes saved</span><button type="button" data-save-settings disabled>Save settings</button></div><?php endif; ?>
    </form>
    </section>

    <section class="side-card">
      <h2>Rulesets</h2>
      <?php if ($flow['linked']): ?>
        <ul class="side-list">
          <?php foreach ($flow['linked'] as $set): ?>
            <li><a href="<?= e(base_url('rulesets', ['layer' => 'set', 'ref' => $set['id']])) ?>"><?= e($set['name']) ?></a> <small><?= (int) $set['rules'] ?> rules</small></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><p class="muted">No shared rulesets linked.</p><?php endif; ?>
      <p class="muted"><?= (int) $c['project_rules'] ?> rule<?= $c['project_rules'] === 1 ? '' : 's' ?> only for this book.</p>
      <a class="button secondary" href="<?= e(base_url('rulesets', ['layer' => 'project', 'ref' => $project['id']])) ?>">Manage rulesets</a>
    </section>
  </aside>
</div>

<?php if ($sourceAsset): ?>
<details class="panel source-technical"><summary>Project and source details</summary><div class="source-summary"><dl><div><dt>Project ID</dt><dd><code><?= e($project['id']) ?></code></dd></div><div><dt>Original filename</dt><dd><?= e($sourceAsset['original_name']) ?></dd></div><div><dt>Size</dt><dd><?= number_format((int) $sourceAsset['byte_size']) ?> bytes</dd></div><div><dt>ZIP entries</dt><dd><?= number_format((int) $sourceAsset['zip_entries']) ?></dd></div><div><dt>Imported</dt><dd><?= e($sourceAsset['imported_at']) ?> UTC</dd></div><div class="wide"><dt>SHA-256</dt><dd><code><?= e($sourceAsset['sha256']) ?></code></dd></div></dl></div></details>
<?php endif; ?>

<details class="danger-fold"><summary>Project lifecycle: archive or delete</summary><?php if (!$archived): ?><p>Archive this project to hide it from the active list. Project files are preserved.</p><form method="post" action="<?= e(base_url('project-archive')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($project['id']) ?>"><button class="danger" type="submit">Archive project</button></form><?php else: ?><p>This project is archived. Restore it, or permanently delete it by entering its exact title.</p><form class="inline-form" method="post" action="<?= e(base_url('project-restore')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($project['id']) ?>"><button type="submit">Restore project</button></form><form method="post" action="<?= e(base_url('project-delete')) ?>" onsubmit="return confirm('Permanently delete this project and its files?');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($project['id']) ?>"><label>Type the exact title to permanently delete<input name="confirmation" required></label><button class="danger" type="submit">Permanently delete project</button></form><?php endif; ?></details>

<div class="toast-region" aria-live="polite" aria-atomic="true" data-project-toast></div>
<?php if (!$archived): ?><script src="<?= e(base_url('assets/js/phase2c4.js')) ?>" defer></script><?php endif; ?>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
