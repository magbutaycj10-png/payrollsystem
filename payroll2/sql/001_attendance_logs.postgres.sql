-- =====================================================================
--  001_attendance_logs.postgres.sql   (Aiven for PostgreSQL)
--
--  Same schema as the MySQL migration, for the Postgres variant of the
--  stack. Set  driver = postgres  in the agent's config.ini to use it.
--
--  Apply with:
--      psql "postgres://USER:PASS@HOST:PORT/DBNAME?sslmode=require" \
--           -f 001_attendance_logs.postgres.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS attendance_logs (
    id           BIGSERIAL    PRIMARY KEY,
    employee_id  VARCHAR(32)  NOT NULL,
    punch_time   TIMESTAMP    NOT NULL,
    punch_state  SMALLINT     NOT NULL DEFAULT 0,
    verify_mode  SMALLINT     NOT NULL DEFAULT 0,
    device_id    VARCHAR(40)  NOT NULL DEFAULT '',
    source       VARCHAR(16)  NOT NULL DEFAULT 'serial',
    processed    BOOLEAN      NOT NULL DEFAULT FALSE,
    received_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    -- Makes every re-sync idempotent: ON CONFLICT DO NOTHING drops repeats.
    CONSTRAINT uq_punch UNIQUE (employee_id, punch_time)
);

CREATE INDEX IF NOT EXISTS idx_unprocessed ON attendance_logs (processed, punch_time);
CREATE INDEX IF NOT EXISTS idx_punch_time  ON attendance_logs (punch_time);

COMMENT ON COLUMN attendance_logs.punch_state IS
    '0 check-in, 1 check-out, 2 break-out, 3 break-in, 4 ot-in, 5 ot-out';


CREATE TABLE IF NOT EXISTS biometric_employee_map (
    device_user_id VARCHAR(32) PRIMARY KEY,
    emp_id         VARCHAR(20) NOT NULL,
    device_id      VARCHAR(40) NOT NULL DEFAULT '',
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_map_emp ON biometric_employee_map (emp_id);


CREATE TABLE IF NOT EXISTS biometric_agent_state (
    device_id       VARCHAR(40)  PRIMARY KEY,
    agent_host      VARCHAR(100) NOT NULL DEFAULT '',
    agent_version   VARCHAR(20)  NOT NULL DEFAULT '',
    last_punch_time TIMESTAMP,
    last_sync_at    TIMESTAMPTZ,
    punches_total   INTEGER      NOT NULL DEFAULT 0
);


CREATE TABLE IF NOT EXISTS biometric_api_keys (
    id           SERIAL       PRIMARY KEY,
    key_hash     CHAR(64)     NOT NULL UNIQUE,
    label        VARCHAR(100) NOT NULL DEFAULT '',
    device_id    VARCHAR(40)  NOT NULL DEFAULT '',
    active       BOOLEAN      NOT NULL DEFAULT TRUE,
    last_used_at TIMESTAMPTZ,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Issue a key (Postgres needs pgcrypto for sha256, or hash it app-side):
--   CREATE EXTENSION IF NOT EXISTS pgcrypto;
--   INSERT INTO biometric_api_keys (key_hash, label, device_id)
--   VALUES (encode(digest('the-key-you-generated','sha256'),'hex'),
--           'Front desk PC', 'EPH-A6-01');
