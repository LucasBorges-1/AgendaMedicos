<?php
declare(strict_types=1);
// Uma única função PHP. Somente as páginas públicas podem ser despachadas.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/api/index.php' || str_starts_with($path, '/api/index.php/')) {
    $_SERVER['PATH_INFO'] = substr($path, strlen('/api/index.php'));
    require __DIR__ . '/index.php';
} elseif ($path === '/') {
    require __DIR__ . '/../index.php';
} elseif (preg_match('~^/(index|login|medicos|usuarios|imprimir|pdf)\.php$~D', $path)) {
    require __DIR__ . '/..' . $path;
} else {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Página não encontrada.';
}
