<?php
// Shared bootstrap for API endpoints to enforce secure sessions and same-origin access.

if (PHP_SAPI === 'cli') {
    if (!function_exists('require_api_auth')) {
        function require_api_auth(): void {}
    }
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_httponly', '1');
        if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
            ini_set('session.cookie_samesite', 'Lax');
        }
        session_start();
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $appOrigin = $host !== '' ? $scheme . '://' . $host : null;

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    if ($appOrigin !== null) {
        header('Access-Control-Allow-Origin: ' . $appOrigin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null && $appOrigin !== null && $origin !== $appOrigin) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Origin not allowed']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if ($origin === null || $appOrigin === null || $origin !== $appOrigin) {
            http_response_code(403);
            exit;
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        http_response_code(204);
        exit;
    }

    require_once __DIR__ . '/models/Setting.php';
    require_once __DIR__ . '/models/Log.php';

    if (!function_exists('require_api_auth')) {
        function require_api_auth(): void {
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }

            $timeoutMinutes = (int)(Setting::get('session_timeout_minutes') ?? 0);
            $now = time();
            $lastActivity = $_SESSION['last_activity'] ?? $now;

            if ($timeoutMinutes > 0 && ($now - $lastActivity) > $timeoutMinutes * 60) {
                Log::write('Session expired for user ' . $userId, 'WARN');
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_unset();
                    session_destroy();
                }
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Session expired']);
                exit;
            }

            $_SESSION['last_activity'] = $now;
        }
    }
}
