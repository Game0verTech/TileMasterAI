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
$payload = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];

$lobbyId = (int) ($method === 'GET' ? ($_GET['lobbyId'] ?? 0) : ($payload['lobbyId'] ?? 0));
$lobby = $repository->getLobbyById($lobbyId);
if (!$lobby) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Lobby not found.']);
    exit;
}

if ($method === 'POST') {
    $action = $payload['action'] ?? '';
    if ($action === 'draw') {
        if ($lobby['status'] !== 'drawing') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Lobby not accepting draws.']);
            exit;
        }
        $players = $repository->listPlayers($lobbyId);
        $isMember = array_filter($players, static fn ($p) => (int) $p['user_id'] === $user['id']);
        if (!$isMember) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only lobby players can draw.']);
            exit;
        }

        try {
            $result = $repository->recordDraw($lobbyId, $user['id']);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

        $game = $repository->getGameByLobby($lobbyId);
        echo json_encode(['success' => true, 'result' => $result, 'game' => $game]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported game action.']);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
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
