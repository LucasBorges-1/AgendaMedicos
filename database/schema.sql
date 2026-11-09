-- Esquema original fornecido para a Clínica Vidas. PostgreSQL 12+.
CREATE TABLE medico (
    id_medico INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    tipo VARCHAR(100),
    ativo BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE TABLE abreviacao (
    id_abreviacao INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sigla VARCHAR(20) NOT NULL UNIQUE,
    descricao VARCHAR(255) NOT NULL
);
CREATE TABLE usuario (
    id_usuario INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    papel VARCHAR(50) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE TABLE medico_dia_semana (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_medico INTEGER NOT NULL,
    dia_semana SMALLINT NOT NULL,
    CONSTRAINT fk_medico_dia FOREIGN KEY (id_medico) REFERENCES medico(id_medico),
    CONSTRAINT ck_dia_semana CHECK (dia_semana BETWEEN 0 AND 6),
    CONSTRAINT uk_medico_dia UNIQUE (id_medico, dia_semana)
);
CREATE TABLE agendamento (
    id_agendamento INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_medico INTEGER NOT NULL,
    id_usuario INTEGER NOT NULL,
    id_abreviacao INTEGER,
    data DATE NOT NULL,
    observacao TEXT,
    status VARCHAR(30) NOT NULL DEFAULT 'AGENDADO',
    CONSTRAINT fk_agendamento_medico FOREIGN KEY (id_medico) REFERENCES medico(id_medico),
    CONSTRAINT fk_agendamento_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario),
    CONSTRAINT fk_agendamento_abreviacao FOREIGN KEY (id_abreviacao) REFERENCES abreviacao(id_abreviacao),
    CONSTRAINT ck_agendamento_status CHECK (status IN ('AGENDADO','CONFIRMADO','CANCELADO','CONCLUIDO'))
);
CREATE TABLE escala (
    id_escala INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    data DATE NOT NULL,
    id_usuario INTEGER NOT NULL,
    observacao TEXT,
    CONSTRAINT fk_escala_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
);
CREATE TABLE escala_profissional (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_escala INTEGER NOT NULL,
    nome_profissional VARCHAR(150) NOT NULL,
    ordem SMALLINT NOT NULL DEFAULT 1,
    CONSTRAINT fk_escala_profissional FOREIGN KEY (id_escala) REFERENCES escala(id_escala)
);
