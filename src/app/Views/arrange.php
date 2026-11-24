<?php
declare(strict_types=1);

/* Arrange. Expects $project, $sections, $advice (id => keep/name/words), $lastRevision, $sourceFiles, $metadata, $focus. */
$pageTitle = 'Arrange · ' . $project['title'];
$layout = 'bare';
require APP_ROOT . '/app/Views/layout-top.php';

$kept = count(array_filter($sections, static fn (array $s): bool => (int) $s['included'] === 1));
$focusId = $focus !== '' ? $focus : (string) (array_values(array_filter($sections, static fn (array $s): bool => (int) $s['included'] === 1))[0]['id'] ?? ($sections[0]['id'] ?? ''));
$looks = static function (array $s, ?array $a): array {
    if ($a === null) return ['', ''];
    if ($a['keep']) return ['Story', 'blue'];
    return (int) $a['words'] < 120 ? ['Short page', ''] : ['Contents or notes', 'amber'];
};
?>
<div class="page wide" id="arrange" data-endpoint="<?= e(base_url('arrange-action')) ?>" data-text="<?= e(base_url('section-text', ['id' => $project['id']])) ?>"
     data-token="<?= e(\App\Core\Csrf::token()) ?>" data-project="<?= e((string) $project['id']) ?>" data-focus="<?= e($focusId) ?>">
  <div class="page-head">
    <div>
      <h1>Arrange</h1>
      <p><?= $kept ?> of <?= count($sections) ?> sections are read aloud. Name the chapters and choose what is read; every change saves as you go.</p>
    </div>
    <div class="page-actions">
      <button type="button" class="btn-outline" data-act="suggest_keep" title="Keeps sections with real story text and leaves out title pages, contents lists and short fragments. Applies to the selected sections, or to all.">Suggest what to keep</button>
      <button type="button" class="btn-outline" data-act="suggest_names" title="Names sections from the heading in their own text. Applies to the selected sections, or to all.">Suggest names</button>
      <button type="button" class="btn-outline" data-act="undo" data-undo <?= $lastRevision ? '' : 'disabled' ?>><?= $lastRevision ? 'Undo: ' . e(rtrim((string) $lastRevision['description'], '.')) : 'Nothing to undo' ?></button>
    </div>
  </div>

  <div class="bulkbar" data-bulk hidden role="region" aria-label="Selected sections">
    <strong data-bulk-count>0 selected</strong>
    <button type="button" data-act="keep_on">Keep</button>
    <button type="button" data-act="keep_off">Leave out</button>
    <button type="button" data-act="combine">Combine</button>
    <span style="display: inline-flex; gap: 8px; align-items: center; flex-wrap: wrap">
      <button type="button" class="primary" data-act="name_chapters">Name as chapters</button>
      <label>from <input type="number" min="1" max="999" value="1" data-start aria-label="First chapter number"></label>
      <label><input type="checkbox" data-headings checked> keep headings found in the text</label>
    </span>
    <button type="button" data-act="clear" style="margin-left: auto; border-color: transparent">Clear</button>
  </div>
  <p class="save-state" data-status aria-live="polite"></p>

  <div class="split-view">
    <div class="split-main">
      <div class="table-box">
        <table class="table" data-table>
          <thead>
            <tr>
              <th style="width: 32px"><input type="checkbox" data-select-all aria-label="Select all sections"></th>
              <th style="width: 34px">#</th>
              <th>Name</th>
              <th class="num">Words</th>
              <th>Looks like</th>
              <th>Read aloud</th>
              <th><span class="visually-hidden">Order</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($sections as $i => $s): $a = $advice[$s['id']] ?? null; [$look, $tone] = $looks($s, $a); $on = (int) $s['included'] === 1; ?>
            <tr data-row="<?= e((string) $s['id']) ?>" class="<?= $on ? '' : 'is-off' ?> <?= $s['id'] === $focusId ? 'is-current' : '' ?>">
              <td><input type="checkbox" data-select aria-label="Select <?= e((string) $s['title']) ?>"></td>
              <td class="quiet"><?= (int) $s['position'] ?></td>
              <td><input class="name-input" data-name value="<?= e((string) $s['title']) ?>" maxlength="200" aria-label="Name of section <?= (int) $s['position'] ?>" data-saved="<?= e((string) $s['title']) ?>"></td>
              <td class="num quiet"><?= number_format((int) $s['word_count']) ?></td>
              <td><?php if ($look !== ''): ?><span class="tag <?= $tone ?>"><?= e($look) ?></span><?php endif; ?></td>
              <td><button type="button" class="switch" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="Read <?= e((string) $s['title']) ?> aloud" data-keep></button></td>
              <td style="white-space: nowrap">
                <button type="button" class="btn-icon btn-sm" data-move="-1" aria-label="Move up" <?= $i === 0 ? 'disabled' : '' ?>><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 15l6-6 6 6"/></svg></button>
                <button type="button" class="btn-icon btn-sm" data-move="1" aria-label="Move down" <?= $i === count($sections) - 1 ? 'disabled' : '' ?>><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg></button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($sourceFiles): ?>
        <details class="card" style="margin-top: 20px">
          <summary style="cursor: pointer; font-weight: 600">The EPUB's own files (<?= count($sourceFiles) ?>)</summary>
          <?php if ($metadata): ?><p class="quiet" style="margin: 10px 0 0">EPUB title: <?= e((string) ($metadata['title'] ?: 'not given')) ?> · creator: <?= e((string) ($metadata['creator'] ?: 'not given')) ?> · language: <?= e((string) ($metadata['language'] ?: 'not given')) ?></p><?php endif; ?>
          <p class="quiet">The sections above are built from these files, in reading order. The original text is never changed.</p>
          <table class="table" style="margin-top: 6px">
            <thead><tr><th>#</th><th>File</th><th class="num">Words</th></tr></thead>
            <tbody>
            <?php foreach ($sourceFiles as $sf): ?>
              <tr><td class="quiet"><?= (int) $sf['spine_index'] + 1 ?></td><td><a href="<?= e(base_url('source-section', ['id' => $project['id'], 'section' => $sf['id']])) ?>"><?= e((string) ($sf['href'])) ?></a></td><td class="num quiet"><?= number_format((int) $sf['word_count']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>
    </div>

    <aside class="split-side card" aria-label="Section text" data-reader>
      <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px">
        <h2 data-reader-title>Select a section</h2>
        <div style="display: flex; gap: 6px">
          <button type="button" class="btn-icon" data-step="-1" aria-label="Previous section"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 6l-6 6 6 6"/></svg></button>
          <button type="button" class="btn-icon" data-step="1" aria-label="Next section"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></button>
        </div>
      </div>
      <p class="quiet" data-reader-meta style="margin: 2px 0 14px"></p>
      <div class="reader" data-reader-text><p class="quiet" style="font-family: var(--sans)">Click a row to read it here. Point between two paragraphs to split the section there.</p></div>
    </aside>
  </div>
</div>
<script src="<?= e(base_url('assets/js/arrange.js')) ?>" defer></script>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
