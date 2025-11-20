<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/src/Server/Db/Connection.php';
require_once dirname(__DIR__) . '/src/Server/Db/Schema.php';

use TileMasterAI\Server\Db\Connection;
use TileMasterAI\Server\Db\Schema;

$pdo = Connection::getPdo();
Schema::applyMigrations($pdo);

echo "Migrations applied successfully.\n";
