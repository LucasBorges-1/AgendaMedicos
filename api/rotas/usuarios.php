<?php
/* Usuários e papéis (somente Administrador) */

function rota_usuarios($m)
{
    $eu   = exigir_papel(['adm']);
    $pdo  = db();
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $acao = (isset($_GET['acao']) && is_string($_GET['acao'])) ? $_GET['acao'] : '';

    /* ---- Listar ---- */
    if ($m === 'GET') {
        $linhas = $pdo->query('SELECT id_usuario, nome, email, papel FROM usuario WHERE ativo = TRUE ORDER BY nome')->fetchAll();
        responder(['usuarios' => array_map('usuario_publico', $linhas)]);
    }

    /* ---- Redefinir senha (gera nova senha provisória) ---- */
    if ($m === 'POST' && $acao === 'redefinir') {
        $st = $pdo->prepare('SELECT id_usuario FROM usuario WHERE id_usuario = ? AND ativo = TRUE');
        $st->execute([$id]);
        if (!$st->fetch()) {
            throw new HttpError('Usuário não encontrado.', 404);
        }
        $senha = gerar_senha();
        $pdo->prepare('UPDATE usuario SET senha_hash = ? WHERE id_usuario = ?')
            ->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
        responder(['senha_temporaria' => $senha]);
    }

    /* ---- Convidar / criar usuário ---- */
    if ($m === 'POST') {
        $in    = entrada();
        $nome  = texto(isset($in['nome']) ? $in['nome'] : '', 120);
        $email = mb_strtolower(texto(isset($in['email']) ? $in['email'] : '', 190));
        $papel = isset($in['papel']) ? $in['papel'] : 'viewer';

        if ($nome === '') {
            throw new HttpError('Informe o nome do usuário.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError('E-mail inválido.', 422);
        }
        if (!in_array($papel, PAPEIS, true)) {
            throw new HttpError('Papel inválido.', 422);
        }

        $st = $pdo->prepare('SELECT id_usuario, ativo FROM usuario WHERE email = ?');
        $st->execute([$email]);
        $existente = $st->fetch();

        $senha = gerar_senha();
        $hash  = password_hash($senha, PASSWORD_DEFAULT);

        if ($existente) {
            if (como_bool($existente['ativo'])) {
                throw new HttpError('Já existe um usuário com este e-mail.', 409);
            }
            // Reativa um acesso removido anteriormente
            $novoId = (int)$existente['id_usuario'];
            $pdo->prepare('UPDATE usuario SET nome = ?, papel = ?, senha_hash = ?, ativo = TRUE WHERE id_usuario = ?')
                ->execute([$nome, $papel, $hash, $novoId]);
        } else {
            $ins = $pdo->prepare('INSERT INTO usuario (nome, email, senha_hash, papel, ativo) VALUES (?, ?, ?, ?, TRUE) RETURNING id_usuario');
            $ins->execute([$nome, $email, $hash, $papel]);
            $novoId = (int)$ins->fetchColumn();
        }

        responder([
            'usuario'          => ['id' => $novoId, 'nome' => $nome, 'email' => $email, 'papel' => $papel],
            'senha_temporaria' => $senha,
        ], 201);
    }

    /* ---- Alterar papel ---- */
    if ($m === 'PUT') {
        $in    = entrada();
        $papel = isset($in['papel']) ? $in['papel'] : '';
        if (!in_array($papel, PAPEIS, true)) {
            throw new HttpError('Papel inválido.', 422);
        }
        if ($id === $eu['id_usuario'] && $papel !== 'adm') {
            throw new HttpError('Você não pode remover seu próprio acesso de administrador.', 422);
        }
        $st = $pdo->prepare('UPDATE usuario SET papel = ? WHERE id_usuario = ? AND ativo = TRUE');
        $st->execute([$papel, $id]);
        // rowCount() = 0 também ocorre quando o papel já era o mesmo; confirma a existência:
        $chk = $pdo->prepare('SELECT 1 FROM usuario WHERE id_usuario = ? AND ativo = TRUE');
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            throw new HttpError('Usuário não encontrado.', 404);
        }
        responder(['ok' => true]);
    }

    /* ---- Remover acesso (desativa; mantém o histórico) ---- */
    if ($m === 'DELETE') {
        if ($id === $eu['id_usuario']) {
            throw new HttpError('Você não pode remover o seu próprio acesso.', 422);
        }
        $st = $pdo->prepare('UPDATE usuario SET ativo = FALSE WHERE id_usuario = ? AND ativo = TRUE');
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            throw new HttpError('Usuário não encontrado.', 404);
        }
        responder(['ok' => true]);
    }

    throw new HttpError('Método não permitido.', 405);
}
