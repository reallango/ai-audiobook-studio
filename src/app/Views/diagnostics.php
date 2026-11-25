<?php
declare(strict_types=1);

/* Diagnostics: is everything the app needs in place. */
$pageTitle = 'Diagnostics';
$layout = 'bare';
$health = \App\Core\App::health();
$limits = $health['upload_limits'];
$extensions = $health['required_extensions'];
$allExtensions = !in_array(false, $extensions, true);
$audioWorker = \App\Services\JobService::workerOnline();
$buildWorker = \App\Services\TaskService::workerOnline();
$voiceServer = trim((string) config('tts.kokoro_api_key', '')) !== '';
$checker = \App\Services\PronCheckClient::configured() ? (new \App\Services\PronCheckClient())->problem() : 'Not set up (optional)';
require APP_ROOT . '/app/Views/layout-top.php';

$checks = [
    ['Storage', $health['storage_writable'], $health['storage_writable'] ? 'Writable' : 'Not writable'],
    ['Database', $health['database_exists'], $health['database_exists'] ? 'Ready' : 'Missing'],
    ['Reading EPUBs', $allExtensions, $allExtensions ? 'Ready' : 'A PHP extension is missing'],
    ['Encoding audio', $health['ffmpeg_executable'] && $health['ffprobe_executable'], $health['ffmpeg_executable'] && $health['ffprobe_executable'] ? 'ffmpeg and ffprobe found' : 'ffmpeg or ffprobe missing'],
    ['Voice server', $voiceServer, $voiceServer ? 'Set up' : 'No API key set'],
    ['Audio worker', $audioWorker, $audioWorker ? 'Running' : 'Not seen recently'],
    ['Build worker', $buildWorker, $buildWorker ? 'Running' : 'Not seen recently'],
    ['Pronunciation checker', $checker === null, $checker ?? 'Connected'],
    ['Largest upload', $limits['effective_bytes'] !== null, (string) $limits['effective_display']],
];
?>
<div class="page" style="max-width: 980px">
  <div class="page-head">
    <div>
      <h1>Diagnostics</h1>
      <p>Whether everything the studio needs is in place on this server.</p>
    </div>
    <div class="page-actions"><a class="btn btn-outline" href="<?= e(base_url('api-health')) ?>">Full health report (JSON)</a></div>
  </div>

  <div class="grid-cards" style="grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 14px">
    <?php foreach ($checks as [$label, $ok, $text]): ?>
      <div class="card" style="display: flex; gap: 12px; align-items: center; padding: 16px">
        <span class="dot <?= $ok ? 'ok' : 'bad' ?>" aria-hidden="true"></span>
        <div><div class="quiet"><?= e($label) ?></div><strong><?= e($text) ?></strong></div>
      </div>
    <?php endforeach; ?>
  </div>

  <section class="table-box" style="margin-top: 20px">
    <h2 style="font-size: 16px; padding: 16px 16px 4px">Details</h2>
    <table class="table">
      <tbody>
        <tr><td class="muted">PHP</td><td><?= e($health['php']) ?></td></tr>
        <tr><td class="muted">App version</td><td><?= e($health['version']) ?></td></tr>
        <tr><td class="muted">FFmpeg</td><td><?= $health['ffmpeg_executable'] ? e($health['ffmpeg_version'] ?? 'Found') : 'Not found' ?></td></tr>
        <tr><td class="muted">FFprobe</td><td><?= $health['ffprobe_executable'] ? e($health['ffprobe_version'] ?? 'Found') : 'Not found' ?></td></tr>
        <tr><td class="muted">Word list for name checks</td><td><?= \App\Services\TextCheckService::dictionary() !== null ? 'Installed' : 'Not installed' ?></td></tr>
      </tbody>
    </table>
  </section>

  <section class="card" style="margin-top: 20px">
    <h2 style="font-size: 16px; margin: 0 0 4px">Pronunciation checker</h2>
    <p class="quiet" style="margin: 0 0 12px">Optional. The pron-check service tells how the voice will read a word without making audio, so “Check pronunciations online” can show which words the voice already says right and suggest plain spellings for the rest.</p>
    <?php if (\App\Services\PronCheckClient::fromEnvironment()): ?>
      <p style="margin: 0">Set in the container's environment: <code><?= e(\App\Services\PronCheckClient::url()) ?></code></p>
    <?php else: ?>
      <form method="post" action="<?= e(base_url('pron-checker')) ?>" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: end">
        <?= csrf_field() ?>
        <label style="flex: 1 1 260px">Address<input type="url" name="url" value="<?= e(\App\Services\PronCheckClient::url()) ?>" placeholder="http://192.168.1.130:8890"></label>
        <label style="flex: 1 1 220px">API key<input type="password" name="key" value="" autocomplete="off" placeholder="<?= \App\Services\PronCheckClient::key() !== '' ? 'saved; leave blank to keep it' : 'the service’s PRON_CHECK_API_KEY' ?>"></label>
        <button type="submit">Save and test</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="table-box" style="margin-top: 20px">
    <h2 style="font-size: 16px; padding: 16px 16px 4px">Upload limits</h2>
    <p class="quiet" style="padding: 0 16px; margin: 0">The smallest of these decides the largest EPUB that can be uploaded. Nginx value: <?= e((string) $limits['nginx_detection']) ?>.</p>
    <table class="table">
      <tbody>
      <?php foreach ($limits['details'] as $limit): ?>
        <tr><td class="muted"><?= e($limit['label']) ?></td><td><?= e($limit['display']) ?><?= $limit['limiting'] ? ' <span class="tag amber">the limit</span>' : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <details class="card" style="margin-top: 20px">
    <summary style="cursor: pointer; font-weight: 600">PHP extensions</summary>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin-top: 12px">
      <?php foreach ($extensions as $name => $loaded): ?>
        <span style="display: flex; gap: 8px; align-items: center"><span class="dot <?= $loaded ? 'ok' : 'bad' ?>" aria-hidden="true"></span><?= e($name) ?><span class="quiet"><?= $loaded ? '' : 'missing' ?></span></span>
      <?php endforeach; ?>
    </div>
  </details>
</div>
<?php require APP_ROOT . '/app/Views/layout-bottom.php'; ?>
