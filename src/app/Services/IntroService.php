<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * The optional spoken introduction at the start of an audiobook: the title and author, a line saying it was made with
 * AI Audiobook Studio on the voice service and voice in use, and that it is for personal use only. Its audio is made
 * once for a given text, voice, speed and quality, and becomes the first chapter of the M4B and the first file of the ZIP.
 */
final class IntroService
{
    public const TITLE = 'Introduction';
    public const MAX_CHARS = 1200;

    /** @var null|callable(string,string,float):string */
    private $synth;
    /** @var null|callable(string,string,int,array<string,string>):void */
    private $encode;

    /** Tests pass a fake voice server and encoder, as for GenerationService. */
    public function __construct(?callable $synth = null, ?callable $encode = null)
    {
        $this->synth = $synth;
        $this->encode = $encode;
    }

    /** The standard text, from the book's own title and author and the settings in use. @param array<string,mixed> $project */
    public static function defaultText(array $project, string $author): string
    {
        $title = trim((string) $project['title']);
        $author = trim($author);
        $voice = (string) (($project['active_voice_profile_id'] ?? '') ?: VoiceCatalog::DEFAULT_VOICE);
        $provider = self::providerName((string) ($project['active_provider_profile_id'] ?? ''));
        return $title . ($author !== '' ? ', by ' . $author : '') . ".\n"
            . 'This audiobook was made with AI Audiobook Studio, using the ' . $provider . ' text-to-speech server and the voice ' . self::speakableVoice($voice) . ".\n"
            . 'It is for personal use only. Please do not share or sell it.';
    }

    /** "kokoro" -> "Kokoro"; anything else as it is named. */
    public static function providerName(string $provider): string
    {
        $provider = trim($provider) !== '' ? trim($provider) : (string) config('tts.default_provider', 'kokoro');
        return $provider === 'kokoro' ? 'Kokoro' : ucfirst($provider);
    }

    /**
     * A voice id as it can be read aloud: "am_michael" -> "Michael, an American male voice"; a blend such as
     * "af_bella+af_sky" -> "a blend of Bella and Sky".
     */
    public static function speakableVoice(string $voice): string
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[+,]/', $voice) ?: [])));
        $name = static fn (string $v): string => ucfirst(preg_replace('/\(.*\)$|^[a-z]{2}_/', '', $v) ?? $v);
        if (count($parts) > 1) {
            $names = array_map($name, $parts);
            return 'a blend of ' . implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
        }
        $voice = $parts[0] ?? $voice;
        $kinds = ['af' => 'an American female voice', 'am' => 'an American male voice', 'bf' => 'a British female voice', 'bm' => 'a British male voice'];
        $kind = $kinds[substr($voice, 0, 2)] ?? '';
        return $name($voice) . ($kind !== '' ? ', ' . $kind : '');
    }

    /** The text to read, or null when the book has no introduction. @param array<string,mixed> $project */
    public function text(array $project): ?string
    {
        if ((int) ($project['intro_enabled'] ?? 0) !== 1) return null;
        $own = trim((string) ($project['intro_text'] ?? ''));
        return $own !== '' ? $own : self::defaultText($project, $this->author((string) $project['id']));
    }

    /** Switches the introduction on or off and stores its text (empty: the standard text). */
    public function save(string $projectId, bool $enabled, string $text): void
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if (mb_strlen($text) > self::MAX_CHARS) throw new InvalidArgumentException('The introduction is limited to ' . self::MAX_CHARS . ' characters.');
        $project = $this->project($projectId);
        // the standard text is stored as "empty", so it keeps following the title, author and voice when they change
        if ($text === trim(self::defaultText($project, $this->author($projectId)))) $text = '';
        $this->db()->prepare('UPDATE projects SET intro_enabled=:e, intro_text=:t, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute(['e' => (int) $enabled, 't' => $text === '' ? null : $text, 'id' => $projectId]);
    }

    /**
     * What the introduction's audio must match: the spoken text (after the book's rules), voice, speed and quality.
     * Empty when the book has no introduction.
     */
    public function signature(string $projectId): string
    {
        $project = $this->project($projectId);
        $text = $this->text($project);
        if ($text === null) return '';
        [$spoken, $voice, $speed] = $this->spoken($project, $text);
        return hash('sha256', implode('|', [$spoken, $voice, number_format($speed, 4, '.', ''), (int) $project['audio_bitrate_kbps']]));
    }

    /**
     * The introduction as a ready part of the audiobook, made first if it is missing or out of date (this needs the
     * voice server). Null when the book has no introduction.
     * @return array{id:string,position:int,title:string,path:string,signature:string,bitrate_kbps:int}|null
     */
    public function ready(string $projectId, bool $make = true): ?array
    {
        $project = $this->project($projectId);
        $text = $this->text($project);
        if ($text === null) return null;
        $signature = $this->signature($projectId);
        $row = $this->row($projectId);
        $path = $row !== null ? $this->absolute((string) $row['relpath']) : null;
        if ($row === null || $row['signature'] !== $signature || $path === null || !is_file($path)) {
            if (!$make) return null;
            $path = $this->make($project, $text, $signature);
        }
        return ['id' => 'intro', 'position' => 0, 'title' => self::TITLE, 'path' => $path, 'signature' => $signature, 'bitrate_kbps' => (int) $project['audio_bitrate_kbps']];
    }

    /** @param array<string,mixed> $project */
    private function make(array $project, string $text, string $signature): string
    {
        $id = (string) $project['id'];
        [$spoken, $voice, $speed] = $this->spoken($project, $text);
        $wav = $this->synth !== null ? ($this->synth)($spoken, $voice, $speed) : (new KokoroClient())->speech($spoken, $voice, $speed);
        $dir = $this->absolute($id . '/audio/intro');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create the audio folder.');
        $wavPath = $dir . '/intro.wav';
        if (file_put_contents($wavPath, $wav) === false) throw new RuntimeException('Cannot store the introduction audio.');
        try {
            $rel = $id . '/audio/intro/intro-' . substr($signature, 0, 12) . '.m4a';
            $encode = $this->encode ?? [new AudioEncoder(), 'encode'];
            $encode($wavPath, $this->absolute($rel), (int) $project['audio_bitrate_kbps'], ['title' => self::TITLE, 'album' => (string) $project['title'], 'track' => '0']);
            $duration = WavFile::info($wavPath)['duration_ms'];
        } finally {
            @unlink($wavPath);
        }
        $old = $this->row($id);
        $this->db()->prepare('INSERT INTO project_intro(project_id,signature,relpath,duration_ms) VALUES(:p,:s,:r,:d)
            ON CONFLICT(project_id) DO UPDATE SET signature=excluded.signature, relpath=excluded.relpath, duration_ms=excluded.duration_ms, created_at=CURRENT_TIMESTAMP')
            ->execute(['p' => $id, 's' => $signature, 'r' => $rel, 'd' => $duration]);
        if ($old !== null && $old['relpath'] !== $rel) @unlink($this->absolute((string) $old['relpath']));
        return $this->absolute($rel);
    }

    /**
     * The text as it will be spoken (the book's rules applied, so names in the title read as they do in the book), with
     * the book's voice and speed. @param array<string,mixed> $project
     * @return array{0:string,1:string,2:float}
     */
    private function spoken(array $project, string $text): array
    {
        $voice = (string) (($project['active_voice_profile_id'] ?? '') ?: VoiceCatalog::DEFAULT_VOICE);
        $rules = new PronunciationService();
        $spoken = $rules->apply(preg_replace('/\s*\n\s*/', ' ', $text) ?? $text, $rules->effective((string) $project['id'], $voice), (int) ($project['normalize_caps'] ?? 1) === 1)['text'];
        return [$spoken, $voice, (float) ($project['tts_speed'] ?? 1.0)];
    }

    public function author(string $projectId): string
    {
        $s = $this->db()->prepare('SELECT creator FROM source_metadata WHERE project_id=:p');
        $s->execute(['p' => $projectId]);
        return trim((string) ($s->fetchColumn() ?: ''));
    }

    /** @return array<string,mixed> */
    private function project(string $projectId): array
    {
        $s = $this->db()->prepare('SELECT * FROM projects WHERE id=:id');
        $s->execute(['id' => $projectId]);
        return $s->fetch() ?: throw new InvalidArgumentException('Project not found.');
    }

    /** @return array<string,mixed>|null */
    private function row(string $projectId): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM project_intro WHERE project_id=:p');
        $s->execute(['p' => $projectId]);
        return $s->fetch() ?: null;
    }

    private function absolute(string $relative): string
    {
        return rtrim((string) config('paths.projects'), '/\\') . '/' . ltrim($relative, '/');
    }

    private function db(): PDO
    {
        return Database::connection();
    }
}
