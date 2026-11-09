<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
function ask(string $label): string { echo $label; return trim((string)fgets(STDIN)); }
$name = ask('Nome: ');
$email = strtolower(ask('E-mail: '));
$hidden = function_exists('stream_isatty') && stream_isatty(STDIN) && PHP_OS_FAMILY !== 'Windows';
if ($hidden) system('stty -echo');
try { $password = ask('Senha (mínimo 10 caracteres): '); }
finally { if ($hidden) { system('stty echo'); echo "\n"; } }
try {
    $name = text_field(['nome' => $name], 'nome', 150);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) throw new RuntimeException('E-mail inválido.');
    if (strlen($password) < 10 || strlen($password) > 72) throw new RuntimeException('Use uma senha entre 10 e 72 bytes.');
    query('INSERT INTO usuario (nome,email,senha_hash,papel) VALUES (?,?,?,?)', [$name,$email,password_hash($password,PASSWORD_DEFAULT),'adm']);
    echo "Administrador criado. Acesse login.php.\n";
} catch (Throwable $e) { fwrite(STDERR, 'Não foi possível criar o administrador: ' . $e->getMessage() . "\n"); exit(1); }
