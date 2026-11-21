<?php
declare(strict_types=1);
$health = \App\Core\App::health();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e((string) config('app.name')) ?></title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
</head>
<body>
<header class="topbar">
  <div class="brand"><span class="brand-mark">A</span><span>AI Audiobook Studio</span></div>
  <nav><a href="<?= e(base_url('diagnostics')) ?>">Diagnostics</a></nav>
</header>
<main class="container">
  <section class="hero">
    <p class="eyebrow">PROJECT-BASED AUDIOBOOK AUTHORING</p>
    <h1>Your audiobook workspace is ready.</h1>
    <p class="lead">Project creation, EPUB import, rule processing, review tools, and section audio generation will be added as isolated milestones.</p>
    <div class="actions">
      <button type="button" disabled>Create project <small>Phase 1</small></button>
      <a class="button secondary" href="<?= e(base_url('diagnostics')) ?>">Run diagnostics</a>
    </div>
  </section>
  <section class="grid">
    <article class="card"><span class="status <?= $health['storage_writable'] ? 'good' : 'bad' ?>"></span><h2>Storage</h2><p><?= $health['storage_writable'] ? 'Writable and ready' : 'Not writable' ?></p></article>
    <article class="card"><span class="status <?= $health['database_exists'] ? 'good' : 'warn' ?>"></span><h2>Database</h2><p><?= $health['database_exists'] ? 'SQLite file present' : 'Not initialized' ?></p></article>
    <article class="card"><span class="status <?= $health['ffmpeg_executable'] ? 'good' : 'warn' ?>"></span><h2>Portable FFmpeg</h2><p><?= $health['ffmpeg_executable'] ? 'Executable found' : 'Binary not added yet' ?></p></article>
  </section>
  <section class="panel">
    <h2>Planned workflow</h2>
    <ol class="steps">
      <li><strong>Projects</strong><span>Create, open, archive, and protect book projects.</span></li>
      <li><strong>EPUB and sections</strong><span>Preserve source, inspect sections, combine, split, reorder, and rename.</span></li>
      <li><strong>Text workbench</strong><span>Scan suspect words, test voices, apply scoped rules, and protect manual edits.</span></li>
      <li><strong>Audio</strong><span>Generate WAV through a provider adapter and encode section M4A files at 96 kbps AAC by default.</span></li>
    </ol>
  </section>
</main>
<footer><span>Version <?= e($health['version'] ?: 'unknown') ?></span></footer>
</body>
</html>
