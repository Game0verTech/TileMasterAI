<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/Server/Db/Connection.php';
require dirname(__DIR__) . '/src/Server/Db/GameRepository.php';
require dirname(__DIR__) . '/src/Server/Support/ErrorLogger.php';

use TileMasterAI\Server\Db\GameRepository;
use TileMasterAI\Server\Support\ErrorLogger;

header('Content-Type: application/json');

$repository = new GameRepository();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$maxPlayers = 4;

try {
    if ($method === 'GET') {
        $sessions = $repository->listOpenSessionsWithCounts();
        echo json_encode([
            'success' => true,
            'sessions' => $sessions,
            'message' => $sessions === [] ? 'No sessions available.' : 'OK',
        ]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input') ?: 'null', true);

        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
            exit;
        }

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $playerName = trim((string) ($payload['playerName'] ?? ''));

        if ($code === '' || $playerName === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Session code and player name are required.']);
            exit;
        }

        if ($repository->getSessionByCode($code)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Session code already exists.']);
            exit;
        }

        $sessionId = $repository->createSession($code, 'active');
        $playerId = $repository->createPlayer($playerName);
        $currentCount = $repository->countPlayersInSession($sessionId);

        if ($currentCount >= $maxPlayers) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This session is already full.']);
            exit;
        }

        $repository->addPlayerToSession($sessionId, $playerId, $currentCount + 1, true);

        echo json_encode([
            'success' => true,
            'message' => 'Session created successfully.',
            'session' => [
                'id' => $sessionId,
                'code' => $code,
                'status' => 'active',
                'player_count' => $currentCount + 1,
                'max_players' => $maxPlayers,
                'host' => [
                    'id' => $playerId,
                    'name' => $playerName,
                ],
            ],
        ]);
        exit;
    }

    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
} catch (Throwable $exception) {
    $logPath = ErrorLogger::log($exception, 'sessions');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal error occurred. Please review ' . basename($logPath) . '.',
    ]);
    exit;
}
