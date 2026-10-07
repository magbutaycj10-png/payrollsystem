<?php
/*
 * 01 — Statutory tables: BIR Annex E withholding tax, SSS, PhilHealth, Pag-IBIG, amount in words.
 *
 * Playing: the BIR examiner (are the tables the published ones, applied the published way?),
 * the accountant (is every centavo right?) and QA (boundaries, sweeps, rounding).
 */
require_once AppCopy::root() . '/includes/bir-print.php';

/** pesos float from the engine -> centavos, failing on sub-centavo noise */
function qa_cents(float $v): int { return (int)round($v * 100); }

T::suite('01 · Statutory tables', function () {

    /* ================================================================== BIR */

    T::test('BIR: the app\'s Annex E tables are the published ones (4 tables × 5 brackets)', function (T $t) {
        foreach (Ledger::BIR as $name => $rows) {
            $app = PH_RULES['bir']['tables'][$name] ?? null;
            $t->ok($app !== null, "table $name exists in PH_RULES");
            $t->same(count($rows), count($app), "$name: number of brackets");
            foreach ($rows as $i => [$over, $base, $pct]) {
                [$aOver, $aBase, $aRate] = $app[$i];
                $t->same($over, qa_cents((float)$aOver), "$name bracket " . ($i + 1) . ': threshold');
                $t->same($base, qa_cents((float)$aBase), "$name bracket " . ($i + 1) . ': prescribed tax');
                $t->same($pct, (int)round($aRate * 100), "$name bracket " . ($i + 1) . ': rate');
            }
        }
    });

    T::test('BIR: no jump in tax at any bracket boundary (each bracket starts where the one below ends)', function (T $t) {
        foreach (Ledger::BIR as $name => $rows) {
            // rows are listed top bracket first; walk upward
            $rows = array_reverse($rows);
            for ($i = 1; $i < count($rows); $i++) {
                [$lo, $loBase, $loPct] = $rows[$i - 1];
                [$hi, $hiBase]         = $rows[$i];
                $atBoundary = $loBase + intdiv(($hi - $lo) * $loPct, 100);
                $t->ok(abs($atBoundary - $hiBase) <= 1, sprintf('%s: at ₱%s the lower bracket gives ₱%s but the next one starts at ₱%s',
                    $name, Ledger::fmt($hi), Ledger::fmt($atBoundary), Ledger::fmt($hiBase)));
            }
        }
    });

    T::test('BIR: hand-computed examples from the regulation (40 points)', function (T $t) {
        $points = [
            'monthly' => [[20833, '0.00'], [20833.01, '0.00'], [25000, '625.05'], [33333, '1875.00'], [40000, '3208.40'], [66667, '8541.80'],
                          [100000, '16875.05'], [166667, '33541.80'], [200000, '43541.70'], [666667, '183541.80'], [1000000, '300208.35']],
            'semi'    => [[10417, '0.00'], [15000, '687.45'], [16667, '937.50'], [25000, '2604.10'], [33333, '4270.70'], [50000, '8437.45'],
                          [83333, '16770.70'], [100000, '21770.80'], [333333, '91770.70'], [400000, '115104.15']],
            'weekly'  => [[4808, '0.00'], [6000, '178.80'], [7692, '432.60'], [10000, '894.20'], [15385, '1971.20'], [20000, '3124.95'],
                          [38462, '7740.45'], [50000, '11201.85'], [153846, '42355.65'], [200000, '58509.55']],
            'daily'   => [[685, '0.00'], [1000, '47.25'], [1096, '61.65'], [2000, '242.45'], [2192, '280.85'], [4000, '732.85'],
                          [5479, '1102.60'], [10000, '2458.90'], [21918, '6034.30'], [30000, '8863.00']],
        ];
        foreach ($points as $table => $list) foreach ($list as [$taxable, $expected]) {
            $t->money($expected, birTax((float)$taxable, $table), "$table table, taxable ₱$taxable");
        }
    });

    T::test('BIR: exempt up to the threshold, 15% starts just above it', function (T $t) {
        foreach (['monthly' => 20833, 'semi' => 10417, 'weekly' => 4808, 'daily' => 685] as $table => $limit) {
            $t->money(0, birTax((float)$limit, $table), "$table: at the threshold");
            $t->money(0, birTax(0.0, $table), "$table: zero pay");
            $t->money(0, birTax($limit - 0.01, $table), "$table: just below");
            $t->money(0, birTax($limit + 0.03, $table), "$table: 3 centavos above rounds to nothing");
            $t->money(Ledger::tax($table, $limit * 100 + 1000), birTax($limit + 10.0, $table), "$table: ₱10 above");
        }
    });

    /** true when the exact tax (excess × rate) lands on a half centavo, e.g. ₱0.30 × 15% = 4.5 centavos */
    $isTie = function (string $table, int $c): bool {
        foreach (Ledger::BIR[$table] as [$over, , $pct]) if ($c > $over) return (($c - $over) * $pct) % 100 === 50;
        return false;
    };

    T::test('BIR: engine equals the ledger on every centavo within ₱3 of each threshold (apart from half-centavo ties)', function (T $t) use ($isTie) {
        foreach (Ledger::BIR as $table => $rows) foreach ($rows as [$over]) {
            for ($c = $over - 300; $c <= $over + 300; $c++) {
                $t->checks++;
                if ($isTie($table, $c)) continue;
                $exp = Ledger::tax($table, $c);
                $act = qa_cents(birTax($c / 100, $table));
                if ($exp !== $act) $t->money($exp, $act / 100, "$table at taxable ₱" . Ledger::fmt($c));
            }
        }
    });

    T::test('BIR: engine equals the ledger on 60,000 random amounts (apart from half-centavo ties)', function (T $t) use ($isTie) {
        mt_srand(20261007);
        $max = ['daily' => 4000000, 'weekly' => 25000000, 'semi' => 60000000, 'monthly' => 120000000];
        $bad = [];
        foreach ($max as $table => $hi) {
            for ($i = 0; $i < 15000; $i++) {
                $c = mt_rand(0, $hi);
                $t->checks++;
                if ($isTie($table, $c)) continue;
                $exp = Ledger::tax($table, $c);
                $act = qa_cents(birTax($c / 100, $table));
                if ($exp !== $act && count($bad) < 5) $bad[] = sprintf('%s ₱%s: ledger ₱%s, app ₱%s', $table, Ledger::fmt($c), Ledger::fmt($exp), Ledger::fmt($act));
            }
        }
        $t->same([], $bad, 'amounts where the app is off');
    });

    T::test('BIR: a tax that lands exactly on a half centavo always rounds up (₱0.045 → ₱0.05), like every other payslip figure', function (T $t) use ($isTie) {
        $bad = [];
        $total = 0;
        foreach (Ledger::BIR as $table => $rows) foreach ($rows as [$over]) {
            for ($c = $over - 300; $c <= $over + 3000; $c++) {
                if (!$isTie($table, $c)) continue;
                $total++;
                $exp = Ledger::tax($table, $c);
                $act = qa_cents(birTax($c / 100, $table));
                if ($exp !== $act) $bad[] = sprintf('%s taxable ₱%s: exact tax is a half centavo → ₱%s, the app gives ₱%s', $table, Ledger::fmt($c), Ledger::fmt($exp), Ledger::fmt($act));
            }
        }
        $t->checks += $total;
        $t->same([], array_slice($bad, 0, 4), count($bad) . " of $total tie cases rounded the wrong way (first 4 shown)");
    }, ['defect' => 'D-01']);

    T::test('BIR: tables agree with the annual TRAIN schedule ÷ periods per year (within ₱0.10)', function (T $t) {
        mt_srand(7);
        foreach (['monthly' => 12, 'semi' => 24, 'weekly' => 52, 'daily' => 365] as $table => $periods) {
            for ($i = 0; $i < 3000; $i++) {
                $c = mt_rand(0, $table === 'daily' ? 3000000 : 80000000);
                $diff = abs(birTax($c / 100, $table) - Ledger::annualTax($c * $periods) / $periods / 100);
                $t->ok($diff <= 0.10, sprintf('%s: taxable ₱%s differs from annual÷%d by ₱%.2f', $table, Ledger::fmt($c), $periods, $diff));
            }
        }
    });

    T::test('BIR: tax never falls when pay rises, never exceeds 35% of pay, never negative', function (T $t) {
        foreach (['monthly', 'semi', 'weekly', 'daily'] as $table) {
            $prev = 0.0;
            for ($c = 0; $c <= 70000000; $c += 7919) {            // ~₱79 steps up to ₱700k
                $tax = birTax($c / 100, $table);
                $t->ok($tax >= $prev - 1e-9, "$table: tax fell at ₱" . Ledger::fmt($c));
                $t->ok($tax <= $c / 100 * 0.35 + 0.01, "$table: tax above 35% at ₱" . Ledger::fmt($c));
                $prev = $tax;
            }
        }
    });

    /* ================================================================== SSS */

    T::test('SSS: monthly salary credit at the published boundaries (₱5,250 → 5,500 … ₱34,750 → 35,000)', function (T $t) {
        $cases = [
            [0, 5000], [1, 5000], [5249.99, 5000], [5250, 5500], [5499.99, 5500], [5749.99, 5500], [5750, 6000],
            [9999.99, 10000], [10249.99, 10000], [10250, 10500], [14749.99, 14500], [14750, 15000], [19999.99, 20000],
            [34749.99, 34500], [34750, 35000], [35000, 35000], [100000, 35000], [1000000, 35000],
        ];
        foreach ($cases as [$comp, $msc]) $t->money((string)$msc, sssCredit((float)$comp), "compensation ₱$comp");
    });

    T::test('SSS: employee 5%, employer 10% — together 15% of the credit', function (T $t) {
        foreach ([5000, 6000, 12000, 17500, 20000, 27500, 35000] as $msc) {
            $c = $msc * 100;
            $t->money(Ledger::sssEe($c), sssMonthly((float)$msc), "employee share on ₱$msc");
            $t->same(Ledger::sssEe($c) + Ledger::sssEr($c), intdiv($c * 15, 100), "EE+ER is 15% of ₱$msc");
        }
        $t->money('250.00', sssMonthly(1000.0), 'minimum employee contribution is ₱250');
        $t->money('1750.00', sssMonthly(80000.0), 'maximum employee contribution is ₱1,750');
        $t->money('875.00', sssMonthly(17450.0), 'the pharmacy example: ₱17,450 → credit ₱17,500 → ₱875');
    });

    T::test('SSS: engine equals the ledger every 25 centavos from ₱0 to ₱40,000, and at every boundary ±2 centavos', function (T $t) {
        $bad = [];
        $check = function (int $c) use (&$bad, $t) {
            $t->checks++;
            $exp = Ledger::sssEe($c);
            $act = qa_cents(sssMonthly($c / 100));
            $expMsc = Ledger::sssMsc($c);
            $actMsc = qa_cents(sssCredit($c / 100));
            if (($exp !== $act || $expMsc !== $actMsc) && count($bad) < 5) $bad[] = sprintf('₱%s: ledger credit ₱%s / share ₱%s, app ₱%s / ₱%s',
                Ledger::fmt($c), Ledger::fmt($expMsc), Ledger::fmt($exp), Ledger::fmt($actMsc), Ledger::fmt($act));
        };
        for ($c = 0; $c <= 4000000; $c += 25) $check($c);
        for ($step = 525000; $step <= 3475000; $step += 50000) for ($d = -2; $d <= 2; $d++) $check($step + $d);
        $t->same([], $bad);
    });

    /* ================================================================== PhilHealth */

    T::test('PhilHealth: 2.5% of basic pay, floor ₱10,000 (₱250), ceiling ₱100,000 (₱2,500)', function (T $t) {
        $cases = [[0, '250.00'], [9999.99, '250.00'], [10000, '250.00'], [10000.01, '250.00'], [13333.33, '333.33'], [17360, '434.00'],
                  [25000, '625.00'], [50000, '1250.00'], [99999.99, '2500.00'], [100000, '2500.00'], [250000, '2500.00']];
        foreach ($cases as [$basic, $ee]) $t->money($ee, philhealthMonthly((float)$basic), "basic ₱$basic");
    });

    T::test('PhilHealth: engine equals the ledger every ₱0.37 from ₱0 to ₱120,000', function (T $t) {
        $bad = [];
        for ($c = 0; $c <= 12000000; $c += 37) {
            $t->checks++;
            $exp = Ledger::philhealthEe($c);
            $act = qa_cents(philhealthMonthly($c / 100));
            if ($exp !== $act && count($bad) < 5) $bad[] = sprintf('basic ₱%s: ledger ₱%s, app ₱%s', Ledger::fmt($c), Ledger::fmt($exp), Ledger::fmt($act));
        }
        $t->same([], $bad);
    });

    /* ================================================================== Pag-IBIG */

    T::test('Pag-IBIG: 1% up to ₱1,500, 2% above, on at most ₱10,000 (₱200 cap)', function (T $t) {
        $cases = [[0, '0.00'], [1000, '10.00'], [1500, '15.00'], [1500.01, '30.00'], [2500, '50.00'], [9999.99, '200.00'], [10000, '200.00'], [50000, '200.00']];
        foreach ($cases as [$pay, $ee]) $t->money($ee, pagibigMonthly((float)$pay), "monthly pay ₱$pay");
    });

    T::test('Pag-IBIG: engine equals the ledger every ₱0.23 from ₱0 to ₱15,000 and at the ₱1,500 step', function (T $t) {
        $bad = [];
        for ($c = 0; $c <= 1500000; $c += 23) {
            $t->checks++;
            $exp = Ledger::pagibigEe($c);
            $act = qa_cents(pagibigMonthly($c / 100));
            if ($exp !== $act && count($bad) < 5) $bad[] = sprintf('pay ₱%s: ledger ₱%s, app ₱%s', Ledger::fmt($c), Ledger::fmt($exp), Ledger::fmt($act));
        }
        $t->same([], $bad);
    });

    /* ================================================================== amount in words */

    T::test('Amount in words: the wording a receipt needs, for classic cases', function (T $t) {
        $t->same('TWELVE THOUSAND THREE HUNDRED FORTY-FIVE PESOS AND 67/100', amountInWords(12345.67));
        $t->same('ZERO PESOS AND 00/100', amountInWords(0.0));
        $t->same('ONE THOUSAND PESOS AND 00/100', amountInWords(1000.0));
        $t->same('NINETEEN PESOS AND 99/100', amountInWords(19.99));
        $t->same('ONE HUNDRED ONE PESOS AND 01/100', amountInWords(101.01));
        $t->same('ONE MILLION PESOS AND 00/100', amountInWords(1000000.0));
        $t->same('MINUS FIVE HUNDRED PESOS AND 25/100', amountInWords(-500.25));
    });

    T::test('Amount in words: every centavo from ₱0.00 to ₱2,000.00 and 20,000 random amounts match the ledger (no floating-point slip)', function (T $t) {
        $bad = [];
        $check = function (int $c) use (&$bad, $t) {
            $t->checks++;
            $exp = Ledger::words($c, AppCopy::hasFixes());   // the fixed receipts say "ONE PESO" (D-12)
            $act = amountInWords($c / 100);
            if ($exp !== $act && count($bad) < 5) $bad[] = "₱" . Ledger::fmt($c) . ": expected “$exp”, got “$act”";
        };
        for ($c = 0; $c <= 200000; $c++) $check($c);
        mt_srand(11);
        for ($i = 0; $i < 20000; $i++) $check(mt_rand(0, 99999999999));
        $t->same([], $bad);
    });

    T::test('Amount in words: "ONE PESOS" is wrong wording for a receipt (should be "ONE PESO")', function (T $t) {
        $t->contains('ONE PESO AND', amountInWords(1.0));
        $t->notContains('ONE PESOS', amountInWords(1.0));
    }, ['defect' => 'D-12']);
});
