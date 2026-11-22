<?php
declare(strict_types=1);

use App\Services\TextCheckService;

/*
 * Text checks: things in the book a voice may read badly. Expects $project, $kind, $rows, $hasDictionary, $projectSets, $voice.
 * Each row has the same sound test bar as the rule pages, so a word can be tried and saved as a rule from here.
 */
$pageTitle = 'Text checks';
$projectNav = ['project' => $project];
require APP_ROOT . '/app/Views/layout-top.php';
$projectId = (string) $project['id'];
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/pronunciation.css')) ?>">
<section class="page-head">
  <div>
    <p class="eyebrow">NARRATION</p>
    <h1>Text checks</h1>
    <p class="lead compact">Looks through the book for text a voice may read badly, so you can try it, hear it and turn it into a rule. Anything a rule already changes is left out.</p>
  </div>
</section>

<div class="review-layout">
<div class="review-main">
<section class="form-panel pron-panel">
  <form method="get" action="<?= e(base_url('textchecks')) ?>" class="pron-picker">
    <input type="hidden" name="page" value="textchecks"><input type="hidden" name="id" value="<?= e($projectId) ?>">
    <label>Look for
      <select name="kind" onchange="this.form.submit()">
        <?php foreach (TextCheckService::KINDS as $key => [$label]): ?>
          <option value="<?= e($key) ?>" <?= $key === $kind ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript><button type="submit" class="button secondary">Show</button></noscript>
  </form>
  <p class="pron-explain"><?= e(TextCheckService::KINDS[$kind][1]) ?></p>
  <?php if ($kind === 'proper' && !$hasDictionary): ?>
    <p class="pron-explain"><strong>No word list is installed here,</strong> so common words cannot be ruled out. Only capitalised words inside a sentence are shown, and ordinary names such as Paris will appear too.</p>
  <?php endif; ?>

  <h2 class="pron-h2"><?= e(TextCheckService::KINDS[$kind][0]) ?> <small>(<?= count($rows) ?><?= count($rows) >= 150 ? '+' : '' ?>)</small></h2>
  <?php if ($rows): ?>
    <table class="pron-table">
      <thead><tr><th>Text</th><th>Kind</th><th>Times</th><th>Reads as</th><th>Where it is</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><code><?= e($row['text']) ?></code></td>
          <td><?= e($row['what']) ?></td>
          <td><?= (int) $row['count'] ?></td>
          <td><?= $row['becomes'] === $row['text'] ? '<em>as written</em>' : '<code>' . e($row['becomes']) . '</code>' ?></td>
          <td class="tc-example"><?= e($row['example']) ?></td>
          <td class="pron-actions"><button type="button" class="link-button" data-wb-load data-written="<?= e($row['text']) ?>" data-spoken="<?= e($row['becomes']) ?>" data-layer="project|<?= e($projectId) ?>" data-label="<?= e($row['what']) ?>" data-case="1" data-whole="1">try</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="pron-empty">Nothing of this kind needs a decision in this book.</p>
  <?php endif; ?>
</section>
</div>
<?php
    $wbProject = $projectId;
    $wbSet = null;
    $wbVoice = $voice;
    $wbSpeed = (float) ($project['tts_speed'] ?? 1.0);
    $wbToken = \App\Core\Csrf::token();
    $wbLayers = [['project|' . $projectId, 'This project']];
    foreach ($projectSets as $ps) $wbLayers[] = ['set|' . $ps['id'], 'Ruleset: ' . $ps['name']];
    $wbLayers[] = ['voice|' . $voice, 'Voice ' . $voice . ', every book'];
    $wbLayers[] = ['default|', 'Default, every book and voice'];
    require APP_ROOT . '/app/Views/workbench.php';
?>
</div>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
