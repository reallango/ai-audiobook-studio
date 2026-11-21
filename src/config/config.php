<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'AI Audiobook Studio',
        // Empty means served at the web root. Set e.g. /AI-Audiobook to serve from a subfolder.
        'base_path' => rtrim((string) (getenv('AI_AUDIOBOOK_BASE_PATH') ?: ''), '/'),
        'environment' => getenv('AI_AUDIOBOOK_ENV') ?: 'production',
        'debug' => filter_var(getenv('AI_AUDIOBOOK_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
    ],
    'database' => [
        'driver' => 'sqlite',
        'path' => APP_ROOT . '/storage/app.sqlite',
    ],
    'paths' => [
        'ffmpeg' => getenv('FFMPEG_PATH') ?: '/usr/bin/ffmpeg',
        'ffprobe' => getenv('FFPROBE_PATH') ?: '/usr/bin/ffprobe',
        'projects' => APP_ROOT . '/storage/projects',
        'cache' => APP_ROOT . '/storage/cache',
        'logs' => APP_ROOT . '/storage/logs',
        'tmp' => APP_ROOT . '/storage/tmp',
    ],
    'audio' => [
        'default_bitrate_kbps' => 96,
        'retain_wav' => false,
    ],
    'tts' => [
        'default_provider' => 'kokoro',
        'request_timeout_seconds' => 120,
        // Connection only. Voice and speed are per-project settings, never env.
        'kokoro_url' => rtrim(getenv('KOKORO_URL') ?: 'http://localhost:8880', '/'),
        'kokoro_api_key' => getenv('KOKORO_API_KEY') ?: '',
    ],
];
