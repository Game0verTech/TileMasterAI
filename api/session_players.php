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
$sessionTtlMinutes = 120;

$sendError = static function (int $status, string $message, ?string $detail = null): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message, 'detail' => $detail]);
    exit;
};

$isAdminName = static function (string $name): bool {
    return strtolower(trim($name)) === 'tomadmin';
};

$hasSessionExpired = static function (?string $updatedAt, int $ttlMinutes): bool {
    if (!$updatedAt) {
        return false;
    }

    $timestamp = strtotime($updatedAt);

    if ($timestamp === false) {
        return false;
    }

    return (time() - $timestamp) > ($ttlMinutes * 60);
};

$repository->purgeStaleSessions($sessionTtlMinutes);

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

        if ($hasSessionExpired($session['updated_at'] ?? null, $sessionTtlMinutes)) {
            $repository->deleteSession((int) $session['id']);
            $sendError(410, 'Session expired and was removed.');
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
            'turn_state' => $repository->getTurnState((int) $session['id']),
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
        $clientToken = trim((string) ($payload['clientToken'] ?? ''));

        if ($clientToken === '') {
            $clientToken = bin2hex(random_bytes(16));
        }

        if ($code === '') {
            $sendError(422, 'Session code is required.');
        }

        $session = $repository->getSessionByCode($code);

        if (!$session) {
            $sendError(404, 'Session not found.');
        }

        if ($hasSessionExpired($session['updated_at'] ?? null, $sessionTtlMinutes)) {
            $repository->deleteSession((int) $session['id']);
            $sendError(410, 'Session expired and was removed.');
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

        if ($action === 'rejoin') {
            $player = $repository->findPlayerByToken($clientToken);

            if (!$player) {
                $sendError(404, 'No saved player found for this browser.');
            }

            $role = $repository->getPlayerSessionRole($sessionId, (int) $player['id']);

            if (!$role) {
                $sendError(404, 'Saved player is not part of this session.');
            }

            $repository->touchSession($sessionId);

            $players = $repository->listPlayersForSession($sessionId);

            echo json_encode([
                'success' => true,
                'message' => 'Session restored.',
                'session' => [
                    'id' => $sessionId,
                    'code' => $session['code'],
                    'status' => $session['status'],
                    'player_count' => count($players),
                    'max_players' => $maxPlayers,
                ],
                'player' => [
                    'id' => (int) $player['id'],
                    'name' => (string) $player['name'],
                    'client_token' => (string) $player['client_token'],
                    'is_host' => (bool) ($role['is_host'] ?? false),
                    'join_order' => (int) ($role['join_order'] ?? 0),
                    'is_admin' => $isAdminName((string) $player['name']),
                ],
                'players' => $players,
                'turn_state' => $repository->getTurnState($sessionId),
            ]);
            exit;
        }

        $playerName = trim((string) ($payload['playerName'] ?? ''));

        if ($playerName === '') {
            $sendError(422, 'Player name is required.');
        }

        $player = $repository->syncPlayerIdentity($playerName, $clientToken);
        $activeSession = $repository->getActiveSessionForPlayer($player['id'], $sessionTtlMinutes);

        if ($activeSession && (int) $activeSession['id'] !== $sessionId) {
            $sendError(
                409,
                'You are already in another active session. Leave it before joining a new one.',
                json_encode([
                    'id' => (int) $activeSession['id'],
                    'code' => (string) $activeSession['code'],
                    'status' => (string) $activeSession['status'],
                ], JSON_THROW_ON_ERROR)
            );
        }

        $role = $repository->getPlayerSessionRole($sessionId, $player['id']);

        if ($role) {
            $players = $repository->listPlayersForSession($sessionId);

            echo json_encode([
                'success' => true,
                'message' => 'Rejoined session.',
                'session' => [
                    'id' => $sessionId,
                    'code' => $session['code'],
                    'status' => $session['status'],
                    'player_count' => count($players),
                    'max_players' => $maxPlayers,
                ],
                'player' => [
                    'id' => $player['id'],
                    'name' => $player['name'],
                    'client_token' => $player['client_token'],
                    'is_host' => (bool) $role['is_host'],
                    'join_order' => (int) $role['join_order'],
                    'is_admin' => $isAdminName($player['name']),
                ],
                'players' => $players,
            ]);
            exit;
        }

        if (($session['status'] ?? '') === 'started') {
            $sendError(409, 'This game is already underway.');
        }

        $currentCount = $repository->countPlayersInSession($sessionId);

        if ($currentCount >= $maxPlayers) {
            $sendError(409, 'This session is already full.');
        }

        $repository->addPlayerToSession($sessionId, $player['id'], $currentCount + 1, false);

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
                'id' => $player['id'],
                'name' => $player['name'],
                'client_token' => $player['client_token'],
                'is_admin' => $isAdminName($player['name']),
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

        if ($hasSessionExpired($session['updated_at'] ?? null, $sessionTtlMinutes)) {
            $repository->deleteSession($sessionId);
            $sendError(410, 'Session expired and was removed.');
        }
        $role = $repository->getPlayerSessionRole($sessionId, $playerId);

        if (!$role) {
            $sendError(404, 'Player is not part of this session.');
        }

        $repository->removePlayerFromSession($sessionId, $playerId);

        $remaining = $repository->countPlayersInSession($sessionId);

        if ($remaining === 0) {
            $repository->deleteSession($sessionId);

            echo json_encode([
                'success' => true,
                'message' => 'Left session. Lobby removed because it was empty.',
                'session' => [
                    'id' => $sessionId,
                    'code' => $session['code'],
                    'status' => 'closed',
                    'player_count' => 0,
                    'max_players' => $maxPlayers,
                ],
                'players' => [],
            ]);
            exit;
        }

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
    $logPath = ErrorLogger::log($exception, 'session_players');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal error occurred. Please review ' . basename($logPath) . '.',
    ]);
    exit;
}
