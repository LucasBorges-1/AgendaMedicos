"""Teste HTTP real. Execute somente em uma instância com banco descartável.

TEST_ALLOW_WRITES=1 API_BASE=http://127.0.0.1:8080/ \
ADMIN_EMAIL=admin@teste.local ADMIN_PASSWORD=... python3 tests/integration.py
"""
import http.cookiejar
import json
import os
import secrets
import urllib.error
import urllib.parse
import urllib.request

if os.environ.get('TEST_ALLOW_WRITES') != '1':
    raise SystemExit('Use TEST_ALLOW_WRITES=1 somente com um banco de testes descartável.')

BASE = os.environ.get('API_BASE', 'http://127.0.0.1:8080/').rstrip('/') + '/'
PREFIX = 'TESTE-' + secrets.token_hex(4)
PASSWORD = secrets.token_urlsafe(16)
checks = 0


def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1
    print('OK', message)


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = None

    def request(self, route, method='GET', data=None, expected=200, csrf=True):
        url = BASE + 'api/index.php?' + urllib.parse.urlencode({'rota': route.split('?')[0]})
        if '?' in route:
            url += '&' + route.split('?', 1)[1]
        headers = {'Accept': 'application/json'}
        if method != 'GET':
            headers['Content-Type'] = 'application/json'
            if csrf and self.csrf:
                headers['X-CSRF-Token'] = self.csrf
        request = urllib.request.Request(url, data=None if data is None else json.dumps(data).encode(), method=method, headers=headers)
        try:
            response = self.opener.open(request)
        except urllib.error.HTTPError as error:
            response = error
        body = json.loads(response.read())
        check(response.status == expected, f'{method} {route.split("?")[0]} → {expected} (recebido {response.status})')
        return body.get('data', body)

    def session(self):
        data = self.request('sessao')
        self.csrf = data['csrf']
        return data

    def login(self, email, password):
        self.session()
        data = self.request('login', 'POST', {'email': email, 'senha': password})
        self.csrf = data['csrf']
        return data['usuario']

    def page(self, path):
        return self.opener.open(BASE + path)


admin = Client()
check(admin.session()['usuario'] is None, 'sessão anônima não contém usuário')
admin.request('calendario', expected=401)
admin.request('login', 'POST', {'email': 'x@teste.local', 'senha': 'x'}, expected=419, csrf=False)
admin.request('login', 'POST', {'email': 'x@teste.local', 'senha': 'senha-invalida'}, expected=401)
actor = admin.login(os.environ['ADMIN_EMAIL'], os.environ['ADMIN_PASSWORD'])
check(actor['papel'] == 'adm', 'administrador autenticado')
admin.request('usuarios', 'POST', {'nome': 'x', 'email': 'invalido', 'senha': PASSWORD}, expected=422)

viewer = admin.request('usuarios', 'POST', {'nome': PREFIX + ' viewer', 'email': PREFIX.lower()+'-v@teste.local', 'senha': PASSWORD, 'papel': 'viewer'}, expected=201)
editor = admin.request('usuarios', 'POST', {'nome': PREFIX + ' editor', 'email': PREFIX.lower()+'-e@teste.local', 'senha': PASSWORD, 'papel': 'editor'}, expected=201)
check('senha_hash' not in viewer and 'senha' not in viewer, 'respostas não expõem senhas')
admin.request('usuarios', 'POST', {'nome': PREFIX, 'email': viewer['email'].upper(), 'senha': PASSWORD}, expected=409)
admin.request('usuarios/'+str(actor['id_usuario']), 'PATCH', {'papel': 'viewer'}, expected=409)
admin.request('usuarios/'+str(actor['id_usuario']), 'DELETE', expected=409)

read = Client()
read.login(viewer['email'], PASSWORD)
read.request('usuarios', expected=403)
read.request('medicos', 'POST', {'nome': PREFIX}, expected=403)
read.request('escalas/2037-06-07', 'PUT', {'profissionais': ['Equipe']}, expected=403)
write = Client()
write.login(editor['email'], PASSWORD)
write.request('usuarios', expected=403)

doctor = write.request('medicos', 'POST', {'nome': PREFIX+' Médico São João <script>alert(1)</script>', 'tipo': 'fixo', 'dias_semana': [1,3]}, expected=201)
doctor_id = str(doctor['id_medico'])
check(doctor['dias_semana'] == [1,3], 'dias fixos persistidos')
write.request('medicos/'+doctor_id, 'PATCH', {'dias_semana': [9]}, expected=422)
check(write.request('medicos/'+doctor_id)['dias_semana'] == [1,3], 'edição inválida não altera dias fixos')
tag = write.request('abreviacoes', 'POST', {'sigla': PREFIX, 'descricao': 'Observação à tarde'}, expected=201)
tag_id = str(tag['id_abreviacao'])
entry = write.request('agendamentos', 'POST', {'id_medico': doctor['id_medico'], 'data': '2037-06-01', 'id_abreviacao': tag['id_abreviacao'], 'observacao': "Atenção: paciente não é armazenado. D'Ávila <script>1</script>"}, expected=201)
entry_id = str(entry['id_agendamento'])
check(entry['id_usuario'] == editor['id_usuario'], 'autor do agendamento vem da sessão')
write.request('agendamentos', 'POST', {'id_medico': doctor['id_medico'], 'data': '2037-06-01'}, expected=409)
write.request('agendamentos/'+entry_id, 'PATCH', {'status': 'INVENTADO'}, expected=422)
write.request('agendamentos', 'POST', {'id_medico': doctor['id_medico'], 'data': '2037-02-30'}, expected=422)
write.request('agendamentos', 'POST', {'id_medico': 2147483647, 'data': '2037-06-02'}, expected=404)
write.request('abreviacoes/'+tag_id, 'DELETE', expected=409)
entry = write.request('agendamentos/'+entry_id, 'PATCH', {'status': 'CONFIRMADO', 'observacao': 'Observação com acentos: ação, manhã e biópsia. <script>alert(1)</script>'})
check(entry['status'] == 'CONFIRMADO', 'status e observação atualizados')

write.request('feriados/2037-06-08', 'PUT', {'descricao': PREFIX+' Feriado'})
first = write.request('dias-fixos', 'POST', {'ano': 2037})
second = write.request('dias-fixos', 'POST', {'ano': 2037})
check(first['criados'] > 0 and second['criados'] == 0, 'aplicação anual idempotente')
data = read.request('calendario?mes=2037-06')
check(not any(a['data']=='2037-06-08' and a['id_medico']==doctor['id_medico'] for a in data['agendamentos']), 'dias fixos respeitam feriados')
check(len([a for a in data['agendamentos'] if a['data']=='2037-06-01' and a['id_medico']==doctor['id_medico']])==1, 'aplicação anual preserva agendamento manual')
write.request('agendamentos/'+entry_id, 'DELETE')
write.request('dias-fixos', 'POST', {'ano': 2037})
data = read.request('calendario?mes=2037-06')
same = [a for a in data['agendamentos'] if a['data']=='2037-06-01' and a['id_medico']==doctor['id_medico']]
check(len(same)==1 and same[0]['status']=='CANCELADO', 'aplicação anual mantém cancelamento')
write.request('agendamentos/'+entry_id, 'PATCH', {'status':'CONCLUIDO','id_abreviacao':None})

scale = write.request('escalas/2037-06-07','PUT',{'profissionais':['TÁTI','ANÉ','João'],'observacao':'Equipe completa'})
check([p['ordem'] for p in scale['profissionais']] == [1,2,3], 'ordem dos profissionais preservada')
write.request('escalas/2037-06-07','PUT',{'profissionais':['Duplicado','Duplicado']},expected=422)
check(len(read.request('escalas/2037-06-07')['profissionais'])==3, 'escala preservada após requisição inválida')
write.request('escalas/2037-06-07','PUT',{'profissionais':['MARGA'],'observacao':'Escala atualizada'})
check(len(read.request('escalas/2037-06-07')['profissionais'])==1, 'edição substitui profissionais sem duplicar escala')
write.request('escalas/2037-06-07','DELETE')
check(read.request('escalas/2037-06-07') is None, 'escala e profissionais removidos juntos')

write.request('medicos/'+doctor_id, 'DELETE')
check(not any(m['id_medico']==doctor['id_medico'] for m in read.request('medicos')), 'médico inativo não aparece entre opções do visualizador')
check(any(a['id_medico']==doctor['id_medico'] for a in read.request('calendario?mes=2037-06')['agendamentos']), 'desativar médico preserva histórico')
write.request('agendamentos', 'POST', {'id_medico':doctor['id_medico'],'data':'2037-07-01'}, expected=422)
write.request('medicos/'+doctor_id, 'PATCH', {'ativo':True,'tipo':'variado'})
check(write.request('medicos/'+doctor_id)['dias_semana']==[], 'trocar para agenda variável remove dias fixos')

for path in ['index.php', 'imprimir.php?mes=2037-06']:
    response=read.page(path)
    html=response.read().decode()
    check(response.status==200 and 'Clínica Vidas' in html, path+' responde ao usuário autenticado')
    check('<script>alert(1)</script>' not in html, path+' escapa conteúdo de registros')
    if path=='index.php':
        check('id="newUser"' not in html and 'id="appointmentForm"' not in html, 'visualizador não recebe formulários de edição')
response=read.page('pdf.php?mes=2037-06')
pdf=response.read()
check(response.headers.get_content_type()=='application/pdf' and pdf.startswith(b'%PDF-1.4') and pdf.endswith(b'%%EOF\n'), 'download gera PDF real')
if os.environ.get('PDF_TEST_OUTPUT'):
    with open(os.environ['PDF_TEST_OUTPUT'],'wb') as target:
        target.write(pdf)

for path in ['.env','app/bootstrap.php','database/schema.sql','bin/create-admin.php','tests/integration.py','conexao.php','compose.yaml']:
    try:
        read.page(path)
        raise AssertionError('Arquivo privado acessível: '+path)
    except urllib.error.HTTPError as error:
        check(error.code in (403,404), 'arquivo privado bloqueado: '+path)

admin.request('usuarios/'+str(editor['id_usuario']),'PATCH',{'senha':PASSWORD+'-nova'})
write.request('medicos',expected=401)
write.login(editor['email'],PASSWORD+'-nova')
check(write.request('medicos') is not None,'troca de senha revoga sessão antiga e permite novo login')
admin.request('usuarios/'+str(viewer['id_usuario']),'DELETE')
read.request('calendario?mes=2037-06',expected=401)
admin.request('usuarios/'+str(editor['id_usuario']),'DELETE')
write.request('medicos',expected=401)
admin.request('feriados/2037-06-08','DELETE')
admin.request('medicos/'+doctor_id,'DELETE')
admin.request('abreviacoes/'+tag_id,'DELETE')
admin.request('logout','POST')
admin.request('usuarios',expected=401)
print(f'\n{checks} verificações passaram. Use banco descartável: registros históricos de teste são mantidos.')
