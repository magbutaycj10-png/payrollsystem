<?php
/*
 * 14 - The database change of 2026-10-15: every table that points at an employee links it by employees.id (employee_id),
 * with a foreign key, instead of keeping its own copy of the text ID (emp_id). payroll2/sql/migrate-employee-id.sql makes
 * the change on the live database. This suite runs that file the way DBeaver does - statement by statement, every error
 * IGNORED and the run carried on (the worst way to answer DBeaver's error dialog) - on a copy of the live layout
 * (tests/schema_before_employee_id.sql) filled with data, and proves:
 *
 *   A  Part A only adds: backup copies identical to the tables, employee_id filled in for every row that has an employee
 *      (other capitals / a trailing space and mixed collations included), the old layout untouched, and its report lists
 *      the rows that have no employee - a terminal number from an old upload, the records of a deleted employee
 *   B  while such rows exist Part B stops at its guard and changes NOTHING, even with every error ignored
 *   C  after the documented remedies, Part B switches: no emp_id left in the ten tables, employee_id required (users:
 *      optional), the foreign keys with their ON DELETE / ON UPDATE rules, the unique keys; every row still there with every
 *      other value unchanged and pointing at the same employee; Part C finds nothing missing
 *   D  running Part A and Part B again changes nothing
 *   E  rollback-employee-id.sql brings back the old layout, indexes and foreign keys and the same data, and the migration runs
 *      again on top of it
 *   F  the application reads the migrated data (register, payslip, manager scope, employees) - and on a database that has not
 *      been migrated it says what to do instead of failing
 *   G  the foreign keys hold: history stops an employee being deleted, the portal login / profile / assignment / device
 *      number go with the employee, a renumbered employee keeps every record
 */

const QA14_DB  = 'qa_migr';
const QA14_OLD = 'qa_migr_old';
const QA14_TABLES = ['attendance' => 'id', 'payroll' => 'id', 'bonus_deduction_history' => 'id', 'biometric_daily' => 'id',
                     'leave_requests' => 'id', 'payslip_signatures' => 'id', 'employee_profiles' => 'id', 'manager_employees' => 'id',
                     'users' => 'id', 'biometric_employee_map' => 'device_user_id'];

/** a fresh database in the layout from before the change, with the app's database user allowed in */
function qa14_old_database(string $name): PDO
{
    $root = TestDb::root();
    $root->exec("DROP DATABASE IF EXISTS $name");
    // MySQL 8's own default collation, as on the server: tables created without one get it (users, leave_requests, payslip_signatures)
    $root->exec("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    foreach (['127.0.0.1', 'localhost', '%'] as $h) $root->exec("GRANT ALL ON $name.* TO '" . TestDb::USER . "'@'$h'");
    $db = TestDb::root($name);
    $r = SqlScript::run($db, (string)file_get_contents(dirname(__DIR__) . '/schema_before_employee_id.sql'), true);
    if ($r['errors']) throw new RuntimeException('old layout: ' . $r['errors'][0][1]);
    return $db;
}

/** the data: six employees whose ids are NOT the numbers in their emp_id, history, logins, a profile, assignments, device numbers */
function qa14_seed(PDO $db): void
{
    $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('payroll_period', 'Semi-Monthly'), ('company_name', 'QA Pharmacy')");
    $db->exec("INSERT INTO employees (id, emp_id, full_name, branch, base_salary, salary_type, status) VALUES
        (11, 'EMP-001', 'ANA REYES',    'MAIN',  15000, 'monthly',  'Active'),
        (12, 'EMP-002', 'BEN CRUZ',     'MAIN',    520, 'daily',    'Active'),
        (15, 'EMP-003', 'CARLA DIAZ',   'NORTH',  7500, 'kinsenas', 'Active'),
        (17, 'EMP-004', 'DANTE LIM',    'NORTH',   500, 'daily',    'Active'),
        (23, 'EMP-005', 'HASMIN DIEGA', 'MAIN',    600, 'daily',    'Active'),
        (30, 'EMP-006', 'FE SANTOS',    'MAIN',    480, 'daily',    'Inactive')");
    $db->exec("INSERT INTO payroll_periods (id, period_label, period_start, period_end, period_type, status, finalize_count) VALUES
        (1, 'Mar 1-15, 2026',  '2026-03-01', '2026-03-15', 'Semi-Monthly', 'Locked', 1),
        (2, 'Mar 16-31, 2026', '2026-03-16', '2026-03-31', 'Semi-Monthly', 'Locked', 1),
        (3, 'Apr 1-15, 2026',  '2026-04-01', '2026-04-15', 'Semi-Monthly', 'Open',   0)");
    // payroll + attendance: HASMIN's March lines carry her ID typed in small letters with a trailing space - MySQL always matched it
    $lines = [
        [1, 'EMP-001', 'ANA REYES', 7500.00, 0, 495.00, 0, 0, 1000.00, 0], [1, 'EMP-002', 'BEN CRUZ', 5720.00, 45.00, 0, 0, 0, 0, 0],
        [1, 'EMP-003', 'CARLA DIAZ', 7500.00, 0, 405.00, 0, 0, 0, 150.25], [1, 'emp-005 ', 'HASMIN DIEGA', 6600.00, 120.50, 540.00, 0, 0, 0, 0],
        [1, 'EMP-006', 'FE SANTOS', 4800.00, 0, 0, 0, 0, 0, 0],
        [2, 'EMP-001', 'ANA REYES', 7500.00, 0, 0, 250.00, 200.00, 0, 0], [2, 'EMP-002', 'BEN CRUZ', 6240.00, 90.00, 0, 0, 0, 0, 200.50],
        [2, 'EMP-003', 'CARLA DIAZ', 7500.00, 0, 0, 0, 0, 0, 0], [2, 'emp-005 ', 'HASMIN DIEGA', 7200.00, 62.00, 0, 250.00, 0, 0, 0],
        [3, 'EMP-001', 'ANA REYES', 2000.00, 0, 495.00, 0, 0, 0, 0], [3, 'EMP-002', 'BEN CRUZ', 2080.00, 0, 0, 0, 0, 0, 0],
    ];
    $pay = $db->prepare("INSERT INTO payroll (period_id, emp_id, emp_name, gross_pay, ot_late_adj, sss, philhealth, pagibig, bonus,
                         other_deductions, withholding_tax, net_pay, hours_worked, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $att = $db->prepare("INSERT INTO attendance (period_id, emp_id, emp_name, gross_pay, hours_worked, manager_approved) VALUES (?,?,?,?,?,?)");
    foreach ($lines as $i => [$per, $emp, $name, $gross, $ot, $sss, $ph, $pi, $bonus, $ded]) {
        $tax = $per === 2 && $emp === 'EMP-001' ? 125.75 : 0.0;
        $net = round($gross + $bonus - $sss - $ph - $pi - $ded - $tax, 2);
        $pay->execute([$per, $emp, $name, $gross, $ot, $sss, $ph, $pi, $bonus, $ded, $tax, $net, 80 + $i, $per === 3 ? 'Draft' : 'Finalized']);
        $att->execute([$per, trim(strtoupper($emp)), $name, $gross, 80 + $i, $i % 2]);
    }
    $db->exec("INSERT INTO bonus_deduction_history (entry_date, emp_id, emp_name, entry_type, amount, reason, period_id, finalize_cycle) VALUES
        ('2026-03-14', 'EMP-001', 'ANA REYES', 'Bonus', 1000.00, 'Performance bonus', 1, 0),
        ('2026-03-30', 'EMP-002', 'BEN CRUZ', 'Deduction', 200.50, 'Cash advance', 2, 0),
        ('2025-12-20', 'EMP-006', 'FE SANTOS', 'Bonus', 2400.00, '13th Month Pay 2025', NULL, 0),
        ('2026-02-14', 'EMP-099', 'ROSA TAN', 'Bonus', 500.00, 'Performance bonus', NULL, 0)");     // ROSA TAN was deleted from Employees long ago
    // uploaded_at / updated_at are ON UPDATE CURRENT_TIMESTAMP: old times here, so a migration that rewrote them would show
    $day = $db->prepare("INSERT INTO biometric_daily (period_id, emp_id, emp_name, att_date, hours_worked, overtime_hours, late_hours, undertime_hours, day_off, entered_by, uploaded_at)
                         VALUES (3,?,?,?,?,?,?,?,?,?, '2026-04-04 18:30:00')");
    foreach (['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04'] as $k => $d) {
        $day->execute(['EMP-001', 'ANA REYES', $d, 8, 0, 0, null, 0, null]);
        $day->execute(['EMP-002', 'BEN CRUZ', $d, $k === 1 ? 0 : 8.5, $k === 2 ? 1.5 : 0, 0.25, $k === 3 ? 1 : null, $k === 1 ? 1 : 0, $k === 0 ? 'QA Manager' : null]);
    }
    $day->execute(['00007', 'JUAN DELA CRUZ', '2026-04-02', 8, 0, 0, null, 0, null]);              // an old upload filed under the terminal's number
    $db->exec("INSERT INTO leave_requests (emp_id, emp_name, leave_type, date_from, date_to, reason, status, reviewed_by) VALUES
        ('EMP-002', 'BEN CRUZ', 'Vacation', '2026-04-06', '2026-04-07', 'Family', 'Approved', 'QA Admin'),
        ('EMP-003', 'CARLA DIAZ', 'Sick Leave', '2026-04-10', '2026-04-10', NULL, 'Pending', NULL)");
    $pid = (int)$db->query("SELECT id FROM payroll WHERE period_id = 1 AND emp_id = 'EMP-001'")->fetchColumn();
    $db->prepare("INSERT INTO payslip_signatures (payroll_id, emp_id, emp_name, period_id, period_label, signature_data, net_signed)
                  VALUES (?, 'EMP-001', 'ANA REYES', 1, 'Mar 1-15, 2026', 'data:image/png;base64,AAAA', 8005.00)")->execute([$pid]);
    $db->exec("INSERT INTO employee_profiles (emp_id, address, phone, updated_at) VALUES ('EMP-001', 'Calbayog City', '0917', '2026-03-01 09:00:00'),
                                                                                      ('EMP-004', 'Catubig', '0918', '2026-03-02 10:15:00')");
    $db->exec("INSERT INTO users (id, full_name, email, password_hash, role, emp_id, branch, scope_type) VALUES
        (1, 'Admin', 'admin@qa', 'x', 'admin', NULL, '', 'branch'),
        (2, 'QA Manager', 'mgr@qa', 'x', 'manager', NULL, '', 'custom'),
        (3, 'ANA REYES', 'ana@qa', 'x', 'earner', 'EMP-001', '', 'branch'),
        (4, 'DANTE LIM', 'dante@qa', 'x', 'earner', 'EMP-004', '', 'branch'),
        (5, 'North Manager', 'north@qa', 'x', 'manager', '', 'NORTH', 'branch')");
    $db->exec("INSERT INTO manager_employees (manager_id, emp_id) VALUES (2, 'EMP-001'), (2, 'EMP-004')");
    $db->exec("INSERT INTO biometric_employee_map (device_user_id, emp_id, device_id) VALUES ('00001', 'EMP-001', 'A6'), ('00004', 'EMP-004', 'A6')");
}

/** every row of a table, keyed by its primary key, every column as MySQL returns it */
function qa14_rows(PDO $db, string $table): array
{
    $pk = QA14_TABLES[$table] ?? 'id';
    $out = [];
    foreach ($db->query("SELECT * FROM `$table` ORDER BY `$pk`")->fetchAll() as $r) $out[(string)$r[$pk]] = $r;
    return $out;
}

/** every table of the database, rows and all */
function qa14_snapshot(PDO $db): array
{
    $out = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pk = QA14_TABLES[$t] ?? null;
        $rows = $db->query("SELECT * FROM `$t`")->fetchAll();
        if ($pk === null) sort($rows);
        else { $k = []; foreach ($rows as $r) $k[(string)$r[$pk]] = $r; ksort($k); $rows = $k; }
        $out[$t] = $rows;
    }
    return $out;
}

/** the columns, indexes and foreign keys of the ten tables - the layout, without the data */
function qa14_layout(PDO $db): array
{
    $in = "'" . implode("','", array_keys(QA14_TABLES)) . "'";
    $cols = $db->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in) AND COLUMN_NAME IN ('emp_id', 'employee_id')
                         ORDER BY TABLE_NAME, COLUMN_NAME")->fetchAll(PDO::FETCH_NUM);
    $idx = $db->query("SELECT TABLE_NAME, INDEX_NAME, MAX(NON_UNIQUE), GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
                         FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)
                        GROUP BY TABLE_NAME, INDEX_NAME ORDER BY TABLE_NAME, INDEX_NAME")->fetchAll(PDO::FETCH_NUM);
    $fks = $db->query("SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
                         FROM information_schema.KEY_COLUMN_USAGE k
                         JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ($in) AND k.REFERENCED_TABLE_NAME IS NOT NULL
                        ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME")->fetchAll(PDO::FETCH_NUM);
    return ['columns' => array_map(fn($r) => implode(' ', $r), $cols), 'indexes' => array_map(fn($r) => implode(' ', $r), $idx),
            'foreign_keys' => array_map(fn($r) => implode(' ', $r), $fks)];
}

/** emp_id the way MySQL compares them (capitals and trailing spaces do not count) */
function qa14_same_id(?string $a, ?string $b): bool
{
    return $a !== null && $b !== null && strcasecmp(rtrim($a), rtrim($b)) === 0;
}

/** the migration file in parts */
function qa14_sql(string $part): string
{
    static $file = null;
    $file ??= (string)file_get_contents(AppCopy::root() . '/sql/migrate-employee-id.sql');
    return match ($part) {
        'A'  => SqlScript::section($file, 'PART A - additive', 'END OF PART A'),
        'B'  => SqlScript::section($file, 'PART B - the switch', 'END OF PART B'),
        'C'  => SqlScript::section($file, 'PART C - checks', 'END OF PART C'),
        'A6' => SqlScript::section($file, '-- A6. CHECK', '-- IF A6 SHOWS ROWS - decide'),
        'remedy1' => SqlScript::uncomment($file, '-- INSERT INTO employees (emp_id, full_name, status)', 'GROUP BY u.emp_id;'),
        'remedy3' => SqlScript::uncomment($file, '-- CREATE TABLE IF NOT EXISTS biometric_daily_unmatched', '-- COMMIT;'),
        'rollback' => (string)file_get_contents(AppCopy::root() . '/sql/rollback-employee-id.sql'),
        'all' => $file,
    };
}

/** the rows the A6 report lists ([] when it lists none) */
function qa14_a6(PDO $db): array
{
    $r = SqlScript::run($db, qa14_sql('A6'));
    if ($r['errors']) throw new RuntimeException('A6: ' . $r['errors'][0][1]);
    $rows = [];
    foreach ($r['results'] as [$stmt, $set]) foreach ($set as $row) if (isset($row['problem'])) $rows[] = $row;
    return $rows;
}

T::suite('14 · The employee_id migration (sql/migrate-employee-id.sql, rollback-employee-id.sql)', function () {
    $S = new stdClass();     // what one test leaves for the next

    T::test('A - Part A only adds: backup copies identical to the tables, employee_id filled in (other capitals, a trailing space, mixed collations), the old layout untouched, and the report lists the rows without an employee', function (T $t) use ($S) {
        $db = qa14_old_database(QA14_DB);
        qa14_seed($db);
        $S->db = $db;
        $S->before = [];
        foreach (QA14_TABLES as $tbl => $pk) $S->before[$tbl] = qa14_rows($db, $tbl);
        $S->beforeEmployees = qa14_rows($db, 'employees');
        $S->layoutBefore = qa14_layout($db);

        $r = SqlScript::run($db, qa14_sql('A'));
        $t->same([], $r['errors'], 'Part A runs without one error');

        foreach (QA14_TABLES as $tbl => $pk) {
            $now = qa14_rows($db, $tbl);
            $t->same(array_keys($S->before[$tbl]), array_keys($now), "$tbl: the same rows");
            foreach ($now as $k => $row) {
                $old = $S->before[$tbl][$k];
                $t->same($old, array_diff_key($row, ['employee_id' => 1]), "$tbl #$k: every old column unchanged (emp_id still there)");
                $want = null;
                foreach ($S->beforeEmployees as $e) if (qa14_same_id($old['emp_id'], $e['emp_id'])) $want = (int)$e['id'];
                $t->same($want, $row['employee_id'] === null ? null : (int)$row['employee_id'], "$tbl #$k: employee_id for '{$old['emp_id']}'");
            }
            // the backup copy holds exactly the rows (and columns) as they were
            $bak = $db->query("SELECT * FROM `bak_emp_$tbl` ORDER BY `$pk`")->fetchAll();
            $t->same(array_values($S->before[$tbl]), $bak, "bak_emp_$tbl is an exact copy");
        }
        $t->same(23, (int)$db->query("SELECT employee_id FROM payroll WHERE emp_id = 'emp-005 ' LIMIT 1")->fetchColumn(), "'emp-005 ' is HASMIN DIEGA (EMP-005, id 23)");
        $t->same(null, $db->query("SELECT employee_id FROM users WHERE id = 1")->fetchColumn() ?: null, 'the admin has no employee');
        $t->same(array_values($S->beforeEmployees), $db->query('SELECT * FROM bak_emp_employees ORDER BY id')->fetchAll(), 'employees copied too');
        $t->same(1, (int)$db->query("SELECT COUNT(*) FROM bak_emp_foreign_keys WHERE table_name = 'employee_profiles' AND constraint_name = 'fk_profile_emp'
                                       AND delete_rule = 'CASCADE' AND update_rule = 'CASCADE'")->fetchColumn(), 'the foreign key on emp_id is recorded with its rules');
        $t->ok((int)$db->query("SELECT COUNT(*) FROM bak_emp_indexes")->fetchColumn() >= 10, 'the indexes on emp_id are recorded');
        $t->same($S->layoutBefore['indexes'], array_values(array_filter(qa14_layout($db)['indexes'], fn($i) => true)), 'no index added or removed by Part A');

        // the report: per table, and the rows without an employee
        $rep = qa14_a6($db);
        $found = array_map(fn($x) => $x['table_name'] . ' ' . trim($x['emp_id']) . ' ' . $x['row_count'], $rep);
        sort($found);
        $t->same(['biometric_daily 00007 1', 'bonus_deduction_history EMP-099 1'], $found, 'A6 lists the terminal number and the deleted employee, nothing else');
        $S->db = $db;
    });

    T::test('B - while rows have no employee, Part B stops at its guard and changes NOTHING - even with every error ignored', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        $snap = qa14_snapshot($db);
        $layout = qa14_layout($db);
        $r = SqlScript::run($db, qa14_sql('B'));                    // "Ignore All": every statement runs, errors or not
        // (MySQL on Windows writes table names in small letters, so the message is compared that way)
        $t->ok(count($r['errors']) >= 1 && stripos($r['errors'][0][1], 'STOP - 2 unlinked, 0 twice, 0 unconverted') !== false,
               'the guard stops with a message naming the problem (the terminal-number day and the deleted employee\'s bonus): ' . ($r['errors'][0][1] ?? 'no error'));
        $t->same(3, count($r['errors']), 'the only errors are the guard\'s own PREPARE / EXECUTE / DEALLOCATE');
        $t->same($layout, qa14_layout($db), 'layout unchanged: emp_id still in every table, no foreign key added');
        $t->same($snap, qa14_snapshot($db), 'data unchanged');
        $guard = SqlScript::resultWith($r, ['switch_now']);
        $t->same('NO - stopped, nothing below runs', $guard[0]['switch_now'] ?? null, 'the result tab says it stopped');
        // and the same with "Stop" (DBeaver's default answer)
        $r = SqlScript::run($db, qa14_sql('B'), true);
        $t->same(1, count($r['errors']));
        $t->same($layout, qa14_layout($db));
    });

    T::test('C - after the remedies, Part B switches: no emp_id left, foreign keys with their rules, unique keys, and every row unchanged and pointing at the same employee', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        // remedy (3): the terminal-number day is moved aside, unchanged
        $r = SqlScript::run($db, qa14_sql('remedy3'));
        $t->same([], $r['errors'], 'remedy (3) runs');
        $aside = $db->query('SELECT * FROM biometric_daily_unmatched')->fetchAll();
        $t->same(1, count($aside));
        $orphan = array_values(array_filter($S->before['biometric_daily'], fn($x) => $x['emp_id'] === '00007'))[0];
        $t->same($orphan, array_diff_key($aside[0], ['employee_id' => 1]), 'the day is kept as it was, in biometric_daily_unmatched');
        // the remedies say to run Part A's report again: the whole of Part A runs again cleanly, and leaves the backups alone
        $bakBefore = $db->query('SELECT * FROM bak_emp_biometric_daily ORDER BY id')->fetchAll();
        $r = SqlScript::run($db, qa14_sql('A'));
        $t->same([], $r['errors'], 'Part A runs again before Part B without an error');
        $t->same($bakBefore, $db->query('SELECT * FROM bak_emp_biometric_daily ORDER BY id')->fetchAll(), 'the backup copy is not touched by the second run');
        $t->same(1, count($blocked = qa14_a6($db)), 'A6 still lists the deleted employee');
        // the guard still holds while one row is left
        $r = SqlScript::run($db, qa14_sql('B'));
        $t->ok(stripos($r['errors'][0][1] ?? '', 'STOP - 1 unlinked') !== false, 'still stopped for EMP-099: ' . ($r['errors'][0][1] ?? 'no error'));
        $t->same(3, count($r['errors']));
        // remedy (1): the deleted employee comes back as Inactive, with the name from the records
        $r = SqlScript::run($db, qa14_sql('remedy1'));
        $t->same([], $r['errors'], 'remedy (1) runs');
        $rosa = $db->query("SELECT * FROM employees WHERE emp_id = 'EMP-099'")->fetch();
        $t->same(['ROSA TAN', 'Inactive'], [$rosa['full_name'] ?? null, $rosa['status'] ?? null], 'EMP-099 is back as an Inactive employee');

        $r = SqlScript::run($db, qa14_sql('B'));
        $t->same([], $r['errors'], 'Part B runs without one error');
        $t->same('yes - going ahead', SqlScript::resultWith($r, ['switch_now'])[0]['switch_now'] ?? null);

        // the layout
        $L = qa14_layout($db);
        $expectFk = [
            'attendance' => ['fk_att_employee', 'RESTRICT'], 'payroll' => ['fk_payroll_employee', 'RESTRICT'],
            'bonus_deduction_history' => ['fk_bdh_employee', 'RESTRICT'], 'biometric_daily' => ['fk_daily_employee', 'RESTRICT'],
            'leave_requests' => ['fk_leave_employee', 'RESTRICT'], 'payslip_signatures' => ['fk_sig_employee', 'RESTRICT'],
            'employee_profiles' => ['fk_profile_employee', 'CASCADE'], 'manager_employees' => ['fk_mgr_employee', 'CASCADE'],
            'users' => ['fk_users_employee', 'CASCADE'], 'biometric_employee_map' => ['fk_biomap_employee', 'CASCADE'],
        ];
        foreach ($expectFk as $tbl => [$fk, $rule]) {
            $t->ok(!in_array("$tbl emp_id", array_map(fn($c) => implode(' ', array_slice(explode(' ', $c), 0, 2)), $L['columns']), true), "$tbl has no emp_id");
            $t->ok(in_array("$tbl employee_id int " . ($tbl === 'users' ? 'YES' : 'NO'), $L['columns'], true), "$tbl.employee_id INT " . ($tbl === 'users' ? 'NULL' : 'NOT NULL'));
            $t->ok(in_array("$tbl $fk employee_id employees id CASCADE $rule", $L['foreign_keys'], true), "$tbl: $fk -> employees(id) ON UPDATE CASCADE ON DELETE $rule");
        }
        foreach (['payroll uq_payroll_period_emp 0 period_id,employee_id', 'attendance uq_attendance_period_emp 0 period_id,employee_id',
                  'biometric_daily uq_daily 0 period_id,employee_id,att_date', 'employee_profiles uq_profile_emp 0 employee_id',
                  'manager_employees uq_mgr_emp 0 manager_id,employee_id', 'payslip_signatures uq_sig_payroll 0 payroll_id'] as $key) {
            $t->ok(in_array($key, $L['indexes'], true), "unique key $key");
        }
        $t->ok(!str_contains(implode("\n", $L['foreign_keys']), 'employee_profiles fk_profile_emp emp_id'), 'fk_profile_emp (to employees.emp_id) is gone');
        foreach (['payslip_signatures fk_sig_payroll payroll_id payroll id', 'payslip_signatures fk_sig_period period_id payroll_periods id',
                  'biometric_daily fk_daily_period period_id payroll_periods id', 'payroll fk_payroll_period period_id payroll_periods id',
                  'attendance fk_att_period period_id payroll_periods id'] as $kept) {
            $t->ok(str_contains(implode("\n", $L['foreign_keys']), $kept), "kept: $kept");
        }

        // the data: every row, every other value, the same employee
        $emp = [];
        foreach ($db->query('SELECT id, emp_id FROM employees')->fetchAll() as $e) $emp[(int)$e['id']] = $e['emp_id'];
        foreach (QA14_TABLES as $tbl => $pk) {
            $now = qa14_rows($db, $tbl);
            $was = $S->before[$tbl];
            if ($tbl === 'biometric_daily') $was = array_filter($was, fn($x) => $x['emp_id'] !== '00007');
            $t->same(array_keys($was), array_keys($now), "$tbl: every row is still there");
            foreach ($now as $k => $row) {
                $old = $was[$k] ?? [];
                $t->same(array_diff_key($old, ['emp_id' => 1]), array_diff_key($row, ['employee_id' => 1]), "$tbl #$k: every other value unchanged");
                if ($tbl === 'users' && trim((string)$old['emp_id']) === '') {
                    $t->same(null, $row['employee_id'], "$tbl #$k: no employee");
                } else {
                    $t->ok(qa14_same_id($old['emp_id'], $emp[(int)$row['employee_id']] ?? null), "$tbl #$k: still {$old['emp_id']}");
                }
            }
        }

        // Part C: every backed-up row still there, the same employee (the day moved aside is the one missing)
        $r = SqlScript::run($db, qa14_sql('C'));
        $t->same([], $r['errors'], 'Part C runs');
        $c1 = SqlScript::resultWith($r, ['table_name', 'missing_now', 'other_employee_now']);
        $t->same(10, count($c1 ?? []), 'C1: one line per table');
        foreach ($c1 ?? [] as $line) {
            $t->same([$line['table_name'] === 'biometric_daily' ? 1 : 0, 0], [(int)$line['missing_now'], (int)$line['other_employee_now']], "C1 {$line['table_name']}");
        }
        $S->migrated = qa14_snapshot($db);
        $S->migratedLayout = qa14_layout($db);
    });

    T::test('D - running Part A and Part B again changes nothing', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        $layout = qa14_layout($db);
        $r = SqlScript::run($db, qa14_sql('A'));
        $t->same([], $r['errors'], 'Part A again: no error');
        $r = SqlScript::run($db, qa14_sql('B'));
        $t->same([], $r['errors'], 'Part B again: no error');
        $t->same($layout, qa14_layout($db), 'layout unchanged');
        $t->same($S->migrated, qa14_snapshot($db), 'data unchanged, backups included');
    });

    T::test('E - rollback-employee-id.sql brings back the old layout, its indexes and foreign keys and the same data; the migration then runs again', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        $r = SqlScript::run($db, qa14_sql('rollback'));
        $t->same([], $r['errors'], 'the rollback runs without one error');
        $L = qa14_layout($db);
        $t->same($S->layoutBefore['indexes'], $L['indexes'], 'every index as it was (names, columns, unique or not)');
        $t->same($S->layoutBefore['foreign_keys'], $L['foreign_keys'], 'every foreign key as it was - fk_profile_emp to employees.emp_id with its rules');
        foreach ($L['columns'] as $c) $t->ok(str_contains($c, ' emp_id varchar(20) '), "back: $c");
        $t->same(count(QA14_TABLES), count($L['columns']), 'emp_id in all ten tables, employee_id in none');
        $emp = [];
        foreach ($db->query('SELECT emp_id FROM employees')->fetchAll(PDO::FETCH_COLUMN) as $e) $emp[] = $e;
        foreach (QA14_TABLES as $tbl => $pk) {
            $now = qa14_rows($db, $tbl);
            $was = $S->before[$tbl];
            if ($tbl === 'biometric_daily') $was = array_filter($was, fn($x) => $x['emp_id'] !== '00007');
            $t->same(array_keys($was), array_keys($now), "$tbl: every row");
            foreach ($now as $k => $row) {
                $old = $was[$k];
                $t->same(array_diff_key($old, ['emp_id' => 1]), array_diff_key($row, ['emp_id' => 1]), "$tbl #$k: every other value");
                if ($tbl === 'users' && trim((string)$old['emp_id']) === '') { $t->same(null, $row['emp_id'], "$tbl #$k: no employee"); continue; }
                $t->ok(qa14_same_id($old['emp_id'], $row['emp_id']) && in_array($row['emp_id'], $emp, true),
                       "$tbl #$k: '{$old['emp_id']}' comes back as the employee's own '{$row['emp_id']}'");
            }
        }
        $t->same('EMP-005', $db->query("SELECT DISTINCT emp_id FROM payroll WHERE emp_name = 'HASMIN DIEGA'")->fetchColumn(), "'emp-005 ' is written EMP-005 now");

        // and forward again
        $r = SqlScript::run($db, qa14_sql('A'));
        $t->same([], $r['errors'], 'Part A after the rollback');
        $t->same([], qa14_a6($db), 'nothing unlinked');
        $r = SqlScript::run($db, qa14_sql('B'));
        $t->same([], $r['errors'], 'Part B after the rollback');
        $t->same($S->migratedLayout ?? null, qa14_layout($db), 'the same layout as the first time');
        $t->same(0, (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'emp_id' AND TABLE_NAME <> 'employees' AND TABLE_NAME NOT LIKE 'bak\\_emp\\_%' AND TABLE_NAME <> 'biometric_daily_unmatched'")->fetchColumn(),
                 'converted again');
    });

    T::test('F - the application of 2026-10-15 on, whose layout is the standard one, stops with what to do on a database in this layout - and on one older than it', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        // the migrated (employee_id) layout is still not the standard one: the app does not run on it, it says which script converts it
        $r = Http::call('dashboard.php', ['method' => 'GET', 'session' => Http::admin(), 'env' => ['DB_NAME' => QA14_DB]]);
        // (a web server gets this as a 503 page; here the page runs from the command line, where showAppError() writes it to stderr)
        $t->contains('one-time update', $r['stderr']);
        $t->contains('migrate-standard-schema.sql', $r['stderr']);
        $t->notContains('migrate-employee-id.sql', $r['stderr'], 'the first conversion is already done');
        $t->same(0, (int)$db->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'schema_version'")->fetchColumn(), 'nothing was changed or remembered on it');

        // a database that was never migrated: both scripts, in order
        qa14_old_database(QA14_OLD);
        $r = Http::call('dashboard.php', ['method' => 'GET', 'session' => Http::admin(), 'env' => ['DB_NAME' => QA14_OLD]]);
        $t->contains('one-time update', $r['stderr']);
        $t->contains('migrate-employee-id.sql and then sql/migrate-standard-schema.sql', $r['stderr']);
        $t->notContains('Dashboard', strip_tags($r['body']), 'the dashboard itself did not run');
        $t->same(0, (int)TestDb::root(QA14_OLD)->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'schema_version'")->fetchColumn(),
                 'nothing was changed or remembered on it');
        TestDb::root()->exec('DROP DATABASE ' . QA14_OLD);
    });

    T::test('G - the foreign keys hold: history stops a delete, what belongs to the employee goes with them, a renumbered employee keeps every record', function (T $t) use ($S) {
        $db = $S->db ?? T::skip('needs test A');
        // ANA (id 11) has payroll: the database refuses
        $code = null;
        try { $db->exec('DELETE FROM employees WHERE id = 11'); } catch (PDOException $e) { $code = (int)($e->errorInfo[1] ?? 0); }
        $t->same(1451, $code, 'deleting an employee with payroll is refused (1451)');
        // DANTE (id 17) has no history: his portal login, profile, manager assignment and device number go with him
        $db->exec('DELETE FROM employees WHERE id = 17');
        foreach (['users', 'employee_profiles', 'manager_employees', 'biometric_employee_map'] as $tbl) {
            $t->same(0, (int)$db->query("SELECT COUNT(*) FROM `$tbl` WHERE employee_id = 17")->fetchColumn(), "$tbl: DANTE's row went with him");
        }
        $t->same(1, (int)$db->query("SELECT COUNT(*) FROM manager_employees WHERE employee_id = 11")->fetchColumn(), 'the manager keeps ANA');
        // BEN (id 12) renumbered: every record follows (ON UPDATE CASCADE)
        $counts = fn(int $id) => array_map(fn($tbl) => (int)$db->query("SELECT COUNT(*) FROM `$tbl` WHERE employee_id = $id")->fetchColumn(),
                                           ['payroll', 'attendance', 'biometric_daily', 'leave_requests', 'bonus_deduction_history']);
        $was = $counts(12);
        $db->exec('UPDATE employees SET id = 112 WHERE id = 12');
        $t->same($was, $counts(112), 'payroll, attendance, days, leave and history follow the new id');
        $t->same([0, 0, 0, 0, 0], $counts(12));
        // a row for an employee who does not exist cannot be written any more
        $code = null;
        try { $db->exec("INSERT INTO payroll (period_id, employee_id, emp_name) VALUES (3, 9999, 'Nobody')"); } catch (PDOException $e) { $code = (int)($e->errorInfo[1] ?? 0); }
        $t->same(1452, $code, 'a payroll line for a missing employee is refused (1452)');
        TestDb::root()->exec('DROP DATABASE ' . QA14_DB);
    });
});
