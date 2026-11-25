<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Finds how a word is pronounced, online: Wiktionary, then Wikipedia, then a Wikipedia search with the book's title as
 * context. Wikimedia asks for gentle use, so requests are spaced out, a "too many requests" answer is waited out, and
 * every answer (found or not) is cached so a word is only looked up once.
 */
final class PronLookup
{
    public const USER_AGENT = 'AudiobookStudio/0.1 (pronunciation lookup; self-hosted)';
    private const CACHE_DAYS = 30;

    /** @var callable(string):array{status:int,body:string} */
    private $fetch;
    private float $lastRequest = 0.0;

    /**
     * @param null|callable(string):array{status:int,body:string} $fetch tests pass a fake
     * @param float $spacing seconds between requests
     */
    public function __construct(?callable $fetch = null, private float $spacing = 1.1, private int $retries = 3)
    {
        $this->fetch = $fetch ?? [self::class, 'httpGet'];
    }

    /**
     * @return array{status:string,ipa:string,lang:string,confidence:string,source_title:string,source_url:string,note:string}
     */
    public function find(string $word, string $context = ''): array
    {
        $word = trim($word);
        $key = 'v2|' . mb_strtolower($word) . '|' . mb_strtolower(trim($context));
        $cached = $this->cached($key);
        if ($cached !== null) return $cached;

        $result = $this->wiktionary($word) ?? $this->wikipediaPage($word) ?? $this->wikipediaSearch($word, $context) ?? self::none();
        if ($result['status'] !== 'error') $this->store($key, $result);
        return $result;
    }

    // ----- sources -----

    /**
     * Wiktionary, read from the rendered page: its pronunciation templates (Spanish and French ones especially) only
     * produce IPA when rendered. Every language's pronunciations are kept, with their accent labels, so the choice of
     * language and accent can be made for the book (see pick()).
     * @return array<string,mixed>|null
     */
    private function wiktionary(string $word): ?array
    {
        foreach (array_unique([$word, mb_strtolower($word)]) as $i => $page) {
            $html = $this->renderedPage('https://en.wiktionary.org/w/api.php', $page);
            if ($html === null) continue;
            $options = self::ipaFromWiktionaryHtml($html);
            if ($options === []) continue;
            $result = self::found($options[0]['ipa'], $options[0]['lang'], 'medium', 'Wiktionary: ' . $page, 'https://en.wiktionary.org/wiki/' . rawurlencode(str_replace(' ', '_', $page)),
                $i > 0 ? 'Found as the ordinary word "' . $page . '".' : '');
            $result['options'] = $options;
            $result['ordinary'] = $i > 0 || $page === mb_strtolower($page);     // a dictionary word, not a name
            return self::pick($result, 'americas', []);
        }
        return null;
    }

    /**
     * Chooses among a Wiktionary answer's pronunciations: English first, then the language this book uses most for
     * its other words ($langRank: language => how many words), then the accent region ('americas' or 'europe').
     * @param array<string,mixed> $result
     * @param array<string,int> $langRank
     * @return array<string,mixed>
     */
    public static function pick(array $result, string $region, array $langRank): array
    {
        $options = $result['options'] ?? [];
        if (!is_array($options) || $options === []) return $result;
        $base = ['es' => 6, 'fr' => 5, 'it' => 4, 'pt' => 3, 'de' => 2, 'nl' => 1];
        $best = null;
        $bestScore = null;
        foreach ($options as $n => $o) {
            $score = [$o['lang'] === 'en' ? 1 : 0, $langRank[$o['lang']] ?? 0, $base[$o['lang']] ?? 0, self::regionScore((string) $o['qual'], $region), -$n];
            if ($bestScore === null || $score > $bestScore) { $best = $o; $bestScore = $score; }
        }
        $own = !str_starts_with((string) $result['note'], 'Found as the ordinary word');
        $result['ipa'] = $best['ipa'];
        $result['lang'] = $best['lang'];
        $result['confidence'] = $best['lang'] === 'en' && $own ? 'high' : 'medium';
        $result['accent'] = (string) $best['qual'];
        return $result;
    }

    /**
     * How well an accent label suits the region: 3 the region's general accent ("US", "Latin America", "UK", "Spain"),
     * 2 no label, 1 anything else (the other region, or a local accent such as "Buenos Aires").
     */
    public static function regionScore(string $qual, string $region): int
    {
        if (trim($qual) === '') return 2;
        $broad = $region === 'europe'
            ? '/\b(UK|RP|Received Pronunciation|British|Spain|Castilian|Peninsular|France|Parisian|Portugal|European)\b/i'
            : '/\b(US|General American|GenAm|American|Canada|Canadian|Quebec|Latin America|Americas|Brazil)\b/i';
        return preg_match($broad, $qual) === 1 ? 3 : 1;
    }

    /** @return array<string,string>|null */
    private function wikipediaPage(string $word): ?array
    {
        $page = $this->wikipediaIntro($word);
        if ($page === null) return null;
        [$title, $text] = $page;
        if (self::isDisambiguation($text)) return null;
        [$ipa, $lang] = self::ipaFromWikipedia($text);
        if ($ipa === '') return null;
        $same = self::same($title, $word);
        $variant = $same || self::spellingVariant($title, $word);
        return self::found($ipa, $lang, $lang === 'en' && $same ? 'high' : ($variant ? 'medium' : 'low'), 'Wikipedia: ' . $title, self::wikiUrl($title),
            $same ? '' : ($variant ? 'Wikipedia sends "' . $word . '" to "' . $title . '", another spelling; the sounds are for that spelling.'
                : 'Wikipedia sends "' . $word . '" to "' . $title . '", a different name; these are the sounds of "' . $title . '".'));
    }

    /** @return array<string,string>|null */
    private function wikipediaSearch(string $word, string $context): ?array
    {
        if (trim($context) === '') return null;
        $json = $this->get('https://en.wikipedia.org/w/api.php?' . http_build_query(['action' => 'query', 'list' => 'search', 'srsearch' => $word . ' ' . $context, 'srlimit' => 3, 'format' => 'json']));
        $hits = $json !== null ? (json_decode($json, true)['query']['search'] ?? []) : [];
        foreach ($hits as $hit) {
            $title = (string) ($hit['title'] ?? '');
            if (mb_stripos($title, $word) === false) continue;            // only a page about this word
            $page = $this->wikipediaIntro($title);
            if ($page === null || self::isDisambiguation($page[1])) continue;
            [$ipa, $lang] = self::ipaFromWikipedia($page[1]);
            if ($ipa === '') continue;
            $exact = self::same(preg_replace('/\s*\(.*\)$/', '', $page[0]) ?? $page[0], $word);
            return self::found($ipa, $lang, $exact ? 'medium' : 'low', 'Wikipedia: ' . $page[0], self::wikiUrl($page[0]),
                $exact ? 'Found by searching with the book\'s title.' : 'From the page "' . $page[0] . '", which only contains the word; the ending may differ.');
        }
        return null;
    }

    // ----- parsing (public for tests) -----

    /** Language names Wiktionary heads its sections with, and the codes kept for them. */
    public const LANGUAGES = ['English' => 'en', 'Spanish' => 'es', 'French' => 'fr', 'Italian' => 'it', 'Portuguese' => 'pt', 'German' => 'de', 'Dutch' => 'nl',
        'Latin' => 'la', 'Catalan' => 'ca', 'Galician' => 'gl', 'Indonesian' => 'id', 'Malay' => 'ms', 'Tagalog' => 'tl', 'Polish' => 'pl', 'Czech' => 'cs',
        'Swedish' => 'sv', 'Danish' => 'da', 'Norwegian' => 'no', 'Irish' => 'ga', 'Welsh' => 'cy', 'Scottish Gaelic' => 'gd', 'Japanese' => 'ja', 'Chinese' => 'zh',
        'Russian' => 'ru', 'Greek' => 'el', 'Turkish' => 'tr', 'Arabic' => 'ar', 'Hawaiian' => 'haw', 'Maori' => 'mi'];

    /** A language code as a name for the page ("es" => "Spanish"). */
    public static function languageName(string $lang): string
    {
        $name = array_search($lang, self::LANGUAGES, true);
        return is_string($name) ? $name : ucfirst($lang);
    }

    /**
     * The pronunciations on a rendered Wiktionary page, one per line, with the language and the line's accent labels,
     * in page order.
     * @return list<array{ipa:string,lang:string,qual:string}>
     */
    public static function ipaFromWiktionaryHtml(string $html): array
    {
        $out = [];
        $parts = preg_split('/<h2\b[^>]*\bid="([^"]+)"/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $name = str_replace('_', ' ', html_entity_decode($parts[$i], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $lang = self::LANGUAGES[$name] ?? mb_strtolower($name);
            if (!preg_match_all('#<li\b[^>]*>(.*?)</li>#su', $parts[$i + 1], $items)) continue;
            foreach ($items[1] as $item) {
                if (!preg_match('#<span class="IPA[^"]*"[^>]*>([^<]+)</span>#u', $item, $m)) continue;
                $ipa = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if (!str_contains($plain, 'IPA') || !preg_match('#^[/\[].*[/\]]$#u', $ipa) || str_starts_with(trim($ipa, '/['), '-')) continue;
                // accent labels: whatever else the line says, without the IPA itself
                preg_match_all('#<span class="IPA[^"]*"[^>]*>([^<]+)</span>#u', $item, $spans);
                $rest = str_replace(array_map(static fn (string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $spans[1]), '', $plain);
                $rest = preg_replace('/IPA\s*\(key\)\s*:?/u', '', $rest) ?? $rest;
                $qual = trim((string) preg_replace('/[()]+|\s+/u', ' ', $rest), " ,;:\u{00a0}");
                $qual = (string) preg_replace('/\s{2,}/u', ' ', $qual);
                $out[] = ['ipa' => $ipa, 'lang' => $lang, 'qual' => $qual];
            }
        }
        return $out;
    }

    /** The subject's pronunciation from the start of a Wikipedia article. @return array{0:string,1:string} */
    public static function ipaFromWikipedia(string $text): array
    {
        if (preg_match('/\{\{IPAc-en\|([^{}]*)\}\}/u', $text, $m)) {
            $ipa = IpaConverter::fromIpacEn(explode('|', $m[1]));
            if ($ipa !== '') return [$ipa, 'en'];
        }
        if (preg_match('/\{\{IPA\|([a-z]{2,3})(?:-[A-Za-z0-9]+)?\|([^|{}]+)/u', $text, $m)) return [trim($m[2]), $m[1]];
        if (preg_match('/\{\{IPA-([a-z]{2,3})\|([^|{}]+)/u', $text, $m)) return [trim($m[2]), $m[1]];
        return ['', ''];
    }

    public static function isDisambiguation(string $text): bool
    {
        return preg_match('/\{\{(disambiguation|disambig|dab|hndis|geodis|surname|given name)[|}]/i', $text) === 1 || stripos($text, 'may refer to:') !== false;
    }

    // ----- plumbing -----

    /** @return array{0:string,1:string}|null the page title (after redirects) and the wikitext of its first section */
    private function wikipediaIntro(string $title): ?array
    {
        $json = $this->get('https://en.wikipedia.org/w/api.php?' . http_build_query(['action' => 'query', 'prop' => 'revisions', 'rvprop' => 'content', 'rvslots' => 'main', 'rvsection' => 0, 'titles' => $title, 'redirects' => 1, 'format' => 'json']));
        if ($json === null) return null;
        $pages = json_decode($json, true)['query']['pages'] ?? [];
        $page = is_array($pages) ? reset($pages) : null;
        if (!is_array($page) || isset($page['missing'])) return null;
        $text = (string) ($page['revisions'][0]['slots']['main']['*'] ?? '');
        return $text === '' ? null : [(string) $page['title'], $text];
    }

    private function renderedPage(string $api, string $page): ?string
    {
        $json = $this->get($api . '?' . http_build_query(['action' => 'parse', 'prop' => 'text', 'page' => $page, 'redirects' => 1, 'disableeditsection' => 1, 'disablelimitreport' => 1, 'format' => 'json', 'formatversion' => 2]));
        if ($json === null) return null;
        $text = json_decode($json, true)['parse']['text'] ?? null;
        return is_string($text) ? $text : null;
    }

    /** A GET with spacing between requests; waits out "too many requests". Null on failure. */
    private function get(string $url): ?string
    {
        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $wait = $this->lastRequest + $this->spacing - microtime(true);
            if ($wait > 0) usleep((int) ($wait * 1_000_000));
            $this->lastRequest = microtime(true);
            $r = ($this->fetch)($url);
            if ($r['status'] === 200) return $r['body'];
            if ($r['status'] !== 429 && $r['status'] < 500) return null;
            if ($this->spacing > 0) sleep(min(60, 10 * ($attempt + 1)));    // back off, then try again
        }
        throw new \RuntimeException('Wikimedia is not answering right now (too many requests). Try again later.');
    }

    /** @return array{status:int,body:string} */
    public static function httpGet(string $url): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n", 'timeout' => 15, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
        return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? '' : $body];
    }

    /** @return array<string,string>|null */
    private function cached(string $key): ?array
    {
        $s = Database::connection()->prepare("SELECT result_json FROM pron_lookup_cache WHERE cache_key=:k AND fetched_at > datetime('now', :age)");
        $s->execute(['k' => $key, 'age' => '-' . self::CACHE_DAYS . ' days']);
        $json = $s->fetchColumn();
        return is_string($json) ? json_decode($json, true) : null;
    }

    /** @param array<string,string> $result */
    private function store(string $key, array $result): void
    {
        Database::connection()->prepare('INSERT INTO pron_lookup_cache(cache_key,result_json) VALUES(:k,:j) ON CONFLICT(cache_key) DO UPDATE SET result_json=excluded.result_json, fetched_at=CURRENT_TIMESTAMP')
            ->execute(['k' => $key, 'j' => json_encode($result, JSON_UNESCAPED_UNICODE)]);
    }

    private static function same(string $a, string $b): bool
    {
        // compare without capitals or accents (Malecón = Malecon); intl may not be installed, so accents are folded by hand too
        $fold = static function (string $s): string {
            if (class_exists(\Normalizer::class)) $s = (string) preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s);
            return mb_strtolower(strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ç' => 'c', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']));
        };
        return $fold($a) === $fold($b);
    }

    /** Close enough to be the same name spelled another way: at most a third of the letters differ. */
    public static function spellingVariant(string $a, string $b): bool
    {
        $fold = static function (string $s): string {
            $s = strtr(mb_strtolower($s), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
            return (string) preg_replace('/[^a-z]/', '', $s);
        };
        $x = $fold($a);
        $y = $fold($b);
        if ($x === '' || $y === '') return false;
        return levenshtein($x, $y) * 3 <= max(strlen($x), strlen($y));
    }

    private static function wikiUrl(string $title): string
    {
        return 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title));
    }

    /** @return array<string,string> */
    private static function found(string $ipa, string $lang, string $confidence, string $title, string $url, string $note = ''): array
    {
        return ['status' => 'found', 'ipa' => $ipa, 'lang' => $lang, 'confidence' => $confidence, 'source_title' => $title, 'source_url' => $url, 'note' => $note];
    }

    /** @return array<string,string> */
    private static function none(): array
    {
        return ['status' => 'none', 'ipa' => '', 'lang' => '', 'confidence' => '', 'source_title' => '', 'source_url' => '', 'note' => ''];
    }
}
