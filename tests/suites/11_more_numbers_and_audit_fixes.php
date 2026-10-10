<?php
/*
 * 11 - More numbers, and what the audit fixes added.
 *
 *   A  big pay, big typed amounts: ₱250,000 and ₱800,000 a month, a ₱1,500 daily rate - the law's figures for that pay typed on the
 *      employee, taken to the centavo, every figure worked out by hand
 *   B  bonuses and deductions: untaxed, net = gross + bonus − deductions, entries accumulate to the centavo; the ₱90,000 yearly
 *      ceiling for tax-exempt benefits; a deduction may not turn a payslip negative
 *   C  input limits on every door: day upload, totals file, manager's manual entry, Employee Management (and the four optional
 *      monthly amounts), Settings, Adjustments
 *   D  the Labor Code overtime method, and the Settings warning while the flat rate underpays
 *   E  a tax refund end to end: an amount lowered after a cut-off was finalized - payslip, register
 *   F  the labor-cost series of the forecast equals the employer shares worked out independently
 *   G  hire-date proration, Recompute, the stale-figures and negative-net guards of Finalize, the four new columns
 *
 * A and the first three B tests run against either version of the app. Everything else describes behaviour the audit fixes added
 * and is skipped on an older copy of the app that lacks it (--app=<older folder>).
 */
require_once AppCopy::root() . '/includes/bir-print.php';

/**
 * The tax-refund story, played through the real pages: ₱1,000 a day, 13 days in the 1st cut-off and one in the 2nd. The employee has a typed
 * tax of ₱579.90 (the 1st cut-off takes half, ₱289.95) with PhilHealth ₱400 and Pag-IBIG ₱200 for the last cut-off. The 1st cut-off is finalized,
 * then the admin removes the tax on Employees - so the 2nd cut-off settles the month at ₱0 and hands the ₱289.95 back.
 * Returns ['emp', 'a' (1st period), 'b' (2nd), 'pa', 'pb' (the payroll lines)].
 */
function qa_refund_case(string $name): array
{
    $e = Fixtures::employee(['full_name' => $name, 'base_salary' => '1000.00', 'philhealth_amount' => '400.00', 'pagibig_amount' => '200.00', 'tax_amount' => '579.90']);
    $a = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
    $b = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
    Fixtures::days($a, Fixtures::fullDays($name, '2026-04-01', '2026-04-15'));
    Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $a]);
    qa_edit_employee($e, ['tax_amount' => '0']);
    Fixtures::days($b, [Fixtures::day($name, '2026-04-16', 8, 0, 0, 0)]);
    return ['emp' => $e, 'a' => $a, 'b' => $b, 'pa' => Fixtures::payroll($a)[$e], 'pb' => Fixtures::payroll($b)[$e]];
}

T::suite('11 · More numbers & the audit fixes', function () {

    /* ================================================================== A · high brackets, by hand */

    T::test('Example H - monthly ₱250,000 with the law\'s figures typed: SSS ₱1,750, PhilHealth ₱2,500, Pag-IBIG ₱200, tax ₱57,206.70 - big amounts are taken to the centavo', function (T $t) {
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '250000.00',
                                       'sss_amount' => '1750.00', 'philhealth_amount' => '2500.00', 'pagibig_amount' => '200.00', 'tax_amount' => '57206.70'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger on every figure');
        // the typed figures are what the tables give for ₱250,000: SSS 5% × 35,000 = 1,750 · PhilHealth 2.5% × 100,000 = 2,500 · Pag-IBIG 2% × 10,000 = 200
        // · tax on taxable 245,550.00 → 33,541.80 + 30% × (245,550 − 166,667) = 57,206.70 - a monthly payroll takes them all in full
        // net 250,000 − 4,450 − 57,206.70 = 188,343.30
        $t->moneyMap(['gross_pay' => 25000000, 'sss' => 175000, 'philhealth' => 250000, 'pagibig' => 20000,
                      'withholding_tax' => 5720670, 'net_pay' => 18834330], $row, 'Example H by hand');
        // the reference calculators (what the law prescribes) give those same figures
        $t->money('1750.00', sssMonthly(250000.0), 'reference SSS');
        $t->money('2500.00', philhealthMonthly(250000.0), 'reference PhilHealth');
        $t->money('200.00', pagibigMonthly(250000.0), 'reference Pag-IBIG');
        $t->money('57206.70', birTax(245550.0, 'monthly'), 'reference tax');
    });

    T::test('Example I - monthly ₱800,000 with a ₱228,650.85 tax typed (the 35% bracket\'s figure): taken to the centavo', function (T $t) {
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '800000.00',
                                       'sss_amount' => '1750.00', 'philhealth_amount' => '2500.00', 'pagibig_amount' => '200.00', 'tax_amount' => '228650.85'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger on every figure');
        // taxable 800,000 − 4,450 = 795,550.00 → 183,541.80 + 35% × (795,550 − 666,667) = 183,541.80 + 45,109.05 = 228,650.85
        // net 800,000 − 4,450 − 228,650.85 = 566,899.15
        $t->moneyMap(['gross_pay' => 80000000, 'sss' => 175000, 'philhealth' => 250000, 'pagibig' => 20000,
                      'withholding_tax' => 22865085, 'net_pay' => 56689915], $row, 'Example I by hand');
        $t->money('228650.85', birTax(795550.0, 'monthly'), 'reference tax');
    });

    T::test('Example K - daily rate ₱1,500 × 26 days = ₱39,000 with SSS ₱1,750, PhilHealth ₱975, Pag-IBIG ₱200 and tax ₱2,423.40 typed', function (T $t) {
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '1500.00',
                                       'sss_amount' => '1750.00', 'philhealth_amount' => '975.00', 'pagibig_amount' => '200.00', 'tax_amount' => '2423.40'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger on every figure');
        // SSS 5% × 35,000 = 1,750.00 · PhilHealth 2.5% × 39,000 = 975.00 · Pag-IBIG 200.00 → 2,925.00
        // taxable 36,075.00 → 1,875.00 + 20% × (36,075 − 33,333) = 1,875.00 + 548.40 = 2,423.40 · net 39,000 − 2,925 − 2,423.40 = 33,651.60
        $t->moneyMap(['gross_pay' => 3900000, 'sss' => 175000, 'philhealth' => 97500, 'pagibig' => 20000,
                      'withholding_tax' => 242340, 'net_pay' => 3365160], $row, 'Example K by hand');
        $t->money('975.00', philhealthMonthly(39000.0), 'reference PhilHealth on ₱39,000');
        $t->money('2423.40', birTax(36075.0, 'monthly'), 'reference tax');
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
        $small = qa_worker('Small Net', '2026-04-01', '2026-04-15', '500.00', ['sss_amount' => '325.00']);
        $big   = qa_worker('Big Net', '2026-04-01', '2026-04-15', '1500.00');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, array_merge(Fixtures::fullDays('Small Net', '2026-04-01', '2026-04-15'), Fixtures::fullDays('Big Net', '2026-04-01', '2026-04-15')));
        $before = Fixtures::payroll($pid);
        $t->money('6175.00', $before[$small]['net_pay'], 'the ₱6,500 earner takes home ₱6,175 (typed SSS ₱325)');
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

    T::test('Employees: SSS, PhilHealth, Pag-IBIG and tax are optional monthly amounts - blank means none (0.00), typed amounts are saved as typed, negative / absurd / non-numeric ones are refused', function (T $t) {
        Fixtures::reset();
        $db  = getDB();
        $add = fn(string $name, array $x) => Http::page('employee.php', $x + ['action' => 'add', 'full_name' => $name, 'branch' => 'MAIN', 'salary_type' => 'daily', 'base_salary' => '500']);
        $row = fn(string $name) => $db->query('SELECT * FROM employees WHERE full_name = ' . $db->quote($name))->fetch();
        $none = ['sss_amount' => '0.00', 'philhealth_amount' => '0.00', 'pagibig_amount' => '0.00', 'tax_amount' => '0.00'];

        $add('Blank Boxes', ['sss_amount' => '', 'philhealth_amount' => '', 'pagibig_amount' => '', 'tax_amount' => '']);
        $t->ok($row('Blank Boxes') !== false, 'an employee with every amount blank is saved');
        $t->same($none, array_intersect_key($row('Blank Boxes'), $none), 'blank boxes mean none: 0.00 each');
        $add('No Boxes At All', []);
        $t->same($none, array_intersect_key($row('No Boxes At All') ?: [], $none), 'the form fields missing altogether: none');
        $add('Some Typed', ['sss_amount' => '495.50', 'philhealth_amount' => '', 'pagibig_amount' => '100.25', 'tax_amount' => '0']);
        $t->same(['sss_amount' => '495.50', 'philhealth_amount' => '0.00', 'pagibig_amount' => '100.25', 'tax_amount' => '0.00'], array_intersect_key($row('Some Typed'), $none), 'only some typed');
        $add('All Typed', ['sss_amount' => '1750', 'philhealth_amount' => '2500.00', 'pagibig_amount' => '200', 'tax_amount' => '9999999.99']);
        $t->same(['sss_amount' => '1750.00', 'philhealth_amount' => '2500.00', 'pagibig_amount' => '200.00', 'tax_amount' => '9999999.99'], array_intersect_key($row('All Typed'), $none), 'all typed, saved as typed');

        foreach ([['sss_amount', '-1'], ['philhealth_amount', 'abc'], ['pagibig_amount', '100001'], ['tax_amount', '10000001'], ['sss_amount', '1e9'], ['tax_amount', '12,000']] as [$field, $bad]) {
            $name = "Bad $field $bad";
            $r = $add($name, [$field => $bad]);
            $t->same(false, $row($name), "$field = \"$bad\" was saved");
            $t->contains('Not saved', $r['body'], "$field = \"$bad\" is refused with a message");
        }
        // editing: the amounts change, and a bad one leaves the employee as it was
        $id = $row('Some Typed')['emp_id'];
        qa_edit_employee($id, ['sss_amount' => '600', 'tax_amount' => '300.50']);
        $t->same(['sss_amount' => '600.00', 'pagibig_amount' => '100.25', 'tax_amount' => '300.50'], array_intersect_key(Fixtures::empRow($id), ['sss_amount' => 1, 'pagibig_amount' => 1, 'tax_amount' => 1]), 'edited');
        qa_edit_employee($id, ['sss_amount' => '-5']);
        $t->money('600.00', Fixtures::empRow($id)['sss_amount'], 'a refused edit changes nothing');
        // the modal gets the typed amounts back to edit
        $page = Http::page('employee.php')['body'];
        $t->contains('name="sss_amount"', $page, 'the form has the four boxes');
        $t->contains('name="tax_amount"', $page);
        $t->contains('Contribution Schedule', $page, 'and says where the schedule is set');
        $t->notContains('name="deduct_sss"', $page, 'the old tick-boxes are gone');
        $t->notContains('name="deduct_philhealth"', $page);
        $t->notContains('name="deduct_pagibig"', $page);
    });

    T::test('editing an employee\'s amounts recomputes their OPEN payroll straight away - and leaves a finalized cut-off exactly as it was', function (T $t) {
        Fixtures::reset();
        $e = qa_worker('Edit Me', '2026-04-01', '2026-04-30', '500.00');
        $a = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $b = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        Fixtures::days($a, Fixtures::fullDays('Edit Me', '2026-04-01', '2026-04-15'));
        Fixtures::days($b, Fixtures::fullDays('Edit Me', '2026-04-16', '2026-04-30'));
        $t->money('0.00', Fixtures::payroll($a)[$e]['sss'], 'nothing typed yet: nothing deducted');
        $t->same(true, Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $a])['json']['success'] ?? null, 'cut-off 1 is finalized');
        $locked = Fixtures::payroll($a)[$e];

        $r = qa_edit_employee($e, ['sss_amount' => '400.00', 'philhealth_amount' => '250.00', 'tax_amount' => '300']);
        $t->contains('Payroll recomputed for Apr 16-30, 2026', $r['body'], 'the page says which payroll it brought up to date');
        $t->notContains('Apr 1-15, 2026.', $r['body'], 'and that it left the finalized one alone');
        $t->same($locked, Fixtures::payroll($a)[$e], 'the finalized cut-off 1 is untouched');
        // cut-off 2 is the month's last: it settles everything that cut-off 1 did not take (SSS 400 and half the tax: cut-off 1 took nothing)
        $after = Fixtures::payroll($b)[$e];
        $t->moneyMap(['sss' => '400.00', 'philhealth' => '250.00', 'withholding_tax' => '300.00'], $after, 'cut-off 2 takes the whole month at once');
        $t->money(Ledger::c($after['gross_pay']) - 95000, $after['net_pay'], 'net pay is gross less 400 + 250 + 300');
    });

    T::test('Settings: overtime multiplier 1–3, method flat|labor_code, schedule, timing, duty day 1–24 - junk is refused, valid values saved, the rest still saved', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::setting('overtime_multiplier', '1.25');
        Fixtures::setting('overtime_method', 'flat');
        $get = fn(string $k) => (string)getDB()->query("SELECT setting_value FROM settings WHERE setting_key = '$k'")->fetchColumn();
        $r = Http::page('settings.php', ['overtime_multiplier' => '0.5', 'overtime_method' => 'maybe', 'payroll_period' => 'Fortnightly', 'standard_hours' => '30',
                                         'contribution_timing_sss' => 'never', 'contribution_timing_tax' => 'weekly', 'overtime_rate' => '-45', 'late_rate' => 'abc']);
        $t->contains('Not saved', $r['body']);
        $t->same('1.25', $get('overtime_multiplier'), 'multiplier 0.5 is below the legal minimum of 1');
        $t->same('flat', $get('overtime_method'), 'unknown method');
        $t->same('Semi-Monthly', $get('payroll_period'), 'unknown schedule keeps the old one');
        $t->same('8', $get('standard_hours'), '30-hour duty day');
        $t->same('first', $get('contribution_timing_sss'), 'unknown timing keeps the old one');
        $t->same('split', $get('contribution_timing_tax'), 'unknown tax timing keeps the old one');
        $t->same('45', $get('overtime_rate'), 'negative overtime rate');
        $t->same('80', $get('late_rate'), 'non-numeric late rate');
        Http::page('settings.php', ['overtime_multiplier' => '1.30', 'overtime_method' => 'labor_code', 'overtime_rate' => '60.50', 'standard_hours' => '10',
                                    'contribution_timing_pagibig' => 'split', 'contribution_timing_sss' => 'second', 'contribution_timing_tax' => 'first']);
        $t->same('1.30', $get('overtime_multiplier'));
        $t->same('labor_code', $get('overtime_method'));
        $t->same('60.50', $get('overtime_rate'));
        $t->same('10', $get('standard_hours'));
        $t->same('split', $get('contribution_timing_pagibig'), 'split is a valid timing');
        $t->same('second', $get('contribution_timing_sss'), 'second is a valid timing');
        $t->same('first', $get('contribution_timing_tax'), 'first is a valid timing, for tax too');
        $page = Http::page('settings.php')['body'];
        $t->contains('1st cut-off of the month, in full', $page, 'the schedule offers the 1st cut-off');
        $t->contains('Every cut-off, in equal shares', $page, 'equal shares');
        $t->contains('Last cut-off of the month, in full', $page, 'and the last cut-off');
        $t->contains('name="contribution_timing_tax"', $page, 'withholding tax has its own schedule line');
        $t->contains('Contribution tables - for reference', $page, 'the statutory tables are labelled as reference only');
        $t->notContains('applied automatically', $page, 'and no longer claimed to be applied automatically');
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

    T::test('tax taken in a finalized cut-off and then removed on Employees comes back as a negative tax on the month\'s last run - printed on the payslip and in the register', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $c = qa_refund_case('Refund Case');
        [$e, $a, $b, $pa, $pb] = [$c['emp'], $c['a'], $c['b'], $c['pa'], $c['pb']];
        $t->money('289.95', $pa['withholding_tax'], 'first cut-off takes half of the typed ₱579.90');
        $t->money('-289.95', $pb['withholding_tax'], 'second cut-off returns it: the month now owes ₱0');
        $t->money('0.00', Ledger::c($pa['withholding_tax']) + Ledger::c($pb['withholding_tax']), 'the month ends at exactly ₱0 tax');
        // gross 1,000 − PhilHealth 400 − Pag-IBIG 200 + refund 289.95
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
        $c = qa_refund_case('Refund Pages');
        [$e, $a, $b, $pb] = [$c['emp'], $c['a'], $c['b'], $c['pb']];
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

    T::test('forecast-data.php: total_employer_share = Σ employer SSS + EC + PhilHealth + Pag-IBIG per period, figured on what each period deducted; total_labor_cost adds gross and bonus', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '15000.00', 'sss_amount' => '900.00', 'philhealth_amount' => '750.00', 'pagibig_amount' => '200.00'],
            'cfg' => ['timing' => ['sss' => 'split']],              // SSS in two ₱450 shares: EC ₱10 on the first, the ₱20 step-up on the second
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
        $t->money('1800.00', Ledger::fmt($r['exp'][0]['er']['sss'] + $r['exp'][1]['er']['sss']), 'the two periods\' employer SSS together are twice the typed ₱900');
        $t->money('30.00', Ledger::fmt($r['exp'][0]['er']['ec'] + $r['exp'][1]['er']['ec']), 'and the EC comes to ₱30 over the month (₱10 + the ₱20 step-up)');
        // the employer share is a property of what was DEDUCTED: raising the typed amounts later must not rewrite earlier periods in the forecast
        $before = array_map(fn($p) => $p['total_employer_share'], $data);
        getDB()->exec("UPDATE employees SET sss_amount = 1750, philhealth_amount = 2500, pagibig_amount = 200");
        $after = array_map(fn($p) => $p['total_employer_share'], Http::call('api/forecast-data.php', ['method' => 'GET', 'session' => Http::admin()])['json']['data']);
        $t->same($before, $after, 'editing an employee\'s amounts afterwards does not change the company share of periods already paid');
    });

    T::test('Payroll Processing: the schedule line, the remittances and the per-employee table follow what was deducted - each typed amount, what came out earlier, when the rest does', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '15000.00', 'sss_amount' => '675.00', 'philhealth_amount' => '750.00', 'tax_amount' => '600.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $p1 = Http::page('payroll.php', [], ['period' => $r['periods'][0]]);
        $p2 = Http::page('payroll.php', [], ['period' => $r['periods'][1]]);
        foreach ([1 => $p1, 2 => $p2] as $n => $p) {
            $t->same(200, $p['status'], "cut-off $n answers");
            $t->same([], $p['warnings'] ?? [], "cut-off $n raised no PHP warnings");
            $t->contains('Company Cost &amp; Remittances', $p['body']);
            $t->notContains('Pay this month so far', $p['body'], 'the old "pay so far" column is gone');
        }
        // cut-off 1: SSS in full, half the tax, PhilHealth waits
        $t->contains('SSS taken in full; withholding tax taken in equal shares', $p1['body'], 'the schedule line says what this run takes');
        $t->contains('PhilHealth and Pag-IBIG wait for the last cut-off', $p1['body']);
        $t->contains('&#8369;675.00 / &#8369;1,350.00 + &#8369;10.00', $p1['body'], 'SSS: employee / company + EC');
        $t->contains('monthly &#8369;750.00 · waits for the last cut-off', $p1['body'], 'PhilHealth is not taken yet');
        $t->contains('monthly &#8369;600.00', $p1['body'], 'the typed tax');
        // cut-off 2: the last run settles - SSS was taken earlier, PhilHealth and the rest of the tax come out now
        $t->contains('last cut-off of the month', $p2['body']);
        $t->contains('monthly &#8369;675.00 · &#8369;675.00 taken earlier', $p2['body'], 'SSS: all of it was taken on the 1st cut-off');
        $t->contains('&#8369;750.00 / &#8369;750.00', $p2['body'], 'PhilHealth: employee / company');
        $t->notContains('credit &#8369;', $p2['body'], 'no more "salary credit" talk: the amounts are typed');
        // the remittance table counts what the payroll rows deducted
        $sss = array_sum(array_map(fn($x) => (float)$x['sss'], array_values(Fixtures::payroll($r['periods'][1]))));
        $t->money('0.00', $sss, 'cut-off 2 deducted no SSS');
    });

    /* ================================================================== G · hire date, Recompute, Finalize guards, schema */

    T::test('a salaried employee whose file starts on 9 April is paid ₱19,000 of a ₱26,000 month (7 working days not in the file × ₱1,000); a file with every day, or a daily-rate employee, are not affected', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '26000.00', 'date_hired' => '2026-04-09', 'sss_amount' => '900.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-04-09', '2026-04-30')]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger (the days not in the file are unpaid)');
        $t->money('19000.00', $row['gross_pay'], '26,000 − 7 × 1,000');
        $t->money('900.00', $row['sss'], 'the typed SSS is not pro-rated with the pay: a month\'s amount is a month\'s amount');
        $t->money('7000.00', $row['absent_deduction'], 'shown as the absent deduction');
        $t->eq(7, (float)$row['absent_days'], 'the 7 working days that are not in the file: Apr 1–4 and 6–8');
        $t->contains('Unpaid days (absent, or not in the timesheet)', Http::page('print-doc.php', [], ['doc' => 'payslip', 'payroll_id' => $row['id'], 'auto' => '0'])['body'], 'the payslip says why');

        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '26000.00', 'date_hired' => '2026-04-01'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $t->money('26000.00', $r['app'][0]['gross_pay'], 'every working day is in the file: the full month');

        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '1000.00', 'date_hired' => '2026-04-09'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-04-09', '2026-04-30')]);
        $t->money('19000.00', $r['app'][0]['gross_pay'], 'daily rate: paid for the 19 days worked');
        $t->money('0.00', $r['app'][0]['absent_deduction'], 'daily rate: nothing is deducted for the days not in the file - they are simply not paid');
        $t->eq(7, (float)$r['app'][0]['absent_days'], 'the 7 working days not in the file are SHOWN as absent (no money effect for a daily rate)');
    });

    /* ---- a new employee, then a file for this month or a past month: the days to compute are the days IN THE FILE - never the days from the Date Hired on */

    T::test('a monthly / kinsenas employee is paid for the days in the file: 3 days uploaded, 3 days paid - whatever the Date Hired says (its first day, the day added, or nothing)', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        // Kinsenas ₱13,000 (₱26,000 a month; April 2026 has 26 working days, so a day is ₱1,000). The 1st-15th cut-off has 13 working days (the 5th and
        // 12th are Sundays). The file holds three of them: Wed 8th, Thu 9th and Fri 10th. The other ten - the 1st-7th AND the 11th-15th - are not in the
        // file, so they are not paid. (Counting from the Date Hired on, the 11th-15th would have been paid although nothing was uploaded for them.)
        $days = Scenario::fullDays('2026-04-08', '2026-04-10');
        foreach ([['2026-04-08', 'Date Hired = the first day in the file'], ['2026-10-10', 'Date Hired = the day they were added (today)'], [null, 'no Date Hired']] as [$hired, $what]) {
            $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '13000.00', 'date_hired' => $hired],
                'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly']], 'days' => $days]);
            $row = $r['app'][0];
            $t->same([], Scenario::diff($row, $r['exp'][0]), "$what: engine = ledger");
            $t->money('3000.00', $row['gross_pay'], "$what: 3 days in the file × ₱1,000");
            $t->eq(10, (float)$row['absent_days'], "$what: the 10 working days that are not in the file");
            $t->money('10000.00', $row['absent_deduction'], "$what: 10 × ₱1,000 left out of the kinsena");
        }
    });

    T::test('the file of THIS month so far: only the days uploaded are paid, and the rest of the cut-off is paid as it is uploaded - OFF days and approved leave count as days in the file', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        // Kinsenas ₱13,000 (₱26,000 a month; October 2026 has 27 working days, so a day is ₱962.96). The 1st-15th cut-off has 13 working days (the 4th
        // and 11th are Sundays). Uploaded through the 10th: worked Mon-Sat except the 7th (the sheet says OFF) and the 6th (approved leave, no hours) -
        // nine working days are accounted for. The 12th-15th are not in the file yet.
        $e = Fixtures::employee(['full_name' => 'This Month Case', 'salary_type' => 'kinsenas', 'base_salary' => '13000.00', 'date_hired' => '2026-10-10']);
        $pid = Fixtures::period('Oct 1-15, 2026', '2026-10-01', '2026-10-15');
        $rows = array_values(array_filter(Fixtures::fullDays('This Month Case', '2026-10-01', '2026-10-10'), fn($x) => !in_array($x['att_date'], ['2026-10-06', '2026-10-07'], true)));
        $rows[] = Fixtures::day('This Month Case', '2026-10-07', 0, 0, 0, null, true);
        Fixtures::leave($e, '2026-10-06', '2026-10-06', 'Approved');
        $up = Fixtures::days($pid, $rows);
        $t->same(true, $up['success'] ?? null, json_encode($up));
        $row = Fixtures::payroll($pid)[$e];
        $t->eq(4, (float)$row['absent_days'], 'the 12th-15th are not in the file');
        $t->eq(1, (float)$row['leave_days'], 'the approved leave day (6th) is paid');
        $t->eq(2, (float)$row['days_off'], 'Sunday the 4th and the 7th (OFF); the 11th is after the last uploaded day');
        $t->money('3851.85', $row['absent_deduction'], '4 × 26,000 ÷ 27');
        $t->money('9148.15', $row['gross_pay'], '13,000 − 3,851.85: nine of the thirteen working days');
        // the next day's file adds the 12th-15th: the days accumulate and the cut-off is complete
        $up = Fixtures::days($pid, Fixtures::fullDays('This Month Case', '2026-10-12', '2026-10-15'));
        $t->same(true, $up['success'] ?? null, json_encode($up));
        $row = Fixtures::payroll($pid)[$e];
        $t->money('13000.00', $row['gross_pay'], 'every working day is in the file: the whole kinsena');
        $t->eq(0, (float)$row['absent_days'], 'no unpaid day left');
    });

    T::test('a file for a PAST month, with OFF days and approved leave: nothing the file accounts for is deducted - and the Date Hired (today) changes nothing', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        // Monthly ₱26,000 (26 working days in September 2026, so ₱1,000 a day). Added on 10 Oct 2026 with that day as the Date Hired, then September's
        // timesheet is uploaded: worked Mon-Sat, two rotating OFF days (9th, 23rd), one approved leave day (14th, no hours), a Sunday worked (6th).
        $days = Scenario::fullDays('2026-09-01', '2026-09-30');
        $days['2026-09-09'] = ['off' => true];
        $days['2026-09-23'] = ['off' => true];
        unset($days['2026-09-14']);
        $days['2026-09-06'] = ['h' => '8.00', 'under' => '0'];
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '26000.00', 'date_hired' => '2026-10-10'],
            'runs' => [['start' => '2026-09-01', 'end' => '2026-09-30', 'type' => 'Monthly']], 'days' => $days, 'leave' => ['2026-09-14']]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'engine = ledger');
        $t->money('26000.00', $row['gross_pay'], 'the whole month: every working day is in the file, marked OFF, or approved leave');
        $t->money('0.00', $row['absent_deduction'], 'no unpaid day');
        $t->eq(0, (float)$row['absent_days'], 'no unpaid day');
        $t->eq(1, (float)$row['leave_days'], 'the approved leave day is paid leave');
        $t->eq(5, (float)$row['days_off'], 'the two OFF days the sheet marks + the three Sundays nobody worked');
        // the month-to-date page judges the same days the same way
        $m = monthAttendance(getDB(), '2026-09', null, false)['emps'][$r['emp']];
        $t->same([0, 1, 5], [$m['absent'], $m['leave'], $m['off']], 'This Month\'s Attendance agrees: absent / leave / off');
    });

    T::test('the Date Hired plays no part in the pay: the same file gives the same payroll line whatever it says - nothing, the first day, the day added, a date after the period', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $days = Scenario::fullDays('2026-09-15', '2026-09-30');
        $days['2026-09-22'] = ['off' => true];
        $lines = [];
        foreach ([null, '2026-09-15', '2026-10-10', '2027-01-01'] as $hired) {
            $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '26000.00', 'date_hired' => $hired],
                'runs' => [['start' => '2026-09-01', 'end' => '2026-09-30', 'type' => 'Monthly']], 'days' => $days, 'leave' => ['2026-09-08']]);
            $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]), 'Date Hired ' . ($hired ?? 'none') . ': engine = ledger');
            $row = $r['app'][0];
            $lines[$hired ?? 'none'] = [$row['gross_pay'], $row['absent_days'], $row['leave_days'], $row['days_off'], $row['absent_deduction'], $row['net_pay']];
        }
        $t->same(1, count(array_unique(array_map('json_encode', $lines))), 'one and the same line for every Date Hired: ' . json_encode($lines));
        // 26,000 − 11 × 1,000: of the 12 working days before the 15th (Sundays left out) one is the approved leave day, the other eleven are not in the file
        $t->money('15000.00', array_values($lines)[0][0], 'the 14 working days in the file (one marked OFF) + the leave day = 15 days × ₱1,000');
    });

    T::test('approved leave is a paid day even in a month whose file starts late: September\'s file begins on the 21st, the 8th-9th are approved leave', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e   = Fixtures::employee(['full_name' => 'Leave Case', 'salary_type' => 'monthly', 'base_salary' => '26000.00', 'date_hired' => '2026-10-10']);
        $sep = Fixtures::period('Sep 1-30, 2026', '2026-09-01', '2026-09-30', 'Monthly');
        Fixtures::leave($e, '2026-09-08', '2026-09-09', 'Approved');
        Fixtures::days($sep, Fixtures::fullDays('Leave Case', '2026-09-20', '2026-09-30'));            // the 20th and 27th are Sundays: 9 days from the 21st
        $s = Fixtures::payroll($sep)[$e];
        // September 1-19 has 17 working days (the 6th and 13th are Sundays): 2 are approved leave (paid), the other 15 are not in the file
        $t->eq(15, (float)$s['absent_days'], '15 working days that are not in the file');
        $t->eq(2, (float)$s['leave_days'], 'the 2 approved leave days are paid leave');
        $t->money('11000.00', $s['gross_pay'], '26,000 − 15 × 1,000 = 11 days: the 9 in the file + the 2 of leave');
    });

    T::test('the admin\'s own steps: add the employee on the Employees page with today as the Date Hired, then upload last month\'s file and this month\'s so far - both are paid for the days in the file and both finalize', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $db = getDB();
        $name = 'Added Today Case';
        $r = Http::page('employee.php', ['action' => 'add', 'full_name' => $name, 'branch' => 'MAIN', 'salary_type' => 'monthly', 'base_salary' => '26000',
                                         'date_hired' => '2026-10-10', 'rest_days' => ['7'], 'sss_amount' => '900']);
        $t->contains('added', $r['body'], 'the employee is added');
        $e = (string)$db->query('SELECT emp_id FROM employees WHERE full_name = ' . $db->quote($name))->fetchColumn();
        $t->ok($e !== '', 'the employee exists');

        // last month: worked Mon-Sat, the sheet marks the 9th OFF, the 14th is approved leave (no hours)
        $sep  = Fixtures::period('Sep 1-30, 2026', '2026-09-01', '2026-09-30', 'Monthly');
        $rows = Fixtures::fullDays($name, '2026-09-01', '2026-09-30');
        $rows = array_values(array_filter($rows, fn($x) => !in_array($x['att_date'], ['2026-09-09', '2026-09-14'], true)));
        $rows[] = Fixtures::day($name, '2026-09-09', 0, 0, 0, null, true);
        Fixtures::leave($e, '2026-09-14', '2026-09-14', 'Approved');
        $up = Fixtures::days($sep, $rows);
        $t->same(true, $up['success'] ?? null, json_encode($up));
        $s = Fixtures::payroll($sep)[$e];
        $t->money('26000.00', $s['gross_pay'], 'September: every working day is in the file, marked OFF, or approved leave - paid in full');
        $t->eq(0, (float)$s['absent_days'], 'September: no unpaid day');
        $t->money('900.00', $s['sss'], 'September: the typed SSS');
        $t->money('25100.00', $s['net_pay'], 'September: net pay');

        // this month so far: the 1st-15th cut-off through today (the 10th), the 7th OFF, the 6th approved leave - the 12th-15th are not in the file yet
        $oct  = Fixtures::period('Oct 1-15, 2026', '2026-10-01', '2026-10-15');
        $rows = Fixtures::fullDays($name, '2026-10-01', '2026-10-10');
        $rows = array_values(array_filter($rows, fn($x) => !in_array($x['att_date'], ['2026-10-06', '2026-10-07'], true)));
        $rows[] = Fixtures::day($name, '2026-10-07', 0, 0, 0, null, true);
        Fixtures::leave($e, '2026-10-06', '2026-10-06', 'Approved');
        $up = Fixtures::days($oct, $rows);
        $t->same(true, $up['success'] ?? null, json_encode($up));
        $o = Fixtures::payroll($oct)[$e];
        $t->eq(4, (float)$o['absent_days'], 'October: the 4 working days (12th-15th) that are not in the file are not paid');
        $t->money('9148.15', $o['gross_pay'], 'October 1-15 so far: ₱13,000 for the cut-off − 4 × 26,000 ÷ 27');
        $t->money('900.00', $o['sss'], 'October: SSS in full on the 1st cut-off');

        foreach ([$sep, $oct] as $pid) {
            $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid]);
            $t->same(true, $f['json']['success'] ?? null, "finalize $pid: " . $f['body']);
        }
    });

    T::test('a daily-rate employee added today is paid exactly the hours the file shows, for a past month too (the Date Hired never matters to a daily rate)', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '500.00', 'date_hired' => '2026-10-10'],
            'runs' => [['start' => '2026-09-01', 'end' => '2026-09-15', 'type' => 'Semi-Monthly']], 'days' => Scenario::fullDays('2026-09-01', '2026-09-12')]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]), 'engine = ledger');
        $t->money('5500.00', $r['app'][0]['gross_pay'], '11 days worked (1st-5th, 7th-12th) × ₱500');
        $t->eq(0, (float)$r['app'][0]['absent_days'], 'no absence is invented');
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
        $e = Fixtures::employee(['full_name' => 'Stale Case', 'base_salary' => '800.00', 'sss_amount' => '400.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Stale Case', '2026-04-01', '2026-04-15'));
        $t->money('400.00', Fixtures::payroll($pid)[$e]['sss'], 'the typed SSS is deducted');
        // the employee's typed amount changed behind the engine's back (the Employee page would have recomputed; this does not)
        getDB()->prepare("UPDATE employees SET sss_amount = 0 WHERE emp_id = ?")->execute([$e]);
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
        $n = Fixtures::employee(['full_name' => 'Negative Case', 'base_salary' => '480.00', 'sss_amount' => '250.00', 'philhealth_amount' => '250.00']);
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
        $n = Fixtures::employee(['full_name' => 'Banner Negative', 'base_salary' => '480.00', 'sss_amount' => '250.00', 'philhealth_amount' => '250.00']);
        $p = Fixtures::period('Apr 1-30, 2026', '2026-04-01', '2026-04-30', 'Monthly');
        Fixtures::days($p, [Fixtures::day('Banner Negative', '2026-04-01', 8, 0, 0, 0)]);
        $page = Http::page('payroll.php', [], ['period' => $p])['body'];
        $t->contains('have a negative net pay', $page);
        $t->contains('Banner Negative', $page);
        $t->contains('−₱', $page, 'the negative amount is printed signed');
        $t->contains('Recompute', $page, 'the Recompute button exists on an open period');
        getDB()->prepare("UPDATE employees SET sss_amount = 0 WHERE emp_id = ?")->execute([$n]);
        $t->contains('no longer settle the month correctly', Http::page('payroll.php', [], ['period' => $p])['body'], 'a stale line is announced');
    });

    T::test('a finalized month is called out of date only when an EARLIER cut-off changed after it was finalized - a raise made later is not a reason to "recompute" history', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $e = Fixtures::employee(['full_name' => 'Raise Case', 'base_salary' => '20000.00', 'salary_type' => 'monthly', 'sss_amount' => '500.00', 'philhealth_amount' => '300.00']);
        $a = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $b = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        Fixtures::days($a, Fixtures::fullDays('Raise Case', '2026-04-01', '2026-04-15'));
        Fixtures::days($b, Fixtures::fullDays('Raise Case', '2026-04-16', '2026-04-30'));
        foreach ([$a, $b] as $pid) $t->same(true, Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid])['json']['success'] ?? null, "finalize $pid");
        $db = getDB();
        $db->exec("UPDATE payroll_periods SET finalized_at = '2026-05-01 10:00:00' WHERE id = $a");
        $db->exec("UPDATE payroll_periods SET finalized_at = '2026-05-01 10:05:00' WHERE id = $b");

        $db->prepare("UPDATE employees SET base_salary = 40000, philhealth_amount = 400 WHERE emp_id = ?")->execute([$e]);       // a raise and a higher PhilHealth, months later
        $t->ok(settlementDrift($db, $b) !== [], 'by arithmetic alone cut-off 2 would now differ (PhilHealth ₱300 was taken, the employee now says ₱400)');
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

    T::test('the schema gains exactly four optional columns on employees (sss_amount, philhealth_amount, pagibig_amount, tax_amount: DECIMAL(12,2) NOT NULL DEFAULT 0) - an existing employee starts with none, so nothing is deducted until the admin types it', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $db = getDB();
        foreach (['sss_amount', 'philhealth_amount', 'pagibig_amount', 'tax_amount'] as $col) {
            $c = $db->query("SELECT COLUMN_TYPE AS ct, IS_NULLABLE AS nul, COLUMN_DEFAULT AS def FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = '$col'")->fetch();
            $t->ok($c !== false, "employees.$col exists");
            $t->same(['decimal(12,2)', 'NO', '0.00'], [$c['ct'] ?? null, $c['nul'] ?? null, $c['def'] ?? null], "employees.$col is DECIMAL(12,2) NOT NULL DEFAULT 0");
        }
        // an employee row written without the new columns (as every row of an older database is) has none, and the engine deducts nothing
        $db->exec("INSERT INTO employees (emp_id, full_name, base_salary, salary_type, branch) VALUES ('OLD-ROW', 'Old Row', 480, 'daily', 'MAIN')");
        $row = Fixtures::empRow('OLD-ROW');
        $t->same(['0.00', '0.00', '0.00', '0.00'], [$row['sss_amount'], $row['philhealth_amount'], $row['pagibig_amount'], $row['tax_amount']], 'an existing employee starts with no amounts');
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Old Row', '2026-04-01', '2026-04-15'));
        $line = Fixtures::payroll($pid)['OLD-ROW'];
        $t->moneyMap(['sss' => '0.00', 'philhealth' => '0.00', 'pagibig' => '0.00', 'withholding_tax' => '0.00'], $line, 'nothing deducted');
        $t->money($line['gross_pay'], $line['net_pay'], 'net pay is gross pay');
        // the fresh-install file builds the same columns (suite 13 compares the whole schema)
        $sql = (string)file_get_contents(AppCopy::root() . '/sql/database.sql');
        foreach (['sss_amount', 'philhealth_amount', 'pagibig_amount', 'tax_amount'] as $col) $t->contains($col, $sql, "sql/database.sql creates $col");
    });
});
