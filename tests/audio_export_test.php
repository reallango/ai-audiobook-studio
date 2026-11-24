<?php
declare(strict_types=1);

// php tests/audio_export_test.php  (throwaway database and audio folder; ffmpeg, ffprobe and Kokoro are faked)
$tmp = sys_get_temp_dir() . '/audiobook-exp-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv('AI_AUDIOBOOK_DB_PATH=' . $tmp . '/app.sqlite');
putenv('AI_AUDIOBOOK_PROJECTS_DIR=' . $tmp . '/projects');
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
use App\Services\AudioEncoder;
use App\Services\ExportService;
use App\Services\GenerationService;
use App\Services\NarrationService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}
function expectError(callable $fn): string
{
    try { $fn(); return 'no error'; } catch (InvalidArgumentException | RuntimeException $e) { return 'rejected'; }
}

// ----- encoder arguments and tags -----
$args = AudioEncoder::encodeArgs('/usr/bin/ffmpeg', '/in.wav', '/out.m4a.part', 64, ['title' => 'Chapter 1', 'artist' => '']);
check('mono AAC at the requested bitrate, no shell involved', [$args[0], in_array('-ac', $args, true) && $args[array_search('-ac', $args, true) + 1] === '1', $args[array_search('-b:a', $args, true) + 1], $args[array_search('-c:a', $args, true) + 1]], ['/usr/bin/ffmpeg', true, '64k', 'aac']);
check('tags are passed, empty ones skipped', [in_array('title=Chapter 1', $args, true), count(array_filter($args, static fn ($a) => str_starts_with($a, 'artist=')))], [true, 0]);
check('bitrate choices are low, default, high, very high', [AudioEncoder::BITRATES, AudioEncoder::DEFAULT_BITRATE], [[32 => 'Low', 64 => 'Default', 96 => 'High', 128 => 'Very high'], 64]);
check('only those bitrates are valid', [AudioEncoder::validBitrate(32), AudioEncoder::validBitrate(128), AudioEncoder::validBitrate(50), AudioEncoder::validBitrate(192)], [true, true, false, false]);

$meta = AudioEncoder::metadataFile(['title' => 'A=B; #1', 'artist' => 'Someone'], [['title' => "One\ntwo", 'start_ms' => 0, 'end_ms' => 1500], ['title' => 'Two', 'start_ms' => 1500, 'end_ms' => 4000]]);
check('metadata file has the book tags and a marker per chapter', [str_starts_with($meta, ";FFMETADATA1\n"), str_contains($meta, 'title=A\=B\; \#1'), substr_count($meta, '[CHAPTER]'), str_contains($meta, "START=1500\nEND=4000\ntitle=Two")], [true, true, 2, true]);
check('newlines in a chapter title become spaces', str_contains($meta, 'title=One two'), true);

$ranFake = [];
$enc = new AudioEncoder(function (array $argv) use (&$ranFake): string {
    $ranFake[] = $argv;
    if (str_ends_with($argv[0], 'ffprobe')) return '12.5';
    file_put_contents(end($argv), 'AAC' . str_repeat('x', 50));
    return '';
}, '/fake/ffmpeg', '/fake/ffprobe');
$out = $tmp . '/one.m4a';
$enc->encode($tmp . '/in.wav', $out, 96, ['title' => 'T']);
check('encode writes through a temporary name and renames', [is_file($out), is_file($out . '.part')], [true, false]);
check('duration comes from ffprobe', $enc->durationMs($out), 12500);
check('an unsupported bitrate is refused', expectError(fn () => $enc->encode($tmp . '/in.wav', $tmp . '/x.m4a', 50)), 'rejected');

// ----- a project with two finished chapters -----
$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title,active_voice_profile_id,audio_bitrate_kbps) VALUES('p','p','My Book','am_michael',64)");
$pdo->exec("INSERT INTO source_metadata(project_id,title,creator,package_path) VALUES('p','My Book','An Author','content.opf')");
$pdo->exec("INSERT INTO source_assets(id,project_id,original_name,stored_relpath,sha256,byte_size) VALUES('a','p','b.epub','x','h',1)");
foreach ([1, 2] as $n) {
    $pdo->exec("INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,raw_sha256) VALUES('s$n','p','a',$n,'c$n','c$n.xhtml','h')");
    $pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)')
        ->execute(["b$n", 'p', "s$n", 0, 'p', "Paragraph of chapter $n. It has some words in it.", 'h', '/']);
    $pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L$n','p',$n,'Chapter $n',1)");
    $pdo->exec("INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES('L$n','s$n',0)");
}
$fakeSynth = function (string $text): string {
    $data = str_repeat(pack('v', 400), strlen($text) * 240);
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
};
$fakeEncode = function (string $wav, string $m4a, int $kbps, array $meta): void { file_put_contents($m4a, 'FAKE-AAC-' . $kbps . str_repeat('x', 300)); };
$gen = new GenerationService($fakeSynth, $fakeEncode);
$narr = new NarrationService();

// the exporter's fake ffmpeg/ffprobe
$calls = [];
$metadataSeen = '';
$exportEnc = new AudioEncoder(function (array $argv) use (&$calls, &$metadataSeen): string {
    $calls[] = $argv;
    if (str_ends_with($argv[0], 'ffprobe')) {
        if (in_array('-show_chapters', $argv, true)) return json_encode(['chapters' => [[], []]]);
        return '60.5';
    }
    $iAt = array_keys($argv, '-i', true);
    if (count($iAt) === 2) $metadataSeen = (string) file_get_contents($argv[$iAt[1] + 1]);
    file_put_contents(end($argv), 'M4B' . str_repeat('y', 500));
    return '';
}, '/fake/ffmpeg', '/fake/ffprobe');
$exports = new ExportService($exportEnc);

check('nothing to export before any audio exists', expectError(fn () => $exports->buildM4b('p')), 'rejected');
foreach (['L1', 'L2'] as $id) {
    $narr->approve('p', $id);
    $gen->prepare('p', $id);
    while ($gen->generateNext('p', $id)['segment'] !== null) {
    }
    $gen->assemble('p', $id);
}
$ready = $exports->readyChapters('p');
check('both chapters are ready, in order, as m4a files', [array_column($ready, 'title'), array_map(static fn ($c) => pathinfo($c['path'], PATHINFO_EXTENSION), $ready)], [['Chapter 1', 'Chapter 2'], ['m4a', 'm4a']]);

$row = $exports->buildM4b('p');
check('the m4b is stored with its chapter count and total length', [(int) $row['chapter_count'], (int) $row['duration_ms'], str_ends_with((string) $row['relpath'], 'My-Book.m4b')], [2, 121000, true]);
check('chapter markers follow the real file lengths', [str_contains($metadataSeen, "START=0\nEND=60500\ntitle=Chapter 1"), str_contains($metadataSeen, "START=60500\nEND=121000\ntitle=Chapter 2"), str_contains($metadataSeen, "artist=An Author")], [true, true, true]);
$concat = array_values(array_filter($calls, static fn ($a) => str_ends_with($a[0], 'ffmpeg')))[0];
check('chapters are copied, not re-encoded', [$concat[array_search('-c', $concat, true) + 1], in_array('-f', $concat, true) && $concat[array_search('-f', $concat, true) + 1] === 'concat'], ['copy', true]);
check('the temporary files are removed', [is_file(dirname((string) $exports->m4bFile('p')) . '/.files.txt'), is_file(dirname((string) $exports->m4bFile('p')) . '/.chapters.ffmetadata')], [false, false]);
check('the m4b is current', $exports->isCurrent('p'), true);

// a changed chapter makes it out of date and drops that chapter from the ready list
$narr->setOverride('p', 'L2', 'b2', 'A different paragraph for chapter two.');
check('after a text change the m4b is no longer current', $exports->isCurrent('p'), false);
check('and the changed chapter is not listed as ready', array_column($exports->readyChapters('p'), 'title'), ['Chapter 1']);
$narr->approve('p', 'L2');
$gen->prepare('p', 'L2');
while ($gen->generateNext('p', 'L2')['segment'] !== null) {
}
$gen->assemble('p', 'L2');
check('changing the bitrate makes every chapter out of date', (function () use ($pdo, $exports): array { $pdo->exec('UPDATE projects SET audio_bitrate_kbps=128'); return array_column($exports->readyChapters('p'), 'title'); })(), []);
$pdo->exec('UPDATE projects SET audio_bitrate_kbps=64');

// ZIP of the chapter files
$zipPath = $exports->buildZip('p');
$zip = new ZipArchive();
$zip->open($zipPath);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
check('the ZIP holds one m4a per chapter, numbered', $names, ['001 - Chapter 1.m4a', '002 - Chapter 2.m4a']);
check('files are stored, not compressed', $zip->statIndex(0)['comp_method'], ZipArchive::CM_STORE);
$zip->close();
@unlink($zipPath);

// ----- the spoken introduction -----
use App\Services\IntroService;
$introCalls = [];
$intro = new IntroService(function (string $text, string $voice, float $speed) use (&$introCalls, $fakeSynth): string { $introCalls[] = [$text, $voice]; return $fakeSynth($text); }, $fakeEncode);
$withIntro = new ExportService($exportEnc, $intro);
$project = $pdo->query("SELECT * FROM projects WHERE id='p'")->fetch();
$standard = IntroService::defaultText($project, 'An Author');
$exports->buildM4b('p');                                // a current audiobook without an introduction to start from
check('the standard introduction names the book, author, app, voice service and voice, and says personal use only', [
    str_starts_with($standard, 'My Book, by An Author.'), str_contains($standard, 'AI Audiobook Studio'), str_contains($standard, 'Kokoro text-to-speech server'),
    str_contains($standard, 'Michael, an American male voice'), str_contains($standard, 'personal use only')], [true, true, true, true, true]);
check('voice ids are read as names', [IntroService::speakableVoice('bf_emma'), IntroService::speakableVoice('af_bella+af_sky')], ['Emma, a British female voice', 'a blend of Bella and Sky']);
check('with the introduction off nothing changes', [$intro->text($project), $intro->signature('p'), $withIntro->isCurrent('p')], [null, '', true]);
$intro->save('p', true, $standard);
check('saving the standard text stores it as standard, so it follows later changes', $pdo->query("SELECT intro_enabled, intro_text FROM projects WHERE id='p'")->fetch(PDO::FETCH_NUM), [1, null]);
check('switching it on makes the audiobook out of date', $withIntro->isCurrent('p'), false);
$row = $withIntro->buildM4b('p');
check('the rebuilt audiobook opens with the spoken title, called Title', [str_contains($metadataSeen, 'title=Title'), (int) $row['chapter_count'] >= 2, count($introCalls)], [true, true, 1]);
check('the book\'s rules apply to the introduction text', str_contains($introCalls[0][0], 'My Book'), true);
check('and it is up to date again', $withIntro->isCurrent('p'), true);
$withIntro->buildM4b('p');
check('the introduction audio is made once and reused', count($introCalls), 1);
$zipPath = $withIntro->buildZip('p');
$zip = new ZipArchive();
$zip->open($zipPath);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
$zip->close();
@unlink($zipPath);
check('the ZIP starts with the spoken title as file 000', $names, ['000 - Title.m4a', '001 - Chapter 1.m4a', '002 - Chapter 2.m4a']);
$intro->save('p', true, 'My own words about this book.');
check('edited text makes it out of date and is made again on the next build', [$withIntro->isCurrent('p'), (function () use ($withIntro, &$introCalls): int { $withIntro->buildM4b('p'); return count($introCalls); })()], [false, 2]);
$refused = expectError(fn () => $intro->save('p', true, str_repeat('x', IntroService::MAX_CHARS + 1)));
check('an over-long introduction is refused', $refused, 'rejected');
$intro->save('p', false, '');
check('switching it off goes back to the chapters alone', [$withIntro->isCurrent('p'), $intro->text($pdo->query("SELECT * FROM projects WHERE id='p'")->fetch())], [false, null]);

function rrmdir(string $d): void { foreach (glob("$d/{,.}*", GLOB_BRACE) ?: [] as $f) { if (in_array(basename($f), ['.', '..'], true)) continue; is_dir($f) ? rrmdir($f) : @unlink($f); } @rmdir($d); }
rrmdir($tmp);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
