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
        $key = 'v1|' . mb_strtolower($word) . '|' . mb_strtolower(trim($context));
        $cached = $this->cached($key);
        if ($cached !== null) return $cached;

        $result = $this->wiktionary($word) ?? $this->wikipediaPage($word) ?? $this->wikipediaSearch($word, $context) ?? self::none();
        if ($result['status'] !== 'error') $this->store($key, $result);
        return $result;
    }

    // ----- sources -----

    /** @return array<string,string>|null */
    private function wiktionary(string $word): ?array
    {
        foreach (array_unique([$word, mb_strtolower($word)]) as $i => $page) {
            $text = $this->wikitext('https://en.wiktionary.org/w/api.php', $page);
            if ($text === null) continue;
            [$ipa, $lang] = self::ipaFromWiktionary($text);
            if ($ipa === '') continue;
            return self::found($ipa, $lang, $lang === 'en' && $i === 0 ? 'high' : 'medium', 'Wiktionary: ' . $page, 'https://en.wiktionary.org/wiki/' . rawurlencode(str_replace(' ', '_', $page)),
                $i > 0 ? 'Found as the ordinary word "' . $page . '".' : '');
        }
        return null;
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
        return self::found($ipa, $lang, $lang === 'en' && $same ? 'high' : 'medium', 'Wikipedia: ' . $title, self::wikiUrl($title),
            $same ? '' : 'Wikipedia sends "' . $word . '" to "' . $title . '"; check it is the same thing.');
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

    /** The first English pronunciation on a Wiktionary page, else the first in any language. @return array{0:string,1:string} */
    public static function ipaFromWiktionary(string $text): array
    {
        $first = ['', ''];
        if (preg_match_all('/\{\{IPA\|([^{}]*)\}\}/u', $text, $all)) {
            foreach ($all[1] as $inner) {
                $params = explode('|', $inner);
                $lang = '';
                $ipa = '';
                foreach ($params as $p) {
                    if (preg_match('/^lang=(.+)$/', $p, $m)) $lang = $m[1];
                    elseif ($lang === '' && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/', $p)) $lang = $p;
                    elseif ($ipa === '' && preg_match('#^[/\[].+[/\]]$#u', trim($p))) $ipa = trim($p);
                }
                if ($ipa === '') continue;
                $lang = strtolower(explode('-', $lang)[0]);
                if ($lang === 'en') return [$ipa, 'en'];
                if ($first[0] === '') $first = [$ipa, $lang];
            }
        }
        return $first;
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

    private function wikitext(string $api, string $page): ?string
    {
        $json = $this->get($api . '?' . http_build_query(['action' => 'parse', 'prop' => 'wikitext', 'page' => $page, 'redirects' => 1, 'format' => 'json']));
        if ($json === null) return null;
        $text = json_decode($json, true)['parse']['wikitext']['*'] ?? null;
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
