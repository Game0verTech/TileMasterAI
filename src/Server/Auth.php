<?php

declare(strict_types=1);

namespace TileMasterAI\Server;

use TileMasterAI\Server\Db\AppRepository;

/**
 * Session-backed authentication helper.
 */
class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }

    public static function currentUser(AppRepository $repository): ?array
    {
        self::start();
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        return $repository->getUserById((int) $userId);
    }

    public static function requireUser(AppRepository $repository): array
    {
        $user = self::currentUser($repository);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            exit;
        }

        return $user;
    }

    public static function login(AppRepository $repository, int $userId): void
    {
        self::start();
        $_SESSION['user_id'] = $userId;
        $repository->recordSession(session_id(), $userId);
    }

    public static function logout(AppRepository $repository): void
    {
        self::start();
        $sessionId = session_id();
        if (isset($_SESSION['user_id'])) {
            $repository->deleteSessionById($sessionId);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
