# Validação — Vercel, impressão e escala

Verificado em 09/11/2026 com PostgreSQL 16 local descartável. Nenhum teste de escrita ou migração foi executado no Supabase da hospedagem.

- Pacote da função gerado pelo builder `vercel-php@0.9.0`, com `api/vercel.php` como entrada e Node 22. Arquivos internos necessários presentes; `.env` e SQL fora do pacote. Rotas validadas com `@vercel/routing-utils`.
- 98 verificações HTTP aprovadas no artefato gerado com o binário PHP 8.5.12 do runtime e sessões no banco. Cobrem login, CSRF, perfis, CRUD, validações, histórico, impressão, PDF, arquivos privados e revogação de acesso.
- Sessão e CSRF compartilhados entre duas instâncias independentes; logout em uma instância revogou a sessão na outra.
- Migrações em banco vazio e repetição aprovadas; `001_complementos` e `002_sessoes` registradas, RLS de `app_session` habilitada.
- Teste Chromium com APIs reais: navegação da escala entre meses, preservação de rascunhos, salvar alterações do mês, persistência após recarregar, cadastro em data fora do domingo e em outro mês, layout móvel sem transbordamento horizontal. Nenhum erro de JavaScript nesses fluxos.
- Impressão Chromium e download PHP validados em fevereiro, agosto e novembro de 2026: seis PDFs com exatamente uma página, nomes e observações presentes, texto dentro dos limites da folha. Conteúdo real com seis médicos por data, abreviações, feriado e escalas. Prévia HTML e PDF conferidos visualmente.
- Sintaxe PHP e JavaScript e `git diff --check` aprovados.

## Carregamento intermitente do calendário

O aspecto relatado foi reproduzido no Chromium ao manter a requisição do calendário sem resposta: o título aparecia, mas a grade tinha zero células e nenhum aviso. Isso demonstra a falha da interface ao lidar com uma requisição pendente; não identifica a causa da demora no ambiente do usuário.

Após a correção, `tests/loading.cjs` passou em sete cenários simulados: requisição sem resposta com timeout e recuperação sem recarregar a página; recuperação automática de 503; preservação do calendário anterior ao falhar uma atualização; erro de usuários independente do calendário; respostas antigas ignoradas ao navegar rapidamente; resposta incompleta com aviso e nova tentativa; escrita com falha de rede sem repetição automática. O teste usa Playwright e as mesmas variáveis de acesso dos testes de navegador, sem alterar cadastros. As regressões existentes de escala, celular, impressão e PDF também passaram novamente.

As leituras têm limite de 12 segundos por tentativa e podem ser repetidas uma vez; escritas têm limite de 30 segundos e não são repetidas automaticamente. Arquivos CSS e JavaScript da página principal recebem uma versão na URL para renovar o cache após atualizações.

A primeira validação em PHP 8.5 revelou que o login usava o hash antigo na sessão após atualizar o custo da senha. O fluxo foi corrigido e os testes passaram. As execuções finais foram sequenciais, evitando que logins simultâneos de versões diferentes do PHP atualizassem o hash do mesmo administrador durante o teste.

## Limites

Não foi realizado deploy Vercel nem validação contra o Supabase real: o `.env` permanece na hospedagem. A montagem e os testes locais do runtime não substituem a conferência no serviço publicado. O runtime PHP é comunitário. O ambiente Docker local não possui a biblioteca gráfica usada pela extensão opcional GD do runtime; a aplicação não usa GD, e PDO, sessões e geração PDF foram exercitados.

A pausa automática do plano gratuito do Supabase é uma condição do serviço. Para produção sem essa pausa por inatividade, é necessário um plano apropriado, como Pro. Latência e disponibilidade de produção ainda devem ser medidas depois do deploy. Meses com conteúdo muito denso usam fontes menores para manter a exportação em uma folha.

Instruções de publicação e variáveis estão em [DEPLOY_VERCEL.md](DEPLOY_VERCEL.md).
