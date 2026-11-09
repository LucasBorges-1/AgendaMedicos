// npm install --prefix /tmp/agenda-test-tools playwright pdfjs-dist
// NODE_PATH=/tmp/agenda-test-tools/node_modules TEST_ALLOW_WRITES=1 API_BASE=... ADMIN_EMAIL=... ADMIN_PASSWORD=... node tests/browser.cjs
const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 if(process.env.TEST_ALLOW_WRITES!=='1') throw Error('Use somente em banco descartável com TEST_ALLOW_WRITES=1');
 const base=process.env.API_BASE || 'http://127.0.0.1:8081/';
 const out=process.env.TEST_OUTPUT || '/tmp/agenda-browser'; fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1280,height:900}});
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto(base+'login.php');
 await page.locator('[name=email]').fill(process.env.ADMIN_EMAIL);
 await page.locator('[name=senha]').fill(process.env.ADMIN_PASSWORD);
 await page.locator('[type=submit]').click();
 await page.waitForURL('**/index.php'); await page.waitForSelector('#sheet[aria-busy=false]',{state:'attached'});
 const csrf=await page.locator('meta[name=csrf-token]').getAttribute('content');
 for(const date of ['2039-11-06','2039-11-13','2040-02-14']) {
   const res=await page.request.delete(base+'api/index.php?rota=escalas/'+date,{headers:{'X-CSRF-Token':csrf}});
   assert.equal(res.status(),200);
 }
 await page.locator('[data-view=cardiologista]').click();
 await page.locator('#escalaMes').fill('2039-11'); await page.locator('#escalaMes').dispatchEvent('change');
 await page.waitForSelector('[data-scale-date="2039-11-06"]');
 await page.locator('#scale-names-2039-11-06').fill('TATI\nANE');
 await page.locator('#scale-note-2039-11-06').fill('Equipe da manhã');
 await page.locator('#nextScaleMonth').click(); await page.waitForSelector('[data-scale-date="2039-12-04"]');
 await page.locator('#prevScaleMonth').click(); await page.waitForSelector('[data-scale-date="2039-11-06"]');
 assert.equal(await page.locator('#scale-names-2039-11-06').inputValue(),'TATI\nANE');
 assert.match(await page.locator('#scaleSummary').innerText(),/não salvas/);
 await page.locator('#scale-names-2039-11-13').fill('MARGA\nAMANDA');
 await page.locator('#saveAllScales').click();
 await page.waitForFunction(()=>document.querySelector('#saveAllScales').disabled && !document.querySelector('.scale-state.is-dirty'));
 await page.reload(); await page.waitForSelector('#sheet[aria-busy=false]',{state:'attached'});
 await page.locator('#escalaMes').fill('2039-11'); await page.locator('#escalaMes').dispatchEvent('change');
 await page.waitForSelector('[data-scale-date="2039-11-06"]');
 assert.equal(await page.locator('#scale-names-2039-11-06').inputValue(),'TATI\nANE');
 await page.locator('#newScale').click(); await page.locator('#scaleDateForm [name=data]').fill('2040-02-14');
 await page.locator('#scaleDateForm [type=submit]').click();
 await page.waitForSelector('#scale-names-2040-02-14');
 await page.locator('#scale-names-2040-02-14').fill('NATI\nAMANDA');
 await page.locator('[data-action=save-scale][data-date="2040-02-14"]').click();
 await page.waitForFunction(()=>!document.querySelector('.scale-state.is-dirty'));
 assert.equal(await page.locator('#escalaMes').inputValue(),'2040-02');
 await page.screenshot({path:out+'/escala.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await page.screenshot({path:out+'/escala-mobile.png',fullPage:true});
 await page.setViewportSize({width:1280,height:900});
 // Conteúdo real no calendário para verificar ajuste de texto e exportação.
 const post=async(route,data,method='POST')=>{
   const response=await page.request.fetch(base+'api/index.php?rota='+route,{method,headers:{'X-CSRF-Token':csrf},data});
   assert.ok(response.ok(),route+': '+response.status()); return (await response.json()).data;
 };
 const calendar=(await (await page.request.get(base+'api/index.php?rota=calendario&mes=2026-11')).json()).data;
 const tag=calendar.abreviacoes[0];
 for(const name of ['WENES','LOURIVAL','RICHART','BIBIANA','MARCOS','DENISE']) {
   const doctor=calendar.medicos.find(item=>item.nome===name && item.ativo) || await post('medicos',{nome:name,tipo:'variado',ativo:true,dias_semana:[]});
   for(const date of ['2026-11-03','2026-11-04','2026-11-05','2026-11-06','2026-08-31','2026-02-02']) {
     const existing=(await (await page.request.get(base+'api/index.php?rota=agendamentos&mes='+date.slice(0,7))).json()).data;
     if(existing.some(item=>item.id_medico===doctor.id_medico && item.data===date && item.status!=='CANCELADO')) continue;
     await post('agendamentos',{id_medico:doctor.id_medico,data:date,id_abreviacao:tag.id_abreviacao,observacao:name==='WENES'?'Atende até 15h':'',status:'AGENDADO'});
   }
 }
 for(const [date,profissionais] of [['2026-11-08',['TATI','ANE']],['2026-11-15',['MARGA','AMANDA']],['2026-11-22',['TATI','ANE']],['2026-11-29',['NATI','AMANDA']]]) await post('escalas/'+date,{profissionais,observacao:''},'PUT');
 await post('feriados/2026-11-02',{descricao:'FERIADO'},'PUT');
 const pdfjs=await import(require('node:url').pathToFileURL(require.resolve('pdfjs-dist/legacy/build/pdf.mjs')).href);
 for(const month of ['2026-11','2026-08','2026-02']) {
   await page.goto(base+'imprimir.php?mes='+month);
   assert.equal(await page.locator('.print-cell').count(),37);
   assert.equal(await page.locator('.print-details').count(),0);
   const over=await page.locator('.print-content').evaluateAll(nodes=>nodes.filter(n=>n.scrollHeight>n.clientHeight+1).length);
   assert.equal(over,0);
   await page.pdf({path:out+'/'+month+'.pdf',preferCSSPageSize:true,printBackground:true});
   const res=await page.request.get(base+'pdf.php?mes='+month);
   assert.equal(res.status(),200);fs.writeFileSync(out+'/'+month+'-download.pdf',await res.body());
   for(const suffix of ['.pdf','-download.pdf']) {
     const pdf=await pdfjs.getDocument({data:new Uint8Array(fs.readFileSync(out+'/'+month+suffix)),useSystemFonts:true}).promise;
     assert.equal(pdf.numPages,1,'Uma folha: '+month+suffix);
     const text=await (await pdf.getPage(1)).getTextContent();
     const content=text.items.map(item=>item.str).join(' ');
     assert.match(content,/WENES/); assert.match(content,/15h/i);
     assert.ok(text.items.filter(item=>item.str.trim()).every(item=>item.transform[4]>=0 && item.transform[5]>=0 && item.transform[4]<850 && item.transform[5]<600));
   }
 }
 await page.goto(base+'imprimir.php?mes=2026-11');
 await page.screenshot({path:out+'/impressao.png',fullPage:true});
 assert.deepEqual(errors,[]);
 await browser.close();
 console.log('OK navegação entre meses, preservação de rascunhos, salvar mês, persistência, outra data, mobile, impressão de 4/5/6 semanas e ausência de erros JavaScript');
})().catch(error=>{console.error(error);process.exit(1)});
