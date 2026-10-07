<?php
/*
 * 07 — JavaScript, run in headless Edge: the forecast models (assets/js/forecast.js) and the timesheet
 * parsing (assets/js/attendance-formats.js + the value parsers in attendance-upload.js).
 *
 * The page loads the ORIGINAL scripts from the app (not copies), runs the checks, and the results come back
 * as JSON. The parsing half also feeds identical inputs to BrowserSim — the PHP port the end-to-end suites use —
 * and fails if the port and the original ever disagree.
 */

/** structural comparison where numbers compare with a tolerance and everything else strictly */
function qa_same_value($a, $b, string $path, array &$diffs): void
{
    if (count($diffs) >= 5) return;
    if (is_float($b) && is_nan($b)) $b = null;      // JSON has no NaN: the browser's NaN comes back as null
    if (is_numeric($a) && is_numeric($b) && !is_string($a) && !is_string($b)) {
        if (abs((float)$a - (float)$b) > 1e-6) $diffs[] = "$path: JS " . json_encode($a) . ' vs PHP ' . json_encode($b);
        return;
    }
    if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b)) { $diffs[] = "$path: JS has " . count($a) . ' items, PHP ' . count($b); return; }
        foreach ($a as $k => $v) qa_same_value($v, $b[$k] ?? null, "$path/$k", $diffs);
        return;
    }
    if ($a !== $b) $diffs[] = "$path: JS " . json_encode($a) . ' vs PHP ' . json_encode($b);
}

T::suite('07 · JavaScript (headless Edge): forecast models & timesheet parsing', function () {
    if (!Edge::find()) {
        T::test('JavaScript tests', fn() => T::skip('no Microsoft Edge / Chrome found — set PAYROLL_TEST_EDGE to a browser path'));
        return;
    }
    $root = AppCopy::root();

    // ---- inputs for the parity half
    $hours = ['08:29', '8:5', '00:00', '12:30:30', '-1:30', '', null, 8.5, '8.5', 'abc', '1,234.5', '24:00', '100:15', '7:07:07', 0, '0:45',
              // what a typo looks like: the original reads these as hundreds of hours, the audit-fixed upload as "unreadable" (NaN)
              '08:60', '8h30m', '1,5', '8:5:3:1', 'ABSENT', '-', 'N/A', '1,160', '.5', '8.', '-1.25', '12abc'];
    $serial = (int)((strtotime('2026-04-05 UTC') - strtotime('1899-12-30 UTC')) / 86400);
    $dates = ['2026-04-05', '4/5/2026', '04-05-2026', '2026/04/05', $serial, '', null, 'April 5, 2026', '12/31/2026', 'not a date'];
    $headers = ['TOTAL NO OF HOURS WORKED', 'Under Time Hours ', 'OVER_TIME-HOURS', 'Hours (worked)', 'Gross Pay #', null, '  EMPLOYEE   NAME  ', 'Time In ', 'UNDERTIME_DEDUCTIONS'];

    // the April sheet written as a CSV, read back, as a grid of strings
    $path = TestDb::tmp() . DIRECTORY_SEPARATOR . 'parity-april.csv';
    qa_csv(qa_sheet(), $path);
    $grids = ['synthetic April sheet' => BrowserSim::readCsv($path)];
    @unlink($path);
    // a day-by-day export in a different column order, with OFF / ABSENT remarks and decimal hours
    $grids['decimal hours + remarks'] = [
        ['Date', 'Employee Name', 'Hours', 'Late', 'OT', 'Remarks'],
        ['2026-04-01', 'A B', '8.5', '0.25', '0.5', 'DUTY'], ['2026-04-02', 'A B', '', '', '', 'OFF'], ['2026-04-03', 'A B', '', '', '', 'ABSENT'],
        ['2026-04-04', 'A B', '7', '', '', ''], ['', '', '', '', '', ''], ['TOTAL', '', '', '', '', ''],
    ];
    $samples = getenv('PAYROLL_SAMPLES') ?: 'E:\\payroll\\samp';
    $real = 0;
    if (is_dir($samples)) foreach (glob($samples . DIRECTORY_SEPARATOR . 'TIMESHEET *.csv') ?: [] as $f) {
        $grids['pharmacy sample ' . preg_replace('/^TIMESHEET |\.csv$/', '', basename($f))] = BrowserSim::readCsv($f);
        $real++;
    }

    // two pages: forecast.js and attendance-upload.js both declare a top-level MONTHS, which the app never loads together
    $show = function (array $res, string $what) {
        foreach ($res['tests'] as $jt) {
            T::test('JS · ' . $jt['name'], function (T $t) use ($jt) {
                $t->checks++;
                if (!$jt['ok']) throw new AssertionFailed($jt['msg']);
            }, $jt['defect'] ? ['defect' => $jt['defect']] : []);
        }
        if ($res['errors']) {
            T::test("JS · $what load without uncaught errors (the page stubs the DOM they expect)", function (T $t) use ($res) {
                $t->same([], $res['errors']);
            });
        }
    };
    try {
        $show(Edge::run(Edge::page([$root . '/assets/js/forecast.js'], (string)file_get_contents(__DIR__ . '/../js/forecast.test.js')), 60000), 'forecast.js');
        $res = Edge::run(Edge::page([$root . '/assets/js/attendance-upload.js', $root . '/assets/js/attendance-formats.js'],
            (string)file_get_contents(__DIR__ . '/../js/parsing.test.js'),
            ['hours' => $hours, 'dates' => $dates, 'headers' => $headers, 'grids' => $grids]), 60000);
    } catch (Throwable $e) {
        T::test('JavaScript tests', function () use ($e) { throw new AssertionFailed('could not run the browser tests: ' . $e->getMessage()); });
        return;
    }
    $show($res, 'the upload scripts');

    // ---- the forecast PAGE itself: what PHP renders, with the real forecast.js running on the real API data (Chart.js replaced by a recorder)
    if (AppCopy::hasFixes()) {
        try {
            qa_sql_dataset();                                              // nine months of correctly computed payroll: 17 pay periods
            $page = Http::page('forecast.php');
            $api  = Http::call('api/forecast-data.php', ['method' => 'GET', 'session' => Http::admin()]);
            $stubs = 'HTMLCanvasElement.prototype.getContext = function () { return {}; };'
                   . 'window.__API = ' . json_encode($api['json']) . ';'
                   . 'window.fetch = function () { return Promise.resolve({ json: function () { return Promise.resolve(window.__API); } }); };'
                   . 'window.Chart = class { constructor(ctx, cfg) { (window.__charts = window.__charts || []).push(cfg); } destroy() { window.__destroyed = (window.__destroyed || 0) + 1; } };';
            $html = Edge::wrap($page['body'], (string)file_get_contents(__DIR__ . '/../js/forecast-page.test.js'), ['api' => $api['json']], [
                'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js' => $stubs,
                'assets/js/forecast.js' => (string)file_get_contents($root . '/assets/js/forecast.js'),
            ]);
            $show(Edge::run($html, 90000), 'the forecast page');
        } catch (Throwable $e) {
            T::test('JS · the forecast page in a browser', function () use ($e) { throw new AssertionFailed('could not run the page test: ' . $e->getMessage()); });
        }
    }

    // ---- parity: original JavaScript vs the PHP port
    $out = $res['out'];
    T::test('parity · toHours: the PHP port agrees with the original for ' . count($hours) . ' inputs', function (T $t) use ($hours, $out) {
        $diffs = [];
        foreach ($hours as $i => $v) {
            $t->checks++;
            qa_same_value($out['hours'][$i], BrowserSim::toHours($v), 'toHours(' . json_encode($v) . ')', $diffs);
        }
        $t->same([], $diffs);
    });
    T::test('parity · toDate: ISO, M/D/YYYY, Excel serials, long dates, blanks', function (T $t) use ($dates, $out) {
        $diffs = [];
        foreach ($dates as $i => $v) {
            $t->checks++;
            if ($out['dates'][$i] !== BrowserSim::toDate($v)) $diffs[] = 'toDate(' . json_encode($v) . '): JS ' . json_encode($out['dates'][$i]) . ' vs PHP ' . json_encode(BrowserSim::toDate($v));
        }
        $t->same([], $diffs);
    });
    T::test('parity · normHeader', function (T $t) use ($headers, $out) {
        $diffs = [];
        foreach ($headers as $i => $v) {
            $t->checks++;
            if ($out['headers'][$i] !== BrowserSim::normHeader($v)) $diffs[] = 'normHeader(' . json_encode($v) . '): JS ' . json_encode($out['headers'][$i]) . ' vs PHP ' . json_encode(BrowserSim::normHeader($v));
        }
        $t->same([], $diffs);
    });
    foreach ($grids as $name => $grid) {
        T::test("parity · readFlatTable on the $name: every day row the browser would build equals the port's", function (T $t) use ($name, $grid, $out) {
            $js = $out['tables'][$name] ?? null;
            $t->ok($js !== null, 'the original produced a table');
            $php = BrowserSim::readFlatTable($grid);
            $t->same($js['kind'], $php['kind'], 'table kind');
            $diffs = [];
            qa_same_value($js['rows'], $php['rows'], 'rows', $diffs);
            $t->checks += count($php['rows']);
            $t->same([], $diffs, count($php['rows']) . ' day rows compared');
        });
    }
    if (!$real) {
        T::test('parity · the pharmacy\'s real timesheets', fn() => T::skip("no TIMESHEET *.csv in $samples (set PAYROLL_SAMPLES) — the synthetic sheets above were compared instead"));
    }
});
