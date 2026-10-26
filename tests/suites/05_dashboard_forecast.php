<?php
/*
 * 05 - The dashboard: its "predicted next payroll" (linear regression), the figures it shows and the
 * pipeline it draws. This is a different calculation from forecast.php's models (see suite 07).
 */

/** history: one payroll line per period, net = the given amount */
function qa_history(array $series, int $startMonth = 1): array
{
    $db = getDB();
    $ids = [];
    $emp = Fixtures::employee(['full_name' => 'History Person', 'base_salary' => '500.00']);
    $halves = [[1, 15], [16, null]];
    $i = 0;
    foreach ($series as $net) {
        $m = $startMonth + intdiv($i, 2);
        $y = 2025 + intdiv($m - 1, 12);
        $mm = (($m - 1) % 12) + 1;
        $first = sprintf('%d-%02d-01', $y, $mm);
        [$a, $b] = $halves[$i % 2];
        $start = sprintf('%d-%02d-%02d', $y, $mm, $a);
        $end   = $b ? sprintf('%d-%02d-%02d', $y, $mm, $b) : date('Y-m-t', strtotime($first));
        $label = date('M j', strtotime($start)) . '-' . date('j', strtotime($end)) . ', ' . $y;
        $db->prepare("INSERT INTO payroll_periods (period_label, start_date, end_date, period_type, status) VALUES (?,?,?,?, 'Open')")
           ->execute([$label, $start, $end, 'Semi-Monthly']);
        $pid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO payroll (period_id, employee_id, gross_pay, net_pay)
                      VALUES (?, (SELECT id FROM employees WHERE employee_code = ?), ?, ?)")
           ->execute([$pid, $emp, $net, $net]);
        $ids[] = $pid;
        $i++;
    }
    return $ids;
}

T::suite('05 · Dashboard forecast & status', function () {

    T::test('regression: a perfectly linear series is continued exactly (5 periods: 10,000 … 14,000 → 15,000)', function (T $t) {
        Fixtures::reset();
        qa_history([10000, 11000, 12000, 13000, 14000]);
        $d = qa_dashboard_data(Http::page('dashboard.php')['body']);
        $t->same(5, $d['n']);
        $t->eq(15000.0, $d['predicted']);
        $t->eq([10000.0, 11000.0, 12000.0, 13000.0, 14000.0], $d['net']);
    });

    T::test('regression: a noisy series matches an independent least-squares fit to the centavo (6 periods)', function (T $t) {
        Fixtures::reset();
        $nets = [48250.35, 51190.10, 49880.55, 53410.00, 52120.75, 55001.20];
        qa_history($nets);
        $d = qa_dashboard_data(Http::page('dashboard.php')['body']);
        $t->money(sprintf('%.2f', qa_next_by_regression($nets)), $d['predicted']);
    });

    T::test('regression: needs two periods; with one it shows a dash; a falling series never predicts a negative payroll', function (T $t) {
        Fixtures::reset();
        qa_history([10000]);
        $res = Http::page('dashboard.php');
        $t->contains('-', $res['body']);
        $t->same(0.0, qa_dashboard_data($res['body'])['predicted']);
        Fixtures::reset();
        qa_history([9000, 6000, 3000, 1000]);
        $t->same(0.0, qa_dashboard_data(Http::page('dashboard.php')['body'])['predicted'], 'floored at zero');
    });

    T::test('the chart and the forecast use the last SIX MONTHS (the newest month included), not the oldest data', function (T $t) {
        Fixtures::reset();
        // sixteen kinsenas cut-offs, Jan to Aug 2025: the window is Mar to Aug = the last twelve cut-offs
        $nets = [9000, 9200, 9500, 9700, 10000, 10400, 11000, 11200, 12000, 12100, 13000, 13300, 13100, 13800, 14000, 14500];
        qa_history($nets);
        $d = qa_dashboard_data(Http::page('dashboard.php')['body']);
        $window = array_slice($nets, -12);
        $t->eq($window, array_map('intval', $d['net']), 'the chart/regression series: ' . json_encode($d['net']));
        $t->money(sprintf('%.2f', qa_next_by_regression($window)), $d['predicted'], 'prediction from the last six months');
        // a series that fits inside six months is shown whole
        Fixtures::reset();
        $short = [10000, 10400, 11000, 11200, 12000, 12100, 13000, 13300];      // Jan to Apr
        qa_history($short);
        $d = qa_dashboard_data(Http::page('dashboard.php')['body']);
        $t->eq($short, array_map('intval', $d['net']), 'four months: all eight cut-offs');
    }, ['defect' => 'D-04']);

    T::test('the employee and manager dashboards chart the last six months too, and "vs. last period" still compares the two newest payslips', function (T $t) {
        Fixtures::reset();
        $db = getDB();
        $emp = Fixtures::employee(['full_name' => 'Chart Person', 'base_salary' => '500.00']);
        $ids = qa_history([9000, 9100, 9200, 9300, 9400, 9500, 9600, 9700, 9800, 9900, 10000, 10100, 10200, 10300, 10400, 10500]);   // Jan to Aug 2025
        $db->exec("INSERT INTO payroll (period_id, employee_id, gross_pay, net_pay) SELECT id, (SELECT id FROM employees WHERE employee_code = '$emp'), 500, 500 + id FROM payroll_periods");
        $db->exec("INSERT IGNORE INTO attendance_summary (period_id, employee_id, total_hours_worked) SELECT id, (SELECT id FROM employees WHERE employee_code = '$emp'), 80 FROM payroll_periods");
        $db->prepare("INSERT INTO users (employee_id, full_name, email, password_hash, role) VALUES ((SELECT id FROM employees WHERE employee_code = ?), 'Chart Person', 'chart@test', 'x', 'Employee')")->execute([$emp]);
        $eu = (int)$db->lastInsertId();
        $html = Http::page('employee/dashboard.php', [], [], Http::employee($emp, $eu, 'Chart Person'))['body'];
        preg_match('/const payLabels = (\[.*?\]);/s', $html, $m);
        $labels = json_decode($m[1] ?? '[]', true);
        $t->same(12, count($labels), 'Mar to Aug 2025 = twelve cut-offs: ' . json_encode($labels));
        $t->contains('Last 6 months (12 period(s))', $html);
        $t->contains('Mar 1-15, 2025', $m[1] ?? '');
        $t->notContains('Feb 16-28, 2025', $m[1] ?? '');
        $t->ok(preg_match('/Vs\. Last Period.*?\+₱\d/s', $html) === 1, 'the comparison with the previous payslip is still there');
        // the manager's team-hours chart
        $db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES ('Mgr Chart', 'mgrchart@test', 'x', 'Manager')")->execute();
        $mid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO user_scopes (user_id, scope_type) VALUES (?, 'Global')")->execute([$mid]);
        $mhtml = Http::page('manager/dashboard.php', [], [], Http::manager($mid, 'Mgr Chart', ''))['body'];
        $t->contains('Last 6 months (12 period(s))', $mhtml, 'manager: team hours of the last six months');
    });

    T::test('the prediction card says what it predicts: on a kinsenas calendar that is the next CUT-OFF, not "next month"', function (T $t) {
        Fixtures::reset();
        qa_history([10000, 11000, 12000]);
        $html = Http::page('dashboard.php')['body'];
        $t->ok(preg_match('/Predicted Next ([A-Za-z\- ]+) Net Payroll/', $html, $m) === 1, 'prediction card found');
        $t->notContains('Predicted Next Month Net Payroll', $html, 'each series point here is half a month, so the next point is half a month - not next month');
    }, ['defect' => 'D-11']);

    T::test('pipeline step "Period finalized" turns done once the latest period is finalized', function (T $t) {
        Fixtures::reset();
        $ids = qa_history([10000, 11000]);
        // the history rows are synthetic (no contributions, no days): tell the audit-fixed finalize not to question that
        Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => end($ids), 'ignore_drift' => true, 'allow_negative' => true]);
        $t->same('Locked', getDB()->query('SELECT status FROM payroll_periods WHERE id = ' . end($ids))->fetchColumn());
        $html = Http::page('dashboard.php')['body'];
        $t->ok(preg_match('/<div class="pipe-step ([^"]*)">\s*<div class="pipe-no">Step 4<\/div>\s*<div class="pipe-label">Period finalized/', $html, $m) === 1, 'step 4 found');
        $t->contains('done', $m[1] ?? '', 'step 4 class is "' . ($m[1] ?? '?') . '" although the period is Locked');
    }, ['defect' => 'D-09']);

    T::test('the headline numbers describe the most recent period BY DATE, even if an older one was entered afterwards', function (T $t) {
        Fixtures::reset();
        qa_history([10000, 11000]);                                  // Jan 1-15, Jan 16-31 2025
        $db = getDB();
        $db->prepare("INSERT INTO payroll_periods (period_label, start_date, end_date, period_type, status) VALUES ('Dec 16-31, 2024', '2024-12-16', '2024-12-31', 'Semi-Monthly', 'Open')")->execute();
        $old = (int)$db->lastInsertId();
        Fixtures::employee(['emp_id' => 'OLD', 'full_name' => 'Old Entry']);
        $db->prepare("INSERT INTO payroll (period_id, employee_id, gross_pay, net_pay)
                      VALUES (?, (SELECT id FROM employees WHERE employee_code = 'OLD'), 1, 1)")->execute([$old]);
        $html = Http::page('dashboard.php')['body'];
        $t->contains('Jan 16-31, 2025', $html);
        $t->ok(!preg_match('/card-sub">\s*Dec 16-31, 2024/', $html), 'the cards are labelled with the December period just because it was entered last');
    }, ['defect' => 'D-13']);

    T::test('after sign-in the dashboard opens on the period that holds TODAY, even before any payroll is computed in it', function (T $t) {
        Fixtures::reset();
        qa_history([10000, 11000]);                                  // Jan 1-15, Jan 16-31 2025 - both have payroll
        Fixtures::period('This month', date('Y-m-01'), date('Y-m-t'), 'Monthly');   // opened just now, nothing uploaded into it
        $html = Http::page('dashboard.php')['body'];
        $t->contains('Showing <strong>This month</strong>', $html, 'the heading names the period holding today');
        $t->ok(preg_match('/card-sub">\s*This month/', $html) === 1, 'the cards are labelled with it, not with the older period that has data');
        $t->contains('no attendance uploaded yet', $html);
        $t->notContains('no period covers today yet', $html, 'today is inside this period');
        $d = qa_dashboard_data($html);
        $t->same([10000.0, 11000.0], array_map('floatval', $d['net']), 'the trend still shows the earlier periods that have payroll');
    });

    T::test('the dashboard says how far the attendance files reach into the period it shows', function (T $t) {
        Fixtures::reset();
        Fixtures::employee(['full_name' => 'Daily Person', 'base_salary' => '500.00']);
        $pid = Fixtures::period('This month', date('Y-m-01'), date('Y-m-t'), 'Monthly');
        $first = date('Y-m-01');
        $r = Fixtures::days($pid, [Fixtures::day('Daily Person', $first, 8)]);
        $t->same(200, $r['status'], 'the day was saved: ' . json_encode($r));
        $html = Http::page('dashboard.php')['body'];
        $t->contains('attendance up to ' . date('M j, Y', strtotime($first)), $html);
    });

    T::test('a period entered in advance for a later month is not "latest": the dashboard stays on the newest one that has started', function (T $t) {
        Fixtures::reset();
        qa_history([10000, 11000]);                                  // Jan 1-15, Jan 16-31 2025
        $db = getDB();
        $db->prepare("INSERT INTO payroll_periods (period_label, start_date, end_date, period_type, status) VALUES ('Next year', ?, ?, 'Semi-Monthly', 'Open')")
           ->execute([date('Y-m-d', strtotime('+1 year')), date('Y-m-d', strtotime('+1 year +14 days'))]);
        $future = (int)$db->lastInsertId();
        $emp = Fixtures::employee(['full_name' => 'Future Person']);
        $db->prepare("INSERT INTO payroll (period_id, employee_id, gross_pay, net_pay)
                      VALUES (?, (SELECT id FROM employees WHERE employee_code = ?), 99999, 99999)")->execute([$future, $emp]);
        $html = Http::page('dashboard.php')['body'];
        $t->contains('Showing <strong>Jan 16-31, 2025</strong>', $html);
        $t->ok(preg_match('/card-sub">\s*Next year/', $html) !== 1, 'the cards are labelled with a period that has not started');
        $t->contains('no period covers today yet', $html, 'the page says today is outside the period it shows');
        $d = qa_dashboard_data($html);
        $t->same([10000.0, 11000.0], array_map('floatval', $d['net']), 'the trend and the prediction ignore the period that has not started');
    });

    T::test('dashboard totals: gross, net, tax, bonus, deductions of the latest period equal the sums of its payroll rows', function (T $t) {
        Fixtures::reset();
        $emp = Fixtures::employee(['full_name' => 'Totals A']);
        $emp2 = Fixtures::employee(['full_name' => 'Totals B']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $db = getDB();
        $ins = $db->prepare("INSERT INTO payroll (period_id, employee_id, gross_pay, withholding_tax, sss_deduction, philhealth_deduction, pagibig_deduction, bonus_pay, other_deductions, net_pay)
                             VALUES (?,(SELECT id FROM employees WHERE employee_code = ?),?,?,?,?,?,?,?,?)");
        $hrs = $db->prepare("INSERT INTO attendance_summary (period_id, employee_id, total_overtime_hours, total_late_hours)
                             VALUES (?,(SELECT id FROM employees WHERE employee_code = ?),?,?)");
        $ins->execute([$pid, $emp, '6270.00', '0', '325.00', '0', '0', '1000.00', '500.50', '6444.50']);
        $ins->execute([$pid, $emp2, '13692.69', '390.10', '675.00', '0', '0', '0', '0', '12627.59']);
        $hrs->execute([$pid, $emp, '2.5', '0.5']);
        $hrs->execute([$pid, $emp2, '3', '1']);
        $html = Http::page('dashboard.php')['body'];
        // the KPI cards show WHOLE pesos (number_format(x, 0)): 19,962.69 → 19,963 ; 19,072.09 → 19,072 ; 500.50 → 501
        $card = fn(string $label) => preg_match('/' . preg_quote($label, '/') . '<\/div>\s*<div class="card-value">(?:&#8369;)?([^<]+)</', $html, $m) ? trim($m[1]) : '(card not found)';
        $t->same('19,963', $card('Total Gross Pay'), 'gross 6,270.00 + 13,692.69');
        $t->same('19,072', $card('Total Net Pay'), 'net 6,444.50 + 12,627.59');
        $t->same('390', $card('Tax Withheld'));
        $t->same('1,000', $card('Total Bonuses'));
        $t->same('501', $card('Total Deductions'), 'other deductions 500.50 shown rounded half-up');
        $t->same('5.5 hrs', $card('Overtime Hours'), 'overtime 2.5 + 3');
        $t->same('1.5 hrs', $card('Late Hours'), 'late 0.5 + 1');
    });
});
