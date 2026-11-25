<?php
declare(strict_types=1);

use App\Services\PronunciationService;
use App\Services\TextCheckService;

/*
 * A book's pronunciation. Expects $project, $tab (rules|suggestions|shared), $own (this book's rules), $inherited (rules
 * from rulesets, the voice and the defaults that apply), $voice, $kind, $suggestions, $kindCounts, $hasDictionary,
 * $linked, $available.
 */
$pageTitle = 'Pronunciation · ' . $project['title'];
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';

$pid = (string) $project['id'];
$archived = ($project['status'] ?? '') === 'ARCHIVED';
$hidden = static fn (string $tab = 'rules'): string => csrf_field() . '<input type="hidden" name="layer" value="project"><input type="hidden" name="ref" value="' . e($pid) . '">'
    . '<input type="hidden" name="return" value="pronounce"><input type="hidden" name="tab" value="' . e($tab) . '">';
$tabUrl = static fn (string $t, array $extra = []): string => base_url('pronounce', ['id' => $pid, 'tab' => $t] + $extra);
$matchLabel = static fn (array $r): string => ['word' => 'Whole word', 'anywhere' => 'Inside words too', 'regex' => 'Pattern'][PronunciationService::typeOf($r)] ?? 'Whole word';
$fromTag = static function (array $r): array {
    return match ($r['layer'] ?? '') {
        'set' => [preg_replace('/^Ruleset /', '', (string) $r['layer_label']), 'purple'],
        'voice' => ['Voice ' . ($r['scope_ref'] ?? ''), ''],
        'default' => ['Every book', ''],
        default => ['This book', 'blue'],
    };
};
?>
<div class="three" style="min-height: calc(100vh - 56px)">
  <div class="three-main" style="padding-top: 28px">
    <div class="page-head">
      <div>
        <h1>Pronunciation</h1>
        <p>How this book's words are spoken. This book's rules win over shared rulesets, which win over the voice's and everyone's defaults.</p>
      </div>
    </div>
    <nav class="tabs" aria-label="Pronunciation">
      <a href="<?= e($tabUrl('rules')) ?>" <?= $tab === 'rules' ? 'aria-current="page"' : '' ?>>Rules <span class="count"><?= count($own) + count($inherited) ?></span></a>
      <a href="<?= e($tabUrl('suggestions')) ?>" <?= $tab === 'suggestions' ? 'aria-current="page"' : '' ?>>Suggestions from the text</a>
      <a href="<?= e($tabUrl('shared')) ?>" <?= $tab === 'shared' ? 'aria-current="page"' : '' ?>>Shared rulesets <span class="count"><?= count($linked) ?></span></a>
    </nav>

  <?php if ($tab === 'rules'): ?>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px">
      <label style="flex: 1 1 240px; display: flex"><span class="visually-hidden">Search rules</span><input type="search" data-rule-search placeholder="Search <?= count($own) + count($inherited) ?> rules" style="width: 100%"></label>
      <div class="chips" role="group" aria-label="Show">
        <button type="button" class="chip" aria-pressed="true" data-rule-filter="all">All</button>
        <button type="button" class="chip" aria-pressed="false" data-rule-filter="book">This book</button>
        <button type="button" class="chip" aria-pressed="false" data-rule-filter="inherited">From rulesets and defaults</button>
        <button type="button" class="chip" aria-pressed="false" data-rule-filter="regex">Patterns</button>
      </div>
      <?php if (!$archived): ?><button type="button" class="btn-dark" data-toggle-add aria-expanded="false">Add a rule</button><?php endif; ?>
    </div>

    <?php if (!$archived): ?>
    <section class="card" data-add-panel hidden style="margin-bottom: 14px">
      <form method="post" action="<?= e(base_url('rulesets')) ?>" class="pron-add" id="add" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; align-items: end">
        <?= $hidden() ?><input type="hidden" name="action" value="add_rule"><input type="hidden" name="edit_id" value="">
        <p class="pron-editing" id="pron-editing" hidden style="grid-column: 1 / -1; margin: 0">Editing a rule. <button type="button" class="link-button" id="pron-edit-cancel">Cancel</button></p>
        <label style="display: grid; gap: 4px; font-size: 13px; font-weight: 600">Written <input name="pattern" maxlength="400" required placeholder="Habana"></label>
        <label style="display: grid; gap: 4px; font-size: 13px; font-weight: 600">Spoken <input name="replacement" maxlength="200" placeholder="Ha-BAH-nah (empty: say nothing)"></label>
        <label style="display: grid; gap: 4px; font-size: 13px; font-weight: 600">Matches <select name="match_type"><option value="word">Whole word</option><option value="anywhere">Inside words too</option><option value="regex">Pattern</option></select></label>
        <label style="display: grid; gap: 4px; font-size: 13px; font-weight: 600">Note <input name="note" maxlength="300" placeholder="optional"></label>
        <label style="display: flex; gap: 8px; align-items: center; font-size: 13px"><input type="checkbox" name="case_sensitive"> Capitals must match</label>
        <button type="submit" id="pron-save">Add or update</button>
      </form>
      <details class="regex-helper" id="regex-helper" data-url="<?= e(base_url('rulesets-regex')) ?>" data-project="<?= e($pid) ?>" style="margin-top: 12px">
        <summary style="cursor: pointer; font-weight: 600">Pattern builder: find and change text without knowing regex</summary>
        <div class="rh-body" id="rh-mount"></div>
      </details>
    </section>
    <?php endif; ?>

    <div class="table-box">
      <table class="table" data-rules>
        <thead><tr><th>Written</th><th>Spoken</th><th>Matches</th><th>From</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($own as $r): $off = (int) $r['enabled'] !== 1; ?>
          <tr id="rule-<?= (int) $r['id'] ?>" class="<?= $off ? 'is-off' : '' ?>" data-from="book" data-match="<?= e(PronunciationService::typeOf($r)) ?>" data-text="<?= e(mb_strtolower($r['pattern'] . ' ' . $r['replacement'] . ' ' . $r['note'])) ?>">
            <td><strong><?= e((string) $r['pattern']) ?></strong><?= $r['note'] !== '' ? '<br><span class="quiet">' . e((string) $r['note']) . '</span>' : '' ?></td>
            <td><?= $r['replacement'] === '' ? '<em class="quiet">says nothing</em>' : e((string) $r['replacement']) ?></td>
            <td class="quiet"><?= e($matchLabel($r)) ?><?= (int) $r['case_sensitive'] ? ', capitals' : '' ?><?= $off ? ' · switched off' : '' ?></td>
            <td><span class="tag blue">This book</span></td>
            <td style="text-align: right; white-space: nowrap">
              <button type="button" class="btn-soft btn-sm" data-wb-load data-written="<?= e((string) $r['pattern']) ?>" data-spoken="<?= e((string) $r['replacement']) ?>" data-layer="project|<?= e($pid) ?>" data-label="rule for this book" data-case="<?= (int) $r['case_sensitive'] ?>" data-whole="<?= (int) $r['whole_word'] ?>" data-match="<?= e(PronunciationService::typeOf($r)) ?>">Try</button>
              <?php if (!$archived): ?>
              <details class="row-menu">
                <summary class="btn-icon btn-sm" aria-label="More for <?= e((string) $r['pattern']) ?>"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></summary>
                <div class="row-menu-pop">
                  <button type="button" class="link-button" data-edit-rule data-id="<?= (int) $r['id'] ?>" data-written="<?= e((string) $r['pattern']) ?>" data-spoken="<?= e((string) $r['replacement']) ?>" data-note="<?= e((string) $r['note']) ?>" data-case="<?= (int) $r['case_sensitive'] ?>" data-match="<?= e(PronunciationService::typeOf($r)) ?>">Edit</button>
                  <?php foreach (['top' => 'Move to the top', 'up' => 'Move up', 'down' => 'Move down'] as $how => $tip): ?>
                    <form method="post" action="<?= e(base_url('rulesets')) ?>"><?= $hidden() ?><input type="hidden" name="action" value="move_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="how" value="<?= $how ?>"><button type="submit" class="link-button"><?= $tip ?></button></form>
                  <?php endforeach; ?>
                  <form method="post" action="<?= e(base_url('rulesets')) ?>"><?= $hidden() ?><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="enabled" value="<?= $off ? '1' : '0' ?>"><button type="submit" class="link-button"><?= $off ? 'Switch on' : 'Switch off' ?></button></form>
                  <?php if ($linked): ?>
                    <form method="post" action="<?= e(base_url('rulesets')) ?>"><?= $hidden() ?><input type="hidden" name="action" value="transfer_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="mode" value="move">
                      <label class="quiet" style="display: grid; gap: 4px">Move to a ruleset <select name="to"><?php foreach ($linked as $s): ?><option value="set|<?= e((string) $s['id']) ?>"><?= e((string) $s['name']) ?></option><?php endforeach; ?></select></label>
                      <button type="submit" class="btn-outline btn-sm">Move</button></form>
                  <?php endif; ?>
                  <form method="post" action="<?= e(base_url('rulesets')) ?>" onsubmit="return confirm('Delete this rule?');"><?= $hidden() ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="link-button danger">Delete</button></form>
                </div>
              </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($inherited as $r): [$from, $tone] = $fromTag($r); ?>
          <tr data-from="inherited" data-match="<?= e(PronunciationService::typeOf($r)) ?>" data-text="<?= e(mb_strtolower($r['pattern'] . ' ' . $r['replacement'] . ' ' . $from)) ?>">
            <td><strong><?= e((string) $r['pattern']) ?></strong></td>
            <td><?= $r['replacement'] === '' ? '<em class="quiet">says nothing</em>' : e((string) $r['replacement']) ?></td>
            <td class="quiet"><?= e($matchLabel($r)) ?><?= (int) $r['case_sensitive'] ? ', capitals' : '' ?></td>
            <td><span class="tag <?= $tone ?>"><?= e($from) ?></span></td>
            <td style="text-align: right; white-space: nowrap">
              <button type="button" class="btn-soft btn-sm" data-wb-load data-written="<?= e((string) $r['pattern']) ?>" data-spoken="<?= e((string) $r['replacement']) ?>" data-layer="project|<?= e($pid) ?>" data-label="<?= e($from) ?>: save a change as this book's own rule" data-case="<?= (int) $r['case_sensitive'] ?>" data-whole="<?= (int) $r['whole_word'] ?>" data-match="<?= e(PronunciationService::typeOf($r)) ?>">Try</button>
              <a class="btn btn-outline btn-sm" href="<?= e(base_url('rulesets', ['layer' => (string) $r['layer'], 'ref' => (string) ($r['scope_ref'] ?? '')]) . '#rule-' . (int) ($r['id'] ?? 0)) ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$own && !$inherited): ?><p class="muted" style="padding: 16px">No rules yet. Add one, or look at the suggestions from the text.</p><?php endif; ?>
    </div>
    <p class="quiet" style="margin-top: 10px">When two rules match at the same place, the one higher in the list wins. Use a rule's menu to move it.</p>
  <?php endif; ?>

  <?php if ($tab === 'suggestions'): ?>
    <?php $found = $checkResults['found']; $missing = $checkResults['missing']; $ordinary = $checkResults['ordinary'] ?? []; $already = $checkResults['already'] ?? [];
      $voiceTone = static fn (?float $s, int $min): string => $s === null ? '' : ($s >= $min ? 'green' : ($s >= $min - 20 ? 'amber' : 'red')); ?>
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 14px">
      <nav class="segmented" aria-label="Suggestions" style="min-width: 320px">
        <a href="<?= e($tabUrl('suggestions')) ?>" <?= !$online ? 'aria-current="page"' : '' ?>>From the text</a>
        <a href="<?= e($tabUrl('suggestions', ['view' => 'online'])) ?>" <?= $online ? 'aria-current="page"' : '' ?>>Checked online<?= $found || $missing ? ' (' . count($found) . ' to fix)' : '' ?></a>
      </nav>
      <?php if (!$archived): ?>
        <form method="post" action="<?= e(base_url('pron-check')) ?>" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-left: auto">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="start">
          <input type="hidden" name="kinds[]" value="proper"><input type="hidden" name="kinds[]" value="odd">
          <label class="quiet" style="display: flex; gap: 6px; align-items: center" title="Which accent to prefer when a source gives several">Accent
            <select name="region" style="width: auto">
              <?php foreach (\App\Services\PronCheckService::REGIONS as $code => $label): ?><option value="<?= e($code) ?>" <?= ($checkRegion ?? 'americas') === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></label>
          <label class="quiet" style="display: flex; gap: 6px; align-items: center" title="What the book is about, for example Star Trek. Names are looked up with it first, so Bajor is the planet, not a Hungarian word. Leave empty for a book set in the real world.">About
            <input type="text" name="subject" maxlength="80" value="<?= e((string) ($checkSubject ?? '')) ?>" placeholder="e.g. Star Trek" style="width: 140px"></label>
          <?php if ($found || $missing || $ordinary || $already): ?><label class="quiet" style="display: flex; gap: 6px; align-items: center"><input type="checkbox" name="recheck" value="1"> check the old ones again</label><?php endif; ?>
          <button type="submit" <?= $checkRunning ? 'disabled' : '' ?> title="Looks up names and odd spellings on Wiktionary and Wikipedia, about one word a second, in the background"><?= $checkRunning ? 'Checking online…' : 'Check pronunciations online' ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'suggestions' && $online): ?>
    <?php if ($checkRunning): ?><p class="notice" style="margin: 0 0 14px">Checking now. The Activity button shows how far it is; refresh this page for the results so far.</p><?php endif; ?>
    <?php if (($found || $already) && !$archived): ?>
      <div class="card" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; padding: 12px 16px; margin: 0 0 14px">
        <?php if ($checkerReady ?? false): ?>
          <span class="quiet" style="flex: 1 1 280px">“Voice now” compares how the voice reads each word with what was found online, without making audio. At <?= (int) $checkMinimum ?> or above it counts as already right.</span>
          <form method="post" action="<?= e(base_url('pron-check')) ?>" style="display: flex; gap: 6px; align-items: center"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="minimum">
            <label class="quiet" style="display: flex; gap: 6px; align-items: center">Good enough at <input type="number" name="minimum" min="50" max="100" value="<?= (int) $checkMinimum ?>" style="width: 72px"></label><button type="submit" class="btn-soft btn-sm">Set</button></form>
          <form method="post" action="<?= e(base_url('pron-check')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="compare"><button type="submit" class="btn-soft btn-sm" <?= $checkRunning ? 'disabled' : '' ?> title="Asks the pronunciation checker again, for example after changing the book's voice">Compare with the voice</button></form>
        <?php else: ?>
          <span class="quiet">Set up the pronunciation checker in <a href="<?= e(base_url('diagnostics')) ?>">Diagnostics</a> to see which of these the voice already says right, and get plain spellings for the rest.</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (!$found && !$missing && !$ordinary && !$already && !$checkRunning): ?>
      <p class="card muted">Nothing checked yet. “Check pronunciations online” looks up this book's names and odd spellings on Wiktionary and Wikipedia (using the book's title to find the right page), and suggests a rule for each one it finds.</p>
    <?php endif; ?>
    <?php if ($found): $high = count(array_filter($found, static fn (array $r): bool => $r['confidence'] === 'high')); ?>
      <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin: 4px 0 10px">
        <h2 style="font-size: 16px">Suggested rules <span class="count quiet"><?= count($found) ?></span></h2>
        <?php if (!$archived && $high > 0): ?>
          <form method="post" action="<?= e(base_url('pron-check')) ?>" onsubmit="return confirm('Make <?= $high ?> rule<?= $high === 1 ? '' : 's' ?> from the high-confidence results?');">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="make_all"><input type="hidden" name="confidence" value="high">
            <button type="submit" class="btn-dark btn-sm">Make all <?= $high ?> high-confidence rules</button></form>
        <?php endif; ?>
      </div>
      <p class="quiet" style="margin: 0 0 10px">Make rule tells the voice the exact sounds found online; where there is one, a plain spelling is an alternative that reads closest. Try before making a rule: a source can be about something else with the same name.</p>
      <div class="table-box">
        <table class="table">
          <thead><tr><th>Word</th><th>Voice now</th><th>Sounds like</th><th>Confidence</th><th>Source</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody>
          <?php foreach ($found as $r): $tone = ['high' => 'green', 'medium' => 'amber', 'low' => 'red'][$r['confidence']] ?? ''; ?>
            <tr>
              <td><strong><?= e((string) $r['word']) ?></strong></td>
              <td><?php if ($r['voice_score'] !== null): ?><span class="tag <?= $voiceTone((float) $r['voice_score'], (int) $checkMinimum) ?>"><?= (int) round((float) $r['voice_score']) ?></span><br><span class="quiet">says <?= e(\App\Services\IpaConverter::respell((string) $r['voice_reads'])) ?></span><?php else: ?><span class="quiet">–</span><?php endif; ?></td>
              <td><?= e((string) $r['respelling']) ?><br><span class="quiet" style="font-family: var(--serif)">/<?= e((string) $r['ipa'] !== '' ? trim((string) $r['ipa'], '/[]') : (string) $r['sounds']) ?>/</span><?php $accent = trim((string) ($r['accent'] ?? '')); ?><?php if ($r['lang'] !== '' && ($r['lang'] !== 'en' || $accent !== '')): ?><br><span class="quiet"><?= e(\App\Services\PronLookup::languageName((string) $r['lang'])) ?><?= $accent !== '' ? ', ' . e(mb_strimwidth($accent, 0, 60, '…')) : '' ?></span><?php endif; ?>
                <?php if ((string) $r['alt_spelling'] !== ''): ?>
                  <div style="margin-top: 6px">Or write it <strong>“<?= e((string) $r['alt_spelling']) ?>”</strong> <span class="tag <?= $voiceTone((float) $r['alt_score'], (int) $checkMinimum) ?>"><?= (int) round((float) $r['alt_score']) ?></span>
                    <button type="button" class="btn-link" style="font-size: 13px" data-wb-load data-written="<?= e((string) $r['word']) ?>" data-spoken="<?= e((string) $r['alt_spelling']) ?>" data-layer="project|<?= e($pid) ?>" data-label="plain spelling" data-case="<?= (int) ($r['common'] ?? 0) === 1 ? '0' : '1' ?>" data-whole="1">Try</button>
                    <?php if (!$archived): ?><form method="post" action="<?= e(base_url('pron-check')) ?>" style="display: inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="make"><input type="hidden" name="spelling" value="1"><input type="hidden" name="word" value="<?= e((string) $r['word']) ?>"><button type="submit" class="btn-link" style="font-size: 13px">Use this spelling</button></form><?php endif; ?>
                  </div>
                <?php endif; ?></td>
              <td><span class="tag <?= $tone ?>"><?= e(ucfirst((string) $r['confidence'])) ?></span></td>
              <td><a href="<?= e((string) $r['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $r['source_title']) ?></a><?= $r['note'] !== '' ? '<br><span class="quiet">' . e((string) $r['note']) . '</span>' : '' ?></td>
              <td style="text-align: right; white-space: nowrap">
                <button type="button" class="btn-soft btn-sm" data-wb-load data-written="<?= e((string) $r['word']) ?>" data-spoken="<?= e((string) $r['spoken']) ?>" data-layer="project|<?= e($pid) ?>" data-label="found online: <?= e((string) $r['respelling']) ?>" data-case="<?= (int) ($r['common'] ?? 0) === 1 ? '0' : '1' ?>" data-whole="1">Try</button>
                <?php if (!$archived): ?>
                  <form method="post" action="<?= e(base_url('pron-check')) ?>" style="display: inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="make"><input type="hidden" name="word" value="<?= e((string) $r['word']) ?>"><button type="submit" class="btn-sm">Make rule</button></form>
                  <form method="post" action="<?= e(base_url('pron-check')) ?>" style="display: inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="dismiss"><input type="hidden" name="word" value="<?= e((string) $r['word']) ?>"><button type="submit" class="btn-link" style="font-size: 13px">Dismiss</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php if ($already): ?>
      <details style="margin: 22px 0 0">
        <summary style="cursor: pointer"><strong>The voice already says these</strong> <span class="count quiet"><?= count($already) ?></span> <span class="quiet">· its reading scores <?= (int) $checkMinimum ?> or more against what was found online</span></summary>
        <div class="chips" style="margin-top: 10px">
          <?php foreach ($already as $r): ?>
            <span style="display: inline-flex; gap: 2px; align-items: center">
              <button type="button" class="chip" title="Says <?= e(\App\Services\IpaConverter::respell((string) $r['voice_reads'])) ?> (<?= (int) round((float) $r['voice_score']) ?>); found online: <?= e((string) $r['respelling']) ?>" data-wb-load data-written="<?= e((string) $r['word']) ?>" data-spoken="<?= e((string) $r['spoken']) ?>" data-layer="project|<?= e($pid) ?>" data-label="found online: <?= e((string) $r['respelling']) ?>" data-case="<?= (int) ($r['common'] ?? 0) === 1 ? '0' : '1' ?>" data-whole="1"><?= e((string) $r['word']) ?> <span class="quiet"><?= (int) round((float) $r['voice_score']) ?></span></button>
              <?php if (!$archived): ?><form method="post" action="<?= e(base_url('pron-check')) ?>" style="display: inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="dismiss"><input type="hidden" name="word" value="<?= e((string) $r['word']) ?>"><button type="submit" class="btn-link" aria-label="Dismiss <?= e((string) $r['word']) ?>" title="Dismiss" style="font-size: 13px; padding: 0 4px">×</button></form><?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($ordinary): ?>
      <details style="margin: 22px 0 0">
        <summary style="cursor: pointer"><strong>Ordinary English words</strong> <span class="count quiet"><?= count($ordinary) ?></span> <span class="quiet">· in the dictionary, so the voice very likely reads them right already</span></summary>
        <p class="quiet" style="margin: 8px 0 10px">Try any that sound wrong; Try loads the dictionary's pronunciation into the sound test, and you can make a rule from there.</p>
        <div class="chips">
          <?php foreach ($ordinary as $r): ?>
            <span style="display: inline-flex; gap: 2px; align-items: center">
              <button type="button" class="chip" title="<?= e((string) $r['respelling']) ?>" data-wb-load data-written="<?= e((string) $r['word']) ?>" data-spoken="<?= e((string) $r['spoken']) ?>" data-layer="project|<?= e($pid) ?>" data-label="dictionary: <?= e((string) $r['respelling']) ?>" data-case="0" data-whole="1"><?= e((string) $r['word']) ?></button>
              <?php if (!$archived): ?><form method="post" action="<?= e(base_url('pron-check')) ?>" style="display: inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="dismiss"><input type="hidden" name="word" value="<?= e((string) $r['word']) ?>"><button type="submit" class="btn-link" aria-label="Dismiss <?= e((string) $r['word']) ?>" title="Dismiss" style="font-size: 13px; padding: 0 4px">×</button></form><?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($missing): ?>
      <h2 style="font-size: 16px; margin: 22px 0 6px">Couldn't find <span class="count quiet"><?= count($missing) ?></span></h2>
      <p class="quiet" style="margin: 0 0 10px">No pronunciation was found online for these. Use Try to hear how the voice reads them, and change the spoken form until it sounds right.</p>
      <div class="chips">
        <?php foreach ($missing as $r): ?>
          <span style="display: inline-flex; gap: 2px; align-items: center">
            <button type="button" class="chip" <?= (string) $r['voice_reads'] !== '' ? 'title="The voice says ' . e(\App\Services\IpaConverter::respell((string) $r['voice_reads'])) . '"' : '' ?> data-wb-load data-written="<?= e((string) $r['word']) ?>" data-spoken="<?= e((string) $r['word']) ?>" data-layer="project|<?= e($pid) ?>" data-label="not found online" data-case="1" data-whole="1"><?= e((string) $r['word']) ?></button>
            <?php if (!$archived): ?><form method="post" action="<?= e(base_url('pron-check')) ?>" style="display: inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pid) ?>"><input type="hidden" name="action" value="dismiss"><input type="hidden" name="word" value="<?= e((string) $r['word']) ?>"><button type="submit" class="btn-link" aria-label="Dismiss <?= e((string) $r['word']) ?>" title="Dismiss" style="font-size: 13px; padding: 0 4px">×</button></form><?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($tab === 'suggestions' && !$online): ?>
    <p class="muted" style="margin: 0 0 12px">Words in this book a voice may read badly. Nothing changes until you make a rule; anything a rule already covers is left out.</p>
    <div class="chips" role="group" aria-label="Kind" style="margin-bottom: 12px">
      <?php foreach (TextCheckService::KINDS as $k => [$label]): ?>
        <a class="chip" href="<?= e($tabUrl('suggestions', ['kind' => $k])) ?>" aria-pressed="<?= $k === $kind ? 'true' : 'false' ?>" style="display: inline-flex; align-items: center; text-decoration: none"><?= e($label) ?>&nbsp;<span style="opacity: .75"><?= (int) ($kindCounts[$k] ?? 0) ?></span></a>
      <?php endforeach; ?>
    </div>
    <p class="quiet" style="margin: 0 0 12px"><?= e(TextCheckService::KINDS[$kind][1]) ?><?= $kind === 'proper' && !$hasDictionary ? ' No word list is installed here, so ordinary names such as Paris appear too.' : '' ?></p>
    <?php if ($suggestions): ?>
      <div class="table-box">
        <table class="table">
          <thead><tr><th>Text</th><th>Where it appears</th><th class="num">Times</th><th>Reads as</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody>
          <?php foreach ($suggestions as $row): ?>
            <tr>
              <td><strong><?= e($row['text']) ?></strong><br><span class="quiet"><?= e($row['what']) ?></span></td>
              <td style="font-family: var(--serif); color: var(--ink-2); max-width: 420px"><?= e($row['example']) ?></td>
              <td class="num"><?= (int) $row['count'] ?></td>
              <td><?= $row['becomes'] === $row['text'] ? '<span class="quiet">as written</span>' : e($row['becomes']) ?></td>
              <td style="text-align: right"><button type="button" class="btn-soft btn-sm" data-wb-load data-written="<?= e($row['text']) ?>" data-spoken="<?= e($row['becomes']) ?>" data-layer="project|<?= e($pid) ?>" data-label="<?= e($row['what']) ?>" data-case="1" data-whole="1">Try and make a rule</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="card muted">Nothing of this kind needs a decision in this book.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($tab === 'shared'): ?>
    <p class="muted" style="margin: 0 0 12px">A shared ruleset is a list of rules several books can use, for example for a series. Editing it changes every book that uses it.</p>
    <div class="grid-cards" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr))">
      <?php foreach ($linked as $s): ?>
        <div class="card" style="display: grid; gap: 8px">
          <div style="display: flex; justify-content: space-between; gap: 8px"><strong><?= e((string) $s['name']) ?></strong><span class="tag green">Used by this book</span></div>
          <span class="quiet"><?= (int) ($s['rule_count'] ?? 0) ?> rules<?= ($s['description'] ?? '') !== '' ? ' · ' . e((string) $s['description']) : '' ?></span>
          <div style="display: flex; gap: 8px; flex-wrap: wrap">
            <a class="btn btn-outline btn-sm" href="<?= e(base_url('rulesets', ['layer' => 'set', 'ref' => $s['id']])) ?>">Open</a>
            <?php if (!$archived): ?><form method="post" action="<?= e(base_url('rulesets')) ?>"><?= $hidden('shared') ?><input type="hidden" name="action" value="detach_set"><input type="hidden" name="set_id" value="<?= e((string) $s['id']) ?>"><button type="submit" class="btn-outline btn-sm">Stop using</button></form><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php foreach ($available as $s): ?>
        <div class="card" style="display: grid; gap: 8px">
          <div style="display: flex; justify-content: space-between; gap: 8px"><strong><?= e((string) $s['name']) ?></strong><span class="tag">Not used</span></div>
          <span class="quiet"><?= (int) ($s['rule_count'] ?? 0) ?> rules<?= ($s['description'] ?? '') !== '' ? ' · ' . e((string) $s['description']) : '' ?></span>
          <?php if (!$archived): ?><form method="post" action="<?= e(base_url('rulesets')) ?>"><?= $hidden('shared') ?><input type="hidden" name="action" value="attach_set"><input type="hidden" name="set_id" value="<?= e((string) $s['id']) ?>"><button type="submit" class="btn-dark btn-sm">Use for this book</button></form><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p style="margin-top: 14px"><a href="<?= e(base_url('rulesets', ['layer' => 'set'])) ?>">Create or edit shared rulesets</a></p>
  <?php endif; ?>
  </div>

  <aside class="three-side" aria-label="Sound test" style="padding-top: 24px">
    <h2 style="font-size: 16px; margin-bottom: 4px">Sound test</h2>
    <?php
      $wbLayers = [['project|' . $pid, 'This book']];
      foreach ($linked as $set) $wbLayers[] = ['set|' . $set['id'], 'Ruleset: ' . $set['name']];
      $wbLayers[] = ['voice|' . $voice, 'Voice ' . $voice . ', every book'];
      $wbLayers[] = ['default|', 'Every book and voice'];
      $wbProject = $pid;
      $wbVoice = $voice;
      $wbSpeed = (float) ($project['tts_speed'] ?? 1.0);
      $wbToken = \App\Core\Csrf::token();
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
  const chips = [...document.querySelectorAll('[data-rule-filter]')];
  let filter = 'all';
  const apply = () => {
    if (!table) return;
    const q = (search ? search.value : '').trim().toLowerCase();
    table.querySelectorAll('tbody tr').forEach(tr => {
      const kind = filter === 'all' || (filter === 'regex' ? tr.dataset.match === 'regex' : tr.dataset.from === filter);
      tr.hidden = !(kind && (q === '' || (tr.dataset.text || '').includes(q)));
    });
  };
  if (search) search.addEventListener('input', apply);
  chips.forEach(c => c.addEventListener('click', () => { filter = c.dataset.ruleFilter; chips.forEach(x => x.setAttribute('aria-pressed', String(x === c))); apply(); }));
  const toggle = document.querySelector('[data-toggle-add]');
  const panel = document.querySelector('[data-add-panel]');
  if (toggle && panel) {
    const show = on => { panel.hidden = !on; toggle.setAttribute('aria-expanded', String(on)); if (on) panel.querySelector('input[name=pattern]').focus(); };
    toggle.addEventListener('click', () => show(panel.hidden));
    document.addEventListener('click', e => { if (e.target.closest('[data-edit-rule]')) { panel.hidden = false; toggle.setAttribute('aria-expanded', 'true'); const menu = e.target.closest('details'); if (menu) menu.open = false; } });
  }
})();
</script>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
