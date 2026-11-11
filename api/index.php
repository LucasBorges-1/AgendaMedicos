<?php
/* =====================================================================
   Roteador único da API:   api/index.php?r=<rota>[&id=..][&ano=..&mes=..]
   O verbo HTTP (GET/POST/PUT/DELETE) define a operação.
   ===================================================================== */

require __DIR__ . '/bootstrap.php';

$rotas = [
    'me'           => ['auth.php',         'rota_me'],
    'login'        => ['auth.php',         'rota_login'],
    'logout'       => ['auth.php',         'rota_logout'],
    'senha'        => ['auth.php',         'rota_senha'],
    'setup'        => ['auth.php',         'rota_setup'],
    'usuarios'     => ['usuarios.php',     'rota_usuarios'],
    'medicos'      => ['medicos.php',      'rota_medicos'],
    'abreviacoes'  => ['abreviacoes.php',  'rota_abreviacoes'],
    'painel'       => ['painel.php',       'rota_painel'],
    'calendario'   => ['calendario.php',   'rota_calendario'],
    'agendamentos' => ['agendamentos.php', 'rota_agendamentos'],
    'feriados'     => ['agendamentos.php', 'rota_feriados'],
    'fixos'        => ['agendamentos.php', 'rota_fixos'],
    'escala'       => ['escala.php',       'rota_escala'],
];

$r = (isset($_GET['r']) && is_string($_GET['r'])) ? $_GET['r'] : '';
if (!isset($rotas[$r])) {
    throw new HttpError('Rota não encontrada.', 404);
}

$metodo = strtoupper($_SERVER['REQUEST_METHOD']);
if ($metodo !== 'GET' && $metodo !== 'HEAD') {
    exigir_csrf();
}

require __DIR__ . '/rotas/' . $rotas[$r][0];
$rotas[$r][1]($metodo);
