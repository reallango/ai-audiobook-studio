<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * "Check pronunciations online" for a book: takes the words the text checks flagged, finds each one's pronunciation
 * online, and keeps what was found as suggested rules (with how sure it is) and what was not as a list to set by ear.
 * It runs as a background task; the results stay until a rule is made or the word is dismissed.
 */
final class PronCheckService
{
    public const DEFAULT_KINDS = ['proper', 'odd'];
    public const MAX_WORDS = 200;
    private const CONFIDENCE_ORDER = ['high' => 3, 'medium' => 2, 'low' => 1];

    public function __construct(private ?PronLookup $lookup = null)
    {
        $this->lookup ??= new PronLookup();
    }

    /** Queues a check of the book's flagged words. @param list<string> $kinds */
    public function start(string $projectId, array $kinds, bool $recheck = false): array
    {
        $kinds = array_values(array_intersect($kinds, array_keys(TextCheckService::KINDS)));
        if ($kinds === []) $kinds = self::DEFAULT_KINDS;
        return (new TaskService())->enqueue($projectId, 'pron_check', ['kinds' => $kinds, 'recheck' => $recheck]);
    }

    /**
     * The words to check: what the text checks flag for the chosen kinds (rules already cover the rest), most frequent
     * first, without the ones already checked unless asked to check again.
     * @param list<string> $kinds
     * @return list<array{text:string,what:string,count:int,kind:string}>
     */
    public function words(string $projectId, array $kinds, bool $recheck = false): array
    {
        $project = $this->project($projectId);
        $voice = (string) (($project['active_voice_profile_id'] ?? '') ?: VoiceCatalog::DEFAULT_VOICE);
        $texts = (new NarrationService())->sourceTexts($projectId);
        $effective = (new PronunciationService())->effective($projectId, $voice);
        $dictionary = TextCheckService::dictionary();
        $done = $recheck ? [] : array_flip(array_column($this->rows($projectId), 'word'));
        $words = [];
        foreach ($kinds as $kind) {
            foreach (TextCheckService::scan($texts, $kind, $effective, $dictionary, (int) ($project['normalize_caps'] ?? 1) === 1, self::MAX_WORDS) as $row) {
                if (isset($done[$row['text']]) || isset($words[$row['text']])) continue;
                if (preg_match('/\p{L}/u', $row['text']) !== 1) continue;      // numbers and dates are not looked up
                $words[$row['text']] = $row + ['kind' => $kind];
            }
        }
        usort($words, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        return array_slice(array_values($words), 0, self::MAX_WORDS);
    }

    /**
     * Checks the words one by one, storing each result as it comes. $progress(done, total) is told after each word.
     * @param array{kinds?:list<string>,recheck?:bool} $params
     * @return array{checked:int,found:int}
     */
    public function run(string $projectId, array $params, ?callable $progress = null): array
    {
        $project = $this->project($projectId);
        $context = trim($project['title'] . ' ' . (new IntroService())->author($projectId));
        $words = $this->words($projectId, $params['kinds'] ?? self::DEFAULT_KINDS, !empty($params['recheck']));
        $found = 0;
        foreach ($words as $i => $w) {
            $r = $this->lookup->find($w['text'], $context);
            $sounds = $r['status'] === 'found' ? IpaConverter::toKokoro($r['ipa']) : '';
            if ($r['status'] === 'found' && $sounds === '') $r = ['status' => 'none'] + $r;
            if ($r['status'] === 'found') $found++;
            $this->db()->prepare('INSERT INTO pron_checks(project_id,word,kind,status,ipa,sounds,respelling,lang,confidence,source_title,source_url,note,dismissed,checked_at)
                VALUES(:p,:w,:k,:s,:ipa,:snd,:rsp,:lang,:conf,:st,:su,:note,0,CURRENT_TIMESTAMP)
                ON CONFLICT(project_id,word) DO UPDATE SET kind=excluded.kind, status=excluded.status, ipa=excluded.ipa, sounds=excluded.sounds, respelling=excluded.respelling,
                  lang=excluded.lang, confidence=excluded.confidence, source_title=excluded.source_title, source_url=excluded.source_url, note=excluded.note, checked_at=CURRENT_TIMESTAMP')
                ->execute(['p' => $projectId, 'w' => $w['text'], 'k' => $w['kind'], 's' => $r['status'], 'ipa' => $r['ipa'], 'snd' => $sounds,
                    'rsp' => $sounds !== '' ? IpaConverter::respell($sounds) : '', 'lang' => $r['lang'], 'conf' => $r['confidence'],
                    'st' => $r['source_title'], 'su' => $r['source_url'], 'note' => $r['note']]);
            if ($progress !== null) $progress($i + 1, count($words));
        }
        return ['checked' => count($words), 'found' => $found];
    }

    /** Results still to decide on, most certain first; rules made since are left out. @return list<array<string,mixed>> */
    public function rows(string $projectId, bool $withDismissed = true): array
    {
        $s = $this->db()->prepare('SELECT * FROM pron_checks WHERE project_id=:p' . ($withDismissed ? '' : ' AND dismissed=0') . ' ORDER BY status, word COLLATE NOCASE');
        $s->execute(['p' => $projectId]);
        $rows = $s->fetchAll();
        usort($rows, static fn (array $a, array $b): int => [(self::CONFIDENCE_ORDER[$b['confidence']] ?? 0), $a['word']] <=> [(self::CONFIDENCE_ORDER[$a['confidence']] ?? 0), $b['word']]);
        return $rows;
    }

    /** The open results split for the page: found (with a rule to make) and not found. @return array{found:list<array<string,mixed>>,missing:list<array<string,mixed>>} */
    public function results(string $projectId): array
    {
        $own = array_flip(array_map('mb_strtolower', array_column((new PronunciationService())->rules('project', $projectId), 'pattern')));
        $found = $missing = [];
        foreach ($this->rows($projectId, false) as $r) {
            if (isset($own[mb_strtolower((string) $r['word'])])) continue;        // a rule was made for it since
            if ($r['status'] === 'found') $found[] = $r + ['spoken' => IpaConverter::markup((string) $r['word'], (string) $r['sounds'])];
            else $missing[] = $r;
        }
        return ['found' => $found, 'missing' => $missing];
    }

    /** Makes this book's rule for a found word: the exact sounds, with where they came from as the note. */
    public function makeRule(string $projectId, string $word): void
    {
        $r = $this->row($projectId, $word);
        if ($r === null || $r['status'] !== 'found') throw new InvalidArgumentException('No pronunciation was found for that word.');
        (new PronunciationService())->save('project', $projectId, (string) $r['word'], IpaConverter::markup((string) $r['word'], (string) $r['sounds']), true, true,
            mb_substr('Sounds like ' . $r['respelling'] . ' (' . $r['source_title'] . ', ' . $r['confidence'] . ' confidence)', 0, 300), 'word');
    }

    /** Makes rules for every open result at or above a confidence. Returns how many. */
    public function makeRules(string $projectId, string $atLeast = 'high'): int
    {
        $min = self::CONFIDENCE_ORDER[$atLeast] ?? 3;
        $n = 0;
        foreach ($this->results($projectId)['found'] as $r) {
            if ((self::CONFIDENCE_ORDER[$r['confidence']] ?? 0) < $min) continue;
            $this->makeRule($projectId, (string) $r['word']);
            $n++;
        }
        return $n;
    }

    public function dismiss(string $projectId, string $word): void
    {
        $this->db()->prepare('UPDATE pron_checks SET dismissed=1 WHERE project_id=:p AND word=:w')->execute(['p' => $projectId, 'w' => $word]);
    }

    /** @return array<string,mixed>|null */
    private function row(string $projectId, string $word): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM pron_checks WHERE project_id=:p AND word=:w');
        $s->execute(['p' => $projectId, 'w' => $word]);
        return $s->fetch() ?: null;
    }

    /** @return array<string,mixed> */
    private function project(string $projectId): array
    {
        $s = $this->db()->prepare('SELECT * FROM projects WHERE id=:id');
        $s->execute(['id' => $projectId]);
        return $s->fetch() ?: throw new InvalidArgumentException('Project not found.');
    }

    private function db(): PDO
    {
        return Database::connection();
    }
}
