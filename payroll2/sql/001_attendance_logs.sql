-- =====================================================================
--  001_attendance_logs.sql   (MySQL 8 / Aiven for MySQL)
--
--  Raw biometric punches land here. This is a staging table, deliberately
--  separate from the payroll tables: it is append-only, the agent can
--  re-send the same rows forever without harm, and nothing here is
--  authoritative until it has been rolled up into biometric_daily.
--
--  Pipeline:
--      device -> attendance_logs -> biometric_daily -> attendance/payroll
--
--  Apply with:
--      mysql --host=$DB_HOST --port=$DB_PORT --user=$DB_USER --password=... \
--            --ssl-ca=ca.pem defaultdb < 001_attendance_logs.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS attendance_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- The number as the TERMINAL knows it (e.g. "00001"). Not necessarily
    -- the payroll emp_id - see biometric_employee_map below.
    employee_id  VARCHAR(32)  NOT NULL,

    punch_time   DATETIME     NOT NULL,

    -- ZKTeco punch states:
    --   0 check-in   1 check-out   2 break-out
    --   3 break-in   4 ot-in       5 ot-out
    punch_state  TINYINT      NOT NULL DEFAULT 0,

    -- How the person identified: 0 password, 1 fingerprint, 2 card, ...
    verify_mode  TINYINT      NOT NULL DEFAULT 0,

    -- Which terminal, and which transport the agent used to get it.
    device_id    VARCHAR(40)  NOT NULL DEFAULT '',
    source       VARCHAR(16)  NOT NULL DEFAULT 'serial',

    -- Flipped to 1 once the row has been folded into biometric_daily.
    processed    TINYINT(1)   NOT NULL DEFAULT 0,

    received_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- THE duplicate guard. One person cannot punch twice in the same second,
    -- so this makes every re-sync idempotent: INSERT IGNORE silently drops
    -- anything already stored. Two PCs syncing the same terminal is safe.
    UNIQUE KEY uq_punch (employee_id, punch_time),

    -- Drives the rollup query (unprocessed rows in date order).
    KEY idx_unprocessed (processed, punch_time),

    -- Date-range scans for a payroll period.
    KEY idx_punch_time (punch_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Terminal user number  ->  payroll emp_id
--
--  The A6 stores users as 00001, 00002... while the payroll tables use
--  whatever emp_id the employee was created with. Without this mapping the
--  rollup falls back to stripping leading zeros, which is right often
--  enough to be dangerous. Populate this table for anyone it is wrong for.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS biometric_employee_map (
    device_user_id VARCHAR(32) NOT NULL,
    emp_id         VARCHAR(20) NOT NULL,
    device_id      VARCHAR(40) NOT NULL DEFAULT '',
    created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (device_user_id),
    KEY idx_emp (emp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Per-device agent heartbeat.
--
--  Answers "which PC is the terminal plugged into right now, and when did
--  it last talk to us?" - the question that actually matters when someone
--  moves the device and attendance quietly stops arriving.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS biometric_agent_state (
    device_id       VARCHAR(40)  NOT NULL,
    agent_host      VARCHAR(100) NOT NULL DEFAULT '',
    agent_version   VARCHAR(20)  NOT NULL DEFAULT '',
    last_punch_time DATETIME     NULL DEFAULT NULL,
    last_sync_at    TIMESTAMP    NULL DEFAULT NULL,
    punches_total   INT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  API keys, one per client PC, so a compromised machine can be cut off
--  without touching the others.
--
--  Store a SHA-256 hash, never the key itself. Issue a key with:
--
--      INSERT INTO biometric_api_keys (key_hash, label, device_id)
--      VALUES (SHA2('the-key-you-generated', 256), 'Front desk PC', 'EPH-A6-01');
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS biometric_api_keys (
    id           INT          NOT NULL AUTO_INCREMENT,
    key_hash     CHAR(64)     NOT NULL,
    label        VARCHAR(100) NOT NULL DEFAULT '',
    device_id    VARCHAR(40)  NOT NULL DEFAULT '',
    active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_used_at TIMESTAMP    NULL DEFAULT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_key_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Restricted account for any PC that writes STRAIGHT to Aiven
--  (sync.mode = db). Run as the admin database user, once.
--
--  Never put the admin database credentials in config.ini: that file lives on a
--  desktop PC and a PyInstaller bundle is trivially unpacked.
-- ---------------------------------------------------------------------
-- CREATE USER 'biometric_agent'@'%' IDENTIFIED BY 'a-long-random-password';
-- GRANT INSERT                  ON defaultdb.attendance_logs      TO 'biometric_agent'@'%';
-- GRANT INSERT, UPDATE, SELECT  ON defaultdb.biometric_agent_state TO 'biometric_agent'@'%';
-- FLUSH PRIVILEGES;
