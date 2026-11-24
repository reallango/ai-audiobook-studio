<?php
declare(strict_types=1);

/*
 * The app shell. Views may set before including this file:
 *   $pageTitle   the window title
 *   $projectNav  ['project' => row] to show the book's stage bar (pages that also have $project get it automatically)
 *   $layout      'bare' for pages that lay out their own width (the new pages); default is the centred container
 */
$pageTitle = $pageTitle ?? 'Audiobook Studio';
$layout = $layout ?? 'container';
$flash = flash_message();
$route = trim((string) ($_GET['page'] ?? ''), '/');
$script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

// Which stage of a book this page belongs to.
$stageByRoute = [
    'arrange' => 'arrange', 'section-preview' => 'arrange', 'source-section' => 'arrange', 'source-sections' => 'arrange',
    'proofread' => 'proofread', 'chapters' => 'proofread', 'chapter-review' => 'proofread',
    'export' => 'export',
    'pronounce' => 'pronounce', 'textchecks' => 'pronounce',
    'project' => 'settings', 'epub-upload' => 'settings',
];
$stage = $script === 'logical_sections.php' ? 'arrange' : ($stageByRoute[$route] ?? null);
$book = $projectNav['project'] ?? (($stage !== null && isset($project) && is_array($project) && isset($project['id'], $project['title'])) ? $project : null);
if ($route === 'rulesets' && !isset($projectNav)) $book = null;

$mainNav = [
    ['library', 'Library', base_url()],
    ['rulesets', 'Shared rulesets', base_url('rulesets')],
    ['voices', 'Voices', base_url('voice-lab')],
    ['diagnostics', 'Diagnostics', base_url('diagnostics')],
];
$currentMain = match (true) {
    $route === 'voice-lab' => 'voices',
    $route === 'diagnostics' => 'diagnostics',
    $route === 'rulesets' || $route === 'pronunciation' => 'rulesets',
    default => 'library',
};
$bookNav = $book === null ? [] : [
    ['arrange', 'Arrange', base_url('arrange', ['id' => $book['id']])],
    ['proofread', 'Proofread and listen', base_url('proofread', ['id' => $book['id']])],
    ['export', 'Export', base_url('export', ['id' => $book['id']])],
    ['pronounce', 'Pronunciation', base_url('pronounce', ['id' => $book['id']])],
    ['settings', 'Settings', base_url('project', ['id' => $book['id']])],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($pageTitle) ?> · Audiobook Studio</title>
  <meta name="color-scheme" content="light dark">
  <script>(function () { try { var t = localStorage.getItem('theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t); } catch (e) {} })();</script>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/phase1.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/phase2a.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/phase2a1.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/phase2b.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/phase2c.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/phase2c3.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/phase2c4.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/phase1-final.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/ui.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/studio.css')) ?>">
</head>
<body>
<a class="visually-hidden" href="#main">Skip to the page</a>
<header class="app-top">
  <a class="app-brand" href="<?= e(base_url()) ?>"><span class="app-mark" aria-hidden="true">AS</span>Audiobook Studio</a>
  <?php if ($book !== null): ?>
    <span class="app-sep" aria-hidden="true">/</span>
    <a class="app-book" href="<?= e(base_url('proofread', ['id' => $book['id']])) ?>" title="<?= e((string) $book['title']) ?>"><?= e((string) $book['title']) ?></a>
    <?php if (($book['status'] ?? '') === 'ARCHIVED'): ?><span class="app-archived">Archived</span><?php endif; ?>
    <nav class="app-nav" aria-label="Book">
      <?php foreach ($bookNav as [$key, $label, $href]): ?>
        <a href="<?= e($href) ?>" <?= $stage === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php else: ?>
    <nav class="app-nav" aria-label="Main">
      <?php foreach ($mainNav as [$key, $label, $href]): ?>
        <a href="<?= e($href) ?>" <?= $currentMain === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <div class="app-spacer"></div>
  <button type="button" class="theme-btn" data-theme-toggle data-mode="system" aria-label="Theme: same as the system" title="Theme: same as the system">
    <svg class="i-system" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 1 0 16z" fill="currentColor"/></svg>
    <svg class="i-light" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    <svg class="i-dark" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></svg>
  </button>
  <div class="activity" id="activity" data-url="<?= e(base_url('activity', $book !== null ? ['id' => $book['id']] : [])) ?>"
       data-reload="<?= e(in_array($route, ['proofread', 'export'], true) ? 'yes' : '') ?>"
       data-chapter-url="<?= e(base_url('proofread') . '&id=') ?>"></div>
</header>
<?php if ($flash): ?><div class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
<main id="main" class="<?= $layout === 'bare' ? 'app-main' : 'container' ?>">
