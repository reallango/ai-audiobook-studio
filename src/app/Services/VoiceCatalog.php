<?php
declare(strict_types=1);
namespace App\Services;

/** Kokoro v1.0 English voices. Any other valid voice name can still be typed in. */
final class VoiceCatalog
{
    public const DEFAULT_VOICE = 'am_michael';
    public const DEFAULT_SPEED = 1.0;

    public const GROUPS = [
        'American male' => ['am_michael', 'am_adam', 'am_echo', 'am_eric', 'am_fenrir', 'am_liam', 'am_onyx', 'am_puck', 'am_santa'],
        'British male' => ['bm_lewis', 'bm_daniel', 'bm_fable', 'bm_george'],
        'American female' => ['af_bella', 'af_sarah', 'af_heart', 'af_alloy', 'af_aoede', 'af_jessica', 'af_kore', 'af_nicole', 'af_nova', 'af_river', 'af_sky'],
        'British female' => ['bf_alice', 'bf_emma', 'bf_isabella', 'bf_lily'],
    ];

    public const SAMPLE_TEXT = "The ship lurched as the station's lights fell away behind them. \"We're not alone out here,\" the captain said quietly. Nobody argued. For a long moment the only sound on the bridge was the low hum of the engines.";

    /** @return string[] */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }
}
