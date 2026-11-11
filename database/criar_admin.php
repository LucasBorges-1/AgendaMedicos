<?php
/* Cria ou redefine um administrador pela linha de comando.
 *   php database/criar_admin.php "Nome" email@clinica.com "SenhaForte123"
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente linha de comando.'); }
require __DIR__ . '/../api/config.php';

if ($argc < 4) { fwrite(STDERR, "Uso: php criar_admin.php \"Nome\" email senha\n"); exit(1); }
list(, $nome, $email, $senha) = $argv;
$email = mb_strtolower(trim($email));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "E-mail inválido.\n"); exit(1); }
if (strlen($senha) < 8) { fwrite(STDERR, "A senha deve ter ao menos 8 caracteres.\n"); exit(1); }

$pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5', DB_HOST, DB_PORT, DB_NAME),
               DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hash = password_hash($senha, PASSWORD_DEFAULT);
$st = $pdo->prepare('SELECT id_usuario FROM usuario WHERE email = ?');
$st->execute([$email]);
if ($id = $st->fetchColumn()) {
    $pdo->prepare("UPDATE usuario SET nome = ?, senha_hash = ?, papel = 'adm', ativo = TRUE WHERE id_usuario = ?")->execute([$nome, $hash, $id]);
    echo "Administrador atualizado: $email\n";
} else {
    $pdo->prepare("INSERT INTO usuario (nome, email, senha_hash, papel, ativo) VALUES (?, ?, ?, 'adm', TRUE)")->execute([$nome, $email, $hash]);
    echo "Administrador criado: $email\n";
}
