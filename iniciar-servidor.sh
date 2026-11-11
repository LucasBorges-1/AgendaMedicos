#!/bin/sh
cd "$(dirname "$0")"
echo "Abra http://127.0.0.1:8000  (diagnóstico: /diagnostico.php)"
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000
