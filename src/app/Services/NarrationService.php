<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Repositories\LogicalSectionRepository;
use App\Repositories\ProjectRepository;
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
        $text = str_replace(["\u{2019}", "\u{2018}"], "'", $text);
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
        // a chapter's own voice also selects the voice-layer pronunciation rules
        $voice = ChapterSettings::get($logicalId)['voice'] ?? (string) ($project['active_voice_profile_id'] ?: VoiceCatalog::DEFAULT_VOICE);

        $pron = new PronunciationService();
        $effective = $pron->effective($projectId, $voice);

        $blockRows = [];
        $s = $this->db()->prepare('SELECT * FROM narration_blocks WHERE logical_section_id=:id');
        $s->execute(['id' => $logicalId]);
        foreach ($s->fetchAll() as $row) $blockRows[$row['source_block_id']] = $row;
        $editRows = [];
        $q = $this->db()->prepare('SELECT * FROM narration_edits WHERE logical_section_id=:id ORDER BY src_start');
        $q->execute(['id' => $logicalId]);
        foreach ($q->fetchAll() as $row) $editRows[$row['source_block_id']][] = $row;

        $caps = (int) ($project['normalize_caps'] ?? 1) === 1;
        $items = [];
        $paragraphs = [];
        $words = 0;
        foreach ((new LogicalSectionRepository())->blocks($logicalId) as $block) {
            $source = (string) $block['source_text'];
            $cleaned = self::clean($source);
            $built = ParagraphEdits::build($pron, $effective, $cleaned, $caps, $editRows[$block['id']] ?? []);
            $row = $blockRows[$block['id']] ?? null;
            $excluded = $row !== null && (int) $row['excluded'] === 1;
            $override = $row !== null && $row['override_text'] !== null ? (string) $row['override_text'] : null;
            $narration = $override ?? $built['text'];
            $items[] = [
                'id' => (string) $block['id'], 'type' => (string) $block['block_type'], 'source' => $source, 'cleaned' => $cleaned,
                'narration' => $narration, 'computed' => $built['text'], 'hits' => $built['hits'], 'excluded' => $excluded,
                'overridden' => $override !== null,
                'pieces' => $override !== null
                    ? [['src' => $cleaned, 'src_start' => 0, 'out' => $override, 'kind' => 'edit', 'rule' => '', 'scope' => '', 'scope_ref' => '', 'label' => 'Your edit']]
                    : $built['pieces'],
                'edits' => $built['edits'], 'edit_count' => count($built['edits']), 'stale' => $built['stale'],
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
            'words' => $words, 'approval' => $approval, 'status' => $status, 'announced' => $announce ? $title : null,
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
                'words' => $chapter['words'], 'status' => $chapter['status'], 'text' => $chapter['text'],
                'edited' => count(array_filter($chapter['items'], static fn (array $i): bool => $i['overridden'] || $i['excluded'] || $i['edit_count'] > 0)),
            ];
        }
        return $rows;
    }

    /**
     * Cleaned source text of every paragraph in the included chapters (for scanning the book, e.g. for ALL-CAPS words).
     * @return string[]
     */
    public function sourceTexts(string $projectId): array
    {
        $texts = [];
        $repo = new LogicalSectionRepository();
        foreach ($repo->all($projectId) as $section) {
            if ((int) $section['included'] !== 1) continue;
            foreach ($repo->blocks((string) $section['id']) as $block) $texts[] = self::clean((string) $block['source_text']);
        }
        return $texts;
    }

    /**
     * Saves what the user typed for one paragraph. The changed words are stored as edits to the source words behind
     * them, so later changes to other rules still apply around the edit. Empty text skips the paragraph.
     */
    public function saveParagraphText(string $projectId, string $logicalId, string $blockId, string $text): void
    {
        $this->assertBlock($projectId, $logicalId, $blockId);
        $typed = trim((string) preg_replace('/\s+/u', ' ', self::clean($text)));
        if (mb_strlen($typed) > self::MAX_OVERRIDE_CHARS) throw new InvalidArgumentException('That text is too long for one paragraph.');
        if ($typed === '') {
            $this->setExcluded($projectId, $logicalId, $blockId, true);
            return;
        }
        $this->db()->prepare('UPDATE narration_blocks SET override_text=NULL WHERE logical_section_id=:l AND source_block_id=:b')->execute(['l' => $logicalId, 'b' => $blockId]);
        $item = $this->item($projectId, $logicalId, $blockId);
        if ($typed === $item['narration']) return;
        $edits = ParagraphEdits::diff($item['cleaned'], $item['pieces'], $item['narration'], $typed, $item['edits']);
        $db = $this->db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM narration_edits WHERE logical_section_id=:l AND source_block_id=:b')->execute(['l' => $logicalId, 'b' => $blockId]);
        $insert = $db->prepare('INSERT INTO narration_edits(logical_section_id,source_block_id,src_start,src_len,src,dst) VALUES(:l,:b,:s,:n,:src,:dst)');
        foreach ($edits as $e) $insert->execute(['l' => $logicalId, 'b' => $blockId, 's' => $e['start'], 'n' => $e['len'], 'src' => $e['src'], 'dst' => $e['dst']]);
        $db->commit();
        $this->prune($logicalId, $blockId);
    }

    /**
     * A sentence of the book that contains the word, to hear a rule in context. With no project, the most recent ones are searched.
     */
    public function exampleSentence(?string $projectId, string $word, bool $wholeWord = true, bool $caseSensitive = false): ?string
    {
        return $this->exampleSentences($projectId, $word, $wholeWord, $caseSensitive, 1)[0] ?? null;
    }

    /**
     * Sentences of the book in which the rule would actually apply, in reading order: whole words only (a rule for "os"
     * does not fire inside "most"), the possessive included, case as the rule says.
     * @return string[]
     */
    public function exampleSentences(?string $projectId, string $word, bool $wholeWord = true, bool $caseSensitive = false, int $limit = 60, ?string $matchType = null): array
    {
        return array_column($this->exampleHits($projectId, $word, $wholeWord, $caseSensitive, $limit, $matchType), 'sentence');
    }

    /**
     * Sentences from the book that a rule would touch, each with where it is: project, chapter (logical section), paragraph.
     * @return list<array{sentence:string,project:string,section:string,chapter:string,block:string}>
     */
    public function exampleHits(?string $projectId, string $word, bool $wholeWord = true, bool $caseSensitive = false, int $limit = 60, ?string $matchType = null): array
    {
        if ($matchType === 'regex') {
            $word = trim($word);
            if (PronunciationService::validateRegex($word, $caseSensitive) !== null) return [];
            $pattern = PronunciationService::regexFull($word, $caseSensitive);
        } else {
            if ($matchType !== null) $wholeWord = $matchType === 'word';
            $word = trim(str_replace(["\u{2019}", "\u{2018}"], "'", $word));
            if ($word === '' || mb_strlen($word) > 100) return [];
            $body = preg_quote($word, '/');
            $pattern = '/' . ($wholeWord ? '(?<![\p{L}\p{N}_\'])' . $body . '(?:\'[sS])?(?![\p{L}\p{N}_\'])' : $body) . '/u' . ($caseSensitive ? '' : 'i');
        }
        $ids = $projectId !== null && $projectId !== '' ? [$projectId] : array_slice(array_column((new ProjectRepository())->all(false), 'id'), 0, 5);
        $found = [];
        $repo = new LogicalSectionRepository();
        foreach ($ids as $id) {
            foreach ($repo->all((string) $id) as $section) {
                if ((int) $section['included'] !== 1) continue;
                foreach ($repo->blocks((string) $section['id']) as $block) {
                    $paragraph = self::clean((string) $block['source_text']);
                    if (preg_match($pattern, $paragraph) !== 1) continue;
                    foreach (Segmenter::sentences($paragraph) as $sentence) {
                        if (preg_match($pattern, $sentence) !== 1) continue;
                        $key = mb_substr($sentence, 0, 600);
                        if (isset($found[$key])) continue;
                        $found[$key] = ['sentence' => $key, 'project' => (string) $id, 'section' => (string) $section['id'], 'chapter' => trim((string) $section['title']), 'block' => (string) $block['id']];
                        if (count($found) >= $limit) return array_values($found);
                    }
                }
            }
        }
        return array_values($found);
    }

    /** Removes every hand edit of a paragraph, so the rules alone decide its narration. */
    public function resetParagraph(string $projectId, string $logicalId, string $blockId): void
    {
        $this->assertBlock($projectId, $logicalId, $blockId);
        $this->db()->prepare('DELETE FROM narration_edits WHERE logical_section_id=:l AND source_block_id=:b')->execute(['l' => $logicalId, 'b' => $blockId]);
        $this->db()->prepare('UPDATE narration_blocks SET override_text=NULL WHERE logical_section_id=:l AND source_block_id=:b')->execute(['l' => $logicalId, 'b' => $blockId]);
        $this->prune($logicalId, $blockId);
    }

    /** @return array<string,mixed> */
    private function item(string $projectId, string $logicalId, string $blockId): array
    {
        foreach ($this->chapter($projectId, $logicalId)['items'] as $item) {
            if ($item['id'] === $blockId) return $item;
        }
        throw new InvalidArgumentException('That block is not part of this chapter.');
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
