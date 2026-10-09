<?php
/*
 * 11 - More numbers, and what the audit fixes added.
 *
 *   A  high-bracket pay: ₱250,000 and ₱800,000 a month, a ₱1,500 daily rate - every figure worked out by hand from the published tables
 *   B  bonuses and deductions: untaxed, net = gross + bonus − deductions, entries accumulate to the centavo; the ₱90,000 yearly
 *      ceiling for tax-exempt benefits; a deduction may not turn a payslip negative
 *   C  input limits on every door: day upload, totals file, manager's manual entry, Employee Management, Settings, Adjustments
 *   D  the Labor Code overtime method, and the Settings warning while the flat rate underpays
 *   E  a tax refund end to end: payslip, register
 *   F  the labor-cost series of the forecast equals the employer shares worked out independently
 *   G  hire-date proration, Recompute, the stale-figures and negative-net guards of Finalize, no schema changes
 *
 * A and the first three B tests run against either version of the app. Everything else describes behaviour the audit fixes added
 * and is skipped on an older copy of the app that lacks it (--app=<older folder>).
 */
require_once AppCopy::root() . '/includes/bir-print.php';

T::suite('11 · More numbers & the audit fixes', function () {

    /* ================================================================== A · high brackets, by hand */

    T::test('Example H - monthly ₱250,000: SSS credit capped at ₱35,000, PhilHealth and Pag-IBIG capped, tax in the 30% bracket', function (T $t) {
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '250000.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger on every figure');
        // gross 250,000.00 · SSS 5% × 35,000 = 1,750.00 · PhilHealth 2.5% × 100,000 = 2,500.00 · Pag-IBIG 2% × 10,000 = 200.00
        // taxable 250,000 − 4,450 = 245,550.00 → 33,541.80 + 30% × (245,550 − 166,667) = 33,541.80 + 23,664.90 = 57,206.70
        // net 250,000 − 4,450 − 57,206.70 = 188,343.30
        $t->moneyMap(['gross_pay' => 25000000, 'sss' => 175000, 'philhealth' => 250000, 'pagibig' => 20000,
                      'withholding_tax' => 5720670, 'net_pay' => 18834330], $row, 'Example H by hand');
    });

    T::test('Example I - monthly ₱800,000: the 35% bracket (over ₱666,667 a month)', function (T $t) {
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '800000.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger on every figure');
        // taxable 800,000 − 4,450 = 795,550.00 → 183,541.80 + 35% × (795,550 − 666,667) = 183,541.80 + 45,109.05 = 228,650.85
        // net 800,000 − 4,450 − 228,650.85 = 566,899.15
        $t->moneyMap(['gross_pay' => 80000000, 'sss' => 175000, 'philhealth' => 250000, 'pagibig' => 20000,
                      'withholding_tax' => 22865085, 'net_pay' => 56689915], $row, 'Example I by hand');
    });

    T::test('Example K - daily rate ₱1,500 × 26 days = ₱39,000: PhilHealth on the pay, the 20% bracket', function (T $t) {
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '1500.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger on every figure');
        // SSS 5% × 35,000 = 1,750.00 · PhilHealth 2.5% × 39,000 = 975.00 · Pag-IBIG 200.00 → 2,925.00
        // taxable 36,075.00 → 1,875.00 + 20% × (36,075 − 33,333) = 1,875.00 + 548.40 = 2,423.40 · net 39,000 − 2,925 − 2,423.40 = 33,651.60
        $t->moneyMap(['gross_pay' => 3900000, 'sss' => 175000, 'philhealth' => 97500, 'pagibig' => 20000,
                      'withholding_tax' => 242340, 'net_pay' => 3365160], $row, 'Example K by hand');
    });

    /* ================================================================== B · bonuses and deductions */

    T::test('a bonus is not taxed and moves no contribution: net rises by exactly the bonus, everything else stays', function (T $t) {
        Fixtures::reset();
        $e = qa_worker('Bonus Case', '2026-04-01', '2026-04-15');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Bonus Case', '2026-04-01', '2026-04-15'));
        $before = Fixtures::payroll($pid)[$e];
        qa_adjust($pid, [$e], 'Bonus', '3000');
        $after = Fixtures::payroll($pid)[$e];
        $t->money(Ledger::c($before['net_pay']) + 300000, $after['net_pay'], 'net pay rises by exactly ₱3,000.00');
        $t->money('3000.00', $after['bonus'], 'bonus column');
        foreach (['gross_pay', 'sss', 'philhealth', 'pagibig', 'withholding_tax'] as $k) $t->same($before[$k], $after[$k], "$k is untouched by a bonus");
        $row = Http::page('adjustments.php', [], ['period' => $pid])['body'];
        $t->contains('3,000.00', $row, 'the Adjustments page shows the entry in its period summary');
    });

    T::test('bonus and deduction entries accumulate to the centavo: bonus 1,000.50 + 499.50, deduction 200.25', function (T $t) {
        Fixtures::reset();
        $e = qa_worker('Accumulate', '2026-04-01', '2026-04-15');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Accumulate', '2026-04-01', '2026-04-15'));
        $net0 = Ledger::c(Fixtures::payroll($pid)[$e]['net_pay']);
        qa_adjust($pid, [$e], 'Bonus', '1000.50');
        qa_adjust($pid, [$e], 'Bonus', '499.50');
        qa_adjust($pid, [$e], 'Deduction', '200.25');
        $row = Fixtures::payroll($pid)[$e];
        $t->money('1500.00', $row['bonus'], 'bonus 1,000.50 + 499.50');
        $t->money('200.25', $row['other_deductions'], 'deduction');
        $t->money($net0 + 150000 - 20025, $row['net_pay'], 'net = before + 1,500.00 − 200.25');
        $st = getDB()->prepare("SELECT entry_type, amount FROM bonus_deduction_history WHERE emp_id = ? ORDER BY id");
        $st->execute([$e]);
        $t->same([['Bonus', '1000.50'], ['Bonus', '499.50'], ['Deduction', '200.25']], array_map(fn($r) => [$r['entry_type'], $r['amount']], $st->fetchAll()), 'history keeps each entry');
    });

    T::test('one bonus to several employees: each net rises by that amount, and a re-upload of the days keeps the bonus', function (T $t) {
        Fixtures::reset();
        $ids = [];
        foreach (['Many A', 'Many B', 'Many C'] as $n) $ids[$n] = qa_worker($n, '2026-04-01', '2026-04-15', $n === 'Many C' ? '650.00' : '500.00');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $rows = [];
        foreach ($ids as $n => $_) $rows = array_merge($rows, Fixtures::fullDays($n, '2026-04-01', '2026-04-15'));
        Fixtures::days($pid, $rows);
        $net0 = array_map(fn($r) => Ledger::c($r['net_pay']), Fixtures::payroll($pid));
        qa_adjust($pid, array_values($ids), 'Bonus', '1234.56');
        Fixtures::days($pid, $rows);                         // the same file again
        foreach ($ids as $n => $id) {
            $row = Fixtures::payroll($pid)[$id];
            $t->money($net0[$id] + 123456, $row['net_pay'], "$n: net = before + 1,234.56 after the re-upload");
            $t->money('1234.56', $row['bonus'], "$n: bonus kept");
        }
    });

    T::test('the ₱90,000 yearly ceiling for tax-exempt benefits: a bonus that crosses it waits for a decision; only those employees are re-sent', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $over  = qa_worker('Bonus Over', '2026-03-01', '2026-04-15');
        $under = qa_worker('Bonus Under', '2026-03-01', '2026-04-15');
        $exact = qa_worker('Bonus Exact', '2026-03-01', '2026-04-15');
        $prev  = qa_worker('Bonus Last Year', '2025-12-01', '2026-04-15');
        $p0 = Fixtures::period('Dec 1-15, 2025', '2025-12-01', '2025-12-15');
        $p1 = Fixtures::period('Mar 1-15, 2026', '2026-03-01', '2026-03-15');
        $p2 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $names = ['Bonus Over', 'Bonus Under', 'Bonus Exact', 'Bonus Last Year'];
        $every = fn(string $from, string $to) => array_merge(...array_map(fn($n) => Fixtures::fullDays($n, $from, $to), $names));
        Fixtures::days($p0, $every('2025-12-01', '2025-12-15'));
        Fixtures::days($p1, $every('2026-03-01', '2026-03-15'));
        Fixtures::days($p2, $every('2026-04-01', '2026-04-15'));

        qa_adjust($p1, [$over], 'Bonus', '85000');           // 85,000 so far this year - under the ceiling, recorded
        qa_adjust($p1, [$exact], 'Bonus', '80000');
        qa_adjust($p0, [$prev], 'Bonus', '85000');           // last year's bonus does not count towards 2026
        $t->money('85000.00', Fixtures::payroll($p1)[$over]['bonus'], 'the first ₱85,000 went through');
        $t->money('85000.00', Fixtures::payroll($p0)[$prev]['bonus'], 'December 2025 bonus recorded');

        $r = qa_adjust($p2, [$over, $under, $exact, $prev], 'Bonus', '10000');
        $apr = Fixtures::payroll($p2);
        $t->money('0.00', $apr[$over]['bonus'], 'over: 85,000 + 10,000 = 95,000 > 90,000 → held back');
        $t->money('10000.00', $apr[$under]['bonus'], 'under: 10,000 → recorded');
        $t->money('10000.00', $apr[$exact]['bonus'], 'exact: 80,000 + 10,000 = 90,000 is AT the ceiling, not over → recorded');
        $t->money('10000.00', $apr[$prev]['bonus'], '2025 bonuses are another year → recorded');
        $t->contains('tax-exempt', $r['body'], 'the page says why');
        $t->contains('Bonus Over', $r['body'], 'and for whom');
        $t->contains('Record anyway', $r['body'], 'and offers to record it anyway');
        $t->same(1, (int)getDB()->query("SELECT COUNT(*) FROM bonus_deduction_history WHERE emp_id = '$over'")->fetchColumn(), 'no history row for the held-back bonus');

        // what the "Record anyway" button re-sends: ONLY the held-back employee, with the confirmation
        qa_adjust($p2, [$over], 'Bonus', '10000', ['confirm_over_exempt' => '1']);
        $apr = Fixtures::payroll($p2);
        $t->money('10000.00', $apr[$over]['bonus'], 'confirmed: recorded');
        $t->money('10000.00', $apr[$under]['bonus'], 'nobody else got it twice');
        $t->money('10000.00', $apr[$exact]['bonus'], 'nobody else got it twice (exact)');
        $t->same(2, (int)getDB()->query("SELECT COUNT(*) FROM bonus_deduction_history WHERE emp_id = '$over'")->fetchColumn(), 'history: 85,000 and 10,000');
    });

    T::test('a deduction may not turn a payslip negative: it is held back and said so; one that fits is applied (same request)', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $small = qa_worker('Small Net', '2026-04-01', '2026-04-15', '500.00');
        $big   = qa_worker('Big Net', '2026-04-01', '2026-04-15', '1500.00');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, array_merge(Fixtures::fullDays('Small Net', '2026-04-01', '2026-04-15'), Fixtures::fullDays('Big Net', '2026-04-01', '2026-04-15')));
        $before = Fixtures::payroll($pid);
        $t->money('6175.00', $before[$small]['net_pay'], 'the ₱6,500 earner takes home ₱6,175 (SSS ₱325)');
        $r = qa_adjust($pid, [$small, $big], 'Deduction', '7000');
        $after = Fixtures::payroll($pid);
        $t->money($before[$small]['net_pay'], $after[$small]['net_pay'], 'a ₱7,000 deduction would leave −₱825: unchanged');
        $t->money('0.00', $after[$small]['other_deductions'], 'no deduction recorded for them');
        $t->money(Ledger::c($before[$big]['net_pay']) - 700000, $after[$big]['net_pay'], 'the ₱19,500 earner: net falls by ₱7,000');
        $t->contains('NOT applied', $r['body'], 'the page says it held one back');
        $t->contains('Small Net', $r['body']);
        $t->same(1, (int)getDB()->query("SELECT COUNT(*) FROM bonus_deduction_history WHERE entry_type = 'Deduction'")->fetchColumn(), 'only one history row');
    });

    T::test('Adjustments refuse an amount that is not a real peso figure: 1e999, abc, zero, negative, or a typing error (₱99,999,999,999)', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = qa_worker('Amount Case', '2026-04-01', '2026-04-15');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Amount Case', '2026-04-01', '2026-04-15'));
        $net = Fixtures::payroll($pid)[$e]['net_pay'];
        foreach (['1e999', 'abc', '0', '-50', '99999999999', ''] as $bad) {
            qa_adjust($pid, [$e], 'Bonus', $bad);
            $t->same($net, Fixtures::payroll($pid)[$e]['net_pay'], "amount \"$bad\" changed the payslip");
        }
        $t->same(0, (int)getDB()->query('SELECT COUNT(*) FROM bonus_deduction_history')->fetchColumn(), 'nothing was recorded');
    });

    /* ================================================================== C · input limits */

    T::test('dayHoursProblem(): what a day can and cannot hold', function (T $t) {
        qa_need_fixes();
        $ok = [[8, 0, 0], [24, 0, 0], [8, 16, 0], [null, 2, 0.5], ['8', '1.5', '0.25'], [0, 0, 0], [7.5, 0, 24, 3], ['', '', ''], [4, 16, 0], [0, 16, 0]];
        foreach ($ok as $a) $t->same(null, dayHoursProblem(...$a), 'should be fine: ' . json_encode($a));
        $bad = [[24.01, 0, 0, 'hours worked'], [8, 16.01, 0, 'overtime'], [12, 13, 0, 'more than 24'], [-1, 0, 0, 'negative'], [8, -0.5, 0, 'negative'],
                ['abc', 0, 0, 'not a number'], ['1e3', 0, 0, 'not a number'], [8, 0, 25, 'late'], [8, 0, 0, 'undertime', 24.5], [80, 30, 0, 'hours worked'],
                // overtime over its own limit although the day as a whole still fits into 24 hours
                [2, 16.5, 0, 'overtime hours in one day'], [0, 17, 0, 'overtime hours in one day']];
        foreach ($bad as $a) {
            $why = $a[3];
            $under = $a[4] ?? null;
            $msg = dayHoursProblem($a[0], $a[1], $a[2], $under);
            $t->ok($msg !== null && stripos($msg, $why) !== false, 'should be refused as "' . $why . '": ' . json_encode([$a[0], $a[1], $a[2], $under]) . ' → ' . var_export($msg, true));
        }
        $t->same(null, periodHoursProblem(160, 10, 2, 15), 'a 15-day period can hold 160 h');
        $t->ok(periodHoursProblem(361, 0, 0, 15) !== null, '361 h in 15 days is more than 24 h a day');
        $t->ok(periodHoursProblem(-1, 0, 0, 15) !== null && periodHoursProblem('x', 0, 0, 15) !== null, 'negative / text totals');
        $t->same(null, pesoProblem('Salary', '25000', 1e6));
        $t->ok(pesoProblem('Salary', '-1', 1e6) !== null && pesoProblem('Salary', '1e999', 1e6) !== null && pesoProblem('Salary', '1000001', 1e6) !== null && pesoProblem('Salary', '', 1e6) !== null);
        $t->same(null, numberOrNull(' 8.5 ') === 8.5 ? null : 'trim', 'numberOrNull trims');
        $t->same([null, null, null, 0.5, -3.0], [numberOrNull('1e5'), numberOrNull('08:60'), numberOrNull('abc'), numberOrNull('.5'), numberOrNull('-3')], 'numberOrNull');
    });

    T::test('a day upload with some impossible rows saves the possible ones and reports the rest (80 h, 30 OT, negative, text, 8 h + 17 OT)', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = qa_worker('Mixed Upload', '2026-04-01', '2026-04-15');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($pid, [Fixtures::day('Mixed Upload', '2026-04-01', 8),
            Fixtures::day('Mixed Upload', '2026-04-02', 80, 30, 0, 0), Fixtures::day('Mixed Upload', '2026-04-03', -2),
            Fixtures::day('Mixed Upload', '2026-04-04', 'abc'), Fixtures::day('Mixed Upload', '2026-04-06', 8, 17),
            Fixtures::day('Mixed Upload', '2026-04-08', 4, 17),             // 21 h in all, but 17 h of overtime is over the overtime limit
            Fixtures::day('Mixed Upload', '2026-04-07', null, 2)]);        // no hours column: a full duty day - fine
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $t->same(2, $r['inserted'] ?? null, 'the 1st and the 7th were saved');
        $t->same(5, $r['invalid_count'] ?? null, 'five rows refused');
        $t->same(['2026-04-02', '2026-04-03', '2026-04-04', '2026-04-06', '2026-04-08'], array_column($r['invalid'] ?? [], 'att_date'), 'and named');
        $dates = getDB()->query("SELECT att_date FROM biometric_daily ORDER BY att_date")->fetchAll(PDO::FETCH_COLUMN);
        $t->same(['2026-04-01', '2026-04-07'], $dates, 'only the possible days are on file');
        $row = Fixtures::payroll($pid)[$e];
        $t->money('1000.00', (float)$row['gross_pay'] - (float)$row['ot_late_adj'], 'two duty days at ₱500');
        $t->money('90.00', $row['ot_late_adj'], '2 h overtime on the 7th at ₱45');
    });

    T::test('a day upload where EVERY row is impossible changes nothing and says why (HTTP 400)', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        qa_worker('All Bad', '2026-04-01', '2026-04-15');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($pid, [Fixtures::day('All Bad', '2026-04-02', 80, 30, 0, 0), Fixtures::day('All Bad', '2026-04-03', -2)]);
        $t->same(400, $r['status']);
        $t->contains('cannot be right', (string)($r['error'] ?? ''), 'the error says what is wrong');
        $t->same(0, (int)getDB()->query('SELECT COUNT(*) FROM biometric_daily')->fetchColumn(), 'no day was saved');
        $t->same(0, (int)getDB()->query('SELECT COUNT(*) FROM payroll')->fetchColumn(), 'no payroll line');
    });

    T::test('a device report keeps its uncapped hours up to 24 a day (the excess over the duty day is overtime) and refuses more', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = qa_worker('Device Day', '2026-04-01', '2026-04-15');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($pid, [Fixtures::day('Device Day', '2026-04-01', 10, 0, 0, 0), Fixtures::day('Device Day', '2026-04-02', 25, 0, 0, 0)], ['auto_ot' => true]);
        $t->same(1, $r['inserted'] ?? null, json_encode($r));
        $row = Fixtures::payroll($pid)[$e];
        $t->eq(8.0, (float)$row['hours_worked'], 'the duty day');
        $t->eq(2.0, (float)$row['overtime_hours'], '10 h on the clock − 8 h duty day = 2 h overtime');
        $t->same(['2026-04-02'], array_column($r['invalid'] ?? [], 'att_date'), '25 h in one day is refused');
    });

    T::test('a totals file: lines with impossible hours are reported, the rest are saved; a file of only bad lines wipes nothing', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $a = qa_worker('Totals Good', '2026-04-01', '2026-04-15');
        $b = qa_worker('Totals Negative', '2026-04-01', '2026-04-15');
        $c = qa_worker('Totals Huge', '2026-04-01', '2026-04-15');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $rows = [['emp_name' => 'Totals Good', 'hours_worked' => 104, 'overtime_hours' => 4, 'late_hours' => 1],
                 ['emp_name' => 'Totals Negative', 'hours_worked' => -8, 'overtime_hours' => 0, 'late_hours' => 0],
                 ['emp_name' => 'Totals Huge', 'hours_worked' => 361, 'overtime_hours' => 0, 'late_hours' => 0]];
        $r = Http::api('save-attendance.php', ['period_id' => $pid, 'rows' => $rows]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $t->same(1, $r['json']['count'] ?? null, 'one line saved');
        $t->same(['Totals Negative', 'Totals Huge'], array_column($r['json']['invalid'] ?? [], 'emp_name'), 'two reported');
        $t->same([$a], array_keys(Fixtures::payroll($pid)), 'only the good line has pay');
        $before = json_encode(Fixtures::payroll($pid));
        $r2 = Http::api('save-attendance.php', ['period_id' => $pid, 'rows' => [['emp_name' => 'Totals Good', 'hours_worked' => -1, 'overtime_hours' => 0, 'late_hours' => 0]]]);
        $t->same(400, $r2['status'], 'every line bad: ' . $r2['body']);
        $t->same($before, json_encode(Fixtures::payroll($pid)), 'the period keeps what it had');
    });

    T::test('the manager\'s manual entry checks what is typed: 30 h, 17 h overtime, 8 h + 17 h, and a negative figure are refused; 9 h + 3 h overtime is saved', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = qa_worker('Manual Case', '2026-04-01', '2026-04-15');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        getDB()->prepare("INSERT INTO users (full_name, email, password_hash, role, branch, scope_type) VALUES ('Mgr', ?, 'x', 'manager', '', 'branch')")->execute(['mgr11-' . uniqid() . '@test']);
        $mid = (int)getDB()->lastInsertId();
        $enter = fn(string $date, array $hours) => Http::page('manager/manual-attendance.php',
            ['emp_id' => $e, 'att_date' => $date, 'day_status' => 'worked'] + $hours, [], Http::manager($mid, 'Mgr', ''));
        foreach ([['hours_worked' => '30'], ['hours_worked' => '8', 'overtime_hours' => '17'], ['hours_worked' => '12', 'overtime_hours' => '13'],
                  ['hours_worked' => '-4'], ['hours_worked' => '8', 'late_hours' => '30']] as $i => $bad) {
            $r = $enter('2026-04-0' . ($i + 1), $bad);
            $t->contains('Not saved', $r['body'], 'refused: ' . json_encode($bad));
        }
        $t->same(0, (int)getDB()->query('SELECT COUNT(*) FROM biometric_daily')->fetchColumn(), 'nothing saved by the refused entries');
        $r = $enter('2026-04-09', ['hours_worked' => '9', 'overtime_hours' => '3']);
        $t->contains('Saved', $r['body'], 'a possible day is saved');
        $row = Fixtures::payroll($pid)[$e];
        $t->eq(3.0, (float)$row['overtime_hours'], '3 overtime hours on the payroll line');
    });

    T::test('Employee Management refuses a salary that is negative, absurd or not a number, and duty-day hours outside 1–24', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $post = fn(array $x) => Http::page('employee.php', $x + ['action' => 'add', 'full_name' => 'Limit Case', 'branch' => 'MAIN', 'salary_type' => 'daily']);
        foreach (['-500', '1e12', 'abc', '', '100001'] as $bad) {
            $post(['base_salary' => $bad]);
            $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM employees WHERE full_name = 'Limit Case'")->fetchColumn(), "daily rate \"$bad\" was saved");
        }
        $r = $post(['base_salary' => '600', 'hours_per_day' => '30']);
        $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM employees WHERE full_name = 'Limit Case'")->fetchColumn(), 'a 30-hour duty day was saved');
        $t->contains('Not saved', $r['body']);
        $post(['base_salary' => '600', 'hours_per_day' => '']);
        $t->same(1, (int)getDB()->query("SELECT COUNT(*) FROM employees WHERE full_name = 'Limit Case' AND base_salary = 600")->fetchColumn(), 'a valid employee is added (blank duty hours = the standard day)');
        Http::page('employee.php', ['action' => 'add', 'full_name' => 'Monthly Big', 'branch' => 'MAIN', 'salary_type' => 'monthly', 'base_salary' => '9000000']);
        $t->same(1, (int)getDB()->query("SELECT COUNT(*) FROM employees WHERE full_name = 'Monthly Big'")->fetchColumn(), 'a ₱9,000,000 monthly salary is within the limit');
    });

    T::test('Settings: overtime multiplier 1–3, method flat|labor_code, schedule, timing, duty day 1–24 - junk is refused, valid values saved, the rest still saved', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::setting('overtime_multiplier', '1.25');
        Fixtures::setting('overtime_method', 'flat');
        $get = fn(string $k) => (string)getDB()->query("SELECT setting_value FROM settings WHERE setting_key = '$k'")->fetchColumn();
        $r = Http::page('settings.php', ['overtime_multiplier' => '0.5', 'overtime_method' => 'maybe', 'payroll_period' => 'Fortnightly', 'standard_hours' => '30',
                                         'contribution_timing_sss' => 'never', 'overtime_rate' => '-45', 'late_rate' => 'abc']);
        $t->contains('Not saved', $r['body']);
        $t->same('1.25', $get('overtime_multiplier'), 'multiplier 0.5 is below the legal minimum of 1');
        $t->same('flat', $get('overtime_method'), 'unknown method');
        $t->same('Semi-Monthly', $get('payroll_period'), 'unknown schedule keeps the old one');
        $t->same('8', $get('standard_hours'), '30-hour duty day');
        $t->same('split', $get('contribution_timing_sss'), 'unknown timing');
        $t->same('45', $get('overtime_rate'), 'negative overtime rate');
        $t->same('80', $get('late_rate'), 'non-numeric late rate');
        Http::page('settings.php', ['overtime_multiplier' => '1.30', 'overtime_method' => 'labor_code', 'overtime_rate' => '60.50', 'standard_hours' => '10', 'contribution_timing_pagibig' => 'split']);
        $t->same('1.30', $get('overtime_multiplier'));
        $t->same('labor_code', $get('overtime_method'));
        $t->same('60.50', $get('overtime_rate'));
        $t->same('10', $get('standard_hours'));
        $t->same('split', $get('contribution_timing_pagibig'));
    });

    /* ================================================================== D · Labor Code overtime */

    T::test('Labor Code overtime to the centavo, all three salary types: hourly rate × 1.25 (hand-worked: ₱480 → 75.00; ₱620 → 96.88; ₱30,000 kinsenas month → 180.29)', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::setting('overtime_method', 'labor_code');
        Fixtures::setting('overtime_multiplier', '1.25');
        $d = Fixtures::employee(['full_name' => 'OT Daily 480', 'base_salary' => '480.00']);
        $b = Fixtures::employee(['full_name' => 'OT Daily 620', 'base_salary' => '620.00']);
        $k = Fixtures::employee(['full_name' => 'OT Kinsenas', 'base_salary' => '15000.00', 'salary_type' => 'kinsenas']);
        $m = Fixtures::employee(['full_name' => 'OT Monthly', 'base_salary' => '26000.00', 'salary_type' => 'monthly']);
        $h = Fixtures::employee(['full_name' => 'OT Ten Hour', 'base_salary' => '600.00', 'hours_per_day' => '10.00']);
        $pid = Fixtures::period('Apr 1-30, 2026', '2026-04-01', '2026-04-30', 'Monthly');
        $rows = [];
        foreach (['OT Daily 480', 'OT Daily 620', 'OT Kinsenas', 'OT Monthly'] as $n) $rows = array_merge($rows, Fixtures::fullDays($n, '2026-04-01', '2026-04-30'));
        foreach (Fixtures::fullDays('OT Ten Hour', '2026-04-01', '2026-04-30', [7], 10) as $x) $rows[] = $x;
        foreach (['OT Daily 480', 'OT Daily 620', 'OT Kinsenas', 'OT Monthly', 'OT Ten Hour'] as $n) {
            foreach ($rows as $i => $x) if ($x['emp_name'] === $n && $x['att_date'] === '2026-04-02') $rows[$i]['overtime_hours'] = 1;
        }
        Fixtures::days($pid, $rows);
        $pay = Fixtures::payroll($pid);
        // an hour of overtime = (a duty day's pay ÷ the duty hours) × 1.25
        $t->money('75.00',  $pay[$d]['ot_late_adj'], '₱480 ÷ 8 × 1.25');
        $t->money('96.88',  $pay[$b]['ot_late_adj'], '₱620 ÷ 8 × 1.25 = 96.875 → half a centavo rounds up');
        $t->money('75.00',  $pay[$h]['ot_late_adj'], '10-hour day: ₱600 ÷ 10 × 1.25');
        // a salaried employee's day = the month's pay ÷ the month's working days (April 2026: 26)
        $t->money('180.29', $pay[$k]['ot_late_adj'], 'kinsenas ₱15,000 = ₱30,000 a month ÷ 26 ÷ 8 × 1.25 = 180.2884');
        $t->money('156.25', $pay[$m]['ot_late_adj'], 'monthly ₱26,000 ÷ 26 ÷ 8 × 1.25');
    });

    T::test('the multiplier is honoured (1.30 rest day, 2.00 holiday) and the flat method still pays the Settings rate', function (T $t) {
        qa_need_fixes();
        foreach ([['labor_code', '1.30', '78.00'], ['labor_code', '2.00', '120.00'], ['flat', '1.30', '45.00']] as [$method, $mult, $expected]) {
            Fixtures::reset();
            Fixtures::setting('overtime_method', $method);
            Fixtures::setting('overtime_multiplier', $mult);
            $e = Fixtures::employee(['full_name' => 'Mult Case', 'base_salary' => '480.00']);
            $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
            Fixtures::days($pid, [Fixtures::day('Mult Case', '2026-04-01', 8, 1, 0, 0)]);
            $t->money($expected, Fixtures::payroll($pid)[$e]['ot_late_adj'], "$method × $mult: one overtime hour on ₱480 a day");
        }
    });

    T::test('overtimeShortfalls(): the legal minimum per employee against the flat rate; Settings warns while the flat rate underpays and stops once the Labor Code method is chosen', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::employee(['full_name' => 'Short A', 'base_salary' => '480.00']);                    // 75.00
        Fixtures::employee(['full_name' => 'Short B', 'base_salary' => '620.00']);                    // 96.88
        Fixtures::employee(['full_name' => 'Fine C', 'base_salary' => '280.00']);                     // 43.75 ≤ 45
        Fixtures::employee(['full_name' => 'Short D', 'base_salary' => '600.00', 'hours_per_day' => '10.00']);   // 75.00
        $by = array_column(overtimeShortfalls(getDB()), null, 'full_name');
        $t->same(['Short A', 'Short B', 'Short D'], array_keys($by), 'who is underpaid');
        $t->same(['75.00', '96.88', '75.00'], [number_format($by['Short A']['legal'], 2, '.', ''), number_format($by['Short B']['legal'], 2, '.', ''), number_format($by['Short D']['legal'], 2, '.', '')], 'the legal hourly overtime rate');
        $t->same(['45.00', '30.00', '51.88'], [number_format($by['Short A']['paid'], 2, '.', ''), number_format($by['Short A']['gap'], 2, '.', ''), number_format($by['Short B']['gap'], 2, '.', '')], 'what is paid and the gap');
        $page = Http::page('settings.php')['body'];
        $t->contains('Overtime is paid below the legal minimum for 3 employee(s)', $page, 'the Settings page says so');
        Fixtures::setting('overtime_method', 'labor_code');
        $t->same([], overtimeShortfalls(getDB()), 'nobody is underpaid by the Labor Code method');
        $t->notContains('paid below the legal minimum', Http::page('settings.php')['body'], 'the warning is gone');
    });

    /* ================================================================== E · tax refund end to end */

    T::test('over-withheld tax comes back as a negative tax on the month\'s last run - printed on the payslip and in the register', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        // ₱1,000 a day: 13 days in the first half (tax ₱289.95 on the semi-monthly table), one day in the second; the month's
        // taxable pay ₱12,750 is under the ₱20,833 exemption, so the month owes ₱0 and the ₱289.95 goes back.
        $e = Fixtures::employee(['full_name' => 'Refund Case', 'base_salary' => '1000.00']);
        $a = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $b = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        Fixtures::days($a, Fixtures::fullDays('Refund Case', '2026-04-01', '2026-04-15'));
        Fixtures::days($b, [Fixtures::day('Refund Case', '2026-04-16', 8, 0, 0, 0)]);
        $pa = Fixtures::payroll($a)[$e];
        $pb = Fixtures::payroll($b)[$e];
        $t->money('289.95', $pa['withholding_tax'], 'first cut-off tax');
        $t->money('-289.95', $pb['withholding_tax'], 'second cut-off returns it');
        $t->money('0.00', Ledger::c($pa['withholding_tax']) + Ledger::c($pb['withholding_tax']), 'the month ends at exactly ₱0 tax');
        // gross 1,000 − contributions (SSS 50 + PhilHealth 350 + Pag-IBIG 200 = 600) + refund 289.95
        $t->money('689.95', $pb['net_pay'], 'net pay of the second cut-off');
        $slip = Http::page('print-doc.php', [], ['doc' => 'payslip', 'payroll_id' => $pb['id'], 'auto' => '0'])['body'];
        $t->contains('refund of tax withheld earlier this month', $slip, 'payslip label');
        $t->contains('&minus;&#8369;289.95', $slip, 'payslip shows the refund as a negative deduction');
        $t->contains('&#8369;689.95', $slip, 'payslip net');
        $rep = Http::page('reports.php', [], ['period' => $b])['body'];
        $t->contains('Refund of tax withheld earlier this month', $rep, 'reports page marks the cell');
        $t->contains('−₱289.95', $rep, 'and prints it signed');
        $reg = Http::page('print-doc.php', [], ['doc' => 'summary', 'period' => $b, 'auto' => '0'])['body'];
        $t->contains('289.95', $reg, 'the register carries the figure');
    });

    T::test('the signature pages (admin, manager) and the employee portal show the refund as a refund, and render without PHP warnings', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = Fixtures::employee(['full_name' => 'Refund Pages', 'base_salary' => '1000.00']);
        $a = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $b = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        Fixtures::days($a, Fixtures::fullDays('Refund Pages', '2026-04-01', '2026-04-15'));
        Fixtures::days($b, [Fixtures::day('Refund Pages', '2026-04-16', 8, 0, 0, 0)]);
        $pb = Fixtures::payroll($b)[$e];
        getDB()->prepare("INSERT INTO users (full_name, email, password_hash, role, branch, scope_type) VALUES ('Mgr', ?, 'x', 'manager', '', 'branch')")->execute(['mgr11p-' . uniqid() . '@test']);
        $mid = (int)getDB()->lastInsertId();
        $pages = [
            'admin sign-payslip.php'   => Http::page('sign-payslip.php', [], ['period' => $b, 'payroll_id' => $pb['id']]),
            'manager sign-payslip.php' => Http::page('manager/sign-payslip.php', [], ['period' => $b, 'payroll_id' => $pb['id']], Http::manager($mid, 'Mgr', '')),
            'employee payslips.php'    => Http::page('employee/payslips.php', [], [], ['emp_logged_in' => true, 'emp_id' => $e, 'emp_name' => 'Refund Pages', 'last_seen' => time()]),
        ];
        foreach ($pages as $name => $r) {
            $t->same(200, $r['status'], "$name answers");
            $t->same([], $r['warnings'] ?? [], "$name raised no PHP warnings");
        }
        $t->contains('Tax refund', $pages['admin sign-payslip.php']['body']);
        $t->contains('+₱289.95', $pages['admin sign-payslip.php']['body']);
        $t->contains('Tax refund', $pages['manager sign-payslip.php']['body']);
        $t->contains('Tax Refunded', $pages['employee payslips.php']['body']);
        $t->contains('₱289.95', $pages['employee payslips.php']['body']);
    });

    /* ================================================================== F · the forecast's labor cost */

    T::test('forecast-data.php: total_employer_share = Σ employer SSS (10%) + EC + PhilHealth + Pag-IBIG per period, total_labor_cost adds gross and bonus', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '15000.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        qa_adjust($r['periods'][1], [$r['emp']], 'Bonus', '2000');
        $res = Http::call('api/forecast-data.php', ['method' => 'GET', 'session' => Http::admin()]);
        $data = $res['json']['data'] ?? [];
        $t->same(2, count($data), 'two periods');
        foreach ([0, 1] as $k) {
            $er = $r['exp'][$k]['er'];
            $share = $er['sss'] + $er['ec'] + $er['ph'] + $er['pi'];
            $t->money($share, $data[$k]['total_employer_share'], "period $k: employer share = SSS " . Ledger::fmt($er['sss']) . ' + EC ' . Ledger::fmt($er['ec']) . ' + PhilHealth ' . Ledger::fmt($er['ph']) . ' + Pag-IBIG ' . Ledger::fmt($er['pi']));
            $bonus = $k === 1 ? 200000 : 0;
            $t->money($r['exp'][$k]['gross'] + $bonus + $share, $data[$k]['total_labor_cost'], "period $k: labor cost = gross + bonus + employer share");
        }
        $t->ok($data[1]['total_labor_cost'] > $data[1]['total_net'], 'labor cost is more than the take-home the old forecast used');
    });

    /* ================================================================== G · hire date, Recompute, Finalize guards, schema */

    T::test('a salaried employee hired on 9 April is paid ₱19,000 of a ₱26,000 month (7 unpaid working days × ₱1,000); hired on the 1st, or a daily-rate employee, are not affected', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '26000.00', 'date_hired' => '2026-04-09'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-04-09', '2026-04-30')]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger (pre-hire working days unpaid)');
        $t->money('19000.00', $row['gross_pay'], '26,000 − 7 × 1,000');
        $t->money('7000.00', $row['absent_deduction'], 'shown as the absent deduction');
        $t->eq(7, (float)$row['absent_days'], 'the 7 working days before the hire date: Apr 1–4 and 6–8');
        $t->contains('Unpaid days (absent, or before the hire date)', Http::page('print-doc.php', [], ['doc' => 'payslip', 'payroll_id' => $row['id'], 'auto' => '0'])['body'], 'the payslip says why');

        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '26000.00', 'date_hired' => '2026-04-01'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $t->money('26000.00', $r['app'][0]['gross_pay'], 'hired on the 1st: the full month');

        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '1000.00', 'date_hired' => '2026-04-09'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-04-09', '2026-04-30')]);
        $t->money('19000.00', $r['app'][0]['gross_pay'], 'daily rate: paid for the 19 days worked');
        $t->eq(0, (float)$r['app'][0]['absent_days'], 'no absence is invented for a daily-rate employee');
    });

    T::test('Recompute rebuilds an open period from its days with the CURRENT Settings; a locked period refuses; a totals-built period says to upload again', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = Fixtures::employee(['full_name' => 'Recompute Case', 'base_salary' => '500.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, [Fixtures::day('Recompute Case', '2026-04-01', 8, 2, 0, 0)]);
        $t->money('90.00', Fixtures::payroll($pid)[$e]['ot_late_adj'], '2 h × ₱45');
        Fixtures::setting('overtime_rate', '60');
        $r = Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $pid]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $t->money('120.00', Fixtures::payroll($pid)[$e]['ot_late_adj'], '2 h × ₱60 after Recompute');
        $snap = fn() => json_encode(array_map(fn($r) => [$r['id'], $r['gross_pay'], $r['ot_late_adj'], $r['sss'], $r['philhealth'], $r['pagibig'],
                                                          $r['withholding_tax'], $r['bonus'], $r['other_deductions'], $r['net_pay'], $r['absent_days']], Fixtures::payroll($pid)));
        $again = $snap();
        Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $pid]);
        $t->same($again, $snap(), 'Recompute is idempotent: same row ids, same figures');
        Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid, 'allow_negative' => true]);
        $r = Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $pid]);
        $t->same(409, $r['status'], 'a locked period must be unlocked first');
        // a period built from a totals file has no days to rebuild from
        $p2 = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        Http::api('save-attendance.php', ['period_id' => $p2, 'rows' => [['emp_name' => 'Recompute Case', 'hours_worked' => 104, 'overtime_hours' => 0, 'late_hours' => 0]]]);
        $r = Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $p2]);
        $t->same(400, $r['status'], 'no days to recompute from: ' . $r['body']);
    });

    T::test('Finalize refuses lines whose contributions no longer settle the month (stale) and asks before locking a negative net - each with a way through', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = Fixtures::employee(['full_name' => 'Stale Case', 'base_salary' => '800.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Stale Case', '2026-04-01', '2026-04-15'));
        // an employee switch changed behind the engine's back (the Employee page would have recomputed; this does not)
        getDB()->prepare("UPDATE employees SET deduct_sss = 0 WHERE emp_id = ?")->execute([$e]);
        $r = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid]);
        $t->same(409, $r['status'], $r['body']);
        $t->same('stale', $r['json']['error'] ?? null);
        $t->contains('Recompute', (string)($r['json']['message'] ?? ''));
        $t->same('Open', getDB()->query("SELECT status FROM payroll_periods WHERE id = $pid")->fetchColumn(), 'still open');
        $t->same(true, Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $pid])['json']['success'] ?? null, 'Recompute brings the line up to date');
        $t->same(0, (int)round((float)Fixtures::payroll($pid)[$e]['sss'] * 100), 'SSS is no longer deducted for this employee');
        $r = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid]);
        $t->same(true, $r['json']['success'] ?? null, 'now it finalizes: ' . $r['body']);

        // negative net
        Fixtures::reset();
        $n = Fixtures::employee(['full_name' => 'Negative Case', 'base_salary' => '480.00']);
        $p2 = Fixtures::period('Apr 1-30, 2026', '2026-04-01', '2026-04-30', 'Monthly');
        Fixtures::days($p2, [Fixtures::day('Negative Case', '2026-04-01', 8, 0, 0, 0)]);
        $t->ok((float)Fixtures::payroll($p2)[$n]['net_pay'] < 0, 'the case is a negative payslip');
        $r = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $p2]);
        $t->same('negative_net', $r['json']['error'] ?? null, $r['body']);
        $t->contains('Negative Case', (string)($r['json']['message'] ?? ''), 'names the employee');
        $r = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $p2, 'allow_negative' => true]);
        $t->same(true, $r['json']['success'] ?? null, 'confirmed: ' . $r['body']);
        $t->same('Locked', getDB()->query("SELECT status FROM payroll_periods WHERE id = $p2")->fetchColumn());
    });

    T::test('the payroll page warns about stale lines and negative net pay before anyone finalizes', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $n = Fixtures::employee(['full_name' => 'Banner Negative', 'base_salary' => '480.00']);
        $p = Fixtures::period('Apr 1-30, 2026', '2026-04-01', '2026-04-30', 'Monthly');
        Fixtures::days($p, [Fixtures::day('Banner Negative', '2026-04-01', 8, 0, 0, 0)]);
        $page = Http::page('payroll.php', [], ['period' => $p])['body'];
        $t->contains('have a negative net pay', $page);
        $t->contains('Banner Negative', $page);
        $t->contains('−₱', $page, 'the negative amount is printed signed');
        $t->contains('Recompute', $page, 'the Recompute button exists on an open period');
        getDB()->prepare("UPDATE employees SET deduct_pagibig = 0 WHERE emp_id = ?")->execute([$n]);
        $t->contains('no longer settle the month correctly', Http::page('payroll.php', [], ['period' => $p])['body'], 'a stale line is announced');
    });

    T::test('a finalized month is called out of date only when an EARLIER cut-off changed after it was finalized - a raise made later is not a reason to "recompute" history', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = Fixtures::employee(['full_name' => 'Raise Case', 'base_salary' => '20000.00', 'salary_type' => 'monthly']);
        $a = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $b = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        Fixtures::days($a, Fixtures::fullDays('Raise Case', '2026-04-01', '2026-04-15'));
        Fixtures::days($b, Fixtures::fullDays('Raise Case', '2026-04-16', '2026-04-30'));
        foreach ([$a, $b] as $pid) $t->same(true, Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid])['json']['success'] ?? null, "finalize $pid");
        $db = getDB();
        $db->exec("UPDATE payroll_periods SET finalized_at = '2026-05-01 10:00:00' WHERE id = $a");
        $db->exec("UPDATE payroll_periods SET finalized_at = '2026-05-01 10:05:00' WHERE id = $b");

        $db->prepare("UPDATE employees SET base_salary = 40000 WHERE emp_id = ?")->execute([$e]);       // a raise, months later
        $t->ok(settlementDrift($db, $b) !== [], 'by arithmetic alone cut-off 2 would now differ (PhilHealth is read on the contract salary)');
        $t->notContains('no longer settle the month correctly', Http::page('payroll.php', [], ['period' => $b])['body'], 'but April is history: no warning, no invitation to recompute it');
        $t->same(false, earlierRunChangedAfterFinalize($db, $b), 'nothing earlier in April changed after cut-off 2 was finalized');

        // cut-off 1 is unlocked and finalized AGAIN, after cut-off 2: now cut-off 2 really is behind it
        Http::api('update-payroll.php', ['action' => 'unlock', 'period_id' => $a]);
        $t->same(true, earlierRunChangedAfterFinalize($db, $b), 'while cut-off 1 is open for correction');
        Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $a, 'ignore_drift' => true]);
        $db->exec("UPDATE payroll_periods SET finalized_at = '2026-05-02 09:00:00' WHERE id = $a");
        $t->same(true, earlierRunChangedAfterFinalize($db, $b), 'after cut-off 1 was finalized again, later than cut-off 2');
        $t->contains('no longer settle the month correctly', Http::page('payroll.php', [], ['period' => $b])['body'], 'cut-off 2 is flagged');
    });

    T::test('the fixed application changes no database structure: its DDL statements are exactly the original\'s', function (T $t) {
        qa_need_fixes();
        $orig = dirname(__DIR__, 2) . '/payroll2';
        $fixed = AppCopy::original();          // the application under test (here: the audit-fixed folder)
        if (!is_file($orig . '/includes/helpers.php')) T::skip("the original application is not at $orig");
        $ddl = function (string $root) {
            $out = [];
            foreach (['includes/helpers.php', 'includes/schema.php', 'includes/db.php'] as $f) {
                if (!is_file("$root/$f")) continue;
                preg_match_all('/\b(?:ALTER\s+TABLE|CREATE\s+(?:UNIQUE\s+)?(?:TABLE|INDEX)|DROP\s+(?:TABLE|INDEX|COLUMN)|ADD\s+COLUMN)\b[^"\';]*/i', (string)file_get_contents("$root/$f"), $m);
                foreach ($m[0] as $s) $out[] = preg_replace('/\s+/', ' ', trim($s));
            }
            sort($out);
            return $out;
        };
        $t->same($ddl($orig), $ddl($fixed), 'schema statements');
        $t->ok(count($ddl($orig)) > 10, 'the scan found the schema statements (' . count($ddl($orig)) . ')');
    });
});
