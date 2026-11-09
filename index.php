<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$user = require_page_user();
$canEdit = $user['papel'] !== 'viewer';
$isAdmin = $user['papel'] === 'adm';
$labels = ['adm'=>'Administrador','editor'=>'Responsável','viewer'=>'Visualizador'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e($_SESSION['csrf']) ?>">
<title>Clínica Vidas · Agenda médica</title>
<link rel="icon" href="img/logo.png"><link rel="stylesheet" href="css/styles.css">
<script src="js/app.js" defer></script>
</head>
<body data-role="<?= e($user['papel']) ?>" data-user-id="<?= e($user['id_usuario']) ?>" data-month="<?= date('Y-m') ?>">
<div id="app" class="app">
  <header class="topbar no-print">
    <a href="#calendario" class="brand"><img src="img/logo.png" alt="Clínica Vidas"></a>
    <nav class="tabs" aria-label="Seções">
      <a href="#calendario" data-view="calendario">Calendário</a>
      <a href="#cardiologista" data-view="cardiologista">Escala do cardiologista</a>
      <?php if ($canEdit): ?><a href="#medicos" data-view="medicos">Médicos</a><a href="#abreviacoes" data-view="abreviacoes">Abreviações</a><?php endif ?>
      <?php if ($isAdmin): ?><a href="#usuarios" data-view="usuarios">Usuários</a><?php endif ?>
    </nav>
    <div class="who"><div class="identity"><strong><?= e($user['nome']) ?></strong><span><?= e($labels[$user['papel']]) ?></span></div><button class="btn btn-ghost" id="btnLogout">Sair</button></div>
  </header>
  <main>
    <div id="pageError" class="alert error" role="alert" hidden></div>
    <section class="view" id="view-calendario">
      <div class="view-head no-print">
        <div class="month-nav"><button class="icon-btn" id="prevMonth" aria-label="Mês anterior">‹</button><button class="icon-btn" id="nextMonth" aria-label="Próximo mês">›</button><button class="btn btn-ghost" id="today">Hoje</button><label class="sr-only" for="calendarMonth">Mês do calendário</label><input type="month" id="calendarMonth" min="2000-01" max="2100-12"></div>
        <div class="actions">
          <?php if ($canEdit): ?><button class="btn btn-ghost" id="btnFixed">Aplicar dias fixos ao ano</button><?php endif ?>
          <button class="btn btn-outline" id="btnPrint">Imprimir</button><button class="btn btn-primary" id="btnPdf">Baixar PDF</button>
        </div>
      </div>
      <div class="sheet" id="sheet" aria-busy="true"><div class="sheet-heading"><div><span class="eyebrow">AGENDA MÉDICA</span><h1 class="sheet-title" id="sheetTitle">Carregando agenda…</h1></div><span class="month-count" id="monthCount"></span></div><div class="grid" id="calGrid" aria-label="Calendário do mês"></div></div>
      <p class="tip no-print"><?= $canEdit ? 'Selecione um dia para editar os médicos, as observações e o feriado.' : 'Selecione um dia para consultar os detalhes da agenda.' ?></p>
    </section>
    <section class="view" id="view-cardiologista" hidden>
      <div class="view-head"><div><h1>Escala do cardiologista</h1><p class="muted">Planeje a equipe de qualquer mês. As escalas aparecem no calendário.</p></div>
        <div class="month-nav"><button class="icon-btn" id="prevScaleMonth" aria-label="Mês anterior da escala">‹</button><label class="sr-only" for="escalaMes">Mês da escala</label><input type="month" id="escalaMes" min="2000-01" max="2100-12"><button class="icon-btn" id="nextScaleMonth" aria-label="Próximo mês da escala">›</button><button class="btn btn-ghost" id="scaleToday">Mês atual</button></div>
      </div>
      <div class="schedule-toolbar"><p id="scaleSummary" class="muted" role="status"></p><?php if ($canEdit): ?><div class="actions"><button class="btn btn-outline" id="newScale">Adicionar outra data</button><button class="btn btn-primary" id="saveAllScales" disabled>Salvar alterações</button></div><?php endif ?></div>
      <p class="hint" id="scaleHint">Domingos são exibidos automaticamente. Até 12 profissionais por data, um por linha.</p>
      <div class="panel schedule-panel"><table class="table" id="escalaTable"><thead><tr><th>Data</th><th>Profissionais</th><th>Observação</th><th>Ações</th></tr></thead><tbody></tbody></table></div>
    </section>
    <?php if ($canEdit): ?>
    <section class="view" id="view-medicos" hidden>
      <div class="view-head"><div><h1>Médicos</h1><p class="muted">Cadastre os médicos e defina os dias de atendimento.</p></div><button class="btn btn-primary" id="newDoctor">Cadastrar médico</button></div>
      <div class="panel"><table class="table"><thead><tr><th>Nome no calendário</th><th>Tipo</th><th>Dias fixos</th><th>Situação</th><th>Ações</th></tr></thead><tbody id="medicosBody"></tbody></table></div>
    </section>
    <section class="view" id="view-abreviacoes" hidden>
      <div class="view-head"><div><h1>Abreviações</h1><p class="muted">Estas siglas formam a legenda do calendário.</p></div><button class="btn btn-primary" id="newTag">Cadastrar abreviação</button></div>
      <div class="panel"><table class="table"><thead><tr><th>Sigla</th><th>Significado</th><th>Ações</th></tr></thead><tbody id="tagsBody"></tbody></table></div>
    </section>
    <?php endif ?>
    <?php if ($isAdmin): ?>
    <section class="view" id="view-usuarios" hidden>
      <div class="view-head"><div><h1>Usuários e papéis</h1><p class="muted">Controle quem consulta a agenda e quem pode editar.</p></div><button class="btn btn-primary" id="newUser">Cadastrar usuário</button></div>
      <div class="role-legend"><div><strong>Administrador</strong><span>Gerencia a agenda e os acessos.</span></div><div><strong>Responsável</strong><span>Cadastra médicos, abreviações e edita a agenda.</span></div><div><strong>Visualizador</strong><span>Consulta o calendário, a escala e baixa o PDF.</span></div></div>
      <div class="panel"><table class="table"><thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Situação</th><th>Ações</th></tr></thead><tbody id="usersBody"></tbody></table></div>
    </section>
    <?php endif ?>
  </main>
</div>
<div class="overlay" id="modalDia" hidden><section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="diaTitulo" tabindex="-1">
  <div class="dialog-heading"><h2 id="diaTitulo">Agenda do dia</h2><button class="icon-btn" data-close aria-label="Fechar">×</button></div>
  <div id="dayHoliday" class="holiday-banner" hidden></div><div id="dayScale" class="muted"></div><div id="diaLista" class="appointment-list"></div>
  <?php if ($canEdit): ?>
  <form id="appointmentForm" class="stack"><h3 id="appointmentHeading">Adicionar médico</h3><input type="hidden" name="id_agendamento">
    <div class="form-grid"><label>Médico<select name="id_medico" id="diaMedico" required></select></label><label>Abreviação<select name="id_abreviacao" id="diaTag"></select></label></div>
    <label>Status<select name="status"><option value="AGENDADO">Agendado</option><option value="CONFIRMADO">Confirmado</option><option value="CONCLUIDO">Concluído</option><option value="CANCELADO">Cancelado</option></select></label>
    <label>Observação<textarea name="observacao" rows="2" maxlength="3000" placeholder="Detalhes do atendimento"></textarea></label>
    <div class="actions"><button class="btn btn-primary" type="submit">Salvar agendamento</button><button class="btn btn-ghost" type="button" id="resetAppointment" hidden>Cancelar edição</button></div>
  </form>
  <form id="holidayForm" class="stack divider"><label class="check"><input type="checkbox" name="feriado"> Marcar como feriado</label><label>Nome do feriado<input name="descricao" maxlength="150" placeholder="Ex.: Dia da Consciência Negra"></label><button class="btn btn-outline" type="submit">Salvar feriado</button><p class="hint">Marcar um feriado preserva os agendamentos existentes. Novas aplicações de dias fixos ignoram esta data.</p></form>
  <?php endif ?>
  <div class="modal-foot"><button class="btn btn-ghost" data-close>Fechar</button></div>
</section></div>
<?php if ($canEdit): ?>
<div class="overlay" id="modalScale" hidden><form id="scaleDateForm" class="modal" role="dialog" aria-modal="true" aria-labelledby="scaleDateTitle" tabindex="-1"><h2 id="scaleDateTitle">Adicionar data à escala</h2><p class="muted">Escolha qualquer data, inclusive de outro mês.</p><label>Data<input type="date" name="data" min="2000-01-01" max="2100-12-31" required></label><div class="modal-foot"><button class="btn btn-ghost" type="button" data-close>Cancelar</button><button class="btn btn-primary" type="submit">Abrir data</button></div></form></div>
<div class="overlay" id="modalMedico" hidden><form id="doctorForm" class="modal" role="dialog" aria-modal="true" aria-labelledby="doctorTitle" tabindex="-1">
  <h2 id="doctorTitle">Cadastrar médico</h2><input type="hidden" name="id_medico"><label>Nome no calendário<input name="nome" required maxlength="150" placeholder="Nome do médico"></label>
  <label>Tipo de agenda<select name="tipo"><option value="variado">Dias variados</option><option value="fixo">Dias fixos</option></select></label>
  <fieldset id="fixedDays"><legend>Dias fixos da semana</legend><div class="weekdays">
  <?php foreach (['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $day=>$name): ?><label><input type="checkbox" name="dias_semana" value="<?= $day ?>"><?= $name ?></label><?php endforeach ?>
  </div><p class="hint">Depois de salvar, use “Aplicar dias fixos ao ano” no calendário para gerar as datas.</p></fieldset>
  <label class="check"><input type="checkbox" name="ativo" checked> Cadastro ativo</label><div class="modal-foot"><button class="btn btn-ghost" type="button" data-close>Cancelar</button><button class="btn btn-primary" type="submit">Salvar médico</button></div>
</form></div>
<div class="overlay" id="modalAbreviacao" hidden><form id="tagForm" class="modal" role="dialog" aria-modal="true" aria-labelledby="tagTitle" tabindex="-1"><h2 id="tagTitle">Abreviação</h2><input type="hidden" name="id_abreviacao"><label>Sigla<input name="sigla" maxlength="20" required></label><label>Significado<input name="descricao" maxlength="255" required></label><div class="modal-foot"><button class="btn btn-ghost" type="button" data-close>Cancelar</button><button class="btn btn-primary" type="submit">Salvar abreviação</button></div></form></div>
<div class="overlay" id="modalFixed" hidden><form id="fixedForm" class="modal" role="dialog" aria-modal="true" aria-labelledby="fixedTitle" tabindex="-1"><h2 id="fixedTitle">Aplicar dias fixos</h2><p class="muted">Gera os dias dos médicos ativos com agenda fixa. Mantém as edições existentes, os cancelamentos e os feriados.</p><label>Ano<input name="ano" type="number" min="2000" max="2100" required></label><div class="modal-foot"><button class="btn btn-ghost" type="button" data-close>Cancelar</button><button class="btn btn-primary" type="submit">Gerar agendamentos</button></div></form></div>
<?php endif ?>
<?php if ($isAdmin): ?>
<div class="overlay" id="modalUsuario" hidden><form id="userForm" class="modal" role="dialog" aria-modal="true" aria-labelledby="userTitle" tabindex="-1"><h2 id="userTitle">Cadastrar usuário</h2><input type="hidden" name="id_usuario"><label>Nome<input name="nome" required maxlength="150" autocomplete="name"></label><label>E-mail<input name="email" type="email" required maxlength="150" autocomplete="email"></label><label>Senha<input name="senha" type="password" minlength="10" maxlength="72" autocomplete="new-password"><span class="hint">Na edição, deixe vazio para manter a senha atual.</span></label><label>Papel<select name="papel"><option value="viewer">Visualizador</option><option value="editor">Responsável</option><option value="adm">Administrador</option></select></label><label class="check"><input type="checkbox" name="ativo" checked> Acesso ativo</label><div class="modal-foot"><button class="btn btn-ghost" type="button" data-close>Cancelar</button><button class="btn btn-primary" type="submit">Salvar usuário</button></div></form></div>
<?php endif ?>
<div class="overlay" id="modalConfirm" hidden><section class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" tabindex="-1"><h2 id="confirmTitle">Confirmar ação</h2><p id="confirmText"></p><div class="modal-foot"><button class="btn btn-ghost" id="confirmCancel">Cancelar</button><button class="btn btn-primary" id="confirmOk">Confirmar</button></div></section></div>
<div id="toast" class="toast" role="status" hidden></div>
</body></html>
