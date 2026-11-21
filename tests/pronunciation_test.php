<?php
declare(strict_types=1);

// php tests/pronunciation_test.php  (uses a throwaway database)
$db = sys_get_temp_dir() . '/audiobook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('AI_AUDIOBOOK_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
use App\Services\PronunciationService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$svc = new PronunciationService();
$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title) VALUES('p1','p1','Book One'),('p2','p2','Book Two')");

// layers: default < voice < set < project
$svc->save('default', '', 'Bajor', 'Bay-jor');
$svc->save('default', '', 'Sisko', 'Siss-ko');
$svc->save('voice', 'am_michael', 'Sisko', 'Sisk-oh');
$setId = $svc->createSet('DS9');
$svc->save('set', $setId, 'Sisko', 'Siss-koh');
$svc->save('set', $setId, 'Worf', 'Woorf');
$svc->attachSet('p1', $setId);
$svc->save('project', 'p1', 'Worf', 'Worff');

$spoken = static fn (?string $project, ?string $voice, string $text): string => $svc->apply($text, $svc->effective($project, $voice))['text'];

check('default only', $spoken(null, null, 'Sisko of Bajor'), 'Siss-ko of Bay-jor');
check('voice beats default', $spoken(null, 'am_michael', 'Sisko'), 'Sisk-oh');
check('other voice keeps default', $spoken(null, 'bm_lewis', 'Sisko'), 'Siss-ko');
check('set beats voice', $spoken('p1', 'am_michael', 'Sisko'), 'Siss-koh');
check('project beats set', $spoken('p1', 'am_michael', 'Worf'), 'Worff');
check('project without the set is unaffected', $spoken('p2', 'am_michael', 'Worf Sisko'), 'Worf Sisk-oh');

// disabling a rule in a higher layer switches the pattern off
$rule = $svc->rules('project', 'p1')[0];
$svc->setEnabled((int) $rule['id'], false);
check('disabled project rule falls back off entirely', $spoken('p1', 'am_michael', 'Worf'), 'Worf');
$svc->setEnabled((int) $rule['id'], true);

// matching behaviour
$svc->save('default', '', "Bajor's", "Bay-jor's");
check('longest pattern wins', $spoken(null, null, "Bajor's ships and Bajor"), "Bay-jor's ships and Bay-jor");
check('case insensitive by default', $spoken(null, null, 'BAJOR'), 'Bay-jor');
check('whole words only', $spoken(null, null, 'Bajoran'), 'Bajoran');
check('curly apostrophe', $spoken(null, null, "Bajor\u{2019}s"), "Bay-jor's");
$svc->save('default', '', 'Rom', 'Ra-mm', true);
check('case sensitive rule', $spoken(null, null, 'Rom and rom'), 'Ra-mm and rom');
$svc->save('default', '', 'Ra', 'X');
check('spoken text is not rewritten again', $spoken(null, null, 'Rom'), 'Ra-mm');

$svc->save('default', '', 'OceanofPDF.com', '');
check('empty spoken form removes the text', trim($spoken(null, null, 'Hello OceanofPDF.com there')), 'Hello  there');

// import
check('import count', $svc->importMap('project', 'p2', ['Quark' => 'Kwork', 'Odo' => 'Oh-doh']), 2);
check('json map decoding rejects lists', (function (): string { try { PronunciationService::decodeJsonMap('["a"]'); return 'accepted'; } catch (InvalidArgumentException $e) { return 'rejected'; } })(), 'rejected');
check('bundled list is valid', count(PronunciationService::decodeJsonMap((string) file_get_contents(dirname(__DIR__) . '/src/resources/pronunciations/sisko-original.json'))) > 20, true);

// deleting a set removes its rules and links
$svc->deleteSet($setId);
check('set rules removed', (int) $pdo->query("SELECT COUNT(*) FROM pronunciation_rules WHERE scope='set'")->fetchColumn(), 0);
check('other layers untouched', count($svc->rules('default', '')) > 0, true);
check('set links removed', (int) $pdo->query('SELECT COUNT(*) FROM project_pronunciation_sets')->fetchColumn(), 0);

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
