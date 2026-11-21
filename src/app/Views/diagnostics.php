<?php
declare(strict_types=1);

$pageTitle = 'Diagnostics';
$health = \App\Core\App::health();
$limits = $health['upload_limits'];
$extensions = $health['required_extensions'];
$allExtensions = !in_array(false, $extensions, true);
require APP_ROOT . '/app/Views/layout-top.php';
?>
<section class="page-head diagnostic-heading">
  <div>
    <p class="eyebrow">SYSTEM CHECK</p>
    <h1>Diagnostics</h1>
    <p class="lead compact">A focused view of runtime readiness. Hover or focus the upload limit to see which configured layer is controlling it.</p>
  </div>
</section>

<section class="diagnostic-cards">
  <article class="diagnostic-card">
    <span class="status <?= $health['storage_writable'] ? 'good' : 'bad' ?>"></span>
    <div><small>Storage</small><strong><?= $health['storage_writable'] ? 'Writable' : 'Not writable' ?></strong></div>
  </article>
  <article class="diagnostic-card">
    <span class="status <?= $health['database_exists'] ? 'good' : 'bad' ?>"></span>
    <div><small>Database</small><strong><?= $health['database_exists'] ? 'Ready' : 'Missing' ?></strong></div>
  </article>
  <article class="diagnostic-card">
    <span class="status <?= $allExtensions ? 'good' : 'bad' ?>"></span>
    <div><small>EPUB extensions</small><strong><?= $allExtensions ? 'Ready' : 'Missing dependency' ?></strong></div>
  </article>

  <article class="diagnostic-card upload-limit-card" tabindex="0" aria-describedby="upload-limit-details">
    <span class="status <?= $limits['effective_bytes'] !== null ? 'good' : 'warn' ?>"></span>
    <div><small>Effective upload limit</small><strong><?= e($limits['effective_display']) ?></strong></div>
    <span class="info-icon" aria-hidden="true">i</span>
    <div class="limit-popover" id="upload-limit-details" role="tooltip">
      <div class="popover-title">Configured upload limits</div>
      <?php foreach ($limits['details'] as $limit): ?>
        <div class="limit-row <?= $limit['limiting'] ? 'limiting' : '' ?>">
          <span><?= e($limit['label']) ?></span>
          <strong><?= e($limit['display']) ?></strong>
          <?php if ($limit['limiting']): ?><em>Limiting</em><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <p>Nginx value source: <?= e($limits['nginx_detection']) ?>.</p>
    </div>
  </article>
</section>

<section class="panel diagnostic-detail">
  <h2>Runtime details</h2>
  <dl class="diagnostics">
    <div><dt>PHP</dt><dd><?= e($health['php']) ?></dd></div>
    <div><dt>Application version</dt><dd><?= e($health['version']) ?></dd></div>
    <div><dt>Portable FFmpeg</dt><dd><?= $health['ffmpeg_executable'] ? e($health['ffmpeg_version'] ?? 'Executable') : 'Not ready' ?></dd></div>
    <div><dt>Portable FFprobe</dt><dd><?= $health['ffprobe_executable'] ? e($health['ffprobe_version'] ?? 'Executable') : 'Not ready' ?></dd></div>
  </dl>
</section>

<details class="panel diagnostic-detail">
  <summary>Required PHP extensions</summary>
  <div class="extension-grid">
    <?php foreach ($extensions as $name => $loaded): ?>
      <div><span class="status <?= $loaded ? 'good' : 'bad' ?>"></span><strong><?= e($name) ?></strong><span><?= $loaded ? 'Loaded' : 'Missing' ?></span></div>
    <?php endforeach; ?>
  </div>
</details>

<p><a class="button secondary" href="<?= e(base_url('api-health')) ?>">View complete health JSON</a></p>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
