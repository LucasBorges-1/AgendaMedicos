<?php
// Compatibilidade com o arquivo do projeto original. Não exponha este arquivo por HTTP.
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();
