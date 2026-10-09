<?php
/*
 * api/rollup-punches.php
 *
 * Turns raw punches in attendance_logs into per-day hour totals, in exactly
 * the row shape api/save-daily-attendance.php already expects:
 *
 *     { emp_id, emp_name, att_date, hours_worked, overtime_hours, late_hours }
 *
 * It deliberately does NOT write to biometric_daily, attendance or payroll
 * itself. It hands the rows back so the existing Daily Biometrics flow can
 * post them through save-daily-attendance.php - which means every bit of
 * payroll maths (SSS, PhilHealth, Pag-IBIG, BIR brackets, OT and late rates)
 * stays in one place instead of being duplicated here.
 *
 *   GET/POST api/rollup-punches.php?period_id=12
 *   -> {"ok":true,"rows":[...],"unmatched":[...],"days":31,"punches":420}
 *
 * Admin session required - this is an operator action, not a machine one.
 *
 * Shift rules come from the settings table, so they can be changed without
 * touching code:
 *     shift_start     default 08:00
 *     shift_end       default 17:00
 *     grace_minutes   default 15
 *     break_minutes   default 60
 *     standard_hours  default 8 - an employee's own Hours per Duty Day wins
 */

require __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) {
    jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$db        = getDB();
$period_id = (int)($_REQUEST['period_id'] ?? 0);

if (!$period_id) {
    jsonResponse(['ok' => false, 'error' => 'Missing period_id'], 400);
}

/* ── Period window ────────────────────────────────────────────── */
$pStmt = $db->prepare("SELECT period_start, period_end FROM payroll_periods WHERE id = ?");
$pStmt->execute([$period_id]);
$period = $pStmt->fetch();

if (!$period) {
    jsonResponse(['ok' => false, 'error' => 'Unknown period_id'], 404);
}

$startDate = $period['period_start'];
$endDate   = $period['period_end'];

/* ── Shift rules ──────────────────────────────────────────────── */
$shiftStart    = getSetting('shift_start',    '08:00');
$graceMinutes  = (int)getSetting('grace_minutes',  '15');
$breakMinutes  = (int)getSetting('break_minutes',  '60');
$standardHours = (float)getSetting('standard_hours', '8');

/* ── Employee lookup + terminal-number mapping ─────────────────── */
$employees = [];
$dayHours  = [];   /* emp_id => that employee's duty day (10 for a 10-hour shift) */
foreach ($db->query("SELECT emp_id, full_name, hours_per_day FROM employees")->fetchAll() as $row) {
    $employees[(string)$row['emp_id']] = $row['full_name'];
    $dayHours[(string)$row['emp_id']]  = (float)$row['hours_per_day'] > 0 ? (float)$row['hours_per_day'] : $standardHours;
}

$map = [];
foreach ($db->query("SELECT device_user_id, emp_id FROM biometric_employee_map")->fetchAll() as $row) {
    $map[(string)$row['device_user_id']] = (string)$row['emp_id'];
}

/*
 * Resolve a terminal user number to a payroll emp_id.
 * Explicit mapping wins; otherwise try the number as-is, then with leading
 * zeros stripped ("00001" -> "1"), which is how these terminals pad IDs.
 */
function resolveEmpId(string $deviceUserId, array $map, array $employees): ?string {
    if (isset($map[$deviceUserId]))              return $map[$deviceUserId];
    if (isset($employees[$deviceUserId]))        return $deviceUserId;

    $stripped = ltrim($deviceUserId, '0');
    if ($stripped !== '' && isset($employees[$stripped])) return $stripped;

    return null;
}

/* ── Pull the period's punches ────────────────────────────────── */
$punchStmt = $db->prepare(
    "SELECT employee_id, punch_time, punch_state
       FROM attendance_logs
      WHERE punch_time >= ? AND punch_time < DATE_ADD(?, INTERVAL 1 DAY)
      ORDER BY employee_id, punch_time"
);
$punchStmt->execute([$startDate, $endDate]);

/* Bucket by employee + calendar day. */
$byDay       = [];
$unmatched   = [];
$punchCount  = 0;

foreach ($punchStmt as $row) {
    $punchCount++;

    $empId = resolveEmpId((string)$row['employee_id'], $map, $employees);
    if ($empId === null) {
        // Report it once, with a count, so the operator can add a mapping.
        $key = (string)$row['employee_id'];
        $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
        continue;
    }

    $day = substr($row['punch_time'], 0, 10);
    $byDay[$empId][$day][] = [
        'time'  => $row['punch_time'],
        'state' => (int)$row['punch_state'],
    ];
}

/* ── Collapse each day into hours ─────────────────────────────── */
$rows = [];

foreach ($byDay as $empId => $days) {
    $std = $dayHours[$empId] ?? $standardHours;
    foreach ($days as $day => $punches) {
        // Punches arrive ordered, but a mixed-source day can interleave.
        usort($punches, fn($a, $b) => strcmp($a['time'], $b['time']));

        $first = $punches[0]['time'];
        $last  = $punches[count($punches) - 1]['time'];

        // A lone punch means someone forgot to clock out - record the day
        // with zero hours so it shows up for manual correction rather than
        // silently inflating or vanishing.
        if (count($punches) < 2) {
            $rows[] = [
                'emp_id'         => $empId,
                'emp_name'       => $employees[$empId] ?? '',
                'att_date'       => $day,
                'hours_worked'   => 0,
                'overtime_hours' => 0,
                'late_hours'     => 0,
                'incomplete'     => true,
            ];
            continue;
        }

        $inTs  = strtotime($first);
        $outTs = strtotime($last);
        $span  = ($outTs - $inTs) / 3600.0;

        /* Deduct the unpaid break only from a day long enough to have taken one - but never so that a LONGER
           day pays LESS: a half day (4 h) pays 4 h, and so must one that runs a few minutes past it. Past half
           the duty day the break comes off, down to no less than that half day. */
        if ($span > ($std / 2)) {
            $span = max($std / 2, $span - $breakMinutes / 60.0);
        }
        if ($span < 0) $span = 0;

        /* Late: arrival after shift start + grace. */
        $shiftTs = strtotime($day . ' ' . $shiftStart);
        $late    = 0.0;
        if ($shiftTs && $inTs > $shiftTs + ($graceMinutes * 60)) {
            $late = ($inTs - $shiftTs) / 3600.0;
        }

        /* Overtime: anything past the employee's own duty day. */
        $worked   = min($span, $std);
        $overtime = max(0.0, $span - $std);

        $rows[] = [
            'emp_id'         => $empId,
            'emp_name'       => $employees[$empId] ?? '',
            'att_date'       => $day,
            'hours_worked'   => round($worked,   2),
            'overtime_hours' => round($overtime, 2),
            'late_hours'     => round($late,     2),
            'incomplete'     => false,
        ];
    }
}

/* Stable order: by employee, then date. */
usort($rows, fn($a, $b) => [$a['emp_id'], $a['att_date']] <=> [$b['emp_id'], $b['att_date']]);

/* Reshape unmatched into something readable in the UI. */
$unmatchedOut = [];
foreach ($unmatched as $deviceUserId => $count) {
    $unmatchedOut[] = ['device_user_id' => $deviceUserId, 'punches' => $count];
}

jsonResponse([
    'ok'           => true,
    'period_id'    => $period_id,
    'period_start' => $startDate,
    'period_end'   => $endDate,
    'punches'      => $punchCount,
    'days'         => count($rows),
    'rows'         => $rows,
    'unmatched'    => $unmatchedOut,
]);
