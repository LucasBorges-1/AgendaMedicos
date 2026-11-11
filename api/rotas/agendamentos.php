<?php
/* Agendamentos (médico em um dia), feriados e aplicação dos dias fixos */

require_once __DIR__ . '/_fixos.php';

function rota_agendamentos($m)
{
    $eu  = exigir_papel(['adm', 'editor']);
    $pdo = db();

    /* ---- Adicionar médico a um dia ---- */
    if ($m === 'POST') {
        $in       = entrada();
        $idMedico = isset($in['id_medico']) ? (int)$in['id_medico'] : 0;
        $data     = isset($in['data']) ? $in['data'] : '';
        if (!data_valida($data)) {
            throw new HttpError('Data inválida.', 422);
        }

        $idAb = null;
        if (isset($in['id_abreviacao']) && $in['id_abreviacao'] !== '' && $in['id_abreviacao'] !== null) {
            $idAb = (int)$in['id_abreviacao'];
        }
        $obs = mb_strtoupper(texto(isset($in['observacao']) ? $in['observacao'] : '', 255));
        if ($obs === '') {
            $obs = null;
        }

        // Uma única consulta valida médico, abreviação e duplicidade
        $st = $pdo->prepare(
            'SELECT (SELECT nome  FROM medico     WHERE id_medico = ? AND ativo = TRUE) AS medico,
                    (SELECT sigla FROM abreviacao WHERE id_abreviacao = CAST(? AS INTEGER)) AS sigla,
                    EXISTS (SELECT 1 FROM agendamento
                             WHERE id_medico = ? AND data = ? AND id_abreviacao IS NOT DISTINCT FROM CAST(? AS INTEGER)) AS dup'
        );
        $st->execute([$idMedico, $idAb, $idMedico, $data, $idAb]);
        $v = $st->fetch();
        if ($v['medico'] === null) {
            throw new HttpError('Selecione um médico válido.', 422);
        }
        if ($idAb !== null && $v['sigla'] === null) {
            throw new HttpError('Observação inválida.', 422);
        }
        if (como_bool($v['dup'])) {
            throw new HttpError('Este médico já está neste dia com a mesma observação.', 409);
        }

        $status = ($v['sigla'] === 'CANCEL') ? 'cancelado' : 'confirmado';
        $ins = $pdo->prepare(
            'INSERT INTO agendamento (id_medico, id_usuario, id_abreviacao, data, observacao, status)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id_agendamento'
        );
        $ins->execute([$idMedico, $eu['id_usuario'], $idAb, $data, $obs, $status]);

        responder(['item' => [
            'id'         => (int)$ins->fetchColumn(),
            'id_medico'  => $idMedico,
            'medico'     => $v['medico'],
            'sigla'      => $v['sigla'],
            'observacao' => $obs,
            'status'     => $status,
        ]], 201);
    }

    /* ---- Remover médico de um dia ---- */
    if ($m === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $st = $pdo->prepare("DELETE FROM agendamento WHERE id_agendamento = ? AND status <> 'feriado'");
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            throw new HttpError('Agendamento não encontrado.', 404);
        }
        responder(['ok' => true]);
    }

    throw new HttpError('Método não permitido.', 405);
}

/** Marca ou desmarca um dia como feriado. Corpo: {data, feriado: true|false} */
function rota_feriados($m)
{
    $eu  = exigir_papel(['adm', 'editor']);
    $pdo = db();
    if ($m !== 'POST') {
        throw new HttpError('Método não permitido.', 405);
    }
    $in   = entrada();
    $data = isset($in['data']) ? $in['data'] : '';
    if (!data_valida($data)) {
        throw new HttpError('Data inválida.', 422);
    }

    if (!empty($in['feriado'])) {
        $pdo->prepare(
            "INSERT INTO agendamento (id_medico, id_usuario, id_abreviacao, data, observacao, status)
             SELECT CAST(NULL AS INTEGER), CAST(? AS INTEGER), CAST(NULL AS INTEGER), CAST(? AS DATE), CAST(NULL AS VARCHAR), 'feriado'
              WHERE NOT EXISTS (SELECT 1 FROM agendamento WHERE data = CAST(? AS DATE) AND status = 'feriado')"
        )->execute([$eu['id_usuario'], $data, $data]);
    } else {
        $pdo->prepare("DELETE FROM agendamento WHERE data = ? AND status = 'feriado'")->execute([$data]);
    }
    responder(['ok' => true]);
}

/** Aplica os dias fixos de todos os médicos ao ano inteiro. Corpo: {ano} */
function rota_fixos($m)
{
    $eu  = exigir_papel(['adm', 'editor']);
    if ($m !== 'POST') {
        throw new HttpError('Método não permitido.', 405);
    }
    $in  = entrada();
    $ano = isset($in['ano']) ? (int)$in['ano'] : 0;
    if ($ano < 2000 || $ano > 2100) {
        throw new HttpError('Ano inválido.', 422);
    }
    $criados = aplicar_dias_fixos(db(), $eu['id_usuario'], "$ano-01-01", "$ano-12-31");
    responder(['agendamentos_criados' => $criados]);
}
