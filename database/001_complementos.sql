-- Complementos necessários às funcionalidades da interface.
-- Aplicado dentro de transação por bin/migrate.php, uma única vez.
CREATE TABLE feriado (
    data DATE PRIMARY KEY,
    descricao VARCHAR(150) NOT NULL DEFAULT 'Feriado',
    id_usuario INTEGER NOT NULL REFERENCES usuario(id_usuario)
);
CREATE TABLE login_tentativa (
    chave CHAR(64) PRIMARY KEY,
    tentativas INTEGER NOT NULL DEFAULT 1,
    inicio TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- A agenda é diária (o esquema não possui horários).
-- Um médico possui no máximo um agendamento não cancelado por dia.
CREATE UNIQUE INDEX uk_agendamento_ativo_dia ON agendamento (id_medico, data) WHERE status <> 'CANCELADO';
CREATE UNIQUE INDEX uk_escala_data ON escala (data);
CREATE UNIQUE INDEX uk_usuario_email_normalizado ON usuario (LOWER(email));
CREATE INDEX ix_agendamento_data ON agendamento (data);
CREATE INDEX ix_agendamento_usuario ON agendamento (id_usuario);
CREATE INDEX ix_agendamento_abreviacao ON agendamento (id_abreviacao);
CREATE INDEX ix_escala_profissional_escala ON escala_profissional (id_escala, ordem);
INSERT INTO abreviacao (sigla, descricao) VALUES
('T','Tarde'), ('M','Manhã'), ('DT','Dia todo'), ('ESP','Espuma'),
('ATÉ 17H','Atendimento até as 17h'), ('CON','Consulta'), ('PROC','Proctologista'),
('PSI','Psicólogo'), ('PSIQ','Psiquiatra'), ('BIOP','Biópsia'), ('AP.','A partir de'),
('CANCEL','Cancelado') ON CONFLICT (sigla) DO NOTHING;
