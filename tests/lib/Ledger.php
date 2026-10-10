<?php
/*
 * Ledger - "the accountant's calculator".
 *
 * An independent re-computation of everything the payroll engine does, written from
 * the pay rules documented in info/ph_government_deductions.md - NOT from the engine's code.
 * A pay run takes the employee's own typed MONTHLY amounts (SSS, PhilHealth, Pag-IBIG, tax)
 * on the cut-off(s) the schedule names. The statutory tables below (SSS Circular 2024-006,
 * PhilHealth 5%, HDMF Circular 460, BIR RR 11-2018 Annex E) are what suite 01 holds the
 * reference calculators in helpers.php against. It works in WHOLE CENTAVOS with integer
 * arithmetic only: no floats and no round(), so it is exact and cannot share the engine's
 * rounding behaviour.
 *
 * Conventions
 *   money   integer centavos      (₱1,234.56 = 123456)
 *   hours   integer hundredths    (8.5 h     = 850)         ← how hours are stored (DECIMAL(6,2))
 *   rounding is always "half up" (away from zero on ties), the way a payslip is rounded by hand
 */
final class Ledger
{
    /* ============================================================ parsing / formatting */

    /** pesos (string "1,234.5" or float) -> centavos; extra decimals round half up */
    public static function c($v): int
    {
        if (is_int($v)) throw new InvalidArgumentException('Ledger::c() takes pesos as string/float, not int');
        if (is_float($v)) $v = sprintf('%.6F', $v);
        $s = trim(str_replace(',', '', (string)$v));
        if ($s === '' || !preg_match('/^(-)?(\d*)(?:\.(\d+))?$/', $s, $m)) throw new InvalidArgumentException("bad amount '$v'");
        $frac = $m[3] ?? '';
        $c = (int)($m[2] === '' ? '0' : $m[2]) * 100 + (int)str_pad(substr($frac, 0, 2), 2, '0');
        if (strlen($frac) > 2 && (int)$frac[2] >= 5) $c++;
        return $m[1] === '-' ? -$c : $c;
    }

    /** hours given as decimal text/number -> hundredths of an hour */
    public static function hh($v): int
    {
        return self::c(is_float($v) ? $v : (string)$v);
    }

    public static function fmt(int $c): string
    {
        return ($c < 0 ? '-' : '') . intdiv(abs($c), 100) . '.' . str_pad((string)(abs($c) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** n / d rounded half up (ties away from zero); d > 0 */
    public static function div(int $n, int $d): int
    {
        if ($d <= 0) throw new InvalidArgumentException('divisor must be positive');
        $q = intdiv(2 * abs($n) + $d, 2 * $d);
        return $n < 0 ? -$q : $q;
    }

    /* ============================================================ statutory tables */

    /** SSS monthly salary credit: compensation to the nearest ₱500 (ties up), ₱5,000 … ₱35,000 */
    public static function sssMsc(int $compC): int
    {
        return max(500000, min(self::div($compC, 50000) * 50000, 3500000));
    }
    public static function sssEe(int $compC): int { return intdiv(self::sssMsc($compC) * 5, 100); }   // 5 % of MSC (exact)
    public static function sssEr(int $compC): int { return intdiv(self::sssMsc($compC) * 10, 100); }  // 10 % of MSC (exact)
    /** Employees' Compensation, employer only: ₱10 below a ₱15,000 credit, ₱30 from it */
    public static function sssEc(int $compC): int { return self::sssMsc($compC) >= 1500000 ? 3000 : 1000; }

    /** PhilHealth employee share: 2.5 % of basic, basic floored at ₱10,000 and capped at ₱100,000 */
    public static function philhealthEe(int $basicC): int
    {
        return self::div(max(1000000, min($basicC, 10000000)) * 25, 1000);
    }

    /** Pag-IBIG employee share: 1 % of pay up to ₱1,500, else 2 %, on at most ₱10,000 */
    public static function pagibigEe(int $payC): int
    {
        return self::div(min($payC, 1000000) * ($payC <= 150000 ? 1 : 2), 100);
    }
    public static function pagibigEr(int $payC): int { return self::div(min($payC, 1000000) * 2, 100); }

    /*
     * BIR Annex E (RR 11-2018, effective 1 Jan 2023): [taxable compensation over, tax on that amount, % of the excess].
     * Typed again here from the published table; deliberately not copied from PH_RULES.
     */
    public const BIR = [
        'daily'   => [[2191800, 603430, 35], [547900, 110260, 30], [219200, 28085, 25], [109600, 6165, 20], [68500, 0, 15]],
        'weekly'  => [[15384600, 4235565, 35], [3846200, 774045, 30], [1538500, 197120, 25], [769200, 43260, 20], [480800, 0, 15]],
        'semi'    => [[33333300, 9177070, 35], [8333300, 1677070, 30], [3333300, 427070, 25], [1666700, 93750, 20], [1041700, 0, 15]],
        'monthly' => [[66666700, 18354180, 35], [16666700, 3354180, 30], [6666700, 854180, 25], [3333300, 187500, 20], [2083300, 0, 15]],
    ];

    public static function tax(string $table, int $taxableC): int
    {
        foreach (self::BIR[$table] as [$over, $base, $pct]) {
            if ($taxableC > $over) return $base + self::div(($taxableC - $over) * $pct, 100);
        }
        return 0;
    }

    /** The annual TRAIN schedule (RA 10963) - the source the monthly tables were built from */
    public static function annualTax(int $annualC): int
    {
        $steps = [[800000000, 220250000, 35], [200000000, 40250000, 30], [80000000, 10250000, 25], [40000000, 2250000, 20], [25000000, 0, 15]];
        foreach ($steps as [$over, $base, $pct]) {
            if ($annualC > $over) return $base + self::div(($annualC - $over) * $pct, 100);
        }
        return 0;
    }

    /* ============================================================ calendar */

    public static function dow(string $date): int { return (int)date('N', strtotime($date)); }

    /** days of the month containing $date that are not one of the employee's weekly rest days */
    public static function workingDays(string $date, array $rest = [7]): int
    {
        $first = date('Y-m-01', strtotime($date));
        $n = 0;
        for ($i = 0, $len = (int)date('t', strtotime($first)); $i < $len; $i++) {
            if (!in_array((int)date('N', strtotime("$first +$i days")), $rest, true)) $n++;
        }
        return $n;
    }

    public static function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) $out[] = $d;
        return $out;
    }

    /** is this the month's last pay run? (a monthly run, or one that ends the month; a weekly run whose next week is next month) */
    public static function isFinal(string $type, string $start, string $end): bool
    {
        if ($type === 'Monthly') return true;
        if (date('Y-m', strtotime("$end +1 day")) !== date('Y-m', strtotime($start))) return true;
        return $type === 'Weekly' && date('Y-m', strtotime("$end +7 days")) !== date('Y-m', strtotime($start));
    }

    /* ============================================================ pay run */

    /** The schedule Settings starts with: SSS in full on the 1st cut-off, PhilHealth and Pag-IBIG in full on the last, tax in equal shares */
    public const TIMING = ['sss' => 'first', 'philhealth' => 'second', 'pagibig' => 'second', 'tax' => 'split'];

    /**
     * One employee's pay for one run, plus the company's shares.
     *
     * $emp   type daily|monthly|kinsenas · base ("480.00") · hours_per_day (null = $cfg['std'])
     *        rest [ISO weekdays]   (no hire date: the days that count are the days in $days - see below)
     *        amt [sss, ph, pi, tax => the employee's MONTHLY amounts, centavos; missing = 0 = none]
     * $run   start · end · type Semi-Monthly|Monthly|Weekly
     * $days  date => [h, ot, late, under (null = work it out), off (bool)]   - hours as decimal text
     * $leave dates of APPROVED leave
     * $cfg   ot_rate · late_rate · std (standard hours) · timing [sss,philhealth,pagibig,tax => first|split|second]
     *        per_day (true: late is not charged, the day-by-day rule) · refund (true: over-withholding is returned)
     *        ot_method flat|labor_code · ot_mult ("1.25"): with labor_code overtime pays hourly rate × ot_mult (D-14)
     *        runs_before: how many earlier pay runs of this month are in the system (month() fills it in)
     * $prev  earlier runs of the same month, centavos: g, basic, sss, ph, pi, tax
     * $coveredTo  the day a daily-rate employee's absences are shown up to (default: the last day with any record).
     *        A monthly / kinsenas salary has no such limit: the days to compute are the days IN THE FILE, so every working
     *        day of the run that the file does not account for (hours, OFF, approved leave) is unpaid, uploaded yet or not -
     *        and the Date Hired never matters.
     */
    public static function run(array $emp, array $run, array $days, array $leave, array $cfg, array $prev = [], ?string $coveredTo = null): array
    {
        $prev += ['g' => 0, 'basic' => 0, 'sss' => 0, 'ph' => 0, 'pi' => 0, 'tax' => 0];
        $cfg  += ['ot_rate' => '45', 'late_rate' => '80', 'std' => 8, 'per_day' => true, 'refund' => true, 'runs_before' => 0,
                  'timing' => self::TIMING];
        $cfg['timing'] += self::TIMING;
        $type   = $emp['type'];
        $rateC  = self::c($emp['base']);
        $dayHh  = self::hh($emp['hours_per_day'] ?? $cfg['std']);
        $rest   = $emp['rest'] ?? [7];
        $wd     = self::workingDays($run['start'], $rest);
        $final  = self::isFinal($run['type'], $run['start'], $run['end']);

        // ---- day by day
        $hoursHh = $otHh = $lateHh = $paidHh = $underHh = 0;
        $worked = [];
        $upTo = $coveredTo ?? '';
        foreach ($days as $date => $d) {
            if ($date < $run['start'] || $date > $run['end']) continue;
            $upTo = $coveredTo ?? max($upTo, $date);
            $h = self::hh($d['h'] ?? 0);
            $hoursHh += $h;
            $otHh    += self::hh($d['ot'] ?? 0);
            $lateHh  += self::hh($d['late'] ?? 0);
            if ($h <= 0) continue;
            $worked[$date] = true;
            if (array_key_exists('under', $d) && $d['under'] !== null && $d['under'] !== '') {
                $u = min(max(0, self::hh($d['under'])), $dayHh);
            } else {            // shortfall to the nearest whole hour, ties up - the app's documented fallback
                $u = min(self::div(max(0, $dayHh - $h), 100) * 100, $dayHh);
            }
            $underHh += $u;
            $paidHh  += $dayHh - $u;
        }
        $absent = $leaveN = $off = 0;
        foreach (self::dates($run['start'], $run['end']) as $date) {
            if (isset($worked[$date])) continue;
            if (!empty($days[$date]['off']) || in_array(self::dow($date), $rest, true)) { if ($date <= $upTo) $off++; continue; }
            if (in_array($date, $leave, true)) { $leaveN++; continue; }
            // a working day the file does not account for: unpaid on a salary however far the uploads got (the days to compute are the
            // days in the file); for a daily rate it is only shown, up to the last uploaded day
            if ($type !== 'daily' || $date <= $upTo) $absent++;
        }

        // ---- basic pay
        $absentDed = $underDed = 0;
        if ($type === 'daily') {
            $paidAll = $paidHh + $leaveN * $dayHh;
            $basic   = self::div($rateC * $paidAll, $dayHh);
            $underDed = self::div($rateC * $underHh, $dayHh);   // shown, not deducted again
        } else {
            $monthly   = $type === 'kinsenas' ? 2 * $rateC : $rateC;
            $share     = ['Semi-Monthly' => [1, 2], 'Weekly' => [12, 52], 'Monthly' => [1, 1]][$run['type']];
            $absentDed = self::div($absent * $monthly, $wd);
            $underDed  = self::div($underHh * $monthly, $wd * $dayHh);
            $basic     = max(0, self::div($monthly * $share[0], $share[1]) - $absentDed - $underDed);
        }
        if (($cfg['ot_method'] ?? 'flat') === 'labor_code') {
            // Labor Code Art. 87: the employee's own hourly rate × the multiplier (1.25 on an ordinary day).
            // One duty day's pay is $payC ÷ $payDen centavos; an hour is a duty-day's-hours part of it. Half a centavo rounds up.
            $payC   = $type === 'daily' ? $rateC : ($type === 'kinsenas' ? 2 * $rateC : $rateC);
            $payDen = $type === 'daily' ? 1 : $wd;
            $mult   = self::c($cfg['ot_mult'] ?? '1.25');                       // hundredths: 1.25 → 125
            $otC    = self::div($otHh * $payC * $mult, $dayHh * 100 * $payDen);
        } else {
            $otC   = self::div($otHh * self::c($cfg['ot_rate']), 100);         // the flat peso rate of Settings
        }
        $lateC = $cfg['per_day'] ? 0 : self::div($lateHh * self::c($cfg['late_rate']), 100);
        $gross = $basic + $otC - $lateC;

        // ---- the employee's own MONTHLY amounts (typed by the admin; 0 = none), taken per the schedule:
        //        first   the month's first run takes it all
        //        split   every run takes its share of the month (1/2 semi-monthly, 12/52 weekly), never more than is owed
        //        second  nothing until the month's last run
        //      The month's last run always takes whatever is still owed, so a month comes to exactly the amount typed.
        //      A contribution never goes below zero; only the last run may hand TAX back (an amount lowered mid-month).
        $basicM = $prev['basic'] + $basic;
        $amt    = ($emp['amt'] ?? []) + ['sss' => 0, 'ph' => 0, 'pi' => 0, 'tax' => 0];
        $share  = ['Semi-Monthly' => [1, 2], 'Weekly' => [12, 52], 'Monthly' => [1, 1]][$run['type']];
        $timingKey = ['sss' => 'sss', 'ph' => 'philhealth', 'pi' => 'pagibig', 'tax' => 'tax'];
        $take = function (string $k) use ($amt, $prev, $cfg, $timingKey, $share, $final): int {
            $owed   = $amt[$k] - $prev[$k];
            $timing = $cfg['timing'][$timingKey[$k]];
            if ($final || ($timing === 'first' && $cfg['runs_before'] === 0)) $c = $owed;
            elseif ($timing === 'split')                                       $c = min(self::div($amt[$k] * $share[0], $share[1]), $owed);
            else                                                               $c = 0;
            return ($k === 'tax' && $final && $cfg['refund']) ? $c : max(0, $c);
        };
        $sss = $take('sss');
        $ph  = $take('ph');
        $pi  = $take('pi');
        $tax = $take('tax');
        $contrib = $sss + $ph + $pi;

        // ---- the company's share, on what this run deducted: SSS twice the employee's, EC ₱10 (₱30 from a ₱750 SSS share, i.e. a
        //      ₱15,000 credit) once the month takes SSS and the step up when it crosses ₱750, PhilHealth equal, Pag-IBIG 2% (twice at ≤ ₱1,500 pay)
        $ecAt = fn(int $sssC) => $sssC <= 0 ? 0 : ($sssC >= 75000 ? 3000 : 1000);
        $er = [
            'sss' => 2 * $sss,
            'ec'  => $sss > 0 ? max(0, $ecAt($prev['sss'] + $sss) - $ecAt($prev['sss'])) : 0,
            'ph'  => $ph,
            'pi'  => $basicM <= 150000 ? 2 * $pi : $pi,
        ];
        $net = $gross - $tax - $contrib;

        return ['basic' => $basic, 'ot_late' => $otC - $lateC, 'gross' => $gross, 'sss' => $sss, 'ph' => $ph, 'pi' => $pi,
                'tax' => $tax, 'net' => $net, 'absent' => $absent, 'leave' => $leaveN, 'off' => $off,
                'absent_ded' => $absentDed, 'under_ded' => $underDed, 'under_hh' => $underHh, 'hours_hh' => $hoursHh,
                'ot_hh' => $otHh, 'late_hh' => $lateHh, 'final' => $final, 'er' => $er, 'working_days' => $wd];
    }

    /** Run several runs of one month in order, feeding each run what the earlier ones carried. */
    public static function month(array $emp, array $runs, array $days, array $leave, array $cfg, array $coveredTo = []): array
    {
        $prev = [];
        $out = [];
        foreach ($runs as $k => $run) {
            $r = self::run($emp, $run, $days, $leave, ['runs_before' => count($out)] + $cfg, $prev, $coveredTo[$k] ?? null);
            $out[$k] = $r;
            $prev = ['g' => ($prev['g'] ?? 0) + $r['gross'], 'basic' => ($prev['basic'] ?? 0) + $r['basic'],
                     'sss' => ($prev['sss'] ?? 0) + $r['sss'], 'ph' => ($prev['ph'] ?? 0) + $r['ph'],
                     'pi' => ($prev['pi'] ?? 0) + $r['pi'], 'tax' => ($prev['tax'] ?? 0) + $r['tax']];
        }
        return $out;
    }

    /* ============================================================ amount in words */

    /** "ONE THOUSAND TWO HUNDRED THIRTY-FOUR PESOS AND 56/100" - the wording the receipt uses, from integer centavos */
    public static function words(int $cents, bool $singularOne = false): string
    {
        $neg = $cents < 0;
        $pesos = intdiv(abs($cents), 100);
        $cts = abs($cents) % 100;
        // $singularOne: "ONE PESO" (correct, D-12) instead of the original's "ONE PESOS"
        $unit = ($singularOne && $pesos === 1) ? ' PESO AND ' : ' PESOS AND ';
        return ($neg ? 'MINUS ' : '') . self::spell($pesos) . $unit . str_pad((string)$cts, 2, '0', STR_PAD_LEFT) . '/100';
    }

    private static function spell(int $n): string
    {
        $ones = ['ZERO', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN', 'ELEVEN', 'TWELVE',
                 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];
        $tens = [2 => 'TWENTY', 3 => 'THIRTY', 4 => 'FORTY', 5 => 'FIFTY', 6 => 'SIXTY', 7 => 'SEVENTY', 8 => 'EIGHTY', 9 => 'NINETY'];
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        if ($n < 1000) return $ones[intdiv($n, 100)] . ' HUNDRED' . ($n % 100 ? ' ' . self::spell($n % 100) : '');
        foreach ([1000000000 => 'BILLION', 1000000 => 'MILLION', 1000 => 'THOUSAND'] as $u => $name) {
            if ($n >= $u) return self::spell(intdiv($n, $u)) . ' ' . $name . ($n % $u ? ' ' . self::spell($n % $u) : '');
        }
        return (string)$n;
    }
}
