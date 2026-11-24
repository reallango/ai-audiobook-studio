<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use ZipArchive;

/** Whole-book output: a chaptered m4b built from the finished chapter m4a files, or a ZIP of those files. */
final class ExportService
{
    public function __construct(private ?AudioEncoder $encoder = null, private ?IntroService $intro = null)
    {
        $this->encoder ??= new AudioEncoder();
        $this->intro ??= new IntroService();
    }

    /**
     * Chapters whose audio is finished and current, in book order.
     * @return array<int,array{id:string,position:int,title:string,path:string,signature:string,bitrate_kbps:int}>
     */
    public function readyChapters(string $projectId): array
    {
        $overview = (new NarrationService())->overview($projectId);
        $states = (new GenerationService())->summaries($projectId, $overview);
        $db = $this->db();
        $q = $db->prepare('SELECT a.* FROM chapter_audio a JOIN logical_sections l ON l.id=a.logical_section_id WHERE l.project_id=:p');
        $q->execute(['p' => $projectId]);
        $audio = [];
        foreach ($q->fetchAll() as $row) $audio[(string) $row['logical_section_id']] = $row;

        $out = [];
        foreach ($overview as $chapter) {
            $id = (string) $chapter['id'];
            $row = $audio[$id] ?? null;
            if (($states[$id]['state'] ?? '') !== 'ready' || $row === null || $row['format'] !== 'm4a') continue;
            $path = $this->absolute((string) $row['audio_relpath']);
            if (!is_file($path)) continue;
            $out[] = ['id' => $id, 'position' => (int) $chapter['position'], 'title' => (string) $chapter['title'], 'path' => $path,
                'signature' => (string) $row['segment_signature'], 'bitrate_kbps' => (int) $row['bitrate_kbps']];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function exportRow(string $projectId, string $kind = 'm4b'): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM project_exports WHERE project_id=:p AND kind=:k');
        $s->execute(['p' => $projectId, 'k' => $kind]);
        return $s->fetch() ?: null;
    }

    /** True when the m4b was built from exactly the chapter audio that is current now. */
    public function isCurrent(string $projectId, ?array $row = null, ?array $ready = null): bool
    {
        $row ??= $this->exportRow($projectId);
        $ready ??= $this->readyChapters($projectId);
        // an introduction the file should open with counts too (by what it must be, whether or not its audio exists yet)
        $intro = $this->intro->signature($projectId);
        if ($intro !== '' && $ready !== []) {
            $project = $this->project($projectId);
            array_unshift($ready, ['id' => 'intro', 'signature' => $intro, 'bitrate_kbps' => (int) $project['audio_bitrate_kbps']]);
        }
        return $row !== null && $ready !== [] && $row['signature'] === self::signature($ready) && is_file($this->absolute((string) $row['relpath']));
    }

    /** Builds the chaptered m4b. @return array<string,mixed> the stored export row */
    public function buildM4b(string $projectId): array
    {
        $ready = $this->readyChapters($projectId);
        if ($ready === []) throw new InvalidArgumentException('No chapter has finished audio yet.');
        $intro = $this->intro->ready($projectId);
        if ($intro !== null) array_unshift($ready, $intro);
        $project = $this->project($projectId);
        $tags = ['title' => (string) $project['title'], 'album' => (string) $project['title'], 'artist' => (string) ($project['author'] ?? ''), 'genre' => 'Audiobook'];

        $chapters = [];
        $cursor = 0;
        foreach ($ready as $chapter) {
            $ms = $this->encoder->durationMs($chapter['path']);
            $chapters[] = ['title' => $chapter['title'], 'start_ms' => $cursor, 'end_ms' => $cursor + $ms];
            $cursor += $ms;
        }

        $dir = $this->absolute($projectId . '/export');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create the export folder.');
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $project['title']), '-');
        $rel = $projectId . '/export/' . ($slug !== '' ? $slug : 'audiobook') . '.m4b';
        $meta = $dir . '/.chapters.ffmetadata';
        file_put_contents($meta, AudioEncoder::metadataFile($tags, $chapters));
        try {
            $this->encoder->joinToM4b(array_column($ready, 'path'), $dir . '/.files.txt', $meta, $this->absolute($rel));
        } finally {
            @unlink($meta);
            @unlink($dir . '/.files.txt');
            @unlink($this->absolute($rel) . '.part');
        }

        $found = $this->encoder->chapterCount($this->absolute($rel));
        $this->db()->prepare(
            'INSERT INTO project_exports(project_id,kind,relpath,byte_size,chapter_count,duration_ms,signature) VALUES(:p,\'m4b\',:r,:b,:c,:d,:s)
             ON CONFLICT(project_id,kind) DO UPDATE SET relpath=excluded.relpath, byte_size=excluded.byte_size, chapter_count=excluded.chapter_count,
               duration_ms=excluded.duration_ms, signature=excluded.signature, built_at=CURRENT_TIMESTAMP'
        )->execute(['p' => $projectId, 'r' => $rel, 'b' => (int) filesize($this->absolute($rel)), 'c' => $found, 'd' => $cursor, 's' => self::signature($ready)]);
        return $this->exportRow($projectId) ?? [];
    }

    /** Absolute path of the stored m4b, or null. */
    public function m4bFile(string $projectId): ?string
    {
        $row = $this->exportRow($projectId);
        $path = $row !== null ? $this->absolute((string) $row['relpath']) : null;
        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * Writes a ZIP of the finished chapter m4a files (stored, not compressed: AAC does not shrink) and returns its path.
     * The caller streams and deletes it.
     */
    public function buildZip(string $projectId): string
    {
        $ready = $this->readyChapters($projectId);
        if ($ready === []) throw new InvalidArgumentException('No chapter has finished audio yet.');
        $intro = $this->intro->ready($projectId);
        if ($intro !== null) array_unshift($ready, $intro);
        $tmpDir = $this->absolute('../tmp');
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) $tmpDir = sys_get_temp_dir();
        $zipPath = tempnam($tmpDir, 'zip');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create the ZIP file.');
        foreach ($ready as $index => $chapter) {
            $name = str_pad((string) ($intro !== null ? $index : $index + 1), 3, '0', STR_PAD_LEFT) . ' - ' . trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '', $chapter['title'])) . '.m4a';
            $zip->addFile($chapter['path'], $name);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        $zip->close();
        return $zipPath;
    }

    /** @param array<int,array{id:string,signature:string,bitrate_kbps:int}> $ready */
    public static function signature(array $ready): string
    {
        return hash('sha256', implode('|', array_map(static fn (array $c): string => $c['id'] . ':' . $c['signature'] . ':' . $c['bitrate_kbps'], $ready)));
    }

    /** @return array<string,mixed> */
    private function project(string $projectId): array
    {
        $s = $this->db()->prepare('SELECT p.*, m.creator AS author FROM projects p LEFT JOIN source_metadata m ON m.project_id=p.id WHERE p.id=:p');
        $s->execute(['p' => $projectId]);
        return $s->fetch() ?: throw new InvalidArgumentException('Project not found.');
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
