"""Session persistence across two instances. Both must use SESSION_DRIVER=database.

API_BASE=http://127.0.0.1:8081/ SECOND_API_BASE=http://127.0.0.1:8082/
ADMIN_EMAIL=... ADMIN_PASSWORD=... python3 tests/serverless.py
"""
import http.cookiejar
import json
import os
import urllib.request

jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
a = os.environ['API_BASE'].rstrip('/') + '/'
b = os.environ['SECOND_API_BASE'].rstrip('/') + '/'


def request(base, route, data=None, csrf=None):
    headers = {'Content-Type': 'application/json'}
    if csrf:
        headers['X-CSRF-Token'] = csrf
    req = urllib.request.Request(base+'api/index.php?rota='+route,
        data=None if data is None else json.dumps(data).encode(), headers=headers)
    with client.open(req) as response:
        assert response.status == 200
        return json.load(response)['data']


session = request(a, 'sessao')
logged = request(a, 'login', {'email': os.environ['ADMIN_EMAIL'],
    'senha': os.environ['ADMIN_PASSWORD']}, session['csrf'])
other = request(b, 'sessao')
assert other['usuario']['id_usuario'] == logged['usuario']['id_usuario']
assert other['csrf'] == logged['csrf']
print('OK sessão e CSRF preservados entre instâncias')
request(b, 'logout', {}, other['csrf'])
assert request(a, 'sessao')['usuario'] is None
print('OK logout na segunda instância revoga a sessão na primeira')
