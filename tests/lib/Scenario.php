<?php
/*
 * Scenario - play one employee through one or more pay runs on BOTH sides:
 *
 *   the application   rows go into biometric_daily, recomputePeriodFromDaily() (the code every upload,
 *                     manual entry and leave decision calls) builds the payroll lines, read back from `payroll`
 *   the ledger        Ledger::month() recomputes the same thing in whole centavos
 *
 * and compare them field by field. Uses the real engine, orchestration and SQL; skips only HTTP.
 */
final class Scenario
{
    private static int $seq = 0;

    /**
     * $spec: emp[] (employees columns - sss_amount, philhealth_amount, pagibig_amount, tax_amount are the monthly amounts typed
     *        on Employees), runs[] (start, end, type), days (date => [h, ot, late, under, off]),
     *        leave[] approved dates, pending[] / rejected[] dates, cfg (ot_rate, timing… - timing is saved on the employee: sss_timing…),
     *        setup (callable). The employee is paid Monthly when every run is a Monthly one, else Semi-Monthly (emp['pay_schedule'] overrides).
     *        between: [run index => callable ($empId, $periodIds)] - runs after that run is computed and before the next one (an admin
     *        finalizing a cut-off, then editing the employee mid-month). The ledger cannot follow an edit, so 'exp' is only meaningful without it.
     * @return array{emp:string, periods:int[], app:array, exp:array}
     */
    public static function play(array $spec): array
    {
        $db = getDB();
        $n = ++self::$seq;

        /* A scenario owns the dates of its runs. Many cases use the same dates, and a day belongs to EVERY pay period that holds its date,
           so the periods (with their pay lines) and the days an earlier case left on these dates are cleared first. (The application refuses
           overlapping periods; only a test that writes periods straight into the database can make them.) Other months stay. */
        foreach ($spec['runs'] as $r) {
            $db->prepare('DELETE FROM payroll_periods WHERE start_date <= ? AND end_date >= ?')->execute([$r['end'], $r['start']]);
            $db->prepare('DELETE FROM biometric_daily WHERE att_date BETWEEN ? AND ?')->execute([$r['start'], $r['end']]);
        }
        $cfg = ($spec['cfg'] ?? []) + ['ot_rate' => '45'];
        $cfg['timing'] = ($cfg['timing'] ?? []) + Ledger::TIMING;          // when each monthly amount is taken: first | split | second

        Fixtures::setting('overtime_rate', $cfg['ot_rate']);
        Fixtures::setting('overtime_method', $cfg['ot_method'] ?? 'flat');          // ignored by the original application
        Fixtures::setting('overtime_multiplier', $cfg['ot_mult'] ?? '1.25');
        Fixtures::setting('late_rate', $cfg['late_rate'] ?? '80');
        Fixtures::setting('standard_hours', (string)($cfg['std'] ?? 8));

        // the employee's own schedules: the cut-off that takes each monthly amount (cfg timing), and how often they are paid - Monthly when every
        // run of the scenario is a Monthly one (a pay period only takes the employees on its schedule), else Semi-Monthly (a Weekly run takes everybody)
        $allMonthly = $spec['runs'] && !array_filter($spec['runs'], fn($r) => $r['type'] !== 'Monthly');
        $own = ['pay_schedule' => $allMonthly ? 'Monthly' : 'Semi-Monthly',
                'sss_timing' => $cfg['timing']['sss'], 'philhealth_timing' => $cfg['timing']['philhealth'],
                'pagibig_timing' => $cfg['timing']['pagibig'], 'tax_timing' => $cfg['timing']['tax']];
        $empId = Fixtures::employee(($spec['emp'] ?? []) + $own + ['emp_id' => sprintf('QA-%05d', $n), 'full_name' => "QA Employee $n", 'branch' => "B$n"]);
        $emp = Fixtures::empRow($empId);

        $periods = [];
        $runs = [];
        foreach ($spec['runs'] as $i => $r) {
            $db->prepare("INSERT INTO payroll_periods (period_label, start_date, end_date, period_type, status) VALUES (?,?,?,?, 'Open')")
               ->execute(["QA $n run $i", $r['start'], $r['end'], $r['type']]);
            $periods[$i] = (int)$db->lastInsertId();
            $runs[$i] = ['start' => $r['start'], 'end' => $r['end'], 'type' => $r['type']];
        }

        // day rows: one per date, and only dates that a run holds (the run that holds the date picks it up)
        $ins = $db->prepare("INSERT INTO biometric_daily (employee_id, att_date, hours_worked, overtime_hours, late_hours, undertime_hours, is_day_off)
                             VALUES (?,?,?,?,?,?,?)");
        foreach ($spec['days'] as $date => $d) {
            foreach ($runs as $i => $r) {
                if ($date < $r['start'] || $date > $r['end']) continue;
                $off = !empty($d['off']);
                $ins->execute([(int)$emp['id'], $date, $off ? 0 : ($d['h'] ?? 0), $off ? 0 : ($d['ot'] ?? 0),
                               $off ? 0 : ($d['late'] ?? 0), $off ? null : ($d['under'] ?? null), $off ? 1 : 0]);
                break;
            }
        }
        foreach (['Approved' => $spec['leave'] ?? [], 'Pending' => $spec['pending'] ?? [], 'Rejected' => $spec['rejected'] ?? []] as $status => $dates) {
            foreach ($dates as $date) Fixtures::leave($empId, $date, $date, $status);
        }
        if (isset($spec['setup'])) ($spec['setup'])($empId, $periods);

        // ---- the application: the real engine, run by run, oldest first
        $app = [];
        foreach ($periods as $i => $pid) {
            recomputePeriodFromDaily($db, $pid, ['role' => 'admin', 'scope' => null]);
            $app[$i] = Fixtures::payroll($pid)[$empId] ?? null;
            if (isset($spec['between'][$i])) ($spec['between'][$i])($empId, $periods);
        }

        // ---- the ledger
        // 'exp' follows the application under test: the ORIGINAL does not return tax over-withheld earlier in the month (defect D-02);
        // the version with the audit fixes does. 'exp_refund' is always what the month SHOULD come to (refund on).
        $fixed = AppCopy::hasFixes();
        $emp2 = Fixtures::ledgerEmp($emp);
        $exp = Ledger::month($emp2, $runs, $spec['days'], $spec['leave'] ?? [], $cfg + ['refund' => $fixed]);
        $expRefund = Ledger::month($emp2, $runs, $spec['days'], $spec['leave'] ?? [], $cfg + ['refund' => true]);
        return ['emp' => $empId, 'periods' => $periods, 'app' => $app, 'exp' => $exp, 'exp_refund' => $expRefund, 'runs' => $runs];
    }

    /** The payroll line's money against the ledger's, field by field. Returns a list of differences (empty = identical). */
    public static function diff(?array $row, array $e): array
    {
        if ($row === null) return ['no payroll line was produced'];
        $money = ['gross_pay' => 'gross', 'ot_late_adj' => 'ot_late', 'sss' => 'sss', 'philhealth' => 'ph', 'pagibig' => 'pi',
                  'withholding_tax' => 'tax', 'net_pay' => 'net', 'absent_deduction' => 'absent_ded', 'undertime_deduction' => 'under_ded'];
        $out = [];
        foreach ($money as $col => $k) {
            if (Ledger::c($row[$col]) !== $e[$k]) {
                $out[] = sprintf('%s: ledger ₱%s, app ₱%s (%+.2f)', $col, Ledger::fmt($e[$k]), $row[$col], (Ledger::c($row[$col]) - $e[$k]) / 100);
            }
        }
        foreach (['absent_days' => 'absent', 'leave_days' => 'leave', 'days_off' => 'off'] as $col => $k) {
            if ((float)$row[$col] != (float)$e[$k]) $out[] = "$col: ledger {$e[$k]}, app {$row[$col]}";
        }
        if (abs((float)$row['undertime_hours'] - $e['under_hh'] / 100) > 0.0001) $out[] = "undertime_hours: ledger " . ($e['under_hh'] / 100) . ", app {$row['undertime_hours']}";
        // identity every payslip must satisfy
        $net = Ledger::c($row['gross_pay']) + Ledger::c($row['bonus']) - Ledger::c($row['withholding_tax']) - Ledger::c($row['sss'])
             - Ledger::c($row['philhealth']) - Ledger::c($row['pagibig']) - Ledger::c($row['other_deductions']);
        if ($net !== Ledger::c($row['net_pay'])) $out[] = 'net_pay is not gross + bonus − deductions on this very row';
        return $out;
    }

    /** every day in [from, to] that is not one of the rest weekdays, as full duty-day rows */
    public static function fullDays(string $from, string $to, array $rest = [7], string $hours = '8.00', $under = '0'): array
    {
        $days = [];
        foreach (Ledger::dates($from, $to) as $d) {
            if (!in_array(Ledger::dow($d), $rest, true)) $days[$d] = ['h' => $hours, 'under' => $under];
        }
        return $days;
    }

    public static function fmt(array $list): string { return implode("\n", array_slice($list, 0, 8)); }
}
