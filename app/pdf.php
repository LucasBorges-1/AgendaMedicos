<?php
declare(strict_types=1);
// PDF 1.4 com fontes padrão: dispensa Composer, extensões gráficas e serviços externos.
final class AgendaPdf {
    private array $pages=[];
    private string $stream='';
    public function page(): void {
        if ($this->stream!=='') $this->pages[]=$this->stream;
        $this->stream='q 1 1 1 rg 0 0 842 595 re f Q' . "\n";
    }
    public function text(float $x,float $top,string $text,int $size=9,bool $bold=false,array $color=[0.12,0.17,0.13]): void {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT',$text);
        $encoded=str_replace(["\\",'(',')',"\r","\n"],["\\\\","\\(","\\)",' ',' '],$encoded===false?'?':$encoded);
        $encoded=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$encoded);
        $this->stream.=sprintf("BT /%s %d Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",$bold?'F2':'F1',$size,...[...$color,$x,595-$top-$size,$encoded]);
    }
    public function box(float $x,float $top,float $width,float $height,array $fill=[1,1,1]): void {
        $this->stream.=sprintf("q %.3F %.3F %.3F rg 0.65 0.78 0.55 RG 0.5 w %.2F %.2F %.2F %.2F re B Q\n",...array_merge($fill,[$x,595-$top-$height,$width,$height]));
    }
    public function output(): string {
        if ($this->stream!=='') $this->pages[]=$this->stream;
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids=[];
        foreach($this->pages as $index=>$stream) {
            $page=5+$index*2; $content=$page+1; $kids[]="$page 0 R";
            $objects[$page]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $content 0 R >>";
            $objects[$content]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
        }
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';
        ksort($objects); $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets=[0];
        foreach($objects as $number=>$object) { $offsets[$number]=strlen($pdf); $pdf.="$number 0 obj\n$object\nendobj\n"; }
        $xref=strlen($pdf); $pdf.='xref' . "\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        foreach(array_slice($offsets,1) as $offset) $pdf.=sprintf("%010d 00000 n \n",$offset);
        return $pdf.'trailer' . "\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}

function pdf_char_width(string $character,int $size,bool $bold): float {
    // Limites conservadores para Helvetica/Helvetica-Bold, em pontos.
    $ratio=match (true) {
        $character===' ' => 0.28,
        str_contains('mwMW@%&',$character) => 1.02,
        str_contains('ijlI.,:;!|',$character) => 0.35,
        str_contains('ft()[]/-',$character) => 0.40,
        (bool)preg_match('/^\p{Lu}$/u',$character) => 0.80,
        (bool)preg_match('/^[0-9]$/',$character) => 0.57,
        in_array($character,['—','…','→'],true) => 1.0,
        default => $bold ? 0.65 : 0.62,
    };
    return $size*$ratio;
}

function pdf_wrap(string $text,float $width,int $size=9,bool $bold=false): array {
    $lines=[];
    foreach(explode("\n",str_replace("\r",'', $text)) as $paragraph) {
        $characters=preg_split('//u',$paragraph,-1,PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$characters) { $lines[]=''; continue; }
        while ($characters) {
            $used=0; $end=0; $lastSpace=null;
            foreach($characters as $index=>$character) {
                $used+=pdf_char_width($character,$size,$bold);
                if($used>$width && $index>0) break;
                $end=$index+1;
                if($character===' ') $lastSpace=$index;
            }
            if($end===count($characters)) break;
            if($lastSpace!==null && $lastSpace>$end/2) $end=$lastSpace;
            $lines[]=implode('',array_splice($characters,0,$end));
            if (($characters[0]??'')===' ') array_shift($characters);
        }
        if ($characters) $lines[]=implode('',$characters);
    }
    return $lines;
}

function build_calendar_pdf(array $data): string {
    $pdf=new AgendaPdf(); $pdf->page();
    $pdf->text(28,22,'CLÍNICA VIDAS · AGENDA MÉDICA',10,true,[0.12,0.37,0.17]);
    $pdf->text(28,40,month_title($data['mes']),22,true,[0.12,0.37,0.17]);
    $pdf->text(28,70,'Calendário mensal. Os detalhes, status e observações completos estão nas próximas páginas.',8);
    $days=grouped_days($data); $first=new DateTimeImmutable($data['mes'].'-01');
    $start=(int)$first->format('w'); $total=(int)$first->format('t'); $weeks=(int)ceil(($start+$total)/7);
    $width=786/7; $height=426/$weeks;
    foreach(['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'] as $column=>$label) {
        $pdf->box(28+$column*$width,91,$width,22,[0.725,0.855,0.62]);
        $pdf->text(34+$column*$width,97,$label,9,true);
    }
    for($i=0;$i<$weeks*7;$i++) {
        $day=$i-$start+1; $x=28+($i%7)*$width; $top=113+intdiv($i,7)*$height;
        $pdf->box($x,$top,$width,$height,($day<1||$day>$total)?[0.97,0.98,0.96]:[1,1,1]);
        if($day<1||$day>$total) continue;
        $pdf->text($x+$width-18,$top+3,(string)$day,8,true);
        $date=$data['mes'].'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT); $items=$days[$date]??[]; $lines=[];
        if(isset($items['feriado'])) foreach(pdf_wrap('FERIADO: '.$items['feriado']['descricao'],$width-8,7,true) as $line) $lines[]=[$line,[0.8,0.08,0.1]];
        if(isset($items['escala'])) foreach(pdf_wrap('Escala: '.implode(' / ',array_column($items['escala']['profissionais'],'nome_profissional')),$width-8,7,true) as $line) $lines[]=[$line,[0.8,0.08,0.1]];
        foreach($items['agendamentos']??[] as $item) foreach(pdf_wrap(appointment_label($item),$width-8,7,true) as $line) $lines[]=[$line,$item['status']==='CANCELADO'?[0.55,0.55,0.55]:[0.08,0.1,0.08]];
        $limit=(int)floor(($height-20)/9); $overflow=count($lines)>$limit;
        foreach(array_slice($lines,0,$overflow?$limit-1:$limit) as $lineIndex=>[$line,$color]) $pdf->text($x+4,$top+17+$lineIndex*9,$line,7,true,$color);
        if($overflow) $pdf->text($x+4,$top+17+($limit-1)*9,'Continua nos detalhes →',7,false,[0.12,0.37,0.17]);
    }
    $pdf->text(28,551,'Emitido em '.date('d/m/Y H:i').' · Agenda diária · Clínica Vidas',8);
    $pdf->page(); $page=2; $y=65;
    $pdf->text(28,25,month_title($data['mes']).' · Detalhes da agenda',17,true,[0.12,0.37,0.17]);
    $write=function(string $text,bool $bold=false,int $size=9) use ($pdf,&$page,&$y,$data): void {
        // Largura conservadora para as fontes padrão, inclusive caracteres largos.
        foreach(pdf_wrap($text,786,$size,$bold) as $line) {
            if($y>546) {
                $pdf->text(770,567,(string)$page,8); $pdf->page(); $page++; $y=65;
                $pdf->text(28,25,month_title($data['mes']).' · Continuação',17,true,[0.12,0.37,0.17]);
            }
            $pdf->text(28,$y,$line,$size,$bold); $y+=$size+5;
        }
    };
    if(!$days) $write('Nenhum registro neste mês.');
    foreach($days as $date=>$items) {
        $write((new DateTimeImmutable($date))->format('d/m/Y'),true,12);
        if(isset($items['feriado'])) $write('Feriado: '.$items['feriado']['descricao']);
        if(isset($items['escala'])) {
            $write('Escala: '.implode(' · ',array_column($items['escala']['profissionais'],'nome_profissional')),true);
            if($items['escala']['observacao']) $write('Observação da escala: '.$items['escala']['observacao']);
        }
        foreach($items['agendamentos']??[] as $item) {
            $write(appointment_label($item),true);
            if($item['observacao']) $write('Observação: '.$item['observacao']);
        }
        $y+=8;
    }
    $write('Legenda das abreviações',true,12);
    foreach($data['abreviacoes'] as $item) $write('('.$item['sigla'].') '.$item['descricao']);
    $pdf->text(770,567,(string)$page,8);
    return $pdf->output();
}
