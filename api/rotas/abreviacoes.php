<?php
/* Legenda de abreviações (leitura para qualquer usuário logado) */

function rota_abreviacoes($m)
{
    if ($m !== 'GET') {
        throw new HttpError('Método não permitido.', 405);
    }
    exigir_login();
    $linhas = db()->query('SELECT id_abreviacao, sigla, descricao FROM abreviacao ORDER BY id_abreviacao')->fetchAll();
    $saida = [];
    foreach ($linhas as $l) {
        $saida[] = ['id' => (int)$l['id_abreviacao'], 'sigla' => $l['sigla'], 'descricao' => $l['descricao']];
    }
    responder(['abreviacoes' => $saida]);
}
