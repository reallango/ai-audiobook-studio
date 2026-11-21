<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Repositories\LogicalSectionRepository;
use InvalidArgumentException;
use PDO;

/**
 * Builds the narration text of a chapter from the immutable source blocks:
 * source text -> cleaned -> pronunciation rules (project voice and layers) -> per-block edits.
 */
final class NarrationService
{
    public const MAX_OVERRIDE_CHARS = 20000;

    /** Normalises whitespace and invisible characters. It never changes the words. */
    public static function clean(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);
        $text = preg_replace('/[\x{00AD}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /** True when two headings say the same thing, for example "CHAPTER ONE" and "Chapter 1". */
    public static function sameTitle(string $a, string $b): bool
    {
        $norm = static function (string $s): string {
            $s = mb_strtolower(self::clean($s));
            $ones = ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
                'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19];
            $tens = ['twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90];
            $s = preg_replace_callback('/\b(' . implode('|', array_keys($tens)) . ')(?:[\s-]+(' . implode('|', array_keys($ones)) . '))?\b/u', static fn (array $m): string => (string) ($tens[$m[1]] + ($ones[$m[2] ?? ''] ?? 0)), $s) ?? $s;
            $s = preg_replace_callback('/\b(' . implode('|', array_keys($ones)) . ')\b/u', static fn (array $m): string => (string) $ones[$m[1]], $s) ?? $s;
            return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;
        };
        $x = $norm($a);
        return $x !== '' && $x === $norm($b);
    }

    /** @return array<string,mixed> */
    public function chapter(string $projectId, string $logicalId): array
    {
        $project = $this->project($projectId);
        $section = $this->section($projectId, $logicalId);
        $voice = (string) ($project['active_voice_profile_id'] ?: VoiceCatalog::DEFAULT_VOICE);

        $pron = new PronunciationService();
        $effective = $pron->effective($projectId, $voice);

        $edits = [];
        $s = $this->db()->prepare('SELECT * FROM narration_blocks WHERE logical_section_id=:id');
        $s->execute(['id' => $logicalId]);
        foreach ($s->fetchAll() as $row) $edits[$row['source_block_id']] = $row;

        $items = [];
        $paragraphs = [];
        $words = 0;
        foreach ((new LogicalSectionRepository())->blocks($logicalId) as $block) {
            $source = (string) $block['source_text'];
            $cleaned = self::clean($source);
            $applied = $pron->apply($cleaned, $effective);
            $edit = $edits[$block['id']] ?? null;
            $excluded = $edit !== null && (int) $edit['excluded'] === 1;
            $override = $edit !== null && $edit['override_text'] !== null ? (string) $edit['override_text'] : null;
            $narration = $override ?? $applied['text'];
            $items[] = [
                'id' => (string) $block['id'], 'type' => (string) $block['block_type'], 'source' => $source, 'cleaned' => $cleaned,
                'narration' => $narration, 'computed' => $applied['text'], 'hits' => $applied['hits'], 'excluded' => $excluded,
                'overridden' => $override !== null,
            ];
            if (!$excluded && trim($narration) !== '') {
                $paragraphs[] = $narration;
                $words += preg_match_all('/\S+/u', $narration);
            }
        }

        // The chapter title is announced first, unless the EPUB already opens with that same heading.
        $title = trim((string) $section['title']);
        $announce = $title !== '' && !(isset($paragraphs[0]) && self::sameTitle($paragraphs[0], $title));
        $text = ($announce ? $title . '.' . "\n\n" : '') . implode("\n\n", $paragraphs);
        $hash = hash('sha256', $text);
        $approval = $this->approval($logicalId);
        $status = $approval === null ? 'unreviewed' : ($approval['narration_sha256'] === $hash ? 'approved' : 'changed');

        return [
            'section' => $section, 'voice' => $voice, 'items' => $items, 'text' => $text, 'hash' => $hash,
            'words' => $words, 'approval' => $approval, 'status' => $status,
        ];
    }

    /**
     * One row per included chapter with its word count and approval state.
     * @return array<int,array<string,mixed>>
     */
    public function overview(string $projectId): array
    {
        $rows = [];
        foreach ((new LogicalSectionRepository())->all($projectId) as $section) {
            if ((int) $section['included'] !== 1) continue;
            $chapter = $this->chapter($projectId, (string) $section['id']);
            $rows[] = [
                'id' => (string) $section['id'], 'position' => (int) $section['position'], 'title' => (string) $section['title'],
                'words' => $chapter['words'], 'status' => $chapter['status'],
                'edited' => count(array_filter($chapter['items'], static fn (array $i): bool => $i['overridden'] || $i['excluded'])),
            ];
        }
        return $rows;
    }

    public function setExcluded(string $projectId, string $logicalId, string $blockId, bool $excluded): void
    {
        $this->assertBlock($projectId, $logicalId, $blockId);
        $this->db()->prepare(
            'INSERT INTO narration_blocks(logical_section_id,source_block_id,excluded) VALUES(:l,:b,:e)
             ON CONFLICT(logical_section_id,source_block_id) DO UPDATE SET excluded=excluded.excluded, updated_at=CURRENT_TIMESTAMP'
        )->execute(['l' => $logicalId, 'b' => $blockId, 'e' => (int) $excluded]);
        $this->prune($logicalId, $blockId);
    }

    /** Sets the narrated text of one block. An empty string clears the override. */
    public function setOverride(string $projectId, string $logicalId, string $blockId, string $text): void
    {
        $this->assertBlock($projectId, $logicalId, $blockId);
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_OVERRIDE_CHARS) throw new InvalidArgumentException('That text is too long for one block.');
        $this->db()->prepare(
            'INSERT INTO narration_blocks(logical_section_id,source_block_id,override_text) VALUES(:l,:b,:t)
             ON CONFLICT(logical_section_id,source_block_id) DO UPDATE SET override_text=excluded.override_text, updated_at=CURRENT_TIMESTAMP'
        )->execute(['l' => $logicalId, 'b' => $blockId, 't' => $text === '' ? null : $text]);
        $this->prune($logicalId, $blockId);
    }

    public function approve(string $projectId, string $logicalId): void
    {
        $chapter = $this->chapter($projectId, $logicalId);
        if ($chapter['words'] === 0) throw new InvalidArgumentException('This chapter has no narration text to approve.');
        $this->db()->prepare(
            'INSERT INTO chapter_approvals(logical_section_id,narration_sha256) VALUES(:l,:h)
             ON CONFLICT(logical_section_id) DO UPDATE SET narration_sha256=excluded.narration_sha256, approved_at=CURRENT_TIMESTAMP'
        )->execute(['l' => $logicalId, 'h' => $chapter['hash']]);
    }

    public function unapprove(string $projectId, string $logicalId): void
    {
        $this->section($projectId, $logicalId);
        $this->db()->prepare('DELETE FROM chapter_approvals WHERE logical_section_id=:l')->execute(['l' => $logicalId]);
    }

    // ----- internals -----

    /** @return array<string,mixed> */
    private function project(string $projectId): array
    {
        $s = $this->db()->prepare('SELECT * FROM projects WHERE id=:id');
        $s->execute(['id' => $projectId]);
        return $s->fetch() ?: throw new InvalidArgumentException('Project not found.');
    }

    /** @return array<string,mixed> */
    private function section(string $projectId, string $logicalId): array
    {
        return (new LogicalSectionRepository())->find($projectId, $logicalId) ?: throw new InvalidArgumentException('Chapter not found.');
    }

    /** @return array<string,mixed>|null */
    private function approval(string $logicalId): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM chapter_approvals WHERE logical_section_id=:l');
        $s->execute(['l' => $logicalId]);
        return $s->fetch() ?: null;
    }

    private function assertBlock(string $projectId, string $logicalId, string $blockId): void
    {
        $this->section($projectId, $logicalId);
        foreach ((new LogicalSectionRepository())->blocks($logicalId) as $block) {
            if ((string) $block['id'] === $blockId) return;
        }
        throw new InvalidArgumentException('That block is not part of this chapter.');
    }

    /** Drops an edit row that no longer changes anything. */
    private function prune(string $logicalId, string $blockId): void
    {
        $this->db()->prepare('DELETE FROM narration_blocks WHERE logical_section_id=:l AND source_block_id=:b AND excluded=0 AND override_text IS NULL')
            ->execute(['l' => $logicalId, 'b' => $blockId]);
    }

    private function db(): PDO
    {
        return Database::connection();
    }
}
