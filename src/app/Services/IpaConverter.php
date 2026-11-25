<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Pronunciations written in IPA (as Wiktionary and Wikipedia give them) turned into what Kokoro reads, and into a
 * respelling people can read ("Ha-BAH-nah").
 *
 * Kokoro's English voices read sounds from a fixed set (misaki's): mostly IPA, with single letters for the
 * diphthongs (A = eɪ, I = aɪ, W = aʊ, Y = ɔɪ, O = oʊ). Sounds English does not have become the nearest English one.
 */
final class IpaConverter
{
    private const VOWELS = 'aeiouæɑɐɒɔəɛɜɚɝɪʊʌɤɯøœyAIOWYᵊ';

    /** Wikipedia's {{IPAc-en}} writes some sounds in ASCII; these are its codes. */
    private const IPAC_EN = [
        "'" => 'ˈ', ',' => 'ˌ', '@' => 'ə', '@r' => 'ər', 'ae' => 'æ', 'aI' => 'aɪ', 'aU' => 'aʊ', 'eI' => 'eɪ', 'oU' => 'oʊ', 'OI' => 'ɔɪ',
        'dZ' => 'dʒ', 'tS' => 'tʃ', 'S' => 'ʃ', 'Z' => 'ʒ', 'T' => 'θ', 'D' => 'ð', 'N' => 'ŋ', 'j' => 'j', 'A:' => 'ɑː', 'O:' => 'ɔː',
        'I' => 'ɪ', 'U' => 'ʊ', 'V' => 'ʌ', 'E' => 'ɛ', '3:' => 'ɜː', 'i:' => 'iː', 'u:' => 'uː', 'Q' => 'ɒ', 'r' => 'r', 'x' => 'x',
    ];

    /** IPA (or IPA-like) to Kokoro's sound set, longest first. */
    private const TO_KOKORO = [
        // diphthongs first
        'eɪ' => 'A', 'ei' => 'A', 'aɪ' => 'I', 'ai' => 'I', 'aʊ' => 'W', 'au' => 'W', 'ɔɪ' => 'Y', 'oi' => 'Y', 'oʊ' => 'O', 'əʊ' => 'O', 'ou' => 'O',
        'ɪə' => 'ɪɹ', 'ɛə' => 'ɛɹ', 'ʊə' => 'ʊɹ',
        'dʒ' => 'ʤ', 'd͡ʒ' => 'ʤ', 'tʃ' => 'ʧ', 't͡ʃ' => 'ʧ', 'ts' => 'ts',
        // r sounds
        'ɝ' => 'ɜɹ', 'ɚ' => 'əɹ', 'r' => 'ɹ', 'ɾ' => 'ɹ', 'ʁ' => 'ɹ', 'ʀ' => 'ɹ', 'ɻ' => 'ɹ',
        // vowels English lacks, or writes differently
        'ɐ' => 'ə', 'ɒ' => 'ɑ', 'a' => 'ɑ', 'e' => 'A', 'o' => 'O', 'ø' => 'ə', 'œ' => 'ə', 'y' => 'u', 'ɤ' => 'ʌ', 'ɯ' => 'u', 'ɨ' => 'ɪ',
        // consonants English lacks
        'ɣ' => 'ɡ', 'β' => 'b', 'ɱ' => 'm', 'x' => 'h', 'χ' => 'h', 'ç' => 'h', 'ħ' => 'h', 'ʎ' => 'j', 'ʝ' => 'j', 'ɲ' => 'nj', 'ʃʲ' => 'ʃ',
        'ʔ' => '', 'ɫ' => 'l', 'ʋ' => 'v', 'ɸ' => 'f', 'c' => 'k', 'ɟ' => 'ɡ', 'g' => 'ɡ', 'q' => 'k', 'ɦ' => 'h', 'ɕ' => 'ʃ', 'ʑ' => 'ʒ',
    ];

    /** What Kokoro's English reader accepts; anything else is dropped. */
    private const KOKORO_CHARS = 'AIOWYbdfhjklmnprstvwzæðŋɑɔəɛɜɡɪɹʃʊʌʒʤʧθiuˈˌᵊ';

    /** The sounds of {{IPAc-en|h|ə|ˈ|v|æ|n|ə}} (its parameters, minus labels such as "US" or "lang"). @param string[] $parts */
    public static function fromIpacEn(array $parts): string
    {
        $out = '';
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || str_contains($p, '=') || preg_match('/^(US|UK|CA|AU|NZ|lang|local|also|USalso|UKalso|pron|audio|.*\.(ogg|wav|mp3))$/i', $p)) continue;
            $out .= self::IPAC_EN[$p] ?? $p;
        }
        return $out;
    }

    /** Plain IPA cleaned of the marks around it: slashes, brackets, syllable dots, length, ties and accents. */
    public static function clean(string $ipa): string
    {
        $ipa = trim($ipa);
        $ipa = (string) preg_replace('#^[/\[]+|[/\]]+$#u', '', $ipa);
        $ipa = str_replace(['.', 'ː', 'ˑ', '‿', '(', ')', ' ', '-', '͡', '‍'], '', $ipa);
        $ipa = str_replace(["'"], ['ˈ'], $ipa);
        // a vowel marked non-syllabic is a glide: fwe̯ / fu̯e -> fwe, i̯ -> j
        $ipa = str_replace(["u\u{032F}", "ʊ\u{032F}", "i\u{032F}", "ɪ\u{032F}"], ['w', 'w', 'j', 'j'], $ipa);
        // combining marks (nasal ~, syllabic, dental...) carry no sound of their own here
        return (string) preg_replace('/\p{Mn}/u', '', $ipa);
    }

    /** IPA to the sounds Kokoro reads. Empty when nothing usable is left. */
    public static function toKokoro(string $ipa): string
    {
        $ipa = self::clean($ipa);
        $keys = array_keys(self::TO_KOKORO);
        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $out = '';
        $chars = preg_split('//u', $ipa, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        for ($i = 0; $i < count($chars);) {
            foreach ($keys as $k) {
                $len = mb_strlen($k);
                if (implode('', array_slice($chars, $i, $len)) === $k) { $out .= self::TO_KOKORO[$k]; $i += $len; continue 2; }
            }
            $out .= $chars[$i];
            $i++;
        }
        // keep only what the reader knows; a stress mark must come before a sound, not at the end
        $kept = implode('', array_filter(preg_split('//u', $out, -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn (string $c): bool => mb_strpos(self::KOKORO_CHARS, $c) !== false));
        return (string) preg_replace('/[ˈˌ]+$/u', '', $kept);
    }

    /** The spoken form of a rule: the word with its exact sounds, which Kokoro reads as those sounds. */
    public static function markup(string $word, string $kokoro): string
    {
        return '[' . str_replace([']', '['], '', $word) . '](/' . $kokoro . '/)';
    }

    /** A readable respelling, syllables split, the stressed one in capitals: həbˈɑnə -> "huh-BAH-nuh". */
    public static function respell(string $kokoro): string
    {
        $map = [
            'A' => 'ay', 'I' => 'eye', 'O' => 'oh', 'W' => 'ow', 'Y' => 'oy', 'æ' => 'a', 'ɑ' => 'ah', 'ɔ' => 'aw', 'ə' => 'uh', 'ᵊ' => 'uh', 'ɛ' => 'eh',
            'ɜ' => 'ur', 'ɪ' => 'ih', 'i' => 'ee', 'ʊ' => 'uu', 'u' => 'oo', 'ʌ' => 'uh', 'ð' => 'th', 'θ' => 'th', 'ŋ' => 'ng', 'ɡ' => 'g', 'ɹ' => 'r',
            'ʃ' => 'sh', 'ʒ' => 'zh', 'ʤ' => 'j', 'ʧ' => 'ch', 'j' => 'y',
        ];
        $chars = preg_split('//u', $kokoro, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isVowel = static fn (string $c): bool => mb_strpos(self::VOWELS, $c) !== false;
        // syllables: a new one starts at a stress mark, or at a consonant that comes before a vowel and after a vowel
        $syllables = [];
        $current = '';
        $stressed = [];
        $stressNext = false;
        $seenVowel = false;
        foreach ($chars as $i => $c) {
            if ($c === 'ˈ' || $c === 'ˌ') {
                $carry = '';
                // written after the consonant that starts the stressed syllable (həbˈɑnə): that consonant moves over
                $cur = preg_split('//u', $current, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($cur) > 1 && !$isVowel(end($cur)) && $isVowel($chars[$i + 1] ?? '') && $seenVowel) { $carry = array_pop($cur); $current = implode('', $cur); }
                if ($current !== '') { $syllables[] = $current; $current = ''; $seenVowel = false; }
                $current = $carry;
                $stressNext = $c === 'ˈ';
                continue;
            }
            $next = $chars[$i + 1] ?? '';
            $onset = $next !== '' && ($isVowel($next) || (($next === 'w' || $next === 'j') && $isVowel($chars[$i + 2] ?? '')));   // pur-kwa, not purk-wa
            if (!$isVowel($c) && $seenVowel && $onset && $current !== '') { $syllables[] = $current; $current = ''; $seenVowel = false; }
            // two vowels side by side are two syllables (the voice's diphthongs are single letters): si-uhs
            elseif ($isVowel($c) && $seenVowel && $current !== '') { $syllables[] = $current; $current = ''; $seenVowel = false; }
            if ($stressNext) { $stressed[count($syllables)] = true; $stressNext = false; }
            $current .= $c;
            if ($isVowel($c)) $seenVowel = true;
        }
        if ($current !== '') $syllables[] = $current;
        $words = [];
        foreach ($syllables as $n => $s) {
            $text = '';
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) $text .= $map[$c] ?? $c;
            $words[] = isset($stressed[$n]) ? mb_strtoupper($text) : $text;
        }
        return implode('-', $words);
    }
}
