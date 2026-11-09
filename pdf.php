<?php
declare(strict_types=1);
require __DIR__ . '/app/services.php';
require __DIR__ . '/app/print-view.php';
require __DIR__ . '/app/pdf.php';
require_page_user();
try {
    $data=calendar_data($_GET['mes']??date('Y-m'));
    $pdf=build_calendar_pdf($data);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="agenda-vidas-'.$data['mes'].'.pdf"');
    header('Content-Length: '.strlen($pdf));
    echo $pdf;
} catch (ApiError $e) { http_response_code($e->status); exit(e($e->getMessage())); }
catch (Throwable $e) { error_log($e->getMessage()); http_response_code(503); exit('Não foi possível gerar o PDF. Tente novamente.'); }
