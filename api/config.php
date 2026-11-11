<?php
/* =====================================================================
   Configuração · Clínica Vidas
   Edite os valores abaixo OU defina variáveis de ambiente
   (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
   ===================================================================== */
define('DB_HOST', getenv('DB_HOST') ?: 'aws-1-us-east-1.pooler.supabase.com');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 6543));
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
define('DB_USER', getenv('DB_USER') ?: 'postgres.swisoaygvtjalinihwfc');
define('DB_PASS', '@Cumida009@');

define('DB_POOLER', true);                  // true na porta 6543 (Supabase pooler); false na 5432 ou Postgres local
define('DB_SSLMODE', 'require');            // Supabase exige SSL; use 'prefer' em Postgres local

define('APP_TZ', 'America/Sao_Paulo');
define('SESSAO_MAX_OCIOSA', 60 * 60 * 8);   // 8 horas sem uso derrubam a sessão
define('APP_DEBUG', false);                 // true mostra a mensagem real dos erros 500
