<?php
// Roteador seguro do servidor de desenvolvimento. Uso: php -S 127.0.0.1:8080 router.php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (!is_string($path)) { http_response_code(400); exit; }
$path=rawurldecode($path);
if ($path==='/') { require __DIR__.'/index.php'; return true; }
if (preg_match('~^/(index|login|medicos|usuarios|imprimir|pdf)\.php$~D',$path)) { require __DIR__.$path; return true; }
if ($path==='/api/index.php' || str_starts_with($path,'/api/index.php/')) {
    $_SERVER['PATH_INFO']=substr($path,strlen('/api/index.php'));
    require __DIR__.'/api/index.php'; return true;
}
if (preg_match('~^/(css|js|img)/[a-zA-Z0-9_-]+\.(css|js|png|jpg|svg|ico|webp)$~D',$path) && is_file(__DIR__.$path)) return false;
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Página não encontrada.';
return true;
