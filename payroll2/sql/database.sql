-- =====================================================================================================================
--  database.sql - the base tables of the payroll system (MySQL 8)
--
--  Run this ONCE on a new, empty database (DBeaver: open the file, select the right connection, press Alt+X).
--  It is safe to run again: every statement is CREATE TABLE IF NOT EXISTS.
--
--  Everything else - the users table and the admin sign-in, leave requests, daily attendance, the audit trail, signatures,
--  the biometric log - is created and kept up to date by the application itself the first time a page loads
--  (applySchemaPatches() in includes/helpers.php), so there is nothing else to import.
-- =====================================================================================================================

CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (setting_key)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
  id                INT          NOT NULL AUTO_INCREMENT,
  emp_id            VARCHAR(20)  NOT NULL,
  full_name         VARCHAR(150) NOT NULL,
  position          VARCHAR(100) NULL DEFAULT '',
  branch            VARCHAR(100) NULL DEFAULT '',
  email             VARCHAR(150) NULL DEFAULT '',
  base_salary       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  salary_type       ENUM('monthly','kinsenas','daily') NOT NULL DEFAULT 'monthly',
  date_hired        DATE NULL,
  status            ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  deduct_sss        TINYINT(1) NOT NULL DEFAULT 1,
  deduct_philhealth TINYINT(1) NOT NULL DEFAULT 1,
  deduct_pagibig    TINYINT(1) NOT NULL DEFAULT 1,
  rest_days         VARCHAR(20) NOT NULL DEFAULT '7',
  hours_per_day     DECIMAL(4,2) NULL DEFAULT NULL,
  -- The employee's own MONTHLY amounts, typed by the admin and deducted as typed (0 = none). The deduct_* columns above
  -- are no longer read: a zero amount is how an employee is left out of SSS, PhilHealth, Pag-IBIG or withholding tax.
  sss_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  philhealth_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pagibig_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_emp_id (emp_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_periods (
  id             INT         NOT NULL AUTO_INCREMENT,
  period_label   VARCHAR(80) NOT NULL,
  period_start   DATE NOT NULL,
  period_end     DATE NOT NULL,
  period_type    VARCHAR(20) NULL DEFAULT NULL,
  status         ENUM('Open','Locked') NOT NULL DEFAULT 'Open',
  finalized_at   TIMESTAMP NULL DEFAULT NULL,
  finalize_count INT NOT NULL DEFAULT 0,
  reopen_count   INT NOT NULL DEFAULT 0,
  reopened_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id                  INT NOT NULL AUTO_INCREMENT,
  period_id           INT NOT NULL,
  emp_id              VARCHAR(20)  NOT NULL,
  emp_name            VARCHAR(150) NOT NULL,
  hours_worked        DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  overtime_hours      DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  late_hours          DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  gross_pay           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  withholding_tax     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  upload_date         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  manually_entered_by VARCHAR(150) NULL,
  manager_approved    TINYINT(1) NOT NULL DEFAULT 0,
  approved_by         VARCHAR(150) NULL,
  approved_at         TIMESTAMP NULL DEFAULT NULL,
  gross_incl_ot       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_period_emp (period_id, emp_id),
  KEY idx_att_emp (emp_id),
  CONSTRAINT fk_att_period FOREIGN KEY (period_id) REFERENCES payroll_periods (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll (
  id                     INT NOT NULL AUTO_INCREMENT,
  period_id              INT NOT NULL,
  emp_id                 VARCHAR(20)  NOT NULL,
  emp_name               VARCHAR(150) NOT NULL,
  hours_worked           DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  overtime_hours         DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  late_hours             DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  gross_pay              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  withholding_tax        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ot_late_adj            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  sss                    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  philhealth             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pagibig                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bonus                  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  other_deductions       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_pay                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status                 ENUM('Draft','Finalized') NOT NULL DEFAULT 'Draft',
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revised_after_finalize TINYINT(1) NOT NULL DEFAULT 0,
  revised_at             TIMESTAMP NULL DEFAULT NULL,
  absent_days            DECIMAL(5,1)  NOT NULL DEFAULT 0.0,
  leave_days             DECIMAL(5,1)  NOT NULL DEFAULT 0.0,
  absent_deduction       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gross_incl_ot          TINYINT(1) NOT NULL DEFAULT 1,
  undertime_hours        DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  undertime_deduction    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  days_off               DECIMAL(5,1)  NOT NULL DEFAULT 0.0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_period_emp (period_id, emp_id),
  KEY idx_payroll_emp (emp_id),
  CONSTRAINT fk_payroll_period FOREIGN KEY (period_id) REFERENCES payroll_periods (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bonus_deduction_history (
  id             INT NOT NULL AUTO_INCREMENT,
  entry_date     DATE NOT NULL,
  emp_id         VARCHAR(20)  NOT NULL,
  emp_name       VARCHAR(150) NOT NULL,
  entry_type     ENUM('Bonus','Deduction') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reason         VARCHAR(255) NOT NULL DEFAULT '',
  processed_by   VARCHAR(100) NOT NULL DEFAULT 'Admin',
  period_id      INT NULL,
  finalize_cycle INT NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bdh_emp (emp_id),
  CONSTRAINT fk_bdh_period FOREIGN KEY (period_id) REFERENCES payroll_periods (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS print_log (
  id            INT NOT NULL AUTO_INCREMENT,
  document_name VARCHAR(200) NOT NULL,
  document_type VARCHAR(80)  NOT NULL DEFAULT 'Physical Print',
  period_id     INT NULL,
  printed_by    VARCHAR(120) NULL,
  log_datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_plog_period FOREIGN KEY (period_id) REFERENCES payroll_periods (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
