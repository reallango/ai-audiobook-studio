<?php
declare(strict_types=1);

/*
 * The pronunciation panel, shared by the chapter page and the Pronunciation page.
 * Expects: $wbToken, $wbVoice, $wbSpeed, $wbLayers (list of [value, label]), and optionally $wbProject (project id or null).
 */
$wbProject ??= null;
?>
<aside class="review-side">
  <section class="workbench" data-workbench
           data-preview="<?= e(base_url('voice-preview')) ?>" data-rule="<?= e(base_url('pronunciation-rule')) ?>" data-example="<?= e(base_url('pronunciation-example')) ?>"
           data-token="<?= e($wbToken) ?>" data-project="<?= e((string) ($wbProject ?? '')) ?>" data-set="<?= e((string) ($wbSet ?? '')) ?>" data-voice="<?= e($wbVoice) ?>" data-speed="<?= e(number_format((float) $wbSpeed, 2, '.', '')) ?>">
    <h2>Pronunciation</h2>
    <p class="muted">Pick a word to try: click an underlined word, select words in the text, or press <b>try</b> on a rule. Change how it sounds, compare, then save it as a rule. Nothing changes until you save.</p>
    <p class="wb-from" data-wb-from></p>
    <div class="wb-row">
      <label>Written <input data-wb-written maxlength="100" placeholder="Bajor"></label>
      <label>Spoken <input data-wb-spoken maxlength="200" placeholder="Bay-jor"></label>
    </div>
    <label class="wb-text">Sentence to hear <textarea data-wb-text rows="3" maxlength="3000" placeholder="Pick a word, or type a sentence"></textarea></label>
    <div class="wb-row wb-examples">
      <button type="button" class="button secondary" data-wb-prev title="Previous example from the book" aria-label="Previous example">&larr;</button>
      <span class="wb-count" data-wb-count>no example yet</span>
      <button type="button" class="button secondary" data-wb-next title="Next example from the book" aria-label="Next example">&rarr;</button>
      <button type="button" class="button secondary" data-wb-example title="Find sentences in the book where this rule would apply">Find in the book</button>
    </div>
    <div class="wb-row wb-compare">
      <button type="button" class="button secondary" data-wb-hear-original title="The sentence exactly as the book spells it, with no rules">Hear as written</button>
      <button type="button" data-wb-hear title="The sentence with the current rules and the spoken form above">Hear with my change</button>
    </div>
    <audio controls hidden data-wb-audio></audio>
    <p class="wb-status" data-wb-status aria-live="polite"></p>
    <div class="wb-row">
      <label>Save as a rule in
        <select data-wb-layer>
          <?php foreach ($wbLayers as [$value, $label]): ?><option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="check"><input type="checkbox" data-wb-whole checked> whole word</label>
      <button type="button" class="button secondary" data-wb-save>Save rule</button>
    </div>
  </section>
</aside>
<script src="<?= e(base_url('assets/js/workbench.js')) ?>" defer></script>
