<?php
/*
 * Mutations - a self-check of the test suite itself: does it FAIL when the application is wrong?
 *
 *   run-tests.bat --mutation-check                  the current application: M01–M09, M12–M16 and M19–M20 on the original engine and
 *                                                   tables, plus M10–M11, M17–M18 and M21–M56, bugs that can only exist in the code the
 *                                                   audit, the 13th month and the typed monthly amounts (M46–M56) added
 *   run-tests.bat --mutation-check --app=<folder>   another copy; an older one gets only the mutants marked for it (the older copies
 *                                                   compute contributions from pay, so the ledger no longer matches them in other respects)
 *
 * Each mutation edits one line of the COPY of the app that the run uses (never the source tree) - a changed rate,
 * a dropped term, floor instead of round - and the named suite must then report a failure. "SURVIVED" means the suite
 * could not see that bug.
 */
final class Mutations
{
    /** id => [what, file in the app, text to find, replacement, suite to run, 'original'|'fixed'|'both'] */
    public static function all(): array
    {
        $h = 'includes/helpers.php';
        $all = [
            'M01' => ['SSS employee rate 5% → 4.5%',                         $h, "'ee_rate'  => 0.05,", "'ee_rate'  => 0.045,", '01'],
            'M02' => ['PhilHealth floor ₱10,000 → ₱8,000',                   $h, "'floor'    => 10000.0,", "'floor'    => 8000.0,", '01'],
            'M03' => ['Pag-IBIG maximum fund salary ₱10,000 → ₱5,000',       $h, "'max_comp'  => 10000.0,", "'max_comp'  => 5000.0,", '01'],
            'M04' => ['BIR monthly table: prescribed tax typo (33,541.80 → 33,541.00)', $h, '[166667.0, 33541.80, 0.30]', '[166667.0, 33541.00, 0.30]', '01'],
            'M05' => ['SSS credit: round to the nearest ₱500 → floor',        $h, "round(\$compensation / \$r['msc_step']) * \$r['msc_step']", "floor(\$compensation / \$r['msc_step']) * \$r['msc_step']", '01'],
            'M06' => ['EC threshold: SSS share ≥ ₱750 (credit ₱15,000) → > ₱750', $h, ">= \$R['sss']['ec_from'] ? \$R['sss']['ec_high']", "> \$R['sss']['ec_from'] ? \$R['sss']['ec_high']", '03', 'fixed'],
            'M07' => ['Daily pay divides by 8 instead of the duty day',       $h, "\$basic   = round(\$rate * \$paidHours / \$dayHours, 2);", "\$basic   = round(\$rate * \$paidHours / 8, 2);", '03'],
            'M08' => ['Month-end detection: a run that ends the month is not "last"', $h, "|| date('Y-m', strtotime(\$end . ' +1 day')) !== date('Y-m', strtotime(\$start))", '|| false', '03'],
            'M09' => ['Absent-day deduction truncated instead of rounded',     $h, "\$absentDed = round((float)(\$abs['absent'] ?? 0) * \$dayRate, 2);", "\$absentDed = floor((float)(\$abs['absent'] ?? 0) * \$dayRate * 100) / 100;", '03'],
            'M10' => ['Net pay off by one centavo',                            $h, "\$net = round(\$gross - (\$tax + \$sss + \$ph + \$pag), 2);", "\$net = round(\$gross - (\$tax + \$sss + \$ph + \$pag) + 0.01, 2);", '03', 'fixed'],
            'M11' => ['"First cut-off" amounts wait for the last cut-off (SSS in full on the 1st)', $h, "return (int)(\$ctx['earlier_runs'] ?? 0) === 0 ? 'rest' : 'none';", "return 'none';", '03', 'fixed'],
            'M12' => ['Adjustments forget the bonus in net pay',               $h, "\$net = ((float)\$row['gross_pay'] + \$bonus)", "\$net = ((float)\$row['gross_pay'])", '04', 'fixed'],   // recordAdjustments() lives in helpers.php since the 2026-10-07 refactor
            'M13' => ['Payslip prints gross where net pay belongs',            'includes/bir-print.php', "<div class=\"val\">' . birPeso(\$r['net_pay']) . '</div>", "<div class=\"val\">' . birPeso(\$r['gross_pay']) . '</div>", '04'],
            'M14' => ['Weekly period = a quarter month instead of 12/52',      $h, "'Weekly'       => 12.0 / 52.0,", "'Weekly'       => 0.25,", '03'],
            'M15' => ['Re-upload overwrites net pay and loses bonus/deductions', $h, "net_pay = \$newNet,", "net_pay = VALUES(net_pay),", '04'],
            'M16' => ['Undertime rounded down instead of to the nearest hour', $h, "round(max(0.0, \$dayHours - (float)\$d['hours_worked']))", "floor(max(0.0, \$dayHours - (float)\$d['hours_worked']))", '02'],
            'M17' => ['"Split" timing ignored (nothing until the last run)',     $h, "return \$timing === 'split' ? 'share' : 'none';", "return 'none';", '03', 'fixed'],
            'M18' => ['A "split" share ignores the period (always half a month, even for a week)', $h, "(int)round(\$cents(\$amount) * \$ctx['fraction'])", "(int)round(\$cents(\$amount) * 0.5)", '03', 'fixed'],
            'M19' => ['Late hours charged even with day-by-day records',       $h, "\$lateDeduction = \$perDay ? 0.0 : \$late * \$ctx['late_rate'];", "\$lateDeduction = \$late * \$ctx['late_rate'];", '03', 'original'],
            'M19f' => ['Late hours charged even with day-by-day records',      $h, "\$lateDeduction = \$perDay ? 0.0 : round(\$late * \$ctx['late_rate'], 2);", "\$lateDeduction = round(\$late * \$ctx['late_rate'], 2);", '03', 'fixed'],
            'M20' => ['Employer SSS share 10% → 12%',                          $h, "'er_rate'  => 0.10,", "'er_rate'  => 0.12,", '03'],

            // ---- bugs that can only exist in the code the audit added
            'M21' => ['BIR tax: half a centavo rounds down again (D-01)',        $h, "intdiv(\$excess + 50, 100)", "intdiv(\$excess, 100)", '01', 'fixed'],
            'M22' => ['Month-end tax true-up cannot return tax any more (D-02)', $h, "if (\$k !== 'tax' || empty(\$ctx['final'])) \$c = max(0, \$c);", "\$c = max(0, \$c);", '03', 'fixed'],
            'M23' => ['A salaried employee is paid for working days the uploads have not reached (the days to compute are not the days in the file)', $h, "if (\$s === 'absent' && (\$salary || \$d <= \$upTo)) \$absent++;", "if (\$s === 'absent' && \$d <= \$upTo) \$absent++;", '11', 'fixed'],
            'M24' => ['Labor Code overtime ignores the multiplier setting (D-14)', $h, "\$mult = (int)round((\$ctx['ot_multiplier'] ?? 1.25) * 100);", "\$mult = 125;", '03', 'fixed'],
            'M25' => ['Bonus ceiling: ₱90,000 → effectively none (recordAdjustments)', $h, "\$ytd > BIR_EXEMPT_BENEFITS + 0.004", "\$ytd > BIR_EXEMPT_BENEFITS * 1000", '11', 'fixed'],
            'M26' => ['A deduction may take net pay below zero again (D-10)',    $h, "\$type === 'Deduction' && round(\$net, 2) < 0", "\$type === 'Deduction' && round(\$net, 2) < -1e12", '11', 'fixed'],
            'M27' => ['Finalize no longer checks for stale lines (D-18)',        'api/update-payroll.php', "if (empty(\$body['ignore_drift'])) {", "if (false) {", '11', 'fixed'],
            'M28' => ['Overtime limit per day 16 h → 160 h (D-16)',              $h, "const MAX_DAY_OVERTIME = 16.0;", "const MAX_DAY_OVERTIME = 160.0;", '11', 'fixed'],
            'M29' => ['Punch roll-up: a longer day can pay less again (D-17)',   'api/rollup-punches.php', "\$span = max(\$std / 2, \$span - \$breakMinutes / 60.0);", "\$span = \$span - \$breakMinutes / 60.0;", '10', 'fixed'],
            'M30' => ['Forecast: ARIMA counts the average change twice (D-05)',  'assets/js/forecast.js', "        let next = mu;", "        let next = 2 * mu;", '07', 'fixed'],
            'M31' => ['Forecast: Random Forest unseeded again (D-07)',           'assets/js/forecast.js', "this.rng   = makeRng(this.seed !== null ? this.seed : seedFrom(X, y));", "this.rng   = Math.random;", '07', 'fixed'],
            'M32' => ['Labor cost forgets the Employees\' Compensation (D-06)',   $h, "\$row['total'] = round(array_sum(\$row), 2);", "\$row['total'] = round(array_sum(\$row) - \$row['ec'], 2);", '11', 'fixed'],
            'M33' => ['"ONE PESO" reads "ONE PESOS" again (D-12)',               'includes/bir-print.php', "(\$pesos === 1 ? ' PESO' : ' PESOS')", "' PESOS'", '01', 'fixed'],
            'M34' => ['Settings accept an overtime multiplier below 1',          'settings.php', "\$n < 1.0 || \$n > 3.0", "\$n < 0.0 || \$n > 3.0", '11', 'fixed'],
            'M35' => ['Upload: a text without digits ("ABSENT") becomes unreadable', 'assets/js/attendance-upload.js', "if (!/\\d/.test(s)) return 0;", "if (!/\\d/.test(s)) return NaN;", '07', 'fixed'],
            'M36' => ['Hours of a day may exceed 24 again (D-16)',                $h, "const MAX_DAY_HOURS    = 24.0;", "const MAX_DAY_HOURS    = 240.0;", '11', 'fixed'],

            // ---- 13th month pay, the shared adjustment code, the Home page, the shipped schema
            'M37' => ['13th month: a half centavo rounds down',                  $h, "intdiv(2 * \$r['sum'] + 12, 24)", "intdiv(\$r['sum'], 12)", '13', 'fixed'],
            'M38' => ['13th month: overtime counted as basic pay',               $h, "SUM(p.gross_pay - p.ot_late_adj) AS basic", "SUM(p.gross_pay) AS basic", '13', 'fixed'],
            'M39' => ['13th month: payments already made are not counted',       $h, "WHERE h.entry_type = 'Bonus' AND h.reason LIKE '13th Month Pay%'", "WHERE h.entry_type = 'Deduction' AND h.reason LIKE '13th Month Pay%'", '13', 'fixed'],
            'M40' => ['13th month: more than the balance may be paid',           'thirteenth-month.php', "if (\$n > \$row['balance'] + 0.004)", "if (\$n > \$row['balance'] + 1000000000)", '13', 'fixed'],
            'M41' => ['13th month: divided by 13 instead of 12',                 $h, "intdiv(2 * \$r['sum'] + 12, 24)", "intdiv(2 * \$r['sum'] + 13, 26)", '13', 'fixed'],
            'M42' => ['13th month: a month is the month a period ENDS in',       $h, "GROUP BY p.emp_id, MONTH(pp.period_start)", "GROUP BY p.emp_id, MONTH(pp.period_end)", '13', 'fixed'],
            'M43' => ['Home page: a Locked period is not "finalized" (D-19)',    'home.php', "\$periodLocked = in_array(\$latestPeriod['status'] ?? '', ['Locked', 'Finalized'], true);", "\$periodLocked = (\$latestPeriod['status'] ?? '') === 'Finalized';", '13', 'fixed'],
            'M44' => ['database.sql: a column differs from the tested schema',   'sql/database.sql', "hours_per_day     DECIMAL(4,2) NULL DEFAULT NULL,", "hours_per_day     DECIMAL(4,1) NULL DEFAULT NULL,", '13', 'fixed'],
            'M45' => ['The audit trail of a correction states the wrong total',   $h, "\"\$type totalling PHP \" . number_format(\$total, 2)", "\"\$type totalling PHP \" . number_format(\$total + 1, 2)", '13', 'fixed'],

            // ---- SSS / PhilHealth / Pag-IBIG / tax as the employee's own typed monthly amounts (2026-10-10)
            'M46' => ['The month\'s last cut-off no longer settles what is still owed',  $h, "if (!empty(\$ctx['final'])) return 'rest';", "if (false) return 'rest';", '03', 'fixed'],
            'M47' => ['A contribution may go below zero when its amount is lowered mid-month', $h, "if (\$k !== 'tax' || empty(\$ctx['final'])) \$c = max(0, \$c);", "if (false) \$c = max(0, \$c);", '03', 'fixed'],
            'M48' => ['Typed amounts are worked in whole pesos instead of centavos',     $h, "(int)round((float)\$v * 100);       /* whole centavos", "(int)round((float)\$v) * 100;       /* whole centavos", '03', 'fixed'],
            'M49' => ['Company Pag-IBIG share equals the employee\'s even at 1% pay',    $h, "\$pagRatio = \$basicMonth <= \$R['pagibig']['low_limit'] ? \$R['pagibig']['er_rate'] / \$R['pagibig']['rate_low'] : 1.0;", "\$pagRatio = 1.0;", '03', 'fixed'],
            'M50' => ['EC: the step up to ₱30 is not added when SSS is taken in shares', $h, "\$ecOf(\$sssPrev + \$ee['sss']) - \$ecOf(\$sssPrev)", "\$ecOf(\$ee['sss'])", '03', 'fixed'],
            'M51' => ['The engine ignores the SSS amount typed on the employee',         $h, "'sss_amount'        => (float)(\$e['sss_amount']        ?? 0),", "'sss_amount'        => 0.0,", '03', 'fixed'],
            'M52' => ['Employees accepts a ₱10,000,000 Pag-IBIG (the amount limits are gone)', 'employee.php', "\$k === 'tax' ? MAX_SALARY_PESOS : MAX_RATE_PESOS", "MAX_SALARY_PESOS", '11', 'fixed'],
            'M53' => ['Editing an employee\'s amounts does not recompute the open payroll', 'employee.php', "if ((float)(\$prev[\"{\$k}_amount\"] ?? 0) !== \$v) \$changed = true;", "if (false) \$changed = true;", '11', 'fixed'],
            'M54' => ['Settings accept any word as a contribution timing',              'settings.php', "fn(\$v) => in_array(trim(\$v), CONTRIBUTION_TIMING_CHOICES, true) ? null", "fn(\$v) => true ? null", '11', 'fixed'],
            'M55' => ['The default SSS schedule is not "1st cut-off, in full" any more', $h, "const CONTRIBUTION_TIMING_DEFAULT = ['sss' => 'first',", "const CONTRIBUTION_TIMING_DEFAULT = ['sss' => 'split',", '02', 'fixed'],
            'M56' => ['Finalize cannot see that a later cut-off\'s tax is stale',        $h, "'withholding_tax' => \$c['tax']];", "'withholding_tax' => (float)\$r['withholding_tax']];", '09', 'fixed'],

            // ---- the days to compute are the days in the uploaded file (2026-10-10)
            'M57' => ['A day the timesheet marks OFF is deducted as absent',  $h, "    if (\$markedOff) return 'off';", "    if (\$markedOff) return 'absent';", '11', 'fixed'],
        ];
        // an entry without a sixth element applies to both versions of the application
        return array_map(fn($m) => $m + [5 => 'both'], $all);
    }

    /** does the application under test carry the audit fixes? (the runner has not loaded it yet when the mutation check starts) */
    public static function appIsFixed(): bool
    {
        $h = AppCopy::original() . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'helpers.php';
        return is_file($h) && str_contains((string)file_get_contents($h), 'PAYROLL_AUDIT_FIXES');
    }

    public static function apply(string $id, string $appRoot): void
    {
        $m = self::all()[$id] ?? throw new RuntimeException("unknown mutation $id");
        [, $file, $from, $to] = $m;
        $path = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
        $src = (string)file_get_contents($path);
        if (substr_count($src, $from) !== 1) throw new RuntimeException("mutation $id: expected exactly one occurrence of the target text in $file, found " . substr_count($src, $from));
        file_put_contents($path, str_replace($from, $to, $src));
    }

    /** run every mutation in its own process; print the scoreboard; return 0 when all are killed */
    public static function check(array $only = []): int
    {
        $fixed = self::appIsFixed();
        echo "\033[1mMutation check\033[0m - each bug is injected into a COPY of the app; the named suite must fail.\n"
           . '  application: ' . AppCopy::original() . ' (' . ($fixed ? 'with the audit fixes' : 'original code') . ")\n\n";
        $killed = $survived = 0;
        $php = [PHP_BINARY];
        if (php_ini_loaded_file()) { $php[] = '-c'; $php[] = php_ini_loaded_file(); }
        $php[] = '-d'; $php[] = 'extension_dir=' . ini_get('extension_dir');
        foreach (self::all() as $id => [$what, , , , $suite, $for]) {
            if ($only && !in_array($id, $only, true)) continue;
            if ($for !== 'both' && $for !== ($fixed ? 'fixed' : 'original')) continue;
            $t0 = microtime(true);
            $cmd = array_merge($php, ['-r', "require getenv('QA_MAIN');", '--', "--suite=$suite"]);      // the app under test travels in PAYROLL_APP_SRC
            $env = array_merge(getenv(), ['QA_MUTATION' => $id, 'QA_MAIN' => __DIR__ . DIRECTORY_SEPARATOR . 'Main.php', 'QA_FUZZ' => '150']);
            $proc = proc_open($cmd, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($proc);
            $failed = preg_match('/(\d+) failed/', preg_replace('/\e\[[0-9;]*m/', '', $out), $m) ? (int)$m[1] : -1;
            $ok = $code !== 0 && $failed > 0;
            $ok ? $killed++ : $survived++;
            printf("  %s %-4s %-72s suite %s  %s (%.0fs)\n", $ok ? "\033[32mKILLED  \033[0m" : "\033[1;31mSURVIVED\033[0m", $id, $what, $suite,
                $ok ? "→ $failed failing test(s)" : ($failed < 0 ? '(run did not complete: ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($out))), -120) . ')' : '→ no test noticed'), microtime(true) - $t0);
        }
        printf("\n%d of %d injected bugs were caught.\n", $killed, $killed + $survived);
        return $survived ? 1 : 0;
    }
}
