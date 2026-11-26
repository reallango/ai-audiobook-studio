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
        self::$connection = new SqlitePdo('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$connection->exec('PRAGMA foreign_keys = ON');
        // the web app, the chapter worker, the task worker and the pronunciation check all write: wait for each other
        self::$connection->exec('PRAGMA busy_timeout = 30000');
        Migrator::run(self::$connection);
        return self::$connection;
    }

    /** True for SQLite's "database is locked" / "busy", which goes away if the work is simply tried again. */
    public static function isBusy(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'database table is locked')
            || str_contains($e->getMessage(), 'SQLSTATE[HY000]: General error: 5 ');
    }
}

/**
 * PDO whose transactions start with BEGIN IMMEDIATE. A plain BEGIN reads first and asks for the write lock later; in WAL
 * mode, if another process wrote in between, SQLite refuses that upgrade at once ("database is locked") without waiting
 * for busy_timeout. Taking the write lock at the start makes a transaction wait its turn instead.
 */
final class SqlitePdo extends PDO
{
    private bool $open = false;

    public function beginTransaction(): bool
    {
        if ($this->open) throw new \PDOException('There is already an active transaction');
        $this->exec('BEGIN IMMEDIATE');
        $this->open = true;
        return true;
    }

    public function commit(): bool
    {
        if (!$this->open) throw new \PDOException('There is no active transaction');
        $this->exec('COMMIT');
        $this->open = false;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->open) throw new \PDOException('There is no active transaction');
        $this->open = false;
        $this->exec('ROLLBACK');
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }
}
