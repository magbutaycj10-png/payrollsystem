<?php
/*
 * 03 - The pay engine against the ledger.
 *
 * Worked examples first (every figure derived by hand and written out, so an accountant can
 * check them line by line), then the same engine against the ledger on hundreds of generated
 * employee-months. Both go through recomputePeriodFromDaily(): the real day-by-day engine,
 * the real SQL, the real month-to-date settlement.
 */

T::suite('03 · Pay engine vs ledger', function () {
    Fixtures::reset();

    $run = fn(string $label, array $spec) => Scenario::play($spec);

    /* ================================================================== worked examples */

    T::test('Example A - daily-rate ₱480, April 2026, two cut-offs: every figure derived by hand', function (T $t) {
        $d1 = Scenario::fullDays('2026-04-01', '2026-04-15');
        $d1['2026-04-07'] = ['h' => '7.00', 'under' => '1'];       // one hour short, as the sheet states
        $d1['2026-04-09'] = ['h' => '8.00', 'ot' => '2', 'under' => '0'];
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '480.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => $d1 + Scenario::fullDays('2026-04-16', '2026-04-30')]);
        [$a, $b] = $r['app'];
        // run 1: 13 duty days = 104 h − 1 h undertime = 103 h → 480 × 103 / 8 = 6,180.00 ; OT 2 h × ₱45 = 90.00
        $t->moneyMap(['basic' => '6180.00', 'gross_pay' => '6270.00', 'ot_late_adj' => '90.00', 'sss' => '325.00', 'philhealth' => '0.00',
                      'pagibig' => '0.00', 'withholding_tax' => '0.00', 'net_pay' => '5945.00', 'undertime_deduction' => '60.00'],
                     $a + ['basic' => (float)$a['gross_pay'] - (float)$a['ot_late_adj']], 'cut-off 1 (SSS: ₱6,270 → credit ₱6,500 × 5% = ₱325)');
        // run 2: 13 duty days = 6,240.00 ; month 12,510 → credit 12,500 → ₱625 − ₱325 = ₱300 ; PhilHealth 2.5% × 12,420 = 310.50 ; Pag-IBIG 2% × 10,000 cap = 200
        $t->moneyMap(['gross_pay' => '6240.00', 'sss' => '300.00', 'philhealth' => '310.50', 'pagibig' => '200.00', 'withholding_tax' => '0.00', 'net_pay' => '5429.50'],
                     $b, 'cut-off 2 (month taxable ₱11,374.50 is under ₱20,833 → no tax)');
        $t->same([], Scenario::diff($a, $r['exp'][0]), 'ledger agrees on cut-off 1');
        $t->same([], Scenario::diff($b, $r['exp'][1]), 'ledger agrees on cut-off 2');
    });

    T::test('Example B - kinsenas ₱15,000 (₱30,000 a month): absence, undertime, overtime, semi-monthly tax then monthly settlement', function (T $t) {
        $d1 = Scenario::fullDays('2026-04-01', '2026-04-15');
        unset($d1['2026-04-08']);                                    // unexcused absence (a Wednesday)
        $d1['2026-04-10'] = ['h' => '6.00', 'under' => '2'];         // 2 h undertime
        $d1['2026-04-14'] = ['h' => '8.00', 'ot' => '3', 'under' => '0'];
        $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '15000.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => $d1 + Scenario::fullDays('2026-04-16', '2026-04-30')]);
        [$a, $b] = $r['app'];
        // day rate 30,000 / 26 = 1,153.846… ; absent 1,153.85 ; 2 h undertime = 288.46 ; basic 13,557.69 ; OT 135.00
        $t->moneyMap(['gross_pay' => '13692.69', 'ot_late_adj' => '135.00', 'absent_deduction' => '1153.85', 'undertime_deduction' => '288.46',
                      // SSS: 13,692.69 → credit 13,500 → 675.00 ; taxable 13,017.69 → 15% × (13,017.69 − 10,417) = 390.1035
                      'sss' => '675.00', 'philhealth' => '0.00', 'pagibig' => '0.00', 'withholding_tax' => '390.10', 'net_pay' => '12627.59'],
                     $a, 'cut-off 1');
        $t->same(1.0, (float)$a['absent_days']);
        // run 2: full 15,000 ; month 28,692.69 → credit 28,500 → 1,425 − 675 = 750 ; PhilHealth on the ₱30,000 contract = 750 ; Pag-IBIG 200
        // month taxable (13,692.69 − 675) + (15,000 − 1,700) = 26,317.69 → 15% × 5,484.69 = 822.7035 → 822.70 − 390.10 already withheld = 432.60
        $t->moneyMap(['gross_pay' => '15000.00', 'sss' => '750.00', 'philhealth' => '750.00', 'pagibig' => '200.00', 'withholding_tax' => '432.60', 'net_pay' => '12867.40'],
                     $b, 'cut-off 2');
        $t->same([], Scenario::diff($a, $r['exp'][0]));
        $t->same([], Scenario::diff($b, $r['exp'][1]));
    });

    T::test('Example C - monthly ₱75,000, one monthly run: SSS cap, PhilHealth 2.5%, Pag-IBIG cap and the 25% bracket', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '75000.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        // 75,000 − (1,750 + 1,875 + 200) = 71,175 taxable → 8,541.80 + 25% × (71,175 − 66,667) = 9,668.80
        $t->moneyMap(['gross_pay' => '75000.00', 'sss' => '1750.00', 'philhealth' => '1875.00', 'pagibig' => '200.00',
                      'withholding_tax' => '9668.80', 'net_pay' => '61506.20'], $r['app'][0]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
    });

    T::test('Example D - weekly run for a ₱20,000 monthly employee: 12/52 of a month, weekly tax table', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '20000.00'],
            'runs' => [['start' => '2026-04-06', 'end' => '2026-04-12', 'type' => 'Weekly']],
            'days' => Scenario::fullDays('2026-04-06', '2026-04-12')]);
        // 20,000 × 12 / 52 = 4,615.38 ; SSS (split, first week) floor credit 5,000 → 250 ; taxable 4,365.38 < 4,808 → no tax
        $t->moneyMap(['gross_pay' => '4615.38', 'sss' => '250.00', 'philhealth' => '0.00', 'pagibig' => '0.00', 'withholding_tax' => '0.00', 'net_pay' => '4365.38'], $r['app'][0]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
    });

    T::test('Example E - pay of ₱1,200 a month: Pag-IBIG at 1%, SSS and PhilHealth minimums', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '1200.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $t->moneyMap(['gross_pay' => '1200.00', 'sss' => '250.00', 'philhealth' => '250.00', 'pagibig' => '12.00', 'withholding_tax' => '0.00', 'net_pay' => '688.00'], $r['app'][0]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
    });

    T::test('Example F - switches: only PhilHealth deducted for a ₱30,000 monthly employee (15% bracket)', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '30000.00', 'deduct_sss' => 0, 'deduct_pagibig' => 0],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        // taxable 29,250 → 15% × (29,250 − 20,833) = 1,262.55
        $t->moneyMap(['gross_pay' => '30000.00', 'sss' => '0.00', 'philhealth' => '750.00', 'pagibig' => '0.00', 'withholding_tax' => '1262.55', 'net_pay' => '27987.45'], $r['app'][0]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
    });

    T::test('Example G - a 10-hour duty day (₱620): undertime costs rate ÷ 10 an hour; approved leave is paid; pending leave is an unpaid absence', function (T $t) {
        $days = Scenario::fullDays('2026-04-01', '2026-04-15');
        foreach ($days as $d => $v) $days[$d] = ['h' => '10.20', 'under' => '0'];
        $days['2026-04-02'] = ['h' => '8.00', 'under' => '2'];             // 2 h short → ₱124
        unset($days['2026-04-03'], $days['2026-04-04'], $days['2026-04-06']);   // 3 & 4 approved leave, 6 pending leave
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '620.00', 'hours_per_day' => '10.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly']],
            'days' => $days, 'leave' => ['2026-04-03', '2026-04-04'], 'pending' => ['2026-04-06']]);
        $row = $r['app'][0];
        // 13 duty days: 9 worked + 2 leave + 1 pending(absent) + 1 short (counted in the 9) → 10 worked? recount below from the ledger
        $t->same([], Scenario::diff($row, $r['exp'][0]));
        $t->eq(1.0, (float)$row['absent_days'], 'the pending leave day is an absence');
        $t->eq(2.0, (float)$row['leave_days'], 'two approved leave days');
        $t->money('124.00', $row['undertime_deduction'], '2 h × (620 / 10)');
    });

    T::test('Totals-file path: no day records, late hours ARE charged (₱80/h) and overtime paid - gross = basic + OT − late', function (T $t) {
        $ctx = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-15', 'period_type' => 'Semi-Monthly']);
        $emp = payEmployee(['emp_id' => 'QA-T', 'salary_type' => 'daily', 'base_salary' => 500]);
        $p = computePayLine($emp, 120.0, 4.0, 2.5, false, $ctx);
        $t->moneyMap(['basic' => '7500.00', 'ot_late_adj' => '-20.00', 'gross' => '7480.00'], $p);   // 7,500 + 180 − 200
        $p2 = computePayLine($emp, 120.0, 4.0, 2.5, true, $ctx);
        $t->money('180.00', $p2['ot_late_adj'], 'with day-by-day records lateness is not charged separately');
    });

    T::test('Employer shares: SSS 10% of the credit, EC ₱10 → ₱30 when the credit reaches ₱15,000, PhilHealth equal split, Pag-IBIG 2% (even when the employee pays 1%)', function (T $t) {
        $emp = payEmployee(['emp_id' => 'QA-ER', 'salary_type' => 'daily', 'base_salary' => 500]);
        $first = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-15', 'period_type' => 'Semi-Monthly']);
        $b1 = contributionBreakdown($emp, 6000.0, 6000.0, $first);
        $t->money('300.00', $b1['ee']['sss'], 'employee SSS on ₱6,000');
        $t->money('600.00', $b1['er']['sss'], 'employer SSS is twice the employee share');
        $t->money('10.00', $b1['er']['ec'], 'EC below a ₱15,000 credit');
        $earlier = ['QA-ER' => ['g' => 6000.0, 'basic' => 6000.0, 'sss' => 300.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'tax' => 0.0]];
        $second = buildPayContext(['period_start' => '2026-04-16', 'period_end' => '2026-04-30', 'period_type' => 'Semi-Monthly'], $earlier, 1);
        $b2 = contributionBreakdown($emp, 10500.0, 10500.0, $second);
        $t->money('525.00', $b2['ee']['sss'], '₱16,500 → credit 16,500 → 825 − 300');
        $t->money('1050.00', $b2['er']['sss']);
        $t->money('20.00', $b2['er']['ec'], 'EC steps from ₱10 to ₱30: the second run owes the ₱20 difference');
        $t->money($b2['ee']['philhealth'] , $b2['er']['philhealth'], 'PhilHealth: employer share equals the employee share');
        $low = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'period_type' => 'Monthly']);
        $b3 = contributionBreakdown(payEmployee(['emp_id' => 'QA-LOW', 'salary_type' => 'monthly', 'base_salary' => 1200]), 1200.0, 1200.0, $low);
        $t->money('12.00', $b3['ee']['pagibig'], 'Pag-IBIG employee 1%');
        $t->money('24.00', $b3['er']['pagibig'], 'Pag-IBIG employer stays at 2%');
    });

    /* ================================================================== generated employee-months */

    T::test('Generated employee-months: engine = ledger on every figure (' . (int)(getenv('QA_FUZZ') ?: 600) . ' cases, every salary type, rest-day pattern, run shape, timing combination)', function (T $t) {
        $cases = (int)(getenv('QA_FUZZ') ?: 600);
        $bad = [];
        $runs = 0;
        $overRuns = 0;
        $overTotal = 0;
        for ($i = 1; $i <= $cases; $i++) {
            if ($i % 120 === 0) { TestDb::truncateAll(); }
            $spec = qa_fuzz_case($i);
            $r = Scenario::play($spec);
            foreach ($spec['runs'] as $k => $run) {
                $runs++;
                if ($r['exp_refund'][$k]['tax'] !== $r['exp'][$k]['tax']) { $overRuns++; $overTotal += $r['exp'][$k]['tax'] - $r['exp_refund'][$k]['tax']; }
                $d = Scenario::diff($r['app'][$k] ?? null, $r['exp'][$k]);
                $t->checks += 14;
                if ($d && count($bad) < 6) $bad[] = "case #$i ({$spec['emp']['salary_type']} {$spec['emp']['base_salary']}, {$spec['shape']}, run $k {$run['start']}→{$run['end']}, rest '{$spec['emp']['rest_days']}', timing "
                    . implode('/', $spec['cfg']['timing']) . "):\n    " . implode("\n    ", array_slice($d, 0, 4));
            }
        }
        $t->same([], $bad, "$runs pay runs compared");
        fwrite(STDOUT, sprintf(AppCopy::hasFixes()
            ? "         \033[2m(info) %d of %d generated pay runs carried tax over-withheld earlier in the month; the app returned ₱%s in total as a negative tax (D-02, fixed)\033[0m\n"
            : "         \033[2m(info) %d of %d generated pay runs carried over-withheld tax the app never returns - ₱%s in total (defect D-02)\033[0m\n",
            $overRuns, $runs, number_format($overTotal / 100, 2)));
    });

    T::test('Employees\' Compensation steps from ₱10 to ₱30 exactly when the SSS credit reaches ₱15,000 (pay ₱14,750), not before and not after', function (T $t) {
        $monthly = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'period_type' => 'Monthly']);
        $emp = payEmployee(['emp_id' => 'QA-EC', 'salary_type' => 'monthly', 'base_salary' => 1]);
        $cases = [[14749.99, '14500.00', '10.00'], [14750.00, '15000.00', '30.00'], [15000.00, '15000.00', '30.00'], [14500.00, '14500.00', '10.00'], [15250.00, '15500.00', '30.00']];
        foreach ($cases as [$pay, $credit, $ec]) {
            $b = contributionBreakdown($emp, (float)$pay, (float)$pay, $monthly);
            $t->money($credit, $b['msc'], "credit for pay ₱$pay");
            $t->money($ec, $b['er']['ec'], "EC for pay ₱$pay");
            $t->same(Ledger::sssEc(Ledger::c((float)$pay)), (int)round($b['er']['ec'] * 100), "ledger EC for pay ₱$pay");
        }
    });

    /* ================================================================== defects found while building the ledger */

    T::test('Month-end true-up returns tax over-withheld in the first cut-off (each month must end exact)', function (T $t) {
        // ₱1,000/day: 13 days in the first half is taxed on the semi-monthly table (₱289.95), then almost nothing is earned in the
        // second half, so the whole month's taxable pay (₱12,750) is under the ₱20,833 monthly exemption: the month's tax is ₱0.
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '1000.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-15') + ['2026-04-16' => ['h' => '8.00', 'under' => '0']]]);
        $tax = Ledger::c($r['app'][0]['withholding_tax']) + Ledger::c($r['app'][1]['withholding_tax']);
        $monthTaxable = ($r['exp'][0]['gross'] - $r['exp'][0]['sss'] - $r['exp'][0]['ph'] - $r['exp'][0]['pi'])
                      + ($r['exp'][1]['gross'] - $r['exp'][1]['sss'] - $r['exp'][1]['ph'] - $r['exp'][1]['pi']);
        $t->money(Ledger::tax('monthly', $monthTaxable), $tax / 100, "tax withheld over the month (month taxable ₱" . Ledger::fmt($monthTaxable) . ")");
    }, ['defect' => 'D-02']);

    T::test('A salaried employee hired mid-period is paid only from the hire date', function (T $t) {
        // kinsenas ₱7,500; hired Wed 8 Apr 2026; works every duty day 8–15 Apr. The 1st–7th (6 duty days of 13) fall before the hire date.
        $days = Scenario::fullDays('2026-04-08', '2026-04-15');
        $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '7500.00', 'date_hired' => '2026-04-08'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly']], 'days' => $days]);
        $basic = (float)$r['app'][0]['gross_pay'];
        $t->ok($basic < 7500.00 - 1.0, sprintf('gross ₱%.2f - the full kinsena, although 6 of the 13 duty days were before the hire date', $basic));
        // one defensible rule: treat each pre-hire duty day like an unexcused absence (₱15,000 / 26 per day)
        $t->money(Ledger::fmt(750000 - Ledger::div(6 * 1500000, 26)), $basic, 'pro-rated like absences');
    }, ['defect' => 'D-03']);

    T::test('A negative net pay is never locked in unnoticed (a month with one day worked still owes the SSS/PhilHealth minimums)', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '480.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => ['2026-04-01' => ['h' => '8.00', 'under' => '0']]]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'arithmetic itself is right: the statutory minimums really do exceed one day\'s pay');
        $t->ok((float)$row['net_pay'] < 0, 'the case is a negative payslip: net ₱' . $row['net_pay']);
        // finalizing it must not go through without anyone being told
        $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $r['periods'][0]]);
        $status = getDB()->query('SELECT status FROM payroll_periods WHERE id = ' . (int)$r['periods'][0])->fetchColumn();
        $t->ok($status === 'Open' && empty($f['json']['success']),
            sprintf('net pay ₱%s was locked in with no warning (finalize answered %s)', $row['net_pay'], json_encode($f['json'] ?? $f['body'])));
    }, ['defect' => 'D-10']);
});
