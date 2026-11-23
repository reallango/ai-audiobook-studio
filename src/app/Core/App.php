<?php
declare(strict_types=1);
namespace App\Core;
use App\Repositories\ProjectRepository;
use App\Repositories\SourceAssetRepository;
use App\Repositories\SourceAnalysisRepository;
use App\Services\EpubUploadService;
use App\Services\EpubAnalysisService;
use App\Services\ProjectService;
use App\Services\KokoroClient;
use App\Services\VoiceCatalog;
use App\Services\PronunciationService;
use App\Services\NarrationService;
use App\Services\TextCheckService;
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
        try{match($route){'api-health'=>self::json(self::health()),'diagnostics'=>self::view('diagnostics'),'projects','home',''=>self::projects(),'project-create'=>self::createProject(),'project'=>self::project(),'epub-upload'=>self::epubUpload(),'epub-analyze'=>self::epubAnalyze(),'source-sections'=>self::sourceSections(),'source-section'=>self::sourceSection(),'project-archive'=>self::archiveProject(),'project-restore'=>self::restoreProject(),'project-delete'=>self::deleteProject(),'voice-lab'=>self::voiceLab(),'voice-preview'=>self::voicePreview(),'pronunciation'=>self::pronunciation(),'rulesets'=>self::pronunciation(),'pronunciation-rule'=>self::pronunciationRule(),'pronunciation-example'=>self::pronunciationExample(),'rulesets-regex'=>self::regexPreview(),'textchecks'=>self::textChecks(),'section-preview'=>self::sectionPreview(),'chapters'=>self::chapters(),'chapter-review'=>self::chapterReview(),'chapter-audio'=>self::chapterAudio(),'chapter-text'=>self::chapterText(),'audio'=>self::audio(),'export'=>self::export(),default=>self::notFound()};}
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

        $layer = (string) ($_GET['layer'] ?? $_POST['layer'] ?? 'default');
        if (!isset($layers[$layer])) $layer = 'default';
        $ref = (string) ($_GET['ref'] ?? $_POST['ref'] ?? '');
        if ($layer === 'default') $ref = '';
        if ($layer === 'voice' && !KokoroClient::validVoice($ref)) $ref = VoiceCatalog::DEFAULT_VOICE;
        if ($layer === 'set' && !$svc->set($ref)) $ref = $sets ? (string) $sets[0]['id'] : '';
        if ($layer === 'project' && !(new ProjectRepository())->find($ref)) $ref = $projects ? (string) $projects[0]['id'] : '';

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
                self::flash('error', $error->getMessage());
            }
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
        $project = self::requireProject();
        $repo = new \App\Repositories\LogicalSectionRepository();
        $all = $repo->all($project['id']);
        $id = (string) ($_GET['section'] ?? $_POST['section'] ?? '');
        $at = null;
        foreach ($all as $i => $s) if ((string) $s['id'] === $id) $at = $i;
        if ($at === null) { self::notFound(); return; }
        $prev = $at > 0 ? (string) $all[$at - 1]['id'] : null;
        $next = $at < count($all) - 1 ? (string) $all[$at + 1]['id'] : null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            self::requireCsrf();
            $action = (string) ($_POST['action'] ?? '');
            try {
                $rows = array_map(static fn (array $s): array => ['id' => (string) $s['id'], 'title' => (string) $s['title'], 'included' => (int) $s['included']], $all);
                if ($action === 'keep' || $action === 'skip') $rows[$at]['included'] = $action === 'keep' ? 1 : 0;
                elseif ($action === 'rename') {
                    $title = trim((string) ($_POST['title'] ?? ''));
                    if ($title === '' || mb_strlen($title) > 200) throw new \InvalidArgumentException('A name must be 1 to 200 characters.');
                    $rows[$at]['title'] = $title;
                } else throw new \InvalidArgumentException('Unknown action.');
                (new \App\Services\LogicalSectionService())->updateAll($project['id'], $rows);
                self::flash('success', $action === 'rename' ? 'Renamed.' : ($action === 'keep' ? 'Kept in the audiobook.' : 'Left out of the audiobook.'));
            } catch (Throwable $error) {
                self::flash('error', $error->getMessage());
            }
            $goNext = ($_POST['go'] ?? '') === 'next' && $next !== null;
            self::redirect('section-preview', ['id' => $project['id'], 'section' => $goNext ? $next : $id]);
        }
        self::view('section-preview', ['project' => $project, 'section' => $all[$at], 'blocks' => $repo->blocks($id), 'prev' => $prev, 'next' => $next, 'number' => $at + 1, 'total' => count($all)]);
    }

    /** GET ?page=textchecks&id=PROJECT&kind=proper|number|... : things in the book a voice may read badly, to turn into rules. */
    private static function textChecks():void
    {
        $id = (string) ($_GET['id'] ?? '');
        $project = $id !== '' ? (new ProjectRepository())->find($id) : null;
        if (!$project) { self::notFound(); return; }
        $kind = (string) ($_GET['kind'] ?? 'proper');
        if (!isset(TextCheckService::KINDS[$kind])) $kind = 'proper';
        $voice = (string) ($project['active_voice_profile_id'] ?? '') ?: VoiceCatalog::DEFAULT_VOICE;
        $svc = new PronunciationService();
        $dictionary = TextCheckService::dictionary();
        $rows = TextCheckService::scan((new NarrationService())->sourceTexts($id), $kind, $svc->effective($id, $voice), $dictionary, (int) ($project['normalize_caps'] ?? 1) === 1);
        self::view('textchecks', ['project' => $project, 'kind' => $kind, 'rows' => $rows, 'hasDictionary' => $dictionary !== null, 'projectSets' => $svc->projectSets($id), 'voice' => $voice]);
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
        header('X-Spoken-Text: ' . rawurlencode(mb_substr($spoken, 0, 600)));
        header('Access-Control-Expose-Headers: X-Spoken-Text');
        echo $wav;
    }

    /** Chapter list of a project with word counts and approval state. */
    private static function chapters():void
    {
        $project = self::requireProject();
        $projectId = (string) $project['id'];
        $narration = new NarrationService();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!Csrf::validate(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) throw new \InvalidArgumentException('Security token expired. Try again.');
                if ($project['status'] === 'ARCHIVED') throw new \InvalidArgumentException('Restore the project before generating audio.');
                $action = (string) ($_POST['action'] ?? '');
                if ($action === 'build_m4b') {
                    set_time_limit(0);
                    session_write_close();
                    $built = (new ExportService())->buildM4b($projectId);
                    self::flash('success', 'Audiobook file built with ' . (int) $built['chapter_count'] . ' chapter markers.');
                    self::redirect('chapters', ['id' => $projectId]);
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
                self::flash('success', $queued > 0 ? "Queued $queued chapter(s). The worker handles them one after another; this page shows the progress." : 'Nothing to queue: every approved chapter is up to date or already queued.');
            } catch (Throwable $error) {
                self::flash('error', $error->getMessage());
            }
            self::redirect('chapters', ['id' => $projectId]);
        }
        $rows = $narration->overview($projectId);
        try {
            $audio = (new GenerationService())->summaries($projectId, $rows);
        } catch (\InvalidArgumentException $error) {
            $audio = [];
        }
        $exports = new ExportService();
        $exportRow = $exports->exportRow($projectId);
        $readyCount = count(array_filter($audio, static fn (array $a): bool => $a['state'] === 'ready'));
        self::view('chapters', ['project' => $project, 'rows' => $rows, 'audio' => $audio, 'workerOnline' => JobService::workerOnline(),
            'exportRow' => $exportRow, 'exportCurrent' => $exportRow !== null && $readyCount > 0 && $exports->isCurrent($projectId, $exportRow), 'readyCount' => $readyCount]);
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
                        self::flash('success', 'Chapter approved for generation.');
                        break;
                    case 'unapprove':
                        $svc->unapprove($projectId, $sectionId);
                        break;
                    default:
                        throw new \InvalidArgumentException('Unknown action.');
                }
            } catch (Throwable $error) {
                self::flash('error', $error->getMessage());
            }
            self::redirect('chapter-review', ['id' => $projectId, 'section' => $sectionId] + ($focus !== '' ? ['b' => $focus] : []));
        }

        try {
            $chapter = $svc->chapter($projectId, $sectionId);
        } catch (\InvalidArgumentException $error) {
            self::notFound();
            return;
        }
        $order = array_column($svc->overview($projectId), 'id');
        $at = array_search($sectionId, $order, true);
        try {
            $audio = (new GenerationService())->status($projectId, $sectionId);
        } catch (\InvalidArgumentException $error) {
            $audio = null; // archived project, or an invalid project voice
        }
        self::view('chapter-review', [
            'project' => $project, 'chapter' => $chapter, 'focus' => (string) ($_GET['b'] ?? ''), 'audio' => $audio,
            'audioToken' => Csrf::token(), 'job' => (new JobService())->forChapter($sectionId), 'workerOnline' => JobService::workerOnline(),
            'prevId' => $at !== false && $at > 0 ? $order[$at - 1] : null,
            'nextId' => $at !== false && $at < count($order) - 1 ? $order[$at + 1] : null,
        ]);
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
                self::flash('error', $error->getMessage());
                self::redirect('chapters', ['id' => $projectId]);
            }
            session_write_close();
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

    private static function projects():void{$show=($_GET['archived']??'')==='1';self::view('projects',['projects'=>(new ProjectRepository())->all($show),'showArchived'=>$show]);}
    private static function createProject():void{$errors=[];$values=['title'=>'','provider'=>'kokoro','voice'=>VoiceCatalog::DEFAULT_VOICE,'bitrate'=>AudioEncoder::DEFAULT_BITRATE];if($_SERVER['REQUEST_METHOD']==='POST'){$values=['title'=>(string)($_POST['title']??''),'provider'=>(string)($_POST['provider']??'kokoro'),'voice'=>(string)($_POST['voice']??''),'bitrate'=>(int)($_POST['bitrate']??96)];if(!Csrf::validate($_POST['_token']??null))$errors[]='Security token expired. Reload the page and try again.';if(!$errors)try{$project=(new ProjectService())->create($values['title'],$values['provider'],$values['voice'],$values['bitrate']);self::flash('success','Project created.');self::redirect('project',['id'=>$project['id']]);}catch(Throwable $error){$errors[]=$error->getMessage();}}self::view('project-create',['errors'=>$errors,'values'=>$values]);}
    private static function project():void{$project=self::requireProject();$asset=(new SourceAssetRepository())->findByProject($project['id']);$analysis=new SourceAnalysisRepository();self::view('project',['project'=>$project,'sourceAsset'=>$asset,'sourceAnalyzed'=>$analysis->analyzed($project['id'])]);}
    private static function epubUpload():void{$project=self::requireProject();$repo=new SourceAssetRepository();if($repo->findByProject($project['id'])){self::flash('error','This project already has an immutable source EPUB.');self::redirect('project',['id'=>$project['id']]);}$errorMessage=null;if($_SERVER['REQUEST_METHOD']==='POST'){self::requireCsrf();try{$asset=(new EpubUploadService())->upload($project['id'],$_FILES['epub']??[]);self::flash('success','EPUB stored with SHA-256 '.$asset['sha256'].'.');self::redirect('project',['id'=>$project['id']]);}catch(Throwable $error){$errorMessage=$error->getMessage();}}self::view('epub-upload',['project'=>$project,'errorMessage'=>$errorMessage]);}
    private static function epubAnalyze():void{self::requirePost();self::requireCsrf();$project=self::requireProject();try{$result=(new EpubAnalysisService())->analyze($project['id']);self::flash('success','Created '.$result['sections'].' source sections and '.$result['blocks'].' source blocks.');self::redirect('source-sections',['id'=>$project['id']]);}catch(Throwable $error){self::flash('error',$error->getMessage());self::redirect('project',['id'=>$project['id']]);}}
    private static function sourceSections():void{$project=self::requireProject();$repo=new SourceAnalysisRepository();if(!$repo->analyzed($project['id'])){self::flash('error','Analyze the EPUB first.');self::redirect('project',['id'=>$project['id']]);}self::view('source-sections',['project'=>$project,'metadata'=>$repo->metadata($project['id']),'sections'=>$repo->sections($project['id']),'warnings'=>$repo->warnings($project['id'])]);}
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
