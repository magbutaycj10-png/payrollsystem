-- Base tables as the live database has them. Column lists come from the ERD export
-- (E:\downL\Chapter4_figures\source\schema.json); indexes/keys from the same file.
-- Everything else (users, leave_requests, biometric_daily, period_audit, ...) is created
-- by applySchemaPatches() when the suite boots, exactly as on a fresh install.
-- One statement per block, separated by a line holding only "--;;".

CREATE TABLE settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (setting_key)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE employees (
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
  PRIMARY KEY (id),
  UNIQUE KEY uq_emp_id (emp_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE payroll_periods (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE attendance (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE payroll (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE bonus_deduction_history (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE print_log (
  id            INT NOT NULL AUTO_INCREMENT,
  document_name VARCHAR(200) NOT NULL,
  document_type VARCHAR(80)  NOT NULL DEFAULT 'Physical Print',
  period_id     INT NULL,
  printed_by    VARCHAR(120) NULL,
  log_datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_plog_period FOREIGN KEY (period_id) REFERENCES payroll_periods (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE biometric_daily (
  id              INT          NOT NULL AUTO_INCREMENT,
  period_id       INT          NOT NULL,
  emp_id          VARCHAR(20)  NOT NULL,
  emp_name        VARCHAR(150) NOT NULL DEFAULT '',
  att_date        DATE         NOT NULL,
  hours_worked    DECIMAL(6,2) NOT NULL DEFAULT 0,
  overtime_hours  DECIMAL(6,2) NOT NULL DEFAULT 0,
  late_hours      DECIMAL(6,2) NOT NULL DEFAULT 0,
  undertime_hours DECIMAL(6,2) NULL DEFAULT NULL,
  entered_by      VARCHAR(150) NULL DEFAULT NULL,
  day_off         TINYINT(1)   NOT NULL DEFAULT 0,
  uploaded_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_daily (period_id, emp_id, att_date),
  CONSTRAINT fk_daily_period FOREIGN KEY (period_id) REFERENCES payroll_periods (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
