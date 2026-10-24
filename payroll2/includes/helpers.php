<?php
require_once __DIR__ . '/errors.php';   // friendly errors everywhere - load first
require_once __DIR__ . '/db.php';

/*
 * The layout of the database this version of the app reads and writes (sql/database.sql). It is part of the fingerprint
 * applySchemaPatches() keeps, so a new layout is looked at once on the first page load.
 */
const SCHEMA_LAYOUT = 'standard-2026-10-15';

/*
 * applySchemaPatches()
 * Gets the database ready for this version of the app and seeds the admin account on the first-ever run:
 *   1. a database with no payroll tables yet is built from sql/database.sql - a fresh install needs nothing else
 *   2. a database still in an older layout stops the app, with what to do about it (schemaLayoutProblem)
 *   3. $patches: changes made to the standard layout after it was introduced, oldest first
 * Safe to call on every request: a static guard runs it once per PHP process, and a fingerprint kept in settings means a
 * normal page load costs one query.
 */
function applySchemaPatches(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = getDB();

    /* Each statement must be safe on a database that already has it - a failure is ignored */
    $patches = [
        /* 2026-10-24: the pay schedule and the contribution schedule belong to each employee (they used to be two Settings, the same
           for everybody). seedEmployeeSchedules() fills them in the first time they exist. */
        "ALTER TABLE employees ADD COLUMN pay_schedule ENUM('Semi-Monthly','Monthly') NOT NULL DEFAULT 'Semi-Monthly' AFTER salary_type",
        "ALTER TABLE employees ADD COLUMN sss_timing ENUM('first','split','second') NOT NULL DEFAULT 'first' AFTER tax_amount",
        "ALTER TABLE employees ADD COLUMN philhealth_timing ENUM('first','split','second') NOT NULL DEFAULT 'second' AFTER sss_timing",
        "ALTER TABLE employees ADD COLUMN pagibig_timing ENUM('first','split','second') NOT NULL DEFAULT 'second' AFTER philhealth_timing",
        "ALTER TABLE employees ADD COLUMN tax_timing ENUM('first','split','second') NOT NULL DEFAULT 'split' AFTER pagibig_timing",
    ];

    /*
     * Run only when something has changed since the last run. The database is in the cloud (~350 ms a round trip), so
     * checking on every page load would cost seconds. A fingerprint of the layout and the patch list is kept in settings.
     */
    $version = md5(SCHEMA_LAYOUT . "\n" . implode("\n", $patches));
    try {
        $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'");
        $st->execute();
        if ($st->fetchColumn() === $version) return;
    } catch (PDOException $e) { /* no settings table: a fresh install, or an older layout - looked at below */ }

    installSchemaIfEmpty($db);

    /* A database from an older layout would fail on the first query that reads it: say what to do instead, and remember
       nothing, so the next page load looks again (it costs a couple of queries until the update has been run). */
    if (($problem = schemaLayoutProblem($db)) !== null) {
        showAppError($problem, '', 503);
    }

    /* Do the employees have their own pay schedule yet? (a fresh install builds the table with it; an existing database gets it below) */
    $hadSchedule = true;
    try {
        $hadSchedule = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'pay_schedule'")->fetchColumn() > 0;
    } catch (PDOException $e) {}

    foreach ($patches as $sql) {
        try { $db->exec($sql); } catch (PDOException $e) {}
    }
    if (!$hadSchedule) seedEmployeeSchedules($db);

    // Seed the admin user on first-ever run. The first password comes from
    // the ADMIN_INITIAL_PASSWORD environment variable. Without it, a local
    // install starts with the default password (and must change it at first
    // sign-in); the live site creates no admin at all rather than one anybody
    // could guess. After this runs once, credentials live only in users.
    try {
        $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn();
        $first = (string)(getenv('ADMIN_INITIAL_PASSWORD') ?: '');
        if ($count === 0 && ($first !== '' || APP_ENV !== 'production')) {
            $email = getSetting('admin_email', 'admin@lnpharmacy.com') ?: 'admin@lnpharmacy.com';
            $db->prepare("INSERT INTO users (full_name, email, password_hash, role)
                          VALUES ('Admin', ?, ?, 'Admin')")
               ->execute([$email, password_hash($first !== '' ? $first : DEFAULT_ADMIN_PASSWORD, PASSWORD_BCRYPT)]);
        }
    } catch (PDOException $e) {}

    /* Done - remember this layout so the next page load skips all of it */
    try { setSetting('schema_version', $version); } catch (PDOException $e) {}
}

/*
 * A database with none of the payroll tables is built from sql/database.sql. If any of them exists nothing is built, so a
 * database in an older layout is never built over.
 */
function installSchemaIfEmpty(PDO $db): void {
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('settings', 'employees', 'users', 'payroll')")->fetchColumn();
    } catch (PDOException $e) {
        return;
    }
    $file = __DIR__ . '/../sql/database.sql';
    if ($n > 0 || !is_file($file)) return;
    foreach (splitSqlStatements((string)file_get_contents($file)) as $stmt) {
        $db->exec($stmt);
    }
}

/*
 * The first time the employees table has its own pay schedule and contribution schedule (2026-10-24), everybody starts exactly
 * where the company-wide Settings had them, so no payroll changes by itself:
 *   contribution schedule   Settings' contribution_timing_sss / _philhealth / _pagibig / _tax (the defaults where Settings never said)
 *   pay schedule            the schedule of the pay period the employee was last paid in; an employee with no pay yet takes Settings'
 *                           payroll_period. Weekly (no longer offered) and anything unknown become Semi-Monthly.
 * Nothing is deleted: the old Settings rows stay in the settings table, unused. updated_at is left as it was.
 */
function seedEmployeeSchedules(PDO $db): void {
    try {
        $s = $db->query("SELECT setting_key, setting_value FROM settings
                          WHERE setting_key IN ('payroll_period', 'contribution_timing_sss', 'contribution_timing_philhealth',
                                                'contribution_timing_pagibig', 'contribution_timing_tax')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $default = in_array($s['payroll_period'] ?? '', PAY_SCHEDULES, true) ? $s['payroll_period'] : 'Semi-Monthly';
        $sets    = ['pay_schedule = ?', 'updated_at = updated_at'];
        $args    = [$default];
        foreach (CONTRIBUTION_TIMING_DEFAULT as $k => $def) {
            $v      = $s["contribution_timing_$k"] ?? '';
            $sets[] = "{$k}_timing = ?";
            $args[] = in_array($v, CONTRIBUTION_TIMING_CHOICES, true) ? $v : $def;
        }
        $db->prepare('UPDATE employees SET ' . implode(', ', $sets))->execute($args);

        /* how each employee has actually been paid: the latest pay period they have a line in decides */
        $last = [];
        foreach ($db->query("SELECT p.employee_id, pp.period_type FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
                              ORDER BY pp.start_date, pp.id")->fetchAll() as $r) {
            $last[(int)$r['employee_id']] = $r['period_type'];
        }
        foreach (PAY_SCHEDULES as $type) {
            $ids = array_keys(array_filter($last, fn($t) => $t === $type));
            if (!$ids) continue;
            $db->prepare('UPDATE employees SET pay_schedule = ?, updated_at = updated_at WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
               ->execute(array_merge([$type], $ids));
        }
    } catch (PDOException $e) { /* the columns keep their defaults; the admin can set each employee on Employees */ }
}

/* The statements of a .sql file, in order, without comments: split on ";" outside quotes and comments */
function splitSqlStatements(string $sql): array {
    $out = [];
    $cur = '';
    $n   = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c    = $sql[$i];
        $next = $i + 1 < $n ? $sql[$i + 1] : '';
        if ($c === '-' && $next === '-' && ($i + 2 >= $n || in_array($sql[$i + 2], [' ', "\t", "\r", "\n"], true))) {
            while ($i < $n && $sql[$i] !== "\n") $i++;               /* "-- " comment to the end of the line */
            $cur .= "\n";
            continue;
        }
        if ($c === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i   = $end === false ? $n : $end + 1;
            $cur .= ' ';
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $cur .= $c;
            for ($i++; $i < $n; $i++) {
                $d = $sql[$i];
                $cur .= $d;
                if ($d === '\\' && $q !== '`' && $i + 1 < $n) { $cur .= $sql[++$i]; continue; }
                if ($d === $q) {
                    if ($i + 1 < $n && $sql[$i + 1] === $q) { $cur .= $sql[++$i]; continue; }
                    break;
                }
            }
            continue;
        }
        if ($c === ';') {
            if (trim($cur) !== '') $out[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}

/*
 * Null when the database is in the layout this version reads. Otherwise it still has an older one (emp_id in employees,
 * attendance, employee_profiles ...) and the answer is what to do: sql/migrate-standard-schema.sql converts it and keeps
 * every record. A layout older than 2026-10-15 (no employee_id) needs sql/migrate-employee-id.sql first.
 */
function schemaLayoutProblem(PDO $db): ?string {
    try {
        $st = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE()
                             AND ((TABLE_NAME = 'employees' AND COLUMN_NAME IN ('emp_id', 'employee_code'))
                               OR (TABLE_NAME = 'payroll'   AND COLUMN_NAME IN ('employee_id', 'emp_id')))");
        $have = [];
        foreach ($st->fetchAll() as $r) $have[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = true;
    } catch (PDOException $e) {
        return null;    /* cannot tell - let the page try */
    }
    if (!isset($have['employees.emp_id'])) return null;
    $steps = isset($have['payroll.emp_id'])
           ? 'Run sql/migrate-employee-id.sql and then sql/migrate-standard-schema.sql'
           : 'Run sql/migrate-standard-schema.sql';
    return 'The database needs a one-time update before this version of the payroll can open it: it is still in the older layout '
         . '(emp_id, employee_profiles, attendance, bonus_deductions ...). '
         . $steps . ' on the database (in DBeaver; it keeps every record), then reload this page.';
}

/*
 * employees.id for an employee code as people see it (EMP-004), or null when there is no such employee. Tables store
 * employee_id = employees.id, while forms, files, sessions and payslips carry the code - which this app calls emp_id in its
 * own arrays, forms and URLs and which is the employee_code column; this is where one becomes the other.
 */
function employeePk(PDO $db, string $empId): ?int {
    return employeePks($db, [$empId])[$empId] ?? null;
}

/* The same for many codes in one query: emp_id => employees.id, for those that exist (compared the way MySQL compares them) */
function employeePks(PDO $db, array $empIds): array {
    $empIds = array_values(array_unique(array_map('strval', $empIds)));
    if (!$empIds) return [];
    $st = $db->prepare("SELECT id, employee_code FROM employees WHERE employee_code IN (" . implode(',', array_fill(0, count($empIds), '?')) . ")");
    $st->execute($empIds);
    $out = [];
    foreach ($st->fetchAll() as $e) {
        $out[(string)$e['employee_code']] = (int)$e['id'];
        /* a code typed in another letter case or with a trailing space still finds its employee, as it did in SQL */
        foreach ($empIds as $asked) {
            if (!isset($out[$asked]) && strcasecmp(rtrim($asked), rtrim((string)$e['employee_code'])) === 0) $out[$asked] = (int)$e['id'];
        }
    }
    return $out;
}

/*
 * The employee list in the form every page and the pay computation reads it: the employee_code as emp_id, the position and
 * branch NAMES (they are rows in positions and branches), and the department. Use it wherever an employee's own columns are
 * needed together with those names:
 *     SELECT e.*, ... FROM employees e [EMPLOYEE_JOINS] ...
 * EMPLOYEE_COLUMNS adds emp_id, position, branch and department to e.* .
 */
const EMPLOYEE_COLUMNS = "e.*, e.employee_code AS emp_id, COALESCE(pos.title, '') AS `position`, COALESCE(br.name, '') AS branch, COALESCE(dep.name, '') AS department";
const EMPLOYEE_JOINS   = "LEFT JOIN branches br ON br.id = e.branch_id LEFT JOIN positions pos ON pos.id = e.position_id LEFT JOIN departments dep ON dep.id = pos.department_id";

/*
 * A pay line in the shape the payslips, the register, the reports and the employee portal read it: every payroll column, plus
 *   emp_id, emp_name, branch, position           from employees (and its branch and position)
 *   hours_worked, overtime_hours, late_hours, undertime_hours, absent_days, leave_days, days_off    from attendance_summary
 *   sss, philhealth, pagibig, bonus               the short names of sss_deduction, philhealth_deduction, pagibig_deduction, bonus_pay
 *   ot_late_adj                                   gross_pay - basic_pay: overtime pay less what lateness cost
 *   absent_deduction, undertime_deduction         absence_deduction and tardy_undertime_deduction
 * Use with PAY_LINE_JOINS and the payroll table aliased p:
 *     SELECT PAY_LINE_COLUMNS, pp.period_label FROM payroll p PAY_LINE_JOINS JOIN payroll_periods pp ON pp.id = p.period_id
 */
const PAY_LINE_COLUMNS = "p.*, e.employee_code AS emp_id, e.full_name AS emp_name, COALESCE(br.name, '') AS branch, COALESCE(pos.title, '') AS `position`,
        p.sss_deduction AS sss, p.philhealth_deduction AS philhealth, p.pagibig_deduction AS pagibig, p.bonus_pay AS bonus,
        p.gross_pay - p.basic_pay AS ot_late_adj, p.absence_deduction AS absent_deduction, p.tardy_undertime_deduction AS undertime_deduction,
        COALESCE(s.total_hours_worked, 0) AS hours_worked, COALESCE(s.total_overtime_hours, 0) AS overtime_hours,
        COALESCE(s.total_late_hours, 0) AS late_hours, COALESCE(s.total_undertime_hours, 0) AS undertime_hours,
        COALESCE(s.absent_days, 0) AS absent_days, COALESCE(s.leave_days, 0) AS leave_days, COALESCE(s.days_off, 0) AS days_off";
const PAY_LINE_JOINS = "JOIN employees e ON e.id = p.employee_id
        LEFT JOIN branches br ON br.id = e.branch_id LEFT JOIN positions pos ON pos.id = e.position_id
        LEFT JOIN attendance_summary s ON s.period_id = p.period_id AND s.employee_id = p.employee_id";

/*
 * A bonus / deduction entry as the history pages and receipts read it: entry_type (the type column), the employee's emp_id and
 * emp_name, and processed_by as the NAME of the account that recorded it. Table aliased h:
 *     SELECT ADJ_LINE_COLUMNS FROM bonus_deductions h ADJ_LINE_JOINS
 */
const ADJ_LINE_COLUMNS = "h.id, h.employee_id, h.period_id, h.type AS entry_type, h.amount, h.reason, h.entry_date, h.finalize_cycle, h.created_at,
        e.employee_code AS emp_id, e.full_name AS emp_name, COALESCE(u.full_name, '') AS processed_by";
const ADJ_LINE_JOINS   = "JOIN employees e ON e.id = h.employee_id LEFT JOIN users u ON u.id = h.processed_by";

/*
 * A leave request as the leave pages read it: the employee's emp_id and emp_name, and reviewed_by as the NAME of the account that
 * decided it. Table aliased lr:
 *     SELECT LEAVE_LINE_COLUMNS FROM leave_requests lr LEAVE_LINE_JOINS
 */
const LEAVE_LINE_COLUMNS = "lr.id, lr.employee_id, lr.leave_type, lr.date_from, lr.date_to, lr.reason, lr.status, lr.review_note, lr.reviewed_at, lr.created_at,
        e.employee_code AS emp_id, e.full_name AS emp_name, COALESCE(ru.full_name, '') AS reviewed_by";
const LEAVE_LINE_JOINS   = "JOIN employees e ON e.id = lr.employee_id LEFT JOIN users ru ON ru.id = lr.reviewed_by";
/* the same, with the employee's branch, position and day-off schedule (the leave pages' detail dialogs) */
const LEAVE_FULL_COLUMNS = LEAVE_LINE_COLUMNS . ", COALESCE(br.name, '') AS branch, COALESCE(pos.title, '') AS `position`, e.rest_days";
const LEAVE_FULL_JOINS   = LEAVE_LINE_JOINS . " LEFT JOIN branches br ON br.id = e.branch_id LEFT JOIN positions pos ON pos.id = e.position_id";

/* positions.id for a typed position title: the existing one (compared the way MySQL does, so another letter case is the same), or a new one. null for a blank title. */
function positionId(PDO $db, string $title): ?int {
    $title = trim($title);
    if ($title === '') return null;
    $st = $db->prepare("SELECT id FROM positions WHERE title = ? LIMIT 1");
    $st->execute([$title]);
    if ($id = $st->fetchColumn()) return (int)$id;
    $db->prepare("INSERT INTO positions (title) VALUES (?)")->execute([mb_substr($title, 0, 100)]);
    return (int)$db->lastInsertId();
}

/* branches.id for a branch name, or null when the name is blank or is not in the list */
function branchId(PDO $db, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $st = $db->prepare("SELECT id FROM branches WHERE name = ? LIMIT 1");
    $st->execute([$name]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/*
 * requireAuth() - admin portal guard.
 * Redirects to index.php if the admin session is missing.
 */
function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['logged_in']) || !sessionStillActive()) {
        header('Location: /index.php');
        exit;
    }
    /* Still on the default password: nothing else until it is changed */
    if (!empty($_SESSION['must_change_pw']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'settings.php') {
        header('Location: /settings.php?change_password=1');
        exit;
    }
    applySchemaPatches();
}

/* The password every fresh install starts with - never accepted on the live site */
const DEFAULT_ADMIN_PASSWORD = 'admin123';

/* Signed-in sessions end after this long without any page being opened */
const SESSION_IDLE_SECONDS = 8 * 3600;

/*
 * False (and the session is ended) when the user has been idle too long -
 * a payroll left open on a shared PC does not stay signed in forever.
 */
function sessionStillActive(): bool {
    $now = time();
    if (isset($_SESSION['last_seen']) && $now - (int)$_SESSION['last_seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    $_SESSION['last_seen'] = $now;
    return true;
}

/*
 * The visitor's address. Behind Render's proxy the connection comes from the
 * proxy, so the address the proxy appended last to X-Forwarded-For is used.
 */
function clientIp(): string {
    $fwd = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($fwd !== '') {
        $parts = array_map('trim', explode(',', $fwd));
        $ip = end($parts);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/*
 * Password guessing is slowed down: after 5 wrong passwords for one email,
 * or 20 from one address, within 15 minutes, sign-in waits.
 */
function loginBlocked(PDO $db, string $email): bool {
    try {
        $st = $db->prepare("SELECT
                SUM(email = ? AND ok = 0) AS by_email,
                SUM(ip = ? AND ok = 0)    AS by_ip
              FROM login_attempts WHERE attempted_at > NOW() - INTERVAL 15 MINUTE AND (email = ? OR ip = ?)");
        $ip = clientIp();
        $st->execute([$email, $ip, $email, $ip]);
        $r = $st->fetch();
        return (int)$r['by_email'] >= 5 || (int)$r['by_ip'] >= 20;
    } catch (PDOException $e) {
        return false;   /* no table yet: never lock anybody out because of it */
    }
}

function recordLogin(PDO $db, string $email, bool $ok): void {
    try {
        $db->prepare("INSERT INTO login_attempts (ip, email, ok) VALUES (?, ?, ?)")
           ->execute([clientIp(), mb_substr($email, 0, 150), $ok ? 1 : 0]);
        if (random_int(1, 20) === 1) $db->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
    } catch (PDOException $e) { /* logging a sign-in must never block one */ }
}

/*
 * jsonResponse() - terminate with a JSON payload.
 * Used by all API endpoints under api/.
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/*
 * ── Official contribution and tax tables (REFERENCE) ──────────────────
 * What the four agencies prescribe. Payroll does NOT apply them: the SSS,
 * PhilHealth, Pag-IBIG and withholding-tax amounts are typed per employee
 * by the admin (employees.sss_amount … tax_amount) and deducted as typed.
 * The tables stay as the guide Settings shows the admin when working out
 * those amounts, and as the rates the company's share is figured from
 * (employerShare). When an agency changes a rate, edit it here.
 * Last checked October 2026 - unchanged for 2026 by all four agencies.
 */
/* Marks this copy of the app as carrying the 2026-10-07 payroll audit fixes (tests/AUDIT_FINDINGS.md). No schema changes. */
const PAYROLL_AUDIT_FIXES = '2026-10-07';

/* Tax-exempt yearly ceiling for 13th-month pay and other benefits (TRAIN law, Sec. 32(B)(7)(e)) */
const BIR_EXEMPT_BENEFITS = 90000.0;

/* Sanity limits for what a single day (or a pay period) may hold - a typo must not become pay */
const MAX_DAY_HOURS    = 24.0;    /* worked in one day */
const MAX_DAY_OVERTIME = 16.0;    /* overtime in one day */
const MAX_RATE_PESOS   = 100000.0;/* an hourly overtime / late rate, or a daily salary rate */
const MAX_SALARY_PESOS = 10000000.0;

/* "8", "8.5", " 8 " → a finite float; anything else (text, "1e999", "08:60", "", null) → null */
function numberOrNull($v): ?float {
    if (is_int($v) || is_float($v)) return is_finite((float)$v) ? (float)$v : null;
    if (!is_string($v)) return null;
    $v = trim($v);
    return preg_match('/^-?\d+(\.\d+)?$|^-?\.\d+$/', $v) ? (float)$v : null;
}

/* 8 → "8", 8.5 → "8.5", 80.25 → "80.25" (for the messages below) */
function plainNum(float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/*
 * Is this one day's attendance possible? Returns why not, or null when it is fine.
 * Hours, overtime, late and undertime must be real, non-negative numbers; a day holds at most 24 hours; overtime past
 * MAX_DAY_OVERTIME is a typing error. Before these limits a day of 80 h + 30 OT h was paid (₱1,830 for one day), a device
 * report with 80 h became 72 h of overtime, and a negative figure was quietly turned into 0 (a day that looked absent).
 * $hours is null for "a full duty day" (the file had no hours column).
 */
function dayHoursProblem($hours, $ot, $late, $under = null): ?string {
    $v = [];
    foreach (['hours' => $hours, 'overtime' => $ot, 'late hours' => $late, 'undertime' => $under] as $what => $raw) {
        if ($raw === null || $raw === '') { $v[$what] = 0.0; continue; }
        $n = numberOrNull($raw);
        if ($n === null) return "$what is not a number";
        if ($n < 0)      return "$what is negative (" . plainNum($n) . ')';
        $v[$what] = $n;
    }
    if ($v['hours'] > MAX_DAY_HOURS)       return plainNum($v['hours']) . ' hours worked in one day';
    if ($v['overtime'] > MAX_DAY_OVERTIME) return plainNum($v['overtime']) . ' overtime hours in one day';
    if ($v['late hours'] > MAX_DAY_HOURS)  return plainNum($v['late hours']) . ' late hours in one day';
    if ($v['undertime'] > MAX_DAY_HOURS)   return plainNum($v['undertime']) . ' undertime hours in one day';
    if ($v['hours'] + $v['overtime'] > MAX_DAY_HOURS) {
        return plainNum($v['hours']) . ' h + ' . plainNum($v['overtime']) . ' h overtime is more than 24 hours in one day';
    }
    return null;
}

/* The same for a whole pay period's totals (a totals file): at most 24 h - and MAX_DAY_OVERTIME h of overtime - for every calendar day of the period */
function periodHoursProblem($hours, $ot, $late, int $days): ?string {
    $days = max(1, $days);
    $v = [];
    foreach (['hours' => $hours, 'overtime' => $ot, 'late hours' => $late] as $what => $raw) {
        if ($raw === null || $raw === '') { $v[$what] = 0.0; continue; }
        $n = numberOrNull($raw);
        if ($n === null) return "$what is not a number";
        if ($n < 0)      return "$what is negative (" . plainNum($n) . ')';
        $v[$what] = $n;
    }
    if ($v['hours'] > MAX_DAY_HOURS * $days)       return plainNum($v['hours']) . " hours in a $days-day period";
    if ($v['overtime'] > MAX_DAY_OVERTIME * $days) return plainNum($v['overtime']) . " overtime hours in a $days-day period";
    if ($v['late hours'] > MAX_DAY_HOURS * $days)  return plainNum($v['late hours']) . " late hours in a $days-day period";
    return null;
}

/* A peso setting or rate: a real number from 0 up to $max. Returns why not, or null. */
function pesoProblem(string $what, $raw, float $max): ?string {
    $n = numberOrNull($raw);
    if ($n === null) return "$what must be a number";
    if ($n < 0)      return "$what cannot be negative";
    if ($n > $max)   return "$what cannot be more than ₱" . number_format($max, 2);
    return null;
}

const PH_RULES = [
    'sss' => [
        'name'     => 'SSS',
        'ee_rate'  => 0.05,     // employee share
        'er_rate'  => 0.10,     // employer share (total 15%)
        'ec_low'   => 10.0,     // Employees' Compensation, employer only:
        'ec_high'  => 30.0,     //   ₱10 a month below a ₱15,000 credit, ₱30 from ₱15,000
        'ec_from'  => 15000.0,
        'msc_min'  => 5000.0,   // monthly salary credit floor (pay below ₱5,250)
        'msc_max'  => 35000.0,  // monthly salary credit ceiling (pay ₱34,750 and up)
        'msc_step' => 500.0,
        'since'    => 'January 2025',
        'source'   => 'SSS Circular No. 2024-006 (RA 11199)',
    ],
    'philhealth' => [
        'name'     => 'PhilHealth',
        'rate'     => 0.05,     // total premium, split equally
        'ee_share' => 0.5,
        'floor'    => 10000.0,
        'ceiling'  => 100000.0,
        'since'    => 'January 2024',
        'source'   => 'Universal Health Care Act (RA 11223)',
    ],
    'pagibig' => [
        'name'      => 'Pag-IBIG',
        'rate_low'  => 0.01,    // monthly pay of ₱1,500 or less
        'low_limit' => 1500.0,
        'rate'      => 0.02,
        'er_rate'   => 0.02,    // employer always 2%
        'max_comp'  => 10000.0, // maximum fund salary
        'since'     => 'February 2024',
        'source'    => 'HDMF Circular No. 460 (RA 9679)',
    ],
    'bir' => [
        'name'   => 'Withholding tax',
        'since'  => 'January 2023',
        'source' => 'BIR RR 11-2018 Annex E (TRAIN Law, RA 10963)',
        /* Annex E, one table per payroll period:
           taxable compensation over => [prescribed tax, rate on the excess] */
        'tables' => [
            'daily'   => [[21918.0, 6034.30, 0.35], [5479.0, 1102.60, 0.30], [2192.0, 280.85, 0.25],
                          [1096.0, 61.65, 0.20], [685.0, 0.0, 0.15]],
            'weekly'  => [[153846.0, 42355.65, 0.35], [38462.0, 7740.45, 0.30], [15385.0, 1971.20, 0.25],
                          [7692.0, 432.60, 0.20], [4808.0, 0.0, 0.15]],
            'semi'    => [[333333.0, 91770.70, 0.35], [83333.0, 16770.70, 0.30], [33333.0, 4270.70, 0.25],
                          [16667.0, 937.50, 0.20], [10417.0, 0.0, 0.15]],
            'monthly' => [[666667.0, 183541.80, 0.35], [166667.0, 33541.80, 0.30], [66667.0, 8541.80, 0.25],
                          [33333.0, 1875.00, 0.20], [20833.0, 0.0, 0.15]],
        ],
    ],
];

/*
 * The calculators below - sssCredit, sssMonthly, philhealthMonthly, pagibigMonthly, birTax - work out what the law
 * prescribes for a given pay. They are REFERENCE: payroll no longer calls them (amounts are typed per employee), and they
 * are kept, with the tests that pin them to the published tables, for checking an amount the admin is about to enter.
 */

/* SSS monthly salary credit: the month's compensation to the nearest ₱500, ₱5,000–₱35,000 */
function sssCredit(float $compensation): float {
    $r = PH_RULES['sss'];
    return max($r['msc_min'], min(round($compensation / $r['msc_step']) * $r['msc_step'], $r['msc_max']));
}

/* SSS employee share: 5% of the salary credit. Compensation is ALL pay
   earned in the month - basic, overtime, everything (RA 11199, Sec. 8). */
function sssMonthly(float $compensation): float {
    return round(sssCredit($compensation) * PH_RULES['sss']['ee_rate'], 2);
}

/* PhilHealth employee share: 5% of monthly BASIC salary (no overtime,
   allowances or bonuses), ₱10,000 floor, ₱100,000 ceiling, halved */
function philhealthMonthly(float $basicSalary): float {
    $r    = PH_RULES['philhealth'];
    $base = max($r['floor'], min($basicSalary, $r['ceiling']));
    return round($base * $r['rate'] * $r['ee_share'], 2);
}

/* Pag-IBIG employee share: 1% up to ₱1,500, else 2%, on at most ₱10,000 */
function pagibigMonthly(float $monthlyPay): float {
    $r    = PH_RULES['pagibig'];
    $rate = $monthlyPay <= $r['low_limit'] ? $r['rate_low'] : $r['rate'];
    return round(min($monthlyPay, $r['max_comp']) * $rate, 2);
}

/*
 * BIR withholding tax on TAXABLE compensation for one payroll period,
 * from the Annex E table of that period: 'daily', 'weekly', 'semi', 'monthly'.
 * Taxable = pay minus the employee's own SSS, PhilHealth and Pag-IBIG,
 * which are non-taxable - the caller takes them off before calling this.
 *
 * Worked in WHOLE CENTAVOS: the excess over the bracket is a difference of two
 * floats, and that subtraction leaves ~1e-13 of noise, so a tax of exactly half a
 * centavo (₱0.30 × 15% = ₱0.045) could round down to ₱0.04. As integers it is
 * exact, and a half centavo always rounds up like every other payslip figure.
 */
function birTax(float $taxable, string $table = 'monthly'): float {
    $cents = (int)round($taxable * 100);
    foreach (PH_RULES['bir']['tables'][$table] as [$over, $base, $rate]) {
        $overC = (int)round($over * 100);
        if ($cents > $overC) {
            $excess = ($cents - $overC) * (int)round($rate * 100);      /* centavos × percent */
            return ((int)round($base * 100) + intdiv($excess + 50, 100)) / 100;
        }
    }
    return 0.0;
}

/*
 * The law's brackets laid out as tables - what Employees > Add / Edit shows under "See brackets". Reference only, built from
 * PH_RULES (the same figures as Settings' "Contribution tables"); payroll does not apply them, it deducts what is typed.
 *
 * Returns a list of tables, each
 *   key, title, note   what it is, and one line on how it works
 *   basis              which figure picks the row: 'pay' (monthly pay), 'taxable_month' (monthly pay less the SSS, PhilHealth and
 *                      Pag-IBIG typed on the employee) or 'taxable_cutoff' (half of that: one semi-monthly cut-off)
 *   head               the column titles
 *   rows               each ['cells' => the text shown, 'lo' => float, 'hi' => ?float]: the row covers lo <= figure < hi (hi null =
 *                      no upper limit); the window uses these to mark the row an employee's pay falls in
 */
function contributionBrackets(): array {
    $R   = PH_RULES;
    $php = fn(float $x, int $d = 2): string => '₱' . number_format($x, $d);
    $pct = fn(float $x): string => rtrim(rtrim(number_format($x * 100, 2, '.', ''), '0'), '.') . '%';      /* 0.05 -> "5%", 0.025 -> "2.5%" */
    $out = [];

    /* SSS: the Monthly Salary Credit is the pay to the nearest ₱500 (₱5,000 - ₱35,000); pay from credit - 250 up to credit + 249.99 */
    $s    = $R['sss'];
    $half = $s['msc_step'] / 2;
    $rows = [];
    for ($msc = $s['msc_min']; $msc <= $s['msc_max']; $msc += $s['msc_step']) {
        $first = $msc === $s['msc_min'];
        $last  = $msc === $s['msc_max'];
        $ee    = round($msc * $s['ee_rate'], 2);
        $er    = round($msc * $s['er_rate'], 2);
        $ec    = $msc >= $s['ec_from'] ? $s['ec_high'] : $s['ec_low'];
        $range = $first ? 'Below ' . $php($msc + $half)
               : ($last ? $php($msc - $half) . ' and above' : $php($msc - $half) . ' - ' . $php($msc + $half - 0.01));
        $rows[] = ['cells' => [$range, $php($msc), $php($ee), $php($er), $php($ec), $php($ee + $er + $ec)],
                   'lo' => $first ? 0.0 : $msc - $half, 'hi' => $last ? null : $msc + $half];
    }
    $out[] = ['key' => 'sss', 'title' => 'SSS', 'basis' => 'pay',
              'note' => 'The employee pays ' . $pct($s['ee_rate']) . ' of the Monthly Salary Credit and the employer ' . $pct($s['er_rate']) . ' (together '
                      . $pct($s['ee_rate'] + $s['er_rate']) . '); the Employees\' Compensation (EC) is paid by the employer only. The credit is the monthly pay to the nearest '
                      . $php($s['msc_step'], 0) . ', from ' . $php($s['msc_min'], 0) . ' to ' . $php($s['msc_max'], 0) . '. In effect since ' . $s['since'] . ' (' . $s['source'] . ').',
              'head' => ['Monthly pay', 'Monthly Salary Credit', 'Employee ' . $pct($s['ee_rate']), 'Employer ' . $pct($s['er_rate']), 'EC (employer)', 'Total'],
              'rows' => $rows];

    /* PhilHealth: 5% of the monthly basic salary, split equally; the salary counted runs from the floor to the ceiling */
    $p    = $R['philhealth'];
    $prem = fn(float $base): float => $base * $p['rate'];
    $half2 = fn(float $base): float => $base * $p['rate'] * $p['ee_share'];
    $out[] = ['key' => 'philhealth', 'title' => 'PhilHealth', 'basis' => 'pay',
              'note' => 'The premium is ' . $pct($p['rate']) . ' of the monthly basic salary, split equally between employee and employer (' . $pct($p['rate'] * $p['ee_share'])
                      . ' each). The salary counted is at least ' . $php($p['floor'], 0) . ' and at most ' . $php($p['ceiling'], 0) . '. In effect since ' . $p['since'] . ' (' . $p['source'] . ').',
              'head' => ['Monthly basic salary', 'Premium (' . $pct($p['rate']) . ')', 'Employee share', 'Employer share'],
              'rows' => [
                  ['cells' => [$php($p['floor'], 0) . ' and below', $php($prem($p['floor'])) . ' (fixed)', $php($half2($p['floor'])), $php($half2($p['floor']))],
                   'lo' => 0.0, 'hi' => $p['floor'] + 0.005],
                  ['cells' => ['Over ' . $php($p['floor'], 0) . ' up to ' . $php($p['ceiling'], 0), $pct($p['rate']) . ' of the salary', $pct($p['rate'] * $p['ee_share']) . ' of the salary', $pct($p['rate'] * (1 - $p['ee_share'])) . ' of the salary'],
                   'lo' => $p['floor'] + 0.005, 'hi' => $p['ceiling']],
                  ['cells' => [$php($p['ceiling'], 0) . ' and above', $php($prem($p['ceiling'])) . ' (fixed)', $php($half2($p['ceiling'])), $php($half2($p['ceiling']))],
                   'lo' => $p['ceiling'], 'hi' => null],
              ]];

    /* Pag-IBIG: 2% each on a monthly pay of up to ₱10,000 (the employee 1% when the pay is ₱1,500 or less) */
    $g    = $R['pagibig'];
    $top  = $g['max_comp'] * $g['rate'];
    $out[] = ['key' => 'pagibig', 'title' => 'Pag-IBIG', 'basis' => 'pay',
              'note' => 'Computed on the monthly pay up to ' . $php($g['max_comp'], 0) . ' (the maximum fund salary), so each side pays at most ' . $php($top)
                      . ' a month. The employer always pays ' . $pct($g['er_rate']) . '. In effect since ' . $g['since'] . ' (' . $g['source'] . ').',
              'head' => ['Monthly pay', 'Employee', 'Employer'],
              'rows' => [
                  ['cells' => [$php($g['low_limit'], 0) . ' and below', $pct($g['rate_low']), $pct($g['er_rate'])],
                   'lo' => 0.0, 'hi' => $g['low_limit'] + 0.005],
                  ['cells' => ['Over ' . $php($g['low_limit'], 0) . ' up to ' . $php($g['max_comp'], 0), $pct($g['rate']), $pct($g['er_rate'])],
                   'lo' => $g['low_limit'] + 0.005, 'hi' => $g['max_comp'] + 0.005],
                  ['cells' => ['Over ' . $php($g['max_comp'], 0), $php($top) . ' (the most)', $php($g['max_comp'] * $g['er_rate']) . ' (the most)'],
                   'lo' => $g['max_comp'] + 0.005, 'hi' => null],
              ]];

    /* Withholding tax: the TRAIN tables (BIR Annex E) for the two pay schedules - the tax on a bracket's lower amount, plus a rate on the excess */
    $b = $R['bir'];
    foreach (['semi' => ['tax_semi', 'taxable_cutoff', 'Withholding tax - Semi-Monthly (each cut-off)'],
              'monthly' => ['tax_month', 'taxable_month', 'Withholding tax - Monthly']] as $table => [$key, $basis, $title]) {
        $br   = array_reverse($b['tables'][$table]);              /* lowest bracket first: [over, tax on that amount, rate] */
        $rows = [['cells' => ['-', $php($br[0][0], 0), 'No tax', '-'], 'lo' => 0.0, 'hi' => $br[0][0] + 0.005]];
        foreach ($br as $i => [$over, $base, $rate]) {
            $next = $br[$i + 1][0] ?? null;
            $rows[] = ['cells' => [$php($over, 0), $next === null ? 'no limit' : $php($next, 0), $php($base), $pct($rate) . ' of the excess over ' . $php($over, 0)],
                       'lo' => $over + 0.005, 'hi' => $next === null ? null : $next + 0.005];
        }
        $out[] = ['key' => $key, 'title' => $title, 'basis' => $basis,
                  'note' => 'The TRAIN graduated table on the taxable pay of ' . ($table === 'semi' ? 'one semi-monthly cut-off' : 'a month')
                          . ' (gross pay minus the employee\'s SSS, PhilHealth and Pag-IBIG). In effect since ' . $b['since'] . ' (' . $b['source'] . ').',
                  'head' => ['Taxable pay over', 'But not over', 'Tax on the "over" amount', 'Plus'],
                  'rows' => $rows];
    }
    return $out;
}

/*
 * SSS, PhilHealth, Pag-IBIG and withholding tax are each a MONTHLY amount typed on the employee. When the employee's month has more
 * than one pay run (a Semi-Monthly pay schedule), the employee's own contribution schedule (Employees > Add / Edit) says which run
 * takes each amount:
 *   first   the month's first run, in full
 *   split   every run takes its share of the month (half on a semi-monthly payroll), the last run takes the rest
 *   second  nothing until the month's last run, which takes it in full
 * Whatever the schedule, the month's last run settles anything still owed, so the month always ends at exactly the
 * amount typed. An employee on a Monthly pay schedule has one run a month, which takes everything. The defaults follow
 * L&N Pharmacy's own timesheets - SSS in full on the 1st cut-off, PhilHealth and Pag-IBIG in full on the 2nd, tax in shares.
 */
const CONTRIBUTION_TIMING_DEFAULT = ['sss' => 'first', 'philhealth' => 'second', 'pagibig' => 'second', 'tax' => 'split'];
const CONTRIBUTION_TIMING_CHOICES = ['first', 'split', 'second'];
const CONTRIBUTION_LABELS         = ['sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG', 'tax' => 'Withholding tax'];
const CONTRIBUTION_TIMING_LABELS  = ['first'  => '1st cut-off of the month, in full',
                                     'split'  => 'every cut-off, in equal shares',
                                     'second' => 'last cut-off of the month, in full'];

/*
 * How often an employee is paid - and so which pay periods they are in: a pay period takes the employees on its own schedule.
 * Semi-Monthly is the kinsenas (1-15 and 16-end), Monthly one run for the whole month. (A Weekly period is no longer offered; one
 * that an older database still holds keeps working and takes every employee, as it always did.)
 */
const PAY_SCHEDULES       = ['Semi-Monthly', 'Monthly'];
const PAY_SCHEDULE_LABELS = ['Semi-Monthly' => 'Semi-Monthly (kinsenas)',
                             'Monthly'      => 'Monthly (once a month)'];

/* When each of an employee's monthly amounts comes out: their own choice per amount, the default where it is blank or unknown */
function employeeTiming(array $emp): array {
    $out = [];
    foreach (CONTRIBUTION_TIMING_DEFAULT as $k => $def) {
        $v = $emp["{$k}_timing"] ?? '';
        $out[$k] = in_array($v, CONTRIBUTION_TIMING_CHOICES, true) ? $v : $def;
    }
    return $out;
}

/* Is this employee paid on this pay period's schedule? (payEmployee() row; a Weekly period takes everybody) */
function employeeInPeriod(array $emp, string $periodType): bool {
    return $periodType === 'Weekly' || ($emp['pay_schedule'] ?? 'Semi-Monthly') === $periodType;
}

/*
 * The schedule most active employees are paid on - what a new pay period starts with. Semi-Monthly when it is a tie, and when
 * nobody is on the payroll yet.
 */
function defaultPaySchedule(?PDO $db = null): string {
    try {
        $n = ($db ?? getDB())->query("SELECT pay_schedule, COUNT(*) FROM employees WHERE status = 'Active' GROUP BY pay_schedule")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return 'Semi-Monthly';
    }
    return (int)($n['Monthly'] ?? 0) > (int)($n['Semi-Monthly'] ?? 0) ? 'Monthly' : 'Semi-Monthly';
}

/*
 * How much of an employee's monthly amount this pay run takes, from that amount's schedule:
 *   rest   everything the month still owes - always on the month's last run, and on the month's first run for a "first" amount
 *   share  this run's share of the month (a "split" amount), never more than is still owed
 *   none   nothing - it comes out on another run
 */
function contributionTake(string $timing, array $ctx): string {
    if (!empty($ctx['final'])) return 'rest';
    if ($timing === 'first') return (int)($ctx['earlier_runs'] ?? 0) === 0 ? 'rest' : 'none';
    return $timing === 'split' ? 'share' : 'none';
}

/* One line on the screen: how this pay run takes the employees' monthly amounts (each employee's own contribution schedule decides, except on the month's last run) */
function contributionPlanText(array $ctx): string {
    if ($ctx['period_type'] === 'Monthly') return "the month's contributions and tax are taken in full";
    if ($ctx['final']) {
        return "last cut-off of the month: SSS, PhilHealth, Pag-IBIG and withholding tax are settled in full, minus what earlier cut-offs took";
    }
    return "SSS, PhilHealth, Pag-IBIG and withholding tax follow each employee's own contribution schedule";
}

/*
 * SSS, PhilHealth, Pag-IBIG and withholding tax for one employee on one pay run - what is DEDUCTED from their pay, and the
 * company's share paid on top.
 *
 * The amounts are the employee's own, typed by the admin (sss_amount, philhealth_amount, pagibig_amount, tax_amount): each
 * is a monthly amount, 0 meaning none. Nothing is worked out from pay. Which run takes it is the amount's schedule on the employee
 * (employeeTiming / contributionTake); a run takes what the month still owes, so the month adds up to exactly the
 * amount typed. If the amount is changed mid-month the last run settles the difference: a contribution never goes below
 * zero, tax is refunded when the month owes less than was withheld.
 *
 *   $basic     basic pay of this run (after absences and undertime) - only the company's Pag-IBIG rate reads it
 *
 * Returns
 *   earlier    what earlier runs of the month already deducted (EARLIER_NONE shape)
 *   month      the employee's monthly amounts: sss, philhealth, pagibig, tax
 *   timing     the employee's schedule per amount: 'first' | 'split' | 'second'
 *   on         per amount: the employee has one (> 0)
 *   take       per amount: 'rest' | 'share' | 'none' on this run (contributionTake)
 *   due        per amount: this run takes something (take is not 'none')
 *   ee         SSS, PhilHealth, Pag-IBIG deducted on this run
 *   tax        withholding tax deducted on this run (negative = a refund)
 *   er         the company's share of what this run deducted (employerShare)
 *   basic      basic pay so far this month
 *   ee_total, er_total
 */
function contributionBreakdown(array $emp, float $basic, array $ctx): array {
    $prev = $ctx['earlier'][$emp['emp_id'] ?? ''] ?? EARLIER_NONE;
    $cents = fn($v): int => (int)round((float)$v * 100);       /* whole centavos: no float noise in a split or a balance */

    $month = ['sss'        => max(0.0, (float)($emp['sss_amount']        ?? 0)),
              'philhealth' => max(0.0, (float)($emp['philhealth_amount'] ?? 0)),
              'pagibig'    => max(0.0, (float)($emp['pagibig_amount']    ?? 0)),
              'tax'        => max(0.0, (float)($emp['tax_amount']        ?? 0))];

    $timing = employeeTiming($emp);
    $take = $due = $on = $now = [];
    foreach ($month as $k => $amount) {
        $take[$k] = contributionTake($timing[$k], $ctx);
        $due[$k]  = $take[$k] !== 'none';
        $on[$k]   = $amount > 0;
        $owed     = $cents($amount) - $cents($prev[$k]);
        $c        = match ($take[$k]) {
            'rest'  => $owed,
            'share' => min((int)round($cents($amount) * $ctx['fraction']), $owed),
            default => 0,
        };
        /* only the month's last run may hand tax back */
        if ($k !== 'tax' || empty($ctx['final'])) $c = max(0, $c);
        $now[$k] = $c / 100.0;
    }
    $tax = $now['tax'];
    unset($now['tax']);
    $ee  = $now;

    $basicM = $prev['basic'] + $basic;
    $er     = employerShare($ee, $prev, $basicM);

    return [
        'basic'     => $basicM,       // basic pay so far this month (the Pag-IBIG company rate reads this)
        'earlier'   => $prev,         // what earlier cut-offs this month paid / deducted
        'month'     => $month,
        'timing'    => $timing,
        'on'        => $on,
        'take'      => $take,
        'due'       => $due,
        'ee'        => $ee,
        'tax'       => $tax,
        'er'        => $er,
        'ee_total'  => round(array_sum($ee), 2),
        'er_total'  => round(array_sum($er), 2),
    ];
}

/*
 * What the COMPANY pays on top of the employee shares one pay run deducted - from the deductions themselves, so the figure
 * follows what was really taken from pay (an employee's amount changing later does not rewrite earlier months).
 *   $ee           this run's sss, philhealth, pagibig deductions
 *   $prev         what earlier runs of the month deducted (EARLIER_NONE shape)
 *   $basicMonth   basic pay so far this month (the Pag-IBIG rate below ₱1,500)
 * SSS is the company's 10% against the employee's 5%; PhilHealth is split equally; Pag-IBIG is 2% each (the company still
 * pays 2% when a pay of ₱1,500 or less leaves the employee at 1%); Employees' Compensation is company-only, a flat ₱10 a
 * month - ₱30 from a ₱15,000 salary credit, which is an SSS share of ₱750 - owed from the first run that takes SSS, with
 * the difference added if a later run lifts the month's SSS across ₱750.
 * Returns sss, ec, philhealth, pagibig in pesos.
 */
function employerShare(array $ee, array $prev, float $basicMonth): array {
    $R        = PH_RULES;
    $pagRatio = $basicMonth <= $R['pagibig']['low_limit'] ? $R['pagibig']['er_rate'] / $R['pagibig']['rate_low'] : 1.0;
    $ecOf     = fn(float $sss) => $sss <= 0 ? 0.0
              : (round($sss / $R['sss']['ee_rate'], 2) >= $R['sss']['ec_from'] ? $R['sss']['ec_high'] : $R['sss']['ec_low']);
    $sssPrev  = (float)$prev['sss'];
    return [
        'sss'        => round($ee['sss'] * ($R['sss']['er_rate'] / $R['sss']['ee_rate']), 2),
        'ec'         => $ee['sss'] > 0 ? round(max(0.0, $ecOf($sssPrev + $ee['sss']) - $ecOf($sssPrev)), 2) : 0.0,
        'philhealth' => round((float)$ee['philhealth'], 2),
        'pagibig'    => round($ee['pagibig'] * $pagRatio, 2),
    ];
}

/* What earlier pay runs of the month carried, for an employee with none */
const EARLIER_NONE = ['g' => 0.0, 'basic' => 0.0, 'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'tax' => 0.0];

/* An employee's regular duty day in hours: their own, or Settings' standard */
function employeeDayHours(array $emp, array $ctx): float {
    $h = (float)($emp['hours_per_day'] ?? 0);
    return $h > 0 ? $h : $ctx['standard'];
}

/*
 * A row of the employees table, as the pay computation reads it. The employee code is employee_code in the table and
 * emp_id in this app's own arrays (EMPLOYEE_COLUMNS adds the alias); either is accepted.
 */
function payEmployee(array $e): array {
    $timing = employeeTiming($e);
    return [
        'id'                => (int)($e['id'] ?? 0),        /* employees.id - what payroll rows store as employee_id */
        'emp_id'            => (string)($e['emp_id'] ?? $e['employee_code'] ?? ''),
        'full_name'         => (string)($e['full_name'] ?? ''),
        'base_salary'       => (float)($e['base_salary'] ?? 0),
        'salary_type'       => $e['salary_type'] ?? 'monthly',
        /* how often they are paid - which pay periods they are in */
        'pay_schedule'      => in_array($e['pay_schedule'] ?? '', PAY_SCHEDULES, true) ? $e['pay_schedule'] : 'Semi-Monthly',
        /* the monthly amounts the admin typed on the employee; 0 = none */
        'sss_amount'        => (float)($e['sss_amount']        ?? 0),
        'philhealth_amount' => (float)($e['philhealth_amount'] ?? 0),
        'pagibig_amount'    => (float)($e['pagibig_amount']    ?? 0),
        'tax_amount'        => (float)($e['tax_amount']        ?? 0),
        /* and which cut-off of the month takes each one (first | split | second) */
        'sss_timing'        => $timing['sss'],
        'philhealth_timing' => $timing['philhealth'],
        'pagibig_timing'    => $timing['pagibig'],
        'tax_timing'        => $timing['tax'],
        'hours_per_day'     => isset($e['hours_per_day']) && $e['hours_per_day'] !== null ? (float)$e['hours_per_day'] : null,
        'rest_days'         => $e['rest_days'] ?? '7',
    ];
}

/*
 * Hours to pay from day-by-day records, the way the pharmacy's timesheet
 * pays them: each duty day is one full day (the employee's duty hours,
 * e.g. 8 or 10) minus its undertime - the timesheet's own undertime when it
 * states one, otherwise the hours short of a full day rounded to the
 * nearest hour (7:35 of 8 -> 0, 7:27 -> 1). A long day without approved
 * overtime is still one day.
 *   $days  rows with hours_worked, overtime_hours, late_hours, undertime_hours (null = not stated)
 */
function payHoursFromDays(array $days, float $dayHours): array {
    $t = ['hours' => 0.0, 'paid_hours' => 0.0, 'ot' => 0.0, 'late' => 0.0, 'under' => 0.0, 'days' => 0];
    foreach ($days as $d) {
        $h = (float)$d['hours_worked'];
        $t['hours'] += $h;
        $t['ot']    += (float)$d['overtime_hours'];
        $t['late']  += (float)$d['late_hours'];
        if ($h <= 0) continue;
        $t['days']++;
        $under = dayUndertime($d, $dayHours);
        $t['under']      += $under;
        $t['paid_hours'] += $dayHours - $under;
    }
    return $t;
}

/* One duty day's undertime in hours: the timesheet's own figure when it
   states one, otherwise the hours short of a full day to the nearest hour;
   never more than the day itself */
function dayUndertime(array $d, float $dayHours): float {
    $u = $d['undertime_hours'] ?? null;
    $under = ($u !== null && $u !== '') ? (float)$u : round(max(0.0, $dayHours - (float)$d['hours_worked']));
    return min(max(0.0, $under), $dayHours);
}

/*
 * One employee's pay for one payroll run - shared by the day-by-day and
 * totals uploads, manual attendance and leave decisions, so they can never
 * compute differently.
 *
 *   $emp        payEmployee() row
 *   $paidHours  regular hours to pay. A daily-rate employee earns their rate
 *               per duty day of THEIR duty hours (8 by default, 10 for a
 *               10-hour shift), so undertime costs rate ÷ duty hours an hour.
 *   $perDay     true when $paidHours came from day-by-day records. Lateness is
 *               then not charged separately - coming in late only costs pay if
 *               it leaves the day short, as undertime (the pharmacy's own
 *               rule). Settings' late rate applies only to totals files, which
 *               carry late hours but no days.
 *   $ctx        from payContext()
 *   $abs        'absent' => unexcused absent days, 'undertime' => hours short of
 *               full duty days, 'working_days' => this employee's working days
 *               in the month (days off left out). A salaried employee loses one
 *               day's rate per absent day and their hourly rate (a day's rate ÷
 *               duty hours) per undertime hour; days off and approved leave
 *               cost nothing. A daily-rate employee is simply paid for the
 *               duty hours in $paidHours, so their undertime is already out.
 *
 * Gross pay is everything earned: basic + overtime − late - what the
 * pharmacy's timesheet calls GROSS PAY. Then the employee's own monthly
 * SSS, PhilHealth, Pag-IBIG and withholding-tax amounts, as typed on the
 * employee, are deducted on the run(s) the employee's own contribution schedule
 * names (see contributionBreakdown) - they do not depend on what was earned. Net pay is
 * gross less those four.
 *
 * Returns gross, basic, overtime_pay, late_deduction, ot_late_adj (overtime pay - late deduction), sss, philhealth,
 * pagibig, tax, net, absent_deduction, undertime_hours, undertime_deduction.
 * (payroll.basic_pay, overtime_pay, tardy_undertime_deduction = late_deduction + undertime_deduction, absence_deduction.)
 */
function computePayLine(array $emp, float $paidHours, float $ot, float $late, bool $perDay, array $ctx, array $abs = []): array {
    $rate      = (float)$emp['base_salary'];
    $type      = $emp['salary_type'] ?? 'monthly';
    $frac      = $ctx['fraction'];
    $workDays  = max(1, (int)($abs['working_days'] ?? $ctx['working_days']));
    $dayHours  = employeeDayHours($emp, $ctx);
    $under     = max(0.0, (float)($abs['undertime'] ?? 0));
    $absentDed = 0.0;
    /* late is charged on its own only without day-by-day records (see $perDay) */
    $lateDeduction = $perDay ? 0.0 : round($late * $ctx['late_rate'], 2);

    /* what one duty hour is worth, in centavos × denominator (kept as integers: Labor Code overtime below) */
    if ($type === 'daily') {
        /* Daily rate × days worked, a day being the employee's duty hours.
           Undertime is already out of $paidHours; its cost is shown, not taken again. */
        $basic   = round($rate * $paidHours / $dayHours, 2);
        $underDed = round($rate * $under / $dayHours, 2);
        $payCents = (int)round($rate * 100);                       /* a day's pay … */
        $payDen   = 1;                                             /* … on one day */
    } else {
        /* Salaried, monthly or kinsenas: the slice of the month this run covers,
           less one day's rate (the month's pay over its working days) per absent
           day, and that day's rate ÷ duty hours per hour of undertime */
        $monthly   = monthlyEquivalent($type, $rate, $workDays);
        $dayRate   = $monthly / $workDays;
        $absentDed = round((float)($abs['absent'] ?? 0) * $dayRate, 2);
        $underDed  = round($under * $dayRate / $dayHours, 2);
        $basic     = max(0.0, round($monthly * $frac, 2) - $absentDed - $underDed);
        $payCents  = (int)round($monthly * 100);                   /* a month's pay … */
        $payDen    = $workDays;                                    /* … over its working days */
    }

    $otPay  = overtimePay($ot, $payCents, $payDen, $dayHours, $ctx);
    $otLate = round($otPay - $lateDeduction, 2);
    $gross  = round($basic + $otLate, 2);

    $c = contributionBreakdown($emp, $basic, $ctx);
    ['sss' => $sss, 'philhealth' => $ph, 'pagibig' => $pag] = $c['ee'];
    $tax = $c['tax'];

    $net = round($gross - ($tax + $sss + $ph + $pag), 2);

    return ['gross' => $gross, 'basic' => $basic, 'tax' => $tax, 'ot_late_adj' => $otLate,
            'overtime_pay' => $otPay, 'late_deduction' => $lateDeduction,
            'sss' => $sss, 'philhealth' => $ph, 'pagibig' => $pag, 'net' => $net,
            'absent_deduction' => $absentDed, 'undertime_hours' => round($under, 2),
            'undertime_deduction' => $underDed];
}

/*
 * Overtime pay for $ot hours, to the centavo.
 *   flat        every overtime hour pays Settings' overtime rate (the pharmacy's own sheets: ₱45)
 *   labor_code  the employee's hourly rate × the Settings multiplier (1.25 = Labor Code Art. 87, an ordinary day).
 *               The hourly rate is a day's pay ÷ the duty hours; a salaried employee's day's pay is the month's pay ÷
 *               working days. Worked in integers so a half centavo always rounds up.
 * $payCents ÷ $payDen is the pay of one duty day, in centavos.
 */
function overtimePay(float $ot, int $payCents, int $payDen, float $dayHours, array $ctx): float {
    if ($ot <= 0) return 0.0;
    if (($ctx['ot_method'] ?? 'flat') !== 'labor_code') return round($ot * $ctx['overtime_rate'], 2);
    $hh   = (int)round($ot * 100);                       /* hundredths of an hour */
    $dayH = (int)round($dayHours * 100);
    $mult = (int)round(($ctx['ot_multiplier'] ?? 1.25) * 100);
    $num  = $hh * $payCents * $mult;
    $den  = $dayH * 100 * max(1, $payDen);
    return intdiv(2 * $num + $den, 2 * $den) / 100;
}

/* employees.rest_days ("6,7") -> [6, 7]; ISO weekdays, 1 = Monday … 7 = Sunday */
function restDayList(?string $restDays): array {
    if ($restDays === null) return [7];
    return array_values(array_unique(array_filter(
        array_map('intval', explode(',', $restDays)), fn($d) => $d >= 1 && $d <= 7)));
}

/* "6,7" -> "Sat, Sun" for screens; '' -> "None" */
function restDayLabel(?string $restDays): string {
    $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $list  = restDayList($restDays);
    sort($list);
    return $list ? implode(', ', array_map(fn($d) => $names[$d], $list)) : 'None';
}

/* Working days in the month a period starts in - every day except the
   given days off (Sunday unless the employee's own schedule says otherwise) */
function workingDaysInMonth(string $periodStart, array $restDays = [7]): int {
    $first = date('Y-m-01', strtotime($periodStart));
    $n = 0;
    for ($d = 0, $len = (int)date('t', strtotime($first)); $d < $len; $d++) {
        if (!in_array((int)date('N', strtotime("+$d days", strtotime($first))), $restDays, true)) $n++;
    }
    return $n;
}

/*
 * What the schedule says about some employees over a date range:
 *   emp_id => ['rest'   => [ISO weekdays off],
 *              'branch' => branch name,
 *              'leave'  => ['YYYY-MM-DD' => ['status' => Approved|Pending|Rejected, 'type' => …]]]
 * Where leave requests overlap, Approved beats Pending beats Rejected.
 *
 * Deliberately NOT here: the employee's Date Hired. Which days are paid is decided by the uploaded timesheets and nothing
 * else (see dayStatus()) - an employee added today can have this month's or a past month's file uploaded and is paid for
 * the days that file holds, not for the days from a hire date to the end of the period.
 */
function attendanceCalendar(PDO $db, array $empIds, string $from, string $to): array {
    $empIds = array_values(array_unique(array_map('strval', $empIds)));
    if (!$empIds) return [];
    $in  = implode(',', array_fill(0, count($empIds), '?'));
    $cal = [];
    $st  = $db->prepare("SELECT " . EMPLOYEE_COLUMNS . " FROM employees e " . EMPLOYEE_JOINS . " WHERE e.employee_code IN ($in)");
    $st->execute($empIds);
    foreach ($st->fetchAll() as $e) {
        $cal[$e['emp_id']] = ['rest' => restDayList($e['rest_days'] ?? null),
                              'branch' => (string)($e['branch'] ?? ''), 'leave' => []];
    }
    $rank = ['Rejected' => 1, 'Pending' => 2, 'Approved' => 3];
    $st = $db->prepare("SELECT e.employee_code AS emp_id, lr.leave_type, lr.date_from, lr.date_to, lr.status
                          FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
                         WHERE e.employee_code IN ($in) AND lr.date_from <= ? AND lr.date_to >= ?");
    $st->execute(array_merge($empIds, [$to, $from]));
    foreach ($st->fetchAll() as $l) {
        if (!isset($cal[$l['emp_id']])) continue;
        $d    = max($l['date_from'], $from);
        $last = min($l['date_to'], $to);
        for (; $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $cur = $cal[$l['emp_id']]['leave'][$d] ?? null;
            if (!$cur || $rank[$l['status']] > $rank[$cur['status']]) {
                $cal[$l['emp_id']]['leave'][$d] = ['status' => $l['status'], 'type' => $l['leave_type']];
            }
        }
    }
    return $cal;
}

/*
 * One day for one employee, from attendanceCalendar():
 *   worked   - has hours that day (a day off worked still counts as worked)
 *   off      - their weekly day off, or a day the timesheet marks OFF
 *              ($markedOff): never absent, never deducted
 *   leave    - approved leave: not absent, no deduction (paid)
 *   absent   - a working day with no hours and no approved leave: not in the timesheet, so not paid
 *              (pending or rejected leave does not excuse it)
 * Nothing depends on when the employee was hired or added: the days that count are the days in the file.
 */
function dayStatus(?array $cal, string $date, bool $worked, bool $markedOff = false): string {
    if ($worked) return 'worked';
    if ($markedOff) return 'off';
    if (in_array((int)date('N', strtotime($date)), $cal['rest'] ?? [7], true)) return 'off';
    if (($cal['leave'][$date]['status'] ?? '') === 'Approved') return 'leave';
    return 'absent';
}

/*
 * The pay period a page opens on when none is picked: the one whose payroll
 * was computed most recently - what was just uploaded - else the newest one.
 * (Opening on the newest by date showed an old, unrelated period after an
 * upload into an earlier one.)
 */
function defaultPeriodId(PDO $db, array $periods): int {
    $ids  = array_map('intval', array_column($periods, 'id'));
    $last = (int)$db->query("SELECT period_id FROM payroll ORDER BY updated_at DESC, id DESC LIMIT 1")->fetchColumn();
    return ($last && in_array($last, $ids, true)) ? $last : ($ids[0] ?? 0);
}

/* A period's pay schedule: its own - or, for one that has none, the schedule most employees are on (defaultPaySchedule). 'Weekly' is
   only read: it is no longer offered, but a period an older database holds keeps working. */
function periodType(?string $stored): string {
    return in_array($stored, ['Monthly', 'Semi-Monthly', 'Weekly'], true) ? $stored : defaultPaySchedule();
}

/* "Semi-Monthly · 1st half" etc., for period pickers and headings */
function periodTypeLabel(array $period): string {
    $type = periodType($period['period_type'] ?? null);
    if ($type !== 'Semi-Monthly') return $type;
    return 'Semi-Monthly · ' . ((int)date('j', strtotime($period['start_date'])) <= 15 ? '1st half' : '2nd half');
}

/*
 * Everything computePayLine() needs from Settings, the period and the
 * month's earlier pay runs. The arithmetic lives in buildPayContext(), which
 * touches no database, so it can be checked against sample timesheets.
 */
function payContext(PDO $db, int $periodId): array {
    $st = $db->prepare("SELECT start_date, end_date, period_type FROM payroll_periods WHERE id = ?");
    $st->execute([$periodId]);
    $row   = $st->fetch() ?: ['start_date' => date('Y-m-01'), 'end_date' => date('Y-m-t'), 'period_type' => null];
    $start = $row['start_date'];

    /*
     * Contributions and tax are settled per calendar MONTH. What earlier pay
     * runs of this same month already earned, deducted and withheld, per
     * employee - each cut-off then only takes what is still due.
     * (basic_pay is the basic pay of the run; gross_pay also holds overtime and the late deduction.)
     */
    $monthStart  = date('Y-m-01', strtotime($start));
    $earlier     = [];
    $earlierRuns = 0;
    try {
        $st = $db->prepare("
            SELECT e.employee_code AS emp_id, SUM(p.gross_pay) g, SUM(p.basic_pay) basic,
                   SUM(p.sss_deduction) s, SUM(p.philhealth_deduction) ph, SUM(p.pagibig_deduction) pg, SUM(p.withholding_tax) tax
              FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
              JOIN employees e ON e.id = p.employee_id
             WHERE pp.id <> ? AND pp.start_date >= ? AND pp.end_date < ?
             GROUP BY e.id, e.employee_code");
        /* only runs that ENDED before this one began - an overlapping period
           (e.g. an old whole-month period over the same days) is not history */
        $st->execute([$periodId, $monthStart, $start]);
        foreach ($st->fetchAll() as $r) {
            $earlier[$r['emp_id']] = ['g' => (float)$r['g'], 'basic' => (float)$r['basic'], 'sss' => (float)$r['s'],
                                      'philhealth' => (float)$r['ph'], 'pagibig' => (float)$r['pg'], 'tax' => (float)$r['tax']];
        }
        /* the earlier runs of THIS schedule: a Monthly run is not an earlier cut-off for the kinsenas employees, and the other way round */
        $st = $db->prepare("SELECT COUNT(*) FROM payroll_periods WHERE id <> ? AND start_date >= ? AND end_date < ? AND period_type = ?");
        $st->execute([$periodId, $monthStart, $start, (string)($row['period_type'] ?? '')]);
        $earlierRuns = (int)$st->fetchColumn();
    } catch (PDOException $e) { /* older database: no history, each run stands alone */ }

    return buildPayContext($row, $earlier, $earlierRuns);
}

/*
 * The pay context for a period row (start_date, end_date, period_type),
 * given what earlier runs of the month carried (emp_id => EARLIER_NONE shape).
 */
function buildPayContext(array $period, array $earlier = [], int $earlierRuns = 0): array {
    $start = $period['start_date'];
    $end   = $period['end_date'] ?? $start;
    $type  = periodType($period['period_type'] ?? null);

    /* The month's last pay run - where the month is settled in full */
    $final = $type === 'Monthly'
          || date('Y-m', strtotime($end . ' +1 day')) !== date('Y-m', strtotime($start))
          || ($type === 'Weekly' && date('Y-m', strtotime($end . ' +7 days')) !== date('Y-m', strtotime($start)));

    return [
        'overtime_rate'    => (float)getSetting('overtime_rate', '150'),
        /* how overtime is paid: 'flat' = Settings' peso rate for everybody (the default, as the pharmacy's sheets do);
           'labor_code' = the employee's own hourly rate × ot_multiplier (1.25 on an ordinary day, Labor Code Art. 87) */
        'ot_method'        => getSetting('overtime_method', 'flat') === 'labor_code' ? 'labor_code' : 'flat',
        'ot_multiplier'    => min(3.0, max(1.0, (float)getSetting('overtime_multiplier', '1.25'))),
        'late_rate'        => (float)getSetting('late_rate', '80'),
        'period_type'      => $type,
        'fraction'         => periodFraction($type),
        'semi'             => $type === 'Semi-Monthly',
        'half'             => (int)date('j', strtotime($start)) <= 15 ? 1 : 2,
        'final'            => $final,        /* last pay run of the month */
        'earlier'          => $earlier,      /* emp_id => already earned / deducted / withheld this month */
        'earlier_runs'     => $earlierRuns,  /* earlier pay periods this month in the system */
        'working_days'     => workingDaysInMonth($start),
        'standard'         => max(1.0, (float)getSetting('standard_hours', '8')),
    ];
}

/* =============================================================
 *  Pay-period arithmetic
 *
 *  An employee's rate is quoted per month, per kinsena or per day,
 *  and an employee's SSS, PhilHealth, Pag-IBIG and tax amounts are
 *  MONTHLY. A payroll run, though, covers what its pay period says - a whole
 *  month or a kinsena (half a month); an older database may hold a week.
 *
 *  So the computation works in two steps:
 *    1. monthlyEquivalent() - what this employee earns in a month,
 *       whichever way their rate happens to be quoted.
 *    2. periodFraction()    - how much of a month this run covers.
 *  Basic pay is (1) scaled by (2); a monthly amount that the schedule
 *  splits across the cut-offs is scaled by (2) the same way.
 * ============================================================= */

/*
 * How much of a month one payroll period covers.
 * Semi-Monthly is the kinsenas schedule: two runs per month.
 */
function periodFraction(?string $periodType = null): float {
    return match (periodType($periodType)) {
        'Semi-Monthly' => 0.5,
        'Weekly'       => 12.0 / 52.0,
        default        => 1.0,      /* Monthly */
    };
}

/*
 * An employee's full monthly-equivalent salary.
 *   monthly  - base_salary is already a month's pay
 *   kinsenas - base_salary is half a month's pay, so a month is twice it
 *   daily    - base_salary is one day's pay, times the month's working days
 */
function monthlyEquivalent(string $salaryType, float $baseSalary, int $workingDaysInMonth = 26): float {
    return match ($salaryType) {
        'kinsenas' => $baseSalary * 2.0,
        'daily'    => $baseSalary * $workingDaysInMonth,
        default    => $baseSalary,
    };
}

/*
 * Labor Code Art. 87: overtime on an ordinary working day is paid at the hourly rate PLUS at least 25% of it.
 * Under the default 'flat' method every employee gets Settings' one peso rate per overtime hour, which for a
 * ₱480 day (₱60/h) is below the legal ₱75/h. This lists the active employees the flat rate underpays:
 *   [ ['emp_id', 'full_name', 'legal' => ₱/h the law requires, 'paid' => ₱/h the flat rate pays, 'gap' => ₱/h short], … ]
 * Empty when overtime is paid by the Labor Code method, or when the flat rate is high enough for everyone.
 * (Rest-day, holiday and night-shift premiums are higher still - they are not modelled; see tests/AUDIT_FINDINGS.md D-14.)
 */
function overtimeShortfalls(PDO $db): array {
    if (getSetting('overtime_method', 'flat') === 'labor_code') return [];
    $flat = (float)getSetting('overtime_rate', '150');
    $std  = max(1.0, (float)getSetting('standard_hours', '8'));
    $out  = [];
    foreach ($db->query("SELECT * FROM employees WHERE status = 'Active' ORDER BY full_name")->fetchAll() as $row) {
        $e = payEmployee($row);
        if ($e['base_salary'] <= 0) continue;
        $workDays = max(1, workingDaysInMonth(date('Y-m-01'), restDayList($e['rest_days'])));
        $dayRate  = monthlyEquivalent($e['salary_type'], $e['base_salary'], $workDays) / $workDays;
        $hours    = $e['hours_per_day'] !== null && $e['hours_per_day'] > 0 ? $e['hours_per_day'] : $std;
        $legal    = round($dayRate / $hours * 1.25, 2);
        if ($legal > $flat + 0.004) {
            $out[] = ['emp_id' => $e['emp_id'], 'full_name' => $e['full_name'], 'legal' => $legal, 'paid' => round($flat, 2),
                      'gap' => round($legal - $flat, 2)];
        }
    }
    return $out;
}

/* How a salary type is written on screen. */
function salaryTypeLabel(string $salaryType): string {
    return match ($salaryType) {
        'kinsenas' => 'Kinsenas',
        'daily'    => 'Daily',
        default    => 'Monthly',
    };
}

/*
 * A name reduced to a comparable key: lowercase, punctuation dropped, words
 * sorted - so "DELA CRUZ, Juan" and "Juan Dela Cruz" come out the same.
 */
function nameKey(string $name): string {
    $name  = strtolower(trim($name));
    $name  = preg_replace('/[^a-z0-9\s]+/', ' ', $name);
    $words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    sort($words);
    return implode(' ', $words);
}

/*
 * Matches an uploaded row to an employee in Employee Management.
 *
 * The biometric terminal numbers its people its own way (1, 2, 00003 ...),
 * so the ID in an uploaded file usually is NOT the system emp_id. The name
 * is what both sides share, so it is tried first; the file's ID is only a
 * fallback for when no name matches (or two employees share one name).
 *
 * Returns a function (fileId, fileName) => system emp_id, or null.
 */
function employeeResolver(PDO $db): callable {
    $ids    = [];
    $byName = [];
    foreach ($db->query("SELECT employee_code AS emp_id, full_name FROM employees")->fetchAll() as $e) {
        $id       = (string)$e['emp_id'];
        $ids[$id] = true;
        $byName[nameKey($e['full_name'])][] = $id;
    }

    return function (string $fileId, string $fileName) use ($ids, $byName): ?string {
        $key = nameKey($fileName);
        if ($key !== '' && isset($byName[$key]) && count($byName[$key]) === 1) {
            return $byName[$key][0];
        }
        if (isset($ids[$fileId])) return $fileId;

        $stripped = ltrim($fileId, '0');
        if ($stripped !== '' && isset($ids[$stripped])) return $stripped;

        return null;
    };
}

/*
 * Who is calling an attendance-upload endpoint.
 *   admin   -> ['role' => 'admin',   'scope' => null]   whole company
 *   manager -> ['role' => 'manager', 'scope' => [ids]]  their employees
 *                                    (scope null = an unscoped manager)
 * Anyone else is turned away with a 401.
 */
function attendanceUploader(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    applySchemaPatches();   /* new columns the uploads write (one query once in place) */
    if (!empty($_SESSION['logged_in'])) {
        return ['role' => 'admin', 'scope' => null];
    }
    if (!empty($_SESSION['mgr_logged_in'])) {
        require_once __DIR__ . '/../manager/includes/auth.php';
        return ['role' => 'manager', 'scope' => mgrEmpIds()];
    }
    jsonResponse(['error' => 'Unauthorized'], 401);
}

/*
 * Uploads (and manual entries) only go into a period that is still Open -
 * for the admin too. Writing into a finalized period would quietly turn its
 * locked payroll back into a draft.
 */
function uploadPeriodGuard(PDO $db, int $periodId, array $who): void {
    $st = $db->prepare("SELECT status, period_label FROM payroll_periods WHERE id = ?");
    $st->execute([$periodId]);
    $per = $st->fetch();
    if (!$per) {
        jsonResponse(['error' => 'Unknown payroll period'], 404);
    }
    if ($per['status'] !== 'Open') {
        jsonResponse(['error' => $who['role'] === 'manager'
            ? "{$per['period_label']} is finalized. Ask the admin to unlock it before uploading."
            : "{$per['period_label']} is finalized. Unlock it in Payroll Processing before uploading, so the locked payroll is not changed by accident."], 403);
    }
}

/*
 * Brings a period's attendance_summary + payroll rows in line with freshly computed
 * ones. Uploads repeat - today's file, then tomorrow's, or the same sheet
 * again with one more line per person - so this must be safe to run any
 * number of times:
 *   - rows are updated IN PLACE (insert or update on period + employee), so a
 *     payroll line keeps its id: its payslip signature, its receipt number
 *     and its revision history stay attached to it. Only the lines of
 *     employees (in scope) who are no longer in the period are removed;
 *   - it all happens in one transaction, so two saves at once (a
 *     double-click, two tabs) cannot leave an employee with two rows;
 *   - bonuses and deductions recorded in Adjustments stay on the row and in
 *     its net pay;
 *   - a manager's approval stays on an employee whose hours did not change.
 *     New days mean new hours, and those go back to Pending for review;
 *   - in a period that was already finalized once (and re-opened), a line
 *     whose net pay changes is flagged Revised.
 * Attendance tuples (attendance_summary): period, emp, total hours worked, overtime hours, late hours, undertime hours,
 *   absent days, leave days, days off, manually entered (0/1).
 * Payroll tuples: period, emp, basic pay, overtime pay, gross pay, SSS, PhilHealth, Pag-IBIG, withholding tax,
 *   tardy + undertime deduction, absence deduction, net pay (before bonus and deductions).
 * "emp" is the emp_id (EMP-004, the employee_code column); the rows are stored under that employee's employees.id.
 * A tuple for an emp_id that is not an employee is left out.
 */
function rewritePeriodRows(PDO $db, int $periodId, array $who, array $attRows, array $payRows): void {
    $st = $db->prepare("SELECT finalize_count FROM payroll_periods WHERE id = ?");
    $st->execute([$periodId]);
    $reopened = (int)$st->fetchColumn() > 0 ? 1 : 0;

    /* emp_id -> employees.id in every tuple */
    $pk = employeePks($db, array_merge(array_column($attRows, 1), array_column($payRows, 1)));
    $toPk = function (array $rows) use ($pk): array {
        $out = [];
        foreach ($rows as $r) {
            if (!isset($pk[(string)$r[1]])) continue;
            $r[1] = $pk[(string)$r[1]];
            $out[] = $r;
        }
        return $out;
    };
    $attRows = $toPk($attRows);
    $payRows = $toPk($payRows);

    $db->beginTransaction();
    try {
        /* Lines of employees (in scope) who are no longer in the period */
        $keep = array_map(fn($p) => (int)$p[1], $payRows);
        foreach (['attendance_summary', 'payroll'] as $table) {
            $sql  = "DELETE FROM $table WHERE period_id = ?";
            $args = [$periodId];
            if ($who['scope'] !== null) {
                if (!$who['scope']) continue;
                $sql .= ' AND employee_id IN (SELECT id FROM employees WHERE employee_code IN ('
                      . implode(',', array_fill(0, count($who['scope']), '?')) . '))';
                $args = array_merge($args, array_values($who['scope']));
            }
            if ($keep) {
                $sql .= ' AND employee_id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')';
                $args = array_merge($args, $keep);
            }
            $db->prepare($sql)->execute($args);
        }

        /* Approval survives only while the hours are unchanged. MySQL applies
           the SET list left to right, so the checks come before the hours. */
        $same = "total_hours_worked = VALUES(total_hours_worked) AND total_overtime_hours = VALUES(total_overtime_hours)
                 AND total_late_hours = VALUES(total_late_hours)";
        bulkInsert($db,
            "INSERT INTO attendance_summary (period_id,employee_id,total_hours_worked,total_overtime_hours,total_late_hours,
                                             total_undertime_hours,absent_days,leave_days,days_off,is_manually_entered) VALUES",
            "(?,?,?,?,?,?,?,?,?,?)", $attRows,
            "ON DUPLICATE KEY UPDATE
                approval_status = IF($same, approval_status, 'Pending'),
                approved_by     = IF($same, approved_by, NULL),
                approved_at     = IF($same, approved_at, NULL),
                total_hours_worked = VALUES(total_hours_worked), total_overtime_hours = VALUES(total_overtime_hours),
                total_late_hours = VALUES(total_late_hours), total_undertime_hours = VALUES(total_undertime_hours),
                absent_days = VALUES(absent_days), leave_days = VALUES(leave_days), days_off = VALUES(days_off),
                is_manually_entered = VALUES(is_manually_entered)");

        /* The new net keeps the row's own bonus and deductions. Revised is
           decided before net_pay is overwritten (left-to-right again). */
        $newNet  = "(VALUES(net_pay) + bonus_pay - other_deductions)";
        $changed = "($reopened = 1 AND ABS(net_pay - $newNet) >= 0.005)";
        bulkInsert($db,
            "INSERT INTO payroll (period_id,employee_id,basic_pay,overtime_pay,gross_pay,sss_deduction,philhealth_deduction,
                                  pagibig_deduction,withholding_tax,tardy_undertime_deduction,absence_deduction,net_pay,status) VALUES",
            "(?,?,?,?,?,?,?,?,?,?,?,?,'Draft')", $payRows,
            "ON DUPLICATE KEY UPDATE
                revised_at = IF($changed, NOW(), revised_at),
                revised_after_finalize = IF($changed, 1, revised_after_finalize),
                net_pay = $newNet,
                basic_pay = VALUES(basic_pay), overtime_pay = VALUES(overtime_pay), gross_pay = VALUES(gross_pay),
                sss_deduction = VALUES(sss_deduction), philhealth_deduction = VALUES(philhealth_deduction),
                pagibig_deduction = VALUES(pagibig_deduction), withholding_tax = VALUES(withholding_tax),
                tardy_undertime_deduction = VALUES(tardy_undertime_deduction), absence_deduction = VALUES(absence_deduction),
                status = 'Draft', updated_at = CURRENT_TIMESTAMP");
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}

/*
 * Rebuilds a period's attendance + payroll from every day saved inside its dates in
 * biometric_daily - earlier uploads, today's upload and manual entries alike.
 * This is what lets days accumulate: each save adds or replaces only its own
 * days, then the whole period is summed again. A manager recomputes only
 * their own employees. Returns how many employees got a payroll line.
 */
function recomputePeriodFromDaily(PDO $db, int $periodId, array $who): int {
    $ctx = payContext($db, $periodId);

    $pr = $db->prepare("SELECT start_date, end_date FROM payroll_periods WHERE id = ?");
    $pr->execute([$periodId]);
    $per = $pr->fetch();

    $emps = [];
    foreach ($db->query("SELECT * FROM employees")->fetchAll() as $er) $emps[$er['employee_code']] = payEmployee($er);
    $inScope = fn($id) => $who['scope'] === null || in_array((string)$id, array_map('strval', $who['scope']), true);
    /* A pay period takes the employees paid on its schedule: a Monthly employee is not in a Semi-Monthly cut-off and the other way
       round. Their days are saved all the same and count in the pay period of their own schedule. */
    $onSchedule = fn($id) => isset($emps[$id]) && employeeInPeriod($emps[$id], $ctx['period_type']);

    /* Every day saved inside the period's dates (a day belongs to the period that holds its date) */
    $st = $db->prepare("SELECT e.employee_code AS emp_id, b.att_date, b.hours_worked, b.overtime_hours, b.late_hours, b.undertime_hours, b.is_day_off, b.entered_by
                          FROM biometric_daily b JOIN employees e ON e.id = b.employee_id
                         WHERE b.att_date BETWEEN ? AND ?");
    $st->execute([$per['start_date'], $per['end_date']]);
    $byEmp   = [];   /* emp_id => that employee's day rows */
    $worked  = [];   /* emp_id => [date => true]: days with hours */
    $offDay  = [];   /* emp_id => [date => true]: days the timesheet marks OFF */
    $covered = [];   /* emp_id => last date any record covers (worked, off or a 0-hour day) */
    $manual  = [];   /* emp_id => true: a day was typed in by hand (Manual Attendance) */
    foreach ($st->fetchAll() as $d) {
        $byEmp[$d['emp_id']][] = $d;
        if ((float)$d['hours_worked'] > 0) $worked[$d['emp_id']][$d['att_date']] = true;
        elseif ((int)$d['is_day_off'] === 1) $offDay[$d['emp_id']][$d['att_date']] = true;
        $covered[$d['emp_id']] = max($covered[$d['emp_id']] ?? '', $d['att_date']);
        if ($d['entered_by'] !== null) $manual[$d['emp_id']] = true;
    }

    /* One line per employee: someone whose only records are days off gets no payroll line. */
    $lines = [];
    foreach ($byEmp as $id => $days) {
        if ($onSchedule($id) && $inScope($id) && (isset($worked[$id]) || count($days) > count($offDay[$id] ?? []))) {
            $lines[$id] = true;
        }
    }

    /*
     * Absences. Every day of the period is one of: worked, day off (weekly, or
     * marked OFF on the timesheet), approved leave, or absent (not in the file).
     *
     * The days to compute are the days IN THE FILE - not the days from a hire
     * date, not "from the employee's first day to the end of the period". For a
     * monthly / kinsenas salary a working day is paid only when the timesheet
     * accounts for it (worked, marked OFF, or approved leave); every other
     * working day of the period is unpaid, uploaded yet or not. So a new
     * employee with 3 days in the file is paid 3 days, and this month's
     * partial file is paid for the days uploaded so far.
     * A daily-rate employee is simply paid for the hours in the file; their
     * absences are only SHOWN, and only for days the uploads have reached - up
     * to the latest day any record of the employee's branch covers.
     */
    /* Someone on approved leave with no hours at all still gets a line */
    $st = $db->prepare("SELECT DISTINCT e.employee_code FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
                         WHERE lr.status = 'Approved' AND lr.date_from <= ? AND lr.date_to >= ?");
    $st->execute([$per['end_date'], $per['start_date']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ($onSchedule($id) && $inScope($id)) $lines[$id] = true;
    }

    $cal = attendanceCalendar($db, array_merge(array_keys($lines), array_keys($covered)),
                              $per['start_date'], $per['end_date']);
    $judgedTo = [];   /* branch => last day the uploads cover */
    foreach ($covered as $id => $last) {
        $b = $cal[$id]['branch'] ?? '';
        $judgedTo[$b] = max($judgedTo[$b] ?? '', $last);
    }

    $attRows = [];
    $payRows = [];
    foreach (array_keys($lines) as $id) {
        $emp    = $emps[$id];
        $absent = $leave = $off = 0;
        $upTo   = $judgedTo[$cal[$id]['branch'] ?? ''] ?? '';
        $salary = $emp['salary_type'] !== 'daily';
        for ($d = $per['start_date']; $d <= $per['end_date']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $s = dayStatus($cal[$id] ?? null, $d, isset($worked[$id][$d]), isset($offDay[$id][$d]));
            if ($s === 'leave')                    $leave++;
            if ($s === 'off'    && $d <= $upTo)    $off++;
            /* A working day the file does not account for is unpaid on a salary (absent_days / absence_deduction, one day's rate each),
               however far the uploads have got; for a daily rate it is only shown, up to the last uploaded day */
            if ($s === 'absent' && ($salary || $d <= $upTo)) $absent++;
        }

        /* Each duty day pays one full day of the employee's duty hours, less
           undertime (payHoursFromDays). Approved leave is paid: a daily-rate
           employee gets a full duty day for each leave day. */
        $dayHours = employeeDayHours($emp, $ctx);
        $t    = payHoursFromDays($byEmp[$id] ?? [], $dayHours);
        $paid = $t['paid_hours'];
        if ($emp['salary_type'] === 'daily') $paid += $leave * $dayHours;
        $pay = computePayLine($emp, $paid, $t['ot'], $t['late'], true, $ctx, [
            'absent'       => $absent,
            'undertime'    => $t['under'],
            'working_days' => workingDaysInMonth($per['start_date'], $cal[$id]['rest'] ?? [7]),
        ]);

        $attRows[] = [$periodId, $id, $t['hours'], $t['ot'], $t['late'], $pay['undertime_hours'], $absent, $leave, $off, empty($manual[$id]) ? 0 : 1];
        $payRows[] = [$periodId, $id, $pay['basic'], $pay['overtime_pay'], $pay['gross'], $pay['sss'], $pay['philhealth'], $pay['pagibig'], $pay['tax'],
                      round($pay['late_deduction'] + $pay['undertime_deduction'], 2), $pay['absent_deduction'], $pay['net']];
    }

    rewritePeriodRows($db, $periodId, $who, $attRows, $payRows);
    return count($payRows);
}

/*
 * Approve or reject a leave request - admin and manager alike - then bring
 * the payroll in line with it straight away:
 *   Approved  the days are leave: not absent, no deduction (paid)
 *   Rejected  an unworked day is absent: no pay for it
 * $scope: null = may decide for anyone, else the emp_ids allowed (manager).
 * Returns ['type' => success|error, 'text' => message].
 */
function decideLeave(PDO $db, int $id, string $action, string $note, ?array $scope): array {
    if (!in_array($action, ['approve', 'reject'], true)) return ['type' => 'error', 'text' => 'Unknown action.'];
    $st = $db->prepare("SELECT lr.*, e.employee_code AS emp_id FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE lr.id = ?");
    $st->execute([$id]);
    $lr = $st->fetch();
    if (!$lr || ($scope !== null && !in_array($lr['emp_id'], $scope, true))) {
        return ['type' => 'error', 'text' => 'Leave request not found among your employees.'];
    }
    $status = $action === 'approve' ? 'Approved' : 'Rejected';
    $db->prepare("UPDATE leave_requests SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE id = ?")
       ->execute([$status, currentUserId(), $note, $id]);

    $text = "Leave request {$status}.";
    try {
        $periods = recomputeEmployeeOpenPeriods($db, $lr['emp_id'], $lr['date_from'], $lr['date_to']);
        if ($periods) {
            $text .= ' Payroll updated for ' . implode(', ', $periods) . ($status === 'Approved'
                ? ' - the leave days are not counted as absent.'
                : ' - days not worked count as absent, without pay.');
        }
    } catch (PDOException $e) {
        $text .= ' The payroll could not be updated yet (Reference: ' . logAppError($e) . '); it will be on the next upload.';
    }
    /* A finalized period is never changed behind the admin's back */
    $st = $db->prepare("SELECT period_label FROM payroll_periods
                         WHERE status <> 'Open' AND start_date <= ? AND end_date >= ?");
    $st->execute([$lr['date_to'], $lr['date_from']]);
    if ($locked = $st->fetchAll(PDO::FETCH_COLUMN)) {
        $text .= ' ' . implode(', ', $locked) . ' is finalized, so its payroll was not changed - '
               . 'unlock it in Payroll Processing and upload its attendance again to apply this decision.';
    }
    return ['type' => 'success', 'text' => $text];
}

/*
 * After a leave decision or a day-off change: recompute this employee's
 * line in every OPEN pay period overlapping from..to that is built from
 * day-by-day records. Finalized periods are left exactly as they were.
 * Returns the labels of the periods recomputed.
 */
function recomputeEmployeeOpenPeriods(PDO $db, string $empId, string $from, string $to): array {
    $st = $db->prepare("SELECT p.id FROM payroll_periods p
                         WHERE p.status = 'Open' AND p.start_date <= ? AND p.end_date >= ?
                           AND EXISTS (SELECT 1 FROM biometric_daily b WHERE b.att_date BETWEEN p.start_date AND p.end_date)");
    $st->execute([$to, $from]);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[(int)$id] = true;
        foreach (laterPeriodsInMonth($db, (int)$id) as $later) $ids[$later] = true;
    }
    if (!$ids) return [];

    /* oldest first: each cut-off settles the month against the ones before it */
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, period_label FROM payroll_periods WHERE id IN ($in) ORDER BY start_date");
    $st->execute(array_keys($ids));
    $done = [];
    foreach ($st->fetchAll() as $p) {
        recomputePeriodFromDaily($db, (int)$p['id'], ['role' => 'manager', 'scope' => [$empId]]);
        $done[] = $p['period_label'];
    }
    return $done;
}

/*
 * Open pay periods built from day-by-day records that come AFTER this one
 * in the same calendar month, oldest first. Their contributions and tax
 * settle the month against what this period deducted, so whenever this
 * period changes they have to be recomputed as well.
 */
function laterPeriodsInMonth(PDO $db, int $periodId): array {
    $st = $db->prepare("SELECT start_date, end_date, period_type FROM payroll_periods WHERE id = ?");
    $st->execute([$periodId]);
    $p = $st->fetch();
    if (!$p) return [];
    /* only the cut-offs of the same schedule: they hold the same employees, so they are the ones that settle against this one */
    $st = $db->prepare("SELECT l.id FROM payroll_periods l
                         WHERE l.status = 'Open' AND l.id <> ? AND l.start_date > ? AND l.start_date <= ? AND l.period_type = ?
                           AND EXISTS (SELECT 1 FROM biometric_daily b WHERE b.att_date BETWEEN l.start_date AND l.end_date)
                         ORDER BY l.start_date");
    $st->execute([$periodId, $p['end_date'], date('Y-m-t', strtotime($p['start_date'])), $p['period_type']]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/*
 * Recompute a period from its saved days, then the later cut-offs of the
 * same month that settle against it. Returns the first period's line count.
 */
function recomputeMonthFrom(PDO $db, int $periodId, array $who): int {
    $n = recomputePeriodFromDaily($db, $periodId, $who);
    foreach (laterPeriodsInMonth($db, $periodId) as $later) recomputePeriodFromDaily($db, $later, $who);
    return $n;
}

/*
 * Days were saved or changed in this period's dates: besides the period itself (recomputeMonthFrom), every OTHER open pay period
 * that holds some of those dates is brought up to date too. Employees paid on different schedules are in different pay periods
 * over the same dates (a Monthly run and the two kinsenas cut-offs), and a saved day counts in the period of its employee's schedule.
 * Returns the labels of the other periods recomputed.
 */
function recomputeParallelPeriods(PDO $db, int $periodId, array $who): array {
    $st = $db->prepare("SELECT o.id, o.period_label FROM payroll_periods o JOIN payroll_periods p ON p.id = ?
                         WHERE o.id <> p.id AND o.status = 'Open' AND o.start_date <= p.end_date AND o.end_date >= p.start_date
                           AND EXISTS (SELECT 1 FROM biometric_daily b WHERE b.att_date BETWEEN o.start_date AND o.end_date)
                         ORDER BY o.start_date, o.id");
    $st->execute([$periodId]);
    $done = [];
    foreach ($st->fetchAll() as $o) {
        recomputeMonthFrom($db, (int)$o['id'], $who);
        $done[] = $o['period_label'];
    }
    return $done;
}

/*
 * Does this pay run still settle its month the way the cut-offs BEFORE it now stand?
 *
 * Each run takes the employee's monthly SSS / PhilHealth / Pag-IBIG and tax amounts minus what earlier runs already took, so a
 * later run is only right while the earlier ones are unchanged. Open later runs are re-settled automatically; a FINALIZED one
 * is never changed behind the admin's back - so if an earlier cut-off is unlocked and corrected, or an employee's amount is
 * edited, the finalized one quietly stops adding up. This finds those lines by working out what the same line would deduct
 * against the month and the employee as they are NOW (the same arithmetic as computePayLine) and comparing with what is
 * stored. Nothing is stored or changed.
 * Returns emp_id => [column => [stored, now]] for every line that differs.
 */
function settlementDrift(PDO $db, int $periodId, ?array $ctx = null): array {
    $ctx ??= payContext($db, $periodId);
    $emps = [];
    foreach ($db->query("SELECT * FROM employees")->fetchAll() as $er) $emps[$er['employee_code']] = payEmployee($er);
    $st = $db->prepare("SELECT p.*, e.employee_code AS emp_id FROM payroll p JOIN employees e ON e.id = p.employee_id WHERE p.period_id = ?");
    $st->execute([$periodId]);
    $out = [];
    /* the answer is keyed by the short names; the payroll columns are the *_deduction ones */
    $column = ['sss' => 'sss_deduction', 'philhealth' => 'philhealth_deduction', 'pagibig' => 'pagibig_deduction', 'withholding_tax' => 'withholding_tax'];
    foreach ($st->fetchAll() as $r) {
        $emp = $emps[$r['emp_id']] ?? payEmployee(['emp_id' => $r['emp_id']]);
        $c   = contributionBreakdown($emp, (float)$r['basic_pay'], $ctx);
        $now = ['sss' => $c['ee']['sss'], 'philhealth' => $c['ee']['philhealth'], 'pagibig' => $c['ee']['pagibig'], 'withholding_tax' => $c['tax']];
        foreach ($now as $key => $v) {
            $stored = (float)$r[$column[$key]];
            if (abs($v - $stored) >= 0.005) $out[$r['emp_id']][$key] = [$stored, $v];
        }
    }
    return $out;
}

/*
 * Should a FINALIZED period be reported as out of date? Only when an earlier cut-off of its own month was changed AFTER it
 * was finalized: still open for correction, or finalized again later. A raise, an employee switch or a Settings change made
 * months afterwards does not make last month's payroll wrong - and "recomputing" it would rewrite history.
 */
function earlierRunChangedAfterFinalize(PDO $db, int $periodId): bool {
    $st = $db->prepare("SELECT start_date, finalized_at FROM payroll_periods WHERE id = ?");
    $st->execute([$periodId]);
    $p = $st->fetch();
    if (!$p) return false;
    $st = $db->prepare("SELECT COUNT(*) FROM payroll_periods e
                         WHERE e.id <> ? AND e.start_date >= ? AND e.end_date < ?
                           AND (e.status = 'Open' OR (? IS NOT NULL AND e.finalized_at >= ?))");
    $st->execute([$periodId, date('Y-m-01', strtotime($p['start_date'])), $p['start_date'], $p['finalized_at'], $p['finalized_at']]);
    return (int)$st->fetchColumn() > 0;
}

/* "₱1,234.50", and "−₱289.95" for a negative amount (a tax refund, a negative net pay) - number_format alone prints "₱-289.95" */
function pesoFmt($n, int $decimals = 2): string {
    $n = (float)$n;
    return ($n < 0 ? '−' : '') . '₱' . number_format(abs($n), $decimals);
}

/* Payroll lines whose net pay is below zero - the employee's monthly contribution and tax amounts or a deduction exceed what was earned.
   [['emp_id','emp_name','net_pay'], …] */
function negativeNetLines(PDO $db, int $periodId): array {
    $st = $db->prepare("SELECT e.employee_code AS emp_id, e.full_name AS emp_name, p.net_pay FROM payroll p JOIN employees e ON e.id = p.employee_id
                         WHERE p.period_id = ? AND p.net_pay < 0 ORDER BY e.full_name");
    $st->execute([$periodId]);
    return $st->fetchAll();
}

/*
 * 13th-month pay (Presidential Decree 851) for one calendar year, employee by employee.
 *
 *     13th-month pay  =  total BASIC pay earned in the calendar year  ÷  12
 *
 * Basic pay is the payroll's own Basic Pay column - gross pay less the overtime / tardiness adjustment - so pay for days worked and
 * paid leave counts, absences and undertime are already out, and overtime, bonuses and allowances are not in (they are not
 * "basic salary" for this purpose). A year's pay is every payroll line of a pay period that STARTS in that year, grouped by month
 * for the computation sheet. Someone who worked only part of the year simply has fewer months in the total (pro-rated); someone
 * who has left is still listed - pay is due on separation. The result is rounded half a centavo UP, in whole centavos.
 *
 * "Paid" is every Bonus entry whose reason starts with "13th Month Pay" in a period of that year (those recorded by the 13th Month
 * Pay page and those typed by hand on Bonus & Deductions), so a mid-year advance and the December balance add up.
 *
 * Returns
 *   year, rows  emp_id => [emp_id, full_name, status, months [1..12 => pesos], basic, months_paid, due, paid, balance,
 *                          other_bonus (bonuses that are not 13th month), taxable_excess (if the balance were paid in full)]
 *   periods, open_periods, last_end      the pay periods of that year that hold payroll, how many are still Open, the last day covered
 *   totals      basic, due, paid, balance, accrual [1..12 => basic of the month ÷ 12]
 */
function thirteenthMonthData(PDO $db, int $year): array {
    $names = [];
    foreach ($db->query("SELECT e.employee_code AS emp_id, e.full_name, e.status, COALESCE(br.name, '') AS branch
                           FROM employees e LEFT JOIN branches br ON br.id = e.branch_id")->fetchAll() as $e) $names[$e['emp_id']] = $e;

    $cents = fn($v): int => (int)round((float)$v * 100);
    $rows  = [];

    $st = $db->prepare("SELECT e.employee_code AS emp_id, MONTH(pp.start_date) AS m, SUM(p.basic_pay) AS basic
                          FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
                          JOIN employees e ON e.id = p.employee_id
                         WHERE YEAR(pp.start_date) = ?
                         GROUP BY e.id, e.employee_code, MONTH(pp.start_date)");
    $st->execute([$year]);
    foreach ($st->fetchAll() as $r) {
        $id = (string)$r['emp_id'];
        $rows[$id] ??= ['emp_id' => $id, 'full_name' => $names[$id]['full_name'] ?? $id, 'status' => $names[$id]['status'] ?? 'Removed',
                        'branch' => trim((string)($names[$id]['branch'] ?? '')), 'cents' => array_fill(1, 12, 0), 'sum' => 0];
        $rows[$id]['cents'][(int)$r['m']] += $cents($r['basic']);
        $rows[$id]['sum']                 += $cents($r['basic']);
    }

    /* bonuses recorded for that year: all of them, and the 13th-month ones among them (a history row with no period counts in the year it was entered) */
    $paid = $bonus = [];
    $st = $db->prepare("SELECT e.employee_code AS emp_id, SUM(p.bonus_pay) AS b FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
                          JOIN employees e ON e.id = p.employee_id
                         WHERE YEAR(pp.start_date) = ? GROUP BY e.id, e.employee_code");
    $st->execute([$year]);
    foreach ($st->fetchAll() as $r) $bonus[(string)$r['emp_id']] = $cents($r['b']);
    $st = $db->prepare("SELECT e.employee_code AS emp_id, SUM(h.amount) AS a FROM bonus_deductions h LEFT JOIN payroll_periods pp ON pp.id = h.period_id
                          JOIN employees e ON e.id = h.employee_id
                         WHERE h.type = 'Bonus' AND h.reason LIKE '13th Month Pay%'
                           AND COALESCE(YEAR(pp.start_date), YEAR(h.entry_date)) = ?
                         GROUP BY e.id, e.employee_code");
    $st->execute([$year]);
    foreach ($st->fetchAll() as $r) $paid[(string)$r['emp_id']] = $cents($r['a']);

    $out = [];
    $totals = ['basic' => 0, 'due' => 0, 'paid' => 0, 'balance' => 0, 'accrual' => array_fill(1, 12, 0)];
    $monthSum = array_fill(1, 12, 0);
    foreach ($rows as $id => $r) {
        $due     = $r['sum'] > 0 ? intdiv(2 * $r['sum'] + 12, 24) : 0;              /* sum ÷ 12, half a centavo up */
        $p       = $paid[$id] ?? 0;
        $other   = max(0, ($bonus[$id] ?? 0) - $p);
        $balance = $due - $p;
        $excess  = max(0, $other + $p + max(0, $balance) - (int)round(BIR_EXEMPT_BENEFITS * 100));
        $out[$id] = ['emp_id' => $id, 'full_name' => $r['full_name'], 'status' => $r['status'], 'branch' => $r['branch'],
                     'months' => array_map(fn($c) => $c / 100, $r['cents']), 'basic' => $r['sum'] / 100,
                     'months_paid' => count(array_filter($r['cents'], fn($c) => $c > 0)),
                     'due' => $due / 100, 'paid' => $p / 100, 'balance' => $balance / 100,
                     'other_bonus' => $other / 100, 'taxable_excess' => $excess / 100];
        $totals['basic'] += $r['sum']; $totals['due'] += $due; $totals['paid'] += $p; $totals['balance'] += max(0, $balance);
        foreach ($r['cents'] as $m => $c) $monthSum[$m] += $c;
    }
    uasort($out, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));
    foreach ($monthSum as $m => $c) $totals['accrual'][$m] = intdiv(2 * $c + 12, 24) / 100;
    foreach (['basic', 'due', 'paid', 'balance'] as $k) $totals[$k] /= 100;

    $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(pp.status = 'Open'), 0) AS open_n, MAX(pp.end_date) AS last_end
                          FROM payroll_periods pp
                         WHERE YEAR(pp.start_date) = ? AND EXISTS (SELECT 1 FROM payroll p WHERE p.period_id = pp.id)");
    $st->execute([$year]);
    $meta = $st->fetch();
    return ['year' => $year, 'rows' => $out, 'periods' => (int)$meta['n'], 'open_periods' => (int)$meta['open_n'],
            'last_end' => $meta['last_end'], 'totals' => $totals];
}

/*
 * recordAdjustments() - add bonuses or deductions to the payroll lines of ONE open period. The single code path behind the
 * Adjustments page and the 13th Month Pay page, so both obey the same rules and leave the same trail.
 *
 *   $period  a payroll_periods row (id, start_date, finalize_count) - the caller has checked that it is Open
 *   $lines   emp_id => amount (pesos, > 0): one entry per employee
 *   $type    'Bonus' | 'Deduction'
 *
 * Each line and its history row are written together in one transaction. Two guards hold a line back (it is reported, never
 * half-applied):
 *   · a Deduction that would take net pay below zero (the Labor Code, Art. 113, limits what may be withheld from wages)
 *   · a Bonus that takes the employee's bonuses for the period's calendar year over ₱90,000, the tax-exempt ceiling for
 *     13th-month pay and other benefits - unless $confirmOverExempt says a person decided to record it anyway
 *
 * Returns ['applied', 'applied_ids', 'skipped' (no payroll line), 'negative' / 'over' (texts), 'over_ids', 'cycle', 'is_revision'].
 * A database error rolls everything back and is thrown.
 */
function recordAdjustments(PDO $db, array $period, array $lines, string $type, string $reason, bool $confirmOverExempt = false): array {
    $periodId   = (int)$period['id'];
    $cycle      = (int)($period['finalize_count'] ?? 0);
    $isRevision = $cycle > 0;          /* finalized, re-opened and now being corrected: stamped on the history row and the payroll row */
    $who        = currentUserId();
    $periodYear = (int)date('Y', strtotime($period['start_date']));

    $getPR = $db->prepare(
        "SELECT p.id, p.employee_id, e.employee_code AS emp_id, e.full_name AS emp_name, p.gross_pay, p.withholding_tax,
                p.sss_deduction, p.philhealth_deduction, p.pagibig_deduction, p.bonus_pay, p.other_deductions
           FROM payroll p JOIN employees e ON e.id = p.employee_id
          WHERE p.period_id = ? AND e.employee_code = ?"
    );
    /* status stays Draft: the period is open, so these rows are not final */
    $updPR    = $db->prepare("UPDATE payroll SET bonus_pay = ?, other_deductions = ?, net_pay = ?, status = 'Draft' WHERE id = ?");
    $updPRrev = $db->prepare("UPDATE payroll SET bonus_pay = ?, other_deductions = ?, net_pay = ?, status = 'Draft',
                                     revised_after_finalize = 1, revised_at = NOW() WHERE id = ?");
    $histIns  = $db->prepare("INSERT INTO bonus_deductions
                              (entry_date, employee_id, type, amount, reason, processed_by, period_id, finalize_cycle)
                              VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)");
    /* Bonuses already on this employee's payroll rows in the period's calendar year (this row included) */
    $ytdBonus = $db->prepare("SELECT COALESCE(SUM(p.bonus_pay), 0) FROM payroll p
                                JOIN payroll_periods pp ON pp.id = p.period_id
                               WHERE p.employee_id = ? AND YEAR(pp.start_date) = ?");

    $applied = [];
    $skipped = $negative = $over = $overIds = [];
    $total   = 0.0;
    try {
        $db->beginTransaction();
        foreach ($lines as $eid => $amount) {
            $eid    = trim((string)$eid);
            $amount = round((float)$amount, 2);
            if ($eid === '' || $amount <= 0) continue;

            $getPR->execute([$periodId, $eid]);
            $row = $getPR->fetch();
            if (!$row) { $skipped[] = $eid; continue; }

            $bonus = (float)$row['bonus_pay'];
            $ded   = (float)$row['other_deductions'];
            if ($type === 'Bonus') $bonus += $amount; else $ded += $amount;

            /* net = gross (overtime already in it) + bonus − (tax + SSS + PhilHealth + Pag-IBIG + deductions) */
            $net = ((float)$row['gross_pay'] + $bonus)
                 - ((float)$row['withholding_tax'] + (float)$row['sss_deduction'] + (float)$row['philhealth_deduction'] + (float)$row['pagibig_deduction'] + $ded);

            if ($type === 'Deduction' && round($net, 2) < 0) {
                $negative[] = $row['emp_name'] . ' (net pay would be −₱' . number_format(-$net, 2) . ')';
                continue;
            }

            /* This system does not withhold on a bonus, so one that crosses the ceiling needs a person's decision first. */
            if ($type === 'Bonus' && !$confirmOverExempt) {
                $ytdBonus->execute([$row['employee_id'], $periodYear]);
                $ytd = (float)$ytdBonus->fetchColumn() + $amount;      /* the row's own bonus is already in the sum */
                if ($ytd > BIR_EXEMPT_BENEFITS + 0.004) {
                    $over[]    = $row['emp_name'] . ' (' . $periodYear . ' bonuses would be ₱' . number_format($ytd, 2)
                               . ', ₱' . number_format($ytd - BIR_EXEMPT_BENEFITS, 2) . ' over)';
                    $overIds[] = $eid;
                    continue;
                }
            }

            ($isRevision ? $updPRrev : $updPR)->execute([$bonus, $ded, round($net, 2), $row['id']]);
            $histIns->execute([$row['employee_id'], $type, $amount, $reason, $who, $periodId, $cycle]);
            $applied[$eid] = $amount;
            $total += $amount;
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    /* The audit trail records the correction itself, not just its effect. */
    if ($applied && $isRevision) {
        $same = count(array_unique($applied)) === 1;
        logPeriodAudit($periodId, 'Revised', $cycle,
            ($same ? "$type of PHP " . number_format(reset($applied), 2) : "$type totalling PHP " . number_format($total, 2))
          . " ($reason) applied to " . count($applied) . " employee(s) after finalize #$cycle.");
    }
    return ['applied' => count($applied), 'applied_ids' => array_keys($applied), 'skipped' => $skipped,
            'negative' => $negative, 'over' => $over, 'over_ids' => $overIds, 'cycle' => $cycle, 'is_revision' => $isRevision];
}

/*
 * What the COMPANY adds on top of the pay it hands out, per pay period: its share of SSS (10% of the credit), the Employees'
 * Compensation amount, its half of PhilHealth and its 2% of Pag-IBIG - employerShare() applied to what each stored payroll
 * line actually deducted, the same figures payroll.php's "company cost" shows.
 * Labor cost of a period = Σ gross pay + Σ bonus + these shares (deductions such as loans are the employee's money, not a saving).
 * Returns period_id => ['sss','ec','philhealth','pagibig','total'] in pesos.
 */
function employerSharesByPeriod(PDO $db): array {
    $periods = $db->query("SELECT id, start_date, end_date FROM payroll_periods ORDER BY start_date, id")->fetchAll();
    $lines = [];
    foreach ($db->query("SELECT period_id, employee_id, gross_pay, basic_pay, sss_deduction AS sss, philhealth_deduction AS philhealth, pagibig_deduction AS pagibig FROM payroll")->fetchAll() as $r) {
        $lines[$r['period_id']][] = $r;
    }
    $out = [];
    foreach ($periods as $p) {
        $monthStart = date('Y-m-01', strtotime($p['start_date']));
        $earlier = [];
        foreach ($periods as $q) {      /* runs of the same month that ended before this one began (as payContext() does) */
            if ((int)$q['id'] === (int)$p['id'] || $q['start_date'] < $monthStart || $q['end_date'] >= $p['start_date']) continue;
            foreach ($lines[$q['id']] ?? [] as $r) {
                $e = &$earlier[$r['employee_id']];
                $e ??= EARLIER_NONE;
                $e['basic'] += (float)$r['basic_pay'];
                $e['sss']   += (float)$r['sss'];
                unset($e);
            }
        }
        $tot = ['sss' => 0.0, 'ec' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0];
        foreach ($lines[$p['id']] ?? [] as $r) {
            $prev = $earlier[$r['employee_id']] ?? EARLIER_NONE;
            $b    = employerShare(['sss' => (float)$r['sss'], 'philhealth' => (float)$r['philhealth'], 'pagibig' => (float)$r['pagibig']],
                                  $prev, $prev['basic'] + (float)$r['basic_pay']);
            foreach ($tot as $k => $_) $tot[$k] += $b[$k];
        }
        $row = array_map(fn($v) => round($v, 2), $tot);
        $row['total'] = round(array_sum($row), 2);
        $out[(int)$p['id']] = $row;
    }
    return $out;
}

/*
 * One calendar month of attendance, added up from every day saved so far
 * (daily uploads + manual entries), for the "This Month's Attendance" pages.
 *   $ym     'YYYY-MM'
 *   $scope  null = every employee, otherwise the emp_ids to show
 *   $withPay  also sum the draft/final payroll of the periods inside the month
 * Only days inside their own pay period's dates count - the same rule the
 * payroll uses - so the page and the payroll always agree.
 */
function monthAttendance(PDO $db, string $ym, ?array $scope, bool $withPay = true): array {
    $start = $ym . '-01';
    $end   = date('Y-m-t', strtotime($start));
    $std   = max(1, (float)getSetting('standard_hours', '8'));

    $empSql  = "SELECT e.employee_code AS emp_id, e.full_name, COALESCE(br.name, '') AS branch, e.hours_per_day
                  FROM employees e LEFT JOIN branches br ON br.id = e.branch_id WHERE e.status = 'Active'";
    $dayScope = '';
    $args     = [];
    if ($scope !== null) {
        if (!$scope) $scope = [''];
        $in       = implode(',', array_fill(0, count($scope), '?'));
        $empSql  .= " AND e.employee_code IN ($in)";
        $dayScope = " AND b.employee_id IN (SELECT id FROM employees WHERE employee_code IN ($in))";
        $args     = array_values($scope);
    }
    $st = $db->prepare($empSql . ' ORDER BY e.full_name');
    $st->execute($args);
    $emps = [];
    foreach ($st->fetchAll() as $e) {
        $emps[$e['emp_id']] = ['name' => $e['full_name'], 'branch' => $e['branch'] ?? '', 'days' => [],
                               'std' => (float)$e['hours_per_day'] > 0 ? (float)$e['hours_per_day'] : $std,
                               'present' => 0, 'hours' => 0.0, 'ot' => 0.0, 'late' => 0.0, 'under' => 0.0,
                               'manual' => 0, 'last' => null, 'covered' => '', 'gross' => null, 'net' => null];
    }

    /* a day belongs to the pay period that holds its date (a day outside every period does not count) */
    $st = $db->prepare("
        SELECT e.employee_code AS emp_id, b.att_date, b.hours_worked, b.overtime_hours, b.late_hours,
               b.undertime_hours, b.is_day_off, ub.full_name AS entered_by, p.period_label
          FROM biometric_daily b
          JOIN employees e ON e.id = b.employee_id
          JOIN payroll_periods p ON p.id = (SELECT p2.id FROM payroll_periods p2
                                             WHERE b.att_date BETWEEN p2.start_date AND p2.end_date
                                             ORDER BY (p2.period_type = e.pay_schedule) DESC, p2.start_date, p2.id LIMIT 1)
          LEFT JOIN users ub ON ub.id = b.entered_by
         WHERE b.att_date BETWEEN ? AND ? $dayScope
         ORDER BY b.att_date");
    $st->execute(array_merge([$start, $end], $args));
    $last = null;
    foreach ($st->fetchAll() as $d) {
        $id = $d['emp_id'];
        if (!isset($emps[$id])) continue;          /* inactive / unregistered */
        $h     = (float)$d['hours_worked'];
        $under = $h > 0 ? dayUndertime($d, $emps[$id]['std']) : 0.0;
        $emps[$id]['days'][(int)substr($d['att_date'], 8, 2)] = [
            'hours' => $h, 'ot' => (float)$d['overtime_hours'], 'late' => (float)$d['late_hours'],
            'under' => $under, 'off' => $h <= 0 && (int)$d['is_day_off'] === 1,
            'manual' => $d['entered_by'], 'period' => $d['period_label'],
        ];
        if ($h > 0) {
            $emps[$id]['present']++;
            $emps[$id]['last'] = $d['att_date'];
        }
        $emps[$id]['hours'] += $h;
        $emps[$id]['ot']    += (float)$d['overtime_hours'];
        $emps[$id]['late']  += (float)$d['late_hours'];
        $emps[$id]['under'] += $under;
        $emps[$id]['covered'] = $d['att_date'];
        if ($d['entered_by']) $emps[$id]['manual']++;
        if ($d['att_date'] > $last) $last = $d['att_date'];
    }

    /*
     * The days without hours: day off (weekly, or marked OFF on the
     * timesheet), leave (approved / pending / rejected) or absent - judged
     * the same way as the payroll, but only up to the latest day the uploads
     * of the employee's branch cover: the days after it are shown as "not
     * uploaded yet", not as absences. (The payroll of a monthly / kinsenas
     * salary does not pay those days either - the days to compute are the days
     * in the file - and counts them in its Absent column until they are
     * uploaded.)
     */
    $cal      = attendanceCalendar($db, array_keys($emps), $start, $end);
    $judgedTo = [];
    foreach ($emps as $id => $e) {
        if ($e['covered'] !== '') $judgedTo[$e['branch']] = max($judgedTo[$e['branch']] ?? '', $e['covered']);
    }
    foreach ($emps as $id => &$e) {
        $e['marks'] = [];
        $e['off'] = $e['leave'] = $e['absent'] = 0;
        $upTo = $judgedTo[$e['branch']] ?? '';
        /* Same rule as the payroll: someone with no worked day and no approved
           leave has no payroll line, so is not marked absent either */
        $approvedLeave = array_filter($cal[$id]['leave'] ?? [], fn($l) => $l['status'] === 'Approved');
        if (!$e['present'] && !$approvedLeave) $upTo = '';
        for ($n = 1, $len = (int)date('t', strtotime($start)); $n <= $len; $n++) {
            $date = $ym . '-' . str_pad($n, 2, '0', STR_PAD_LEFT);
            $s = dayStatus($cal[$id] ?? null, $date, ($e['days'][$n]['hours'] ?? 0) > 0, !empty($e['days'][$n]['off']));
            if ($s === 'worked') continue;
            if ($s === 'off')   { $e['marks'][$n] = 'off';   if ($date <= $upTo) $e['off']++; continue; }
            if ($s === 'leave') { $e['marks'][$n] = 'leave'; $e['leave']++; continue; }
            if ($date > $upTo) {
                /* not uploaded yet - only show a leave still waiting for a decision */
                $ls = $cal[$id]['leave'][$date]['status'] ?? '';
                if ($ls === 'Pending') $e['marks'][$n] = 'pending';
                continue;
            }
            $ls = $cal[$id]['leave'][$date]['status'] ?? '';
            $e['marks'][$n] = $ls === 'Pending' ? 'absent-pending' : ($ls === 'Rejected' ? 'absent-rejected' : 'absent');
            $e['absent']++;
        }
    }
    unset($e);

    /* Pay periods that fall inside the month, and their payroll so far */
    $pst = $db->prepare("SELECT id, period_label, start_date, end_date, status FROM payroll_periods
                          WHERE start_date <= ? AND end_date >= ? ORDER BY start_date");
    $pst->execute([$end, $start]);
    $periods = $pst->fetchAll();

    if ($withPay && $periods) {
        $ids = array_column($periods, 'id');
        $st  = $db->prepare("SELECT e.employee_code AS emp_id, SUM(p.gross_pay) g, SUM(p.net_pay) n
                               FROM payroll p JOIN employees e ON e.id = p.employee_id
                              WHERE p.period_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                              GROUP BY e.id, e.employee_code");
        $st->execute($ids);
        foreach ($st->fetchAll() as $p) {
            if (!isset($emps[$p['emp_id']])) continue;
            $emps[$p['emp_id']]['gross'] = (float)$p['g'];
            $emps[$p['emp_id']]['net']   = (float)$p['n'];
        }
    }

    /* Newest day on record anywhere, so an empty month can point to it */
    $st = $db->prepare("SELECT MAX(b.att_date) FROM biometric_daily b WHERE 1 = 1 $dayScope");
    $st->execute($args);
    $latest = $st->fetchColumn() ?: null;

    return ['ym' => $ym, 'start' => $start, 'end' => $end, 'days' => (int)date('t', strtotime($start)),
            'std' => $std, 'emps' => $emps, 'last' => $last, 'latest' => $latest,
            'periods' => $periods, 'withPay' => $withPay];
}

/*
 * Days a leave request covers: every calendar day, and the duty days among
 * them (the employee's weekly days off left out) - what payroll pays as leave.
 */
function leaveDays(string $from, string $to, ?string $restDays): array {
    $rest = restDayList($restDays);
    $cal = $duty = 0;
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $cal++;
        if (!in_array((int)date('N', strtotime($d)), $rest, true)) $duty++;
    }
    return ['calendar' => $cal, 'duty' => $duty];
}

/* "3 days (2 duty days)" - or just "3 days" when they are the same */
function leaveDaysLabel(string $from, string $to, ?string $restDays): string {
    ['calendar' => $c, 'duty' => $d] = leaveDays($from, $to, $restDays);
    $txt = $c . ' day' . ($c === 1 ? '' : 's');
    return $d === $c ? $txt : $txt . ' (' . $d . ' duty day' . ($d === 1 ? '' : 's') . ')';
}

/*
 * The pay period "now" is about: the one whose dates hold today, else the
 * latest one by date (never by id - periods are often created out of order).
 * $openOnly limits it to periods still open.
 */
function currentPeriod(PDO $db, bool $openOnly = false): ?array {
    $open  = $openOnly ? " AND status = 'Open'" : '';
    $today = date('Y-m-d');
    $st = $db->prepare("SELECT * FROM payroll_periods WHERE start_date <= ? AND end_date >= ?$open
                         ORDER BY start_date DESC LIMIT 1");
    $st->execute([$today, $today]);
    if ($p = $st->fetch()) return $p;
    $st = $db->query("SELECT * FROM payroll_periods WHERE 1 = 1$open ORDER BY start_date DESC, id DESC LIMIT 1");
    return $st->fetch() ?: null;
}

/*
 * The pay period the admin dashboard reports on after sign-in: the one whose
 * dates hold today, else the newest one that has already started. A period
 * entered in advance for a later month is never "latest" - only when every
 * period still lies in the future is the soonest of them shown.
 */
function reportingPeriod(PDO $db): ?array {
    $today = date('Y-m-d');
    $st = $db->prepare("SELECT * FROM payroll_periods WHERE start_date <= ?
                         ORDER BY (end_date >= ?) DESC, start_date DESC, id DESC LIMIT 1");
    $st->execute([$today, $today]);
    if ($p = $st->fetch()) return $p;
    return $db->query("SELECT * FROM payroll_periods ORDER BY start_date ASC, id ASC LIMIT 1")->fetch() ?: null;
}

/*
 * Another period whose dates overlap start..end, or null. Two periods of the SAME schedule covering the same days would pay those
 * days twice. Periods of different schedules do not clash - a Monthly run and the two kinsenas cut-offs of the same month hold
 * different employees - except a Weekly one (no longer offered, but an older database may hold one), which takes everybody.
 *   $type   the schedule of the period to be (Semi-Monthly | Monthly); null = a clash with a period of any schedule
 */
function overlappingPeriod(PDO $db, string $start, string $end, int $exceptId = 0, ?string $type = null): ?array {
    $st = $db->prepare("SELECT id, period_label, start_date, end_date, period_type FROM payroll_periods
                         WHERE start_date <= ? AND end_date >= ? AND id <> ?
                           AND (? IS NULL OR period_type = ? OR period_type = 'Weekly' OR ? = 'Weekly')
                         ORDER BY start_date LIMIT 1");
    $st->execute([$end, $start, $exceptId, $type, $type, $type]);
    return $st->fetch() ?: null;
}

/*
 * Remembers which employee a biometric device user number belongs to, as
 * picked on the upload page ({"00001": "EMP-004", ...}). A device report has
 * no name, so this is how its next upload finds the employee by itself.
 * Only real employees, and for a manager only their own, are accepted.
 */
function saveBioLinks(PDO $db, $links, array $who): void {
    if (!is_array($links) || !$links) return;
    $scope = $who['scope'] === null ? null : array_flip($who['scope']);
    $known = $db->prepare("SELECT id FROM employees WHERE employee_code = ?");
    $save  = $db->prepare("INSERT INTO biometric_employee_map (device_user_id, employee_id) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id)");
    foreach ($links as $deviceId => $empId) {
        $deviceId = substr(trim((string)$deviceId), 0, 32);
        $empId    = trim((string)$empId);
        if ($deviceId === '' || $empId === '') continue;
        if ($scope !== null && !isset($scope[$empId])) continue;
        $known->execute([$empId]);
        $pk = $known->fetchColumn();
        if (!$pk) continue;
        $save->execute([$deviceId, (int)$pk]);
    }
}

/*
 * <script> block the upload pages put before attendance-formats.js:
 * the employees the uploader may pick from, the saved device-ID links,
 * and the shift rules used to turn raw punches into hours.
 * $scope: null = every employee, otherwise the allowed emp_ids.
 */
function uploadPageScript(PDO $db, ?array $scope): string {
    $emps = [];
    foreach ($db->query("SELECT employee_code AS emp_id, full_name FROM employees ORDER BY full_name")->fetchAll() as $e) {
        if ($scope === null || in_array($e['emp_id'], $scope, true)) {
            $emps[] = ['emp_id' => $e['emp_id'], 'full_name' => $e['full_name']];
        }
    }
    $map = [];
    foreach ($db->query("SELECT m.device_user_id, e.employee_code AS emp_id FROM biometric_employee_map m
                           JOIN employees e ON e.id = m.employee_id")->fetchAll() as $m) {
        $map[$m['device_user_id']] = $m['emp_id'];
    }
    $shift = [
        'start'    => getSetting('shift_start', '08:00'),
        'grace'    => (int)getSetting('grace_minutes', '15'),
        'breakMin' => (int)getSetting('break_minutes', '60'),
        'standard' => (float)getSetting('standard_hours', '8'),
    ];
    $j = fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    return "<script>\n"
         . "window.UPLOAD_EMPLOYEES = {$j($emps)};\n"
         . "window.UPLOAD_BIOMAP    = {$j((object)$map)};\n"
         . "window.UPLOAD_SHIFT     = {$j($shift)};\n"
         . "</script>\n";
}

/*
 * Inserts many rows with a few multi-row INSERTs instead of one per row.
 * The database is in the cloud (~350 ms a round trip), so a row-by-row
 * upload of a few hundred lines runs past PHP's time limit.
 *
 *   $head  "INSERT INTO t (a,b,c) VALUES"
 *   $tuple "(?,?,?)" - one row's placeholders
 *   $tail  optional, e.g. "ON DUPLICATE KEY UPDATE ..."
 */
function bulkInsert(PDO $db, string $head, string $tuple, array $rows, string $tail = '', int $chunk = 200): void {
    foreach (array_chunk($rows, $chunk) as $part) {
        $sql = $head . ' ' . implode(',', array_fill(0, count($part), $tuple)) . ' ' . $tail;
        $db->prepare($sql)->execute(array_merge(...$part));
    }
}

/*
 * getSetting() - read one value from the settings table.
 * Returns $default when the key does not exist.
 */
function getSetting(string $key, string $default = ''): string {
    $all = settingsCache();
    return array_key_exists($key, $all) ? (string)$all[$key] : $default;
}

/*
 * Every setting, read once per request. The database is in the cloud
 * (~350 ms a round trip) and one pay computation reads half a dozen
 * settings, so asking per key made each recompute seconds slower.
 * $update keeps the cache in step with setSetting().
 */
function settingsCache(array $update = []): array {
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = getDB()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            return $update;   /* no settings table yet (fresh install) */
        }
    }
    foreach ($update as $k => $v) $cache[$k] = $v;
    return $cache;
}

/*
 * setSetting() - write one value into the settings table.
 * Uses INSERT … ON DUPLICATE KEY UPDATE (setting_key is the PK) so a
 * brand-new key - e.g. the company letterhead fields - is created on first
 * save instead of silently doing nothing like a bare UPDATE would.
 */
function setSetting(string $key, string $value): void {
    getDB()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
    settingsCache([$key => $value]);
}

/*
 * A payslip signature acknowledges one net amount. It still counts while the
 * payslip's net pay is what was signed for; after a correction it does not.
 * (Signatures from before the amount was recorded have no amount: they count.)
 */
function signatureCurrent($netSigned, $netNow): bool {
    if ($netSigned === null || $netSigned === '') return true;
    if ($netNow === null) return false;
    return abs((float)$netSigned - (float)$netNow) < 0.005;
}

/*
 * Save a payslip signature - only for a finalized period, whose figures can no
 * longer change, and stamped with the net pay it acknowledges.
 * Returns [ok, message].
 */
function savePayslipSignature(PDO $db, array $payrollRow, string $signatureData): array {
    if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+={0,2}$#', $signatureData) || strlen($signatureData) > 2000000) {
        return [false, 'That signature could not be read. Clear the pad and sign again.'];
    }
    $st = $db->prepare("SELECT status FROM payroll_periods WHERE id = ?");
    $st->execute([$payrollRow['period_id']]);
    if ($st->fetchColumn() === 'Open') {
        return [false, 'This pay period is still open, so its figures can still change. '
                     . 'Finalize it in Payroll Processing first, then collect the signature.'];
    }
    /* the employee, the period and their names are found through the pay line; signed_by_user is whoever collected the signature */
    $db->prepare("
        INSERT INTO payslip_signatures (payroll_id, signature_data, net_signed, signed_by_user)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE signature_data = VALUES(signature_data), net_signed = VALUES(net_signed),
                                signed_by_user = VALUES(signed_by_user), signed_at = NOW()
    ")->execute([$payrollRow['id'], $signatureData, $payrollRow['net_pay'], currentUserId()]);
    return [true, 'Signature saved.'];
}

/*
 * currentActor() - the human name to show for whoever is signed in (a message, a greeting).
 * Works from any portal: admin, manager or employee session.
 */
function currentActor(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (['admin', 'mgr_name', 'emp_name'] as $k) {
        $v = trim((string)($_SESSION[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return 'Admin';
}

/*
 * currentUserId() - users.id of whoever is signed in, to stamp on an audit row (processed, reviewed, approved, printed,
 * entered, signed by). Null when there is no session (a command-line run, a test without one).
 */
function currentUserId(): ?int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (['admin_id', 'mgr_id', 'emp_user_id'] as $k) {
        $v = (int)($_SESSION[$k] ?? 0);
        if ($v > 0) return $v;
    }
    return null;
}

/*
 * logPeriodAudit() - append one row to the finalize / re-open trail.
 *
 * This is the durable record behind every "Revised" indicator in the UI:
 * without it a corrected period looks identical to one that was closed
 * right the first time. Auditing must never break the action it is
 * describing, so a failure here is swallowed.
 */
function logPeriodAudit(int $periodId, string $action, int $cycle = 0, string $note = ''): void {
    if (!$periodId) return;
    try {
        getDB()->prepare(
            'INSERT INTO period_audit (period_id, action, cycle, performed_by, note)
             VALUES (?,?,?,?,?)'
        )->execute([$periodId, $action, $cycle, currentUserId(), $note !== '' ? $note : null]);
    } catch (PDOException $e) { /* never block the caller */ }
}

/*
 * periodRevisionInfo() - everything a page needs to describe one period's
 * revision state in a single query pair.
 *
 *   reopened  - has this period ever been unlocked after a finalize?
 *   cycle     - how many times it has been finalized
 *   revised   - how many payroll rows changed after a finalize
 *   entries   - how many bonus/deduction entries were recorded as corrections
 */
function periodRevisionInfo(int $periodId): array {
    $out = ['reopened' => false, 'cycle' => 0, 'reopen_count' => 0,
            'reopened_at' => null, 'revised' => 0, 'entries' => 0];
    if (!$periodId) return $out;

    $db = getDB();
    try {
        $st = $db->prepare('SELECT finalize_count, reopen_count, reopened_at
                              FROM payroll_periods WHERE id = ?');
        $st->execute([$periodId]);
        if ($p = $st->fetch()) {
            $out['cycle']        = (int)$p['finalize_count'];
            $out['reopen_count'] = (int)$p['reopen_count'];
            $out['reopened_at']  = $p['reopened_at'];
            $out['reopened']     = (int)$p['reopen_count'] > 0;
        }

        $st = $db->prepare('SELECT COUNT(*) FROM payroll
                             WHERE period_id = ? AND revised_after_finalize = 1');
        $st->execute([$periodId]);
        $out['revised'] = (int)$st->fetchColumn();

        $st = $db->prepare('SELECT COUNT(*) FROM bonus_deductions
                             WHERE period_id = ? AND finalize_cycle > 0');
        $st->execute([$periodId]);
        $out['entries'] = (int)$st->fetchColumn();
    } catch (PDOException $e) { /* pre-patch database - report "not revised" */ }

    return $out;
}
