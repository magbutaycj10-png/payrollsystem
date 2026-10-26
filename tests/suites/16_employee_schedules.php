<?php
/*
 * 16 - The pay schedule and the contribution schedule belong to each employee (2026-10-24). They used to be two Settings, the same for
 * everybody; now Employees > Add / Edit holds them, because some employees are paid Monthly and some kinsenas (twice a month), and not all
 * of them have their SSS, PhilHealth, Pag-IBIG and withholding tax taken on the same cut-off.
 *
 *   A  a pay period takes the employees paid on its schedule: kinsenas and Monthly employees side by side, a Monthly pay period beside the
 *      two cut-offs of the same month, nobody paid twice
 *   B  changing an employee's pay schedule on the Employees page re-works the OPEN pay periods and leaves a finalized one alone
 *   C  the contribution schedule is per employee: the same typed SSS and tax come out on different cut-offs; a Monthly employee takes it all
 *   D  Employees > Add / Edit: the new fields, what junk and a greyed-out box do, the removed localhost login hint
 *   E  "See brackets": the tables are the law's (they agree with the reference calculators), the window is on the page - and, in a real
 *      browser, the form's schedule boxes and the marked brackets behave (tests/js/employee-page.test.js)
 *   F  Weekly is no longer offered; periods of the same schedule cannot overlap, those of different schedules can
 *   G  a totals file and a day-by-day file say who is paid on another schedule; a totals file neither counts nor replaces their days
 *   H  the first page load of an existing database gives every employee what the old Settings said - once
 */

/** a manager account that sees everybody, as a session */
function qa16_manager(): array
{
    $db = getDB();
    $db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES ('Mgr16', ?, 'x', 'Manager')")->execute(['mgr16-' . uniqid() . '@test']);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO user_scopes (user_id, scope_type) VALUES (?, 'Global')")->execute([$id]);
    return Http::manager($id, 'Mgr16', '');
}

T::suite('16 · Pay schedule and contribution schedule per employee', function () {
    $lines = fn(int $pid): array => array_keys(Fixtures::payroll($pid));
    /** same members, whatever the order (employee codes sort as text) */
    $sorted = function (array $a): array { sort($a); return $a; };

    /* ================================================================== A · a pay period takes the employees on its schedule */

    T::test('A - a pay period takes the employees paid on its schedule: kinsenas and Monthly employees side by side, a Monthly period beside the two cut-offs of the month, nobody paid twice', function (T $t) use ($lines, $sorted) {
        Fixtures::reset();
        $k = Fixtures::employee(['full_name' => 'Kin Senas', 'salary_type' => 'kinsenas', 'base_salary' => '15000.00', 'sss_amount' => '1000.00']);
        $m = Fixtures::employee(['full_name' => 'Mon Thly', 'salary_type' => 'monthly', 'base_salary' => '30000.00', 'pay_schedule' => 'Monthly', 'sss_amount' => '1500.00', 'sss_timing' => 'second']);
        $d = Fixtures::employee(['full_name' => 'Dai Ly', 'salary_type' => 'daily', 'base_salary' => '500.00']);
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $p2 = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        $pm = Fixtures::period('April 2026', '2026-04-01', '2026-04-30', 'Monthly');            // beside the cut-offs: a different schedule is no clash
        $everyone = fn(string $from, string $to) => array_merge(Fixtures::fullDays('Kin Senas', $from, $to), Fixtures::fullDays('Mon Thly', $from, $to), Fixtures::fullDays('Dai Ly', $from, $to));

        // the file of cut-off 1 holds all three people
        $r = Fixtures::days($p1, $everyone('2026-04-01', '2026-04-15'));
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $t->same([['name' => 'Mon Thly', 'schedule' => 'Monthly']], $r['other_schedule'] ?? null, 'the upload names who is paid on another schedule');
        $t->contains('paid on another schedule', (string)($r['message'] ?? ''), 'and says so');
        $t->same($sorted([$k, $d]), $sorted($lines($p1)), 'cut-off 1 takes the kinsenas and the daily-rate employee, not the Monthly one');
        $t->same([$m], $lines($pm), 'the Monthly pay period took the Monthly employee at once, from the days saved while cut-off 1 was uploaded');
        $t->same([], $lines($p2), 'cut-off 2 has no day yet');

        // a file of only this schedule's own employees leaves the Monthly period alone; one with the Monthly employee in it brings it up to date
        $db = getDB();
        $stamp = fn() => (string)$db->query("SELECT MIN(updated_at) FROM payroll WHERE period_id = $pm")->fetchColumn();
        $db->exec("UPDATE payroll SET updated_at = '2000-01-01 00:00:00' WHERE period_id = $pm");
        $own = array_merge(Fixtures::fullDays('Kin Senas', '2026-04-01', '2026-04-15'), Fixtures::fullDays('Dai Ly', '2026-04-01', '2026-04-15'));
        $r = Fixtures::days($p1, $own);
        $t->same([], $r['other_schedule'] ?? null, 'nobody in this file is paid on another schedule');
        $t->same('2000-01-01 00:00:00', $stamp(), 'so the Monthly period was not touched');
        Fixtures::days($p1, $everyone('2026-04-01', '2026-04-15'));
        $t->ok($stamp() > '2000-01-02', 'a file with the Monthly employee in it brings the Monthly period up to date');

        $r = Fixtures::days($p2, $everyone('2026-04-16', '2026-04-30'));
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $t->same($sorted([$k, $d]), $sorted($lines($p2)), 'cut-off 2: the same two');
        $t->same([$m], $lines($pm), 'the Monthly period still holds only the Monthly employee');

        // by hand: kinsenas ₱15,000 → ₱15,000 a cut-off; ₱500 a day × 13 working days = ₱6,500 (Sundays off); the Monthly ₱30,000 in full; SSS on the 1st cut-off, none on the 2nd
        $a = Fixtures::payroll($p1);
        $b = Fixtures::payroll($p2);
        $c = Fixtures::payroll($pm);
        $t->moneyMap(['gross_pay' => '15000.00', 'sss' => '1000.00'], $a[$k], 'Kin Senas, cut-off 1: SSS "first"');
        $t->moneyMap(['gross_pay' => '15000.00', 'sss' => '0.00'], $b[$k], 'Kin Senas, cut-off 2: SSS was taken on the 1st');
        $t->moneyMap(['gross_pay' => '6500.00'], $a[$d], 'Dai Ly, cut-off 1: 13 days × ₱500');
        $t->moneyMap(['gross_pay' => '6500.00'], $b[$d], 'Dai Ly, cut-off 2: 13 days × ₱500');
        $t->moneyMap(['gross_pay' => '30000.00', 'sss' => '1500.00', 'net_pay' => '28500.00'], $c[$m], 'Mon Thly: the whole month, SSS taken in full (a Monthly run takes everything, whatever the schedule box says)');

        // the cut-offs that settle against cut-off 1 are the other cut-offs - not the Monthly period
        $t->same([$p2], laterPeriodsInMonth(getDB(), $p1), 'later periods of the month: the same schedule only');
        $t->same([], laterPeriodsInMonth(getDB(), $pm));

        // the whole month's file into the Monthly period: the kinsenas people are named as paid elsewhere and their cut-offs are untouched
        $snap = fn() => json_encode(array_map(fn($pid) => array_map(fn($r) => array_diff_key($r, ['created_at' => 1, 'updated_at' => 1]), Fixtures::payroll($pid)), [$p1, $p2]));   // (a rewritten line gets a new timestamp)
        $before = $snap();
        $r = Fixtures::days($pm, $everyone('2026-04-01', '2026-04-30'));
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $t->same(['Dai Ly', 'Kin Senas'], $sorted(array_column($r['other_schedule'] ?? [], 'name')), 'the kinsenas employees are paid on another schedule than the Monthly period');
        $t->same([$m], $lines($pm), 'still only the Monthly employee in the Monthly period');
        $t->same($before, $snap(), 'the two cut-offs did not change (same days, same pay)');

        // the audit queries agree: nobody's day is counted in two pay periods
        $byId = array_column(qa_sql_checks(), null, 'id');
        $t->same([], qa_sql_run($byId['C22']), 'DBeaver C22: no day is inside two pay periods that both take the employee');
        $t->same([], qa_sql_run($byId['C23']), 'DBeaver C23: the Monthly run beside the cut-offs is not an overlap');
        $t->same([], qa_sql_run($byId['C32']), 'DBeaver C32: every pay line is on its employee\'s schedule');
        $t->same(3, (int)$db->query("SELECT COUNT(*) FROM payroll_periods")->fetchColumn());
    });

    /* ================================================================== B · changing an employee's pay schedule */

    T::test('B - changing an employee\'s pay schedule on the Employees page re-works the OPEN pay periods (out of the old schedule\'s, into the new one\'s) and leaves a finalized one alone', function (T $t) use ($lines) {
        Fixtures::reset();
        $e = Fixtures::employee(['full_name' => 'Switch Over', 'salary_type' => 'monthly', 'base_salary' => '26000.00', 'sss_amount' => '900.00']);
        $a = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $b = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        $m = Fixtures::period('April 2026', '2026-04-01', '2026-04-30', 'Monthly');
        Fixtures::days($a, Fixtures::fullDays('Switch Over', '2026-04-01', '2026-04-15'));
        Fixtures::days($b, Fixtures::fullDays('Switch Over', '2026-04-16', '2026-04-30'));
        $t->same([[$e], [$e], []], [$lines($a), $lines($b), $lines($m)], 'paid Semi-Monthly: in both cut-offs, not in the Monthly period');

        // cut-off 1 is finalized (its line is history)
        $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $a, 'ignore_drift' => true, 'allow_negative' => true]);
        $t->same(true, $f['json']['success'] ?? null, $f['body']);
        $history = Fixtures::payroll($a)[$e];

        $r = qa_edit_employee($e, ['pay_schedule' => 'Monthly']);
        $t->contains('Pay schedule is now Monthly', $r['body'], 'the page says what changed');
        $t->same('Monthly', Fixtures::empRow($e)['pay_schedule']);
        /* pay_schedule on a pay line is read live from the employee; every stored figure must be untouched */
        $t->same(array_diff_key($history, ['pay_schedule' => 1]), array_diff_key(Fixtures::payroll($a)[$e], ['pay_schedule' => 1]), 'the finalized cut-off 1 is exactly as it was');
        $t->same([], $lines($b), 'the open cut-off 2 no longer holds the employee');
        $t->same([$e], $lines($m), 'the open Monthly period now does, from the days already saved');
        $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM attendance_summary WHERE period_id = $b")->fetchColumn(), 'and its attendance summary row went with the pay line');

        // and back
        qa_edit_employee($e, ['pay_schedule' => 'Semi-Monthly']);
        $t->same([$e], $lines($b), 'back on Semi-Monthly: cut-off 2 holds the employee again');
        $t->same([], $lines($m), 'and the Monthly period does not');
        $t->same($history, Fixtures::payroll($a)[$e], 'cut-off 1 is still exactly as it was');

        // saving the form with the same schedule changes nothing and says nothing about it
        $r = qa_edit_employee($e, ['base_salary' => '26000.00']);
        $t->notContains('Pay schedule is now', $r['body']);
    });

    /* ================================================================== C · the contribution schedule is the employee's own */

    T::test('C - the same typed SSS and tax come out on different cut-offs for different employees; a Monthly employee takes it all in the one run', function (T $t) use ($lines) {
        Fixtures::reset();
        $mk = fn(string $name, array $x) => Fixtures::employee($x + ['full_name' => $name, 'salary_type' => 'kinsenas', 'base_salary' => '10000.00', 'sss_amount' => '1000.00', 'tax_amount' => '400.00']);
        $a = $mk('Sched A', ['sss_timing' => 'first',  'tax_timing' => 'split']);
        $b = $mk('Sched B', ['sss_timing' => 'second', 'tax_timing' => 'first']);
        $c = $mk('Sched C', ['sss_timing' => 'split',  'tax_timing' => 'second']);
        $o = $mk('Sched M', ['pay_schedule' => 'Monthly', 'salary_type' => 'monthly', 'base_salary' => '20000.00', 'sss_timing' => 'second', 'tax_timing' => 'split']);
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $p2 = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        $pm = Fixtures::period('April 2026', '2026-04-01', '2026-04-30', 'Monthly');
        $names = ['Sched A', 'Sched B', 'Sched C', 'Sched M'];
        $file = function (string $from, string $to) use ($names) { $rows = []; foreach ($names as $n) $rows = array_merge($rows, Fixtures::fullDays($n, $from, $to)); return $rows; };
        $t->same(true, Fixtures::days($p1, $file('2026-04-01', '2026-04-15'))['success'] ?? null);
        $t->same(true, Fixtures::days($p2, $file('2026-04-16', '2026-04-30'))['success'] ?? null);

        $x = Fixtures::payroll($p1);
        $y = Fixtures::payroll($p2);
        // cut-off 1: A SSS in full, tax half · B no SSS yet, tax in full ("first") · C SSS half, tax waits ("second")
        $t->moneyMap(['sss' => '1000.00', 'withholding_tax' => '200.00'], $x[$a], 'A, cut-off 1');
        $t->moneyMap(['sss' => '0.00',    'withholding_tax' => '400.00'], $x[$b], 'B, cut-off 1');
        $t->moneyMap(['sss' => '500.00',  'withholding_tax' => '0.00'],   $x[$c], 'C, cut-off 1');
        // cut-off 2 (the month's last run) settles whatever is still owed
        $t->moneyMap(['sss' => '0.00',    'withholding_tax' => '200.00'], $y[$a], 'A, cut-off 2');
        $t->moneyMap(['sss' => '1000.00', 'withholding_tax' => '0.00'],   $y[$b], 'B, cut-off 2');
        $t->moneyMap(['sss' => '500.00',  'withholding_tax' => '400.00'], $y[$c], 'C, cut-off 2');
        // the month ends at exactly what was typed
        foreach ([$a, $b, $c] as $e) {
            $t->same(['100000', '40000'], [(string)(Ledger::c($x[$e]['sss']) + Ledger::c($y[$e]['sss'])), (string)(Ledger::c($x[$e]['withholding_tax']) + Ledger::c($y[$e]['withholding_tax']))], "$e: SSS ₱1,000 and tax ₱400 over the month");
        }
        // the Monthly employee: one run, everything in it, whatever "second" and "split" say
        $z = Fixtures::payroll($pm);
        $t->same([$o], $lines($pm));
        $t->moneyMap(['sss' => '1000.00', 'withholding_tax' => '400.00'], $z[$o], 'the Monthly employee: the whole month at once');

        // the Payroll Processing page speaks for each employee's own schedule
        $page = Http::page('payroll.php', [], ['period' => $p1])['body'];
        $t->contains('follow each employee&#039;s own contribution schedule', $page, 'the line over the table');
        $t->contains('waits for the last cut-off', $page, 'B\'s SSS and C\'s tax wait');
        $t->contains('set on each employee', $page);
        $t->notContains('change in Settings', $page);
        $last = Http::page('payroll.php', [], ['period' => $p2])['body'];
        $t->contains('last cut-off of the month', $last);
        $monthly = Http::page('payroll.php', [], ['period' => $pm])['body'];
        $t->contains('taken in full', $monthly);
    });

    /* ================================================================== D · Employees > Add / Edit */

    T::test('D - Employees > Add / Edit saves the pay schedule and the four contribution schedules; junk and a greyed-out box keep what the employee had; the localhost login hint is gone', function (T $t) {
        Fixtures::reset();
        $db  = getDB();
        $add = fn(string $name, array $x) => Http::page('employee.php', $x + ['action' => 'add', 'first_name' => $name, 'branch' => 'MAIN', 'salary_type' => 'daily', 'base_salary' => '500']);
        $row = fn(string $name) => $db->query('SELECT *, employee_code AS emp_id FROM employees WHERE full_name = ' . $db->quote($name))->fetch();
        $own = fn(array $r) => [$r['pay_schedule'], $r['sss_timing'], $r['philhealth_timing'], $r['pagibig_timing'], $r['tax_timing']];
        $usual = ['Semi-Monthly', 'first', 'second', 'second', 'split'];

        $add('Plain Add', []);
        $t->same($usual, $own($row('Plain Add')), 'an employee added with none of the fields: Semi-Monthly and the usual schedule');
        $add('Monthly Add', ['pay_schedule' => 'Monthly', 'sss_timing' => 'split', 'philhealth_timing' => 'first', 'pagibig_timing' => 'split', 'tax_timing' => 'second']);
        $t->same(['Monthly', 'split', 'first', 'split', 'second'], $own($row('Monthly Add')), 'every choice is saved as chosen');
        $add('Junk Add', ['pay_schedule' => 'Weekly', 'sss_timing' => 'never', 'philhealth_timing' => '', 'pagibig_timing' => 'WEEKLY', 'tax_timing' => 'monthly']);
        $t->same($usual, $own($row('Junk Add')), 'Weekly and every other unknown choice fall back to the defaults (a Weekly pay schedule does not exist)');

        // editing
        $id = $row('Monthly Add')['emp_id'];
        qa_edit_employee($id, ['pay_schedule' => 'Semi-Monthly', 'tax_timing' => 'first']);
        $t->same(['Semi-Monthly', 'split', 'first', 'split', 'first'], $own(Fixtures::empRow($id)), 'edited: the pay schedule and one of the four');
        qa_edit_employee($id, ['pay_schedule' => 'Weekly', 'sss_timing' => 'bogus', 'pagibig_timing' => '']);
        $t->same(['Semi-Monthly', 'split', 'first', 'split', 'first'], $own(Fixtures::empRow($id)), 'junk in an edit keeps what the employee had');
        // a Monthly employee: the form greys the four boxes out, so the browser does not send them - they keep what they had
        qa_edit_employee($id, ['pay_schedule' => 'Monthly'], ['sss_timing', 'philhealth_timing', 'pagibig_timing', 'tax_timing']);
        $t->same(['Monthly', 'split', 'first', 'split', 'first'], $own(Fixtures::empRow($id)), 'greyed-out boxes are not sent: the schedules are kept for the day the employee is paid kinsenas again');

        // the page
        $page = Http::page('employee.php')['body'];
        foreach (['name="pay_schedule"', 'name="sss_timing"', 'name="philhealth_timing"', 'name="pagibig_timing"', 'name="tax_timing"', 'id="seeBrackets"', 'See brackets',
                  'Pay Schedule', 'Contribution Schedule', '>Semi-Monthly (kinsenas)</option>', '>Monthly (once a month)</option>',
                  '1st cut-off of the month, in full', 'Every cut-off, in equal shares', 'Last cut-off of the month, in full', 'id="f_timing_grid"'] as $needle) {
            $t->contains($needle, $page, "the form has: $needle");
        }
        foreach (['Employee logs in at', 'localhost:8765', 'Weekly', 'settings.php#contribSchedule'] as $gone) {
            $t->notContains($gone, $page, "the form no longer says: $gone");
        }
        $t->contains('Employee Portal Access', $page, 'the portal section is still there');
        $t->contains('name="portal_password"', $page);
        $t->same([1, 1], [preg_match_all('/<option value="Semi-Monthly">/', $page), preg_match_all('/<option value="Monthly">/', $page)], 'two pay schedules are offered, once each');
        // the table says how each employee is paid, and the Edit button carries the schedules to the form
        $t->contains('Paid monthly', $page);
        $t->contains('Paid semi-monthly', $page);
        $t->contains('"pay_schedule":"Monthly"', $page, 'the Edit button hands the employee\'s pay schedule to the form');
        $t->contains('"sss_timing":"split"', $page, 'and their contribution schedule');

        // a schedule change through the form recomputes the open payroll of that employee (covered in B); a change of one contribution schedule does too
        $e = Fixtures::employee(['full_name' => 'Timing Change', 'salary_type' => 'kinsenas', 'base_salary' => '10000.00', 'sss_amount' => '1000.00', 'sss_timing' => 'first']);
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $p2 = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        Fixtures::days($p1, Fixtures::fullDays('Timing Change', '2026-04-01', '2026-04-15'));
        Fixtures::days($p2, Fixtures::fullDays('Timing Change', '2026-04-16', '2026-04-30'));
        $t->moneyMap(['sss' => '1000.00'], Fixtures::payroll($p1)[$e], 'SSS "first": all on cut-off 1');
        $r = qa_edit_employee($e, ['sss_timing' => 'second']);
        $t->contains('Payroll recomputed for', $r['body'], 'a changed schedule re-works the open payroll');
        $t->moneyMap(['sss' => '0.00'], Fixtures::payroll($p1)[$e], 'now it waits for the last cut-off');
        $t->moneyMap(['sss' => '1000.00'], Fixtures::payroll($p2)[$e], 'and the last cut-off takes it');
    });

    /* ================================================================== E · See brackets */

    T::test('E - the brackets are the law\'s: contiguous from ₱0 up, and every row agrees with the reference calculators (SSS, PhilHealth, Pag-IBIG, the TRAIN tax tables)', function (T $t) {
        $tables = array_column(contributionBrackets(), null, 'key');
        $t->same(['sss', 'philhealth', 'pagibig', 'tax_semi', 'tax_month'], array_keys($tables), 'one table each; the tax has one for each pay schedule');
        $num = fn(string $s): float => (float)preg_replace('/[^0-9.]/', '', $s);
        // every table starts at 0, has no gap or overlap, ends open, and every row has one cell per column
        foreach ($tables as $key => $tb) {
            $rows = $tb['rows'];
            $t->same(0.0, (float)$rows[0]['lo'], "$key starts at ₱0");
            $t->same(null, $rows[count($rows) - 1]['hi'], "$key has no upper limit");
            foreach ($rows as $i => $r) {
                $t->same(count($tb['head']), count($r['cells']), "$key row $i has a cell for every column");
                if (isset($rows[$i + 1])) $t->eq($r['hi'], $rows[$i + 1]['lo'], "$key: row $i ends where row " . ($i + 1) . ' begins');
                $t->ok($r['hi'] === null || $r['hi'] > $r['lo'], "$key row $i is not empty");
            }
        }

        // SSS: 61 credits ₱5,000 … ₱35,000 in ₱500 steps
        $sss = $tables['sss']['rows'];
        $t->same('pay', $tables['sss']['basis']);
        $t->same(61, count($sss), 'sixty-one credits');
        $t->same(['Below ₱5,250.00', '₱5,000.00', '₱250.00', '₱500.00', '₱10.00', '₱760.00'], $sss[0]['cells'], 'the first credit');
        $t->same(['₱5,250.00 - ₱5,749.99', '₱5,500.00', '₱275.00', '₱550.00', '₱10.00', '₱835.00'], $sss[1]['cells'], 'the second');
        $t->same(['₱14,750.00 - ₱15,249.99', '₱15,000.00', '₱750.00', '₱1,500.00', '₱30.00', '₱2,280.00'], $sss[20]['cells'], 'the EC steps up to ₱30 at the ₱15,000 credit');
        $t->same(['₱34,750.00 and above', '₱35,000.00', '₱1,750.00', '₱3,500.00', '₱30.00', '₱5,280.00'], $sss[60]['cells'], 'the last');
        foreach ($sss as $i => $r) {
            $msc = $num($r['cells'][1]);
            $ee  = $num($r['cells'][2]);
            foreach ([$r['lo'], $r['hi'] === null ? $r['lo'] + 5000 : $r['hi'] - 0.01] as $pay) {      // both ends of the row
                $t->eq($msc, sssCredit($pay), "credit at ₱$pay");
                $t->eq($ee, sssMonthly($pay), "employee share at ₱$pay");
            }
            $t->eq(round($msc * 0.10, 2), $num($r['cells'][3]), "employer share of the ₱$msc credit");
            $t->eq(round($ee + $num($r['cells'][3]) + $num($r['cells'][4]), 2), $num($r['cells'][5]), "total of the ₱$msc credit");
            $t->eq($msc >= 15000 ? 30 : 10, $num($r['cells'][4]), "EC of the ₱$msc credit");
        }

        // PhilHealth: ₱250 at the floor, 2.5% in between, ₱2,500 at the ceiling
        $ph = $tables['philhealth']['rows'];
        $t->same(3, count($ph));
        $t->same(['₱10,000 and below', '₱500.00 (fixed)', '₱250.00', '₱250.00'], $ph[0]['cells']);
        $t->same(['Over ₱10,000 up to ₱100,000', '5% of the salary', '2.5% of the salary', '2.5% of the salary'], $ph[1]['cells']);
        $t->same(['₱100,000 and above', '₱5,000.00 (fixed)', '₱2,500.00', '₱2,500.00'], $ph[2]['cells']);
        $t->eq(250.0, philhealthMonthly(5000.0));
        $t->eq(1250.0, philhealthMonthly(50000.0));
        $t->eq(2500.0, philhealthMonthly(150000.0));

        // Pag-IBIG: 1% up to ₱1,500, 2% above, at most ₱200
        $pi = $tables['pagibig']['rows'];
        $t->same(3, count($pi));
        $t->same(['₱1,500 and below', '1%', '2%'], $pi[0]['cells']);
        $t->same(['Over ₱1,500 up to ₱10,000', '2%', '2%'], $pi[1]['cells']);
        $t->same(['Over ₱10,000', '₱200.00 (the most)', '₱200.00 (the most)'], $pi[2]['cells']);
        $t->eq(10.0, pagibigMonthly(1000.0));
        $t->eq(100.0, pagibigMonthly(5000.0));
        $t->eq(200.0, pagibigMonthly(15000.0));

        // withholding tax: 6 rows each (no tax, then five brackets); the tax of a pay inside a bracket = the bracket's tax + its rate on the excess
        foreach (['tax_semi' => 'semi', 'tax_month' => 'monthly'] as $key => $birTable) {
            $rows = $tables[$key]['rows'];
            $t->same(6, count($rows), "$key: no tax, then 15%, 20%, 25%, 30%, 35%");
            $t->same(['-', 'No tax', '-'], [$rows[0]['cells'][0], $rows[0]['cells'][2], $rows[0]['cells'][3]], "$key: the first row");
            $t->eq(0.0, birTax($rows[0]['hi'] - 1, $birTable), "$key: no tax below the first bracket");
            $t->same($key === 'tax_semi' ? 'taxable_cutoff' : 'taxable_month', $tables[$key]['basis']);
            foreach (array_slice($rows, 1) as $i => $r) {
                $over = $num($r['cells'][0]);
                $base = $num($r['cells'][2]);
                $rate = $num(explode('%', $r['cells'][3])[0]) / 100;
                $x = $over + 100.0;                                                                    // 100 pesos inside the bracket
                $t->eq(round($base + $rate * 100, 2), birTax($x, $birTable), "$key bracket over ₱$over: ₱$base + " . ($rate * 100) . '% of the excess');
                $t->eq($over + 0.005, $r['lo'], "$key bracket over ₱$over: the row starts just above the lower limit");
            }
            $t->same('no limit', $rows[5]['cells'][1], "$key: the top bracket has no limit");
        }
    });

    T::test('E - the Employees page carries the window: tabs, every bracket row, the marked-row data, and the note that payroll does not apply them', function (T $t) {
        Fixtures::reset();
        $page = Http::page('employee.php')['body'];
        $t->contains('id="bracketModal"', $page, 'the brackets window');
        foreach (['sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG', 'tax' => 'Withholding tax'] as $key => $title) {
            $t->contains("showBracketTab('$key')\">$title</button>", $page, "the $title tab");
            $t->contains('data-panel="' . $key . '"', $page, "and its panel");
        }
        $t->same(61 + 3 + 3 + 6 + 6, substr_count($page, '<tr data-lo='), 'every bracket row of every table is on the page (61 SSS + 3 PhilHealth + 3 Pag-IBIG + 6 + 6 tax)');
        foreach (['Below ₱5,250.00', '₱5,250.00 - ₱5,749.99', '₱34,750.00 and above', 'Over ₱10,000 up to ₱100,000', '₱200.00 (the most)', '15% of the excess over ₱20,833',
                  '35% of the excess over ₱666,667', '35% of the excess over ₱333,333', 'data-basis="taxable_cutoff"', 'data-basis="taxable_month"', 'data-basis="pay"'] as $needle) {
            $t->contains($needle, $page, "the window shows: $needle");
        }
        $t->contains('payroll does not', str_replace("\n", ' ', preg_replace('/\s+/', ' ', $page)), 'it says payroll does not work these out');
        $t->contains('openBrackets()', $page, 'the button opens it');
    });

    // ---- in a real browser: the page's own employee.js on the page employee.php rendered
    if (!Edge::find()) {
        T::test('JS · the Employees page in a browser', fn() => T::skip('no Microsoft Edge / Chrome found - set PAYROLL_TEST_EDGE to a browser path'));
    } else {
        try {
            Fixtures::reset();
            // two employees paid Semi-Monthly against one Monthly: a new employee starts Semi-Monthly
            Fixtures::employee(['full_name' => 'Browser Case', 'pay_schedule' => 'Monthly', 'base_salary' => '26000.00', 'salary_type' => 'monthly', 'sss_timing' => 'split']);
            Fixtures::employee(['full_name' => 'Browser Two', 'pay_schedule' => 'Semi-Monthly']);
            Fixtures::employee(['full_name' => 'Browser Three', 'pay_schedule' => 'Semi-Monthly']);
            $page = Http::page('employee.php');
            $html = Edge::wrap($page['body'], (string)file_get_contents(__DIR__ . '/../js/employee-page.test.js'), [],
                               ['assets/js/employee.js' => (string)file_get_contents(AppCopy::root() . '/assets/js/employee.js')]);
            $res = Edge::run($html, 60000);
            foreach ($res['tests'] as $jt) {
                T::test('JS · ' . $jt['name'], function (T $t) use ($jt) {
                    $t->checks++;
                    if (!$jt['ok']) throw new AssertionFailed($jt['msg']);
                });
            }
            if ($res['errors']) T::test('JS · the Employees page raised no uncaught error', function (T $t) use ($res) { $t->same([], $res['errors']); });
        } catch (Throwable $e) {
            T::test('JS · the Employees page in a browser', function () use ($e) { throw new AssertionFailed('could not run the page test: ' . $e->getMessage()); });
        }
    }

    /* ================================================================== F · Weekly is gone; overlaps are per schedule */

    T::test('F - Weekly is no longer offered; two periods cannot overlap; a separate Monthly period is refused; a missing type is Semi-Monthly', function (T $t) {
        Fixtures::reset();
        $make = fn(string $label, string $from, string $to, ?string $type) => Http::api('create-period.php', ['label' => $label, 'start' => $from, 'end' => $to] + ($type === null ? [] : ['type' => $type]));
        $db = getDB();
        $periods = fn() => (int)$db->query('SELECT COUNT(*) FROM payroll_periods')->fetchColumn();

        $r = $make('Weekly try', '2026-04-06', '2026-04-12', 'Weekly');
        $t->same(400, $r['status'], 'a Weekly period is refused: ' . $r['body']);
        $t->contains('no longer offered', (string)($r['json']['error'] ?? ''));
        $t->same(0, $periods(), 'and nothing was created');

        $a = $make('Apr 1-15, 2026', '2026-04-01', '2026-04-15', 'Semi-Monthly');
        $t->same(true, $a['json']['success'] ?? null, $a['body']);
        $t->same('Semi-Monthly', $a['json']['type'] ?? null);
        $same = $make('Apr 1-15 again', '2026-04-01', '2026-04-15', 'Semi-Monthly');
        $t->same(409, $same['status'], 'the same dates and schedule again');
        $t->same(true, $same['json']['clash']['same'] ?? null, $same['body']);
        $inside = $make('Apr 10-20', '2026-04-10', '2026-04-20', 'Semi-Monthly');
        $t->same(409, $inside['status'], 'overlapping dates, same schedule: ' . $inside['body']);
        $t->contains('These dates overlap Apr 1-15, 2026', (string)($inside['json']['error'] ?? ''));
        $m = $make('April 2026', '2026-04-01', '2026-04-30', 'Monthly');
        $t->same(400, $m['status'], 'a separate Monthly period is refused: monthly-paid staff are paid in the last cut-off: ' . $m['body']);
        $t->contains('last cut-off', (string)($m['json']['error'] ?? ''));
        $b = $make('Apr 16-30, 2026', '2026-04-16', '2026-04-30', 'Semi-Monthly');
        $t->same(true, $b['json']['success'] ?? null, 'the second cut-off fits beside the first: ' . $b['body']);
        $t->same(2, $periods());
        // an older database may still hold a whole-month Monthly period: it is no clash for a cut-off over the same days
        $db->exec("INSERT INTO payroll_periods (period_label, start_date, end_date, period_type, status) VALUES ('Legacy April', '2026-04-01', '2026-04-30', 'Monthly', 'Open')");
        $legacy = $make('Apr 1-15 beside legacy', '2026-04-01', '2026-04-15', 'Semi-Monthly');
        $t->same(409, $legacy['status'], 'the cut-off still clashes with the cut-off already there, not with the Monthly one: ' . $legacy['body']);
        $t->contains('Apr 1-15, 2026', (string)($legacy['json']['error'] ?? ''), 'and it names the cut-off, not the Monthly period');
        $db->exec("DELETE FROM payroll_periods WHERE period_label = 'Apr 1-15, 2026'");
        $beside = $make('Apr 1-15 beside legacy', '2026-04-01', '2026-04-15', 'Semi-Monthly');
        $t->same(true, $beside['json']['success'] ?? null, 'with the Monthly April present, a cut-off over the same days is no clash: ' . $beside['body']);
        $db->exec("DELETE FROM payroll_periods WHERE period_label IN ('Legacy April', 'Apr 1-15 beside legacy')");
        $t->same(1, $periods());

        // a missing or unknown type: there is only one kind of pay period, whatever the employees' schedules are
        $x = $make('May 1-15', '2026-05-01', '2026-05-15', null);
        $t->same('Semi-Monthly', $x['json']['type'] ?? null, 'no type: Semi-Monthly');
        Fixtures::employee(['full_name' => 'Vote A', 'pay_schedule' => 'Monthly']);
        Fixtures::employee(['full_name' => 'Vote B', 'pay_schedule' => 'Monthly']);
        Fixtures::employee(['full_name' => 'Vote C', 'pay_schedule' => 'Semi-Monthly']);
        $y = $make('June 1-15', '2026-06-01', '2026-06-15', null);
        $t->same('Semi-Monthly', $y['json']['type'] ?? null, 'even when most employees are Monthly');
        $z = $make('July 1-15', '2026-07-01', '2026-07-15', 'Fortnightly');
        $t->same('Semi-Monthly', $z['json']['type'] ?? null, 'and so is one with a type nobody knows');

        // the upload page
        $panel = Http::page('attendance-upload.php')['body'];
        $t->notContains('Weekly', $panel, 'the Upload Attendance page has no Weekly');
        $t->contains('<option value="Semi-Monthly|1" selected>', $panel, 'a new period starts as the first half');
        $t->notContains('Monthly|0', $panel, 'there is no separate whole-month option');

        $mgr = Http::page('manager/attendance-upload.php', [], [], qa16_manager())['body'];
        $t->notContains('Weekly', $mgr, 'nor has the manager\'s page');
    });

    /* ================================================================== G · totals file and day file say who is paid elsewhere */

    T::test('G - a totals file does not take the employees of another schedule, does not list them as missing, and neither counts nor replaces their saved days', function (T $t) use ($lines) {
        Fixtures::reset();
        $k = Fixtures::employee(['full_name' => 'Tot Kinsenas', 'salary_type' => 'daily', 'base_salary' => '500.00']);
        $m = Fixtures::employee(['full_name' => 'Tot Monthly', 'salary_type' => 'monthly', 'base_salary' => '26000.00', 'pay_schedule' => 'Monthly']);
        $p  = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $pm = Fixtures::period('April 2026', '2026-04-01', '2026-04-30', 'Monthly');
        $line = fn(string $n) => ['emp_name' => $n, 'hours_worked' => 104, 'overtime_hours' => 0, 'late_hours' => 0];

        $r = Http::api('save-attendance.php', ['period_id' => $p, 'rows' => [$line('Tot Kinsenas'), $line('Tot Monthly')]]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $t->same(1, $r['json']['count'] ?? null, 'one employee saved');
        $other = array_values(array_filter($r['json']['mismatches'] ?? [], fn($x) => $x['type'] === 'other_schedule'));
        $t->same(1, count($other), 'one line is reported as paid on another schedule: ' . json_encode($r['json']['mismatches'] ?? []));
        $t->same([$m, 'Tot Monthly', 'Monthly'], [$other[0]['emp_id'], $other[0]['name'], $other[0]['schedule']]);
        $t->contains('Paid Monthly', $other[0]['issue']);
        $t->same([$k], $lines($p), 'only the kinsenas employee has a line');

        // a file that leaves the Monthly employee out says nothing about them
        $r = Http::api('save-attendance.php', ['period_id' => $p, 'rows' => [$line('Tot Kinsenas')]]);
        $t->same([], array_values(array_filter($r['json']['mismatches'] ?? [], fn($x) => $x['type'] === 'emp_only')), 'the Monthly employee is not "missing from the file"');

        // saved days: the Monthly employee's days sit in the same dates; a totals file for the cut-off must neither count nor delete them
        $up = Fixtures::days($pm, Fixtures::fullDays('Tot Monthly', '2026-04-01', '2026-04-15'));
        $t->same(true, $up['success'] ?? null, json_encode($up));
        $mDays = fn() => (int)getDB()->query("SELECT COUNT(*) FROM biometric_daily WHERE employee_id = (SELECT id FROM employees WHERE employee_code = '$m')")->fetchColumn();
        $t->same(13, $mDays(), 'thirteen of the Monthly employee\'s days are saved');
        $r = Http::api('save-attendance.php', ['period_id' => $p, 'rows' => [$line('Tot Kinsenas')]]);
        $t->same(true, $r['json']['success'] ?? null, 'a totals file is not stopped by days that belong to another schedule: ' . $r['body']);
        Fixtures::days($p, Fixtures::fullDays('Tot Kinsenas', '2026-04-01', '2026-04-03'));            // now the kinsenas employee has three days of their own
        $r = Http::api('save-attendance.php', ['period_id' => $p, 'rows' => [$line('Tot Kinsenas')]]);
        $t->same(409, $r['status'], 'now it asks: ' . $r['body']);
        $t->same(['has_days', 3], [$r['json']['error'] ?? null, $r['json']['days'] ?? null], 'three days, not sixteen: only the employees this period takes are counted');
        $r = Http::api('save-attendance.php', ['period_id' => $p, 'rows' => [$line('Tot Kinsenas')], 'replace_days' => true]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $t->same(13, $mDays(), 'replacing the days of this cut-off left the Monthly employee\'s thirteen days alone');
        $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM biometric_daily WHERE employee_id = (SELECT id FROM employees WHERE employee_code = '$k')")->fetchColumn(), 'and removed the kinsenas employee\'s three');
    });

    T::test('G - the manager manual entry goes to the open cut-off that holds the day; a monthly-paid employee is paid in the last cut-off of the month', function (T $t) {
        Fixtures::reset();
        $m = Fixtures::employee(['full_name' => 'Man Ual', 'salary_type' => 'monthly', 'base_salary' => '26000.00', 'pay_schedule' => 'Monthly']);
        $k = Fixtures::employee(['full_name' => 'Kin Ual', 'salary_type' => 'daily', 'base_salary' => '500.00']);
        $first  = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $second = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        $mgr  = qa16_manager();
        $enter = fn(string $emp, string $date = '2026-04-08') => Http::page('manager/manual-attendance.php', ['emp_id' => $emp, 'att_date' => $date, 'day_status' => 'worked', 'hours_worked' => '8', 'overtime_hours' => '0', 'late_hours' => '0'], [], $mgr);
        $r = $enter($m);
        $t->contains('in Apr 1-15, 2026', $r['body'], 'the Monthly employee day goes to the cut-off that holds it: ' . substr(strip_tags($r['body']), 0, 200));
        $t->contains('is paid monthly', $r['body'], 'and the page says the pay is computed in the last cut-off');
        $r = $enter($k);
        $t->contains('in Apr 1-15, 2026', $r['body'], 'the kinsenas employee too');
        $t->same([$k], array_keys(Fixtures::payroll($first)), 'the 1st cut-off holds only the kinsenas employee line');
        $r = $enter($m, '2026-04-20');
        $t->contains('in Apr 16-30, 2026', $r['body'], 'a day in the 2nd half goes to the 2nd cut-off');
        $t->same(true, isset(Fixtures::payroll($second)[$m]), 'and the month pay line is there, in the last cut-off');
        // a day no open pay period holds
        $r = $enter($m, '2026-05-08');
        $t->contains('No open pay period covers', $r['body'], 'no period: ' . substr(strip_tags($r['body']), 0, 200));
    });

    /* ================================================================== H · the first load gives everybody what the old Settings said */

    T::test('H - the first page load of an existing database gives every employee the old Settings\' contribution schedule and the pay schedule they were last paid on - once', function (T $t) {
        qa_need_fixes();
        $root = TestDb::root();
        $name = 'qa_sched';
        $root->exec("DROP DATABASE IF EXISTS $name");
        $root->exec("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach (['127.0.0.1', 'localhost', '%'] as $h) $root->exec("GRANT ALL ON $name.* TO '" . TestDb::USER . "'@'$h'");
        $db = TestDb::root($name);
        foreach (preg_split('/^--;;\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/schema.sql')) as $stmt) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
            if ($stmt !== '') $db->exec($stmt);
        }
        // the layout of the day before: no pay schedule and no contribution schedule on employees
        $db->exec('ALTER TABLE employees DROP COLUMN pay_schedule, DROP COLUMN sss_timing, DROP COLUMN philhealth_timing, DROP COLUMN pagibig_timing, DROP COLUMN tax_timing');
        $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('payroll_period', 'Monthly'), ('contribution_timing_sss', 'split'), ('contribution_timing_tax', 'first')");
        $stamp = '2026-01-02 03:04:05';
        $db->exec("INSERT INTO employees (id, employee_code, first_name, updated_at) VALUES (1, 'S-1', 'Anna', '$stamp'), (2, 'S-2', 'Berta', '$stamp'), (3, 'S-3', 'Carla', '$stamp'),
                                                                                            (4, 'S-4', 'Dora', '$stamp'), (5, 'S-5', 'Elsa', '$stamp')");
        $db->exec("INSERT INTO payroll_periods (id, period_label, start_date, end_date, period_type, status) VALUES
            (1, 'Mar 1-15', '2026-03-01', '2026-03-15', 'Semi-Monthly', 'Locked'), (2, 'Mar 16-31', '2026-03-16', '2026-03-31', 'Semi-Monthly', 'Locked'),
            (3, 'March 2026', '2026-03-01', '2026-03-31', 'Monthly', 'Locked'), (4, 'Week', '2026-04-06', '2026-04-12', 'Weekly', 'Locked'),
            (5, 'Apr 1-15', '2026-04-01', '2026-04-15', 'Semi-Monthly', 'Open')");
        // 1: only cut-offs · 2: only the Monthly run · 3: the Monthly run, later a cut-off (the latest wins) · 4: never paid · 5: only a Weekly period
        $db->exec('INSERT INTO payroll (period_id, employee_id) VALUES (1, 1), (2, 1), (3, 2), (3, 3), (5, 3), (4, 5)');

        $env = ['env' => ['DB_NAME' => $name]];
        $r = Http::call('employee.php', ['method' => 'GET', 'session' => Http::admin()] + $env);
        $t->same(200, $r['status'], 'the first page opens: ' . substr(strip_tags($r['body']), 0, 200));
        $t->same([], $r['warnings'] ?? [], 'no PHP warnings');
        $read = fn() => $db->query('SELECT id, pay_schedule, sss_timing, philhealth_timing, pagibig_timing, tax_timing, updated_at FROM employees ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $rows = $read();
        // the contribution schedule: the two Settings that were set, the usual for the two that never were
        foreach ($rows as [$id, , $sss, $ph, $pi, $tax]) $t->same(['split', 'second', 'second', 'first'], [$sss, $ph, $pi, $tax], "employee $id: the old contribution schedule");
        // the pay schedule: how they were last paid; with no pay yet (4) or only a Weekly period (5): Settings' payroll_period, Monthly here
        $t->same([1 => 'Semi-Monthly', 2 => 'Monthly', 3 => 'Semi-Monthly', 4 => 'Monthly', 5 => 'Monthly'], array_column($rows, 1, 0), 'the pay schedule each employee was last paid on, else the old default');
        foreach ($rows as [$id, , , , , , $at]) $t->same($stamp, $at, "employee $id: updated_at was not touched");
        $t->same('Monthly', (string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'payroll_period'")->fetchColumn(), 'the old Settings rows are left alone');

        // once: the admin sets some employees, a later page load (the fingerprint forgotten, as after the next deploy) does not put the old values back
        $db->exec("UPDATE employees SET pay_schedule = 'Monthly', sss_timing = 'first', tax_timing = 'second' WHERE id = 1");
        $db->exec("DELETE FROM settings WHERE setting_key = 'schema_version'");
        $r = Http::call('employee.php', ['method' => 'GET', 'session' => Http::admin()] + $env);
        $t->same(200, $r['status']);
        $after = array_column($read(), null, 0);
        $t->same(['Monthly', 'first', 'second'], [$after[1][1], $after[1][2], $after[1][5]], 'the admin\'s own choices survive the next page load');
        $root->exec("DROP DATABASE $name");
    });
});
