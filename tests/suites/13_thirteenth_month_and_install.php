<?php
/*
 * 13 — 13th Month Pay (PD 851), and the pieces added with it.
 *
 *   13th-month pay = total BASIC pay earned in the calendar year ÷ 12   (thirteenthMonthData(), thirteenth-month.php)
 *
 *   A  the arithmetic, by hand: a full year, a part year, overtime / bonus left out, half-centavo rounding, year boundaries
 *   B  the computation sheet against the independent ledger: three employees, three months, absences, undertime, overtime
 *   C  recording the payment: full, in two instalments, never more than the balance, never twice, the ₱90,000 ceiling, closed periods
 *   D  the page: figures, CSV, access control, sidebar
 *   E  the Home page shows a finalized period as finalized (D-19)
 *   F  a fresh install: sql/database.sql + the application's own first-load setup is enough
 *
 * Everything here describes the audit-fixed application and is skipped on the original.
 */

/** an open pay period row, straight into the table (a test that wants round numbers does not need the upload) */
function qa13_period(string $start, string $end, string $status = 'Open'): int
{
    $db = getDB();
    $have = $db->prepare("SELECT id FROM payroll_periods WHERE period_start = ? AND period_end = ?");
    $have->execute([$start, $end]);
    if ($id = $have->fetchColumn()) return (int)$id;               // employees of one scene share the cut-offs
    $db->prepare("INSERT INTO payroll_periods (period_label, period_start, period_end, period_type, status) VALUES (?,?,?,?,?)")
       ->execute([date('M j', strtotime($start)) . '-' . date('j, Y', strtotime($end)), $start, $end, 'Semi-Monthly', $status]);
    return (int)$db->lastInsertId();
}

/** a payroll line straight into the table: gross pay, of which $otLate is overtime / tardiness, plus a bonus */
function qa13_line(int $periodId, string $empId, string $name, $gross, $otLate = 0, $bonus = 0): void
{
    getDB()->prepare("INSERT INTO payroll (period_id, emp_id, emp_name, gross_pay, ot_late_adj, bonus, net_pay) VALUES (?,?,?,?,?,?,?)")
           ->execute([$periodId, $empId, $name, $gross, $otLate, $bonus, (float)$gross + (float)$bonus]);
}

/** twelve months × two cut-offs of one year for one employee, $perCutoff pesos of basic pay each, from month $from to month $to */
function qa13_year(string $empId, string $name, int $year, $perCutoff, int $from = 1, int $to = 12, $otLate = 0, $bonus = 0): array
{
    $ids = [];
    for ($m = $from; $m <= $to; $m++) {
        $first = sprintf('%d-%02d-01', $year, $m);
        $ids[] = $a = qa13_period($first, sprintf('%d-%02d-15', $year, $m));
        $ids[] = $b = qa13_period(sprintf('%d-%02d-16', $year, $m), date('Y-m-t', strtotime($first)));
        qa13_line($a, $empId, $name, (float)$perCutoff + (float)$otLate, $otLate, $bonus);
        qa13_line($b, $empId, $name, (float)$perCutoff + (float)$otLate, $otLate, 0);
    }
    return $ids;
}

T::suite('13 · 13th Month Pay & fresh install', function () {

    /* ================================================================== A · the arithmetic, by hand */

    T::test('a full year at ₱15,000 a kinsena: ₱360,000 of basic pay ÷ 12 = ₱30,000.00 — ₱30,000 a month, ₱2,500 set aside each month', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::employee(['emp_id' => 'E-FULL', 'full_name' => 'Full Year', 'base_salary' => '15000.00', 'salary_type' => 'kinsenas']);
        qa13_year('E-FULL', 'Full Year', 2026, '15000.00');
        $d = thirteenthMonthData(getDB(), 2026);
        $r = $d['rows']['E-FULL'];
        $t->same(12, $r['months_paid']);
        foreach (range(1, 12) as $m) $t->money('30000.00', $r['months'][$m], "basic pay of month $m (two cut-offs of ₱15,000)");
        $t->money('360000.00', $r['basic'], '12 × ₱30,000');
        $t->money('30000.00', $r['due'], '13th month = 360,000 ÷ 12');
        $t->money('0.00', $r['paid']);
        $t->money('30000.00', $r['balance']);
        foreach (range(1, 12) as $m) $t->money('2500.00', $d['totals']['accrual'][$m], "set aside in month $m = 30,000 ÷ 12");
        $t->same(24, $d['periods'], '24 cut-offs');
        $t->same(24, $d['open_periods'], 'all still open');
        $t->same('2026-12-31', $d['last_end']);
    });

    T::test('part of a year is pro-rated, someone who left is still listed, and a year with no pay lists nobody', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::employee(['emp_id' => 'E-NEW', 'full_name' => 'Hired In August', 'base_salary' => '15000.00', 'salary_type' => 'kinsenas', 'date_hired' => '2026-08-01']);
        Fixtures::employee(['emp_id' => 'E-GONE', 'full_name' => 'Resigned In January', 'base_salary' => '15000.00', 'salary_type' => 'kinsenas', 'status' => 'Inactive']);
        // one set of periods, two employees: August–December for the first, January for the second
        $ids = [];
        for ($m = 1; $m <= 12; $m++) {
            $first = sprintf('2026-%02d-01', $m);
            $a = qa13_period($first, sprintf('2026-%02d-15', $m));
            $b = qa13_period(sprintf('2026-%02d-16', $m), date('Y-m-t', strtotime($first)));
            if ($m >= 8) { qa13_line($a, 'E-NEW', 'Hired In August', '15000.00'); qa13_line($b, 'E-NEW', 'Hired In August', '15000.00'); }
            if ($m === 1) { qa13_line($a, 'E-GONE', 'Resigned In January', '15000.00'); qa13_line($b, 'E-GONE', 'Resigned In January', '15000.00'); }
        }
        $d = thirteenthMonthData(getDB(), 2026);
        $t->money('150000.00', $d['rows']['E-NEW']['basic'], 'August–December = 5 × ₱30,000');
        $t->money('12500.00', $d['rows']['E-NEW']['due'], '150,000 ÷ 12 — five twelfths of a full 13th month');
        $t->same(5, $d['rows']['E-NEW']['months_paid']);
        $t->money('2500.00', $d['rows']['E-GONE']['due'], 'January only: 30,000 ÷ 12');
        $t->same('Inactive', $d['rows']['E-GONE']['status'], 'still listed: it is due on separation');
        $t->same([], thirteenthMonthData(getDB(), 2030)['rows'], 'a year with no payroll');
    });

    T::test('overtime, tardiness adjustments and bonuses are NOT basic pay: each month ₱31,000 gross with ₱1,000 overtime and a ₱500 bonus still gives ₱30,000 a month', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::employee(['emp_id' => 'E-OT', 'full_name' => 'Overtime Worker', 'base_salary' => '15000.00', 'salary_type' => 'kinsenas']);
        qa13_year('E-OT', 'Overtime Worker', 2026, '15000.00', 1, 12, '500.00', '500.00');      // per cut-off: gross 15,500 of which 500 overtime; ₱500 bonus in the first cut-off of each month
        $r = thirteenthMonthData(getDB(), 2026)['rows']['E-OT'];
        $t->money('360000.00', $r['basic'], 'the overtime is out');
        $t->money('30000.00', $r['due']);
        $t->money('6000.00', $r['other_bonus'], 'twelve ₱500 bonuses are other bonuses, counted towards the ₱90,000 ceiling but not into the 13th month');
        $t->money('0.00', $r['taxable_excess'], '36,000 + 6,000 is far below 90,000');
    });

    T::test('a half centavo rounds UP: 300 random yearly totals and every tie come out as ⌊total ÷ 12 + ½⌉ in centavos', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        mt_srand(1313);
        $want = [];
        $totals = [6, 18, 10000050, 10000046, 10000058, 1, 5, 7, 11, 12];                 // centavos: ties (…6 mod 12), just under / over, tiny
        for ($i = 0; $i < 300; $i++) $totals[] = $i % 3 === 0 ? mt_rand(1, 50000) * 12 + 6 : mt_rand(1, 400000000);   // a third are exact ties
        $per = qa13_period('2026-03-01', '2026-03-15');
        foreach ($totals as $i => $c) {
            $id = 'R' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
            Fixtures::employee(['emp_id' => $id, 'full_name' => "Round $i"]);
            qa13_line($per, $id, "Round $i", sprintf('%d.%02d', intdiv($c, 100), $c % 100));
            $want[$id] = (int)floor($c / 12 + 0.5);
        }
        $d = thirteenthMonthData(getDB(), 2026);
        $bad = [];
        foreach ($want as $id => $cents) {
            $t->checks++;
            if ((int)round($d['rows'][$id]['due'] * 100) !== $cents && count($bad) < 5) $bad[] = "$id: total ₱{$d['rows'][$id]['basic']} → app ₱{$d['rows'][$id]['due']}, expected ₱" . Ledger::fmt($cents);
        }
        $t->same([], $bad);
        $t->money('0.01', $d['rows']['R0000']['due'], '₱0.06 ÷ 12 = ₱0.005 → ₱0.01');
        $t->money('8333.38', $d['rows']['R0002']['due'], '₱100,000.50 ÷ 12 = 8,333.375 → 8,333.38');
        $t->money('8333.37', $d['rows']['R0003']['due'], '₱100,000.46 ÷ 12 = 8,333.3716… → 8,333.37');
    });

    T::test('a year is the year the pay period STARTS in: Dec 16–31 and a Dec 26 – Jan 10 period belong to the old year, Jan 1–15 to the new', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::employee(['emp_id' => 'E-YR', 'full_name' => 'Year Boundary']);
        qa13_line(qa13_period('2025-12-16', '2025-12-31'), 'E-YR', 'Year Boundary', '1200.00');
        qa13_line(qa13_period('2025-12-26', '2026-01-10'), 'E-YR', 'Year Boundary', '100.00');      // spans the new year: counted in 2025
        qa13_line(qa13_period('2026-01-01', '2026-01-15'), 'E-YR', 'Year Boundary', '4000.00');
        $old = thirteenthMonthData(getDB(), 2025)['rows']['E-YR'];
        $new = thirteenthMonthData(getDB(), 2026)['rows']['E-YR'];
        $t->money('1300.00', $old['basic'], '1,200 + 100');
        $t->money('1300.00', $old['months'][12], 'both in December');
        $t->money('4000.00', $new['basic'], 'only the January period');
        $t->money('4000.00', $new['months'][1]);
    });

    /* ================================================================== B · the sheet against the independent ledger */

    T::test('three employees, three months through the real engine: every month\'s basic pay equals the ledger\'s, and so does the 13th month', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $emps = [
            'Q Daily'    => Fixtures::employee(['full_name' => 'Q Daily',    'base_salary' => '500.00']),
            'Q Kinsenas' => Fixtures::employee(['full_name' => 'Q Kinsenas', 'base_salary' => '15000.00', 'salary_type' => 'kinsenas']),
            'Q Monthly'  => Fixtures::employee(['full_name' => 'Q Monthly',  'base_salary' => '26000.00', 'salary_type' => 'monthly']),
        ];
        // a pattern with an absence, an hour of undertime and two hours of overtime in every month
        $pattern = function (string $from, string $to): array {
            $days = [];
            foreach (Ledger::dates($from, $to) as $d) {
                if (Ledger::dow($d) === 7) continue;
                $n = (int)substr($d, 8, 2);
                if ($n % 9 === 0) continue;                                              // absent
                if ($n % 7 === 0) $days[$d] = ['h' => '7.00', 'under' => '1'];           // an hour short
                elseif ($n % 5 === 0) $days[$d] = ['h' => '8.00', 'ot' => '2', 'under' => '0'];
                else $days[$d] = ['h' => '8.00', 'under' => '0'];
            }
            return $days;
        };
        $cfg = ['ot_rate' => '45', 'refund' => true, 'prehire' => true, 'timing' => ['sss' => 'split', 'philhealth' => 'second', 'pagibig' => 'second']];
        $expect = array_fill_keys(array_keys($emps), array_fill(1, 12, 0));
        foreach ([1, 2, 3] as $m) {
            $first = sprintf('2026-%02d-01', $m);
            $last  = date('Y-m-t', strtotime($first));
            $runs  = [['start' => $first, 'end' => sprintf('2026-%02d-15', $m), 'type' => 'Semi-Monthly'],
                      ['start' => sprintf('2026-%02d-16', $m), 'end' => $last, 'type' => 'Semi-Monthly']];
            $days = $pattern($first, $last);
            foreach ($runs as $k => $run) {
                $rows = [];
                foreach ($emps as $name => $id) {
                    foreach ($days as $d => $v) {
                        if ($d < $run['start'] || $d > $run['end']) continue;
                        $rows[] = Fixtures::day($name, $d, $v['h'], $v['ot'] ?? 0, 0, $v['under']);
                    }
                }
                $r = Fixtures::days(Fixtures::period("Q $m-$k", $run['start'], $run['end']), $rows);
                $t->same(true, $r['success'] ?? null, json_encode($r));
            }
            foreach ($emps as $name => $id) {
                $month = Ledger::month(Fixtures::ledgerEmp(Fixtures::empRow($id)), $runs, $days, [], $cfg);
                $expect[$name][$m] = array_sum(array_column($month, 'basic'));
            }
        }
        $d = thirteenthMonthData(getDB(), 2026);
        foreach ($emps as $name => $id) {
            $row = $d['rows'][$id];
            foreach ([1, 2, 3] as $m) $t->money($expect[$name][$m], $row['months'][$m], "$name — basic pay of month $m, the ledger's: ₱" . Ledger::fmt($expect[$name][$m]));
            $sum = array_sum($expect[$name]);
            $t->money($sum, $row['basic'], "$name — three months");
            $t->money((int)floor($sum / 12 + 0.5), $row['due'], "$name — 13th month = ⌊Σ ÷ 12 + ½⌉");
            $t->ok($row['due'] > 0 && $row['basic'] > 0, "$name has a 13th month");
        }
        // overtime is in gross pay but not in basic pay: the kinsenas employee earned overtime, yet her basic pay is below ₱30,000 a month
        $t->ok($d['rows'][$emps['Q Kinsenas']]['months'][1] < 30000.0, 'absence and undertime are out of basic pay');
    });

    /* ================================================================== C · recording the payment */

    /** two employees with a full year on file and a fresh open period (Dec 16–31 of 2027, outside the year of the data) to pay in */
    $payScene = function (): array {
        Fixtures::reset();
        Fixtures::employee(['emp_id' => 'E-A', 'full_name' => 'Ana Pay', 'base_salary' => '15000.00', 'salary_type' => 'kinsenas']);
        Fixtures::employee(['emp_id' => 'E-B', 'full_name' => 'Ben Pay', 'base_salary' => '10000.00', 'salary_type' => 'kinsenas']);
        qa13_year('E-A', 'Ana Pay', 2026, '15000.00');
        qa13_year('E-B', 'Ben Pay', 2026, '10000.00');
        // the payment goes into the last open cut-off of the year (the 24th period holds both employees)
        $target = (int)getDB()->query("SELECT id FROM payroll_periods WHERE period_start = '2026-12-16'")->fetchColumn();
        return [$target];
    };
    $pay = fn(int $target, array $emps, array $amounts, array $extra = [], int $year = 2026) =>
        Http::page('thirteenth-month.php', ['action' => 'pay', 'year' => $year, 'period_id' => $target, 'emp_ids' => $emps, 'amount' => $amounts] + $extra);

    T::test('recording the payment: Bonus on the chosen open period, history row "13th Month Pay 2026", net pay up by the amount — and a second click pays nothing', function (T $t) use ($payScene, $pay) {
        qa_need_fixes();
        [$target] = $payScene();
        $db = getDB();
        $before = Fixtures::payroll($target);
        $t->money('30000.00', thirteenthMonthData($db, 2026)['rows']['E-A']['balance']);
        $t->money('20000.00', thirteenthMonthData($db, 2026)['rows']['E-B']['balance']);

        $r = $pay($target, ['E-A', 'E-B'], ['E-A' => '30000.00', 'E-B' => '20000.00']);
        $t->contains('13th Month Pay 2026 of', $r['body'], 'the page says what it did');
        $after = Fixtures::payroll($target);
        $t->money('30000.00', $after['E-A']['bonus'], 'bonus on Ana\'s payslip');
        $t->money('20000.00', $after['E-B']['bonus']);
        $t->money(Ledger::c($before['E-A']['net_pay']) + 3000000, $after['E-A']['net_pay'], 'net pay up by exactly the 13th month');
        $hist = $db->query("SELECT emp_id, entry_type, amount, reason, period_id FROM bonus_deduction_history ORDER BY emp_id")->fetchAll();
        $t->same([['E-A', 'Bonus', '30000.00', '13th Month Pay 2026', $target], ['E-B', 'Bonus', '20000.00', '13th Month Pay 2026', $target]],
                 array_map('array_values', $hist), 'history');
        $d = thirteenthMonthData($db, 2026);
        $t->money('30000.00', $d['rows']['E-A']['paid']);
        $t->money('0.00', $d['rows']['E-A']['balance'], 'nothing left to pay');
        $t->money('0.00', $d['totals']['balance']);

        // press the same button again
        $again = $pay($target, ['E-A', 'E-B'], ['E-A' => '30000.00', 'E-B' => '20000.00']);
        $t->contains('more than the balance of ₱0.00', $again['body'], 'refused: nothing is owed');
        $t->same(2, (int)$db->query('SELECT COUNT(*) FROM bonus_deduction_history')->fetchColumn(), 'no new history rows');
        $t->money('30000.00', Fixtures::payroll($target)['E-A']['bonus'], 'bonus unchanged');
    });

    T::test('in two instalments: half in June as an advance, the balance in December; the legacy "13th Month Pay" typed on Bonus & Deductions counts as paid too', function (T $t) use ($payScene, $pay) {
        qa_need_fixes();
        [$target] = $payScene();
        $db = getDB();
        $june = (int)$db->query("SELECT id FROM payroll_periods WHERE period_start = '2026-06-16'")->fetchColumn();
        $pay($june, ['E-A'], ['E-A' => '15000.00']);                                         // the advance: half of ₱30,000
        $d = thirteenthMonthData($db, 2026)['rows']['E-A'];
        $t->money('15000.00', $d['paid']);
        $t->money('15000.00', $d['balance']);
        // Ben's half was typed by hand on the Bonus & Deductions page long ago, with the old reason text
        Http::page('adjustments.php', ['period_id' => $june, 'emp_ids' => ['E-B'], 'entry_type' => 'Bonus', 'amount' => '10000', 'reason_select' => '13th Month Pay']);
        Http::page('adjustments.php', ['period_id' => $june, 'emp_ids' => ['E-B'], 'entry_type' => 'Bonus', 'amount' => '77', 'reason_select' => 'Performance Reward']);
        $db->prepare("INSERT INTO bonus_deduction_history (entry_date, emp_id, emp_name, entry_type, amount, reason, period_id) VALUES (CURDATE(), 'E-B', 'Ben Pay', 'Deduction', 5000, '13th Month Pay', ?)")->execute([$june]);
        $b = thirteenthMonthData($db, 2026)['rows']['E-B'];
        $t->money('10000.00', $b['paid'], 'only the Bonus entries whose reason starts "13th Month Pay" count — not a Performance Reward, not a Deduction');
        $t->money('10000.00', $b['balance']);
        $t->money('77.00', $b['other_bonus'], 'the Performance Reward is another bonus');

        $pay($target, ['E-A', 'E-B'], ['E-A' => '15000.00', 'E-B' => '10000.00']);          // December: the balances
        $d = thirteenthMonthData($db, 2026);
        $t->money('30000.00', $d['rows']['E-A']['paid']);
        $t->money('0.00', $d['rows']['E-A']['balance']);
        $t->money('20000.00', $d['rows']['E-B']['paid']);
        $t->money('0.00', $d['totals']['balance']);
        // the entries from a different year are not counted
        $db->prepare("INSERT INTO bonus_deduction_history (entry_date, emp_id, emp_name, entry_type, amount, reason, period_id) VALUES (CURDATE(), 'E-A', 'Ana Pay', 'Bonus', 9999, '13th Month Pay 2025', ?)")
           ->execute([qa13_period('2025-12-01', '2025-12-15')]);
        $t->money('30000.00', thirteenthMonthData($db, 2026)['rows']['E-A']['paid'], '2025\'s 13th month is not 2026\'s');
    });

    T::test('amounts the page refuses: zero, negative, text, 1e999, more than the balance, an employee with no pay that year — nothing is recorded', function (T $t) use ($payScene, $pay) {
        qa_need_fixes();
        [$target] = $payScene();
        $db = getDB();
        foreach (['0', '-5', 'abc', '1e999', '', '30000.01', '99999999'] as $bad) {
            $r = $pay($target, ['E-A'], ['E-A' => $bad]);
            $t->contains('Nothing was recorded', $r['body'], "amount \"$bad\"");
        }
        $r = $pay($target, ['NOBODY'], ['NOBODY' => '100']);
        $t->contains('has no pay in 2026', $r['body']);
        $t->same(0, (int)$db->query('SELECT COUNT(*) FROM bonus_deduction_history')->fetchColumn(), 'no history rows');
        $t->money('0.00', Fixtures::payroll($target)['E-A']['bonus']);
        // exactly the balance is fine, and so are the centavos of an amount that is not a round number
        $r = $pay($target, ['E-A'], ['E-A' => '30000.00']);
        $t->contains('recorded for 1 employee', $r['body']);
    });

    T::test('a closed period cannot receive the payment; with no open period the page says so', function (T $t) use ($payScene, $pay) {
        qa_need_fixes();
        [$target] = $payScene();
        $db = getDB();
        $db->exec("UPDATE payroll_periods SET status = 'Locked' WHERE id = $target");
        $r = $pay($target, ['E-A'], ['E-A' => '30000.00']);
        $t->contains('Choose an open pay period', $r['body']);
        $t->same(0, (int)$db->query('SELECT COUNT(*) FROM bonus_deduction_history')->fetchColumn());
        $db->exec("UPDATE payroll_periods SET status = 'Locked'");
        $page = Http::page('thirteenth-month.php', [], ['year' => 2026])['body'];
        $t->contains('No open pay period', $page, 'the page explains why there is no Pay button');
        $t->notContains('name="emp_ids[]"', $page, 'and offers no tick boxes');
    });

    T::test('a payment into a period that was finalized and re-opened is stamped as a revision, and a payment with an employee that has no payroll line there is reported', function (T $t) use ($payScene, $pay) {
        qa_need_fixes();
        [$target] = $payScene();
        $db = getDB();
        $db->exec("UPDATE payroll_periods SET finalize_count = 1, reopen_count = 1 WHERE id = $target");     // finalized once, now open again
        $r = $pay($target, ['E-A'], ['E-A' => '30000.00']);
        $t->contains('marked as a revision', $r['body']);
        $t->same(1, (int)$db->query("SELECT revised_after_finalize FROM payroll WHERE period_id = $target AND emp_id = 'E-A'")->fetchColumn(), 'payroll row stamped');
        $t->same(1, (int)$db->query("SELECT finalize_cycle FROM bonus_deduction_history WHERE emp_id = 'E-A'")->fetchColumn(), 'history row stamped with the cycle');
        // a period where Ben has no payroll line
        $empty = qa13_period('2027-01-01', '2027-01-15');
        qa13_line($empty, 'E-A', 'Ana Pay', '15000.00');
        $r = $pay($empty, ['E-B'], ['E-B' => '20000.00']);
        $t->contains('No payroll line in', $r['body']);
        $t->money('0.00', thirteenthMonthData($db, 2026)['rows']['E-B']['paid'], 'nothing was paid');
    });

    T::test('one payment with a different amount per employee, into a re-opened period: all or nothing in one transaction, and the audit trail states the total', function (T $t) use ($payScene, $pay) {
        qa_need_fixes();
        [$target] = $payScene();
        $db = getDB();
        $db->exec("UPDATE payroll_periods SET finalize_count = 2, reopen_count = 2 WHERE id = $target");
        $r = $pay($target, ['E-A', 'E-B'], ['E-A' => '100.10', 'E-B' => '250.25']);
        $t->contains('13th Month Pay 2026 of ₱350.35 recorded for 2 employee', $r['body']);
        $pa = Fixtures::payroll($target);
        $t->money('100.10', $pa['E-A']['bonus']);
        $t->money('250.25', $pa['E-B']['bonus']);
        $t->money('15100.10', $pa['E-A']['net_pay'], 'net pay = gross 15,000 + 100.10');
        $note = (string)$db->query("SELECT note FROM period_audit WHERE period_id = $target AND action = 'Revised' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $t->same('Bonus totalling PHP 350.35 (13th Month Pay 2026) applied to 2 employee(s) after finalize #2.', $note, 'unequal amounts → "totalling"');
        // equal amounts keep the older wording
        qa_adjust($target, ['E-A', 'E-B'], 'Bonus', '10');
        $note = (string)$db->query("SELECT note FROM period_audit WHERE period_id = $target AND action = 'Revised' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $t->same('Bonus of PHP 10.00 (Performance bonus) applied to 2 employee(s) after finalize #2.', $note);
        $t->same(4, (int)$db->query('SELECT COUNT(*) FROM bonus_deduction_history WHERE finalize_cycle = 2')->fetchColumn(), 'four history rows, each stamped with cycle 2');
    });

    T::test('the ₱90,000 ceiling: a 13th month of ₱120,000 is held back for a decision; ₱90,000 is allowed; the rest is held; "Record anyway" records it once', function (T $t) use ($pay) {
        qa_need_fixes();
        Fixtures::reset();
        Fixtures::employee(['emp_id' => 'E-BIG', 'full_name' => 'Big Earner', 'base_salary' => '60000.00', 'salary_type' => 'kinsenas']);
        qa13_year('E-BIG', 'Big Earner', 2026, '60000.00');                                  // ₱1,440,000 of basic pay → ₱120,000
        $db = getDB();
        $target = (int)$db->query("SELECT id FROM payroll_periods WHERE period_start = '2026-12-16'")->fetchColumn();
        $d = thirteenthMonthData($db, 2026)['rows']['E-BIG'];
        $t->money('120000.00', $d['due']);
        $t->money('30000.00', $d['taxable_excess'], '120,000 − 90,000, shown on the sheet');

        $r = $pay($target, ['E-BIG'], ['E-BIG' => '120000.00']);
        $t->contains('HELD BACK', $r['body']);
        $t->contains('Record anyway', $r['body']);
        $t->money('0.00', Fixtures::payroll($target)['E-BIG']['bonus'], 'nothing recorded yet');

        $r = $pay($target, ['E-BIG'], ['E-BIG' => '90000.00']);                              // exactly the ceiling: allowed
        $t->contains('recorded for 1 employee', $r['body']);
        $t->money('90000.00', Fixtures::payroll($target)['E-BIG']['bonus']);
        $r = $pay($target, ['E-BIG'], ['E-BIG' => '30000.00']);                              // the rest crosses it: held
        $t->contains('HELD BACK', $r['body']);
        $t->money('90000.00', Fixtures::payroll($target)['E-BIG']['bonus'], 'still ₱90,000');
        $r = $pay($target, ['E-BIG'], ['E-BIG' => '30000.00'], ['confirm_over_exempt' => '1']);   // what the "Record anyway" button sends
        $t->contains('recorded for 1 employee', $r['body']);
        $t->money('120000.00', Fixtures::payroll($target)['E-BIG']['bonus']);
        $t->same(2, (int)$db->query("SELECT COUNT(*) FROM bonus_deduction_history WHERE emp_id = 'E-BIG'")->fetchColumn(), 'two entries: 90,000 and 30,000');
        $t->money('0.00', thirteenthMonthData($db, 2026)['rows']['E-BIG']['balance']);
    });

    /* ================================================================== D · the page */

    T::test('the page: figures, month columns, totals and the monthly accrual; the CSV carries the same numbers; the sidebar links to it', function (T $t) use ($payScene) {
        qa_need_fixes();
        $payScene();
        $page = Http::page('thirteenth-month.php', [], ['year' => 2026]);
        $t->same(200, $page['status']);
        $t->same([], $page['warnings'] ?? [], 'no PHP warnings');
        foreach (['13th Month Pay', 'Presidential Decree 851', 'Ana Pay', 'Ben Pay', 'Set aside each month', 'December 24', 'Computation sheet 2026'] as $needle) {
            $t->contains($needle, $page['body'], $needle);
        }
        $t->contains('50,000.00', $page['body'], 'total 13th month ₱30,000 + ₱20,000');
        $t->contains('600,000.00', $page['body'], 'total basic pay ₱360,000 + ₱240,000');
        $t->contains('thirteenth-month.php', Http::page('dashboard.php')['body'], 'the sidebar link is on every admin page');
        $csv = Http::page('thirteenth-month.php', [], ['year' => 2026, 'export' => 'csv']);
        $lines = preg_split('/\R/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv['body'])));
        $t->same(['Emp ID', 'Name', 'Status', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
                  'Basic pay earned', '13th month (basic / 12)', 'Paid so far', 'Balance'], str_getcsv($lines[0]), 'header');
        $t->same(4, count($lines), 'header, two employees, total');
        $a = str_getcsv($lines[1]);
        $t->same(['E-A', 'Ana Pay', 'Active'], array_slice($a, 0, 3));
        $t->same(['30000.00', '30000.00'], [$a[3], $a[14]], 'January and December basic pay');
        $t->same(['360000.00', '30000.00', '0.00', '30000.00'], array_slice($a, 15), 'basic, 13th month, paid, balance');
        $tot = str_getcsv($lines[3]);
        $t->same(['TOTAL', '600000.00', '50000.00'], [$tot[1], $tot[15], $tot[16]]);
    });

    T::test('access: a visitor and a manager are sent to the sign-in page; an empty year shows a clear message instead of a table', function (T $t) {
        qa_need_fixes();
        Fixtures::reset();
        $t->same(302, Http::call('thirteenth-month.php', ['method' => 'GET', 'session' => []])['status'], 'no session');
        getDB()->prepare("INSERT INTO users (full_name, email, password_hash, role, branch, scope_type) VALUES ('Mgr', ?, 'x', 'manager', '', 'branch')")->execute(['mgr13-' . uniqid() . '@test']);
        $mid = (int)getDB()->lastInsertId();
        $t->same(302, Http::page('thirteenth-month.php', [], [], Http::manager($mid, 'Mgr', ''))['status'], 'a manager');
        $t->same(302, Http::call('thirteenth-month.php', ['method' => 'POST', 'post' => ['action' => 'pay'], 'session' => []])['status'], 'a visitor posting a payment');
        $page = Http::page('thirteenth-month.php', [], ['year' => 2031])['body'];
        $t->contains('There is no payroll for 2031 yet', $page);
    });

    T::test('the DBeaver query for 13th month pay and the sheet agree (C60: more 13th month recorded than basic pay allows)', function (T $t) use ($payScene, $pay) {
        qa_need_fixes();
        [$target] = $payScene();
        $db = getDB();
        $sql = (string)file_get_contents(dirname(__DIR__) . '/dbeaver_checks.sql');
        $t->ok(str_contains($sql, '-- @check C60'), 'the check exists');
        $checks = array_column(qa_sql_checks(), null, 'id');
        $t->same([], qa_sql_run($checks['C60']), 'nothing wrong yet');
        $pay($target, ['E-A'], ['E-A' => '30000.00']);
        $t->same([], qa_sql_run($checks['C60']), 'paying exactly what is due is fine');
        $db->prepare("INSERT INTO bonus_deduction_history (entry_date, emp_id, emp_name, entry_type, amount, reason, period_id) VALUES (CURDATE(), 'E-A', 'Ana Pay', 'Bonus', 0.01, '13th Month Pay 2026', ?)")->execute([$target]);
        $rows = qa_sql_run($checks['C60']);
        $t->same(1, count($rows), 'one cent over is found');
        $t->same('E-A', $rows[0]['emp_id']);
        $r = qa_sql_run($checks['R03']);
        $row = array_values(array_filter($r, fn($x) => $x['emp_id'] === 'E-B'))[0];
        $t->money('20000.00', $row['thirteenth_month_due'], 'R03 reports Ben\'s 13th month');
    });

    /* ================================================================== E · Home page status (D-19) */

    T::test('the Home page calls a finalized pay period finalized (its status is "Locked", not "Finalized")', function (T $t) {
        Fixtures::reset();
        Fixtures::employee(['full_name' => 'Home Page', 'base_salary' => '500.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Home Page', '2026-04-01', '2026-04-15'));
        $open = Http::page('home.php')['body'];
        $t->contains('waiting to be reviewed and finalized', $open, 'while the period is open');
        $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid, 'ignore_drift' => true, 'allow_negative' => true]);
        $t->same('Locked', getDB()->query("SELECT status FROM payroll_periods WHERE id = $pid")->fetchColumn(), 'finalized: ' . json_encode($f['json'] ?? $f['body']));
        $done = Http::page('home.php')['body'];
        $t->contains('is finalized. Upload the next attendance file', $done, 'the one-line status');
        $t->notContains('waiting to be reviewed and finalized', $done);
        $t->ok((bool)preg_match('/badge-green">\s*Finalized\s*</', $done), 'the Period status badge is green and says Finalized');
    }, ['defect' => 'D-19']);

    /* ================================================================== F · a fresh install */

    T::test('a fresh install: sql/database.sql on an empty database, then the application\'s own first-load setup — sign in, add an employee, a period, a day, and it computes pay', function (T $t) {
        qa_need_fixes();
        $sqlFile = AppCopy::root() . '/sql/database.sql';
        $t->ok(is_file($sqlFile), 'sql/database.sql ships with the app');
        $root = TestDb::root();
        $root->exec('DROP DATABASE IF EXISTS qa_fresh');
        $root->exec('CREATE DATABASE qa_fresh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        foreach (['127.0.0.1', 'localhost', '%'] as $h) $root->exec("GRANT ALL ON qa_fresh.* TO '" . TestDb::USER . "'@'$h'");
        $fresh = TestDb::root('qa_fresh');
        $statements = array_filter(array_map('trim', explode(";\n", preg_replace('/^--.*$/m', '', (string)file_get_contents($sqlFile)))));
        foreach ($statements as $s) $fresh->exec($s);
        $base = $fresh->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($base);
        $t->same(['attendance', 'bonus_deduction_history', 'employees', 'payroll', 'payroll_periods', 'print_log', 'settings'], $base, 'the seven base tables');
        // running the file twice changes nothing
        foreach ($statements as $s) $fresh->exec($s);

        $env = ['env' => ['DB_NAME' => 'qa_fresh']];
        $r = Http::call('dashboard.php', ['method' => 'GET', 'session' => Http::admin()] + $env);          // first page load: applySchemaPatches()
        $t->same(200, $r['status'], 'the first page opens on an empty database: ' . substr(strip_tags($r['body']), 0, 160));
        $all = $fresh->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'leave_requests', 'biometric_daily', 'period_audit', 'payslip_signatures', 'employee_profiles', 'login_attempts'] as $tbl) {
            $t->ok(in_array($tbl, $all, true), "the application created $tbl by itself");
        }
        // the shipped file builds exactly the tables every other test runs on (the ERD export in tests/schema.sql, plus the app's own patches)
        $cols = function (PDO $db, string $schema): array {
            $st = $db->prepare("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, 'NULL') AS d, COLLATION_NAME
                                  FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('settings','employees','payroll_periods','attendance','payroll','bonus_deduction_history','print_log')
                                 ORDER BY TABLE_NAME, COLUMN_NAME");
            $st->execute([$schema]);
            return array_map(fn($r) => implode(' | ', $r), $st->fetchAll(PDO::FETCH_NUM));
        };
        $a = $cols($root, TestDb::DB);
        $b = $cols($root, 'qa_fresh');
        $t->ok(count($a) > 80, 'the comparison saw the columns (' . count($a) . ')');
        $t->same([], array_values(array_diff($a, $b)), 'columns the test database has and the fresh install lacks / differs in');
        $t->same([], array_values(array_diff($b, $a)), 'columns the fresh install has and the test database lacks / differs in');
        $call = fn(string $script, array $spec) => Http::call($script, $spec + $env);
        $r = $call('employee.php', ['method' => 'POST', 'post' => ['action' => 'add', 'full_name' => 'Fresh Install', 'base_salary' => '500', 'salary_type' => 'daily', 'branch' => 'MAIN'], 'session' => Http::admin()]);
        $emp = (string)$fresh->query("SELECT emp_id FROM employees WHERE full_name = 'Fresh Install'")->fetchColumn();
        $t->ok($emp !== '', 'the employee was saved in the fresh database');
        $r = $call('api/create-period.php', ['method' => 'POST', 'body' => json_encode(['label' => 'Apr 1-15, 2026', 'start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly']), 'session' => Http::admin()]);
        $pid = (int)($r['json']['id'] ?? 0);
        $t->ok($pid > 0, 'a period: ' . $r['body']);
        $r = $call('api/save-daily-attendance.php', ['method' => 'POST', 'session' => Http::admin(),
            'body' => json_encode(['period_id' => $pid, 'rows' => [Fixtures::day('Fresh Install', '2026-04-01', 8, 0, 0, 0)]])]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $gross = (string)$fresh->query("SELECT gross_pay FROM payroll WHERE period_id = $pid AND emp_id = '$emp'")->fetchColumn();
        $t->money('500.00', $gross, 'one day at ₱500 on a database built only from sql/database.sql');
        $root->exec('DROP DATABASE qa_fresh');
    });
});
