<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'AI Audiobook Studio';
$flash = flash_message();
$route = trim((string) ($_GET['page'] ?? ''), '/');
$script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$projectIdForBack = (string) ($_GET['id'] ?? $_GET['project'] ?? $_POST['id'] ?? $_POST['project'] ?? '');

// Pages that belong to one project get the project header and tabs instead of a back link.
$tabByRoute = [
    'project' => 'overview', 'epub-upload' => 'source', 'source-sections' => 'source', 'source-section' => 'source',
    'chapters' => 'chapters', 'chapter-review' => 'chapters', 'pronunciation' => 'pronunciation', 'rulesets' => 'pronunciation',
];
$activeTab = $script === 'logical_sections.php' ? 'structure' : ($tabByRoute[$route] ?? null);
$shellProject = $projectNav['project'] ?? (($activeTab !== null && isset($project) && is_array($project) && isset($project['id'], $project['title'])) ? $project : null);
if (in_array($route, ['pronunciation', 'rulesets'], true) && !isset($projectNav)) $shellProject = null;   // the page sets it when a project layer is shown

if ($shellProject !== null) {
    $backUrl = null;
    $backLabel = null;
} elseif (!isset($backUrl, $backLabel)) {
    $backUrl = null;
    $backLabel = null;
    if (in_array($route, ['project-create', 'diagnostics'], true)) {
        $backUrl = base_url();
        $backLabel = 'Projects';
    }
}

$navLinks = [
    ['projects', 'Projects', base_url()],
    ['voice-lab', 'Voice Lab', base_url('voice-lab')],
    ['rulesets', 'Rulesets', base_url('rulesets')],
    ['diagnostics', 'Diagnostics', base_url('diagnostics')],
];
$currentNav = match (true) {
    $route === 'voice-lab' => 'voice-lab',
    $route === 'diagnostics' => 'diagnostics',
    in_array($route, ['pronunciation', 'rulesets'], true) && $shellProject === null => 'rulesets',
    default => 'projects',
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($pageTitle) ?> | AI Audiobook Studio</title>
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
</head>
<body>
<header class="topbar">
  <div class="topbar-leading">
    <a class="brand" href="<?= e(base_url()) ?>"><span class="brand-mark">A</span><span class="brand-name">AI Audiobook Studio</span></a>
  </div>
  <nav class="main-nav" aria-label="Main">
    <?php foreach ($navLinks as [$key, $label, $href]): ?>
      <a href="<?= e($href) ?>" <?= $currentNav === $key ? 'class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main class="container">
<?php if ($shellProject !== null): ?>
  <div class="project-shell">
    <div class="project-shell-head">
      <div class="project-shell-title">
        <a class="crumb" href="<?= e(base_url()) ?>">Projects</a><span class="crumb-sep">/</span>
        <a class="project-name" href="<?= e(base_url('project', ['id' => $shellProject['id']])) ?>"><?= e((string) $shellProject['title']) ?></a>
        <?php if (($shellProject['status'] ?? '') === 'ARCHIVED'): ?><span class="badge status-<?= e(strtolower((string) $shellProject['status'])) ?>"><?= e(str_replace('_', ' ', (string) $shellProject['status'])) ?></span><?php endif; ?>
      </div>
      <nav class="project-tabs" aria-label="Project">
        <?php foreach ([
            'overview' => ['Overview', base_url('project', ['id' => $shellProject['id']])],
            'source' => ['Source', base_url('source-sections', ['id' => $shellProject['id']])],
            'structure' => ['Structure', rtrim((string) config('app.base_path', ''), '/') . '/logical_sections.php?project=' . rawurlencode((string) $shellProject['id'])],
            'chapters' => ['Chapters and audio', base_url('chapters', ['id' => $shellProject['id']])],
            'pronunciation' => ['Rulesets', base_url('rulesets', ['layer' => 'project', 'ref' => $shellProject['id']])],
        ] as $tab => [$label, $href]): ?>
          <a href="<?= e($href) ?>" <?= $activeTab === $tab ? 'class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </div>
<?php elseif ($backUrl): ?>
  <a class="global-back" href="<?= e($backUrl) ?>">&larr; <span><?= e((string) $backLabel) ?></span></a>
<?php endif; ?>
<?php if ($flash): ?><div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
