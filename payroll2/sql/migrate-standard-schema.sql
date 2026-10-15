-- =====================================================================================================================
--  migrate-standard-schema.sql - converts the payroll database to the standard layout of database.sql, keeping every record
--
--  What changes (nothing is lost - every old record is copied, with the same id, before the old tables are removed):
--    employees         employee_code (was emp_id), first_name + last_name (full_name is worked out from them), the profile
--                      table is merged in (address, phone, emergency contact), branch and position are links (branch_id,
--                      position_id) to the branches and positions lists
--    users             roles are Admin / Manager / Employee, what a manager handles moved to user_scopes
--    attendance        is now attendance_summary (with the absent, leave, undertime and day-off counts from the pay line)
--    payroll           basic_pay, overtime_pay, bonus_pay, *_deduction, total_deductions, updated_at - and no copy of the name or hours
--    biometric_daily   one row per employee per DAY (a day saved in two overlapping pay periods is kept once)
--    bonus_deduction_history -> bonus_deductions, print_log -> print_logs, payroll_periods.start_date / end_date
--    who did it        (processed, reviewed, approved, printed, entered, signed) is a link to the account, not a typed name
--    new lists         departments, positions
--
--  How to run it: open the file in DBeaver, pick the payroll database, keep the connection in Auto-commit mode (the "Auto" button
--  in the toolbar), press Alt+X (Execute script). If DBeaver asks what to do about an error, choose Stop. Read the result tabs: the
--  first says the older layout was found, the "check_verdict" one must say PASSED, the last says Done. Everything is guarded: it
--  only runs while the database is in the older layout, so running it a second time changes nothing. The old tables are kept
--  (as old_*) until PART C has shown that every record and every peso arrived, and only then deleted in PART D.
--
--  The database must already be in the layout made by migrate-employee-id.sql (employee_id in every table).
-- =====================================================================================================================
-- ---------------------------------------------------------------------------------------------------------------------
--  PART A - detect the older layout, set the old tables aside and build the new ones
-- ---------------------------------------------------------------------------------------------------------------------

SET @ok = (SELECT COUNT(*) = 6 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('employees', 'attendance', 'payroll', 'bonus_deduction_history', 'print_log', 'employee_profiles'))
      AND (SELECT COUNT(*) = 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'emp_id')
      AND (SELECT COUNT(*) = 0 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('old_employees', 'departments', 'positions', 'user_scopes', 'attendance_summary', 'bonus_deductions', 'print_logs'));

SELECT IF(@ok = 1, 'The older layout was found - converting it now. Nothing is deleted until PART D.', 'STOP - this database is not in the older layout, or it was already converted. Nothing was changed.') AS migration_status;

-- the terminal-number table points at employees: let go of it now, it is tied to the new employees table in PART B
SET @s = IF(@ok = 1, 'ALTER TABLE biometric_employee_map DROP FOREIGN KEY fk_biomap_employee', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- the tables that are rebuilt keep their rows under an old_ name until PART D (their foreign keys follow the rename)
SET @s = IF(@ok = 1, 'RENAME TABLE employees TO old_employees, users TO old_users, biometric_daily TO old_biometric_daily, leave_requests TO old_leave_requests, payroll TO old_payroll, payslip_signatures TO old_payslip_signatures, document_serials TO old_document_serials', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- tables kept in place, brought to the new names
SET @s = IF(@ok = 1, 'ALTER TABLE branches ADD COLUMN address TEXT NULL AFTER name, RENAME INDEX uq_branch_name TO uq_branches_name', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'UPDATE payroll_periods SET period_type = CASE
         WHEN DAY(period_start) = 1 AND period_end = LAST_DAY(period_start) THEN ''Monthly''
         WHEN DATEDIFF(period_end, period_start) <= 7 THEN ''Weekly''
         ELSE ''Semi-Monthly'' END
 WHERE period_type IS NULL OR period_type NOT IN (''Monthly'', ''Semi-Monthly'', ''Weekly'')', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'ALTER TABLE payroll_periods CHANGE COLUMN period_start start_date DATE NOT NULL, CHANGE COLUMN period_end end_date DATE NOT NULL, MODIFY COLUMN period_type ENUM(''Semi-Monthly'',''Monthly'',''Weekly'') NOT NULL DEFAULT ''Monthly'', ADD COLUMN created_by INT NULL, ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD KEY idx_payroll_periods_dates (start_date, end_date), ADD KEY idx_payroll_periods_created_by (created_by)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'ALTER TABLE attendance_logs RENAME COLUMN employee_id TO device_user_id, RENAME INDEX uq_punch TO uq_attendance_logs_punch, RENAME INDEX idx_unprocessed TO idx_attendance_logs_unprocessed, RENAME INDEX idx_punch_time TO idx_attendance_logs_punch_time', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'ALTER TABLE login_attempts RENAME INDEX idx_email TO idx_login_attempts_email, RENAME INDEX idx_ip TO idx_login_attempts_ip', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'ALTER TABLE biometric_api_keys RENAME INDEX uq_key_hash TO uq_biometric_api_keys_hash', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'ALTER TABLE document_series CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;


-- every table of the new layout (the ones that already exist, and were kept, are skipped)

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value VARCHAR(500) NOT NULL DEFAULT '''',
  PRIMARY KEY (setting_key)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS branches (
  id         INT          NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  address    TEXT         NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branches_name (name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS departments (
  id   INT          NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_name (name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS positions (
  id            INT          NOT NULL AUTO_INCREMENT,
  department_id INT          NULL,
  title         VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_positions_title (title),
  KEY idx_positions_department (department_id),
  CONSTRAINT fk_positions_department_id FOREIGN KEY (department_id) REFERENCES departments (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS employees (
  id                 INT           NOT NULL AUTO_INCREMENT,
  employee_code      VARCHAR(30)   NOT NULL,
  first_name         VARCHAR(100)  NOT NULL,
  last_name          VARCHAR(100)  NOT NULL DEFAULT '''',
  full_name          VARCHAR(201)  GENERATED ALWAYS AS (TRIM(CONCAT_WS('' '', NULLIF(first_name, ''''), NULLIF(last_name, '''')))) VIRTUAL,
  email              VARCHAR(150)  NULL,
  phone              VARCHAR(30)   NULL,
  address            TEXT          NULL,
  emergency_name     VARCHAR(150)  NULL,
  emergency_phone    VARCHAR(30)   NULL,
  emergency_relation VARCHAR(80)   NULL,
  branch_id          INT           NULL,
  position_id        INT           NULL,
  date_hired         DATE          NULL,
  status             ENUM(''Active'',''Inactive'') NOT NULL DEFAULT ''Active'',
  salary_type        ENUM(''monthly'',''kinsenas'',''daily'') NOT NULL DEFAULT ''monthly'',
  base_salary        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  hours_per_day      DECIMAL(4,2)  NULL DEFAULT NULL,
  rest_days          VARCHAR(20)   NOT NULL DEFAULT ''7'',
  sss_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  philhealth_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pagibig_amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employees_code (employee_code),
  UNIQUE KEY uq_employees_email (email),
  KEY idx_employees_branch (branch_id),
  KEY idx_employees_position (position_id),
  CONSTRAINT fk_employees_branch_id   FOREIGN KEY (branch_id)   REFERENCES branches (id)   ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_employees_position_id FOREIGN KEY (position_id) REFERENCES positions (id)  ON UPDATE CASCADE ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS users (
  id            INT          NOT NULL AUTO_INCREMENT,
  employee_id   INT          NULL,
  full_name     VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM(''Admin'',''Manager'',''Employee'') NOT NULL DEFAULT ''Employee'',
  status        ENUM(''Active'',''Inactive'') NOT NULL DEFAULT ''Active'',
  last_login    TIMESTAMP    NULL DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_employee (employee_id),
  CONSTRAINT fk_users_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS user_scopes (
  id            INT NOT NULL AUTO_INCREMENT,
  user_id       INT NOT NULL,
  scope_type    ENUM(''Global'',''Branch'',''Department'',''Employee'') NOT NULL DEFAULT ''Branch'',
  branch_id     INT NULL,
  department_id INT NULL,
  employee_id   INT NULL,
  PRIMARY KEY (id),
  KEY idx_user_scopes_user (user_id),
  KEY idx_user_scopes_branch (branch_id),
  KEY idx_user_scopes_department (department_id),
  KEY idx_user_scopes_employee (employee_id),
  CONSTRAINT fk_user_scopes_user_id       FOREIGN KEY (user_id)       REFERENCES users (id)       ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_scopes_branch_id     FOREIGN KEY (branch_id)     REFERENCES branches (id)    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_scopes_department_id FOREIGN KEY (department_id) REFERENCES departments (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_scopes_employee_id   FOREIGN KEY (employee_id)   REFERENCES employees (id)   ON UPDATE CASCADE ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS payroll_periods (
  id             INT          NOT NULL AUTO_INCREMENT,
  period_label   VARCHAR(80)  NOT NULL,
  start_date     DATE         NOT NULL,
  end_date       DATE         NOT NULL,
  period_type    ENUM(''Semi-Monthly'',''Monthly'',''Weekly'') NOT NULL DEFAULT ''Monthly'',
  status         ENUM(''Open'',''Locked'') NOT NULL DEFAULT ''Open'',
  finalized_at   TIMESTAMP    NULL DEFAULT NULL,
  finalize_count INT          NOT NULL DEFAULT 0,
  reopen_count   INT          NOT NULL DEFAULT 0,
  reopened_at    TIMESTAMP    NULL DEFAULT NULL,
  created_by     INT          NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payroll_periods_dates (start_date, end_date),
  KEY idx_payroll_periods_created_by (created_by),
  CONSTRAINT fk_payroll_periods_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS biometric_daily (
  id              INT          NOT NULL AUTO_INCREMENT,
  employee_id     INT          NOT NULL,
  att_date        DATE         NOT NULL,
  hours_worked    DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  overtime_hours  DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  late_hours      DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  undertime_hours DECIMAL(6,2) NULL DEFAULT NULL,
  is_day_off      TINYINT(1)   NOT NULL DEFAULT 0,
  entered_by      INT          NULL,
  uploaded_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_biometric_daily_employee_date (employee_id, att_date),
  KEY idx_biometric_daily_date (att_date),
  KEY idx_biometric_daily_entered_by (entered_by),
  CONSTRAINT fk_biometric_daily_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_biometric_daily_entered_by  FOREIGN KEY (entered_by)  REFERENCES users (id)     ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS attendance_summary (
  id                    INT          NOT NULL AUTO_INCREMENT,
  period_id             INT          NOT NULL,
  employee_id           INT          NOT NULL,
  total_hours_worked    DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  total_overtime_hours  DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  total_late_hours      DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  total_undertime_hours DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  absent_days           DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  leave_days            DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  days_off              DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  is_manually_entered   TINYINT(1)   NOT NULL DEFAULT 0,
  approval_status       ENUM(''Pending'',''Approved'',''Flagged'') NOT NULL DEFAULT ''Pending'',
  approved_by           INT          NULL,
  approved_at           TIMESTAMP    NULL DEFAULT NULL,
  uploaded_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_summary_period_employee (period_id, employee_id),
  KEY idx_attendance_summary_employee (employee_id),
  KEY idx_attendance_summary_approved_by (approved_by),
  CONSTRAINT fk_attendance_summary_period_id   FOREIGN KEY (period_id)   REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_attendance_summary_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id)       ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_summary_approved_by FOREIGN KEY (approved_by) REFERENCES users (id)           ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS leave_requests (
  id          INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  leave_type  ENUM(''Vacation'',''Sick Leave'',''Emergency'',''Other'') NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  reason      TEXT NULL,
  status      ENUM(''Pending'',''Approved'',''Rejected'') NOT NULL DEFAULT ''Pending'',
  reviewed_by INT NULL,
  review_note VARCHAR(255) NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_leave_requests_employee (employee_id),
  KEY idx_leave_requests_reviewed_by (reviewed_by),
  CONSTRAINT fk_leave_requests_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_leave_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id)     ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS bonus_deductions (
  id             INT           NOT NULL AUTO_INCREMENT,
  employee_id    INT           NOT NULL,
  period_id      INT           NULL,
  type           ENUM(''Bonus'',''Deduction'') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reason         VARCHAR(255)  NOT NULL DEFAULT '''',
  processed_by   INT           NULL,
  entry_date     DATE          NOT NULL,
  finalize_cycle INT           NOT NULL DEFAULT 0,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bonus_deductions_employee (employee_id),
  KEY idx_bonus_deductions_period (period_id),
  KEY idx_bonus_deductions_processed_by (processed_by),
  CONSTRAINT fk_bonus_deductions_employee_id  FOREIGN KEY (employee_id)  REFERENCES employees (id)       ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_bonus_deductions_period_id    FOREIGN KEY (period_id)    REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_bonus_deductions_processed_by FOREIGN KEY (processed_by) REFERENCES users (id)           ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS payroll (
  id                        INT           NOT NULL AUTO_INCREMENT,
  period_id                 INT           NOT NULL,
  employee_id               INT           NOT NULL,
  basic_pay                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  overtime_pay              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bonus_pay                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gross_pay                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  sss_deduction             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  philhealth_deduction      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pagibig_deduction         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  withholding_tax           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tardy_undertime_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  absence_deduction         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  other_deductions          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_deductions          DECIMAL(12,2) GENERATED ALWAYS AS (sss_deduction + philhealth_deduction + pagibig_deduction + withholding_tax + other_deductions) STORED,
  net_pay                   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status                    ENUM(''Draft'',''Finalized'') NOT NULL DEFAULT ''Draft'',
  revised_after_finalize    TINYINT(1)    NOT NULL DEFAULT 0,
  revised_at                TIMESTAMP     NULL DEFAULT NULL,
  created_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_period_employee (period_id, employee_id),
  KEY idx_payroll_employee (employee_id),
  CONSTRAINT fk_payroll_period_id   FOREIGN KEY (period_id)   REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_payroll_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id)       ON UPDATE CASCADE ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS payslip_signatures (
  id             INT           NOT NULL AUTO_INCREMENT,
  payroll_id     INT           NOT NULL,
  signature_data LONGTEXT      NOT NULL,
  net_signed     DECIMAL(12,2) NULL DEFAULT NULL,
  signed_by_user INT           NULL,
  signed_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payslip_signatures_payroll (payroll_id),
  KEY idx_payslip_signatures_signed_by (signed_by_user),
  CONSTRAINT fk_payslip_signatures_payroll_id     FOREIGN KEY (payroll_id)     REFERENCES payroll (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_payslip_signatures_signed_by_user FOREIGN KEY (signed_by_user) REFERENCES users (id)   ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS print_logs (
  id            INT          NOT NULL AUTO_INCREMENT,
  document_name VARCHAR(200) NOT NULL,
  document_type VARCHAR(80)  NOT NULL DEFAULT ''Physical Print'',
  period_id     INT          NULL,
  printed_by    INT          NULL,
  printed_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_print_logs_period (period_id),
  KEY idx_print_logs_printed_by (printed_by),
  CONSTRAINT fk_print_logs_period_id  FOREIGN KEY (period_id)  REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_print_logs_printed_by FOREIGN KEY (printed_by) REFERENCES users (id)           ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS period_audit (
  id           INT          NOT NULL AUTO_INCREMENT,
  period_id    INT          NOT NULL,
  action       ENUM(''Finalized'',''Reopened'',''Revised'') NOT NULL,
  cycle        INT          NOT NULL DEFAULT 0,
  performed_by INT          NULL,
  note         VARCHAR(255) NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_period_audit_period (period_id),
  KEY idx_period_audit_performed_by (performed_by),
  CONSTRAINT fk_period_audit_period_id    FOREIGN KEY (period_id)    REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_period_audit_performed_by FOREIGN KEY (performed_by) REFERENCES users (id)           ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT          NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)  NOT NULL DEFAULT '''',
  email        VARCHAR(150) NOT NULL DEFAULT '''',
  ok           TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_email (email, attempted_at),
  KEY idx_login_attempts_ip (ip, attempted_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS document_series (
  series  VARCHAR(20) NOT NULL,
  next_no BIGINT      NOT NULL DEFAULT 1,
  PRIMARY KEY (series)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS document_serials (
  id        INT         NOT NULL AUTO_INCREMENT,
  series    VARCHAR(20) NOT NULL,
  ref_id    INT         NOT NULL,
  serial_no BIGINT      NOT NULL,
  issued_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_document_serials_ref (series, ref_id),
  UNIQUE KEY uq_document_serials_no (series, serial_no)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS attendance_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_user_id VARCHAR(32)  NOT NULL,
  punch_time     DATETIME     NOT NULL,
  punch_state    TINYINT      NOT NULL DEFAULT 0,
  verify_mode    TINYINT      NOT NULL DEFAULT 0,
  device_id      VARCHAR(40)  NOT NULL DEFAULT '''',
  source         VARCHAR(16)  NOT NULL DEFAULT ''serial'',
  processed      TINYINT(1)   NOT NULL DEFAULT 0,
  received_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_logs_punch (device_user_id, punch_time),
  KEY idx_attendance_logs_unprocessed (processed, punch_time),
  KEY idx_attendance_logs_punch_time (punch_time)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS biometric_employee_map (
  device_user_id VARCHAR(32) NOT NULL,
  employee_id    INT         NOT NULL,
  device_id      VARCHAR(40) NOT NULL DEFAULT '''',
  created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (device_user_id),
  KEY idx_biometric_employee_map_employee (employee_id),
  CONSTRAINT fk_biometric_employee_map_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS biometric_agent_state (
  device_id       VARCHAR(40)  NOT NULL,
  agent_host      VARCHAR(100) NOT NULL DEFAULT '''',
  agent_version   VARCHAR(20)  NOT NULL DEFAULT '''',
  last_punch_time DATETIME     NULL DEFAULT NULL,
  last_sync_at    TIMESTAMP    NULL DEFAULT NULL,
  punches_total   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'CREATE TABLE IF NOT EXISTS biometric_api_keys (
  id           INT          NOT NULL AUTO_INCREMENT,
  key_hash     CHAR(64)     NOT NULL,
  label        VARCHAR(100) NOT NULL DEFAULT '''',
  device_id    VARCHAR(40)  NOT NULL DEFAULT '''',
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_used_at TIMESTAMP    NULL DEFAULT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_biometric_api_keys_hash (key_hash)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------------------------------------------------
--  PART B - copy every record into the new tables (each keeps its id, so everything that points at it still does)
-- ---------------------------------------------------------------------------------------------------------------------

-- positions: the typed position names become a list
SET @s = IF(@ok = 1, 'INSERT INTO positions (title) SELECT MIN(TRIM(position)) FROM old_employees WHERE position IS NOT NULL AND TRIM(position) <> '''' GROUP BY TRIM(position)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- branches: any branch name in use that is not in the list yet is added
SET @s = IF(@ok = 1, 'INSERT IGNORE INTO branches (name) SELECT MIN(TRIM(branch)) FROM old_employees WHERE branch IS NOT NULL AND TRIM(branch) <> '''' GROUP BY TRIM(branch)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'INSERT IGNORE INTO branches (name) SELECT MIN(TRIM(branch)) FROM old_users WHERE role = ''manager'' AND branch IS NOT NULL AND TRIM(branch) <> '''' GROUP BY TRIM(branch)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- names: "FIRST M. LAST" is split into first_name and last_name. The last word is the surname, together with a particle before it
-- (DE ASIS, DELA CRUZ, DE LA CRUZ, SAN JOSE ...) and a suffix after it (JR., SR., III). "LAST, First" is read as written.
SET @s = IF(@ok = 1, 'CREATE TABLE std_name_split (id INT NOT NULL, t VARCHAR(400) NOT NULL, n INT NOT NULL DEFAULT 1, s INT NOT NULL DEFAULT 1, has_comma TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (id)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'INSERT INTO std_name_split (id, t, n, has_comma) SELECT x.id, x.t, 1 + CHAR_LENGTH(x.t) - CHAR_LENGTH(REPLACE(x.t, '' '', '''')), IF(INSTR(x.t, '','') > 0, 1, 0) FROM (SELECT id, TRIM(REGEXP_REPLACE(TRIM(full_name), ''[[:space:]]+'', '' '')) AS t FROM old_employees) x', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'UPDATE std_name_split SET s = 2 WHERE has_comma = 0 AND n >= 3 AND UPPER(SUBSTRING_INDEX(t, '' '', -1)) IN (''JR'', ''JR.'', ''SR'', ''SR.'', ''II'', ''III'', ''IV'')', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'UPDATE std_name_split SET s = s + 1 WHERE has_comma = 0 AND n >= s + 2 AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(t, '' '', -(s + 1)), '' '', 1)) IN (''de'', ''del'', ''dela'', ''delos'', ''delas'', ''di'', ''da'', ''san'', ''sta'', ''sta.'', ''sto'', ''sto.'', ''santa'', ''van'', ''von'', ''la'', ''los'', ''las'')', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'UPDATE std_name_split SET s = s + 1 WHERE has_comma = 0 AND n >= s + 2 AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(t, '' '', -(s + 1)), '' '', 1)) IN (''de'', ''del'', ''dela'', ''delos'', ''delas'', ''di'', ''da'', ''san'', ''sta'', ''sta.'', ''sto'', ''sto.'', ''santa'', ''van'', ''von'', ''la'', ''los'', ''las'')', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- employees (the profile table is merged in; a blank e-mail, phone or address becomes empty, and an e-mail typed twice stays on the first employee only)
SET @s = IF(@ok = 1, 'INSERT INTO employees (id, employee_code, first_name, last_name, email, phone, address, emergency_name, emergency_phone, emergency_relation, branch_id, position_id, date_hired, status, salary_type, base_salary, hours_per_day, rest_days, sss_amount, philhealth_amount, pagibig_amount, tax_amount, created_at, updated_at)
SELECT e.id, e.emp_id,
       CASE WHEN ns.has_comma = 1 THEN TRIM(SUBSTRING(ns.t, INSTR(ns.t, '','') + 1))
            WHEN ns.n = 1 THEN ns.t
            ELSE TRIM(SUBSTRING(ns.t, 1, CHAR_LENGTH(ns.t) - CHAR_LENGTH(SUBSTRING_INDEX(ns.t, '' '', -ns.s)))) END,
       CASE WHEN ns.has_comma = 1 THEN TRIM(SUBSTRING_INDEX(ns.t, '','', 1))
            WHEN ns.n = 1 THEN ''''
            ELSE SUBSTRING_INDEX(ns.t, '' '', -ns.s) END,
       CASE WHEN NULLIF(TRIM(e.email), '''') IS NULL THEN NULL
            WHEN e.id = (SELECT MIN(e2.id) FROM old_employees e2 WHERE LOWER(TRIM(e2.email)) = LOWER(TRIM(e.email))) THEN TRIM(e.email)
            ELSE NULL END,
       NULLIF(TRIM(p.phone), ''''), NULLIF(TRIM(p.address), ''''), NULLIF(TRIM(p.emergency_name), ''''), NULLIF(TRIM(p.emergency_phone), ''''), NULLIF(TRIM(p.emergency_relation), ''''),
       (SELECT b.id FROM branches b WHERE b.name = TRIM(e.branch) LIMIT 1),
       (SELECT ps.id FROM positions ps WHERE ps.title = TRIM(e.position) LIMIT 1),
       e.date_hired, e.status, e.salary_type, e.base_salary, e.hours_per_day, e.rest_days, e.sss_amount, e.philhealth_amount, e.pagibig_amount, e.tax_amount,
       LEAST(COALESCE(p.updated_at, NOW()),
             COALESCE((SELECT MIN(u.created_at) FROM old_users u WHERE u.employee_id = e.id), NOW()),
             COALESCE((SELECT MIN(d.uploaded_at) FROM old_biometric_daily d WHERE d.employee_id = e.id), NOW())),
       COALESCE(p.updated_at, NOW())
  FROM old_employees e
  JOIN std_name_split ns ON ns.id = e.id
  LEFT JOIN employee_profiles p ON p.employee_id = e.id', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'DROP TABLE std_name_split', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- users: the three roles are written the standard way
SET @s = IF(@ok = 1, 'INSERT INTO users (id, employee_id, full_name, email, password_hash, role, status, last_login, created_at)
SELECT u.id, u.employee_id, u.full_name, u.email, u.password_hash,
       CASE u.role WHEN ''admin'' THEN ''Admin'' WHEN ''manager'' THEN ''Manager'' ELSE ''Employee'' END,
       u.status, u.last_login, u.created_at
  FROM old_users u', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- who a manager handles: no branch = everybody (Global), a branch, or the employees picked one by one
SET @s = IF(@ok = 1, 'INSERT INTO user_scopes (user_id, scope_type, branch_id, department_id, employee_id)
SELECT u.id, ''Global'', NULL, NULL, NULL FROM old_users u WHERE u.role = ''manager'' AND u.scope_type = ''branch'' AND (u.branch IS NULL OR TRIM(u.branch) = '''')
UNION ALL
SELECT u.id, ''Branch'', b.id, NULL, NULL FROM old_users u JOIN branches b ON b.name = TRIM(u.branch) WHERE u.role = ''manager'' AND u.scope_type = ''branch'' AND TRIM(u.branch) <> ''''
UNION ALL
SELECT me.manager_id, ''Employee'', NULL, NULL, me.employee_id FROM manager_employees me JOIN old_users u ON u.id = me.manager_id WHERE u.role = ''manager'' AND u.scope_type = ''custom''', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- pay periods (kept in place): who created them is not known for the old ones, when is the first sign of the period
SET @s = IF(@ok = 1, 'ALTER TABLE payroll_periods ADD CONSTRAINT fk_payroll_periods_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'UPDATE payroll_periods pp SET created_at = LEAST(NOW(), COALESCE((SELECT MIN(a.upload_date) FROM attendance a WHERE a.period_id = pp.id), NOW()), COALESCE((SELECT MIN(p.created_at) FROM old_payroll p WHERE p.period_id = pp.id), NOW()))', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- daily attendance: one row per employee per day. A day that was saved in two overlapping pay periods is kept once (the latest upload).
SET @s = IF(@ok = 1, 'INSERT INTO biometric_daily (id, employee_id, att_date, hours_worked, overtime_hours, late_hours, undertime_hours, is_day_off, entered_by, uploaded_at)
SELECT b.id, b.employee_id, b.att_date, b.hours_worked, b.overtime_hours, b.late_hours, b.undertime_hours, b.day_off,
       (SELECT u.id FROM users u WHERE u.full_name = b.entered_by ORDER BY FIELD(u.role, ''Manager'', ''Admin'', ''Employee''), u.id LIMIT 1),
       b.uploaded_at
  FROM old_biometric_daily b
 WHERE b.id = (SELECT x.id FROM old_biometric_daily x WHERE x.employee_id = b.employee_id AND x.att_date = b.att_date ORDER BY x.uploaded_at DESC, x.id DESC LIMIT 1)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- attendance: the hours of the old attendance table and the undertime, absent, leave and day-off counts of the old payroll table, in one row
SET @s = IF(@ok = 1, 'INSERT INTO attendance_summary (id, period_id, employee_id, total_hours_worked, total_overtime_hours, total_late_hours, total_undertime_hours, absent_days, leave_days, days_off, is_manually_entered, approval_status, approved_by, approved_at, uploaded_at)
SELECT a.id, a.period_id, a.employee_id, a.hours_worked, a.overtime_hours, a.late_hours, COALESCE(p.undertime_hours, 0), COALESCE(p.absent_days, 0), COALESCE(p.leave_days, 0), COALESCE(p.days_off, 0),
       IF(a.manually_entered_by IS NULL OR a.manually_entered_by = '''', 0, 1),
       CASE a.manager_approved WHEN 1 THEN ''Approved'' WHEN 2 THEN ''Flagged'' ELSE ''Pending'' END,
       (SELECT u.id FROM users u WHERE u.full_name = a.approved_by ORDER BY FIELD(u.role, ''Manager'', ''Admin'', ''Employee''), u.id LIMIT 1),
       a.approved_at, a.upload_date
  FROM attendance a
  LEFT JOIN old_payroll p ON p.period_id = a.period_id AND p.employee_id = a.employee_id', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- a pay line that has no attendance row (not expected) still keeps its hours
SET @s = IF(@ok = 1, 'INSERT INTO attendance_summary (period_id, employee_id, total_hours_worked, total_overtime_hours, total_late_hours, total_undertime_hours, absent_days, leave_days, days_off, is_manually_entered, approval_status, uploaded_at)
SELECT p.period_id, p.employee_id, p.hours_worked, p.overtime_hours, p.late_hours, p.undertime_hours, p.absent_days, p.leave_days, p.days_off, 0, ''Pending'', p.created_at
  FROM old_payroll p
 WHERE NOT EXISTS (SELECT 1 FROM attendance a WHERE a.period_id = p.period_id AND a.employee_id = p.employee_id)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- leave requests
SET @s = IF(@ok = 1, 'INSERT INTO leave_requests (id, employee_id, leave_type, date_from, date_to, reason, status, reviewed_by, review_note, reviewed_at, created_at)
SELECT l.id, l.employee_id, l.leave_type, l.date_from, l.date_to, l.reason, l.status,
       (SELECT u.id FROM users u WHERE u.full_name = l.reviewed_by ORDER BY FIELD(u.role, ''Manager'', ''Admin'', ''Employee''), u.id LIMIT 1),
       l.review_note, l.reviewed_at, l.created_at
  FROM old_leave_requests l', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- bonuses and deductions
SET @s = IF(@ok = 1, 'INSERT INTO bonus_deductions (id, employee_id, period_id, type, amount, reason, processed_by, entry_date, finalize_cycle, created_at)
SELECT h.id, h.employee_id, h.period_id, h.entry_type, h.amount, h.reason,
       (SELECT u.id FROM users u WHERE u.full_name = h.processed_by ORDER BY FIELD(u.role, ''Admin'', ''Manager'', ''Employee''), u.id LIMIT 1),
       h.entry_date, h.finalize_cycle, h.created_at
  FROM bonus_deduction_history h', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- payroll: basic pay and overtime pay are stored, not worked out (gross - the old overtime / late adjustment), and the late and undertime cost is one column
SET @s = IF(@ok = 1, 'INSERT INTO payroll (id, period_id, employee_id, basic_pay, overtime_pay, bonus_pay, gross_pay, sss_deduction, philhealth_deduction, pagibig_deduction, withholding_tax, tardy_undertime_deduction, absence_deduction, other_deductions, net_pay, status, revised_after_finalize, revised_at, created_at, updated_at)
SELECT p.id, p.period_id, p.employee_id,
       CASE WHEN p.gross_incl_ot = 1 THEN p.gross_pay - p.ot_late_adj ELSE p.gross_pay END,
       GREATEST(p.ot_late_adj, 0),
       p.bonus,
       CASE WHEN p.gross_incl_ot = 1 THEN p.gross_pay ELSE p.gross_pay + p.ot_late_adj END,
       p.sss, p.philhealth, p.pagibig, p.withholding_tax,
       p.undertime_deduction + GREATEST(-p.ot_late_adj, 0),
       p.absent_deduction, p.other_deductions, p.net_pay, p.status, p.revised_after_finalize, p.revised_at, p.created_at, p.created_at
  FROM old_payroll p', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- payslip signatures: the employee, period and names are found through the pay line
SET @s = IF(@ok = 1, 'INSERT INTO payslip_signatures (id, payroll_id, signature_data, net_signed, signed_by_user, signed_at)
SELECT s.id, s.payroll_id, s.signature_data, s.net_signed, NULL, s.signed_at FROM old_payslip_signatures s', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- print log: the person is found by name (an employee's own printing is looked for among the employee accounts first)
SET @s = IF(@ok = 1, 'INSERT INTO print_logs (id, document_name, document_type, period_id, printed_by, printed_at)
SELECT l.id, l.document_name, l.document_type, l.period_id,
       (SELECT u.id FROM users u WHERE u.full_name = l.printed_by
         ORDER BY CASE WHEN l.document_type = ''Employee Self-Service'' THEN FIELD(u.role, ''Employee'', ''Manager'', ''Admin'') ELSE FIELD(u.role, ''Admin'', ''Manager'', ''Employee'') END, u.id LIMIT 1),
       l.log_datetime
  FROM print_log l', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- period trail: who did it becomes a link to the account
SET @s = IF(@ok = 1, 'ALTER TABLE period_audit ADD COLUMN performed_by_user INT NULL AFTER cycle', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'UPDATE period_audit pa SET pa.performed_by_user = (SELECT u.id FROM users u WHERE u.full_name = pa.performed_by ORDER BY FIELD(u.role, ''Admin'', ''Manager'', ''Employee''), u.id LIMIT 1)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'DELETE FROM period_audit WHERE period_id NOT IN (SELECT id FROM payroll_periods)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'ALTER TABLE period_audit DROP COLUMN performed_by', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'ALTER TABLE period_audit CHANGE COLUMN performed_by_user performed_by INT NULL, RENAME INDEX idx_period TO idx_period_audit_period, ADD KEY idx_period_audit_performed_by (performed_by), ADD CONSTRAINT fk_period_audit_period_id FOREIGN KEY (period_id) REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE CASCADE, ADD CONSTRAINT fk_period_audit_performed_by FOREIGN KEY (performed_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- receipt numbers
SET @s = IF(@ok = 1, 'INSERT INTO document_serials (series, ref_id, serial_no, issued_at) SELECT series, ref_id, serial_no, issued_at FROM old_document_serials ORDER BY issued_at, series, ref_id', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- terminal numbers -> employees, tied to the new employees table
SET @s = IF(@ok = 1, 'ALTER TABLE biometric_employee_map RENAME INDEX idx_emp TO idx_biometric_employee_map_employee, ADD CONSTRAINT fk_biometric_employee_map_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;



-- ---------------------------------------------------------------------------------------------------------------------
--  PART C - checks: every record and every peso must have arrived. PART D only runs when every check passes.
-- ---------------------------------------------------------------------------------------------------------------------

SET @s = IF(@ok = 1, 'CREATE TABLE std_checks (name VARCHAR(90) NOT NULL, expected DECIMAL(16,2) NOT NULL, found DECIMAL(16,2) NOT NULL, PRIMARY KEY (name)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'INSERT INTO std_checks (name, expected, found)
SELECT ''01 employees'', (SELECT COUNT(*) FROM old_employees), (SELECT COUNT(*) FROM employees)
UNION ALL SELECT ''02 user accounts'', (SELECT COUNT(*) FROM old_users), (SELECT COUNT(*) FROM users)
UNION ALL SELECT ''03 pay lines'', (SELECT COUNT(*) FROM old_payroll), (SELECT COUNT(*) FROM payroll)
UNION ALL SELECT ''04 attendance rows'', (SELECT COUNT(*) FROM attendance) + (SELECT COUNT(*) FROM old_payroll p WHERE NOT EXISTS (SELECT 1 FROM attendance a WHERE a.period_id = p.period_id AND a.employee_id = p.employee_id)), (SELECT COUNT(*) FROM attendance_summary)
UNION ALL SELECT ''05 bonus and deduction entries'', (SELECT COUNT(*) FROM bonus_deduction_history), (SELECT COUNT(*) FROM bonus_deductions)
UNION ALL SELECT ''06 print log entries'', (SELECT COUNT(*) FROM print_log), (SELECT COUNT(*) FROM print_logs)
UNION ALL SELECT ''07 leave requests'', (SELECT COUNT(*) FROM old_leave_requests), (SELECT COUNT(*) FROM leave_requests)
UNION ALL SELECT ''08 payslip signatures'', (SELECT COUNT(*) FROM old_payslip_signatures), (SELECT COUNT(*) FROM payslip_signatures)
UNION ALL SELECT ''09 receipt numbers'', (SELECT COUNT(*) FROM old_document_serials), (SELECT COUNT(*) FROM document_serials)
UNION ALL SELECT ''10 days (one per employee per date)'', (SELECT COUNT(*) FROM (SELECT 1 FROM old_biometric_daily GROUP BY employee_id, att_date) d), (SELECT COUNT(*) FROM biometric_daily)
UNION ALL SELECT ''11 net pay, total'', (SELECT COALESCE(SUM(net_pay), 0) FROM old_payroll), (SELECT COALESCE(SUM(net_pay), 0) FROM payroll)
UNION ALL SELECT ''12 gross pay, total'', (SELECT COALESCE(SUM(gross_pay + IF(gross_incl_ot = 1, 0, ot_late_adj)), 0) FROM old_payroll), (SELECT COALESCE(SUM(gross_pay), 0) FROM payroll)
UNION ALL SELECT ''13 SSS, PhilHealth, Pag-IBIG, tax and other deductions, total'', (SELECT COALESCE(SUM(sss + philhealth + pagibig + withholding_tax + other_deductions), 0) FROM old_payroll), (SELECT COALESCE(SUM(total_deductions), 0) FROM payroll)
UNION ALL SELECT ''14 bonus on pay lines, total'', (SELECT COALESCE(SUM(bonus), 0) FROM old_payroll), (SELECT COALESCE(SUM(bonus_pay), 0) FROM payroll)
UNION ALL SELECT ''15 bonus and deduction entries, total'', (SELECT COALESCE(SUM(amount), 0) FROM bonus_deduction_history), (SELECT COALESCE(SUM(amount), 0) FROM bonus_deductions)
UNION ALL SELECT ''16 hours worked, total'', (SELECT COALESCE(SUM(hours_worked), 0) FROM attendance), (SELECT COALESCE(SUM(total_hours_worked), 0) FROM attendance_summary WHERE id IN (SELECT id FROM attendance))
UNION ALL SELECT ''17 names that no longer read the same'', 0, (SELECT COUNT(*) FROM old_employees e JOIN employees n ON n.id = e.id WHERE n.full_name <> TRIM(REGEXP_REPLACE(TRIM(e.full_name), ''[[:space:]]+'', '' '')) AND INSTR(e.full_name, '','') = 0)
UNION ALL SELECT ''18 employees whose code, salary, type or status changed'', 0, (SELECT COUNT(*) FROM old_employees e JOIN employees n ON n.id = e.id WHERE n.employee_code <> e.emp_id OR n.base_salary <> e.base_salary OR n.salary_type <> e.salary_type OR n.status <> e.status)
UNION ALL SELECT ''19 employees who lost their branch'', 0, (SELECT COUNT(*) FROM old_employees e WHERE NULLIF(TRIM(e.branch), '''') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM employees n WHERE n.id = e.id AND n.branch_id IS NOT NULL))
UNION ALL SELECT ''20 employees who lost their position'', 0, (SELECT COUNT(*) FROM old_employees e WHERE NULLIF(TRIM(e.position), '''') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM employees n WHERE n.id = e.id AND n.position_id IS NOT NULL))
UNION ALL SELECT ''21 managers left without what they handle'', 0, (SELECT COUNT(*) FROM old_users u WHERE u.role = ''manager'' AND NOT EXISTS (SELECT 1 FROM user_scopes s WHERE s.user_id = u.id) AND (u.scope_type = ''branch'' OR EXISTS (SELECT 1 FROM manager_employees me WHERE me.manager_id = u.id)))
UNION ALL SELECT ''22 pay lines whose net pay is not gross + bonus - deductions'', (SELECT COUNT(*) FROM old_payroll p WHERE ABS(p.net_pay - (p.gross_pay + p.bonus - (p.sss + p.philhealth + p.pagibig + p.withholding_tax + p.other_deductions))) >= 0.005), (SELECT COUNT(*) FROM payroll p WHERE ABS(p.net_pay - (p.gross_pay + p.bonus_pay - p.total_deductions)) >= 0.005)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'SET @bad = (SELECT COUNT(*) FROM std_checks WHERE expected <> found)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SELECT IF(@ok = 1 AND @bad = 0, 'PASSED - every record and every peso arrived', IF(@ok = 1, 'FAILED - do not continue, see result below', 'skipped')) AS check_verdict, @bad AS checks_failed;

SET @s = IF(@ok = 1, 'SELECT name AS `check`, expected, found, IF(expected = found, ''ok'', ''FAILED'') AS result FROM std_checks ORDER BY name', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'DROP TABLE std_checks', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'SELECT e.emp_id AS employee_code, e.full_name AS name_before, n.first_name, n.last_name FROM old_employees e JOIN employees n ON n.id = e.id ORDER BY e.id', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF(@ok = 1, 'SELECT u.full_name AS manager, s.scope_type, b.name AS branch FROM users u JOIN user_scopes s ON s.user_id = u.id LEFT JOIN branches b ON b.id = s.branch_id ORDER BY u.id, s.id', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @ok = IF(@ok = 1 AND @bad = 0, 1, 0);

-- ---------------------------------------------------------------------------------------------------------------------
--  PART D - the old tables, now replaced, are deleted
-- ---------------------------------------------------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

SET @s = IF(@ok = 1, 'DROP TABLE old_payslip_signatures, attendance, bonus_deduction_history, print_log, employee_profiles, manager_employees, old_leave_requests, old_biometric_daily, old_payroll, old_users, old_employees, old_document_serials', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET FOREIGN_KEY_CHECKS = 1;

SELECT IF(@ok = 1, 'Done - the database now has the standard layout.', 'The old tables were NOT deleted.') AS migration_result;


-- ---------------------------------------------------------------------------------------------------------------------
--  PART E (optional, not run) - the backup copies the earlier update (migrate-employee-id.sql) left in the database.
--  Remove the two dashes in front of the lines below only when you no longer need those copies.
-- ---------------------------------------------------------------------------------------------------------------------

-- DROP TABLE IF EXISTS bak_emp_attendance, bak_emp_biometric_daily, bak_emp_biometric_employee_map, bak_emp_bonus_deduction_history, bak_emp_employee_profiles, bak_emp_employees, bak_emp_foreign_keys, bak_emp_indexes, bak_emp_leave_requests, bak_emp_manager_employees, bak_emp_payroll, bak_emp_payslip_signatures, bak_emp_users, biometric_daily_unmatched, users_unmatched;
