<?php
/*
 * 08 — The pharmacy's own timesheets, end to end.
 *
 * Eight kinsenas timesheets (Feb–May 2026, 10–11 employees, daily-rate staff with rotating days off) are read the way the
 * browser reads them, uploaded cut-off by cut-off through the real endpoints, finalized, and checked:
 *   · gross pay against the PERIOD TOTAL the pharmacy's own spreadsheet formulas produced (to the centavo)
 *   · SSS / PhilHealth / Pag-IBIG / tax against the ledger, month by month
 *   · the forecast pages against the series these periods make
 *
 * Needs the files in E:\payroll\samp (override with PAYROLL_SAMPLES); skipped when they are not there. The files hold real
 * names and are NOT part of the repository — nothing from them is written into tests/.
 */

/** the sheet's code for a person: MICHELLE and MICH are one sheet */
function qa_sheet_key(string $code): string { return $code === 'MICHELLE' ? 'MICH' : $code; }

T::suite('08 · The pharmacy\'s real timesheets, Feb–May 2026', function () {
    $dir = getenv('PAYROLL_SAMPLES') ?: 'E:\\payroll\\samp';
    $files = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . 'TIMESHEET 0*.csv') ?: []) : [];
    sort($files);
    if (count($files) < 2) {
        T::test('pharmacy sample files', fn() => T::skip("no TIMESHEET *.csv files in $dir — set PAYROLL_SAMPLES to run the real-data suite"));
        return;
    }

    $S = new stdClass();
    // ---- read every file: sheet code -> rows
    $S->periods = [];          // [file => ['label','start','end','rows'=>[...], 'by'=>[code=>['name','rate','total','rows']]]]
    $names = [];               // canonical name per sheet code (the latest spelling)
    $variants = [];            // every spelling seen -> canonical
    foreach ($files as $f) {
        $rows = [];
        $fh = fopen($f, 'r');
        $head = fgetcsv($fh, 0, ',', '"', '');
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) if (count($r) === count($head)) $rows[] = array_combine($head, $r);
        fclose($fh);
        // the period comes from the file name ("02FEB 1-15 2026"); a stray date cell inside a sheet must not move it
        preg_match('/(\d{2})([A-Z]{3}) (\d+)-(\d+) (\d{4})/', basename($f), $m);
        $pStart = sprintf('%04d-%02d-%02d', $m[5], $m[1], $m[3]);
        $pEnd   = sprintf('%04d-%02d-%02d', $m[5], $m[1], $m[4]);
        $by = [];
        foreach ($rows as $r) {
            $code = qa_sheet_key($r['SHEET']);
            $by[$code]['name'] = $r['EMPLOYEE_NAME'];
            $by[$code]['rate'] = $r['DAILY_RATE'];
            $by[$code]['ot'] = $r['OT_PAY_PER_HOUR'];
            $by[$code]['total'] = $r['PERIOD_TOTAL'];
            $names[$code] = $r['EMPLOYEE_NAME'];
        }
        $S->periods[] = ['file' => basename($f, '.csv'), 'path' => $f, 'start' => $pStart, 'end' => $pEnd, 'by' => $by,
                         'label' => date('M j', strtotime($pStart)) . '-' . date('j, Y', strtotime($pEnd))];
    }
    foreach ($S->periods as $p) foreach ($p['by'] as $code => $b) $variants[$b['name']] = $names[$code];
    // the same person is spelled differently from sheet to sheet; Employee Management has one spelling
    $S->names = $names;
    $S->variants = $variants;

    T::test('set up the roster (one spelling per person; HASMIN works a 10-hour day) and a Semi-Monthly period for every file', function (T $t) use ($S) {
        Fixtures::reset();
        $S->emp = [];
        foreach ($S->names as $code => $name) {
            $S->emp[$code] = Fixtures::employee(['full_name' => $name, 'branch' => 'CATUBIG-MAIN', 'salary_type' => 'daily', 'base_salary' => '400.00',
                                                 'hours_per_day' => $code === 'JASH' ? '10.00' : null, 'rest_days' => '7']);
        }
        foreach ($S->periods as $i => $p) $S->periods[$i]['id'] = Fixtures::period($p['label'], $p['start'], $p['end']);
        $t->same(count($S->periods), count(array_filter(array_column($S->periods, 'id'))));
        $t->ok(count($S->emp) >= 11, count($S->emp) . ' people across the sheets');
    });

    /*
     * Where the app cannot (or must not) agree with the pharmacy's spreadsheet, and why. Anything NOT explained here must match.
     *   strays   a day row whose date cell is not a date (one sheet has 1899-12-31): the browser drops rows outside the pay period,
     *            so that day is not paid — the sheet still counts it
     *   ot       ROLLY's sheets (from 16 Mar) pay overtime at ₱62.50 an hour although their own OT_PAY_PER_HOUR column says ₱45; the app has one
     *            overtime rate for everybody (Settings) and pays ₱45
     */
    $sheetOt = ['ROLLY' => ['from' => '2026-03-16', 'rate' => '62.50']];

    foreach ($S->periods as $i => $p0) {
        T::test("{$p0['file']}: upload, then every employee's gross equals the spreadsheet's PERIOD TOTAL", function (T $t) use ($S, $i, $sheetOt) {
            $p = $S->periods[$i];
            // a raise takes effect with its period: set the rate the sheet states (earlier periods are already locked)
            foreach ($p['by'] as $code => $b) {
                getDB()->prepare('UPDATE employees SET base_salary = ? WHERE emp_id = ?')->execute([$b['rate'], $S->emp[$code]]);
            }
            Fixtures::setting('overtime_rate', array_values($p['by'])[0]['ot']);
            $table = BrowserSim::readFlatTable(BrowserSim::readCsv($p['path']));
            $payload = BrowserSim::dailyPayload($table, $p['start'], $p['end'], $S->variants);
            $S->periods[$i]['payload'] = $payload;
            $sent = count($payload);
            $t->ok($sent > 100, "$sent day rows");

            $r = Fixtures::days($p['id'], $payload);
            $t->same(true, $r['success'] ?? null, json_encode($r));
            $t->same([], $r['unmatched'] ?? [], 'every name in the sheet matched an employee');
            $t->same([], $r['warnings'], 'no PHP warnings');
            $S->periods[$i]['rows'] = Fixtures::payroll($p['id']);

            // rows of the sheet the browser could not place in the period (e.g. a corrupt date cell) and the overtime hours per person
            $strays = [];
            foreach ($table['rows'] as $row) {
                $d = BrowserSim::toDate($row[0]);
                if (($d < $p['start'] || $d > $p['end']) && (float)$row[3] > 0) $strays[$S->variants[$row[1]] ?? $row[1]][] = [$row[0], (float)$row[3]];
            }
            $otHours = [];
            foreach ($payload as $row) $otHours[$row['emp_name']] = ($otHours[$row['emp_name']] ?? 0) + $row['overtime_hours'];

            $diff = [];
            $explained = [];
            foreach ($p['by'] as $code => $b) {
                $row = $S->periods[$i]['rows'][$S->emp[$code]] ?? null;
                $t->ok($row !== null, "{$b['name']} has a payroll line");
                $name = $S->names[$code];
                $expected = Ledger::c($b['total']);
                $why = [];
                if (!empty($strays[$name])) { $expected -= count($strays[$name]) * Ledger::c($b['rate']); $why[] = count($strays[$name]) . ' day(s) with a date cell the browser cannot read (' . $strays[$name][0][0] . ') are not paid'; }
                if (isset($sheetOt[$code]) && $p['start'] >= $sheetOt[$code]['from'] && ($otHours[$name] ?? 0) > 0) {
                    $expected -= (int)round(($otHours[$name]) * (Ledger::c($sheetOt[$code]['rate']) - Ledger::c($b['ot'])));
                    $why[] = sprintf('%g h of overtime: the sheet pays ₱%s, the app ₱%s', $otHours[$name], $sheetOt[$code]['rate'], $b['ot']);
                }
                $t->checks++;
                $engine = Ledger::c($row['gross_pay']);
                if ($engine !== $expected) $diff[] = sprintf('%s: sheet ₱%s%s, system ₱%s — expected ₱%s (%+.2f)', $b['name'], Ledger::fmt(Ledger::c($b['total'])),
                    $why ? ' (' . implode('; ', $why) . ')' : '', Ledger::fmt($engine), Ledger::fmt($expected), ($engine - $expected) / 100);
                elseif ($why) $explained[] = $b['name'] . ': ' . implode('; ', $why);
            }
            if ($explained) fwrite(STDOUT, "         \033[2m(info) explained difference(s) from the sheet — " . implode(' | ', $explained) . "\033[0m\n");
            $t->same([], $diff, count($p['by']) . ' employees compared');

            // lock it, as the admin does, so the next period's rate change cannot reach back
            $f = Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $p['id']]);
            $t->same(true, $f['json']['success'] ?? null);
        });
    }

    T::test('contributions and tax, month by month, equal the ledger (SSS 5% on the month\'s pay, PhilHealth 2.5% / Pag-IBIG 2% on the month, once)', function (T $t) use ($S) {
        $byMonth = [];
        foreach ($S->periods as $p) $byMonth[substr($p['start'], 0, 7)][] = $p;
        $cfg = ['ot_rate' => '45', 'refund' => AppCopy::hasFixes(), 'prehire' => AppCopy::hasFixes(), 'timing' => ['sss' => 'split', 'philhealth' => 'second', 'pagibig' => 'second']];
        $bad = [];
        foreach ($byMonth as $ym => $runs) {
            foreach ($S->emp as $code => $empId) {
                $prev = [];
                foreach ($runs as $k => $p) {
                    if (!isset($p['by'][$code])) continue;
                    $days = [];
                    foreach ($p['payload'] as $r) {
                        if ($S->variants[$r['emp_name']] !== $S->names[$code] && $r['emp_name'] !== $S->names[$code]) continue;
                        $days[$r['att_date']] = ['h' => sprintf('%.2f', $r['hours_worked']), 'ot' => sprintf('%.2f', $r['overtime_hours']), 'late' => sprintf('%.2f', $r['late_hours']),
                                                 'under' => $r['undertime_hours'] === null ? null : sprintf('%.2f', $r['undertime_hours']), 'off' => (bool)$r['day_off']];
                    }
                    $emp = ['type' => 'daily', 'base' => $p['by'][$code]['rate'], 'hours_per_day' => $code === 'JASH' ? '10.00' : null, 'rest' => [7], 'hired' => null, 'sss' => 1, 'ph' => 1, 'pi' => 1];
                    $run = ['start' => $p['start'], 'end' => $p['end'], 'type' => 'Semi-Monthly'];
                    $exp = Ledger::run($emp, $run, $days, [], $cfg, $prev);
                    $row = $p['rows'][$empId];
                    $t->checks += 6;
                    foreach (['sss' => 'sss', 'philhealth' => 'ph', 'pagibig' => 'pi', 'withholding_tax' => 'tax', 'net_pay' => 'net', 'gross_pay' => 'gross'] as $col => $key) {
                        // a known sheet error changes gross (and so the rest); the ledger is built from the day rows, not from the sheet, so it still applies
                        if (Ledger::c($row[$col]) !== $exp[$key] && count($bad) < 6) $bad[] = "{$p['file']} {$S->names[$code]} $col: ledger ₱" . Ledger::fmt($exp[$key]) . ", system ₱{$row[$col]}";
                    }
                    $prev = ['g' => ($prev['g'] ?? 0) + $exp['gross'], 'basic' => ($prev['basic'] ?? 0) + $exp['basic'], 'sss' => ($prev['sss'] ?? 0) + $exp['sss'],
                             'ph' => ($prev['ph'] ?? 0) + $exp['ph'], 'pi' => ($prev['pi'] ?? 0) + $exp['pi'], 'tax' => ($prev['tax'] ?? 0) + $exp['tax']];
                }
            }
        }
        $t->same([], $bad);
    });

    T::test('the month closes: per person, SSS = 5% of the credit on the month\'s gross; PhilHealth and Pag-IBIG were taken once; nobody is taxed under ₱20,833', function (T $t) use ($S) {
        $byMonth = [];
        foreach ($S->periods as $p) $byMonth[substr($p['start'], 0, 7)][] = $p;
        $people = $over = 0;
        foreach ($byMonth as $ym => $runs) {
            foreach ($S->emp as $code => $empId) {
                $g = $b = $sss = $ph = $pi = $tax = 0;
                $n = 0;
                foreach ($runs as $p) {
                    if (!isset($p['rows'][$empId])) continue;
                    $r = $p['rows'][$empId]; $n++;
                    $g += Ledger::c($r['gross_pay']); $b += Ledger::c($r['gross_pay']) - Ledger::c($r['ot_late_adj']);
                    $sss += Ledger::c($r['sss']); $ph += Ledger::c($r['philhealth']); $pi += Ledger::c($r['pagibig']); $tax += Ledger::c($r['withholding_tax']);
                }
                if ($n === 0) continue;
                if ($n < count($runs)) continue;                 // someone who left or joined mid-month is not "closed"
                $people++;
                $t->money(Ledger::fmt(Ledger::sssEe($g)), $sss / 100, "$ym {$S->names[$code]} SSS on ₱" . Ledger::fmt($g));
                $t->money(Ledger::fmt(Ledger::philhealthEe($b)), $ph / 100, "$ym {$S->names[$code]} PhilHealth");
                $t->money(Ledger::fmt(Ledger::pagibigEe($b)), $pi / 100, "$ym {$S->names[$code]} Pag-IBIG");
                $t->money('0.00', $tax / 100, "$ym {$S->names[$code]} withholding tax (monthly taxable ₱" . Ledger::fmt($g - $sss - $ph - $pi) . ')');
            }
        }
        $t->ok($people >= 20, "$people person-months closed");
    });

    T::test('REPORT: what the pharmacy\'s sheets deduct versus what the rules require (information, not an assertion)', function (T $t) use ($S) {
        $sheetSss = $sheetPh = $sheetPi = $n = 0;
        $appSss = $appPh = $appPi = 0;
        foreach ($S->periods as $p) {
            $fh = fopen($p['path'], 'r');
            $head = fgetcsv($fh, 0, ',', '"', '');
            $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
            $seen = [];
            while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                if (count($r) !== count($head)) continue;
                $r = array_combine($head, $r);
                if (isset($seen[$r['SHEET']])) continue;
                $seen[$r['SHEET']] = true;
                $n++;
                $sheetSss += $r['SSS'] !== '' ? Ledger::c($r['SSS']) : 0;
                $sheetPh += $r['PHILHEALTH'] !== '' ? Ledger::c($r['PHILHEALTH']) : 0;
            }
            fclose($fh);
            foreach ($p['rows'] as $row) { $appSss += Ledger::c($row['sss']); $appPh += Ledger::c($row['philhealth']); $appPi += Ledger::c($row['pagibig']); }
        }
        fwrite(STDOUT, sprintf("         \033[2m(info) %d employee-cut-offs. SSS deducted: sheets ₱%s, system ₱%s · PhilHealth: sheets ₱%s, system ₱%s · Pag-IBIG: sheets ₱0.00, system ₱%s\033[0m\n",
            $n, number_format($sheetSss / 100, 2), number_format($appSss / 100, 2), number_format($sheetPh / 100, 2), number_format($appPh / 100, 2), number_format($appPi / 100, 2)));
        $t->ok(true);
    });

    T::test('forecast data for these 8 cut-offs: per-period totals equal the sums of the finalized lines', function (T $t) use ($S) {
        $res = Http::call('api/forecast-data.php', ['method' => 'GET', 'session' => Http::admin()]);
        $t->same(8, $res['json']['count'] ?? null);
        $series = [];
        foreach ($res['json']['data'] as $i => $d) {
            $sum = 0;
            foreach ($S->periods[$i]['rows'] as $r) $sum += Ledger::c($r['net_pay']);
            $t->money(Ledger::fmt($sum), $d['total_net'], "period {$d['label']} net");
            $series[] = $d['total_net'];
        }
        $t->same(['2026-02-01', '2026-02-16', '2026-03-01', '2026-03-16', '2026-04-01', '2026-04-16', '2026-05-01', '2026-05-16'], array_column($res['json']['data'], 'period_start'));
        $S->series = $series;
    });

    T::test('the dashboard\'s "predicted next payroll" uses the latest six cut-offs of this history (Mar 16 → May 16)', function (T $t) use ($S) {
        $html = Http::page('dashboard.php')['body'];
        $d = qa_dashboard_data($html);
        $lastSix = array_slice($S->series, -6);
        fwrite(STDOUT, sprintf("         \033[2m(info) dashboard shows ₱%s from the series %s · independent regression on the latest six: ₱%s\033[0m\n",
            number_format($d['predicted'], 2), json_encode(array_map('intval', $d['net'])), number_format(qa_next_by_regression($lastSix), 2)));
        $t->same(6, $d['n']);
        $t->money(sprintf('%.2f', qa_next_by_regression($lastSix)), $d['predicted'], 'prediction from the latest six');
    }, ['defect' => 'D-04']);
});
