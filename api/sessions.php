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
    echo json_encode([
        'success' => false,
        'message' => $message,
        'detail' => $detail,
    ]);
    exit;
};

$isAdminName = static function (string $name): bool {
    return strtolower(trim($name)) === 'tomadmin';
};

$hasSessionExpired = static function (?string $updatedAt, int $ttlMinutes) use ($sendError) {
    if (!$updatedAt) {
        return false;
    }

    $updated = strtotime($updatedAt);
    if ($updated === false) {
        return false;
    }

    $ageSeconds = time() - $updated;
    return $ageSeconds > ($ttlMinutes * 60);
};

$repository->purgeStaleSessions($sessionTtlMinutes);

try {
    if ($method === 'GET') {
        $sessions = $repository->listOpenSessionsWithCounts($sessionTtlMinutes);
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
            $sendError(400, 'Invalid JSON payload.');
        }

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $playerName = trim((string) ($payload['playerName'] ?? ''));
        $clientToken = trim((string) ($payload['clientToken'] ?? ''));

        if ($clientToken === '') {
            $clientToken = bin2hex(random_bytes(16));
        }

        if ($code === '' || $playerName === '') {
            $sendError(422, 'Session code and player name are required.');
        }

        if ($repository->getSessionByCode($code)) {
            $sendError(409, 'Session code already exists.');
        }

        $player = $repository->syncPlayerIdentity($playerName, $clientToken);
        $existingSession = $repository->getActiveSessionForPlayer($player['id'], $sessionTtlMinutes);

        if ($existingSession) {
            $sendError(
                409,
                'You are already seated in another session.',
                json_encode([
                    'id' => (int) $existingSession['id'],
                    'code' => (string) $existingSession['code'],
                    'status' => (string) $existingSession['status'],
                ], JSON_THROW_ON_ERROR)
            );
        }

        $sessionId = $repository->createSession($code, 'active');
        $currentCount = $repository->countPlayersInSession($sessionId);

        if ($currentCount >= $maxPlayers) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This session is already full.']);
            exit;
        }

        $repository->addPlayerToSession($sessionId, $player['id'], $currentCount + 1, true);

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
                    'id' => $player['id'],
                    'name' => $player['name'],
                    'client_token' => $player['client_token'],
                    'is_admin' => $isAdminName($player['name']),
                ],
            ],
            'player' => [
                'id' => $player['id'],
                'name' => $player['name'],
                'client_token' => $player['client_token'],
                'is_host' => true,
                'is_admin' => $isAdminName($player['name']),
            ],
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        $payload = json_decode(file_get_contents('php://input') ?: 'null', true);

        if (!is_array($payload)) {
            $sendError(400, 'Invalid JSON payload.');
        }

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $clientToken = trim((string) ($payload['clientToken'] ?? ''));
        $playerName = trim((string) ($payload['playerName'] ?? ''));

        if ($code === '' || $clientToken === '' || $playerName === '') {
            $sendError(422, 'Session code, player name, and client token are required.');
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

        $player = $repository->syncPlayerIdentity($playerName, $clientToken);
        $isAdmin = $isAdminName($player['name']);
        $role = $repository->getPlayerSessionRole($sessionId, $player['id']);

        if (!$isAdmin && (!$role || !$role['is_host'])) {
            $sendError(403, 'Only the host or TomAdmin can delete this session.');
        }

        $repository->deleteSession($sessionId);

        echo json_encode([
            'success' => true,
            'message' => 'Session deleted.',
            'session' => [
                'code' => $code,
                'id' => $sessionId,
            ],
        ]);
        exit;
    }

    http_response_code(405);
    header('Allow: GET, POST, DELETE');
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
