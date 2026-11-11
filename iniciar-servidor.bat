@echo off
cd /d "%~dp0"
echo Abra http://127.0.0.1:8000  (diagnostico: /diagnostico.php)
php -S 127.0.0.1:8000
