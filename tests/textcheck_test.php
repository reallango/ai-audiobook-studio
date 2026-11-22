<?php
declare(strict_types=1);

// php tests/textcheck_test.php  (uses a throwaway database)
$db = sys_get_temp_dir() . '/audiobook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('AI_AUDIOBOOK_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Services\TextCheckService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$wordFile = sys_get_temp_dir() . '/words-' . bin2hex(random_bytes(3)) . '.txt';
file_put_contents($wordFile, implode("\n", ['the', 'and', 'left', 'station', 'Paris', 'sunday', 'He', 'walked', 'to', 'at', 'was', 'on']) . "\n");
$dict = TextCheckService::dictionary($wordFile);
check('a word list loads', is_array($dict) && isset($dict['station']) && isset($dict['Paris']), true);

$texts = [
    'Sisko and Worf left Bajor. Sunday was quiet. He walked to Paris. Bajor was calm, said Worf.',
    'The Dominion attacked at 5:30 pm on 12/04/1998 and in the 1990s, on March 3rd, in 1865 and 2375, for $4,500 and 3.5% of 1,200 men.',
    'King Henry VIII met Louis XIV; Dr. Bashir and Mr. Garak, i.e. the FBI and USS Defiant, saw a Noooo at NCC-1701 near Café Quark and McCoy.',
];
$names = TextCheckService::scan($texts, 'proper', [], $dict);
check('names rank by how often they occur and skip dictionary words', array_column($names, 'count', 'text')['Bajor'] ?? null, 2);
check('sentence-start dictionary words and known names are not names', array_intersect(array_column($names, 'text'), ['Sunday', 'Paris', 'He', 'The']), []);
check('a name at the start of a sentence counts when the dictionary lacks it', in_array('Sisko', array_column($names, 'text'), true), true);
check('the first sentence-start word without a word list is trusted only mid-sentence', in_array('Sisko', array_column(TextCheckService::scan($texts, 'proper', [], null), 'text'), true), false);
check('each row has an example sentence', str_contains($names[0]['example'], 'Bajor') || str_contains($names[0]['example'], 'Worf'), true);

$numbers = array_column(TextCheckService::scan($texts, 'number', [], $dict), 'what', 'text');
check('numbers are told apart by shape', [$numbers['2375'] ?? null, $numbers['$4,500'] ?? null, $numbers['3.5%'] ?? null, $numbers['1,200'] ?? null, $numbers['3rd'] ?? null], ['whole number', 'amount of money', 'percentage', 'number with commas', 'ordinal']);
check('a four-digit year is named as one', $numbers['1865'] ?? null, 'year or number');
check('times and slash dates are not listed as numbers', array_intersect(array_keys($numbers), ['5', '30', '12', '04', '5:30']), []);

check('Roman numerals', array_column(TextCheckService::scan($texts, 'roman', [], $dict), 'text'), ['VIII', 'XIV']);
check('initialisms leave out Roman numerals', array_column(TextCheckService::scan($texts, 'initialism', [], $dict), 'text'), ['FBI', 'USS']);
check('abbreviations', array_column(TextCheckService::scan($texts, 'abbrev', [], $dict), 'text'), ['Dr.', 'Mr.', 'i.e.']);
$dates = array_column(TextCheckService::scan($texts, 'datetime', [], $dict), 'what', 'text');
check('dates and times', [$dates['5:30 pm'] ?? null, $dates['12/04/1998'] ?? null, $dates['1990s'] ?? null, $dates['March 3rd'] ?? null], ['time', 'date with slashes', 'decade', 'date']);
$odd = array_column(TextCheckService::scan($texts, 'odd', [], $dict), 'what', 'text');
check('odd spellings', [$odd['Noooo'] ?? null, $odd['NCC-1701'] ?? null, $odd['Café'] ?? null, $odd['McCoy'] ?? null], ['stretched letters', 'letters and digits mixed', 'accented or non-English letters', 'capital inside the word']);

// a rule that already decides a candidate removes it from the list
$tmp = new App\Services\PronunciationService();
$rules = $tmp->withTemporary([], [['pattern' => 'Bajor', 'replacement' => 'Bay-jor']]);
check('words with a rule are left out', in_array('Bajor', array_column(TextCheckService::scan($texts, 'proper', $rules, $dict), 'text'), true), false);
check('an unknown kind finds nothing', TextCheckService::scan($texts, 'nonsense', [], $dict), []);
check('every kind has a label and an explanation', array_filter(TextCheckService::KINDS, static fn (array $k): bool => count($k) !== 2 || $k[0] === '' || $k[1] === ''), []);

@unlink($db);
@unlink($wordFile);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
