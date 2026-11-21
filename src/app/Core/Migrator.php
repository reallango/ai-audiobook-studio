<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Applies database/migrations/*.sql in filename order, once each.
 * A migration file's name without ".sql" is its version in schema_migrations.
 */
final class Migrator
{
    public static function run(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $applied = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration {$version}.");
            }
            $db->exec($sql);
            $db->prepare('INSERT OR IGNORE INTO schema_migrations(version) VALUES (:v)')->execute(['v' => $version]);
        }
    }
}
