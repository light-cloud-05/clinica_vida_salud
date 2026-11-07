<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
try {
    require dirname(__DIR__) . '/backend/bootstrap.php';
    $routes = require dirname(__DIR__) . '/backend/routes/web.php';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!isset($routes[$path])) {
        http_response_code(404);
        echo 'Página no encontrada.';
    } elseif (!isset($routes[$path][$_SERVER['REQUEST_METHOD'] ?? 'GET'])) {
        http_response_code(405);
        header('Allow: ' . implode(', ', array_intersect(['GET', 'POST'], array_keys($routes[$path]))));
        echo 'Método no permitido.';
    } else {
        \Clinica\Services\SessionService::start();
        $route = $routes[$path];
        [$controller, $method] = $route[$_SERVER['REQUEST_METHOD'] ?? 'GET'];
        $user = null;
        if (isset($route['role']) || isset($route['roles'])) {
            $user = \Clinica\Middleware\AuthMiddleware::current(\Clinica\Support\AuthFactory::service());
            if (!$user) {
                header('Location: /login', true, 303);
                exit;
            }
            $allowed = isset($route['roles'])
                ? \Clinica\Middleware\AuthorizationMiddleware::allowsAny($user, $route['roles'], $route['permission'])
                : \Clinica\Middleware\AuthorizationMiddleware::allows($user, $route['role'], $route['permission'] ?? null);
            if (!$allowed) {
                http_response_code(403);
                echo 'No tiene autorización para acceder a esta página.';
                exit;
            }
        }
        $instance = new $controller();
        $user === null ? $instance->$method() : $instance->$method($user);
    }
} catch (\Throwable $exception) {
    http_response_code(503);
    echo 'Servicio no disponible. Revise la instalación y la configuración local.';
}
