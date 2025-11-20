<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Server/Db/Connection.php';
require dirname(__DIR__) . '/src/Server/Db/AppRepository.php';
require dirname(__DIR__) . '/src/Server/Auth.php';

use TileMasterAI\Server\Auth;
use TileMasterAI\Server\Db\AppRepository;

header('Content-Type: application/json');

$repository = new AppRepository();
$user = Auth::requireUser($repository);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$lobbyId = (int) ($_GET['lobbyId'] ?? 0);
$lobby = $repository->getLobbyById($lobbyId);
if (!$lobby) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Lobby not found.']);
    exit;
}

$game = $repository->getGameByLobby($lobbyId);
if (!$game) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Game not started yet.']);
    exit;
}

$players = $repository->listPlayers($lobbyId);

echo json_encode([
    'success' => true,
    'lobby' => $lobby,
    'game' => $game,
    'players' => $players,
]);
exit;
