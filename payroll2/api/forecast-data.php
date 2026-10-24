<?php
/*
 * api/forecast-data.php
 * Returns all finalized (and draft) payroll period totals as JSON.
 * The forecast page uses this to feed the Random Forest and ARIMA models.
 *
 * Each record contains:
 *   period_index   - sequential integer (1, 2, 3…) used as the trend feature
 *   month          - calendar month number 1-12, used for seasonality
 *   year           - 4-digit year
 *   label          - human-readable period label
 *   employee_count - distinct employees in that period
 *   total_gross    - sum of gross_pay for the period
 *   total_net      - sum of net_pay  (this is the TARGET we predict)
 *   total_bonus    - sum of bonus adjustments
 *   total_deductions - sum of other_deductions
 *   avg_gross      - average gross pay per employee
 */

require '../includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) { jsonResponse(['error' => 'Unauthorized'], 401); }

$db = getDB();

/* Aggregate payroll figures per period, ordered oldest-first so period_index is chronological */
$rows = $db->query("
    SELECT
        pp.id,
        pp.period_label                          AS label,
        pp.start_date,
        pp.period_type,
        pp.status,
        COUNT(DISTINCT p.employee_id)            AS employee_count,
        COALESCE(SUM(p.gross_pay), 0)            AS total_gross,
        COALESCE(SUM(p.net_pay),   0)            AS total_net,
        COALESCE(SUM(p.bonus_pay), 0)            AS total_bonus,
        COALESCE(SUM(p.other_deductions), 0)     AS total_deductions,
        COALESCE(SUM(p.withholding_tax),  0)     AS total_tax,
        CASE
            WHEN COUNT(DISTINCT p.employee_id) > 0
            THEN COALESCE(SUM(p.gross_pay), 0) / COUNT(DISTINCT p.employee_id)
            ELSE 0
        END                                      AS avg_gross
    FROM payroll_periods pp
    LEFT JOIN payroll p ON p.period_id = pp.id
    GROUP BY pp.id
    ORDER BY pp.start_date ASC, pp.id ASC
")->fetchAll();

/*
 * The payroll calendar the models have to forecast on. A kinsenas shop runs
 * two periods a month, so "the next period" is not "the next month", and the
 * seasonal signal is (month, half) rather than month on its own.
 */
/* …read from the most recent period itself (each period carries its own schedule); the schedule most employees are on when there is none */
$lastType        = null;
foreach ($rows as $r0) { if ((float)$r0['total_net'] > 0) $lastType = $r0['period_type'] ?? null; }
$periodType      = periodType($lastType);
$periodsPerMonth = match ($periodType) {
    'Semi-Monthly' => 2,
    'Weekly'       => 4,
    default        => 1,
};

/* What the company adds on top of the pay it hands out (employer SSS, EC, PhilHealth, Pag-IBIG), per period */
$employer = employerSharesByPeriod($db);

/* Attach a sequential index and extract month/year from start_date */
$result = [];
foreach ($rows as $i => $r) {
    /* Fall back to extracting month/year from the label if start_date is null */
    $ts    = $r['start_date'] ? strtotime($r['start_date']) : null;
    $month = $ts ? (int)date('n', $ts) : 0;
    $year  = $ts ? (int)date('Y', $ts) : 0;

    /*
     * Which half of the month this run covers, read from the start day so it
     * works whether the period was labelled "Apr 1-15" or "April 2026".
     * Always 1 on a monthly calendar.
     */
    $startDay = $ts ? (int)date('j', $ts) : 1;
    $half     = ($periodsPerMonth >= 2 && $startDay > 15) ? 2 : 1;

    $result[] = [
        'id'               => (int)$r['id'],    /* actual DB primary key - needed by period-detail API */
        'period_index'     => $i + 1,
        'month'            => $month,
        'year'             => $year,
        'half'             => $half,
        'start_date'     => $r['start_date'],
        'label'            => $r['label'],
        'status'           => $r['status'],
        'employee_count'   => (int)$r['employee_count'],
        'total_gross'      => (float)$r['total_gross'],
        'total_net'        => (float)$r['total_net'],
        'total_bonus'      => (float)$r['total_bonus'],
        'total_deductions' => (float)$r['total_deductions'],
        'total_tax'        => (float)$r['total_tax'],
        'avg_gross'        => (float)$r['avg_gross'],
        /* company cost: pay + bonus + the employer's SSS / EC / PhilHealth / Pag-IBIG - what a budget has to cover */
        'total_employer_share' => $employer[(int)$r['id']]['total'] ?? 0.0,
        'total_labor_cost'     => round((float)$r['total_gross'] + (float)$r['total_bonus'] + ($employer[(int)$r['id']]['total'] ?? 0.0), 2),
    ];
}

jsonResponse([
    'success'           => true,
    'period_type'       => $periodType,
    'periods_per_month' => $periodsPerMonth,
    'data'              => $result,
    'count'             => count($result),
]);
