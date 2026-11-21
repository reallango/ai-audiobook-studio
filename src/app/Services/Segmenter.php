<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Splits narration text into generation segments. Preferred boundaries, in order: paragraph, sentence,
 * clause, and a hard split at a word boundary only as a last resort. Each segment says how long a pause follows it.
 */
final class Segmenter
{
    public const DEFAULT_MAX_CHARS = 900;
    public const GAP_PARAGRAPH_MS = 800;
    public const GAP_SENTENCE_MS = 250;

    private const ABBREVIATIONS = [
        'mr', 'mrs', 'ms', 'dr', 'st', 'jr', 'sr', 'prof', 'vs', 'etc', 'gen', 'col', 'capt', 'lt', 'cmdr', 'adm', 'maj', 'sgt',
        'cdr', 'ens', 'rev', 'hon', 'mt', 'no', 'fig', 'approx', 'inc', 'ltd', 'co',
    ];

    /** @return array<int,array{text:string,gap_ms:int}> */
    public static function split(string $text, int $maxChars = self::DEFAULT_MAX_CHARS): array
    {
        $maxChars = max(100, $maxChars);
        $segments = [];
        foreach (preg_split('/\n\s*\n/u', trim($text)) ?: [] as $paragraph) {
            $paragraph = trim((string) preg_replace('/\s+/u', ' ', $paragraph));
            if ($paragraph === '') continue;
            $pieces = mb_strlen($paragraph) <= $maxChars ? [$paragraph] : self::pack(self::sentences($paragraph), $maxChars);
            foreach ($pieces as $i => $piece) {
                $segments[] = ['text' => $piece, 'gap_ms' => $i === array_key_last($pieces) ? self::GAP_PARAGRAPH_MS : self::GAP_SENTENCE_MS];
            }
        }
        return $segments;
    }

    /** @return string[] sentences, with abbreviations and initials kept inside their sentence */
    public static function sentences(string $paragraph): array
    {
        $raw = preg_split('/(?<=[.!?\x{2026}])(?:["\'\x{201D}\x{2019})\]]*)\s+(?=["\'\x{201C}\x{2018}(\[]?[\p{Lu}\p{N}])/u', $paragraph) ?: [$paragraph];
        $out = [];
        foreach ($raw as $piece) {
            if ($out !== [] && self::endsWithAbbreviation($out[array_key_last($out)])) {
                $out[array_key_last($out)] .= ' ' . $piece;
            } else {
                $out[] = $piece;
            }
        }
        return $out;
    }

    private static function endsWithAbbreviation(string $sentence): bool
    {
        if (!preg_match('/(?:^|[\s("\'])([\p{L}]+)\.$/u', $sentence, $m)) return false;
        $word = mb_strtolower($m[1]);
        return in_array($word, self::ABBREVIATIONS, true) || (mb_strlen($word) === 1 && mb_strtoupper($word) === $m[1]);
    }

    /**
     * Packs sentences into segments of at most $max characters. A sentence longer than $max is split at clause
     * punctuation, and only then at a word boundary.
     * @param string[] $sentences
     * @return string[]
     */
    private static function pack(array $sentences, int $max): array
    {
        $units = [];
        foreach ($sentences as $sentence) {
            foreach (mb_strlen($sentence) <= $max ? [$sentence] : self::splitLong($sentence, $max) as $unit) $units[] = $unit;
        }
        $segments = [];
        $current = '';
        foreach ($units as $unit) {
            $candidate = $current === '' ? $unit : $current . ' ' . $unit;
            if (mb_strlen($candidate) <= $max) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') $segments[] = $current;
            $current = $unit;
        }
        if ($current !== '') $segments[] = $current;
        return $segments;
    }

    /** @return string[] */
    private static function splitLong(string $sentence, int $max): array
    {
        $clauses = preg_split('/(?<=[,;:\x{2014}\x{2013}])\s+/u', $sentence) ?: [$sentence];
        $out = [];
        $current = '';
        foreach ($clauses as $clause) {
            foreach (mb_strlen($clause) <= $max ? [$clause] : self::hardSplit($clause, $max) as $part) {
                $candidate = $current === '' ? $part : $current . ' ' . $part;
                if (mb_strlen($candidate) <= $max) { $current = $candidate; continue; }
                if ($current !== '') $out[] = $current;
                $current = $part;
            }
        }
        if ($current !== '') $out[] = $current;
        return $out;
    }

    /** @return string[] last resort: cut at the last space before the limit */
    private static function hardSplit(string $text, int $max): array
    {
        $parts = [];
        while (mb_strlen($text) > $max) {
            $cut = mb_strrpos(mb_substr($text, 0, $max + 1), ' ');
            if ($cut === false || $cut < (int) ($max * 0.4)) $cut = $max;
            $parts[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if ($text !== '') $parts[] = $text;
        return $parts;
    }
}
