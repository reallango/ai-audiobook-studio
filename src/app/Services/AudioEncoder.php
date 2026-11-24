<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

/**
 * ffmpeg and ffprobe, run without a shell. Chapter audio is AAC mono in an m4a file; a whole book is a chaptered m4b
 * made by joining those m4a files without re-encoding.
 */
final class AudioEncoder
{
    /** Project quality choices: AAC mono bitrate in kbit/s. */
    public const BITRATES = [32 => 'Low', 64 => 'Default', 96 => 'High', 128 => 'Very high'];
    public const DEFAULT_BITRATE = 64;

    /** @var callable(array<int,string>):string */
    private $runner;

    /** @param null|callable(array<int,string>):string $runner argv -> stdout; tests pass a fake */
    public function __construct(?callable $runner = null, private ?string $ffmpeg = null, private ?string $ffprobe = null)
    {
        $this->runner = $runner ?? [self::class, 'runProcess'];
    }

    public static function validBitrate(int $kbps): bool
    {
        return isset(self::BITRATES[$kbps]);
    }

    /**
     * ffmpeg arguments that turn a WAV into mono AAC with tags.
     * @param array<string,string> $meta
     * @return array<int,string>
     */
    public static function encodeArgs(string $ffmpeg, string $in, string $out, int $kbps, array $meta): array
    {
        $args = [$ffmpeg, '-nostdin', '-y', '-v', 'error', '-i', $in, '-vn', '-map_metadata', '-1', '-ac', '1', '-c:a', 'aac', '-b:a', $kbps . 'k', '-movflags', '+faststart'];
        foreach ($meta as $key => $value) {
            if ($value !== '') array_push($args, '-metadata', $key . '=' . $value);
        }
        array_push($args, '-f', 'ipod', $out);
        return $args;
    }

    /** @param array<string,string> $meta */
    public function encode(string $wav, string $m4a, int $kbps, array $meta = []): void
    {
        if (!self::validBitrate($kbps)) throw new RuntimeException('Unsupported audio bitrate.');
        $tmp = $m4a . '.part';
        try {
            ($this->runner)(self::encodeArgs($this->ffmpeg(), $wav, $tmp, $kbps, $meta));
            if (!is_file($tmp) || filesize($tmp) === 0) throw new RuntimeException('The encoder produced no audio.');
            if (!rename($tmp, $m4a)) throw new RuntimeException('Cannot store the encoded audio.');
        } finally {
            if (is_file($tmp)) @unlink($tmp);                   // a failed encode leaves no half-written file behind
        }
    }

    /** Duration of an audio file in milliseconds, from ffprobe. */
    public function durationMs(string $file): int
    {
        $out = trim(($this->runner)([$this->ffprobe(), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file]));
        if (!is_numeric($out)) throw new RuntimeException('Cannot read the audio length of ' . basename($file) . '.');
        return (int) round((float) $out * 1000);
    }

    /** How many chapter markers a finished file holds. */
    public function chapterCount(string $file): int
    {
        $json = json_decode(($this->runner)([$this->ffprobe(), '-v', 'error', '-show_chapters', '-of', 'json', $file]), true);
        return is_array($json) && isset($json['chapters']) && is_array($json['chapters']) ? count($json['chapters']) : 0;
    }

    /**
     * Joins m4a files into one m4b with chapter markers, copying the audio as it is.
     * @param string[] $files in order
     */
    public function joinToM4b(array $files, string $listPath, string $metadataPath, string $out): void
    {
        $list = '';
        foreach ($files as $file) $list .= "file '" . str_replace("'", "'\\''", $file) . "'\n";
        if (file_put_contents($listPath, $list) === false) throw new RuntimeException('Cannot write the file list.');
        $tmp = $out . '.part';
        ($this->runner)([$this->ffmpeg(), '-nostdin', '-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', $listPath, '-i', $metadataPath,
            '-map', '0:a', '-map_metadata', '1', '-map_chapters', '1', '-c', 'copy', '-movflags', '+faststart', '-f', 'ipod', $tmp]);
        if (!is_file($tmp) || filesize($tmp) === 0) throw new RuntimeException('The audiobook file was not produced.');
        if (!rename($tmp, $out)) throw new RuntimeException('Cannot store the audiobook file.');
    }

    /**
     * ffmetadata text with the book tags and one marker per chapter.
     * @param array<string,string> $meta
     * @param array<int,array{title:string,start_ms:int,end_ms:int}> $chapters
     */
    public static function metadataFile(array $meta, array $chapters): string
    {
        $esc = static fn (string $v): string => preg_replace('/([=;#\\\\])/', '\\\\$1', str_replace(["\r", "\n"], ' ', $v)) ?? $v;
        $text = ";FFMETADATA1\n";
        foreach ($meta as $key => $value) if ($value !== '') $text .= $key . '=' . $esc($value) . "\n";
        foreach ($chapters as $c) {
            $text .= "\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=" . $c['start_ms'] . "\nEND=" . $c['end_ms'] . "\ntitle=" . $esc($c['title']) . "\n";
        }
        return $text;
    }

    private function ffmpeg(): string
    {
        return $this->ffmpeg ?? configured_path('paths.ffmpeg');
    }

    private function ffprobe(): string
    {
        return $this->ffprobe ?? configured_path('paths.ffprobe');
    }

    /** @param array<int,string> $argv */
    public static function runProcess(array $argv): string
    {
        $process = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start ' . basename($argv[0]) . '. Is it installed?');
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) throw new RuntimeException(basename($argv[0]) . ' failed: ' . mb_substr(trim($err), 0, 300));
        return $out;
    }
}
