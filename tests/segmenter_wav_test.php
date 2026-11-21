<?php
declare(strict_types=1);

// php tests/segmenter_wav_test.php
require dirname(__DIR__) . '/src/app/Services/Segmenter.php';
require dirname(__DIR__) . '/src/app/Services/WavFile.php';

use App\Services\Segmenter;
use App\Services\WavFile;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

// ----- segmenter -----
$s = Segmenter::split("Chapter 1.\n\nShort paragraph here.\n\nAnother one.", 900);
check('one segment per short paragraph', array_column($s, 'text'), ['Chapter 1.', 'Short paragraph here.', 'Another one.']);
check('paragraph pauses', array_unique(array_column($s, 'gap_ms')), [Segmenter::GAP_PARAGRAPH_MS]);

$long = str_repeat('This is a sentence of moderate length. ', 30);
$s = Segmenter::split(trim($long), 300);
check('long paragraph is split at sentences', count($s) > 3, true);
check('no segment exceeds the limit', max(array_map(static fn (array $x): int => mb_strlen($x['text']), $s)) <= 300, true);
check('every segment ends on a sentence boundary', count(array_filter($s, static fn (array $x): bool => !str_ends_with($x['text'], '.'))), 0);
check('short pause inside, long pause at paragraph end', [$s[0]['gap_ms'], $s[array_key_last($s)]['gap_ms']], [Segmenter::GAP_SENTENCE_MS, Segmenter::GAP_PARAGRAPH_MS]);
check('no words lost or added', preg_replace('/\s+/', ' ', implode(' ', array_column($s, 'text'))), trim($long));

$abbr = Segmenter::sentences('Dr. Bashir met Mr. Garak at 9 p.m. They spoke with J. Sisko about Bajor.');
check('abbreviations and initials stay inside a sentence', $abbr, ['Dr. Bashir met Mr. Garak at 9 p.m.', 'They spoke with J. Sisko about Bajor.']);

$clauses = 'one two three four, five six seven eight, nine ten eleven twelve, thirteen fourteen fifteen sixteen';
$s = Segmenter::split($clauses, 100);
check('single long sentence falls back to clauses', array_column($s, 'text'), [$clauses]);
$s = Segmenter::split(str_repeat('alpha beta, ', 20) . 'omega.', 100);
check('clause split keeps commas', count($s) >= 2 && str_ends_with($s[0]['text'], ','), true);
$noPunct = implode(' ', array_fill(0, 80, 'word'));
$s = Segmenter::split($noPunct, 100);
check('hard split only as a last resort, at spaces', [count($s) > 1, max(array_map(static fn (array $x): int => mb_strlen($x['text']), $s)) <= 100, implode(' ', array_column($s, 'text')) === $noPunct], [true, true, true]);
check('empty text gives no segments', Segmenter::split("  \n\n "), []);

// ----- wav -----
function makeWav(string $path, array $samples, bool $streamedHeader = false): void
{
    $data = pack('v*', ...$samples);
    $len = strlen($data);
    file_put_contents($path, 'RIFF' . pack('V', 36 + $len) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', $streamedHeader ? 0xFFFFFFFF : $len) . $data);
}
$dir = sys_get_temp_dir() . '/wavtest-' . bin2hex(random_bytes(4));
mkdir($dir);
makeWav("$dir/a.wav", array_fill(0, 2400, 1000));              // 100 ms
makeWav("$dir/b.wav", array_fill(0, 4800, -1000), true);       // 200 ms, streamed-style header
$info = WavFile::info("$dir/b.wav");
check('streamed header length is taken from the file', [$info['data_length'], $info['duration_ms'], $info['rate']], [9600, 200, 24000]);
$r = WavFile::join([['path' => "$dir/a.wav", 'gap_ms' => 500], ['path' => "$dir/b.wav", 'gap_ms' => 999]], "$dir/out.wav");
check('joined length = audio + one gap (last gap dropped)', $r['duration_ms'], 800);
$out = WavFile::info("$dir/out.wav");
check('joined file is a valid WAV of that length', [$out['duration_ms'], $out['rate'], $out['channels'], $out['bits']], [800, 24000, 1, 16]);
$bytes = file_get_contents("$dir/out.wav");
check('file size matches the header', [strlen($bytes), unpack('V', substr($bytes, 4, 4))[1] + 8], [$r['byte_size'], $r['byte_size']]);
$samples = array_values(unpack('v*', substr($bytes, 44)));
check('samples in order: audio, silence, audio', [$samples[0], $samples[2399], $samples[2400], $samples[2400 + 12000 - 1], $samples[2400 + 12000]], [1000, 1000, 0, 0, 64536]);

makeWav("$dir/c.wav", [1, 2, 3]);
file_put_contents("$dir/rate.wav", 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 22050, 44100, 2, 16) . 'data' . pack('V', 2) . pack('v', 1));
try { WavFile::join([['path' => "$dir/c.wav", 'gap_ms' => 0], ['path' => "$dir/rate.wav", 'gap_ms' => 0]], "$dir/bad.wav"); $r2 = 'joined'; } catch (RuntimeException $e) { $r2 = 'rejected'; }
check('different formats are refused', $r2, 'rejected');

array_map('unlink', glob("$dir/*"));
rmdir($dir);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
