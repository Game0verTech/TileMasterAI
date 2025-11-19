<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/Server/Db/Connection.php';
require dirname(__DIR__) . '/src/Server/Db/GameRepository.php';

use TileMasterAI\Server\Db\GameRepository;

header('Content-Type: application/json');

$repository = new GameRepository();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$maxPlayers = 4;

$sendError = static function (int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
};

try {
    if ($method === 'GET') {
        $code = strtoupper(trim((string) ($_GET['code'] ?? '')));

        if ($code === '') {
            $sendError(422, 'Session code is required.');
        }

        $session = $repository->getSessionByCode($code);

        if (!$session) {
            $sendError(404, 'Session not found.');
        }

        $players = $repository->listPlayersForSession((int) $session['id']);

        echo json_encode([
            'success' => true,
            'session' => [
                'id' => (int) $session['id'],
                'code' => $session['code'],
                'status' => $session['status'],
                'max_players' => $maxPlayers,
                'player_count' => count($players),
            ],
            'players' => $players,
        ]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input') ?: 'null', true);

        if (!is_array($payload)) {
            $sendError(400, 'Invalid JSON payload.');
        }

        $action = $payload['action'] ?? 'join';
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));

        if ($code === '') {
            $sendError(422, 'Session code is required.');
        }

        $session = $repository->getSessionByCode($code);

        if (!$session) {
            $sendError(404, 'Session not found.');
        }

        $sessionId = (int) $session['id'];

        if ($action === 'start') {
            $playerId = (int) ($payload['playerId'] ?? 0);
            $role = $repository->getPlayerSessionRole($sessionId, $playerId);

            if (($session['status'] ?? '') === 'started') {
                $sendError(409, 'Game already started.');
            }

            if (!$role || !$role['is_host']) {
                $sendError(403, 'Only the host can start the game.');
            }

            $players = $repository->listPlayersForSession($sessionId);

            if (count($players) < 2) {
                $sendError(422, 'Need at least two players to start.');
            }

            $repository->setSessionStatus($sessionId, 'started');

            echo json_encode([
                'success' => true,
                'message' => 'Game started.',
                'session' => [
                    'id' => $sessionId,
                    'code' => $session['code'],
                    'status' => 'started',
                    'player_count' => count($players),
                    'max_players' => $maxPlayers,
                ],
            ]);
            exit;
        }

        $playerName = trim((string) ($payload['playerName'] ?? ''));

        if ($playerName === '') {
            $sendError(422, 'Player name is required.');
        }

        if (($session['status'] ?? '') === 'started') {
            $sendError(409, 'This game is already underway.');
        }

        $currentCount = $repository->countPlayersInSession($sessionId);

        if ($currentCount >= $maxPlayers) {
            $sendError(409, 'This session is already full.');
        }

        $playerId = $repository->createPlayer($playerName);
        $repository->addPlayerToSession($sessionId, $playerId, $currentCount + 1, false);

        $players = $repository->listPlayersForSession($sessionId);

        echo json_encode([
            'success' => true,
            'message' => 'Joined session.',
            'session' => [
                'id' => $sessionId,
                'code' => $session['code'],
                'status' => $session['status'],
                'player_count' => count($players),
                'max_players' => $maxPlayers,
            ],
            'player' => [
                'id' => $playerId,
                'name' => $playerName,
            ],
            'players' => $players,
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        $payload = json_decode(file_get_contents('php://input') ?: 'null', true);

        if (!is_array($payload)) {
            $sendError(400, 'Invalid JSON payload.');
        }

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $playerId = (int) ($payload['playerId'] ?? 0);

        if ($code === '' || $playerId < 1) {
            $sendError(422, 'Session code and player ID are required.');
        }

        $session = $repository->getSessionByCode($code);

        if (!$session) {
            $sendError(404, 'Session not found.');
        }

        $sessionId = (int) $session['id'];
        $role = $repository->getPlayerSessionRole($sessionId, $playerId);

        if (!$role) {
            $sendError(404, 'Player is not part of this session.');
        }

        $repository->removePlayerFromSession($sessionId, $playerId);

        if ($role['is_host']) {
            $repository->assignNextHost($sessionId);
        }

        $players = $repository->listPlayersForSession($sessionId);

        echo json_encode([
            'success' => true,
            'message' => 'Left session.',
            'session' => [
                'id' => $sessionId,
                'code' => $session['code'],
                'status' => $session['status'],
                'player_count' => count($players),
                'max_players' => $maxPlayers,
            ],
            'players' => $players,
        ]);
        exit;
    }

    http_response_code(405);
    header('Allow: GET, POST, DELETE');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $exception->getMessage(),
    ]);
    exit;
}
