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
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

if ($method === 'GET') {
    $user = Auth::currentUser($repository);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    $lobbies = array_map(
        function ($lobby) use ($repository) {
            $players = $repository->listPlayers($lobby['id']);
            return [
                'id' => $lobby['id'],
                'code' => $lobby['code'],
                'owner_user_id' => $lobby['owner_user_id'],
                'status' => $lobby['status'],
                'players' => $players,
            ];
        },
        $repository->listLobbies()
    );

    echo json_encode(['success' => true, 'lobbies' => $lobbies]);
    exit;
}

$user = Auth::requireUser($repository);
$action = $payload['action'] ?? '';

if ($method === 'POST') {
    if ($action === 'create') {
        $code = trim((string) ($payload['code'] ?? ''));
        $lobbyId = $repository->createLobby($user['id'], $code === '' ? null : strtoupper($code));
        $repository->addPlayerToLobby($lobbyId, $user['id']);
        echo json_encode(['success' => true, 'lobby' => $repository->getLobbyById($lobbyId)]);
        exit;
    }

    if ($action === 'join') {
        $code = trim((string) ($payload['code'] ?? ''));
        $lobby = $repository->getLobbyByCode(strtoupper($code));
        if (!$lobby) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Lobby not found.']);
            exit;
        }
        if ($lobby['status'] === 'in_game') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Game already started for this lobby.']);
            exit;
        }
        $repository->addPlayerToLobby($lobby['id'], $user['id']);
        echo json_encode(['success' => true, 'lobby' => $repository->getLobbyById($lobby['id'])]);
        exit;
    }

    if ($action === 'ready') {
        $lobbyId = (int) ($payload['lobbyId'] ?? 0);
        $isReady = (bool) ($payload['ready'] ?? false);
        $lobby = $repository->getLobbyById($lobbyId);
        if (!$lobby) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Lobby not found.']);
            exit;
        }
        $repository->setReady($lobbyId, $user['id'], $isReady);

        if ($repository->playerCount($lobbyId) >= 2 && $repository->allReady($lobbyId) && !$repository->getGameByLobby($lobbyId)) {
            $players = $repository->listPlayers($lobbyId);
            $repository->updateLobbyStatus($lobbyId, 'in_game');
            $repository->createGame($lobbyId, $players);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'leave') {
        $lobbyId = (int) ($payload['lobbyId'] ?? 0);
        $repository->removePlayerFromLobby($lobbyId, $user['id']);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'start') {
        $lobbyId = (int) ($payload['lobbyId'] ?? 0);
        $lobby = $repository->getLobbyById($lobbyId);
        if (!$lobby) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Lobby not found.']);
            exit;
        }
        if ($lobby['owner_user_id'] !== $user['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only the owner can start the game manually.']);
            exit;
        }
        $players = $repository->listPlayers($lobbyId);
        if (count($players) < 2) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'At least two players are required.']);
            exit;
        }
        if (!$repository->allReady($lobbyId)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'All players must be ready.']);
            exit;
        }
        if (!$repository->getGameByLobby($lobbyId)) {
            $repository->updateLobbyStatus($lobbyId, 'in_game');
            $repository->createGame($lobbyId, $players);
        }
        echo json_encode(['success' => true]);
        exit;
    }
}

if ($method === 'DELETE') {
    if ($action === 'delete') {
        $lobbyId = (int) ($payload['lobbyId'] ?? 0);
        $owner = $repository->lobbyOwner($lobbyId);
        if ($owner !== $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only owners or admins can delete lobbies.']);
            exit;
        }
        $repository->deleteLobby($lobbyId);
        echo json_encode(['success' => true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unsupported action.']);
exit;
