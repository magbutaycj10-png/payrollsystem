<?php
/*
 * 09 - Controls: what a payroll system must refuse, flag or escape - and where it falls short of the Labor Code.
 *
 *   input sanity     implausible hours, negative rates and salaries
 *   compliance       overtime premium (Labor Code Art. 87), what the forecast measures (labor cost, not take-home)
 *   access           every endpoint refuses a visitor who is not signed in
 *   safety           a hostile name cannot inject SQL or script; two people with one name are never mixed up
 */
require_once AppCopy::root() . '/includes/bir-print.php';

T::suite('09 · Controls & compliance', function () {

    /* ================================================================== input sanity */

    T::test('a single day of 80 hours / 30 overtime hours is refused or flagged, not paid (₱1,350 of overtime from one typo)', function (T $t) {
        Fixtures::reset();
        $emp = Fixtures::employee(['full_name' => 'Typo Case', 'base_salary' => '480.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($pid, [Fixtures::day('Typo Case', '2026-04-01', 80, 30, 0, 0)]);
        $row = Fixtures::payroll($pid)[$emp] ?? null;
        $paid = $row ? (float)$row['gross_pay'] : 0.0;
        $t->ok($r['status'] >= 400 || !empty($r['warnings_data'] ?? null) || $paid <= 480.0 + 12 * 45,
            sprintf('upload accepted (HTTP %d) and paid ₱%.2f for one day: 8 normal hours plus %s overtime hours', $r['status'], $paid, '30'));
    }, ['defect' => 'D-16']);

    T::test('a device report with a day of 80 hours does not turn into 72 hours of overtime', function (T $t) {
        Fixtures::reset();
        $emp = Fixtures::employee(['full_name' => 'Device Typo', 'base_salary' => '480.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($pid, [Fixtures::day('Device Typo', '2026-04-01', 80, 0, 0, 0)], ['auto_ot' => true]);
        $row = Fixtures::payroll($pid)[$emp] ?? null;
        $ot = $row ? (float)$row['overtime_hours'] : 0.0;
        $t->ok($r['status'] >= 400 || $ot <= 12, "payroll line carries $ot overtime hours for a single day");
    }, ['defect' => 'D-16']);

    T::test('Settings refuse a negative or non-numeric overtime rate and late rate', function (T $t) {
        Fixtures::reset();
        Http::page('settings.php', ['overtime_rate' => '-45', 'late_rate' => 'abc']);
        $ot = (string)getDB()->query("SELECT setting_value FROM settings WHERE setting_key = 'overtime_rate'")->fetchColumn();
        $late = (string)getDB()->query("SELECT setting_value FROM settings WHERE setting_key = 'late_rate'")->fetchColumn();
        $t->ok($ot === '45' && $late === '80', "overtime_rate is now \"$ot\" and late_rate \"$late\" - a negative rate would make overtime reduce pay");
    }, ['defect' => 'D-16']);

    T::test('Employee Management refuses a negative base salary', function (T $t) {
        Fixtures::reset();
        Http::page('employee.php', ['action' => 'add', 'full_name' => 'Negative Pay', 'base_salary' => '-500', 'salary_type' => 'daily', 'branch' => 'MAIN']);
        $n = (int)getDB()->query("SELECT COUNT(*) FROM employees WHERE full_name = 'Negative Pay' AND base_salary < 0")->fetchColumn();
        $t->same(0, $n, 'an employee with a negative rate was saved');
    }, ['defect' => 'D-16']);

    T::test('a deduction larger than the employee\'s net pay is refused or warned, not recorded (net pay −₱45,000)', function (T $t) {
        Fixtures::reset();
        $emp = Fixtures::employee(['full_name' => 'Overdrawn', 'base_salary' => '500.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Overdrawn', '2026-04-01', '2026-04-15'));
        Http::page('adjustments.php', ['period_id' => $pid, 'emp_ids' => [$emp], 'entry_type' => 'Deduction', 'amount' => '50000', 'reason_select' => 'Cash advance']);
        $net = (float)Fixtures::payroll($pid)[$emp]['net_pay'];
        $t->ok($net >= 0, "net pay is ₱$net after the deduction");
    }, ['defect' => 'D-10']);

    T::test('Settings\' payroll period and contribution timing fall back safely on junk values', function (T $t) {
        Fixtures::reset();
        Fixtures::setting('payroll_period', 'Fortnightly');
        $t->same('Monthly', periodType(null), 'an unknown schedule means Monthly');
        $t->eq(1.0, periodFraction(null));
        Fixtures::setting('payroll_period', 'Semi-Monthly');
    });

    /* ================================================================== compliance */

    T::test('overtime pays at least 125% of the hourly rate (Labor Code Art. 87): ₱480 a day → ₱75 an hour, ₱620 a day → ₱96.88', function (T $t) {
        Fixtures::reset();
        // the audit-fixed Settings offer the Labor Code method (the default stays the pharmacy's flat rate: choosing is the owner's call)
        Fixtures::setting('overtime_method', 'labor_code');
        Fixtures::setting('overtime_multiplier', '1.25');
        $a = Fixtures::employee(['full_name' => 'OT Four Eighty', 'base_salary' => '480.00']);
        $b = Fixtures::employee(['full_name' => 'OT Six Twenty', 'base_salary' => '620.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, [Fixtures::day('OT Four Eighty', '2026-04-01', 8, 1, 0, 0), Fixtures::day('OT Six Twenty', '2026-04-01', 8, 1, 0, 0)]);
        $rows = Fixtures::payroll($pid);
        $t->money('75.00', $rows[$a]['ot_late_adj'], '₱480 ÷ 8 × 125% for one overtime hour');
        $t->money('96.88', $rows[$b]['ot_late_adj'], '₱620 ÷ 8 × 125% for one overtime hour');
    }, ['defect' => 'D-14']);

    T::test('the forecast can budget the company\'s LABOR COST (gross + employer SSS, EC, PhilHealth, Pag-IBIG), not only employees\' take-home', function (T $t) {
        Fixtures::reset();
        $emp = Fixtures::employee(['full_name' => 'Cost Person', 'base_salary' => '480.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        Fixtures::days($pid, Fixtures::fullDays('Cost Person', '2026-04-01', '2026-04-15'));
        $res = Http::call('api/forecast-data.php', ['method' => 'GET', 'session' => Http::admin()]);
        $row = $res['json']['data'][0] ?? [];
        $t->ok(isset($row['total_labor_cost']) || isset($row['total_employer_share']),
            'forecast-data.php offers: ' . implode(', ', array_keys($row)) . ' - the models predict "total_net", which excludes the employer\'s contributions and any bonus deducted from it');
    }, ['defect' => 'D-06']);

    T::test('correcting cut-off 1 after cut-off 2 was finalized still leaves the month tax and contributions exact', function (T $t) {
        // ₱1,200 a day. Both cut-offs are finalized; then cut-off 1 is unlocked and corrected with ₱1,980 more overtime.
        // The app re-settles only OPEN later cut-offs, so the locked cut-off 2 still settles the month against the OLD cut-off 1.
        Fixtures::reset();
        $emp = Fixtures::employee(['full_name' => 'Stale Month', 'base_salary' => '1200.00']);
        $a = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $b = Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30');
        $rowsA = Fixtures::fullDays('Stale Month', '2026-04-01', '2026-04-15');
        Fixtures::days($a, $rowsA);
        Fixtures::days($b, Fixtures::fullDays('Stale Month', '2026-04-16', '2026-04-30'));
        $f1 = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $a]);
        $f2 = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $b]);
        $t->same(true, $f1['json']['success'] ?? null, 'finalize cut-off 1: ' . json_encode($f1['json'] ?? $f1['body']));
        $t->same(true, $f2['json']['success'] ?? null, 'finalize cut-off 2: ' . json_encode($f2['json'] ?? $f2['body']));
        Http::api('update-payroll.php', ['action' => 'unlock', 'period_id' => $a]);
        foreach (range(0, 10) as $i) $rowsA[$i]['overtime_hours'] = 4;           // 11 days × 4 h = 44 h of overtime (₱1,980)
        $r = Fixtures::days($a, $rowsA);
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $f3 = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $a]);
        $t->same(true, $f3['json']['success'] ?? null, 're-finalize cut-off 1: ' . json_encode($f3['json'] ?? $f3['body']));

        // The locked cut-off 2 was settled against the OLD cut-off 1 and nobody changes a locked period behind the admin's back.
        // The audit-fixed app does not leave that silent: it says so on the period's page, and unlock → Recompute → finalize repairs it.
        if (function_exists('settlementDrift')) {
            $t->ok(settlementDrift(getDB(), $b) !== [], 'the app can tell that cut-off 2 no longer settles the month');
            $t->contains('no longer settle the month correctly', Http::page('payroll.php', [], ['period' => $b])['body'], 'the payroll page of cut-off 2 says so');
            Http::api('update-payroll.php', ['action' => 'unlock', 'period_id' => $b]);
            $rc = Http::api('update-payroll.php', ['action' => 'recompute', 'period_id' => $b]);
            $t->same(true, $rc['json']['success'] ?? null, 'recompute cut-off 2: ' . json_encode($rc['json'] ?? $rc['body']));
            $f4 = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $b]);
            $t->same(true, $f4['json']['success'] ?? null, 're-finalize cut-off 2: ' . json_encode($f4['json'] ?? $f4['body']));
            $t->same([], settlementDrift(getDB(), $b), 'nothing is stale any more');
        }

        $pa = Fixtures::payroll($a)[$emp];
        $pb = Fixtures::payroll($b)[$emp];
        $comp = Ledger::c($pa['gross_pay']) + Ledger::c($pb['gross_pay']);
        $basic = $comp - Ledger::c($pa['ot_late_adj']) - Ledger::c($pb['ot_late_adj']);
        $sss = Ledger::c($pa['sss']) + Ledger::c($pb['sss']);
        $ph = Ledger::c($pa['philhealth']) + Ledger::c($pb['philhealth']);
        $pi = Ledger::c($pa['pagibig']) + Ledger::c($pb['pagibig']);
        $tax = Ledger::c($pa['withholding_tax']) + Ledger::c($pb['withholding_tax']);
        $monthTaxable = $comp - $sss - $ph - $pi;
        $off = [];
        if ($sss !== Ledger::sssEe($comp)) $off[] = 'SSS ₱' . Ledger::fmt($sss) . ' vs ₱' . Ledger::fmt(Ledger::sssEe($comp));
        if ($ph !== Ledger::philhealthEe($basic)) $off[] = 'PhilHealth ₱' . Ledger::fmt($ph) . ' vs ₱' . Ledger::fmt(Ledger::philhealthEe($basic));
        if ($pi !== Ledger::pagibigEe($basic)) $off[] = 'Pag-IBIG ₱' . Ledger::fmt($pi) . ' vs ₱' . Ledger::fmt(Ledger::pagibigEe($basic));
        if ($tax !== Ledger::tax('monthly', $monthTaxable)) $off[] = 'tax ₱' . Ledger::fmt($tax) . ' vs ₱' . Ledger::fmt(Ledger::tax('monthly', $monthTaxable)) . ' on a month taxable of ₱' . Ledger::fmt($monthTaxable);
        $t->same([], $off, 'month totals after the correction (app vs what the month\'s pay requires)');
    }, ['defect' => 'D-18']);

    /* ================================================================== access control */

    T::test('every payroll endpoint answers 401 to a visitor who is not signed in', function (T $t) {
        foreach (['save-daily-attendance.php', 'save-attendance.php', 'create-period.php', 'update-payroll.php', 'forecast-data.php', 'period-detail.php', 'rollup-punches.php', 'log-print.php'] as $e) {
            $r = Http::call('api/' . $e, ['method' => 'POST', 'body' => '{}', 'session' => []]);
            $t->same(401, $r['status'], "api/$e without a session");
        }
    });

    T::test('admin pages send a visitor without a session to the sign-in page', function (T $t) {
        foreach (['dashboard.php', 'payroll.php', 'adjustments.php', 'reports.php', 'settings.php', 'employee.php', 'print-doc.php', 'forecast.php'] as $p) {
            $r = Http::call($p, ['method' => 'GET', 'session' => []]);
            $t->same(302, $r['status'], "$p without a session is redirected (to the sign-in page)");
            $t->same('', trim($r['body']), "$p shows nothing to a visitor without a session");
        }
    });

    T::test('an idle session (8 hours) is signed out', function (T $t) {
        $s = Http::admin();
        $s['last_seen'] = time() - 9 * 3600;
        $r = Http::call('dashboard.php', ['method' => 'GET', 'session' => $s]);
        $t->same(302, $r['status'], 'redirected to sign in');
        $s['last_seen'] = time() - 3600;
        $t->same(200, Http::call('dashboard.php', ['method' => 'GET', 'session' => $s])['status'], 'a session idle for one hour is still fine');
    });

    /* ================================================================== safety */

    T::test('a hostile employee name cannot inject script into a payslip or SQL into an upload', function (T $t) {
        Fixtures::reset();
        $name = '<script>alert(1)</script> O\'Brien "Q"';
        $emp = Fixtures::employee(['full_name' => $name, 'base_salary' => '480.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($pid, array_merge(Fixtures::fullDays($name, '2026-04-01', '2026-04-04'), [Fixtures::day("x'; DROP TABLE payroll; --", '2026-04-03', 8)]));
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $t->same(1, (int)getDB()->query("SELECT COUNT(*) FROM payroll")->fetchColumn(), 'the payroll table is intact and has one line');
        $row = Fixtures::payroll($pid)[$emp];
        $html = Http::page('print-doc.php', [], ['doc' => 'payslip', 'payroll_id' => $row['id'], 'auto' => '0'])['body'];
        $t->notContains('<script>alert(1)</script>', $html, 'raw script tag in the payslip');
        $t->contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'the name is shown escaped');
    });

    T::test('two employees with the same name: the upload refuses to guess (rows reported, nobody is paid someone else\'s days)', function (T $t) {
        Fixtures::reset();
        $a = Fixtures::employee(['full_name' => 'JUAN DELA CRUZ', 'base_salary' => '500.00']);
        $b = Fixtures::employee(['full_name' => 'Dela Cruz, Juan', 'base_salary' => '600.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($pid, [Fixtures::day('Juan Dela Cruz', '2026-04-01', 8)]);
        $t->same(400, $r['status'], 'ambiguous name: ' . json_encode($r));
        $t->same(0, (int)getDB()->query('SELECT COUNT(*) FROM payroll')->fetchColumn(), 'no payroll line was created for either of them');
    });

    T::test('names are matched ignoring case, punctuation and word order ("DELA CRUZ, Juan" = "Juan Dela Cruz")', function (T $t) {
        $t->same(nameKey('DELA CRUZ, Juan'), nameKey('Juan Dela Cruz'));
        $t->same(nameKey("O'Brien, Pat"), nameKey('pat o brien'));
        $t->ok(nameKey('Ana Reyes') !== nameKey('Ana Reyes Jr'), 'a suffix makes it a different person');
    });

    T::test('finalizing a period with no payroll lines is refused', function (T $t) {
        Fixtures::reset();
        $pid = Fixtures::period('Empty', '2026-04-01', '2026-04-15');
        $r = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid]);
        $t->same(400, $r['status']);
    });
});
