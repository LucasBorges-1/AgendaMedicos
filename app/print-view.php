<?php
declare(strict_types=1);
function month_title(string $month): string {
    $names=['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    return $names[(int)substr($month,5,2)-1] . ' de ' . substr($month,0,4);
}
function appointment_label(array $item): string {
    return $item['medico_nome'] . ($item['sigla'] ? ' (' . $item['sigla'] . ')' : '') . ($item['status']!=='AGENDADO' ? ' — ' . ['CONFIRMADO'=>'Confirmado','CANCELADO'=>'Cancelado','CONCLUIDO'=>'Concluído'][$item['status']] : '');
}
function grouped_days(array $data): array {
    $days=[];
    foreach ($data['agendamentos'] as $item) $days[$item['data']]['agendamentos'][]=$item;
    foreach ($data['escalas'] as $item) $days[$item['data']]['escala']=$item;
    foreach ($data['feriados'] as $item) $days[$item['data']]['feriado']=$item;
    ksort($days);
    return $days;
}

function print_observations(array $data): array {
    $notes=[];
    foreach(grouped_days($data) as $date=>$items) {
        $day=(new DateTimeImmutable($date))->format('d');
        if (!empty($items['escala']['observacao'])) $notes[]=$day.' · Escala: '.$items['escala']['observacao'];
        foreach($items['agendamentos']??[] as $item) {
            if (!empty($item['observacao'])) $notes[]=$day.' · '.$item['medico_nome'].': '.$item['observacao'];
        }
    }
    return $notes;
}
