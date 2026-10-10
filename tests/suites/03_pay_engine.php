<?php
/*
 * 03 - The pay engine against the ledger.
 *
 * Worked examples first (every figure derived by hand and written out, so an accountant can
 * check them line by line), then the same engine against the ledger on hundreds of generated
 * employee-months. Both go through recomputePeriodFromDaily(): the real day-by-day engine,
 * the real SQL, the real month settlement.
 *
 * SSS, PhilHealth, Pag-IBIG and withholding tax are the employee's own MONTHLY amounts, typed by the
 * admin (employees.sss_amount … tax_amount) and taken as typed: the examples type them, the engine takes
 * them on the cut-off(s) Settings' Contribution Schedule names - first | split | second - and the
 * month's last cut-off settles whatever is still owed.
 */

T::suite('03 · Pay engine vs ledger', function () {
    Fixtures::reset();

    $run = fn(string $label, array $spec) => Scenario::play($spec);

    /* ================================================================== worked examples */

    T::test('Example A - daily-rate ₱480, April 2026, two cut-offs: every figure derived by hand (typed SSS ₱500, PhilHealth ₱250, Pag-IBIG ₱100, tax ₱600; the default schedule)', function (T $t) {
        $d1 = Scenario::fullDays('2026-04-01', '2026-04-15');
        $d1['2026-04-07'] = ['h' => '7.00', 'under' => '1'];       // one hour short, as the sheet states
        $d1['2026-04-09'] = ['h' => '8.00', 'ot' => '2', 'under' => '0'];
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '480.00',
                                       'sss_amount' => '500.00', 'philhealth_amount' => '250.00', 'pagibig_amount' => '100.00', 'tax_amount' => '600.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => $d1 + Scenario::fullDays('2026-04-16', '2026-04-30')]);
        [$a, $b] = $r['app'];
        // run 1: 13 duty days = 104 h − 1 h undertime = 103 h → 480 × 103 / 8 = 6,180.00 ; OT 2 h × ₱45 = 90.00 ; gross 6,270.00
        //        SSS: the schedule takes it on the 1st cut-off, in full → 500.00 ; tax: in equal shares → ½ × 600 = 300.00 ; PhilHealth and Pag-IBIG wait
        //        net 6,270 − 500 − 300 = 5,470.00
        $t->moneyMap(['basic' => '6180.00', 'gross_pay' => '6270.00', 'ot_late_adj' => '90.00', 'sss' => '500.00', 'philhealth' => '0.00',
                      'pagibig' => '0.00', 'withholding_tax' => '300.00', 'net_pay' => '5470.00', 'undertime_deduction' => '60.00'],
                     $a + ['basic' => (float)$a['gross_pay'] - (float)$a['ot_late_adj']], 'cut-off 1 (SSS in full, half the tax)');
        // run 2: 13 duty days = 6,240.00 ; SSS already taken ; PhilHealth 250.00 and Pag-IBIG 100.00 in full ; tax the other half = 600 − 300 = 300.00
        //        net 6,240 − 250 − 100 − 300 = 5,590.00
        $t->moneyMap(['gross_pay' => '6240.00', 'sss' => '0.00', 'philhealth' => '250.00', 'pagibig' => '100.00', 'withholding_tax' => '300.00', 'net_pay' => '5590.00'],
                     $b, 'cut-off 2 (the rest: PhilHealth, Pag-IBIG, the other half of the tax)');
        $t->same([], Scenario::diff($a, $r['exp'][0]), 'ledger agrees on cut-off 1');
        $t->same([], Scenario::diff($b, $r['exp'][1]), 'ledger agrees on cut-off 2');
    });

    T::test('Example B - kinsenas ₱15,000 (₱30,000 a month): absence, undertime, overtime; SSS in two shares, Pag-IBIG on the 1st cut-off, PhilHealth and tax on the 2nd', function (T $t) {
        $d1 = Scenario::fullDays('2026-04-01', '2026-04-15');
        unset($d1['2026-04-08']);                                    // unexcused absence (a Wednesday)
        $d1['2026-04-10'] = ['h' => '6.00', 'under' => '2'];         // 2 h undertime
        $d1['2026-04-14'] = ['h' => '8.00', 'ot' => '3', 'under' => '0'];
        $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '15000.00',
                                       'sss_amount' => '675.00', 'philhealth_amount' => '750.00', 'pagibig_amount' => '200.00', 'tax_amount' => '800.00'],
            'cfg' => ['timing' => ['sss' => 'split', 'philhealth' => 'second', 'pagibig' => 'first', 'tax' => 'second']],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => $d1 + Scenario::fullDays('2026-04-16', '2026-04-30')]);
        [$a, $b] = $r['app'];
        // day rate 30,000 / 26 = 1,153.846… ; absent 1,153.85 ; 2 h undertime = 288.46 ; basic 13,557.69 ; OT 135.00 ; gross 13,692.69
        // run 1: SSS ½ × 675 = 337.50 ; Pag-IBIG "first" = 200.00 in full ; PhilHealth and tax wait → net 13,692.69 − 337.50 − 200.00 = 13,155.19
        $t->moneyMap(['gross_pay' => '13692.69', 'ot_late_adj' => '135.00', 'absent_deduction' => '1153.85', 'undertime_deduction' => '288.46',
                      'sss' => '337.50', 'philhealth' => '0.00', 'pagibig' => '200.00', 'withholding_tax' => '0.00', 'net_pay' => '13155.19'],
                     $a, 'cut-off 1');
        $t->same(1.0, (float)$a['absent_days']);
        // run 2: full 15,000 ; SSS the rest 675 − 337.50 = 337.50 ; PhilHealth 750.00 and tax 800.00 in full ; Pag-IBIG already taken
        //        net 15,000 − 337.50 − 750 − 800 = 13,112.50
        $t->moneyMap(['gross_pay' => '15000.00', 'sss' => '337.50', 'philhealth' => '750.00', 'pagibig' => '0.00', 'withholding_tax' => '800.00', 'net_pay' => '13112.50'],
                     $b, 'cut-off 2');
        $t->same([], Scenario::diff($a, $r['exp'][0]));
        $t->same([], Scenario::diff($b, $r['exp'][1]));
    });

    T::test('Example C - monthly ₱75,000, one monthly run: a monthly payroll takes every typed amount in full (here the law\'s figures for this pay)', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '75000.00',
                                       'sss_amount' => '1750.00', 'philhealth_amount' => '1875.00', 'pagibig_amount' => '200.00', 'tax_amount' => '9668.80'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        // 75,000 − (1,750 + 1,875 + 200 + 9,668.80) = 61,506.20
        $t->moneyMap(['gross_pay' => '75000.00', 'sss' => '1750.00', 'philhealth' => '1875.00', 'pagibig' => '200.00',
                      'withholding_tax' => '9668.80', 'net_pay' => '61506.20'], $r['app'][0]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
    });

    T::test('Example D - weekly run for a ₱20,000 monthly employee: pay is 12/52 of a month, a "split" amount is 12/52 of its month too, "first" is taken in full', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '20000.00', 'sss_amount' => '1000.00', 'tax_amount' => '1200.00'],
            'runs' => [['start' => '2026-04-06', 'end' => '2026-04-12', 'type' => 'Weekly']],
            'days' => Scenario::fullDays('2026-04-06', '2026-04-12')]);
        // 20,000 × 12 / 52 = 4,615.38 ; SSS (first) 1,000.00 in full ; tax (split) 1,200 × 12 / 52 = 276.92 ; net 4,615.38 − 1,000.00 − 276.92 = 3,338.46
        $t->moneyMap(['gross_pay' => '4615.38', 'sss' => '1000.00', 'philhealth' => '0.00', 'pagibig' => '0.00', 'withholding_tax' => '276.92', 'net_pay' => '3338.46'], $r['app'][0]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
    });

    T::test('Example E - a ₱1,200 month with typed SSS ₱250, PhilHealth ₱250 and Pag-IBIG ₱12: taken as typed, whatever the pay', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '1200.00',
                                       'sss_amount' => '250.00', 'philhealth_amount' => '250.00', 'pagibig_amount' => '12.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $t->moneyMap(['gross_pay' => '1200.00', 'sss' => '250.00', 'philhealth' => '250.00', 'pagibig' => '12.00', 'withholding_tax' => '0.00', 'net_pay' => '688.00'], $r['app'][0]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
    });

    T::test('Example F - only some typed: PhilHealth ₱750 and tax ₱1,262.55 for a ₱30,000 monthly employee - no SSS, no Pag-IBIG (blank = none)', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '30000.00', 'philhealth_amount' => '750.00', 'tax_amount' => '1262.55'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        $t->moneyMap(['gross_pay' => '30000.00', 'sss' => '0.00', 'philhealth' => '750.00', 'pagibig' => '0.00', 'withholding_tax' => '1262.55', 'net_pay' => '27987.45'], $r['app'][0]);
        $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
    });

    T::test('Example G - a 10-hour duty day (₱620): undertime costs rate ÷ 10 an hour; approved leave is paid; pending leave is an unpaid absence', function (T $t) {
        $days = Scenario::fullDays('2026-04-01', '2026-04-15');
        foreach ($days as $d => $v) $days[$d] = ['h' => '10.20', 'under' => '0'];
        $days['2026-04-02'] = ['h' => '8.00', 'under' => '2'];             // 2 h short → ₱124
        unset($days['2026-04-03'], $days['2026-04-04'], $days['2026-04-06']);   // 3 & 4 approved leave, 6 pending leave
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '620.00', 'hours_per_day' => '10.00', 'sss_amount' => '375.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly']],
            'days' => $days, 'leave' => ['2026-04-03', '2026-04-04'], 'pending' => ['2026-04-06']]);
        $row = $r['app'][0];
        // 13 duty days: 9 worked + 2 leave + 1 pending(absent) + 1 short (counted in the 9) → 10 worked? recount below from the ledger
        $t->same([], Scenario::diff($row, $r['exp'][0]));
        $t->eq(1.0, (float)$row['absent_days'], 'the pending leave day is an absence');
        $t->eq(2.0, (float)$row['leave_days'], 'two approved leave days');
        $t->money('124.00', $row['undertime_deduction'], '2 h × (620 / 10)');
        $t->money('375.00', $row['sss'], 'the typed SSS, in full on the 1st cut-off');
    });

    T::test('Example H - an employee with nothing typed (most of the pharmacy): no SSS, PhilHealth, Pag-IBIG or tax on any cut-off - net pay is gross pay', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '350.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
        foreach ($r['app'] as $k => $row) {
            $t->moneyMap(['sss' => '0.00', 'philhealth' => '0.00', 'pagibig' => '0.00', 'withholding_tax' => '0.00'], $row, "cut-off " . ($k + 1));
            $t->money($row['gross_pay'], $row['net_pay'], 'cut-off ' . ($k + 1) . ': net pay = gross pay');
            $t->same([], Scenario::diff($row, $r['exp'][$k]));
        }
    });

    T::test('Totals-file path: no day records, late hours ARE charged (₱80/h) and overtime paid - gross = basic + OT − late', function (T $t) {
        $ctx = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-15', 'period_type' => 'Semi-Monthly']);
        $emp = payEmployee(['emp_id' => 'QA-T', 'salary_type' => 'daily', 'base_salary' => 500]);
        $p = computePayLine($emp, 120.0, 4.0, 2.5, false, $ctx);
        $t->moneyMap(['basic' => '7500.00', 'ot_late_adj' => '-20.00', 'gross' => '7480.00'], $p);   // 7,500 + 180 − 200
        $p2 = computePayLine($emp, 120.0, 4.0, 2.5, true, $ctx);
        $t->money('180.00', $p2['ot_late_adj'], 'with day-by-day records lateness is not charged separately');
    });

    T::test('Employer shares follow what was deducted: SSS twice the employee\'s, EC ₱10 → ₱30 from a ₱750 SSS share, PhilHealth equal, Pag-IBIG 2% (even when the employee pays 1%)', function (T $t) {
        $none = EARLIER_NONE;
        $e = employerShare(['sss' => 300.0, 'philhealth' => 250.0, 'pagibig' => 200.0], $none, 9000.0);
        $t->money('600.00', $e['sss'], 'employer SSS is twice the employee share (10% against 5%)');
        $t->money('10.00', $e['ec'], 'EC below a ₱15,000 credit (an SSS share under ₱750)');
        $t->money('250.00', $e['philhealth'], 'PhilHealth: employer share equals the employee share');
        $t->money('200.00', $e['pagibig'], 'Pag-IBIG: employer 2% matches the employee 2%');
        // an SSS typed ₱900 and taken in two ₱450 shares: the first half owes ₱10 of EC, the second the ₱20 step up to ₱30
        $half1 = employerShare(['sss' => 450.0, 'philhealth' => 0.0, 'pagibig' => 0.0], $none, 9000.0);
        $half2 = employerShare(['sss' => 450.0, 'philhealth' => 0.0, 'pagibig' => 0.0], ['sss' => 450.0] + $none, 9000.0);
        $t->money('10.00', $half1['ec'], 'EC on the first share');
        $t->money('20.00', $half2['ec'], 'EC steps from ₱10 to ₱30: the second share owes the ₱20 difference');
        $t->money('1800.00', $half1['sss'] + $half2['sss'] + 0.0, 'employer SSS over the month = twice ₱900');
        // pay of ₱1,200 a month: the employee's Pag-IBIG is 1% (₱12) but the company still pays 2%
        $low = employerShare(['sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 12.0], $none, 1200.0);
        $t->money('24.00', $low['pagibig'], 'Pag-IBIG employer stays at 2% when the employee pays 1%');
        $t->money('0.00', $low['ec'], 'no SSS, no EC');
        // through the engine: the breakdown's company share is the same function on the same deductions
        $emp = payEmployee(['emp_id' => 'QA-ER', 'salary_type' => 'daily', 'base_salary' => 500, 'sss_amount' => 300, 'philhealth_amount' => 250, 'pagibig_amount' => 200]);
        $mon = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'period_type' => 'Monthly']);
        $b = contributionBreakdown($emp, 12000.0, $mon);
        $t->same(['sss' => 300.0, 'philhealth' => 250.0, 'pagibig' => 200.0], $b['ee'], 'a monthly run takes the typed amounts');
        $t->same(employerShare($b['ee'], EARLIER_NONE, 12000.0), $b['er']);
        $t->money('600.00', $b['er']['sss']);
    });

    /* ================================================================== the schedule: where a monthly amount goes */

    T::test('The schedule per amount: "first" in full on the 1st cut-off, "split" in equal shares, "second" in full on the last - and the month always comes to exactly the amount typed', function (T $t) {
        // ₱495.01 is awkward on purpose: half of it is a half centavo. Shares round half up, the last cut-off takes the rest.
        foreach ([['first', '495.01', '0.00'], ['split', '247.51', '247.50'], ['second', '0.00', '495.01']] as [$when, $c1, $c2]) {
            $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '9000.00', 'sss_amount' => '495.01', 'tax_amount' => '495.01'],
                'cfg' => ['timing' => ['sss' => $when, 'tax' => $when]],
                'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
                'days' => Scenario::fullDays('2026-04-01', '2026-04-30')]);
            [$a, $b] = $r['app'];
            $t->money($c1, $a['sss'], "$when: SSS on the 1st cut-off");
            $t->money($c2, $b['sss'], "$when: SSS on the 2nd cut-off");
            $t->money($c1, $a['withholding_tax'], "$when: tax on the 1st cut-off");
            $t->money($c2, $b['withholding_tax'], "$when: tax on the 2nd cut-off");
            $t->money('495.01', Ledger::fmt(Ledger::c($a['sss']) + Ledger::c($b['sss'])), "$when: the month's SSS is exactly what was typed");
            $t->same([], Scenario::diff($a, $r['exp'][0]), "$when: ledger agrees on cut-off 1");
            $t->same([], Scenario::diff($b, $r['exp'][1]), "$when: ledger agrees on cut-off 2");
        }
    });

    T::test('A weekly payroll: "first" in the month\'s first week, "split" at 12/52 a week with the last week taking the rest, "second" in the last week', function (T $t) {
        $weeks = [['2026-04-01', '2026-04-07'], ['2026-04-08', '2026-04-14'], ['2026-04-15', '2026-04-21'], ['2026-04-22', '2026-04-28']];
        $runs  = array_map(fn($w) => ['start' => $w[0], 'end' => $w[1], 'type' => 'Weekly'], $weeks);
        $r = Scenario::play(['emp' => ['salary_type' => 'monthly', 'base_salary' => '20000.00', 'sss_amount' => '500.00', 'philhealth_amount' => '300.00', 'tax_amount' => '1000.00'],
            'cfg' => ['timing' => ['sss' => 'first', 'philhealth' => 'second', 'tax' => 'split']],
            'runs' => $runs, 'days' => Scenario::fullDays('2026-04-01', '2026-04-28')]);
        $sss = array_map(fn($row) => $row['sss'], $r['app']);
        $ph  = array_map(fn($row) => $row['philhealth'], $r['app']);
        $tax = array_map(fn($row) => $row['withholding_tax'], $r['app']);
        // week 4 (Apr 22–28) ends the month's weeks: its next week is in May, so it is the last run
        $t->same(['500.00', '0.00', '0.00', '0.00'], $sss, 'SSS "first": all of it in week 1');
        $t->same(['0.00', '0.00', '0.00', '300.00'], $ph, 'PhilHealth "second": all of it in the last week');
        // tax split: 1,000 × 12/52 = 230.77 a week for weeks 1-3 (692.31), the last week takes the rest 307.69
        $t->same(['230.77', '230.77', '230.77', '307.69'], $tax, 'tax "split": 12/52 of the month a week, the last week settles');
        foreach ($r['app'] as $k => $row) $t->same([], Scenario::diff($row, $r['exp'][$k]), 'ledger agrees on week ' . ($k + 1));
    });

    T::test('An amount edited between the cut-offs: the last cut-off settles the difference - a contribution stops at 0, tax is handed back (a negative tax)', function (T $t) {
        // kinsenas ₱15,000, everything "split". Cut-off 1 is finalized with the old amounts (SSS 500, PhilHealth 250, tax 600), then the admin edits
        // the employee: SSS down to 100, PhilHealth up to 400, tax down to 200. Cut-off 2 settles the MONTH against the new amounts.
        $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '15000.00', 'sss_amount' => '500.00', 'philhealth_amount' => '250.00', 'tax_amount' => '600.00'],
            'cfg' => ['timing' => ['sss' => 'split', 'philhealth' => 'split', 'pagibig' => 'split', 'tax' => 'split']],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'], ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']],
            'days' => Scenario::fullDays('2026-04-01', '2026-04-30'),
            'between' => [0 => function (string $empId, array $periods) {
                Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $periods[0], 'ignore_drift' => true, 'allow_negative' => true]);
                getDB()->prepare("UPDATE employees SET sss_amount = 100, philhealth_amount = 400, tax_amount = 200 WHERE emp_id = ?")->execute([$empId]);
            }]]);
        [$a, $b] = $r['app'];
        // cut-off 1 (old amounts, ½ each): SSS 250.00, PhilHealth 125.00, tax 300.00 → net 15,000 − 675 = 14,325.00
        $t->moneyMap(['sss' => '250.00', 'philhealth' => '125.00', 'withholding_tax' => '300.00', 'net_pay' => '14325.00'], $a, 'cut-off 1');
        // cut-off 2 (new amounts): SSS owes 100 − 250 = −150 → 0.00 (a contribution already taken is not refunded) ; PhilHealth 400 − 125 = 275.00 ;
        //   tax 200 − 300 = −100.00 (a refund) → net 15,000 − 0 − 275 + 100 = 14,825.00
        $t->moneyMap(['sss' => '0.00', 'philhealth' => '275.00', 'withholding_tax' => '-100.00', 'net_pay' => '14825.00'], $b, 'cut-off 2');
        $t->money('400.00', Ledger::fmt(Ledger::c($a['philhealth']) + Ledger::c($b['philhealth'])), 'the month\'s PhilHealth is exactly the new ₱400 (125 + 275)');
        $t->money('200.00', Ledger::fmt(Ledger::c($a['withholding_tax']) + Ledger::c($b['withholding_tax'])), 'the month\'s tax is exactly the new ₱200 (300 − 100)');
        $t->money('250.00', Ledger::fmt(Ledger::c($a['sss']) + Ledger::c($b['sss'])), 'SSS: ₱250 was already taken and is not handed back, although the new amount is ₱100');
    }, ['defect' => 'D-02']);

    /* ================================================================== generated employee-months */

    T::test('Generated employee-months: engine = ledger on every figure (' . (int)(getenv('QA_FUZZ') ?: 600) . ' cases, every salary type, rest-day pattern, run shape, typed amounts - some none - and schedule combination)', function (T $t) {
        $cases = (int)(getenv('QA_FUZZ') ?: 600);
        $bad = [];
        $runs = 0;
        for ($i = 1; $i <= $cases; $i++) {
            if ($i % 120 === 0) { TestDb::truncateAll(); }
            $spec = qa_fuzz_case($i);
            $r = Scenario::play($spec);
            foreach ($spec['runs'] as $k => $run) {
                $runs++;
                $d = Scenario::diff($r['app'][$k] ?? null, $r['exp'][$k]);
                $t->checks += 14;
                if ($d && count($bad) < 6) $bad[] = "case #$i ({$spec['emp']['salary_type']} {$spec['emp']['base_salary']}, {$spec['shape']}, run $k {$run['start']}→{$run['end']}, rest '{$spec['emp']['rest_days']}', "
                    . "typed sss/ph/pi/tax {$spec['emp']['sss_amount']}/{$spec['emp']['philhealth_amount']}/{$spec['emp']['pagibig_amount']}/{$spec['emp']['tax_amount']}, timing "
                    . implode('/', $spec['cfg']['timing']) . "):\n    " . implode("\n    ", array_slice($d, 0, 4));
            }
        }
        $t->same([], $bad, "$runs pay runs compared");
    });

    T::test('Employees\' Compensation steps from ₱10 to ₱30 exactly when the SSS share reaches ₱750 (a ₱15,000 credit), not before and not after', function (T $t) {
        $cases = [['749.99', '10.00'], ['750.00', '30.00'], ['750.01', '30.00'], ['725.00', '10.00'], ['762.50', '30.00'], ['1750.00', '30.00'], ['250.00', '10.00'], ['0.01', '10.00']];
        foreach ($cases as [$sss, $ec]) {
            $e = employerShare(['sss' => (float)$sss, 'philhealth' => 0.0, 'pagibig' => 0.0], EARLIER_NONE, 9000.0);
            $t->money($ec, $e['ec'], "EC for an SSS share of ₱$sss");
        }
        $t->money('0.00', employerShare(['sss' => 0.0, 'philhealth' => 250.0, 'pagibig' => 0.0], EARLIER_NONE, 9000.0)['ec'], 'no SSS, no EC');
    });

    /* ================================================================== defects found while building the ledger */

    T::test('A salaried employee who starts mid-period is paid only for the days in the file (not the full period salary)', function (T $t) {
        // kinsenas ₱7,500; starts Wed 8 Apr 2026 (Date Hired 8 Apr - it plays no part); the file holds every duty day 8–15 Apr. The 1st–7th (6 duty
        // days of 13) are not in the file.
        $days = Scenario::fullDays('2026-04-08', '2026-04-15');
        $r = Scenario::play(['emp' => ['salary_type' => 'kinsenas', 'base_salary' => '7500.00', 'date_hired' => '2026-04-08'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly']], 'days' => $days]);
        $basic = (float)$r['app'][0]['gross_pay'];
        $t->ok($basic < 7500.00 - 1.0, sprintf('gross ₱%.2f - the full kinsena, although 6 of the 13 duty days are not in the file', $basic));
        // each duty day the file does not account for is an unpaid day at the month's day rate (₱15,000 / 26)
        $t->money(Ledger::fmt(750000 - Ledger::div(6 * 1500000, 26)), $basic, 'pro-rated like absences');
    }, ['defect' => 'D-03']);

    T::test('A negative net pay is never locked in unnoticed (a month with one day worked still owes the typed SSS and PhilHealth)', function (T $t) {
        $r = Scenario::play(['emp' => ['salary_type' => 'daily', 'base_salary' => '480.00', 'sss_amount' => '250.00', 'philhealth_amount' => '250.00'],
            'runs' => [['start' => '2026-04-01', 'end' => '2026-04-30', 'type' => 'Monthly']],
            'days' => ['2026-04-01' => ['h' => '8.00', 'under' => '0']]]);
        $row = $r['app'][0];
        $t->same([], Scenario::diff($row, $r['exp'][0]), 'arithmetic itself is right: the typed amounts really do exceed one day\'s pay');
        $t->ok((float)$row['net_pay'] < 0, 'the case is a negative payslip: net ₱' . $row['net_pay']);
        // finalizing it must not go through without anyone being told
        $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $r['periods'][0]]);
        $status = getDB()->query('SELECT status FROM payroll_periods WHERE id = ' . (int)$r['periods'][0])->fetchColumn();
        $t->ok($status === 'Open' && empty($f['json']['success']),
            sprintf('net pay ₱%s was locked in with no warning (finalize answered %s)', $row['net_pay'], json_encode($f['json'] ?? $f['body'])));
    }, ['defect' => 'D-10']);
});
