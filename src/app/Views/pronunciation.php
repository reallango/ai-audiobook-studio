<?php
declare(strict_types=1);

use App\Core\Csrf;

$pageTitle = 'Pronunciation';
$projectNav = null;
if ($layer === 'project' && $ref !== '') foreach ($projects as $p) if ((string) $p['id'] === $ref) $projectNav = ['project' => $p];
require APP_ROOT . '/app/Views/layout-top.php';

$layerUrl = static fn (string $l, string $r = ''): string => base_url('pronunciation', ['layer' => $l, 'ref' => $r]);
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
$explain = [
    'default' => 'Used for every book and every voice, unless a more specific layer says otherwise.',
    'voice' => 'Used whenever this voice narrates. Overrides the default layer.',
    'set' => 'A reusable list for a series. Link it to projects: editing it changes every linked book. Overrides voice and default.',
    'project' => 'Only this book. Wins over linked series sets, voice and default.',
];
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/pronunciation.css')) ?>">
<section class="page-head">
  <div>
    <p class="eyebrow">NARRATION</p>
    <h1>Pronunciation</h1>
    <p class="lead compact">Written form to spoken form, applied before text goes to Kokoro. Precedence: <strong>project</strong> beats <strong>series set</strong> beats <strong>voice</strong> beats <strong>default</strong>.</p>
  </div>
</section>

<nav class="pron-tabs" aria-label="Layer">
  <?php foreach ($layers as $key => $label): ?>
    <a class="<?= $layer === $key ? 'active' : '' ?>" href="<?= e($layerUrl($key, $layer === $key ? $ref : '')) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<section class="form-panel pron-panel">
  <p class="pron-explain"><?= e($explain[$layer]) ?></p>

  <?php if ($layer === 'voice'): ?>
    <form method="get" action="<?= e(base_url('pronunciation')) ?>" class="pron-picker">
      <input type="hidden" name="page" value="pronunciation"><input type="hidden" name="layer" value="voice">
      <label>Voice <select name="ref" onchange="this.form.submit()"><?= $voiceOptions($voiceGroups, $ref) ?></select></label>
      <noscript><button>Show</button></noscript>
    </form>
  <?php elseif ($layer === 'set'): ?>
    <div class="pron-picker">
      <?php if ($sets): ?>
        <form method="get" action="<?= e(base_url('pronunciation')) ?>"><input type="hidden" name="page" value="pronunciation"><input type="hidden" name="layer" value="set">
          <label>Series set <select name="ref" onchange="this.form.submit()"><?php foreach ($sets as $s): ?><option value="<?= e($s['id']) ?>" <?= $s['id'] === $ref ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= (int) $s['rule_count'] ?> rules, <?= (int) $s['project_count'] ?> projects)</option><?php endforeach; ?></select></label>
        </form>
      <?php else: ?><p>No series sets yet.</p><?php endif; ?>
      <form method="post" class="pron-inline"><?= $hidden() ?><input type="hidden" name="action" value="create_set">
        <label>New set <input name="name" maxlength="100" placeholder="Star Trek DS9" required></label>
        <label>Description <input name="description" maxlength="300" placeholder="optional"></label>
        <button type="submit" class="button secondary">Create set</button>
      </form>
      <?php if ($bundled): ?>
        <form method="post" class="pron-inline"><?= $hidden() ?><input type="hidden" name="action" value="import_bundled">
          <label>Bundled list <select name="bundled"><?php foreach ($bundled as $file => $name): ?><option value="<?= e($file) ?>"><?= e($name) ?></option><?php endforeach; ?></select></label>
          <button type="submit" class="button secondary">Import as a set</button>
        </form>
      <?php endif; ?>
    </div>
  <?php elseif ($layer === 'project'): ?>
    <?php if ($projects): ?>
      <form method="get" action="<?= e(base_url('pronunciation')) ?>" class="pron-picker"><input type="hidden" name="page" value="pronunciation"><input type="hidden" name="layer" value="project">
        <label>Project <select name="ref" onchange="this.form.submit()"><?php foreach ($projects as $p): ?><option value="<?= e($p['id']) ?>" <?= $p['id'] === $ref ? 'selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?></select></label>
      </form>
    <?php else: ?><p>Create a project first.</p><?php endif; ?>
  <?php endif; ?>

  <?php if ($layer === 'project' && $ref !== ''): ?>
    <div class="pron-linked">
      <h2>Linked series sets</h2>
      <?php if ($projectSets): ?>
        <ul>
          <?php foreach ($projectSets as $ps): ?>
            <li><a href="<?= e($layerUrl('set', (string) $ps['id'])) ?>"><?= e($ps['name']) ?></a>
              <form method="post" class="pron-inline"><?= $hidden() ?><input type="hidden" name="action" value="detach_set"><input type="hidden" name="set_id" value="<?= e($ps['id']) ?>"><button type="submit" class="link-button">unlink</button></form>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="pron-note">Later sets in the list override earlier ones.</p>
      <?php else: ?><p>None linked.</p><?php endif; ?>
      <?php $linkedIds = array_column($projectSets, 'id'); $available = array_filter($sets, static fn (array $s): bool => !in_array($s['id'], $linkedIds, true)); ?>
      <?php if ($available): ?>
        <form method="post" class="pron-inline"><?= $hidden() ?><input type="hidden" name="action" value="attach_set">
          <label>Link a set <select name="set_id"><?php foreach ($available as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
          <button type="submit" class="button secondary">Link</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php $ready = $layer === 'default' || $ref !== ''; ?>
  <?php if ($ready && ($layer !== 'set' || $currentSet)): ?>
    <h2 class="pron-h2"><?= e($layers[$layer]) ?> rules<?= $layer === 'voice' ? ' for ' . e($ref) : '' ?><?= $currentSet ? ': ' . e($currentSet['name']) : '' ?> <small>(<?= count($rules) ?>)</small></h2>

    <form method="post" class="pron-add" id="add"><?= $hidden() ?><input type="hidden" name="action" value="add_rule">
      <label>Written <input name="pattern" maxlength="100" required placeholder="Bajor" value="<?= e($prefillWritten) ?>"></label>
      <label>Spoken <small>(empty = say nothing)</small> <input name="replacement" maxlength="200" placeholder="Bay-jor" value="<?= e($prefillSpoken) ?>"></label>
      <label>Note <input name="note" maxlength="300" placeholder="optional"></label>
      <label class="check"><input type="checkbox" name="whole_word" checked> whole word</label>
      <label class="check"><input type="checkbox" name="case_sensitive"> match case</label>
      <button type="submit">Add or update</button>
    </form>

    <?php if ($rules): ?>
      <table class="pron-table">
        <thead><tr><th>Written</th><th>Spoken</th><th>Matching</th><th>Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
          <tr class="<?= (int) $r['enabled'] ? '' : 'off' ?>">
            <td><code><?= e($r['pattern']) ?></code></td>
            <td><?php if ($r['replacement'] === ''): ?><em>(removed from narration)</em><?php else: ?><code><?= e($r['replacement']) ?></code><?php endif; ?></td>
            <td><?= (int) $r['whole_word'] ? 'whole word' : 'anywhere' ?>, <?= (int) $r['case_sensitive'] ? 'match case' : 'any case' ?></td>
            <td><?= e($r['note']) ?></td>
            <td class="pron-actions">
              <form method="post"><?= $hidden() ?><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="enabled" value="<?= (int) $r['enabled'] ? '0' : '1' ?>"><button type="submit" class="link-button"><?= (int) $r['enabled'] ? 'disable' : 'enable' ?></button></form>
              <form method="post"><?= $hidden() ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="link-button danger">delete</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="pron-empty">No rules in this layer yet.</p>
    <?php endif; ?>

    <details class="pron-import">
      <summary>Import a JSON list</summary>
      <form method="post" enctype="multipart/form-data"><?= $hidden() ?><input type="hidden" name="action" value="import_json">
        <p>An object of written to spoken forms, for example <code>{"Bajor": "Bay-jor", "Sisko": "Siss-ko"}</code>. Existing patterns in this layer are updated.</p>
        <textarea name="json" rows="5" placeholder='{"Bajor": "Bay-jor"}'></textarea>
        <label>or a file <input type="file" name="json_file" accept=".json,application/json"></label>
        <button type="submit" class="button secondary">Import into this layer</button>
      </form>
    </details>

    <?php if ($currentSet): ?>
      <details class="pron-import">
        <summary>Delete this series set</summary>
        <form method="post"><?= $hidden() ?><input type="hidden" name="action" value="delete_set">
          <p>Deletes the set and its <?= count($rules) ?> rules, and unlinks it from <?= (int) array_sum(array_column(array_filter($sets, static fn (array $s): bool => $s['id'] === $currentSet['id']), 'project_count')) ?> project(s).</p>
          <label class="check"><input type="checkbox" name="confirm" value="yes"> Yes, delete it</label>
          <button type="submit" class="danger">Delete set</button>
        </form>
      </details>
    <?php endif; ?>
  <?php elseif ($layer === 'set' && !$sets): ?>
    <p class="pron-empty">Create a series set above, or import the bundled list.</p>
  <?php endif; ?>
</section>

<?php if ($layer === 'project' && $ref !== ''): ?>
<section class="form-panel pron-panel" id="caps">
  <h2 class="pron-h2">ALL-CAPS words in this book <small>(<?= count($capsAudit) ?><?= count($capsAudit) === 80 ? '+' : '' ?>)</small></h2>
  <p class="pron-explain">Kokoro spells ALL-CAPS words letter by letter.
    <?= $capsOn ? 'Here they are turned into normal words (STARBASE becomes Starbase) unless a rule covers them. Initialisms that should be spelled, like USS or FBI, need a rule such as USS to U.S.S.' : 'Turning them into normal words is switched off for this project, so every word below is spelled out unless a rule covers it.' ?></p>
  <?php if ($capsAudit): ?>
    <table class="pron-table">
      <thead><tr><th>Word</th><th>Times</th><th>Becomes</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($capsAudit as $row): ?>
        <tr>
          <td><code><?= e($row['token']) ?></code></td>
          <td><?= (int) $row['count'] ?></td>
          <td><?= $row['becomes'] === null ? '<em>left as written (a rule decides)</em>' : ($capsOn ? '<code>' . e($row['becomes']) . '</code>' : '<em>spelled out</em>') ?></td>
          <td><a href="<?= e(base_url('pronunciation', ['layer' => 'project', 'ref' => $ref, 'p' => $row['token'], 's' => $row['becomes'] ?? $row['token']])) ?>#add">make a rule</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?><p class="pron-empty">No uncovered ALL-CAPS words.</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="form-panel pron-panel" id="test">
  <h2 class="pron-h2">Try it</h2>
  <p class="pron-explain">Shows what Kokoro will be sent for a voice and project, with the layer each rule came from.</p>
  <form method="get" action="<?= e(base_url('pronunciation')) ?>#test" class="pron-test"
        data-endpoint="<?= e(base_url('voice-preview')) ?>" data-token="<?= e(Csrf::token()) ?>">
    <input type="hidden" name="page" value="pronunciation"><input type="hidden" name="layer" value="<?= e($layer) ?>"><input type="hidden" name="ref" value="<?= e($ref) ?>">
    <label>Text <textarea name="test" rows="3" maxlength="2000" placeholder="Sisko and Worf left Bajor."><?= e($testText) ?></textarea></label>
    <div class="pron-test-row">
      <label>Voice <select name="test_voice"><?= $voiceOptions($voiceGroups, $testVoice) ?></select></label>
      <label>Project <select name="test_project"><option value="">None</option><?php foreach ($projects as $p): ?><option value="<?= e($p['id']) ?>" <?= $p['id'] === $testProject ? 'selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?></select></label>
      <button type="submit" class="button secondary">Show rewritten text</button>
      <button type="button" data-hear>Hear it</button>
    </div>
    <audio controls data-audio hidden></audio>
    <p class="pron-status" data-status aria-live="polite"></p>
  </form>
  <?php if ($testResult): ?>
    <div class="pron-result">
      <h3>Sent to Kokoro</h3>
      <p class="pron-spoken"><?= e($testResult['text']) ?></p>
      <?php if ($testResult['hits']): ?>
        <h3>Rules that fired</h3>
        <ul>
          <?php foreach ($testResult['hits'] as $pattern => $count): $rule = $testResult['effective'][mb_strtolower((string) $pattern)] ?? null; ?>
            <li><code><?= e((string) $pattern) ?></code> → <code><?= e((string) ($rule['replacement'] ?? '')) ?></code> × <?= (int) $count ?> <small>from <?= e((string) ($rule['layer_label'] ?? '')) ?></small></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><p>No rules matched.</p><?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<script src="<?= e(base_url('assets/js/pronunciation.js')) ?>" defer></script>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
