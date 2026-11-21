<?php
declare(strict_types=1);
namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/** Thin client for Kokoro's OpenAI-compatible /v1/audio/speech endpoint. Voice and speed are always arguments. */
final class KokoroClient
{
    public const MAX_PREVIEW_CHARS = 3000;

    public static function validVoice(string $voice): bool
    {
        // single voices (am_michael) or blends (af_bella+af_sky)
        return (bool) preg_match('/^[a-z]{2}_[a-z0-9]+(\+[a-z]{2}_[a-z0-9]+)*$/', $voice) && strlen($voice) <= 100;
    }

    public static function validSpeed(float $speed): bool
    {
        return $speed >= 0.5 && $speed <= 2.0;
    }

    /** Returns WAV bytes for the text. */
    public function speech(string $text, string $voice, float $speed): string
    {
        $text = trim($text);
        if ($text === '') throw new InvalidArgumentException('Enter some text to speak.');
        if (!self::validVoice($voice)) throw new InvalidArgumentException('That voice name is not valid.');
        if (!self::validSpeed($speed)) throw new InvalidArgumentException('Speed must be between 0.5 and 2.0.');

        $url = (string) config('tts.kokoro_url');
        $key = (string) config('tts.kokoro_api_key');
        if ($key === '') throw new RuntimeException('KOKORO_API_KEY is not set for this container.');

        $ch = curl_init($url . '/v1/audio/speech');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) config('tts.request_timeout_seconds', 120),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'tts-1', 'input' => $text, 'voice' => $voice,
                'response_format' => 'wav', 'speed' => $speed,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) throw new RuntimeException('Could not reach Kokoro at ' . $url . ': ' . $err);
        if ($code === 401) throw new RuntimeException('Kokoro rejected the API key (401).');
        if ($code < 200 || $code >= 300) throw new RuntimeException('Kokoro returned HTTP ' . $code . ': ' . mb_substr(trim((string) $body), 0, 200));
        if (strncmp((string) $body, 'RIFF', 4) !== 0) throw new RuntimeException('Kokoro did not return WAV audio.');
        return (string) $body;
    }
}
