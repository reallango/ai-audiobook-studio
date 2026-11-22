<?php
declare(strict_types=1);

// php tests/narration_edit_test.php  (throwaway database)
$db = sys_get_temp_dir() . '/audiobook-edit-' . bin2hex(random_bytes(4)) . '.sqlite';
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
$pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)')
    ->execute(['b1', 'p', 's1', 0, 'p', 'Sisko walked to Bajor and met Dukat at the STARBASE.', 'h', '/']);
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L','p',1,'Chapter 1',1)");
$pdo->exec("INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES('L','s1',0)");

$pron = new PronunciationService();
$pron->save('project', 'p', 'Bajor', 'Bay-jor');
$narr = new NarrationService();
$item = fn () => $narr->chapter('p', 'L')['items'][0];
$say = fn () => $item()['narration'];
$kinds = fn () => array_values(array_unique(array_column(array_filter($item()['pieces'], static fn ($p) => $p['kind'] !== 'plain'), 'kind')));

check('rules and the ALL-CAPS step apply first', $say(), 'Sisko walked to Bay-jor and met Dukat at the Starbase.');
check('the pieces say which words changed and why', $kinds(), ['rule', 'caps']);
check('pieces rebuild the narration exactly', implode('', array_column($item()['pieces'], 'out')), $say());

// 1. change one plain word
$narr->saveParagraphText('p', 'L', 'b1', 'Sisko strolled to Bay-jor and met Dukat at the Starbase.');
check('editing a plain word keeps the other changes', $say(), 'Sisko strolled to Bay-jor and met Dukat at the Starbase.');
check('and is stored as a change to the source word', array_map(static fn ($e) => [$e['src'], $e['dst']], $item()['edits']), [['walked', 'strolled']]);
check('edited words are marked as yours', $kinds(), ['edit', 'rule', 'caps']);

// 2. other rules still apply around the edit, even ones added later
$pron->save('project', 'p', 'Sisko', 'Siss-ko');
check('a rule added later still applies around the edit', $say(), 'Siss-ko strolled to Bay-jor and met Dukat at the Starbase.');
$pron->save('project', 'p', 'Bajor', 'Bay-joor');
check('a changed rule is picked up too', $say(), 'Siss-ko strolled to Bay-joor and met Dukat at the Starbase.');

// 3. editing the same word again replaces the edit, it does not stack
$narr->saveParagraphText('p', 'L', 'b1', 'Siss-ko ambled to Bay-joor and met Dukat at the Starbase.');
check('editing again replaces the earlier edit', [$say(), count($item()['edits'])], ['Siss-ko ambled to Bay-joor and met Dukat at the Starbase.', 1]);

// 4. edit a word that a rule produced: the edit wins over the rule, scoped to that place
$narr->saveParagraphText('p', 'L', 'b1', 'Siss-ko ambled to Bayjor and met Dukat at the Starbase.');
check('an edit of a rule-made word replaces the rule there', [$say(), array_map(static fn ($e) => [$e['src'], $e['dst']], $item()['edits'])], ['Siss-ko ambled to Bayjor and met Dukat at the Starbase.', [['walked', 'ambled'], ['Bajor', 'Bayjor']]]);
$pron->save('project', 'p', 'Bajor', 'Bay-jorrr');
check('and a later change to that rule does not override the edit', $say(), 'Siss-ko ambled to Bayjor and met Dukat at the Starbase.');

// 5. deletion and insertion
$narr->saveParagraphText('p', 'L', 'b1', 'Siss-ko ambled to Bayjor at the Starbase.');
check('deleting words removes them and their spacing', $say(), 'Siss-ko ambled to Bayjor at the Starbase.');
$narr->saveParagraphText('p', 'L', 'b1', 'Siss-ko ambled slowly to Bayjor at the Starbase.');
check('inserting a word', $say(), 'Siss-ko ambled slowly to Bayjor at the Starbase.');
$narr->saveParagraphText('p', 'L', 'b1', 'Then Siss-ko ambled slowly to Bayjor at the Starbase.');
check('inserting at the start', $say(), 'Then Siss-ko ambled slowly to Bayjor at the Starbase.');
$narr->saveParagraphText('p', 'L', 'b1', 'Then Siss-ko ambled slowly to Bayjor at the Starbase tonight.');
check('inserting at the end', $say(), 'Then Siss-ko ambled slowly to Bayjor at the Starbase tonight.');

// 6. no change, reset, skip
$before = count($item()['edits']);
$narr->saveParagraphText('p', 'L', 'b1', $say());
check('saving the same text changes nothing', count($item()['edits']), $before);
$narr->resetParagraph('p', 'L', 'b1');
check('reset gives the rules-only narration back', [$say(), $item()['edit_count']], ['Siss-ko walked to Bay-jorrr and met Dukat at the Starbase.', 0]);
$narr->saveParagraphText('p', 'L', 'b1', "  \n ");
check('empty text skips the paragraph', $item()['excluded'], true);
$narr->setExcluded('p', 'L', 'b1', false);

// 7. a source change that no longer fits makes an edit stale instead of applying it to the wrong word
$narr->saveParagraphText('p', 'L', 'b1', 'Siss-ko strolled to Bay-jorrr and met Dukat at the Starbase.');
$pdo->exec("UPDATE source_blocks SET source_text='Sisko hurried to Bajor and met Dukat at the STARBASE.' WHERE id='b1'");
check('an edit whose word is gone is reported stale and not applied', [$say(), $item()['stale']], ['Siss-ko hurried to Bay-jorrr and met Dukat at the Starbase.', 1]);
$pdo->exec("UPDATE source_blocks SET source_text='Well, Sisko walked to Bajor and met Dukat at the STARBASE.' WHERE id='b1'");
check('an edit whose word moved is found again', $say(), 'Well, Siss-ko strolled to Bay-jorrr and met Dukat at the Starbase.');

// 8. curly apostrophes in the source are normalised before offsets are taken
$pdo->exec("UPDATE source_blocks SET source_text='Sisko\u{2019}s ship left Bajor.' WHERE id='b1'");
$narr->resetParagraph('p', 'L', 'b1');
$narr->saveParagraphText('p', 'L', 'b1', "Sisko's boat left Bay-jorrr.");
check('edits after curly apostrophes land on the right word', $say(), "Sisko's boat left Bay-jorrr.");

// 9. hand edits make the chapter text change, so approval is noticed
$narr->approve('p', 'L');
$narr->saveParagraphText('p', 'L', 'b1', "Sisko's raft left Bay-jorrr.");
check('an edit after approval shows as changed', $narr->chapter('p', 'L')['status'], 'changed');

// example sentences, to hear a rule in context
check('an example sentence from the book contains the word', $narr->exampleSentence('p', 'bajor'), "Sisko's ship left Bajor.");
check('without a project, recent projects are searched', $narr->exampleSentence(null, 'Bajor'), "Sisko's ship left Bajor.");
check('a word that is not in the book has no example', [$narr->exampleSentence('p', 'Cardassia'), $narr->exampleSentence('p', '')], [null, null]);
$pdo->exec("UPDATE source_blocks SET source_text='Most of the shops were closed. The OS was down. Sisko\u{2019}s os stayed on. Nothing to hear.' WHERE id='b1'");
check('a whole-word rule only finds the whole word, not letters inside other words', $narr->exampleSentences('p', 'os'), ['The OS was down.', "Sisko's os stayed on."]);
check('matching inside words is available for rules that do that', count($narr->exampleSentences('p', 'os', false)) >= 3, true);
check('case-sensitive rules only find that case', [$narr->exampleSentences('p', 'OS', true, true), $narr->exampleSentences('p', 'os', true, true)], [['The OS was down.'], ["Sisko's os stayed on."]]);
check('the possessive counts as the word', $narr->exampleSentences('p', 'Sisko'), ["Sisko's os stayed on."]);
check('the limit is respected', count($narr->exampleSentences('p', 'os', true, false, 1)), 1);

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
