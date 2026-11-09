-- Sessões compartilhadas entre instâncias serverless. Sem dados no disco efêmero.
CREATE TABLE app_session (
    id CHAR(64) PRIMARY KEY,
    payload TEXT NOT NULL DEFAULT '',
    expires_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX ix_app_session_expiry ON app_session (expires_at);
-- O dono da tabela (conexão PDO) acessa; a API pública do Supabase não recebe políticas.
ALTER TABLE app_session ENABLE ROW LEVEL SECURITY;
