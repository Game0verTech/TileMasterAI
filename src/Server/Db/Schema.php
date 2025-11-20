<?php

declare(strict_types=1);

namespace TileMasterAI\Server\Db;

use PDO;

final class Schema
{
    public static function ensureSessionsTable(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $exists = false;

        if ($driver === 'sqlite') {
            $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sessions' LIMIT 1");
            $exists = $statement !== false && $statement->fetchColumn() !== false;
        } else {
            $statement = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' LIMIT 1");
            $exists = $statement !== false && $statement->fetchColumn() !== false;
        }

        if (!$exists) {
            self::applyMigrations($pdo, $driver);
        }

        self::ensurePlayerTokenColumn($pdo, $driver);
    }

    public static function applyMigrations(PDO $pdo, ?string $driver = null): void
    {
        $driver = $driver ?: (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'mysql'
            ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $boolColumn = $driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER';

        $stringColumn = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';

        $migrations = [
            <<<SQL
            CREATE TABLE IF NOT EXISTS sessions (
                id {$idColumn},
                code TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS players (
                id {$idColumn},
                name TEXT NOT NULL,
                client_token {$stringColumn} NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(client_token)
            );
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS session_players (
                id {$idColumn},
                session_id INTEGER NOT NULL,
                player_id INTEGER NOT NULL,
                join_order INTEGER NOT NULL,
                is_host {$boolColumn} NOT NULL DEFAULT 0,
                joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(session_id, player_id),
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
            );
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS tile_bag (
                id {$idColumn},
                session_id INTEGER NOT NULL,
                letter TEXT NOT NULL,
                value INTEGER NOT NULL DEFAULT 0,
                state TEXT NOT NULL DEFAULT 'bag',
                drawn_by INTEGER NULL,
                drawn_at DATETIME NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (drawn_by) REFERENCES players(id) ON DELETE SET NULL
            );
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS turns (
                id {$idColumn},
                session_id INTEGER NOT NULL,
                player_id INTEGER NOT NULL,
                turn_number INTEGER NOT NULL,
                placements TEXT NOT NULL,
                score INTEGER NOT NULL,
                action TEXT NOT NULL DEFAULT 'play',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(session_id, turn_number),
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
            );
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS game_state_snapshots (
                id {$idColumn},
                session_id INTEGER NOT NULL,
                turn_id INTEGER NULL,
                board_state TEXT NOT NULL,
                rack_state TEXT NOT NULL,
                bag_state TEXT NOT NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (turn_id) REFERENCES turns(id) ON DELETE SET NULL
            );
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS turn_states (
                id {$idColumn},
                session_id INTEGER NOT NULL UNIQUE,
                current_player_id INTEGER NULL,
                turn_number INTEGER NOT NULL DEFAULT 1,
                sequence TEXT NULL,
                phase TEXT NOT NULL DEFAULT 'idle',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (current_player_id) REFERENCES players(id) ON DELETE SET NULL
            );
            SQL,
        ];

        foreach ($migrations as $sql) {
            $pdo->exec($sql);
        }
    }

    private static function ensurePlayerTokenColumn(PDO $pdo, string $driver): void
    {
        $hasColumn = false;

        if ($driver === 'sqlite') {
            $statement = $pdo->query("PRAGMA table_info(players)");
            $columns = $statement ? $statement->fetchAll() : [];
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'client_token') {
                    $hasColumn = true;
                    break;
                }
            }
        } else {
            $statement = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME = 'players' AND COLUMN_NAME = 'client_token' LIMIT 1"
            );
            $statement->execute();
            $hasColumn = $statement->fetchColumn() !== false;
        }

        if ($hasColumn) {
            return;
        }

        $columnType = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $pdo->exec("ALTER TABLE players ADD COLUMN client_token {$columnType} NULL");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_players_client_token ON players(client_token)');
    }
}
