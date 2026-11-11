<?php
/* Autenticação: me, login, logout, senha */

function rota_me($m)
{
    if ($m !== 'GET') {
        throw new HttpError('Método não permitido.', 405);
    }
    $u = usuario_logado();
    responder([
        'autenticado'   => (bool)$u,
        'usuario'       => $u ? usuario_publico($u) : null,
        'precisa_setup' => $u ? false : !existe_admin(),
        'csrf'          => token_csrf(),
    ]);
}

function existe_admin()
{
    return (int)db()->query("SELECT COUNT(*) FROM usuario WHERE papel = 'adm' AND ativo = TRUE")->fetchColumn() > 0;
}

/** Primeiro acesso: só funciona enquanto NÃO existir nenhum administrador ativo. */
function rota_setup($m)
{
    if ($m !== 'POST') {
        throw new HttpError('Método não permitido.', 405);
    }
    $in    = entrada();
    $nome  = texto(isset($in['nome']) ? $in['nome'] : '', 120);
    $email = mb_strtolower(texto(isset($in['email']) ? $in['email'] : '', 190));
    $senha = isset($in['senha']) && is_string($in['senha']) ? $in['senha'] : '';

    if ($nome === '') {
        throw new HttpError('Informe o nome do administrador.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new HttpError('Digite um e-mail válido.', 422);
    }
    if (strlen($senha) < 8 || strlen($senha) > 72) {
        throw new HttpError('A senha deve ter entre 8 e 72 caracteres.', 422);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Trava transacional para evitar duas criações simultâneas do primeiro admin
        $pdo->query('SELECT pg_advisory_xact_lock(48151623)');
        $qtd = (int)$pdo->query("SELECT COUNT(*) FROM usuario WHERE papel = 'adm' AND ativo = TRUE")->fetchColumn();
        if ($qtd > 0) {
            throw new HttpError('O sistema já possui um administrador. Faça login.', 403);
        }
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $st = $pdo->prepare('SELECT id_usuario FROM usuario WHERE email = ?');
        $st->execute([$email]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $pdo->prepare("UPDATE usuario SET nome = ?, senha_hash = ?, papel = 'adm', ativo = TRUE WHERE id_usuario = ?")
                ->execute([$nome, $hash, (int)$id]);
        } else {
            $pdo->prepare("INSERT INTO usuario (nome, email, senha_hash, papel, ativo) VALUES (?, ?, ?, 'adm', TRUE)")
                ->execute([$nome, $email, $hash]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder(['ok' => true], 201);
}

function rota_login($m)
{
    if ($m !== 'POST') {
        throw new HttpError('Método não permitido.', 405);
    }
    iniciar_sessao();

    if (!empty($_SESSION['bloqueado_ate']) && (int)$_SESSION['bloqueado_ate'] > time()) {
        throw new HttpError('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429);
    }

    $in    = entrada();
    $email = mb_strtolower(texto(isset($in['email']) ? $in['email'] : '', 190));
    $senha = isset($in['senha']) && is_string($in['senha']) ? $in['senha'] : '';
    if ($email === '') {
        throw new HttpError('Informe o e-mail.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new HttpError('Digite um e-mail válido.', 422);
    }
    if ($senha === '') {
        throw new HttpError('Informe a senha.', 422);
    }
    if (strlen($senha) > 200) {
        throw new HttpError('E-mail ou senha incorretos.', 401);
    }

    $st = db()->prepare('SELECT id_usuario, nome, email, senha_hash, papel, ativo FROM usuario WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    $ok = password_verify($senha, $u ? $u['senha_hash'] : HASH_FICTICIO) && $u && como_bool($u['ativo']);

    if (!$ok) {
        $_SESSION['falhas'] = (isset($_SESSION['falhas']) ? (int)$_SESSION['falhas'] : 0) + 1;
        if ($_SESSION['falhas'] >= 5) {
            $_SESSION['falhas'] = 0;
            $_SESSION['bloqueado_ate'] = time() + 300;
        }
        usleep(400000);
        throw new HttpError('E-mail ou senha incorretos.', 401);
    }

    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE usuario SET senha_hash = ? WHERE id_usuario = ?')
            ->execute([password_hash($senha, PASSWORD_DEFAULT), (int)$u['id_usuario']]);
    }

    session_regenerate_id(true);
    $_SESSION['uid']    = (int)$u['id_usuario'];
    guardar_cache_usuario(['id_usuario' => (int)$u['id_usuario'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel']]);
    $_SESSION['falhas'] = 0;
    unset($_SESSION['bloqueado_ate']);
    $_SESSION['csrf']   = bin2hex(random_bytes(32));

    responder(['usuario' => usuario_publico($u), 'csrf' => $_SESSION['csrf']]);
}

function rota_logout($m)
{
    if ($m !== 'POST') {
        throw new HttpError('Método não permitido.', 405);
    }
    iniciar_sessao();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    responder(['ok' => true]);
}

function rota_senha($m)
{
    if ($m !== 'POST') {
        throw new HttpError('Método não permitido.', 405);
    }
    $eu    = exigir_login();
    $in    = entrada();
    $atual = isset($in['atual']) && is_string($in['atual']) ? $in['atual'] : '';
    $nova  = isset($in['nova'])  && is_string($in['nova'])  ? $in['nova']  : '';

    if (strlen($nova) < 8) {
        throw new HttpError('A nova senha deve ter pelo menos 8 caracteres.', 422);
    }
    if (strlen($nova) > 72) {
        throw new HttpError('A nova senha deve ter no máximo 72 caracteres.', 422);
    }

    $st = db()->prepare('SELECT senha_hash FROM usuario WHERE id_usuario = ?');
    $st->execute([$eu['id_usuario']]);
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify($atual, $hash)) {
        throw new HttpError('A senha atual está incorreta.', 422);
    }

    db()->prepare('UPDATE usuario SET senha_hash = ? WHERE id_usuario = ?')
        ->execute([password_hash($nova, PASSWORD_DEFAULT), $eu['id_usuario']]);

    session_regenerate_id(true);
    responder(['ok' => true]);
}
