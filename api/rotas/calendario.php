<?php
/* Dados de um mês: médicos por dia, feriados e escala dos domingos */

function rota_calendario($m)
{
    if ($m !== 'GET') {
        throw new HttpError('Método não permitido.', 405);
    }
    exigir_login();
    list($ano, $mes) = ano_mes($_GET);
    $pdo = db();

    $ini = sprintf('%04d-%02d-01', $ano, $mes);
    $fim = (new DateTime($ini))->modify('last day of this month')->format('Y-m-d');

    $st = $pdo->prepare(
        'SELECT a.id_agendamento, a.id_medico, a.data, a.observacao, a.status, m.nome AS medico, ab.sigla
           FROM agendamento a
      LEFT JOIN medico m      ON m.id_medico = a.id_medico
      LEFT JOIN abreviacao ab ON ab.id_abreviacao = a.id_abreviacao
          WHERE a.data BETWEEN ? AND ?
       ORDER BY a.data, a.id_agendamento'
    );
    $st->execute([$ini, $fim]);

    $dias = [];       // dia => [ {id, id_medico, medico, sigla, observacao, status}, ... ]
    $feriados = [];   // dia => true
    foreach ($st->fetchAll() as $r) {
        $dia = (int)substr($r['data'], 8, 2);
        if ($r['status'] === 'feriado') {
            $feriados[$dia] = true;
            continue;
        }
        $dias[$dia][] = [
            'id'         => (int)$r['id_agendamento'],
            'id_medico'  => (int)$r['id_medico'],
            'medico'     => $r['medico'],
            'sigla'      => $r['sigla'],
            'observacao' => $r['observacao'],
            'status'     => $r['status'],
        ];
    }

    $st = $pdo->prepare(
        'SELECT e.data, p.nome_profissional
           FROM escala e
           JOIN escala_profissional p ON p.id_escala = e.id_escala
          WHERE e.data BETWEEN ? AND ?
       ORDER BY e.data, p.ordem'
    );
    $st->execute([$ini, $fim]);
    $escala = [];     // dia => [nomes]
    foreach ($st->fetchAll() as $r) {
        $escala[(int)substr($r['data'], 8, 2)][] = $r['nome_profissional'];
    }

    responder([
        'ano'       => $ano,
        'mes'       => $mes,
        'dias'      => (object)$dias,
        'feriados'  => (object)$feriados,
        'escala'    => (object)$escala,
    ]);
}
