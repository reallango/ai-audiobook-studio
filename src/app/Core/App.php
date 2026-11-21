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
use App\Services\GenerationService;
use App\Services\JobService;
use Throwable;
final class App
{
    public static function run(): void
    {
        $route=trim((string)($_GET['page']??'projects'),'/');
        try{match($route){'api-health'=>self::json(self::health()),'diagnostics'=>self::view('diagnostics'),'projects','home',''=>self::projects(),'project-create'=>self::createProject(),'project'=>self::project(),'epub-upload'=>self::epubUpload(),'epub-analyze'=>self::epubAnalyze(),'source-sections'=>self::sourceSections(),'source-section'=>self::sourceSection(),'project-archive'=>self::archiveProject(),'project-restore'=>self::restoreProject(),'project-delete'=>self::deleteProject(),'voice-lab'=>self::voiceLab(),'voice-preview'=>self::voicePreview(),'pronunciation'=>self::pronunciation(),'chapters'=>self::chapters(),'chapter-review'=>self::chapterReview(),'chapter-audio'=>self::chapterAudio(),'audio'=>self::audio(),default=>self::notFound()};}
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

    /** Pronunciation manager: one layer at a time (default, voice, series set, project), plus a rewrite test. */
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
                self::redirect('pronunciation', $back);
            }
            try {
                $action = (string) ($_POST['action'] ?? '');
                switch ($action) {
                    case 'add_rule':
                        $svc->save($layer, $ref, (string) ($_POST['pattern'] ?? ''), (string) ($_POST['replacement'] ?? ''), isset($_POST['case_sensitive']), isset($_POST['whole_word']), (string) ($_POST['note'] ?? ''));
                        self::flash('success', 'Rule saved.');
                        break;
                    case 'toggle_rule':
                        $svc->setEnabled((int) ($_POST['id'] ?? 0), ($_POST['enabled'] ?? '') === '1');
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
                        self::flash('success', "Imported $count rules into the series set \"$name\".");
                        $back = ['layer' => 'set', 'ref' => $setId];
                        break;
                    case 'create_set':
                        $setId = $svc->createSet((string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''));
                        self::flash('success', 'Series set created.');
                        $back = ['layer' => 'set', 'ref' => $setId];
                        break;
                    case 'delete_set':
                        if ((string) ($_POST['confirm'] ?? '') !== 'yes') throw new \InvalidArgumentException('Tick the box to confirm deleting the set.');
                        $svc->deleteSet($ref);
                        self::flash('success', 'Series set deleted.');
                        $back = ['layer' => 'set', 'ref' => ''];
                        break;
                    case 'attach_set':
                        $svc->attachSet($ref, (string) ($_POST['set_id'] ?? ''));
                        self::flash('success', 'Series set linked to the project.');
                        break;
                    case 'detach_set':
                        $svc->detachSet($ref, (string) ($_POST['set_id'] ?? ''));
                        self::flash('success', 'Series set unlinked.');
                        break;
                    default:
                        throw new \InvalidArgumentException('Unknown action.');
                }
            } catch (Throwable $error) {
                self::flash('error', $error->getMessage());
            }
            self::redirect('pronunciation', $back);
        }

        $currentSet = $layer === 'set' && $ref !== '' ? $svc->set($ref) : null;
        $testText = (string) ($_GET['test'] ?? '');
        $testVoice = (string) ($_GET['test_voice'] ?? VoiceCatalog::DEFAULT_VOICE);
        if (!KokoroClient::validVoice($testVoice)) $testVoice = VoiceCatalog::DEFAULT_VOICE;
        $testProject = (string) ($_GET['test_project'] ?? '');
        $testResult = null;
        if ($testText !== '') {
            $effective = $svc->effective($testProject !== '' ? $testProject : null, $testVoice);
            $testResult = $svc->apply(mb_substr($testText, 0, 2000), $effective) + ['effective' => $effective];
        }

        self::view('pronunciation', [
            'svc' => $svc, 'layers' => $layers, 'layer' => $layer, 'ref' => $ref, 'projects' => $projects, 'sets' => $sets,
            'currentSet' => $currentSet, 'rules' => ($layer !== 'set' || $currentSet) && ($layer === 'default' || $ref !== '') ? $svc->rules($layer, $ref) : [],
            'projectSets' => $layer === 'project' && $ref !== '' ? $svc->projectSets($ref) : [],
            'voiceGroups' => VoiceCatalog::GROUPS, 'bundled' => PronunciationService::BUNDLED,
            'testText' => $testText, 'testVoice' => $testVoice, 'testProject' => $testProject, 'testResult' => $testResult,
        ]);
    }

    /** POST {_token, text, voice, speed} -> audio/wav, or JSON {ok:false,message} on failure. */
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
            if ($project !== null && !(new ProjectRepository())->find($project)) { $fail(404, 'Project not found.'); return; }
            $service = new PronunciationService();
            $spoken = $service->apply($text, $service->effective($project, $voice))['text'];
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
        $rows = (new NarrationService())->overview((string) $project['id']);
        self::view('chapters', ['project' => $project, 'rows' => $rows]);
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

    /** GET ?page=audio&id=PROJECT&kind=segment|chapter&ref=ID[&download=1]: streams a stored WAV, with Range support. */
    private static function audio():void
    {
        $projectId = (string) ($_GET['id'] ?? '');
        $kind = (string) ($_GET['kind'] ?? '');
        $path = (new GenerationService())->audioFile($projectId, $kind, (string) ($_GET['ref'] ?? ''));
        if ($path === null) { self::notFound(); return; }
        session_write_close();
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
        header('Content-Type: audio/wav');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-cache');
        if ($status === 206) header("Content-Range: bytes $start-$end/$size");
        header('Content-Length: ' . ($end - $start + 1));
        if (($_GET['download'] ?? '') === '1') header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($_GET['name'] ?? 'audio')) . '.wav"');
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
    }

    private static function projects():void{$show=($_GET['archived']??'')==='1';self::view('projects',['projects'=>(new ProjectRepository())->all($show),'showArchived'=>$show]);}
    private static function createProject():void{$errors=[];$values=['title'=>'','provider'=>'kokoro','voice'=>VoiceCatalog::DEFAULT_VOICE,'bitrate'=>96];if($_SERVER['REQUEST_METHOD']==='POST'){$values=['title'=>(string)($_POST['title']??''),'provider'=>(string)($_POST['provider']??'kokoro'),'voice'=>(string)($_POST['voice']??''),'bitrate'=>(int)($_POST['bitrate']??96)];if(!Csrf::validate($_POST['_token']??null))$errors[]='Security token expired. Reload the page and try again.';if(!$errors)try{$project=(new ProjectService())->create($values['title'],$values['provider'],$values['voice'],$values['bitrate']);self::flash('success','Project created.');self::redirect('project',['id'=>$project['id']]);}catch(Throwable $error){$errors[]=$error->getMessage();}}self::view('project-create',['errors'=>$errors,'values'=>$values]);}
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
