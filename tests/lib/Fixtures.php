<?php
/*
 * Fixtures - building blocks for scenarios: wipe the data, register employees, create pay
 * periods and push attendance through the real endpoints, read the payroll back.
 */
final class Fixtures
{
    /** the pharmacy's settings (₱45 overtime hour, kinsenas calendar, contribution schedule as in Settings) */
    public const DEFAULT_SETTINGS = [
        'overtime_rate' => '45', 'overtime_method' => 'flat', 'overtime_multiplier' => '1.25',
        'late_rate' => '80', 'standard_hours' => '8', 'payroll_period' => 'Semi-Monthly',
        'contribution_timing_sss' => 'first', 'contribution_timing_philhealth' => 'second', 'contribution_timing_pagibig' => 'second',
        'contribution_timing_tax' => 'split',
        'bir_registered_name' => 'QA TEST PHARMACY', 'bir_signatory_name' => 'QA Signatory',
    ];

    public static function db(): PDO { return getDB(); }

    /** empty every data table and set the settings the scenario expects */
    public static function reset(array $settings = []): void
    {
        TestDb::truncateAll();
        self::lookup('branches', 'name', 'MAIN');          /* the branch the employee forms in the tests post */
        foreach (self::DEFAULT_SETTINGS as $k => $v) setSetting($k, $v);
        foreach ($settings as $k => $v) setSetting($k, (string)$v);
    }

    public static function setting(string $k, $v): void { setSetting($k, (string)$v); }

    /**
     * insert an employee; returns the emp_id. sss_amount / philhealth_amount / pagibig_amount / tax_amount are the
     * employee's MONTHLY amounts as the admin types them on Employees (default 0.00 = none).
     */
    public static function employee(array $e): string
    {
        static $n = 0;
        $e += ['emp_id' => 'EMP-' . str_pad((string)(++$n), 3, '0', STR_PAD_LEFT), 'full_name' => 'Test Employee ' . $n, 'position' => 'Staff',
               'branch' => 'MAIN', 'base_salary' => '480.00', 'salary_type' => 'daily', 'date_hired' => null, 'rest_days' => '7',
               'hours_per_day' => null, 'sss_amount' => '0.00', 'philhealth_amount' => '0.00', 'pagibig_amount' => '0.00', 'tax_amount' => '0.00',
               'status' => 'Active'];
        [$first, $last] = self::splitName($e['full_name']);
        self::db()->prepare("INSERT INTO employees (employee_code, first_name, last_name, position_id, branch_id, base_salary, salary_type, date_hired, rest_days,
                             hours_per_day, sss_amount, philhealth_amount, pagibig_amount, tax_amount, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$e['emp_id'], $first, $last, self::lookup('positions', 'title', $e['position']), self::lookup('branches', 'name', $e['branch']),
                       $e['base_salary'], $e['salary_type'], $e['date_hired'],
                       $e['rest_days'], $e['hours_per_day'], $e['sss_amount'], $e['philhealth_amount'], $e['pagibig_amount'], $e['tax_amount'], $e['status']]);
        return $e['emp_id'];
    }

    /** "Juan dela Cruz" -> ['Juan', 'dela Cruz']: the last word is the last name (full_name is first_name + last_name again) */
    public static function splitName(string $name): array
    {
        $name = trim($name);
        $at = strrpos($name, ' ');
        return $at === false ? [$name, ''] : [substr($name, 0, $at), substr($name, $at + 1)];
    }

    /** id of the row of a lookup table (branches.name, positions.title) with this text - created when it is new; null for a blank text */
    public static function lookup(string $table, string $col, ?string $text): ?int
    {
        $text = trim((string)$text);
        if ($text === '') return null;
        $db = self::db();
        $db->prepare("INSERT IGNORE INTO $table ($col) VALUES (?)")->execute([$text]);
        $st = $db->prepare("SELECT id FROM $table WHERE $col = ?");
        $st->execute([$text]);
        return (int)$st->fetchColumn();
    }

    /** create a pay period through api/create-period.php; returns its id */
    public static function period(string $label, string $start, string $end, string $type = 'Semi-Monthly'): int
    {
        $r = Http::api('create-period.php', ['label' => $label, 'start' => $start, 'end' => $end, 'type' => $type]);
        if (empty($r['json']['success'])) throw new RuntimeException("create-period failed: " . $r['body']);
        return (int)$r['json']['id'];
    }

    /** upload day rows through api/save-daily-attendance.php; returns the decoded response (with ['status']) */
    public static function days(int $periodId, array $rows, array $extra = [], ?array $session = null): array
    {
        $r = Http::api('save-daily-attendance.php', ['period_id' => $periodId, 'rows' => $rows] + $extra, $session);
        return ($r['json'] ?? []) + ['status' => $r['status'], 'warnings' => $r['warnings'] ?? []];
    }

    /** one day row, shaped as attendance-upload.js posts it */
    public static function day(string $name, string $date, $hours, $ot = 0, $late = 0, $under = null, bool $off = false): array
    {
        return ['emp_name' => $name, 'att_date' => $date, 'hours_worked' => $hours, 'overtime_hours' => $ot,
                'late_hours' => $late, 'undertime_hours' => $under, 'day_off' => $off];
    }

    /** a full duty day every date from..to that is not one of the rest weekdays */
    public static function fullDays(string $name, string $from, string $to, array $rest = [7], $hours = 8, $under = 0): array
    {
        $rows = [];
        foreach (Ledger::dates($from, $to) as $d) {
            if (in_array(Ledger::dow($d), $rest, true)) continue;
            $rows[] = self::day($name, $d, $hours, 0, 0, $under);
        }
        return $rows;
    }

    /** payroll rows of a period keyed by emp_id (DECIMAL columns come back as exact strings) */
    public static function payroll(int $periodId): array
    {
        $st = self::db()->prepare('SELECT ' . PAY_LINE_COLUMNS . ' FROM payroll p ' . PAY_LINE_JOINS . '
                                    WHERE p.period_id = ? ORDER BY e.employee_code');
        $st->execute([$periodId]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[$r['emp_id']] = $r;
        return $out;
    }

    public static function leave(string $empId, string $from, string $to, string $status = 'Approved'): int
    {
        $db = self::db();
        $db->prepare("INSERT INTO leave_requests (employee_id, leave_type, date_from, date_to, status)
                      SELECT id, ?, ?, ?, ? FROM employees WHERE employee_code = ?")
           ->execute(['Vacation', $from, $to, $status, $empId]);
        return (int)$db->lastInsertId();
    }

    /** ledger view of an employees-table row */
    public static function ledgerEmp(array $e): array
    {
        return ['type' => $e['salary_type'], 'base' => $e['base_salary'], 'hours_per_day' => $e['hours_per_day'],
                'rest' => $e['rest_days'] === '' ? [] : array_map('intval', explode(',', $e['rest_days'])),
                'amt' => ['sss' => Ledger::c($e['sss_amount']), 'ph' => Ledger::c($e['philhealth_amount']),
                          'pi' => Ledger::c($e['pagibig_amount']), 'tax' => Ledger::c($e['tax_amount'])]];
    }

    public static function empRow(string $empId): array
    {
        $st = self::db()->prepare('SELECT ' . EMPLOYEE_COLUMNS . ' FROM employees e ' . EMPLOYEE_JOINS . ' WHERE e.employee_code = ?');
        $st->execute([$empId]);
        return $st->fetch();
    }
}
