<?php
declare(strict_types=1);

// php tests/advisor_test.php
$db = sys_get_temp_dir() . '/audiobook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('AI_AUDIOBOOK_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Services\SectionAdvisor;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$story = array_fill(0, 40, 'The ship drifted on through the dark, and nobody aboard said a word about what they had seen.');
$toc = array_map(static fn (int $i): string => 'Chapter ' . $i, range(1, 40));

check('a long run of story is kept', SectionAdvisor::keep('Oeb C01 R1', 'c01.html', $story), true);
check('a two-word watermark section is not', SectionAdvisor::keep('Oeb Fm2', 'fm2.html', ['OceanofPDF.com']), false);
check('a contents list is not, however many words', SectionAdvisor::keep('Oeb Toc', 'toc.html', $toc), false);
check('a short copyright page is not', SectionAdvisor::keep('Copyright', 'copy.html', ['Copyright 2002 by someone.', 'All rights reserved. ' . str_repeat('word ', 130)]), false);
check('a long chapter is kept even if the file is called ack', SectionAdvisor::keep('Notes', 'ack.html', $story), true);
check('a non-linear item is not kept', SectionAdvisor::keep('Chapter', 'c.html', $story, false), false);
check('the watermark does not count as words', SectionAdvisor::keep('x', 'x.html', array_merge(array_fill(0, 30, 'OceanofPDF.com'), ['Short.'])), false);

check('a plain chapter heading names the section', SectionAdvisor::name(['Chapter 7', 'It was a dark night.'], ['h1', 'p']), 'Chapter 7');
check('a short title line after a chapter number is joined', SectionAdvisor::name(['Chapter 7', 'THE LONG WAY HOME', 'It was a dark night, and the rain would not stop.'], ['h1', 'h2', 'p']), 'Chapter 7: The Long Way Home');
check('a watermark line first is skipped', SectionAdvisor::name(['OceanofPDF.com', 'Prologue', 'It began long ago.']), 'Prologue');
check('a story opening is not mistaken for a heading', SectionAdvisor::name(['The lieutenant looked around at the people in the room.', 'Another line.']), null);
check('an ALL-CAPS heading becomes Title Case', SectionAdvisor::name(['THE BEGINNING'], ['h1']), 'The Beginning');
check('a bare chapter number is a chapter, with a place heading as its subtitle', [SectionAdvisor::name(['1', 'NEW ENGLAND', 'HAMDEN, CONNECTICUT. 1805. Jack walked a step behind his father.'], ['p', 'p', 'p']), SectionAdvisor::name(['12', 'He walked on.'])], ['Chapter 1: New England', 'Chapter 12']);
check('nothing to name', SectionAdvisor::name([]), null);

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
