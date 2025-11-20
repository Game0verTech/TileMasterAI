<?php

declare(strict_types=1);

namespace TileMasterAI\Server\Db;

use PDO;
use TileMasterAI\Game\Scoring;

require_once dirname(__DIR__, 2) . '/Game/Scoring.php';

class GameRepository
{
    private PDO $pdo;
    private string $driver;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::getPdo();
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function createSession(string $code, string $status = 'pending'): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (code, status) VALUES (:code, :status)'
        );
        $statement->execute([
            ':code' => $code,
            ':status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function getSessionByCode(string $code): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sessions WHERE code = :code LIMIT 1');
        $statement->execute([':code' => $code]);
        $session = $statement->fetch();

        return $session !== false ? $session : null;
    }

    public function getSessionById(int $sessionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sessions WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $sessionId]);
        $session = $statement->fetch();

        return $session !== false ? $session : null;
    }

    public function createPlayer(string $name, ?string $clientToken = null): int
    {
        $statement = $this->pdo->prepare('INSERT INTO players (name, client_token) VALUES (:name, :client_token)');
        $statement->execute([
            ':name' => $name,
            ':client_token' => $clientToken,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findPlayerByToken(string $clientToken): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM players WHERE client_token = :client_token LIMIT 1');
        $statement->execute([':client_token' => $clientToken]);
        $player = $statement->fetch();

        return $player !== false ? $player : null;
    }

    public function syncPlayerIdentity(string $name, string $clientToken): array
    {
        $existing = $this->findPlayerByToken($clientToken);

        if ($existing) {
            if ((string) $existing['name'] !== $name) {
                $update = $this->pdo->prepare('UPDATE players SET name = :name WHERE id = :id');
                $update->execute([
                    ':name' => $name,
                    ':id' => $existing['id'],
                ]);
                $existing['name'] = $name;
            }

            return [
                'id' => (int) $existing['id'],
                'name' => (string) $existing['name'],
                'client_token' => (string) $existing['client_token'],
            ];
        }

        $id = $this->createPlayer($name, $clientToken);

        return [
            'id' => $id,
            'name' => $name,
            'client_token' => $clientToken,
        ];
    }

    public function getPlayer(int $playerId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM players WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $playerId]);
        $player = $statement->fetch();

        return $player !== false ? $player : null;
    }

    public function addPlayerToSession(int $sessionId, int $playerId, int $joinOrder, bool $isHost = false): void
    {
        $sql = $this->driver === 'mysql'
            ? 'INSERT INTO session_players (session_id, player_id, join_order, is_host) '
                . 'VALUES (:session_id, :player_id, :join_order, :is_host) '
                . 'ON DUPLICATE KEY UPDATE join_order = VALUES(join_order), is_host = VALUES(is_host)'
            : 'INSERT OR REPLACE INTO session_players (session_id, player_id, join_order, is_host) '
                . 'VALUES (:session_id, :player_id, :join_order, :is_host)';

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':session_id' => $sessionId,
            ':player_id' => $playerId,
            ':join_order' => $joinOrder,
            ':is_host' => $isHost ? 1 : 0,
        ]);

        $this->touchSession($sessionId);
    }

    public function removePlayerFromSession(int $sessionId, int $playerId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM session_players WHERE session_id = :session_id AND player_id = :player_id');
        $statement->execute([
            ':session_id' => $sessionId,
            ':player_id' => $playerId,
        ]);

        $this->touchSession($sessionId);
    }

    public function countPlayersInSession(int $sessionId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) as total FROM session_players WHERE session_id = :session_id');
        $statement->execute([':session_id' => $sessionId]);
        $result = $statement->fetch();

        return isset($result['total']) ? (int) $result['total'] : 0;
    }

    /**
     * @return array<int, array{id: int, name: string, join_order: int, is_host: bool}>
     */
    public function listPlayersForSession(int $sessionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.name, sp.join_order, sp.is_host '
            . 'FROM session_players sp '
            . 'JOIN players p ON p.id = sp.player_id '
            . 'WHERE sp.session_id = :session_id '
            . 'ORDER BY sp.join_order ASC'
        );
        $statement->execute([':session_id' => $sessionId]);
        $players = $statement->fetchAll();

        return array_map(
            static fn ($player) => [
                'id' => (int) $player['id'],
                'name' => (string) $player['name'],
                'join_order' => (int) $player['join_order'],
                'is_host' => (bool) $player['is_host'],
            ],
            $players ?: []
        );
    }

    public function getPlayerSessionRole(int $sessionId, int $playerId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT join_order, is_host FROM session_players WHERE session_id = :session_id AND player_id = :player_id LIMIT 1'
        );
        $statement->execute([
            ':session_id' => $sessionId,
            ':player_id' => $playerId,
        ]);
        $result = $statement->fetch();

        if ($result === false) {
            return null;
        }

        return [
            'join_order' => (int) $result['join_order'],
            'is_host' => (bool) $result['is_host'],
        ];
    }

    public function getActiveSessionForPlayer(int $playerId, ?int $maxAgeMinutes = null): ?array
    {
        $clauses = ['sp.player_id = :player_id', 's.status IN ("pending", "active", "started")'];
        $params = [':player_id' => $playerId];

        if ($maxAgeMinutes !== null) {
            $minutes = max(1, (int) $maxAgeMinutes);
            $clauses[] = $this->driver === 'mysql'
                ? sprintf('s.updated_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)', $minutes)
                : 's.updated_at >= DATETIME("now", :age_cutoff)';

            if ($this->driver !== 'mysql') {
                $params[':age_cutoff'] = sprintf('-%d minutes', $minutes);
            }
        }

        $where = implode(' AND ', $clauses);

        $statement = $this->pdo->prepare(
            'SELECT s.* FROM sessions s '
            . 'JOIN session_players sp ON sp.session_id = s.id '
            . "WHERE {$where} "
            . 'ORDER BY s.updated_at DESC, s.created_at DESC LIMIT 1'
        );
        $statement->execute($params);
        $session = $statement->fetch();

        return $session !== false ? $session : null;
    }

    public function assignNextHost(int $sessionId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT player_id FROM session_players WHERE session_id = :session_id ORDER BY join_order ASC LIMIT 1'
        );
        $statement->execute([':session_id' => $sessionId]);
        $nextHost = $statement->fetch();

        if ($nextHost === false) {
            return;
        }

        $playerId = (int) $nextHost['player_id'];

        $reset = $this->pdo->prepare('UPDATE session_players SET is_host = 0 WHERE session_id = :session_id');
        $reset->execute([':session_id' => $sessionId]);

        $assign = $this->pdo->prepare(
            'UPDATE session_players SET is_host = 1 WHERE session_id = :session_id AND player_id = :player_id'
        );
        $assign->execute([
            ':session_id' => $sessionId,
            ':player_id' => $playerId,
        ]);

        $this->touchSession($sessionId);
    }

    public function setSessionStatus(int $sessionId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE sessions SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([
            ':status' => $status,
            ':id' => $sessionId,
        ]);
    }

    /**
     * @return array<int, array{id: int, code: string, status: string, player_count: int}>
     */
    public function listOpenSessionsWithCounts(?int $maxAgeMinutes = null): array
    {
        $clauses = ['s.status IN ("pending", "active")'];
        $params = [];

        if ($maxAgeMinutes !== null) {
            $minutes = max(1, (int) $maxAgeMinutes);
            $clauses[] = $this->driver === 'mysql'
                ? sprintf('s.updated_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)', $minutes)
                : 's.updated_at >= DATETIME("now", :age_cutoff)';

            if ($this->driver !== 'mysql') {
                $params[':age_cutoff'] = sprintf('-%d minutes', $minutes);
            }
        }

        $where = implode(' AND ', $clauses);

        $statement = $this->pdo->prepare(
            'SELECT s.id, s.code, s.status, COUNT(sp.id) as player_count '
            . 'FROM sessions s '
            . 'LEFT JOIN session_players sp ON sp.session_id = s.id '
            . "WHERE {$where} "
            . 'GROUP BY s.id, s.code, s.status '
            . 'ORDER BY s.updated_at DESC, s.created_at DESC'
        );

        $statement->execute($params);
        $sessions = $statement->fetchAll();

        return array_map(
            static fn ($session) => [
                'id' => (int) $session['id'],
                'code' => (string) $session['code'],
                'status' => (string) $session['status'],
                'player_count' => (int) $session['player_count'],
            ],
            $sessions ?: []
        );
    }

    /**
     * @param array<int, array<string, mixed>> $placements
     */
    public function recordTurn(
        int $sessionId,
        int $playerId,
        int $turnNumber,
        array $placements,
        int $score,
        string $action = 'play'
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO turns (session_id, player_id, turn_number, placements, score, action) '
            . 'VALUES (:session_id, :player_id, :turn_number, :placements, :score, :action)'
        );
        $statement->execute([
            ':session_id' => $sessionId,
            ':player_id' => $playerId,
            ':turn_number' => $turnNumber,
            ':placements' => json_encode($placements, JSON_THROW_ON_ERROR),
            ':score' => $score,
            ':action' => $action,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function saveSnapshot(
        int $sessionId,
        ?int $turnId,
        string $boardState,
        string $rackState,
        string $bagState,
        ?string $notes = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO game_state_snapshots (session_id, turn_id, board_state, rack_state, bag_state, notes) '
            . 'VALUES (:session_id, :turn_id, :board_state, :rack_state, :bag_state, :notes)'
        );
        $statement->execute([
            ':session_id' => $sessionId,
            ':turn_id' => $turnId,
            ':board_state' => $boardState,
            ':rack_state' => $rackState,
            ':bag_state' => $bagState,
            ':notes' => $notes,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function clearTileBag(int $sessionId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM tile_bag WHERE session_id = :session_id');
        $statement->execute([':session_id' => $sessionId]);
    }

    /**
     * @param array<int, array{letter: string, value: int, count: int}> $tileSet
     */
    public function seedTileBag(int $sessionId, array $tileSet): void
    {
        $this->clearTileBag($sessionId);

        $statement = $this->pdo->prepare(
            'INSERT INTO tile_bag (session_id, letter, value, state) VALUES (:session_id, :letter, :value, :state)'
        );

        foreach ($tileSet as $tile) {
            if ($tile['count'] < 1) {
                continue;
            }

            for ($i = 0; $i < $tile['count']; $i++) {
                $statement->execute([
                    ':session_id' => $sessionId,
                    ':letter' => strtoupper($tile['letter']),
                    ':value' => $tile['value'],
                    ':state' => 'bag',
                ]);
            }
        }
    }

    public function seedStandardTileBag(int $sessionId): void
    {
        $distribution = Scoring::tileDistribution();
        $tileSet = [];

        foreach ($distribution as $letter => $meta) {
            $tileSet[] = [
                'letter' => $letter,
                'value' => $meta['value'],
                'count' => $meta['count'],
            ];
        }

        $this->seedTileBag($sessionId, $tileSet);
    }

    public function countTilesInBag(int $sessionId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) AS total FROM tile_bag WHERE session_id = :session_id');
        $statement->execute([':session_id' => $sessionId]);
        $result = $statement->fetch();

        return isset($result['total']) ? (int) $result['total'] : 0;
    }

    /**
     * @return array<int, array{player: array{id: int, name: string}, tile: array{id: int, letter: string, value: int}, distance?: int, drawn_at?: string}>
     */
    public function listDrawnTiles(int $sessionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tb.id as tile_id, tb.letter, tb.value, tb.drawn_by, tb.drawn_at, p.name AS player_name '
            . 'FROM tile_bag tb '
            . 'LEFT JOIN players p ON p.id = tb.drawn_by '
            . 'WHERE tb.session_id = :session_id AND tb.state = "drawn" '
            . 'ORDER BY tb.drawn_at ASC'
        );

        $statement->execute([':session_id' => $sessionId]);
        $rows = $statement->fetchAll();

        return array_map(
            static fn ($row) => [
                'player' => [
                    'id' => (int) $row['drawn_by'],
                    'name' => (string) ($row['player_name'] ?? ''),
                ],
                'tile' => [
                    'id' => (int) $row['tile_id'],
                    'letter' => (string) $row['letter'],
                    'value' => (int) $row['value'],
                ],
                'drawn_at' => (string) ($row['drawn_at'] ?? ''),
            ],
            $rows ?: []
        );
    }

    public function drawTileFromBag(int $sessionId, ?int $playerId = null): ?array
    {
        $this->pdo->beginTransaction();

        $orderBy = $this->driver === 'mysql' ? 'RAND()' : 'RANDOM()';
        $select = $this->pdo->prepare(
            sprintf(
                'SELECT id, letter, value FROM tile_bag WHERE session_id = :session_id AND state = "bag" ORDER BY %s LIMIT 1',
                $orderBy
            )
        );
        $select->execute([':session_id' => $sessionId]);
        $tile = $select->fetch();

        if ($tile === false) {
            $this->pdo->commit();
            return null;
        }

        $update = $this->pdo->prepare(
            'UPDATE tile_bag SET state = "drawn", drawn_by = :drawn_by, drawn_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $update->execute([
            ':id' => $tile['id'],
            ':drawn_by' => $playerId,
        ]);
        $this->pdo->commit();

        return $tile;
    }

    public function recordTileReturn(int $tileId): void
    {
        $statement = $this->pdo->prepare('UPDATE tile_bag SET state = "bag", drawn_by = NULL, drawn_at = NULL WHERE id = :id');
        $statement->execute([':id' => $tileId]);
    }

    public function updateSessionStatus(int $sessionId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE sessions SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([
            ':status' => $status,
            ':id' => $sessionId,
        ]);
    }

    public function saveTurnState(int $sessionId, ?int $currentPlayerId, int $turnNumber, string $phase, array $sequence): void
    {
        $sql = $this->driver === 'mysql'
            ? 'INSERT INTO turn_states (session_id, current_player_id, turn_number, phase, sequence, updated_at) '
                . 'VALUES (:session_id, :current_player_id, :turn_number, :phase, :sequence, CURRENT_TIMESTAMP) '
                . 'ON DUPLICATE KEY UPDATE current_player_id = VALUES(current_player_id), turn_number = VALUES(turn_number), '
                . 'phase = VALUES(phase), sequence = VALUES(sequence), updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO turn_states (session_id, current_player_id, turn_number, phase, sequence, updated_at) '
                . 'VALUES (:session_id, :current_player_id, :turn_number, :phase, :sequence, CURRENT_TIMESTAMP) '
                . 'ON CONFLICT(session_id) DO UPDATE SET current_player_id = excluded.current_player_id, '
                . 'turn_number = excluded.turn_number, phase = excluded.phase, sequence = excluded.sequence, updated_at = CURRENT_TIMESTAMP';

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':session_id' => $sessionId,
            ':current_player_id' => $currentPlayerId,
            ':turn_number' => $turnNumber,
            ':phase' => $phase,
            ':sequence' => json_encode(array_values($sequence), JSON_THROW_ON_ERROR),
        ]);
    }

    public function getTurnState(int $sessionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM turn_states WHERE session_id = :session_id LIMIT 1');
        $statement->execute([':session_id' => $sessionId]);
        $state = $statement->fetch();

        if ($state === false) {
            return null;
        }

        return [
            'session_id' => (int) $state['session_id'],
            'current_player_id' => $state['current_player_id'] !== null ? (int) $state['current_player_id'] : null,
            'turn_number' => (int) $state['turn_number'],
            'phase' => (string) $state['phase'],
            'sequence' => json_decode((string) $state['sequence'], true) ?: [],
        ];
    }

    public function advanceTurnState(int $sessionId): ?array
    {
        $state = $this->getTurnState($sessionId);
        if (!$state || $state['sequence'] === []) {
            return null;
        }

        $sequence = array_values($state['sequence']);
        $current = $state['current_player_id'];
        $index = array_search($current, $sequence, true);
        $nextIndex = $index === false ? 0 : ($index + 1) % count($sequence);
        $nextPlayerId = (int) $sequence[$nextIndex];
        $turnNumber = $state['turn_number'] + 1;

        $this->saveTurnState($sessionId, $nextPlayerId, $turnNumber, 'idle', $sequence);

        return $this->getTurnState($sessionId);
    }

    public function deleteSession(int $sessionId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
        $statement->execute([':id' => $sessionId]);
    }

    public function purgeStaleSessions(int $maxAgeMinutes, array $statuses = ['pending', 'active']): int
    {
        if ($maxAgeMinutes < 1 || $statuses === []) {
            return 0;
        }

        $minutes = max(1, (int) $maxAgeMinutes);
        $statusList = implode(', ', array_fill(0, count($statuses), '?'));

        if ($this->driver === 'mysql') {
            $sql = sprintf(
                'DELETE FROM sessions WHERE status IN (%s) AND updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)',
                $statusList,
                $minutes
            );
            $params = $statuses;
        } else {
            $sql = sprintf(
                'DELETE FROM sessions WHERE status IN (%s) AND updated_at < DATETIME("now", ?)',
                $statusList
            );
            $params = array_merge($statuses, [sprintf('-%d minutes', $minutes)]);
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function touchSession(int $sessionId): void
    {
        $statement = $this->pdo->prepare('UPDATE sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([':id' => $sessionId]);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function countTilesInState(int $sessionId, string $state = 'bag'): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) as total FROM tile_bag WHERE session_id = :session_id AND state = :state'
        );
        $statement->execute([
            ':session_id' => $sessionId,
            ':state' => $state,
        ]);

        $result = $statement->fetch();

        return isset($result['total']) ? (int) $result['total'] : 0;
    }
}
