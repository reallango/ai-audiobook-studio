<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * The pronunciation checker (the pron-check service on the voice machine): tells how the voice will read a word,
 * without making audio, how close that is to a pronunciation found online, and a plain spelling that comes closer.
 * Its address and key come from PRON_CHECK_URL / PRON_CHECK_API_KEY, or from Diagnostics when those are not set.
 * The studio works without it (the online check then only finds pronunciations).
 */
final class PronCheckClient
{
    public const DEFAULT_MINIMUM = 85;

    /** @param null|callable(string,string,array):array{status:int,body:string} $post tests pass a fake */
    public function __construct(private $post = null)
    {
    }

    public static function url(): string
    {
        $env = trim((string) getenv('PRON_CHECK_URL'));
        return rtrim($env !== '' ? $env : self::setting('pron_check_url'), '/');
    }

    public static function key(): string
    {
        $env = trim((string) getenv('PRON_CHECK_API_KEY'));
        return $env !== '' ? $env : self::setting('pron_check_key');
    }

    /** Whether the address and key come from the container's environment (then Diagnostics cannot change them). */
    public static function fromEnvironment(): bool
    {
        return trim((string) getenv('PRON_CHECK_URL')) !== '';
    }

    public static function configured(): bool
    {
        return self::url() !== '';
    }

    /** Saves the address, and the key when one is given (a blank key keeps the old one). */
    public static function save(string $url, string $key): void
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?(/[^\s]*)?$#', $url)) throw new \InvalidArgumentException('The address should look like http://192.168.1.130:8890');
        self::store('pron_check_url', rtrim($url, '/'));
        if (trim($key) !== '') self::store('pron_check_key', trim($key));
        if ($url === '') self::store('pron_check_key', '');
    }

    /** Null when reachable, else why not. */
    public function problem(): ?string
    {
        if (!self::configured()) return 'Not set up';
        try {
            $this->call('/assess', ['items' => [['word' => 'test']]], 10);
            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param list<array{word:string,sounds?:string}> $items
     * @return list<array{word:string,voice:string,score?:float,spelling?:array{text:string,reads:string,score:float}}>
     */
    public function assess(array $items, bool $british = false, float $minimum = self::DEFAULT_MINIMUM): array
    {
        $out = [];
        foreach (array_chunk($items, 25) as $chunk) {
            $r = $this->call('/assess', ['items' => $chunk, 'british' => $british, 'minimum' => $minimum], 120);
            foreach ($r['items'] ?? [] as $row) $out[] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function call(string $path, array $body, int $timeout): array
    {
        if (!self::configured()) throw new RuntimeException('The pronunciation checker is not set up.');
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $r = $this->post !== null ? ($this->post)(self::url() . $path, (string) $json, ['timeout' => $timeout]) : self::httpPost(self::url() . $path, (string) $json, $timeout);
        if ($r['status'] === 401) throw new RuntimeException('The pronunciation checker refused the key.');
        if ($r['status'] !== 200) throw new RuntimeException($r['status'] === 0 ? 'The pronunciation checker is not answering.' : 'The pronunciation checker answered ' . $r['status'] . '.');
        $data = json_decode($r['body'], true);
        if (!is_array($data)) throw new RuntimeException('The pronunciation checker gave an answer the studio could not read.');
        return $data;
    }

    /** @return array{status:int,body:string} */
    private static function httpPost(string $url, string $json, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Authorization: Bearer ' . self::key()],
        ]);
        $body = curl_exec($ch);
        $status = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    private static function setting(string $key): string
    {
        $s = Database::connection()->prepare('SELECT setting_value FROM settings WHERE setting_key=:k');
        $s->execute(['k' => $key]);
        return (string) ($s->fetchColumn() ?: '');
    }

    private static function store(string $key, string $value): void
    {
        Database::connection()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(:k,:v) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value, updated_at=CURRENT_TIMESTAMP')
            ->execute(['k' => $key, 'v' => $value]);
    }
}
