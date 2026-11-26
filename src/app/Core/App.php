<?php
declare(strict_types=1);
namespace App\Core;
use App\Repositories\ProjectRepository;
use App\Repositories\SourceAssetRepository;
use App\Repositories\SourceAnalysisRepository;
use App\Services\EpubUploadService;
use App\Services\PronCheckClient;
use App\Services\EpubAnalysisService;
use App\Services\ProjectService;
use App\Services\KokoroClient;
use App\Services\VoiceCatalog;
use App\Services\PronunciationService;
use App\Services\NarrationService;
use App\Services\TextCheckService;
use App\Services\ProjectWorkflow;
use App\Services\GenerationService;
use App\Services\JobService;
use App\Services\ChapterSettings;
use App\Services\ExportService;
use App\Services\AudioEncoder;
use Throwable;
final class App
{
    public static function run(): void
    {
        $route=trim((string)($_GET['page']??'projects'),'/');
        try{match($route){'api-health'=>self::json(self::health()),'diagnostics'=>self::view('diagnostics'),'projects','home',''=>self::projects(),'quick-import'=>self::quickImport(),'arrange'=>self::arrange(),'proofread'=>self::proofread(),'pronounce'=>self::pronounce(),'intro-settings'=>self::introSettings(),'pron-check'=>self::pronCheck(),'pron-checker'=>self::pronChecker(),'pron-find'=>self::pronFind(),'arrange-action'=>self::arrangeAction(),'section-text'=>self::sectionText(),'project-create'=>self::createProject(),'project'=>self::project(),'epub-upload'=>self::epubUpload(),'epub-analyze'=>self::epubAnalyze(),'source-sections'=>self::sourceSections(),'source-section'=>self::sourceSection(),'project-archive'=>self::archiveProject(),'project-restore'=>self::restoreProject(),'project-delete'=>self::deleteProject(),'voice-lab'=>self::voiceLab(),'voice-preview'=>self::voicePreview(),'pronunciation'=>self::pronunciation(),'rulesets'=>self::pronunciation(),'pronunciation-rule'=>self::pronunciationRule(),'pronunciation-example'=>self::pronunciationExample(),'rulesets-regex'=>self::regexPreview(),'textchecks'=>self::textChecks(),'activity'=>self::activity(),'activity-clear'=>self::activityClear(),'section-preview'=>self::sectionPreview(),'chapters'=>self::chapters(),'chapter-review'=>self::chapterReview(),'chapter-audio'=>self::chapterAudio(),'chapter-text'=>self::chapterText(),'audio'=>self::audio(),'export'=>self::export(),default=>self::notFound()};}
        catch(Throwable $error){error_log($error->__toString());http_response_code(500);self::view('error',['message'=>config('app.debug',false)?$error->getMessage():'The request could not be completed. Check the application log for details.']);}
    }
    private static function voiceLab():void
    {
        self::view('voice-lab', [
            'voiceGroups' => VoiceCatalog::GROUPS,
            'sampleText' => VoiceCatalog::SAMPLE_TEXT,
            'defaultVoice' => VoiceCatalog::DEFAULT_VOICE,
            'defaultSpeed' => VoiceCatalog::DEFAULT_SPEED,
            'maxChars' => KokoroClient::MAX_PREVIEW_CHARS,
            'kokoroReady' => (string) config('tts.kokoro_api_key') !== '',
            'projects' => (new ProjectRepository())->all(false),
        ]);
    }

    /** Rulesets: one layer at a time (default, voice, shared ruleset, project), plus a rewrite test. */
    private static function pronunciation():void
    {
        $svc = new PronunciationService();
        $layers = PronunciationService::LAYER_LABELS;
        $projects = (new ProjectRepository())->all(false);
        $sets = $svc->sets();

        // Shared rulesets opens on the rulesets themselves; the per-book layer lives on each book's Pronunciation page
        $layer = (string) ($_GET['layer'] ?? $_POST['layer'] ?? 'set');
        if (!isset($layers[$layer])) $layer = 'set';
        $ref = (string) ($_GET['ref'] ?? $_POST['ref'] ?? '');
        if ($layer === 'default') $ref = '';
        if ($layer === 'voice' && !KokoroClient::validVoice($ref)) $ref = VoiceCatalog::DEFAULT_VOICE;
        if ($layer === 'set' && !$svc->set($ref)) $ref = $sets ? (string) $sets[0]['id'] : '';
        if ($layer === 'project' && !(new ProjectRepository())->find($ref)) $ref = $projects ? (string) $projects[0]['id'] : '';

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && $layer === 'project' && $ref !== '' && ($_GET['test'] ?? '') === '') self::redirect('pronounce', ['id' => $ref]);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $back = ['layer' => $layer, 'ref' => $ref];
            if (!Csrf::validate(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
                self::flash('error', 'Security token expired. Try again.');
                self::redirect('rulesets', $back);
            }
            try {
                $action = (string) ($_POST['action'] ?? '');
                switch ($action) {
                    case 'add_rule':
                        $svc->save($layer, $ref, (string) ($_POST['pattern'] ?? ''), (string) ($_POST['replacement'] ?? ''), isset($_POST['case_sensitive']), isset($_POST['whole_word']), (string) ($_POST['note'] ?? ''), in_array((string) ($_POST['match_type'] ?? ''), PronunciationService::MATCH_TYPES, true) ? (string) $_POST['match_type'] : null, ($_POST['edit_id'] ?? '') !== '' ? (int) $_POST['edit_id'] : null);
                        self::flash('success', ($_POST['edit_id'] ?? '') !== '' ? 'Rule changed.' : 'Rule saved.');
                        break;
                    case 'toggle_rule':
                        $svc->setEnabled((int) ($_POST['id'] ?? 0), ($_POST['enabled'] ?? '') === '1');
                        break;
                    case 'transfer_rule':
                        [$toScope, $toRef] = array_pad(explode('|', (string) ($_POST['to'] ?? ''), 2), 2, '');
                        $move = ($_POST['mode'] ?? 'copy') === 'move';
                        $result = $svc->transfer((int) ($_POST['id'] ?? 0), $toScope, $toRef, $move);
                        self::flash('success', ($move ? 'Moved' : 'Copied') . ' the rule to ' . $svc->layerName($toScope, $toRef) . ($result === 'replaced' ? ', replacing the rule with the same written form there' : '') . '.');
                        break;
                    case 'move_rule':
                        $svc->move((int) ($_POST['id'] ?? 0), (string) ($_POST['how'] ?? ''));
                        break;
                    case 'reset_order':
                        $svc->resetOrder($layer, $ref);
                        self::flash('success', 'Order reset: plain text rules longest first, then pattern rules.');
                        break;
                    case 'delete_rule':
                        $svc->delete((int) ($_POST['id'] ?? 0));
                        self::flash('success', 'Rule deleted.');
                        break;
                    case 'import_json':
                        $json = (string) ($_POST['json'] ?? '');
                        if (!empty($_FILES['json_file']['tmp_name']) && is_uploaded_file($_FILES['json_file']['tmp_name'])) $json = (string) file_get_contents($_FILES['json_file']['tmp_name']);
                        $count = $svc->importMap($layer, $ref, PronunciationService::decodeJsonMap($json));
                        self::flash('success', "Imported $count rules.");
                        break;
                    case 'import_bundled':
                        $file = (string) ($_POST['bundled'] ?? '');
                        if (!isset(PronunciationService::BUNDLED[$file])) throw new \InvalidArgumentException('Unknown bundled list.');
                        $name = PronunciationService::BUNDLED[$file];
                        $existing = array_values(array_filter($sets, static fn (array $s): bool => $s['name'] === $name));
                        $setId = $existing ? (string) $existing[0]['id'] : $svc->createSet($name, 'Imported from the bundled ' . $file);
                        $count = $svc->importMap('set', $setId, PronunciationService::decodeJsonMap((string) file_get_contents(APP_ROOT . '/resources/pronunciations/' . $file)));
                        self::flash('success', "Imported $count rules into the ruleset \"$name\".");
                        $back = ['layer' => 'set', 'ref' => $setId];
                        break;
                    case 'create_set':
                        $setId = $svc->createSet((string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''));
                        self::flash('success', 'Ruleset created.');
                        $back = ['layer' => 'set', 'ref' => $setId];
                        break;
                    case 'delete_set':
                        if ((string) ($_POST['confirm'] ?? '') !== 'yes') throw new \InvalidArgumentException('Tick the box to confirm deleting the ruleset.');
                        $svc->deleteSet($ref);
                        self::flash('success', 'Ruleset deleted.');
                        $back = ['layer' => 'set', 'ref' => ''];
                        break;
                    case 'attach_set':
                        $svc->attachSet($ref, (string) ($_POST['set_id'] ?? ''));
                        self::flash('success', 'Ruleset linked to the project.');
                        break;
                    case 'detach_set':
                        $svc->detachSet($ref, (string) ($_POST['set_id'] ?? ''));
                        self::flash('success', 'Ruleset unlinked.');
                        break;
                    default:
                        throw new \InvalidArgumentException('Unknown action.');
                }
            } catch (Throwable $error) {
                self::flash('error', self::userMessage($error));
            }
            if (($_POST['return'] ?? '') === 'pronounce' && $layer === 'project') self::redirect('pronounce', ['id' => $ref, 'tab' => (string) ($_POST['tab'] ?? 'rules')]);
            self::redirect('rulesets', $back);
        }

        $currentSet = $layer === 'set' && $ref !== '' ? $svc->set($ref) : null;
        $testText = (string) ($_GET['test'] ?? '');
        $testVoice = (string) ($_GET['test_voice'] ?? VoiceCatalog::DEFAULT_VOICE);
        if (!KokoroClient::validVoice($testVoice)) $testVoice = VoiceCatalog::DEFAULT_VOICE;
        $testProject = (string) ($_GET['test_project'] ?? '');
        $testResult = null;
        if ($testText !== '') {
            $effective = $svc->effective($testProject !== '' ? $testProject : null, $testVoice, $layer === 'set' && $ref !== '' ? [$ref] : []);
            $testRow = $testProject !== '' ? (new ProjectRepository())->find($testProject) : null;
            $testResult = $svc->apply(mb_substr($testText, 0, 2000), $effective, $testRow === null || (int) ($testRow['normalize_caps'] ?? 1) === 1) + ['effective' => $effective];
        }
        // ALL-CAPS words in the book that no rule covers (Kokoro would spell them out)
        $capsAudit = [];
        if ($layer === 'project' && $ref !== '') {
            $auditProject = (new ProjectRepository())->find($ref);
            $voiceForAudit = (string) ($auditProject['active_voice_profile_id'] ?? '') ?: VoiceCatalog::DEFAULT_VOICE;
            $capsAudit = array_slice(PronunciationService::capsAudit((new NarrationService())->sourceTexts($ref), $svc->effective($ref, $voiceForAudit)), 0, 80);
        }

        self::view('pronunciation', [
            'svc' => $svc, 'layers' => $layers, 'layer' => $layer, 'ref' => $ref, 'projects' => $projects, 'sets' => $sets,
            'currentSet' => $currentSet, 'rules' => ($layer !== 'set' || $currentSet) && ($layer === 'default' || $ref !== '') ? $svc->rules($layer, $ref) : [],
            'projectSets' => $layer === 'project' && $ref !== '' ? $svc->projectSets($ref) : [],
            'voiceGroups' => VoiceCatalog::GROUPS, 'bundled' => PronunciationService::BUNDLED,
            'testText' => $testText, 'testVoice' => $testVoice, 'testProject' => $testProject, 'testResult' => $testResult,
            'capsAudit' => $capsAudit, 'capsOn' => $layer === 'project' && $ref !== '' ? (int) (($auditProject['normalize_caps'] ?? 1)) === 1 : true,
            'prefillWritten' => mb_substr((string) ($_GET['p'] ?? ''), 0, 100), 'prefillSpoken' => mb_substr((string) ($_GET['s'] ?? ''), 0, 200),
        ]);
    }

    /** GET ?page=pronunciation-example&project=ID&q=word -> {ok, sentence}: a sentence of the book containing the word. */
    private static function pronunciationExample():void
    {
        $project = (string) ($_GET['project'] ?? '');
        $match = (string) ($_GET['match'] ?? '');
        $hits = (new NarrationService())->exampleHits($project !== '' ? $project : null, (string) ($_GET['q'] ?? ''), ($_GET['whole'] ?? '1') !== '0', ($_GET['case'] ?? '0') === '1', 60, in_array($match, PronunciationService::MATCH_TYPES, true) ? $match : null);
        $sentences = array_column($hits, 'sentence');
        $where = array_map(static fn (array $h): array => [
            'url' => base_url('chapter-review') . '&id=' . rawurlencode($h['project']) . '&section=' . rawurlencode($h['section']) . '&b=' . rawurlencode($h['block']),
            'label' => $h['chapter'] !== '' ? $h['chapter'] : 'the book',
        ], $hits);
        self::json(['ok' => true, 'sentences' => $sentences, 'where' => $where, 'sentence' => $sentences[0] ?? null]);
    }

    /**
     * One chapter-to-be, read through: GET ?page=section-preview&id=PROJECT&section=LOGICAL_ID. POST keeps or skips it, renames it,
     * and may carry on to the next one, so a whole book can be gone through quickly.
     */
    private static function sectionPreview():void
    {
        self::redirect('arrange', ['id' => (string) ($_GET['id'] ?? $_POST['id'] ?? ''), 'section' => (string) ($_GET['section'] ?? $_POST['section'] ?? '')]);
    }

    /**
     * What a person is told when something fails. Problems they can act on (a bad value, a missing file, a step that
     * must come first) keep their own message; database and other internal errors are logged and summarised.
     */
    public static function userMessage(Throwable $error): string
    {
        if ($error instanceof \PDOException || $error instanceof \Error || !($error instanceof \InvalidArgumentException || $error instanceof \RuntimeException)) {
            error_log(get_class($error) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine());
            return str_contains($error->getMessage(), 'database is locked')
                ? 'The database was busy with background work. Try again in a moment.'
                : 'Something went wrong on the server. Try again; if it keeps happening, the application log has the details.';
        }
        return $error->getMessage();
    }

    /**
     * A book's pronunciation: its rules with the rules it inherits, suggestions from the text, and the shared rulesets
     * it uses. Changes post to the Rulesets handlers and come back here.
     */
    private static function pronounce():void
    {
        $project = self::requireProject();
        $id = (string) $project['id'];
        $tab = in_array($_GET['tab'] ?? '', ['rules', 'suggestions', 'shared'], true) ? (string) $_GET['tab'] : 'rules';
        $svc = new PronunciationService();
        $voice = (string) ($project['active_voice_profile_id'] ?? '') ?: VoiceCatalog::DEFAULT_VOICE;
        $own = $svc->rules('project', $id);
        $inherited = array_values(array_filter(PronunciationService::order(array_values($svc->effective($id, $voice))), static fn (array $r): bool => ($r['layer'] ?? '') !== 'project'));
        $kind = (string) ($_GET['kind'] ?? 'proper');
        if (!isset(TextCheckService::KINDS[$kind])) $kind = 'proper';
        $suggestions = [];
        $kindCounts = [];
        if ($tab === 'suggestions' && ($_GET['view'] ?? '') !== 'online') {
            $texts = (new NarrationService())->sourceTexts($id);
            $effective = $svc->effective($id, $voice);
            $dictionary = TextCheckService::dictionary();
            $caps = (int) ($project['normalize_caps'] ?? 1) === 1;
            foreach (array_keys(TextCheckService::KINDS) as $k) {
                $found = TextCheckService::scan($texts, $k, $effective, $dictionary, $caps);
                $kindCounts[$k] = count($found);
                if ($k === $kind) $suggestions = $found;
            }
        }
        $linked = $svc->projectSets($id);
        $linkedIds = array_column($linked, 'id');
        $online = $tab === 'suggestions' && ($_GET['view'] ?? '') === 'online';
        $checks = new \App\Services\PronCheckService();
        self::view('pronounce', [
            'online' => $online, 'checkResults' => $checks->results($id), 'checkRunning' => (new \App\Services\TaskService())->isBusy($id, 'pron_check'), 'checkRegion' => $checks->region($id), 'checkSubject' => $checks->subject($id),'checkMinimum' => $checks->minimum($id), 'checkerReady' => PronCheckClient::configured(),
            'project' => $project, 'tab' => $tab, 'own' => $own, 'inherited' => $inherited, 'voice' => $voice,
            'kind' => $kind, 'suggestions' => $suggestions, 'kindCounts' => $kindCounts, 'hasDictionary' => TextCheckService::dictionary() !== null,
            'linked' => $linked, 'available' => array_values(array_filter($svc->sets(), static fn (array $s): bool => !in_array($s['id'], $linkedIds, true))),
        ]);
    }

    /** POST from Diagnostics: where the pronunciation checker is, and its key (a blank key keeps the old one). */
    private static function pronChecker():void
    {
        self::requirePost();
        self::requireCsrf();
        try {
            if (PronCheckClient::fromEnvironment()) throw new \InvalidArgumentException('The checker is set in the container\'s environment (PRON_CHECK_URL), so it cannot be changed here.');
            PronCheckClient::save((string) ($_POST['url'] ?? ''), (string) ($_POST['key'] ?? ''));
            $problem = (new PronCheckClient())->problem();
            if (!PronCheckClient::configured()) self::flash('success', 'Pronunciation checker removed.');
            elseif ($problem === null) self::flash('success', 'Pronunciation checker connected.');
            else self::flash('error', 'Saved, but: ' . $problem);
        } catch (Throwable $error) {
            self::flash('error', self::userMessage($error));
        }
        self::redirect('diagnostics');
    }

    /** POST from Pronunciation, Suggestions: start a check online, make rules from it, or dismiss a word. */
    private static function pronCheck():void
    {
        self::requirePost();
        self::requireCsrf();
        $project = self::requireProject();
        $id = (string) $project['id'];
        $svc = new \App\Services\PronCheckService();
        try {
            if (($project['status'] ?? '') === 'ARCHIVED') throw new \InvalidArgumentException('Restore the book before changing it.');
            switch ((string) ($_POST['action'] ?? '')) {
                case 'start':
                    $kinds = array_values(array_map('strval', is_array($_POST['kinds'] ?? null) ? $_POST['kinds'] : []));
                    $svc->start($id, $kinds, ($_POST['recheck'] ?? '') === '1', isset($_POST['region']) ? (string) $_POST['region'] : null, false, isset($_POST['subject']) ? (string) $_POST['subject'] : null);
                    self::flash('success', 'Checking pronunciations online in the background, about one word a second. The Activity button shows how far it is; the results appear here.');
                    break;
                case 'make':
                    $svc->makeRule($id, (string) ($_POST['word'] ?? ''), ($_POST['spelling'] ?? '') === '1');
                    self::flash('success', 'Rule made for “' . (string) ($_POST['word'] ?? '') . '”.');
                    break;
                case 'compare':
                    $svc->start($id, [], false, null, true);
                    self::flash('success', 'Comparing with the voice in the background; it takes a few seconds.');
                    break;
                case 'minimum':
                    $svc->setMinimum($id, (int) ($_POST['minimum'] ?? PronCheckClient::DEFAULT_MINIMUM));
                    break;
                case 'make_all':
                    $n = $svc->makeRules($id, (string) ($_POST['confidence'] ?? 'high'));
                    self::flash('success', $n > 0 ? "Made $n rule" . ($n === 1 ? '' : 's') . '. Listen to a few in the sound test.' : 'No results at that confidence.');
                    break;
                case 'dismiss':
                    $svc->dismiss($id, (string) ($_POST['word'] ?? ''));
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown action.');
            }
        } catch (Throwable $error) {
            self::flash('error', self::userMessage($error));
        }
        self::redirect('pronounce', ['id' => $id, 'tab' => 'suggestions', 'view' => 'online']);
    }

    /** POST from Settings: switch the spoken title on or off and keep its text. */
    private static function introSettings():void
    {
        self::requirePost();
        self::requireCsrf();
        $project = self::requireProject();
        $id = (string) $project['id'];
        try {
            if (($project['status'] ?? '') === 'ARCHIVED') throw new \InvalidArgumentException('Restore the book before changing it.');
            $reset = ($_POST['reset'] ?? '') === '1';
            (new \App\Services\IntroService())->save($id, ($_POST['enabled'] ?? '') === '1', $reset ? '' : (string) ($_POST['text'] ?? ''));
            self::flash('success', $reset ? 'The spoken title is back to the standard text.' : (($_POST['enabled'] ?? '') === '1' ? 'Spoken title saved. It goes in the next time the audiobook is built.' : 'Spoken title switched off.'));
        } catch (Throwable $error) {
            self::flash('error', self::userMessage($error));
        }
        header('Location: ' . base_url('project', ['id' => $id]) . '#intro', true, 303);
        exit;
    }

    /** GET ?page=activity&id=PROJECT -> JSON {worker, active, items[]}: the background work for a project, for the activity bar. */
    private static function activity():void
    {
        $id = (string) ($_GET['id'] ?? '');
        if ($id !== '' && !(new ProjectRepository())->find($id)) { self::json(['ok' => false, 'message' => 'Project not found.'], 404); return; }
        self::json(['ok' => true, 'scope' => $id === '' ? 'all' : 'book'] + (new \App\Services\TaskService())->activity($id === '' ? null : $id));
    }

    /** POST ?page=activity-clear {_token, id?} -> {ok, cleared}: hides finished and failed work from the activity list (one book, or all). */
    private static function activityClear():void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); self::json(['ok' => false, 'message' => 'POST is required.'], 405); return; }
        $p = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($p)) { self::json(['ok' => false, 'message' => 'The request was not valid JSON.'], 400); return; }
        if (!Csrf::validate(isset($p['_token']) ? (string) $p['_token'] : null)) { self::json(['ok' => false, 'message' => 'Security token expired. Reload the page.'], 419); return; }
        $id = (string) ($p['id'] ?? '');
        if ($id !== '' && !(new ProjectRepository())->find($id)) { self::json(['ok' => false, 'message' => 'Project not found.'], 404); return; }
        self::json(['ok' => true, 'cleared' => (new \App\Services\TaskService())->clearFinished($id === '' ? null : $id)]);
    }

    /** GET ?page=textchecks&id=PROJECT&kind=proper|number|... : things in the book a voice may read badly, to turn into rules. */
    private static function textChecks():void
    {
        self::redirect('pronounce', ['id' => (string) ($_GET['id'] ?? ''), 'tab' => 'suggestions'] + (isset($_GET['kind']) ? ['kind' => (string) $_GET['kind']] : []));
    }

    /** GET ?page=rulesets-regex&pattern=...&case=0|1&project=ID -> {valid, error, count, capped, examples:[{before,match,after}]}: a live check of a regex against the book. */
    private static function regexPreview():void
    {
        $pattern = trim((string) ($_GET['pattern'] ?? ''));
        $case = ($_GET['case'] ?? '0') === '1';
        $problem = PronunciationService::validateRegex($pattern, $case);
        if ($problem !== null) { self::json(['ok' => true, 'valid' => false, 'error' => $problem, 'count' => 0, 'examples' => []]); return; }
        $project = (string) ($_GET['project'] ?? '');
        $cap = 200;
        $sentences = (new NarrationService())->exampleSentences($project !== '' ? $project : null, $pattern, true, $case, $cap, 'regex');
        $re = PronunciationService::regexFull($pattern, $case);
        $examples = [];
        $replacement = array_key_exists('replacement', $_GET) ? (string) $_GET['replacement'] : null;
        $svc = new PronunciationService();
        $rules = $replacement === null ? [] : $svc->withTemporary([], [['pattern' => $pattern, 'replacement' => $replacement, 'match_type' => 'regex', 'case_sensitive' => $case]]);
        foreach (array_slice($sentences, 0, 6) as $sentence) {
            if (preg_match($re, $sentence, $m, PREG_OFFSET_CAPTURE) === 1) {
                $examples[] = ['before' => substr($sentence, 0, $m[0][1]), 'match' => $m[0][0], 'after' => substr($sentence, $m[0][1] + strlen($m[0][0])),
                    'result' => $rules === [] ? null : $svc->apply($sentence, $rules, false)['text']];
            }
        }
        self::json(['ok' => true, 'valid' => true, 'error' => '', 'count' => count($sentences), 'capped' => count($sentences) >= $cap, 'examples' => $examples]);
    }

    /** POST JSON {_token, word, how, voice?} -> spellings the voice reads as "how" (the sound test's "Find a spelling"). */
    private static function pronFind():void
    {
        $fail = static function (int $status, string $message): void { self::json(['ok' => false, 'message' => $message], $status); };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $fail(405, 'POST is required.'); return; }
        $p = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($p)) { $fail(400, 'The request was not valid JSON.'); return; }
        if (!Csrf::validate(isset($p['_token']) ? (string) $p['_token'] : null)) { $fail(419, 'Security token expired. Reload the page.'); return; }
        try {
            $british = str_starts_with((string) ($p['voice'] ?? ''), 'b');
            self::json(['ok' => true] + (new PronCheckClient())->find((string) ($p['word'] ?? ''), (string) ($p['how'] ?? ''), $british));
        } catch (\InvalidArgumentException $error) {
            $fail(422, $error->getMessage());
        } catch (\RuntimeException $error) {
            $fail(502, $error->getMessage());
        }
    }

    /** POST JSON {_token, layer, ref, pattern, replacement, whole_word?, case_sensitive?, note?} -> saves one rule (used by the chapter workbench). */
    private static function pronunciationRule():void
    {
        $fail = static function (int $status, string $message): void { self::json(['ok' => false, 'message' => $message], $status); };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $fail(405, 'POST is required.'); return; }
        $p = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($p)) { $fail(400, 'The request was not valid JSON.'); return; }
        if (!Csrf::validate(isset($p['_token']) ? (string) $p['_token'] : null)) { $fail(419, 'Security token expired. Reload the page.'); return; }
        try {
            $layer = (string) ($p['layer'] ?? '');
            if (!isset(PronunciationService::LAYER_LABELS[$layer])) throw new \InvalidArgumentException('Choose where to save the rule.');
            (new PronunciationService())->save($layer, $layer === 'default' ? '' : (string) ($p['ref'] ?? ''), (string) ($p['pattern'] ?? ''), (string) ($p['replacement'] ?? ''), !empty($p['case_sensitive']), !array_key_exists('whole_word', $p) || !empty($p['whole_word']), (string) ($p['note'] ?? ''), in_array((string) ($p['match_type'] ?? ''), PronunciationService::MATCH_TYPES, true) ? (string) $p['match_type'] : null);
            self::json(['ok' => true, 'message' => 'Rule saved.']);
        } catch (\InvalidArgumentException $error) {
            $fail(422, $error->getMessage());
        } catch (Throwable $error) {
            error_log($error->__toString());
            $fail(500, 'The rule could not be saved.');
        }
    }

    /** POST {_token, text, voice, speed, apply_pronunciation?, project_id?, temp_rules?} -> audio/wav, or JSON {ok:false,message} on failure. */
    private static function voicePreview():void
    {
        $fail = static function (int $status, string $message): void {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
        };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $fail(405, 'POST is required.'); return; }
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) { $fail(400, 'The request was not valid JSON.'); return; }
        if (!Csrf::validate(isset($payload['_token']) ? (string) $payload['_token'] : null)) { $fail(419, 'Security token expired. Reload the page.'); return; }
        $text = (string) ($payload['text'] ?? '');
        if (mb_strlen($text) > KokoroClient::MAX_PREVIEW_CHARS) { $fail(422, 'Preview text is limited to ' . KokoroClient::MAX_PREVIEW_CHARS . ' characters.'); return; }
        $voice = (string) ($payload['voice'] ?? '');
        if (!KokoroClient::validVoice($voice)) { $fail(422, 'That voice name is not valid.'); return; }
        $spoken = $text;
        if (!empty($payload['apply_pronunciation'])) {
            $project = isset($payload['project_id']) && $payload['project_id'] !== '' ? (string) $payload['project_id'] : null;
            $row = $project !== null ? (new ProjectRepository())->find($project) : null;
            if ($project !== null && !$row) { $fail(404, 'Project not found.'); return; }
            $service = new PronunciationService();
            // the series set being edited counts too, so a test sentence hears the rest of that set
            $effective = $service->effective($project, $voice, isset($payload['set_id']) && $payload['set_id'] !== '' ? [(string) $payload['set_id']] : []);
            // rules being tried right now, not saved anywhere
            if (!empty($payload['temp_rules']) && is_array($payload['temp_rules'])) $effective = $service->withTemporary($effective, $payload['temp_rules']);
            $spoken = $service->apply($text, $effective, $row === null || (int) ($row['normalize_caps'] ?? 1) === 1)['text'];
        }
        // Do not hold the session lock while Kokoro is generating.
        session_write_close();
        try {
            $wav = (new KokoroClient())->speech($spoken, $voice, (float) ($payload['speed'] ?? 1.0));
        } catch (\InvalidArgumentException $error) {
            $fail(422, $error->getMessage()); return;
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $fail(502, $error->getMessage()); return;
        }
        http_response_code(200);
        header('Content-Type: audio/wav');
        header('Content-Length: ' . strlen($wav));
        header('Cache-Control: no-store');
        $said = mb_substr($spoken, 0, 600);
        while (strlen(rawurlencode($said)) > 1800) $said = mb_substr($said, 0, (int) (mb_strlen($said) * 0.8));
        header('X-Spoken-Text: ' . rawurlencode($said));
        header('Access-Control-Expose-Headers: X-Spoken-Text');
        echo $wav;
    }

    /** Chapter list of a project with word counts and approval state. */
    private static function chapters():void
    {
        $project = self::requireProject();
        $projectId = (string) $project['id'];
        $narration = new NarrationService();
        $back = (string) ($_POST['section'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!Csrf::validate(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) throw new \InvalidArgumentException('Security token expired. Try again.');
                if ($project['status'] === 'ARCHIVED') throw new \InvalidArgumentException('Restore the project before generating audio.');
                $action = (string) ($_POST['action'] ?? '');
                if ($action === 'build_m4b') {
                    (new \App\Services\TaskService())->enqueue($projectId, 'm4b');
                    self::flash('success', 'Building the audiobook in the background. The Activity button at the top shows how it is going; the download appears here when it is done.');
                    self::redirect('export', ['id' => $projectId]);
                }
                if ($action === 'approve_all') {
                    // Approves every included chapter that is not approved for its current text (new, or changed since approval).
                    $approvedNow = 0;
                    $skipped = 0;
                    foreach ($narration->overview($projectId) as $row) {
                        if ($row['status'] === 'approved') continue;
                        if ((int) $row['words'] === 0) { $skipped++; continue; }
                        $narration->approve($projectId, (string) $row['id']);
                        $approvedNow++;
                    }
                    self::flash('success', $approvedNow > 0 ? "Approved $approvedNow chapter" . ($approvedNow === 1 ? '' : 's') . ($skipped > 0 ? " ($skipped with no text left out)" : '') . '. Make their audio when you are ready.' : 'Every chapter with text was already approved.');
                    self::redirect('proofread', ['id' => $projectId] + ($back !== '' ? ['section' => $back] : []));
                }
                if ($action !== 'generate_all') throw new \InvalidArgumentException('Unknown action.');
                $overview = $narration->overview($projectId);
                $states = (new GenerationService())->summaries($projectId, $overview);
                $jobs = new JobService();
                $queued = 0;
                foreach ($overview as $row) {
                    if (!in_array($states[$row['id']]['state'], ['none', 'partial', 'outdated', 'failed'], true)) continue;
                    $jobs->enqueue($projectId, (string) $row['id']);
                    $queued++;
                }
                self::flash('success', $queued > 0 ? "Making audio for $queued chapter" . ($queued === 1 ? '' : 's') . '. The Activity button at the top shows the progress.' : 'Nothing to make: every approved chapter is up to date or already waiting.');
            } catch (Throwable $error) {
                self::flash('error', self::userMessage($error));
            }
            self::redirect('proofread', ['id' => $projectId] + ($back !== '' ? ['section' => $back] : []));
        }
        self::redirect('proofread', ['id' => $projectId]);
    }

    /**
     * Proofread and listen: one chapter's narration beside the list of chapters and the sound test. With no chapter
     * given it opens the first one that still needs a look.
     */
    private static function proofread():void
    {
        $project = self::requireProject();
        $projectId = (string) $project['id'];
        $svc = new NarrationService();
        $rows = $svc->overview($projectId);
        if (!$rows) { self::flash('error', 'No chapter is read aloud yet. Choose what is read on Arrange.'); self::redirect('arrange', ['id' => $projectId]); }
        $sectionId = (string) ($_GET['section'] ?? '');
        if (!in_array($sectionId, array_column($rows, 'id'), true)) {
            $sectionId = (string) $rows[0]['id'];
            foreach ($rows as $r) if ($r['status'] !== 'approved' && (int) $r['words'] > 0) { $sectionId = (string) $r['id']; break; }
        }
        $generation = new GenerationService();
        try {
            $summaries = $generation->summaries($projectId, $rows);
        } catch (\InvalidArgumentException $error) {
            $summaries = [];
        }
        try {
            $audio = $generation->status($projectId, $sectionId);
        } catch (\InvalidArgumentException $error) {
            $audio = null;
        }
        $order = array_column($rows, 'id');
        $at = array_search($sectionId, $order, true);
        $todo = 0;
        foreach ($rows as $r) if ($r['status'] === 'approved' && in_array($summaries[$r['id']]['state'] ?? '', ['none', 'partial', 'outdated', 'failed'], true)) $todo++;
        self::view('proofread', [
            'project' => $project, 'rows' => $rows, 'summaries' => $summaries, 'chapter' => $svc->chapter($projectId, $sectionId), 'audio' => $audio,
            'job' => (new JobService())->forChapter($sectionId), 'workerOnline' => JobService::workerOnline(), 'audioToken' => Csrf::token(),
            'prevId' => $at !== false && $at > 0 ? $order[$at - 1] : null, 'nextId' => $at !== false && $at < count($order) - 1 ? $order[$at + 1] : null,
            'focus' => (string) ($_GET['b'] ?? ''), 'todo' => $todo,
        ]);
    }

    /** One chapter: original blocks beside the narration text, per-block exclude and edit, approve. */
    private static function chapterReview():void
    {
        $project = self::requireProject();
        $projectId = (string) $project['id'];
        $sectionId = (string) ($_GET['section'] ?? $_POST['section'] ?? '');
        $svc = new NarrationService();

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $focus = (string) ($_POST['block_id'] ?? '');
            try {
                if (!Csrf::validate(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) throw new \InvalidArgumentException('Security token expired. Try again.');
                if ($project['status'] === 'ARCHIVED') throw new \InvalidArgumentException('Restore the project before editing chapters.');
                switch ((string) ($_POST['action'] ?? '')) {
                    case 'toggle_block':
                        $svc->setExcluded($projectId, $sectionId, $focus, ($_POST['excluded'] ?? '') === '1');
                        break;
                    case 'set_override':
                        $svc->setOverride($projectId, $sectionId, $focus, (string) ($_POST['text'] ?? ''));
                        self::flash('success', 'Narration text saved for that paragraph.');
                        break;
                    case 'clear_override':
                        $svc->setOverride($projectId, $sectionId, $focus, '');
                        break;
                    case 'approve':
                        $svc->approve($projectId, $sectionId);
                        self::flash('success', 'Approved.');
                        if (($_POST['then'] ?? '') !== '') { self::redirect('proofread', ['id' => $projectId, 'section' => (string) $_POST['then']]); }
                        break;
                    case 'unapprove':
                        $svc->unapprove($projectId, $sectionId);
                        break;
                    default:
                        throw new \InvalidArgumentException('Unknown action.');
                }
            } catch (Throwable $error) {
                self::flash('error', self::userMessage($error));
            }
            self::redirect('proofread', ['id' => $projectId, 'section' => $sectionId] + ($focus !== '' ? ['b' => $focus] : []));
        }
        self::redirect('proofread', ['id' => $projectId, 'section' => $sectionId] + (($_GET['b'] ?? '') !== '' ? ['b' => (string) $_GET['b']] : []));
    }

    /** POST JSON {_token, project_id, section, action: prepare|next|assemble|reset, segment_id?} -> JSON. One step per call. */
    private static function chapterAudio():void
    {
        $fail = static function (int $status, string $message): void { self::json(['ok' => false, 'message' => $message], $status); };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $fail(405, 'POST is required.'); return; }
        $p = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($p)) { $fail(400, 'The request was not valid JSON.'); return; }
        if (!Csrf::validate(isset($p['_token']) ? (string) $p['_token'] : null)) { $fail(419, 'Security token expired. Reload the page.'); return; }
        $projectId = (string) ($p['project_id'] ?? '');
        $sectionId = (string) ($p['section'] ?? '');
        if (!(new ProjectRepository())->find($projectId)) { $fail(404, 'Project not found.'); return; }
        // Generation takes seconds per segment: do not hold the session lock meanwhile.
        session_write_close();
        $gen = new GenerationService();
        try {
            switch ((string) ($p['action'] ?? '')) {
                case 'prepare':
                    self::json(['ok' => true] + $gen->prepare($projectId, $sectionId));
                    return;
                case 'next':
                    self::json(['ok' => true] + $gen->generateNext($projectId, $sectionId));
                    return;
                case 'assemble':
                    self::json(['ok' => true] + $gen->assemble($projectId, $sectionId));
                    return;
                case 'enqueue':
                    $jobs = new JobService();
                    $job = $jobs->enqueue($projectId, $sectionId);
                    self::json(['ok' => true, 'job' => $job, 'worker_online' => JobService::workerOnline()]);
                    return;
                case 'cancel':
                    (new JobService())->cancel($projectId, $sectionId);
                    self::json(['ok' => true]);
                    return;
                case 'job':
                    $job = (new JobService())->forChapter($sectionId);
                    self::json(['ok' => true, 'job' => $job, 'counts' => $gen->status($projectId, $sectionId)['counts'], 'worker_online' => JobService::workerOnline()]);
                    return;
                case 'chapter_settings':
                    $voice = isset($p['voice']) ? trim((string) $p['voice']) : '';
                    $speed = isset($p['speed']) && $p['speed'] !== '' && $p['speed'] !== null ? (float) $p['speed'] : null;
                    ChapterSettings::set($projectId, $sectionId, $voice === '' ? null : $voice, $speed);
                    self::json(['ok' => true]);
                    return;
                case 'reset_all':
                    self::json(['ok' => true, 'reset' => $gen->resetAll($projectId, $sectionId)]);
                    return;
                case 'reset':
                    $segment = isset($p['segment_id']) && $p['segment_id'] !== '' ? (string) $p['segment_id'] : null;
                    self::json(['ok' => true, 'reset' => $gen->reset($projectId, $sectionId, $segment)]);
                    return;
                default:
                    $fail(422, 'Unknown action.');
                    return;
            }
        } catch (\InvalidArgumentException $error) {
            $fail(422, $error->getMessage());
        } catch (Throwable $error) {
            error_log($error->__toString());
            $fail(500, config('app.debug', false) ? $error->getMessage() : 'The step failed. Check the application log.');
        }
    }

    /** POST JSON {_token, project_id, section, block_id, action: save_paragraph|reset_paragraph|skip, text?, excluded?} -> JSON. */
    private static function chapterText():void
    {
        $fail = static function (int $status, string $message): void { self::json(['ok' => false, 'message' => $message], $status); };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $fail(405, 'POST is required.'); return; }
        $p = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($p)) { $fail(400, 'The request was not valid JSON.'); return; }
        if (!Csrf::validate(isset($p['_token']) ? (string) $p['_token'] : null)) { $fail(419, 'Security token expired. Reload the page.'); return; }
        $project = (new ProjectRepository())->find((string) ($p['project_id'] ?? ''));
        if (!$project) { $fail(404, 'Project not found.'); return; }
        if ($project['status'] === 'ARCHIVED') { $fail(422, 'Restore the project before editing chapters.'); return; }
        $narration = new NarrationService();
        $section = (string) ($p['section'] ?? '');
        $block = (string) ($p['block_id'] ?? '');
        try {
            switch ((string) ($p['action'] ?? '')) {
                case 'save_paragraph': $narration->saveParagraphText((string) $project['id'], $section, $block, (string) ($p['text'] ?? '')); break;
                case 'reset_paragraph': $narration->resetParagraph((string) $project['id'], $section, $block); break;
                case 'skip': $narration->setExcluded((string) $project['id'], $section, $block, !empty($p['excluded'])); break;
                default: $fail(422, 'Unknown action.'); return;
            }
            self::json(['ok' => true]);
        } catch (\InvalidArgumentException $error) {
            $fail(422, $error->getMessage());
        } catch (Throwable $error) {
            error_log($error->__toString());
            $fail(500, 'The change could not be saved.');
        }
    }

    /** GET ?page=audio&id=PROJECT&kind=segment|chapter&ref=ID[&download=1&name=...]: streams stored chapter or segment audio, with Range support. */
    private static function audio():void
    {
        $projectId = (string) ($_GET['id'] ?? '');
        $path = (new GenerationService())->audioFile($projectId, (string) ($_GET['kind'] ?? ''), (string) ($_GET['ref'] ?? ''));
        if ($path === null) { self::notFound(); return; }
        self::streamFile($path, ($_GET['download'] ?? '') === '1' ? (string) ($_GET['name'] ?? 'audio') : null);
    }

    /** GET ?page=export&id=PROJECT&kind=m4b|zip: the finished audiobook file, or a ZIP of the chapter m4a files. */
    private static function export():void
    {
        $projectId = (string) ($_GET['id'] ?? '');
        $project = (new ProjectRepository())->find($projectId);
        if (!$project) { self::notFound(); return; }
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $project['title']), '-') ?: 'audiobook';
        $exports = new ExportService();
        $kind = (string) ($_GET['kind'] ?? '');
        if ($kind === '') {                                      // the Export page itself
            $rows = (new NarrationService())->overview($projectId);
            try {
                $summaries = (new GenerationService())->summaries($projectId, $rows);
            } catch (\InvalidArgumentException $error) {
                $summaries = [];
            }
            $exportRow = $exports->exportRow($projectId);
            $ready = count(array_filter($summaries, static fn (array $s): bool => $s['state'] === 'ready'));
            $meta = (new SourceAnalysisRepository())->metadata($projectId);
            self::view('export', [
                'project' => $project, 'rows' => $rows, 'summaries' => $summaries, 'exportRow' => $exportRow, 'ready' => $ready,
                'exportCurrent' => $exportRow !== null && $ready > 0 && $exports->isCurrent($projectId, $exportRow),
                'building' => (new \App\Services\TaskService())->isBusy($projectId), 'author' => (string) ($meta['creator'] ?? ''),
            ]);
            return;
        }
        if ($kind === 'm4b') {
            $path = $exports->m4bFile($projectId);
            if ($path === null) { self::notFound(); return; }
            self::streamFile($path, $slug);
            return;
        }
        if ($kind === 'zip') {
            try {
                $zip = $exports->buildZip($projectId);
            } catch (\InvalidArgumentException $error) {
                self::flash('error', self::userMessage($error));
                self::redirect('chapters', ['id' => $projectId]);
            }
            session_write_close();
            ignore_user_abort(true);                                 // finish and clean up even if the browser stops reading
            register_shutdown_function(static function () use ($zip): void { @unlink($zip); });
            self::streamFile($zip, $slug . '-m4a.zip', 'application/zip', true);
            return;
        }
        self::notFound();
    }

    /** Streams a file with Range support. A name makes it a download. $delete removes the file afterwards. */
    private static function streamFile(string $path, ?string $downloadName, ?string $mime = null, bool $delete = false):void
    {
        session_write_close();
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime ??= match ($ext) { 'm4a', 'm4b' => 'audio/mp4', 'wav' => 'audio/wav', 'zip' => 'application/zip', default => 'application/octet-stream' };
        $size = (int) filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) $_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); } else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
            if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); return; }
            $status = 206;
        }
        http_response_code($status);
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-cache');
        if ($status === 206) header("Content-Range: bytes $start-$end/$size");
        header('Content-Length: ' . ($end - $start + 1));
        if ($downloadName !== null) {
            $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'audio';
            header('Content-Disposition: attachment; filename="' . $clean . ($ext !== '' && !str_ends_with(strtolower($clean), '.' . $ext) ? '.' . $ext : '') . '"');
        }
        set_time_limit(0);
        $h = fopen($path, 'rb');
        fseek($h, $start);
        for ($left = $end - $start + 1; $left > 0 && !feof($h); ) {
            $chunk = fread($h, min(1 << 18, $left));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($h);
        if ($delete) @unlink($path);
    }

    /** The Library: every book with where it stands and its next step. */
    private static function projects():void
    {
        $show = ($_GET['archived'] ?? '') === '1';
        $assets = new SourceAssetRepository();
        $analysis = new SourceAnalysisRepository();
        $cards = [];
        foreach ((new ProjectRepository())->all($show) as $p) {
            $asset = $assets->findByProject((string) $p['id']);
            $analyzed = $analysis->analyzed((string) $p['id']);
            try {
                $flow = ProjectWorkflow::build($p, $asset !== null, $analyzed, $asset['original_name'] ?? null);
            } catch (Throwable $e) {
                error_log('library card: ' . $e->getMessage());
                $flow = null;
            }
            $meta = $analyzed ? $analysis->metadata((string) $p['id']) : null;
            $cards[] = ['project' => $p, 'flow' => $flow, 'author' => (string) ($meta['creator'] ?? '')];
        }
        self::view('projects', ['cards' => $cards, 'showArchived' => $show, 'archivedCount' => count((new ProjectRepository())->all(true)) - count((new ProjectRepository())->all(false))]);
    }

    /** Arrange: which parts of the book are read, their names and their order. */
    private static function arrange():void
    {
        $project = self::requireProject();
        $id = (string) $project['id'];
        $analysis = new SourceAnalysisRepository();
        if (!$analysis->analyzed($id)) { self::flash('error', 'Import the EPUB first, on the Settings page.'); self::redirect('project', ['id' => $id]); }
        $repo = new \App\Repositories\LogicalSectionRepository();
        $svc = new \App\Services\LogicalSectionService();
        if (!$repo->exists($id)) {
            try { $svc->initialize($id); } catch (\InvalidArgumentException $e) { /* nothing to build from yet */ }
        }
        $sections = $repo->all($id);
        $advice = [];
        foreach ($svc->suggestions($id) as $s) $advice[$s['id']] = $s;
        self::view('arrange', [
            'project' => $project, 'sections' => $sections, 'advice' => $advice, 'lastRevision' => $repo->lastRevision($id),
            'sourceFiles' => $analysis->sections($id), 'metadata' => $analysis->metadata($id), 'focus' => (string) ($_GET['section'] ?? ''),
        ]);
    }

    /** POST JSON {_token, project, action, ...} -> {ok, message}: one change on the Arrange page, each one undoable. */
    private static function arrangeAction():void
    {
        $fail = static function (int $status, string $message): void { self::json(['ok' => false, 'message' => $message], $status); };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { $fail(405, 'POST is required.'); return; }
        $x = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($x)) { $fail(400, 'The request was not valid JSON.'); return; }
        if (!Csrf::validate($x['_token'] ?? null)) { $fail(419, 'Security token expired. Reload the page.'); return; }
        $project = (new ProjectRepository())->find((string) ($x['project'] ?? ''));
        if (!$project) { $fail(404, 'Project not found.'); return; }
        if (($project['status'] ?? '') === 'ARCHIVED') { $fail(422, 'Restore the project before changing its chapters.'); return; }
        $p = (string) $project['id'];
        $svc = new \App\Services\LogicalSectionService();
        $ids = array_values(array_map('strval', is_array($x['ids'] ?? null) ? $x['ids'] : []));
        try {
            $message = match ((string) ($x['action'] ?? '')) {
                'rename' => (function () use ($svc, $p, $x): string { $svc->rename($p, (string) ($x['id'] ?? ''), (string) ($x['title'] ?? '')); return 'Saved.'; })(),
                'keep' => (function () use ($svc, $p, $ids, $x): string { $svc->setIncluded($p, $ids, !empty($x['value'])); return 'Saved.'; })(),
                'move' => (function () use ($svc, $p, $x): string { $svc->move($p, (string) ($x['id'] ?? ''), (int) ($x['delta'] ?? 0)); return 'Moved.'; })(),
                'combine' => (function () use ($svc, $p, $ids): string { $svc->combine($p, $ids); return 'Combined ' . count($ids) . ' sections into one chapter.'; })(),
                'split' => (function () use ($svc, $p, $x): string { $svc->split($p, (string) ($x['id'] ?? ''), (string) ($x['block'] ?? ''), (string) ($x['title'] ?? '')); return 'Split into two sections.'; })(),
                'name_chapters' => (function () use ($svc, $p, $ids, $x): string { $svc->nameChapters($p, $ids, (int) ($x['start'] ?? 1), !empty($x['headings'])); return 'Named ' . count($ids) . ' chapters.'; })(),
                'suggest_keep' => (function () use ($svc, $p, $ids): string { $n = $svc->applySuggestions($p, $ids, true, false); return $n > 0 ? "Changed $n section" . ($n === 1 ? '' : 's') . ' to match the suggestion. Undo puts them back.' : 'Everything already matches the suggestion.'; })(),
                'suggest_names' => (function () use ($svc, $p, $ids): string { $n = $svc->applySuggestions($p, $ids, false, true); return $n > 0 ? "Named $n section" . ($n === 1 ? '' : 's') . ' from their text. Undo puts the old names back.' : 'No new names were found in the text.'; })(),
                'undo' => (function () use ($svc, $p): string { $svc->undo($p); return 'Undone.'; })(),
                default => throw new \InvalidArgumentException('Unknown action.'),
            };
            $last = (new \App\Repositories\LogicalSectionRepository())->lastRevision($p);
            self::json(['ok' => true, 'message' => $message, 'undo' => $last ? (string) $last['description'] : null]);
        } catch (Throwable $error) {
            $fail(422, self::userMessage($error));
        }
    }

    /** GET ?page=section-text&id=PROJECT&section=ID -> {ok, title, words, blocks:[{id,text,heading}]}: the reading panel on Arrange. */
    private static function sectionText():void
    {
        $project = (new ProjectRepository())->find((string) ($_GET['id'] ?? ''));
        $repo = new \App\Repositories\LogicalSectionRepository();
        $section = $project ? $repo->find((string) $project['id'], (string) ($_GET['section'] ?? '')) : null;
        if (!$section) { self::json(['ok' => false, 'message' => 'Section not found.'], 404); return; }
        $blocks = array_map(static fn (array $b): array => ['id' => (string) $b['id'], 'text' => (string) $b['source_text'], 'heading' => in_array($b['block_type'], ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)], $repo->blocks((string) $section['id']));
        self::json(['ok' => true, 'title' => (string) $section['title'], 'included' => (int) $section['included'] === 1, 'blocks' => array_slice($blocks, 0, 400), 'more' => max(0, count($blocks) - 400)]);
    }

    /**
     * POST an EPUB from the Library: a new book is made from it in one step (project, upload, analysis, chapters),
     * named after the book's own title, and opens on Arrange.
     */
    private static function quickImport():void
    {
        self::requirePost();
        self::requireCsrf();
        $file = $_FILES['epub'] ?? [];
        $name = (string) ($file['name'] ?? '');
        if ($name === '' || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) { self::flash('error', 'Choose an EPUB file to start a new audiobook.'); self::redirect('projects'); }
        $title = trim((string) preg_replace('/[_\s]+/', ' ', preg_replace('/\.epub$/i', '', basename($name)) ?? ''));
        $title = mb_substr($title !== '' ? $title : 'New audiobook', 0, 160);
        try {
            $project = (new ProjectService())->create($title, 'kokoro', VoiceCatalog::DEFAULT_VOICE, AudioEncoder::DEFAULT_BITRATE);
        } catch (Throwable $error) {
            self::flash('error', self::userMessage($error));
            self::redirect('projects');
        }
        $id = (string) $project['id'];
        try {
            (new EpubUploadService())->upload($id, $file);
            (new EpubAnalysisService())->analyze($id);
            $meta = (new SourceAnalysisRepository())->metadata($id);
            $bookTitle = trim((string) ($meta['title'] ?? ''));
            if ($bookTitle !== '') \App\Core\Database::connection()->prepare('UPDATE projects SET title=:t, updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['t' => mb_substr($bookTitle, 0, 160), 'id' => $id]);
            (new \App\Services\LogicalSectionService())->initialize($id);
            self::flash('success', 'The book is in. Check which parts are read and what each chapter is called.');
            self::redirect('arrange', ['id' => $id]);
        } catch (Throwable $error) {
            self::flash('error', 'The book was created but the EPUB could not be read: ' . self::userMessage($error) . ' You can try another file from its Settings page.');
            self::redirect('project', ['id' => $id]);
        }
    }
    private static function createProject():void{$errors=[];$values=['title'=>'','provider'=>'kokoro','voice'=>VoiceCatalog::DEFAULT_VOICE,'bitrate'=>AudioEncoder::DEFAULT_BITRATE];if($_SERVER['REQUEST_METHOD']==='POST'){$values=['title'=>(string)($_POST['title']??''),'provider'=>(string)($_POST['provider']??'kokoro'),'voice'=>(string)($_POST['voice']??''),'bitrate'=>(int)($_POST['bitrate']??96)];if(!Csrf::validate($_POST['_token']??null))$errors[]='Security token expired. Reload the page and try again.';if(!$errors)try{$project=(new ProjectService())->create($values['title'],$values['provider'],$values['voice'],$values['bitrate']);self::flash('success','Project created.');self::redirect('project',['id'=>$project['id']]);}catch(Throwable $error){$errors[]=$error->getMessage();}}self::view('project-create',['errors'=>$errors,'values'=>$values]);}
    private static function project():void{$project=self::requireProject();$asset=(new SourceAssetRepository())->findByProject($project['id']);$analysis=new SourceAnalysisRepository();self::view('project',['project'=>$project,'sourceAsset'=>$asset,'sourceAnalyzed'=>$analysis->analyzed($project['id'])]);}
    private static function epubUpload():void{$project=self::requireProject();$repo=new SourceAssetRepository();if($repo->findByProject($project['id'])){self::flash('error','This project already has an immutable source EPUB.');self::redirect('project',['id'=>$project['id']]);}$errorMessage=null;if($_SERVER['REQUEST_METHOD']==='POST'){self::requireCsrf();try{$asset=(new EpubUploadService())->upload($project['id'],$_FILES['epub']??[]);self::flash('success','EPUB stored with SHA-256 '.$asset['sha256'].'.');self::redirect('project',['id'=>$project['id']]);}catch(Throwable $error){$errorMessage=$error->getMessage();}}self::view('epub-upload',['project'=>$project,'errorMessage'=>$errorMessage]);}
    private static function epubAnalyze():void{self::requirePost();self::requireCsrf();$project=self::requireProject();try{$result=(new EpubAnalysisService())->analyze($project['id'],($_POST['replace']??'')==='yes');self::flash('success','Created '.$result['sections'].' source sections and '.$result['blocks'].' source blocks.');self::redirect('arrange',['id'=>$project['id']]);}catch(Throwable $error){self::flash('error',$error->getMessage());self::redirect('project',['id'=>$project['id']]);}}
    private static function sourceSections():void{self::redirect('arrange',['id'=>(string)($_GET['id']??'')]);}
    private static function sourceSection():void{$project=self::requireProject();$repo=new SourceAnalysisRepository();$section=$repo->section($project['id'],(string)($_GET['section']??''));if(!$section){self::notFound();return;}self::view('source-section',['project'=>$project,'section'=>$section,'blocks'=>$repo->blocks($section['id'])]);}
    private static function archiveProject():void{self::requirePost();self::requireCsrf();(new ProjectRepository())->archive((string)($_POST['id']??''));self::flash('success','Project archived.');self::redirect('projects');}
    private static function restoreProject():void{self::requirePost();self::requireCsrf();$id=(string)($_POST['id']??'');(new ProjectRepository())->restore($id);self::flash('success','Project restored.');self::redirect('project',['id'=>$id]);}
    private static function deleteProject():void{self::requirePost();self::requireCsrf();$id=(string)($_POST['id']??'');try{(new ProjectService())->deleteArchived($id,(string)($_POST['confirmation']??''));self::flash('success','Project permanently deleted.');self::redirect('projects',['archived'=>'1']);}catch(Throwable $error){self::flash('error',$error->getMessage());self::redirect('project',['id'=>$id]);}}
    public static function health():array{$ffmpeg=configured_path('paths.ffmpeg');$ffprobe=configured_path('paths.ffprobe');$limits=self::uploadLimits();return['application'=>(string)config('app.name','AI Audiobook Studio'),'version'=>trim((string)@file_get_contents(APP_ROOT.'/VERSION')),'status'=>'ok','php'=>PHP_VERSION,'storage_writable'=>is_writable(APP_STORAGE),'database_exists'=>is_file((string)config('database.path')),'epub_upload_ready'=>extension_loaded('zip')&&is_writable((string)config('paths.projects')),'required_extensions'=>['zip'=>extension_loaded('zip'),'dom'=>extension_loaded('dom'),'mbstring'=>extension_loaded('mbstring'),'pdo_sqlite'=>extension_loaded('pdo_sqlite')],'upload_limits'=>$limits,'ffmpeg_present'=>is_file($ffmpeg),'ffmpeg_executable'=>is_executable($ffmpeg),'ffmpeg_version'=>self::binaryVersion($ffmpeg),'ffprobe_present'=>is_file($ffprobe),'ffprobe_executable'=>is_executable($ffprobe),'ffprobe_version'=>self::binaryVersion($ffprobe),'time_utc'=>gmdate('c')];}
    private static function uploadLimits():array{$raw=['PHP upload_max_filesize'=>(string)ini_get('upload_max_filesize'),'PHP post_max_size'=>(string)ini_get('post_max_size'),'Nginx client_max_body_size'=>(string)(getenv('SERVICE_NGINX_CLIENT_MAX_BODY_SIZE')?:''),'Application EPUB limit'=>'512M'];$details=[];foreach($raw as$label=>$value){$bytes=self::sizeToBytes($value);$details[]=['label'=>$label,'configured'=>$value!==''?$value:null,'bytes'=>$bytes,'display'=>$bytes!==null?self::formatBytes($bytes):'Unknown','limiting'=>false];}$known=array_values(array_filter(array_column($details,'bytes'),static fn($v):bool=>is_int($v)&&$v>0));$effective=$known?min($known):null;foreach($details as&$detail)$detail['limiting']=$effective!==null&&$detail['bytes']===$effective;unset($detail);return['effective_bytes'=>$effective,'effective_display'=>$effective!==null?self::formatBytes($effective):'Unknown','limiting_settings'=>array_values(array_map(static fn($d)=>$d['label'],array_filter($details,static fn($d)=>$d['limiting']))),'nginx_detection'=>$raw['Nginx client_max_body_size']!==''?'WebDevOps environment variable':'Unavailable','details'=>$details];}
    private static function sizeToBytes(string$value):?int{$value=trim($value);if($value==='')return null;if($value==='-1')return PHP_INT_MAX;if(!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgt]?)b?$/i',$value,$m))return null;$power=match(strtolower($m[2])){'k'=>1,'m'=>2,'g'=>3,'t'=>4,default=>0};$bytes=(float)$m[1]*(1024**$power);return$bytes>=PHP_INT_MAX?PHP_INT_MAX:(int)floor($bytes);}
    private static function formatBytes(int$bytes):string{if($bytes===PHP_INT_MAX)return'Unlimited';$units=['B','KiB','MiB','GiB','TiB'];$value=(float)$bytes;$unit=0;while($value>=1024&&$unit<count($units)-1){$value/=1024;$unit++;}$precision=$value>=10||floor($value)===$value?0:1;return number_format($value,$precision).' '.$units[$unit];}
    private static function requireProject():array{$id=(string)($_GET['id']??$_POST['id']??'');$project=(new ProjectRepository())->find($id);if(!$project){self::notFound();exit;}return$project;}
    private static function binaryVersion(string$path):?string{if(!is_file($path)||!is_executable($path)||!function_exists('exec'))return null;$out=[];$code=1;@exec(escapeshellarg($path).' -version 2>/dev/null',$out,$code);return$code===0&&!empty($out[0])?trim($out[0]):null;}
    private static function view(string$name,array$data=[]):void{extract($data,EXTR_SKIP);require APP_ROOT.'/app/Views/'.$name.'.php';}
    private static function redirect(string$page,array$params=[]):never{header('Location: '.base_url($page,$params),true,303);exit;}
    private static function flash(string$type,string$message):void{$_SESSION['flash']=['type'=>$type,'message'=>$message];}
    private static function requirePost():void{if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}}
    private static function requireCsrf():void{if(!Csrf::validate($_POST['_token']??null)){http_response_code(419);self::view('error',['message'=>'Security token expired. Reload the page and try again.']);exit;}}
    private static function notFound():void{http_response_code(404);self::view('404');}
    private static function json(array$payload,int$status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);}
}
