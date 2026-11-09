# Agenda Vidas — PHP puro + PostgreSQL

Aplicação concluída a partir do layout enviado. O servidor usa PHP sem framework, PDO e PostgreSQL; a interface usa JavaScript puro e o CSS original adaptado. Não há dependência de Composer, Node, CDN, conta externa ou serviço de e-mail para executar o site.

## Funcionalidades

- Login, logout e sessão com expiração após duas horas de inatividade.
- Perfis de administrador, responsável e visualizador, verificados em todas as APIs.
- Calendário mensal com navegação por mês, detalhes do dia e dados persistidos.
- Cadastro, edição, desativação e reativação de médicos e usuários.
- Dias fixos por médico e geração anual sem duplicidades, preservando datas já editadas e canceladas.
- Agendamentos diários com abreviação, observação, autor e status: agendado, confirmado, cancelado ou concluído.
- Escala do cardiologista com até 12 profissionais por data, navegação entre meses, cadastro em qualquer dia e salvamento individual ou das alterações do mês. Rascunhos permanecem ao navegar entre meses na mesma página; recarregar ou fechar a página exige salvá-los antes.
- Cadastro de abreviações e legenda atualizada automaticamente.
- Feriados persistidos, impressão e download de PDF real, gerado no PHP. Impressão e PDF usam uma única folha A4 em paisagem, com siglas e escalas em vermelho, legenda e observações no rodapé. Conteúdo denso reduz a fonte automaticamente.

O banco fornecido representa uma **agenda diária de médicos**, sem horários ou pacientes. Cada médico pode ter um agendamento não cancelado por data. Os nomes do projeto original eram exemplos e não foram importados como registros reais.

## Hospedagem na Vercel com Supabase

Consulte [DEPLOY_VERCEL.md](DEPLOY_VERCEL.md) para configurar as variáveis, executar a migração de sessões e publicar. O projeto inclui roteamento Vercel, runtime comunitário PHP 8.5 e sessões compartilhadas em PostgreSQL. A migração é executada manualmente antes do deploy, nunca automaticamente nas requisições.

## Instalação local

Requisitos: PHP **8.1+** com `pdo_pgsql`, `session`, `json` e `iconv`; PostgreSQL **12+**. Em Debian/Ubuntu, os pacotes usuais são `php-cli`, `php-pgsql` e `postgresql`. No Windows, habilite `pdo_pgsql` no `php.ini`. A pasta de sessões configurada no PHP precisa permitir gravação pelo processo PHP.

1. Extraia `AgendaVidas` e abra um terminal nessa pasta.
2. Crie um banco vazio e um usuário proprietário. No console SQL do PostgreSQL, usando uma conta com permissão administrativa:

   ```sql
   CREATE USER agenda_vidas WITH PASSWORD 'substitua-por-uma-senha-forte';
   CREATE DATABASE agenda_vidas OWNER agenda_vidas;
   ```

3. Copie `.env.example` para `.env` e preencha `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD`. Use a senha definida no PostgreSQL. No Linux/macOS:

   ```sh
   cp .env.example .env
   ```

   No PowerShell: `Copy-Item .env.example .env`. Valores entre aspas são aceitos; variáveis de ambiente do servidor têm prioridade. As credenciais não ficam no código-fonte.

4. Crie/atualize as tabelas e cadastre seu administrador:

   ```sh
   php bin/migrate.php
   php bin/create-admin.php
   ```

   O segundo comando solicita nome, e-mail e senha. Não existe senha padrão. A senha deve ter entre 10 e 72 bytes e é armazenada com `password_hash()`. O cadastro de outros usuários é feito pela tela Usuários, pelo administrador.

5. Inicie o servidor de desenvolvimento usando o roteador incluído:

   ```sh
   php -S 127.0.0.1:8080 router.php
   ```

6. Acesse **http://127.0.0.1:8080/login.php**. Cadastre médicos, defina os dias fixos e use **Aplicar dias fixos ao ano** no calendário. A geração é explícita: salvar o médico não modifica silenciosamente o calendário.

Não use o servidor embutido do PHP para produção. O `router.php` bloqueia o acesso HTTP a configurações, SQL e arquivos internos durante o desenvolvimento.

## Se o SQL original já está instalado

Configure `.env` apontando para o banco existente e execute `php bin/migrate.php`. O instalador reconhece as sete tabelas fornecidas e aplica somente os complementos. O comando pode ser repetido: as migrações já aplicadas não são executadas novamente.

Os complementos em `database/001_complementos.sql` adicionam:

- `feriado`, pois a marcação já existia no layout mas não no esquema.
- `login_tentativa`, para limitar tentativas de autenticação por IP.
- `app_migration`, criada pelo instalador para registrar a versão aplicada.
- Índices de consulta e unicidade para médico/data não cancelados, escala/data e e-mail sem diferença entre maiúsculas e minúsculas.
- As abreviações da interface original, sem sobrescrever siglas existentes.

As sete tabelas de negócio continuam com os campos enviados. Se o banco existente tiver duplicidades incompatíveis com os índices, a migração falha e desfaz a transação, sem apagar registros. Revise essas duplicidades antes de tentar novamente. Um esquema parcial também é recusado para evitar instalação inconsistente.

Os novos usuários usam os papéis `adm`, `editor` e `viewer`. Para bancos existentes, a autenticação também reconhece `Administrador`/`admin` e `Responsável`/`responsavel`; outros papéis recebem somente leitura. Médicos novos usam os tipos `fixo` ou `variado`.

## Execução opcional com Docker

O projeto inclui PHP 8.3/Apache e PostgreSQL 16. Com Docker e Compose instalados:

```sh
cp .env.example .env
# Edite .env e defina uma DB_PASSWORD forte antes de continuar.
docker compose up -d --build
docker compose exec app php bin/migrate.php
docker compose exec app php bin/create-admin.php
```

Acesse http://127.0.0.1:8080. O Compose substitui `DB_HOST` por `db` e mantém os dados no volume `postgres_data`. O PostgreSQL não publica porta externa. A configuração `.env` não é copiada para a imagem. Ao alterar o código, reconstrua a imagem. Os arquivos Docker são uma alternativa de instalação; os testes realizados nesta entrega usaram PHP e PostgreSQL diretamente.

## APIs

Base: `api/index.php?rota=RECURSO`. Essa forma funciona em subpastas, Apache e Nginx sem regras de reescrita. O roteador de desenvolvimento também aceita `api/index.php/RECURSO`.

Todas as respostas de sucesso são JSON no formato `{"data": ...}`. Falhas retornam `{"erro": "mensagem"}` com o status HTTP correspondente. Envie JSON nas operações com corpo. Para POST, PUT, PATCH e DELETE, envie o cookie de sessão e o cabeçalho `X-CSRF-Token`.

| Rota | Métodos | Acesso e função |
| --- | --- | --- |
| `sessao` | GET | Retorna usuário atual (ou null) e token CSRF |
| `login` | POST | Autentica com `email` e `senha`; devolve novo token CSRF |
| `logout` | POST | Encerra a sessão |
| `calendario&mes=2026-11` | GET | Autenticados; agrega médicos, agendamentos, escalas, feriados e abreviações |
| `medicos` | GET, POST | Autenticados consultam; responsável/admin cadastra |
| `medicos/ID` | GET, PUT, PATCH, DELETE | Consulta, edição e desativação lógica |
| `agendamentos&mes=2026-11` | GET | Agendamentos do mês |
| `agendamentos` | POST | Cria um agendamento; autor obtido da sessão |
| `agendamentos/ID` | GET, PUT, PATCH, DELETE | Consulta, edição e cancelamento lógico |
| `dias-fixos` | POST | Responsável/admin; recebe `{"ano":2026}` e retorna quantos registros criou |
| `escalas&mes=2026-11` | GET | Escalas do mês com profissionais ordenados |
| `escalas` | POST | Recebe `data`, `profissionais` e `observacao` |
| `escalas/AAAA-MM-DD` | GET, PUT, PATCH, DELETE | Consulta, substituição da equipe ou remoção da escala |
| `abreviacoes` | GET, POST | Lista ou cria siglas |
| `abreviacoes/ID` | GET, PUT, PATCH, DELETE | Consulta, edita ou exclui uma sigla não utilizada |
| `usuarios` | GET, POST | Somente administrador; lista ou cadastra usuários |
| `usuarios/ID` | GET, PUT, PATCH, DELETE | Somente administrador; edita ou desativa acesso |
| `feriados&mes=2026-11` | GET | Feriados do mês |
| `feriados/AAAA-MM-DD` | PUT, DELETE | Define `descricao` ou remove a marcação |

As alterações, exceto login/logout, exigem responsável ou administrador; as de usuários exigem administrador. `medico_dia_semana` é gerenciada junto de `medicos` pelo campo `dias_semana`; `escala_profissional` é gerenciada junto de `escalas` pelo campo `profissionais`. As operações relacionadas são transacionais, dispensando endpoints que deixem registros incompletos.

Exemplos de corpos:

```json
{"nome":"Dra. Maria","tipo":"fixo","dias_semana":[1,3,5],"ativo":true}
```

Dias: 0=domingo, 1=segunda, ..., 6=sábado. Para tipo `fixo`, ao menos um dia é obrigatório. Alterar para `variado` remove os dias fixos cadastrados, preservando os agendamentos que já existem.

```json
{"id_medico":1,"data":"2026-11-20","id_abreviacao":1,"observacao":"Atende a partir das 14h","status":"CONFIRMADO"}
```

`id_abreviacao` aceita null; o usuário autor é sempre obtido da sessão. DELETE cancela o agendamento, mantendo o histórico. Para reativá-lo, edite seu status. Agendamentos antigos podem ser atualizados após a desativação do médico; novos agendamentos exigem médico ativo.

```json
{"profissionais":["Tati","Ane"],"observacao":"Equipe do domingo"}
```

Em escalas, PUT/PATCH substituem a lista completa de profissionais. Uma lista vazia sem observação remove a escala. Nomes repetidos são recusados e a ordem da lista é preservada.

```json
{"nome":"Recepção","email":"recepcao@clinica.com","senha":"escolha-uma-senha-forte","papel":"editor","ativo":true}
```

Na edição de usuário, `senha` vazia mantém a senha atual. Administradores não podem remover o próprio papel/acesso nem eliminar o último administrador ativo. Não há envio de convite por e-mail: o administrador cadastra o acesso e informa as credenciais ao usuário por seu canal habitual.

Fluxo de integração:

1. GET `api/index.php?rota=sessao`, guardando o cookie e `data.csrf`.
2. POST `api/index.php?rota=login` com email/senha e `X-CSRF-Token`.
3. Atualize o token com `data.csrf` retornado no login.
4. Use o cookie e o novo token nas operações seguintes.

Outros endpoints autenticados: `imprimir.php?mes=2026-11` gera uma versão imprimível; `pdf.php?mes=2026-11` devolve um arquivo PDF. A exportação usa as fontes padrão do PDF, com acentos do português; caracteres fora de Windows-1252 são transliterados quando possível.

Status de erro: 400=JSON inválido, 401=sem sessão/credenciais inválidas, 403=sem permissão, 404=não encontrado, 405=método inválido, 409=duplicidade/vínculo em uso, 413=corpo muito grande, 415=tipo de conteúdo inválido, 419=CSRF inválido, 422=validação, 429=limite de login, 503=banco indisponível.

## Regras da agenda

- Aplicar dias fixos insere apenas datas sem registro do médico, incluindo verificação de cancelamentos anteriores. Executar novamente não duplica registros.
- Feriados são ignorados ao gerar dias fixos. Marcar um feriado depois da geração preserva a agenda existente: cancele manualmente os atendimentos que não ocorrerão.
- Desativar médico ou usuário preserva referências e histórico.
- Alterar o nome de um médico ou o significado de uma abreviação atualiza a exibição dos registros vinculados; o esquema não possui versões históricas desses nomes.
- A agenda aceita datas entre 2000 e 2100. Não há cadastro de pacientes ou envio de notificações.

## Publicação em servidor PHP

Publique em uma hospedagem com PHP/PostgreSQL. O arquivo `deploy/apache.conf` exemplifica a liberação de `.htaccess`; `deploy/nginx.conf.example` contém as restrições equivalentes para Nginx.

- Use HTTPS e `SESSION_SECURE=true` ao publicar.
- Mantenha `.env`, `app`, `bin`, `database`, `tests` e `deploy` inacessíveis por HTTP. O Apache precisa aplicar os `.htaccess` incluídos. O Nginx precisa das regras do exemplo.
- Configure o usuário do banco como proprietário apenas deste banco e mantenha backups do PostgreSQL.
- Mantenha o diretório de sessões fora da pasta pública. Se necessário, defina `SESSION_PATH` apontando para um diretório privado e gravável.
- Há limite persistido de dez tentativas de login por IP em 15 minutos, cookies HttpOnly/SameSite, CSRF, consultas preparadas, escape de HTML e cabeçalhos de segurança. Trocar a senha invalida as outras sessões; desativar o usuário revoga o acesso na próxima requisição.

## Verificação

O teste `tests/integration.py` usa apenas a biblioteca padrão do Python e faz chamadas HTTP reais. Ele cria registros e mantém históricos; execute **somente em banco descartável**, com uma instância própria do site:

```sh
TEST_ALLOW_WRITES=1 API_BASE=http://127.0.0.1:8080/ \
ADMIN_EMAIL=admin@teste.local ADMIN_PASSWORD='senha-do-admin-de-teste' \
python3 tests/integration.py
```

Ele cobre autenticação, CSRF, permissões, cadastros, duplicidades, validação de datas/status, aplicação anual, feriados, cancelamentos, escala transacional, histórico, impressão, PDF, bloqueio de arquivos internos e revogação de acesso.

## Estrutura

```text
index.php, login.php          Telas principais
api/index.php                Roteador das APIs
app/bootstrap.php            Configuração, PDO, sessão, autenticação e validação
app/services.php             Regras e transações de negócio
app/pdf.php                  Gerador PDF sem bibliotecas externas
app/print-view.php           Helpers compartilhados das exportações
imprimir.php, pdf.php         Exportações autenticadas
css/, js/, img/              Interface e logo original
database/                   SQL original e complementos
bin/                        Migração e criação do administrador via terminal
deploy/                     Exemplos de configuração do servidor
tests/                      Testes de integração
router.php                  Servidor de desenvolvimento com acesso restrito
Dockerfile, compose.yaml     Instalação opcional com Docker
```
