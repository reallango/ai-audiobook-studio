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
            // Web requests and both workers start at the same time after a deploy. BEGIN IMMEDIATE takes the write lock
            // first, the version is checked again inside it, and the migration and its version row commit together,
            // so a migration is never run twice or left half applied.
            // The journal mode cannot change inside a transaction, so that statement runs first, on its own.
            $sql = (string) preg_replace_callback('/^\s*PRAGMA\s+journal_mode\s*=\s*\w+\s*;\s*$/mi', static function (array $m) use ($db): string {
                $db->exec(trim($m[0]));
                return '';
            }, $sql);
            $db->exec('BEGIN IMMEDIATE');
            try {
                $done = $db->prepare('SELECT 1 FROM schema_migrations WHERE version=:v');
                $done->execute(['v' => $version]);
                if ($done->fetchColumn() === false) {
                    $db->exec($sql);
                    // OR IGNORE: some early migration files record their own version
                    $db->prepare('INSERT OR IGNORE INTO schema_migrations(version) VALUES (:v)')->execute(['v' => $version]);
                }
                $db->exec('COMMIT');
            } catch (\Throwable $error) {
                $db->exec('ROLLBACK');
                throw $error;
            }
        }
    }
}
