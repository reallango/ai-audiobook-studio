<?php
declare(strict_types=1);

// php tests/pages_test.php  (renders every page of the new layout against a seeded book; throwaway database)
$tmp = sys_get_temp_dir() . '/audiobook-pages-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv('AI_AUDIOBOOK_DB_PATH=' . $tmp . '/app.sqlite');
putenv('AI_AUDIOBOOK_PROJECTS_DIR=' . $tmp . '/projects');
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Services\LogicalSectionService;
use App\Services\NarrationService;
use App\Services\PronunciationService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title,active_voice_profile_id,tts_speed,audio_bitrate_kbps) VALUES('p','p','Test Book','am_michael',1.0,64)");
$pdo->exec("INSERT INTO source_assets(id,project_id,original_name,stored_relpath,sha256,byte_size) VALUES('a','p','book.epub','x','h',1)");
$pdo->exec("INSERT INTO source_metadata(project_id,title,creator,language,identifier,package_path,package_version,analyzed_at) VALUES('p','Test Book','An Author','en','id','c.opf','3.0',datetime('now'))");
$ins = $pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)');
foreach ([1, 2, 3] as $n) {
    $pdo->exec("INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,raw_sha256,word_count,block_count) VALUES('s$n','p','a',$n,'m$n','c$n.xhtml','h',200,2)");
    $ins->execute(["b{$n}a", 'p', "s$n", 0, 'h2', "Chapter $n", 'h', '/']);
    $ins->execute(["b{$n}b", 'p', "s$n", 1, 'p', str_repeat("Kira walked to the harbour at Bajor and said nothing about the STARBASE. ", 12), 'h', '/']);
}
(new LogicalSectionService())->initialize('p');
(new PronunciationService())->save('project', 'p', 'Bajor', 'Bay-jor');
$first = (string) $pdo->query("SELECT id FROM logical_sections WHERE project_id='p' ORDER BY position LIMIT 1")->fetchColumn();
(new NarrationService())->approve('p', $first);

$render = static function (array $get): string {
    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/?' . http_build_query($get);
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    ob_start();
    try { App::run(); } catch (Throwable $e) { echo 'EXCEPTION ' . $e->getMessage(); }
    return (string) ob_get_clean();
};
$ok = static fn (string $html): bool => !str_contains($html, 'EXCEPTION') && !str_contains($html, 'Fatal error') && !str_contains($html, 'Request failed') && str_contains($html, '</html>');

$lib = $render(['page' => 'projects']);
check('the Library renders with the book and its stage', [$ok($lib), str_contains($lib, 'Test Book'), str_contains($lib, 'Proofread'), str_contains($lib, 'quick-import')], [true, true, true, true]);
$arr = $render(['page' => 'arrange', 'id' => 'p']);
check('Arrange renders a row per section with switches', [$ok($arr), substr_count($arr, 'data-row='), substr_count($arr, 'role="switch"')], [true, 3, 3]);
$pr = $render(['page' => 'proofread', 'id' => 'p']);
check('Proofread opens the first chapter still to approve, with marks and tools', [$ok($pr), str_contains($pr, 'Chapter 2'), str_contains($pr, 'mark class="chg rule"'), str_contains($pr, 'data-workbench'), str_contains($pr, 'data-audio-panel')], [true, true, true, true, true]);
// workbench.js finds the chapter text by [data-book]: a second element with that attribute (the Activity bar once had
// one) takes its place, and clicking words, Listen and editing all stop working
check('only the chapter text carries data-book', preg_match_all('/\sdata-book[\s>=]/', $pr), 1);
$pr1 = $render(['page' => 'proofread', 'id' => 'p', 'section' => $first]);
check('an approved chapter offers to remove the approval', [$ok($pr1), str_contains($pr1, 'Remove approval')], [true, true]);
foreach (['rules', 'suggestions', 'shared'] as $tab) {
    $pn = $render(['page' => 'pronounce', 'id' => 'p', 'tab' => $tab]);
    check("Pronunciation, $tab tab, renders", $ok($pn) && str_contains($pn, 'aria-current="page">' . ['rules' => 'Rules', 'suggestions' => 'Suggestions', 'shared' => 'Shared'][$tab]), true);
}
check('the rules tab lists this book\'s rule', str_contains($render(['page' => 'pronounce', 'id' => 'p']), '<strong>Bajor</strong>'), true);
$ex = $render(['page' => 'export', 'id' => 'p']);
check('Export renders the chapters and says nothing is built', [$ok($ex), str_contains($ex, 'Not built yet'), substr_count($ex, 'page=proofread&amp;id=p&amp;section=')], [true, true, 3]);
$st = $render(['page' => 'project', 'id' => 'p']);
check('Settings renders the reading settings and the archive section', [$ok($st), str_contains($st, 'id="project-settings"'), str_contains($st, 'Archive this book')], [true, true, true]);
check('every book page has the stage bar and the activity button', [str_contains($arr, 'aria-label="Book"'), str_contains($ex, 'id="activity"')], [true, true]);
check('the global pages still render', [$ok($render(['page' => 'voice-lab'])), $ok($render(['page' => 'rulesets', 'layer' => 'default'])), $ok($render(['page' => 'diagnostics']))], [true, true, true]);
$pdo->exec("INSERT INTO pronunciation_sets(id,name) VALUES('st','Series rules')");
(new PronunciationService())->save('set', 'st', 'Kira', 'Keera');
(new PronunciationService())->attachSet('p', 'st');
$sr = $render(['page' => 'rulesets']);
check('Shared rulesets opens on the rulesets, with the rule and the books that use it', [$ok($sr), str_contains($sr, '<strong>Kira</strong>'), str_contains($sr, 'Used by'), str_contains($sr, 'Test Book')], [true, true, true, true]);
$vr = $render(['page' => 'rulesets', 'layer' => 'voice', 'ref' => 'am_michael']);
check('the voice tab renders with a voice picker', [$ok($vr), str_contains($vr, 'id="voice-pick"')], [true, true]);
$vl = $render(['page' => 'voice-lab']);
check('Voices keeps the hooks its script needs', [str_contains($vl, 'class="voice-lab"'), substr_count($vl, 'data-take='), str_contains($vl, 'data-generate-both')], [true, 2, true]);
check('Diagnostics lists the workers and the voice server', [str_contains($render(['page' => 'diagnostics']), 'Audio worker'), str_contains($render(['page' => 'diagnostics']), 'Voice server')], [true, true]);
$nf = $render(['page' => 'no-such-page']);
check('an unknown page shows the not-found page in the normal shell', [str_contains($nf, "That page isn't here"), str_contains($nf, 'app-top')], [true, true]);
$pc = $render(['page' => 'project-create']);
check('New audiobook renders', [$ok($pc), str_contains($pc, 'name="title"')], [true, true]);
$st2 = $render(['page' => 'project', 'id' => 'p']);
check('Settings has the spoken title card with the standard text', [str_contains($st2, 'Spoken title'), str_contains($st2, 'Test Book, by An Author.'), str_contains($st2, 'personal use only')], [true, true, true]);

function rrmdir(string $d): void { foreach (glob("$d/*") ?: [] as $f) is_dir($f) ? rrmdir($f) : @unlink($f); @rmdir($d); }
rrmdir($tmp);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
