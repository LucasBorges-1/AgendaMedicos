# Vercel + Supabase

A aplicação continua em PHP. `vercel.json` usa o runtime comunitário [vercel-php 0.9.0](https://github.com/vercel-community/php), PHP 8.5, com Node 22 definido em `package.json`. Esse runtime é mantido pela comunidade, sem suporte oficial da Vercel. O Dockerfile continua disponível para hospedagem PHP tradicional.

## Preparar o banco existente

Faça backup do banco de produção. Usando as credenciais existentes do `.env` da hospedagem em uma máquina autorizada, execute na raiz do projeto:

```sh
php bin/migrate.php
```

A migração preserva os registros existentes e adiciona `002_sessoes`/`app_session`. Pode ser repetida. Não recrie seu administrador se ele já existe. Não execute `tests/integration.py` ou `tests/browser.cjs` no Supabase de produção: eles alteram dados.

As sessões precisam do banco porque arquivos locais não são compartilhados entre instâncias da Vercel. `app_session` tem RLS habilitada, sem políticas para a API pública; use a conexão PDO do proprietário das tabelas. As sessões expiram após duas horas de inatividade. Desativação do usuário, mudança de senha e logout continuam revogando o acesso. A limpeza das sessões expiradas ocorre por coleta probabilística; nenhum cron é necessário para autenticar.

## Configurar o projeto na Vercel

1. Importe este repositório, selecionando a raiz do projeto como Root Directory e **Other** como Framework Preset. Use Node.js **22.x**. Deixe Build Command e Output Directory sem overrides; não selecione uma pasta pública contendo os arquivos PHP.
2. Em **Settings → Environment Variables**, cadastre os valores do banco. O `.env` ignorado pelo Git não será transferido automaticamente. Não coloque credenciais em `vercel.json`, no Git ou no JavaScript.
3. Faça o deploy depois de aplicar a migração. Para Preview, use um projeto Supabase/banco separado de produção.

| Variável | Valor |
| --- | --- |
| `DB_HOST` | Host de **Connect → Transaction pooler** no Supabase |
| `DB_PORT` | Porta indicada pelo pooler, normalmente `6543` |
| `DB_NAME` | Nome existente, normalmente `postgres` |
| `DB_USER` | Usuário completo do pooler, normalmente `postgres.<project-ref>` |
| `DB_PASSWORD` | Senha existente do banco, inserida como valor sensível |
| `DB_SSLMODE` | `require` |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE` | `true` |
| `APP_TIMEZONE` | `America/Sao_Paulo` |

Use os valores exatos do painel **Connect**, incluindo usuário e porta. Não são a URL HTTP do projeto, a chave `anon`, `publishable` nem `service_role`. PDO conecta pelo protocolo PostgreSQL. As consultas usam parâmetros com preparação emulada, compatível com o pooler transaction. Os locks de sessão são de transação, em uma conexão separada das transações de negócio.

`regions: ["gru1"]` posiciona a função em São Paulo. Se seu Supabase estiver em outra região, ajuste para a região Vercel mais próxima do banco antes de publicar. Isso reduz a latência da conexão, não muda a localização do banco existente.

## Disponibilidade e tempo de resposta

Para impedir a pausa automática por inatividade, use **Supabase Pro ou superior** no projeto de produção. No plano gratuito, código da aplicação não fornece garantia de que o projeto ficará ativo. Alterar o plano exige acesso administrativo e tem custo; este trabalho não alterou sua conta ou plano. Um projeto já pausado precisa ser restaurado no painel.

Foram preparados:

- Pooler transaction para limitar o consumo de conexões em serverless, sem conexões PDO persistentes.
- Calendário obtido em uma consulta SQL em vez de cinco viagens sequenciais ao banco.
- Consulta do usuário autenticado reaproveitada dentro da mesma requisição.
- Lista administrativa de usuários carregada somente ao abrir a seção Usuários.
- Salvar uma escala atualiza o calendário com a resposta da API, sem recarregar todos os cadastros.
- Cache de uma hora para CSS, JavaScript e imagens. HTML e dados autenticados permanecem `private, no-store`.
- OPcache habilitado e função próxima ao banco quando as regiões estiverem alinhadas.

A primeira execução da função e o tamanho dos dados ainda afetam a latência. Sem acesso a este Supabase e sem deploy Vercel, os tempos reais de produção não foram medidos. Após publicar, confira duração e erros em Vercel Logs/Observability e conexões/consultas no Supabase.

## Conferência após o deploy

Abra o login por HTTPS e entre com seu administrador existente. Navegue por dois meses, salve uma escala de teste autorizada e confira seu aparecimento no calendário. Recarregue a página e confirme a sessão. Abra impressão e PDF; ambos devem usar uma folha A4 em paisagem. Meses muito densos usam fontes menores, portanto confira legibilidade antes de imprimir.

Requisições a `.env`, `app/bootstrap.php`, `database/schema.sql`, `api/php.ini`, `bin/migrate.php` e caminhos não públicos devem retornar 404. As rotas públicas são explicitamente permitidas pelo despachante; `.htaccess` não é usado na Vercel. Os arquivos internos necessários são incluídos na função e não são páginas públicas.

## Validação local

`tests/integration.py` usa somente Python padrão e realiza 98 verificações HTTP. `tests/serverless.py` verifica a mesma sessão e o logout entre duas instâncias com `SESSION_DRIVER=database`, configuradas por `API_BASE` e `SECOND_API_BASE`. Ambos usam `ADMIN_EMAIL` e `ADMIN_PASSWORD` sem imprimi-los.

`tests/browser.cjs` requer Chromium, Playwright e pdfjs-dist. Instale as ferramentas fora do projeto, por exemplo `npm install --prefix /tmp/agenda-test-tools playwright pdfjs-dist`, e execute com `NODE_PATH=/tmp/agenda-test-tools/node_modules`, `TEST_ALLOW_WRITES=1` e as variáveis de acesso do banco descartável. Use `CHROMIUM_PATH` para o executável do Chromium e `TEST_OUTPUT` para os artefatos. O teste cobre rascunhos entre meses, salvar alterações, persistência, data fora do domingo, celular, impressão e PDF.
