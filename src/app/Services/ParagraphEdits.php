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
        $n = count($shownTokens);
        $m = count($typedTokens);

        // Where each piece sits in the shown text, and which source text is behind each shown word. Words that come
        // from a rule or an earlier edit belong to that piece as a whole: they are changed together or not at all.
        $at = 0;
        $ranges = [];
        foreach ($pieces as $k => $p) {
            $ranges[] = [$at, $at + strlen((string) $p['out']), $p, $k];
            $at += strlen((string) $p['out']);
        }
        $group = range(0, max(0, $n - 1));           // token -> first token of its unit
        $tokenPieces = array_fill(0, $n, []);
        $byPiece = [];
        foreach ($shownTokens as $i => $tok) {
            foreach ($ranges as [$os, $oe, $p, $k]) {
                if ($oe <= $tok['s'] || $os >= $tok['e']) continue;
                $tokenPieces[$i][] = $k;
                if ($p['kind'] !== 'plain') $byPiece[$k][] = $i;
            }
        }
        foreach ($byPiece as $members) {
            $root = min(array_map(static fn (int $i): int => $group[$i], $members));
            foreach ($members as $i) $group[$i] = $root;
        }
        for ($i = 0; $i < $n; $i++) $group[$i] = $group[$group[$i]];
        $unitOf = static function (int $i) use ($group, $n): array {      // first and last token of the unit holding token i
            $lo = $i;
            $hi = $i;
            while ($lo > 0 && $group[$lo - 1] === $group[$i]) $lo--;
            while ($hi < $n - 1 && $group[$hi + 1] === $group[$i]) $hi++;
            return [$lo, $hi];
        };
        $isInsertEdit = static function (int $i) use ($tokenPieces, $pieces): bool {
            foreach ($tokenPieces[$i] as $k) if ($pieces[$k]['kind'] === 'edit' && (string) $pieces[$k]['src'] === '') return true;
            return false;
        };
        $sourceOf = static function (int $i) use ($shownTokens, $ranges): array {     // source range behind token i
            $lo = null;
            $hi = null;
            foreach ($ranges as [$os, $oe, $p]) {
                if ($oe <= $shownTokens[$i]['s'] || $os >= $shownTokens[$i]['e']) continue;
                if ($p['kind'] === 'plain') {
                    $s = $p['src_start'] + (max($shownTokens[$i]['s'], $os) - $os);
                    $e = $p['src_start'] + (min($shownTokens[$i]['e'], $oe) - $os);
                } else {
                    $s = $p['src_start'];
                    $e = $p['src_start'] + strlen((string) $p['src']);
                }
                $lo = $lo === null ? $s : min($lo, $s);
                $hi = $hi === null ? $e : max($hi, $e);
            }
            return [$lo ?? 0, $hi ?? 0];
        };

        // Longest common subsequence of the words: which shown words were kept, and as which typed word.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $shownTokens[$i]['t'] === $typedTokens[$j]['t'] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $match = array_fill(0, $n, null);
        $insertAfter = [];                            // shown index (-1 = before the first word) => typed words added there
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $shownTokens[$i]['t'] === $typedTokens[$j]['t']) { $match[$i++] = $j++; continue; }
            if ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) { $insertAfter[$i - 1] = true; $j++; } else { $i++; }
        }

        // Regions of shown words to replace: every changed unit, whole; an addition next to an earlier added word
        // joins that word, so "slowly" then "slowly home" is one edit, not two at the same spot.
        $regions = [];
        for ($i = 0; $i < $n; $i++) {
            if ($match[$i] !== null) continue;
            $regions[] = $unitOf($i);
        }
        foreach (array_keys($insertAfter) as $k) {
            if ($k >= 0 && $isInsertEdit($k)) { $regions[] = $unitOf($k); continue; }
            if ($k + 1 < $n && $isInsertEdit($k + 1)) { $regions[] = $unitOf($k + 1); continue; }
            $regions[] = [$k + 1, $k];                // an empty region: a new word between k and k+1
        }
        // a changed word inside a unit takes the whole unit (a rule's "Bay jor", an earlier edit)
        $regions = array_map(static function (array $r) use ($unitOf, $n): array {
            if ($r[0] > $r[1]) return $r;
            return [$unitOf($r[0])[0], $unitOf($r[1])[1]];
        }, $regions);
        usort($regions, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $merged = [];
        foreach ($regions as $r) {
            $last = count($merged) - 1;
            if ($last >= 0 && $r[0] <= $merged[$last][1] + 1) { $merged[$last][1] = max($merged[$last][1], $r[1]); continue; }
            $merged[] = $r;
        }

        $new = [];
        $replacedEdits = [];
        foreach ($merged as [$a, $b]) {
            // the typed text between the kept words on either side of the region
            $from = $a > 0 ? $typedTokens[$match[$a - 1]]['e'] : 0;
            $to = $b + 1 < $n ? $typedTokens[$match[$b + 1]]['s'] : strlen($typed);
            $dst = trim(substr($typed, $from, max(0, $to - $from)));
            if ($a <= $b) {
                $lo = null;
                $hi = null;
                for ($t = $a; $t <= $b; $t++) {
                    [$s, $e] = $sourceOf($t);
                    $lo = $lo === null ? $s : min($lo, $s);
                    $hi = $hi === null ? $e : max($hi, $e);
                    foreach ($tokenPieces[$t] as $k) if ($pieces[$k]['kind'] === 'edit') $replacedEdits[$pieces[$k]['src_start'] . '|' . $pieces[$k]['src']] = true;
                }
            } else {
                $lo = $hi = $a > 0 ? $sourceOf($a - 1)[1] : ($n > 0 ? $sourceOf(0)[0] : 0);
            }
            if ($lo === $hi) {
                if ($dst === '') continue;              // an added word removed again: the old edit simply goes
                // a word added after another word gets a space before it; at the start of the text, a space after it
                if ($lo > 0 && $cleaned[$lo - 1] !== ' ') $dst = ' ' . $dst;
                elseif ($lo < strlen($cleaned) && $cleaned[$lo] !== ' ') $dst .= ' ';
            } elseif ($dst === '') {                    // a deletion also takes one neighbouring space
                if ($hi < strlen($cleaned) && $cleaned[$hi] === ' ') $hi++; elseif ($lo > 0 && $cleaned[$lo - 1] === ' ') $lo--;
            }
            $new[] = ['start' => $lo, 'len' => $hi - $lo, 'src' => substr($cleaned, $lo, $hi - $lo), 'dst' => $dst];
        }

        $keep = array_values(array_filter($existing, static function (array $old) use ($new, $replacedEdits): bool {
            if (isset($replacedEdits[$old['start'] . '|' . $old['src']])) return false;
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
