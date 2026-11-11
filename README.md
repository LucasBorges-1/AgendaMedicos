# Clínica Vidas · Calendário dos médicos (PHP puro + PostgreSQL)

Sem frameworks e sem Composer. Requisitos: **PHP 7.4+** (com `pdo_pgsql` e `mbstring`) e **PostgreSQL 10+**.

## Instalação

1. **Banco de dados**
   ```bash
   createdb -U postgres clinica_vidas
   psql -U postgres -d clinica_vidas -f database/schema.sql
   ```
   Cria as 7 tabelas do diagrama e a legenda de abreviações (no pgAdmin: crie o banco `clinica_vidas` e rode o conteúdo de `schema.sql` na Query Tool).

2. **Configuração** – edite `api/config.php` (host, porta 5432, banco, usuário e senha; ou variáveis de ambiente `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`). No `php.ini`, ative `extension=pdo_pgsql`.

3. **Publicar** – copie a pasta para o servidor (Apache/Nginx com PHP) **ou** teste localmente:
   ```bash
   php -S localhost:8000
   ```
   e abra http://127.0.0.1:8000 (ou use `iniciar-servidor.bat` no Windows / `iniciar-servidor.sh` no Linux/Mac).
   O servidor embutido do PHP atende **uma requisição por vez** (no Windows não há como mudar isso); se a página travar, prefira XAMPP/Apache ou use `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000` (Linux/Mac).
   Para checar PHP, extensões e banco, abra http://127.0.0.1:8000/diagnostico.php (só responde localmente; apague em produção).

4. **Primeiro acesso:** ao abrir o sistema pela primeira vez aparece a tela **Primeiro acesso**, onde você cria o administrador (nome, e-mail e senha). Ela só aparece enquanto não houver nenhum administrador ativo.
   Alternativa/recuperação pela linha de comando:
   `php database/criar_admin.php "Seu Nome" voce@clinica.com "SenhaForte123"`

> Em produção use HTTPS. Em Nginx, bloqueie o acesso web às pastas `database/` e `api/rotas/` (o `.htaccess` só vale para Apache).

## Papéis

| Papel (`usuario.papel`) | Pode |
|---|---|
| `adm` Administrador | tudo, inclusive usuários e papéis |
| `editor` Responsável | cadastrar médicos, editar dias, feriados e escala |
| `viewer` Visualizador | ver calendário/escala e imprimir/baixar PDF |

## API (`api/index.php?r=<rota>`)

| Rota | Verbos | Descrição |
|---|---|---|
| `me`, `login`, `logout`, `senha` | GET / POST | sessão e troca de senha |
| `calendario&ano=2026&mes=11` | GET | médicos por dia, feriados e escala do mês |
| `medicos[&id=]` | GET POST PUT DELETE | cadastro de médicos e dias fixos |
| `agendamentos[&id=]` | POST DELETE | adiciona/remove médico em um dia |
| `feriados` | POST | marca/desmarca feriado `{data, feriado}` |
| `fixos` | POST | aplica dias fixos ao ano `{ano}` |
| `escala&ano=&mes=` | GET PUT | escala dos domingos (2 pessoas) |
| `abreviacoes` | GET | legenda (T, M, DT…) |
| `usuarios[&id=]` | GET POST PUT DELETE | gestão de usuários (`&acao=redefinir` gera nova senha) |

Segurança: PDO com prepared statements, `password_hash` (bcrypt), sessão com cookie HttpOnly/SameSite, token CSRF em toda requisição de escrita, bloqueio temporário após 5 logins errados e permissões verificadas no servidor.

## Observações sobre o modelo

- `agendamento.id_medico` aceita `NULL` apenas para o registro de **feriado** (`status = 'feriado'`), já que o diagrama não tinha tabela de feriados.
- `agendamento.id_abreviacao` é opcional (opção "Nenhuma"); o campo `observacao` guarda o detalhe livre (ex.: `ATÉ 15 H`).
- `dia_semana`: 0 = domingo … 6 = sábado.
- Médicos e usuários "excluídos" são desativados (`ativo = 0`) quando há histórico, para não quebrar as chaves estrangeiras.

## Desempenho

- **Conexão persistente** com o banco (a conexão é reaproveitada entre requisições) e consultas reduzidas ao mínimo.
- **Uma única requisição por mês**: `api/index.php?r=painel&ano=&mes=` devolve legenda, médicos, agenda, feriados e escala numa só consulta SQL.
- O usuário logado fica em cache na sessão por 60 s (mudanças de papel/remoção valem em até 1 minuto).
- Dias fixos de um médico são gerados por **uma instrução SQL** (antes eram centenas de INSERTs).
- A tela atualiza localmente após adicionar/remover médico num dia, marcar feriado e salvar escala, e guarda os meses já visitados em memória.
- Com o banco remoto (Supabase), a latência de rede domina: prefira a região mais próxima (ex.: `sa-east-1`, São Paulo).
