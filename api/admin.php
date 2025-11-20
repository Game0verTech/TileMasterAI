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

if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

if ($method === 'GET') {
    $users = $repository->listUsers();
    $lobbies = array_map(
        function ($lobby) use ($repository) {
            return [
                'id' => $lobby['id'],
                'code' => $lobby['code'],
                'owner_user_id' => $lobby['owner_user_id'],
                'status' => $lobby['status'],
                'players' => $repository->listPlayers($lobby['id']),
            ];
        },
        $repository->listLobbies()
    );
    $sessions = $repository->listSessions();

    echo json_encode(['success' => true, 'users' => $users, 'lobbies' => $lobbies, 'sessions' => $sessions]);
    exit;
}

if ($method === 'DELETE') {
    $action = $payload['action'] ?? '';
    if ($action === 'user') {
        $userId = (int) ($payload['userId'] ?? 0);
        $repository->deleteUser($userId);
        $repository->deleteAllSessionsForUser($userId);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'lobby') {
        $lobbyId = (int) ($payload['lobbyId'] ?? 0);
        $repository->deleteLobby($lobbyId);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'session') {
        $sessionId = (string) ($payload['sessionId'] ?? '');
        if ($sessionId !== '') {
            $repository->deleteSessionById($sessionId);
            echo json_encode(['success' => true]);
            exit;
        }
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unsupported admin action.']);
exit;
