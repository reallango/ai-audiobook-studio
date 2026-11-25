<?php
declare(strict_types=1);

// php tests/pron_check_test.php  (no network: Wiktionary and Wikipedia are faked; throwaway database)
$db = sys_get_temp_dir() . '/audiobook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('AI_AUDIOBOOK_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
use App\Services\IpaConverter;
use App\Services\PronCheckClient;
use App\Services\PronCheckService;
use App\Services\PronLookup;
use App\Services\PronunciationService;
use App\Services\TaskService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

// ----- converting pronunciations -----
check('IPA to the voice\'s sounds', [IpaConverter::toKokoro('/ˈhætəɹəs/'), IpaConverter::toKokoro('maˈtansas'), IpaConverter::toKokoro('[ma.leˈkon]'), IpaConverter::toKokoro('/ˈoʊdoʊ/')],
    ['ˈhætəɹəs', 'mɑˈtɑnsɑs', 'mɑlAˈkOn', 'ˈOdO']);
check('sounds English lacks become the nearest English ones', IpaConverter::toKokoro('sjeɱˈfweɣos'), 'sjAmˈfwAɡOs');
check('Wikipedia\'s IPAc-en codes', IpaConverter::fromIpacEn(['b', '@', "'", 'dZ', 'oʊr', '@', 'n', 'US', 'lang=x']), 'bəˈdʒoʊrən');
check('readable respellings, stressed syllable in capitals', [IpaConverter::respell('həbˈɑnə'), IpaConverter::respell('ˈhætəɹəs'), IpaConverter::respell('mɑˈtɑnsɑs')], ['huh-BAH-nuh', 'HA-tuh-ruhs', 'mah-TAHN-sahs']);
check('a stress mark after the opening consonants marks that syllable (as the voice writes it)', [IpaConverter::respell('kˈɛɹəlInz'), IpaConverter::respell('twˈɛnti'), IpaConverter::respell('sˈu')], ['KEH-ruh-leyenz', 'TWEHN-tee', 'SOO']);
check('the spoken form of a rule carries the exact sounds', IpaConverter::markup('Habana', 'həbˈɑnə'), '[Habana](/həbˈɑnə/)');
check('nothing usable gives nothing', IpaConverter::toKokoro('/ʔ/'), '');

// ----- parsing the sources -----
// rendered Wiktionary pages: a section per language, a list line per pronunciation with its accent labels
function wikt(array $sections): string
{
    $html = '<div class="mw-heading mw-heading2"><h2 id="mw-toc-heading">Contents</h2></div>';
    foreach ($sections as $lang => $lines) {
        $html .= '<div class="mw-heading mw-heading2"><h2 id="' . str_replace(' ', '_', $lang) . '">' . $lang . '</h2></div><ul>';
        foreach ($lines as [$ipa, $qual]) $html .= '<li>' . ($qual !== '' ? '<span class="ib-brac">(</span><span class="ib-content">' . $qual . '</span><span class="ib-brac">)</span> ' : '')
            . '<a href="/wiki/Appendix:IPA">IPA</a><sup>(<a>key</a>)</sup>:&#32;<span class="IPA nowrap">' . $ipa . '</span></li>';
        $html .= '<li>Rhymes: <a><span class="IPA">-asjas</span></a></li></ul>';
    }
    return $html;
}
$gracias = wikt(['English' => [['/ˈɡɹɑ.θi.əs/', 'UK'], ['/ˈɡɹɑ.si.əs/', 'US']], 'Aragonese' => [['/ˈɡɾaθjas/', '']], 'Spanish' => [['/ˈɡɾaθjas/', 'Spain, Equatorial Guinea'], ['/ˈɡɾasjas/', 'Latin America, Philippines']]]);
$opts = PronLookup::ipaFromWiktionaryHtml($gracias);
check('Wiktionary page: every pronunciation with its language and accent; rhymes left out', [count($opts), $opts[0], $opts[4]['lang']],
    [5, ['ipa' => '/ˈɡɹɑ.θi.əs/', 'lang' => 'en', 'qual' => 'UK'], 'es']);
$pick = static fn (string $html, string $region, array $rank = []): array => PronLookup::pick(['note' => '', 'options' => PronLookup::ipaFromWiktionaryHtml($html), 'ipa' => '', 'lang' => '', 'confidence' => ''], $region, $rank);
check('English first, in the book\'s accent', [$pick($gracias, 'americas')['ipa'], $pick($gracias, 'europe')['ipa']], ['/ˈɡɹɑ.si.əs/', '/ˈɡɹɑ.θi.əs/']);
$capitan = wikt(['Spanish' => [['/kapiˈtan/', '']], 'Aragonese' => [['/kapiˈtan/', '']]]);
$vaya = wikt(['Zazaki' => [['[vɑːˈɑ]', '']], 'Spanish' => [['/ˈbaʝa/', ''], ['/ˈbaʃa/', 'Buenos Aires and environs'], ['/ˈbaʒa/', 'elsewhere in Pampas and southern Argentina']]]);
$gracSpanish = wikt(['Spanish' => [['/ˈɡɾaθjas/', 'Spain'], ['/ˈɡɾasjas/', 'Latin America, Philippines']]]);
check('no English: the language the book uses most, else Spanish/French before rarer ones', [$pick($vaya, 'americas')['lang'], $pick($capitan, 'americas', ['aragonese' => 9, 'es' => 3])['lang']], ['es', 'aragonese']);
check('a local accent (Buenos Aires) is not taken for the region\'s general one', [$pick($vaya, 'americas')['ipa'], $pick($gracSpanish, 'americas')['ipa'], $pick($gracSpanish, 'europe')['ipa']],
    ['/ˈbaʝa/', '/ˈɡɾasjas/', '/ˈɡɾaθjas/']);
check('a renamed page is medium when it is another spelling, low when it is another name', [PronLookup::spellingVariant('Havana', 'Habana'), PronLookup::spellingVariant('Palau', 'Pelews')], [true, false]);
check('vowels side by side are separate syllables; a non-syllabic vowel is a glide', [IpaConverter::respell(IpaConverter::toKokoro('/ˈɡɹɑ.si.əs/')), IpaConverter::respell(IpaConverter::toKokoro('/ˈfu̯eɣo/'))], ['GRAH-see-uhs', 'FWAY-goh']);
$dict = ['god' => true, 'fearing' => true, 'twenty' => true, 'nine' => true, 'how' => true, 'dutch' => true, 'lay' => true];
check('plain English compounds and contractions are not looked up', array_map(static fn (string $w): bool => PronCheckService::plainEnglish($w, $dict), ['God-fearing', 'Twenty-nine', "How'd", "Dutch'll", "Wha'd", 'Oost-Indische', 'Hatteras']),
    [true, true, true, true, false, false, false]);
check('Wikipedia: IPAc-en, then a foreign IPA', [PronLookup::ipaFromWikipedia("'''Havana''' ({{IPAc-en|h|ə|ˈ|v|æ|n|ə}}; Spanish: {{IPA|es|aˈβana|lang}})"), PronLookup::ipaFromWikipedia('({{IPA|es-419|maˈtansas|lang}})')],
    [['həˈvænə', 'en'], ['maˈtansas', 'es']]);
check('disambiguation pages are skipped', [PronLookup::isDisambiguation("'''Quark''' may refer to:"), PronLookup::isDisambiguation('{{disambiguation}}'), PronLookup::isDisambiguation('A city.')], [true, true, false]);

// ----- looking words up (fake Wikimedia) -----
$requests = [];
$pages = [
    'wiktionary|Hatteras' => ['parse' => ['text' => wikt(['English' => [['/ˈhætəɹəs/', '']]])]],
    'wiktionary|mamá' => ['parse' => ['text' => wikt(['Spanish' => [['/maˈma/', '']], 'Portuguese' => [['/mɐˈma/', '']]])]],
    'wiktionary|dammit' => ['parse' => ['text' => wikt(['English' => [['/ˈdæmɪt/', '']]])]],
    'wikipedia|Pelews' => ['query' => ['pages' => ['4' => ['title' => 'Palau', 'revisions' => [['slots' => ['main' => ['*' => "'''Palau''' ({{IPAc-en|p|ə|ˈ|l|aʊ}})"]]]]]]]],
    'wikipedia|Matanzas' => ['query' => ['pages' => ['1' => ['title' => 'Matanzas', 'revisions' => [['slots' => ['main' => ['*' => "'''Matanzas''' ({{IPA|es-419|maˈtansas|lang}}) is a city"]]]]]]]],
    'wikipedia|Habana' => ['query' => ['pages' => ['2' => ['title' => 'Havana', 'revisions' => [['slots' => ['main' => ['*' => "'''Havana''' ({{IPAc-en|h|ə|ˈ|v|æ|n|ə}})"]]]]]]]],
    'search|Odo' => ['query' => ['search' => [['title' => 'Odo (Star Trek)']]]],
    'wiktionary|Bajor' => ['parse' => ['text' => wikt(['Hungarian' => [['/ˈbɒjor/', '']]])]],
    'wiktionary|Ferengi' => ['parse' => ['text' => wikt(['Italian' => [['/feˈrɛndʒi/', '']]])]],
    'search|Bajor' => ['query' => ['search' => [['title' => 'Bajoran'], ['title' => 'Bajor (album)']]]],
    'search|Ferengi' => ['query' => ['search' => [['title' => 'Quark (Star Trek)'], ['title' => 'Ferengi']]]],
    'wikipedia|Ferengi' => ['query' => ['pages' => ['5' => ['title' => 'Ferengi', 'revisions' => [['slots' => ['main' => ['*' => "The '''Ferengi''' ({{IPAc-en|f|ə|ˈ|r|ɛ|ŋ|g|i}}) are a species"]]]]]]]],
    'wikipedia|Odo (Star Trek)' =>['query' => ['pages' => ['3' => ['title' => 'Odo (Star Trek)', 'revisions' => [['slots' => ['main' => ['*' => "'''Odo''' ({{IPAc-en|ˈ|oʊ|d|oʊ}}) is a character"]]]]]]]],
];
$fake = function (string $url) use (&$requests, $pages): array {
    $requests[] = $url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $site = str_contains($url, 'wiktionary') ? 'wiktionary' : (($q['list'] ?? '') === 'search' ? 'search' : 'wikipedia');
    $name = $site === 'wiktionary' ? ($q['page'] ?? '') : ($site === 'search' ? explode(' ', (string) ($q['srsearch'] ?? ''))[0] : ($q['titles'] ?? ''));
    if (str_contains((string) $name, 'Busy')) return ['status' => 429, 'body' => ''];
    $body = $pages[$site . '|' . $name] ?? ($site === 'wiktionary' ? ['error' => ['code' => 'missingtitle']] : ($site === 'search' ? ['query' => ['search' => []]] : ['query' => ['pages' => ['-1' => ['missing' => '']]]]));
    return ['status' => 200, 'body' => json_encode($body)];
};
$lookup = new PronLookup($fake, 0.0, 0);
$h = $lookup->find('Hatteras', 'My Book');
check('Wiktionary answer, English, on the word\'s own page: high', [$h['status'], $h['confidence'], $h['ipa']], ['found', 'high', '/ˈhætəɹəs/']);
$m = $lookup->find('Matanzas', 'My Book');
check('Wikipedia, another language: medium', [$m['confidence'], $m['lang']], ['medium', 'es']);
$hv = $lookup->find('Habana', 'My Book');
check('a redirect to another spelling is medium, with a note', [$hv['confidence'], str_contains($hv['note'], 'Havana')], ['medium', true]);
$o = $lookup->find('Odo', 'Star Trek');
check('found by search with the book\'s title: medium', [$o['status'], $o['confidence'], $o['source_title']], ['found', 'medium', 'Wikipedia: Odo (Star Trek)']);
check('nothing anywhere', $lookup->find('Sisko', 'Star Trek')['status'], 'none');
check('Wikipedia sending a name to a different name is low', $lookup->find('Pelews', 'My Book')['confidence'], 'low');
$b = $lookup->find('Bajor', 'My Book');
check('a name Wiktionary only has as a word of another language is low, and says so', [$b['confidence'], $b['lang'], str_contains($b['note'], 'Hungarian word')], ['low', 'hungarian', true]);
check('... unless that language leads the book', [PronLookup::pick($b, 'americas', ['hungarian' => 12, 'es' => 2])['confidence'], PronLookup::pick($b, 'americas', ['hungarian' => 6, 'es' => 4])['confidence']], ['medium', 'low']);
check('the book\'s leading language needs 5 words and twice the next', [PronLookup::leadingLanguage(['es' => 9, 'fr' => 2, 'en' => 40]), PronLookup::leadingLanguage(['es' => 4]), PronLookup::leadingLanguage([])], ['es', '', '']);
$f = $lookup->find('Ferengi', 'My Book', 'Star Trek');
check('with a subject, a name\'s own page found with it comes first: high for English', [$f['confidence'], $f['source_title'], $f['ipa']], ['high', 'Wikipedia: Ferengi', 'fəˈrɛŋgi']);
check('... a search hit that only mentions the name is not taken', $lookup->find('Bajor', 'My Book', 'Star Trek')['source_title'], 'Wiktionary: Bajor');
check('without a subject, the same name is looked up as before', $lookup->find('Ferengi', 'My Book')['source_title'], 'Wiktionary: Ferengi');
check('a page title\'s qualifier is not part of the name', PronLookup::withoutQualifier('Rom (Star Trek)'), 'Rom');
check('a qualified page counts only when it is about the subject', [PronLookup::qualifierFits('Rom (Star Trek)', 'Star Trek'), PronLookup::qualifierFits('Odo (Star Trek: Deep Space Nine)', 'Star Trek'), PronLookup::qualifierFits('Tycho (lunar crater)', 'Star Trek'), PronLookup::qualifierFits('Ferengi', 'Star Trek')],
    [true, true, false, true]);
$before = count($requests);
$lookup->find('Hatteras', 'My Book');
check('an answer is looked up once, then remembered', count($requests), $before);
$threw = '';
try { $lookup->find('Busyword', 'x'); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
check('"too many requests" is reported, and not remembered as "none"', [str_contains($threw, 'too many requests'), Database::connection()->query("SELECT COUNT(*) FROM pron_lookup_cache WHERE cache_key LIKE 'v2|busyword%'")->fetchColumn()], [true, 0]);

// ----- checking a book -----
$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title,active_voice_profile_id) VALUES('p','p','My Book','am_michael')");
$pdo->exec("INSERT INTO source_assets(id,project_id,original_name,stored_relpath,sha256,byte_size) VALUES('a','p','b.epub','x','h',1)");
$pdo->exec("INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,raw_sha256) VALUES('s1','p','a',1,'c1','c1.xhtml','h')");
$pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)')
    ->execute(['b1', 'p', 's1', 0, 'p', 'They sailed from Hatteras to Matanzas, where Odo and Sisko waited. Then Matanzas again, and Habana. The boy said Mamá, then Dammit, then Mamá and mamá.', 'h', '/']);
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L','p',1,'Chapter 1',1)");
$pdo->exec("INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES('L','s1',0)");
$svc = new PronCheckService($lookup);
$words = array_column($svc->words('p', ['proper', 'odd']), 'text');
check('the words to check are the flagged names, most frequent first', [$words[0], in_array('Sisko', $words, true), in_array('They', $words, true)], ['Mamá', true, false]);   // Mamá twice and mamá once count as one word
$progress = [];
$result = $svc->run('p', ['kinds' => ['proper', 'odd']], function (int $d, int $t) use (&$progress): void { $progress[] = "$d/$t"; });
check('the run reports progress and what it found', [$result['found'] >= 3, end($progress) === count($words) . '/' . count($words)], [true, true]);
$res = $svc->results('p');
check('found and not-found lists', [in_array('Sisko', array_column($res['missing'], 'word'), true), array_column($res['found'], 'word')[0]], [true, 'Hatteras']);
check('a result carries the rule\'s spoken form and a readable respelling', [$res['found'][0]['spoken'], $res['found'][0]['respelling']], ['[Hatteras](/ˈhætəɹəs/)', 'HA-tuh-ruhs']);
$svc->makeRule('p', 'Matanzas');
$rule = array_values(array_filter((new PronunciationService())->rules('project', 'p'), static fn (array $r): bool => $r['pattern'] === 'Matanzas'))[0] ?? [];
check('making a rule stores the exact sounds and where they came from', [$rule['replacement'] ?? '', str_contains((string) ($rule['note'] ?? ''), 'Wikipedia: Matanzas')], ['[Matanzas](/mɑˈtɑnsɑs/)', true]);
check('a word with a rule leaves the results', in_array('Matanzas', array_column($svc->results('p')['found'], 'word'), true), false);
check('the rule is read with the sounds', (new PronunciationService())->apply('to Matanzas.', (new PronunciationService())->effective('p', 'am_michael'), false)['text'], 'to [Matanzas](/mɑˈtɑnsɑs/).');
check('make all high-confidence rules', [$svc->makeRules('p', 'high'), in_array('Hatteras', array_column((new PronunciationService())->rules('project', 'p'), 'pattern'), true)], [1, true]);
$svc->setRegion('p', 'europe');
check('the accent region is kept per book', $svc->region('p'), 'europe');
check('a word in two capitals is checked once', count(array_filter($words, static fn (string $w): bool => mb_strtolower($w) === 'mamá')), 1);
check('a dictionary word with an English pronunciation goes to the ordinary list', [array_column($res['ordinary'], 'word'), in_array('Dammit', array_column($res['found'], 'word'), true)], [['Dammit'], false]);
$svc->makeRule('p', 'Mamá');
check('a dictionary word\'s rule covers every capital', (new PronunciationService())->apply('Mamá and mamá.', (new PronunciationService())->effective('p', 'am_michael'), false)['text'], '[Mamá](/mɑˈmɑ/) and [Mamá](/mɑˈmɑ/).');
$svc->dismiss('p', 'Sisko');
check('a dismissed word leaves the list', in_array('Sisko', array_column($svc->results('p')['missing'], 'word'), true), false);
check('checked words are not checked again unless asked', [count($svc->words('p', ['proper'])), count($svc->words('p', ['proper'], true)) > 0], [0, true]);

// ----- as a background task -----
$task = $svc->start('p', ['proper'], true);
check('a check is queued as a background task with its settings', [$task['kind'], json_decode((string) $task['params'], true)['kinds']], ['pron_check', ['proper']]);
check('it does not wait for chapter audio', (new TaskService())->isBusy('p', 'pron_check'), true);

// ----- compared with the voice (fake pronunciation checker) -----
check('without a checker, nothing is compared', [PronCheckClient::configured(), $svc->results('p')['already']], [false, []]);
PronCheckClient::save('http://checker.test:8890', 'secret');
$sent = [];
$checker = new PronCheckClient(function (string $url, string $json, array $o) use (&$sent): array {
    $sent[] = [$url, json_decode($json, true)];
    $items = [];
    foreach (json_decode($json, true)['items'] as $it) {
        $row = ['word' => $it['word'], 'voice' => $it['word'] === 'Habana' ? 'ɑbˈɑnɑ' : 'ˈOdə'];
        if (isset($it['sounds'])) $row['score'] = $it['word'] === 'Habana' ? 92.0 : 40.0;
        if ($it['word'] === 'Odo') $row['spelling'] = ['text' => 'Ohdoh', 'reads' => 'ˈOdO', 'score' => 97.0];
        $items[] = $row;
    }
    return ['status' => 200, 'body' => json_encode(['items' => $items])];
});
$voiced = new PronCheckService($lookup, $checker);
$voiced->compareWithVoice('p');
check('the checker is asked about every open word, with the sounds found online where there are some',
    [$sent[0][0], in_array(['word' => 'Habana', 'sounds' => 'həˈvænə'], $sent[0][1]['items'], true) || in_array('Habana', array_column($sent[0][1]['items'], 'word'), true), $sent[0][1]['minimum']],
    ['http://checker.test:8890/assess', true, 85]);
$res = $voiced->results('p');
check('a word the voice already says well moves to "already"; the rest stay, with a plain spelling', [array_column($res['already'], 'word'), array_column($res['found'], 'word'), $res['found'][0]['alt_spelling']],
    [['Habana'], ['Odo'], 'Ohdoh']);
$voiced->setMinimum('p', 95);
check('the "good enough" score is per book', array_column($voiced->results('p')['found'], 'word'), ['Odo', 'Habana']);
$voiced->makeRule('p', 'Odo', true);
$odo = array_values(array_filter((new PronunciationService())->rules('project', 'p'), static fn (array $r): bool => $r['pattern'] === 'Odo'))[0] ?? [];
check('a rule can use the plain spelling instead of the sounds', [$odo['replacement'] ?? '', str_contains((string) ($odo['note'] ?? ''), '97/100')], ['Ohdoh', true]);
$down = new PronCheckService($lookup, new PronCheckClient(fn (): array => ['status' => 0, 'body' => '']));
$threw = '';
try { $down->compareWithVoice('p'); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
check('a checker that is away is reported plainly', $threw, 'The pronunciation checker is not answering.');
check('the key is kept when the form leaves it blank', (function (): string { PronCheckClient::save('http://checker.test:8890', ''); return PronCheckClient::key(); })(), 'secret');

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
