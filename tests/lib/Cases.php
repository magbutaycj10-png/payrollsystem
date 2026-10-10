<?php
/* Cases - generated employee-months shared by several suites (seeded, so case #n is always the same case). */

/** one generated employee-month: employee, runs, day records, leave, settings */
function qa_fuzz_case(int $seed): array
{
    mt_srand(9000 + $seed);
    $pick = fn(array $a) => $a[mt_rand(0, count($a) - 1)];
    $month = mt_rand(1, 12);
    $first = sprintf('2026-%02d-01', $month);
    $last  = date('Y-m-t', strtotime($first));
    $shape = $pick(['SM', 'SM', 'M', 'W']);
    if ($shape === 'SM')     $runs = [['start' => $first, 'end' => date('Y-m-15', strtotime($first)), 'type' => 'Semi-Monthly'],
                                      ['start' => date('Y-m-16', strtotime($first)), 'end' => $last, 'type' => 'Semi-Monthly']];
    elseif ($shape === 'M')  $runs = [['start' => $first, 'end' => $last, 'type' => 'Monthly']];
    else {
        $runs = [];
        foreach ([[1, 7], [8, 14], [15, 21], [22, 28]] as [$a, $b]) {
            $runs[] = ['start' => sprintf('2026-%02d-%02d', $month, $a), 'end' => sprintf('2026-%02d-%02d', $month, $b), 'type' => 'Weekly'];
        }
    }

    $type = $pick(['daily', 'daily', 'daily', 'kinsenas', 'monthly']);
    $base = match ($type) {
        'daily'    => sprintf('%.2f', mt_rand(35000, 95000) / 100),
        'kinsenas' => sprintf('%.2f', mt_rand(500000, 4500000) / 100),
        default    => sprintf('%.2f', mt_rand(1000000, 15000000) / 100),
    };
    $hpd  = $pick([null, null, null, '10.00', '9.00', '7.50', '12.00']);
    $rest = $pick(['7', '7', '7', '6,7', '', '1', '3,4']);
    $restList = $rest === '' ? [] : array_map('intval', explode(',', $rest));
    $dayH = (float)($hpd ?? 8);
    // the employee's own monthly amounts, as typed on Employees: some have none (0.00), the rest anything from ₱50 up to a plausible ceiling
    $typed = fn(int $maxCentavos) => mt_rand(1, 100) <= 35 ? '0.00' : sprintf('%.2f', mt_rand(5000, $maxCentavos) / 100);
    $emp = ['salary_type' => $type, 'base_salary' => $base, 'hours_per_day' => $hpd, 'rest_days' => $rest,
            'sss_amount' => $typed(175000), 'philhealth_amount' => $typed(250000), 'pagibig_amount' => $typed(20000), 'tax_amount' => $typed(3000000)];

    $days = [];
    $leave = $pending = $rejected = [];
    $lastDate = $runs[count($runs) - 1]['end'];
    foreach (Ledger::dates($first, $lastDate) as $d) {
        $isRest = in_array(Ledger::dow($d), $restList, true);
        $roll = mt_rand(1, 100);
        if ($isRest) {
            if ($roll <= 8)       $days[$d] = ['h' => sprintf('%.2f', $dayH)];
            elseif ($roll <= 15)  $days[$d] = ['off' => true];
            continue;
        }
        if ($roll <= 86) {
            $r2 = mt_rand(1, 100);
            $h = $r2 <= 60 ? $dayH : ($r2 <= 75 ? $dayH + mt_rand(0, 100) / 100 : max(0.25, $dayH - mt_rand(5, 400) / 100));
            $days[$d] = ['h' => sprintf('%.2f', $h),
                         'ot' => mt_rand(1, 100) <= 15 ? (string)mt_rand(1, 3) : '0',
                         'late' => mt_rand(1, 100) <= 20 ? sprintf('%.2f', mt_rand(1, 120) / 100) : '0',
                         'under' => mt_rand(1, 100) <= 45 ? (string)mt_rand(0, 3) : null];
        } elseif ($roll <= 90) {
            $days[$d] = ['off' => true];
        } else {
            $r3 = mt_rand(1, 100);                       // no record: maybe leave
            if ($r3 <= 25) $leave[] = $d; elseif ($r3 <= 35) $pending[] = $d; elseif ($r3 <= 42) $rejected[] = $d;
        }
    }
    // every run needs a worked day, and usually the last day of the run is one (so absences up to it are judged)
    foreach ($runs as $r) {
        $has = false;
        foreach ($days as $d => $v) if ($d >= $r['start'] && $d <= $r['end'] && isset($v['h'])) { $has = true; break; }
        if (!$has || mt_rand(1, 100) <= 70) {
            $d = $r['end'];
            $days[$d] = ['h' => sprintf('%.2f', $dayH), 'ot' => '0', 'late' => '0', 'under' => null];
            $leave = array_values(array_diff($leave, [$d])); $pending = array_values(array_diff($pending, [$d])); $rejected = array_values(array_diff($rejected, [$d]));
        }
    }
    return ['emp' => $emp, 'runs' => $runs, 'days' => $days, 'leave' => $leave, 'pending' => $pending, 'rejected' => $rejected,
            'cfg' => ['ot_rate' => $pick(['45', '150', '62.50', '93.75', '100.40']),
                      'timing' => ['sss' => $pick(['first', 'split', 'second']), 'philhealth' => $pick(['first', 'split', 'second']),
                                   'pagibig' => $pick(['first', 'split', 'second']), 'tax' => $pick(['first', 'split', 'second'])]]
                     // every third case of the audit-fixed app pays overtime by the Labor Code method, with the multiplier cycling 1.25 / 1.30 / 2.00
                     // (decided from the case number, so the random stream - and every case of the original - is unchanged)
                     + (AppCopy::hasFixes() && $seed % 3 === 0 ? ['ot_method' => 'labor_code', 'ot_mult' => ['1.25', '1.30', '2.00'][intdiv($seed, 3) % 3]] : [])
                     ,
            'shape' => $shape];
}

/* ---- small helpers shared by the suites ---- */

/** skip a test that only the audit-fixed application can pass */
function qa_need_fixes(): void
{
    if (!AppCopy::hasFixes()) T::skip('behaviour added by the 2026-10-07 audit fixes - the copy under test is older and lacks it');
}

/** record a bonus or deduction through the Adjustments page, the way the admin does */
function qa_adjust(int $periodId, array $empIds, string $type, string $amount, array $extra = []): array
{
    return Http::page('adjustments.php', ['period_id' => $periodId, 'emp_ids' => $empIds, 'entry_type' => $type,
                                          'amount' => $amount, 'reason_select' => $type === 'Bonus' ? 'Performance bonus' : 'Cash advance'] + $extra);
}

/** an employee with a full duty day on every working day of a date range */
function qa_worker(string $name, string $from, string $to, string $rate = '500.00', array $emp = []): string
{
    return Fixtures::employee($emp + ['full_name' => $name, 'base_salary' => $rate]);
}

/**
 * What the admin does on Employees → Edit: one employee through the real page, every field posted again as the form does,
 * with $change on top (e.g. ['tax_amount' => '0']). Returns the page's response.
 */
function qa_edit_employee(string $empId, array $change): array
{
    $e = Fixtures::empRow($empId);
    $post = ['action' => 'edit', 'id' => $e['id'], 'emp_id' => $e['emp_id'], 'full_name' => $e['full_name'], 'position' => (string)$e['position'],
             'branch' => (string)$e['branch'], 'email' => (string)$e['email'], 'salary_type' => $e['salary_type'], 'base_salary' => $e['base_salary'],
             'date_hired' => (string)$e['date_hired'], 'hours_per_day' => (string)$e['hours_per_day'],
             'rest_days' => $e['rest_days'] === '' ? [] : explode(',', $e['rest_days']),
             'sss_amount' => $e['sss_amount'], 'philhealth_amount' => $e['philhealth_amount'], 'pagibig_amount' => $e['pagibig_amount'], 'tax_amount' => $e['tax_amount']];
    return Http::page('employee.php', $change + $post);
}

/* ---- the DBeaver queries (tests/dbeaver_checks.sql), parsed and run ---- */

/** the checks of dbeaver_checks.sql: [['id', 'title', 'expect', 'sql'], …] */
function qa_sql_checks(): array
{
    $text = (string)file_get_contents(dirname(__DIR__) . '/dbeaver_checks.sql');
    $parts = preg_split('/^-- @check /m', $text);
    array_shift($parts);
    $out = [];
    foreach ($parts as $p) {
        [$head, $body] = explode("\n", $p, 2) + ['', ''];
        $h = array_map('trim', explode('|', $head));
        $expect = preg_match('/expect:\s*(none|review|info)/', end($h), $m) ? $m[1] : '';
        $out[] = ['id' => $h[0], 'title' => $h[1] ?? '', 'expect' => $expect, 'sql' => trim($body)];
    }
    return $out;
}

/** run one check's statement; returns the rows */
function qa_sql_run(array $check): array
{
    $sql = rtrim(preg_replace('/^\s*--.*$/m', '', $check['sql']));        // drop the comment lines
    $sql = rtrim($sql, "; \t\r\n");
    return getDB()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- nine months of correctly computed payroll (suite 12 - the DBeaver queries - and the forecast page test in suite 07) ---- */

/** nine months of correctly computed payroll, every salary type and run shape - the data the "expect: none" queries must be silent on */
function qa_sql_dataset(): array
{
    Fixtures::reset();
    $SM = fn(string $y, string $m) => [['start' => "$y-$m-01", 'end' => "$y-$m-15", 'type' => 'Semi-Monthly'],
                                       ['start' => "$y-$m-16", 'end' => date('Y-m-t', strtotime("$y-$m-01")), 'type' => 'Semi-Monthly']];
    $set = [];

    // Every employee below has typed monthly amounts, as the admin does on Employees (sss_amount, philhealth_amount, pagibig_amount, tax_amount) -
    // each one something on both cut-offs, so no period is without a deduction.

    // Jan - kinsenas ₱15,000: an absence, an undertime day, overtime
    $days = Scenario::fullDays('2026-01-01', '2026-01-31');
    unset($days['2026-01-08']);                                                   // absent
    $days['2026-01-09'] = ['h' => '7.00', 'under' => '1'];                        // an hour short
    $days['2026-01-12'] = ['h' => '8.00', 'ot' => '2', 'under' => '0'];
    $set['jan'] = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '15000.00', 'sss_amount' => '675.00', 'philhealth_amount' => '750.00',
                                            'pagibig_amount' => '200.00', 'tax_amount' => '800.00'], 'runs' => $SM('2026', '01'), 'days' => $days]);

    // Feb - daily ₱480: a day off, approved leave, overtime, lateness
    $days = Scenario::fullDays('2026-02-01', '2026-02-28');
    $days['2026-02-10'] = ['off' => true];
    unset($days['2026-02-17']);
    $days['2026-02-04'] = ['h' => '8.00', 'ot' => '3', 'late' => '0.50', 'under' => '0'];
    $set['feb'] = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '480.00', 'sss_amount' => '300.00', 'philhealth_amount' => '250.00'],
                                  'runs' => $SM('2026', '02'), 'days' => $days, 'leave' => ['2026-02-17']]);

    // Mar - monthly ₱75,000, one monthly run
    $set['mar'] = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '75000.00', 'sss_amount' => '1750.00', 'philhealth_amount' => '1875.00',
                                            'pagibig_amount' => '200.00', 'tax_amount' => '9668.80'],
        'runs' => [['start' => '2026-03-01', 'end' => '2026-03-31', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-03-01', '2026-03-31')]);

    // Apr - a ₱20,000 monthly employee on a WEEKLY calendar (four runs; the month ends on the fourth)
    $set['apr'] = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '20000.00', 'sss_amount' => '1000.00', 'pagibig_amount' => '200.00', 'tax_amount' => '600.00'],
        'runs' => [['start' => '2026-04-01', 'end' => '2026-04-07', 'type' => 'Weekly'], ['start' => '2026-04-08', 'end' => '2026-04-14', 'type' => 'Weekly'],
                   ['start' => '2026-04-15', 'end' => '2026-04-21', 'type' => 'Weekly'], ['start' => '2026-04-22', 'end' => '2026-04-28', 'type' => 'Weekly']],
        'days' => Scenario::fullDays('2026-04-01', '2026-04-28')]);

    // May - ₱1,000 a day: 13 days in the first half, one in the second. The tax-refund case: the 1st cut-off is finalized with a typed tax of ₱600
    // (₱300 taken), then the admin lowers it to ₱200 - the 2nd cut-off settles the month and hands ₱100 back (a negative tax).
    $set['may'] = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '1000.00', 'sss_amount' => '500.00', 'philhealth_amount' => '250.00', 'tax_amount' => '600.00'],
        'runs' => $SM('2026', '05'),
        'days' => Scenario::fullDays('2026-05-01', '2026-05-15') + ['2026-05-16' => ['h' => '8.00', 'under' => '0']],
        'between' => [0 => function (string $empId, array $periods) {
            Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $periods[0], 'ignore_drift' => true, 'allow_negative' => true]);
            getDB()->prepare("UPDATE employees SET tax_amount = 200 WHERE emp_id = ?")->execute([$empId]);
        }]]);

    // Jun - a monthly ₱26,000 salary, hired 9 June (the audit's hire-date case)
    $set['jun'] = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '26000.00', 'date_hired' => '2026-06-09', 'sss_amount' => '900.00',
                                            'philhealth_amount' => '650.00', 'pagibig_amount' => '200.00'],
        'runs' => [['start' => '2026-06-01', 'end' => '2026-06-30', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-06-09', '2026-06-30')]);

    // Jul - monthly ₱250,000 (big amounts, in full)
    $set['jul'] = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '250000.00', 'sss_amount' => '1750.00', 'philhealth_amount' => '2500.00',
                                            'pagibig_amount' => '200.00', 'tax_amount' => '57206.70'],
        'runs' => [['start' => '2026-07-01', 'end' => '2026-07-31', 'type' => 'Monthly']], 'days' => Scenario::fullDays('2026-07-01', '2026-07-31')]);

    // Aug - daily ₱1,500 with a ₱3,000 bonus and a ₱500 deduction recorded through Adjustments
    $set['aug'] = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '1500.00', 'sss_amount' => '500.00', 'pagibig_amount' => '200.00'],
                                  'runs' => $SM('2026', '08'), 'days' => Scenario::fullDays('2026-08-01', '2026-08-31')]);
    Http::page('adjustments.php', ['period_id' => $set['aug']['periods'][1], 'emp_ids' => [$set['aug']['emp']], 'entry_type' => 'Bonus', 'amount' => '3000', 'reason_select' => 'Performance bonus']);
    Http::page('adjustments.php', ['period_id' => $set['aug']['periods'][1], 'emp_ids' => [$set['aug']['emp']], 'entry_type' => 'Deduction', 'amount' => '500', 'reason_select' => 'Cash advance']);

    // Sep - a 10-hour duty day, Saturday and Sunday off, a ₱620 daily rate, overtime
    $days = Scenario::fullDays('2026-09-01', '2026-09-30', [6, 7], '10.00');
    $days['2026-09-03'] = ['h' => '10.00', 'ot' => '2', 'under' => '0'];
    $set['sep'] = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '620.00', 'hours_per_day' => '10.00', 'rest_days' => '6,7',
                                            'sss_amount' => '450.00', 'philhealth_amount' => '250.00'], 'runs' => $SM('2026', '09'), 'days' => $days]);

    // a finalized period (statuses must agree), through the real endpoint
    foreach ($set['jan']['periods'] as $pid) Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid, 'ignore_drift' => true, 'allow_negative' => true]);
    return $set;
}

/* ---- the April 2026 timesheet used by the end-to-end suite and the JS parity suite ---- */

function qa_hhmm(int $minutes): string { return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60); }

/** rows of the April 2026 sheet: [name, date, regular minutes, late minutes, undertime ('' = not stated), overtime hours, remark] */
function qa_sheet(): array
{
    $rows = [];
    $add = function (string $name, string $date, int $min, int $late = 0, $under = '', int $ot = 0, string $remark = 'DUTY') use (&$rows) {
        $rows[$name . '|' . $date] = [$name, $date, $min, $late, $under, $ot, $remark];
    };
    foreach (Ledger::dates('2026-04-01', '2026-04-30') as $d) {
        if (Ledger::dow($d) === 7) continue;                                  // Sunday: nobody works
        $add('ALMA REYES', $d, 8 * 60 + 15, 0, 0);                          // ₱480 a day
        $add('BEN SANTOS', $d, 10 * 60 + 12, 0, 0);                         // ₱620, a 10-hour duty day
        $add('CORA DIZON', $d, 8 * 60, 0, 0);                               // kinsenas ₱7,500
        $add('DANTE LIM', $d, 8 * 60, 0, 0);                                // monthly ₱30,000
        $add('ELENA CRUZ', $d, 8 * 60 + 5, 0, 0);                           // ₱350 a day
        $add('FRANCIS GHOST', $d, 8 * 60, 0, 0);                            // not registered in Employee Management
    }
    // ---- cut-off 1 (1–15 April)
    $add('ALMA REYES', '2026-04-07', 7 * 60, 0, 1);                          // one hour short, stated on the sheet
    $add('ALMA REYES', '2026-04-09', 8 * 60 + 22, 0, 0, 1);                  // 9:22 on the clock, 1 h overtime
    $add('ALMA REYES', '2026-04-14', 8 * 60 + 10, 10, 0);                    // 10 minutes late (not charged separately)
    $add('BEN SANTOS', '2026-04-03', 8 * 60, 0, 2);                          // two hours short on a 10-hour day
    unset($rows['CORA DIZON|2026-04-08']);                                   // absent: no row, no leave
    $add('CORA DIZON', '2026-04-10', 0, 0, '', 0, 'OFF');                    // a rotating day off, marked OFF
    $add('DANTE LIM', '2026-04-02', 8 * 60, 0, 0, 3);                        // 3 h overtime
    unset($rows['ELENA CRUZ|2026-04-13'], $rows['ELENA CRUZ|2026-04-14'], $rows['ELENA CRUZ|2026-04-15']);   // 13–14 approved leave, 15 rejected
    // ---- cut-off 2 (16–30 April)
    $add('ALMA REYES', '2026-04-20', 8 * 60, 0, 0, 2);
    $add('ALMA REYES', '2026-04-21', 4 * 60, 0, '');                         // half a day, undertime NOT stated → worked out (4 h)
    $add('BEN SANTOS', '2026-04-22', 10 * 60, 0, 0, 2);
    $add('CORA DIZON', '2026-04-27', 6 * 60, 0, 2);
    return $rows;
}

function qa_csv(array $rows, string $path): void
{
    $fh = fopen($path, 'w');
    fputcsv($fh, ['EMPLOYEE_NAME', 'DATE', 'TOTAL_HOURS_WORKED', 'LATE_HOURS', 'UNDERTIME_HOURS', 'OVERTIME_HOURS', 'REMARKS'], ',', '"', '');
    foreach ($rows as [$name, $date, $min, $late, $under, $ot, $remark]) {
        $total = $remark === 'OFF' ? '' : qa_hhmm($min + 60 * $ot);          // like the pharmacy's sheet, the total INCLUDES overtime
        fputcsv($fh, [$name, $date, $total, $late ? qa_hhmm($late) : '', $under, $ot ?: '', $remark], ',', '"', '');
    }
    fclose($fh);
}

/** the rows the browser would post for one file and one pay period */
function qa_payload(array $sheetRows, string $from, string $to, string $file = 'sheet.csv'): array
{
    $path = TestDb::tmp() . DIRECTORY_SEPARATOR . $file;
    qa_csv(array_filter($sheetRows, fn($r) => $r[1] >= $from && $r[1] <= $to), $path);
    $table = BrowserSim::readFlatTable(BrowserSim::readCsv($path));
    @unlink($path);
    return BrowserSim::dailyPayload($table, $from, $to);
}

/* ---- dashboard helpers (suites 05 and 08) ---- */

/** the numbers the dashboard hands to its chart: window.PAYROLL_DATA = {...} */
function qa_dashboard_data(string $html): array
{
    preg_match('/window\.PAYROLL_DATA = \{\s*n:\s*(\d+),\s*labels:\s*(\[.*?\]),\s*netData:\s*(\[.*?\]),\s*groData:\s*(\[.*?\]),\s*predicted:\s*([0-9.\-]+)/s', $html, $m);
    return $m ? ['n' => (int)$m[1], 'labels' => json_decode($m[2], true), 'net' => json_decode($m[3], true), 'gross' => json_decode($m[4], true), 'predicted' => (float)$m[5]]
              : ['n' => -1, 'labels' => [], 'net' => [], 'gross' => [], 'predicted' => NAN];
}

/** least-squares line through (0..n-1, y), evaluated at x = n - written independently of the app */
function qa_next_by_regression(array $y): float
{
    $n = count($y);
    $sx = $sy = $sxx = $sxy = 0.0;
    foreach ($y as $i => $v) { $sx += $i; $sy += $v; $sxx += $i * $i; $sxy += $i * $v; }
    $slope = ($n * $sxy - $sx * $sy) / ($n * $sxx - $sx * $sx);
    $icpt = ($sy - $slope * $sx) / $n;
    return max(0.0, $icpt + $slope * $n);
}
