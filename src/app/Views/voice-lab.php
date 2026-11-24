<?php
declare(strict_types=1);

use App\Core\Csrf;

/* Voices: try voices and speeds on a short passage, two takes side by side. Expects $kokoroReady, $maxChars, $sampleText, $projects, $voiceGroups, $defaultVoice, $defaultSpeed. */
$pageTitle = 'Voices';
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';

$renderVoiceOptions = static function (array $groups, string $selected): string {
    $html = '';
    foreach ($groups as $label => $voices) {
        $html .= '<optgroup label="' . e($label) . '">';
        foreach ($voices as $voice) $html .= '<option value="' . e($voice) . '"' . ($voice === $selected ? ' selected' : '') . '>' . e($voice) . '</option>';
        $html .= '</optgroup>';
    }
    return $html;
};
$field = 'display: grid; gap: 4px; font-weight: 600; font-size: 14px';
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1>Voices</h1>
      <p>Try voices and speeds on a short passage before choosing one for a whole book. Make two takes and compare them side by side.</p>
    </div>
  </div>

  <?php if (!$kokoroReady): ?>
    <p class="flash error" style="margin: 0 0 16px"><strong>The voice server is not set up.</strong> Set <code>KOKORO_API_KEY</code> (and <code>KOKORO_URL</code>) in the container's environment, then redeploy.</p>
  <?php endif; ?>

  <section class="voice-lab" data-endpoint="<?= e(base_url('voice-preview')) ?>" data-token="<?= e(Csrf::token()) ?>" data-max-chars="<?= (int) $maxChars ?>" style="display: grid; gap: 20px">
    <div class="card" style="display: grid; gap: 12px">
      <label style="<?= $field ?>">Passage
        <textarea data-lab-text rows="5" maxlength="<?= (int) $maxChars ?>" style="font-family: var(--serif); font-size: 16px; line-height: 1.6"><?= e($sampleText) ?></textarea></label>
      <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: end">
        <button type="button" class="btn-outline btn-sm" data-use-sample>Use the sample passage</button>
        <span class="quiet" data-char-count></span>
        <label style="<?= $field ?>; margin-left: auto; min-width: 260px">Pronunciation rules
          <select data-lab-pron>
            <option value="off">Off: read the text exactly as typed</option>
            <option value="">On: everyone's defaults and the voice's rules</option>
            <?php foreach ($projects as $p): ?><option value="<?= e($p['id']) ?>">On, as in: <?= e($p['title']) ?></option><?php endforeach; ?>
          </select></label>
      </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px">
      <?php foreach (['A' => $defaultVoice, 'B' => 'bm_lewis'] as $slot => $voice): ?>
        <article class="card lab-take" data-take="<?= e($slot) ?>" style="display: grid; gap: 12px; align-content: start">
          <h2>Take <?= e($slot) ?></h2>
          <label style="<?= $field ?>">Voice <select data-take-voice><?= $renderVoiceOptions($voiceGroups, $voice) ?></select></label>
          <label style="<?= $field ?>">Or a voice name or blend <input data-take-custom maxlength="100" placeholder="for example af_bella+af_sky">
            <span class="quiet" style="font-weight: 400">Leave empty to use the list.</span></label>
          <label style="<?= $field ?>">Speed <input data-take-speed type="number" min="0.5" max="2" step="0.05" value="<?= e(number_format($defaultSpeed, 2, '.', '')) ?>">
            <span class="quiet" style="font-weight: 400">0.5 to 2.0; 1.0 is normal.</span></label>
          <div style="display: flex; gap: 8px; flex-wrap: wrap">
            <button type="button" data-generate <?= $kokoroReady ? '' : 'disabled' ?>>Make take <?= e($slot) ?></button>
            <a class="btn btn-outline" data-download hidden>Download WAV</a>
          </div>
          <p class="quiet" data-status aria-live="polite" style="margin: 0"></p>
          <audio controls preload="none" data-audio hidden style="width: 100%"></audio>
        </article>
      <?php endforeach; ?>
    </div>
    <div><button type="button" class="btn-outline" data-generate-both <?= $kokoroReady ? '' : 'disabled' ?>>Make both takes</button></div>
  </section>
</div>
<script src="<?= e(base_url('assets/js/voice-lab.js')) ?>" defer></script>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
