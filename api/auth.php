<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Server/Db/Connection.php';
require dirname(__DIR__) . '/src/Server/Db/AppRepository.php';
require dirname(__DIR__) . '/src/Server/Auth.php';

use TileMasterAI\Server\Db\AppRepository;
use TileMasterAI\Server\Auth;

header('Content-Type: application/json');

$repository = new AppRepository();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

if ($method === 'GET') {
    $user = Auth::currentUser($repository);
    echo json_encode(['success' => true, 'user' => $user ? ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']] : null]);
    exit;
}

if ($method === 'POST') {
    $action = $body['action'] ?? '';
    if ($action === 'register') {
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['confirm'] ?? '');

        if ($username === '' || $password === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
            exit;
        }

        if (strlen($password) < 6) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
            exit;
        }

        if ($password !== $confirm) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        if ($repository->getUserByUsername($username)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Username is already taken.']);
            exit;
        }

        $role = $repository->userCount() === 0 ? 'admin' : 'user';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $repository->createUser($username, $hash, $role);

        Auth::login($repository, $userId);

        echo json_encode(['success' => true, 'message' => 'Registration successful.', 'user' => ['id' => $userId, 'username' => $username, 'role' => $role]]);
        exit;
    }

    if ($action === 'login') {
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $user = $repository->getUserByUsername($username);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
            exit;
        }

        Auth::login($repository, $user['id']);
        echo json_encode(['success' => true, 'message' => 'Logged in.', 'user' => ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']]]);
        exit;
    }

    if ($action === 'logout') {
        Auth::logout($repository);
        echo json_encode(['success' => true, 'message' => 'Logged out.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit;
