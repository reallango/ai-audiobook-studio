<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Judges a section of a book from its text rather than its file name: is it story worth narrating, and what is it called.
 * Both are suggestions; a person confirms them.
 */
final class SectionAdvisor
{
    /** Fewer words than this is a title page, a part divider or a watermark, not a chapter. */
    private const MIN_WORDS = 120;
    private const NOT_STORY = '/cover|copyright|colophon|title.?page|half.?title|table.?of.?contents|\btoc\b|navigation|also.by|other.books|about.the.(author|publisher)|acknowledg|permissions|imprint|newsletter|credits|dedication|epigraph|\bnav\b/iu';
    private const WATERMARK = '/oceanofpdf|^\s*https?:\/\/|^\s*www\./iu';
    private const HEADING_WORDS = '(?:chapter|part|book|prologue|epilogue|interlude|appendix|section|preface|foreword|introduction|afterword)';

    /**
     * @param string[] $texts the section's paragraphs, in order
     */
    public static function keep(string $title, string $href, array $texts, bool $linear = true): bool
    {
        if (!$linear) return false;
        $texts = self::withoutWatermark($texts);
        $words = self::words($texts);
        if ($words < self::MIN_WORDS) return false;
        if (preg_match(self::NOT_STORY, mb_strtolower($title . ' ' . $href)) === 1 && $words < 1500) return false;
        // A contents list: many very short lines and little else.
        $blocks = count($texts);
        if ($blocks >= 15 && $words / $blocks < 6) return false;
        return true;
    }

    /**
     * A name for the section from its own opening lines: "Chapter 12", "Prologue", or "Chapter 12: The Long Way Home".
     * Null when nothing in the first lines looks like a heading.
     * @param string[] $texts
     * @param string[] $types block types ("h1", "p", ...) in the same order, when known
     */
    public static function name(array $texts, array $types = []): ?string
    {
        $lines = [];
        foreach ($texts as $i => $text) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            if ($text === '' || preg_match(self::WATERMARK, $text) === 1) continue;
            $lines[] = [$text, (string) ($types[$i] ?? 'p')];
            if (count($lines) >= 3) break;
        }
        if (!$lines) return null;
        [$first, $firstType] = $lines[0];
        $isHeading = static fn (string $t, string $type): bool => mb_strlen($t) <= 70 && str_word_count($t) <= 9 && preg_match('/[.!?,;]$/u', $t) !== 1
            && (preg_match('/^h[1-6]$/', $type) === 1 || preg_match('/^' . self::HEADING_WORDS . '\b/iu', $t) === 1 || preg_match('/^\p{Lu}[^.]*$/u', $t) === 1);
        $bare = preg_match('/^(\d{1,3}|[IVXLC]{1,6})\.?$/u', $first) === 1;      // a chapter that is only numbered: 7, VII
        if (!$bare && !$isHeading($first, $firstType)) return null;
        $name = $bare ? 'Chapter ' . rtrim($first, '.') : self::tidy($first);
        // "Chapter 12" followed by a short title line becomes "Chapter 12: Title".
        if (($bare || preg_match('/^' . self::HEADING_WORDS . '\s+[\p{L}\p{N}-]+$/iu', $first) === 1) && isset($lines[1])) {
            [$second, $secondType] = $lines[1];
            if ($isHeading($second, $secondType) && preg_match('/^' . self::HEADING_WORDS . '\b/iu', $second) !== 1) $name .= ': ' . self::tidy($second);
        }
        return mb_substr($name, 0, 200);
    }

    /** ALL-CAPS headings become Title Case; anything else is left as written. */
    private static function tidy(string $text): string
    {
        $text = trim($text, " \t\n\r\0\x0B.:-");
        if ($text !== '' && $text === mb_strtoupper($text) && preg_match('/\p{L}/u', $text) === 1) $text = mb_convert_case(mb_strtolower($text), MB_CASE_TITLE, 'UTF-8');
        return $text;
    }

    /** @param string[] $texts @return string[] */
    private static function withoutWatermark(array $texts): array
    {
        return array_values(array_filter($texts, static fn (string $t): bool => trim($t) !== '' && preg_match(self::WATERMARK, $t) !== 1));
    }

    /** @param string[] $texts */
    private static function words(array $texts): int
    {
        $n = 0;
        foreach ($texts as $t) $n += (int) preg_match_all('/[\p{L}\p{N}]+/u', $t);
        return $n;
    }
}
