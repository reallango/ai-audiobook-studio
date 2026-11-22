<?php
declare(strict_types=1);

use App\Core\Csrf;

$pageTitle = 'Voice Lab';
require APP_ROOT . '/app/Views/layout-top.php';

$renderVoiceOptions = static function (array $groups, string $selected): string {
    $html = '';
    foreach ($groups as $label => $voices) {
        $html .= '<optgroup label="' . e($label) . '">';
        foreach ($voices as $voice) {
            $html .= '<option value="' . e($voice) . '"' . ($voice === $selected ? ' selected' : '') . '>' . e($voice) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
};
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/voice-lab.css')) ?>">
<section class="page-head">
  <div>
    <p class="eyebrow">NARRATION</p>
    <h1>Voice Lab</h1>
    <p class="lead compact">Try voices and speeds on a short passage before committing a whole book. Generate two takes to compare them side by side.</p>
  </div>
</section>

<?php if (!$kokoroReady): ?>
  <div class="error-list"><strong>Kokoro is not configured.</strong> Set <code>KOKORO_API_KEY</code> (and <code>KOKORO_URL</code>) in this container's environment, then redeploy.</div>
<?php endif; ?>

<section class="form-panel voice-lab"
         data-endpoint="<?= e(base_url('voice-preview')) ?>"
         data-token="<?= e(Csrf::token()) ?>"
         data-max-chars="<?= (int) $maxChars ?>">
  <label>Test text
    <textarea data-lab-text rows="5" maxlength="<?= (int) $maxChars ?>"><?= e($sampleText) ?></textarea>
  </label>
  <div class="lab-text-tools">
    <button type="button" class="button secondary" data-use-sample>Use sample passage</button>
    <span data-char-count></span>
  </div>
  <label>Rules
    <select data-lab-pron>
      <option value="off">Off, send the text as typed</option>
      <option value="">On: default and voice layers only</option>
      <?php foreach ($projects as $p): ?><option value="<?= e($p['id']) ?>">On, with project: <?= e($p['title']) ?></option><?php endforeach; ?>
    </select>
  </label>

  <div class="lab-takes">
    <?php foreach (['A' => $defaultVoice, 'B' => 'bm_lewis'] as $slot => $voice): ?>
      <article class="lab-take" data-take="<?= e($slot) ?>">
        <h2>Take <?= e($slot) ?></h2>
        <label>Voice
          <select data-take-voice><?= $renderVoiceOptions($voiceGroups, $voice) ?></select>
        </label>
        <label>Or type a voice name or blend <small>(for example af_bella+af_sky)</small>
          <input data-take-custom maxlength="100" placeholder="Leave empty to use the list">
        </label>
        <label>Speed <small>0.5 to 2.0, default <?= e(number_format($defaultSpeed, 2)) ?></small>
          <input data-take-speed type="number" min="0.5" max="2" step="0.05" value="<?= e(number_format($defaultSpeed, 2, '.', '')) ?>">
        </label>
        <div class="lab-actions">
          <button type="button" data-generate <?= $kokoroReady ? '' : 'disabled' ?>>Generate</button>
          <a class="button secondary" data-download hidden>Download WAV</a>
        </div>
        <p class="lab-status" data-status aria-live="polite"></p>
        <audio controls preload="none" data-audio hidden></audio>
      </article>
    <?php endforeach; ?>
  </div>
  <div class="lab-actions">
    <button type="button" class="button secondary" data-generate-both <?= $kokoroReady ? '' : 'disabled' ?>>Generate both</button>
  </div>
</section>
<script src="<?= e(base_url('assets/js/voice-lab.js')) ?>" defer></script>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
