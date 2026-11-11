<?php
/* Geração dos dias fixos dos médicos no calendário */

/**
 * Cria, em UMA instrução SQL, os agendamentos dos médicos de "dias fixos" entre $de e $ate (Y-m-d).
 * Não duplica dias já existentes e pula feriados. Retorna quantos foram criados.
 */
function aplicar_dias_fixos(PDO $pdo, $idUsuario, $de, $ate, $idMedico = null)
{
    $sql = "INSERT INTO agendamento (id_medico, id_usuario, id_abreviacao, data, observacao, status)
            SELECT m.id_medico, CAST(? AS INTEGER), CAST(NULL AS INTEGER), CAST(g.dia AS DATE), CAST(NULL AS VARCHAR), 'confirmado'
              FROM medico m
              JOIN medico_dia_semana d ON d.id_medico = m.id_medico
        CROSS JOIN generate_series(CAST(? AS TIMESTAMP), CAST(? AS TIMESTAMP), INTERVAL '1 day') AS g(dia)
             WHERE m.ativo = TRUE AND m.tipo = 'fixo'
               AND CAST(EXTRACT(DOW FROM g.dia) AS INTEGER) = d.dia_semana";
    $par = [(int)$idUsuario, $de, $ate];
    if ($idMedico !== null) {
        $sql .= ' AND m.id_medico = ?';
        $par[] = (int)$idMedico;
    }
    $sql .= " AND NOT EXISTS (SELECT 1 FROM agendamento f WHERE f.data = CAST(g.dia AS DATE) AND f.status = 'feriado')
              AND NOT EXISTS (SELECT 1 FROM agendamento a WHERE a.id_medico = m.id_medico AND a.data = CAST(g.dia AS DATE))
         ORDER BY g.dia, m.id_medico";
    $st = $pdo->prepare($sql);
    $st->execute($par);
    return $st->rowCount();
}
