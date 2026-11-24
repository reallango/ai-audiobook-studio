<?php
declare(strict_types=1);

// php tests/epub_extract_test.php  (checks how page text becomes paragraphs; no EPUB file needed)
$db = sys_get_temp_dir() . '/audiobook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('AI_AUDIOBOOK_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Services\EpubAnalysisService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$svc = new EpubAnalysisService();
$m = new ReflectionMethod($svc, 'xhtml');
$m->setAccessible(true);
$texts = static fn (string $html): array => array_column($m->invoke($svc, '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>T</title></head><body>' . $html . '</body></html>', 'c.xhtml', 'Book')['blocks'], 'text');
$long = str_repeat('The ship drifted on through the dark. ', 12);

check('ordinary paragraphs are read as before', $texts("<h1>One</h1><p>Hello there.</p><p>Second paragraph.</p>"), ['One', 'Hello there.', 'Second paragraph.']);
check('a watermark paragraph does not hide a story kept in divs', $texts("<p>OceanofPDF.com</p><div>$long</div><div>Another line of the story goes here and carries on for a while so it counts as text.</div>")[1] ?? '', trim($long));
check('story text in spans and loose text is found', count($texts("<p>OceanofPDF.com</p><div><span>$long</span> <span>$long</span></div>")) >= 2, true);
check('double line breaks split paragraphs in loose text', array_slice($texts("<p>OceanofPDF.com</p>$long<br/><br/>" . str_repeat('Second part of the story. ', 15) . '<br/>' . str_repeat('Still second. ', 5)), 1, 2), [trim($long), trim(str_repeat('Second part of the story. ', 15) . str_repeat('Still second. ', 5))]);
check('a short page of real paragraphs is not touched', $texts('<p>Short.</p><div>Tiny.</div>'), ['Short.']);
check('well-formed pages keep their paragraphs even when other text exists', count($texts("<p>$long</p><p>$long</p><div>x</div>")), 2);

check('paragraphs inside a blockquote are read once each', $texts('<blockquote><p>Quoted one.</p><p>Quoted two.</p></blockquote><p>After.</p>'), ['Quoted one.', 'Quoted two.', 'After.']);
check('a list item with its own paragraph is read once', $texts('<ul><li><p>Item text.</p></li><li>Plain item.</li></ul>'), ['Item text.', 'Plain item.']);
check('text directly in a blockquote beside its paragraphs is kept', $texts('<blockquote>Lead line. <p>Inner para.</p></blockquote>'), ['Lead line.', 'Inner para.']);

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
