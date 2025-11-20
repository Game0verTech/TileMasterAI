<?php

declare(strict_types=1);

namespace TileMasterAI\Server\Db;

use PDO;
use PDOException;
use RuntimeException;

require_once __DIR__ . '/Schema.php';

/**
 * Lightweight PDO connection manager.
 * Defaults to a SQLite database under data/ but can switch to MySQL via env vars.
 */
class Connection
{
    private static ?PDO $pdo = null;

    public static function getPdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        $dsn = getenv('DB_DSN');
        $user = getenv('DB_USERNAME') ?: null;
        $password = getenv('DB_PASSWORD') ?: null;

        if ($driver === 'mysql') {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $database = getenv('DB_DATABASE') ?: 'tilemaster';
            $charset = 'utf8mb4';
            $dsn = $dsn ?: sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);
        } else {
            $databasePath = getenv('DB_DATABASE') ?: dirname(__DIR__, 3) . '/data/tilemaster.sqlite';
            $dsn = $dsn ?: 'sqlite:' . $databasePath;
            $user = null;
            $password = null;

            $directory = dirname($databasePath);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create data directory at %s', $directory));
            }
        }

        try {
            $pdo = new PDO($dsn, $user ?? '', $password ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            if ($driver === 'sqlite') {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }

            Schema::ensureSessionsTable($pdo);
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }

        self::$pdo = $pdo;
        return self::$pdo;
    }
}
