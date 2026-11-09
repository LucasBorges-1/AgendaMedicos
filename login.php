<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
start_session();
$unavailable=false;
try { if (current_user()) { header('Location: index.php'); exit; } }
catch (Throwable $e) { error_log($e->getMessage()); $unavailable=true; }
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="<?= e($_SESSION['csrf']) ?>"><title>Entrar · Clínica Vidas</title><link rel="icon" href="img/logo.png"><link rel="stylesheet" href="css/styles.css"><script src="js/login.js" defer></script></head><body>
<main class="login"><form id="loginForm" class="login-card"><img src="img/logo.png" alt="Clínica Vidas" class="login-logo"><div><h1>Bem-vindo à agenda</h1><p class="muted login-subtitle">Entre com seu acesso da clínica.</p></div>
<div id="loginError" class="alert error" role="alert" <?= $unavailable ? '' : 'hidden' ?>><?= $unavailable ? 'Banco indisponível. Confira a configuração e as migrações do projeto.' : '' ?></div>
<label>E-mail<input name="email" type="email" autocomplete="username" required maxlength="150" autofocus></label><label>Senha<input name="senha" type="password" autocomplete="current-password" required maxlength="1024"></label><button class="btn btn-primary" type="submit">Entrar</button><p class="hint login-subtitle">Para obter ou recuperar o acesso, procure o administrador da clínica.</p></form></main></body></html>
