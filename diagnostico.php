<?php
/* Diagnóstico de instalação · acessível apenas a partir do próprio computador.
   Abra: http://127.0.0.1:8000/diagnostico.php   (apague em produção) */
if (!in_array(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Disponível somente no próprio servidor.');
}
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/api/config.php';

$itens = [];
function item(&$itens, $ok, $titulo, $detalhe = '') { $itens[] = [$ok, $titulo, $detalhe]; }

item($itens, version_compare(PHP_VERSION, '7.4.0', '>='), 'Versão do PHP', PHP_VERSION . ' (mínimo 7.4)');
item($itens, extension_loaded('pdo_pgsql'), 'Extensão pdo_pgsql', extension_loaded('pdo_pgsql') ? 'carregada' : 'ATIVE "extension=pdo_pgsql" no php.ini');
item($itens, extension_loaded('mbstring'), 'Extensão mbstring', extension_loaded('mbstring') ? 'carregada' : 'ATIVE "extension=mbstring" no php.ini');
$sess = session_save_path() ?: sys_get_temp_dir();
item($itens, is_writable($sess), 'Pasta de sessões gravável', $sess);
item($itens, true, 'Servidor', PHP_SAPI . (PHP_SAPI === 'cli-server' ? ' (embutido; getenv PHP_CLI_SERVER_WORKERS=' . (getenv('PHP_CLI_SERVER_WORKERS') ?: 'não definido') . ')' : ''));
item($itens, true, 'Configuração do banco', DB_USER . '@' . DB_HOST . ':' . DB_PORT . ' / ' . DB_NAME);

if (extension_loaded('pdo_pgsql')) {
    $t = microtime(true);
    try {
        $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5', DB_HOST, DB_PORT, DB_NAME),
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        item($itens, true, 'Conexão com o PostgreSQL', round((microtime(true) - $t) * 1000) . ' ms');
        $esperadas = ['usuario','medico','medico_dia_semana','abreviacao','agendamento','escala','escala_profissional'];
        $tem = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()")->fetchAll(PDO::FETCH_COLUMN);
        $falta = array_diff($esperadas, $tem);
        item($itens, !$falta, 'Tabelas do schema.sql', $falta ? 'faltando: ' . implode(', ', $falta) : 'todas as 7 existem');
        if (!$falta) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM usuario WHERE papel='adm' AND ativo=TRUE")->fetchColumn();
            item($itens, $n > 0, 'Administrador', $n > 0 ? "$n ativo(s)" : 'nenhum — a tela "Primeiro acesso" vai aparecer');
        }
    } catch (Throwable $e) {
        item($itens, false, 'Conexão com o PostgreSQL', $e->getMessage());
    }
}
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Diagnóstico</title>
<style>body{font:16px system-ui;max-width:760px;margin:30px auto;padding:0 16px}li{margin:8px 0}.ok{color:#0a7a38}.er{color:#c00}small{color:#555}</style></head>
<body><h1>Diagnóstico · Clínica Vidas</h1><ul>
<?php foreach ($itens as $i): ?>
<li class="<?= $i[0] ? 'ok' : 'er' ?>"><?= $i[0] ? '✔' : '✘' ?> <b><?= htmlspecialchars($i[1]) ?></b> <small><?= htmlspecialchars($i[2]) ?></small></li>
<?php endforeach; ?>
</ul></body></html>
