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

// testing inside a series set: the rest of that set applies even with no project
check('a series set can be added to a test without a project', $svc->apply('Worf and Sisko', $svc->effective(null, 'am_michael', [$setId]))['text'], 'Woorf and Siss-koh');
check('and without it the set does not apply', $svc->apply('Worf', $svc->effective(null, 'am_michael'))['text'], 'Worf');
check('an unknown set id is ignored', $svc->apply('Worf', $svc->effective(null, null, ['nope']))['text'], 'Worf');
check('a set that is already linked is not applied twice', count(array_filter($svc->effective('p1', null, [$setId]), static fn ($r) => $r['layer'] === 'set')) , count(array_filter($svc->effective('p1', null), static fn ($r) => $r['layer'] === 'set')));

// regex rules
$rx = static fn (string $t, ?string $project = 'p1'): string => $svc->apply($t, $svc->effective($project, null), false)['text'];
$svc->save('project', 'p1', '(\d+) km', '$1 kilometres', false, false, '', 'regex');
check('a regex rule with a group in the spoken form', $rx('It is 25 km away, not 7 KM.'), 'It is 25 kilometres away, not 7 kilometres.');
$svc->save('project', 'p1', '\bMr\.\s+([A-Z][a-z]+)', 'Mister $1', true, false, '', 'regex');
check('a case-sensitive regex with a capture', $rx('Mr. Garak and mr. odo'), 'Mister Garak and mr. odo');
$svc->save('project', 'p1', '(?<=Deep Space )9', 'nine', false, false, '', 'regex');
check('look-behind sees the text before the match', $rx('Deep Space 9 and 9 lives'), 'Deep Space nine and 9 lives');
$svc->save('project', 'p1', '(colou?r)s?', 'shade', false, false, '', 'regex');
check('regex rules and word rules work together, the word rule first', $rx('Bajor colours'), 'Bay-jor shade');
check('the match type is stored, and old rows read as word or anywhere', [array_column($svc->rules('project', 'p1'), 'match_type', 'pattern')['(colou?r)s?'], PronunciationService::typeOf(['whole_word' => 0]), PronunciationService::typeOf(['whole_word' => 1])], ['regex', 'anywhere', 'word']);
check('a plain rule saved without a match type is word or anywhere', [$svc->save('project', 'p1', 'Qq', 'Kew', false, false) ?? array_column($svc->rules('project', 'p1'), 'match_type', 'pattern')['Qq']], ['anywhere']);
$bad = static function (string $p) use ($svc): string { try { $svc->save('project', 'p1', $p, 'x', false, false, '', 'regex'); return 'accepted'; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
check('a broken pattern is refused with a reason', str_starts_with($bad('(unclosed'), 'This is not a valid pattern'), true);
check('a pattern that matches nothing is refused', str_contains($bad('a*'), 'match nothing'), true);
check('a catastrophic pattern is refused', str_contains($bad('(a+)+$'), 'too complex'), true);
check('an empty or over-long pattern is refused', [$bad(''), str_contains($bad(str_repeat('a', 201)), '200')], ['A pattern must be 1 to 200 characters.', true]);
check('slashes and tildes in a pattern are fine', [PronunciationService::validateRegex('a/b~c'), $svc->apply('a/b~c', $svc->withTemporary([], [['pattern' => 'a/b~c', 'replacement' => 'ok', 'match_type' => 'regex']]), false)['text']], [null, 'ok']);
check('a trial regex works and an invalid trial is ignored', [$svc->apply('call 12 now', $svc->withTemporary([], [['pattern' => '\d+', 'replacement' => 'N', 'match_type' => 'regex']]), false)['text'], $svc->apply('call 12 now', $svc->withTemporary([], [['pattern' => '(', 'replacement' => 'N', 'match_type' => 'regex']]), false)['text']], ['call N now', 'call 12 now']);
check('regex pieces say which rule fired', array_values(array_filter($svc->apply('go 3 km', $svc->effective('p1', null), false)['pieces'], static fn ($p) => $p['kind'] === 'rule'))[0]['match'], 'regex');

// move or copy a rule between rulesets
$svc->save('project', 'p2', 'Quark', 'Kwork', true, true, 'bartender');
$rid = (int) $svc->rules('project', 'p2')[0]['id'];
check('copying creates the rule in the other ruleset and keeps the original', [$svc->transfer($rid, 'default', '', false), count($svc->rules('project', 'p2')), array_column($svc->rules('default', ''), 'replacement', 'pattern')['Quark'] ?? null], ['created', 1, 'Kwork']);
$copy = array_values(array_filter($svc->rules('default', ''), static fn ($r) => $r['pattern'] === 'Quark'))[0];
check('flags and note are copied too', [(int) $copy['case_sensitive'], (int) $copy['whole_word'], $copy['note']], [1, 1, 'bartender']);
$svc->save('voice', 'bm_lewis', 'Quark', 'old');
check('copying onto an existing written form replaces it', [$svc->transfer($rid, 'voice', 'bm_lewis', false), $svc->rules('voice', 'bm_lewis')[0]['replacement']], ['replaced', 'Kwork']);
check('moving removes the original', [$svc->transfer($rid, 'set', $setId, true), count($svc->rules('project', 'p2')), count(array_filter($svc->rules('set', $setId), static fn ($r) => $r['pattern'] === 'Quark'))], ['created', 0, 1]);
check('a rule cannot be moved to where it already is', (function () use ($svc, $setId): string { $id = (int) array_values(array_filter($svc->rules('set', $setId), static fn ($r) => $r['pattern'] === 'Quark'))[0]['id']; try { $svc->transfer($id, 'set', $setId, false); return 'accepted'; } catch (InvalidArgumentException $e) { return 'rejected'; } })(), 'rejected');
check('unknown destinations and rules are refused', [(function () use ($svc): string { try { $svc->transfer(1, 'set', 'nope', false); return 'accepted'; } catch (InvalidArgumentException $e) { return 'rejected'; } })(), (function () use ($svc): string { try { $svc->transfer(999999, 'default', '', false); return 'accepted'; } catch (InvalidArgumentException $e) { return 'rejected'; } })()], ['rejected', 'rejected']);
check('layer names read well in messages', [$svc->layerName('default', ''), $svc->layerName('voice', 'bm_lewis'), $svc->layerName('set', $setId), $svc->layerName('project', 'p1')], ['Default', 'Voice bm_lewis', 'Ruleset DS9', 'Project Book One']);

// disabling a rule in a higher layer switches the pattern off
$rule = array_values(array_filter($svc->rules('project', 'p1'), static fn ($r) => $r['pattern'] === 'Worf'))[0];
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

// ALL-CAPS words are normalised unless a rule covers them
$caps = static fn (string $t, bool $on = true): string => $svc->apply($t, $svc->effective(null, null), $on)['text'];
check('ALL-CAPS word becomes a normal word', $caps('The STARBASE was quiet.'), 'The Starbase was quiet.');
check('apostrophes and hyphens', $caps("It WASN'T the NCC-1701."), "It Wasn't the Ncc-1701.");
check('single letters and I are left alone', $caps('I met A friend.'), 'I met A friend.');
check('Roman numerals are left to rules', $caps('Rigel VII and XII'), 'Rigel VII and XII');
check('a rule beats the ALL-CAPS step', (function () use ($svc, $caps): string { $svc->save('default', '', 'USS', 'U.S.S.'); return $caps('The USS Defiant and the STARBASE'); })(), 'The U.S.S. Defiant and the Starbase');
check('normalising can be switched off', $caps('The STARBASE', false), 'The STARBASE');
check('mixed case is untouched', $caps('McCoy and DeForest'), 'McCoy and DeForest');
$audit = PronunciationService::capsAudit(['STARBASE and USS and STARBASE, NASA.', 'I met VII'], $svc->effective(null, null));
check('audit lists uncovered ALL-CAPS words by frequency, not covered or single ones', [array_column($audit, 'token'), $audit[0]['count'], $audit[0]['becomes']], [['STARBASE', 'NASA', 'VII'], 2, 'Starbase']);
check('audit marks Roman numerals as left alone', end($audit)['becomes'], null);
$tmp = $svc->withTemporary($svc->effective(null, null), [['pattern' => 'Qonos', 'replacement' => "Kah-nos"]]);
check('temporary rules apply but are not saved', [$svc->apply('Qonos', $tmp)['text'], $svc->apply('Qonos', $svc->effective(null, null))['text']], ['Kah-nos', 'Qonos']);
check('temporary rules win over saved ones', $svc->apply('Sisko', $svc->withTemporary($svc->effective(null, null), [['pattern' => 'Sisko', 'replacement' => 'Sis-koh']]))['text'], 'Sis-koh');

// a rule for a word also covers its possessive
$svc->save('default', '', 'Kira', 'Keer-ah');
check('a rule covers the possessive', $spoken(null, null, "Kira's and KIRA'S and Kira’s office"), "Keer-ah's and Keer-ah's and Keer-ah's office");
check('and not words that merely start with it', $spoken(null, null, 'Kirana'), 'Kirana');
$svc->save('default', '', "Kira's", "Keer-ah-s");
check('a separate rule for the possessive wins over the derived one at the same layer', $spoken(null, null, "Kira's"), 'Keer-ah-s');
$svc->save('project', 'p1', 'Kira', 'Kear-a');
check('a higher layer rule for the word beats a lower layer rule for the possessive', $spoken('p1', null, "Kira's"), "Kear-a's");
$svc->save('project', 'p1', 'Odo', 'Oh-doh', false, false);
check('a rule that matches inside words gets no possessive', $spoken('p1', null, "Odo's"), "Oh-doh's");
$pron2 = $svc->apply("DEEP SPACE 9'S LEGENDARY CAPTAIN,AND BAJOR'S EMISSARY", $svc->effective(null, null));
check('digit possessives, the ALL-CAPS step and an existing Bajor rule', $pron2['text'], "Deep Space 9's Legendary Captain,And Bay-jor's Emissary");
$svc->save('default', '', 'Bajor', 'Bay-jor');
check('the reported line with a plain Bajor rule and no project', $svc->apply("THE UNIQUE CAREER OF DEEP SPACE 9'S LEGENDARY CAPTAIN,AND BAJOR'S EMISSARY", $svc->effective(null, null))['text'], "The Unique Career Of Deep Space 9's Legendary Captain,And Bay-jor's Emissary");

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
