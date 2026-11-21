<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Turns an approved chapter into audio: prepare segments, generate them one at a time through Kokoro, join them
 * into a chapter WAV. All state lives in the database, so any step can be repeated or resumed.
 */
final class GenerationService
{
    public const MAX_ATTEMPTS = 3;
    private const STALE_CLAIM = '-5 minutes';

    /** @var null|callable(string,string,float):string */
    private $synth;

    /** @param null|callable(string,string,float):string $synth text, voice, speed -> WAV bytes (tests inject a fake) */
    public function __construct(?callable $synth = null)
    {
        $this->synth = $synth;
    }

    /**
     * Splits the approved narration into segments. Segments whose text, voice and speed are unchanged keep their audio.
     * @return array{total:int,reused:int,created:int,removed:int}
     */
    public function prepare(string $projectId, string $logicalId): array
    {
        [$project, $voice, $speed] = $this->settings($projectId, $logicalId);
        $chapter = (new NarrationService())->chapter($projectId, $logicalId);
        if ($chapter['status'] !== 'approved') throw new InvalidArgumentException('Approve the chapter before generating audio.');
        $wanted = Segmenter::split($chapter['text'], (int) config('tts.segment_max_chars', Segmenter::DEFAULT_MAX_CHARS));
        if ($wanted === []) throw new InvalidArgumentException('This chapter has no narration text.');

        $db = $this->db();
        $db->beginTransaction();
        try {
            $existing = $db->prepare('SELECT * FROM segments WHERE logical_section_id=:l');
            $existing->execute(['l' => $logicalId]);
            $pool = [];
            foreach ($existing->fetchAll() as $row) {
                if ($row['status'] === 'DONE') $pool[$this->key((string) $row['text_sha256'], (string) $row['voice'], (float) $row['speed'])][] = $row;
            }
            $db->prepare('UPDATE segments SET position = -position - 1 WHERE logical_section_id=:l')->execute(['l' => $logicalId]);

            $reused = $created = 0;
            $keep = [];
            $insert = $db->prepare('INSERT INTO segments(id,logical_section_id,position,text,text_sha256,gap_ms,voice,speed) VALUES(:id,:l,:p,:t,:h,:g,:v,:s)');
            foreach ($wanted as $position => $segment) {
                $sha = hash('sha256', $segment['text']);
                $key = $this->key($sha, $voice, $speed);
                if (!empty($pool[$key])) {
                    $row = array_shift($pool[$key]);
                    $db->prepare('UPDATE segments SET position=:p, gap_ms=:g, updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['p' => $position, 'g' => $segment['gap_ms'], 'id' => $row['id']]);
                    $keep[$row['id']] = true;
                    $reused++;
                    continue;
                }
                $insert->execute(['id' => self::uuid(), 'l' => $logicalId, 'p' => $position, 't' => $segment['text'], 'h' => $sha, 'g' => $segment['gap_ms'], 'v' => $voice, 's' => $speed]);
                $created++;
            }
            $stale = $db->prepare('SELECT id, audio_relpath FROM segments WHERE logical_section_id=:l AND position < 0');
            $stale->execute(['l' => $logicalId]);
            $removed = 0;
            foreach ($stale->fetchAll() as $row) {
                $this->deleteFile($row['audio_relpath']);
                $removed++;
            }
            $db->prepare('DELETE FROM segments WHERE logical_section_id=:l AND position < 0')->execute(['l' => $logicalId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['total' => count($wanted), 'reused' => $reused, 'created' => $created, 'removed' => $removed];
    }

    /**
     * Generates the next segment that needs audio. Returns done=true when nothing is left to try.
     * @return array<string,mixed>
     */
    public function generateNext(string $projectId, string $logicalId): array
    {
        $this->settings($projectId);
        $this->assertChapter($projectId, $logicalId);
        $db = $this->db();
        $pick = $db->prepare(
            "SELECT * FROM segments WHERE logical_section_id=:l AND (status='PENDING' OR (status='FAILED' AND attempts<:max)
               OR (status='GENERATING' AND claimed_at < datetime('now', :stale))) ORDER BY position LIMIT 1"
        );
        $pick->execute(['l' => $logicalId, 'max' => self::MAX_ATTEMPTS, 'stale' => self::STALE_CLAIM]);
        $row = $pick->fetch();
        if (!$row) return ['segment' => null, 'counts' => $this->counts($logicalId)];

        $claim = $db->prepare("UPDATE segments SET status='GENERATING', claimed_at=CURRENT_TIMESTAMP, attempts=attempts+1, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status=:s");
        $claim->execute(['id' => $row['id'], 's' => $row['status']]);
        if ($claim->rowCount() !== 1) return ['segment' => null, 'busy' => true, 'counts' => $this->counts($logicalId)];

        try {
            $wav = $this->synthesize((string) $row['text'], (string) $row['voice'], (float) $row['speed']);
            $rel = $projectId . '/audio/' . $logicalId . '/seg-' . str_pad((string) $row['position'], 4, '0', STR_PAD_LEFT) . '-' . substr((string) $row['id'], 0, 8) . '.wav';
            $this->writeFile($rel, $wav);
            $info = WavFile::info($this->absolute($rel));
            $db->prepare("UPDATE segments SET status='DONE', audio_relpath=:r, duration_ms=:d, error=NULL, generated_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['r' => $rel, 'd' => $info['duration_ms'], 'id' => $row['id']]);
        } catch (Throwable $e) {
            $db->prepare("UPDATE segments SET status='FAILED', error=:e, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['e' => mb_substr($e->getMessage(), 0, 300), 'id' => $row['id']]);
        }
        $after = $db->prepare('SELECT id,position,status,attempts,error,duration_ms FROM segments WHERE id=:id');
        $after->execute(['id' => $row['id']]);
        return ['segment' => $after->fetch(), 'counts' => $this->counts($logicalId)];
    }

    /** Joins all segment audio into the chapter WAV. @return array<string,mixed> */
    public function assemble(string $projectId, string $logicalId): array
    {
        $this->assertChapter($projectId, $logicalId);
        $rows = $this->segments($logicalId);
        if ($rows === []) throw new InvalidArgumentException('Prepare the segments first.');
        $missing = count(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'DONE'));
        if ($missing > 0) throw new InvalidArgumentException("$missing segment(s) still need audio.");

        $parts = array_map(fn (array $r): array => ['path' => $this->absolute((string) $r['audio_relpath']), 'gap_ms' => (int) $r['gap_ms']], $rows);
        $rel = $projectId . '/audio/' . $logicalId . '/chapter.wav';
        $result = WavFile::join($parts, $this->absolute($rel));
        $this->db()->prepare(
            'INSERT INTO chapter_audio(logical_section_id,audio_relpath,duration_ms,byte_size,segment_signature) VALUES(:l,:r,:d,:b,:s)
             ON CONFLICT(logical_section_id) DO UPDATE SET audio_relpath=excluded.audio_relpath, duration_ms=excluded.duration_ms,
               byte_size=excluded.byte_size, segment_signature=excluded.segment_signature, built_at=CURRENT_TIMESTAMP'
        )->execute(['l' => $logicalId, 'r' => $rel, 'd' => $result['duration_ms'], 'b' => $result['byte_size'], 's' => $this->signature($rows)]);
        return $result;
    }

    /**
     * Audio state of every chapter in the overview rows (from NarrationService::overview), without extra narration work.
     * State: n/a (not approved), none, partial, working, failed, outdated, ready.
     * @param array<int,array<string,mixed>> $overview
     * @return array<string,array<string,mixed>> keyed by chapter id
     */
    public function summaries(string $projectId, array $overview): array
    {
        [, $projectVoice, $projectSpeed] = $this->settings($projectId);
        $overrides = ChapterSettings::forProject($projectId);
        $db = $this->db();
        $ids = array_column($overview, 'id');
        $segments = [];
        $s = $db->prepare('SELECT g.* FROM segments g JOIN logical_sections l ON l.id=g.logical_section_id WHERE l.project_id=:p ORDER BY g.logical_section_id, g.position');
        $s->execute(['p' => $projectId]);
        foreach ($s->fetchAll() as $row) $segments[$row['logical_section_id']][] = $row;
        $audio = [];
        $a = $db->prepare('SELECT a.* FROM chapter_audio a JOIN logical_sections l ON l.id=a.logical_section_id WHERE l.project_id=:p');
        $a->execute(['p' => $projectId]);
        foreach ($a->fetchAll() as $row) $audio[$row['logical_section_id']] = $row;
        $jobs = [];
        $j = $db->prepare('SELECT * FROM generation_jobs WHERE project_id=:p');
        $j->execute(['p' => $projectId]);
        foreach ($j->fetchAll() as $row) $jobs[$row['logical_section_id']] = $row;

        $out = [];
        foreach ($overview as $row) {
            $id = (string) $row['id'];
            $voice = $overrides[$id]['voice'] ?? $projectVoice;
            $speed = $overrides[$id]['speed'] ?? $projectSpeed;
            $rows = $segments[$id] ?? [];
            $done = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'DONE'));
            $job = $jobs[$id] ?? null;
            $info = ['state' => 'n/a', 'voice' => $voice, 'speed' => $speed, 'custom' => isset($overrides[$id]), 'done' => $done, 'total' => count($rows), 'duration_ms' => isset($audio[$id]) ? (int) $audio[$id]['duration_ms'] : null];
            if ($row['status'] === 'approved') {
                $wanted = array_map(static fn (array $w): string => hash('sha256', $w['text']), Segmenter::split((string) $row['text'], (int) config('tts.segment_max_chars', Segmenter::DEFAULT_MAX_CHARS)));
                $have = array_map(static fn (array $r): string => (string) $r['text_sha256'], $rows);
                $settingsOk = count(array_filter($rows, static fn (array $r): bool => $r['voice'] !== $voice || abs((float) $r['speed'] - $speed) > 0.0001)) === 0;
                $upToDate = $wanted === $have && $settingsOk;
                if ($job && in_array($job['status'], ['QUEUED', 'RUNNING'], true)) $info['state'] = 'working';
                elseif ($job && $job['status'] === 'FAILED' && !($upToDate && isset($audio[$id]) && $audio[$id]['segment_signature'] === $this->signature($rows))) $info['state'] = 'failed';
                elseif (isset($audio[$id])) $info['state'] = $upToDate && $audio[$id]['segment_signature'] === $this->signature($rows) ? 'ready' : 'outdated';
                elseif ($rows !== []) $info['state'] = $upToDate ? 'partial' : 'outdated';
                else $info['state'] = 'none';
            }
            $out[$id] = $info;
        }
        return $out;
    }

    /** Marks every segment of a chapter for generation again, discarding their audio. */
    public function resetAll(string $projectId, string $logicalId): int
    {
        $this->assertChapter($projectId, $logicalId);
        $s = $this->db()->prepare('SELECT audio_relpath FROM segments WHERE logical_section_id=:l');
        $s->execute(['l' => $logicalId]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $rel) $this->deleteFile($rel);
        $reset = $this->db()->prepare("UPDATE segments SET status='PENDING', attempts=0, error=NULL, audio_relpath=NULL, duration_ms=NULL, claimed_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE logical_section_id=:l");
        $reset->execute(['l' => $logicalId]);
        return $reset->rowCount();
    }

    /** Marks one segment (or, with null, every failed segment) for generation again. */
    public function reset(string $projectId, string $logicalId, ?string $segmentId): int
    {
        $this->assertChapter($projectId, $logicalId);
        $sql = "SELECT id, audio_relpath FROM segments WHERE logical_section_id=:l AND " . ($segmentId === null ? "status='FAILED'" : 'id=:s');
        $s = $this->db()->prepare($sql);
        $s->execute($segmentId === null ? ['l' => $logicalId] : ['l' => $logicalId, 's' => $segmentId]);
        $n = 0;
        foreach ($s->fetchAll() as $row) {
            $this->deleteFile($row['audio_relpath']);
            $this->db()->prepare("UPDATE segments SET status='PENDING', attempts=0, error=NULL, audio_relpath=NULL, duration_ms=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['id' => $row['id']]);
            $n++;
        }
        return $n;
    }

    /**
     * Everything the chapter page needs.
     * @return array<string,mixed>
     */
    public function status(string $projectId, string $logicalId): array
    {
        [$project, $voice, $speed] = $this->settings($projectId, $logicalId);
        $chapter = (new NarrationService())->chapter($projectId, $logicalId);
        $rows = $this->segments($logicalId);

        $wanted = $chapter['status'] === 'approved' ? Segmenter::split($chapter['text'], (int) config('tts.segment_max_chars', Segmenter::DEFAULT_MAX_CHARS)) : [];
        $current = array_map(static fn (array $w): string => hash('sha256', $w['text']), $wanted);
        $have = array_map(static fn (array $r): string => (string) $r['text_sha256'], $rows);
        $settingsMatch = count(array_filter($rows, fn (array $r): bool => $r['voice'] !== $voice || abs((float) $r['speed'] - $speed) > 0.0001)) === 0;

        $audio = $this->db()->prepare('SELECT * FROM chapter_audio WHERE logical_section_id=:l');
        $audio->execute(['l' => $logicalId]);
        $audioRow = $audio->fetch() ?: null;
        $counts = $this->counts($logicalId);

        return [
            'approved' => $chapter['status'] === 'approved', 'narration_status' => $chapter['status'], 'voice' => $voice, 'speed' => $speed,
            'segments' => $rows, 'counts' => $counts,
            'prepared' => $rows !== [],
            'needs_prepare' => $chapter['status'] === 'approved' && ($current !== $have || !$settingsMatch),
            'chapter_audio' => $audioRow,
            'audio_stale' => $audioRow !== null && $audioRow['segment_signature'] !== $this->signature($rows),
            'ready_to_assemble' => $rows !== [] && $counts['done'] === $counts['total'],
        ];
    }

    /** Absolute path of a stored audio file for streaming, or null. */
    public function audioFile(string $projectId, string $kind, string $ref): ?string
    {
        if ($kind === 'chapter') {
            $s = $this->db()->prepare('SELECT a.audio_relpath FROM chapter_audio a JOIN logical_sections l ON l.id=a.logical_section_id WHERE l.project_id=:p AND l.id=:r');
        } elseif ($kind === 'segment') {
            $s = $this->db()->prepare('SELECT g.audio_relpath FROM segments g JOIN logical_sections l ON l.id=g.logical_section_id WHERE l.project_id=:p AND g.id=:r AND g.status=\'DONE\'');
        } else {
            return null;
        }
        $s->execute(['p' => $projectId, 'r' => $ref]);
        $rel = $s->fetchColumn();
        if (!is_string($rel) || $rel === '') return null;
        $path = $this->absolute($rel);
        return is_file($path) ? $path : null;
    }

    // ----- internals -----

    /** @return array<int,array<string,mixed>> */
    private function segments(string $logicalId): array
    {
        $s = $this->db()->prepare('SELECT * FROM segments WHERE logical_section_id=:l ORDER BY position');
        $s->execute(['l' => $logicalId]);
        return $s->fetchAll();
    }

    /** @return array{total:int,pending:int,generating:int,done:int,failed:int} */
    private function counts(string $logicalId): array
    {
        $c = ['total' => 0, 'pending' => 0, 'generating' => 0, 'done' => 0, 'failed' => 0];
        $s = $this->db()->prepare('SELECT status, COUNT(*) FROM segments WHERE logical_section_id=:l GROUP BY status');
        $s->execute(['l' => $logicalId]);
        foreach ($s->fetchAll(PDO::FETCH_NUM) as [$status, $n]) {
            $c[strtolower((string) $status)] = (int) $n;
            $c['total'] += (int) $n;
        }
        return $c;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function signature(array $rows): string
    {
        return hash('sha256', implode('|', array_map(static fn (array $r): string => $r['id'] . ':' . $r['text_sha256'] . ':' . $r['voice'] . ':' . $r['speed'] . ':' . $r['gap_ms'] . ':' . $r['status'], $rows)));
    }

    /** @return array{0:array<string,mixed>,1:string,2:float} */
    private function settings(string $projectId, ?string $logicalId = null): array
    {
        $s = $this->db()->prepare('SELECT * FROM projects WHERE id=:id');
        $s->execute(['id' => $projectId]);
        $project = $s->fetch() ?: throw new InvalidArgumentException('Project not found.');
        if ($project['status'] === 'ARCHIVED') throw new InvalidArgumentException('Restore the project before generating audio.');
        $own = $logicalId !== null ? ChapterSettings::get($logicalId) : ['voice' => null, 'speed' => null];
        $voice = $own['voice'] ?? (string) ($project['active_voice_profile_id'] ?: VoiceCatalog::DEFAULT_VOICE);
        if (!KokoroClient::validVoice($voice)) throw new InvalidArgumentException('The project voice is not valid.');
        return [$project, $voice, $own['speed'] ?? (float) ($project['tts_speed'] ?? 1.0)];
    }

    private function assertChapter(string $projectId, string $logicalId): void
    {
        $s = $this->db()->prepare('SELECT 1 FROM logical_sections WHERE id=:l AND project_id=:p');
        $s->execute(['l' => $logicalId, 'p' => $projectId]);
        if (!$s->fetchColumn()) throw new InvalidArgumentException('Chapter not found.');
    }

    private function synthesize(string $text, string $voice, float $speed): string
    {
        return $this->synth !== null ? ($this->synth)($text, $voice, $speed) : (new KokoroClient())->speech($text, $voice, $speed);
    }

    private function key(string $sha, string $voice, float $speed): string
    {
        return $sha . '|' . $voice . '|' . number_format($speed, 4, '.', '');
    }

    private function absolute(string $relative): string
    {
        return rtrim((string) config('paths.projects'), '/\\') . '/' . ltrim($relative, '/');
    }

    private function writeFile(string $relative, string $bytes): void
    {
        $path = $this->absolute($relative);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create the audio folder.');
        if (file_put_contents($path . '.part', $bytes) === false || !rename($path . '.part', $path)) throw new RuntimeException('Cannot store the audio file.');
    }

    private function deleteFile(mixed $relative): void
    {
        if (is_string($relative) && $relative !== '') @unlink($this->absolute($relative));
    }

    private function db(): PDO
    {
        return Database::connection();
    }

    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 15) | 64);
        $d[8] = chr((ord($d[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
