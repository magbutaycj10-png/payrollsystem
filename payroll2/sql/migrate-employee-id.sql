-- =====================================================================================================================
--  migrate-employee-id.sql - link every employee record by employees.id (the integer primary key)
--
--  Ten tables point at an employee: attendance, payroll, bonus_deduction_history, biometric_daily, leave_requests,
--  payslip_signatures, employee_profiles, manager_employees, users (an employee's portal login) and
--  biometric_employee_map. Until now each kept a copy of the text ID (emp_id, e.g. EMP-004). This script gives each one
--  employee_id INT = employees.id, filled in from that emp_id, then removes the copy and adds a FOREIGN KEY to
--  employees(id). employees keeps emp_id (UNIQUE) - it is still the ID shown on screens, files and payslips.
--
--  NO ROW IS DELETED. A row whose emp_id matches no employee cannot get an employee_id; Part B then refuses to start
--  until you decide what it belongs to (the remedies are under PART A, step A6).
--
--  Foreign keys added (ON UPDATE CASCADE everywhere):
--    attendance, payroll, bonus_deduction_history, biometric_daily, leave_requests, payslip_signatures
--        ON DELETE RESTRICT  - history: an employee who has any of it cannot be deleted
--    employee_profiles, manager_employees, users, biometric_employee_map
--        ON DELETE CASCADE   - they belong to the employee and go with them
--  (The app already refused to delete an employee with history; now the database itself enforces it.)
--
--  WHY IN PARTS, AND NOT ONE TRANSACTION
--    MySQL saves every ALTER TABLE the moment it runs (an "implicit commit") - START TRANSACTION / ROLLBACK cannot undo
--    one. So the safety is built differently:
--      Part A  only ADDS: backup copies of the tables, a new employee_id column (empty at first), and fills it in
--              (that fill-in IS one transaction). Nothing is removed: the old version of the app keeps working (the
--              new version waits for Part B). It ends with a report: check it before going on.
--      Part B  the switch: removes emp_id from the ten tables and adds the foreign keys. It first checks again that
--              every row has its employee_id; if not, it stops on purpose (an error naming the problem) and EVERY step
--              after it is skipped - even if you tell DBeaver to ignore the error.
--      Part C  checks that every row of the backup copies is still there and points at the same employee; later, the
--              line that removes the backup copies.
--    rollback-employee-id.sql puts the old layout back (emp_id filled in again from employees) if ever needed.
--    Every step can be run again: whatever is already done is skipped.
--
--  HOW TO RUN (DBeaver, on the payroll database)
--    0. Make a full backup first: right-click the database > Tools > Backup (or Export Data). Pick a quiet moment -
--       nobody uploading or editing while it runs. Each part is a few hundred short statements: on the cloud database
--       (about a third of a second each) that is a minute or two per part.
--    1. Select everything from "PART A" down to "END OF PART A" and press Alt+X (Execute script). Look at the result
--       tabs of steps A5 and A6.
--    2. When A6 shows no rows, select PART B down to "END OF PART B" and press Alt+X. The last tab (B5) lists each
--       table's new foreign key.
--    3. Deploy the new version of the app (until then the old version shows errors; the new one, deployed early, shows
--       "The database needs a one-time update" instead of working, and is fine as soon as Part B has run).
--    4. Run PART C. When it shows 0 everywhere and the app has been in use for a while, drop the backup copies (C2).
-- =====================================================================================================================


-- =====================================================================================================================
--  PART A - additive and safe to re-run
-- =====================================================================================================================
SET SESSION group_concat_max_len = 1000000;

-- A1. Before anything changes: per table, how many rows there are and how many match an employee (read only).
--     "rows_without_employee" must end up 0 before Part B (see A6). Tables already converted are not listed.
SET @sql = (SELECT GROUP_CONCAT(CONCAT(
        'SELECT ''', c.TABLE_NAME, ''' AS table_name, COUNT(*) AS total_rows, ',
        'SUM(e.id IS NOT NULL) AS rows_with_employee, ',
        'SUM(e.id IS NULL AND NULLIF(TRIM(x.emp_id), '''') IS NOT NULL) AS rows_without_employee ',
        'FROM `', c.TABLE_NAME, '` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci')
        ORDER BY c.TABLE_NAME SEPARATOR ' UNION ALL ')
    FROM information_schema.COLUMNS c
    WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'emp_id' AND c.TABLE_NAME IN ('attendance', 'payroll', 'bonus_deduction_history', 'biometric_daily', 'leave_requests', 'payslip_signatures', 'employee_profiles', 'manager_employees', 'users', 'biometric_employee_map'));
SET @sql = COALESCE(@sql, 'SELECT ''every table already uses employee_id'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- A1b. Two employees whose IDs differ only in capitals or trailing spaces would make a match ambiguous. Expect: no rows.
SELECT CONVERT(emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id_as_compared, COUNT(*) AS employees,
       GROUP_CONCAT(id ORDER BY id) AS employee_ids
  FROM employees
 GROUP BY CONVERT(emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
HAVING COUNT(*) > 1;

-- A1c. Views that read emp_id from these tables would stop working after Part B (the application uses no views; an old
--      install may still have v_latest_payroll / v_monthly_totals). Expect: no rows - or recreate the view afterwards.
SELECT TABLE_NAME AS view_name
  FROM information_schema.VIEWS
 WHERE TABLE_SCHEMA = DATABASE() AND VIEW_DEFINITION LIKE '%emp_id%';

-- A2. Backup copies, inside the database: bak_emp_<table>, with every row as it is now. Made once, while the table is
--     still in its original layout (emp_id and no employee_id yet): a copy that already has rows is left alone, and
--     running Part A again later - before or after Part B - makes none. (This is in addition to the full backup of
--     step 0, not instead of it.) The foreign keys and indexes that use emp_id are recorded too, so the rollback can
--     put back exactly what was there.
-- employees
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_employees` LIKE `employees`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_employees') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_employees`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_employees` SELECT * FROM `employees`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- attendance
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_attendance` LIKE `attendance`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_attendance') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_attendance`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_attendance` SELECT * FROM `attendance`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payroll
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_payroll` LIKE `payroll`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_payroll') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_payroll`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_payroll` SELECT * FROM `payroll`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- bonus_deduction_history
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_bonus_deduction_history` LIKE `bonus_deduction_history`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_bonus_deduction_history') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_bonus_deduction_history`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_bonus_deduction_history` SELECT * FROM `bonus_deduction_history`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_daily
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_biometric_daily` LIKE `biometric_daily`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_biometric_daily') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_biometric_daily`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_biometric_daily` SELECT * FROM `biometric_daily`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- leave_requests
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_leave_requests` LIKE `leave_requests`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_leave_requests') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_leave_requests`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_leave_requests` SELECT * FROM `leave_requests`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payslip_signatures
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_payslip_signatures` LIKE `payslip_signatures`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_payslip_signatures') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_payslip_signatures`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_payslip_signatures` SELECT * FROM `payslip_signatures`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- employee_profiles
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_employee_profiles` LIKE `employee_profiles`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_employee_profiles') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_employee_profiles`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_employee_profiles` SELECT * FROM `employee_profiles`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- manager_employees
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_manager_employees` LIKE `manager_employees`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_manager_employees') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_manager_employees`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_manager_employees` SELECT * FROM `manager_employees`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- users
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_users` LIKE `users`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_users') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_users`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_users` SELECT * FROM `users`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_employee_map
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1,
    'CREATE TABLE IF NOT EXISTS `bak_emp_biometric_employee_map` LIKE `biometric_employee_map`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @copied = NULL;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bak_emp_biometric_employee_map') = 1,
    'SELECT COUNT(*) INTO @copied FROM `bak_emp_biometric_employee_map`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1 AND @copied = 0,
    'INSERT INTO `bak_emp_biometric_employee_map` SELECT * FROM `biometric_employee_map`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- the foreign keys and indexes that use emp_id (for the rollback)
CREATE TABLE IF NOT EXISTS bak_emp_foreign_keys (
    table_name        VARCHAR(64) NOT NULL,
    constraint_name   VARCHAR(64) NOT NULL,
    column_name       VARCHAR(64) NOT NULL,
    referenced_table  VARCHAR(64) NOT NULL,
    referenced_column VARCHAR(64) NOT NULL,
    update_rule       VARCHAR(20) NOT NULL,
    delete_rule       VARCHAR(20) NOT NULL,
    PRIMARY KEY (table_name, constraint_name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO bak_emp_foreign_keys
SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
       r.UPDATE_RULE, r.DELETE_RULE
  FROM information_schema.KEY_COLUMN_USAGE k
  JOIN information_schema.REFERENTIAL_CONSTRAINTS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
 WHERE k.TABLE_SCHEMA = DATABASE() AND k.COLUMN_NAME = 'emp_id' AND k.REFERENCED_TABLE_NAME IS NOT NULL
   AND k.TABLE_NAME IN ('attendance', 'payroll', 'bonus_deduction_history', 'biometric_daily', 'leave_requests', 'payslip_signatures', 'employee_profiles', 'manager_employees', 'users', 'biometric_employee_map');
CREATE TABLE IF NOT EXISTS bak_emp_indexes (
    table_name  VARCHAR(64)  NOT NULL,
    index_name  VARCHAR(64)  NOT NULL,
    non_unique  TINYINT      NOT NULL,
    columns_in  VARCHAR(500) NOT NULL,
    PRIMARY KEY (table_name, index_name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO bak_emp_indexes
SELECT s.TABLE_NAME, s.INDEX_NAME, MAX(s.NON_UNIQUE), GROUP_CONCAT(CONCAT('`', s.COLUMN_NAME, '`') ORDER BY s.SEQ_IN_INDEX SEPARATOR ', ')
  FROM information_schema.STATISTICS s
 WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME IN ('attendance', 'payroll', 'bonus_deduction_history', 'biometric_daily', 'leave_requests', 'payslip_signatures', 'employee_profiles', 'manager_employees', 'users', 'biometric_employee_map') AND s.INDEX_NAME <> 'PRIMARY'
   AND EXISTS (SELECT 1 FROM information_schema.STATISTICS u
                WHERE u.TABLE_SCHEMA = s.TABLE_SCHEMA AND u.TABLE_NAME = s.TABLE_NAME
                  AND u.INDEX_NAME = s.INDEX_NAME AND u.COLUMN_NAME = 'emp_id')
 GROUP BY s.TABLE_NAME, s.INDEX_NAME;

-- A3. The new column: employee_id INT, empty for now, next to emp_id. (emp_id stays until Part B.)
-- attendance
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `attendance` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payroll
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `payroll` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- bonus_deduction_history
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `bonus_deduction_history` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_daily
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `biometric_daily` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- leave_requests
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `leave_requests` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payslip_signatures
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `payslip_signatures` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- employee_profiles
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `employee_profiles` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- manager_employees
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `manager_employees` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- users
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `users` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_employee_map
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1,
    'ALTER TABLE `biometric_employee_map` ADD COLUMN employee_id INT NULL AFTER emp_id',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- A4. Fill employee_id in: each row gets the id of the employee whose emp_id it holds (capitals and trailing spaces are
--     ignored, as MySQL always compared them). One transaction: all of it or none of it. A row that matches nobody
--     keeps employee_id empty (NULL) - it is listed in A6. A "last changed" time that MySQL would otherwise set to now
--     (biometric_daily.uploaded_at, employee_profiles.updated_at) keeps its value.
START TRANSACTION;
-- attendance
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `attendance` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payroll
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `payroll` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- bonus_deduction_history
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `bonus_deduction_history` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_daily
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `biometric_daily` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- leave_requests
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `leave_requests` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payslip_signatures
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `payslip_signatures` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- employee_profiles
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `employee_profiles` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- manager_employees
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `manager_employees` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- users
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `users` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_employee_map
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `biometric_employee_map` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
COMMIT;

-- A5. CHECK: per table, rows, rows linked to an employee, rows NOT linked. Part B needs not_linked = 0 everywhere.
--     (users: only portal logins of employees count - the admin and managers have no employee.)
SET @sql = CONCAT_WS(' UNION ALL ',
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''attendance'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `attendance` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''payroll'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `payroll` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''bonus_deduction_history'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `bonus_deduction_history` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''biometric_daily'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `biometric_daily` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''leave_requests'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `leave_requests` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''payslip_signatures'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `payslip_signatures` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''employee_profiles'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `employee_profiles` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''manager_employees'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `manager_employees` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''users'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL AND NULLIF(TRIM(x.emp_id), '''') IS NOT NULL) AS not_linked FROM `users` x'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''biometric_employee_map'' AS table_name, COUNT(*) AS total_rows, SUM(x.employee_id IS NOT NULL) AS linked, SUM(x.employee_id IS NULL) AS not_linked FROM `biometric_employee_map` x'), NULL));
SET @sql = COALESCE(NULLIF(@sql, ''), 'SELECT ''every table already uses employee_id'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- A6. CHECK: the rows that are not linked, by the emp_id they hold, and rows that would break a unique key once linked
--     (the same employee twice in one period or one day). Expect: no rows. If there are some, see "IF A6 SHOWS ROWS".
SET @sql = CONCAT_WS(' UNION ALL ',
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''attendance'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(MIN(x.emp_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `attendance` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''attendance'' AS table_name, ''would be twice in unique key (period_id, employee_id)'' AS problem, CONVERT(MIN(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(CONCAT_WS('' / '', period_id, x.employee_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `attendance` x WHERE x.employee_id IS NOT NULL GROUP BY period_id, employee_id HAVING COUNT(*) > 1'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''payroll'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(MIN(x.emp_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `payroll` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''payroll'' AS table_name, ''would be twice in unique key (period_id, employee_id)'' AS problem, CONVERT(MIN(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(CONCAT_WS('' / '', period_id, x.employee_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `payroll` x WHERE x.employee_id IS NOT NULL GROUP BY period_id, employee_id HAVING COUNT(*) > 1'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''bonus_deduction_history'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(MIN(x.emp_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `bonus_deduction_history` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''biometric_daily'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(MIN(x.emp_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `biometric_daily` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''biometric_daily'' AS table_name, ''would be twice in unique key (period_id, employee_id, att_date)'' AS problem, CONVERT(MIN(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(CONCAT_WS('' / '', period_id, x.employee_id, att_date) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `biometric_daily` x WHERE x.employee_id IS NOT NULL GROUP BY period_id, employee_id, att_date HAVING COUNT(*) > 1'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''leave_requests'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(MIN(x.emp_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `leave_requests` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''payslip_signatures'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(MIN(x.emp_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `payslip_signatures` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''employee_profiles'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT('''' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `employee_profiles` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''employee_profiles'' AS table_name, ''would be twice in unique key (employee_id)'' AS problem, CONVERT(MIN(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(CONCAT_WS('' / '', x.employee_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `employee_profiles` x WHERE x.employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*) > 1'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''manager_employees'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT('''' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `manager_employees` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''manager_employees'' AS table_name, ''would be twice in unique key (manager_id, employee_id)'' AS problem, CONVERT(MIN(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(CONCAT_WS('' / '', manager_id, x.employee_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `manager_employees` x WHERE x.employee_id IS NOT NULL GROUP BY manager_id, employee_id HAVING COUNT(*) > 1'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''users'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(MIN(x.full_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `users` x WHERE x.employee_id IS NULL AND NULLIF(TRIM(x.emp_id), '''') IS NOT NULL GROUP BY 3'), NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1, CONCAT('SELECT ''biometric_employee_map'' AS table_name, ''no employee with this emp_id'' AS problem, CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT('''' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name_on_record, COUNT(*) AS row_count FROM `biometric_employee_map` x WHERE x.employee_id IS NULL GROUP BY 3'), NULL));
SET @sql = COALESCE(NULLIF(@sql, ''), 'SELECT ''every table already uses employee_id'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- IF A6 SHOWS ROWS - decide what they belong to, run ONE of the remedies below (remove the -- in front of its lines,
-- select them, Alt+X), then run step A4 and A5/A6 again. Nothing here deletes data.
--
--  (1) The employee was deleted, or never registered, but the records are real: put the employee back as Inactive.
--      Every listed emp_id becomes an Inactive employee (name taken from the records), and the rows link to it.
-- INSERT INTO employees (emp_id, full_name, status)
-- SELECT u.emp_id, COALESCE(MAX(NULLIF(TRIM(u.nm), '')), CONCAT('Unknown (', u.emp_id, ')')), 'Inactive'
--   FROM (SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(x.emp_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nm FROM `attendance` x WHERE x.employee_id IS NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(x.emp_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nm FROM `payroll` x WHERE x.employee_id IS NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(x.emp_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nm FROM `bonus_deduction_history` x WHERE x.employee_id IS NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(x.emp_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nm FROM `biometric_daily` x WHERE x.employee_id IS NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(x.emp_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nm FROM `leave_requests` x WHERE x.employee_id IS NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(x.emp_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nm FROM `payslip_signatures` x WHERE x.employee_id IS NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, '' AS nm FROM `employee_profiles` x WHERE x.employee_id IS NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, '' AS nm FROM `manager_employees` x WHERE x.employee_id IS NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, CONVERT(x.full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nm FROM `users` x WHERE x.employee_id IS NULL AND NULLIF(TRIM(x.emp_id), '') IS NOT NULL
--         UNION ALL SELECT CONVERT(TRIM(x.emp_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS emp_id, '' AS nm FROM `biometric_employee_map` x WHERE x.employee_id IS NULL) u
--  GROUP BY u.emp_id;
--
--  (2) The rows belong to an employee who exists under another ID (the ID was changed): point them at that employee.
--      Example for biometric_daily rows filed under 'OLD-ID' that belong to EMP-004 - repeat per table / ID:
-- UPDATE biometric_daily SET employee_id = (SELECT id FROM employees WHERE emp_id = 'EMP-004')
--  WHERE employee_id IS NULL AND emp_id = 'OLD-ID';
--
--  (3) biometric_daily only: days an old upload filed under the terminal's own number (00007 ...) instead of an
--      employee. They have no rate and were never paid. Move them aside, unchanged, into biometric_daily_unmatched.
-- CREATE TABLE IF NOT EXISTS biometric_daily_unmatched LIKE biometric_daily;
-- START TRANSACTION;
-- INSERT INTO biometric_daily_unmatched SELECT * FROM biometric_daily WHERE employee_id IS NULL;
-- DELETE FROM biometric_daily WHERE employee_id IS NULL;
-- SELECT COUNT(*) AS moved_aside FROM biometric_daily_unmatched;
-- COMMIT;

-- =====================================================================================================================
--  END OF PART A
-- =====================================================================================================================


-- =====================================================================================================================
--  PART B - the switch. Run only when A6 shows no rows. Then deploy the new version of the app.
-- =====================================================================================================================
SET SESSION group_concat_max_len = 1000000;
SET @ok = 0;

-- B1. Fill employee_id in again - rows the app wrote since Part A have none yet. (Same as A4; one transaction.)
START TRANSACTION;
-- attendance
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `attendance` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payroll
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `payroll` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- bonus_deduction_history
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `bonus_deduction_history` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_daily
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `biometric_daily` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- leave_requests
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `leave_requests` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payslip_signatures
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `payslip_signatures` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- employee_profiles
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `employee_profiles` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- manager_employees
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `manager_employees` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- users
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `users` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_employee_map
SET @keep = NULL;
SET @keep = (SELECT GROUP_CONCAT(CONCAT(', x.`', COLUMN_NAME, '` = x.`', COLUMN_NAME, '`') SEPARATOR '')
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map'
                AND EXTRA LIKE '%on update%');
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT('UPDATE `biometric_employee_map` x LEFT JOIN employees e ON CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci SET x.employee_id = e.id', COALESCE(@keep, ''), ' WHERE NOT (x.employee_id <=> e.id)'),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
COMMIT;

-- B2. The guard. Counts what would be lost: a table still without employee_id (Part A not run), rows without an
--     employee, rows that would break a unique key. Shows the numbers; @ok = 1 only when all three are 0.
SET @no_column = (SELECT COUNT(*) FROM information_schema.COLUMNS c
                    WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'emp_id' AND c.TABLE_NAME IN ('attendance', 'payroll', 'bonus_deduction_history', 'biometric_daily', 'leave_requests', 'payslip_signatures', 'employee_profiles', 'manager_employees', 'users', 'biometric_employee_map')
                      AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS d
                                       WHERE d.TABLE_SCHEMA = c.TABLE_SCHEMA AND d.TABLE_NAME = c.TABLE_NAME
                                         AND d.COLUMN_NAME = 'employee_id'));
SET @not_linked = NULL;
SET @sql = NULL;
SET @sql = CONCAT('SELECT 0 + ', COALESCE(NULLIF(CONCAT_WS(' + ',
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `attendance` x WHERE x.employee_id IS NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `payroll` x WHERE x.employee_id IS NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `bonus_deduction_history` x WHERE x.employee_id IS NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `biometric_daily` x WHERE x.employee_id IS NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `leave_requests` x WHERE x.employee_id IS NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `payslip_signatures` x WHERE x.employee_id IS NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `employee_profiles` x WHERE x.employee_id IS NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `manager_employees` x WHERE x.employee_id IS NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `users` x WHERE x.employee_id IS NULL AND NULLIF(TRIM(x.emp_id), '''') IS NOT NULL)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM `biometric_employee_map` x WHERE x.employee_id IS NULL)', NULL)), ''), '0'), ' INTO @not_linked');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @twice = NULL; SET @sql = NULL;
SET @sql = CONCAT('SELECT 0 + ', COALESCE(NULLIF(CONCAT_WS(' + ',
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM (SELECT 1 FROM `attendance` WHERE employee_id IS NOT NULL GROUP BY period_id, employee_id HAVING COUNT(*) > 1) d)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM (SELECT 1 FROM `payroll` WHERE employee_id IS NOT NULL GROUP BY period_id, employee_id HAVING COUNT(*) > 1) d)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM (SELECT 1 FROM `biometric_daily` WHERE employee_id IS NOT NULL GROUP BY period_id, employee_id, att_date HAVING COUNT(*) > 1) d)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM (SELECT 1 FROM `employee_profiles` WHERE employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*) > 1) d)', NULL),
    IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1, '(SELECT COUNT(*) FROM (SELECT 1 FROM `manager_employees` WHERE employee_id IS NOT NULL GROUP BY manager_id, employee_id HAVING COUNT(*) > 1) d)', NULL)), ''), '0'), ' INTO @twice');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok = IF(@no_column = 0 AND @not_linked = 0 AND @twice = 0, 1, 0);
SELECT IF(@ok = 1, 'yes - going ahead', 'NO - stopped, nothing below runs') AS switch_now, @no_column AS tables_without_employee_id,
       0 + @not_linked AS rows_without_employee, 0 + @twice AS rows_twice_in_a_key;
-- Stops the script here (on purpose: an error that names the problem) when the numbers above are not all 0.
SET @sql = IF(@ok = 1, 'DO 0',
    CONCAT('SELECT 1 FROM `STOP - ', COALESCE(@not_linked, 'unknown'), ' unlinked, ', COALESCE(@twice, 'unknown'), ' twice, ',
           COALESCE(@no_column, 'unknown'), ' unconverted - see A6`'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- B3. Per table, only when the guard passed (@ok = 1): drop the foreign keys that use emp_id (employee_profiles had one
--     to employees.emp_id), then in ONE statement - all of it or none of it - drop the indexes on emp_id, make
--     employee_id required (users: optional - the admin and managers have no employee), drop emp_id and add the keys
--     on employee_id. A key that is already there is not added twice.
-- attendance
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `attendance` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `attendance` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'uq_attendance_period_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'uq_attendance_period_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD UNIQUE KEY `uq_attendance_period_emp` (period_id, employee_id)', NULL),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_att_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_att_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_att_emp` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payroll
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `payroll` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `payroll` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND INDEX_NAME = 'uq_payroll_period_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND INDEX_NAME = 'uq_payroll_period_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD UNIQUE KEY `uq_payroll_period_emp` (period_id, employee_id)', NULL),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND INDEX_NAME = 'idx_payroll_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND INDEX_NAME = 'idx_payroll_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_payroll_emp` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- bonus_deduction_history
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `bonus_deduction_history` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `bonus_deduction_history` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND INDEX_NAME = 'idx_bdh_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND INDEX_NAME = 'idx_bdh_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_bdh_emp` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_daily
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `biometric_daily` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `biometric_daily` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND INDEX_NAME = 'uq_daily') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND INDEX_NAME = 'uq_daily' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD UNIQUE KEY `uq_daily` (period_id, employee_id, att_date)', NULL),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND INDEX_NAME = 'idx_daily_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND INDEX_NAME = 'idx_daily_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_daily_emp` (employee_id, att_date)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- leave_requests
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `leave_requests` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `leave_requests` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND INDEX_NAME = 'idx_leave_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND INDEX_NAME = 'idx_leave_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_leave_emp` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payslip_signatures
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `payslip_signatures` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `payslip_signatures` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND INDEX_NAME = 'idx_sig_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND INDEX_NAME = 'idx_sig_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_sig_emp` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- employee_profiles
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `employee_profiles` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `employee_profiles` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND INDEX_NAME = 'uq_profile_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND INDEX_NAME = 'uq_profile_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD UNIQUE KEY `uq_profile_emp` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- manager_employees
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `manager_employees` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `manager_employees` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND INDEX_NAME = 'uq_mgr_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND INDEX_NAME = 'uq_mgr_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD UNIQUE KEY `uq_mgr_emp` (manager_id, employee_id)', NULL),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND INDEX_NAME = 'idx_mgr_employee') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND INDEX_NAME = 'idx_mgr_employee' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_mgr_employee` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- users
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `users` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `users` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NULL DEFAULT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_employee') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_employee' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_users_employee` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_employee_map
SET @sql = NULL; SET @drops = NULL;
SET @sql = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @sql = IF(@ok = 1 AND @sql IS NOT NULL, CONCAT('ALTER TABLE `biometric_employee_map` ', @sql), 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @drops = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')
                FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id');
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1,
    CONCAT_WS(', ', CONCAT('ALTER TABLE `biometric_employee_map` ', COALESCE(CONCAT(@drops, ', '), ''), 'MODIFY employee_id INT NOT NULL, DROP COLUMN emp_id'),
        IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND INDEX_NAME = 'idx_emp') = 0 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND INDEX_NAME = 'idx_emp' AND COLUMN_NAME = 'emp_id') > 0,
        'ADD KEY `idx_emp` (employee_id)', NULL)),
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- B4. The foreign keys to employees(id). Added only once the table has no emp_id left (and only if not there already).
-- attendance
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND CONSTRAINT_NAME = 'fk_att_employee') = 0,
    'ALTER TABLE `attendance` ADD CONSTRAINT `fk_att_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payroll
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND CONSTRAINT_NAME = 'fk_payroll_employee') = 0,
    'ALTER TABLE `payroll` ADD CONSTRAINT `fk_payroll_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- bonus_deduction_history
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bonus_deduction_history' AND CONSTRAINT_NAME = 'fk_bdh_employee') = 0,
    'ALTER TABLE `bonus_deduction_history` ADD CONSTRAINT `fk_bdh_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_daily
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_daily' AND CONSTRAINT_NAME = 'fk_daily_employee') = 0,
    'ALTER TABLE `biometric_daily` ADD CONSTRAINT `fk_daily_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- leave_requests
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND CONSTRAINT_NAME = 'fk_leave_employee') = 0,
    'ALTER TABLE `leave_requests` ADD CONSTRAINT `fk_leave_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- payslip_signatures
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_signatures' AND CONSTRAINT_NAME = 'fk_sig_employee') = 0,
    'ALTER TABLE `payslip_signatures` ADD CONSTRAINT `fk_sig_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- employee_profiles
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles' AND CONSTRAINT_NAME = 'fk_profile_employee') = 0,
    'ALTER TABLE `employee_profiles` ADD CONSTRAINT `fk_profile_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- manager_employees
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'manager_employees' AND CONSTRAINT_NAME = 'fk_mgr_employee') = 0,
    'ALTER TABLE `manager_employees` ADD CONSTRAINT `fk_mgr_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- users
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_employee') = 0,
    'ALTER TABLE `users` ADD CONSTRAINT `fk_users_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- biometric_employee_map
SET @sql = IF(@ok = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'employee_id') = 1 AND NOT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND COLUMN_NAME = 'emp_id') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'biometric_employee_map' AND CONSTRAINT_NAME = 'fk_biomap_employee') = 0,
    'ALTER TABLE `biometric_employee_map` ADD CONSTRAINT `fk_biomap_employee` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- B5. Result: every table that points at an employee, its key column and its foreign key. Expect ten rows, all with
--     employee_id and a foreign key to employees (id), and no emp_id column left in any of them.
SELECT c.TABLE_NAME AS table_name,
       GROUP_CONCAT(DISTINCT c.COLUMN_NAME) AS employee_columns,
       (SELECT GROUP_CONCAT(CONCAT(k.CONSTRAINT_NAME, ' -> ', k.REFERENCED_TABLE_NAME, '(', k.REFERENCED_COLUMN_NAME, ') ON DELETE ', r.DELETE_RULE, ' ON UPDATE ', r.UPDATE_RULE))
          FROM information_schema.KEY_COLUMN_USAGE k
          JOIN information_schema.REFERENTIAL_CONSTRAINTS r
            ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
         WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = c.TABLE_NAME AND k.REFERENCED_TABLE_NAME = 'employees') AS foreign_key
  FROM information_schema.COLUMNS c
 WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME IN ('attendance', 'payroll', 'bonus_deduction_history', 'biometric_daily', 'leave_requests', 'payslip_signatures', 'employee_profiles', 'manager_employees', 'users', 'biometric_employee_map')
   AND c.COLUMN_NAME IN ('emp_id', 'employee_id')
 GROUP BY c.TABLE_NAME
 ORDER BY c.TABLE_NAME;

-- =====================================================================================================================
--  END OF PART B
-- =====================================================================================================================


-- =====================================================================================================================
--  PART C - checks after the switch, and the clean-up
-- =====================================================================================================================

-- C1. Every row of the backup copies is still in its table and points at the employee with the same emp_id.
--     Expect missing_now = 0 and other_employee_now = 0 on every line. (Rows added since the backup are not counted;
--     rows moved aside by remedy (3) show as missing in biometric_daily - they are in biometric_daily_unmatched.)
SELECT 'attendance' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_attendance` b LEFT JOIN `attendance` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'payroll' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_payroll` b LEFT JOIN `payroll` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'bonus_deduction_history' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_bonus_deduction_history` b LEFT JOIN `bonus_deduction_history` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'biometric_daily' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_biometric_daily` b LEFT JOIN `biometric_daily` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'leave_requests' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_leave_requests` b LEFT JOIN `leave_requests` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'payslip_signatures' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_payslip_signatures` b LEFT JOIN `payslip_signatures` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'employee_profiles' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_employee_profiles` b LEFT JOIN `employee_profiles` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'manager_employees' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_manager_employees` b LEFT JOIN `manager_employees` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'users' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`id` IS NULL) AS missing_now,
       SUM(x.`id` IS NOT NULL AND NULLIF(TRIM(b.emp_id), '') IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_users` b LEFT JOIN `users` x ON x.`id` = b.`id` LEFT JOIN employees e ON e.id = x.employee_id
UNION ALL
SELECT 'biometric_employee_map' AS table_name, COUNT(*) AS rows_in_backup, SUM(x.`device_user_id` IS NULL) AS missing_now,
       SUM(x.`device_user_id` IS NOT NULL AND NOT COALESCE(CONVERT(e.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(b.emp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci, FALSE)) AS other_employee_now
  FROM `bak_emp_biometric_employee_map` b LEFT JOIN `biometric_employee_map` x ON x.`device_user_id` = b.`device_user_id` LEFT JOIN employees e ON e.id = x.employee_id;

-- C2. When C1 is all 0 and the app has been used for a while without trouble: remove the backup copies.
--     (Keep the full backup from step 0.) Remove the -- in front, select, Alt+X.
-- DROP TABLE IF EXISTS `bak_emp_employees`;
-- DROP TABLE IF EXISTS `bak_emp_attendance`;
-- DROP TABLE IF EXISTS `bak_emp_payroll`;
-- DROP TABLE IF EXISTS `bak_emp_bonus_deduction_history`;
-- DROP TABLE IF EXISTS `bak_emp_biometric_daily`;
-- DROP TABLE IF EXISTS `bak_emp_leave_requests`;
-- DROP TABLE IF EXISTS `bak_emp_payslip_signatures`;
-- DROP TABLE IF EXISTS `bak_emp_employee_profiles`;
-- DROP TABLE IF EXISTS `bak_emp_manager_employees`;
-- DROP TABLE IF EXISTS `bak_emp_users`;
-- DROP TABLE IF EXISTS `bak_emp_biometric_employee_map`;
-- DROP TABLE IF EXISTS bak_emp_foreign_keys;
-- DROP TABLE IF EXISTS bak_emp_indexes;

-- =====================================================================================================================
--  END OF PART C
-- =====================================================================================================================
