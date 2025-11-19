<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/Server/Db/Connection.php';
require dirname(__DIR__) . '/src/Server/Db/GameRepository.php';

use TileMasterAI\Server\Db\GameRepository;

$host = getenv('LOBBY_WS_HOST') ?: '0.0.0.0';
$port = (int) (getenv('LOBBY_WS_PORT') ?: 8090);
$maxPlayers = 4;

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
            $clients[] = ['socket' => $newSocket, 'handshake' => false, 'session' => null];
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
            continue;
        }

        if (($payload['type'] ?? '') === 'session.start') {
            $broadcast($clients, $sessionCode, [
                'type' => 'session.started',
                'sessionCode' => $sessionCode,
                'by' => $payload['by'] ?? null,
            ]);
            continue;
        }

        if (($payload['type'] ?? '') === 'player.sound') {
            $broadcast($clients, $sessionCode, [
                'type' => 'player.sound',
                'tone' => $payload['tone'] ?? 'accept',
            ]);
        }
    }
}
