<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;

/** Per-chapter voice and speed overrides. A null value means "use the project's". */
final class ChapterSettings
{
    /** @return array{voice:?string,speed:?float} */
    public static function get(string $logicalId): array
    {
        $s = Database::connection()->prepare('SELECT voice, speed FROM chapter_settings WHERE logical_section_id=:l');
        $s->execute(['l' => $logicalId]);
        return self::normalize($s->fetch() ?: null);
    }

    /**
     * Overrides for every chapter of a project, keyed by chapter id.
     * @return array<string,array{voice:?string,speed:?float}>
     */
    public static function forProject(string $projectId): array
    {
        $s = Database::connection()->prepare('SELECT c.logical_section_id, c.voice, c.speed FROM chapter_settings c JOIN logical_sections l ON l.id=c.logical_section_id WHERE l.project_id=:p');
        $s->execute(['p' => $projectId]);
        $out = [];
        foreach ($s->fetchAll() as $row) $out[(string) $row['logical_section_id']] = self::normalize($row);
        return $out;
    }

    /** Saves the overrides; null or empty for both removes them. */
    public static function set(string $projectId, string $logicalId, ?string $voice, ?float $speed): void
    {
        $s = Database::connection()->prepare('SELECT 1 FROM logical_sections WHERE id=:l AND project_id=:p');
        $s->execute(['l' => $logicalId, 'p' => $projectId]);
        if (!$s->fetchColumn()) throw new InvalidArgumentException('Chapter not found.');
        $voice = $voice !== null ? trim($voice) : null;
        if ($voice === '') $voice = null;
        if ($voice !== null && !KokoroClient::validVoice($voice)) throw new InvalidArgumentException('Voice must look like am_michael, or a blend such as af_bella+af_sky.');
        if ($speed !== null && !KokoroClient::validSpeed($speed)) throw new InvalidArgumentException('Speed must be between 0.5 and 2.0.');
        if ($voice === null && $speed === null) {
            Database::connection()->prepare('DELETE FROM chapter_settings WHERE logical_section_id=:l')->execute(['l' => $logicalId]);
            return;
        }
        Database::connection()->prepare(
            'INSERT INTO chapter_settings(logical_section_id,voice,speed) VALUES(:l,:v,:s)
             ON CONFLICT(logical_section_id) DO UPDATE SET voice=excluded.voice, speed=excluded.speed, updated_at=CURRENT_TIMESTAMP'
        )->execute(['l' => $logicalId, 'v' => $voice, 's' => $speed]);
    }

    /** @param array<string,mixed>|null $row @return array{voice:?string,speed:?float} */
    private static function normalize(?array $row): array
    {
        return [
            'voice' => $row !== null && $row['voice'] !== null && $row['voice'] !== '' ? (string) $row['voice'] : null,
            'speed' => $row !== null && $row['speed'] !== null ? (float) $row['speed'] : null,
        ];
    }
}
