<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/Server/Db/Connection.php';

use TileMasterAI\Server\Db\Connection;

$pdo = Connection::getPdo();
$driver = getenv('DB_CONNECTION') ?: 'sqlite';

$idColumn = $driver === 'mysql'
    ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
    : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$boolColumn = $driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER';

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
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
];

foreach ($migrations as $sql) {
    $pdo->exec($sql);
}

echo "Migrations applied successfully.\n";
