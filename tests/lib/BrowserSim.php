<?php
/*
 * BrowserSim - what assets/js/attendance-formats.js + attendance-upload.js do in the browser,
 * so a timesheet file can be pushed through the REAL api endpoints without a browser.
 *
 * This is a port, so it can drift from the JavaScript; suites/07_js_parity.php runs the
 * original JavaScript in headless Edge and fails if the two ever disagree.
 *
 * Only the day-by-day CSV path is ported (a flat export with one row per person per day -
 * the layout of the pharmacy's TIMESHEET *.csv files and of the app's own template).
 */
final class BrowserSim
{
    public const DAY_HEADERS = ['Date', 'Name', 'Device ID', 'Hours Worked', 'Overtime', 'Late Hours', 'Undertime', 'Remarks'];
    public const OFF_REMARKS = ['off', 'day off', 'dayoff', 'rest day', 'restday', 'rd', 'rest'];
    private const NAME_HEADERS = ['name', 'full name', 'employee name', 'employeename', 'empname'];

    /* ---------------------------------------------------------------- value parsers (attendance-upload.js) */
    public static function num($v): float
    {
        if (is_int($v) || is_float($v)) return (float)$v;
        return (float)preg_replace('/[^0-9.\-]/', '', (string)($v ?? 0)) ?: 0.0;
    }

    /**
     * "08:29" -> 8.4833 ; 8.5 -> 8.5 ; '' -> 0.
     * The original reads an unreadable duration ("08:60", "8h30m") through num(), which strips the punctuation: 860 hours.
     * The audit-fixed JavaScript returns NaN for it (strict), and this port follows whichever application is under test.
     */
    public static function toHours($v): float
    {
        $strict = AppCopy::hasFixes();
        if ($v === null || $v === '') return 0.0;
        if (is_int($v) || is_float($v)) return ($strict && !is_finite((float)$v)) ? NAN : (float)$v;
        $s = trim((string)$v);
        if ($s === '') return 0.0;
        if (preg_match('/^(-)?(\d{1,4}):([0-5]?\d)(?::([0-5]?\d))?$/', $s, $m)) {
            $mag = (float)$m[2] + (float)$m[3] / 60 + (isset($m[4]) && $m[4] !== '' ? (float)$m[4] / 3600 : 0);
            return round($m[1] === '-' ? -$mag : $mag, 4);
        }
        if ($strict) {
            if (preg_match('/^-?(\d+\.?\d*|\.\d+)$/', $s)) return (float)$s;
            if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) return (float)str_replace(',', '', $s);
            return preg_match('/\d/', $s) ? NAN : 0.0;      // digits that are neither a duration nor a decimal: unreadable
        }
        return self::num($s);
    }

    public static function toDate($v): string
    {
        if ($v === null || $v === '' || $v === false) return '';
        if (is_int($v) || is_float($v)) return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400));
        $s = trim((string)$v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $s, $m)) return sprintf('%s-%02d-%02d', $m[3], $m[1], $m[2]);
        $t = strtotime($s);
        return $t === false ? $s : date('Y-m-d', $t);
    }

    public static function normHeader($h): string
    {
        $h = strtolower((string)($h ?? ''));
        $h = preg_replace('/[_\-]+/', ' ', $h);
        $h = preg_replace('/[^a-z0-9 ]/', '', $h);
        return trim(preg_replace('/\s+/', ' ', $h));
    }

    public static function round2(float $n): float { return round($n, 2); }

    /* ---------------------------------------------------------------- attendance-formats.js */
    public static function findCols(array $headers): array
    {
        $n = array_map([self::class, 'normHeader'], $headers);
        $find = function (array $list) use ($n) { foreach ($n as $i => $h) if (in_array($h, $list, true)) return $i; return -1; };
        return [
            'name'    => $find(self::NAME_HEADERS),
            'date'    => $find(['date']),
            'total'   => $find(['total hours worked', 'total no of hours worked', 'total hours']),
            'late'    => $find(['late hours', 'late']),
            'ot'      => $find(['overtime hours', 'over time hours', 'overtime', 'ot hours']),
            'under'   => $find(['under time hours', 'undertime hours', 'undertime', 'under time']),
            'remarks' => $find(['remarks', 'remark', 'status', 'day status']),
        ];
    }

    /** rows of a timesheet export -> [Date, Name, DeviceID, HoursWorked, OT, Late, Undertime, Remarks] */
    public static function dayRowsFromTable(array $rows, array $col, ?string $fixedName = null): array
    {
        $out = [];
        $stats = ['worked' => 0, 'off' => 0, 'marked' => 0, 'blank' => 0, 'invalid' => 0];
        foreach ($rows as $r) {
            $date = self::toDate($r[$col['date']] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $name  = $fixedName ?? trim((string)($r[$col['name']] ?? ''));
            $total = self::toHours($r[$col['total']] ?? '');
            $ot    = $col['ot'] >= 0 ? self::toHours($r[$col['ot']] ?? '') : 0.0;
            $late  = $col['late'] >= 0 ? self::toHours($r[$col['late']] ?? '') : 0.0;
            $remark = $col['remarks'] >= 0 ? trim((string)($r[$col['remarks']] ?? '')) : '';
            if ($name === '') continue;
            // audit-fixed: an unreadable duration is never carried on as a number - the day is skipped and counted
            if (AppCopy::hasFixes()) {
                $underRaw = $col['under'] >= 0 && ($r[$col['under']] ?? '') !== '' && $r[$col['under']] !== null ? self::toHours($r[$col['under']]) : 0.0;
                if (is_nan($total) || is_nan($ot) || is_nan($late) || is_nan($underRaw)) { $stats['invalid']++; continue; }
            }
            if ($total <= 0 && $ot <= 0) {
                $k = self::normHeader($remark);
                if (in_array($k, self::OFF_REMARKS, true)) { $out[] = [$date, $name, '', 0, 0, 0, '', 'OFF']; $stats['off']++; }
                elseif ($k !== '' && $k !== 'duty')       { $out[] = [$date, $name, '', 0, 0, 0, '', strtoupper($remark)]; $stats['marked']++; }
                else $stats['blank']++;
                continue;
            }
            $regular = $ot > 0 && $total >= $ot ? $total - $ot : $total;
            $under = $col['under'] >= 0 && ($r[$col['under']] ?? '') !== '' && $r[$col['under']] !== null
                ? self::round2(self::toHours($r[$col['under']])) : '';
            $out[] = [$date, $name, '', self::round2($regular), self::round2($ot), self::round2($late), $under, $remark];
            $stats['worked']++;
        }
        return ['rows' => $out] + $stats;
    }

    /** a CSV file -> grid of strings (UTF-8 BOM removed) */
    public static function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        $grid = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($row === [null]) continue;
            $grid[] = $row;
        }
        fclose($fh);
        if ($grid && isset($grid[0][0])) $grid[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $grid[0][0]);
        return $grid;
    }

    /** readFlatTable(): header row + data rows, re-shaped into day rows when it is a timesheet export */
    public static function readFlatTable(array $grid): array
    {
        $h = -1;
        foreach (array_slice($grid, 0, 20) as $i => $r) {
            foreach ($r as $c) if (in_array(self::normHeader($c), self::NAME_HEADERS, true)) { $h = $i; break 2; }
        }
        if ($h < 0) $h = 0;
        $headers = array_map(fn($c) => trim((string)$c), $grid[$h] ?? []);
        $rows = array_values(array_filter(array_slice($grid, $h + 1), fn($r) => count(array_filter($r, fn($c) => $c !== '' && $c !== null)) > 0));
        $col = self::findCols($headers);
        if ($col['name'] >= 0 && $col['date'] >= 0 && $col['total'] >= 0 && $col['ot'] >= 0) {
            $d = self::dayRowsFromTable($rows, $col, null);
            return ['headers' => self::DAY_HEADERS, 'rows' => $d['rows'], 'kind' => 'timesheet'];
        }
        return ['headers' => $headers, 'rows' => $rows, 'kind' => 'table'];
    }

    /* ---------------------------------------------------------------- attendance-upload.js : saveDaily() */
    /**
     * The JSON rows saveDaily() posts, limited to the pay period's own dates.
     * $nameMap lets a test say "the sheet calls her X, Employee Management calls her Y".
     */
    public static function dailyPayload(array $table, string $periodStart, string $periodEnd, array $nameMap = []): array
    {
        $headers = $table['headers'];
        $idx = fn(array $names) => (function () use ($headers, $names) {
            foreach ($headers as $i => $h) if (in_array(self::normHeader($h), $names, true)) return $i;
            return null;
        })();
        $cName = $idx(['name', 'full name', 'employee name', 'employeename', 'empname']);
        $cDate = $idx(['date', 'attendance date', 'att date', 'day']);
        $cHours = $idx(['hours', 'hours worked', 'hoursworked', 'work hours', 'total hours worked', 'total no of hours worked', 'total hours']);
        $cOt   = $idx(['overtime', 'ot', 'ot hours', 'overtime hours', 'over time hours']);
        $cLate = $idx(['late', 'late hours', 'latehours', 'tardiness']);
        $cUnder = $idx(['undertime', 'under time hours', 'undertime hours', 'under time']);
        $cRem  = $idx(['remarks', 'remark', 'status', 'day status']);

        $rows = [];
        foreach ($table['rows'] as $r) {
            $name = trim((string)($r[$cName] ?? ''));
            $name = $nameMap[$name] ?? $name;
            $date = self::toDate($r[$cDate] ?? '');
            if ($name === '' || $date === '') continue;
            if ($date < $periodStart || $date > $periodEnd) continue;
            $row = [
                'emp_name'        => $name,
                'att_date'        => $date,
                'hours_worked'    => $cHours !== null ? self::toHours($r[$cHours] ?? '') : null,
                'overtime_hours'  => self::toHours($cOt !== null ? ($r[$cOt] ?? '') : 0),
                'late_hours'      => self::toHours($cLate !== null ? ($r[$cLate] ?? '') : 0),
                'undertime_hours' => $cUnder !== null && ($r[$cUnder] ?? '') !== '' && ($r[$cUnder] ?? null) !== null ? self::toHours($r[$cUnder]) : null,
                'day_off'         => $cRem !== null && in_array(self::normHeader($r[$cRem] ?? ''), self::OFF_REMARKS, true),
            ];
            // audit-fixed saveDaily(): a row with an unreadable figure is held back, never posted
            if (AppCopy::hasFixes() && (is_nan((float)$row['hours_worked']) || is_nan($row['overtime_hours']) || is_nan($row['late_hours'])
                    || ($row['undertime_hours'] !== null && is_nan($row['undertime_hours'])))) continue;
            $rows[] = $row;
        }
        return $rows;
    }
}
