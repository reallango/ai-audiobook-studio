<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Hand edits to a paragraph, kept as changes to words of the source text. build() applies them (with the
 * pronunciation rules around them); diff() turns what the user typed into the new list of edits.
 */
final class ParagraphEdits
{
    /**
     * Narration of one paragraph: the stored edits first, the pronunciation rules on everything between them.
     * @param array<string,array<string,mixed>> $effective
     * @param array<int,array<string,mixed>> $edits rows with src_start, src, dst
     * @return array{text:string,hits:array<string,int>,pieces:array<int,array<string,mixed>>,edits:array<int,array{start:int,len:int,src:string,dst:string}>,stale:int}
     */
    public static function build(PronunciationService $pron, array $effective, string $cleaned, bool $caps, array $edits): array
    {
        [$valid, $stale] = self::validate($cleaned, $edits);
        $pieces = [];
        $hits = [];
        $cursor = 0;
        $gap = static function (int $from, int $to) use ($pron, $effective, $cleaned, $caps, &$pieces, &$hits): void {
            if ($to <= $from) return;
            $r = $pron->apply(substr($cleaned, $from, $to - $from), $effective, $caps);
            foreach ($r['pieces'] as $p) { $p['src_start'] += $from; $pieces[] = $p; }
            foreach ($r['hits'] as $k => $n) $hits[$k] = ($hits[$k] ?? 0) + $n;
        };
        foreach ($valid as $e) {
            $gap($cursor, $e['start']);
            $pieces[] = ['src' => $e['src'], 'src_start' => $e['start'], 'out' => $e['dst'], 'kind' => 'edit', 'rule' => '', 'scope' => '', 'scope_ref' => '', 'label' => 'Your edit'];
            $cursor = $e['start'] + $e['len'];
        }
        $gap($cursor, strlen($cleaned));
        return ['text' => implode('', array_column($pieces, 'out')), 'hits' => $hits, 'pieces' => $pieces, 'edits' => $valid, 'stale' => $stale];
    }

    /**
     * The edits that still fit the source text, sorted and without overlaps, and how many do not.
     * @param array<int,array<string,mixed>> $edits
     * @return array{0:array<int,array{start:int,len:int,src:string,dst:string}>,1:int}
     */
    public static function validate(string $cleaned, array $edits): array
    {
        $valid = [];
        $stale = 0;
        foreach ($edits as $e) {
            $src = (string) $e['src'];
            $start = (int) $e['src_start'];
            $len = strlen($src);
            if ($len === 0) {
                if ($start < 0 || $start > strlen($cleaned)) { $stale++; continue; }
            } elseif (substr($cleaned, $start, $len) !== $src) {
                $found = strpos($cleaned, $src);
                if ($found === false) { $stale++; continue; }
                $start = $found;
            }
            $valid[] = ['start' => $start, 'len' => $len, 'src' => $src, 'dst' => (string) $e['dst']];
        }
        usort($valid, static fn (array $a, array $b): int => [$a['start'], $a['len']] <=> [$b['start'], $b['len']]);
        $kept = [];
        $end = 0;
        foreach ($valid as $e) {
            if ($e['start'] < $end) { $stale++; continue; }
            $kept[] = $e;
            $end = $e['start'] + $e['len'];
        }
        return [$kept, $stale];
    }

    /**
     * What the user typed, compared word by word with the narration shown, becomes edits to the source words behind
     * the changed words. Existing edits that the new ones replace are dropped; the others are kept.
     *
     * @param array<int,array<string,mixed>> $pieces pieces of the narration shown (from build())
     * @param array<int,array{start:int,len:int,src:string,dst:string}> $existing valid edits behind it
     * @return array<int,array{start:int,len:int,src:string,dst:string}>
     */
    public static function diff(string $cleaned, array $pieces, string $shown, string $typed, array $existing): array
    {
        $shownTokens = self::tokens($shown);
        $typedTokens = self::tokens($typed);

        // where each piece sits in the shown text
        $at = 0;
        $ranges = [];
        foreach ($pieces as $p) {
            $ranges[] = [$at, $at + strlen((string) $p['out']), $p];
            $at += strlen((string) $p['out']);
        }
        $span = static function (int $cs, int $ce) use ($ranges): array {
            $lo = null;
            $hi = null;
            foreach ($ranges as [$os, $oe, $p]) {
                if ($oe <= $cs || $os >= $ce) continue;
                if ($p['kind'] === 'plain') {
                    $s = $p['src_start'] + (max($cs, $os) - $os);
                    $e = $p['src_start'] + (min($ce, $oe) - $os);
                } else {
                    $s = $p['src_start'];
                    $e = $p['src_start'] + strlen((string) $p['src']);
                }
                $lo = $lo === null ? $s : min($lo, $s);
                $hi = $hi === null ? $e : max($hi, $e);
            }
            return [$lo ?? 0, $hi ?? 0];
        };

        // longest common subsequence of the words
        $n = count($shownTokens);
        $m = count($typedTokens);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $shownTokens[$i]['t'] === $typedTokens[$j]['t'] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $hunks = [];
        $current = null;
        $i = 0;
        $j = 0;
        $lastMatchedShown = null;
        $close = static function () use (&$hunks, &$current): void { if ($current !== null) { $hunks[] = $current; $current = null; } };
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $shownTokens[$i]['t'] === $typedTokens[$j]['t']) {
                $close();
                $lastMatchedShown = $i;
                $i++;
                $j++;
                continue;
            }
            $current ??= ['a' => [], 'b' => [], 'after' => $lastMatchedShown];
            if ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) { $current['b'][] = $j++; } else { $current['a'][] = $i++; }
        }
        $close();

        $new = [];
        foreach ($hunks as $h) {
            if ($h['a'] !== []) {
                $lo = null;
                $hi = null;
                foreach ($h['a'] as $idx) {
                    [$s, $e] = $span($shownTokens[$idx]['s'], $shownTokens[$idx]['e']);
                    $lo = $lo === null ? $s : min($lo, $s);
                    $hi = $hi === null ? $e : max($hi, $e);
                }
                $dst = $h['b'] === [] ? '' : substr($typed, $typedTokens[$h['b'][0]]['s'], $typedTokens[end($h['b'])]['e'] - $typedTokens[$h['b'][0]]['s']);
                if ($dst === '') {                    // a deletion also takes one neighbouring space
                    if ($hi < strlen($cleaned) && $cleaned[$hi] === ' ') $hi++; elseif ($lo > 0 && $cleaned[$lo - 1] === ' ') $lo--;
                }
                $new[] = ['start' => $lo, 'len' => $hi - $lo, 'src' => substr($cleaned, $lo, $hi - $lo), 'dst' => $dst];
                continue;
            }
            $words = substr($typed, $typedTokens[$h['b'][0]]['s'], $typedTokens[end($h['b'])]['e'] - $typedTokens[$h['b'][0]]['s']);
            if ($h['after'] === null) {
                $new[] = ['start' => 0, 'len' => 0, 'src' => '', 'dst' => $words . ' '];
            } else {
                [, $anchor] = $span($shownTokens[$h['after']]['s'], $shownTokens[$h['after']]['e']);
                $new[] = ['start' => $anchor, 'len' => 0, 'src' => '', 'dst' => ' ' . $words];
            }
        }

        $keep = array_values(array_filter($existing, static function (array $old) use ($new): bool {
            foreach ($new as $nw) if (self::overlaps($old, $nw)) return false;
            return true;
        }));
        $all = array_merge($keep, $new);
        usort($all, static fn (array $a, array $b): int => [$a['start'], $a['len']] <=> [$b['start'], $b['len']]);
        return $all;
    }

    /** @param array{start:int,len:int} $a @param array{start:int,len:int} $b */
    private static function overlaps(array $a, array $b): bool
    {
        if ($a['len'] === 0 && $b['len'] === 0) return $a['start'] === $b['start'];
        if ($a['len'] === 0) return $a['start'] > $b['start'] && $a['start'] < $b['start'] + $b['len'];
        if ($b['len'] === 0) return $b['start'] > $a['start'] && $b['start'] < $a['start'] + $a['len'];
        return $a['start'] < $b['start'] + $b['len'] && $b['start'] < $a['start'] + $a['len'];
    }

    /** @return array<int,array{t:string,s:int,e:int}> */
    private static function tokens(string $text): array
    {
        preg_match_all('/\S+/u', $text, $m, PREG_OFFSET_CAPTURE);
        return array_map(static fn (array $x): array => ['t' => $x[0], 's' => $x[1], 'e' => $x[1] + strlen($x[0])], $m[0]);
    }
}
