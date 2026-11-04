/* =========================================================
   Clínica Vidas · Frontend (somente interface)
   Os dados abaixo são EXEMPLOS para visualizar o design.
   Substitua pelas chamadas ao seu back-end.
   Marcação de observações: {T} vira (T) em vermelho.
   ========================================================= */

const MESES = ["Janeiro","Fevereiro","Março","Abril","Maio","Junho","Julho","Agosto","Setembro","Outubro","Novembro","Dezembro"];
const DIAS = ["Domingo","segunda-feira","terça-feira","quarta-feira","quinta-feira","sexta-feira","sábado"];

const LEGENDA = "O SIGNIFICADO DAS ABREVIAÇÕES: {T} TARDE - {M} MANHÃ - {DT} DIA TODO - {ESP} ESPUMA - {ATÉ 17} ATÉ HORARIO QUE PODE SER AGENDADO - {CON} CONSULTA - {PROC} PROCTOLOISTA - {PSI} PSICOLOGO - {BIOP} BIOPSIA - {AP.} APARTIR - {CANCEL} CANCELADO";

// EXEMPLO: novembro de 2026 (igual à foto)
const EXEMPLO = {
  "2026-10": {
    dias: {
      2:["{FERIADO}"],
      3:["WENES","JANDERICK"],
      4:["MARCOS{PSIQ}","LOURIVAL","RICHART","BIBIANA/WENES"],
      5:["RICHART","LOURIVAL{BIOP}","ANDRÉ","BIBIANA"],
      6:["RICHART","DENISE","GUILHERME{DT}"],
      9:["SUSAN","SILVIO","MARCOS{PROC}"],
      10:["WENES","LOURIVAL","ALESSANDRO{M}","ROSANGELA"],
      11:["WENES","LOURIVAL{ATÉ 15 H}","RICHART","BIBIANA"],
      12:["RICHART","BIBIANA"],
      13:["RICHART","DENISE"],
      16:["GUILHERME{T}","SUSAN","SILVIO","ROSANGELA"],
      17:["WENES","LOURIVAL","FABIANO"],
      18:["WENES","RICHART","FABIANO","MARCOS{PSI}"],
      19:["RICHART","FABIANO"],
      20:["RICHART","DENISE","FABIANO"],
      23:["GUILHERME","SUSAN /SILVIO","MARCOS{PROC}","FABIANO"],
      24:["WENES","FABIANO","ALESSANDRO{T}"],
      25:["WENES","RICHART","FABIANO"],
      26:["RICHART","DENISE{COM}","ROSANGELA","FABIANO"],
      27:["RICHART","DENISE","FABIANO"],
      30:["GUILHERME","SUSAN","SILVIO"]
    },
    escala: {8:"TATI - ANE",15:"MARGA - AMANDA",22:"TATI - ANE",29:"NATI - AMANDA"}
  }
};
// chave = "ano-mês(0 a 11)"  → "2026-10" é novembro

const MEDICOS = [
  {nome:"RICHART",tipo:"fixo",dias:"Seg a Sex"},
  {nome:"WENES",tipo:"fixo",dias:"Ter, Qua"},
  {nome:"FABIANO",tipo:"fixo",dias:"Seg a Sex"},
  {nome:"LOURIVAL",tipo:"fixo",dias:"Ter, Qua, Qui"},
  {nome:"DENISE",tipo:"fixo",dias:"Sex"},
  {nome:"MARCOS",tipo:"variado",dias:"—"},
  {nome:"GUILHERME",tipo:"variado",dias:"—"},
  {nome:"ALESSANDRO",tipo:"variado",dias:"—"}
];
const USUARIOS = [
  {nome:"Administrador da clínica",email:"adm@clinica.com",papel:"Administrador"},
  {nome:"Recepção",email:"recepcao@clinica.com",papel:"Responsável"},
  {nome:"Equipe",email:"equipe@clinica.com",papel:"Visualizador"}
];

let ano = 2026, mes = 10;
const $ = (s,el=document)=>el.querySelector(s);
const $$ = (s,el=document)=>[...el.querySelectorAll(s)];
const esc = s=>s.replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const marcar = s=>esc(s).replace(/\{([^}]+)\}/g,'<span class="tag">($1)</span>');

/* ---------------- Calendário ---------------- */
function renderCalendario(){
  const dados = EXEMPLO[`${ano}-${mes}`] || {dias:{},escala:{}};
  $("#sheetTitle").textContent = `${MESES[mes]} de ${ano}`;
  const grid = $("#calGrid");
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
    const nomes = (dados.dias[dia]||[]).map(n=>{
      const feriado = n==="{FERIADO}";
      return `<div class="${feriado?'feriado':''}">${marcar(n.replace("{FERIADO}","{FERIADO}")).replace(/<span class="tag">\(FERIADO\)<\/span>/,'FERIADO')}</div>`;
    }).join("");
    const esc_ = col===0 && dados.escala[dia] ? `<span class="escala">${esc(dados.escala[dia])}</span>` : "";
    html += `<div class="cell editable ${esc_?'has-sun':''}" data-dia="${dia}" tabindex="0">
      <span class="num">${dia}</span>${esc_}<div class="names">${nomes}</div></div>`;
  }

  // última linha: próximos dias do mês seguinte + anotações (como na foto)
  const restantes = semanas*7 - (primeiro+total);
  html += [1,2].map(n=>`<div class="cell out"><span class="num">${restantes+n}</span></div>`).join("");
  html += `<div class="notes"><b>Anotações</b>${marcar(LEGENDA).replace("O SIGNIFICADO DAS ABREVIAÇÕES:","<strong>O SIGNIFICADO DAS ABREVIAÇÕES:</strong>")}</div>`;
  grid.innerHTML = html;
  renderEscala();
}

/* ---------------- Escala do cardiologista ---------------- */
function renderEscala(){
  const dados = EXEMPLO[`${ano}-${mes}`] || {escala:{}};
  const linhas = Object.entries(dados.escala).map(([d,txt])=>{
    const [a,b] = txt.split(" - ");
    return `<tr><td><strong>${d} de ${MESES[mes].toLowerCase()}</strong></td>
      <td><input value="${esc(a||"")}"></td><td><input value="${esc(b||"")}"></td>
      <td class="only-edit"><button class="btn btn-danger">Remover</button></td></tr>`;
  }).join("");
  $("#escalaTable tbody").innerHTML = linhas || `<tr><td colspan="4" class="muted">Nenhuma escala registrada neste mês.</td></tr>`;
  $("#escalaMes").innerHTML = `<option>${MESES[mes]} de ${ano}</option>`;
}

/* ---------------- Médicos e usuários ---------------- */
$("#medicosBody").innerHTML = MEDICOS.map(m=>`
  <tr><td><strong>${m.nome}</strong></td>
  <td><span class="badge ${m.tipo==="fixo"?"fixo":"var"}">${m.tipo==="fixo"?"Dias fixos":"Dias variados"}</span></td>
  <td>${m.dias}</td>
  <td style="text-align:right"><button class="btn btn-ghost">Editar</button> <button class="btn btn-danger">Excluir</button></td></tr>`).join("");

$("#usersBody").innerHTML = USUARIOS.map(u=>`
  <tr><td><strong>${u.nome}</strong></td><td>${u.email}</td>
  <td><select class="select">${["Administrador","Responsável","Visualizador"].map(p=>`<option ${p===u.papel?"selected":""}>${p}</option>`).join("")}</select></td>
  <td style="text-align:right"><button class="btn btn-danger">Remover acesso</button></td></tr>`).join("");

$("#diaMedico").innerHTML = MEDICOS.map(m=>`<option>${m.nome}</option>`).join("");

/* ---------------- Navegação entre telas ---------------- */
function mostrar(){
  let v = (location.hash||"#calendario").slice(1);
  if(!$("#view-"+v)) v="calendario";
  const role = document.body.dataset.role;
  if((v==="usuarios" && role!=="adm") || (v==="medicos" && role==="viewer")) v="calendario";
  $$(".view").forEach(s=>s.hidden = s.id!=="view-"+v);
  $$(".tabs a").forEach(a=>a.classList.toggle("active",a.dataset.view===v));
}
window.addEventListener("hashchange",mostrar);

$("#roleSwitch").addEventListener("change",e=>{document.body.dataset.role=e.target.value;mostrar();});
$("#prevMonth").onclick = ()=>{mes--; if(mes<0){mes=11;ano--;} renderCalendario();};
$("#nextMonth").onclick = ()=>{mes++; if(mes>11){mes=0;ano++;} renderCalendario();};
$("#today").onclick = ()=>{ano=2026;mes=10;renderCalendario();};
$("#btnPrint").onclick = ()=>window.print();
$("#btnPdf").onclick = ()=>window.print(); // no diálogo, escolha "Salvar como PDF"

/* ---------------- Modais ---------------- */
const fechar = m=>m.hidden=true;
$$("[data-open]").forEach(b=>b.onclick=()=>$("#"+b.dataset.open).hidden=false);
$$(".overlay").forEach(o=>{
  o.addEventListener("click",e=>{ if(e.target===o||e.target.matches("[data-close]")) fechar(o); });
});
document.addEventListener("keydown",e=>{ if(e.key==="Escape") $$(".overlay").forEach(fechar); });

$("#calGrid").addEventListener("click",e=>{
  const c = e.target.closest(".cell[data-dia]");
  if(!c || document.body.dataset.role==="viewer") return;
  const dados = EXEMPLO[`${ano}-${mes}`] || {dias:{}};
  $("#diaTitulo").textContent = `Dia ${c.dataset.dia} de ${MESES[mes].toLowerCase()}`;
  $("#diaLista").innerHTML = (dados.dias[c.dataset.dia]||[]).map(n=>`<li>${marcar(n)}<button aria-label="Remover">×</button></li>`).join("");
  $("#modalDia").hidden = false;
});
$("#calGrid").addEventListener("keydown",e=>{ if(e.key==="Enter") e.target.click(); });

$("#btnLogout").onclick = ()=>{ $("#app").hidden=true; $("#login").hidden=false; };
$("#login form").addEventListener("submit",()=>{ $("#login").hidden=true; $("#app").hidden=false; });

renderCalendario();
mostrar();
