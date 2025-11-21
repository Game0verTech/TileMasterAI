<?php

declare(strict_types=1);

namespace TileMasterAI\Server\Db;

use PDO;

final class Schema
{
    public static function ensureSessionsTable(PDO $pdo): void
    {
        // Legacy no-op to preserve backward compatibility
        self::ensureAppSchema($pdo);
    }

    /**
     * Main schema entry point for the new authentication + lobby system.
     */
    public static function ensureAppSchema(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Keep legacy tables for backwards-compat while layering the new tables
        self::applyMigrations($pdo, $driver);
        self::ensurePlayerTokenColumn($pdo, $driver);
        self::ensureLobbyEnhancements($pdo, $driver);
        self::applyAuthLobbyMigrations($pdo, $driver);
        self::ensureGameDrawColumns($pdo, $driver);
        self::ensureLiveGameColumns($pdo, $driver);
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

    private static function applyAuthLobbyMigrations(PDO $pdo, string $driver): void
    {
        $idColumn = $driver === 'mysql'
            ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $boolColumn = $driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER';
        $stringColumn = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users ('
            . 'id ' . $idColumn . ', '
            . 'username ' . $stringColumn . ' NOT NULL UNIQUE, '
            . 'password_hash ' . $stringColumn . ' NOT NULL, '
            . "role TEXT NOT NULL DEFAULT 'user', "
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lobbies ('
            . 'id ' . $idColumn . ', '
            . 'code ' . $stringColumn . ' NOT NULL UNIQUE, '
            . 'owner_user_id INTEGER NOT NULL, '
            . "status TEXT NOT NULL DEFAULT 'waiting', "
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE'
            . ')'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lobby_players ('
            . 'id ' . $idColumn . ', '
            . 'lobby_id INTEGER NOT NULL, '
            . 'user_id INTEGER NOT NULL, '
            . 'is_ready ' . $boolColumn . ' NOT NULL DEFAULT 0, '
            . 'joined_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(lobby_id, user_id), '
            . 'FOREIGN KEY (lobby_id) REFERENCES lobbies(id) ON DELETE CASCADE, '
            . 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
            . ')'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS games ('
            . 'id ' . $idColumn . ', '
            . 'lobby_id INTEGER NOT NULL UNIQUE, '
            . 'turn_order TEXT NOT NULL, '
            . 'started_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'FOREIGN KEY (lobby_id) REFERENCES lobbies(id) ON DELETE CASCADE'
            . ')'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS active_sessions ('
            . 'id ' . $stringColumn . ' PRIMARY KEY, '
            . 'user_id INTEGER NOT NULL, '
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
            . ')'
        );
    }

    private static function ensureGameDrawColumns(PDO $pdo, string $driver): void
    {
        $stringColumn = $driver === 'mysql' ? 'TEXT' : 'TEXT';

        $columns = [];
        if ($driver === 'sqlite') {
            $statement = $pdo->query("PRAGMA table_info(games)");
            $columns = $statement ? $statement->fetchAll() : [];
        } else {
            $statement = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME = 'games'"
            );
            $statement->execute();
            $columns = array_map(static fn ($row) => ['name' => $row['COLUMN_NAME'] ?? ''], $statement->fetchAll());
        }

        $columnNames = array_map(static fn ($column) => $column['name'] ?? '', $columns);

        if (!in_array('draw_pool', $columnNames, true)) {
            $pdo->exec("ALTER TABLE games ADD COLUMN draw_pool {$stringColumn} NULL");
        }

        if (!in_array('draws', $columnNames, true)) {
            $pdo->exec("ALTER TABLE games ADD COLUMN draws {$stringColumn} NOT NULL DEFAULT '[]'");
        }
    }

    private static function ensureLiveGameColumns(PDO $pdo, string $driver): void
    {
        $stringColumn = $driver === 'mysql' ? 'TEXT' : 'TEXT';
        $intColumn = $driver === 'mysql' ? 'INT' : 'INTEGER';

        $columns = [];
        if ($driver === 'sqlite') {
            $statement = $pdo->query("PRAGMA table_info(games)");
            $columns = $statement ? $statement->fetchAll() : [];
        } else {
            $statement = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME = 'games'"
            );
            $statement->execute();
            $columns = array_map(static fn ($row) => ['name' => $row['COLUMN_NAME'] ?? ''], $statement->fetchAll());
        }

        $columnNames = array_map(static fn ($column) => $column['name'] ?? '', $columns);

        if (!in_array('board_state', $columnNames, true)) {
            $pdo->exec("ALTER TABLE games ADD COLUMN board_state {$stringColumn} NOT NULL DEFAULT '[]'");
        }

        if (!in_array('racks', $columnNames, true)) {
            $pdo->exec("ALTER TABLE games ADD COLUMN racks {$stringColumn} NOT NULL DEFAULT '{}'"
            );
        }

        if (!in_array('bag', $columnNames, true)) {
            $pdo->exec("ALTER TABLE games ADD COLUMN bag {$stringColumn} NOT NULL DEFAULT '[]'");
        }

        if (!in_array('current_turn_index', $columnNames, true)) {
            $pdo->exec("ALTER TABLE games ADD COLUMN current_turn_index {$intColumn} NOT NULL DEFAULT 0");
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

    private static function ensureLobbyEnhancements(PDO $pdo, string $driver): void
    {
        $columnType = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $boolColumn = $driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER';

        // Add optional lobby name to sessions
        $hasLobbyName = false;
        $hasStatusIndex = false;

        if ($driver === 'sqlite') {
            $statement = $pdo->query("PRAGMA table_info(sessions)");
            $columns = $statement ? $statement->fetchAll() : [];
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'name') {
                    $hasLobbyName = true;
                }
                if (($column['name'] ?? '') === 'status') {
                    $hasStatusIndex = true;
                }
            }
        } else {
            $statement = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME = 'sessions' AND COLUMN_NAME = 'name' LIMIT 1"
            );
            $statement->execute();
            $hasLobbyName = $statement->fetchColumn() !== false;

            $statement = $pdo->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_NAME = 'sessions' AND COLUMN_NAME = 'status' LIMIT 1"
            );
            $statement->execute();
            $hasStatusIndex = $statement->fetchColumn() !== false;
        }

        if (!$hasLobbyName) {
            $pdo->exec("ALTER TABLE sessions ADD COLUMN name {$columnType} NULL");
        }

        if (!$hasStatusIndex) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_status ON sessions(status)');
        }

        // Add readiness flags to session_players
        $hasReadyFlag = false;
        $hasReadyAt = false;

        if ($driver === 'sqlite') {
            $statement = $pdo->query("PRAGMA table_info(session_players)");
            $columns = $statement ? $statement->fetchAll() : [];
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'is_ready') {
                    $hasReadyFlag = true;
                }
                if (($column['name'] ?? '') === 'ready_at') {
                    $hasReadyAt = true;
                }
            }
        } else {
            $statement = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME = 'session_players' AND COLUMN_NAME = 'is_ready' LIMIT 1"
            );
            $statement->execute();
            $hasReadyFlag = $statement->fetchColumn() !== false;

            $statement = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME = 'session_players' AND COLUMN_NAME = 'ready_at' LIMIT 1"
            );
            $statement->execute();
            $hasReadyAt = $statement->fetchColumn() !== false;
        }

        if (!$hasReadyFlag) {
            $pdo->exec("ALTER TABLE session_players ADD COLUMN is_ready {$boolColumn} NOT NULL DEFAULT 0");
        }

        if (!$hasReadyAt) {
            $pdo->exec('ALTER TABLE session_players ADD COLUMN ready_at DATETIME NULL');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_session_players_ready ON session_players(session_id, is_ready)');
    }
}
