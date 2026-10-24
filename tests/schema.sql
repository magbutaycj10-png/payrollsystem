-- The complete schema of the payroll database in the layout of payroll2/sql/database.sql (the standard layout),
-- exported from a database converted by payroll2/sql/migrate-standard-schema.sql. Suite 13 proves that running
-- database.sql on an empty database builds exactly this, and suite 15 that the migration produces it from the older layout.
-- (The layouts before it are tests/schema_before_standard.sql and tests/schema_before_employee_id.sql.)
-- One statement per block, separated by a line holding only "--;;".

CREATE TABLE `settings` (
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `branches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `departments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `positions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `department_id` int DEFAULT NULL,
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_positions_title` (`title`),
  KEY `idx_positions_department` (`department_id`),
  CONSTRAINT `fk_positions_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `employees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `full_name` varchar(201) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (trim(concat_ws(_utf8mb4' ',nullif(`first_name`,_utf8mb4''),nullif(`last_name`,_utf8mb4'')))) VIRTUAL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `emergency_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_relation` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `position_id` int DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `salary_type` enum('monthly','kinsenas','daily') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `pay_schedule` enum('Semi-Monthly','Monthly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Semi-Monthly',
  `base_salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `hours_per_day` decimal(4,2) DEFAULT NULL,
  `rest_days` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '7',
  `sss_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `philhealth_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pagibig_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sss_timing` enum('first','split','second') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'first',
  `philhealth_timing` enum('first','split','second') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'second',
  `pagibig_timing` enum('first','split','second') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'second',
  `tax_timing` enum('first','split','second') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'split',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employees_code` (`employee_code`),
  UNIQUE KEY `uq_employees_email` (`email`),
  KEY `idx_employees_branch` (`branch_id`),
  KEY `idx_employees_position` (`position_id`),
  CONSTRAINT `fk_employees_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_employees_position_id` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int DEFAULT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('Admin','Manager','Employee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Employee',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_employee` (`employee_id`),
  CONSTRAINT `fk_users_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `user_scopes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `scope_type` enum('Global','Branch','Department','Employee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Branch',
  `branch_id` int DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `employee_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_scopes_user` (`user_id`),
  KEY `idx_user_scopes_branch` (`branch_id`),
  KEY `idx_user_scopes_department` (`department_id`),
  KEY `idx_user_scopes_employee` (`employee_id`),
  CONSTRAINT `fk_user_scopes_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_scopes_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_scopes_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_scopes_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `payroll_periods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_label` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `period_type` enum('Semi-Monthly','Monthly','Weekly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Monthly',
  `status` enum('Open','Locked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Open',
  `finalized_at` timestamp NULL DEFAULT NULL,
  `finalize_count` int NOT NULL DEFAULT '0',
  `reopen_count` int NOT NULL DEFAULT '0',
  `reopened_at` timestamp NULL DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_periods_dates` (`start_date`,`end_date`),
  KEY `idx_payroll_periods_created_by` (`created_by`),
  CONSTRAINT `fk_payroll_periods_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `biometric_daily` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `att_date` date NOT NULL,
  `hours_worked` decimal(6,2) NOT NULL DEFAULT '0.00',
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `late_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `undertime_hours` decimal(6,2) DEFAULT NULL,
  `is_day_off` tinyint(1) NOT NULL DEFAULT '0',
  `entered_by` int DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_biometric_daily_employee_date` (`employee_id`,`att_date`),
  KEY `idx_biometric_daily_date` (`att_date`),
  KEY `idx_biometric_daily_entered_by` (`entered_by`),
  CONSTRAINT `fk_biometric_daily_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_biometric_daily_entered_by` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `attendance_summary` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `total_hours_worked` decimal(7,2) NOT NULL DEFAULT '0.00',
  `total_overtime_hours` decimal(7,2) NOT NULL DEFAULT '0.00',
  `total_late_hours` decimal(7,2) NOT NULL DEFAULT '0.00',
  `total_undertime_hours` decimal(7,2) NOT NULL DEFAULT '0.00',
  `absent_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `leave_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `days_off` decimal(5,1) NOT NULL DEFAULT '0.0',
  `is_manually_entered` tinyint(1) NOT NULL DEFAULT '0',
  `approval_status` enum('Pending','Approved','Flagged') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `approved_by` int DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_summary_period_employee` (`period_id`,`employee_id`),
  KEY `idx_attendance_summary_employee` (`employee_id`),
  KEY `idx_attendance_summary_approved_by` (`approved_by`),
  CONSTRAINT `fk_attendance_summary_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_summary_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_summary_period_id` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `leave_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `leave_type` enum('Vacation','Sick Leave','Emergency','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Pending','Approved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `reviewed_by` int DEFAULT NULL,
  `review_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leave_requests_employee` (`employee_id`),
  KEY `idx_leave_requests_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_leave_requests_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_leave_requests_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `bonus_deductions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `period_id` int DEFAULT NULL,
  `type` enum('Bonus','Deduction') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `processed_by` int DEFAULT NULL,
  `entry_date` date NOT NULL,
  `finalize_cycle` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bonus_deductions_employee` (`employee_id`),
  KEY `idx_bonus_deductions_period` (`period_id`),
  KEY `idx_bonus_deductions_processed_by` (`processed_by`),
  CONSTRAINT `fk_bonus_deductions_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_bonus_deductions_period_id` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bonus_deductions_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `payroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `basic_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `overtime_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `bonus_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gross_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sss_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `philhealth_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pagibig_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `withholding_tax` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tardy_undertime_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `absence_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `other_deductions` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_deductions` decimal(12,2) GENERATED ALWAYS AS (((((`sss_deduction` + `philhealth_deduction`) + `pagibig_deduction`) + `withholding_tax`) + `other_deductions`)) STORED,
  `net_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('Draft','Finalized') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `revised_after_finalize` tinyint(1) NOT NULL DEFAULT '0',
  `revised_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_period_employee` (`period_id`,`employee_id`),
  KEY `idx_payroll_employee` (`employee_id`),
  CONSTRAINT `fk_payroll_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payroll_period_id` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `payslip_signatures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payroll_id` int NOT NULL,
  `signature_data` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `net_signed` decimal(12,2) DEFAULT NULL,
  `signed_by_user` int DEFAULT NULL,
  `signed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payslip_signatures_payroll` (`payroll_id`),
  KEY `idx_payslip_signatures_signed_by` (`signed_by_user`),
  CONSTRAINT `fk_payslip_signatures_payroll_id` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payslip_signatures_signed_by_user` FOREIGN KEY (`signed_by_user`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `print_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Physical Print',
  `period_id` int DEFAULT NULL,
  `printed_by` int DEFAULT NULL,
  `printed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_print_logs_period` (`period_id`),
  KEY `idx_print_logs_printed_by` (`printed_by`),
  CONSTRAINT `fk_print_logs_period_id` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_print_logs_printed_by` FOREIGN KEY (`printed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `period_audit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_id` int NOT NULL,
  `action` enum('Finalized','Reopened','Revised') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cycle` int NOT NULL DEFAULT '0',
  `performed_by` int DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period_audit_period` (`period_id`),
  KEY `idx_period_audit_performed_by` (`performed_by`),
  CONSTRAINT `fk_period_audit_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_period_audit_period_id` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ok` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_email` (`email`,`attempted_at`),
  KEY `idx_login_attempts_ip` (`ip`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `document_series` (
  `series` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_no` bigint NOT NULL DEFAULT '1',
  PRIMARY KEY (`series`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `document_serials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `series` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_id` int NOT NULL,
  `serial_no` bigint NOT NULL,
  `issued_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_serials_ref` (`series`,`ref_id`),
  UNIQUE KEY `uq_document_serials_no` (`series`,`serial_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `attendance_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `device_user_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `punch_time` datetime NOT NULL,
  `punch_state` tinyint NOT NULL DEFAULT '0',
  `verify_mode` tinyint NOT NULL DEFAULT '0',
  `device_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'serial',
  `processed` tinyint(1) NOT NULL DEFAULT '0',
  `received_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_logs_punch` (`device_user_id`,`punch_time`),
  KEY `idx_attendance_logs_unprocessed` (`processed`,`punch_time`),
  KEY `idx_attendance_logs_punch_time` (`punch_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--;;
CREATE TABLE `biometric_employee_map` (
  `device_user_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `employee_id` int NOT NULL,
  `device_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`device_user_id`),
  KEY `idx_biometric_employee_map_employee` (`employee_id`),
  CONSTRAINT `fk_biometric_employee_map_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
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
  UNIQUE KEY `uq_biometric_api_keys_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
