<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) return self::$connection;
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('PDO SQLite is not loaded.');
        $path = (string) config('database.path');
        if (!is_dir(dirname($path)) || !is_writable(dirname($path))) throw new RuntimeException('Database directory is not writable.');
        self::$connection = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$connection->exec('PRAGMA foreign_keys = ON');
        self::$connection->exec('PRAGMA busy_timeout = 5000');
        Migrator::run(self::$connection);
        return self::$connection;
    }
}
