-- Dispensas salvas para acompanhar o envio de proposta.
-- As colunas soltas servem para consulta direta; "aviso" guarda a ficha completa como veio do radar.
CREATE TABLE IF NOT EXISTS candidaturas (
    id            BIGSERIAL PRIMARY KEY,
    pncp_id       TEXT NOT NULL UNIQUE,
    objeto        TEXT NOT NULL,
    orgao         TEXT NOT NULL DEFAULT '',
    municipio     TEXT NOT NULL DEFAULT '',
    uf            VARCHAR(2) NOT NULL DEFAULT '',
    valor         NUMERIC(15, 2),
    encerra_em    TIMESTAMP,
    status        TEXT NOT NULL DEFAULT 'salva'
                  CHECK (status IN ('salva', 'enviada', 'ganhei', 'perdi', 'descartada')),
    notas         TEXT NOT NULL DEFAULT '',
    aviso         JSONB NOT NULL,
    criado_em     TIMESTAMPTZ NOT NULL DEFAULT now(),
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS candidaturas_status_idx ON candidaturas (status);
CREATE INDEX IF NOT EXISTS candidaturas_encerra_idx ON candidaturas (encerra_em);
