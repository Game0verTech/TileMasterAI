<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/Server/Db/Connection.php';
require dirname(__DIR__) . '/src/Server/Db/GameRepository.php';
require __DIR__ . '/migrate.php';

use TileMasterAI\Server\Db\GameRepository;

$repository = new GameRepository();
$pdo = $repository->getPdo();

$sessionCode = 'DEVSESSION';
$session = $repository->getSessionByCode($sessionCode);
$sessionId = $session ? (int) $session['id'] : $repository->createSession($sessionCode, 'active');

$players = [
    ['name' => 'Alice', 'is_host' => true],
    ['name' => 'Bob', 'is_host' => false],
    ['name' => 'Carmen', 'is_host' => false],
];

$ensurePlayer = static function (string $name) use ($pdo, $repository): int {
    $existing = $pdo->prepare('SELECT id FROM players WHERE name = :name LIMIT 1');
    $existing->execute([':name' => $name]);
    $row = $existing->fetch();

    if ($row !== false) {
        return (int) $row['id'];
    }

    return $repository->createPlayer($name);
};

$joinOrder = 1;
foreach ($players as $player) {
    $playerId = $ensurePlayer($player['name']);
    $repository->addPlayerToSession($sessionId, $playerId, $joinOrder, $player['is_host']);
    $joinOrder++;
}

$tileDistribution = [
    ['letter' => 'A', 'value' => 1, 'count' => 9],
    ['letter' => 'B', 'value' => 3, 'count' => 2],
    ['letter' => 'C', 'value' => 3, 'count' => 2],
    ['letter' => 'D', 'value' => 2, 'count' => 4],
    ['letter' => 'E', 'value' => 1, 'count' => 12],
    ['letter' => 'F', 'value' => 4, 'count' => 2],
    ['letter' => 'G', 'value' => 2, 'count' => 3],
    ['letter' => 'H', 'value' => 4, 'count' => 2],
    ['letter' => 'I', 'value' => 1, 'count' => 9],
    ['letter' => 'J', 'value' => 8, 'count' => 1],
    ['letter' => 'K', 'value' => 5, 'count' => 1],
    ['letter' => 'L', 'value' => 1, 'count' => 4],
    ['letter' => 'M', 'value' => 3, 'count' => 2],
    ['letter' => 'N', 'value' => 1, 'count' => 6],
    ['letter' => 'O', 'value' => 1, 'count' => 8],
    ['letter' => 'P', 'value' => 3, 'count' => 2],
    ['letter' => 'Q', 'value' => 10, 'count' => 1],
    ['letter' => 'R', 'value' => 1, 'count' => 6],
    ['letter' => 'S', 'value' => 1, 'count' => 4],
    ['letter' => 'T', 'value' => 1, 'count' => 6],
    ['letter' => 'U', 'value' => 1, 'count' => 4],
    ['letter' => 'V', 'value' => 4, 'count' => 2],
    ['letter' => 'W', 'value' => 4, 'count' => 2],
    ['letter' => 'X', 'value' => 8, 'count' => 1],
    ['letter' => 'Y', 'value' => 4, 'count' => 2],
    ['letter' => 'Z', 'value' => 10, 'count' => 1],
    ['letter' => ' ', 'value' => 0, 'count' => 2],
];

$repository->seedTileBag($sessionId, $tileDistribution);

$totalTiles = array_reduce($tileDistribution, static function (int $carry, array $tile): int {
    return $carry + $tile['count'];
}, 0);

$bagState = [
    'total' => $totalTiles,
    'composition' => $tileDistribution,
];

$repository->saveSnapshot(
    $sessionId,
    null,
    json_encode(['board' => 'empty'], JSON_THROW_ON_ERROR),
    json_encode(['racks' => []], JSON_THROW_ON_ERROR),
    json_encode($bagState, JSON_THROW_ON_ERROR),
    'Seeded development snapshot'
);

echo "Seed data loaded for session {$sessionCode}.\n";
