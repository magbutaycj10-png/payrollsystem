-- The layout of the live database as it was before payroll2/sql/migrate-standard-schema.sql (2026-10-15): the layout made by
-- migrate-employee-id.sql, in which every table that points at an employee stores employee_id = employees.id.
-- Exported from a copy of the live database; suite 15 runs the migration on it. Tables, columns, indexes and foreign keys only -
-- the leftover backup copies of the earlier update (bak_emp_*) and the *_unmatched tables are not part of the layout.
-- (The layout before that, which suite 14 migrates, is tests/schema_before_employee_id.sql.)
-- One statement per block, separated by a line holding only "--;;".

CREATE TABLE `attendance_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `punch_time` datetime NOT NULL,
  `punch_state` tinyint NOT NULL DEFAULT '0',
  `verify_mode` tinyint NOT NULL DEFAULT '0',
  `device_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'serial',
  `processed` tinyint(1) NOT NULL DEFAULT '0',
  `received_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_punch` (`employee_id`,`punch_time`),
  KEY `idx_unprocessed` (`processed`,`punch_time`),
  KEY `idx_punch_time` (`punch_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `biometric_agent_state` (
  `device_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_host` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `agent_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_punch_time` datetime DEFAULT NULL,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `punches_total` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `biometric_api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `key_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `device_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `branches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `document_serials` (
  `series` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_id` int NOT NULL,
  `serial_no` bigint NOT NULL,
  `issued_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`series`,`ref_id`),
  UNIQUE KEY `uq_series_no` (`series`,`serial_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `document_series` (
  `series` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_no` bigint NOT NULL DEFAULT '1',
  PRIMARY KEY (`series`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `employees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `emp_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `branch` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `base_salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `salary_type` enum('monthly','kinsenas','daily') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `date_hired` date DEFAULT NULL,
  `status` enum('Active','Inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `deduct_sss` tinyint(1) NOT NULL DEFAULT '1',
  `deduct_philhealth` tinyint(1) NOT NULL DEFAULT '1',
  `deduct_pagibig` tinyint(1) NOT NULL DEFAULT '1',
  `rest_days` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '7',
  `hours_per_day` decimal(4,2) DEFAULT NULL,
  `sss_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `philhealth_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pagibig_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_id` (`emp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `leave_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `emp_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `leave_type` enum('Vacation','Sick Leave','Emergency','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('Pending','Approved','Rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `reviewed_by` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leave_emp` (`employee_id`),
  CONSTRAINT `fk_leave_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ok` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`,`attempted_at`),
  KEY `idx_ip` (`ip`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `manager_employees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `manager_id` int NOT NULL,
  `employee_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mgr_emp` (`manager_id`,`employee_id`),
  KEY `idx_mgr` (`manager_id`),
  KEY `idx_mgr_employee` (`employee_id`),
  CONSTRAINT `fk_mgr_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `payroll_periods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_label` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `period_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Open','Locked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Open',
  `finalized_at` timestamp NULL DEFAULT NULL,
  `finalize_count` int NOT NULL DEFAULT '0',
  `reopen_count` int NOT NULL DEFAULT '0',
  `reopened_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `period_audit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_id` int NOT NULL,
  `action` enum('Finalized','Reopened','Revised') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cycle` int NOT NULL DEFAULT '0',
  `performed_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `print_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Physical Print',
  `period_id` int DEFAULT NULL,
  `printed_by` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `log_datetime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plog_period` (`period_id`),
  CONSTRAINT `fk_plog_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `settings` (
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','manager','earner') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'earner',
  `employee_id` int DEFAULT NULL,
  `branch` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `scope_type` enum('branch','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'branch',
  `status` enum('Active','Inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_users_employee` (`employee_id`),
  CONSTRAINT `fk_users_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `attendance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `emp_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hours_worked` decimal(6,2) NOT NULL DEFAULT '0.00',
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `late_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `gross_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `withholding_tax` decimal(12,2) NOT NULL DEFAULT '0.00',
  `upload_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `manually_entered_by` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_approved` tinyint(1) NOT NULL DEFAULT '0',
  `approved_by` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `gross_incl_ot` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_period_emp` (`period_id`,`employee_id`),
  KEY `idx_att_period` (`period_id`),
  KEY `idx_att_emp` (`employee_id`),
  CONSTRAINT `fk_att_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_att_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `biometric_daily` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `emp_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `att_date` date NOT NULL,
  `hours_worked` decimal(6,2) NOT NULL DEFAULT '0.00',
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `late_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `undertime_hours` decimal(6,2) DEFAULT NULL,
  `entered_by` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `day_off` tinyint(1) NOT NULL DEFAULT '0',
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_daily` (`period_id`,`employee_id`,`att_date`),
  KEY `idx_daily_emp` (`employee_id`,`att_date`),
  CONSTRAINT `fk_daily_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_daily_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `biometric_employee_map` (
  `device_user_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `employee_id` int NOT NULL,
  `device_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`device_user_id`),
  KEY `idx_emp` (`employee_id`),
  CONSTRAINT `fk_biomap_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `bonus_deduction_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entry_date` date NOT NULL,
  `employee_id` int NOT NULL,
  `emp_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_type` enum('Bonus','Deduction') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `processed_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Admin',
  `period_id` int DEFAULT NULL,
  `finalize_cycle` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bdh_period` (`period_id`),
  KEY `idx_bdh_emp` (`employee_id`),
  CONSTRAINT `fk_bdh_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_bdh_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `employee_profiles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_relation` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_profile_emp` (`employee_id`),
  CONSTRAINT `fk_profile_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `payroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `emp_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hours_worked` decimal(6,2) NOT NULL DEFAULT '0.00',
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `late_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `gross_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `withholding_tax` decimal(12,2) NOT NULL DEFAULT '0.00',
  `ot_late_adj` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sss` decimal(12,2) NOT NULL DEFAULT '0.00',
  `philhealth` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pagibig` decimal(12,2) NOT NULL DEFAULT '0.00',
  `bonus` decimal(12,2) NOT NULL DEFAULT '0.00',
  `other_deductions` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('Draft','Finalized') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revised_after_finalize` tinyint(1) NOT NULL DEFAULT '0',
  `revised_at` timestamp NULL DEFAULT NULL,
  `absent_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `leave_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `absent_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gross_incl_ot` tinyint(1) NOT NULL DEFAULT '1',
  `undertime_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `undertime_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `days_off` decimal(5,1) NOT NULL DEFAULT '0.0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_period_emp` (`period_id`,`employee_id`),
  KEY `idx_payroll_period` (`period_id`),
  KEY `idx_payroll_emp` (`employee_id`),
  CONSTRAINT `fk_payroll_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payroll_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `payslip_signatures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payroll_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `emp_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `period_id` int DEFAULT NULL,
  `period_label` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `signature_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `net_signed` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sig_payroll` (`payroll_id`),
  KEY `idx_sig_period` (`period_id`),
  KEY `idx_sig_emp` (`employee_id`),
  CONSTRAINT `fk_sig_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sig_payroll` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sig_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
