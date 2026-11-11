-- =====================================================================
--  Clínica Vidas · Banco de dados PostgreSQL (10 ou superior)
--
--  1) Crie o banco (uma vez):   createdb -U postgres clinica_vidas
--  2) Importe este arquivo:     psql -U postgres -d clinica_vidas -f database/schema.sql
-- =====================================================================
SET client_encoding = 'UTF8';

-- ---------------------------------------------------------------------
-- USUARIO  (papel: adm = Administrador | editor = Responsável | viewer = Visualizador)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuario (
  id_usuario  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nome        VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  senha_hash  VARCHAR(255) NOT NULL,
  papel       VARCHAR(10)  NOT NULL DEFAULT 'viewer',
  ativo       BOOLEAN      NOT NULL DEFAULT TRUE,
  CONSTRAINT uk_usuario_email UNIQUE (email),
  CONSTRAINT ck_usuario_papel CHECK (papel IN ('adm','editor','viewer'))
);

-- ---------------------------------------------------------------------
-- MEDICO  (tipo: fixo = dias fixos da semana | variado = dias avulsos)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS medico (
  id_medico  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nome       VARCHAR(120) NOT NULL,
  tipo       VARCHAR(10)  NOT NULL DEFAULT 'fixo',
  ativo      BOOLEAN      NOT NULL DEFAULT TRUE,
  CONSTRAINT ck_medico_tipo CHECK (tipo IN ('fixo','variado'))
);
CREATE INDEX IF NOT EXISTS ix_medico_nome ON medico (nome);

-- ---------------------------------------------------------------------
-- MEDICO_DIA_SEMANA  (dia_semana: 0 = domingo ... 6 = sábado)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS medico_dia_semana (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  id_medico   INTEGER  NOT NULL REFERENCES medico (id_medico) ON DELETE CASCADE,
  dia_semana  SMALLINT NOT NULL,
  CONSTRAINT uk_medico_dia UNIQUE (id_medico, dia_semana),
  CONSTRAINT ck_mds_dia CHECK (dia_semana BETWEEN 0 AND 6)
);

-- ---------------------------------------------------------------------
-- ABREVIACAO  (T, M, DT, ESP ...)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS abreviacao (
  id_abreviacao  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  sigla          VARCHAR(20)  NOT NULL,
  descricao      VARCHAR(150) NOT NULL,
  CONSTRAINT uk_abreviacao_sigla UNIQUE (sigla)
);

-- ---------------------------------------------------------------------
-- AGENDAMENTO  (um médico em um dia do calendário)
--   * id_medico é NULL somente quando status = 'feriado'
--   * id_abreviacao é opcional (opção "Nenhuma" do calendário)
--   * status: confirmado | cancelado | feriado
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agendamento (
  id_agendamento  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  id_medico       INTEGER REFERENCES medico (id_medico) ON DELETE RESTRICT,
  id_usuario      INTEGER NOT NULL REFERENCES usuario (id_usuario) ON DELETE RESTRICT,
  id_abreviacao   INTEGER REFERENCES abreviacao (id_abreviacao) ON DELETE RESTRICT,
  data            DATE         NOT NULL,
  observacao      VARCHAR(255),
  status          VARCHAR(20)  NOT NULL DEFAULT 'confirmado',
  CONSTRAINT ck_agend_status CHECK (status IN ('confirmado','cancelado','feriado'))
);
CREATE INDEX IF NOT EXISTS ix_agend_data        ON agendamento (data);
CREATE INDEX IF NOT EXISTS ix_agend_medico_data ON agendamento (id_medico, data);

-- ---------------------------------------------------------------------
-- ESCALA  (escala do cardiologista: um registro por domingo)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS escala (
  id_escala   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  data        DATE    NOT NULL,
  id_usuario  INTEGER NOT NULL REFERENCES usuario (id_usuario) ON DELETE RESTRICT,
  observacao  VARCHAR(255),
  CONSTRAINT uk_escala_data UNIQUE (data)
);

-- ---------------------------------------------------------------------
-- ESCALA_PROFISSIONAL  (Pessoa 1 / Pessoa 2 de cada domingo)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS escala_profissional (
  id                 INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  id_escala          INTEGER  NOT NULL REFERENCES escala (id_escala) ON DELETE CASCADE,
  nome_profissional  VARCHAR(120) NOT NULL,
  ordem              SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT uk_escala_ordem UNIQUE (id_escala, ordem)
);

-- =====================================================================
--  DADOS INICIAIS
-- =====================================================================

-- Legenda do calendário
INSERT INTO abreviacao (sigla, descricao) VALUES
  ('T',       'TARDE'),
  ('M',       'MANHÃ'),
  ('DT',      'DIA TODO'),
  ('ESP',     'ESPUMA'),
  ('ATÉ 17H', 'ATÉ HORÁRIO QUE PODE SER AGENDADO'),
  ('CON',     'CONSULTA'),
  ('PROC',    'PROCTOLOGISTA'),
  ('PSI',     'PSICÓLOGO'),
  ('BIOP',    'BIÓPSIA'),
  ('AP.',     'A PARTIR'),
  ('CANCEL',  'CANCELADO')
ON CONFLICT (sigla) DO NOTHING;

-- O administrador é criado na PRIMEIRA abertura do sistema (tela "Primeiro acesso"),
-- ou pela linha de comando:  php database/criar_admin.php "Nome" email@clinica.com "SenhaForte123"
