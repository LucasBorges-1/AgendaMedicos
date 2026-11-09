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
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e(month_title($month)) ?> · Clínica Vidas</title><link rel="stylesheet" href="css/styles.css"><script src="js/print.js" defer></script></head><body><main>
<div class="print-actions no-print"><a class="btn btn-ghost" href="index.php#calendario">Voltar à agenda</a><button class="btn btn-primary" id="printPage">Imprimir</button></div>
<div class="sheet"><p class="print-brand">Clínica Vidas · Agenda médica</p><h1 class="sheet-title"><?= e(month_title($month)) ?></h1><div class="grid">
<?php foreach (['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'] as $name): ?><div class="dow"><?= $name ?></div><?php endforeach ?>
<?php for ($i=0;$i<(int)ceil(($start+$total)/7)*7;$i++): $day=$i-$start+1; if ($day<1||$day>$total): ?><div class="cell out"></div><?php continue; endif; $date=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT); $items=$days[$date]??[]; ?>
<div class="cell"><span class="num"><?= $day ?></span><?php if (isset($items['feriado'])): ?><div class="feriado"><?= e($items['feriado']['descricao']) ?></div><?php endif ?><?php if(isset($items['escala'])): ?><div class="escala"><?= e(implode(' · ',array_column($items['escala']['profissionais'],'nome_profissional'))) ?></div><?php endif ?><div class="names"><?php foreach($items['agendamentos']??[] as $item): ?><div class="<?= $item['status']==='CANCELADO'?'cancelled':'' ?>"><?= e(appointment_label($item)) ?></div><?php endforeach ?></div></div>
<?php endfor ?>
<div class="notes full"><b>Legenda das abreviações</b><?php foreach($data['abreviacoes'] as $item): ?><span class="legend-item"><strong class="tag">(<?= e($item['sigla']) ?>)</strong> <?= e($item['descricao']) ?> · </span><?php endforeach ?></div></div></div>
<section class="print-details"><h1>Detalhes da agenda</h1><?php if(!$days): ?><p>Nenhum registro neste mês.</p><?php endif ?><?php foreach($days as $date=>$items): ?><article><h2><?= e((new DateTimeImmutable($date))->format('d/m/Y')) ?></h2><?php if(isset($items['feriado'])): ?><p>Feriado: <?= e($items['feriado']['descricao']) ?></p><?php endif ?><?php if(isset($items['escala'])): ?><p>Escala: <?= e(implode(' · ',array_column($items['escala']['profissionais'],'nome_profissional'))) ?></p><p><?= e($items['escala']['observacao']) ?></p><?php endif ?><?php foreach($items['agendamentos']??[] as $item): ?><p><strong><?= e(appointment_label($item)) ?></strong></p><?php if($item['observacao']): ?><p><?= e($item['observacao']) ?></p><?php endif ?><?php endforeach ?></article><?php endforeach ?></section>
</main></body></html>
