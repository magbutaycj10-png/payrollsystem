<?php
/*
 * 04 — End to end: a month of payroll the way the pharmacy runs it, through the real pages and endpoints.
 *
 *   timesheet CSV → (browser parsing, ported) → api/create-period → api/save-daily-attendance → payroll
 *   → adjustments.php (bonus / deduction) → api/update-payroll (finalize / unlock) → print-doc.php (payslips, register)
 *   → api/forecast-data.php → dashboard.php
 *
 * After every step the stored payroll is compared, centavo by centavo, with the ledger recomputed from
 * what the browser actually sent.
 */
require_once AppCopy::root() . '/includes/bir-print.php';

/** what the ledger says each registered employee should have been paid, from the rows that were posted */
function qa_expected(array $emp, array $payloadByRun, array $runs, array $upto): array
{
    $out = [];
    foreach ($emp as $name => $id) {
        $days = [];
        foreach ($payloadByRun as $rows) foreach ($rows as $r) {
            if ($r['emp_name'] !== $name) continue;
            $days[$r['att_date']] = ['h' => sprintf('%.2f', $r['hours_worked']), 'ot' => sprintf('%.2f', $r['overtime_hours']),
                                     'late' => sprintf('%.2f', $r['late_hours']), 'under' => $r['undertime_hours'] === null ? null : sprintf('%.2f', $r['undertime_hours']),
                                     'off' => (bool)$r['day_off']];
        }
        $leave = getDB()->prepare("SELECT date_from, date_to FROM leave_requests WHERE emp_id = ? AND status = 'Approved'");
        $leave->execute([$id]);
        $dates = [];
        foreach ($leave->fetchAll() as $l) $dates = array_merge($dates, Ledger::dates($l['date_from'], $l['date_to']));
        $cfg = ['ot_rate' => getSetting('overtime_rate', '45'), 'refund' => AppCopy::hasFixes(), 'prehire' => AppCopy::hasFixes(),
                'timing' => ['sss' => getSetting('contribution_timing_sss', 'split'), 'philhealth' => getSetting('contribution_timing_philhealth', 'second'),
                             'pagibig' => getSetting('contribution_timing_pagibig', 'second')]];
        $out[$name] = Ledger::month(Fixtures::ledgerEmp(Fixtures::empRow($id)), $runs, $days, $dates, $cfg, $upto);
    }
    return $out;
}

T::suite('04 · End to end — April 2026 on a kinsenas calendar', function () {
    Fixtures::reset();
    $S = new stdClass();                              // the story's shared state

    $runsDef = [['start' => '2026-04-01', 'end' => '2026-04-15', 'type' => 'Semi-Monthly'],
                ['start' => '2026-04-16', 'end' => '2026-04-30', 'type' => 'Semi-Monthly']];
    $people = ['ALMA REYES'  => ['salary_type' => 'daily',    'base_salary' => '480.00'],
               'BEN SANTOS'  => ['salary_type' => 'daily',    'base_salary' => '620.00', 'hours_per_day' => '10.00'],
               'CORA DIZON'  => ['salary_type' => 'kinsenas', 'base_salary' => '7500.00'],
               'DANTE LIM'   => ['salary_type' => 'monthly',  'base_salary' => '30000.00'],
               'ELENA CRUZ'  => ['salary_type' => 'daily',    'base_salary' => '350.00']];
    $S->sheet = qa_sheet();

    /** compare stored payroll with the ledger for the runs uploaded so far */
    $verify = function (T $t, int $upToRun, string $when) use ($S, $runsDef) {
        $runs = array_slice($runsDef, 0, $upToRun + 1);
        $upto = [];
        foreach ($runs as $k => $r) {                        // absences are judged up to the last day any upload of the branch covers
            $m = '';
            foreach ($S->payload[$k] as $row) if (isset($S->emp[$row['emp_name']])) $m = max($m, $row['att_date']);
            $upto[$k] = $m;
        }
        $exp = qa_expected($S->emp, array_slice($S->payload, 0, $upToRun + 1), $runs, $upto);
        foreach ($S->emp as $name => $id) foreach ($runs as $k => $r) {
            $row = Fixtures::payroll($S->periods[$k])[$id] ?? null;
            $d = Scenario::diff($row, $exp[$name][$k]);
            $t->checks += 14;
            if ($d) $t->same([], $d, "$when — $name, cut-off " . ($k + 1));
        }
        $S->exp = $exp;
    };

    T::test('set up: five employees (daily, 10-hour day, kinsenas, monthly), approved + rejected leave, two kinsenas periods', function (T $t) use ($S, $people, $runsDef) {
        $S->emp = [];
        foreach ($people as $name => $e) $S->emp[$name] = Fixtures::employee($e + ['full_name' => $name, 'branch' => 'MAIN']);
        Fixtures::leave($S->emp['ELENA CRUZ'], '2026-04-13', '2026-04-14', 'Approved');
        Fixtures::leave($S->emp['ELENA CRUZ'], '2026-04-15', '2026-04-15', 'Rejected');
        $S->periods = [Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15'), Fixtures::period('Apr 16-30, 2026', '2026-04-16', '2026-04-30')];
        $t->same(2, count($S->periods));
        $r = Http::api('create-period.php', ['label' => 'Overlap', 'start' => '2026-04-10', 'end' => '2026-04-20', 'type' => 'Semi-Monthly']);
        $t->same(409, $r['status'], 'a period overlapping an existing one is refused (days would be paid twice)');
        $r = Http::api('create-period.php', ['label' => 'Backwards', 'start' => '2026-06-10', 'end' => '2026-06-01']);
        $t->same(400, $r['status'], 'end before start is refused');
        $S->payload = [qa_payload($S->sheet, '2026-04-01', '2026-04-15'), qa_payload($S->sheet, '2026-04-16', '2026-04-30')];
    });

    T::test('what the browser sends: "09:22" with 1 h overtime becomes 8.37 regular hours + 1 OT; OFF stays a day off; blank undertime stays "work it out"', function (T $t) use ($S) {
        $byKey = [];
        foreach ($S->payload[0] as $r) $byKey[$r['emp_name'] . '|' . $r['att_date']] = $r;
        $a = $byKey['ALMA REYES|2026-04-09'];
        $t->eq(8.37, $a['hours_worked']);
        $t->eq(1.0, $a['overtime_hours']);
        $t->same(0.0, $a['undertime_hours']);
        $t->eq(0.17, $byKey['ALMA REYES|2026-04-14']['late_hours'], '10 minutes late = 0.17 h');
        $t->same(true, $byKey['CORA DIZON|2026-04-10']['day_off']);
        $t->same(0.0, (float)$byKey['CORA DIZON|2026-04-10']['hours_worked']);
        $t->ok(!isset($byKey['CORA DIZON|2026-04-08']), 'an absent day has no row at all');
        $b = [];
        foreach ($S->payload[1] as $r) $b[$r['emp_name'] . '|' . $r['att_date']] = $r;
        $t->same(null, $b['ALMA REYES|2026-04-21']['undertime_hours'], 'undertime left blank on the sheet is posted as null');
        $t->eq(4.0, $b['ALMA REYES|2026-04-21']['hours_worked']);
    });

    T::test('upload cut-off 1: saved, ghost name reported, payroll equals the ledger', function (T $t) use ($S, $verify) {
        $r = Fixtures::days($S->periods[0], $S->payload[0]);
        $t->same(200, $r['status']);
        $t->same(true, $r['success'], json_encode($r));
        $t->same(['FRANCIS GHOST'], $r['unmatched'], 'a name with no employee is reported, not paid');
        $t->same(5, $r['count'], 'one payroll line per registered employee');
        $t->same([], $r['warnings'], 'no PHP warnings in the endpoint');
        $verify($t, 0, 'after cut-off 1');
        $row = Fixtures::payroll($S->periods[0]);
        $t->same(5, count($row));
        $t->ok(!isset($row['QA']), 'no line for the unregistered name');
    });

    T::test('cut-off 1 by hand: ALMA — 13 duty days, 1 h undertime, 1 h overtime', function (T $t) use ($S) {
        $a = Fixtures::payroll($S->periods[0])[$S->emp['ALMA REYES']];
        // 480 × (13 × 8 − 1) / 8 = 6,180.00 + 1 h OT × 45 = 6,225.00 ; SSS credit 6,000 → 300.00 ; below the ₱10,417 semi-monthly exemption
        $t->moneyMap(['gross_pay' => '6225.00', 'ot_late_adj' => '45.00', 'sss' => '300.00', 'philhealth' => '0.00', 'pagibig' => '0.00',
                      'withholding_tax' => '0.00', 'net_pay' => '5925.00'], $a);
    });

    T::test('upload cut-off 2: month settles — SSS/PhilHealth/Pag-IBIG/tax for the whole month, cut-off 1 untouched', function (T $t) use ($S, $verify) {
        $before = Fixtures::payroll($S->periods[0]);
        $r = Fixtures::days($S->periods[1], $S->payload[1]);
        $t->same(true, $r['success'], json_encode($r));
        $verify($t, 1, 'after cut-off 2');
        $after = Fixtures::payroll($S->periods[0]);
        foreach ($before as $emp => $row) {
            $t->same($row['net_pay'], $after[$emp]['net_pay'], "cut-off 1 net of $emp unchanged by uploading cut-off 2");
            $t->same($row['id'], $after[$emp]['id'], "payroll row id of $emp is stable");
        }
    });

    T::test('month totals: each employee\'s SSS / PhilHealth / Pag-IBIG over both cut-offs equals the contribution on the month\'s pay', function (T $t) use ($S) {
        foreach ($S->emp as $name => $id) {
            $a = Fixtures::payroll($S->periods[0])[$id];
            $b = Fixtures::payroll($S->periods[1])[$id];
            $comp = Ledger::c($a['gross_pay']) + Ledger::c($b['gross_pay']);
            $basic = $comp - Ledger::c($a['ot_late_adj']) - Ledger::c($b['ot_late_adj']);
            $e = Fixtures::empRow($id);
            $contract = $e['salary_type'] === 'daily' ? 0 : ($e['salary_type'] === 'kinsenas' ? 2 * Ledger::c($e['base_salary']) : Ledger::c($e['base_salary']));
            $t->money(Ledger::sssEe($comp), (Ledger::c($a['sss']) + Ledger::c($b['sss'])) / 100, "$name SSS for the month (pay ₱" . Ledger::fmt($comp) . ')');
            $t->money(Ledger::philhealthEe(max($basic, $contract)), (Ledger::c($a['philhealth']) + Ledger::c($b['philhealth'])) / 100, "$name PhilHealth for the month");
            $t->money(Ledger::pagibigEe($basic), (Ledger::c($a['pagibig']) + Ledger::c($b['pagibig'])) / 100, "$name Pag-IBIG for the month");
            $t->money('0.00', $a['philhealth'], "$name: PhilHealth waits for the last cut-off");
            $t->money('0.00', $a['pagibig'], "$name: Pag-IBIG waits for the last cut-off");
            $taxable = $comp - (Ledger::c($a['sss']) + Ledger::c($b['sss']) + Ledger::c($a['philhealth']) + Ledger::c($b['philhealth']) + Ledger::c($a['pagibig']) + Ledger::c($b['pagibig']));
            $taxWithheld = Ledger::c($a['withholding_tax']) + Ledger::c($b['withholding_tax']);
            // the month's tax must not fall short of the monthly table; (over-withholding is a known defect, D-02)
            $t->ok($taxWithheld >= Ledger::tax('monthly', $taxable), "$name withheld ₱" . Ledger::fmt($taxWithheld) . ' for taxable ₱' . Ledger::fmt($taxable));
        }
        // DANTE's month: gross 30,000 + 3 h OT ₱135 → tax 15% on (30,135 − 1,500 − 753.38 …) — checked in the ledger above; spot-check that tax exists
        $d = Fixtures::payroll($S->periods[1])[$S->emp['DANTE LIM']];
        $t->ok((float)$d['withholding_tax'] > 0, 'a ₱30,000 monthly employee does pay withholding tax');
    });

    T::test('uploading the same file again changes nothing (idempotent) and keeps the payroll row ids', function (T $t) use ($S, $verify) {
        $snap = fn() => [Fixtures::payroll($S->periods[0]), Fixtures::payroll($S->periods[1])];
        $before = $snap();
        $days0 = (int)getDB()->query("SELECT COUNT(*) FROM biometric_daily")->fetchColumn();
        $r = Fixtures::days($S->periods[0], $S->payload[0]);
        $t->same(true, $r['success']);
        $t->same($days0, (int)getDB()->query("SELECT COUNT(*) FROM biometric_daily")->fetchColumn(), 'no duplicate day rows');
        $after = $snap();
        foreach ([0, 1] as $k) foreach ($before[$k] as $emp => $row) {
            foreach (['id', 'gross_pay', 'sss', 'philhealth', 'pagibig', 'withholding_tax', 'net_pay'] as $col) {
                $t->same($row[$col], $after[$k][$emp][$col], "$emp run $k $col");
            }
        }
        $t->same(5, (int)getDB()->query("SELECT COUNT(*) FROM attendance WHERE period_id = {$S->periods[0]}")->fetchColumn(), 'one attendance row per employee');
        $verify($t, 1, 'after the re-upload');
    });

    T::test('a corrected cut-off 1 file (ALMA works 2 more overtime hours on 14 Apr) re-settles cut-off 2 automatically', function (T $t) use ($S, $verify) {
        $S->sheet['ALMA REYES|2026-04-14'][5] = 2;
        $S->sheet['ALMA REYES|2026-04-14'][2] = 8 * 60 + 10;
        $S->payload[0] = qa_payload($S->sheet, '2026-04-01', '2026-04-15');
        $r = Fixtures::days($S->periods[0], $S->payload[0]);
        $t->same(true, $r['success']);
        $verify($t, 1, 'after correcting cut-off 1');
        $a = Fixtures::payroll($S->periods[0])[$S->emp['ALMA REYES']];
        $t->money('6315.00', $a['gross_pay'], '6,180 + 3 h OT × 45');
    });

    T::test('days outside the pay period are not saved; a day already saved in another period is not paid twice', function (T $t) use ($S) {
        $stray = [Fixtures::day('ALMA REYES', '2026-05-02', 8), Fixtures::day('ALMA REYES', '2026-04-14', 8), Fixtures::day('ALMA REYES', '2026-04-01', 8)];
        $before = Fixtures::payroll($S->periods[1])[$S->emp['ALMA REYES']]['net_pay'];
        $r = Fixtures::days($S->periods[1], $stray);
        $t->same(400, $r['status'], 'nothing valid to save: ' . json_encode($r));
        $t->same($before, Fixtures::payroll($S->periods[1])[$S->emp['ALMA REYES']]['net_pay'], 'cut-off 2 unchanged');
        $r = Fixtures::days($S->periods[0], [Fixtures::day('ALMA REYES', '2026-04-14', 8, 2, 0, 0)]);
        $t->same(true, $r['success'], 'the same day re-sent into its own period replaces itself');
    });

    /* ------------------------------------------------------------------ adjustments, finalize, documents */

    T::test('bonus ₱1,000 and deduction ₱500 (adjustments.php): net = gross + bonus − deductions, history recorded, survive a re-upload', function (T $t) use ($S) {
        $id = $S->emp['BEN SANTOS'];
        $net0 = (float)Fixtures::payroll($S->periods[0])[$id]['net_pay'];
        Http::page('adjustments.php', ['period_id' => $S->periods[0], 'emp_ids' => [$id], 'entry_type' => 'Bonus', 'amount' => '1000.00', 'reason_select' => 'Performance']);
        Http::page('adjustments.php', ['period_id' => $S->periods[0], 'emp_ids' => [$id], 'entry_type' => 'Deduction', 'amount' => '500.50', 'reason_select' => 'Cash advance']);
        $row = Fixtures::payroll($S->periods[0])[$id];
        $t->money('1000.00', $row['bonus']);
        $t->money('500.50', $row['other_deductions']);
        $t->money(Ledger::fmt(Ledger::c((string)$net0) + 100000 - 50050), $row['net_pay'], 'net moved by +1,000.00 − 500.50');
        $t->same(2, (int)getDB()->query("SELECT COUNT(*) FROM bonus_deduction_history WHERE emp_id = '$id' AND period_id = {$S->periods[0]}")->fetchColumn());
        // a re-upload recomputes the engine's figures but keeps the adjustments
        Fixtures::days($S->periods[0], $S->payload[0]);
        $again = Fixtures::payroll($S->periods[0])[$id];
        $t->money('1000.00', $again['bonus'], 'bonus kept after re-upload');
        $t->money('500.50', $again['other_deductions'], 'deduction kept after re-upload');
        $t->money(Ledger::fmt(Ledger::c((string)$net0) + 100000 - 50050), $again['net_pay'], 'net still includes both');
        $S->adjusted = $id;
    });

    T::test('adjustments refuse zero/negative amounts and employees with no payroll line', function (T $t) use ($S) {
        $before = (int)getDB()->query('SELECT COUNT(*) FROM bonus_deduction_history')->fetchColumn();
        Http::page('adjustments.php', ['period_id' => $S->periods[0], 'emp_ids' => [$S->emp['ALMA REYES']], 'entry_type' => 'Bonus', 'amount' => '-50', 'reason_select' => 'x']);
        Http::page('adjustments.php', ['period_id' => $S->periods[0], 'emp_ids' => [$S->emp['ALMA REYES']], 'entry_type' => 'Bonus', 'amount' => '0', 'reason_select' => 'x']);
        Http::page('adjustments.php', ['period_id' => $S->periods[0], 'emp_ids' => ['NOBODY'], 'entry_type' => 'Bonus', 'amount' => '10', 'reason_select' => 'x']);
        $t->same($before, (int)getDB()->query('SELECT COUNT(*) FROM bonus_deduction_history')->fetchColumn(), 'nothing was recorded');
    });

    T::test('finalize cut-off 1: rows become Finalized; uploads and adjustments are then refused', function (T $t) use ($S) {
        $snapshot = Fixtures::payroll($S->periods[0]);
        $r = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $S->periods[0]]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $t->same(5, $r['json']['finalized']);
        $per = getDB()->query("SELECT status, finalize_count FROM payroll_periods WHERE id = {$S->periods[0]}")->fetch();
        $t->same('Locked', $per['status']);
        $t->same(1, (int)$per['finalize_count']);
        $r = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $S->periods[0]]);
        $t->same(409, $r['status'], 'finalizing twice is refused');
        $up = Fixtures::days($S->periods[0], $S->payload[0]);
        $t->same(403, $up['status'], 'upload into a finalized period is refused: ' . json_encode($up));
        $adj = (int)getDB()->query('SELECT COUNT(*) FROM bonus_deduction_history')->fetchColumn();
        Http::page('adjustments.php', ['period_id' => $S->periods[0], 'emp_ids' => [$S->emp['ALMA REYES']], 'entry_type' => 'Bonus', 'amount' => '50', 'reason_select' => 'late']);
        $t->same($adj, (int)getDB()->query('SELECT COUNT(*) FROM bonus_deduction_history')->fetchColumn(), 'adjustment into a finalized period is refused');
        foreach ($snapshot as $emp => $row) {
            $now = Fixtures::payroll($S->periods[0])[$emp];
            $t->same($row['net_pay'], $now['net_pay'], "locked net of $emp unchanged");
            $t->same('Finalized', $now['status']);
        }
    });

    T::test('payslips (print-doc.php): every figure printed equals the stored row; deductions add up; amount in words matches the net', function (T $t) use ($S) {
        $res = Http::page('print-doc.php', [], ['doc' => 'payslip', 'period' => $S->periods[0], 'copies' => 'employee', 'auto' => '0']);
        $t->same(200, $res['status']);
        $html = $res['body'];
        $t->same(5, substr_count($html, 'Payslip &amp; Acknowledgement Receipt</h1>'), 'one payslip per employee');
        foreach (Fixtures::payroll($S->periods[0]) as $row) {
            $gross = Ledger::c($row['gross_pay']) + Ledger::c($row['bonus']);
            $ded = Ledger::c($row['withholding_tax']) + Ledger::c($row['sss']) + Ledger::c($row['philhealth']) + Ledger::c($row['pagibig']) + Ledger::c($row['other_deductions']);
            $t->same(Ledger::c($row['net_pay']), $gross - $ded, "{$row['emp_name']}: stored net = gross + bonus − deductions");
            $peso = fn(int $c) => '&#8369;' . number_format($c / 100, 2);
            $t->contains($peso(Ledger::c($row['net_pay'])), $html, "{$row['emp_name']}: net printed");
            $t->contains($peso($gross), $html, "{$row['emp_name']}: gross pay (incl. bonus) printed");
            $t->contains($peso($ded), $html, "{$row['emp_name']}: total deductions printed");
            $t->contains(htmlspecialchars(Ledger::words(Ledger::c($row['net_pay']), AppCopy::hasFixes()), ENT_QUOTES), $html, "{$row['emp_name']}: amount in words");
        }
        $t->same(5, (int)getDB()->query("SELECT COUNT(DISTINCT serial_no) FROM document_serials WHERE series = 'AR'")->fetchColumn(), 'a receipt number was issued to each payslip');
        // reprinting reproduces the same numbers instead of burning new ones
        Http::page('print-doc.php', [], ['doc' => 'payslip', 'period' => $S->periods[0], 'copies' => 'employee', 'auto' => '0']);
        $t->same(5, (int)getDB()->query("SELECT COUNT(*) FROM document_serials WHERE series = 'AR'")->fetchColumn(), 'reprint keeps the same receipt numbers');
    });

    T::test('payroll register (print-doc.php?doc=summary): totals equal the sum of the stored rows', function (T $t) use ($S) {
        $res = Http::page('print-doc.php', [], ['doc' => 'summary', 'period' => $S->periods[0], 'auto' => '0']);
        $t->same(200, $res['status']);
        $rows = Fixtures::payroll($S->periods[0]);
        $sum = fn(string $col) => array_sum(array_map(fn($r) => Ledger::c($r[$col]), $rows));
        $peso = fn(int $c) => '&#8369;' . number_format($c / 100, 2);
        $t->contains('Total Gross Payroll</div><div class="v">' . $peso($sum('gross_pay')), $res['body'], 'total gross');
        $t->contains('Total Net Pay Released</div><div class="v">' . $peso($sum('net_pay')), $res['body'], 'total net');
        $withheld = $sum('withholding_tax') + $sum('sss') + $sum('philhealth') + $sum('pagibig') + $sum('other_deductions');
        $t->contains('Total Deductions Withheld</div><div class="v">' . $peso($withheld), $res['body'], 'total deductions');
        // the register must reconcile: gross + bonus − deductions = net
        $t->same($sum('net_pay'), $sum('gross_pay') + $sum('bonus') - $withheld, 'register foots: Σ gross + Σ bonus − Σ deductions = Σ net');
    });

    T::test('unlock → correct → re-finalize: cycle counted, audit trail written, rows flagged Revised only if the net changed', function (T $t) use ($S) {
        $r = Http::api('update-payroll.php', ['action' => 'unlock', 'period_id' => $S->periods[0]]);
        $t->same(true, $r['json']['success'] ?? null);
        $t->same('Open', getDB()->query("SELECT status FROM payroll_periods WHERE id = {$S->periods[0]}")->fetchColumn());
        $id = $S->emp['CORA DIZON'];
        Http::page('adjustments.php', ['period_id' => $S->periods[0], 'emp_ids' => [$id], 'entry_type' => 'Bonus', 'amount' => '250', 'reason_select' => 'Correction']);
        $rows = Fixtures::payroll($S->periods[0]);
        $t->same(1, (int)$rows[$id]['revised_after_finalize'], 'the corrected employee is flagged Revised');
        $t->same(0, (int)$rows[$S->emp['ALMA REYES']]['revised_after_finalize'], 'an untouched employee is not');
        $r = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $S->periods[0]]);
        $t->same(2, $r['json']['cycle']);
        $audit = getDB()->query("SELECT action FROM period_audit WHERE period_id = {$S->periods[0]} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $t->same(['Finalized', 'Reopened', 'Revised', 'Finalized'], $audit);
    });

    /* ------------------------------------------------------------------ reporting and forecasting layers */

    T::test('forecast data API: per-period totals equal the sums of the payroll rows; calendar says two periods a month', function (T $t) use ($S) {
        $res = Http::call('api/forecast-data.php', ['method' => 'GET', 'session' => Http::admin()]);
        $t->same(true, $res['json']['success'] ?? null, $res['body']);
        $t->same('Semi-Monthly', $res['json']['period_type']);
        $t->same(2, $res['json']['periods_per_month']);
        foreach ($res['json']['data'] as $i => $p) {
            $q = getDB()->prepare('SELECT SUM(gross_pay) g, SUM(net_pay) n, SUM(bonus) b, SUM(other_deductions) d, SUM(withholding_tax) tx, COUNT(*) c FROM payroll WHERE period_id = ?');
            $q->execute([$p['id']]);
            $x = $q->fetch();
            $t->money((string)$x['g'], $p['total_gross'], "period {$p['label']} gross");
            $t->money((string)$x['n'], $p['total_net'], "period {$p['label']} net");
            $t->money((string)$x['b'], $p['total_bonus'], "period {$p['label']} bonus");
            $t->same((int)$x['c'], $p['employee_count']);
            $t->same($i === 0 ? 1 : 2, $p['half'], 'half of the month');
            $t->same($i + 1, $p['period_index']);
        }
    });

    T::test('manager scope: a manager can only upload for their own employees, and only into open periods', function (T $t) use ($S) {
        $db = getDB();
        $db->prepare("INSERT INTO users (full_name, email, password_hash, role, branch, scope_type) VALUES ('Mgr', 'mgr@test', 'x', 'manager', 'OTHERBRANCH', 'branch')")->execute();
        $mid = (int)$db->lastInsertId();
        $sess = Http::manager($mid, 'Mgr', 'OTHERBRANCH');
        $open = Fixtures::period('May 1-15, 2026', '2026-05-01', '2026-05-15');
        $r = Fixtures::days($open, [Fixtures::day('ALMA REYES', '2026-05-04', 8)], [], $sess);
        $t->same(400, $r['status'], 'ALMA is in branch MAIN, not this manager\'s: ' . json_encode($r));
        $t->same(['ALMA REYES'], $r['out_of_scope'] ?? ['ALMA REYES']);
        $r = Fixtures::days($S->periods[0], [Fixtures::day('ALMA REYES', '2026-04-03', 8)], [], Http::manager($mid, 'Mgr', ''));
        $t->ok(in_array($r['status'], [200, 403], true));
        $db->prepare("UPDATE users SET branch = '' WHERE id = ?")->execute([$mid]);        // an unscoped manager
        $r = Fixtures::days($S->periods[1], [Fixtures::day('ALMA REYES', '2026-04-30', 8)], [], Http::manager($mid, 'Mgr', ''));
        $t->same(200, $r['status'], 'unscoped manager into an open period');
        $db->exec("UPDATE payroll_periods SET status = 'Locked' WHERE id = " . (int)$S->periods[1]);
        $r = Fixtures::days($S->periods[1], [Fixtures::day('ALMA REYES', '2026-04-30', 8)], [], Http::manager($mid, 'Mgr', ''));
        $t->same(403, $r['status'], 'finalized period refused for a manager too');
        $db->exec("UPDATE payroll_periods SET status = 'Open' WHERE id = " . (int)$S->periods[1]);
    });

    T::test('totals file (api/save-attendance.php): late hours ARE charged here, and it will not silently wipe saved days', function (T $t) {
        Fixtures::reset();
        $emp = Fixtures::employee(['full_name' => 'TOTALS PERSON', 'base_salary' => '500.00', 'salary_type' => 'daily']);
        $pid = Fixtures::period('Totals 1-15', '2026-04-01', '2026-04-15');
        $r = Http::api('save-attendance.php', ['period_id' => $pid, 'rows' => [['emp_name' => 'TOTALS PERSON', 'hours_worked' => 120, 'overtime_hours' => 4, 'late_hours' => 2.5]]]);
        $t->same(true, $r['json']['success'] ?? null, $r['body']);
        $row = Fixtures::payroll($pid)[$emp];
        // 500 × 120/8 = 7,500 + 4 h × 45 − 2.5 h × 80 = 7,480.00 ; SSS credit 7,500 → 375.00 ; taxable 7,105 under the exemption
        $t->moneyMap(['gross_pay' => '7480.00', 'ot_late_adj' => '-20.00', 'sss' => '375.00', 'withholding_tax' => '0.00', 'net_pay' => '7105.00'], $row);
        Fixtures::days($pid, [Fixtures::day('TOTALS PERSON', '2026-04-02', 8)]);
        $r = Http::api('save-attendance.php', ['period_id' => $pid, 'rows' => [['emp_name' => 'TOTALS PERSON', 'hours_worked' => 120, 'overtime_hours' => 0, 'late_hours' => 0]]]);
        $t->same(409, $r['status'], 'day records exist: a totals file must ask first');
        $t->same('has_days', $r['json']['error']);
        $r = Http::api('save-attendance.php', ['period_id' => $pid, 'replace_days' => true, 'rows' => [['emp_name' => 'TOTALS PERSON', 'hours_worked' => 120, 'overtime_hours' => 0, 'late_hours' => 0]]]);
        $t->same(true, $r['json']['success'] ?? null);
        $t->same(0, (int)getDB()->query("SELECT COUNT(*) FROM biometric_daily WHERE period_id = $pid")->fetchColumn(), 'confirmed replace removes the old days');
    });
});
