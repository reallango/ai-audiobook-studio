<?php
declare(strict_types=1);

// php tests/structure_undo_test.php  (uses a throwaway database)
$db = sys_get_temp_dir() . '/audiobook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('AI_AUDIOBOOK_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
use App\Repositories\LogicalSectionRepository;
use App\Services\ChapterSettings;
use App\Services\LogicalSectionService;
use App\Services\NarrationService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title,active_voice_profile_id) VALUES('p','p','Book','am_michael')");
$pdo->exec("INSERT INTO source_assets(id,project_id,original_name,stored_relpath,sha256,byte_size) VALUES('a','p','b.epub','x','h',1)");
$ins = $pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)');
foreach ([1, 2, 3] as $n) {
    $pdo->exec("INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,raw_sha256) VALUES('s$n','p','a',$n,'c$n','c$n.xhtml','h')");
    $ins->execute(["b$n", 'p', "s$n", 0, 'p', "Chapter $n has some words to read aloud here.", 'h', '/']);
    $pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L$n','p',$n,'Section $n',1)");
    $pdo->exec("INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES('L$n','s$n',0)");
}

$ins->execute(['b1b', 'p', 's1', 1, 'p', 'More text follows in the first section.', 'h', '/']);
$svc = new LogicalSectionService();
$repo = new LogicalSectionRepository();
$narr = new NarrationService();
$rows = static fn (): array => array_map(static fn (array $s): array => [$s['id'], $s['title'], (int) $s['included'], (int) $s['position']], $repo->all('p'));

// work done on chapter 2 that must survive any structure change or undo
$narr->saveParagraphText('p', 'L2', 'b2', 'Chapter two has some words to read aloud here.');
ChapterSettings::set('p', 'L2', 'af_heart', 1.2);
$edits = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM narration_edits WHERE logical_section_id='L2'")->fetchColumn();
$settings = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM chapter_settings WHERE logical_section_id='L2'")->fetchColumn();
check('the edit and the chapter voice exist before', [$edits() > 0, $settings()], [true, 1]);

$svc->updateAll('p', [['id' => 'L1', 'title' => 'Prologue', 'included' => 0], ['id' => 'L2', 'title' => 'Chapter 1: Two', 'included' => 1], ['id' => 'L3', 'title' => 'Chapter 2', 'included' => 1]]);
check('a bulk change is applied', $rows(), [['L1', 'Prologue', 0, 1], ['L2', 'Chapter 1: Two', 1, 2], ['L3', 'Chapter 2', 1, 3]]);
check('and leaves chapter work alone', [$edits() > 0, $settings()], [true, 1]);

$svc->undo('p');
check('undo restores names, choices and order', $rows(), [['L1', 'Section 1', 1, 1], ['L2', 'Section 2', 1, 2], ['L3', 'Section 3', 1, 3]]);
check('undo keeps the edits and the chapter voice', [$edits() > 0, $settings()], [true, 1]);
check('the undone chapter text is still the edited text', $narr->chapter('p', 'L2')['items'][0]['narration'], 'Chapter two has some words to read aloud here.');

$svc->updateAll('p', [['id' => 'L3', 'title' => 'Third', 'included' => 1], ['id' => 'L1', 'title' => 'First', 'included' => 1], ['id' => 'L2', 'title' => 'Second', 'included' => 1]]);
check('reordering is applied', array_column($rows(), 0), ['L3', 'L1', 'L2']);
$svc->undo('p');
check('undo puts the order back', array_column($rows(), 0), ['L1', 'L2', 'L3']);
check('undo steps back more than once without error', (function () use ($svc): string { try { $svc->undo('p'); return 'undone'; } catch (InvalidArgumentException $e) { return $e->getMessage(); } })(), 'There is no structure operation to undo.');
check('chapter work is still there after everything', [$edits() > 0, $settings()], [true, 1]);

// split and combine can be undone too, and chapter work on the untouched chapters survives
$svc->split('p', 'L1', 'b1b', 'Part 2');
check('split makes a new section', count($rows()), 4);
$svc->undo('p');
check('undoing a split removes the new section and keeps the rest', array_column($rows(), 0), ['L1', 'L2', 'L3']);
check('and the split section is whole again', count($repo->blocks('L1')), 2);
check('chapter work survives the split and its undo', [$edits() > 0, $settings()], [true, 1]);
$svc->combine('p', ['L2', 'L3']);
check('combine joins two sections', count($rows()), 2);
$svc->undo('p');
check('undo brings the combined sections back', array_column($rows(), 0), ['L1', 'L2', 'L3']);
check('chapter 2 kept its work through combine and undo', [$edits() > 0, $settings()], [true, 1]);

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
