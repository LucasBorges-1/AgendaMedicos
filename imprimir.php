<?php
declare(strict_types=1);
require __DIR__ . '/app/services.php';
require __DIR__ . '/app/print-view.php';
require_page_user();
try { $data=calendar_data($_GET['mes']??date('Y-m')); }
catch (ApiError $e) { http_response_code($e->status); exit(e($e->getMessage())); }
catch (Throwable $e) { error_log($e->getMessage()); http_response_code(503); exit('Agenda indisponível. Tente novamente.'); }
$month=$data['mes']; $days=grouped_days($data); $first=new DateTimeImmutable($month.'-01');
$start=(int)$first->format('w'); $total=(int)$first->format('t');
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e(month_title($month)) ?> · Clínica Vidas</title><link rel="stylesheet" href="css/styles.css"><link rel="stylesheet" href="css/print.css"><script src="js/print.js" defer></script></head><body class="print-page"><main>
<div class="print-actions no-print"><a class="btn btn-ghost" href="index.php#calendario">Voltar à agenda</a><p id="printHint" role="status">A4 em paisagem · uma folha</p><button class="btn btn-primary" id="printPage">Imprimir</button></div>
<div class="print-sheet"><h1><?= e(month_title($month)) ?></h1><div class="print-grid" aria-label="Agenda mensal">
<?php foreach (['Domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'] as $name): ?><div class="print-dow"><?= $name ?></div><?php endforeach ?>
<?php for ($i=0;$i<37;$i++): $day=$i-$start+1; $current=$day>=1&&$day<=$total; $date=$first->modify(($day-1).' days')->format('Y-m-d'); $items=$current?($days[$date]??[]):[]; ?>
<div class="print-cell <?= $current?'':'outside' ?>"><span class="print-num"><?= (int)substr($date,8,2) ?></span><div class="print-content">
<?php if (isset($items['feriado'])): ?><div class="print-holiday"><?= e($items['feriado']['descricao']) ?></div><?php endif ?>
<?php if(isset($items['escala'])): ?><div class="print-scale"><?= e(implode(' - ',array_column($items['escala']['profissionais'],'nome_profissional'))) ?></div><?php endif ?>
<?php foreach($items['agendamentos']??[] as $item): ?><div class="print-name <?= $item['status']==='CANCELADO'?'cancelled':'' ?>"><?= e($item['medico_nome']) ?><?php if($item['sigla']): ?><span class="print-tag">(<?= e($item['sigla']) ?>)</span><?php endif ?><?php if($item['status']!=='AGENDADO'): ?><span class="print-status"> <?= e(['CONFIRMADO'=>'Confirmado','CANCELADO'=>'Cancelado','CONCLUIDO'=>'Concluído'][$item['status']]) ?></span><?php endif ?></div><?php endforeach ?>
</div></div>
<?php endfor ?>
<div class="print-notes"><div class="print-content"><div class="print-notes-title">Anotações</div><span>O SIGNIFICADO DAS ABREVIAÇÕES: </span><?php foreach($data['abreviacoes'] as $item): ?><span class="print-legend"><span class="print-tag">(<?= e($item['sigla']) ?>)</span> <?= e($item['descricao']) ?> · </span><?php endforeach ?>
<?php foreach(print_observations($data) as $note): ?><span class="print-observation"> <?= e($note) ?> · </span><?php endforeach ?>
</div></div></div></div>
</main></body></html>
