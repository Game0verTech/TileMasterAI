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

$sendError = static function (int $status, string $message, ?string $detail = null): void {
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'detail' => $detail,
    ]);
    exit;
};

$ensurePlayer = static function (GameRepository $repository, string $token, ?string $name = null): array {
    $player = $repository->getOrCreatePlayerByToken($token, $name);
    if ($name !== null && $name !== '' && $player['name'] !== $name) {
        $repository->updatePlayerName($player['id'], $name);
        $player['name'] = $name;
    }

    return $player;
};

try {
    $token = $_COOKIE['player_token'] ?? '';
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        setcookie('player_token', $token, time() + (86400 * 30), '/');
    }

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';

        if ($action === 'bootstrap') {
            $player = $ensurePlayer($repository, $token, null);
            $activeSession = $repository->getActiveSessionForPlayer($player['id'], 240);
            $lobbies = $repository->listLobbies();

            $active = null;
            if ($activeSession) {
                $players = $repository->listPlayersForSession((int) $activeSession['id']);
                $active = [
                    'id' => (int) $activeSession['id'],
                    'code' => (string) $activeSession['code'],
                    'name' => $activeSession['name'] ?? null,
                    'status' => (string) $activeSession['status'],
                    'players' => $players,
                    'turn_state' => $repository->getTurnState((int) $activeSession['id']),
                ];
            }

            echo json_encode([
                'success' => true,
                'player' => $player,
                'lobbies' => $lobbies,
                'activeLobby' => $active,
            ]);
            exit;
        }

        if ($action === 'detail') {
            $code = strtoupper(trim((string) ($_GET['code'] ?? '')));
            if ($code === '') {
                $sendError(422, 'Lobby code is required.');
            }

            $session = $repository->getSessionByCode($code);
            if (!$session) {
                $sendError(404, 'Lobby not found.');
            }

            $players = $repository->listPlayersForSession((int) $session['id']);

            echo json_encode([
                'success' => true,
                'lobby' => [
                    'id' => (int) $session['id'],
                    'code' => (string) $session['code'],
                    'name' => $session['name'] ?? null,
                    'status' => (string) $session['status'],
                ],
                'players' => $players,
                'turn_state' => $repository->getTurnState((int) $session['id']),
            ]);
            exit;
        }

        $lobbies = $repository->listLobbies();
        echo json_encode([
            'success' => true,
            'lobbies' => $lobbies,
        ]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];
        $action = $payload['action'] ?? 'create';
        $name = trim((string) ($payload['playerName'] ?? ''));
        $player = $ensurePlayer($repository, $token, $name ?: null);

        $respondWithLobby = static function (array $session, array $players, array $extras = []) {
            echo json_encode(array_merge([
                'success' => true,
                'lobby' => [
                    'id' => (int) $session['id'],
                    'code' => (string) $session['code'],
                    'name' => $session['name'] ?? null,
                    'status' => (string) $session['status'],
                ],
                'players' => $players,
            ], $extras));
            exit;
        };

        if ($action === 'create') {
            if ($name === '') {
                $sendError(422, 'Player name is required to create a lobby.');
            }

            $lobbyName = trim((string) ($payload['lobbyName'] ?? '')) ?: null;
            $code = strtoupper(trim((string) ($payload['code'] ?? '')));
            if ($code === '') {
                $code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            }

            if ($repository->getSessionByCode($code)) {
                $sendError(409, 'Lobby code already exists. Choose another.');
            }

            $active = $repository->getActiveSessionForPlayer($player['id'], 240);
            if ($active) {
                $sendError(409, 'You are already in another lobby.');
            }

            $sessionId = $repository->createSession($code, 'waiting', $lobbyName);
            $repository->addPlayerToSession($sessionId, $player['id'], 1, true);
            $players = $repository->listPlayersForSession($sessionId);

            $respondWithLobby([
                'id' => $sessionId,
                'code' => $code,
                'name' => $lobbyName,
                'status' => 'waiting',
            ], $players, ['player' => $player]);
        }

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        if ($code === '') {
            $sendError(422, 'Lobby code is required.');
        }

        $session = $repository->getSessionByCode($code);
        if (!$session) {
            $sendError(404, 'Lobby not found.');
        }
        $sessionId = (int) $session['id'];

        if ($action === 'join') {
            if ($name === '') {
                $sendError(422, 'Player name is required to join a lobby.');
            }

            $existing = $repository->getPlayerSessionRole($sessionId, $player['id']);
            if (!$existing && ($session['status'] ?? '') === 'in_game') {
                $sendError(409, 'This game has already started.');
            }

            if (!$existing) {
                $order = $repository->countPlayersInSession($sessionId) + 1;
                $repository->addPlayerToSession($sessionId, $player['id'], $order, $order === 1);
                $repository->resetReadyStates($sessionId);
            }

            $players = $repository->listPlayersForSession($sessionId);
            $respondWithLobby($session, $players, ['player' => $player]);
        }

        if ($action === 'leave') {
            $role = $repository->getPlayerSessionRole($sessionId, $player['id']);
            if ($role) {
                $repository->removePlayerFromSession($sessionId, $player['id']);
                $remaining = $repository->countPlayersInSession($sessionId);
                if ($remaining === 0) {
                    $repository->deleteSession($sessionId);
                    echo json_encode(['success' => true, 'message' => 'Lobby removed because it became empty.']);
                    exit;
                }

                if ($role['is_host']) {
                    $repository->assignNextHost($sessionId);
                }
            }

            $players = $repository->listPlayersForSession($sessionId);
            $respondWithLobby($session, $players);
        }

        if ($action === 'toggle_ready') {
            $isReady = (bool) ($payload['ready'] ?? false);
            $repository->setPlayerReady($sessionId, $player['id'], $isReady);

            $players = $repository->listPlayersForSession($sessionId);
            $allReady = count($players) >= 2 && array_reduce($players, static fn ($carry, $p) => $carry && ($p['is_ready'] ?? false), true);

            $extras = ['player' => $player, 'allReady' => $allReady];

            if ($allReady && ($session['status'] ?? '') !== 'in_game') {
                $order = $repository->startLobbyGame($sessionId, $players);
                $session['status'] = 'in_game';
                $extras['turn_order'] = $order;
                $extras['turn_state'] = $repository->getTurnState($sessionId);
            }

            $respondWithLobby($session, $players, $extras);
        }

        if ($action === 'set_name') {
            if ($name === '') {
                $sendError(422, 'Player name cannot be empty.');
            }
            $repository->updatePlayerName($player['id'], $name);
            $player['name'] = $name;

            echo json_encode(['success' => true, 'player' => $player]);
            exit;
        }

        $sendError(400, 'Unsupported action.');
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
} catch (Throwable $exception) {
    $logPath = ErrorLogger::log($exception, 'lobbies');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal error occurred. Please review ' . basename($logPath) . '.',
    ]);
    exit;
}
