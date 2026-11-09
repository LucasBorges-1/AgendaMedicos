// NODE_PATH=/tmp/agenda-test-tools/node_modules API_BASE=... ADMIN_EMAIL=... ADMIN_PASSWORD=... node tests/loading.cjs
// Falhas de rede são simuladas no navegador; nenhum cadastro é alterado.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.API_BASE || 'http://127.0.0.1:8081/';
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try {
   const context=await browser.newContext();
   const session=(await (await context.request.get(base+'api/index.php?rota=sessao')).json()).data;
   const login=await context.request.post(base+'api/index.php?rota=login',{headers:{'X-CSRF-Token':session.csrf},data:{email:process.env.ADMIN_EMAIL,senha:process.env.ADMIN_PASSWORD}});
   assert.equal(login.status(),200);
   const page=await context.newPage(),errors=[];
   page.on('pageerror',error=>errors.push(error.message));
   const empty=month=>({mes:month,agendamentos:[],escalas:[],feriados:[],medicos:[],abreviacoes:[]});
   let mode='hang',attempts=0,usersAttempts=0,writes=0,onHeld;
   const pending=[];
   await page.route('**/api/index.php?**',async route=>{
     const url=new URL(route.request().url()),resource=url.searchParams.get('rota'),month=url.searchParams.get('mes');
     if(resource==='medicos' && route.request().method()==='POST') { writes++; return route.abort('failed'); }
     if(resource==='usuarios') {
       usersAttempts++;
       return route.fulfill({status:403,json:{erro:'Falha simulada na lista de usuários.'}});
     }
     if(resource!=='calendario') return route.continue();
     attempts++;
     if(mode==='hang' || mode==='hold') { pending.push(route); onHeld?.(); onHeld=null; return; }
     if(mode==='transient' && attempts===1) return route.fulfill({status:503,json:{erro:'Banco temporariamente indisponível.'}});
     if(mode==='invalid') return route.fulfill({json:{data:{...empty(month),agendamentos:null}}});
     if(mode==='failure') return route.fulfill({status:500,json:{erro:'Falha simulada.'}});
     return route.fulfill({json:{data:empty(month)}});
   });
   await page.clock.install();
   await page.goto(base+'index.php#calendario');
   await page.waitForFunction(()=>document.querySelectorAll('#calGrid .cell').length>0);
   assert.equal(await page.locator('#sheet').getAttribute('aria-busy'),'true');
   assert.equal(await page.locator('#calendarState').isVisible(),true);
   assert.equal(attempts,1);
   // Duas leituras com limite de espera; a interface não depende de recarregar a página.
   await page.clock.runFor(12000);
   await page.clock.runFor(501);
   await page.waitForFunction(()=>document.querySelector('#sheet').getAttribute('aria-busy')==='true');
   await page.clock.runFor(12001);
   await page.locator('#retryLoad').waitFor();
   assert.equal(attempts,2);
   assert.equal(await page.locator('#sheet').getAttribute('aria-busy'),'false');
   assert.ok(await page.locator('#calGrid .cell').count()>0);
   mode='success'; await page.locator('#retryLoad').click();
   await page.waitForFunction(()=>document.querySelector('#sheet').getAttribute('aria-busy')==='false' && document.querySelector('#pageError').hidden);
   assert.equal(await page.locator('#calGrid').getAttribute('inert'),null);
   console.log('OK requisição travada: grade visível, timeout, repetição limitada e recuperação sem atualizar a página');

   mode='transient'; attempts=0;
   const temporaryError=page.waitForResponse(response=>response.url().includes('rota=calendario') && response.status()===503);
   await page.locator('#nextMonth').click();
   await temporaryError;
   await page.clock.runFor(501);
   await page.waitForFunction(()=>document.querySelector('#sheet').getAttribute('aria-busy')==='false');
   assert.equal(attempts,2); assert.equal(await page.locator('#pageError').isVisible(),false);
   console.log('OK falha temporária 503 recuperada automaticamente');

   // Um erro posterior mantém os dias e dados da última carga do mesmo mês.
   const oldGrid=await page.locator('#calGrid').innerHTML();
   mode='failure'; await page.locator('[data-view=usuarios]').click();
   await page.locator('#retryLoad').waitFor();
   assert.equal(await page.locator('#calGrid').innerHTML(),oldGrid);
   assert.match(await page.locator('#calendarState').textContent(),/última agenda/);
   console.log('OK falha na atualização preserva o calendário anterior');

   mode='success'; await page.locator('#retryLoad').click();
   await page.waitForFunction(()=>document.querySelector('#sheet').getAttribute('aria-busy')==='false' && document.querySelector('#pageError').hidden);
   await page.locator('#retryUsers').waitFor(); assert.ok(usersAttempts>0);
   await page.locator('[data-view=calendario]').click();
   assert.ok(await page.locator('#calGrid [data-date]').count()>0);
   assert.equal(await page.locator('#pageError').isVisible(),false);
   console.log('OK falha na lista de usuários não apaga o calendário');

   // A resposta antiga não pode substituir o mês escolhido por último.
   mode='hold'; const initialHeld=pending.length;
   const held=new Promise(resolve=>{onHeld=resolve;});
   await page.locator('#nextMonth').click();
   await page.waitForFunction(()=>document.querySelector('#sheet').getAttribute('aria-busy')==='true');
   await held;
   mode='success'; await page.locator('#nextMonth').click();
   await page.waitForFunction(()=>document.querySelector('#sheet').getAttribute('aria-busy')==='false');
   const month=await page.locator('#calendarMonth').inputValue();
   for(const route of pending.slice(initialHeld)) {
     const oldMonth=new URL(route.request().url()).searchParams.get('mes');
     await route.fulfill({json:{data:empty(oldMonth)}}).catch(()=>{});
   }
   assert.equal(await page.locator('#calGrid').getAttribute('data-loaded-month'),month);
   const dates=await page.locator('#calGrid [data-date]').evaluateAll(nodes=>nodes.map(n=>n.dataset.date));
   assert.ok(dates.every(date=>date.startsWith(month+'-')));
   console.log('OK navegação rápida ignora respostas de meses anteriores');

   mode='invalid'; await page.locator('#nextMonth').click();
   await page.locator('#retryLoad').waitFor();
   assert.match(await page.locator('#pageError').innerText(),/incompletos/);
   assert.ok(await page.locator('#calGrid .cell').count()>0);
   console.log('OK resposta incompleta exibe erro com grade e opção de tentar novamente');

   mode='success'; await page.locator('#retryLoad').click();
   await page.waitForFunction(()=>document.querySelector('#sheet').getAttribute('aria-busy')==='false');
   await page.locator('[data-view=medicos]').click();
   await page.locator('#newDoctor').click();
   await page.locator('#doctorForm [name=nome]').fill('Requisição não enviada ao servidor');
   await page.locator('#doctorForm [type=submit]').click();
   await page.locator('#toast.toast-error').waitFor();
   await page.clock.runFor(1000);
   assert.equal(writes,1);
   assert.equal(await page.locator('#doctorForm [type=submit]').isDisabled(),false);
   console.log('OK escrita com falha de rede não é repetida automaticamente');

   assert.deepEqual(errors,[]);
   for(const route of pending) await route.abort().catch(()=>{});
   await context.close();
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1)});
