'use strict';
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const MONTHS = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
const DAYS = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
const STATUS = {AGENDADO:'Agendado',CONFIRMADO:'Confirmado',CANCELADO:'Cancelado',CONCLUIDO:'Concluído'};
const ROLES = {adm:'Administrador',editor:'Responsável',viewer:'Visualizador'};
const canEdit = document.body.dataset.role !== 'viewer';
const isAdmin = document.body.dataset.role === 'adm';
const initialMonth = document.body.dataset.month;
let selectedMonth = initialMonth, selectedDay = null, model = null, userRows = [], requestVersion = 0;
let lastFocus = null, confirmResolve = null, toastTimer;

async function api(route, method = 'GET', data, params = {}) {
  const url = new URL('api/index.php', location.href);
  url.search = new URLSearchParams({rota: route, ...params}).toString();
  const response = await fetch(url, {method, credentials:'same-origin',
    headers: {'Accept':'application/json', ...(method !== 'GET' ? {'Content-Type':'application/json','X-CSRF-Token': $('meta[name="csrf-token"]').content} : {})},
    ...(data === undefined ? {} : {body:JSON.stringify(data)})});
  let result;
  try { result = await response.json(); } catch { throw new Error('O servidor não respondeu corretamente. Tente novamente.'); }
  if (!response.ok) {
    if (response.status === 401) { location.assign('login.php'); throw new Error('Sessão encerrada.'); }
    throw new Error(result.erro || 'Não foi possível concluir a operação.');
  }
  return result.data;
}

function toast(message, error = false) {
  clearTimeout(toastTimer);
  const node = $('#toast');
  node.textContent = message;
  node.classList.toggle('toast-error', error);
  node.hidden = false;
  toastTimer = setTimeout(() => { node.hidden = true; }, error ? 10000 : 4500);
}

function openModal(id) {
  const node = $('#' + id);
  lastFocus = document.activeElement;
  node.hidden = false;
  document.body.classList.add('modal-open');
  (node.querySelector('input:not([type=hidden]),select,button,[tabindex]') || node.firstElementChild).focus();
}

function closeModal(node) {
  if (node.id === 'modalConfirm' && confirmResolve) { const resolve = confirmResolve; confirmResolve = null; resolve(false); }
  node.hidden = true;
  if (!$$('.overlay').some(overlay => !overlay.hidden)) document.body.classList.remove('modal-open');
  if (lastFocus?.isConnected) lastFocus.focus();
}

function confirmAction(message) {
  $('#confirmText').textContent = message;
  openModal('modalConfirm');
  return new Promise(resolve => { confirmResolve = resolve; });
}
$('#confirmCancel').onclick = () => closeModal($('#modalConfirm'));
$('#confirmOk').onclick = () => {
  const resolve = confirmResolve;
  confirmResolve = null;
  closeModal($('#modalConfirm'));
  resolve?.(true);
};
$$('.overlay').forEach(overlay => overlay.addEventListener('click', event => {
  if (event.target === overlay || event.target.closest('[data-close]')) closeModal(overlay);
}));
document.addEventListener('keydown', event => {
  const open = $$('.overlay').filter(overlay => !overlay.hidden).at(-1);
  if (!open) return;
  if (event.key === 'Escape') closeModal(open);
  if (event.key === 'Tab') {
    const focusable = $$('button:not(:disabled),input:not([type=hidden]):not(:disabled),select:not(:disabled),textarea:not(:disabled),a[href]',open).filter(node => node.getClientRects().length);
    const first = focusable[0], last = focusable.at(-1);
    if (event.shiftKey && (document.activeElement === first || !open.contains(document.activeElement))) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  }
});

function navigate() {
  let view = location.hash.slice(1) || 'calendario';
  const allowed = ['calendario','cardiologista',...(canEdit ? ['medicos','abreviacoes'] : []),...(isAdmin ? ['usuarios'] : [])];
  if (!allowed.includes(view)) view = 'calendario';
  $$('.view').forEach(node => { node.hidden = node.id !== 'view-' + view; });
  $$('.tabs a').forEach(node => { node.classList.toggle('active',node.dataset.view === view); if (node.dataset.view === view) node.setAttribute('aria-current','page'); else node.removeAttribute('aria-current'); });
}
window.addEventListener('hashchange', navigate);

function title(month) { const [year,number] = month.split('-').map(Number); return `${MONTHS[number-1]} de ${year}`; }
function dateLabel(date) { return new Date(date + 'T12:00:00').toLocaleDateString('pt-BR',{day:'numeric',month:'long',year:'numeric'}); }
function dateInMonth(day) { return `${selectedMonth}-${String(day).padStart(2,'0')}`; }

async function refresh() {
  const version = ++requestVersion;
  $('#sheet').setAttribute('aria-busy','true');
  $('#sheetTitle').textContent = title(selectedMonth);
  $('#calendarMonth').value = $('#escalaMes').value = selectedMonth;
  $('#pageError').hidden = true;
  try {
    const [data, users] = await Promise.all([api('calendario','GET',undefined,{mes:selectedMonth}), isAdmin ? api('usuarios') : Promise.resolve([])]);
    if (version !== requestVersion) return;
    model = data; userRows = users;
    renderCalendar(); renderSchedules(); renderDoctors(); renderTags(); renderUsers();
    if (selectedDay && !$('#modalDia').hidden) renderDay();
  } catch (error) {
    if (version !== requestVersion) return;
    model = null;
    $('#calGrid').innerHTML = '';
    $('#monthCount').textContent = '';
    $('#pageError').innerHTML = `<p>${esc(error.message)}</p><button class="btn btn-outline" id="retryLoad">Tentar novamente</button>`;
    $('#pageError').hidden = false;
    $('#retryLoad').onclick = refresh;
    toast(error.message,true);
  } finally { if (version === requestVersion) $('#sheet').setAttribute('aria-busy','false'); }
}

function renderCalendar() {
  const [year,number] = selectedMonth.split('-').map(Number);
  const first = new Date(year,number-1,1).getDay(), total = new Date(year,number,0).getDate();
  const today = new Date().toLocaleDateString('sv-SE');
  let html = DAYS.map(day => `<div class="dow">${day}</div>`).join('');
  for (let index=0; index<Math.ceil((first+total)/7)*7; index++) {
    const day=index-first+1;
    if (day<1 || day>total) { html += '<div class="cell out" aria-hidden="true"></div>'; continue; }
    const date=dateInMonth(day);
    const holiday=model.feriados.find(item => item.data===date);
    const scale=model.escalas.find(item => item.data===date);
    const entries=model.agendamentos.filter(item => item.data===date);
    html += `<div class="cell editable ${date===today?'is-today':''}" data-date="${date}" role="button" tabindex="0" aria-label="${esc(dateLabel(date))}, ${entries.length} agendamentos${holiday?', feriado: '+esc(holiday.descricao):''}">
      <span class="num">${day}</span>${holiday?`<div class="feriado">${esc(holiday.descricao)}</div>`:''}
      ${scale?`<div class="escala">${scale.profissionais.map(p=>esc(p.nome_profissional)).join(' · ')}</div>`:''}
      <div class="names">${entries.map(item=>`<div class="${item.status==='CANCELADO'?'cancelled':''}" title="${esc(STATUS[item.status]+(item.observacao?' · '+item.observacao:''))}">${esc(item.medico_nome)} ${item.sigla?`<span class="tag">(${esc(item.sigla)})</span>`:''}${item.status==='CANCELADO'?'<span class="status-label"> CANCELADO</span>':''}${item.status==='CONFIRMADO'?'<span class="confirmed-dot" aria-label="Confirmado"> ●</span>':''}${item.status==='CONCLUIDO'?'<span class="completed-label"> ✓</span>':''}</div>`).join('')}</div></div>`;
  }
  html += `<div class="notes full"><b>Legenda das abreviações</b>${model.abreviacoes.length ? model.abreviacoes.map(tag=>`<span class="legend-item"><strong class="tag">(${esc(tag.sigla)})</strong> ${esc(tag.descricao)}</span>`).join(' · ') : 'Nenhuma abreviação cadastrada.'}</div>`;
  $('#calGrid').innerHTML = html;
  $('#monthCount').textContent = `${model.agendamentos.filter(a=>a.status!=='CANCELADO').length} atendimentos na agenda`;
}

function renderSchedules() {
  const [year,month]=selectedMonth.split('-').map(Number), dates=[];
  for (let day=1; day<=new Date(year,month,0).getDate(); day++) if (new Date(year,month-1,day).getDay()===0) dates.push(dateInMonth(day));
  model.escalas.forEach(scale=>{ if(!dates.includes(scale.data)) dates.push(scale.data); });
  dates.sort();
  $('#escalaTable tbody').innerHTML=dates.map(date=>{
    const scale=model.escalas.find(item=>item.data===date), names=scale?.profissionais.map(p=>p.nome_profissional).join('\n') || '';
    return `<tr data-scale-date="${date}"><td><strong>${esc(dateLabel(date))}</strong></td><td>${canEdit?`<label class="sr-only" for="scale-names-${date}">Profissionais em ${esc(dateLabel(date))}, um por linha</label><textarea id="scale-names-${date}" class="scale-names" rows="3" placeholder="Um profissional por linha">${esc(names)}</textarea>`:`<span class="pre-line">${esc(names)||'Sem escala'}</span>`}</td><td>${canEdit?`<label class="sr-only" for="scale-note-${date}">Observação em ${esc(dateLabel(date))}</label><textarea id="scale-note-${date}" class="scale-note" rows="3" maxlength="3000">${esc(scale?.observacao)}</textarea>`:esc(scale?.observacao)||'—'}</td><td>${canEdit?`<div class="row-actions"><button class="btn btn-primary" data-action="save-scale" data-date="${date}">Salvar</button>${scale?`<button class="btn btn-danger" data-action="delete-scale" data-date="${date}">Limpar</button>`:''}</div>`:'Consulta'}</td></tr>`;
  }).join('');
}

function renderDoctors() {
  if (!canEdit) return;
  $('#medicosBody').innerHTML=model.medicos.map(doctor=>`<tr><td><strong>${esc(doctor.nome)}</strong></td><td><span class="badge ${doctor.tipo==='fixo'?'fixo':'var'}">${doctor.tipo==='fixo'?'Dias fixos':'Dias variados'}</span></td><td>${doctor.dias_semana.map(day=>DAYS[day]).join(', ')||'—'}</td><td>${doctor.ativo?'Ativo':'Inativo'}</td><td><div class="row-actions"><button class="btn btn-ghost" data-action="edit-doctor" data-id="${doctor.id_medico}">Editar</button>${doctor.ativo?`<button class="btn btn-danger" data-action="disable-doctor" data-id="${doctor.id_medico}">Desativar</button>`:''}</div></td></tr>`).join('') || '<tr><td colspan="5" class="empty-state">Nenhum médico cadastrado. Comece pelo botão “Cadastrar médico”.</td></tr>';
}
function renderTags() {
  if (!canEdit) return;
  $('#tagsBody').innerHTML=model.abreviacoes.map(tag=>`<tr><td><strong>${esc(tag.sigla)}</strong></td><td>${esc(tag.descricao)}</td><td><div class="row-actions"><button class="btn btn-ghost" data-action="edit-tag" data-id="${tag.id_abreviacao}">Editar</button><button class="btn btn-danger" data-action="delete-tag" data-id="${tag.id_abreviacao}">Excluir</button></div></td></tr>`).join('') || '<tr><td colspan="3" class="empty-state">Nenhuma abreviação cadastrada.</td></tr>';
}
function renderUsers() {
  if (!isAdmin) return;
  $('#usersBody').innerHTML=userRows.map(user=>`<tr><td><strong>${esc(user.nome)}</strong></td><td>${esc(user.email)}</td><td>${esc(ROLES[user.papel]||user.papel)}</td><td>${user.ativo?'Ativo':'Inativo'}</td><td><div class="row-actions"><button class="btn btn-ghost" data-action="edit-user" data-id="${user.id_usuario}">Editar</button>${user.ativo && String(user.id_usuario)!==document.body.dataset.userId?`<button class="btn btn-danger" data-action="disable-user" data-id="${user.id_usuario}">Desativar</button>`:''}</div></td></tr>`).join('');
}

function resetAppointment() {
  if (!canEdit || !model) return;
  const form=$('#appointmentForm'); form.reset();
  form.elements.id_agendamento.value='';
  $('#appointmentHeading').textContent='Adicionar médico';
  $('#resetAppointment').hidden=true;
  $('#diaMedico').innerHTML='<option value="">Selecione um médico</option>'+model.medicos.filter(doctor=>doctor.ativo).map(doctor=>`<option value="${doctor.id_medico}">${esc(doctor.nome)}</option>`).join('');
  $('#diaTag').innerHTML='<option value="">Nenhuma</option>'+model.abreviacoes.map(tag=>`<option value="${tag.id_abreviacao}">${esc(tag.sigla)} — ${esc(tag.descricao)}</option>`).join('');
}
function renderDay() {
  if (!model) return;
  $('#diaTitulo').textContent=dateLabel(selectedDay);
  const holiday=model.feriados.find(item=>item.data===selectedDay), scale=model.escalas.find(item=>item.data===selectedDay);
  $('#dayHoliday').hidden=!holiday;
  $('#dayHoliday').textContent=holiday?.descricao || '';
  $('#dayScale').textContent=scale?`Escala: ${scale.profissionais.map(p=>p.nome_profissional).join(' · ')}${scale.observacao?' — '+scale.observacao:''}`:'';
  $('#diaLista').innerHTML=model.agendamentos.filter(item=>item.data===selectedDay).map(item=>`<article class="appointment ${item.status==='CANCELADO'?'cancelled-card':''}"><div><strong>${esc(item.medico_nome)} ${item.sigla?`<span class="tag">(${esc(item.sigla)})</span>`:''}</strong><span class="status-badge status-${item.status.toLowerCase()}">${STATUS[item.status]}</span><p class="pre-line">${esc(item.observacao)}</p><small class="muted">Registrado por ${esc(item.usuario_nome)}</small></div>${canEdit?`<div class="row-actions"><button class="btn btn-ghost" data-action="edit-appointment" data-id="${item.id_agendamento}">Editar</button>${item.status!=='CANCELADO'?`<button class="btn btn-danger" data-action="cancel-appointment" data-id="${item.id_agendamento}">Cancelar</button>`:''}</div>`:''}</article>`).join('') || '<p class="empty-state">Nenhum médico agendado para este dia.</p>';
  if (canEdit) { resetAppointment(); $('#holidayForm').elements.feriado.checked=!!holiday; $('#holidayForm').elements.descricao.value=holiday?.descricao || ''; }
}
$('#calGrid').addEventListener('click',event=>{
  const cell=event.target.closest('[data-date]');
  if (!cell || !model) return;
  selectedDay=cell.dataset.date; renderDay(); openModal('modalDia');
});
$('#calGrid').addEventListener('keydown',event=>{ if ((event.key==='Enter'||event.key===' ') && event.target.matches('[data-date]')) { event.preventDefault(); event.target.click(); } });

function editDoctor(id) {
  const form=$('#doctorForm'); form.reset();
  const doctor=model?.medicos.find(item=>item.id_medico===id);
  $('#doctorTitle').textContent=doctor?'Editar médico':'Cadastrar médico';
  form.elements.id_medico.value=doctor?.id_medico || '';
  form.elements.nome.value=doctor?.nome || '';
  form.elements.tipo.value=doctor?.tipo || 'variado';
  form.elements.ativo.checked=doctor?.ativo ?? true;
  $$('[name=dias_semana]',form).forEach(input=>{ input.checked=doctor?.dias_semana.includes(Number(input.value)) || false; });
  $('#fixedDays').hidden=form.elements.tipo.value!=='fixo'; openModal('modalMedico');
}
function editTag(id) {
  const form=$('#tagForm'); form.reset();
  const tag=model?.abreviacoes.find(item=>item.id_abreviacao===id);
  form.elements.id_abreviacao.value=tag?.id_abreviacao||'';
  form.elements.sigla.value=tag?.sigla||''; form.elements.descricao.value=tag?.descricao||''; openModal('modalAbreviacao');
}
function editUser(id) {
  const form=$('#userForm'); form.reset();
  const user=userRows.find(item=>item.id_usuario===id);
  $('#userTitle').textContent=user?'Editar usuário':'Cadastrar usuário';
  form.elements.id_usuario.value=user?.id_usuario||'';
  ['nome','email'].forEach(key=>{form.elements[key].value=user?.[key]||'';});
  form.elements.papel.value=user?.papel||'viewer'; form.elements.ativo.checked=user?.ativo ?? true;
  form.elements.senha.required=!user; openModal('modalUsuario');
}

document.addEventListener('click', async event=>{
  const button=event.target.closest('[data-action]'); if (!button || !model || button.disabled) return;
  const id=Number(button.dataset.id), action=button.dataset.action;
  button.disabled=true;
  try {
    if (action==='edit-doctor') return editDoctor(id);
    if (action==='edit-user') return editUser(id);
    if (action==='edit-tag') return editTag(id);
    if (action==='edit-appointment') {
      const item=model.agendamentos.find(a=>a.id_agendamento===id), form=$('#appointmentForm');
      resetAppointment();
      if (!model.medicos.find(doctor=>doctor.id_medico===item.id_medico)?.ativo) $('#diaMedico').insertAdjacentHTML('beforeend',`<option value="${item.id_medico}">${esc(item.medico_nome)} (inativo)</option>`);
      ['id_agendamento','id_medico','id_abreviacao','status','observacao'].forEach(key=>{form.elements[key].value=item[key]??'';});
      $('#appointmentHeading').textContent='Editar agendamento'; $('#resetAppointment').hidden=false; form.elements.id_medico.focus(); return;
    }
    if (action==='save-scale') {
      const row=button.closest('tr');
      await api('escalas/'+button.dataset.date,'PUT',{profissionais:$('.scale-names',row).value.split('\n').map(name=>name.trim()).filter(Boolean),observacao:$('.scale-note',row).value});
      toast('Escala salva.'); await refresh(); return;
    }
    const configs={
      'disable-doctor':['Desativar este médico? Os agendamentos existentes serão preservados.','medicos/'+id,'Médico desativado.'],
      'disable-user':['Desativar o acesso deste usuário?','usuarios/'+id,'Acesso desativado.'],
      'delete-tag':['Excluir esta abreviação? Só é possível excluir siglas sem agendamentos vinculados.','abreviacoes/'+id,'Abreviação excluída.'],
      'cancel-appointment':['Cancelar este agendamento? Ele permanecerá no histórico e poderá ser reativado na edição.','agendamentos/'+id,'Agendamento cancelado.'],
      'delete-scale':['Limpar os profissionais e a observação desta escala?','escalas/'+button.dataset.date,'Escala removida.']
    };
    if (configs[action]) { const [message,route,success]=configs[action]; if (await confirmAction(message)) { await api(route,'DELETE'); toast(success); await refresh(); } }
  } catch(error) { toast(error.message,true); }
  finally { if (button.isConnected) button.disabled=false; }
});

function onSubmit(selector, callback) {
  const form=$(selector); if (!form) return;
  form.addEventListener('submit',async event=>{
    event.preventDefault(); const button=$('[type=submit]',form); if (button.disabled) return;
    button.disabled=true;
    try { await callback(form); } catch(error) { toast(error.message,true); }
    finally { button.disabled=false; }
  });
}
onSubmit('#doctorForm',async form=>{
  const id=form.elements.id_medico.value;
  await api('medicos'+(id?'/'+id:''),id?'PUT':'POST',{nome:form.elements.nome.value,tipo:form.elements.tipo.value,ativo:form.elements.ativo.checked,dias_semana:$$('[name=dias_semana]:checked',form).map(input=>Number(input.value))});
  closeModal($('#modalMedico')); toast('Médico salvo.'); await refresh();
});
onSubmit('#userForm',async form=>{
  const id=form.elements.id_usuario.value;
  await api('usuarios'+(id?'/'+id:''),id?'PUT':'POST',{nome:form.elements.nome.value,email:form.elements.email.value,senha:form.elements.senha.value,papel:form.elements.papel.value,ativo:form.elements.ativo.checked});
  closeModal($('#modalUsuario')); toast('Usuário salvo.'); await refresh();
});
onSubmit('#tagForm',async form=>{
  const id=form.elements.id_abreviacao.value;
  await api('abreviacoes'+(id?'/'+id:''),id?'PUT':'POST',{sigla:form.elements.sigla.value,descricao:form.elements.descricao.value});
  closeModal($('#modalAbreviacao')); toast('Abreviação salva.'); await refresh();
});
onSubmit('#appointmentForm',async form=>{
  const id=form.elements.id_agendamento.value;
  await api('agendamentos'+(id?'/'+id:''),id?'PUT':'POST',{data:selectedDay,id_medico:Number(form.elements.id_medico.value),id_abreviacao:form.elements.id_abreviacao.value?Number(form.elements.id_abreviacao.value):null,status:form.elements.status.value,observacao:form.elements.observacao.value});
  toast('Agendamento salvo.'); await refresh();
});
onSubmit('#holidayForm',async form=>{
  const enabled=form.elements.feriado.checked;
  const description=form.elements.descricao.value.trim();
  if (enabled && !description) throw new Error('Informe o nome do feriado.');
  await api('feriados/'+selectedDay,enabled?'PUT':'DELETE',enabled?{descricao:description}:undefined);
  toast(enabled?'Feriado salvo.':'Feriado removido.'); await refresh();
});
onSubmit('#fixedForm',async form=>{
  const result=await api('dias-fixos','POST',{ano:Number(form.elements.ano.value)});
  closeModal($('#modalFixed')); toast(`${result.criados} agendamentos criados para ${result.ano}.`); await refresh();
});

if (canEdit) {
  $('#newDoctor').onclick=()=>editDoctor(); $('#newTag').onclick=()=>editTag();
  $('#doctorForm').elements.tipo.onchange=event=>{$('#fixedDays').hidden=event.target.value!=='fixo';};
  $('#resetAppointment').onclick=resetAppointment;
  $('#btnFixed').onclick=()=>{ $('#fixedForm').elements.ano.value=selectedMonth.slice(0,4); openModal('modalFixed'); };
}
if (isAdmin) $('#newUser').onclick=()=>editUser();
function setMonth(month) {
  if (!/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/.test(month)) { toast('Selecione um mês entre 2000 e 2100.',true); return; }
  selectedMonth=month; refresh();
}
function moveMonth(offset) { const [year,month]=selectedMonth.split('-').map(Number); const date=new Date(year,month-1+offset,1); setMonth(`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}`); }
$('#prevMonth').onclick=()=>moveMonth(-1); $('#nextMonth').onclick=()=>moveMonth(1);
$('#today').onclick=()=>setMonth(initialMonth);
$('#calendarMonth').onchange=event=>setMonth(event.target.value); $('#escalaMes').onchange=event=>setMonth(event.target.value);
$('#btnPrint').onclick=()=>{ if(model) window.open('imprimir.php?mes='+encodeURIComponent(selectedMonth),'_blank','noopener'); };
$('#btnPdf').onclick=()=>{ if(model) location.assign('pdf.php?mes='+encodeURIComponent(selectedMonth)); };
$('#btnLogout').onclick=async()=>{ try { await api('logout','POST'); location.assign('login.php'); } catch(error){ toast(error.message,true); } };
navigate(); refresh();
