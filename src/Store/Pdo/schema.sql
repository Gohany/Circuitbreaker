-- Schema for PDO-based stores
-- This schema is compatible with MySQL, PostgreSQL, and SQLite.

-- State store table
CREATE TABLE IF NOT EXISTS circuit_states (
    circuit_key VARCHAR(255) PRIMARY KEY,
    mode VARCHAR(32) NOT NULL,
    open_until_ms BIGINT,
    half_open_in_flight INT NOT NULL DEFAULT 0,
    meta_json TEXT,
    version INT NOT NULL DEFAULT 1
);

-- History store table
CREATE TABLE IF NOT EXISTS circuit_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT, -- Note: Change to SERIAL or BIGSERIAL for PostgreSQL
    circuit_key VARCHAR(255) NOT NULL,
    outcome VARCHAR(64) NOT NULL,
    recorded_at_ms BIGINT NOT NULL,
    details_json TEXT
);
CREATE INDEX IF NOT EXISTS idx_circuit_history_key ON circuit_history(circuit_key, recorded_at_ms);

-- Probe gate table
CREATE TABLE IF NOT EXISTS circuit_probe_gates (
    circuit_key VARCHAR(255) PRIMARY KEY,
    expires_at_ms BIGINT NOT NULL
);
