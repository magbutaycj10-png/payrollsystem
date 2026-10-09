<?php
/*
 * 12 - The audit queries you run yourself in DBeaver (tests/dbeaver_checks.sql), tested here on a MySQL 8 database so you
 * can trust what they say about the live one.
 *
 *   · the file parses: every check has an id, a title, an expectation and exactly one statement
 *   · on correctly computed payroll - nine months, every salary type and run shape, bonuses, a finalized period - every
 *     "expect: none" query returns no rows (on the audit-fixed application)
 *   · the same queries run on the ORIGINAL application's payroll flag exactly the two things it gets wrong in this data
 *     (tax over-withheld and not returned, a salaried employee paid for days before the hire date)
 *   · for every query, deliberate damage is injected (inside a transaction that is rolled back) and the query must find it
 */

T::suite('12 · DBeaver audit queries (tests/dbeaver_checks.sql)', function () {

    T::test('the SQL file parses: every check has an id, a title, a pass/fail expectation and one SELECT statement', function (T $t) {
        $checks = qa_sql_checks();
        $t->ok(count($checks) >= 20, 'checks found: ' . count($checks));
        $ids = array_column($checks, 'id');
        $t->same(count($ids), count(array_unique($ids)), 'ids are unique');
        foreach ($checks as $c) {
            $t->ok($c['id'] !== '' && $c['title'] !== '' && in_array($c['expect'], ['none', 'review', 'info'], true), "heading of {$c['id']}");
            $code = preg_replace('/^\s*--.*$/m', '', $c['sql']);
            $t->same(1, substr_count(rtrim($code, "; \t\r\n"), ';') === 0 ? 1 : 0, "{$c['id']} holds exactly one statement");
            $t->ok(preg_match('/^\s*(WITH|SELECT)\b/i', trim($code)) === 1, "{$c['id']} is a SELECT");
            $t->ok(preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE)\b/i', preg_replace("/'[^']*'/", "''", $code)) === 0, "{$c['id']} changes nothing");
        }
    });

    T::test('on correctly computed payroll every "expect: none" query returns no rows (and the corrected tax refund shows up as a finding)', function (T $t) {
        if (!AppCopy::hasFixes()) T::skip('the original application computes two things wrongly in this data - see the next test');
        $set = qa_sql_dataset();
        $problems = [];
        foreach (qa_sql_checks() as $c) {
            $rows = qa_sql_run($c);
            $t->checks++;
            if ($c['expect'] === 'none' && $rows) $problems[] = $c['id'] . ' (' . count($rows) . ' row(s)): ' . json_encode($rows[0], JSON_UNESCAPED_UNICODE);
        }
        $t->same([], $problems, 'queries that should have been silent');
        $byId = array_column(qa_sql_checks(), null, 'id');
        $t->same(1, count(qa_sql_run($byId['C04'])), 'C04 lists the May tax refund');
        $t->same([], qa_sql_run($byId['C51']), 'C51: the June hire is not paid for the days before 9 June (absent days were deducted)');
        $r01 = qa_sql_run($byId['R01']);
        $t->same(9, count($r01), 'R01: nine months');
        foreach ($r01 as $row) $t->money(0, $row['unexplained_difference'], 'R01 ' . $row['month'] . ' foots');
        // the month report agrees with the figures worked out by hand for July (Example H) - gross 250,000.00, tax 57,206.70, net 188,343.30
        $jul = array_values(array_filter($r01, fn($r) => $r['month'] === '2026-07'))[0];
        $t->moneyMap(['gross_pay' => 25000000, 'withholding_tax' => 5720670, 'net_pay' => 18834330], $jul, 'R01 July');
    });

    T::test('on the ORIGINAL application\'s payroll the queries find exactly its two known mistakes: over-withheld tax (D-02) and pay for days before the hire date (D-03)', function (T $t) {
        if (AppCopy::hasFixes()) T::skip('this is what the original application does - the fixed one is checked above');
        qa_sql_dataset();
        $byId = array_column(qa_sql_checks(), null, 'id');
        $problems = [];
        foreach (qa_sql_checks() as $c) {
            $rows = qa_sql_run($c);
            $t->checks++;
            if ($c['expect'] === 'none' && $rows && $c['id'] !== 'C13') $problems[] = $c['id'] . ': ' . json_encode($rows[0], JSON_UNESCAPED_UNICODE);
        }
        $t->same([], $problems, 'apart from the tax query, nothing should be flagged on correctly computed pay');
        $tax = qa_sql_run($byId['C13']);
        $t->same(1, count($tax), 'one employee-month has the wrong tax: ' . json_encode($tax));
        $t->same('2026-05', $tax[0]['month'] ?? null, 'May - the first cut-off took ₱289.95 and the month owes nothing');
        $t->money('289.95', $tax[0]['over_withheld'] ?? 0, 'over-withheld, never returned');
        $hire = qa_sql_run($byId['C51']);
        $t->same(1, count($hire), 'C51 finds the June hire paid in full: ' . json_encode($hire));
        $t->money('26000.00', $hire[0]['gross_pay'] ?? 0, 'paid the whole month although hired on the 9th');
    });

    T::test('each query finds the damage injected for it (every injection is rolled back)', function (T $t) {
        qa_sql_dataset();
        $db = getDB();
        $byId = array_column(qa_sql_checks(), null, 'id');
        $emp = fn(string $like) => (string)$db->query("SELECT emp_id FROM employees ORDER BY id LIMIT 1 OFFSET " . (int)$like)->fetchColumn();
        // the employees were created in the order jan feb mar apr may jun jul aug sep
        [$jan, $feb, $mar, $apr, $may, $jun, $jul, $aug, $sep] = array_map($emp, range(0, 8));
        $line = fn(string $e, int $nth = 0) => (int)$db->query("SELECT p.id FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id WHERE p.emp_id = '$e' ORDER BY pp.period_start LIMIT 1 OFFSET $nth")->fetchColumn();
        $lastLine = fn(string $e) => (int)$db->query("SELECT p.id FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id WHERE p.emp_id = '$e' ORDER BY pp.period_start DESC LIMIT 1")->fetchColumn();

        $damage = [
            // check => [SQL statements that damage the data, a way to say how the finding is recognised]
            'C01' => ["UPDATE payroll SET net_pay = net_pay + 0.01 WHERE id = {$line($mar)}"],
            'C02' => ["UPDATE payroll SET net_pay = -25.50 WHERE id = {$line($mar)}"],
            'C03' => ["UPDATE payroll SET sss = -1 WHERE id = {$line($mar)}"],
            'C04' => ["UPDATE payroll SET withholding_tax = -10 WHERE id = {$line($mar)}"],
            'C05' => ["UPDATE attendance SET gross_pay = gross_pay + 1 WHERE emp_id = '$mar'"],
            'C06' => ["UPDATE payroll SET status = 'Draft' WHERE emp_id = '$jan'"],
            'C10' => ["UPDATE payroll SET sss = sss + 0.01 WHERE id = {$lastLine($jul)}"],
            'C11' => ["UPDATE payroll SET philhealth = philhealth + 0.01 WHERE id = {$lastLine($mar)}"],
            'C12' => ["UPDATE payroll SET pagibig = pagibig + 0.01 WHERE id = {$lastLine($mar)}"],
            'C13' => ["UPDATE payroll SET withholding_tax = withholding_tax + 0.01 WHERE id = {$lastLine($jul)}"],
            'C20' => ["UPDATE biometric_daily SET hours_worked = 30 WHERE emp_id = '$feb' LIMIT 1"],
            'C21' => ["UPDATE payroll SET hours_worked = 9999 WHERE id = {$line($mar)}"],
            'C22' => ["INSERT INTO payroll_periods (period_label, period_start, period_end, period_type, status) VALUES ('Dup', '2026-02-01', '2026-02-14', 'Semi-Monthly', 'Open')",
                      "INSERT INTO biometric_daily (period_id, emp_id, emp_name, att_date, hours_worked) SELECT LAST_INSERT_ID(), emp_id, emp_name, att_date, hours_worked FROM biometric_daily WHERE emp_id = '$feb' LIMIT 1"],
            'C23' => ["INSERT INTO payroll_periods (period_label, period_start, period_end, period_type, status) VALUES ('Overlap', '2026-03-10', '2026-03-20', 'Semi-Monthly', 'Open')"],
            'C30' => ["UPDATE employees SET base_salary = -500 WHERE emp_id = '$feb'"],
            'C31' => ["INSERT INTO settings (setting_key, setting_value) VALUES ('overtime_rate', '-45') ON DUPLICATE KEY UPDATE setting_value = '-45'"],
            'C40' => ["UPDATE payroll SET bonus = bonus + 5 WHERE id = {$line($mar)}"],
            'C41' => ["INSERT INTO bonus_deduction_history (entry_date, emp_id, emp_name, entry_type, amount, reason, period_id) VALUES (CURDATE(), '$mar', 'x', 'Bonus', 0, 'x', NULL)"],
            'C42' => ["UPDATE payroll SET bonus = 95000 WHERE id = {$line($mar)}"],
            'C50' => [],                                                              // the dataset pays a flat ₱45 an hour: flagged as it is
            'C60' => ["INSERT INTO bonus_deduction_history (entry_date, emp_id, emp_name, entry_type, amount, reason, period_id) VALUES (CURDATE(), '$mar', 'x', 'Bonus', 99999999, '13th Month Pay 2026', NULL)"],
            'C51' => ["UPDATE employees SET date_hired = '2026-03-20' WHERE emp_id = '$mar'"],
            'C52' => ["UPDATE employees SET date_hired = '2030-01-01' WHERE emp_id = '$mar'"],
        ];
        foreach ($damage as $id => $sqls) {
            if (!$sqls) continue;                                                     // C50: checked below (nothing to damage)
            $t->checks++;
            $before = count(qa_sql_run($byId[$id]));
            $db->beginTransaction();
            try {
                foreach ($sqls as $s) $db->exec($s);
                $after = qa_sql_run($byId[$id]);
            } finally {
                $db->rollBack();
            }
            $t->ok(count($after) > $before, "$id did not notice the damage (rows before $before, after " . count($after) . ')');
            $t->same($before, count(qa_sql_run($byId[$id])), "$id: the injected damage was rolled back");
        }
        // a Labor Code overtime method silences the compliance query
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('overtime_method', 'labor_code') ON DUPLICATE KEY UPDATE setting_value = 'labor_code'");
            $t->same([], qa_sql_run($byId['C50']), 'C50 is silent once overtime is paid by the Labor Code method');
        } finally {
            $db->rollBack();
        }
        $t->ok(count(qa_sql_run($byId['C50'])) >= 3, 'C50 lists the employees whose overtime the flat ₱45 underpays');
    });
});
