<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Server/Db/Connection.php';
require dirname(__DIR__) . '/src/Server/Db/GameRepository.php';

use TileMasterAI\Server\Db\GameRepository;

$host = getenv('LOBBY_WS_HOST') ?: '0.0.0.0';
$port = (int) (getenv('LOBBY_WS_PORT') ?: 8090);
$maxPlayers = 4;
$sessionTtlMinutes = 120;

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

if (!$server) {
    fwrite(STDERR, "Unable to start WebSocket server: {$errstr}\n");
    exit(1);
}

stream_set_blocking($server, false);

$clients = [];
$repository = new GameRepository();

$encodeFrame = static function (string $payload): string {
    $frame = chr(0x81);
    $length = strlen($payload);

    if ($length <= 125) {
        $frame .= chr($length);
    } elseif ($length < 65536) {
        $frame .= chr(126) . pack('n', $length);
    } else {
        $frame .= chr(127) . pack('NN', 0, $length);
    }

    return $frame . $payload;
};

$decodeFrame = static function (string $data): ?string {
    if ($data === '') {
        return null;
    }

    $bytes = array_values(unpack('C*', $data));
    $masked = ($bytes[1] & 0x80) === 0x80;
    $length = $bytes[1] & 0x7F;
    $index = 2;

    if ($length === 126) {
        $length = ($bytes[2] << 8) + $bytes[3];
        $index = 4;
    } elseif ($length === 127) {
        $length = 0;
        for ($i = 0; $i < 8; $i++) {
            $length = ($length << 8) + $bytes[2 + $i];
        }
        $index = 10;
    }

    if ($masked) {
        $mask = array_slice($bytes, $index, 4);
        $index += 4;
    } else {
        $mask = [0, 0, 0, 0];
    }

    $payload = '';
    for ($i = 0; $i < $length; $i++) {
        $payload .= chr($bytes[$index + $i] ^ $mask[$i % 4]);
    }

    return $payload;
};

$broadcast = static function (array &$clients, string $sessionCode, array $message) use ($encodeFrame): void {
    $payload = $encodeFrame(json_encode($message));

    foreach ($clients as $index => $client) {
        if (($client['session'] ?? null) !== $sessionCode) {
            continue;
        }

        if (!is_resource($client['socket'])) {
            unset($clients[$index]);
            continue;
        }

        @fwrite($client['socket'], $payload);
    }
};

  $sendRoster = static function (array &$clients, array $session, array $players, $socket) use ($encodeFrame, $maxPlayers): void {
      $message = [
          'type' => 'session.roster',
          'sessionCode' => $session['code'],
          'status' => $session['status'],
        'maxPlayers' => $maxPlayers,
        'players' => $players,
        'canStart' => count($players) >= 2,
    ];

      @fwrite($socket, $encodeFrame(json_encode($message)));
  };

  $broadcastRoster = static function (array &$clients, array $session, array $players, callable $encodeFrame, int $maxPlayers): void {
      $payload = $encodeFrame(json_encode([
          'type' => 'session.roster',
          'sessionCode' => $session['code'],
          'status' => $session['status'],
          'maxPlayers' => $maxPlayers,
          'players' => $players,
          'canStart' => count($players) >= 2,
      ]));

      foreach ($clients as $index => $client) {
          if (($client['session'] ?? null) !== $session['code']) {
              continue;
          }

          if (!is_resource($client['socket'])) {
              unset($clients[$index]);
              continue;
          }

          @fwrite($client['socket'], $payload);
      }
  };

$sendLobbyList = static function (array &$clients, ?array $targetSockets = null) use ($repository, $encodeFrame, $sessionTtlMinutes, $maxPlayers): void {
    $sessions = $repository->listOpenSessionsWithCounts($sessionTtlMinutes);
    $payload = $encodeFrame(json_encode([
        'type' => 'lobbies.list',
        'sessions' => $sessions,
        'maxPlayers' => $maxPlayers,
    ]));

    if ($targetSockets !== null) {
        foreach ($targetSockets as $socket) {
            @fwrite($socket, $payload);
        }
        return;
    }

    foreach ($clients as $index => $client) {
        if (!($client['watchingLobbyList'] ?? false)) {
            continue;
        }

        if (!is_resource($client['socket'])) {
            unset($clients[$index]);
            continue;
        }

        @fwrite($client['socket'], $payload);
    }
};

$distanceFromA = static function (string $letter): int {
    $upper = strtoupper($letter);

    if ($upper === '?' || $upper === ' ') {
        return 0;
    }

    $ord = ord($upper);

    if ($ord < 65 || $ord > 90) {
        return 100;
    }

    return $ord - ord('A');
};

echo "Lobby WebSocket server listening on ws://{$host}:{$port}\n";

while (true) {
    $readSockets = [$server];
    foreach ($clients as $client) {
        $readSockets[] = $client['socket'];
    }

    $writeSockets = $exceptSockets = [];
    if (stream_select($readSockets, $writeSockets, $exceptSockets, null) < 1) {
        continue;
    }

    if (in_array($server, $readSockets, true)) {
        $newSocket = @stream_socket_accept($server, 0);
        if ($newSocket) {
            stream_set_blocking($newSocket, false);
            $clients[] = ['socket' => $newSocket, 'handshake' => false, 'session' => null, 'watchingLobbyList' => false];
        }

        $readSockets = array_filter($readSockets, static fn ($sock) => $sock !== $server);
    }

    foreach ($readSockets as $socket) {
        $clientIndex = null;
        foreach ($clients as $index => $client) {
            if ($client['socket'] === $socket) {
                $clientIndex = $index;
                break;
            }
        }

        if ($clientIndex === null) {
            continue;
        }

        $data = @fread($socket, 2048);

        if ($data === '' || $data === false) {
            fclose($socket);
            unset($clients[$clientIndex]);
            continue;
        }

        if (!$clients[$clientIndex]['handshake']) {
            if (!preg_match('#Sec-WebSocket-Key: (.*)\r#i', $data, $matches)) {
                fclose($socket);
                unset($clients[$clientIndex]);
                continue;
            }

            $key = trim($matches[1]);
            $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $headers = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";
            fwrite($socket, $headers);
            $clients[$clientIndex]['handshake'] = true;
            continue;
        }

        $message = $decodeFrame($data);

        if ($message === null) {
            fclose($socket);
            unset($clients[$clientIndex]);
            continue;
        }

        $payload = json_decode($message, true);

        if (!is_array($payload) || !isset($payload['type'])) {
            continue;
        }

        if (($payload['type'] ?? '') === 'lobbies.subscribe') {
            $clients[$clientIndex]['watchingLobbyList'] = true;
            $sendLobbyList($clients, [$socket]);
            continue;
        }

        if (($payload['type'] ?? '') === 'lobbies.refresh') {
            $sendLobbyList($clients);
            continue;
        }

        if (($payload['type'] ?? '') === 'subscribe') {
            $code = strtoupper(trim((string) ($payload['sessionCode'] ?? '')));
            $session = $repository->getSessionByCode($code);
            if (!$session) {
                continue;
            }

            $clients[$clientIndex]['session'] = $code;
            $players = $repository->listPlayersForSession((int) $session['id']);
            $sendRoster($clients, $session, $players, $socket);
            continue;
        }

        $sessionCode = strtoupper(trim((string) ($payload['sessionCode'] ?? '')));

        if ($sessionCode === '') {
            continue;
        }

        $session = $repository->getSessionByCode($sessionCode);

        if (!$session) {
            continue;
        }

        if (($payload['type'] ?? '') === 'turnorder.draw') {
            $sessionId = (int) $session['id'];
            $players = $repository->listPlayersForSession($sessionId);
            $playerId = (int) ($payload['playerId'] ?? 0);
            $player = null;

            foreach ($players as $member) {
                if ($member['id'] === $playerId) {
                    $player = $member;
                    break;
                }
            }

            if (!$player) {
                @fwrite($socket, $encodeFrame(json_encode([
                    'type' => 'turnorder.error',
                    'sessionCode' => $sessionCode,
                    'message' => 'Only seated players can draw a tile.',
                ])));
                continue;
            }

            if (count($players) < 2) {
                @fwrite($socket, $encodeFrame(json_encode([
                    'type' => 'turnorder.error',
                    'sessionCode' => $sessionCode,
                    'message' => 'Need at least two players to determine turn order.',
                ])));
                continue;
            }

            if ($repository->countTilesInBag($sessionId) === 0) {
                $repository->seedStandardTileBag($sessionId);
            }

            $existingDraws = $repository->listDrawnTiles($sessionId);

            $existingDraws = array_map(
                static function ($draw) use ($distanceFromA) {
                    $draw['distance'] = $distanceFromA($draw['tile']['letter']);
                    $draw['drawn_at'] = isset($draw['drawn_at']) && $draw['drawn_at'] !== ''
                        ? strtotime((string) $draw['drawn_at'])
                        : time();

                    return $draw;
                },
                $existingDraws
            );

            foreach ($existingDraws as $drawn) {
                if ($drawn['player']['id'] === $playerId) {
                    @fwrite($socket, $encodeFrame(json_encode([
                        'type' => 'turnorder.error',
                        'sessionCode' => $sessionCode,
                        'message' => 'You already drew a tile.',
                    ])));
                    continue 2;
                }
            }

            $tile = $repository->drawTileFromBag($sessionId, $playerId);

            if ($tile === null) {
                @fwrite($socket, $encodeFrame(json_encode([
                    'type' => 'turnorder.error',
                    'sessionCode' => $sessionCode,
                    'message' => 'Tile bag is empty; cannot determine order.',
                ])));

                continue;
            }

            $draw = [
                'player' => [
                    'id' => (int) $player['id'],
                    'name' => $player['name'],
                ],
                'tile' => [
                    'id' => (int) $tile['id'],
                    'letter' => $tile['letter'],
                    'value' => (int) $tile['value'],
                ],
                'distance' => $distanceFromA($tile['letter']),
                'drawn_at' => (int) (time()),
            ];

            $existingDraws[] = $draw;

            $broadcast($clients, $sessionCode, [
                'type' => 'turnorder.drawn',
                'sessionCode' => $sessionCode,
                'player' => $draw['player'],
                'tile' => $draw['tile'],
                'distance' => $draw['distance'],
            ]);

            $broadcast($clients, $sessionCode, [
                'type' => 'player.sound',
                'tone' => 'draw',
            ]);

            if (count($existingDraws) >= count($players)) {
                usort($existingDraws, static function ($left, $right) {
                    if ($left['distance'] === $right['distance']) {
                        return $left['drawn_at'] <=> $right['drawn_at'];
                    }

                    return $left['distance'] <=> $right['distance'];
                });

                $broadcast($clients, $sessionCode, [
                    'type' => 'turnorder.resolved',
                    'sessionCode' => $sessionCode,
                    'order' => array_map(
                        static fn ($entry) => [
                            'player' => $entry['player'],
                            'tile' => $entry['tile'],
                            'distance' => $entry['distance'],
                        ],
                        $existingDraws
                    ),
                    'draws' => $existingDraws,
                ]);

                $sequence = array_map(static fn ($entry) => (int) $entry['player']['id'], $existingDraws);
                if ($sequence !== []) {
                    $repository->saveTurnState($sessionId, $sequence[0], 1, 'idle', $sequence);
                    $broadcast($clients, $sessionCode, [
                        'type' => 'turn.started',
                        'sessionCode' => $sessionCode,
                        'playerId' => $sequence[0],
                        'turnNumber' => 1,
                    ]);
                }

                foreach ($existingDraws as $entry) {
                    $repository->recordTileReturn((int) $entry['tile']['id']);
                }

                $broadcast($clients, $sessionCode, [
                    'type' => 'player.sound',
                    'tone' => 'alert',
                ]);
            }

            continue;
        }

        if (($payload['type'] ?? '') === 'refresh') {
            $players = $repository->listPlayersForSession((int) $session['id']);
            $sendRoster($clients, $session, $players, $socket);
            $broadcast($clients, $sessionCode, [
                'type' => 'session.roster',
                'sessionCode' => $sessionCode,
                'status' => $session['status'],
                'maxPlayers' => $maxPlayers,
                'players' => $players,
                'canStart' => count($players) >= 2,
            ]);
            $sendLobbyList($clients);
            continue;
        }

        if (($payload['type'] ?? '') === 'session.start') {
            $session['status'] = 'started';
            $players = $repository->listPlayersForSession((int) $session['id']);
            $broadcast($clients, $sessionCode, [
                'type' => 'session.started',
                'sessionCode' => $sessionCode,
                'by' => $payload['by'] ?? null,
            ]);
            $broadcastRoster($clients, $session, $players, $encodeFrame, $maxPlayers);
            $sendLobbyList($clients);
            continue;
        }

        if (($payload['type'] ?? '') === 'player.sound') {
            $broadcast($clients, $sessionCode, [
                'type' => 'player.sound',
                'tone' => $payload['tone'] ?? 'accept',
            ]);
        }

        if (($payload['type'] ?? '') === 'turn.start') {
            $playerId = (int) ($payload['playerId'] ?? 0);
            $state = $repository->getTurnState((int) $session['id']);
            if (!$state || $state['current_player_id'] !== $playerId) {
                $broadcast($clients, $sessionCode, [
                    'type' => 'turn.invalid_move',
                    'reason' => 'Not your turn yet.',
                    'playerId' => $playerId,
                ]);
                continue;
            }

            $repository->saveTurnState((int) $session['id'], $playerId, (int) $state['turn_number'], 'active', $state['sequence']);
            $broadcast($clients, $sessionCode, [
                'type' => 'turn.started',
                'sessionCode' => $sessionCode,
                'playerId' => $playerId,
                'turnNumber' => (int) $state['turn_number'],
            ]);
            continue;
        }

        if (($payload['type'] ?? '') === 'turn.complete') {
            $playerId = (int) ($payload['playerId'] ?? 0);
            $placements = $payload['placements'] ?? [];
            if (!is_array($placements) || $placements === []) {
                $broadcast($clients, $sessionCode, [
                    'type' => 'turn.invalid_move',
                    'reason' => 'No placements submitted.',
                    'playerId' => $playerId,
                ]);
                continue;
            }

            $state = $repository->getTurnState((int) $session['id']);
            if (!$state || $state['current_player_id'] !== $playerId) {
                $broadcast($clients, $sessionCode, [
                    'type' => 'turn.invalid_move',
                    'reason' => 'Out of turn submission.',
                    'playerId' => $playerId,
                ]);
                continue;
            }

            $repository->recordTurn((int) $session['id'], $playerId, (int) $state['turn_number'], $placements, (int) ($payload['score'] ?? 0));
            $nextState = $repository->advanceTurnState((int) $session['id']);

            $broadcast($clients, $sessionCode, [
                'type' => 'turn.completed',
                'sessionCode' => $sessionCode,
                'playerId' => $playerId,
                'turnNumber' => (int) $state['turn_number'],
                'placements' => $placements,
            ]);

            if ($nextState) {
                $broadcast($clients, $sessionCode, [
                    'type' => 'turn.started',
                    'sessionCode' => $sessionCode,
                    'playerId' => $nextState['current_player_id'],
                    'turnNumber' => $nextState['turn_number'],
                ]);
            }
            continue;
        }
    }
}
