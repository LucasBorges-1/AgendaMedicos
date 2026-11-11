<?php
/* Médicos (Administrador e Responsável) */

require_once __DIR__ . '/_fixos.php';

/** Lê e valida nome, tipo e dias do corpo da requisição. Retorna [nome, tipo, dias]. */
function medico_validar(array $in)
{
    $nome = mb_strtoupper(texto(isset($in['nome']) ? $in['nome'] : '', 120));
    if ($nome === '') {
        throw new HttpError('Informe o nome do médico como aparece no calendário.', 422);
    }

    $tipo = isset($in['tipo']) ? $in['tipo'] : 'fixo';
    if (!in_array($tipo, ['fixo', 'variado'], true)) {
        throw new HttpError('Tipo de agenda inválido.', 422);
    }

    $dias = [];
    if ($tipo === 'fixo') {
        $brutos = (isset($in['dias']) && is_array($in['dias'])) ? $in['dias'] : [];
        foreach ($brutos as $d) {
            $d = (int)$d;
            if ($d < 0 || $d > 6) {
                throw new HttpError('Dia da semana inválido.', 422);
            }
            $dias[$d] = $d;
        }
        $dias = array_values($dias);
        sort($dias);
        if (!$dias) {
            throw new HttpError('Selecione ao menos um dia da semana fixo.', 422);
        }
    }
    return [$nome, $tipo, $dias];
}

/** Array de inteiros no formato literal do PostgreSQL, ex.: {1,2,3} */
function pg_int_array(array $v)
{
    return '{' . implode(',', array_map('intval', $v)) . '}';
}

function rota_medicos($m)
{
    $eu   = exigir_papel(['adm', 'editor']);
    $pdo  = db();
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $hoje = date('Y-m-d');
    $fimAno = date('Y') . '-12-31';

    /* ---- Listar ---- */
    if ($m === 'GET') {
        $linhas = $pdo->query(
            "SELECT m.id_medico, m.nome, m.tipo,
                    COALESCE((SELECT STRING_AGG(CAST(d.dia_semana AS TEXT), CHR(44) ORDER BY d.dia_semana)
                                FROM medico_dia_semana d WHERE d.id_medico = m.id_medico), '') AS dias
               FROM medico m
              WHERE m.ativo = TRUE
           ORDER BY m.nome"
        )->fetchAll();
        $saida = [];
        foreach ($linhas as $r) {
            $saida[] = [
                'id'   => (int)$r['id_medico'],
                'nome' => $r['nome'],
                'tipo' => $r['tipo'],
                'dias' => $r['dias'] === '' ? [] : array_map('intval', explode(',', $r['dias'])),
            ];
        }
        responder(['medicos' => $saida]);
    }

    /* ---- Cadastrar ---- */
    if ($m === 'POST') {
        list($nome, $tipo, $dias) = medico_validar(entrada());

        // Insere somente se não houver outro médico ativo com o mesmo nome (1 ida ao banco)
        $st = $pdo->prepare(
            'INSERT INTO medico (nome, tipo, ativo)
             SELECT CAST(? AS VARCHAR), CAST(? AS VARCHAR), TRUE
              WHERE NOT EXISTS (SELECT 1 FROM medico WHERE ativo = TRUE AND nome = ?)
             RETURNING id_medico'
        );
        $st->execute([$nome, $tipo, $nome]);
        $novo = $st->fetchColumn();
        if ($novo === false) {
            throw new HttpError('Já existe um médico com este nome.', 409);
        }
        $novo = (int)$novo;

        $criados = 0;
        if ($dias) {
            $vals = [];
            foreach ($dias as $d) {
                $vals[] = '(' . $novo . ',' . (int)$d . ')';
            }
            $pdo->exec('INSERT INTO medico_dia_semana (id_medico, dia_semana) VALUES ' . implode(',', $vals));
            // Entra automaticamente no calendário de hoje até o fim do ano
            $criados = aplicar_dias_fixos($pdo, $eu['id_usuario'], $hoje, $fimAno, $novo);
        }
        responder(['medico' => ['id' => $novo, 'nome' => $nome, 'tipo' => $tipo, 'dias' => $dias], 'agendamentos_criados' => $criados], 201);
    }

    /* ---- Editar ---- */
    if ($m === 'PUT') {
        list($nome, $tipo, $dias) = medico_validar(entrada());

        // Existência + nome duplicado + dias atuais numa única consulta
        $st = $pdo->prepare(
            'SELECT EXISTS (SELECT 1 FROM medico WHERE ativo = TRUE AND nome = ? AND id_medico <> ?) AS dup,
                    COALESCE((SELECT STRING_AGG(CAST(dia_semana AS TEXT), CHR(44)) FROM medico_dia_semana WHERE id_medico = ?), \'\') AS dias
              WHERE EXISTS (SELECT 1 FROM medico WHERE id_medico = ? AND ativo = TRUE)'
        );
        $st->execute([$nome, $id, $id, $id]);
        $atual = $st->fetch();
        if (!$atual) {
            throw new HttpError('Médico não encontrado.', 404);
        }
        if (como_bool($atual['dup'])) {
            throw new HttpError('Já existe um médico com este nome.', 409);
        }
        $antigos    = $atual['dias'] === '' ? [] : array_map('intval', explode(',', $atual['dias']));
        $removidos  = array_values(array_diff($antigos, $dias));
        $adicionados = array_values(array_diff($dias, $antigos));

        // Tudo numa instrução: atualiza o médico, ajusta os dias e remove os dias futuros "limpos" dos dias retirados
        $pdo->prepare(
            "WITH u AS (UPDATE medico SET nome = ?, tipo = ? WHERE id_medico = ? RETURNING id_medico),
                  r AS (DELETE FROM medico_dia_semana
                         WHERE id_medico = ? AND dia_semana = ANY(CAST(? AS INTEGER[])) RETURNING id),
                  a AS (INSERT INTO medico_dia_semana (id_medico, dia_semana)
                        SELECT CAST(? AS INTEGER), x FROM unnest(CAST(? AS INTEGER[])) AS x RETURNING id),
                  c AS (DELETE FROM agendamento
                         WHERE id_medico = ? AND data >= ? AND status = 'confirmado'
                           AND id_abreviacao IS NULL AND (observacao IS NULL OR observacao = '')
                           AND CAST(EXTRACT(DOW FROM data) AS INTEGER) = ANY(CAST(? AS INTEGER[])) RETURNING id_agendamento)
             SELECT 1"
        )->execute([
            $nome, $tipo, $id,
            $id, pg_int_array($removidos),
            $id, pg_int_array($adicionados),
            $id, $hoje, pg_int_array($removidos),
        ]);

        $criados = 0;
        if ($tipo === 'fixo') {
            $criados = aplicar_dias_fixos($pdo, $eu['id_usuario'], $hoje, $fimAno, $id);
        }
        responder(['medico' => ['id' => $id, 'nome' => $nome, 'tipo' => $tipo, 'dias' => $dias], 'agendamentos_criados' => $criados]);
    }

    /* ---- Excluir ---- */
    if ($m === 'DELETE') {
        // 1) remove os agendamentos de hoje em diante (o histórico passado é preservado)
        $pdo->prepare('DELETE FROM agendamento WHERE id_medico = ? AND data >= ?')->execute([$id, $hoje]);

        // 2) com histórico: desativa; sem histórico: apaga de vez
        $st = $pdo->prepare(
            'WITH h AS (SELECT EXISTS (SELECT 1 FROM agendamento WHERE id_medico = ?) AS tem),
                  u AS (UPDATE medico SET ativo = FALSE WHERE id_medico = ? AND ativo = TRUE AND (SELECT tem FROM h) RETURNING id_medico),
                  d AS (DELETE FROM medico WHERE id_medico = ? AND ativo = TRUE AND NOT (SELECT tem FROM h) RETURNING id_medico)
             SELECT (SELECT COUNT(*) FROM u) + (SELECT COUNT(*) FROM d) AS n'
        );
        $st->execute([$id, $id, $id]);
        if ((int)$st->fetchColumn() === 0) {
            throw new HttpError('Médico não encontrado.', 404);
        }
        responder(['ok' => true]);
    }

    throw new HttpError('Método não permitido.', 405);
}
