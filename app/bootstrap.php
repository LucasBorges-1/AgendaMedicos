<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';

// Somente configurações locais; variáveis do servidor têm prioridade.
if (is_file(ROOT . '/.env')) {
    foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) > 1 && in_array($value[0], ['"', "'"], true) && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) && getenv($key) === false) putenv($key . '=' . $value);
    }
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

final class ApiError extends RuntimeException {
    public function __construct(public int $status, string $message, public array $fields = []) {
        parent::__construct($message);
    }
}

function db(): PDO {
    static $connection;
    if (!$connection) {
        $parts = [];
        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'dbname' => 'DB_NAME', 'sslmode' => 'DB_SSLMODE'] as $part => $name) {
            $value = getenv($name) ?: ['host' => '127.0.0.1', 'port' => '5432', 'dbname' => 'agenda_vidas', 'sslmode' => 'prefer'][$part];
            if (strpbrk($value, ";\r\n") !== false) throw new RuntimeException('Configuração inválida: ' . $name);
            $parts[] = $part . '=' . $value;
        }
        $connection = new PDO('pgsql:' . implode(';', $parts) . ';connect_timeout=5', getenv('DB_USER') ?: 'agenda_vidas', getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $connection;
}

function query(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(is_int($key) ? $key + 1 : ':' . $key, $value,
            is_bool($value) ? PDO::PARAM_BOOL : (is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR)));
    }
    $stmt->execute();
    return $stmt;
}

function transaction(callable $callback): mixed {
    db()->beginTransaction();
    try { $result = $callback(); db()->commit(); return $result; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}

function security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    header('Cache-Control: no-store');
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    if (getenv('SESSION_PATH')) session_save_path(getenv('SESSION_PATH'));
    session_name(getenv('SESSION_NAME') ?: 'agenda_vidas');
    session_set_cookie_params(['httponly' => true, 'secure' => filter_var(getenv('SESSION_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'samesite' => 'Lax', 'path' => '/']);
    if (!session_start()) throw new RuntimeException('O diretório de sessões do PHP não está disponível para gravação.');
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function role(string $value): string {
    return match (strtolower($value)) {
        'adm', 'admin', 'administrador' => 'adm',
        'editor', 'responsavel', 'responsável' => 'editor',
        default => 'viewer',
    };
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['user_id'])) return null;
    $user = query('SELECT id_usuario, nome, email, papel, ativo, senha_hash FROM usuario WHERE id_usuario = ?', [$_SESSION['user_id']])->fetch();
    if (!$user || !$user['ativo']) { unset($_SESSION['user_id']); return null; }
    if (!hash_equals($_SESSION['credential'] ?? '', hash('sha256', $user['senha_hash']))) { unset($_SESSION['user_id']); return null; }
    unset($user['senha_hash']);
    $user['papel'] = role($user['papel']);
    return $user;
}

function authorize(string $permission = 'read'): array {
    $user = current_user();
    if (!$user) throw new ApiError(401, 'Sua sessão expirou. Entre novamente.');
    if (($permission === 'write' && $user['papel'] === 'viewer') || ($permission === 'admin' && $user['papel'] !== 'adm')) {
        throw new ApiError(403, 'Seu perfil não tem permissão para esta ação.');
    }
    return $user;
}

function require_page_user(): array {
    try {
        $user = current_user();
        if (!$user) { header('Location: login.php'); exit; }
        return $user;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        http_response_code(503);
        exit('Não foi possível acessar a agenda. Verifique a configuração do PostgreSQL e execute bin/migrate.php conforme o README.');
    }
}

function csrf_check(): void {
    start_session();
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new ApiError(419, 'Sessão de segurança inválida. Atualize a página.');
}

function json_body(): array {
    if (!str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new ApiError(415, 'Envie o corpo como application/json.');
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) throw new ApiError(413, 'Requisição muito grande.');
    try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError(400, 'JSON inválido.'); }
    if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) throw new ApiError(400, 'O corpo deve ser um objeto JSON.');
    return $data;
}

function respond(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function text_field(array $input, string $key, int $max, bool $required = true): ?string {
    $value = $input[$key] ?? null;
    if ($value === null && !$required) return null;
    if (!is_string($value)) throw new ApiError(422, 'Campo inválido: ' . $key . '.');
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ($length === false || ($required && $value === '') || $length > $max) throw new ApiError(422, 'Verifique o campo ' . $key . ' (máximo ' . $max . ' caracteres).');
    return $value === '' && !$required ? null : $value;
}

function int_field(mixed $value, string $name): int {
    if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1 || (int)$value > 2147483647) throw new ApiError(422, 'Identificador inválido: ' . $name . '.');
    return (int)$value;
}

function bool_field(array $data, string $name, bool $default = true): bool {
    if (!array_key_exists($name, $data)) return $default;
    if (!is_bool($data[$name])) throw new ApiError(422, 'Campo booleano inválido: ' . $name . '.');
    return $data[$name];
}

function valid_date(mixed $value): string {
    if (!is_string($value)) throw new ApiError(422, 'Data inválida.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > '2100-12-31') throw new ApiError(422, 'Informe uma data válida entre 2000 e 2100.');
    return $value;
}

function month_range(mixed $value): array {
    if (!is_string($value) || !preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/', $value)) throw new ApiError(422, 'Mês inválido. Use AAAA-MM.');
    $first = new DateTimeImmutable($value . '-01');
    return [$first->format('Y-m-d'), $first->modify('first day of next month')->format('Y-m-d')];
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if (PHP_SAPI !== 'cli') security_headers();
