<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'AI Audiobook Studio';
$flash = flash_message();
$route = trim((string) ($_GET['page'] ?? ''), '/');
$projectIdForBack = (string) ($_GET['id'] ?? $_GET['project'] ?? $_POST['id'] ?? $_POST['project'] ?? '');

if (!isset($backUrl, $backLabel)) {
    $backUrl = null;
    $backLabel = null;

    if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'logical_sections.php') {
        $backUrl = $projectIdForBack !== ''
            ? base_url('project', ['id' => $projectIdForBack])
            : base_url();
        $backLabel = 'Project';
    } else {
        switch ($route) {
            case 'project':
            case 'project-create':
            case 'diagnostics':
                $backUrl = base_url();
                $backLabel = 'Projects';
                break;
            case 'epub-upload':
            case 'source-sections':
                if ($projectIdForBack !== '') {
                    $backUrl = base_url('project', ['id' => $projectIdForBack]);
                    $backLabel = 'Project';
                }
                break;
            case 'source-section':
                if ($projectIdForBack !== '') {
                    $backUrl = base_url('source-sections', ['id' => $projectIdForBack]);
                    $backLabel = 'Source sections';
                }
                break;
        }
    }
}
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
<link rel="stylesheet" href="<?=e(base_url('assets/css/phase1-final.css'))?>"></head>
<body>
<header class="topbar">
  <div class="topbar-leading">
    <?php if ($backUrl): ?>
      <a class="global-back" href="<?= e($backUrl) ?>" aria-label="Back to <?= e((string) $backLabel) ?>">← <span><?= e((string) $backLabel) ?></span></a>
    <?php endif; ?>
    <a class="brand" href="<?= e(base_url()) ?>"><span class="brand-mark">A</span><span>AI Audiobook Studio</span></a>
  </div>
  <nav><a href="<?= e(base_url()) ?>">Projects</a><a href="<?= e(base_url('voice-lab')) ?>">Voice Lab</a><a href="<?= e(base_url('pronunciation')) ?>">Pronunciation</a><a href="<?= e(base_url('diagnostics')) ?>">Diagnostics</a></nav>
</header>
<main class="container">
<?php if ($flash): ?><div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
