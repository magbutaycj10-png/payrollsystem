-- =====================================================================================================================
--  database.sql - the complete schema of the payroll system (MySQL 8)
--
--  A standard, normalised design: every table has its own single-column primary key `id` (the few key/value and
--  device tables are named below), every link between tables is a foreign key called <thing>_id, a person's company
--  number is employee_code (EMP-004) - never a second "id" - and nothing is stored twice (names, labels and hours live in
--  one table and are joined where they are shown).
--
--  Fresh install: the application runs this file by itself the first time it opens an EMPTY database. You can also run it
--  once by hand (DBeaver: open the file, pick the connection, press Alt+X) - every statement is CREATE TABLE IF NOT EXISTS,
--  so running it again is harmless.
--
--  A database that still has the older layout (emp_id, employee_profiles, attendance, bonus_deduction_history ...) is
--  converted, with every record kept, by migrate-standard-schema.sql - do not run this file on it.
--
--  Keep this file free of semicolons inside comments and strings: the application splits it on them.
-- =====================================================================================================================

-- ---------------------------------------------------------------------------------------------------------------------
--  1. Settings and lookup tables
-- ---------------------------------------------------------------------------------------------------------------------

-- Key / value configuration (company name, rates, schedule ...). The key is the primary key.
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (setting_key)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branches (
  id         INT          NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  address    TEXT         NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branches_name (name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
  id   INT          NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_name (name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS positions (
  id            INT          NOT NULL AUTO_INCREMENT,
  department_id INT          NULL,
  title         VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_positions_title (title),
  KEY idx_positions_department (department_id),
  CONSTRAINT fk_positions_department_id FOREIGN KEY (department_id) REFERENCES departments (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------------
--  2. Employees and user accounts
-- ---------------------------------------------------------------------------------------------------------------------

-- One row per person on the payroll. employee_code is the number people see (EMP-004), id is what other tables point at.
-- full_name is worked out from first_name + last_name (the pay computation, uploads and every list read it).
CREATE TABLE IF NOT EXISTS employees (
  id                 INT           NOT NULL AUTO_INCREMENT,
  employee_code      VARCHAR(30)   NOT NULL,
  first_name         VARCHAR(100)  NOT NULL,
  last_name          VARCHAR(100)  NOT NULL DEFAULT '',
  full_name          VARCHAR(201)  GENERATED ALWAYS AS (TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(last_name, '')))) VIRTUAL,
  email              VARCHAR(150)  NULL,
  phone              VARCHAR(30)   NULL,
  address            TEXT          NULL,
  emergency_name     VARCHAR(150)  NULL,
  emergency_phone    VARCHAR(30)   NULL,
  emergency_relation VARCHAR(80)   NULL,
  branch_id          INT           NULL,
  position_id        INT           NULL,
  date_hired         DATE          NULL,
  status             ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  salary_type        ENUM('monthly','kinsenas','daily') NOT NULL DEFAULT 'monthly',
  -- How often the employee is paid: a pay period only takes the employees on its own schedule (Semi-Monthly = kinsenas, twice a month)
  pay_schedule       ENUM('Semi-Monthly','Monthly') NOT NULL DEFAULT 'Semi-Monthly',
  base_salary        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  hours_per_day      DECIMAL(4,2)  NULL DEFAULT NULL,
  -- Pesos paid per overtime hour for THIS employee; NULL = the company rate in Settings
  overtime_rate      DECIMAL(10,2) NULL DEFAULT NULL,
  rest_days          VARCHAR(20)   NOT NULL DEFAULT '7',
  -- The employee's own MONTHLY amounts, typed by the admin and deducted as typed (0 = none)
  sss_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  philhealth_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pagibig_amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  -- When each amount comes out on a Semi-Monthly month: first = the 1st cut-off in full, split = equal shares at every cut-off,
  -- second = the last cut-off in full. The month's last cut-off always settles what is still owed. (A Monthly employee has one run.)
  sss_timing         ENUM('first','split','second') NOT NULL DEFAULT 'first',
  philhealth_timing  ENUM('first','split','second') NOT NULL DEFAULT 'second',
  pagibig_timing     ENUM('first','split','second') NOT NULL DEFAULT 'second',
  tax_timing         ENUM('first','split','second') NOT NULL DEFAULT 'split',
  created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employees_code (employee_code),
  UNIQUE KEY uq_employees_email (email),
  KEY idx_employees_branch (branch_id),
  KEY idx_employees_position (position_id),
  CONSTRAINT fk_employees_branch_id   FOREIGN KEY (branch_id)   REFERENCES branches (id)   ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_employees_position_id FOREIGN KEY (position_id) REFERENCES positions (id)  ON UPDATE CASCADE ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-in accounts for the three portals. employee_id is the employee an Employee account belongs to (NULL for the admin and
-- managers, who are not on the payroll). Passwords are bcrypt hashes only.
CREATE TABLE IF NOT EXISTS users (
  id            INT          NOT NULL AUTO_INCREMENT,
  employee_id   INT          NULL,
  full_name     VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('Admin','Manager','Employee') NOT NULL DEFAULT 'Employee',
  status        ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  last_login    TIMESTAMP    NULL DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_employee (employee_id),
  CONSTRAINT fk_users_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which employees a Manager handles - one row per grant: everybody (Global), a whole Branch, a whole Department, or one
-- picked Employee. A manager with no row handles nobody. The admin is not limited.
CREATE TABLE IF NOT EXISTS user_scopes (
  id            INT NOT NULL AUTO_INCREMENT,
  user_id       INT NOT NULL,
  scope_type    ENUM('Global','Branch','Department','Employee') NOT NULL DEFAULT 'Branch',
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------------
--  3. Pay periods, time and attendance
-- ---------------------------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS payroll_periods (
  id             INT          NOT NULL AUTO_INCREMENT,
  period_label   VARCHAR(80)  NOT NULL,
  start_date     DATE         NOT NULL,
  end_date       DATE         NOT NULL,
  period_type    ENUM('Semi-Monthly','Monthly','Weekly') NOT NULL DEFAULT 'Monthly',
  status         ENUM('Open','Locked') NOT NULL DEFAULT 'Open',
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per employee per day (uploaded timesheet, biometric report or a manager's manual entry). The pay period a day
-- belongs to follows from its date. hours_worked 0 with is_day_off = 1 is a day the timesheet marks OFF.
CREATE TABLE IF NOT EXISTS biometric_daily (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per employee per pay period: what the days (or a totals file) added up to, and the manager's review of it.
CREATE TABLE IF NOT EXISTS attendance_summary (
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
  approval_status       ENUM('Pending','Approved','Flagged') NOT NULL DEFAULT 'Pending',
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_requests (
  id          INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  leave_type  ENUM('Vacation','Sick Leave','Emergency','Other') NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  reason      TEXT NULL,
  status      ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  reviewed_by INT NULL,
  review_note VARCHAR(255) NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_leave_requests_employee (employee_id),
  KEY idx_leave_requests_reviewed_by (reviewed_by),
  CONSTRAINT fk_leave_requests_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_leave_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id)     ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------------
--  4. Payroll and compensation
-- ---------------------------------------------------------------------------------------------------------------------

-- A bonus or deduction recorded against one employee in one open pay period (Adjustments, 13th Month Pay).
-- entry_date is the day it was recorded, finalize_cycle says whether it was a correction made after the period had been finalized.
CREATE TABLE IF NOT EXISTS bonus_deductions (
  id             INT           NOT NULL AUTO_INCREMENT,
  employee_id    INT           NOT NULL,
  period_id      INT           NULL,
  type           ENUM('Bonus','Deduction') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reason         VARCHAR(255)  NOT NULL DEFAULT '',
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One pay line per employee per pay period.
--   gross_pay        everything earned: basic_pay + overtime_pay - the late deduction (bonus is NOT in it)
--   basic_pay        basic pay for the run, after absences and undertime
--   tardy_undertime_deduction / absence_deduction   what lateness, undertime and absences cost - already out of gross_pay
--   total_deductions what comes off gross_pay + bonus_pay: SSS + PhilHealth + Pag-IBIG + withholding tax + other deductions
--   net_pay          gross_pay + bonus_pay - total_deductions
CREATE TABLE IF NOT EXISTS payroll (
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
  status                    ENUM('Draft','Finalized') NOT NULL DEFAULT 'Draft',
  revised_after_finalize    TINYINT(1)    NOT NULL DEFAULT 0,
  revised_at                TIMESTAMP     NULL DEFAULT NULL,
  created_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_period_employee (period_id, employee_id),
  KEY idx_payroll_employee (employee_id),
  CONSTRAINT fk_payroll_period_id   FOREIGN KEY (period_id)   REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_payroll_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id)       ON UPDATE CASCADE ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The employee's drawn signature acknowledging one payslip. net_signed is the net pay it was signed for: a later correction
-- that changes the net pay makes the signature out of date.
CREATE TABLE IF NOT EXISTS payslip_signatures (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The employee's signature on a bonus / deduction receipt: one per bonus_deductions row, stamped with the amount it acknowledges
-- (a signature for an amount that has since changed no longer counts), like payslip_signatures per pay line.
CREATE TABLE IF NOT EXISTS adjustment_signatures (
  id             INT           NOT NULL AUTO_INCREMENT,
  adjustment_id  INT           NOT NULL,
  signature_data LONGTEXT      NOT NULL,
  amount_signed  DECIMAL(12,2) NULL DEFAULT NULL,
  signed_by_user INT           NULL,
  signed_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_adjustment_signatures_adjustment (adjustment_id),
  KEY idx_adjustment_signatures_signed_by (signed_by_user),
  CONSTRAINT fk_adjustment_signatures_adjustment_id  FOREIGN KEY (adjustment_id)  REFERENCES bonus_deductions (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_adjustment_signatures_signed_by_user FOREIGN KEY (signed_by_user) REFERENCES users (id)           ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The admin's drawn signature (Settings > Authorised Signatory): a single row, id 1. Its name and position are settings rows
-- (bir_signatory_name, bir_signatory_position). Printed on a receipt once the receipt's pay period is finalized.
CREATE TABLE IF NOT EXISTS admin_signature (
  id             INT       NOT NULL,
  signature_data LONGTEXT  NOT NULL,
  updated_by     INT       NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_signature_updated_by (updated_by),
  CONSTRAINT fk_admin_signature_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------------
--  5. Audit and logging
-- ---------------------------------------------------------------------------------------------------------------------

-- Every document printed or exported. document_type says how: Physical Print, PDF Export or Employee Self-Service.
CREATE TABLE IF NOT EXISTS print_logs (
  id            INT          NOT NULL AUTO_INCREMENT,
  document_name VARCHAR(200) NOT NULL,
  document_type VARCHAR(80)  NOT NULL DEFAULT 'Physical Print',
  period_id     INT          NULL,
  printed_by    INT          NULL,
  printed_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_print_logs_period (period_id),
  KEY idx_print_logs_printed_by (printed_by),
  CONSTRAINT fk_print_logs_period_id  FOREIGN KEY (period_id)  REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_print_logs_printed_by FOREIGN KEY (printed_by) REFERENCES users (id)           ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The finalize / re-open / revise trail of a pay period: who did what, when and why.
CREATE TABLE IF NOT EXISTS period_audit (
  id           INT          NOT NULL AUTO_INCREMENT,
  period_id    INT          NOT NULL,
  action       ENUM('Finalized','Reopened','Revised') NOT NULL,
  cycle        INT          NOT NULL DEFAULT 0,
  performed_by INT          NULL,
  note         VARCHAR(255) NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_period_audit_period (period_id),
  KEY idx_period_audit_performed_by (performed_by),
  CONSTRAINT fk_period_audit_period_id    FOREIGN KEY (period_id)    REFERENCES payroll_periods (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_period_audit_performed_by FOREIGN KEY (performed_by) REFERENCES users (id)           ON UPDATE CASCADE ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-in attempts, to slow down password guessing.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT          NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  email        VARCHAR(150) NOT NULL DEFAULT '',
  ok           TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_email (email, attempted_at),
  KEY idx_login_attempts_ip (ip, attempted_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------------
--  6. Document numbering (receipts and registers never reuse a number)
-- ---------------------------------------------------------------------------------------------------------------------

-- One counter per document series. The series name is the key.
CREATE TABLE IF NOT EXISTS document_series (
  series  VARCHAR(20) NOT NULL,
  next_no BIGINT      NOT NULL DEFAULT 1,
  PRIMARY KEY (series)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The number issued for one record (ref_id), so a reprint shows the same number.
CREATE TABLE IF NOT EXISTS document_serials (
  id        INT         NOT NULL AUTO_INCREMENT,
  series    VARCHAR(20) NOT NULL,
  ref_id    INT         NOT NULL,
  serial_no BIGINT      NOT NULL,
  issued_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_document_serials_ref (series, ref_id),
  UNIQUE KEY uq_document_serials_no (series, serial_no)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------------
--  7. Biometric terminal (the EPH A6) and its desktop agent
-- ---------------------------------------------------------------------------------------------------------------------

-- Raw punches pushed by the agent. device_user_id is the terminal's own user number - not employees.id.
-- uq_attendance_logs_punch is the duplicate guard: one person cannot punch twice in the same second.
CREATE TABLE IF NOT EXISTS attendance_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_user_id VARCHAR(32)  NOT NULL,
  punch_time     DATETIME     NOT NULL,
  punch_state    TINYINT      NOT NULL DEFAULT 0,
  verify_mode    TINYINT      NOT NULL DEFAULT 0,
  device_id      VARCHAR(40)  NOT NULL DEFAULT '',
  source         VARCHAR(16)  NOT NULL DEFAULT 'serial',
  processed      TINYINT(1)   NOT NULL DEFAULT 0,
  received_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_logs_punch (device_user_id, punch_time),
  KEY idx_attendance_logs_unprocessed (processed, punch_time),
  KEY idx_attendance_logs_punch_time (punch_time)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Terminal user number -> the employee. The terminal's number is the key.
CREATE TABLE IF NOT EXISTS biometric_employee_map (
  device_user_id VARCHAR(32) NOT NULL,
  employee_id    INT         NOT NULL,
  device_id      VARCHAR(40) NOT NULL DEFAULT '',
  created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (device_user_id),
  KEY idx_biometric_employee_map_employee (employee_id),
  CONSTRAINT fk_biometric_employee_map_employee_id FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which PC currently holds the terminal, and when it last reported. The device id is the key.
CREATE TABLE IF NOT EXISTS biometric_agent_state (
  device_id       VARCHAR(40)  NOT NULL,
  agent_host      VARCHAR(100) NOT NULL DEFAULT '',
  agent_version   VARCHAR(20)  NOT NULL DEFAULT '',
  last_punch_time DATETIME     NULL DEFAULT NULL,
  last_sync_at    TIMESTAMP    NULL DEFAULT NULL,
  punches_total   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One API key per client PC, stored as a SHA-256 hash so the key itself never sits in the database.
CREATE TABLE IF NOT EXISTS biometric_api_keys (
  id           INT          NOT NULL AUTO_INCREMENT,
  key_hash     CHAR(64)     NOT NULL,
  label        VARCHAR(100) NOT NULL DEFAULT '',
  device_id    VARCHAR(40)  NOT NULL DEFAULT '',
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_used_at TIMESTAMP    NULL DEFAULT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_biometric_api_keys_hash (key_hash)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
