<?php
declare(strict_types=1);

// php tests/narration_test.php  (uses a throwaway database)
$db = sys_get_temp_dir() . '/audiobook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('AI_AUDIOBOOK_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
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
$pdo->exec("INSERT INTO projects(id,slug,title,active_voice_profile_id) VALUES('p','p','Book','am_michael')");
$pdo->exec("INSERT INTO source_assets(id,project_id,original_name,stored_relpath,sha256,byte_size) VALUES('a','p','b.epub','x','h',1)");
$pdo->exec("INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,raw_sha256) VALUES('s1','p','a',1,'c1','c1.xhtml','h')");
$blocks = [['b1', 0, 'h1', "Chapter\u{00A0}One"], ['b2', 1, 'p', "Sisko   walked\nto Bajor\u{00AD}."], ['b3', 2, 'p', 'OceanofPDF.com'], ['b4', 3, 'p', 'Plain text.']];
foreach ($blocks as [$id, $i, $type, $text]) {
    $pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$id, 'p', 's1', $i, $type, $text, 'h', '/']);
}
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L','p',1,'Chapter 1',1)");
$pdo->exec("INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES('L','s1',0)");

$pron = new PronunciationService();
$pron->save('project', 'p', 'Sisko', 'Siss-ko');
$pron->save('project', 'p', 'OceanofPDF.com', '');

$svc = new NarrationService();
check('clean collapses whitespace and invisible characters', NarrationService::clean("  a\u{00A0}b \n c\u{00AD}d  "), 'a b cd');

$c = $svc->chapter('p', 'L');
check('unreviewed at first', $c['status'], 'unreviewed');
check('voice comes from the project', $c['voice'], 'am_michael');
check('the EPUB heading replaces the announced title when they match', $c['text'], "Chapter One\n\nSiss-ko walked to Bajor.\n\nPlain text.");
check('heading matcher: digits, words, case, punctuation', [
    NarrationService::sameTitle('CHAPTER ONE', 'Chapter 1'), NarrationService::sameTitle('Chapter Twenty-One', 'chapter 21.'),
    NarrationService::sameTitle('Chapter 2', 'Chapter 12'), NarrationService::sameTitle('Prologue', 'Chapter 1'), NarrationService::sameTitle('', ''),
], [true, true, false, false, false]);
check('a block with only removed text is not narrated', $c['items'][2]['narration'], '');

// edits
$svc->setExcluded('p', 'L', 'b4', true);
check('excluding drops the block', $svc->chapter('p', 'L')['text'], "Chapter One\n\nSiss-ko walked to Bajor.");
$svc->setOverride('p', 'L', 'b2', 'Siss-ko walked to Bay-jor.');
check('override replaces computed text', $svc->chapter('p', 'L')['items'][1]['narration'], 'Siss-ko walked to Bay-jor.');
$svc->setOverride('p', 'L', 'b2', '');
$svc->setExcluded('p', 'L', 'b4', false);
check('clearing edits prunes the rows', (int) $pdo->query('SELECT COUNT(*) FROM narration_blocks')->fetchColumn(), 0);

// approval follows the exact text
$svc->approve('p', 'L');
check('approved', $svc->chapter('p', 'L')['status'], 'approved');
$pron->save('project', 'p', 'Bajor', 'Bay-jor');
check('a dictionary change after approval is noticed', $svc->chapter('p', 'L')['status'], 'changed');
$svc->approve('p', 'L');
check('re-approving accepts the new text', $svc->chapter('p', 'L')['status'], 'approved');
$svc->unapprove('p', 'L');
check('approval can be removed', $svc->chapter('p', 'L')['status'], 'unreviewed');

// a block from elsewhere cannot be edited through this chapter
try { $svc->setExcluded('p', 'L', 'nope', true); $r = 'accepted'; } catch (InvalidArgumentException $e) { $r = 'rejected'; }
check('foreign block id rejected', $r, 'rejected');

$ov = $svc->overview('p');
check('overview lists included chapters', [count($ov), $ov[0]['status'], $ov[0]['words'] > 0], [1, 'unreviewed', true]);

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
