-- =====================================================================================================================
--  rollback-employee-id.sql - put back the layout from before migrate-employee-id.sql
--
--  Only for going back to the OLD version of the app. For each of the ten tables: emp_id comes back, filled in from
--  employees through employee_id (so rows written by the new version come back too), the indexes and foreign keys
--  that used emp_id are put back as migrate-employee-id.sql recorded them (bak_emp_indexes, bak_emp_foreign_keys),
--  and employee_id with its foreign key is removed. No row is deleted. An emp_id comes back as the employee's own
--  (EMP-004), even where the old row had it in other capitals or with a trailing space.
--
--  HOW TO RUN: select the whole file, Alt+X, then deploy the old version of the app. Safe to run again.
-- =====================================================================================================================
SET SESSION group_concat_max_len = 1000000;

-- attendance ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `attendance` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `attendance` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `attendance` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'attendance');
SET @adds = COALESCE(@adds, NULLIF('ADD UNIQUE KEY `uq_attendance_period_emp` (period_id, emp_id), ADD KEY `idx_att_emp` (emp_id)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `attendance` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'attendance'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `attendance` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- payroll ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `payroll` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `payroll` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `payroll` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'payroll');
SET @adds = COALESCE(@adds, NULLIF('ADD UNIQUE KEY `uq_payroll_period_emp` (period_id, emp_id), ADD KEY `idx_payroll_emp` (emp_id)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `payroll` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'payroll'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `payroll` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- bonus_deduction_history ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `bonus_deduction_history` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `bonus_deduction_history` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `bonus_deduction_history` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'bonus_deduction_history');
SET @adds = COALESCE(@adds, NULLIF('ADD KEY `idx_bdh_emp` (emp_id)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `bonus_deduction_history` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'bonus_deduction_history'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `bonus_deduction_history` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- biometric_daily ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `biometric_daily` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `biometric_daily` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `biometric_daily` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'biometric_daily');
SET @adds = COALESCE(@adds, NULLIF('ADD UNIQUE KEY `uq_daily` (period_id, emp_id, att_date)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `biometric_daily` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'biometric_daily'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `biometric_daily` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- leave_requests ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `leave_requests` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `leave_requests` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `leave_requests` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'leave_requests');
SET @adds = COALESCE(@adds, NULLIF('ADD KEY `idx_leave_emp` (emp_id)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `leave_requests` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'leave_requests'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `leave_requests` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- payslip_signatures ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `payslip_signatures` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `payslip_signatures` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `payslip_signatures` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'payslip_signatures');
SET @adds = COALESCE(@adds, NULLIF('ADD KEY `idx_sig_emp` (emp_id)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `payslip_signatures` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'payslip_signatures'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `payslip_signatures` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- employee_profiles ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `employee_profiles` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `employee_profiles` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `employee_profiles` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'employee_profiles');
SET @adds = COALESCE(@adds, NULLIF('ADD UNIQUE KEY `uq_profile_emp` (emp_id)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `employee_profiles` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'employee_profiles'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `employee_profiles` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- manager_employees ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `manager_employees` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `manager_employees` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `manager_employees` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'manager_employees');
SET @adds = COALESCE(@adds, NULLIF('ADD UNIQUE KEY `uq_mgr_emp` (manager_id, emp_id)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `manager_employees` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'manager_employees'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `manager_employees` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- users ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `users` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `users` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `users` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'users');
SET @adds = COALESCE(@adds, NULLIF('', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `users` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'users'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `users` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- biometric_employee_map ---------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1,
    'ALTER TABLE `biometric_employee_map` ADD COLUMN emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER employee_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT('UPDATE `biometric_employee_map` x JOIN employees e ON e.id = x.employee_id SET x.emp_id = e.emp_id', COALESCE(@keep, ''), ' WHERE NOT (x.emp_id <=> e.emp_id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL; SET @drops = NULL; SET @adds = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@sql IS NOT NULL, CONCAT('ALTER TABLE `biometric_employee_map` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id');
SET @adds = (SELECT GROUP_CONCAT(CONCAT(IF(non_unique = 0, 'ADD UNIQUE KEY `', 'ADD KEY `'), index_name, '` (', columns_in, ')') SEPARATOR ', ')
               FROM bak_emp_indexes WHERE table_name = 'biometric_employee_map');
SET @adds = COALESCE(@adds, NULLIF('ADD KEY `idx_emp` (emp_id)', ''));
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `biometric_employee_map` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY emp_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, DROP COLUMN employee_id'), @adds),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = NULL;
SET @sql = (SELECT GROUP_CONCAT(CONCAT('ADD CONSTRAINT `', f.constraint_name, '` FOREIGN KEY (`', f.column_name, '`) REFERENCES `',
                    f.referenced_table, '` (`', f.referenced_column, '`) ON UPDATE ', f.update_rule, ' ON DELETE ', f.delete_rule) SEPARATOR ', ')
              FROM bak_emp_foreign_keys f
             WHERE f.table_name = 'biometric_employee_map'
               AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = DATABASE()
                                  AND c.TABLE_NAME = f.table_name AND c.CONSTRAINT_NAME = f.constraint_name));
SET @sql = IF(@sql IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('ALTER TABLE `biometric_employee_map` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Result: expect emp_id (and no employee_id) in all ten tables.
SELECT TABLE_NAME AS table_name, GROUP_CONCAT(COLUMN_NAME) AS employee_columns
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('attendance', 'payroll', 'bonus_deduction_history', 'biometric_daily', 'leave_requests', 'payslip_signatures', 'employee_profiles', 'manager_employees', 'users', 'biometric_employee_map') AND COLUMN_NAME IN ('emp_id', 'employee_id')
 GROUP BY TABLE_NAME ORDER BY TABLE_NAME;
