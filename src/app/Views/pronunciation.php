<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Services\PronunciationService;

/*
 * Shared rulesets: rules used by more than one book. Tabs for named rulesets, a voice's rules, and everyone's defaults.
 * (A book's own rules are on that book's Pronunciation page.) Expects the variables of App::pronunciation().
 */
$pageTitle = 'Shared rulesets';
$layout = 'bare';
$projectNav = null;
require APP_ROOT . '/app/Views/layout-top.php';

$tabUrl = static fn (string $l, string $r = ''): string => base_url('rulesets', ['layer' => $l] + ($r !== '' ? ['ref' => $r] : []));
$hidden = static fn (): string => csrf_field() . '<input type="hidden" name="layer" value="' . e($layer) . '"><input type="hidden" name="ref" value="' . e($ref) . '">';
$voiceOptions = static function (array $groups, string $selected): string {
    $html = '';
    foreach ($groups as $label => $voices) {
        $html .= '<optgroup label="' . e($label) . '">';
        foreach ($voices as $voice) $html .= '<option value="' . e($voice) . '"' . ($voice === $selected ? ' selected' : '') . '>' . e($voice) . '</option>';
        $html .= '</optgroup>';
    }
    return $html;
};
// every place a rule can be moved or copied to, except the one being shown
$destOptions = '';
$destGroup = static function (string $label, array $items) use (&$destOptions, $layer, $ref): void {
    $html = '';
    foreach ($items as [$value, $text]) if ($value !== $layer . '|' . $ref) $html .= '<option value="' . e($value) . '">' . e($text) . '</option>';
    if ($html !== '') $destOptions .= '<optgroup label="' . e($label) . '">' . $html . '</optgroup>';
};
$destGroup('Everyone', [['default|', 'Every book and voice']]);
$destGroup('Shared rulesets', array_map(static fn (array $s): array => ['set|' . $s['id'], $s['name']], $sets));
$destGroup('Books', array_map(static fn (array $p): array => ['project|' . $p['id'], $p['title']], $projects));
$destGroup('Voices', array_map(static fn (string $v): array => ['voice|' . $v, $v], \App\Services\VoiceCatalog::all()));
$matchLabel = static fn (array $r): string => ['word' => 'Whole word', 'anywhere' => 'Inside words too', 'regex' => 'Pattern'][PronunciationService::typeOf($r)] ?? 'Whole word';
$ready = $layer === 'default' || $ref !== '';
$where = match ($layer) {
    'set' => $currentSet ? (string) $currentSet['name'] : 'a ruleset',
    'voice' => 'the voice ' . $ref,
    default => 'every book and voice',
};
$field = 'display: grid; gap: 4px; font-size: 13px; font-weight: 600';
$usedBy = [];
if ($layer === 'set' && $currentSet) {
    foreach ($projects as $p) foreach ($svc->projectSets((string) $p['id']) as $s) if ($s['id'] === $currentSet['id']) $usedBy[] = $p;
}
?>
<div class="three" style="min-height: calc(100vh - 56px)">
  <div class="three-main" style="padding-top: 28px">
    <div class="page-head">
      <div>
        <h1>Shared rulesets</h1>
        <p>Pronunciation rules used by more than one book. A book's own rules win over a shared ruleset, which wins over a voice's rules, which win over everyone's defaults. Each book's own rules are on its Pronunciation page.</p>
      </div>
    </div>
    <nav class="tabs" aria-label="Kind of rules">
      <a href="<?= e($tabUrl('set')) ?>" <?= $layer === 'set' ? 'aria-current="page"' : '' ?>>Rulesets <span class="count"><?= count($sets) ?></span></a>
      <a href="<?= e($tabUrl('voice')) ?>" <?= $layer === 'voice' ? 'aria-current="page"' : '' ?>>For a voice</a>
      <a href="<?= e($tabUrl('default')) ?>" <?= $layer === 'default' ? 'aria-current="page"' : '' ?>>For every book</a>
    </nav>

    <?php if ($layer === 'set'): ?>
      <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px">
        <?php if ($sets): ?>
          <form method="get" action="<?= e(base_url('rulesets')) ?>" style="display: flex; gap: 8px; align-items: center">
            <input type="hidden" name="page" value="rulesets"><input type="hidden" name="layer" value="set">
            <label class="visually-hidden" for="set-pick">Ruleset</label>
            <select id="set-pick" name="ref" onchange="this.form.submit()" style="min-width: 260px">
              <?php foreach ($sets as $s): ?><option value="<?= e($s['id']) ?>" <?= $s['id'] === $ref ? 'selected' : '' ?>><?= e($s['name']) ?> · <?= (int) $s['rule_count'] ?> rules · <?= (int) $s['project_count'] ?> book<?= (int) $s['project_count'] === 1 ? '' : 's' ?></option><?php endforeach; ?>
            </select>
            <noscript><button class="btn-outline">Show</button></noscript>
          </form>
        <?php else: ?><p class="muted" style="margin: 0">No shared rulesets yet.</p><?php endif; ?>
        <details class="row-menu" style="margin-left: auto">
          <summary class="btn btn-dark" style="border: 0">New ruleset</summary>
          <div class="row-menu-pop" style="min-width: 300px; padding: 14px; gap: 12px">
            <form method="post" action="<?= e(base_url('rulesets')) ?>" style="display: grid; gap: 8px"><?= $hidden() ?><input type="hidden" name="action" value="create_set">
              <label style="<?= $field ?>">Name <input name="name" maxlength="100" placeholder="for example, a book series" required></label>
              <label style="<?= $field ?>">Description <input name="description" maxlength="300" placeholder="optional"></label>
              <button type="submit">Create ruleset</button></form>
            <?php if ($bundled): ?>
              <form method="post" action="<?= e(base_url('rulesets')) ?>" style="display: grid; gap: 8px; border-top: 1px solid var(--rule-2); padding-top: 12px"><?= $hidden() ?><input type="hidden" name="action" value="import_bundled">
                <label style="<?= $field ?>">Or start from a ready-made list <select name="bundled"><?php foreach ($bundled as $file => $name): ?><option value="<?= e($file) ?>"><?= e($name) ?></option><?php endforeach; ?></select></label>
                <button type="submit" class="btn-outline">Create from this list</button></form>
            <?php endif; ?>
          </div>
        </details>
      </div>
      <?php if ($currentSet): ?>
        <p class="muted" style="margin: 0 0 14px">
          <?= ($currentSet['description'] ?? '') !== '' ? e((string) $currentSet['description']) . ' · ' : '' ?>
          <?php if ($usedBy): ?>Used by <?= implode(', ', array_map(static fn (array $p): string => '<a href="' . e(base_url('pronounce', ['id' => $p['id'], 'tab' => 'shared'])) . '">' . e((string) $p['title']) . '</a>', $usedBy)) ?>. Editing it changes those books.
          <?php else: ?>Not used by any book yet; a book chooses it on its Pronunciation page, under Shared rulesets.<?php endif; ?>
        </p>
      <?php endif; ?>
    <?php elseif ($layer === 'voice'): ?>
      <form method="get" action="<?= e(base_url('rulesets')) ?>" style="display: flex; gap: 8px; align-items: center; margin-bottom: 14px; flex-wrap: wrap">
        <input type="hidden" name="page" value="rulesets"><input type="hidden" name="layer" value="voice">
        <label for="voice-pick" style="font-weight: 600">Rules used whenever this voice reads</label>
        <select id="voice-pick" name="ref" onchange="this.form.submit()"><?= $voiceOptions($voiceGroups, $ref) ?></select>
        <noscript><button class="btn-outline">Show</button></noscript>
      </form>
    <?php else: ?>
      <p class="muted" style="margin: 0 0 14px">Rules for every book and every voice, unless something more specific says otherwise.</p>
    <?php endif; ?>

    <?php if ($ready && ($layer !== 'set' || $currentSet)): ?>
      <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px">
        <label style="flex: 1 1 240px; display: flex"><span class="visually-hidden">Search rules</span><input type="search" data-rule-search placeholder="Search <?= count($rules) ?> rules in <?= e($where) ?>" style="width: 100%"></label>
        <button type="button" class="btn-dark" data-toggle-add aria-expanded="false">Add a rule</button>
      </div>

      <section class="card" data-add-panel hidden style="margin-bottom: 14px">
        <form method="post" action="<?= e(base_url('rulesets')) ?>" class="pron-add" id="add" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; align-items: end">
          <?= $hidden() ?><input type="hidden" name="action" value="add_rule"><input type="hidden" name="edit_id" value="">
          <p class="pron-editing" id="pron-editing" hidden style="grid-column: 1 / -1; margin: 0">Editing a rule. <button type="button" class="link-button" id="pron-edit-cancel">Cancel</button></p>
          <label style="<?= $field ?>">Written <input name="pattern" maxlength="400" required placeholder="Bajor" value="<?= e($prefillWritten) ?>"></label>
          <label style="<?= $field ?>">Spoken <input name="replacement" maxlength="200" placeholder="Bay-jor (empty: say nothing)" value="<?= e($prefillSpoken) ?>"></label>
          <label style="<?= $field ?>">Matches <select name="match_type"><option value="word">Whole word</option><option value="anywhere">Inside words too</option><option value="regex">Pattern</option></select></label>
          <label style="<?= $field ?>">Note <input name="note" maxlength="300" placeholder="optional"></label>
          <label style="display: flex; gap: 8px; align-items: center; font-size: 13px"><input type="checkbox" name="case_sensitive"> Capitals must match</label>
          <button type="submit" id="pron-save">Add or update</button>
        </form>
        <details class="regex-helper" id="regex-helper" data-url="<?= e(base_url('rulesets-regex')) ?>" data-project="" style="margin-top: 12px">
          <summary style="cursor: pointer; font-weight: 600">Pattern builder: find and change text without knowing regex</summary>
          <div class="rh-body" id="rh-mount"></div>
        </details>
      </section>

      <div class="table-box">
        <table class="table" data-rules>
          <thead><tr><th>Written</th><th>Spoken</th><th>Matches</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody>
          <?php foreach ($rules as $r): $off = (int) $r['enabled'] !== 1; ?>
            <tr id="rule-<?= (int) $r['id'] ?>" class="<?= $off ? 'is-off' : '' ?>" data-text="<?= e(mb_strtolower($r['pattern'] . ' ' . $r['replacement'] . ' ' . $r['note'])) ?>">
              <td><strong><?= e((string) $r['pattern']) ?></strong><?= $r['note'] !== '' ? '<br><span class="quiet">' . e((string) $r['note']) . '</span>' : '' ?></td>
              <td><?= $r['replacement'] === '' ? '<em class="quiet">says nothing</em>' : e((string) $r['replacement']) ?></td>
              <td class="quiet"><?= e($matchLabel($r)) ?><?= (int) $r['case_sensitive'] ? ', capitals' : '' ?><?= $off ? ' · switched off' : '' ?></td>
              <td style="text-align: right; white-space: nowrap">
                <button type="button" class="btn-soft btn-sm" data-wb-load data-written="<?= e((string) $r['pattern']) ?>" data-spoken="<?= e((string) $r['replacement']) ?>" data-layer="<?= e($layer . '|' . $ref) ?>" data-label="rule in <?= e($where) ?>" data-case="<?= (int) $r['case_sensitive'] ?>" data-whole="<?= (int) $r['whole_word'] ?>" data-match="<?= e(PronunciationService::typeOf($r)) ?>">Try</button>
                <details class="row-menu">
                  <summary class="btn-icon btn-sm" aria-label="More for <?= e((string) $r['pattern']) ?>"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></summary>
                  <div class="row-menu-pop">
                    <button type="button" class="link-button" data-edit-rule data-id="<?= (int) $r['id'] ?>" data-written="<?= e((string) $r['pattern']) ?>" data-spoken="<?= e((string) $r['replacement']) ?>" data-note="<?= e((string) $r['note']) ?>" data-case="<?= (int) $r['case_sensitive'] ?>" data-match="<?= e(PronunciationService::typeOf($r)) ?>">Edit</button>
                    <?php foreach (['top' => 'Move to the top', 'up' => 'Move up', 'down' => 'Move down'] as $how => $tip): ?>
                      <form method="post" action="<?= e(base_url('rulesets')) ?>"><?= $hidden() ?><input type="hidden" name="action" value="move_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="how" value="<?= $how ?>"><button type="submit" class="link-button"><?= $tip ?></button></form>
                    <?php endforeach; ?>
                    <form method="post" action="<?= e(base_url('rulesets')) ?>"><?= $hidden() ?><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="enabled" value="<?= $off ? '1' : '0' ?>"><button type="submit" class="link-button"><?= $off ? 'Switch on' : 'Switch off' ?></button></form>
                    <form method="post" action="<?= e(base_url('rulesets')) ?>" style="display: grid; gap: 6px; padding: 6px 8px; border-top: 1px solid var(--rule-2)"><?= $hidden() ?><input type="hidden" name="action" value="transfer_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <label class="quiet" style="display: grid; gap: 4px">Move or copy to <select name="to"><?= $destOptions ?></select></label>
                      <span style="display: flex; gap: 6px"><button type="submit" name="mode" value="copy" class="btn-outline btn-sm">Copy</button><button type="submit" name="mode" value="move" class="btn-outline btn-sm">Move</button></span></form>
                    <form method="post" action="<?= e(base_url('rulesets')) ?>" onsubmit="return confirm('Delete this rule?');"><?= $hidden() ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="link-button danger">Delete</button></form>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!$rules): ?><p class="muted" style="padding: 16px">No rules here yet.</p><?php endif; ?>
      </div>
      <?php if ($rules): ?>
        <p class="quiet" style="margin-top: 10px">When two rules match at the same place, the one higher in the list wins.
          <form method="post" action="<?= e(base_url('rulesets')) ?>" style="display: inline"><?= $hidden() ?><input type="hidden" name="action" value="reset_order"><button type="submit" class="link-button" style="font-size: 13px">Reset the order</button></form></p>
      <?php endif; ?>

      <div style="display: grid; gap: 14px; margin-top: 20px">
        <details class="card">
          <summary style="cursor: pointer; font-weight: 600">Import a list of rules</summary>
          <form method="post" action="<?= e(base_url('rulesets')) ?>" enctype="multipart/form-data" style="display: grid; gap: 10px; margin-top: 12px"><?= $hidden() ?><input type="hidden" name="action" value="import_json">
            <p class="muted" style="margin: 0">A JSON object of written to spoken forms, for example <code>{"Bajor": "Bay-jor"}</code>. Rules with the same written form here are updated.</p>
            <textarea name="json" rows="4" placeholder='{"Bajor": "Bay-jor"}'></textarea>
            <label style="<?= $field ?>">or a file <input type="file" name="json_file" accept=".json,application/json"></label>
            <div><button type="submit" class="btn-outline">Import into <?= e($where) ?></button></div>
          </form>
        </details>
        <details class="card">
          <summary style="cursor: pointer; font-weight: 600">Try a sentence</summary>
          <form method="get" action="<?= e(base_url('rulesets')) ?>#try" id="try" class="pron-test" data-set="<?= e($layer === 'set' ? $ref : '') ?>" data-endpoint="<?= e(base_url('voice-preview')) ?>" data-token="<?= e(Csrf::token()) ?>" style="display: grid; gap: 10px; margin-top: 12px">
            <input type="hidden" name="page" value="rulesets"><input type="hidden" name="layer" value="<?= e($layer) ?>"><input type="hidden" name="ref" value="<?= e($ref) ?>">
            <label style="<?= $field ?>">Text <textarea name="test" rows="3" maxlength="2000" placeholder="Sisko and Worf left Bajor."><?= e($testText) ?></textarea></label>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: end">
              <label style="<?= $field ?>">Voice <select name="test_voice"><?= $voiceOptions($voiceGroups, $testVoice) ?></select></label>
              <label style="<?= $field ?>">As in the book <select name="test_project"><option value="">No book</option><?php foreach ($projects as $p): ?><option value="<?= e($p['id']) ?>" <?= $p['id'] === $testProject ? 'selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?></select></label>
              <button type="submit" class="btn-outline">Show what is read</button>
              <button type="button" data-hear>Hear it</button>
            </div>
            <audio controls data-audio hidden></audio>
            <p class="quiet" data-status aria-live="polite" style="margin: 0"></p>
          </form>
          <?php if ($testResult): ?>
            <div style="margin-top: 14px">
              <h3>What is read</h3>
              <p class="reader" style="margin: 6px 0 12px"><?= e($testResult['text']) ?></p>
              <?php if ($testResult['hits']): ?>
                <h3>Rules that changed it</h3>
                <ul style="margin: 6px 0 0">
                  <?php foreach ($testResult['hits'] as $pattern => $count):
                      $pattern = (string) $pattern;
                      $rule = null;
                      foreach ($testResult['effective'] as $candidate) if ((string) $candidate['pattern'] === $pattern) { $rule = $candidate; break; }
                      $isCaps = str_starts_with($pattern, '[caps] ');
                      $word = $isCaps ? substr($pattern, 7) : $pattern; ?>
                    <li><strong><?= e($word) ?></strong> → <?= e($isCaps ? (string) (PronunciationService::normalCase($word) ?? $word) : (string) ($rule['replacement'] ?? '')) ?> <span class="quiet">× <?= (int) $count ?>, from <?= e($isCaps ? 'ALL-CAPS words read as words' : (string) ($rule['layer_label'] ?? '')) ?></span></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?><p class="muted">No rule changed it.</p><?php endif; ?>
            </div>
          <?php endif; ?>
        </details>
        <?php if ($layer === 'set' && $currentSet): ?>
          <details class="card" style="border-color: var(--red-line)">
            <summary style="cursor: pointer; font-weight: 600">Delete this ruleset</summary>
            <form method="post" action="<?= e(base_url('rulesets')) ?>" style="display: grid; gap: 10px; margin-top: 12px"><?= $hidden() ?><input type="hidden" name="action" value="delete_set">
              <p class="muted" style="margin: 0">Deletes “<?= e((string) $currentSet['name']) ?>” and its <?= count($rules) ?> rules<?= $usedBy ? ', and takes it away from ' . count($usedBy) . ' book' . (count($usedBy) === 1 ? '' : 's') : '' ?>. This cannot be undone.</p>
              <label style="display: flex; gap: 8px; align-items: center"><input type="checkbox" name="confirm" value="yes" required> Yes, delete it</label>
              <div><button type="submit" class="btn-danger">Delete the ruleset</button></div>
            </form>
          </details>
        <?php endif; ?>
      </div>
    <?php elseif ($layer === 'set' && !$sets): ?>
      <p class="card muted">Create a ruleset with New ruleset, for example one for a book series, or start from a ready-made list.</p>
    <?php endif; ?>
  </div>

  <aside class="three-side" aria-label="Sound test" style="padding-top: 24px">
    <h2 style="font-size: 16px; margin-bottom: 4px">Sound test</h2>
    <?php
      $wbProject = null;
      $wbSet = ($layer === 'set' && $ref !== '') ? $ref : null;     // hears the rest of the ruleset being edited
      $wbVoice = $layer === 'voice' && $ref !== '' ? $ref : \App\Services\VoiceCatalog::DEFAULT_VOICE;
      $wbSpeed = 1.0;
      $wbToken = Csrf::token();
      $wbLayers = [];
      if ($layer === 'default') $wbLayers[] = ['default|', 'Every book and voice'];
      elseif ($ref !== '') $wbLayers[] = [$layer . '|' . $ref, $layer === 'set' && $currentSet ? 'Ruleset: ' . $currentSet['name'] : 'Voice ' . $ref];
      $wbLayers[] = ['default|', 'Every book and voice'];
      $seen = [];
      $wbLayers = array_values(array_filter($wbLayers, static function (array $l) use (&$seen): bool { if (isset($seen[$l[0]])) return false; return $seen[$l[0]] = true; }));
      require APP_ROOT . '/app/Views/workbench.php';
    ?>
  </aside>
</div>
<script src="<?= e(base_url('assets/js/pronunciation.js')) ?>" defer></script>
<script src="<?= e(base_url('assets/js/regex-helper.js')) ?>" defer></script>
<script>
(function () {
  const table = document.querySelector('[data-rules]');
  const search = document.querySelector('[data-rule-search]');
  if (table && search) search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    table.querySelectorAll('tbody tr').forEach(tr => { tr.hidden = q !== '' && !(tr.dataset.text || '').includes(q); });
  });
  const toggle = document.querySelector('[data-toggle-add]');
  const panel = document.querySelector('[data-add-panel]');
  if (toggle && panel) {
    const show = on => { panel.hidden = !on; toggle.setAttribute('aria-expanded', String(on)); if (on) panel.querySelector('input[name=pattern]').focus(); };
    toggle.addEventListener('click', () => show(panel.hidden));
    if (panel.querySelector('input[name=pattern]').value !== '') show(true);
    document.addEventListener('click', e => { if (e.target.closest('[data-edit-rule]')) { panel.hidden = false; toggle.setAttribute('aria-expanded', 'true'); const menu = e.target.closest('details'); if (menu) menu.open = false; } });
  }
  if (location.hash === '#try' || document.querySelector('.reader')) { const d = document.getElementById('try'); if (d) d.closest('details').open = true; }
})();
</script>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
