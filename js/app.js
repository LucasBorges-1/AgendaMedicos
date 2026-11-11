/* =========================================================
   Clínica Vidas · Frontend conectado à API PHP (api/index.php)
   ========================================================= */

const MESES = ["Janeiro","Fevereiro","Março","Abril","Maio","Junho","Julho","Agosto","Setembro","Outubro","Novembro","Dezembro"];
const DIAS = ["Domingo","segunda-feira","terça-feira","quarta-feira","quinta-feira","sexta-feira","sábado"];
const DIAS_CURTOS = ["Dom","Seg","Ter","Qua","Qui","Sex","Sáb"];
const PAPEL_NOME = {adm:"Administrador", editor:"Responsável", viewer:"Visualizador"};

const $ = (s,el=document)=>el.querySelector(s);
const $$ = (s,el=document)=>[...el.querySelectorAll(s)];
const esc = s=>String(s ?? "").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));

const hoje = new Date();
const estado = {
  usuario: null,
  csrf: "",
  ano: hoje.getFullYear(),
  mes: hoje.getMonth(),          // 0 a 11
  cal: {dias:{}, feriados:{}, escala:{}},
  medicos: [],
  abrevs: [],
  usuarios: [],
  domingos: [],
  diaAberto: null,
  cache: {},        // meses já carregados (mostrados na hora ao navegar)
  reqMes: 0,        // identifica a requisição de mês mais recente
  mut: 0            // conta alterações locais (evita sobrescrever com resposta antiga)
};

/* ---------------- Comunicação com a API ---------------- */
async function api(rota, opc = {}){
  try{
    return await apiUma(rota, opc);
  }catch(e){
    // Leituras são seguras para repetir (ex.: conexão ociosa do banco que caiu)
    const leitura = !opc.metodo || opc.metodo === "GET";
    if(leitura && (e.status >= 500 || e.rede)) return apiUma(rota, opc);
    throw e;
  }
}

async function apiUma(rota, {metodo="GET", params={}, corpo=null} = {}){
  const qs = new URLSearchParams({r: rota, ...params});
  const opcoes = {method: metodo, credentials: "same-origin", headers: {"Accept":"application/json"}};
  if(metodo !== "GET"){
    opcoes.headers["Content-Type"] = "application/json";
    opcoes.headers["X-CSRF-Token"] = estado.csrf;
    opcoes.body = JSON.stringify(corpo ?? {});
  }
  if(location.protocol === "file:")
    throw new Error("Abra o sistema por um servidor web com PHP (ex.: php -S localhost:8000), não direto do arquivo.");
  let resp, dados = {};
  const ctl = new AbortController();
  const timer = setTimeout(()=>ctl.abort(), 40000);
  opcoes.signal = ctl.signal;
  try{
    resp = await fetch(`api/index.php?${qs}`, opcoes);
  }catch(e){
    const err = new Error(e.name === "AbortError"
      ? "O servidor demorou demais para responder. Tente novamente."
      : "Não foi possível falar com o servidor. Verifique se ele está ligado e abra diagnostico.php para testar o banco.");
    err.rede = true;
    throw err;
  }finally{
    clearTimeout(timer);
  }
  try{
    dados = await resp.json();
  }catch(e){
    const err = new Error("O servidor não respondeu como esperado. Verifique se o PHP está ativo e se a pasta api/ está acessível.");
    err.status = resp.status || 500;
    throw err;
  }
  if(!resp.ok){
    if(resp.status === 401 && rota !== "login" && rota !== "senha") sessaoExpirou();
    const err = new Error((dados.erro || "Erro inesperado.") + (dados.codigo ? ` [${dados.codigo}]` : ""));
    err.status = resp.status;
    throw err;
  }
  return dados;
}

let toastTimer;
function aviso(msg, erro=false){
  const t = $("#toast");
  t.textContent = msg;
  t.classList.toggle("erro", erro);
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(()=>t.hidden = true, erro ? 5000 : 3000);
}
function mostrarErro(el, msg){ el.textContent = msg; el.hidden = !msg; }
const falha = e => aviso(e.message, true);

/* ---------------- Utilidades de data / texto ---------------- */
const iso = (a,m,d)=>`${a}-${String(m+1).padStart(2,"0")}-${String(d).padStart(2,"0")}`;

function formatarDias(dias){
  if(!dias || !dias.length) return "—";
  const d = [...dias].sort((a,b)=>a-b);
  const continuo = d.every((v,i)=>i===0 || v===d[i-1]+1);
  if(d.length >= 3 && continuo) return `${DIAS_CURTOS[d[0]]} a ${DIAS_CURTOS[d[d.length-1]]}`;
  return d.map(v=>DIAS_CURTOS[v]).join(", ");
}
const tagHtml = (t)=>`<span class="tag">(${esc(t)})</span>`;
function textoItem(it){
  const tag = it.observacao || it.sigla;
  return `${esc(it.medico || "")}${tag ? tagHtml(tag) : ""}`;
}

/* ---------------- Calendário ---------------- */
const chaveMes = ()=>`${estado.ano}-${estado.mes}`;
const mutou = ()=>{ estado.mut++; };

function usarEntrada(e){ estado.cal = e.cal; estado.domingos = e.domingos; }

function aplicarPainel(d){
  estado.abrevs = d.abreviacoes;
  if(estado.usuario.papel !== "viewer"){ estado.medicos = d.medicos; estado.medicosMudou = true; }
  const entrada = {cal:{dias:d.dias||{}, feriados:d.feriados||{}, escala:d.escala||{}}, domingos:d.domingos||[]};
  estado.cache[`${d.ano}-${d.mes-1}`] = entrada;
  return entrada;
}

function renderTudo(){
  const sel = $("#diaTag");
  if(sel.options.length !== estado.abrevs.length + 1){
    sel.innerHTML = `<option value="">Nenhuma</option>` + estado.abrevs.map(a=>`<option value="${a.id}">${esc(a.sigla)}</option>`).join("");
  }
  if(estado.medicosMudou){ renderMedicos(); estado.medicosMudou = false; }   // não reseta o seletor a cada edição
  renderCalendario();
  if(estado.diaAberto && !$("#modalDia").hidden) renderModalDia();
}

/* Mostra o mês na hora se já estiver em cache e atualiza em segundo plano. */
async function carregarMes(){
  const chave = chaveMes();
  const meu = ++estado.reqMes;
  const mutAntes = estado.mut;
  const grade = $("#calGrid");
  if(estado.cache[chave]){
    usarEntrada(estado.cache[chave]);
    renderTudo();
  }else{
    $("#sheetTitle").textContent = `${MESES[estado.mes]} de ${estado.ano}…`;
    grade.style.opacity = ".5";
    grade.style.pointerEvents = "none";
  }
  try{
    const d = await api("painel", {params:{ano: estado.ano, mes: estado.mes+1}});
    if(meu !== estado.reqMes) return;           // o usuário já foi para outro mês
    if(estado.mut !== mutAntes) return;          // editou durante a leitura: a versão local é mais nova
    usarEntrada(aplicarPainel(d));
    renderTudo();
  }finally{
    if(meu === estado.reqMes){ grade.style.opacity = ""; grade.style.pointerEvents = ""; }
  }
}

function legendaHtml(){
  const itens = estado.abrevs.map(a=>`${tagHtml(a.sigla)} ${esc(a.descricao)}`).join(" - ");
  return `<strong>O SIGNIFICADO DAS ABREVIAÇÕES:</strong> ${itens}`;
}

function renderCalendario(){
  const {ano, mes} = estado;
  const {dias, feriados, escala} = estado.cal;
  $("#sheetTitle").textContent = `${MESES[mes]} de ${ano}`;
  let html = DIAS.map(d=>`<div class="dow" role="columnheader">${d}</div>`).join("");

  const primeiro = new Date(ano,mes,1).getDay();
  const total = new Date(ano,mes+1,0).getDate();
  const semanas = Math.ceil((primeiro+total)/7);

  for(let i=0;i<semanas*7;i++){
    const dia = i-primeiro+1;
    const col = i%7;
    if(dia<1 || dia>total){
      const n = dia<1 ? new Date(ano,mes,dia).getDate() : dia-total;
      html += `<div class="cell out"><span class="num">${n}</span></div>`;
      continue;
    }
    let nomes = feriados[dia] ? `<div class="feriado">FERIADO</div>` : "";
    nomes += (dias[dia]||[]).map(it=>`<div class="${it.status==="cancelado"?"cancelado":""}">${textoItem(it)}</div>`).join("");
    const sun = col===0 && escala[dia] ? `<span class="escala">${esc(escala[dia].join(" - "))}</span>` : "";
    html += `<div class="cell editable ${sun?"has-sun":""}" data-dia="${dia}" tabindex="0">
      <span class="num">${dia}</span>${sun}<div class="names">${nomes}</div></div>`;
  }

  const restantes = semanas*7 - (primeiro+total);
  html += [1,2].map(n=>`<div class="cell out"><span class="num">${restantes+n}</span></div>`).join("");
  html += `<div class="notes"><b>Anotações</b>${legendaHtml()}</div>`;
  $("#calGrid").innerHTML = html;
  renderEscala();
}

/* ---------------- Escala do cardiologista ---------------- */
function renderEscala(){
  const somenteLeitura = estado.usuario && estado.usuario.papel === "viewer";
  const dis = somenteLeitura ? "disabled" : "";
  $("#escalaTable tbody").innerHTML = estado.domingos.map(d=>`
    <tr data-data="${d.data}">
      <td><strong>${d.dia} de ${MESES[estado.mes].toLowerCase()}</strong></td>
      <td><input value="${esc(d.pessoas[0])}" maxlength="120" ${dis}></td>
      <td><input value="${esc(d.pessoas[1])}" maxlength="120" ${dis}></td>
      <td class="only-edit"><button class="btn btn-danger" data-limpar>Remover</button></td>
    </tr>`).join("") || `<tr><td colspan="4" class="muted">Nenhum domingo neste mês.</td></tr>`;

  $("#escalaMes").innerHTML = MESES.map((n,i)=>`<option value="${i}" ${i===estado.mes?"selected":""}>${n} de ${estado.ano}</option>`).join("");
}

$("#escalaMes").addEventListener("change", e=>{
  estado.mes = Number(e.target.value);
  carregarMes().catch(falha);
});

$("#escalaTable").addEventListener("click", e=>{
  const b = e.target.closest("[data-limpar]");
  if(!b) return;
  $$("input", b.closest("tr")).forEach(i=>i.value="");
  aviso("Campos limpos. Clique em “Salvar escala” para confirmar.");
});

$("#btnSalvarEscala").addEventListener("click", async ()=>{
  const linhas = $$("#escalaTable tbody tr[data-data]").map(tr=>({
    data: tr.dataset.data,
    pessoas: $$("input", tr).map(i=>i.value.trim())
  }));
  const btn = $("#btnSalvarEscala");
  btn.disabled = true;
  try{
    await api("escala", {metodo:"PUT", corpo:{ano: estado.ano, mes: estado.mes+1, linhas}});
    // atualiza a tela localmente (o servidor grava em maiúsculas)
    linhas.forEach(l=>{
      const nomes = l.pessoas.map(x=>x.toUpperCase());
      const dia = Number(l.data.slice(8));
      const cheio = nomes.filter(Boolean);
      if(cheio.length) estado.cal.escala[dia] = cheio; else delete estado.cal.escala[dia];
      const dom = estado.domingos.find(x=>x.data===l.data);
      if(dom) dom.pessoas = nomes;
    });
    mutou();
    renderCalendario();
    aviso("Escala salva.");
  }catch(e){ falha(e); }
  finally{ btn.disabled = false; }
});

/* ---------------- Médicos ---------------- */
/* A lista de médicos vem junto com o painel (carregarMes). */
async function recarregarTudo(){ estado.cache = {}; await carregarMes(); }

function renderMedicos(){
  $("#medicosBody").innerHTML = estado.medicos.map(m=>`
    <tr><td><strong>${esc(m.nome)}</strong></td>
    <td><span class="badge ${m.tipo==="fixo"?"fixo":"var"}">${m.tipo==="fixo"?"Dias fixos":"Dias variados"}</span></td>
    <td>${formatarDias(m.dias)}</td>
    <td style="text-align:right"><button class="btn btn-ghost" data-editar="${m.id}">Editar</button> <button class="btn btn-danger" data-excluir="${m.id}">Excluir</button></td></tr>`).join("")
    || `<tr><td colspan="4" class="muted">Nenhum médico cadastrado ainda.</td></tr>`;
  $("#diaMedico").innerHTML = estado.medicos.map(m=>`<option value="${m.id}">${esc(m.nome)}</option>`).join("")
    || `<option value="">Cadastre um médico primeiro</option>`;
}

function abrirModalMedico(m){
  const f = $("#formMedico");
  f.reset();
  f.dataset.id = m ? m.id : "";
  $("#medTitulo").textContent = m ? "Editar médico" : "Cadastrar médico";
  $("#medNome").value = m ? m.nome : "";
  const tipo = m ? m.tipo : "fixo";
  $$('input[name="tipo"]', f).forEach(r=>r.checked = r.value===tipo);
  $$('input[name="dia"]', f).forEach(c=>c.checked = !!m && m.dias.includes(Number(c.value)));
  alternarTipo();
  mostrarErro($("#medErro"), "");
  $("#modalMedico").hidden = false;
  $("#medNome").focus();
}

function alternarTipo(){
  const fixo = $('input[name="tipo"]:checked', $("#formMedico")).value === "fixo";
  $("#fsDias").style.opacity = fixo ? "1" : ".45";
  $$('input[name="dia"]').forEach(c=>c.disabled = !fixo);
}
$$('input[name="tipo"]').forEach(r=>r.addEventListener("change", alternarTipo));

$$('[data-open="modalMedico"]').forEach(b=>b.addEventListener("click", ()=>abrirModalMedico(null)));

$("#medicosBody").addEventListener("click", async e=>{
  const ed = e.target.closest("[data-editar]");
  const ex = e.target.closest("[data-excluir]");
  if(ed){
    abrirModalMedico(estado.medicos.find(m=>m.id===Number(ed.dataset.editar)));
  }else if(ex){
    const m = estado.medicos.find(x=>x.id===Number(ex.dataset.excluir));
    if(!m || !confirm(`Excluir ${m.nome}?\n\nOs dias futuros dele no calendário serão removidos. O histórico passado é mantido.`)) return;
    try{
      await api("medicos", {metodo:"DELETE", params:{id:m.id}});
      await recarregarTudo();
      aviso("Médico excluído.");
    }catch(err){ falha(err); }
  }
});

$("#formMedico").addEventListener("submit", async e=>{
  e.preventDefault();
  const f = e.target;
  const id = f.dataset.id;
  const corpo = {
    nome: $("#medNome").value,
    tipo: $('input[name="tipo"]:checked', f).value,
    dias: $$('input[name="dia"]:checked', f).map(c=>Number(c.value))
  };
  try{
    const r = await api("medicos", id ? {metodo:"PUT", params:{id}, corpo} : {metodo:"POST", corpo});
    $("#modalMedico").hidden = true;
    await recarregarTudo();
    aviso(r.agendamentos_criados ? `Médico salvo. ${r.agendamentos_criados} dias adicionados ao calendário.` : "Médico salvo.");
  }catch(err){ mostrarErro($("#medErro"), err.message); }
});

$("#btnFixed").addEventListener("click", async ()=>{
  if(!confirm(`Aplicar os dias fixos de todos os médicos a todo o ano de ${estado.ano}?\n\nDias já preenchidos não serão duplicados e feriados são ignorados.`)) return;
  try{
    const r = await api("fixos", {metodo:"POST", corpo:{ano: estado.ano}});
    await recarregarTudo();
    aviso(`${r.agendamentos_criados} dias adicionados ao calendário.`);
  }catch(e){ falha(e); }
});

/* ---------------- Usuários ---------------- */
async function carregarUsuarios(){
  if(estado.usuario.papel !== "adm") return;
  estado.usuarios = (await api("usuarios")).usuarios;
  renderUsuarios();
}

function renderUsuarios(){
  $("#usersBody").innerHTML = estado.usuarios.map(u=>{
    const eu = u.id === estado.usuario.id;
    return `<tr><td><strong>${esc(u.nome)}</strong>${eu?' <span class="muted">(você)</span>':""}</td><td>${esc(u.email)}</td>
    <td><select class="select" data-papel="${u.id}" ${eu?"disabled":""}>${Object.entries(PAPEL_NOME).map(([v,n])=>`<option value="${v}" ${v===u.papel?"selected":""}>${n}</option>`).join("")}</select></td>
    <td style="text-align:right"><button class="btn btn-ghost" data-redefinir="${u.id}">Redefinir senha</button> <button class="btn btn-danger" data-remover="${u.id}" ${eu?"disabled":""}>Remover acesso</button></td></tr>`;
  }).join("");
}

function mostrarSenhaTemp(info, senha){
  $("#senhaTempInfo").textContent = info;
  $("#senhaTempValor").textContent = senha;
  $("#modalSenhaTemp").hidden = false;
}

$("#usersBody").addEventListener("change", async e=>{
  const s = e.target.closest("[data-papel]");
  if(!s) return;
  try{
    await api("usuarios", {metodo:"PUT", params:{id:s.dataset.papel}, corpo:{papel:s.value}});
    aviso("Papel atualizado.");
    await carregarUsuarios();
  }catch(err){ falha(err); await carregarUsuarios(); }
});

$("#usersBody").addEventListener("click", async e=>{
  const rd = e.target.closest("[data-redefinir]");
  const rm = e.target.closest("[data-remover]");
  if(rd){
    const u = estado.usuarios.find(x=>x.id===Number(rd.dataset.redefinir));
    if(!u || !confirm(`Gerar uma nova senha provisória para ${u.nome}? A senha atual deixará de funcionar.`)) return;
    try{
      const r = await api("usuarios", {metodo:"POST", params:{acao:"redefinir", id:u.id}});
      mostrarSenhaTemp(`Nova senha de ${u.nome} (${u.email}):`, r.senha_temporaria);
    }catch(err){ falha(err); }
  }else if(rm){
    const u = estado.usuarios.find(x=>x.id===Number(rm.dataset.remover));
    if(!u || !confirm(`Remover o acesso de ${u.nome}?`)) return;
    try{
      await api("usuarios", {metodo:"DELETE", params:{id:u.id}});
      await carregarUsuarios();
      aviso("Acesso removido.");
    }catch(err){ falha(err); }
  }
});

$("#formUsuario").addEventListener("submit", async e=>{
  e.preventDefault();
  try{
    const r = await api("usuarios", {metodo:"POST", corpo:{
      nome: $("#usrNome").value, email: $("#usrEmail").value, papel: $("#usrPapel").value
    }});
    $("#modalUsuario").hidden = true;
    e.target.reset();
    mostrarErro($("#usrErro"), "");
    await carregarUsuarios();
    mostrarSenhaTemp(`Acesso criado para ${r.usuario.nome} (${r.usuario.email}). Senha provisória:`, r.senha_temporaria);
  }catch(err){ mostrarErro($("#usrErro"), err.message); }
});

$("#btnCopiarSenha").addEventListener("click", async ()=>{
  try{ await navigator.clipboard.writeText($("#senhaTempValor").textContent); aviso("Senha copiada."); }
  catch(_){ aviso("Selecione a senha e copie manualmente.", true); }
});

/* ---------------- Trocar minha senha ---------------- */
$("#btnSenha").addEventListener("click", ()=>{
  $("#formSenha").reset();
  mostrarErro($("#senErro"), "");
  $("#modalSenha").hidden = false;
  $("#senAtual").focus();
});
$("#formSenha").addEventListener("submit", async e=>{
  e.preventDefault();
  if($("#senNova").value !== $("#senNova2").value) return mostrarErro($("#senErro"), "As senhas novas não conferem.");
  try{
    await api("senha", {metodo:"POST", corpo:{atual: $("#senAtual").value, nova: $("#senNova").value}});
    $("#modalSenha").hidden = true;
    aviso("Senha alterada.");
  }catch(err){ mostrarErro($("#senErro"), err.message); }
});

/* ---------------- Modal do dia ---------------- */
function renderModalDia(){
  const dia = estado.diaAberto;
  $("#diaTitulo").textContent = `Dia ${dia} de ${MESES[estado.mes].toLowerCase()}`;
  const itens = estado.cal.dias[dia] || [];
  $("#diaLista").innerHTML = itens.map(it=>`<li>${textoItem(it)}<button type="button" aria-label="Remover" data-rm="${it.id}">×</button></li>`).join("")
    || `<li class="empty" style="background:none;border:0;font-weight:500">Nenhum médico neste dia.</li>`;
  $("#diaFeriado").checked = !!estado.cal.feriados[dia];
}

$("#calGrid").addEventListener("click", e=>{
  const c = e.target.closest(".cell[data-dia]");
  if(!c || document.body.dataset.role==="viewer") return;
  estado.diaAberto = Number(c.dataset.dia);
  $("#diaObs").value = "";
  $("#diaTag").value = "";
  renderModalDia();
  $("#modalDia").hidden = false;
});
$("#calGrid").addEventListener("keydown", e=>{ if(e.key==="Enter" && e.target.matches(".cell[data-dia]")) e.target.click(); });

const dataDoDia = ()=>iso(estado.ano, estado.mes, estado.diaAberto);

$("#btnDiaAdd").addEventListener("click", async e=>{
  const idMedico = $("#diaMedico").value;
  if(!idMedico) return aviso("Cadastre um médico primeiro.", true);
  const btn = e.currentTarget, dia = estado.diaAberto;
  btn.disabled = true;
  try{
    const r = await api("agendamentos", {metodo:"POST", corpo:{
      id_medico: Number(idMedico), data: dataDoDia(),
      id_abreviacao: $("#diaTag").value ? Number($("#diaTag").value) : null,
      observacao: $("#diaObs").value
    }});
    $("#diaObs").value = "";
    (estado.cal.dias[dia] = estado.cal.dias[dia] || []).push(r.item);
    mutou();
    renderCalendario();
    renderModalDia();
  }catch(err){ falha(err); }
  finally{ btn.disabled = false; }
});

$("#diaLista").addEventListener("click", async e=>{
  const b = e.target.closest("[data-rm]");
  if(!b) return;
  const dia = estado.diaAberto, id = Number(b.dataset.rm);
  b.disabled = true;
  try{
    await api("agendamentos", {metodo:"DELETE", params:{id}});
    estado.cal.dias[dia] = (estado.cal.dias[dia] || []).filter(x=>x.id !== id);
    mutou();
    renderCalendario();
    renderModalDia();
  }catch(err){ b.disabled = false; falha(err); }
});

$("#diaFeriado").addEventListener("change", async e=>{
  const marcado = e.target.checked, dia = estado.diaAberto;
  try{
    await api("feriados", {metodo:"POST", corpo:{data: dataDoDia(), feriado: marcado}});
    if(marcado) estado.cal.feriados[dia] = true; else delete estado.cal.feriados[dia];
    mutou();
    renderCalendario();
  }catch(err){ e.target.checked = !marcado; falha(err); }
});

/* ---------------- Navegação entre telas ---------------- */
function mostrar(){
  let v = (location.hash||"#calendario").slice(1);
  if(!$("#view-"+v)) v="calendario";
  const role = document.body.dataset.role;
  if((v==="usuarios" && role!=="adm") || (v==="medicos" && role==="viewer")) v="calendario";
  $$(".view").forEach(s=>s.hidden = s.id!=="view-"+v);
  $$(".tabs a").forEach(a=>a.classList.toggle("active",a.dataset.view===v));
  if(v==="usuarios" && estado.usuario) carregarUsuarios().catch(falha);
}
window.addEventListener("hashchange", mostrar);

$("#roleSwitch").addEventListener("change", e=>{ document.body.dataset.role = e.target.value; mostrar(); });
$("#prevMonth").onclick = ()=>{ estado.mes--; if(estado.mes<0){estado.mes=11;estado.ano--;} carregarMes().catch(falha); };
$("#nextMonth").onclick = ()=>{ estado.mes++; if(estado.mes>11){estado.mes=0;estado.ano++;} carregarMes().catch(falha); };
$("#today").onclick = ()=>{ const h=new Date(); estado.ano=h.getFullYear(); estado.mes=h.getMonth(); carregarMes().catch(falha); };
$("#btnPrint").onclick = ()=>window.print();
$("#btnPdf").onclick = ()=>window.print(); // no diálogo, escolha "Salvar como PDF"

/* ---------------- Modais (abrir / fechar) ---------------- */
const fechar = m=>m.hidden=true;
$$("[data-open]").forEach(b=>{ if(b.dataset.open!=="modalMedico") b.onclick=()=>$("#"+b.dataset.open).hidden=false; });
$$(".overlay").forEach(o=>{
  o.addEventListener("click",e=>{ if(e.target===o||e.target.matches("[data-close]")) fechar(o); });
});
document.addEventListener("keydown",e=>{ if(e.key==="Escape") $$(".overlay").forEach(fechar); });

/* ---------------- Login / sessão ---------------- */
function mostrarLogin(msg="", setup=false){
  $("#carregando").hidden = true;
  estado.usuario = null;
  $("#app").hidden = true;
  $("#login").hidden = false;
  $("#loginForm").hidden = setup;
  $("#setupForm").hidden = !setup;
  mostrarErro($("#loginErro"), setup ? "" : msg);
  mostrarErro($("#setErro"), setup ? msg : "");
  $("#loginSenha").value = "";
  (setup ? $("#setNome") : $("#loginEmail")).focus();
}

function sessaoExpirou(){
  if($("#login").hidden) {
    $$(".overlay").forEach(fechar);
    iniciar("Sua sessão expirou. Entre novamente.");
  }
}

async function entrar(usuario){
  $("#carregando").hidden = true;
  estado.usuario = usuario;
  document.body.dataset.role = usuario.papel;
  $("#roleSwitch").value = usuario.papel;
  $("#previewBox").hidden = usuario.papel !== "adm";
  $("#nomeUsuario").textContent = usuario.nome;
  $("#login").hidden = true;
  $("#app").hidden = false;
  estado.cache = {};
  await carregarMes();
  mostrar();
}

async function iniciar(msg=""){
  try{
    const r = await api("me");
    estado.csrf = r.csrf;
    if(r.autenticado) await entrar(r.usuario);
    else mostrarLogin(msg, r.precisa_setup);
  }catch(e){
    mostrarLogin(e.message);
  }
}

const EMAIL_OK = v=>/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);

$("#loginForm").addEventListener("submit", async e=>{
  e.preventDefault();
  const email = $("#loginEmail").value.trim();
  const senha = $("#loginSenha").value;
  const erro = $("#loginErro");
  mostrarErro(erro, "");
  if(!email) return mostrarErro(erro, "Informe o e-mail."), $("#loginEmail").focus();
  if(!EMAIL_OK(email)) return mostrarErro(erro, "Digite um e-mail válido."), $("#loginEmail").focus();
  if(!senha) return mostrarErro(erro, "Informe a senha."), $("#loginSenha").focus();

  const btn = $("#btnEntrar");
  btn.disabled = true; btn.textContent = "Entrando…";
  try{
    const r = await api("login", {metodo:"POST", corpo:{email, senha}});
    estado.csrf = r.csrf;
    await entrar(r.usuario);
  }catch(err){
    mostrarErro(erro, err.message);
    $("#loginSenha").value = "";
  }finally{
    btn.disabled = false; btn.textContent = "Entrar";
  }
});

$("#setupForm").addEventListener("submit", async e=>{
  e.preventDefault();
  const nome = $("#setNome").value.trim(), email = $("#setEmail").value.trim();
  const s1 = $("#setSenha").value, s2 = $("#setSenha2").value;
  const erro = $("#setErro");
  mostrarErro(erro, "");
  if(!nome) return mostrarErro(erro, "Informe o nome.");
  if(!EMAIL_OK(email)) return mostrarErro(erro, "Digite um e-mail válido.");
  if(s1.length < 8) return mostrarErro(erro, "A senha deve ter pelo menos 8 caracteres.");
  if(s1 !== s2) return mostrarErro(erro, "As senhas não conferem.");

  const btn = $("#btnSetup");
  btn.disabled = true;
  try{
    await api("setup", {metodo:"POST", corpo:{nome, email, senha: s1}});
    const r = await api("login", {metodo:"POST", corpo:{email, senha: s1}});
    estado.csrf = r.csrf;
    $("#setupForm").reset();
    await entrar(r.usuario);
    aviso("Administrador criado. Bem-vindo!");
  }catch(err){
    mostrarErro(erro, err.message);
    if(err.status === 403) iniciar();   // já existe admin: volta para o login
  }finally{
    btn.disabled = false;
  }
});

$("#btnLogout").onclick = async ()=>{
  try{ await api("logout", {metodo:"POST"}); }catch(_){}
  location.hash = "#calendario";
  iniciar();
};

iniciar();
