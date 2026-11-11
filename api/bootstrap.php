<?php
/* =====================================================================
   Núcleo da API: erros, banco, sessão, CSRF, permissões e utilitários
   ===================================================================== */

require __DIR__ . '/config.php';

const PAPEIS = ['adm', 'editor', 'viewer'];

// Hash fictício usado para igualar o tempo de resposta quando o e-mail não existe
const HASH_FICTICIO = '$2y$10$awooXjcNvmkyYCedxMfdOui5rFqbwK2i5cppyTn07Z5W7O/4erLmW';

date_default_timezone_set(APP_TZ);
mb_internal_encoding('UTF-8');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

class HttpError extends RuntimeException
{
    public function __construct($mensagem, $status = 400)
    {
        parent::__construct($mensagem, $status);
    }
}

function responder($dados, $status = 200)
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

set_exception_handler(function ($e) {
    if ($e instanceof HttpError) {
        responder(['erro' => $e->getMessage()], $e->getCode());
    }
    error_log('[clinica] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $codigo = get_class($e) . (($e instanceof PDOException) ? ' ' . $e->getCode() : '');
    responder([
        'erro'   => APP_DEBUG ? $e->getMessage() : 'Erro interno do servidor.',
        'codigo' => $codigo,
    ], 500);
});

/* ---------------- Banco de dados ---------------- */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=5', DB_HOST, DB_PORT, DB_NAME, defined('DB_SSLMODE') ? DB_SSLMODE : 'prefer');
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => (defined('DB_POOLER') && DB_POOLER),
                PDO::ATTR_PERSISTENT         => true,
            ]);
            // Conexão persistente: se a requisição anterior terminou no meio de uma transação, desfaz.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            register_shutdown_function(function () use ($pdo) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $e) {
                }
            });
        } catch (PDOException $e) {
            error_log('[clinica] Falha ao conectar no banco: ' . $e->getMessage());
            $msg = 'Não foi possível conectar ao banco de dados. Verifique usuário, senha e nome do banco em api/config.php e se o banco foi criado e o schema.sql importado.';
            if (APP_DEBUG) { $msg .= ' (' . $e->getMessage() . ')'; }
            throw new HttpError($msg, 503);
        }
    }
    return $pdo;
}

/* ---------------- Sessão e CSRF ---------------- */
function iniciar_sessao()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_name('clinica_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (isset($_SESSION['ultimo']) && (time() - (int)$_SESSION['ultimo']) > SESSAO_MAX_OCIOSA) {
        $_SESSION = [];
    }
    $_SESSION['ultimo'] = time();
}

function token_csrf()
{
    iniciar_sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Converte boolean do PostgreSQL (true/'t'/1) em bool do PHP. */
function como_bool($v)
{
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

function exigir_csrf()
{
    $enviado = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if (!hash_equals(token_csrf(), $enviado)) {
        throw new HttpError('Sessão inválida. Recarregue a página.', 403);
    }
}

/* ---------------- Usuário logado e permissões ---------------- */
function usuario_logado()
{
    iniciar_sessao();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $c = isset($_SESSION['ucache']) ? $_SESSION['ucache'] : null;
    if ($c && (int)$c['u']['id_usuario'] === (int)$_SESSION['uid'] && (int)$c['ate'] > time()) {
        return $c['u'];
    }
    $st = db()->prepare('SELECT id_usuario, nome, email, papel FROM usuario WHERE id_usuario = ? AND ativo = TRUE');
    $st->execute([(int)$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u) {
        unset($_SESSION['uid'], $_SESSION['ucache']);
        return null;
    }
    $u['id_usuario'] = (int)$u['id_usuario'];
    guardar_cache_usuario($u);
    return $u;
}

/** Guarda o usuário na sessão por 60 s; remoções de acesso passam a valer em até 1 minuto. */
function guardar_cache_usuario(array $u)
{
    $_SESSION['ucache'] = ['ate' => time() + 60, 'u' => $u];
}

function exigir_login()
{
    $u = usuario_logado();
    if (!$u) {
        throw new HttpError('Faça login para continuar.', 401);
    }
    return $u;
}

function exigir_papel(array $permitidos)
{
    $u = exigir_login();
    if (!in_array($u['papel'], $permitidos, true)) {
        throw new HttpError('Você não tem permissão para esta ação.', 403);
    }
    return $u;
}

function usuario_publico(array $u)
{
    return [
        'id'    => (int)$u['id_usuario'],
        'nome'  => $u['nome'],
        'email' => $u['email'],
        'papel' => $u['papel'],
    ];
}

/* ---------------- Entrada e validação ---------------- */
function entrada()
{
    $bruto = file_get_contents('php://input');
    if ($bruto === false || trim($bruto) === '') {
        return [];
    }
    $dados = json_decode($bruto, true);
    if (!is_array($dados)) {
        throw new HttpError('Corpo da requisição inválido (JSON esperado).', 400);
    }
    return $dados;
}

function texto($valor, $max)
{
    if (!is_scalar($valor)) {
        return '';
    }
    $s = preg_replace('/\s+/u', ' ', trim((string)$valor));
    if ($s === null) {
        throw new HttpError('Texto com caracteres inválidos.', 422);
    }
    if (mb_strlen($s) > $max) {
        throw new HttpError("Texto muito longo (máximo de $max caracteres).", 422);
    }
    return $s;
}

function data_valida($d)
{
    if (!is_string($d)) {
        return false;
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/** Lê ano e mês (1-12) de $_GET ou do corpo da requisição. */
function ano_mes(array $fonte)
{
    $ano = isset($fonte['ano']) ? (int)$fonte['ano'] : 0;
    $mes = isset($fonte['mes']) ? (int)$fonte['mes'] : 0;
    if ($ano < 2000 || $ano > 2100 || $mes < 1 || $mes > 12) {
        throw new HttpError('Ano ou mês inválido.', 422);
    }
    return [$ano, $mes];
}

function gerar_senha($tamanho = 10)
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alfabeto) - 1;
    $s = '';
    for ($i = 0; $i < $tamanho; $i++) {
        $s .= $alfabeto[random_int(0, $max)];
    }
    return $s;
}
