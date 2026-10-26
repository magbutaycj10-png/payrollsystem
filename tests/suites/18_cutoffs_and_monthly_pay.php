<?php
/*
 * 18 - One set of pay periods, monthly-paid employees, and an overtime rate per employee (2026-10-26).
 *
 *   A  the overtime rate on Employees > Add / Edit: blank = the company rate in Settings, 0 = no overtime pay, a number pays every
 *      overtime hour of that employee - under either overtime method; junk and negative numbers are refused
 *   B  there is ONE set of pay periods (the kinsenas cut-offs): a whole-month period is no longer created or offered
 *   C  a file with a half month or the whole month can be uploaded into either cut-off: every day goes to the cut-off that holds its date,
 *      the missing 16th-end cut-off is created, a finalized cut-off is left alone
 *   D  employees paid monthly are not in the 1st half: they wait until the last cut-off of the month holds days of theirs, and are then paid
 *      for the WHOLE month (absences, contributions and tax included) in that one line
 *   E  bonuses and deductions recorded against either half land in the monthly-paid employee's one pay line; a finalized last cut-off holds
 *      them back; the page's totals agree with the history
 *   F  a totals file, a manager's upload, the payslip's covered dates and the forecast calendar follow the same rules
 *   G  an older database that still holds a whole-month period keeps working (nobody is paid twice) and can be tidied up
 *
 * April 2026 has 30 days and four Sundays (5, 12, 19, 26): 13 working days in each half, 26 in the month.
 */

/** a manager account with the given scope: 'Global', or an employee_code to see only that employee */
function qa18_manager(string $scope = 'Global'): array
{
    $db = getDB();
    $db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES ('Mgr18', ?, 'x', 'Manager')")->execute(['mgr18-' . uniqid() . '@test']);
    $id = (int)$db->lastInsertId();
    if ($scope === 'Global') {
        $db->prepare("INSERT INTO user_scopes (user_id, scope_type) VALUES (?, 'Global')")->execute([$id]);
    } else {
        $db->prepare("INSERT INTO user_scopes (user_id, scope_type, employee_id) VALUES (?, 'Employee', (SELECT id FROM employees WHERE employee_code = ?))")->execute([$id, $scope]);
    }
    return Http::manager($id, 'Mgr18', '');
}

/** the two people most tests use: K is paid every cut-off (₱15,000 a kinsena), M once a month (₱26,000, SSS ₱1,200 on the "second", Pag-IBIG ₱200, tax ₱600) → returns [K, M] */
function qa18_people(): array
{
    $k = Fixtures::employee(['full_name' => 'Kin Senas', 'salary_type' => 'kinsenas', 'base_salary' => '15000.00']);
    $m = Fixtures::employee(['full_name' => 'Mon Thly', 'salary_type' => 'monthly', 'base_salary' => '26000.00', 'pay_schedule' => 'Monthly',
                             'sss_amount' => '1200.00', 'sss_timing' => 'second', 'pagibig_amount' => '200.00', 'tax_amount' => '600.00', 'tax_timing' => 'split']);
    return [$k, $m];
}

/** a period row by its start date */
function qa18_period(string $start): ?array
{
    $st = getDB()->prepare("SELECT * FROM payroll_periods WHERE start_date = ? ORDER BY id LIMIT 1");
    $st->execute([$start]);
    return $st->fetch() ?: null;
}

/** a person's full days from..to without the given dates (the days the file leaves out) */
function qa18_daysExcept(string $name, string $from, string $to, array $skip = []): array
{
    return array_values(array_filter(Fixtures::fullDays($name, $from, $to), fn($r) => !in_array($r['att_date'], $skip, true)));
}

/** the sentence the Leave Requests page answers a decision with */
function qa18_leaveMessage(array $r): string
{
    return preg_match('/Leave request (Approved|Rejected)\.[^<]*/', $r['body'], $m) ? $m[0] : '(no message)';
}

T::suite('18 · One set of cut-offs, monthly-paid employees, overtime rate per employee', function () {
    $lines  = fn(int $pid): array => array_keys(Fixtures::payroll($pid));
    $sorted = function (array $a): array { sort($a); return $a; };
    $both   = fn(string $from, string $to) => array_merge(Fixtures::fullDays('Kin Senas', $from, $to), Fixtures::fullDays('Mon Thly', $from, $to));

    /* ================================================================== A · overtime rate per employee */

    T::test('A - an employee\'s own overtime rate pays every overtime hour of theirs (blank = company rate, 0 = none), under both methods', function (T $t) use ($lines) {
        Fixtures::reset();
        $a = Fixtures::employee(['full_name' => 'Ot A', 'base_salary' => '480.00', 'overtime_rate' => '100.00']);
        $b = Fixtures::employee(['full_name' => 'Ot B', 'base_salary' => '480.00']);
        $c = Fixtures::employee(['full_name' => 'Ot C', 'base_salary' => '480.00', 'overtime_rate' => '0.00']);
        $p = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $day = fn(string $n) => Fixtures::day($n, '2026-04-06', 8, 2);
        $r = Fixtures::days($p, [$day('Ot A'), $day('Ot B'), $day('Ot C')]);
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $x = Fixtures::payroll($p);
        // flat method, company rate ₱45: A 2 h × ₱100, B 2 h × ₱45, C 2 h × ₱0 - and ₱480 for the day
        $t->moneyMap(['overtime_pay' => '200.00', 'gross_pay' => '680.00'], $x[$a], 'A: own rate ₱100');
        $t->moneyMap(['overtime_pay' => '90.00',  'gross_pay' => '570.00'], $x[$b], 'B: the company rate ₱45');
        $t->moneyMap(['overtime_pay' => '0.00',   'gross_pay' => '480.00'], $x[$c], 'C: own rate 0 = no overtime pay');
        $t->same(['Ot B', 'Ot C'], array_column(overtimeShortfalls(getDB()), 'full_name'), 'under the flat method the legal-minimum warning names those paid below ₱75 an hour (B: ₱45, C: ₱0)');

        // the company rate changes: only the employee without an own rate follows
        Fixtures::setting('overtime_rate', '50');
        $f = Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $p]);
        $t->same(true, $f['json']['success'] ?? null, $f['body']);
        $x = Fixtures::payroll($p);
        $t->same(['200.00', '100.00', '0.00'], [$x[$a]['overtime_pay'], $x[$b]['overtime_pay'], $x[$c]['overtime_pay']], 'A and C keep their own rates, B follows Settings');

        // Labor Code method: B gets ₱480 ÷ 8 × 1.25 = ₱75 an hour, but an own rate still wins
        Fixtures::setting('overtime_method', 'labor_code');
        Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $p]);
        $x = Fixtures::payroll($p);
        $t->same(['200.00', '150.00', '0.00'], [$x[$a]['overtime_pay'], $x[$b]['overtime_pay'], $x[$c]['overtime_pay']], 'Labor Code: B ₱150, A and C their own rates');
        $t->same(['Ot C'], array_column(overtimeShortfalls(getDB()), 'full_name'), 'and only an own rate below the legal minimum is warned about');
    });

    T::test('A2 - Employees > Add / Edit: the overtime rate is saved as typed, blank means the company rate, junk and negatives are refused, and an edit re-works the open payroll', function (T $t) use ($lines) {
        Fixtures::reset();
        $db  = getDB();
        $add = fn(string $name, string $rate) => Http::page('employee.php', ['action' => 'add', 'first_name' => $name, 'branch' => 'MAIN', 'salary_type' => 'daily', 'base_salary' => '480', 'overtime_rate' => $rate]);
        $get = fn(string $name) => $db->query('SELECT employee_code, overtime_rate FROM employees WHERE first_name = ' . $db->quote($name))->fetch();

        $add('Own55', '55.5');
        $t->same('55.50', $get('Own55')['overtime_rate'], 'a typed rate is kept to the centavo');
        $add('Blank', '');
        $t->same(null, $get('Blank')['overtime_rate'], 'blank = no own rate (NULL), not 0');
        $add('Zero', '0');
        $t->same('0.00', $get('Zero')['overtime_rate'], '0 is a rate: no overtime pay');
        $r = $add('Neg', '-5');
        $t->contains('cannot be negative', $r['body']);
        $t->same(false, $get('Neg'), 'a negative rate is not saved');
        $r = $add('Junk', 'abc');
        $t->contains('must be a number', $r['body']);
        $t->same(false, $get('Junk'));
        $r = $add('Huge', '999999999');
        $t->same(false, $get('Huge'), 'an absurd rate is not saved');

        // the form shows it
        $page = Http::page('employee.php')['body'];
        $t->contains('name="overtime_rate"', $page, 'the Add / Edit form has the field');
        $t->contains('company rate', $page);
        $t->contains('Company Overtime Rate', Http::page('settings.php')['body'], 'Settings calls its rate the company rate');

        // an edit changes the open payroll straight away; saving the form again with the same rate changes nothing
        $e = $get('Own55')['employee_code'];
        $p = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $name = $db->query("SELECT full_name FROM employees WHERE employee_code = " . $db->quote($e))->fetchColumn();
        Fixtures::days($p, [Fixtures::day($name, '2026-04-06', 8, 2)]);
        $t->same('111.00', Fixtures::payroll($p)[$e]['overtime_pay'], '2 h × ₱55.50');
        $r = qa_edit_employee($e, ['overtime_rate' => '70']);
        $t->same('140.00', Fixtures::payroll($p)[$e]['overtime_pay'], 'edited to ₱70: 2 h × ₱70, the open pay period recomputed');
        qa_edit_employee($e, ['overtime_rate' => '']);
        $t->same('90.00', Fixtures::payroll($p)[$e]['overtime_pay'], 'cleared: back to the company rate ₱45');
        $t->same(null, Fixtures::empRow($e)['overtime_rate']);
    });

    /* ================================================================== B · one set of pay periods */

    T::test('B - a whole-month pay period is no longer created or offered; the cut-offs are', function (T $t) {
        Fixtures::reset();
        $r = Http::api('create-period.php', ['label' => 'April 2026', 'start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']);
        $t->same(400, $r['status'], $r['body']);
        $t->contains('last cut-off', $r['json']['error'] ?? '', 'the refusal says where monthly employees are paid');
        $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM payroll_periods")->fetchColumn());
        $r = Http::api('create-period.php', ['label' => 'April 1–15, 2026', 'start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly']);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $r = Http::api('create-period.php', ['label' => 'No type', 'start' => '2026-04-16', 'end' => '2026-04-30']);
        $t->same('Semi-Monthly', $r['json']['type'] ?? null, 'whatever is asked, a new period is a cut-off');
        $t->same(['Semi-Monthly'], getDB()->query("SELECT DISTINCT period_type FROM payroll_periods")->fetchAll(PDO::FETCH_COLUMN));

        $page = Http::page('attendance-upload.php')['body'];
        $t->notContains('Monthly (whole month)', $page, 'the create form no longer offers a whole-month period');
        $t->notContains('schedule-switch"', $page, 'nor a Monthly / Semi-Monthly switch');
        $t->contains('1st half (1–15)', $page);
        $t->contains('2nd half (16–end)', $page);
        $t->contains('paid <b>once a month</b>', $page, 'the screen explains where monthly employees are paid');
        $t->same('October 16–31, 2026', cutoffLabel('2026-10-16', '2026-10-31'), 'the label of a cut-off');
        $t->same('February 1–15, 2028', cutoffLabel('2028-02-01', '2028-02-15'));
    });

    /* ================================================================== C + D · the month, uploaded from the 1st half */

    T::test('C/D - a whole-month file uploaded into the 1st half: the 2nd half is created, each day goes to its own cut-off, and the monthly-paid employee is paid for the whole month in the last one', function (T $t) use ($lines, $sorted, $both) {
        Fixtures::reset();
        [$k, $m] = qa18_people();
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $t->same(null, qa18_period('2026-04-16'), 'there is no 2nd half yet');

        $r = Fixtures::days($p1, $both('2026-04-01', '2026-04-30'));
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $p2row = qa18_period('2026-04-16');
        $t->ok($p2row !== null, 'the 2nd half was created for the days that reached it');
        $p2 = (int)$p2row['id'];
        $t->same(['April 16–30, 2026', 'Semi-Monthly', 'Open', '2026-04-30'], [$p2row['period_label'], $p2row['period_type'], $p2row['status'], $p2row['end_date']]);
        $t->same(['April 16–30, 2026'], $r['created_periods'] ?? null);
        $t->same(52, (int)getDB()->query("SELECT COUNT(*) FROM biometric_daily")->fetchColumn(), '26 days × 2 people saved - none twice, none lost');

        $t->same([$k], $lines($p1), 'the 1st half has the kinsenas employee only: the monthly-paid one is not paid in it');
        $t->same($sorted([$k, $m]), $sorted($lines($p2)), 'the 2nd half has both');
        $a = Fixtures::payroll($p1);
        $b = Fixtures::payroll($p2);
        $t->moneyMap(['gross_pay' => '15000.00', 'net_pay' => '15000.00'], $a[$k], 'K, 1st half');
        $t->moneyMap(['gross_pay' => '15000.00', 'net_pay' => '15000.00'], $b[$k], 'K, 2nd half');
        // M: 26 working days all worked → ₱26,000; SSS ₱1,200 + Pag-IBIG ₱200 + tax ₱600 taken in full on the one line; net ₱24,000
        $t->moneyMap(['basic_pay' => '26000.00', 'gross_pay' => '26000.00', 'sss' => '1200.00', 'philhealth' => '0.00', 'pagibig' => '200.00',
                      'withholding_tax' => '600.00', 'bonus' => '0.00', 'other_deductions' => '0.00', 'net_pay' => '24000.00'], $b[$m], 'M, the whole month in one line');
        $t->same('0.00', $b[$m]['absent_deduction']);
        $t->same(26.0, (float)$b[$m]['hours_worked'] / 8, 'the line holds the hours of all 26 working days (1st and 2nd half)');

        // the same file again changes nothing (days replace themselves; lines stay)
        $r = Fixtures::days($p1, $both('2026-04-01', '2026-04-30'));
        $t->same(true, $r['success'] ?? null);
        $t->same([], $r['created_periods'] ?? [], 'nothing new to create the second time');
        $b2 = Fixtures::payroll($p2);
        $t->same($b[$m]['id'], $b2[$m]['id'], 'the monthly line keeps its row');
        $t->moneyMap(['gross_pay' => '26000.00', 'net_pay' => '24000.00'], $b2[$m], 'and its pay');
        $t->same(52, (int)getDB()->query("SELECT COUNT(*) FROM biometric_daily")->fetchColumn());

        // the page says so
        $page = Http::page('payroll.php', [], ['period' => $p2])['body'];
        $t->contains('This is the last cut-off of April 2026', $page);
        $t->contains('>Monthly</span>', $page, 'the monthly line is marked');
        $t->contains('Cut-off · 2nd half', $page);
        $t->notContains('older whole-month pay period', $page);
        // audit queries: no day paid twice, no line on the wrong schedule
        $byId = array_column(qa_sql_checks(), null, 'id');
        $t->same([], qa_sql_run($byId['C22']), 'DBeaver C22');
        $t->same([], qa_sql_run($byId['C32']), 'DBeaver C32: a monthly-paid line in the last cut-off is on its schedule');
    });

    T::test('D - the monthly-paid employee waits until the last cut-off holds days of theirs; absences of the whole month are deducted in the one line', function (T $t) use ($lines, $sorted, $both) {
        Fixtures::reset();
        [$k, $m] = qa18_people();
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($p1, $both('2026-04-01', '2026-04-15'));
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $t->same(['Mon Thly'], $r['waiting'] ?? null, 'the upload names who is waiting');
        $t->contains('waiting', (string)($r['message'] ?? ''));
        $t->same([$k], $lines($p1));
        $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM payroll p JOIN employees e ON e.id = p.employee_id WHERE e.employee_code = " . getDB()->quote($m))->fetchColumn(), 'the monthly employee has no pay line anywhere yet');
        $w = monthlyWaiting(getDB(), qa18_period('2026-04-01'));
        $t->same([[$m, 13]], array_map(fn($x) => [$x['emp_id'], (int)$x['days']], $w), 'waiting, with their 13 days so far');
        $page = Http::page('payroll.php', [], ['period' => $p1])['body'];
        $t->contains('monthly-paid employee(s) are not in the payroll yet', $page);
        $t->contains('does not exist yet', $page, 'and says the 16th-end cut-off is not there');

        // the 2nd half arrives, with two working days (Apr 20 and 21) missing from the file
        $second = array_values(array_filter($both('2026-04-16', '2026-04-30'), fn($d) => !($d['emp_name'] === 'Mon Thly' && in_array($d['att_date'], ['2026-04-20', '2026-04-21'], true))));
        $r = Fixtures::days($p1, $second);                         // uploaded from the 1st half: still the month's second half
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $p2 = (int)qa18_period('2026-04-16')['id'];
        $t->same($sorted([$k, $m]), $sorted($lines($p2)));
        $t->same([], monthlyWaiting(getDB(), qa18_period('2026-04-01')), 'nobody waits any more');
        $x = Fixtures::payroll($p2)[$m];
        // 26 working days, 24 in the file → 2 unpaid days × ₱1,000; contributions and tax are the typed monthly amounts, in full
        $t->moneyMap(['basic_pay' => '24000.00', 'absent_deduction' => '2000.00', 'sss' => '1200.00', 'pagibig' => '200.00', 'withholding_tax' => '600.00', 'net_pay' => '22000.00'], $x, 'M: ₱26,000 − 2 × ₱1,000');
        $t->same(2.0, (float)$x['absent_days']);

        // the two missing days arrive later (days add up): the deduction goes away, the pay is the whole month again
        $r = Fixtures::days($p2, [Fixtures::day('Mon Thly', '2026-04-20', 8), Fixtures::day('Mon Thly', '2026-04-21', 8)]);
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $x = Fixtures::payroll($p2)[$m];
        $t->moneyMap(['basic_pay' => '26000.00', 'absent_deduction' => '0.00', 'net_pay' => '24000.00'], $x, 'M, complete');
    });

    T::test('C2 - a finalized cut-off is not changed by a later file; its days are reported, the open cut-off is brought up to date', function (T $t) use ($lines, $both) {
        Fixtures::reset();
        [$k, $m] = qa18_people();
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $p2 = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        Fixtures::days($p1, $both('2026-04-01', '2026-04-15'));
        Fixtures::days($p2, $both('2026-04-16', '2026-04-30'));
        $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $p1, 'ignore_drift' => true, 'allow_negative' => true]);
        $t->same(true, $f['json']['success'] ?? null, $f['body']);
        $strip = fn(array $rows) => array_map(fn($r) => array_diff_key($r, ['updated_at' => 1]), $rows);
        $before = json_encode($strip(Fixtures::payroll($p1)));

        // the whole month again, with every day of K changed to 9 hours (1 overtime hour) - the 1st half is locked
        $rows = [];
        foreach ($both('2026-04-01', '2026-04-30') as $d) { if ($d['emp_name'] === 'Kin Senas') $d['overtime_hours'] = 1; $rows[] = $d; }
        $r = Fixtures::days($p2, $rows);
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $t->same(26, $r['locked_days'] ?? null, 'K and M have 13 days each in the finalized 1st half: 26 records left alone');
        $t->contains('finalized', (string)($r['message'] ?? ''));
        $t->same($before, json_encode($strip(Fixtures::payroll($p1))), 'the finalized 1st half is exactly as it was');
        $t->same(1.0, (float)getDB()->query("SELECT overtime_hours FROM biometric_daily b JOIN employees e ON e.id = b.employee_id WHERE e.employee_code = " . getDB()->quote($k) . " AND att_date = '2026-04-20'")->fetchColumn(), 'a 2nd-half day was saved');
        $t->same(0.0, (float)getDB()->query("SELECT overtime_hours FROM biometric_daily b JOIN employees e ON e.id = b.employee_id WHERE e.employee_code = " . getDB()->quote($k) . " AND att_date = '2026-04-02'")->fetchColumn(), 'a 1st-half day was not');
        // 13 days × 1 h × ₱45 = ₱585 overtime for K in the 2nd half only (Apr 16–30, no Sundays)
        $t->moneyMap(['overtime_pay' => '585.00', 'gross_pay' => '15585.00'], Fixtures::payroll($p2)[$k], 'K, 2nd half');

        // a file that holds only the locked half is refused with a reason
        $r = Fixtures::days($p2, $both('2026-04-01', '2026-04-15'));
        $t->same(403, $r['status'], json_encode($r));
        $t->contains('finalized', (string)($r['error'] ?? ''));
        // days of another month do not belong to these cut-offs
        $r = Fixtures::days($p2, [Fixtures::day('Kin Senas', '2026-05-04', 8)]);
        $t->same(400, $r['status']);
        $t->contains('month of', (string)($r['error'] ?? ''));
    });

    /* ================================================================== E · bonuses and deductions of the month */

    T::test('E - bonuses and deductions recorded against either half land in the monthly-paid employee\'s one line; a finalized last cut-off holds them back', function (T $t) use ($lines, $both) {
        Fixtures::reset();
        [$k, $m] = qa18_people();
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($p1, $both('2026-04-01', '2026-04-15'));          // the 1st half only so far: M waits
        $adj = fn(int $pid, string $emp, string $type, string $amt, string $reason = 'Holiday Bonus') =>
            Http::page('adjustments.php', ['period_id' => $pid, 'emp_ids' => [$emp], 'entry_type' => $type, 'amount' => $amt, 'reason_select' => $reason])['body'];

        $page = Http::page('adjustments.php', [], ['period' => $p1])['body'];
        $t->contains('pay waits for the last cut-off', $page, 'the roster lists the monthly employee, marked as waiting');
        $t->contains('>Monthly</span>', $page);

        $body = $adj($p1, $m, 'Bonus', '500.00');
        $t->contains('paid monthly and the pay is not computed yet', $body, 'a bonus for someone with no pay line yet is kept, and says it waits');
        $t->same([['Bonus', '500.00', $p1]], array_map(fn($r) => [$r['type'], $r['amount'], (int)$r['period_id']], getDB()->query("SELECT type, amount, period_id FROM bonus_deductions")->fetchAll()), 'in the history, against the half it was recorded in');
        // a kinsenas employee's bonus works as before
        $adj($p1, $k, 'Bonus', '1000.00');
        $t->same('1000.00', Fixtures::payroll($p1)[$k]['bonus']);
        $t->same('16000.00', Fixtures::payroll($p1)[$k]['net_pay']);

        // the 2nd half is uploaded: the line is built with the bonus already in it
        Fixtures::days($p1, $both('2026-04-16', '2026-04-30'));
        $p2 = (int)qa18_period('2026-04-16')['id'];
        $x = Fixtures::payroll($p2)[$m];
        $t->moneyMap(['bonus' => '500.00', 'other_deductions' => '0.00', 'gross_pay' => '26000.00', 'net_pay' => '24500.00'], $x, 'M: ₱26,000 + ₱500 − (1,200 + 200 + 600)');

        // a deduction recorded in the 2nd half, a bonus recorded in the 1st half now that the line exists: both go to the one line
        $adj($p2, $m, 'Deduction', '300.00', 'Cash Advance');
        $x = Fixtures::payroll($p2)[$m];
        $t->moneyMap(['bonus' => '500.00', 'other_deductions' => '300.00', 'net_pay' => '24200.00'], $x, 'after the deduction');
        $body = $adj($p1, $m, 'Bonus', '100.00', 'Attendance Bonus');
        $t->notContains('paid monthly and the pay is not computed yet', $body, 'the line exists now: applied at once');
        $x = Fixtures::payroll($p2)[$m];
        $t->moneyMap(['bonus' => '600.00', 'other_deductions' => '300.00', 'net_pay' => '24300.00'], $x, 'after the bonus recorded against the 1st half');
        $t->same('0.00', Fixtures::payroll($p1)[$k]['other_deductions']);
        $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM payroll WHERE period_id = $p1 AND employee_id = (SELECT id FROM employees WHERE employee_code = " . getDB()->quote($m) . ")")->fetchColumn(), 'and nothing was put on the 1st half');

        // the lines survive a re-upload and a recompute (they are rebuilt, the month's entries come back)
        Fixtures::days($p2, Fixtures::fullDays('Mon Thly', '2026-04-16', '2026-04-30'));
        $t->moneyMap(['bonus' => '600.00', 'other_deductions' => '300.00', 'net_pay' => '24300.00'], Fixtures::payroll($p2)[$m], 'after a re-upload');
        Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $p1]);
        $t->moneyMap(['bonus' => '600.00', 'other_deductions' => '300.00', 'net_pay' => '24300.00'], Fixtures::payroll($p2)[$m], 'after a recompute from the 1st half');

        // Payroll Processing: the history agrees with the lines on both halves
        foreach ([$p1, $p2] as $pid) {
            $page = Http::page('payroll.php', [], ['period' => $pid])['body'];
            $t->notContains('was entered outside this page', $page, "period $pid: no false drift warning");
        }

        // the last cut-off is finalized: a bonus for the monthly employee, even against the open 1st half, is held back
        $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $p2, 'ignore_drift' => true, 'allow_negative' => true]);
        $t->same(true, $f['json']['success'] ?? null, $f['body']);
        $n = (int)getDB()->query("SELECT COUNT(*) FROM bonus_deductions")->fetchColumn();
        $body = $adj($p1, $m, 'Bonus', '50.00');
        $t->contains('finalized', $body);
        $t->contains('Nothing applied', $body);
        $t->same($n, (int)getDB()->query("SELECT COUNT(*) FROM bonus_deductions")->fetchColumn(), 'nothing was recorded');
        $t->moneyMap(['bonus' => '600.00', 'net_pay' => '24300.00'], Fixtures::payroll($p2)[$m], 'and the finalized line is untouched');
        // unlock, and it goes through (as a revision)
        Http::api('update-payroll.php', ['action' => 'unlock', 'period_id' => $p2]);
        $adj($p1, $m, 'Bonus', '50.00');
        $x = Fixtures::payroll($p2)[$m];
        $t->moneyMap(['bonus' => '650.00', 'net_pay' => '24350.00'], $x, 'unlocked: applied');
        $t->same('1', (string)$x['revised_after_finalize'], 'and flagged Revised');

        // a deduction that would take the net below zero is refused for an existing line, as for anybody
        $body = $adj($p2, $m, 'Deduction', '999999.00');
        $t->same('24350.00', Fixtures::payroll($p2)[$m]['net_pay'], 'net pay is unchanged by the refused deduction');
        $t->contains('negative', $body);
    });

    /* ================================================================== F · totals file, manager, payslip, forecast */

    T::test('F - a totals file into the last cut-off pays the monthly employee for the month; into the 1st half it reports them as waiting', function (T $t) use ($lines, $sorted) {
        Fixtures::reset();
        [$k, $m] = qa18_people();
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $p2 = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        $rows = [['emp_name' => 'Kin Senas', 'hours_worked' => 104, 'overtime_hours' => 0, 'late_hours' => 0],
                 ['emp_name' => 'Mon Thly',  'hours_worked' => 208, 'overtime_hours' => 0, 'late_hours' => 0]];
        $r = Http::api('save-attendance.php', ['period_id' => $p1, 'rows' => $rows]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $t->same([$k], $lines($p1), 'the 1st half takes only the kinsenas employee');
        $t->same(['other_schedule'], array_values(array_unique(array_column($r['json']['mismatches'] ?? [], 'type'))), 'and the monthly employee is named as not in it');
        $r = Http::api('save-attendance.php', ['period_id' => $p2, 'rows' => $rows]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $t->same($sorted([$k, $m]), $sorted($lines($p2)), 'the last cut-off takes both');
        $t->moneyMap(['gross_pay' => '26000.00', 'sss' => '1200.00', 'pagibig' => '200.00', 'withholding_tax' => '600.00', 'net_pay' => '24000.00'], Fixtures::payroll($p2)[$m], 'M: the whole month, contributions in full');
        $t->moneyMap(['gross_pay' => '15000.00'], Fixtures::payroll($p2)[$k], 'K: half a month as always');
    });

    T::test('F2 - a manager can upload the month into the 1st half; only the manager\'s own employees are saved', function (T $t) use ($lines, $sorted, $both) {
        Fixtures::reset();
        [$k, $m] = qa18_people();
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $mgr = qa18_manager($m);                                   // sees Mon Thly only
        $r = Fixtures::days($p1, $both('2026-04-01', '2026-04-30'), [], $mgr);
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $t->same(['Kin Senas'], $r['out_of_scope'] ?? null, 'Kin Senas is not theirs');
        $p2 = (int)qa18_period('2026-04-16')['id'];
        $t->same([], $lines($p1), 'no kinsenas line, and M is paid in the 2nd half');
        $t->same([$m], $lines($p2));
        $t->moneyMap(['gross_pay' => '26000.00', 'net_pay' => '24000.00'], Fixtures::payroll($p2)[$m]);
        $t->same(26, (int)getDB()->query("SELECT COUNT(*) FROM biometric_daily")->fetchColumn(), 'only M\'s 26 days were saved');
        // manual attendance of a day in the 1st half for the monthly employee: saved, and the month's line is recomputed
        $db = getDB();
        $db->prepare("DELETE FROM biometric_daily WHERE att_date = '2026-04-02'")->execute();
        Http::page('manager/manual-attendance.php', ['emp_id' => $m, 'att_date' => '2026-04-02', 'day_status' => 'worked', 'hours_worked' => '8', 'overtime_hours' => '0', 'late_hours' => '0'], [], $mgr);
        $t->same(26, (int)$db->query("SELECT COUNT(*) FROM biometric_daily")->fetchColumn(), 'the day is back');
        $t->same('26000.00', Fixtures::payroll($p2)[$m]['gross_pay'], 'and the month pays in full again');
    });

    T::test('F3 - the payslip of a monthly-paid employee covers the whole month; the forecast calendar follows the runs that happen', function (T $t) use ($both) {
        Fixtures::reset();
        [$k, $m] = qa18_people();
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($p1, $both('2026-04-01', '2026-04-30'));
        $p2 = (int)qa18_period('2026-04-16')['id'];
        $slip = fn(int $pid, string $e) => Http::page('print-doc.php', [], ['doc' => 'payslip', 'payroll_id' => (int)Fixtures::payroll($pid)[$e]['id'], 'auto' => 0])['body'];
        $t->contains('Apr 1 &ndash; Apr 30, 2026', $slip($p2, $m), 'M\'s payslip: the whole month');
        $t->contains('Apr 16 &ndash; Apr 30, 2026', $slip($p2, $k), 'K\'s payslip: the cut-off');
        $t->same(['2026-04-01', '2026-04-30'], payLineRange(['start_date' => '2026-04-16', 'end_date' => '2026-04-30', 'pay_schedule' => 'Monthly']));
        $t->same(['2026-04-16', '2026-04-30'], payLineRange(['start_date' => '2026-04-16', 'end_date' => '2026-04-30', 'pay_schedule' => 'Semi-Monthly']));
        $t->same(['2026-04-01', '2026-04-15'], payLineRange(['start_date' => '2026-04-01', 'end_date' => '2026-04-15', 'pay_schedule' => 'Monthly']), 'a 1st-half period is never widened');

        // forecast: a company whose employees are all paid monthly has one pay run a month, a mixed one two
        $mixed = [['period_type' => 'Semi-Monthly', 'start_date' => '2026-04-01'], ['period_type' => 'Semi-Monthly', 'start_date' => '2026-04-16']];
        $t->same(['period_type' => 'Semi-Monthly', 'periods_per_month' => 2], payrollCalendar($mixed));
        $t->same(['period_type' => 'Monthly', 'periods_per_month' => 1], payrollCalendar([['period_type' => 'Semi-Monthly', 'start_date' => '2026-04-16'], ['period_type' => 'Semi-Monthly', 'start_date' => '2026-05-16']]));
        $t->same(['period_type' => 'Semi-Monthly', 'periods_per_month' => 2], payrollCalendar([]), 'nothing yet: the usual');
        $j = Http::api('forecast-data.php', []);
        $t->same(2, $j['json']['periods_per_month'] ?? null, 'the forecast endpoint: this shop pays twice a month');
    });

    /* ================================================================== G · an older database */

    T::test('G - an older whole-month period keeps the monthly employee from being paid twice, and "Tidy up" folds it into the cut-offs', function (T $t) use ($lines, $sorted, $both) {
        Fixtures::reset();
        [$k, $m] = qa18_people();
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $p2 = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        $pm = Fixtures::legacyMonthlyPeriod('April 2026', '2026-04-01', '2026-04-30');
        $empty = Fixtures::legacyMonthlyPeriod('May 2026', '2026-05-01', '2026-05-31');
        Fixtures::days($p1, $both('2026-04-01', '2026-04-15'));
        Fixtures::days($p2, $both('2026-04-16', '2026-04-30'));
        $t->same([$m], $lines($pm), 'the older whole-month period pays the monthly employee');
        $t->same([$k], $lines($p2), 'the cut-off does not: nobody is paid twice');
        $t->moneyMap(['gross_pay' => '26000.00', 'net_pay' => '24000.00'], Fixtures::payroll($pm)[$m]);
        $page = Http::page('payroll.php', [], ['period' => $p2])['body'];
        $t->contains('older whole-month pay period(s) are still in the list', $page, 'Payroll Processing says the month shows twice');
        $t->contains('Whole month (older)', $page);

        // a bonus recorded against the older period stays with it; tidy up moves it to the last cut-off
        Http::page('adjustments.php', ['period_id' => $pm, 'emp_ids' => [$m], 'entry_type' => 'Bonus', 'amount' => '700.00', 'reason_select' => 'Holiday Bonus']);
        $t->same('700.00', Fixtures::payroll($pm)[$m]['bonus']);

        $r = Http::api('update-payroll.php', ['action' => 'tidy_monthly', 'period_id' => $p2]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $t->same(['May 2026'], $r['json']['result']['removed'] ?? null, 'the empty one is removed');
        $t->same(['April 2026'], $r['json']['result']['merged'] ?? null, 'the one with pay is folded in');
        $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM payroll_periods WHERE period_type = 'Monthly'")->fetchColumn(), 'no whole-month period is left');
        $t->same($sorted([$k, $m]), $sorted($lines($p2)), 'the monthly employee is now in the last cut-off');
        $t->moneyMap(['gross_pay' => '26000.00', 'bonus' => '700.00', 'net_pay' => '24700.00'], Fixtures::payroll($p2)[$m], 'with the month\'s pay and the bonus');
        $t->same(2, (int)getDB()->query("SELECT COUNT(*) FROM payroll_periods")->fetchColumn(), 'one pair of cut-offs, the month shows once');
        $t->notContains('older whole-month', Http::page('payroll.php', [], ['period' => $p2])['body']);

        // a finalized older period is kept, with the reason
        $pm2 = Fixtures::legacyMonthlyPeriod('June 2026', '2026-06-01', '2026-06-30');
        Fixtures::days($pm2, Fixtures::fullDays('Mon Thly', '2026-06-01', '2026-06-30'));
        Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pm2, 'ignore_drift' => true, 'allow_negative' => true]);
        $r = Http::api('update-payroll.php', ['action' => 'tidy_monthly', 'period_id' => $p2]);
        $t->same(['June 2026'], array_keys($r['json']['result']['kept'] ?? []), 'finalized: kept');
        $t->same(1, (int)getDB()->query("SELECT COUNT(*) FROM payroll_periods WHERE period_type = 'Monthly'")->fetchColumn());
    });

    T::test('H - a leave decided after the 1st half was finalized still changes the monthly-paid employee one line (in the open last cut-off); the message names the cut-off that is locked', function (T $t) {
        Fixtures::reset();
        $mona = Fixtures::employee(['full_name' => 'Mona Monthly', 'salary_type' => 'monthly', 'base_salary' => '26000.00', 'pay_schedule' => 'Monthly']);
        $kin  = Fixtures::employee(['full_name' => 'Kin Senas', 'salary_type' => 'monthly', 'base_salary' => '26000.00']);
        $p1 = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($p1, array_merge(qa18_daysExcept('Mona Monthly', '2026-04-01', '2026-04-30', ['2026-04-08', '2026-04-22']),
                                        qa18_daysExcept('Kin Senas', '2026-04-01', '2026-04-30', ['2026-04-08', '2026-04-22'])));
        $p2 = (int)qa18_period('2026-04-16')['id'];
        $t->money('2000.00', Fixtures::payroll($p2)[$mona]['absence_deduction'], 'Mona is absent on the 8th (1st half) and the 22nd: two days in her one line');
        $t->money('1000.00', Fixtures::payroll($p1)[$kin]['absence_deduction'], 'Kin is absent on the 8th');
        $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $p1]);
        $t->same(true, $f['json']['success'] ?? null, $f['body']);

        $lm = Fixtures::leave($mona, '2026-04-08', '2026-04-08', 'Pending');
        $lk = Fixtures::leave($kin, '2026-04-08', '2026-04-08', 'Pending');
        $msg = qa18_leaveMessage(Http::page('leave-requests.php', ['leave_id' => $lm, 'action' => 'approve', 'review_note' => 'ok']));
        $t->money('1000.00', Fixtures::payroll($p2)[$mona]['absence_deduction'], 'approved: only the 22nd is absent now. ' . $msg);
        $t->contains('Payroll updated for April 16', $msg, 'the message names the cut-off that was updated');
        $t->notContains('is finalized', $msg, 'and does not send the admin to unlock the 1st half, which holds no line of hers');
        $msg = qa18_leaveMessage(Http::page('leave-requests.php', ['leave_id' => $lk, 'action' => 'approve', 'review_note' => 'ok']));
        $t->money('1000.00', Fixtures::payroll($p1)[$kin]['absence_deduction'], 'the finalized 1st half of Kin is not changed. ' . $msg);
        $t->contains('Apr 1-15, 2026 is finalized', $msg, 'and Kin is told so');

        Http::page('leave-requests.php', ['leave_id' => $lm, 'action' => 'reject', 'review_note' => 'no']);
        $t->money('2000.00', Fixtures::payroll($p2)[$mona]['absence_deduction'], 'rejected: the 8th is absent again');

        $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $p2]);
        $t->same(true, $f['json']['success'] ?? null, $f['body']);
        $msg = qa18_leaveMessage(Http::page('leave-requests.php', ['leave_id' => $lm, 'action' => 'approve', 'review_note' => 'late']));
        $t->money('2000.00', Fixtures::payroll($p2)[$mona]['absence_deduction'], 'the last cut-off is finalized too: nothing changes. ' . $msg);
        $t->contains('April 16', $msg, 'and the message names that cut-off');
        $t->notContains('Apr 1-15, 2026 is finalized', $msg);
    });
});
