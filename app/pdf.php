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
    public function text(float $x,float $top,string $text,float $size=9,bool $bold=false,array $color=[0.12,0.17,0.13]): void {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT',$text);
        $encoded=str_replace(["\\",'(',')',"\r","\n"],["\\\\","\\(","\\)",' ',' '],$encoded===false?'?':$encoded);
        $encoded=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$encoded);
        $this->stream.=sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",$bold?'F2':'F1',$size,...[...$color,$x,595-$top-$size,$encoded]);
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

function pdf_char_width(string $character,float $size,bool $bold): float {
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

function pdf_wrap(string $text,float $width,float $size=9,bool $bold=false): array {
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

// Quebra trechos coloridos mantendo siglas junto dos nomes e sem truncar registros.
function pdf_rich_lines(array $runs, float $width, float $size): array {
    $lines=[[]]; $used=0;
    foreach($runs as [$text,$bold,$color]) {
        $tokens=preg_split('/(\\s+)/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY) ?: [];
        foreach($tokens as $token) {
            if(str_contains($token,"\n")) { $lines[]=[]; $used=0; continue; }
            $tokenWidth=0;
            foreach(preg_split('//u',$token,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $char) $tokenWidth+=pdf_char_width($char,$size,$bold);
            if(trim($token)!=='' && $used>0 && $tokenWidth<=$width && $used+$tokenWidth>$width) { $lines[]=[]; $used=0; }
            foreach(preg_split('//u',$token,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                $charWidth=pdf_char_width($char,$size,$bold);
                if($used+$charWidth>$width && $used>0) { $lines[]=[]; $used=0; }
                if($used===0 && $char===' ') continue;
                $index=count($lines)-1; $last=count($lines[$index])-1;
                if($last>=0 && $lines[$index][$last][1]===$bold && $lines[$index][$last][2]===$color) $lines[$index][$last][0].=$char;
                else $lines[$index][]=[$char,$bold,$color];
                $used+=$charWidth;
            }
        }
    }
    return $lines;
}

function pdf_fit_runs(AgendaPdf $pdf, array $runs, float $x, float $top, float $width, float $height, float $size=8): void {
    do {
        $lines=pdf_rich_lines($runs,$width,$size);
        if(count($lines)*$size*1.18<=$height) break;
        $size*=0.94;
    } while($size>0.2);
    foreach($lines as $i=>$line) {
        $left=$x;
        foreach($line as [$text,$bold,$color]) {
            $pdf->text($left,$top+$i*$size*1.18,$text,$size,$bold,$color);
            foreach(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $char) $left+=pdf_char_width($char,$size,$bold);
        }
    }
}

function build_calendar_pdf(array $data): string {
    $pdf=new AgendaPdf(); $pdf->page();
    $black=[0.06,0.09,0.06]; $red=[0.93,0,0]; $green=[0.22,0.36,0.16];
    $left=66; $width=710/7; $top=96; $height=69;
    $pdf->text($left+3,39,month_title($data['mes']),25,true,$green);
    foreach(['Domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'] as $column=>$label) {
        $pdf->box($left+$column*$width,74,$width,22,[0.773,0.871,0.686]);
        $pdf->text($left+5+$column*$width,80,$label,8);
    }
    $days=grouped_days($data); $first=new DateTimeImmutable($data['mes'].'-01');
    $start=(int)$first->format('w'); $total=(int)$first->format('t');
    for($i=0;$i<37;$i++) {
        $day=$i-$start+1; $x=$left+($i%7)*$width; $y=$top+intdiv($i,7)*$height;
        $date=$first->modify(($day-1).' days'); $current=$day>=1&&$day<=$total;
        $pdf->box($x,$y,$width,$height);
        $pdf->text($x+$width-15,$y+4,$date->format('j'),8,$current,[0.25,0.30,0.32]);
        $items=$current?($days[$date->format('Y-m-d')]??[]):[]; $runs=[];
        if(isset($items['feriado'])) $runs[]=[$items['feriado']['descricao']."\n",true,$red];
        if(isset($items['escala'])) $runs[]=[implode(' - ',array_column($items['escala']['profissionais'],'nome_profissional'))."\n",true,$red];
        foreach($items['agendamentos']??[] as $item) {
            $runs[]=[$item['medico_nome'],true,$item['status']==='CANCELADO'?[0.4,0.4,0.4]:$black];
            if($item['sigla']) $runs[]=[' ('.$item['sigla'].')',true,$red];
            if($item['status']!=='AGENDADO') $runs[]=[' '.['CONFIRMADO'=>'Confirmado','CANCELADO'=>'Cancelado','CONCLUIDO'=>'Concluído'][$item['status']],false,$black];
            $runs[]=["\n",false,$black];
        }
        pdf_fit_runs($pdf,$runs,$x+5,$y+18,$width-10,$height-22);
    }
    $x=$left+2*$width; $y=$top+5*$height;
    $pdf->box($x,$y,5*$width,$height);
    $runs=[["Anotações\n",false,[0.32,0.35,0.34]],['O SIGNIFICADO DAS ABREVIAÇÕES: ',false,$black]];
    foreach($data['abreviacoes'] as $item) {
        $runs[]=['('.$item['sigla'].') ',true,$red];
        $runs[]=[$item['descricao'].' · ',false,$black];
    }
    foreach(print_observations($data) as $note) $runs[]=[$note.' · ',false,$black];
    pdf_fit_runs($pdf,$runs,$x+8,$y+7,5*$width-16,$height-14,7.5);
    return $pdf->output();
}
