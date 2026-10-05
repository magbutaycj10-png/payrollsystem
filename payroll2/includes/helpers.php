<?php
require_once __DIR__ . '/errors.php';   // friendly errors everywhere — load first
require_once __DIR__ . '/db.php';

/*
 * applySchemaPatches()
 * Creates any tables that may be missing and seeds the admin user
 * on first-ever run.  Safe to call on every request — the static
 * guard ensures it only executes once per PHP process.
 */
function applySchemaPatches(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = getDB();

    $patches = [
        // Attendance audit columns (added in v2 — ALTER is harmless if already present)
        "ALTER TABLE attendance ADD COLUMN manually_entered_by VARCHAR(150) NULL AFTER upload_date",
        "ALTER TABLE attendance ADD COLUMN manager_approved    TINYINT(1)  DEFAULT 0  AFTER manually_entered_by",
        "ALTER TABLE attendance ADD COLUMN approved_by         VARCHAR(150) NULL       AFTER manager_approved",
        "ALTER TABLE attendance ADD COLUMN approved_at         TIMESTAMP   NULL        AFTER approved_by",

        // salary_type column on employees (monthly vs daily wager)
        "ALTER TABLE employees ADD COLUMN salary_type ENUM('monthly','daily') NOT NULL DEFAULT 'monthly' AFTER base_salary",

        // 'kinsenas' — paid twice a month, base_salary stated per kinsena
        // (half-month) rather than per month. MODIFY widens the enum in place
        // and is harmless once the value is already there.
        "ALTER TABLE employees MODIFY COLUMN salary_type ENUM('monthly','kinsenas','daily') NOT NULL DEFAULT 'monthly'",

        // department -> branch rename (v3). Fails harmlessly once already renamed.
        "ALTER TABLE employees RENAME COLUMN department TO branch",
        "ALTER TABLE users     RENAME COLUMN department TO branch",
        // Fallback for installs that never had a department column at all
        "ALTER TABLE employees ADD COLUMN branch VARCHAR(100) DEFAULT '' AFTER position",
        "ALTER TABLE users     ADD COLUMN branch VARCHAR(100) DEFAULT '' AFTER emp_id",

        // How a manager's employee set is resolved: whole branch, or a picked list
        "ALTER TABLE users ADD COLUMN scope_type ENUM('branch','custom') NOT NULL DEFAULT 'branch' AFTER branch",

        // Unified auth table — all three roles (admin / manager / earner) live here.
        // Passwords are stored as bcrypt hashes only.
        "CREATE TABLE IF NOT EXISTS users (
            id            INT          NOT NULL AUTO_INCREMENT,
            full_name     VARCHAR(150) NOT NULL,
            email         VARCHAR(150) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role          ENUM('admin','manager','earner') NOT NULL DEFAULT 'earner',
            emp_id        VARCHAR(20)  DEFAULT NULL,
            branch        VARCHAR(100) DEFAULT '',
            scope_type    ENUM('branch','custom') NOT NULL DEFAULT 'branch',
            status        ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            last_login    TIMESTAMP    NULL DEFAULT NULL,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_email (email)
        )",

        // Supplemental tables that may not exist on older installs
        "CREATE TABLE IF NOT EXISTS employee_profiles (
            id                 INT          NOT NULL AUTO_INCREMENT,
            emp_id             VARCHAR(20)  NOT NULL,
            address            TEXT         DEFAULT NULL,
            phone              VARCHAR(30)  DEFAULT NULL,
            emergency_name     VARCHAR(150) DEFAULT NULL,
            emergency_phone    VARCHAR(30)  DEFAULT NULL,
            emergency_relation VARCHAR(80)  DEFAULT NULL,
            updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_profile_emp (emp_id)
        )",
        "CREATE TABLE IF NOT EXISTS leave_requests (
            id          INT          NOT NULL AUTO_INCREMENT,
            emp_id      VARCHAR(20)  NOT NULL,
            emp_name    VARCHAR(150) DEFAULT '',
            leave_type  ENUM('Vacation','Sick Leave','Emergency','Other') NOT NULL,
            date_from   DATE         NOT NULL,
            date_to     DATE         NOT NULL,
            reason      TEXT         DEFAULT NULL,
            status      ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
            reviewed_by VARCHAR(150) DEFAULT NULL,
            review_note VARCHAR(255) DEFAULT NULL,
            reviewed_at TIMESTAMP    NULL DEFAULT NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        )",
        // Raw daily biometric attendance records — one row per employee per day
        "CREATE TABLE IF NOT EXISTS biometric_daily (
            id             INT          NOT NULL AUTO_INCREMENT,
            period_id      INT          NOT NULL,
            emp_id         VARCHAR(20)  NOT NULL,
            emp_name       VARCHAR(150) NOT NULL DEFAULT '',
            att_date       DATE         NOT NULL,
            hours_worked   DECIMAL(6,2) NOT NULL DEFAULT 0,
            overtime_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
            late_hours     DECIMAL(6,2) NOT NULL DEFAULT 0,
            uploaded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_daily (period_id, emp_id, att_date)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Explicit manager -> employee assignments (used when scope_type = 'custom')
        "CREATE TABLE IF NOT EXISTS manager_employees (
            id         INT         NOT NULL AUTO_INCREMENT,
            manager_id INT         NOT NULL,
            emp_id     VARCHAR(20) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mgr_emp (manager_id, emp_id),
            KEY idx_mgr (manager_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS payslip_signatures (
            id             INT          NOT NULL AUTO_INCREMENT,
            payroll_id     INT          NOT NULL,
            emp_id         VARCHAR(20)  NOT NULL,
            emp_name       VARCHAR(150) DEFAULT '',
            period_id      INT          DEFAULT NULL,
            period_label   VARCHAR(80)  DEFAULT '',
            signature_data LONGTEXT     NOT NULL,
            signed_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        )",

        // Document numbering. document_series holds one counter per document
        // type; document_serials binds an allocated number to the record it was
        // issued for, so a reprint reproduces the same number instead of
        // burning a new one. Numbers never restart — the series runs straight
        // through every month and every payroll run.
        "CREATE TABLE IF NOT EXISTS document_series (
            series  VARCHAR(20) NOT NULL,
            next_no BIGINT      NOT NULL DEFAULT 1,
            PRIMARY KEY (series)
        )",
        "CREATE TABLE IF NOT EXISTS document_serials (
            series    VARCHAR(20) NOT NULL,
            ref_id    INT         NOT NULL,
            serial_no BIGINT      NOT NULL,
            issued_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (series, ref_id),
            UNIQUE KEY uq_series_no (series, serial_no)
        )",

        // When a period was last finalized, and how many times. Lets the UI say
        // "Re-finalize" after an unlock instead of pretending it is the first time.
        "ALTER TABLE payroll_periods ADD COLUMN finalized_at   TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE payroll_periods ADD COLUMN finalize_count INT NOT NULL DEFAULT 0",

        // One signature per payroll row — makes the ON DUPLICATE KEY UPDATE in
        // sign-payslip.php actually replace instead of piling up duplicates, so
        // the acknowledgement receipt always prints the current signature.
        "ALTER TABLE payslip_signatures ADD UNIQUE KEY uq_sig_payroll (payroll_id)",

        // ---- Revision tracking -------------------------------------------
        // A period can be finalized, re-opened, corrected and finalized again.
        // Everything below records that history in the database so the pages
        // can say *which* figures were touched after a finalize instead of
        // showing a corrected period as if it had never been closed.

        // How many times a period has been re-opened, and when it last was.
        "ALTER TABLE payroll_periods ADD COLUMN reopen_count INT NOT NULL DEFAULT 0",
        "ALTER TABLE payroll_periods ADD COLUMN reopened_at  TIMESTAMP NULL DEFAULT NULL",

        // Each period carries its own pay schedule (Monthly / Semi-Monthly /
        // Weekly), so a kinsenas cut-off and a monthly run can sit side by
        // side. NULL = older period: falls back to Settings → payroll_period.
        "ALTER TABLE payroll_periods ADD COLUMN period_type VARCHAR(20) NULL DEFAULT NULL AFTER period_end",

        // Undertime for a day as the timesheet states it (whole hours short of
        // a full day). NULL = not given; it is then worked out from the hours.
        "ALTER TABLE biometric_daily ADD COLUMN undertime_hours DECIMAL(6,2) NULL DEFAULT NULL AFTER late_hours",

        // Who typed a day in by hand (Manual Attendance). NULL = it came from
        // an uploaded file; a later upload of that day clears it again.
        "ALTER TABLE biometric_daily ADD COLUMN entered_by VARCHAR(150) NULL DEFAULT NULL AFTER undertime_hours",

        // Which government contributions are deducted for this employee
        // (all on by default, as the law requires for regular employees).
        "ALTER TABLE employees ADD COLUMN deduct_sss        TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE employees ADD COLUMN deduct_philhealth TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE employees ADD COLUMN deduct_pagibig    TINYINT(1) NOT NULL DEFAULT 1",

        // finalize_cycle = the period's finalize_count at the moment the entry
        // was recorded. 0 = recorded during the first, normal pass.
        // >0 = recorded after the period had already been finalized once, i.e.
        // this entry is a correction made during re-open cycle N.
        "ALTER TABLE bonus_deduction_history ADD COLUMN finalize_cycle INT NOT NULL DEFAULT 0",
        // entry_date is only a DATE; keep the exact moment too.
        "ALTER TABLE bonus_deduction_history ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",

        // Per-employee revision flag: this payroll row's money changed after
        // the period had been finalized at least once.
        "ALTER TABLE payroll ADD COLUMN revised_after_finalize TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE payroll ADD COLUMN revised_at TIMESTAMP NULL DEFAULT NULL",

        // Weekly day(s) off, as ISO weekday numbers (1 = Mon … 7 = Sun), e.g.
        // "7" or "6,7"; '' = no fixed day off. Sunday matches what the pay
        // computation always assumed before this was configurable.
        "ALTER TABLE employees ADD COLUMN rest_days VARCHAR(20) NOT NULL DEFAULT '7'",

        // What the day-by-day computation found: unexcused absent days (and
        // what they cost a salaried employee) and approved, paid leave days.
        "ALTER TABLE payroll ADD COLUMN absent_days      DECIMAL(5,1)  NOT NULL DEFAULT 0",
        "ALTER TABLE payroll ADD COLUMN leave_days       DECIMAL(5,1)  NOT NULL DEFAULT 0",
        "ALTER TABLE payroll ADD COLUMN absent_deduction DECIMAL(12,2) NOT NULL DEFAULT 0",

        // One payroll / attendance line per employee per period, however many
        // times the period's file is uploaded again.
        "ALTER TABLE payroll    ADD UNIQUE KEY uq_payroll_period_emp    (period_id, emp_id)",
        "ALTER TABLE attendance ADD UNIQUE KEY uq_attendance_period_emp (period_id, emp_id)",

        // An employee's regular duty day in hours (e.g. 10 for a 10-hour
        // shift). NULL = Settings' standard. A daily-rate employee earns their
        // rate per duty day, so undertime costs rate ÷ these hours an hour.
        "ALTER TABLE employees ADD COLUMN hours_per_day DECIMAL(4,2) NULL DEFAULT NULL",

        // gross_pay now holds everything earned (basic + overtime − late),
        // as payslips, reports and the pharmacy's timesheets mean it; it used
        // to hold basic pay only. Old rows are converted ONCE: the flag marks
        // a converted row, new rows are written with it set, and only then
        // does the column default flip to 1 — so the UPDATE can never touch
        // a row twice however often this list runs.
        "ALTER TABLE payroll ADD COLUMN gross_incl_ot TINYINT(1) NOT NULL DEFAULT 0",
        "UPDATE payroll SET gross_pay = gross_pay + ot_late_adj, gross_incl_ot = 1 WHERE gross_incl_ot = 0",
        "ALTER TABLE payroll ALTER COLUMN gross_incl_ot SET DEFAULT 1",
        "ALTER TABLE attendance ADD COLUMN gross_incl_ot TINYINT(1) NOT NULL DEFAULT 0",
        "UPDATE attendance a JOIN payroll p ON p.period_id = a.period_id AND p.emp_id = a.emp_id
            SET a.gross_pay = p.gross_pay, a.gross_incl_ot = 1
          WHERE a.gross_incl_ot = 0 AND p.gross_incl_ot = 1",
        "ALTER TABLE attendance ALTER COLUMN gross_incl_ot SET DEFAULT 1",

        // biometric_daily was created with the server default collation while
        // every other table uses utf8mb4_unicode_ci, so joins on emp_id failed.
        "ALTER TABLE biometric_daily CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

        // Periods created before each kept its own schedule fell back to the
        // Settings default, so a whole-month "March 2026" was paid as a half
        // month. Give them the schedule their dates describe (only NULLs).
        "UPDATE payroll_periods SET period_type = CASE
            WHEN DAY(period_start) = 1 AND period_end = LAST_DAY(period_start) THEN 'Monthly'
            WHEN DATEDIFF(period_end, period_start) <= 7 THEN 'Weekly'
            ELSE 'Semi-Monthly' END
          WHERE period_type IS NULL",

        // A day the timesheet marks OFF (a rotating day off): no duty that day,
        // so it is neither worked nor absent, and never deducted.
        "ALTER TABLE biometric_daily ADD COLUMN day_off TINYINT(1) NOT NULL DEFAULT 0 AFTER entered_by",

        // What each pay line found besides absences: undertime (hours short of
        // full duty days, and what they cost) and days off in the period.
        "ALTER TABLE payroll ADD COLUMN undertime_hours     DECIMAL(6,2)  NOT NULL DEFAULT 0",
        "ALTER TABLE payroll ADD COLUMN undertime_deduction DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE payroll ADD COLUMN days_off            DECIMAL(5,1)  NOT NULL DEFAULT 0",

        // The net pay a payslip signature acknowledged. A later correction
        // changes the net, and then the old signature no longer applies.
        "ALTER TABLE payslip_signatures ADD COLUMN net_signed DECIMAL(12,2) NULL DEFAULT NULL",

        // Sign-in attempts, to slow down password guessing (see loginBlocked()).
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id           INT          NOT NULL AUTO_INCREMENT,
            ip           VARCHAR(45)  NOT NULL DEFAULT '',
            email        VARCHAR(150) NOT NULL DEFAULT '',
            ok           TINYINT(1)   NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email (email, attempted_at),
            KEY idx_ip (ip, attempted_at)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // The finalize / re-open trail itself — who did what, when, and why.
        "CREATE TABLE IF NOT EXISTS period_audit (
            id           INT          NOT NULL AUTO_INCREMENT,
            period_id    INT          NOT NULL,
            action       ENUM('Finalized','Reopened','Revised') NOT NULL,
            cycle        INT          NOT NULL DEFAULT 0,
            performed_by VARCHAR(150) DEFAULT NULL,
            note         VARCHAR(255) DEFAULT NULL,
            created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_period (period_id)
        )",

        // The branch list, managed in Settings. employees.branch and
        // users.branch still store the branch NAME rather than an id, so a
        // rename has to carry across to those columns — settings.php does
        // that inside one transaction.
        //
        // The collation is pinned to match employees/users: those names are
        // compared against this column, and MySQL refuses to compare two
        // different collations. Without this the table would inherit the
        // server default (utf8mb4_0900_ai_ci on MySQL 8) and every join
        // would fail with "Illegal mix of collations".
        "CREATE TABLE IF NOT EXISTS branches (
            id         INT          NOT NULL AUTO_INCREMENT,
            name       VARCHAR(100) NOT NULL,
            created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_branch_name (name)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Repairs a branches table created before the collation was pinned.
        "ALTER TABLE branches CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

        // ── Biometric ingest staging (mirrors sql/001_attendance_logs.sql) ──
        // Raw punches from the EPH A6, pushed by the desktop agent.
        // uq_punch is the duplicate guard: one person cannot punch twice in
        // the same second, so every re-sync is idempotent.
        "CREATE TABLE IF NOT EXISTS attendance_logs (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id VARCHAR(32) NOT NULL,
            punch_time  DATETIME    NOT NULL,
            punch_state TINYINT     NOT NULL DEFAULT 0,
            verify_mode TINYINT     NOT NULL DEFAULT 0,
            device_id   VARCHAR(40) NOT NULL DEFAULT '',
            source      VARCHAR(16) NOT NULL DEFAULT 'serial',
            processed   TINYINT(1)  NOT NULL DEFAULT 0,
            received_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_punch (employee_id, punch_time),
            KEY idx_unprocessed (processed, punch_time),
            KEY idx_punch_time (punch_time)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Terminal user number -> payroll emp_id. Collation is pinned because
        // emp_id is compared against employees.emp_id.
        "CREATE TABLE IF NOT EXISTS biometric_employee_map (
            device_user_id VARCHAR(32) NOT NULL,
            emp_id         VARCHAR(20) NOT NULL,
            device_id      VARCHAR(40) NOT NULL DEFAULT '',
            created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (device_user_id),
            KEY idx_emp (emp_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Which PC currently holds the terminal, and when it last reported.
        "CREATE TABLE IF NOT EXISTS biometric_agent_state (
            device_id       VARCHAR(40)  NOT NULL,
            agent_host      VARCHAR(100) NOT NULL DEFAULT '',
            agent_version   VARCHAR(20)  NOT NULL DEFAULT '',
            last_punch_time DATETIME     NULL DEFAULT NULL,
            last_sync_at    TIMESTAMP    NULL DEFAULT NULL,
            punches_total   INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (device_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // One API key per client PC, stored as a SHA-256 hash so the key
        // itself never sits in the database.
        "CREATE TABLE IF NOT EXISTS biometric_api_keys (
            id           INT          NOT NULL AUTO_INCREMENT,
            key_hash     CHAR(64)     NOT NULL,
            label        VARCHAR(100) NOT NULL DEFAULT '',
            device_id    VARCHAR(40)  NOT NULL DEFAULT '',
            active       TINYINT(1)   NOT NULL DEFAULT 1,
            last_used_at TIMESTAMP    NULL DEFAULT NULL,
            created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_key_hash (key_hash)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    /*
     * Run the list only when it has changed since the last run. The database
     * is in the cloud (~350 ms a round trip), so firing ~45 statements on
     * every page load cost 10+ seconds and could stall the single-threaded
     * local server. A fingerprint of the list is kept in settings; a normal
     * page load now costs one query.
     */
    $version = md5(implode("\n", $patches));
    try {
        $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'");
        $st->execute();
        if ($st->fetchColumn() === $version) return;
    } catch (PDOException $e) { /* fresh install: no settings table yet — run everything */ }

    foreach ($patches as $sql) {
        try { $db->exec($sql); } catch (PDOException $e) {}
    }

    // Adopt whatever branch names the employee rows already use, so an
    // existing install starts with a populated list instead of an empty one.
    // INSERT IGNORE against the unique key makes this safe to repeat.
    try {
        $db->exec("INSERT IGNORE INTO branches (name)
                   SELECT DISTINCT TRIM(branch) FROM employees
                    WHERE branch IS NOT NULL AND TRIM(branch) <> ''");
    } catch (PDOException $e) {}

    // Seed the admin user on first-ever run. The first password comes from
    // the ADMIN_INITIAL_PASSWORD environment variable. Without it, a local
    // install starts with the default password (and must change it at first
    // sign-in); the live site creates no admin at all rather than one anybody
    // could guess. After this runs once, credentials live only in users.
    try {
        $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        $first = (string)(getenv('ADMIN_INITIAL_PASSWORD') ?: '');
        if ($count === 0 && ($first !== '' || APP_ENV !== 'production')) {
            $email = getSetting('admin_email', 'admin@lnpharmacy.com') ?: 'admin@lnpharmacy.com';
            $db->prepare("INSERT INTO users (full_name, email, password_hash, role)
                          VALUES ('Admin', ?, ?, 'admin')")
               ->execute([$email, password_hash($first !== '' ? $first : DEFAULT_ADMIN_PASSWORD, PASSWORD_BCRYPT)]);
        }
    } catch (PDOException $e) {}

    /* Done — remember this list so the next page load skips it */
    try { setSetting('schema_version', $version); } catch (PDOException $e) {}
}

/*
 * requireAuth() — admin portal guard.
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

/* The password every fresh install starts with — never accepted on the live site */
const DEFAULT_ADMIN_PASSWORD = 'admin123';

/* Signed-in sessions end after this long without any page being opened */
const SESSION_IDLE_SECONDS = 8 * 3600;

/*
 * False (and the session is ended) when the user has been idle too long —
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
 * jsonResponse() — terminate with a JSON payload.
 * Used by all API endpoints under api/.
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/*
 * ── Official contribution and tax tables ──────────────────────────────
 * The single place these numbers live: the functions below compute with
 * them and Settings shows them (read-only), so the two can never disagree.
 * When an agency changes a rate, edit it here.
 * Last checked October 2026 — unchanged for 2026 by all four agencies.
 */
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

/* SSS monthly salary credit: the month's compensation to the nearest ₱500, ₱5,000–₱35,000 */
function sssCredit(float $compensation): float {
    $r = PH_RULES['sss'];
    return max($r['msc_min'], min(round($compensation / $r['msc_step']) * $r['msc_step'], $r['msc_max']));
}

/* SSS employee share: 5% of the salary credit. Compensation is ALL pay
   earned in the month — basic, overtime, everything (RA 11199, Sec. 8). */
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
 * which are non-taxable — computePayLine() takes them off before calling this.
 */
function birTax(float $taxable, string $table = 'monthly'): float {
    foreach (PH_RULES['bir']['tables'][$table] as [$over, $base, $rate]) {
        if ($taxable > $over) return round($base + ($taxable - $over) * $rate, 2);
    }
    return 0.0;
}

function birMonthlyTax(float $taxable): float {
    return birTax($taxable, 'monthly');
}

/*
 * When each contribution is taken in a month with more than one pay run
 * (Settings → Contribution Schedule):
 *   split   every cut-off takes what is due on the month's pay so far,
 *           and the month's last cut-off settles the rest
 *   second  nothing until the month's last cut-off, which takes the whole
 *           month on the month's actual pay
 * Either way the month ends exact: the contribution on the month's real
 * pay, no more, no less. The defaults follow L&N Pharmacy's own
 * timesheets — SSS from the 1st cut-off, PhilHealth and Pag-IBIG on the 2nd.
 */
const CONTRIBUTION_TIMING_DEFAULT = ['sss' => 'split', 'philhealth' => 'second', 'pagibig' => 'second'];

function contributionTiming(): array {
    $out = [];
    foreach (CONTRIBUTION_TIMING_DEFAULT as $k => $def) {
        $v = getSetting("contribution_timing_$k", '');
        $out[$k] = in_array($v, ['split', 'second'], true) ? $v : $def;
    }
    return $out;
}

/* One line on the screen: how this pay run takes the contributions */
function contributionPlanText(array $ctx): string {
    if ($ctx['period_type'] === 'Monthly') return "the month's contributions are taken in full";
    $names = ['sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG'];
    $split = $second = [];
    foreach ($ctx['timing'] as $k => $t) { if ($t === 'split') $split[] = $names[$k]; else $second[] = $names[$k]; }
    $join = fn($a) => count($a) > 1 ? implode(', ', array_slice($a, 0, -1)) . ' and ' . end($a) : implode('', $a);
    if ($ctx['final']) {
        return "last cut-off of the month: settles " . $join(array_values($names))
             . " on the month's actual pay, minus what earlier cut-offs took";
    }
    return ($split ? $join($split) . ' taken on the pay so far' : '')
         . ($split && $second ? '; ' : '')
         . ($second ? $join($second) . ' wait for the last cut-off' : '');
}

/*
 * Government contributions for one employee on one pay run — the employee's
 * share (deducted from pay) and the company's share (paid on top).
 *
 *   $basic     basic pay of this run (after absences and undertime)
 *   $earnings  everything earned this run: basic + overtime − late
 *
 * Contributions are monthly, so each is read on the pay earned so far this
 * calendar MONTH (earlier cut-offs + this one), minus what earlier cut-offs
 * already deducted:
 *   SSS         on all compensation earned (overtime included)
 *   PhilHealth  on basic pay; a salaried employee's last cut-off uses the full
 *               contract salary (PhilHealth is not prorated)
 *   Pag-IBIG    on basic pay, at most ₱10,000
 * Floors and caps (PhilHealth ₱250, SSS ₱5,000 credit, Pag-IBIG ₱200) are
 * therefore monthly — charged once, never twice — and the month ends exact.
 * Which runs take what follows contributionTiming().
 */
function contributionBreakdown(array $emp, float $basic, float $earnings, array $ctx): array {
    $type = $emp['salary_type'] ?? 'monthly';
    $prev = $ctx['earlier'][$emp['emp_id'] ?? ''] ?? EARLIER_NONE;

    $comp     = $prev['g'] + $earnings;      /* SSS: all pay earned this month so far */
    $basicM   = $prev['basic'] + $basic;     /* PhilHealth, Pag-IBIG: basic pay so far */
    $contract = $type === 'daily' ? 0.0 : monthlyEquivalent($type, (float)$emp['base_salary'], $ctx['working_days']);
    $phBasis  = ($ctx['final'] && $type !== 'daily') ? max($basicM, $contract) : $basicM;

    $on = ['sss'        => (int)($emp['deduct_sss']        ?? 1) === 1,
           'philhealth' => (int)($emp['deduct_philhealth'] ?? 1) === 1,
           'pagibig'    => (int)($emp['deduct_pagibig']    ?? 1) === 1];
    /* due on this run: always on the month's last run, earlier only when split */
    $due = [];
    foreach ($ctx['timing'] as $k => $t) $due[$k] = $ctx['final'] || $t === 'split';

    $R     = PH_RULES;
    $msc   = sssCredit($comp);
    $month = [   /* the month's employee shares on what is known so far */
        'sss'        => sssMonthly($comp),
        'philhealth' => philhealthMonthly($phBasis),
        'pagibig'    => pagibigMonthly($basicM),
    ];
    $ee = [];
    foreach ($month as $k => $amt) {
        $ee[$k] = ($on[$k] && $due[$k]) ? round(max(0.0, $amt - $prev[$k]), 2) : 0.0;
    }

    /* The company's share of what is due now. PhilHealth is split equally and
       Pag-IBIG is 2% each (company 2% even when the employee pays 1%); SSS
       is 10% against the employee's 5% — so each follows the employee share.
       EC is a flat monthly amount: what is still owed after earlier runs. */
    $pagRatio = $basicM <= $R['pagibig']['low_limit'] ? $R['pagibig']['er_rate'] / $R['pagibig']['rate_low'] : 1.0;
    $ecOf     = fn(float $credit) => $credit >= $R['sss']['ec_from'] ? $R['sss']['ec_high'] : $R['sss']['ec_low'];
    $ecPrev   = $prev['sss'] > 0 ? $ecOf(sssCredit($prev['g'])) : 0.0;
    $er = [
        'sss'        => round($ee['sss'] * ($R['sss']['er_rate'] / $R['sss']['ee_rate']), 2),
        'ec'         => ($on['sss'] && $due['sss']) ? round(max(0.0, $ecOf($msc) - $ecPrev), 2) : 0.0,
        'philhealth' => $ee['philhealth'],
        'pagibig'    => round($ee['pagibig'] * $pagRatio, 2),
    ];

    return [
        'comp'      => $comp,         // pay earned so far this month (SSS reads this)
        'basic'     => $basicM,       // basic pay so far this month (Pag-IBIG reads this)
        'ph_basis'  => $phBasis,      // what PhilHealth reads
        'earlier'   => $prev,         // what earlier cut-offs this month paid / deducted
        'due'       => $due,
        'msc'       => $on['sss'] ? $msc : 0.0,
        'on'        => $on,
        'month'     => $month,        // the month's employee shares on the pay so far
        'ee'        => $ee,
        'er'        => $er,
        'ee_total'  => round(array_sum($ee), 2),
        'er_total'  => round(array_sum($er), 2),
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
 * A row of the employees table, as the pay computation reads it.
 */
function payEmployee(array $e): array {
    return [
        'emp_id'            => (string)$e['emp_id'],
        'full_name'         => (string)($e['full_name'] ?? ''),
        'base_salary'       => (float)($e['base_salary'] ?? 0),
        'salary_type'       => $e['salary_type'] ?? 'monthly',
        'deduct_sss'        => (int)($e['deduct_sss']        ?? 1),
        'deduct_philhealth' => (int)($e['deduct_philhealth'] ?? 1),
        'deduct_pagibig'    => (int)($e['deduct_pagibig']    ?? 1),
        'hours_per_day'     => isset($e['hours_per_day']) && $e['hours_per_day'] !== null ? (float)$e['hours_per_day'] : null,
        'rest_days'         => $e['rest_days'] ?? '7',
    ];
}

/*
 * Hours to pay from day-by-day records, the way the pharmacy's timesheet
 * pays them: each duty day is one full day (the employee's duty hours,
 * e.g. 8 or 10) minus its undertime — the timesheet's own undertime when it
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
 * One employee's pay for one payroll run — shared by the day-by-day and
 * totals uploads, manual attendance and leave decisions, so they can never
 * compute differently.
 *
 *   $emp        payEmployee() row
 *   $paidHours  regular hours to pay. A daily-rate employee earns their rate
 *               per duty day of THEIR duty hours (8 by default, 10 for a
 *               10-hour shift), so undertime costs rate ÷ duty hours an hour.
 *   $perDay     true when $paidHours came from day-by-day records. Lateness is
 *               then not charged separately — coming in late only costs pay if
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
 * Gross pay is everything earned: basic + overtime − late — what the
 * pharmacy's timesheet calls GROSS PAY. Then, month-to-date (see
 * contributionBreakdown): SSS, PhilHealth, Pag-IBIG, and withholding tax on
 * gross minus those contributions — this run's BIR table (semi-monthly,
 * weekly), and on the month's last run the monthly table on the whole
 * month, minus what earlier runs withheld.
 *
 * Returns gross, basic, ot_late_adj, sss, philhealth, pagibig, tax, net,
 * absent_deduction, undertime_hours, undertime_deduction, taxable.
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
    $lateDeduction = $perDay ? 0.0 : $late * $ctx['late_rate'];

    if ($type === 'daily') {
        /* Daily rate × days worked, a day being the employee's duty hours.
           Undertime is already out of $paidHours; its cost is shown, not taken again. */
        $basic   = round($rate * $paidHours / $dayHours, 2);
        $underDed = round($rate * $under / $dayHours, 2);
    } else {
        /* Salaried, monthly or kinsenas: the slice of the month this run covers,
           less one day's rate (the month's pay over its working days) per absent
           day, and that day's rate ÷ duty hours per hour of undertime */
        $monthly   = monthlyEquivalent($type, $rate, $workDays);
        $dayRate   = $monthly / $workDays;
        $absentDed = round((float)($abs['absent'] ?? 0) * $dayRate, 2);
        $underDed  = round($under * $dayRate / $dayHours, 2);
        $basic     = max(0.0, round($monthly * $frac, 2) - $absentDed - $underDed);
    }

    $otLate = round($ot * $ctx['overtime_rate'] - $lateDeduction, 2);
    $gross  = round($basic + $otLate, 2);

    $c = contributionBreakdown($emp, $basic, $gross, $ctx);
    ['sss' => $sss, 'philhealth' => $ph, 'pagibig' => $pag] = $c['ee'];
    $contrib = $sss + $ph + $pag;

    /* Withholding tax on taxable pay: gross minus the employee's own
       contributions, which the law exempts */
    $taxable = max(0.0, $gross - $contrib);
    if ($ctx['final']) {
        $p     = $c['earlier'];
        $month = max(0.0, $p['g'] - $p['sss'] - $p['philhealth'] - $p['pagibig']) + $taxable;
        $tax   = round(max(0.0, birTax($month, 'monthly') - $p['tax']), 2);
    } else {
        $tax   = birTax($taxable, $ctx['tax_table']);
    }

    $net = round($gross - ($tax + $contrib), 2);

    return ['gross' => $gross, 'basic' => $basic, 'tax' => $tax, 'ot_late_adj' => $otLate,
            'sss' => $sss, 'philhealth' => $ph, 'pagibig' => $pag, 'net' => $net,
            'absent_deduction' => $absentDed, 'undertime_hours' => round($under, 2),
            'undertime_deduction' => $underDed, 'taxable' => round($taxable, 2)];
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

/* Working days in the month a period starts in — every day except the
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
 *              'hired'  => 'YYYY-MM-DD' | null,
 *              'branch' => branch name,
 *              'leave'  => ['YYYY-MM-DD' => ['status' => Approved|Pending|Rejected, 'type' => …]]]
 * Where leave requests overlap, Approved beats Pending beats Rejected.
 */
function attendanceCalendar(PDO $db, array $empIds, string $from, string $to): array {
    $empIds = array_values(array_unique(array_map('strval', $empIds)));
    if (!$empIds) return [];
    $in  = implode(',', array_fill(0, count($empIds), '?'));
    $cal = [];
    $st  = $db->prepare("SELECT * FROM employees WHERE emp_id IN ($in)");
    $st->execute($empIds);
    foreach ($st->fetchAll() as $e) {
        $cal[$e['emp_id']] = ['rest' => restDayList($e['rest_days'] ?? null), 'hired' => $e['date_hired'] ?: null,
                              'branch' => (string)($e['branch'] ?? ''), 'leave' => []];
    }
    $rank = ['Rejected' => 1, 'Pending' => 2, 'Approved' => 3];
    $st = $db->prepare("SELECT emp_id, leave_type, date_from, date_to, status FROM leave_requests
                         WHERE emp_id IN ($in) AND date_from <= ? AND date_to >= ?");
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
 *   worked   — has hours that day (a day off worked still counts as worked)
 *   nothired — before the employee's hire date
 *   off      — their weekly day off, or a day the timesheet marks OFF
 *              ($markedOff): never absent, never deducted
 *   leave    — approved leave: not absent, no deduction (paid)
 *   absent   — a working day with no hours and no approved leave
 *              (pending or rejected leave does not excuse it)
 */
function dayStatus(?array $cal, string $date, bool $worked, bool $markedOff = false): string {
    if ($worked) return 'worked';
    if ($cal && $cal['hired'] && $date < $cal['hired']) return 'nothired';
    if ($markedOff) return 'off';
    if (in_array((int)date('N', strtotime($date)), $cal['rest'] ?? [7], true)) return 'off';
    if (($cal['leave'][$date]['status'] ?? '') === 'Approved') return 'leave';
    return 'absent';
}

/*
 * The pay period a page opens on when none is picked: the one whose payroll
 * was computed most recently — what was just uploaded — else the newest one.
 * (Opening on the newest by date showed an old, unrelated period after an
 * upload into an earlier one.)
 */
function defaultPeriodId(PDO $db, array $periods): int {
    $ids  = array_map('intval', array_column($periods, 'id'));
    $last = (int)$db->query("SELECT period_id FROM payroll ORDER BY created_at DESC, id DESC LIMIT 1")->fetchColumn();
    return ($last && in_array($last, $ids, true)) ? $last : ($ids[0] ?? 0);
}

/* A period's pay schedule: its own, or the Settings default for older periods */
function periodType(?string $stored): string {
    $t = $stored ?: getSetting('payroll_period', 'Monthly');
    return in_array($t, ['Monthly', 'Semi-Monthly', 'Weekly'], true) ? $t : 'Monthly';
}

/* "Semi-Monthly · 1st half" etc., for period pickers and headings */
function periodTypeLabel(array $period): string {
    $type = periodType($period['period_type'] ?? null);
    if ($type !== 'Semi-Monthly') return $type;
    return 'Semi-Monthly · ' . ((int)date('j', strtotime($period['period_start'])) <= 15 ? '1st half' : '2nd half');
}

/*
 * Everything computePayLine() needs from Settings, the period and the
 * month's earlier pay runs. The arithmetic lives in buildPayContext(), which
 * touches no database, so it can be checked against sample timesheets.
 */
function payContext(PDO $db, int $periodId): array {
    try {
        $st = $db->prepare("SELECT period_start, period_end, period_type FROM payroll_periods WHERE id = ?");
        $st->execute([$periodId]);
    } catch (PDOException $e) {
        /* period_type not added yet (pages add it on load) — use the default */
        $st = $db->prepare("SELECT period_start, period_end, NULL AS period_type FROM payroll_periods WHERE id = ?");
        $st->execute([$periodId]);
    }
    $row   = $st->fetch() ?: ['period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t'), 'period_type' => null];
    $start = $row['period_start'];

    /*
     * Contributions and tax are settled per calendar MONTH. What earlier pay
     * runs of this same month already earned, deducted and withheld, per
     * employee — each cut-off then only takes what is still due.
     * gross_pay holds everything earned (basic + overtime − late), so basic
     * pay is gross_pay − ot_late_adj.
     */
    $monthStart  = date('Y-m-01', strtotime($start));
    $earlier     = [];
    $earlierRuns = 0;
    try {
        $st = $db->prepare("
            SELECT p.emp_id, SUM(p.gross_pay) g, SUM(p.gross_pay - p.ot_late_adj) basic,
                   SUM(p.sss) s, SUM(p.philhealth) ph, SUM(p.pagibig) pg, SUM(p.withholding_tax) tax
              FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
             WHERE pp.id <> ? AND pp.period_start >= ? AND pp.period_end < ?
             GROUP BY p.emp_id");
        /* only runs that ENDED before this one began — an overlapping period
           (e.g. an old whole-month period over the same days) is not history */
        $st->execute([$periodId, $monthStart, $start]);
        foreach ($st->fetchAll() as $r) {
            $earlier[$r['emp_id']] = ['g' => (float)$r['g'], 'basic' => (float)$r['basic'], 'sss' => (float)$r['s'],
                                      'philhealth' => (float)$r['ph'], 'pagibig' => (float)$r['pg'], 'tax' => (float)$r['tax']];
        }
        $st = $db->prepare("SELECT COUNT(*) FROM payroll_periods WHERE id <> ? AND period_start >= ? AND period_end < ?");
        $st->execute([$periodId, $monthStart, $start]);
        $earlierRuns = (int)$st->fetchColumn();
    } catch (PDOException $e) { /* older database: no history, each run stands alone */ }

    return buildPayContext($row, $earlier, $earlierRuns);
}

/*
 * The pay context for a period row (period_start, period_end, period_type),
 * given what earlier runs of the month carried (emp_id => EARLIER_NONE shape).
 */
function buildPayContext(array $period, array $earlier = [], int $earlierRuns = 0): array {
    $start = $period['period_start'];
    $end   = $period['period_end'] ?? $start;
    $type  = periodType($period['period_type'] ?? null);

    /* The month's last pay run — where the month is settled in full */
    $final = $type === 'Monthly'
          || date('Y-m', strtotime($end . ' +1 day')) !== date('Y-m', strtotime($start))
          || ($type === 'Weekly' && date('Y-m', strtotime($end . ' +7 days')) !== date('Y-m', strtotime($start)));

    return [
        'overtime_rate'    => (float)getSetting('overtime_rate', '150'),
        'late_rate'        => (float)getSetting('late_rate', '80'),
        'period_type'      => $type,
        'fraction'         => periodFraction($type),
        'semi'             => $type === 'Semi-Monthly',
        'half'             => (int)date('j', strtotime($start)) <= 15 ? 1 : 2,
        'timing'           => contributionTiming(),
        'tax_table'        => $type === 'Weekly' ? 'weekly' : ($type === 'Semi-Monthly' ? 'semi' : 'monthly'),
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
 *  Every PH bracket table above (tax, SSS, PhilHealth, Pag-IBIG)
 *  is written against a MONTHLY salary. A payroll run, though,
 *  covers whatever the payroll_period setting says — a whole
 *  month, a kinsena (half a month), or a week.
 *
 *  So the computation always works in two steps:
 *    1. monthlyEquivalent() — what this employee earns in a month,
 *       whichever way their rate happens to be quoted.
 *    2. periodFraction()    — how much of a month this run covers.
 *  Bracket amounts are looked up on (1) and then scaled by (2).
 * ============================================================= */

/*
 * How much of a month one payroll period covers.
 * Semi-Monthly is the kinsenas schedule: two runs per month.
 */
function periodFraction(?string $periodType = null): float {
    $periodType = $periodType ?? getSetting('payroll_period', 'Monthly');
    return match ($periodType) {
        'Semi-Monthly' => 0.5,
        'Weekly'       => 12.0 / 52.0,
        default        => 1.0,      /* Monthly */
    };
}

/*
 * An employee's full monthly-equivalent salary.
 *   monthly  — base_salary is already a month's pay
 *   kinsenas — base_salary is half a month's pay, so a month is twice it
 *   daily    — base_salary is one day's pay, times the month's working days
 */
function monthlyEquivalent(string $salaryType, float $baseSalary, int $workingDaysInMonth = 26): float {
    return match ($salaryType) {
        'kinsenas' => $baseSalary * 2.0,
        'daily'    => $baseSalary * $workingDaysInMonth,
        default    => $baseSalary,
    };
}

/* How a salary type is written on screen. */
function salaryTypeLabel(string $salaryType): string {
    return match ($salaryType) {
        'kinsenas' => 'Kinsenas',
        'daily'    => 'Daily',
        default    => 'Monthly',
    };
}

/* The unit a base_salary figure is quoted in, for form hints. */
function salaryRateUnit(string $salaryType): string {
    return match ($salaryType) {
        'kinsenas' => 'per kinsena',
        'daily'    => 'per day',
        default    => 'per month',
    };
}

/*
 * A name reduced to a comparable key: lowercase, punctuation dropped, words
 * sorted — so "DELA CRUZ, Juan" and "Juan Dela Cruz" come out the same.
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
    foreach ($db->query("SELECT emp_id, full_name FROM employees")->fetchAll() as $e) {
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
 * Uploads (and manual entries) only go into a period that is still Open —
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
 * Brings a period's attendance + payroll rows in line with freshly computed
 * ones. Uploads repeat — today's file, then tomorrow's, or the same sheet
 * again with one more line per person — so this must be safe to run any
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
 * Attendance tuples: period, emp, name, hours, ot, late, gross, tax.
 * Payroll tuples: period, emp, name, hours, ot, late, gross, tax, ot_late_adj,
 * sss, philhealth, pagibig, bonus [12], deductions [13], net [14] (before
 * bonus and deductions), absent days [15], leave days [16], absence
 * deduction [17], undertime hours [18], undertime deduction [19], days off
 * [20] — the last six 0 when left out (a totals file has no days to judge).
 */
function rewritePeriodRows(PDO $db, int $periodId, array $who, array $attRows, array $payRows): void {
    $st = $db->prepare("SELECT finalize_count FROM payroll_periods WHERE id = ?");
    $st->execute([$periodId]);
    $reopened = (int)$st->fetchColumn() > 0 ? 1 : 0;

    foreach ($payRows as &$p) {
        $p += [15 => 0, 16 => 0, 17 => 0, 18 => 0, 19 => 0, 20 => 0];
        $p[12] = 0; $p[13] = 0;                  /* a new line starts without adjustments */
        ksort($p);
    }
    unset($p);

    $db->beginTransaction();
    try {
        /* Lines of employees (in scope) who are no longer in the period */
        $keep = array_map(fn($p) => (string)$p[1], $payRows);
        foreach (['attendance', 'payroll'] as $table) {
            $sql  = "DELETE FROM $table WHERE period_id = ?";
            $args = [$periodId];
            if ($who['scope'] !== null) {
                if (!$who['scope']) continue;
                $sql .= ' AND emp_id IN (' . implode(',', array_fill(0, count($who['scope']), '?')) . ')';
                $args = array_merge($args, array_values($who['scope']));
            }
            if ($keep) {
                $sql .= ' AND emp_id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')';
                $args = array_merge($args, $keep);
            }
            $db->prepare($sql)->execute($args);
        }

        /* Approval survives only while the hours are unchanged. MySQL applies
           the SET list left to right, so the checks come before the hours. */
        $same = "hours_worked = VALUES(hours_worked) AND overtime_hours = VALUES(overtime_hours)
                 AND late_hours = VALUES(late_hours)";
        bulkInsert($db,
            "INSERT INTO attendance (period_id,emp_id,emp_name,hours_worked,overtime_hours,late_hours,gross_pay,withholding_tax,gross_incl_ot) VALUES",
            "(?,?,?,?,?,?,?,?,1)", $attRows,
            "ON DUPLICATE KEY UPDATE
                manager_approved = IF($same, manager_approved, 0),
                approved_by      = IF($same, approved_by, NULL),
                approved_at      = IF($same, approved_at, NULL),
                emp_name = VALUES(emp_name), hours_worked = VALUES(hours_worked),
                overtime_hours = VALUES(overtime_hours), late_hours = VALUES(late_hours),
                gross_pay = VALUES(gross_pay), withholding_tax = VALUES(withholding_tax), gross_incl_ot = 1");

        /* The new net keeps the row's own bonus and deductions. Revised is
           decided before net_pay is overwritten (left-to-right again). */
        $newNet  = "(VALUES(net_pay) + bonus - other_deductions)";
        $changed = "($reopened = 1 AND ABS(net_pay - $newNet) >= 0.005)";
        bulkInsert($db,
            "INSERT INTO payroll (period_id,emp_id,emp_name,hours_worked,overtime_hours,late_hours,
                                  gross_pay,withholding_tax,ot_late_adj,sss,philhealth,pagibig,
                                  bonus,other_deductions,net_pay,absent_days,leave_days,absent_deduction,
                                  undertime_hours,undertime_deduction,days_off,status,gross_incl_ot) VALUES",
            "(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Draft',1)", $payRows,
            "ON DUPLICATE KEY UPDATE
                revised_at = IF($changed, NOW(), revised_at),
                revised_after_finalize = IF($changed, 1, revised_after_finalize),
                net_pay = $newNet,
                emp_name = VALUES(emp_name), hours_worked = VALUES(hours_worked),
                overtime_hours = VALUES(overtime_hours), late_hours = VALUES(late_hours),
                gross_pay = VALUES(gross_pay), withholding_tax = VALUES(withholding_tax),
                ot_late_adj = VALUES(ot_late_adj), sss = VALUES(sss), philhealth = VALUES(philhealth),
                pagibig = VALUES(pagibig), absent_days = VALUES(absent_days), leave_days = VALUES(leave_days),
                absent_deduction = VALUES(absent_deduction), undertime_hours = VALUES(undertime_hours),
                undertime_deduction = VALUES(undertime_deduction), days_off = VALUES(days_off),
                status = 'Draft', gross_incl_ot = 1, created_at = CURRENT_TIMESTAMP");
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}

/*
 * Rebuilds a period's attendance + payroll from every day saved for it in
 * biometric_daily — earlier uploads, today's upload and manual entries alike.
 * This is what lets days accumulate: each save adds or replaces only its own
 * days, then the whole period is summed again. A manager recomputes only
 * their own employees. Returns how many employees got a payroll line.
 */
function recomputePeriodFromDaily(PDO $db, int $periodId, array $who): int {
    $ctx = payContext($db, $periodId);

    $pr = $db->prepare("SELECT period_start, period_end FROM payroll_periods WHERE id = ?");
    $pr->execute([$periodId]);
    $per = $pr->fetch();

    $emps = [];
    foreach ($db->query("SELECT * FROM employees")->fetchAll() as $er) $emps[$er['emp_id']] = payEmployee($er);
    $inScope = fn($id) => $who['scope'] === null || in_array((string)$id, array_map('strval', $who['scope']), true);

    /* Every day saved for the period. Only the period's own dates count —
       rows an old upload left outside them are ignored. */
    $st = $db->prepare("SELECT emp_id, att_date, hours_worked, overtime_hours, late_hours, undertime_hours, day_off
                          FROM biometric_daily WHERE period_id = ? AND att_date BETWEEN ? AND ?");
    $st->execute([$periodId, $per['period_start'], $per['period_end']]);
    $byEmp   = [];   /* emp_id => that employee's day rows */
    $worked  = [];   /* emp_id => [date => true]: days with hours */
    $offDay  = [];   /* emp_id => [date => true]: days the timesheet marks OFF */
    $covered = [];   /* emp_id => last date any record covers (worked, off or a 0-hour day) */
    foreach ($st->fetchAll() as $d) {
        $byEmp[$d['emp_id']][] = $d;
        if ((float)$d['hours_worked'] > 0) $worked[$d['emp_id']][$d['att_date']] = true;
        elseif ((int)$d['day_off'] === 1)  $offDay[$d['emp_id']][$d['att_date']] = true;
        $covered[$d['emp_id']] = max($covered[$d['emp_id']] ?? '', $d['att_date']);
    }

    /* One line per employee ID: one person spelled two ways is still one line.
       Days filed under a biometric number by an older upload (not a registered
       employee) have no rate, so no payroll line. Nor does someone whose only
       records are days off. */
    $lines = [];
    foreach ($byEmp as $id => $days) {
        if (isset($emps[$id]) && $inScope($id) && (isset($worked[$id]) || count($days) > count($offDay[$id] ?? []))) {
            $lines[$id] = true;
        }
    }

    /*
     * Absences. Every day of the period is one of: worked, day off (weekly, or
     * marked OFF on the timesheet), approved leave, before hire, or absent. A
     * day is only judged once attendance for it has been uploaded — up to the
     * latest day any record of the employee's branch covers — so days the
     * daily uploads have not reached yet are never counted as absent.
     */
    /* Someone on approved leave with no hours at all still gets a line */
    $st = $db->prepare("SELECT DISTINCT emp_id FROM leave_requests
                         WHERE status = 'Approved' AND date_from <= ? AND date_to >= ?");
    $st->execute([$per['period_end'], $per['period_start']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (isset($emps[$id]) && $inScope($id)) $lines[$id] = true;
    }

    $cal = attendanceCalendar($db, array_merge(array_keys($lines), array_keys($covered)),
                              $per['period_start'], $per['period_end']);
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
        for ($d = $per['period_start']; $d <= $per['period_end']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $s = dayStatus($cal[$id] ?? null, $d, isset($worked[$id][$d]), isset($offDay[$id][$d]));
            if ($s === 'leave')                    $leave++;
            if ($s === 'absent' && $d <= $upTo)    $absent++;
            if ($s === 'off'    && $d <= $upTo)    $off++;
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
            'working_days' => workingDaysInMonth($per['period_start'], $cal[$id]['rest'] ?? [7]),
        ]);

        $attRows[] = [$periodId, $id, $emp['full_name'], $t['hours'], $t['ot'], $t['late'], $pay['gross'], $pay['tax']];
        $payRows[] = [$periodId, $id, $emp['full_name'], $t['hours'], $t['ot'], $t['late'],
                      $pay['gross'], $pay['tax'], $pay['ot_late_adj'], $pay['sss'], $pay['philhealth'],
                      $pay['pagibig'], 0, 0, $pay['net'], $absent, $leave, $pay['absent_deduction'],
                      $pay['undertime_hours'], $pay['undertime_deduction'], $off];
    }

    rewritePeriodRows($db, $periodId, $who, $attRows, $payRows);

    /* Timesheets show which lines include a hand-entered day (and stop
       saying so once an upload has replaced those days) */
    $db->prepare("UPDATE attendance a
               LEFT JOIN (SELECT emp_id, MAX(entered_by) eb FROM biometric_daily
                           WHERE period_id = ? AND entered_by IS NOT NULL GROUP BY emp_id) d
                      ON a.emp_id = d.emp_id
                     SET a.manually_entered_by = d.eb
                   WHERE a.period_id = ?")->execute([$periodId, $periodId]);
    return count($payRows);
}

/*
 * Approve or reject a leave request — admin and manager alike — then bring
 * the payroll in line with it straight away:
 *   Approved  the days are leave: not absent, no deduction (paid)
 *   Rejected  an unworked day is absent: no pay for it
 * $scope: null = may decide for anyone, else the emp_ids allowed (manager).
 * Returns ['type' => success|error, 'text' => message].
 */
function decideLeave(PDO $db, int $id, string $action, string $note, ?array $scope): array {
    if (!in_array($action, ['approve', 'reject'], true)) return ['type' => 'error', 'text' => 'Unknown action.'];
    $st = $db->prepare("SELECT * FROM leave_requests WHERE id = ?");
    $st->execute([$id]);
    $lr = $st->fetch();
    if (!$lr || ($scope !== null && !in_array($lr['emp_id'], $scope, true))) {
        return ['type' => 'error', 'text' => 'Leave request not found among your employees.'];
    }
    $status = $action === 'approve' ? 'Approved' : 'Rejected';
    $db->prepare("UPDATE leave_requests SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE id = ?")
       ->execute([$status, currentActor(), $note, $id]);

    $text = "Leave request {$status}.";
    try {
        $periods = recomputeEmployeeOpenPeriods($db, $lr['emp_id'], $lr['date_from'], $lr['date_to']);
        if ($periods) {
            $text .= ' Payroll updated for ' . implode(', ', $periods) . ($status === 'Approved'
                ? ' — the leave days are not counted as absent.'
                : ' — days not worked count as absent, without pay.');
        }
    } catch (PDOException $e) {
        $text .= ' The payroll could not be updated yet (Reference: ' . logAppError($e) . '); it will be on the next upload.';
    }
    /* A finalized period is never changed behind the admin's back */
    $st = $db->prepare("SELECT period_label FROM payroll_periods
                         WHERE status <> 'Open' AND period_start <= ? AND period_end >= ?");
    $st->execute([$lr['date_to'], $lr['date_from']]);
    if ($locked = $st->fetchAll(PDO::FETCH_COLUMN)) {
        $text .= ' ' . implode(', ', $locked) . ' is finalized, so its payroll was not changed — '
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
                         WHERE p.status = 'Open' AND p.period_start <= ? AND p.period_end >= ?
                           AND EXISTS (SELECT 1 FROM biometric_daily b WHERE b.period_id = p.id)");
    $st->execute([$to, $from]);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[(int)$id] = true;
        foreach (laterPeriodsInMonth($db, (int)$id) as $later) $ids[$later] = true;
    }
    if (!$ids) return [];

    /* oldest first: each cut-off settles the month against the ones before it */
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, period_label FROM payroll_periods WHERE id IN ($in) ORDER BY period_start");
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
    $st = $db->prepare("SELECT period_start, period_end FROM payroll_periods WHERE id = ?");
    $st->execute([$periodId]);
    $p = $st->fetch();
    if (!$p) return [];
    $st = $db->prepare("SELECT l.id FROM payroll_periods l
                         WHERE l.status = 'Open' AND l.id <> ? AND l.period_start > ? AND l.period_start <= ?
                           AND EXISTS (SELECT 1 FROM biometric_daily b WHERE b.period_id = l.id)
                         ORDER BY l.period_start");
    $st->execute([$periodId, $p['period_end'], date('Y-m-t', strtotime($p['period_start']))]);
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
 * Of the given "emp_id|YYYY-MM-DD" days, the ones already saved in ANOTHER
 * period that covers that date, as key => that period's label. A day
 * belongs to one pay period only, or it would be paid twice.
 */
function dailyDaysElsewhere(PDO $db, int $periodId, array $keys): array {
    if (!$keys) return [];
    $ids   = [];
    $dates = [];
    foreach ($keys as $k) {
        [$id, $date] = explode('|', $k, 2);
        $ids[$id] = true;
        $dates[]  = $date;
    }
    $ids = array_keys($ids);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $db->prepare("
        SELECT b.emp_id, b.att_date, p.period_label
          FROM biometric_daily b
          JOIN payroll_periods p ON p.id = b.period_id
         WHERE b.period_id <> ? AND b.emp_id IN ($in)
           AND b.att_date BETWEEN ? AND ?
           AND b.att_date BETWEEN p.period_start AND p.period_end");
    $st->execute(array_merge([$periodId], $ids, [min($dates), max($dates)]));

    $want = array_flip($keys);
    $out  = [];
    foreach ($st->fetchAll() as $d) {
        $key = $d['emp_id'] . '|' . $d['att_date'];
        if (isset($want[$key])) $out[$key] = $d['period_label'];
    }
    return $out;
}

/*
 * One calendar month of attendance, added up from every day saved so far
 * (daily uploads + manual entries), for the "This Month's Attendance" pages.
 *   $ym     'YYYY-MM'
 *   $scope  null = every employee, otherwise the emp_ids to show
 *   $withPay  also sum the draft/final payroll of the periods inside the month
 * Only days inside their own pay period's dates count — the same rule the
 * payroll uses — so the page and the payroll always agree.
 */
function monthAttendance(PDO $db, string $ym, ?array $scope, bool $withPay = true): array {
    $start = $ym . '-01';
    $end   = date('Y-m-t', strtotime($start));
    $std   = max(1, (float)getSetting('standard_hours', '8'));

    $empSql  = "SELECT emp_id, full_name, branch, hours_per_day FROM employees WHERE status = 'Active'";
    $dayScope = '';
    $args     = [];
    if ($scope !== null) {
        if (!$scope) $scope = [''];
        $in       = implode(',', array_fill(0, count($scope), '?'));
        $empSql  .= " AND emp_id IN ($in)";
        $dayScope = " AND b.emp_id IN ($in)";
        $args     = array_values($scope);
    }
    $st = $db->prepare($empSql . ' ORDER BY full_name');
    $st->execute($args);
    $emps = [];
    foreach ($st->fetchAll() as $e) {
        $emps[$e['emp_id']] = ['name' => $e['full_name'], 'branch' => $e['branch'] ?? '', 'days' => [],
                               'std' => (float)$e['hours_per_day'] > 0 ? (float)$e['hours_per_day'] : $std,
                               'present' => 0, 'hours' => 0.0, 'ot' => 0.0, 'late' => 0.0, 'under' => 0.0,
                               'manual' => 0, 'last' => null, 'covered' => '', 'gross' => null, 'net' => null];
    }

    $st = $db->prepare("
        SELECT b.emp_id, b.emp_name, b.att_date, b.hours_worked, b.overtime_hours, b.late_hours,
               b.undertime_hours, b.day_off, b.entered_by, p.period_label
          FROM biometric_daily b
          JOIN payroll_periods p ON p.id = b.period_id
         WHERE b.att_date BETWEEN ? AND ?
           AND b.att_date BETWEEN p.period_start AND p.period_end $dayScope
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
            'under' => $under, 'off' => $h <= 0 && (int)$d['day_off'] === 1,
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
     * timesheet), leave (approved / pending / rejected) or absent — judged
     * the same way as the payroll, and only up to the latest day the uploads
     * of the employee's branch cover.
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
            if ($s === 'worked' || $s === 'nothired') continue;
            if ($s === 'off')   { $e['marks'][$n] = 'off';   if ($date <= $upTo) $e['off']++; continue; }
            if ($s === 'leave') { $e['marks'][$n] = 'leave'; $e['leave']++; continue; }
            if ($date > $upTo) {
                /* not uploaded yet — only show a leave still waiting for a decision */
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
    $pst = $db->prepare("SELECT id, period_label, period_start, period_end, status FROM payroll_periods
                          WHERE period_start <= ? AND period_end >= ? ORDER BY period_start");
    $pst->execute([$end, $start]);
    $periods = $pst->fetchAll();

    if ($withPay && $periods) {
        $ids = array_column($periods, 'id');
        $st  = $db->prepare("SELECT emp_id, SUM(gross_pay) g, SUM(net_pay) n FROM payroll
                              WHERE period_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                              GROUP BY emp_id");
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
 * them (the employee's weekly days off left out) — what payroll pays as leave.
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

/* "3 days (2 duty days)" — or just "3 days" when they are the same */
function leaveDaysLabel(string $from, string $to, ?string $restDays): string {
    ['calendar' => $c, 'duty' => $d] = leaveDays($from, $to, $restDays);
    $txt = $c . ' day' . ($c === 1 ? '' : 's');
    return $d === $c ? $txt : $txt . ' (' . $d . ' duty day' . ($d === 1 ? '' : 's') . ')';
}

/*
 * The pay period "now" is about: the one whose dates hold today, else the
 * latest one by date (never by id — periods are often created out of order).
 * $openOnly limits it to periods still open.
 */
function currentPeriod(PDO $db, bool $openOnly = false): ?array {
    $open  = $openOnly ? " AND status = 'Open'" : '';
    $today = date('Y-m-d');
    $st = $db->prepare("SELECT * FROM payroll_periods WHERE period_start <= ? AND period_end >= ?$open
                         ORDER BY period_start DESC LIMIT 1");
    $st->execute([$today, $today]);
    if ($p = $st->fetch()) return $p;
    $st = $db->query("SELECT * FROM payroll_periods WHERE 1 = 1$open ORDER BY period_start DESC, id DESC LIMIT 1");
    return $st->fetch() ?: null;
}

/*
 * Another period whose dates overlap start..end, or null. Two periods
 * covering the same days would pay those days twice.
 */
function overlappingPeriod(PDO $db, string $start, string $end, int $exceptId = 0): ?array {
    $st = $db->prepare("SELECT id, period_label, period_start, period_end, period_type FROM payroll_periods
                         WHERE period_start <= ? AND period_end >= ? AND id <> ?
                         ORDER BY period_start LIMIT 1");
    $st->execute([$end, $start, $exceptId]);
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
    $known = $db->prepare("SELECT COUNT(*) FROM employees WHERE emp_id = ?");
    $save  = $db->prepare("INSERT INTO biometric_employee_map (device_user_id, emp_id) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE emp_id = VALUES(emp_id)");
    foreach ($links as $deviceId => $empId) {
        $deviceId = substr(trim((string)$deviceId), 0, 32);
        $empId    = trim((string)$empId);
        if ($deviceId === '' || $empId === '') continue;
        if ($scope !== null && !isset($scope[$empId])) continue;
        $known->execute([$empId]);
        if (!$known->fetchColumn()) continue;
        $save->execute([$deviceId, $empId]);
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
    foreach ($db->query("SELECT emp_id, full_name FROM employees ORDER BY full_name")->fetchAll() as $e) {
        if ($scope === null || in_array($e['emp_id'], $scope, true)) {
            $emps[] = ['emp_id' => $e['emp_id'], 'full_name' => $e['full_name']];
        }
    }
    $map = [];
    foreach ($db->query("SELECT device_user_id, emp_id FROM biometric_employee_map")->fetchAll() as $m) {
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
 *   $tuple "(?,?,?)" — one row's placeholders
 *   $tail  optional, e.g. "ON DUPLICATE KEY UPDATE ..."
 */
function bulkInsert(PDO $db, string $head, string $tuple, array $rows, string $tail = '', int $chunk = 200): void {
    foreach (array_chunk($rows, $chunk) as $part) {
        $sql = $head . ' ' . implode(',', array_fill(0, count($part), $tuple)) . ' ' . $tail;
        $db->prepare($sql)->execute(array_merge(...$part));
    }
}

/*
 * getSetting() — read one value from the settings table.
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
 * setSetting() — write one value into the settings table.
 * Uses INSERT … ON DUPLICATE KEY UPDATE (setting_key is the PK) so a
 * brand-new key — e.g. the company letterhead fields — is created on first
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
 * Save a payslip signature — only for a finalized period, whose figures can no
 * longer change, and stamped with the net pay it acknowledges.
 * Returns [ok, message].
 */
function savePayslipSignature(PDO $db, array $payrollRow, string $periodLabel, string $signatureData): array {
    if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+={0,2}$#', $signatureData) || strlen($signatureData) > 2000000) {
        return [false, 'That signature could not be read. Clear the pad and sign again.'];
    }
    $st = $db->prepare("SELECT status FROM payroll_periods WHERE id = ?");
    $st->execute([$payrollRow['period_id']]);
    if ($st->fetchColumn() === 'Open') {
        return [false, 'This pay period is still open, so its figures can still change. '
                     . 'Finalize it in Payroll Processing first, then collect the signature.'];
    }
    $db->prepare("
        INSERT INTO payslip_signatures (payroll_id, emp_id, emp_name, period_id, period_label, signature_data, net_signed)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE signature_data = VALUES(signature_data), net_signed = VALUES(net_signed), signed_at = NOW()
    ")->execute([$payrollRow['id'], $payrollRow['emp_id'], $payrollRow['emp_name'],
                 $payrollRow['period_id'], $periodLabel, $signatureData, $payrollRow['net_pay']]);
    return [true, 'Signature saved.'];
}

/*
 * currentActor() — the human name to stamp on an audit row.
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
 * logPeriodAudit() — append one row to the finalize / re-open trail.
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
        )->execute([$periodId, $action, $cycle, currentActor(), $note !== '' ? $note : null]);
    } catch (PDOException $e) { /* never block the caller */ }
}

/*
 * periodRevisionInfo() — everything a page needs to describe one period's
 * revision state in a single query pair.
 *
 *   reopened  — has this period ever been unlocked after a finalize?
 *   cycle     — how many times it has been finalized
 *   revised   — how many payroll rows changed after a finalize
 *   entries   — how many bonus/deduction entries were recorded as corrections
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

        $st = $db->prepare('SELECT COUNT(*) FROM bonus_deduction_history
                             WHERE period_id = ? AND finalize_cycle > 0');
        $st->execute([$periodId]);
        $out['entries'] = (int)$st->fetchColumn();
    } catch (PDOException $e) { /* pre-patch database — report "not revised" */ }

    return $out;
}
