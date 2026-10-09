<?php
/*
 * Mutations - a self-check of the test suite itself: does it FAIL when the application is wrong?
 *
 *   run-tests.bat --mutation-check                  the current application: M01–M20 (M19 reworded) plus M21–M45,
 *                                                   bugs that can only exist in the code the audit and the 13th month added
 *   run-tests.bat --mutation-check --app=<folder>   another copy; an older one gets only M01–M20
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
            'M06' => ['EC threshold: credit ≥ ₱15,000 → > ₱15,000',           $h, "\$credit >= \$R['sss']['ec_from']", "\$credit > \$R['sss']['ec_from']", '03'],
            'M07' => ['Daily pay divides by 8 instead of the duty day',       $h, "\$basic   = round(\$rate * \$paidHours / \$dayHours, 2);", "\$basic   = round(\$rate * \$paidHours / 8, 2);", '03'],
            'M08' => ['Month-end detection: a run that ends the month is not "last"', $h, "|| date('Y-m', strtotime(\$end . ' +1 day')) !== date('Y-m', strtotime(\$start))", '|| false', '03'],
            'M09' => ['Absent-day deduction truncated instead of rounded',     $h, "\$absentDed = round((float)(\$abs['absent'] ?? 0) * \$dayRate, 2);", "\$absentDed = floor((float)(\$abs['absent'] ?? 0) * \$dayRate * 100) / 100;", '03'],
            'M10' => ['Net pay off by one centavo',                            $h, "\$net = round(\$gross - (\$tax + \$contrib), 2);", "\$net = round(\$gross - (\$tax + \$contrib) + 0.01, 2);", '03'],
            'M11' => ['PhilHealth ignores the salaried contract salary',       $h, "\$phBasis  = (\$ctx['final'] && \$type !== 'daily') ? max(\$basicM, \$contract) : \$basicM;", "\$phBasis  = \$basicM;", '03'],
            'M12' => ['Adjustments forget the bonus in net pay',               'adjustments.php', "\$net = ((float)\$row['gross_pay'] + \$bonus)", "\$net = ((float)\$row['gross_pay'])", '04'],
            'M13' => ['Payslip prints gross where net pay belongs',            'includes/bir-print.php', "<div class=\"val\">' . birPeso(\$r['net_pay']) . '</div>", "<div class=\"val\">' . birPeso(\$r['gross_pay']) . '</div>", '04'],
            'M14' => ['Weekly period = a quarter month instead of 12/52',      $h, "'Weekly'       => 12.0 / 52.0,", "'Weekly'       => 0.25,", '03'],
            'M15' => ['Re-upload overwrites net pay and loses bonus/deductions', $h, "net_pay = \$newNet,", "net_pay = VALUES(net_pay),", '04'],
            'M16' => ['Undertime rounded down instead of to the nearest hour', $h, "round(max(0.0, \$dayHours - (float)\$d['hours_worked']))", "floor(max(0.0, \$dayHours - (float)\$d['hours_worked']))", '02'],
            'M17' => ['SSS "split" timing ignored (nothing until the last run)', $h, "\$due[\$k] = \$ctx['final'] || \$t === 'split';", "\$due[\$k] = \$ctx['final'];", '03'],
            'M18' => ['Contributions not deducted before tax (tax on gross)',  $h, "\$taxable = max(0.0, \$gross - \$contrib);", "\$taxable = max(0.0, \$gross);", '03'],
            'M19' => ['Late hours charged even with day-by-day records',       $h, "\$lateDeduction = \$perDay ? 0.0 : \$late * \$ctx['late_rate'];", "\$lateDeduction = \$late * \$ctx['late_rate'];", '03', 'original'],
            'M19f' => ['Late hours charged even with day-by-day records',      $h, "\$lateDeduction = \$perDay ? 0.0 : round(\$late * \$ctx['late_rate'], 2);", "\$lateDeduction = round(\$late * \$ctx['late_rate'], 2);", '03', 'fixed'],
            'M20' => ['Employer SSS share 10% → 12%',                          $h, "'er_rate'  => 0.10,", "'er_rate'  => 0.12,", '03'],

            // ---- bugs that can only exist in the code the audit added
            'M21' => ['BIR tax: half a centavo rounds down again (D-01)',        $h, "intdiv(\$excess + 50, 100)", "intdiv(\$excess, 100)", '01', 'fixed'],
            'M22' => ['Month-end tax true-up cannot return tax any more (D-02)', $h, "\$tax   = round(birTax(\$month, 'monthly') - \$p['tax'], 2);", "\$tax   = max(0.0, round(birTax(\$month, 'monthly') - \$p['tax'], 2));", '03', 'fixed'],
            'M23' => ['Working days before the hire date are paid again (D-03)', $h, "\$absent += \$prehire;", "\$absent += 0;", '11', 'fixed'],
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
