<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
try {
    transaction(function (): void {
        query('SELECT pg_advisory_xact_lock(73451001)');
        $tables = ['medico','abreviacao','usuario','medico_dia_semana','agendamento','escala','escala_profissional'];
        $existing = 0;
        foreach ($tables as $table) if (query('SELECT to_regclass(?)', [$table])->fetchColumn() !== null) $existing++;
        if ($existing === 0) db()->exec(file_get_contents(ROOT . '/database/schema.sql'));
        elseif ($existing !== count($tables)) throw new RuntimeException('Esquema parcial: revise as sete tabelas originais antes de migrar.');
        db()->exec('CREATE TABLE IF NOT EXISTS app_migration (versao VARCHAR(80) PRIMARY KEY, aplicada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        if (!query('SELECT 1 FROM app_migration WHERE versao = ?', ['001_complementos'])->fetchColumn()) {
            db()->exec(file_get_contents(ROOT . '/database/001_complementos.sql'));
            query('INSERT INTO app_migration (versao) VALUES (?)', ['001_complementos']);
        }
    });
    echo "Banco atualizado. Execute php bin/create-admin.php para cadastrar o primeiro administrador.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Falha na migração: " . $e->getMessage() . "\nNenhum dado existente foi removido. Se há duplicidades de médico/data, escala/data ou e-mail, resolva-as e execute novamente.\n");
    exit(1);
}
