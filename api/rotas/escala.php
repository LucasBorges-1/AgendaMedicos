<?php
/* Escala do cardiologista: um registro por domingo, com até 2 pessoas */

function escala_domingos($ano, $mes)
{
    $d   = new DateTime(sprintf('%04d-%02d-01', $ano, $mes));
    $fim = (new DateTime($d->format('Y-m-d')))->modify('last day of this month');
    $lista = [];
    for (; $d <= $fim; $d->modify('+1 day')) {
        if ($d->format('w') === '0') {
            $lista[] = $d->format('Y-m-d');
        }
    }
    return $lista;
}

function rota_escala($m)
{
    $pdo = db();

    /* ---- Listar os domingos do mês com as pessoas escaladas ---- */
    if ($m === 'GET') {
        exigir_login();
        list($ano, $mes) = ano_mes($_GET);
        $domingos = escala_domingos($ano, $mes);

        $st = $pdo->prepare(
            'SELECT e.data, p.nome_profissional, p.ordem
               FROM escala e
               JOIN escala_profissional p ON p.id_escala = e.id_escala
              WHERE e.data BETWEEN ? AND ?'
        );
        $ini = sprintf('%04d-%02d-01', $ano, $mes);
        $fim = (new DateTime($ini))->modify('last day of this month')->format('Y-m-d');
        $st->execute([$ini, $fim]);
        $mapa = [];
        foreach ($st->fetchAll() as $r) {
            $mapa[$r['data']][(int)$r['ordem']] = $r['nome_profissional'];
        }

        $saida = [];
        foreach ($domingos as $d) {
            $saida[] = [
                'data'    => $d,
                'dia'     => (int)substr($d, 8, 2),
                'pessoas' => [
                    isset($mapa[$d][1]) ? $mapa[$d][1] : '',
                    isset($mapa[$d][2]) ? $mapa[$d][2] : '',
                ],
            ];
        }
        responder(['domingos' => $saida]);
    }

    /* ---- Salvar a escala do mês. Corpo: {ano, mes, linhas:[{data, pessoas:[a,b]}]} ---- */
    if ($m === 'PUT') {
        $eu = exigir_papel(['adm', 'editor']);
        $in = entrada();
        list($ano, $mes) = ano_mes($in);
        $validos = array_flip(escala_domingos($ano, $mes));
        $linhas  = (isset($in['linhas']) && is_array($in['linhas'])) ? $in['linhas'] : [];

        $datas = [];    // todas as datas enviadas
        $cheias = [];   // [data, nome1, nome2] das linhas com alguém escalado
        foreach ($linhas as $l) {
            $data = (is_array($l) && isset($l['data'])) ? $l['data'] : '';
            if (!data_valida($data) || !isset($validos[$data])) {
                throw new HttpError('Data de escala inválida (use os domingos do mês).', 422);
            }
            $pessoas = (isset($l['pessoas']) && is_array($l['pessoas'])) ? array_values($l['pessoas']) : [];
            $n = [];
            for ($i = 0; $i < 2; $i++) {
                $n[$i] = mb_strtoupper(texto(isset($pessoas[$i]) ? $pessoas[$i] : '', 120));
            }
            $datas[$data] = true;
            if ($n[0] !== '' || $n[1] !== '') {
                $cheias[$data] = $n;
            }
        }
        if (!$datas) {
            responder(['ok' => true]);
        }

        $pdo->beginTransaction();
        try {
            // 1) apaga a escala atual dessas datas (as pessoas saem junto, por CASCADE)
            $ph = implode(',', array_fill(0, count($datas), '?'));
            $pdo->prepare("DELETE FROM escala WHERE data IN ($ph)")->execute(array_keys($datas));

            if ($cheias) {
                // 2) insere todas as datas preenchidas de uma vez
                $vals = [];
                $par  = [];
                foreach ($cheias as $data => $n) {
                    $vals[] = '(CAST(? AS DATE), ?, NULL)';
                    $par[]  = $data;
                    $par[]  = $eu['id_usuario'];
                }
                $st = $pdo->prepare('INSERT INTO escala (data, id_usuario, observacao) VALUES ' . implode(',', $vals) . ' RETURNING id_escala, data');
                $st->execute($par);
                $ids = [];
                foreach ($st->fetchAll() as $r) {
                    $ids[$r['data']] = (int)$r['id_escala'];
                }

                // 3) insere todas as pessoas de uma vez
                $vals = [];
                $par  = [];
                foreach ($cheias as $data => $n) {
                    foreach ($n as $i => $nome) {
                        if ($nome !== '') {
                            $vals[] = '(?, ?, ?)';
                            $par[]  = $ids[$data];
                            $par[]  = $nome;
                            $par[]  = $i + 1;
                        }
                    }
                }
                $pdo->prepare('INSERT INTO escala_profissional (id_escala, nome_profissional, ordem) VALUES ' . implode(',', $vals))->execute($par);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        responder(['ok' => true]);
    }

    throw new HttpError('Método não permitido.', 405);
}
