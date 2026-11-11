<?php
/* Painel: tudo que a tela precisa para um mês, em UMA consulta ao banco
   (legenda, médicos, agendamentos, feriados e escala dos domingos). */

function painel_dados($ano, $mes, $papel)
{
    $ini = sprintf('%04d-%02d-01', $ano, $mes);
    $fim = (new DateTime($ini))->modify('last day of this month')->format('Y-m-d');

    $vazio = "CAST('[]' AS JSON)";
    $medicosSql = ($papel === 'viewer') ? $vazio : "(SELECT COALESCE(json_agg(t ORDER BY t.nome), $vazio) FROM (
            SELECT m.id_medico AS id, m.nome, m.tipo,
                   COALESCE((SELECT json_agg(d.dia_semana ORDER BY d.dia_semana)
                               FROM medico_dia_semana d WHERE d.id_medico = m.id_medico), $vazio) AS dias
              FROM medico m WHERE m.ativo = TRUE) t)";

    $sql = "SELECT
        (SELECT COALESCE(json_agg(t ORDER BY t.data, t.id), $vazio) FROM (
            SELECT a.id_agendamento AS id, a.id_medico, a.data, a.observacao, a.status, m.nome AS medico, ab.sigla
              FROM agendamento a
         LEFT JOIN medico m      ON m.id_medico = a.id_medico
         LEFT JOIN abreviacao ab ON ab.id_abreviacao = a.id_abreviacao
             WHERE a.data BETWEEN ? AND ?) t) AS agend,
        (SELECT COALESCE(json_agg(t ORDER BY t.data, t.ordem), $vazio) FROM (
            SELECT e.data, p.nome_profissional AS nome, p.ordem
              FROM escala e JOIN escala_profissional p ON p.id_escala = e.id_escala
             WHERE e.data BETWEEN ? AND ?) t) AS escala,
        (SELECT COALESCE(json_agg(t ORDER BY t.id), $vazio) FROM (
            SELECT id_abreviacao AS id, sigla, descricao FROM abreviacao) t) AS abrevs,
        $medicosSql AS medicos";

    $st = db()->prepare($sql);
    $st->execute([$ini, $fim, $ini, $fim]);
    $r = $st->fetch();

    $dias = [];
    $feriados = [];
    foreach (json_decode($r['agend'], true) ?: [] as $a) {
        $dia = (int)substr($a['data'], 8, 2);
        if ($a['status'] === 'feriado') {
            $feriados[$dia] = true;
            continue;
        }
        $dias[$dia][] = [
            'id'         => (int)$a['id'],
            'id_medico'  => (int)$a['id_medico'],
            'medico'     => $a['medico'],
            'sigla'      => $a['sigla'],
            'observacao' => $a['observacao'],
            'status'     => $a['status'],
        ];
    }

    $porData = [];   // 'Y-m-d' => [ordem => nome]
    foreach (json_decode($r['escala'], true) ?: [] as $e) {
        $porData[$e['data']][(int)$e['ordem']] = $e['nome'];
    }
    $escala = [];
    foreach ($porData as $data => $nomes) {
        ksort($nomes);
        $escala[(int)substr($data, 8, 2)] = array_values($nomes);
    }

    $domingos = [];
    $d = new DateTime($ini);
    $limite = new DateTime($fim);
    for (; $d <= $limite; $d->modify('+1 day')) {
        if ($d->format('w') === '0') {
            $k = $d->format('Y-m-d');
            $domingos[] = [
                'data'    => $k,
                'dia'     => (int)$d->format('j'),
                'pessoas' => [
                    isset($porData[$k][1]) ? $porData[$k][1] : '',
                    isset($porData[$k][2]) ? $porData[$k][2] : '',
                ],
            ];
        }
    }

    $medicos = [];
    foreach (json_decode($r['medicos'], true) ?: [] as $m) {
        $medicos[] = [
            'id'   => (int)$m['id'],
            'nome' => $m['nome'],
            'tipo' => $m['tipo'],
            'dias' => array_map('intval', $m['dias'] ?: []),
        ];
    }

    return [
        'ano'         => $ano,
        'mes'         => $mes,
        'abreviacoes' => json_decode($r['abrevs'], true) ?: [],
        'medicos'     => $medicos,
        'dias'        => (object)$dias,
        'feriados'    => (object)$feriados,
        'escala'      => (object)$escala,
        'domingos'    => $domingos,
    ];
}

function rota_painel($m)
{
    if ($m !== 'GET') {
        throw new HttpError('Método não permitido.', 405);
    }
    $u = exigir_login();
    list($ano, $mes) = ano_mes($_GET);
    responder(painel_dados($ano, $mes, $u['papel']));
}
