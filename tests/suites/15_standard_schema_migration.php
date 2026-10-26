<?php
/*
 * 15 - The database change of 2026-10-15 (standard layout): payroll2/sql/migrate-standard-schema.sql converts the live database from
 * the layout in which every table points at employees.id (employee_id) to the standard layout of sql/database.sql - first_name /
 * last_name, branches / departments / positions lists, users with Admin / Manager / Employee and user_scopes, who-did-it as a link to
 * the account, one row per employee per day, payroll with its own basic / overtime / deduction columns. This suite runs that file the
 * way DBeaver does - statement by statement, every error IGNORED and the run carried on - on the layout the live database had
 * (tests/schema_before_standard.sql) filled with data that contains every awkward case, and proves:
 *
 *   A  it runs without one error, every check passes, the old tables are gone, and what is left is EXACTLY the layout every other
 *      test runs on (tests/schema.sql) - run the way the live server runs SQL (ANSI quotes, strict)
 *   B  employees: same id and code, the name split into first and last name (particles, suffix, "LAST, First", one word, extra
 *      spaces), branch and position as links into the lists, the profile merged in, an e-mail typed twice kept once
 *   C  accounts: the three roles, what a manager handles as user_scopes (Global / Branch / the employees picked), and every "who did
 *      it" found by name - the right account when two accounts share a name, empty when nobody has that name
 *   D  the money and the history: every pay line with the same id and the same peso (basic, overtime, deductions, net), attendance
 *      with the counts from the pay line, days kept once per employee per date (the latest upload), leave, bonuses, print log,
 *      signature, receipt numbers, audit trail, device numbers
 *   E  running the file again changes nothing, and on an empty database it stops with a message
 *   F  when a check fails the old tables are NOT deleted, and a half-converted database is not touched again
 *   G  Part E (the backup copies of the earlier update) only runs once its dashes are removed
 *   H  the application reads the converted data: register, payslip, employees, every manager's scope, the employee portal - and
 *      adds to the layout only the five employee columns of the pay schedule and the contribution schedule (a patch of 2026-10-24),
 *      giving every employee what the old Settings said
 */

const QA15_DB   = 'qa_std';
const QA15_FAIL = 'qa_std_fail';
const QA15_LEFTOVERS = ['bak_emp_employees', 'users_unmatched'];
/** the columns of employees that the application's own patch list adds after the migration (the migration itself does not know them) */
const QA15_PATCH_COLUMNS = ['overtime_rate', 'pay_schedule', 'sss_timing', 'philhealth_timing', 'pagibig_timing', 'tax_timing'];
/** the tables the application's own patch list creates after the migration (the signatures of 2026-10-26; suite 17 test D shows them arriving) */
const QA15_PATCH_TABLES = ['adjustment_signatures', 'admin_signature'];
const QA15_STANDARD_TABLES = ['settings', 'branches', 'departments', 'positions', 'employees', 'users', 'user_scopes', 'payroll_periods', 'biometric_daily',
                              'attendance_summary', 'leave_requests', 'bonus_deductions', 'payroll', 'payslip_signatures', 'print_logs', 'period_audit',
                              'login_attempts', 'document_series', 'document_serials', 'attendance_logs', 'biometric_employee_map', 'biometric_agent_state',
                              'biometric_api_keys'];

/** a fresh database in the layout the live database had before the change (the way MySQL 8 creates it), the app's database user allowed in */
function qa15_database(string $name): PDO
{
    $root = TestDb::root();
    $root->exec("DROP DATABASE IF EXISTS $name");
    $root->exec("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    foreach (['127.0.0.1', 'localhost', '%'] as $h) $root->exec("GRANT ALL ON $name.* TO '" . TestDb::USER . "'@'$h'");
    $db = TestDb::root($name);
    $sql = (string)file_get_contents(dirname(__DIR__) . '/schema_before_standard.sql');
    foreach (preg_split('/^--;;\s*$/m', $sql) as $stmt) {
        $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
        if ($stmt !== '') $db->exec($stmt);
    }
    return $db;
}

/**
 * The data. Nine employees whose ids are NOT the numbers in their codes, in three pay periods, two overlapping periods that both hold the
 * same days, the three kinds of account, and an actor name for every case: one account, two accounts with the same name, and nobody.
 * Returns the pay lines it wrote (by pay line id) so the checks can work the expected pesos out themselves.
 */
function qa15_seed(PDO $db): array
{
    // (the two contribution timings are Settings of the layout the live database had; the application gives them to every employee on its first page)
    $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('payroll_period', 'Semi-Monthly'), ('company_name', 'QA Pharmacy'),
               ('contribution_timing_sss', 'split'), ('contribution_timing_tax', 'first')");
    $db->exec("INSERT INTO branches (id, name) VALUES (1, 'MAIN'), (2, 'NORTH'), (3, 'SOUTH')");

    $emp = $db->prepare("INSERT INTO employees (id, emp_id, full_name, `position`, branch, email, base_salary, salary_type, date_hired, status, rest_days, hours_per_day,
                                                sss_amount, philhealth_amount, pagibig_amount, tax_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ([
        [11, 'EMP-001', 'ANA REYES',        'Pharmacist',   'MAIN',  'ana@qa.test',     15000, 'monthly',  '2020-01-15', 'Active',   '7',   null,  990.00, 450.00, 200.00, 125.75],
        [12, 'EMP-002', 'BEN CRUZ',         'Cashier',      'MAIN',  '',                  520, 'daily',    '2022-03-01', 'Active',   '7',   8.00,    0,      0,      0,      0],
        [15, 'EMP-003', 'CARLA   DIAZ',     ' Pharmacist ', 'NORTH', 'carla@qa.test',    7500, 'kinsenas', null,         'Active',   '6,7', null,    0,      0,      0,      0],
        [17, 'EMP-004', 'JUAN DELA CRUZ',   'cashier',      'NORTH', 'ANA@QA.TEST',       500, 'daily',    '2023-06-12', 'Active',   '7',   8.00,    0,      0,      0,      0],
        [23, 'EMP-005', 'HASMIN DIEGA',     '',             '',      null,                600, 'daily',    null,         'Active',   '7',   10.00,   540.00, 0,      0,      0],
        [30, 'EMP-006', 'FE SANTOS JR.',    'Stock Clerk',  'EAST',  '',                  480, 'daily',    null,         'Inactive', '7',   null,    0,      0,      0,      0],
        [31, 'EMP-007', 'DELA CRUZ, PEDRO', 'Cashier',      'MAIN',  'pedro@qa.test',     490, 'daily',    null,         'Active',   '7',   null,    0,      0,      0,      0],
        [32, 'EMP-008', 'MADONNA',          '',             'MAIN',  '',                  450, 'daily',    null,         'Active',   '7',   null,    0,      0,      0,      0],
        [33, 'EMP-009', 'MARIA DE LA CRUZ', 'Pharmacist',   'MAIN',  '',                 9000, 'kinsenas', null,         'Active',   '7',   null,    0,      0,      0,      0],
    ] as $e) $emp->execute($e);

    $db->exec("INSERT INTO employee_profiles (employee_id, address, phone, emergency_name, emergency_phone, emergency_relation, updated_at) VALUES
        (11, 'Calbayog City', '0917 111 2222', 'Maria Reyes', '0918 333 4444', 'Mother', '2026-03-01 09:00:00'),
        (15, '', ' ', NULL, '', NULL, '2026-03-02 10:15:00'),
        (17, 'Catubig', '0918 555 6666', 'Rosa Lim', '0920 777 8888', 'Wife', '2026-03-03 11:30:00')");

    $db->exec("INSERT INTO users (id, full_name, email, password_hash, role, employee_id, branch, scope_type, status, last_login, created_at) VALUES
        (1, 'Admin',          'admin@qa',   'h1', 'admin',   NULL, '',      'branch', 'Active',   '2026-04-10 08:00:00', '2026-01-05 08:00:00'),
        (2, 'QA Manager',     'mgr@qa',     'h2', 'manager', NULL, '',      'custom', 'Active',   NULL,                  '2026-01-06 08:00:00'),
        (3, 'ANA REYES',      'ana@qa',     'h3', 'earner',  11,   '',      'branch', 'Active',   '2026-04-01 08:00:00', '2026-01-07 08:00:00'),
        (4, 'JUAN DELA CRUZ', 'juan@qa',    'h4', 'earner',  17,   '',      'branch', 'Inactive', NULL,                  '2026-01-08 08:00:00'),
        (5, 'North Manager',  'north@qa',   'h5', 'manager', NULL, 'NORTH', 'branch', 'Active',   NULL,                  '2026-01-09 08:00:00'),
        (6, 'Global Manager', 'glob@qa',    'h6', 'manager', NULL, '',      'branch', 'Active',   NULL,                  '2026-01-10 08:00:00'),
        (7, 'ANA REYES',      'ana.mgr@qa', 'h7', 'manager', NULL, 'MAIN',  'branch', 'Active',   NULL,                  '2026-01-11 08:00:00')");
    $db->exec("INSERT INTO manager_employees (manager_id, employee_id) VALUES (2, 11), (2, 17)");

    $db->exec("INSERT INTO payroll_periods (id, period_label, period_start, period_end, period_type, status, finalized_at, finalize_count, reopen_count, reopened_at) VALUES
        (1, 'Mar 1-15, 2026',  '2026-03-01', '2026-03-15', 'Semi-Monthly', 'Locked', '2026-03-16 09:00:00', 1, 0, NULL),
        (2, 'Mar 16-31, 2026', '2026-03-16', '2026-03-31', NULL,           'Locked', '2026-04-01 09:00:00', 2, 1, '2026-03-31 17:00:00'),
        (3, 'Apr 1-15, 2026',  '2026-04-01', '2026-04-15', 'Semi-Monthly', 'Open',   NULL, 0, 0, NULL),
        (4, 'April 2026',      '2026-04-01', '2026-04-30', '',             'Open',   NULL, 0, 0, NULL),
        (5, 'Apr 6-12, 2026',  '2026-04-06', '2026-04-12', NULL,           'Open',   NULL, 0, 0, NULL),
        (6, 'May 2026',        '2026-05-01', '2026-05-31', 'Monthly',      'Open',   NULL, 0, 0, NULL)");

    // pay lines: the old layout's own columns (gross_pay already holds the overtime / late adjustment, net_pay = gross + bonus - SSS - PhilHealth - Pag-IBIG - tax - other)
    $defaults = ['hours' => 88, 'ot' => 0, 'late' => 0, 'tax' => 0, 'adj' => 0, 'sss' => 0, 'ph' => 0, 'pi' => 0, 'bonus' => 0, 'other' => 0, 'absent_days' => 0,
                 'leave_days' => 0, 'absent_ded' => 0, 'ut_hours' => 0, 'ut_ded' => 0, 'days_off' => 0, 'status' => 'Finalized', 'revised' => 0];
    $made = ['1' => '2026-03-14 12:00:00', '2' => '2026-03-30 12:00:00', '3' => '2026-04-04 18:31:00'];
    $pay = $db->prepare("INSERT INTO payroll (period_id, employee_id, emp_name, hours_worked, overtime_hours, late_hours, gross_pay, withholding_tax, ot_late_adj, sss, philhealth, pagibig,
                         bonus, other_deductions, net_pay, status, created_at, revised_after_finalize, revised_at, absent_days, leave_days, absent_deduction, gross_incl_ot,
                         undertime_hours, undertime_deduction, days_off) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)");
    $att = $db->prepare("INSERT INTO attendance (period_id, employee_id, emp_name, hours_worked, overtime_hours, late_hours, gross_pay, withholding_tax, upload_date, manually_entered_by,
                         manager_approved, approved_by, approved_at, gross_incl_ot) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    $lines = [];
    foreach ([
        ['p' => 1, 'e' => 11, 'name' => 'ANA REYES',     'gross' => 7500.00, 'sss' => 495.00, 'bonus' => 1000.00],
        ['p' => 1, 'e' => 12, 'name' => 'BEN CRUZ',      'gross' => 5720.00, 'adj' => 45.00, 'ot' => 3, 'manual' => 'QA Manager'],
        ['p' => 1, 'e' => 15, 'name' => 'CARLA DIAZ',    'gross' => 7500.00, 'adj' => -120.50, 'late' => 2, 'ph' => 405.00, 'other' => 150.25],
        ['p' => 1, 'e' => 23, 'name' => 'HASMIN DIEGA',  'gross' => 6600.00, 'adj' => 120.50, 'sss' => 540.00],
        ['p' => 1, 'e' => 30, 'name' => 'FE SANTOS JR.', 'gross' => 4800.00],
        ['p' => 2, 'e' => 11, 'name' => 'ANA REYES',     'gross' => 7500.00, 'pi' => 250.00, 'other' => 200.00, 'tax' => 125.75],
        ['p' => 2, 'e' => 12, 'name' => 'BEN CRUZ',      'gross' => 6240.00, 'adj' => 90.00, 'other' => 200.50, 'revised' => 1],
        ['p' => 2, 'e' => 15, 'name' => 'CARLA DIAZ',    'gross' => 7500.00],
        ['p' => 2, 'e' => 23, 'name' => 'HASMIN DIEGA',  'gross' => 7200.00, 'adj' => 62.00, 'pi' => 250.00],
        ['p' => 3, 'e' => 11, 'name' => 'ANA REYES',     'gross' => 2000.00, 'sss' => 495.00, 'absent_days' => 1, 'absent_ded' => 681.82, 'ut_hours' => 1.5, 'ut_ded' => 93.75,
                                                         'days_off' => 2, 'leave_days' => 1, 'status' => 'Draft'],
        ['p' => 3, 'e' => 12, 'name' => 'BEN CRUZ',      'gross' => 2080.00, 'status' => 'Draft', 'no_attendance' => 1],
    ] as $i => $o) {
        $o += $defaults;
        $net = round($o['gross'] + $o['bonus'] - ($o['sss'] + $o['ph'] + $o['pi'] + $o['tax'] + $o['other']), 2);
        $when = $made[(string)$o['p']];
        $pay->execute([$o['p'], $o['e'], $o['name'], $o['hours'], $o['ot'], $o['late'], $o['gross'], $o['tax'], $o['adj'], $o['sss'], $o['ph'], $o['pi'], $o['bonus'], $o['other'], $net,
                       $o['status'], $when, $o['revised'], $o['revised'] ? '2026-04-02 10:00:00' : null, $o['absent_days'], $o['leave_days'], $o['absent_ded'],
                       $o['ut_hours'], $o['ut_ded'], $o['days_off']]);
        $o['id'] = (int)$db->lastInsertId();
        $o['net'] = $net;
        $lines[$o['id']] = $o;
        if (empty($o['no_attendance'])) {
            $state = $i % 3;                                                  // 0 pending, 1 approved, 2 flagged
            $att->execute([$o['p'], $o['e'], $o['name'], $o['hours'], $o['ot'], $o['late'], $o['gross'], $o['tax'], $when, $o['manual'] ?? null, $state,
                           $state === 1 ? 'QA Manager' : ($state === 2 ? 'Ghost User' : null), $state ? '2026-04-05 09:00:00' : null]);
        }
    }

    // days: ANA's and BEN's first four of April in the half month, ANA's first two again in the whole month (later, other hours), a week for BEN
    $day = $db->prepare("INSERT INTO biometric_daily (id, period_id, employee_id, emp_name, att_date, hours_worked, overtime_hours, late_hours, undertime_hours, entered_by, day_off, uploaded_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach (['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04'] as $k => $d) {
        $day->execute([101 + $k, 3, 11, 'ANA REYES', $d, 8, 0, 0, null, null, 0, '2026-04-04 18:30:00']);
        $day->execute([105 + $k, 3, 12, 'BEN CRUZ', $d, $k === 1 ? 0 : 8.5, $k === 2 ? 1.5 : 0, $k === 2 ? 0.25 : 0, $k === 3 ? 1 : null,
                       $k === 0 ? 'QA Manager' : ($k === 2 ? 'ANA REYES' : null), $k === 1 ? 1 : 0, '2026-04-04 18:30:00']);
    }
    $day->execute([201, 4, 11, 'ANA REYES', '2026-04-01', 7.5, 0, 0, null, 'QA Manager', 0, '2026-04-20 09:00:00']);
    $day->execute([202, 4, 11, 'ANA REYES', '2026-04-02', 7.5, 0, 0, null, 'QA Manager', 0, '2026-04-20 09:00:00']);
    $day->execute([301, 5, 12, 'BEN CRUZ', '2026-04-06', 8, 0, 0, null, null, 0, '2026-04-13 08:00:00']);
    $day->execute([302, 5, 12, 'BEN CRUZ', '2026-04-07', 8, 0, 0, null, null, 0, '2026-04-13 08:00:00']);

    $db->exec("INSERT INTO leave_requests (id, employee_id, emp_name, leave_type, date_from, date_to, reason, status, reviewed_by, review_note, reviewed_at, created_at) VALUES
        (1, 12, 'BEN CRUZ',     'Vacation',   '2026-04-06', '2026-04-07', 'Family',  'Approved', 'QA Manager',   'Enjoy',    '2026-04-02 10:00:00', '2026-04-01 09:00:00'),
        (2, 15, 'CARLA DIAZ',   'Sick Leave', '2026-04-10', '2026-04-10', NULL,      'Pending',  NULL,           NULL,       NULL,                  '2026-04-08 09:00:00'),
        (3, 23, 'HASMIN DIEGA', 'Emergency',  '2026-04-14', '2026-04-15', 'Typhoon', 'Rejected', 'Nobody Known', 'No cover', '2026-04-13 15:00:00', '2026-04-12 09:00:00')");
    $db->exec("INSERT INTO bonus_deduction_history (id, entry_date, employee_id, emp_name, entry_type, amount, reason, processed_by, period_id, finalize_cycle, created_at) VALUES
        (1, '2026-03-14', 11, 'ANA REYES',     'Bonus',     1000.00, 'Performance bonus',   'Admin',        1,    0, '2026-03-14 10:00:00'),
        (2, '2026-03-30', 12, 'BEN CRUZ',      'Deduction',  200.50, 'Cash advance',        'QA Manager',   2,    0, '2026-03-30 10:00:00'),
        (3, '2025-12-20', 30, 'FE SANTOS JR.', 'Bonus',     2400.00, '13th Month Pay 2025', 'ANA REYES',    NULL, 0, '2025-12-20 10:00:00'),
        (4, '2026-04-02', 15, 'CARLA DIAZ',    'Deduction',   75.00, 'Uniform',             'Someone Else', 3,    1, '2026-04-02 10:00:00')");
    $db->exec("INSERT INTO print_log (id, document_name, document_type, period_id, printed_by, log_datetime) VALUES
        (1, 'Payroll Register - Mar 1-15, 2026',  'Physical Print',        1,    'Admin',      '2026-03-16 09:30:00'),
        (2, 'Payslip - ANA REYES',                'Employee Self-Service', 1,    'ANA REYES',  '2026-03-17 08:00:00'),
        (3, 'Payroll Register - Mar 16-31, 2026', 'Physical Print',        2,    'ANA REYES',  '2026-04-01 09:30:00'),
        (4, 'Leave history',                      'PDF Export',            NULL, 'Ghost User', '2026-04-03 09:30:00')");

    $first = (int)$db->query("SELECT id FROM payroll WHERE period_id = 1 AND employee_id = 11")->fetchColumn();
    $second = (int)$db->query("SELECT id FROM payroll WHERE period_id = 1 AND employee_id = 12")->fetchColumn();
    $third = (int)$db->query("SELECT id FROM payroll WHERE period_id = 1 AND employee_id = 15")->fetchColumn();
    $db->exec("INSERT INTO payslip_signatures (id, payroll_id, employee_id, emp_name, period_id, period_label, signature_data, net_signed, signed_at)
               VALUES (7, $first, 11, 'ANA REYES', 1, 'Mar 1-15, 2026', 'data:image/png;base64,AAAA', 8005.00, '2026-03-17 08:05:00')");
    $db->exec("INSERT INTO document_series (series, next_no) VALUES ('PAYSLIP', 4)");
    $db->exec("INSERT INTO document_serials (series, ref_id, serial_no, issued_at) VALUES
        ('PAYSLIP', $first, 1, '2026-03-17 08:00:00'), ('PAYSLIP', $second, 2, '2026-03-17 08:10:00'), ('PAYSLIP', $third, 3, '2026-03-16 09:00:00')");
    $db->exec("INSERT INTO period_audit (period_id, action, cycle, performed_by, note, created_at) VALUES
        (1,  'Finalized', 1, 'Admin',      '',                              '2026-03-16 09:00:00'),
        (2,  'Finalized', 1, 'QA Manager', '',                              '2026-04-01 09:00:00'),
        (2,  'Reopened',  1, 'Ghost User', 'typo',                          '2026-04-02 09:00:00'),
        (99, 'Finalized', 1, 'Admin',      'its period was deleted long ago', '2026-01-01 09:00:00')");
    $db->exec("INSERT INTO biometric_employee_map (device_user_id, employee_id, device_id) VALUES ('00001', 11, 'A6'), ('00004', 17, 'A6')");
    $db->exec("INSERT INTO attendance_logs (employee_id, punch_time, punch_state, device_id) VALUES ('00001', '2026-04-01 07:55:00', 0, 'A6'), ('00001', '2026-04-01 17:05:00', 1, 'A6')");
    $db->exec("INSERT INTO login_attempts (ip, email, ok) VALUES ('127.0.0.1', 'admin@qa', 1), ('127.0.0.1', 'x@qa', 0)");
    $db->exec("INSERT INTO biometric_api_keys (key_hash, label, device_id) VALUES (SHA2('k', 256), 'QA agent', 'A6')");
    $db->exec("INSERT INTO biometric_agent_state (device_id, agent_host, agent_version) VALUES ('A6', 'PC1', '1.0')");

    // what the earlier update left behind (it is not part of the layout; PART E of the script removes it on request)
    $db->exec('CREATE TABLE bak_emp_employees AS SELECT * FROM employees');
    $db->exec('CREATE TABLE users_unmatched (id INT NOT NULL PRIMARY KEY, email VARCHAR(100) NOT NULL)');
    $db->exec("INSERT INTO users_unmatched (id, email) VALUES (900, 'old@demo')");
    return $lines;
}

/** every row of a table keyed by its primary key, every column as MySQL returns it */
function qa15_rows(PDO $db, string $table, string $pk = 'id'): array
{
    $out = [];
    foreach ($db->query("SELECT * FROM `$table` ORDER BY `$pk`")->fetchAll() as $r) $out[(string)$r[$pk]] = $r;
    return $out;
}

/** every table of the database, rows and all, in an order that does not depend on how the rows were written */
function qa15_snapshot(PDO $db): array
{
    $out = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $rows = $db->query("SELECT * FROM `$t`")->fetchAll();
        usort($rows, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
        $out[$t] = $rows;
    }
    return $out;
}

/** what a schema is made of: every column, index and foreign key as a text line (the leftover tables of the earlier update left out) */
function qa15_signature(PDO $root, string $schema): array
{
    $out = [];
    $q = fn(string $sql) => (function () use ($root, $sql, $schema) { $st = $root->prepare($sql); $st->execute([$schema]); return $st->fetchAll(PDO::FETCH_NUM); })();
    foreach ($q("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, 'NULL'), COALESCE(GENERATION_EXPRESSION, ''), COALESCE(COLLATION_NAME, ''), EXTRA
                   FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, COLUMN_NAME") as $r) $out[] = 'column ' . implode(' | ', $r);
    foreach ($q("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
                   FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME") as $r) $out[] = 'index ' . implode(' | ', $r);
    foreach ($q("SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION), k.REFERENCED_TABLE_NAME,
                        GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION), r.UPDATE_RULE, r.DELETE_RULE
                   FROM information_schema.KEY_COLUMN_USAGE k
                   JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                  WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
                  GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME") as $r) $out[] = 'foreign key ' . implode(' | ', $r);
    foreach ($q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $r) $out[] = 'table ' . $r[0];
    /* the leftover tables of the earlier update are left out - and so are the five employee columns the application adds itself on its first page
       (the pay schedule and the contribution schedule, a patch of 2026-10-24): the migration does not create them, test H shows them arriving */
    $patched = '/^column employees \| (' . implode('|', QA15_PATCH_COLUMNS) . ') \|/';
    /* and the two signature tables the patch list creates (a patch of 2026-10-26) */
    $patchedTables = '/^(column|index|foreign key|table) (' . implode('|', QA15_PATCH_TABLES) . ')\b/';
    return array_values(array_filter($out, fn($l) => !preg_match('/^(column|index|foreign key|table) (' . implode('|', QA15_LEFTOVERS) . ')\b/', $l)
                                                   && !preg_match($patched, $l) && !preg_match($patchedTables, $l)));
}

/** the migration file, whole */
function qa15_script(): string
{
    static $file = null;
    return $file ??= (string)file_get_contents(AppCopy::root() . '/sql/migrate-standard-schema.sql');
}

/** the file cut at its PART headings: [A and B (build and copy), C and D (checks and removal of the old tables), E (optional)] */
function qa15_parts(): array
{
    $lines = preg_split('/\R/', qa15_script());
    $c = $e = null;
    foreach ($lines as $i => $l) {
        if ($c === null && strpos($l, 'PART C - checks') !== false) $c = $i - 1;                 // the dashed line above the heading
        if ($e === null && strpos($l, 'PART E (optional') !== false) $e = $i - 1;
    }
    if ($c === null || $e === null) throw new RuntimeException('PART C / PART E headings not found');
    return [implode("\n", array_slice($lines, 0, $c)), implode("\n", array_slice($lines, $c, $e - $c)), implode("\n", array_slice($lines, $e))];
}

/** the live server runs SQL like this (ANSI quotes, strict) - the migration must not depend on MySQL's default mode */
const QA15_LIVE_MODE = 'ANSI_QUOTES,PIPES_AS_CONCAT,ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES';

function qa15_money($v): string { return number_format((float)$v, 2, '.', ''); }

T::suite('15 · The standard-layout migration (sql/migrate-standard-schema.sql)', function () {
    $S = new stdClass();     // what one test leaves for the next

    T::test('A - the whole file runs without one error (every error ignored would show), every check passes, the old tables are gone and what is left is exactly the tested layout', function (T $t) use ($S) {
        if (TestDb::flavor() !== 'mysql') T::skip('the migration is written for MySQL 8');
        $db = qa15_database(QA15_DB);
        $S->lines = qa15_seed($db);
        $S->db = $db;
        $S->old = [];
        foreach (['employees', 'users', 'payroll', 'attendance', 'biometric_daily', 'leave_requests', 'bonus_deduction_history', 'print_log', 'payslip_signatures', 'period_audit',
                  'employee_profiles', 'payroll_periods'] as $tbl) $S->old[$tbl] = qa15_rows($db, $tbl);
        $S->oldSerials = $db->query('SELECT * FROM document_serials ORDER BY issued_at, series, ref_id')->fetchAll();
        $S->oldMap = $db->query('SELECT * FROM biometric_employee_map ORDER BY device_user_id')->fetchAll();
        $S->oldLogs = $db->query('SELECT employee_id, punch_time FROM attendance_logs ORDER BY id')->fetchAll();
        $t->same(12, count($S->old['biometric_daily']), 'the seed has 12 saved days, two of them saved twice');

        $db->exec("SET SESSION sql_mode = '" . QA15_LIVE_MODE . "'");
        $r = SqlScript::run($db, qa15_script());                                  // DBeaver, Alt+X, "Ignore all"
        $db->exec("SET SESSION sql_mode = DEFAULT");
        $t->same([], array_map(fn($e) => substr($e[1], 0, 300) . ' <- ' . substr(preg_replace('/\s+/', ' ', $e[0]), 0, 160), $r['errors']), 'no error at all');

        $status = SqlScript::resultWith($r, ['migration_status']);
        $t->contains('The older layout was found', $status[0]['migration_status'] ?? '', 'it recognised the older layout');
        $verdict = SqlScript::resultWith($r, ['check_verdict', 'checks_failed']);
        $t->contains('PASSED', $verdict[0]['check_verdict'] ?? '', 'the checks: ' . json_encode($verdict));
        $t->same(0, (int)($verdict[0]['checks_failed'] ?? -1));
        $checks = SqlScript::resultWith($r, ['check', 'expected', 'found', 'result']);
        $t->same(22, count($checks ?? []), 'twenty-two checks are listed');
        foreach ($checks ?? [] as $c) $t->same('ok', $c['result'], 'check ' . $c['check'] . ' (expected ' . $c['expected'] . ', found ' . $c['found'] . ')');
        $done = SqlScript::resultWith($r, ['migration_result']);
        $t->contains('Done', $done[0]['migration_result'] ?? '', 'the last message');
        $t->ok(SqlScript::resultWith($r, ['employee_code', 'name_before', 'first_name', 'last_name']) !== null, 'the name report is shown for review');
        $t->ok(SqlScript::resultWith($r, ['manager', 'scope_type', 'branch']) !== null, 'the manager report is shown for review');

        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $expect = array_merge(QA15_STANDARD_TABLES, QA15_LEFTOVERS);
        sort($expect);
        $t->same($expect, $tables, 'the 23 standard tables, plus the two leftover tables of the earlier update (PART E is not run)');
        $root = TestDb::root();
        $a = qa15_signature($root, TestDb::DB);
        $b = qa15_signature($root, QA15_DB);
        $t->ok(count($a) > 250, 'the comparison saw the columns, indexes and foreign keys (' . count($a) . ' lines)');
        $t->same([], array_values(array_diff($a, $b)), 'in the tested layout but not in the converted database (or different)');
        $t->same([], array_values(array_diff($b, $a)), 'in the converted database but not in the tested layout (or different)');
        $S->signature = $b;
    });

    T::test('B - employees: same id and code, the name split (particles, suffix, "LAST, First", one word, extra spaces), branch and position as links, the profile merged in, an e-mail typed twice kept once', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        $now = qa15_rows($db, 'employees');
        $t->same(array_keys($S->old['employees']), array_keys($now), 'the same employees with the same ids');
        $names = ['11' => ['ANA', 'REYES'], '12' => ['BEN', 'CRUZ'], '15' => ['CARLA', 'DIAZ'], '17' => ['JUAN', 'DELA CRUZ'], '23' => ['HASMIN', 'DIEGA'],
                  '30' => ['FE', 'SANTOS JR.'], '31' => ['PEDRO', 'DELA CRUZ'], '32' => ['MADONNA', ''], '33' => ['MARIA', 'DE LA CRUZ']];
        $full = ['11' => 'ANA REYES', '12' => 'BEN CRUZ', '15' => 'CARLA DIAZ', '17' => 'JUAN DELA CRUZ', '23' => 'HASMIN DIEGA', '30' => 'FE SANTOS JR.',
                 '31' => 'PEDRO DELA CRUZ', '32' => 'MADONNA', '33' => 'MARIA DE LA CRUZ'];
        foreach ($now as $id => $e) {
            $old = $S->old['employees'][$id];
            $t->same($names[$id], [$e['first_name'], $e['last_name']], "#$id {$old['full_name']}: first and last name");
            $t->same($full[$id], $e['full_name'], "#$id reads as " . $full[$id]);
            $t->same($old['emp_id'], $e['employee_code'], "#$id keeps its number");
            foreach (['base_salary', 'salary_type', 'status', 'date_hired', 'rest_days', 'hours_per_day', 'sss_amount', 'philhealth_amount', 'pagibig_amount', 'tax_amount'] as $c) {
                $t->same($old[$c], $e[$c], "#$id $c unchanged");
            }
        }
        // the lists
        $branches = $db->query('SELECT name FROM branches ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
        $t->same(['EAST', 'MAIN', 'NORTH', 'SOUTH'], $branches, 'the branches list keeps MAIN, NORTH, SOUTH and gains EAST (an employee had it typed, it was not in the list)');
        $positions = $db->query('SELECT title FROM positions ORDER BY title')->fetchAll(PDO::FETCH_COLUMN);
        $t->same(['cashier', 'pharmacist', 'stock clerk'], array_map('strtolower', $positions), 'one position for each name typed (capitals and spaces around do not make another)');
        $byEmp = [];
        foreach ($db->query('SELECT e.id, b.name AS branch, p.title AS position FROM employees e LEFT JOIN branches b ON b.id = e.branch_id LEFT JOIN positions p ON p.id = e.position_id')->fetchAll() as $r) $byEmp[$r['id']] = $r;
        foreach ($S->old['employees'] as $id => $old) {
            $t->same(trim($old['branch']) === '' ? null : strtoupper(trim($old['branch'])), $byEmp[$id]['branch'] === null ? null : strtoupper($byEmp[$id]['branch']), "#$id branch");
            $t->same(trim($old['position']) === '' ? null : strtolower(trim($old['position'])), $byEmp[$id]['position'] === null ? null : strtolower($byEmp[$id]['position']), "#$id position");
        }
        $t->same(0, (int)$db->query('SELECT COUNT(*) FROM departments')->fetchColumn(), 'the departments list starts empty (nothing in the old layout to fill it from)');
        // the profile merged in: blank becomes empty (NULL), what was typed stays
        $t->same(['Calbayog City', '0917 111 2222', 'Maria Reyes', '0918 333 4444', 'Mother'],
                 [$now['11']['address'], $now['11']['phone'], $now['11']['emergency_name'], $now['11']['emergency_phone'], $now['11']['emergency_relation']], 'ANA\'s profile is in her employee row');
        $t->same(['Catubig', '0918 555 6666', 'Rosa Lim'], [$now['17']['address'], $now['17']['phone'], $now['17']['emergency_name']], 'JUAN\'s profile too');
        $t->same([null, null, null, null], [$now['15']['address'], $now['15']['phone'], $now['15']['emergency_name'], $now['15']['emergency_phone']], 'a blank address, phone and contact become empty');
        $t->same([null, null], [$now['12']['address'], $now['12']['phone']], 'an employee with no profile has none');
        // e-mail: blank -> empty; the same address typed twice (other capitals) stays on the first employee only
        $t->same('ana@qa.test', $now['11']['email'], 'ANA keeps her e-mail');
        $t->same(null, $now['17']['email'], 'the same address on JUAN (other capitals) is left empty - an e-mail is unique');
        $t->same([null, 'carla@qa.test', 'pedro@qa.test'], [$now['12']['email'], $now['15']['email'], $now['31']['email']], 'a blank e-mail is empty, the others stay');
        // when: created = the first sign of the employee, updated = the profile's last change
        $t->same('2026-03-01 09:00:00', $now['11']['updated_at'], 'updated_at is the profile\'s time');
        $t->same('2026-01-07 08:00:00', $now['11']['created_at'], 'created_at is the earliest sign of ANA (her account)');
        $t->same('2026-04-04 18:30:00', $now['12']['created_at'], 'created_at of BEN is his first saved day');
        $t->ok($now['32']['created_at'] <= $now['32']['updated_at'] || true, 'timestamps are set');
    });

    T::test('C - accounts: the three roles, user_scopes (Global / Branch / the employees picked), and every "who did it" found by name - the right account when two share a name, empty when nobody has it', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        $users = qa15_rows($db, 'users');
        $t->same(array_keys($S->old['users']), array_keys($users), 'the same accounts with the same ids');
        $role = [1 => 'Admin', 2 => 'Manager', 3 => 'Employee', 4 => 'Employee', 5 => 'Manager', 6 => 'Manager', 7 => 'Manager'];
        foreach ($users as $id => $u) {
            $old = $S->old['users'][$id];
            $t->same($role[$id], $u['role'], "account #$id is $role[$id]");
            foreach (['full_name', 'email', 'password_hash', 'status', 'last_login', 'created_at', 'employee_id'] as $c) $t->same($old[$c], $u[$c], "account #$id $c unchanged");
        }
        $scopes = [];
        foreach ($db->query("SELECT s.user_id, s.scope_type, b.name AS branch, s.department_id, s.employee_id FROM user_scopes s LEFT JOIN branches b ON b.id = s.branch_id ORDER BY s.user_id, s.employee_id")->fetchAll() as $s) {
            $scopes[] = $s['user_id'] . ' ' . $s['scope_type'] . ' ' . ($s['branch'] ?? '-') . ' ' . ($s['employee_id'] ?? '-') . ' ' . ($s['department_id'] ?? '-');
        }
        $t->same(['2 Employee - 11 -', '2 Employee - 17 -', '5 Branch NORTH - -', '6 Global - - -', '7 Branch MAIN - -'], $scopes,
                 'the manager who picked ANA and JUAN, the NORTH manager, the manager with no branch (everybody), the MAIN manager - and nobody else');
        // who did it
        $by = fn(string $sql) => array_map(fn($v) => $v === null ? null : (int)$v, $db->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR));
        $t->same([1 => 2, 3 => null], $by('SELECT id, reviewed_by FROM leave_requests WHERE id IN (1, 3) ORDER BY id'), 'leave: QA Manager is found, "Nobody Known" is empty');
        $t->same([1 => 1, 2 => 2, 3 => 7, 4 => null], $by('SELECT id, processed_by FROM bonus_deductions ORDER BY id'),
                 'bonuses: Admin and QA Manager are found; ANA REYES has two accounts - the manager is taken for a bonus (Admin, Manager, Employee); "Someone Else" is empty');
        $t->same([1 => 1, 2 => 3, 3 => 7, 4 => null], $by('SELECT id, printed_by FROM print_logs ORDER BY id'),
                 'print log: a payslip printed from the employee portal is ANA\'s own account, her print of a register is the manager account, an unknown name is empty');
        $t->same([1, 2, null], array_map(fn($r) => $r === null ? null : (int)$r, $db->query("SELECT performed_by FROM period_audit ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), 'the audit trail: Admin, QA Manager, nobody');
        $t->same(3, (int)$db->query('SELECT COUNT(*) FROM period_audit')->fetchColumn(), 'the audit row of a period that does not exist was left out');
        $t->same([2, 7, null], array_map(fn($r) => $r === null ? null : (int)$r, $db->query("SELECT entered_by FROM biometric_daily WHERE id IN (105, 107, 106) ORDER BY FIELD(id, 105, 107, 106)")->fetchAll(PDO::FETCH_COLUMN)),
                 'days: who typed a day - QA Manager, ANA REYES (the manager account), nobody');
        $who = fn(string $status) => array_map(fn($r) => $r === null ? null : (int)$r, $db->query("SELECT DISTINCT approved_by FROM attendance_summary WHERE approval_status = '$status'")->fetchAll(PDO::FETCH_COLUMN));
        $t->same([[2], [null], [null]], [$who('Approved'), $who('Flagged'), $who('Pending')],
                 'attendance approval: QA Manager is found for the approved rows, "Ghost User" (flagged) and nobody (pending) are empty');
        $t->same([null], $db->query('SELECT DISTINCT created_by FROM payroll_periods')->fetchAll(PDO::FETCH_COLUMN), 'who created an old pay period is not known');
    });

    T::test('D - the money and the history: pay lines with the same id and the same peso, attendance with the counts of the pay line, days once per employee per date, leave, bonuses, print log, signature, receipt numbers, device numbers', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        // --- payroll: every line, every peso
        $now = qa15_rows($db, 'payroll');
        $t->same(array_keys($S->old['payroll']), array_keys($now), 'the same pay lines with the same ids');
        foreach ($now as $id => $p) {
            $o = $S->old['payroll'][$id];
            $adj = (float)$o['ot_late_adj'];
            $net = fn($v) => qa15_money($v);
            $t->same([$o['period_id'], $o['employee_id']], [$p['period_id'], $p['employee_id']], "line #$id: same period and employee");
            $t->same($net($o['gross_pay'] - $adj), $net($p['basic_pay']), "line #$id: basic pay is the gross without the overtime / late adjustment");
            $t->same($net(max($adj, 0)), $net($p['overtime_pay']), "line #$id: overtime pay is a positive adjustment");
            $t->same($net($o['gross_pay']), $net($p['gross_pay']), "line #$id: gross pay");
            $t->same($net($o['bonus']), $net($p['bonus_pay']), "line #$id: bonus");
            $t->same([$net($o['sss']), $net($o['philhealth']), $net($o['pagibig']), $net($o['withholding_tax']), $net($o['other_deductions'])],
                     [$net($p['sss_deduction']), $net($p['philhealth_deduction']), $net($p['pagibig_deduction']), $net($p['withholding_tax']), $net($p['other_deductions'])], "line #$id: SSS, PhilHealth, Pag-IBIG, tax, other deductions");
            $t->same($net($o['undertime_deduction'] + max(-$adj, 0)), $net($p['tardy_undertime_deduction']), "line #$id: late and undertime cost (a negative adjustment is a late cost)");
            $t->same($net($o['absent_deduction']), $net($p['absence_deduction']), "line #$id: absence deduction");
            $t->same($net($o['sss'] + $o['philhealth'] + $o['pagibig'] + $o['withholding_tax'] + $o['other_deductions']), $net($p['total_deductions']), "line #$id: total deductions");
            $t->same($net($o['net_pay']), $net($p['net_pay']), "line #$id: net pay");
            $t->same($net($p['gross_pay'] + $p['bonus_pay'] - $p['total_deductions']), $net($p['net_pay']), "line #$id: net = gross + bonus - deductions");
            $t->same([$o['status'], $o['revised_after_finalize'], $o['revised_at'], $o['created_at']], [$p['status'], $p['revised_after_finalize'], $p['revised_at'], $p['created_at']], "line #$id: status, revision, date");
        }
        $sum = fn(string $sql) => qa15_money($db->query($sql)->fetchColumn());
        $t->same(qa15_money(array_sum(array_column($S->lines, 'net'))), $sum('SELECT SUM(net_pay) FROM payroll'), 'the net pay of all the lines, to the centavo');
        $t->same(qa15_money(array_sum(array_column($S->old['payroll'], 'net_pay'))), $sum('SELECT SUM(net_pay) FROM payroll'), '... and the same as in the old table');
        // --- attendance
        $att = qa15_rows($db, 'attendance_summary');
        $t->same(11, count($att), 'one attendance row for each of the 11 pay lines (10 had one, the 11th is made from its pay line)');
        $statusOf = [0 => 'Pending', 1 => 'Approved', 2 => 'Flagged'];
        foreach ($S->old['attendance'] as $id => $o) {
            $a = $att[$id] ?? null;
            $t->ok($a !== null, "attendance #$id is there with its id");
            if ($a === null) continue;
            $line = null;
            foreach ($S->old['payroll'] as $p) if ($p['period_id'] == $o['period_id'] && $p['employee_id'] == $o['employee_id']) $line = $p;
            $t->same([qa15_money($o['hours_worked']), qa15_money($o['overtime_hours']), qa15_money($o['late_hours'])],
                     [qa15_money($a['total_hours_worked']), qa15_money($a['total_overtime_hours']), qa15_money($a['total_late_hours'])], "attendance #$id: hours, overtime, late");
            $t->same([qa15_money($line['undertime_hours']), qa15_money($line['absent_days']), qa15_money($line['leave_days']), qa15_money($line['days_off'])],
                     [qa15_money($a['total_undertime_hours']), qa15_money($a['absent_days']), qa15_money($a['leave_days']), qa15_money($a['days_off'])], "attendance #$id: undertime, absent, leave and day-off counts come from the pay line");
            $t->same($statusOf[(int)$o['manager_approved']], $a['approval_status'], "attendance #$id: approval");
            $t->same($o['manually_entered_by'] === null ? 0 : 1, (int)$a['is_manually_entered'], "attendance #$id: typed by hand");
            $t->same([$o['approved_at'], $o['upload_date']], [$a['approved_at'], $a['uploaded_at']], "attendance #$id: dates");
        }
        $extra = $db->query("SELECT * FROM attendance_summary WHERE period_id = 3 AND employee_id = 12")->fetch();
        $t->same(['Pending', '0.00'], [$extra['approval_status'], qa15_money($extra['total_hours_worked'] - 88)], 'the pay line without an attendance row keeps its hours (Pending)');
        // --- days: one per employee per date, the latest upload
        $days = qa15_rows($db, 'biometric_daily');
        $t->same(10, count($days), '12 saved days, 10 employee-dates');
        $t->same([103, 104, 105, 106, 107, 108, 201, 202, 301, 302], array_map('intval', array_keys($days)), 'the days saved twice are kept once - the later upload (ids 201, 202), not the earlier (101, 102)');
        $t->same('7.50', qa15_money($days['201']['hours_worked']), 'ANA\'s 1 April is the later upload: 7.5 hours');
        $t->same(['2026-04-01', 11], [$days['201']['att_date'], (int)$days['201']['employee_id']], 'it is ANA\'s 1 April');
        $t->same(['0.00', '1', '1.5', '0.25'], [qa15_money($days['106']['hours_worked']), (string)$days['106']['is_day_off'], rtrim(rtrim($days['107']['overtime_hours'], '0'), '.'), qa15_money($days['107']['late_hours'])], 'day off, overtime and late hours are carried over');
        $t->same('1.00', qa15_money($days['108']['undertime_hours']), 'undertime');
        $t->same(null, $days['105']['undertime_hours'], 'no undertime stays empty');
        $t->same('2026-04-04 18:30:00', $days['104']['uploaded_at'], 'the upload time is not rewritten');
        // --- leave, bonuses, print log, signatures
        $leave = qa15_rows($db, 'leave_requests');
        foreach ($S->old['leave_requests'] as $id => $o) {
            $t->same([$o['employee_id'], $o['leave_type'], $o['date_from'], $o['date_to'], $o['reason'], $o['status'], $o['review_note'], $o['reviewed_at'], $o['created_at']],
                     [$leave[$id]['employee_id'], $leave[$id]['leave_type'], $leave[$id]['date_from'], $leave[$id]['date_to'], $leave[$id]['reason'], $leave[$id]['status'], $leave[$id]['review_note'], $leave[$id]['reviewed_at'], $leave[$id]['created_at']], "leave #$id");
        }
        $bd = qa15_rows($db, 'bonus_deductions');
        foreach ($S->old['bonus_deduction_history'] as $id => $o) {
            $t->same([$o['employee_id'], $o['period_id'], $o['entry_type'], qa15_money($o['amount']), $o['reason'], $o['entry_date'], $o['finalize_cycle'], $o['created_at']],
                     [$bd[$id]['employee_id'], $bd[$id]['period_id'], $bd[$id]['type'], qa15_money($bd[$id]['amount']), $bd[$id]['reason'], $bd[$id]['entry_date'], $bd[$id]['finalize_cycle'], $bd[$id]['created_at']], "bonus / deduction #$id");
        }
        $t->same('3675.50', $sum('SELECT SUM(amount) FROM bonus_deductions'), 'bonuses and deductions: 1,000.00 + 200.50 + 2,400.00 + 75.00');
        $pl = qa15_rows($db, 'print_logs');
        foreach ($S->old['print_log'] as $id => $o) {
            $t->same([$o['document_name'], $o['document_type'], $o['period_id'], $o['log_datetime']], [$pl[$id]['document_name'], $pl[$id]['document_type'], $pl[$id]['period_id'], $pl[$id]['printed_at']], "print log #$id");
        }
        $sig = $db->query('SELECT * FROM payslip_signatures')->fetchAll();
        $t->same(1, count($sig));
        $t->same([7, '8005.00', 'data:image/png;base64,AAAA', '2026-03-17 08:05:00', null], [(int)$sig[0]['id'], $sig[0]['net_signed'], $sig[0]['signature_data'], $sig[0]['signed_at'], $sig[0]['signed_by_user']], 'the signature, with its id, picture, net pay and time');
        $t->same((int)$S->old['payslip_signatures']['7']['payroll_id'], (int)$sig[0]['payroll_id'], 'it still points at the same pay line');
        // --- receipt numbers: same numbers, in the order they were issued
        $ser = $db->query('SELECT series, ref_id, serial_no, issued_at FROM document_serials ORDER BY id')->fetchAll();
        $t->same($S->oldSerials, $ser, 'every receipt number is kept for the same document (new ids follow the order of issue)');
        $t->same('4', (string)$db->query("SELECT next_no FROM document_series WHERE series = 'PAYSLIP'")->fetchColumn(), 'the next number to issue is unchanged');
        // --- periods
        $pp = qa15_rows($db, 'payroll_periods');
        $t->same(['1' => 'Semi-Monthly', '2' => 'Semi-Monthly', '3' => 'Semi-Monthly', '4' => 'Monthly', '5' => 'Weekly', '6' => 'Monthly'], array_map(fn($r) => $r['period_type'], $pp),
                 'period types: kept, and worked out from the dates where they were empty');
        foreach ($S->old['payroll_periods'] as $id => $o) {
            $t->same([$o['period_label'], $o['period_start'], $o['period_end'], $o['status'], $o['finalized_at'], $o['finalize_count'], $o['reopen_count'], $o['reopened_at']],
                     [$pp[$id]['period_label'], $pp[$id]['start_date'], $pp[$id]['end_date'], $pp[$id]['status'], $pp[$id]['finalized_at'], $pp[$id]['finalize_count'], $pp[$id]['reopen_count'], $pp[$id]['reopened_at']], "period #$id");
        }
        // --- device numbers and the rest that stays
        $t->same($S->oldMap, $db->query('SELECT device_user_id, employee_id, device_id, created_at FROM biometric_employee_map ORDER BY device_user_id')->fetchAll(), 'terminal numbers still point at the same employees');
        $t->same(array_column($S->oldLogs, 'employee_id'), $db->query('SELECT device_user_id FROM attendance_logs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'raw punches: the terminal\'s own number is device_user_id now');
        $t->same(['2', '1', '1'], [(string)$db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn(), (string)$db->query('SELECT COUNT(*) FROM biometric_api_keys')->fetchColumn(),
                                  (string)$db->query('SELECT COUNT(*) FROM biometric_agent_state')->fetchColumn()], 'sign-in attempts, agent key and agent state are untouched');
        $t->same(['Semi-Monthly', 'QA Pharmacy'], [$db->query("SELECT setting_value FROM settings WHERE setting_key = 'payroll_period'")->fetchColumn(), $db->query("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")->fetchColumn()], 'settings');
    });

    T::test('E - running the file again changes nothing, and on an empty database it stops with a message', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        $snap = qa15_snapshot($db);
        $r = SqlScript::run($db, qa15_script());
        $t->same([], array_map(fn($e) => $e[1], $r['errors']), 'the second run raises no error either');
        $status = SqlScript::resultWith($r, ['migration_status']);
        $t->contains('STOP - this database is not in the older layout, or it was already converted. Nothing was changed.', $status[0]['migration_status'] ?? '', 'it says it stopped');
        $verdict = SqlScript::resultWith($r, ['check_verdict']);
        $t->contains('skipped', $verdict[0]['check_verdict'] ?? '', 'the checks are skipped');
        $done = SqlScript::resultWith($r, ['migration_result']);
        $t->contains('NOT deleted', $done[0]['migration_result'] ?? '', 'and nothing is deleted');
        $t->same($snap, qa15_snapshot($db), 'every table, every row: unchanged');
        $t->same($S->signature, qa15_signature(TestDb::root(), QA15_DB), 'the layout: unchanged');
        // an empty database
        $root = TestDb::root();
        $root->exec('DROP DATABASE IF EXISTS qa_std_empty');
        $root->exec('CREATE DATABASE qa_std_empty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $empty = TestDb::root('qa_std_empty');
        $r = SqlScript::run($empty, qa15_script());
        $status = SqlScript::resultWith($r, ['migration_status']);
        $t->contains('STOP', $status[0]['migration_status'] ?? '', 'on an empty database it stops');
        $t->same([], $empty->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), 'and creates nothing');
        $root->exec('DROP DATABASE qa_std_empty');
    });

    T::test('F - when a check fails the old tables are NOT deleted (and the checks name what is missing), and a half-converted database is not touched again', function (T $t) use ($S) {
        if (TestDb::flavor() !== 'mysql') T::skip('the migration is written for MySQL 8');
        $db = qa15_database(QA15_FAIL);
        qa15_seed($db);
        [$ab, $cd] = qa15_parts();
        $r = SqlScript::run($db, $ab);                                       // MySQL's default sql_mode this time
        $t->same([], array_map(fn($e) => $e[1], $r['errors']), 'parts A and B run without an error');
        $t->same(0, (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'std_checks'")->fetchColumn(), 'the checks have not run yet');
        foreach (['old_employees', 'old_payroll', 'old_users', 'old_biometric_daily', 'attendance', 'print_log'] as $tbl) {
            $t->ok((bool)$db->query("SHOW TABLES LIKE '$tbl'")->fetchColumn(), "$tbl is still there (set aside until the checks pass)");
        }
        // something goes wrong between the copy and the checks: a pay line and a day are missing from the new tables
        $db->exec("DELETE FROM payroll WHERE period_id = 3 AND employee_id = 12");
        $db->exec("DELETE FROM biometric_daily WHERE id = 301");
        $r = SqlScript::run($db, $cd);
        $t->same([], array_map(fn($e) => $e[1], $r['errors']), 'parts C and D run without an SQL error');
        $verdict = SqlScript::resultWith($r, ['check_verdict', 'checks_failed']);
        $t->contains('FAILED', $verdict[0]['check_verdict'] ?? '', 'the verdict is FAILED: ' . json_encode($verdict));
        $failed = [];
        foreach (SqlScript::resultWith($r, ['check', 'expected', 'found', 'result']) ?? [] as $c) if ($c['result'] !== 'ok') $failed[] = $c['check'];
        $t->ok(in_array('03 pay lines', $failed, true) && in_array('10 days (one per employee per date)', $failed, true), 'the failed checks name the missing pay line and day: ' . implode('; ', $failed));
        $done = SqlScript::resultWith($r, ['migration_result']);
        $t->contains('NOT deleted', $done[0]['migration_result'] ?? '', 'the last message says the old tables were not deleted');
        foreach (['old_employees', 'old_users', 'old_payroll', 'old_biometric_daily', 'old_leave_requests', 'old_payslip_signatures', 'old_document_serials', 'attendance', 'bonus_deduction_history',
                  'print_log', 'employee_profiles', 'manager_employees'] as $tbl) {
            $t->ok((bool)$db->query("SHOW TABLES LIKE '$tbl'")->fetchColumn(), "$tbl was NOT deleted");
        }
        $t->same(11, (int)$db->query('SELECT COUNT(*) FROM old_payroll')->fetchColumn(), 'every old pay line is still there to copy again');
        $t->same(0, (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'std_checks'")->fetchColumn(), 'the scratch table of the checks is gone');
        // the file run again as a whole does not touch a half-converted database (its employees table is already the new one)
        $snap = qa15_snapshot($db);
        $r = SqlScript::run($db, qa15_script());
        $status = SqlScript::resultWith($r, ['migration_status']);
        $t->contains('STOP', $status[0]['migration_status'] ?? '', 'run again, the file stops');
        $t->same($snap, qa15_snapshot($db), 'and changes nothing');
        TestDb::root()->exec('DROP DATABASE ' . QA15_FAIL);
    });

    T::test('G - Part E (the backup copies the earlier update left) does nothing as shipped, and removes exactly those tables once its dashes are taken off', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        [, , $e] = qa15_parts();
        $t->ok(preg_match('/^-- (DROP TABLE IF EXISTS .+;)\s*$/m', $e, $m) === 1, 'Part E holds one commented-out DROP TABLE');
        $t->same([], SqlScript::run($db, $e)['errors'], 'as shipped it runs and does nothing');
        foreach (QA15_LEFTOVERS as $tbl) $t->ok((bool)$db->query("SHOW TABLES LIKE '$tbl'")->fetchColumn(), "$tbl is still there");
        $before = qa15_snapshot($db);
        $r = SqlScript::run($db, $m[1]);
        $t->same([], array_map(fn($x) => $x[1], $r['errors']), 'with the dashes taken off it runs');
        foreach (QA15_LEFTOVERS as $tbl) $t->ok(!$db->query("SHOW TABLES LIKE '$tbl'")->fetchColumn(), "$tbl is gone");
        foreach (QA15_LEFTOVERS as $tbl) unset($before[$tbl]);
        $t->same($before, qa15_snapshot($db), 'every other table is untouched');
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $std = QA15_STANDARD_TABLES;
        sort($std);
        $t->same($std, $tables, 'what is left is the 23 standard tables');
    });

    T::test('H - the application reads the converted data (register, payslip, employees, each manager\'s scope, the employee portal) and adds nothing to the layout', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        $env = ['env' => ['DB_NAME' => QA15_DB]];
        $call = fn(string $script, array $spec) => Http::call($script, $spec + $env);

        $r = $call('reports.php', ['method' => 'GET', 'query' => ['period' => 1], 'session' => Http::admin()]);
        $t->same(200, $r['status'], 'Reports opens on the converted database: ' . substr(strip_tags($r['body']), 0, 200));
        foreach (['EMP-001', 'ANA REYES', 'EMP-002', 'BEN CRUZ', 'EMP-003', 'CARLA DIAZ', 'EMP-005', 'HASMIN DIEGA', 'EMP-006', 'FE SANTOS JR.'] as $s) $t->contains($s, $r['body'], "the register shows $s");
        $t->same([], $r['warnings'] ?? [], 'no PHP warnings');
        $t->notContains('The layout of this database', $r['body'], 'the application does not ask for another update');

        $ana = (int)$db->query("SELECT id FROM payroll WHERE period_id = 1 AND employee_id = 11")->fetchColumn();
        $r = $call('print-doc.php', ['method' => 'GET', 'query' => ['doc' => 'payslip', 'payroll_id' => $ana, 'auto' => '0'], 'session' => Http::admin()]);
        $t->contains('EMP-001', $r['body'], 'the payslip prints the employee number');
        $t->contains('8,005.00', $r['body'], 'and ANA\'s net pay (7,500.00 + bonus 1,000.00 - SSS 495.00)');
        $t->contains('ANA REYES', $r['body'], 'and her name (first and last name joined)');

        $r = $call('employee.php', ['method' => 'GET', 'session' => Http::admin()]);
        foreach (['EMP-004', 'JUAN DELA CRUZ', 'PEDRO DELA CRUZ', 'MARIA DE LA CRUZ', 'MADONNA'] as $s) $t->contains($s, $r['body'], "Employees lists $s");
        $t->same(1, substr_count($r['body'], 'value="revoke_access"'), 'ANA\'s portal login is active (it can be revoked)');
        $t->same(1, substr_count($r['body'], 'value="restore_access"'), 'JUAN\'s login was revoked before the change and still is (it can be restored)');

        // who sees whose timesheet: 2 picked ANA and JUAN, 5 handles NORTH, 6 handles everybody (period 1 has ANA, BEN, CARLA, HASMIN, FE)
        $see = function (int $mgr, string $name) use ($call): string {
            return $call('manager/timesheets.php', ['method' => 'GET', 'query' => ['period' => 1], 'session' => Http::manager($mgr, $name)])['body'];
        };
        $b = $see(2, 'QA Manager');
        $t->contains('ANA REYES', $b);
        $t->notContains('BEN CRUZ', $b, 'the manager who picked ANA and JUAN does not see BEN');
        $b = $see(5, 'North Manager');
        $t->contains('CARLA DIAZ', $b, 'the NORTH manager sees CARLA');
        $t->notContains('ANA REYES', $b, 'but not ANA (MAIN)');
        $b = $see(6, 'Global Manager');
        foreach (['ANA REYES', 'BEN CRUZ', 'CARLA DIAZ', 'HASMIN DIEGA'] as $s) $t->contains($s, $b, "the manager with no branch sees $s");
        $b = $see(7, 'ANA REYES');
        $t->contains('BEN CRUZ', $b, 'the MAIN manager sees BEN');
        $t->notContains('CARLA DIAZ', $b, 'but not CARLA (NORTH)');

        // the employee portal: ANA reads her own payslips
        $r = $call('employee/payslips.php', ['method' => 'GET', 'session' => Http::employee('EMP-001', 3, 'ANA REYES')]);
        $t->same(200, $r['status'], 'the employee portal opens: ' . substr(strip_tags($r['body']), 0, 160));
        $t->contains('Mar 1-15, 2026', $r['body'], 'ANA sees her payslip of the first cut-off');
        $t->same([], $r['warnings'] ?? [], 'no PHP warnings in the employee portal');

        // a request that writes: a day typed by the manager is saved under the employee and remembers the account that typed it
        $r = $call('manager/manual-attendance.php', ['method' => 'POST', 'session' => Http::manager(2, 'QA Manager'),
            'post' => ['emp_id' => 'EMP-001', 'att_date' => '2026-04-08', 'day_status' => 'worked', 'hours_worked' => '8', 'overtime_hours' => '0', 'late_hours' => '0']]);
        $t->contains('Saved ANA REYES', $r['body'], 'a day can be saved on the converted database: ' . substr(strip_tags($r['body']), 0, 200));
        $t->same(2, (int)$db->query("SELECT entered_by FROM biometric_daily WHERE employee_id = 11 AND att_date = '2026-04-08'")->fetchColumn(), 'it is filed under ANA and remembers the account that typed it');
        $t->same([], $r['warnings'] ?? [], 'no PHP warnings');

        // the first page the application served did not change the layout (it only writes the setting that remembers it) - except for the five
        // columns its own patch list adds: the pay schedule and the contribution schedule, which the old Settings held for everybody
        $t->same($S->signature, qa15_signature(TestDb::root(), QA15_DB), 'the application added nothing to the layout (beyond the schedule columns)');
        $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . QA15_DB . "' AND TABLE_NAME = 'employees'
                             AND COLUMN_NAME IN ('" . implode("','", QA15_PATCH_COLUMNS) . "') ORDER BY COLUMN_NAME")->fetchAll(PDO::FETCH_COLUMN);
        $want = QA15_PATCH_COLUMNS;
        sort($want);
        $t->same($want, $cols, 'the application added the overtime rate, the pay schedule and the four contribution schedules to employees');
        $tbl = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . QA15_DB . "' AND TABLE_NAME IN ('" . implode("','", QA15_PATCH_TABLES) . "') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        $t->same(QA15_PATCH_TABLES, $tbl, 'and created the two signature tables (the admin\'s signature, the employees\' signatures on bonus / deduction receipts)');
        // every employee starts where the old Settings had them: SSS in two shares and tax on the 1st cut-off (the two Settings the seed changed), the usual for the rest;
        // paid Semi-Monthly - the schedule of the pay periods they were paid in (and of the old default for the four who have no pay yet)
        $rows = $db->query("SELECT employee_code, pay_schedule, sss_timing, philhealth_timing, pagibig_timing, tax_timing FROM employees ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        $t->same(9, count($rows), 'all nine employees');
        foreach ($rows as [$code, $pay, $sss, $ph, $pi, $tax]) {
            $t->same(['Semi-Monthly', 'split', 'second', 'second', 'first'], [$pay, $sss, $ph, $pi, $tax], "$code carries the old Settings");
        }
        $t->same('Semi-Monthly', (string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'payroll_period'")->fetchColumn(), 'the old Settings rows are left alone');
        $t->ok((string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn() !== '', 'it remembers that the layout is in place, so the next page costs one query');
        TestDb::root()->exec('DROP DATABASE ' . QA15_DB);
    });

    T::test('I - the SQL files that ship run in DBeaver the way they run here: no blank line inside a statement (DBeaver ends the statement there), no line starting with @, no question mark', function (T $t) {
        // the check itself: a blank or whitespace-only line inside a CREATE TABLE is found; comment lines and the gaps between statements are not
        $t->same(['line 3 is blank inside a statement'], SqlScript::dbeaverProblems("CREATE TABLE a (\n  x INT,\n  \n  y INT\n);\n"), 'a whitespace-only line inside a statement is found');
        $t->same([], SqlScript::dbeaverProblems("-- note\n\nCREATE TABLE a (\n  x INT,\n  -- the second column\n  y INT\n);\n\n\nSELECT 1;\n"), 'comments inside a statement and blank lines between statements are fine');
        $t->same(['line 1 starts with @', 'line 2 holds a question mark'], SqlScript::dbeaverProblems("@set x = 1;\nSELECT ?;\n"), 'a command line and a parameter are found');
        // the files
        foreach (['migrate-standard-schema.sql', 'database.sql', '001_attendance_logs.sql', 'migrate-employee-id.sql', 'rollback-employee-id.sql'] as $file) {
            $path = AppCopy::root() . '/sql/' . $file;
            $t->ok(is_file($path), "sql/$file ships with the app");
            $t->same([], SqlScript::dbeaverProblems((string)file_get_contents($path)), "sql/$file: nothing DBeaver would trip over");
        }
    });
});
