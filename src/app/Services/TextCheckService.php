<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Finds text in a book that a speech model may read badly: names, numbers, Roman numerals, initialisms, abbreviations,
 * dates and times, odd spellings. It only proposes; a person decides which become rules.
 */
final class TextCheckService
{
    public const KINDS = [
        'proper' => ['Names and unusual words', 'Capitalised words that are not in the dictionary, so the voice may guess their sound (Bajor, Sisko).'],
        'number' => ['Numbers', 'Years, ordinals, decimals, amounts and plain numbers, each in the shape it appears.'],
        'roman' => ['Roman numerals', 'II, IV, XII: Kokoro spells these as letters unless told otherwise.'],
        'initialism' => ['Initialisms', 'Short ALL-CAPS words such as FBI or USS that may need spelling out.'],
        'abbrev' => ['Abbreviations', 'Mr., Dr., St., e.g. and the like, which the voice may read letter by letter or skip.'],
        'datetime' => ['Dates and times', '5:30 pm, 12/04/1998, the 1990s, March 3rd.'],
        'odd' => ['Odd spellings', 'Words with digits, accents or capitals in the middle, stretched letters (Noooo), and similar.'],
    ];

    private const MONTHS = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';
    private const ROMAN = '/^M{0,3}(?:CM|CD|D?C{0,3})(?:XC|XL|L?X{0,3})(?:IX|IV|V?I{0,3})$/';
    private const ABBREV = '(?:Mr|Mrs|Ms|Messrs|Dr|St|Mt|Jr|Sr|Prof|Rev|Hon|Gen|Col|Lt|Capt|Cmdr|Sgt|Adm|Gov|Sen|Rep|vs|etc|No|Nos|Fig|approx|dept|est|Inc|Ltd|Co|Corp|Ave|Blvd|Rd)\.|(?:\p{L}\.){2,}';
    private static ?array $words = null;

    /** @return array<string,true>|null lowercase and exact-case dictionary words, or null when no word list is installed */
    public static function dictionary(?string $path = null): ?array
    {
        if ($path === null && self::$words !== null) return self::$words;
        $candidates = $path !== null ? [$path] : array_filter([(string) getenv('AUDIOBOOK_WORDLIST'), '/usr/share/dict/american-english', '/usr/share/dict/words']);
        foreach ($candidates as $file) {
            if (!is_readable($file)) continue;
            $set = [];
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                $set[$line] = true;
                $set[mb_strtolower($line)] = true;
            }
            if ($path === null) self::$words = $set;
            return $set;
        }
        return null;
    }

    /**
     * @param string[] $texts paragraphs of source text
     * @param array<string,array<string,mixed>> $effective rules in force (a candidate a rule already changes is left out)
     * @param array<string,true>|null $dictionary
     * @return list<array{text:string,what:string,count:int,becomes:string,example:string}>
     */
    public static function scan(array $texts, string $kind, array $effective, ?array $dictionary, bool $normalizeCaps = true, int $limit = 150): array
    {
        if (!isset(self::KINDS[$kind])) return [];
        $svc = new PronunciationService();
        $found = [];      // text => [what, count, example]
        $add = static function (string $token, string $what, string $paragraph, int $offset) use (&$found): void {
            if (!isset($found[$token])) $found[$token] = ['what' => $what, 'count' => 0, 'example' => self::around($paragraph, $offset, strlen($token))];
            $found[$token]['count']++;
        };
        foreach ($texts as $raw) {
            $paragraph = str_replace(["\u{2019}", "\u{2018}"], "'", $raw);
            foreach (self::candidates($paragraph, $kind, $dictionary) as [$token, $what, $offset]) $add($token, $what, $paragraph, $offset);
        }
        $rows = [];
        foreach ($found as $token => $f) {
            $token = (string) $token;
            $changed = $svc->apply($token, $effective, false)['text'];
            if ($changed !== $token) continue;                 // a rule already decides it
            $rows[] = ['text' => $token, 'what' => $f['what'], 'count' => $f['count'], 'becomes' => $svc->apply($token, $effective, $normalizeCaps)['text'], 'example' => $f['example']];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['text']] <=> [$a['count'], $b['text']]);
        return array_slice($rows, 0, $limit);
    }

    /** @return iterable<array{0:string,1:string,2:int}> token, label, byte offset in the paragraph */
    private static function candidates(string $p, string $kind, ?array $dict): iterable
    {
        switch ($kind) {
            case 'proper':
                foreach (self::matches('/' . PronunciationService::WORD_BEFORE . '\p{Lu}[\p{L}]*(?:[\'-][\p{L}]+)*(?![\p{L}\p{N}_])/u', $p) as [$word, $at]) {
                    $base = preg_replace('/\'s$/i', '', $word) ?? $word;
                    if (mb_strlen($base) < 2 || preg_match('/^\p{Lu}+$/u', $base)) continue;                  // ALL-CAPS words have their own list
                    $before = rtrim(substr($p, 0, $at), " \t\"'(\u{201C}\u{2014}-[");
                    $startsSentence = $before === '' || preg_match('/[.!?:]\z/u', $before) === 1;
                    $known = $dict !== null && (isset($dict[mb_strtolower($base)]) || isset($dict[$base]));
                    if ($known) continue;
                    if ($startsSentence && $dict === null) continue;                                          // without a dictionary, only mid-sentence capitals are trusted
                    yield [$base, 'name or unusual word', $at];
                }
                return;
            case 'number':
                // times (5:30) and slash dates (12/04) belong to Dates and times, so numbers next to ':' or '/' are skipped
                foreach (self::matches('/(?<![\d:\/.,])[$£€]?\d[\d,]*(?:\.\d+)?(?:st|nd|rd|th|%|s)?(?![\p{L}\p{N}]|[:\/]\d)/u', $p) as [$n, $at]) {
                    $n = rtrim($n, ',');
                    $what = match (true) {
                        preg_match('/^[$£€]/u', $n) === 1 => 'amount of money',
                        str_ends_with($n, '%') => 'percentage',
                        preg_match('/\d(st|nd|rd|th)$/', $n) === 1 => 'ordinal',
                        preg_match('/^(1[0-9]|20)\d\d$/', $n) === 1 => 'year or number',
                        str_contains($n, '.') => 'decimal',
                        str_contains($n, ',') => 'number with commas',
                        default => 'whole number',
                    };
                    yield [$n, $what, $at];
                }
                return;
            case 'roman':
                foreach (self::matches('/(?<![\p{L}\p{N}_\'-])[IVXLCDM]{2,}(?![\p{L}\p{N}_\'-])/u', $p) as [$r, $at]) {
                    if (preg_match(self::ROMAN, $r) === 1) yield [$r, 'Roman numeral', $at];
                }
                return;
            case 'initialism':
                foreach (self::matches('/(?<![\p{L}\p{N}_\'-])\p{Lu}{2,5}(?![\p{L}\p{N}_\'-])/u', $p) as [$w, $at]) {
                    if (preg_match(self::ROMAN, $w) === 1) continue;
                    yield [$w, 'initialism', $at];
                }
                return;
            case 'abbrev':
                foreach (self::matches('/(?<![\p{L}\p{N}])(?:' . self::ABBREV . ')/u', $p) as [$a, $at]) yield [$a, 'abbreviation', $at];
                return;
            case 'datetime':
                $patterns = [
                    '/(?<![\p{N}:])\d{1,2}:\d{2}(?::\d{2})?(?:\s?[AaPp]\.?[Mm]\.?)?(?![\p{N}])/u' => 'time',
                    '/(?<![\p{N}\/])\d{1,2}\/\d{1,2}(?:\/\d{2,4})?(?![\p{N}\/])/u' => 'date with slashes',
                    '/(?<![\p{N}])(?:1[0-9]|20)\d0s(?![\p{L}])/u' => 'decade',
                    '/(?<![\p{L}])(?:' . self::MONTHS . ')\.? \d{1,2}(?:st|nd|rd|th)?(?:, \d{4})?(?![\p{N}])/u' => 'date',
                    '/(?<![\p{N}])\d{1,2}(?:st|nd|rd|th)? (?:of )?(?:' . self::MONTHS . ')(?:,? \d{4})?(?![\p{L}])/u' => 'date',
                ];
                foreach ($patterns as $re => $what) foreach (self::matches($re, $p) as [$d, $at]) yield [$d, $what, $at];
                return;
            case 'odd':
                foreach (self::matches('/(?<![\p{L}\p{N}_])[\p{L}\p{N}][\p{L}\p{N}\'-]*(?![\p{L}\p{N}_])/u', $p) as [$w, $at]) {
                    $why = match (true) {
                        preg_match('/\p{L}/u', $w) === 1 && preg_match('/\p{N}/u', $w) === 1 => 'letters and digits mixed',
                        preg_match('/[^\x00-\x7F]/', $w) === 1 && preg_match('/[\x{2018}\x{2019}]/u', $w) !== 1 => 'accented or non-English letters',
                        preg_match('/(\p{L})\1{2,}/ui', $w) === 1 => 'stretched letters',
                        preg_match('/\p{Ll}\p{Lu}/u', $w) === 1 => 'capital inside the word',
                        default => '',
                    };
                    if ($why === '') continue;
                    if ($dict !== null && isset($dict[mb_strtolower($w)])) continue;
                    yield [$w, $why, $at];
                }
                return;
        }
    }

    /** @return list<array{0:string,1:int}> */
    private static function matches(string $re, string $text): array
    {
        $out = [];
        if (@preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE) > 0) foreach ($m[0] as [$s, $o]) $out[] = [$s, (int) $o];
        return $out;
    }

    /** The sentence-ish stretch around a match, kept short. */
    private static function around(string $paragraph, int $offset, int $length): string
    {
        $start = max(0, $offset - 70);
        $text = mb_strcut($paragraph, $start, $length + 140, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return ($start > 0 ? '…' : '') . trim($text) . (strlen($paragraph) > $start + $length + 140 ? '…' : '');
    }
}
