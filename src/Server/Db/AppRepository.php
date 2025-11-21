<?php

declare(strict_types=1);

namespace TileMasterAI\Server\Db;

use PDO;
use TileMasterAI\Game\Scoring;

class AppRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::getPdo();
    }

    public function createUser(string $username, string $passwordHash, string $role = 'user'): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)'
        );
        $statement->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':role' => $role,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function getUserByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute([':username' => $username]);
        $user = $statement->fetch();

        return $user !== false ? $this->normalizeUser($user) : null;
    }

    public function getUserById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $user = $statement->fetch();

        return $user !== false ? $this->normalizeUser($user) : null;
    }

    public function listUsers(): array
    {
        $statement = $this->pdo->query('SELECT * FROM users ORDER BY created_at ASC');
        $rows = $statement->fetchAll() ?: [];

        return array_map(fn ($user) => $this->normalizeUser($user), $rows);
    }

    public function deleteUser(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute([':id' => $userId]);
    }

    public function userCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) as total FROM users');
        $row = $statement->fetch();

        return isset($row['total']) ? (int) $row['total'] : 0;
    }

    private function normalizeUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
            'password_hash' => (string) $user['password_hash'],
            'created_at' => $user['created_at'] ?? null,
        ];
    }

    public function createLobby(int $ownerId, ?string $code = null, string $status = 'waiting'): int
    {
        $code = $code ?: $this->generateLobbyCode();
        $statement = $this->pdo->prepare(
            'INSERT INTO lobbies (code, owner_user_id, status) VALUES (:code, :owner_user_id, :status)'
        );
        $statement->execute([
            ':code' => $code,
            ':owner_user_id' => $ownerId,
            ':status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateLobbyStatus(int $lobbyId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE lobbies SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([
            ':id' => $lobbyId,
            ':status' => $status,
        ]);
    }

    public function getLobbyByCode(string $code): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM lobbies WHERE code = :code LIMIT 1');
        $statement->execute([':code' => $code]);
        $lobby = $statement->fetch();

        return $lobby !== false ? $this->normalizeLobby($lobby) : null;
    }

    public function getLobbyById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM lobbies WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $lobby = $statement->fetch();

        return $lobby !== false ? $this->normalizeLobby($lobby) : null;
    }

    public function listLobbies(): array
    {
        $statement = $this->pdo->query('SELECT * FROM lobbies ORDER BY created_at DESC');
        $rows = $statement->fetchAll() ?: [];

        return array_map(fn ($row) => $this->normalizeLobby($row), $rows);
    }

    public function deleteLobby(int $lobbyId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM lobbies WHERE id = :id');
        $statement->execute([':id' => $lobbyId]);
    }

    public function addPlayerToLobby(int $lobbyId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO lobby_players (lobby_id, user_id, is_ready) VALUES (:lobby_id, :user_id, 0)'
            . ' ON CONFLICT(lobby_id, user_id) DO NOTHING'
        );
        $statement->execute([
            ':lobby_id' => $lobbyId,
            ':user_id' => $userId,
        ]);
        $this->touchLobby($lobbyId);
    }

    public function removePlayerFromLobby(int $lobbyId, int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM lobby_players WHERE lobby_id = :lobby_id AND user_id = :user_id');
        $statement->execute([
            ':lobby_id' => $lobbyId,
            ':user_id' => $userId,
        ]);
        $this->touchLobby($lobbyId);
    }

    public function setReady(int $lobbyId, int $userId, bool $ready): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE lobby_players SET is_ready = :ready WHERE lobby_id = :lobby_id AND user_id = :user_id'
        );
        $statement->execute([
            ':ready' => $ready ? 1 : 0,
            ':lobby_id' => $lobbyId,
            ':user_id' => $userId,
        ]);
        $this->touchLobby($lobbyId);
    }

    public function listPlayers(int $lobbyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT lp.*, u.username FROM lobby_players lp JOIN users u ON u.id = lp.user_id WHERE lp.lobby_id = :lobby_id ORDER BY lp.joined_at ASC'
        );
        $statement->execute([':lobby_id' => $lobbyId]);
        $rows = $statement->fetchAll() ?: [];

        return array_map(
            static fn ($row) => [
                'user_id' => (int) $row['user_id'],
                'username' => (string) $row['username'],
                'is_ready' => (bool) $row['is_ready'],
                'joined_at' => $row['joined_at'] ?? null,
            ],
            $rows
        );
    }

    public function playerCount(int $lobbyId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) as total FROM lobby_players WHERE lobby_id = :lobby_id');
        $statement->execute([':lobby_id' => $lobbyId]);
        $row = $statement->fetch();

        return isset($row['total']) ? (int) $row['total'] : 0;
    }

    public function allReady(int $lobbyId): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) as ready_count FROM lobby_players WHERE lobby_id = :lobby_id AND is_ready = 1');
        $statement->execute([':lobby_id' => $lobbyId]);
        $ready = $statement->fetch();
        $readyCount = isset($ready['ready_count']) ? (int) $ready['ready_count'] : 0;

        return $readyCount > 0 && $readyCount === $this->playerCount($lobbyId);
    }

    public function createGame(int $lobbyId, array $players): int
    {
        $bag = $this->buildTileBag();
        shuffle($bag);

        $statement = $this->pdo->prepare(
            'INSERT INTO games (lobby_id, turn_order, draw_pool, draws, board_state, racks, bag, current_turn_index) '
            . 'VALUES (:lobby_id, :turn_order, :draw_pool, :draws, :board_state, :racks, :bag, :turn_index)'
        );
        $statement->execute([
            ':lobby_id' => $lobbyId,
            ':turn_order' => json_encode([], JSON_THROW_ON_ERROR),
            ':draw_pool' => json_encode($bag, JSON_THROW_ON_ERROR),
            ':draws' => json_encode([], JSON_THROW_ON_ERROR),
            ':board_state' => json_encode([], JSON_THROW_ON_ERROR),
            ':racks' => json_encode((object) [], JSON_THROW_ON_ERROR),
            ':bag' => json_encode($bag, JSON_THROW_ON_ERROR),
            ':turn_index' => 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function getGameByLobby(int $lobbyId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM games WHERE lobby_id = :lobby_id LIMIT 1');
        $statement->execute([':lobby_id' => $lobbyId]);
        $game = $statement->fetch();
        if ($game === false) {
            return null;
        }

        $drawPool = isset($game['draw_pool']) ? json_decode((string) $game['draw_pool'], true, 512, JSON_THROW_ON_ERROR) : [];
        $draws = isset($game['draws']) ? json_decode((string) $game['draws'], true, 512, JSON_THROW_ON_ERROR) : [];
        $bag = isset($game['bag']) ? json_decode((string) $game['bag'], true, 512, JSON_THROW_ON_ERROR) : [];
        $scores = isset($game['scores']) ? json_decode((string) $game['scores'], true, 512, JSON_THROW_ON_ERROR) : [];
        $winnerUserId = $game['winner_user_id'] ?? null;
        $boardState = isset($game['board_state']) ? json_decode((string) $game['board_state'], true, 512, JSON_THROW_ON_ERROR) : [];
        $racks = isset($game['racks']) ? json_decode((string) $game['racks'], true, 512, JSON_THROW_ON_ERROR) : [];
        $turnOrder = json_decode((string) $game['turn_order'], true, 512, JSON_THROW_ON_ERROR);
        $currentTurnIndex = (int) ($game['current_turn_index'] ?? 0);

        $currentTurn = is_array($turnOrder) && isset($turnOrder[$currentTurnIndex])
            ? (int) ($turnOrder[$currentTurnIndex]['user_id'] ?? 0)
            : null;

        $normalizedRacks = $this->normalizeRackCollection($racks);

        $normalizedScores = [];
        foreach ($scores as $userId => $score) {
            $normalizedScores[(int) $userId] = (int) $score;
        }

        return [
            'id' => (int) $game['id'],
            'lobby_id' => (int) $game['lobby_id'],
            'turn_order' => is_array($turnOrder) ? $turnOrder : [],
            'started_at' => $game['started_at'] ?? null,
            'draw_pool' => is_array($drawPool) ? $drawPool : [],
            'draw_pool_remaining' => is_array($drawPool) ? count($drawPool) : 0,
            'draws' => is_array($draws) ? $draws : [],
            'board_state' => is_array($boardState) ? $boardState : [],
            'racks' => $normalizedRacks,
            'bag' => is_array($bag) ? $bag : [],
            'current_turn_index' => $currentTurnIndex,
            'current_turn_user_id' => $currentTurn,
            'scores' => $normalizedScores,
            'winner_user_id' => $winnerUserId ? (int) $winnerUserId : null,
            'status' => $game['status'] ?? 'active',
        ];
    }

    public function recordDraw(int $lobbyId, int $userId): array
    {
        $game = $this->getGameByLobby($lobbyId);
        if (!$game) {
            throw new \RuntimeException('Game not found for lobby.');
        }

        $players = $this->listPlayers($lobbyId);
        $already = array_filter($game['draws'], static fn ($draw) => (int) $draw['user_id'] === $userId);
        if ($already) {
            return ['tile' => current($already)['tile'], 'draws' => $game['draws'], 'turn_order' => $game['turn_order']];
        }

        $drawPool = $game['draw_pool'];
        if (!is_array($drawPool) || empty($drawPool)) {
            $drawPool = $this->buildTileBag();
            shuffle($drawPool);
        }

        $tile = array_shift($drawPool);
        if ($tile === null) {
            throw new \RuntimeException('No tiles remaining to draw.');
        }

        $user = $this->getUserById($userId);
        $draws = $game['draws'];
        $draws[] = [
            'user_id' => $userId,
            'username' => $user['username'] ?? 'Player',
            'tile' => $tile,
            'value' => Scoring::tileValue($tile),
            'revealed' => false,
            'revealed_at' => null,
        ];

        $this->persistGameDrawState($game['id'], $drawPool, $draws);

        $updated = $this->getGameByLobby($lobbyId);

        return [
            'tile' => $tile,
            'draws' => $updated['draws'],
            'turn_order' => $updated['turn_order'],
            'complete' => count($updated['draws']) === count($players),
        ];
    }

    public function revealDraw(int $lobbyId, int $userId): array
    {
        $game = $this->getGameByLobby($lobbyId);
        if (!$game) {
            throw new \RuntimeException('Game not found for lobby.');
        }

        $players = $this->listPlayers($lobbyId);
        $draws = $game['draws'];
        $updatedDraws = [];
        $changed = false;

        foreach ($draws as $entry) {
            if ((int) $entry['user_id'] === $userId) {
                $entry['revealed'] = true;
                $entry['revealed_at'] = date('c');
                $changed = true;
            }
            $updatedDraws[] = $entry;
        }

        if (!$changed) {
            throw new \RuntimeException('Draw not found to reveal.');
        }

        $this->persistGameDrawState($game['id'], $game['draw_pool'], $updatedDraws);

        $allRevealed = count($updatedDraws) === count($players)
            && array_reduce($updatedDraws, static fn ($carry, $entry) => $carry && ($entry['revealed'] ?? false), true);

        if ($allRevealed) {
            $order = $this->determineTurnOrderFromDraws($updatedDraws);
            $this->finalizeGameStart($game['id'], $order, $game['draw_pool'], $lobbyId);
        }

        return $this->getGameByLobby($lobbyId) ?? [];
    }

    public function updateGameState(
        int $lobbyId,
        array $boardState,
        array $racks,
        array $bag,
        int $turnIndex,
        ?int $scoreDelta = null,
        ?int $actingUserId = null,
        string $action = 'sync'
    ): array
    {
        $game = $this->getGameByLobby($lobbyId);
        if (!$game) {
            throw new \RuntimeException('Game not found');
        }

        $orderCount = count($game['turn_order'] ?? []);
        if ($orderCount > 0) {
            $turnIndex = $turnIndex % $orderCount;
            if ($turnIndex < 0) {
                $turnIndex = 0;
            }
        }

        $scores = is_array($game['scores'] ?? null)
            ? $game['scores']
            : (json_decode((string) ($game['scores'] ?? '{}'), true) ?: []);

        if ($scoreDelta !== null && $actingUserId !== null) {
            $scores[$actingUserId] = ($scores[$actingUserId] ?? 0) + $scoreDelta;
        }

        $racks = $this->normalizeRackCollection($racks);

        $winnerUserId = $game['winner_user_id'] ?? null;
        $status = $game['status'] ?? 'active';
        $completedAt = $game['completed_at'] ?? null;

        if ($actingUserId !== null) {
            $rackLetters = $this->rackTiles($racks[$actingUserId] ?? []);
            $bagEmpty = count($bag) === 0;
            if (is_array($rackLetters) && count($rackLetters) === 0 && $bagEmpty) {
                $winnerUserId = $this->determineHighestScorer($scores) ?? $actingUserId;
                $status = 'complete';
                $completedAt = date('c');
            }
        }

        if ($status === 'complete' && !$winnerUserId) {
            $winnerUserId = $this->determineHighestScorer($scores);
        }

        $statement = $this->pdo->prepare(
            'UPDATE games SET board_state = :board_state, racks = :racks, bag = :bag, current_turn_index = :turn_index, '
            . 'scores = :scores, winner_user_id = :winner_user_id, completed_at = :completed_at, status = :status WHERE id = :id'
        );
        $statement->execute([
            ':id' => $game['id'],
            ':board_state' => json_encode(array_values($boardState), JSON_THROW_ON_ERROR),
            ':racks' => json_encode($racks, JSON_THROW_ON_ERROR),
            ':bag' => json_encode(array_values($bag), JSON_THROW_ON_ERROR),
            ':turn_index' => $turnIndex,
            ':scores' => json_encode($scores, JSON_THROW_ON_ERROR),
            ':winner_user_id' => $winnerUserId,
            ':completed_at' => $completedAt,
            ':status' => $status,
        ]);

        return $this->getGameByLobby($lobbyId) ?? [];
    }

    public function passTurn(int $lobbyId, int $userId): array
    {
        $game = $this->getGameByLobby($lobbyId);
        if (!$game) {
            throw new \RuntimeException('Game not found');
        }

        $turnOrder = $game['turn_order'] ?? [];
        $currentIndex = (int) ($game['current_turn_index'] ?? 0);
        $current = $turnOrder[$currentIndex]['user_id'] ?? null;
        if ($current !== $userId) {
            throw new \RuntimeException('Only the active player can pass.');
        }

        $nextIndex = $turnOrder ? ($currentIndex + 1) % count($turnOrder) : 0;

        return $this->updateGameState($lobbyId, $game['board_state'], $game['racks'], $game['bag'], $nextIndex, null, $userId, 'pass');
    }

    public function exchangeRack(int $lobbyId, int $userId): array
    {
        $game = $this->getGameByLobby($lobbyId);
        if (!$game) {
            throw new \RuntimeException('Game not found');
        }

        $turnOrder = $game['turn_order'] ?? [];
        $currentIndex = (int) ($game['current_turn_index'] ?? 0);
        $current = $turnOrder[$currentIndex]['user_id'] ?? null;
        if ($current !== $userId) {
            throw new \RuntimeException('Only the active player can exchange.');
        }

        $racks = $this->normalizeRackCollection($game['racks']);
        $bag = $game['bag'];
        $playerRack = $this->rackTiles($racks[$userId] ?? []);
        $playerTokens = $this->rackTokens($racks[$userId] ?? []);

        if (count($bag) < count($playerRack)) {
            throw new \RuntimeException('Not enough tiles in the bag to exchange.');
        }

        foreach ($playerRack as $letter) {
            $bag[] = $letter;
        }
        shuffle($bag);

        $newRack = [];
        for ($i = 0; $i < 7; $i++) {
            $tile = array_shift($bag);
            if ($tile === null) {
                break;
            }
            $newRack[] = $tile;
        }

        $racks[$userId] = $this->wrapRack($newRack, $playerTokens);
        $nextIndex = $turnOrder ? ($currentIndex + 1) % count($turnOrder) : 0;

        return $this->updateGameState($lobbyId, $game['board_state'], $racks, $bag, $nextIndex, null, $userId, 'exchange');
    }

    public function resetGame(int $lobbyId): array
    {
        $game = $this->getGameByLobby($lobbyId);
        if (!$game) {
            throw new \RuntimeException('Game not found for lobby.');
        }

        $bag = $this->buildTileBag();
        shuffle($bag);

        $scores = [];
        $racks = [];
        $turnOrder = $game['turn_order'] ?? [];
        $players = $turnOrder ?: array_map(
            static fn ($player) => ['user_id' => $player['user_id'] ?? $player['id'], 'username' => $player['username'] ?? 'Player'],
            $this->listPlayers($lobbyId)
        );

        foreach ($players as $entry) {
            $rack = [];
            $scores[$entry['user_id']] = 0;
            for ($i = 0; $i < 7; $i++) {
                $tile = array_shift($bag);
                if ($tile === null) {
                    break;
                }
                $rack[] = $tile;
            }
            $racks[$entry['user_id']] = $this->wrapRack($rack, 1);
        }

        $statement = $this->pdo->prepare(
            'UPDATE games SET '
            . 'turn_order = :turn_order, draw_pool = :draw_pool, draws = :draws, board_state = :board_state, racks = :racks, '
            . 'bag = :bag, current_turn_index = 0, scores = :scores, winner_user_id = NULL, completed_at = NULL, status = :status '
            . 'WHERE id = :id'
        );
        $statement->execute([
            ':id' => $game['id'],
            ':turn_order' => json_encode(array_values($players), JSON_THROW_ON_ERROR),
            ':draw_pool' => json_encode(array_values($bag), JSON_THROW_ON_ERROR),
            ':draws' => json_encode([], JSON_THROW_ON_ERROR),
            ':board_state' => json_encode([], JSON_THROW_ON_ERROR),
            ':racks' => json_encode($racks, JSON_THROW_ON_ERROR),
            ':bag' => json_encode(array_values($bag), JSON_THROW_ON_ERROR),
            ':scores' => json_encode($scores, JSON_THROW_ON_ERROR),
            ':status' => 'active',
        ]);

        $this->updateLobbyStatus($lobbyId, 'active');

        return $this->getGameByLobby($lobbyId) ?? [];
    }

    public function listSessions(): array
    {
        $statement = $this->pdo->query('SELECT * FROM active_sessions ORDER BY created_at DESC');
        $rows = $statement->fetchAll() ?: [];

        return array_map(
            static fn ($row) => [
                'id' => (string) $row['id'],
                'user_id' => (int) $row['user_id'],
                'created_at' => $row['created_at'] ?? null,
                'last_seen' => $row['last_seen'] ?? null,
            ],
            $rows
        );
    }

    public function recordSession(string $sessionId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO active_sessions (id, user_id) VALUES (:id, :user_id) '
            . 'ON CONFLICT(id) DO UPDATE SET user_id = excluded.user_id, last_seen = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
        ]);
    }

    public function deleteSessionById(string $sessionId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM active_sessions WHERE id = :id');
        $statement->execute([':id' => $sessionId]);
    }

    public function touchSession(string $sessionId): void
    {
        $statement = $this->pdo->prepare('UPDATE active_sessions SET last_seen = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([':id' => $sessionId]);
    }

    public function deleteAllSessionsForUser(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM active_sessions WHERE user_id = :user_id');
        $statement->execute([':user_id' => $userId]);
    }

    public function deleteSessionByUser(int $userId): void
    {
        $this->deleteAllSessionsForUser($userId);
    }

    public function lobbyOwner(int $lobbyId): ?int
    {
        $statement = $this->pdo->prepare('SELECT owner_user_id FROM lobbies WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $lobbyId]);
        $owner = $statement->fetchColumn();
        return $owner !== false ? (int) $owner : null;
    }

    private function normalizeLobby(array $lobby): array
    {
        return [
            'id' => (int) $lobby['id'],
            'code' => (string) $lobby['code'],
            'owner_user_id' => (int) $lobby['owner_user_id'],
            'status' => (string) $lobby['status'],
            'created_at' => $lobby['created_at'] ?? null,
            'updated_at' => $lobby['updated_at'] ?? null,
        ];
    }

    private function generateLobbyCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function touchLobby(int $lobbyId): void
    {
        $statement = $this->pdo->prepare('UPDATE lobbies SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([':id' => $lobbyId]);
    }

    private function buildTileBag(): array
    {
        $bag = [];
        foreach (Scoring::tileDistribution() as $letter => $info) {
            $bag = array_merge($bag, array_fill(0, (int) $info['count'], $letter));
        }

        return $bag;
    }

    private function determineTurnOrderFromDraws(array $draws): array
    {
        usort($draws, static function ($a, $b) {
            if (($a['value'] ?? 0) === ($b['value'] ?? 0)) {
                return strcmp((string) $a['tile'], (string) $b['tile']);
            }
            return ($b['value'] ?? 0) <=> ($a['value'] ?? 0);
        });

        return array_map(static fn ($draw) => ['user_id' => $draw['user_id'], 'username' => $draw['username'], 'tile' => $draw['tile']], $draws);
    }

    private function persistGameDrawState(int $gameId, array $pool, array $draws): void
    {
        $statement = $this->pdo->prepare('UPDATE games SET draw_pool = :draw_pool, draws = :draws WHERE id = :id');
        $statement->execute([
            ':id' => $gameId,
            ':draw_pool' => json_encode(array_values($pool), JSON_THROW_ON_ERROR),
            ':draws' => json_encode(array_values($draws), JSON_THROW_ON_ERROR),
        ]);
    }

    private function normalizeRackEntry(array $entry): array
    {
        if (array_key_exists('tiles', $entry)) {
            $tiles = is_array($entry['tiles']) ? array_values($entry['tiles']) : [];
            $tokens = isset($entry['tokens']) ? (int) $entry['tokens'] : 0;

            return [
                'tiles' => $tiles,
                'tokens' => $tokens,
            ];
        }

        return [
            'tiles' => array_values($entry),
            'tokens' => 0,
        ];
    }

    private function normalizeRackCollection(array $racks): array
    {
        $normalized = [];
        foreach ($racks as $userId => $entry) {
            $normalized[(int) $userId] = $this->normalizeRackEntry(is_array($entry) ? $entry : []);
        }

        return $normalized;
    }

    private function rackTiles(array $rackEntry): array
    {
        return array_values($rackEntry['tiles'] ?? []);
    }

    private function rackTokens(array $rackEntry): int
    {
        return isset($rackEntry['tokens']) ? (int) $rackEntry['tokens'] : 0;
    }

    private function wrapRack(array $tiles, int $tokens = 0): array
    {
        return [
            'tiles' => array_values($tiles),
            'tokens' => $tokens,
        ];
    }

    private function determineHighestScorer(array $scores): ?int
    {
        if (empty($scores)) {
            return null;
        }

        arsort($scores);
        $leaderId = array_key_first($scores);

        return $leaderId !== null ? (int) $leaderId : null;
    }

    private function finalizeGameStart(int $gameId, array $order, array $remainingPool, int $lobbyId): void
    {
        $bag = $remainingPool;
        $racks = [];
        $scores = [];

        foreach ($order as $entry) {
            $rack = [];
            $scores[$entry['user_id']] = 0;
            for ($i = 0; $i < 7; $i++) {
                $tile = array_shift($bag);
                if ($tile === null) {
                    break;
                }
                $rack[] = $tile;
            }
            $racks[$entry['user_id']] = $this->wrapRack($rack, 1);
        }

        $statement = $this->pdo->prepare(
            'UPDATE games SET turn_order = :turn_order, board_state = :board_state, racks = :racks, bag = :bag, current_turn_index = 0, scores = :scores, status = :status WHERE id = :id'
        );
        $statement->execute([
            ':id' => $gameId,
            ':turn_order' => json_encode($order, JSON_THROW_ON_ERROR),
            ':board_state' => json_encode([], JSON_THROW_ON_ERROR),
            ':racks' => json_encode($racks, JSON_THROW_ON_ERROR),
            ':bag' => json_encode(array_values($bag), JSON_THROW_ON_ERROR),
            ':scores' => json_encode($scores, JSON_THROW_ON_ERROR),
            ':status' => 'active',
        ]);

        $this->updateLobbyStatus($lobbyId, 'in_game');
    }
}
