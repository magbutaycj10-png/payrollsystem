<?php
/*
 * 06 — The accountant's tie-outs.
 *
 * Properties that must hold for EVERY payslip and EVERY month, whatever the inputs — checked over a
 * generated population (200 employee-months through the real engine) rather than hand-picked numbers:
 *   · each payslip foots (gross + bonus − deductions = net) and has no negative component
 *   · a month ends exact: the contributions taken over its runs are the contribution on the month's pay
 *   · the attendance table mirrors the payroll table
 *   · the company's shares (employer SSS/EC/PhilHealth/Pag-IBIG) are what the law says
 *   · recomputing is stable, and order-independent
 *   · computePayLine never lets floating-point noise reach a payslip
 *
 * Each case is checked right after it is played and the tables are cleared in batches: the engine
 * (rightly) gives anyone with approved leave a line in every period that overlaps it, so employees of
 * different cases must not share a calendar month for long.
 */

/** the Settings a generated case was played under (they are global, and every case uses different ones) */
function qa_apply_cfg(array $spec): void
{
    Fixtures::setting('overtime_rate', $spec['cfg']['ot_rate']);
    foreach (['sss', 'philhealth', 'pagibig'] as $k) Fixtures::setting("contribution_timing_$k", $spec['cfg']['timing'][$k]);
}

T::suite('06 · Accounting tie-outs', function () {

    T::test('200 generated employee-months: payslips foot, months end exact, shares are lawful, tables mirror, recompute is stable and order-independent', function (T $t) {
        Fixtures::reset();
        $db = getDB();
        $bad = ['foot' => [], 'month' => [], 'er' => [], 'mirror' => [], 'stable' => []];
        $note = function (string $k, string $m) use (&$bad) { if (count($bad[$k]) < 4) $bad[$k][] = $m; };
        $stableChecked = 0;
        $snap = fn(array $pids) => json_encode(array_map(fn($pid) => array_map(fn($r) => [$r['id'], $r['gross_pay'], $r['sss'], $r['philhealth'], $r['pagibig'], $r['withholding_tax'], $r['net_pay']], Fixtures::payroll($pid)), $pids));

        for ($i = 1001; $i <= 1200; $i++) {
            if (($i - 1001) % 40 === 0) TestDb::truncateAll();                       // a fresh calendar for each batch of cases
            $spec = qa_fuzz_case($i);
            $c = Scenario::play($spec);
            $e = $spec['emp'];
            $emp = payEmployee(Fixtures::empRow($c['emp']));
            $label = "case #$i ({$e['salary_type']} {$e['base_salary']}, {$spec['shape']}, timing " . implode('/', $spec['cfg']['timing']) . ')';

            // 1 ─ every payslip foots; no negative component
            foreach ($c['app'] as $k => $row) {
                $t->checks += 3;
                $net = Ledger::c($row['gross_pay']) + Ledger::c($row['bonus']) - Ledger::c($row['withholding_tax']) - Ledger::c($row['sss'])
                     - Ledger::c($row['philhealth']) - Ledger::c($row['pagibig']) - Ledger::c($row['other_deductions']);
                if ($net !== Ledger::c($row['net_pay'])) $note('foot', "$label run $k: net ₱{$row['net_pay']} is not gross + bonus − deductions (₱" . Ledger::fmt($net) . ')');
                if (Ledger::c($row['gross_pay']) - Ledger::c($row['ot_late_adj']) < 0) $note('foot', "$label run $k: negative basic pay");
                foreach (['sss', 'philhealth', 'pagibig', 'withholding_tax', 'absent_deduction', 'undertime_deduction'] as $col) {
                    if (Ledger::c($row[$col]) >= 0) continue;
                    // the one lawful negative: the audit-fixed app returns tax over-withheld earlier in the month, on the month's last run only
                    $earlier = 0;
                    foreach ($c['app'] as $j => $r0) if ($j < $k) $earlier += Ledger::c($r0['withholding_tax']);
                    $lastRun = $spec['runs'][count($spec['runs']) - 1];
                    $refund = $col === 'withholding_tax' && AppCopy::hasFixes() && $k === count($spec['runs']) - 1
                           && Ledger::isFinal($lastRun['type'], $lastRun['start'], $lastRun['end']) && -Ledger::c($row[$col]) <= $earlier;
                    if (!$refund) $note('foot', "$label run $k: $col is negative");
                }
            }

            // 1b ─ the month's TAX ends exact too: what was withheld over the month is the monthly BIR table on the month's taxable pay
            //      (the original app keeps what it over-withheld — D-02 — so this is checked on the audit-fixed app)
            $lastRun = $spec['runs'][count($spec['runs']) - 1];
            if (AppCopy::hasFixes() && Ledger::isFinal($lastRun['type'], $lastRun['start'], $lastRun['end'])) {
                $prevTaxable = 0; $taxSum = 0; $nRuns = count($c['app']);
                $g = $contribs = 0;
                foreach ($c['app'] as $k => $row) {
                    $taxSum += Ledger::c($row['withholding_tax']);
                    $contrib = Ledger::c($row['sss']) + Ledger::c($row['philhealth']) + Ledger::c($row['pagibig']);
                    if ($k < $nRuns - 1) { $g += Ledger::c($row['gross_pay']); $contribs += $contrib; }
                    else $prevTaxable = max(0, $g - $contribs) + max(0, Ledger::c($row['gross_pay']) - $contrib);
                }
                $t->checks++;
                if ($taxSum !== Ledger::tax('monthly', $prevTaxable)) {
                    $note('month', "$label withholding tax over the month: should be ₱" . Ledger::fmt(Ledger::tax('monthly', $prevTaxable)) . ' on taxable ₱' . Ledger::fmt($prevTaxable) . ', withheld ₱' . Ledger::fmt($taxSum));
                }
            }

            // 2 ─ the month ends exact
            $last = $spec['runs'][count($spec['runs']) - 1];
            if (Ledger::isFinal($last['type'], $last['start'], $last['end'])) {
                $comp = $basicM = $sss = $ph = $pi = 0;
                foreach ($c['app'] as $row) {
                    $comp += Ledger::c($row['gross_pay']);
                    $basicM += Ledger::c($row['gross_pay']) - Ledger::c($row['ot_late_adj']);
                    $sss += Ledger::c($row['sss']); $ph += Ledger::c($row['philhealth']); $pi += Ledger::c($row['pagibig']);
                }
                $contract = $e['salary_type'] === 'daily' ? 0 : ($e['salary_type'] === 'kinsenas' ? 2 * Ledger::c($e['base_salary']) : Ledger::c($e['base_salary']));
                $want = ['SSS' => [$e['deduct_sss'] ? Ledger::sssEe($comp) : 0, $sss],
                         'PhilHealth' => [$e['deduct_philhealth'] ? Ledger::philhealthEe($e['salary_type'] === 'daily' ? $basicM : max($basicM, $contract)) : 0, $ph],
                         'Pag-IBIG' => [$e['deduct_pagibig'] ? Ledger::pagibigEe($basicM) : 0, $pi]];
                foreach ($want as $name => [$exp, $act]) {
                    $t->checks++;
                    if ($exp !== $act) $note('month', "$label $name over the month: should be ₱" . Ledger::fmt($exp) . ' (pay ₱' . Ledger::fmt($comp) . '), taken ₱' . Ledger::fmt($act));
                }
            }

            // 3 ─ the company's shares, and the employee's shares as recomputed from the stored month-to-date
            qa_apply_cfg($spec);
            foreach ($c['periods'] as $k => $pid) {
                $row = $c['app'][$k];
                $b = contributionBreakdown($emp, (float)$row['gross_pay'] - (float)$row['ot_late_adj'], (float)$row['gross_pay'], payContext($db, $pid));
                foreach ([['sss', 'sss'], ['ec', 'ec'], ['philhealth', 'ph'], ['pagibig', 'pi']] as [$a, $l]) {
                    $t->checks++;
                    if ((int)round($b['er'][$a] * 100) !== $c['exp'][$k]['er'][$l]) $note('er', "$label run $k employer $a: ledger ₱" . Ledger::fmt($c['exp'][$k]['er'][$l]) . ', app ₱' . number_format($b['er'][$a], 2));
                }
                foreach (['sss', 'philhealth', 'pagibig'] as $col) {
                    $t->checks++;
                    if ((int)round($b['ee'][$col] * 100) !== Ledger::c($row[$col])) $note('er', "$label run $k: stored $col ₱{$row[$col]} but the same inputs now give ₱" . number_format($b['ee'][$col], 2));
                }
            }

            // 4 ─ attendance mirrors payroll (this case's periods)
            $in = implode(',', array_map('intval', $c['periods']));
            $q = $db->query("SELECT COUNT(*) FROM payroll p LEFT JOIN attendance a ON a.period_id = p.period_id AND a.emp_id = p.emp_id
                              WHERE p.period_id IN ($in) AND (a.id IS NULL OR a.gross_pay <> p.gross_pay OR a.withholding_tax <> p.withholding_tax)");
            $t->checks++;
            if ((int)$q->fetchColumn() !== 0) $note('mirror', "$label: payroll lines without a matching attendance row, or with different gross/tax");

            // 5 ─ recompute is stable and order-independent (first 30 multi-run cases)
            if (count($c['periods']) >= 2 && $stableChecked < 30) {
                $stableChecked++;
                $pids = $c['periods'];
                $first = $snap($pids);
                foreach ($pids as $pid) recomputePeriodFromDaily($db, $pid, ['role' => 'admin', 'scope' => null]);
                $t->checks++;
                if ($snap($pids) !== $first) $note('stable', "$label: a second recompute in order changed a line");
                foreach (array_reverse($pids) as $pid) recomputePeriodFromDaily($db, $pid, ['role' => 'admin', 'scope' => null]);
                recomputeMonthFrom($db, $pids[0], ['role' => 'admin', 'scope' => null]);
                $t->checks++;
                if ($snap($pids) !== $first) $note('stable', "$label: out-of-order recompute + month re-settle did not restore the lines");
            }
        }
        $t->ok($stableChecked >= 20, "only $stableChecked multi-run cases were checked for stability");
        $t->same([], $bad['foot'], 'payslips that do not foot');
        $t->same([], $bad['month'], 'months that do not end exact');
        $t->same([], $bad['er'], 'company / employee shares that disagree');
        $t->same([], $bad['mirror'], 'attendance vs payroll');
        $t->same([], $bad['stable'], 'unstable recompute');
    });

    T::test('computePayLine returns centavo-exact figures that foot (5,000 random inputs): no 0.1+0.2 noise reaches a payslip', function (T $t) {
        mt_srand(424242);
        $types = ['daily', 'monthly', 'kinsenas'];
        $bad = [];
        for ($i = 0; $i < 5000; $i++) {
            $type = $types[mt_rand(0, 2)];
            $period = [['2026-04-01', '2026-04-15', 'Semi-Monthly'], ['2026-04-16', '2026-04-30', 'Semi-Monthly'], ['2026-04-01', '2026-04-30', 'Monthly'], ['2026-04-06', '2026-04-12', 'Weekly']][mt_rand(0, 3)];
            $ctx = buildPayContext(['period_start' => $period[0], 'period_end' => $period[1], 'period_type' => $period[2]],
                mt_rand(0, 1) ? ['QA' => ['g' => mt_rand(0, 3000000) / 100, 'basic' => mt_rand(0, 3000000) / 100, 'sss' => mt_rand(0, 90000) / 100, 'philhealth' => 0.0, 'pagibig' => 0.0, 'tax' => mt_rand(0, 100000) / 100]] : [], 1);
            $emp = payEmployee(['emp_id' => 'QA', 'salary_type' => $type, 'base_salary' => mt_rand(30000, 9000000) / 100, 'hours_per_day' => mt_rand(0, 2) ? null : 10]);
            $p = computePayLine($emp, mt_rand(0, 12000) / 100, mt_rand(0, 3000) / 100, mt_rand(0, 500) / 100, (bool)mt_rand(0, 1), $ctx,
                ['absent' => mt_rand(0, 5), 'undertime' => mt_rand(0, 800) / 100, 'working_days' => mt_rand(22, 30)]);
            foreach (['gross', 'basic', 'tax', 'ot_late_adj', 'sss', 'philhealth', 'pagibig', 'net', 'absent_deduction', 'undertime_deduction', 'taxable'] as $k) {
                $t->checks++;
                $c = $p[$k] * 100;
                if (abs($c - round($c)) > 1e-6 * max(1, abs($c)) && count($bad) < 5) $bad[] = "$k = " . var_export($p[$k], true) . " ($type, {$period[2]})";
            }
            $t->checks++;
            $foot = (int)round($p['gross'] * 100) - (int)round($p['tax'] * 100) - (int)round($p['sss'] * 100) - (int)round($p['philhealth'] * 100) - (int)round($p['pagibig'] * 100);
            if ($foot !== (int)round($p['net'] * 100) && count($bad) < 5) $bad[] = "net does not foot: gross {$p['gross']} − tax {$p['tax']} − contributions → {$p['net']} ($type)";
        }
        $t->same([], $bad);
    });
});
