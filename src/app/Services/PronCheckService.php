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
    public const REGIONS = ['americas' => 'the Americas (US English, Latin American Spanish)', 'europe' => 'Europe (British English, Spain, France)'];
    private const CONFIDENCE_ORDER = ['high' => 3, 'medium' => 2, 'low' => 1];

    public function __construct(private ?PronLookup $lookup = null, private ?PronCheckClient $voice = null)
    {
        $this->lookup ??= new PronLookup();
        $this->voice ??= new PronCheckClient();
    }

    /** Queues a check of the book's flagged words. @param list<string> $kinds */
    public function start(string $projectId, array $kinds, bool $recheck = false, ?string $region = null, bool $voiceOnly = false, ?string $subject = null): array
    {
        if ($region !== null) $this->setRegion($projectId, $region);
        if ($subject !== null) $this->setSubject($projectId, $subject);
        if ($voiceOnly) {
            if (!PronCheckClient::configured()) throw new InvalidArgumentException('The pronunciation checker is not set up (see Diagnostics).');
            return (new TaskService())->enqueue($projectId, 'pron_check', ['voice_only' => true]);
        }
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
        $done = $recheck ? [] : array_flip(array_map('mb_strtolower', array_column($this->rows($projectId), 'word')));
        $words = [];
        foreach ($kinds as $kind) {
            foreach (TextCheckService::scan($texts, $kind, $effective, $dictionary, (int) ($project['normalize_caps'] ?? 1) === 1, self::MAX_WORDS) as $row) {
                $key = mb_strtolower($row['text']);
                if (isset($done[$key])) continue;
                if (preg_match('/\p{L}/u', $row['text']) !== 1) continue;      // numbers and dates are not looked up
                if (self::plainEnglish($row['text'], $dictionary)) continue;
                if (isset($words[$key])) {                                      // Mamá and mamá: one word, its commoner spelling
                    if ($row['count'] > $words[$key]['count']) $words[$key] = ['count' => $words[$key]['count'] + $row['count']] + $row + ['kind' => $kind];
                    else $words[$key]['count'] += $row['count'];
                    continue;
                }
                $words[$key] = $row + ['kind' => $kind];
            }
        }
        usort($words, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        return array_slice(array_values($words), 0, self::MAX_WORDS);
    }

    /**
     * English made of words the dictionary knows, which the voice reads fine: God-fearing, twenty-nine, how'd, Dutch'll.
     * @param array<string,true>|null $dictionary
     */
    public static function plainEnglish(string $word, ?array $dictionary): bool
    {
        if ($dictionary === null) return false;
        $known = static fn (string $w): bool => isset($dictionary[$w]) || isset($dictionary[mb_strtolower($w)]);
        if (preg_match("/^(\p{L}+)['’](d|ll|s|re|ve|t|m)$/iu", $word, $m)) return $known($m[1]);
        if (str_contains($word, '-')) {
            $parts = array_filter(explode('-', $word), static fn (string $p): bool => $p !== '');
            return $parts !== [] && count(array_filter($parts, $known)) === count($parts);
        }
        return false;
    }

    public function region(string $projectId): string
    {
        $r = (string) ($this->project($projectId)['pron_region'] ?? 'americas');
        return isset(self::REGIONS[$r]) ? $r : 'americas';
    }

    public function setRegion(string $projectId, string $region): void
    {
        if (!isset(self::REGIONS[$region])) throw new InvalidArgumentException('Unknown accent region.');
        $this->db()->prepare('UPDATE projects SET pron_region=:r WHERE id=:id')->execute(['r' => $region, 'id' => $projectId]);
    }

    /** What the book is about ("Star Trek"): names are searched for with it first. */
    public function subject(string $projectId): string
    {
        return (string) ($this->project($projectId)['pron_subject'] ?? '');
    }

    public function setSubject(string $projectId, string $subject): void
    {
        $subject = trim((string) preg_replace('/\s+/u', ' ', $subject));
        if (mb_strlen($subject) > 80) throw new InvalidArgumentException('The subject can be at most 80 characters.');
        $this->db()->prepare('UPDATE projects SET pron_subject=:s WHERE id=:id')->execute(['s' => $subject, 'id' => $projectId]);
    }

    /**
     * Checks the words one by one, storing each result as it comes. $progress(done, total) is told after each word.
     * Where a word is in several languages, the one the book uses most for its other words is chosen; when every word
     * is in, each choice is made again with the whole book's count.
     * @param array{kinds?:list<string>,recheck?:bool} $params
     * @return array{checked:int,found:int}
     */
    public function run(string $projectId, array $params, ?callable $progress = null): array
    {
        if (!empty($params['voice_only'])) {
            $n = $this->compareWithVoice($projectId);
            return ['checked' => $n, 'found' => 0];
        }
        $project = $this->project($projectId);
        $context = trim($project['title'] . ' ' . (new IntroService())->author($projectId));
        $words = $this->words($projectId, $params['kinds'] ?? self::DEFAULT_KINDS, !empty($params['recheck']));
        $region = $this->region($projectId);
        $subject = $this->subject($projectId);
        $rank = $this->languageCounts($projectId);
        $looked = [];
        foreach ($words as $i => $w) {
            $r = $this->lookup->find($w['text'], $context, $subject);
            foreach (array_unique(array_column(is_array($r['options'] ?? null) ? $r['options'] : [], 'lang')) as $lang) if ($lang !== 'en') $rank[$lang] = ($rank[$lang] ?? 0) + 1;
            $looked[] = [$w, $r];
            $this->store($projectId, $w, PronLookup::pick($r, $region, $rank));
            if ($progress !== null) $progress($i + 1, count($words));
        }
        $found = 0;
        foreach ($looked as [$w, $r]) {
            if (!empty($r['options'])) $this->store($projectId, $w, PronLookup::pick($r, $region, $rank));
            if ($r['status'] === 'found') $found++;
        }
        if (PronCheckClient::configured() && $words !== []) {
            try {
                $this->compareWithVoice($projectId);
            } catch (\RuntimeException) {
                // the checker being away does not lose what was found online; "Compare with the voice" can run later
            }
        }
        return ['checked' => count($words), 'found' => $found];
    }

    /**
     * Asks the pronunciation checker how the book's voice reads each open word and how close that is to what was
     * found online, and for a plain spelling that comes closer. Returns how many words were compared.
     */
    public function compareWithVoice(string $projectId): int
    {
        $project = $this->project($projectId);
        $voice = (string) (($project['active_voice_profile_id'] ?? '') ?: VoiceCatalog::DEFAULT_VOICE);
        $rows = $this->rows($projectId, false);
        if ($rows === []) return 0;
        $items = array_map(static fn (array $r): array => ['word' => (string) $r['word']] + ($r['status'] === 'found' && $r['sounds'] !== '' ? ['sounds' => (string) $r['sounds']] : []), $rows);
        $answers = $this->voice->assess($items, str_starts_with($voice, 'b'), (float) $this->minimum($projectId));
        $update = $this->db()->prepare('UPDATE pron_checks SET voice_reads=:vr, voice_score=:vs, alt_spelling=:as, alt_reads=:ar, alt_score=:asc WHERE project_id=:p AND word=:w');
        foreach ($answers as $a) {
            $alt = is_array($a['spelling'] ?? null) ? $a['spelling'] : null;
            $update->execute(['p' => $projectId, 'w' => (string) $a['word'], 'vr' => (string) ($a['voice'] ?? ''), 'vs' => isset($a['score']) ? (float) $a['score'] : null,
                'as' => $alt !== null ? (string) $alt['text'] : '', 'ar' => $alt !== null ? (string) $alt['reads'] : '', 'asc' => $alt !== null ? (float) $alt['score'] : null]);
        }
        return count($answers);
    }

    /** The score at which the voice counts as already saying a word right. */
    public function minimum(string $projectId): int
    {
        $m = (int) ($this->project($projectId)['pron_minimum'] ?? PronCheckClient::DEFAULT_MINIMUM);
        return $m >= 50 && $m <= 100 ? $m : PronCheckClient::DEFAULT_MINIMUM;
    }

    public function setMinimum(string $projectId, int $minimum): void
    {
        if ($minimum < 50 || $minimum > 100) throw new InvalidArgumentException('Choose a score between 50 and 100.');
        $this->db()->prepare('UPDATE projects SET pron_minimum=:m WHERE id=:id')->execute(['m' => $minimum, 'id' => $projectId]);
    }

    /** @param array<string,mixed> $w @param array<string,mixed> $r */
    private function store(string $projectId, array $w, array $r): void
    {
        $sounds = $r['status'] === 'found' ? IpaConverter::toKokoro((string) $r['ipa']) : '';
        if ($r['status'] === 'found' && $sounds === '') $r['status'] = 'none';
        $this->db()->prepare('INSERT INTO pron_checks(project_id,word,kind,status,ipa,sounds,respelling,lang,accent,common,confidence,source_title,source_url,note,dismissed,checked_at)
            VALUES(:p,:w,:k,:s,:ipa,:snd,:rsp,:lang,:acc,:com,:conf,:st,:su,:note,0,CURRENT_TIMESTAMP)
            ON CONFLICT(project_id,word) DO UPDATE SET kind=excluded.kind, status=excluded.status, ipa=excluded.ipa, sounds=excluded.sounds, respelling=excluded.respelling,
              lang=excluded.lang, accent=excluded.accent, common=excluded.common, confidence=excluded.confidence, source_title=excluded.source_title,
              source_url=excluded.source_url, note=excluded.note, checked_at=CURRENT_TIMESTAMP')
            ->execute(['p' => $projectId, 'w' => $w['text'], 'k' => $w['kind'], 's' => $r['status'], 'ipa' => (string) $r['ipa'], 'snd' => $sounds,
                'rsp' => $sounds !== '' ? IpaConverter::respell($sounds) : '', 'lang' => (string) $r['lang'], 'acc' => (string) ($r['accent'] ?? ''),
                'com' => !empty($r['ordinary']) ? 1 : 0, 'conf' => (string) $r['confidence'], 'st' => (string) $r['source_title'], 'su' => (string) $r['source_url'], 'note' => (string) $r['note']]);
    }

    /** How many of the book's checked words are in each language (English left out), from earlier checks. @return array<string,int> */
    private function languageCounts(string $projectId): array
    {
        $s = $this->db()->prepare("SELECT lang, COUNT(*) FROM pron_checks WHERE project_id=:p AND status='found' AND lang NOT IN ('', 'en') GROUP BY lang");
        $s->execute(['p' => $projectId]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** A dictionary word with an English pronunciation, spelled in plain letters: the voice's own dictionary will know it. @param array<string,mixed> $r */
    public static function ordinaryEnglish(array $r): bool
    {
        return (int) ($r['common'] ?? 0) === 1 && ($r['lang'] ?? '') === 'en' && preg_match("/^[A-Za-z'’-]+$/", (string) $r['word']) === 1;
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

    /**
     * The open results split for the page: found (with a rule to make), ordinary English words (found, but the voice
     * very likely reads them right already), and not found. A word in two capitals (Mamá, mamá) shows once.
     * Compared with the voice, a found word the voice already says closely enough goes to "already" instead, and the
     * rest are listed worst first.
     * @return array{found:list<array<string,mixed>>,ordinary:list<array<string,mixed>>,already:list<array<string,mixed>>,missing:list<array<string,mixed>>}
     */
    public function results(string $projectId): array
    {
        $own = array_flip(array_map('mb_strtolower', array_column((new PronunciationService())->rules('project', $projectId), 'pattern')));
        $minimum = $this->minimum($projectId);
        $found = $ordinary = $already = $missing = [];
        $seen = [];
        foreach ($this->rows($projectId, false) as $r) {
            $key = mb_strtolower((string) $r['word']);
            if (isset($own[$key]) || isset($seen[$key])) continue;               // a rule was made for it since, or shown already
            $seen[$key] = true;
            if ($r['status'] !== 'found') { $missing[] = $r; continue; }
            $r += ['spoken' => IpaConverter::markup((string) $r['word'], (string) $r['sounds'])];
            if (self::ordinaryEnglish($r)) $ordinary[] = $r;
            elseif ($r['voice_score'] !== null && (float) $r['voice_score'] >= $minimum) $already[] = $r;
            else $found[] = $r;
        }
        // worst first once compared with the voice; words not compared yet keep their place after them
        usort($found, static fn (array $a, array $b): int => [$a['voice_score'] === null, (float) $a['voice_score']] <=> [$b['voice_score'] === null, (float) $b['voice_score']]);
        return ['found' => $found, 'ordinary' => $ordinary, 'already' => $already, 'missing' => $missing];
    }

    /**
     * Makes this book's rule for a found word: the exact sounds, with where they came from as the note; or, with
     * $spelling, the plain spelling the checker found the voice reads closest.
     */
    public function makeRule(string $projectId, string $word, bool $spelling = false): void
    {
        $r = $this->row($projectId, $word);
        if ($r === null || $r['status'] !== 'found') throw new InvalidArgumentException('No pronunciation was found for that word.');
        if ($spelling) {
            if ((string) $r['alt_spelling'] === '') throw new InvalidArgumentException('No spelling was suggested for that word.');
            (new PronunciationService())->save('project', $projectId, (string) $r['word'], (string) $r['alt_spelling'], (int) ($r['common'] ?? 0) !== 1, true,
                mb_substr('Spelled so the voice says ' . $r['respelling'] . ' (' . $r['source_title'] . '; ' . round((float) $r['alt_score']) . '/100 match)', 0, 300), 'word');
            return;
        }
        (new PronunciationService())->save('project', $projectId, (string) $r['word'], IpaConverter::markup((string) $r['word'], (string) $r['sounds']), (int) ($r['common'] ?? 0) !== 1, true,
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
        $this->db()->prepare('UPDATE pron_checks SET dismissed=1 WHERE project_id=:p AND word=:w COLLATE NOCASE')->execute(['p' => $projectId, 'w' => $word]);
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
