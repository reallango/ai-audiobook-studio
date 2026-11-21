<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

/** Minimal PCM WAV reader and joiner. Kokoro returns 24 kHz mono 16-bit PCM, and joining needs no re-encoding. */
final class WavFile
{
    /**
     * @return array{channels:int,rate:int,bits:int,data_offset:int,data_length:int,duration_ms:int}
     */
    public static function info(string $path): array
    {
        $size = @filesize($path);
        $h = @fopen($path, 'rb');
        if ($h === false || $size === false) throw new RuntimeException('Cannot read the audio file.');
        try {
            if (fread($h, 4) !== 'RIFF') throw new RuntimeException('Not a WAV file.');
            fseek($h, 8);
            if (fread($h, 4) !== 'WAVE') throw new RuntimeException('Not a WAV file.');
            $fmt = null;
            $offset = 12;
            while ($offset + 8 <= $size) {
                fseek($h, $offset);
                $id = (string) fread($h, 4);
                $len = unpack('V', (string) fread($h, 4))[1];
                if ($id === 'fmt ') {
                    $f = unpack('vtag/vchannels/Vrate/Vbyterate/valign/vbits', (string) fread($h, 16));
                    if ($f['tag'] !== 1) throw new RuntimeException('Only PCM WAV audio is supported.');
                    $fmt = $f;
                } elseif ($id === 'data') {
                    if ($fmt === null) throw new RuntimeException('The WAV data comes before its format.');
                    $start = $offset + 8;
                    // Streamed WAVs may carry 0 or 0xFFFFFFFF as the data length: use what is on disk.
                    $length = ($len === 0 || $len === 0xFFFFFFFF || $start + $len > $size) ? $size - $start : $len;
                    $frame = max(1, $fmt['channels'] * intdiv($fmt['bits'], 8));
                    $length -= $length % $frame;
                    return [
                        'channels' => $fmt['channels'], 'rate' => $fmt['rate'], 'bits' => $fmt['bits'],
                        'data_offset' => $start, 'data_length' => $length,
                        'duration_ms' => (int) round($length / ($fmt['rate'] * $frame) * 1000),
                    ];
                }
                $offset += 8 + $len + ($len & 1);
            }
        } finally {
            fclose($h);
        }
        throw new RuntimeException('The WAV file has no audio data.');
    }

    /**
     * Joins WAV files in order, with a silence of the given length after each (the last gap is dropped).
     * @param array<int,array{path:string,gap_ms:int}> $parts
     * @return array{duration_ms:int,byte_size:int}
     */
    public static function join(array $parts, string $outPath): array
    {
        if ($parts === []) throw new RuntimeException('There is nothing to join.');
        $infos = array_map(static fn (array $p): array => self::info($p['path']), $parts);
        $first = $infos[0];
        foreach ($infos as $i) {
            if ($i['channels'] !== $first['channels'] || $i['rate'] !== $first['rate'] || $i['bits'] !== $first['bits']) {
                throw new RuntimeException('The segments have different audio formats and cannot be joined.');
            }
        }
        $frame = $first['channels'] * intdiv($first['bits'], 8);
        $total = 0;
        $gaps = [];
        foreach ($parts as $n => $p) {
            $total += $infos[$n]['data_length'];
            $gap = $n === array_key_last($parts) ? 0 : max(0, (int) $p['gap_ms']);
            $gaps[$n] = $gap > 0 ? (int) round($gap * $first['rate'] / 1000) * $frame : 0;
            $total += $gaps[$n];
        }
        if ($total > 0xFFFFFFFF - 36) throw new RuntimeException('That chapter is too long for one WAV file (4 GB limit).');

        $tmp = $outPath . '.part';
        $out = @fopen($tmp, 'wb');
        if ($out === false) throw new RuntimeException('Cannot write the chapter audio.');
        try {
            fwrite($out, 'RIFF' . pack('V', 36 + $total) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, $first['channels'], $first['rate'], $first['rate'] * $frame, $frame, $first['bits']) . 'data' . pack('V', $total));
            foreach ($parts as $n => $p) {
                $in = fopen($p['path'], 'rb');
                if ($in === false) throw new RuntimeException('Cannot read a segment.');
                fseek($in, $infos[$n]['data_offset']);
                $left = $infos[$n]['data_length'];
                while ($left > 0) {
                    $chunk = fread($in, min(1 << 20, $left));
                    if ($chunk === false || $chunk === '') break;
                    fwrite($out, $chunk);
                    $left -= strlen($chunk);
                }
                fclose($in);
                for ($z = $gaps[$n]; $z > 0; $z -= 65536) fwrite($out, str_repeat("\0", min(65536, $z)));
            }
        } finally {
            fclose($out);
        }
        if (!rename($tmp, $outPath)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot store the chapter audio.');
        }
        return ['duration_ms' => (int) round($total / ($first['rate'] * $frame) * 1000), 'byte_size' => 44 + $total];
    }
}
