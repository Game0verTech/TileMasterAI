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
        $order = $this->determineTurnOrder($players);
        $statement = $this->pdo->prepare('INSERT INTO games (lobby_id, turn_order) VALUES (:lobby_id, :turn_order)');
        $statement->execute([
            ':lobby_id' => $lobbyId,
            ':turn_order' => json_encode($order, JSON_THROW_ON_ERROR),
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

        return [
            'id' => (int) $game['id'],
            'lobby_id' => (int) $game['lobby_id'],
            'turn_order' => json_decode((string) $game['turn_order'], true, 512, JSON_THROW_ON_ERROR),
            'started_at' => $game['started_at'] ?? null,
        ];
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

    private function determineTurnOrder(array $players): array
    {
        $bag = [];
        foreach (Scoring::tileDistribution() as $letter => $info) {
            $bag = array_merge($bag, array_fill(0, (int) $info['count'], $letter));
        }
        shuffle($bag);

        $draws = [];
        foreach ($players as $player) {
            $tile = array_pop($bag);
            $draws[] = [
                'user_id' => $player['user_id'],
                'username' => $player['username'],
                'tile' => $tile,
                'value' => Scoring::tileValue($tile),
            ];
        }

        usort($draws, static function ($a, $b) {
            if ($a['value'] === $b['value']) {
                return strcmp($a['tile'], $b['tile']);
            }
            return $b['value'] <=> $a['value'];
        });

        return array_map(static fn ($draw) => ['user_id' => $draw['user_id'], 'username' => $draw['username'], 'tile' => $draw['tile']], $draws);
    }
}
