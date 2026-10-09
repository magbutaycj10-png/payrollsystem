<?php
/*
 * api/save-attendance.php
 * Receives parsed attendance rows from the front-end (attendance-upload.js),
 * computes payroll figures (SSS, PhilHealth, Pag-IBIG, OT/late adjustments),
 * saves them to the attendance and payroll tables, then returns a JSON response.
 *
 * Rows carry a name only - the system assigns its own employee IDs, so each
 * name is matched against Employee Management. Names that match nobody are
 * not saved; they come back as mismatches for the front-end to show.
 *
 * Used by the admin (whole company) and by managers (their own employees,
 * open periods only).
 */

require_once __DIR__ . '/../includes/helpers.php';
set_time_limit(300); /* cloud DB is slow; a big file must not die halfway */

$who  = attendanceUploader();
$db   = getDB();
$body = json_decode(file_get_contents('php://input'), true);

/* The payroll period this file belongs to, and the parsed row data */
$period_id = (int)($body['period_id'] ?? 0);
$rows      = $body['rows'] ?? [];

if (!$period_id || empty($rows)) {
    jsonResponse(['error' => 'Missing period_id or rows'], 400);
}
uploadPeriodGuard($db, $period_id, $who);

/*
 * A totals file REPLACES the period's attendance. If day-by-day records are
 * already saved for it (daily uploads, manual entries), that would throw
 * away days recorded earlier - so the uploader is asked first, and only on
 * a confirmed replace are those days cleared along with the old totals.
 */
$dayScope = '';
$dayArgs  = [$period_id];
if ($who['scope'] !== null) {
    $dayScope = $who['scope'] ? ' AND emp_id IN (' . implode(',', array_fill(0, count($who['scope']), '?')) . ')' : ' AND 1 = 0';
    $dayArgs  = array_merge($dayArgs, array_values($who['scope']));
}
$dc = $db->prepare("SELECT COUNT(*) FROM biometric_daily WHERE period_id = ?$dayScope");
$dc->execute($dayArgs);
$savedDays = (int)$dc->fetchColumn();
if ($savedDays && empty($body['replace_days'])) {
    jsonResponse(['error' => 'has_days', 'days' => $savedDays], 409);
}

saveBioLinks($db, $body['bio_links'] ?? [], $who);   /* device report -> employee choices */

/* Registered employees this uploader may write for: emp_id => full name */
$names = [];
foreach ($db->query("SELECT emp_id, full_name FROM employees")->fetchAll() as $e) {
    $names[(string)$e['emp_id']] = $e['full_name'];
}
$scope = $who['scope'] === null ? $names : array_intersect_key($names, array_flip($who['scope']));

/*
 * Match each row to an employee by name and give it that employee's system
 * ID. Rows matching nobody, or someone outside a manager's scope, are
 * dropped here and reported.
 */
$resolve    = employeeResolver($db);
$mismatches = [];
$matched    = [];
$invalid    = [];   /* lines whose hours cannot be right (negative, text, more than the period holds) - not saved, reported */

/* How many calendar days the period holds: the most hours a line can honestly carry */
$pp = $db->prepare("SELECT period_start, period_end FROM payroll_periods WHERE id = ?");
$pp->execute([$period_id]);
$perRow     = $pp->fetch();
$periodDays = $perRow ? (int)round((strtotime($perRow['period_end']) - strtotime($perRow['period_start'])) / 86400) + 1 : 31;

foreach ($rows as $r) {
    $name = trim((string)($r['emp_name'] ?? ''));
    if ($name === '') continue;

    $sysId = $resolve('', $name);
    if ($sysId === null) {
        $mismatches[] = ['emp_id' => '', 'name' => $name, 'type' => 'csv_only',
                         'issue' => 'No employee with this name in Employee Management'];
        continue;
    }
    if (!isset($scope[$sysId])) {
        $mismatches[] = ['emp_id' => $sysId, 'name' => $name, 'type' => 'out_of_scope',
                         'issue' => 'Not one of your assigned employees'];
        continue;
    }
    $r['emp_id']   = $sysId;
    $r['emp_name'] = $names[$sysId];   /* stored as registered, not as typed */

    $problem = periodHoursProblem($r['hours_worked'] ?? 0, $r['overtime_hours'] ?? 0, $r['late_hours'] ?? 0, $periodDays)
            ?? (isset($r['paid_hours']) ? periodHoursProblem($r['paid_hours'], 0, 0, $periodDays) : null);
    if ($problem !== null) {
        $invalid[] = ['emp_name' => $names[$sysId], 'why' => $problem];
        continue;
    }

    /* Two spellings of one person in the file are still one payroll line */
    if (isset($matched[$sysId])) {
        foreach (['hours_worked', 'overtime_hours', 'late_hours', 'paid_hours'] as $f) {
            if (isset($r[$f])) $matched[$sysId][$f] = (float)($matched[$sysId][$f] ?? 0) + (float)$r[$f];
        }
        continue;
    }
    $matched[$sysId] = $r;
}
/* …and a person on several lines must still fit into the period once the lines are added up */
foreach ($matched as $sysId => $m) {
    $problem = periodHoursProblem($m['hours_worked'] ?? 0, $m['overtime_hours'] ?? 0, $m['late_hours'] ?? 0, $periodDays);
    if ($problem !== null) {
        $invalid[] = ['emp_name' => $names[$sysId], 'why' => $problem . ' (all their lines added up)'];
        unset($matched[$sysId]);
    }
}
$rows = array_values($matched);

/* Every line was impossible: stop before the wipe below, so the period keeps its data */
if (!$rows && $invalid) {
    $first = $invalid[0];
    jsonResponse(['error' => 'Nothing was saved: ' . count($invalid) . ' line(s) have hours that cannot be right (e.g. '
        . $first['emp_name'] . ': ' . $first['why'] . '). Fix them in the file and upload again.',
        'invalid' => array_slice($invalid, 0, 50)], 400);
}

/* Employees (within scope) that the file left out - they get no payroll line */
$inFile = array_flip(array_column($rows, 'emp_id'));
foreach ($scope as $id => $fullName) {
    if (!isset($inFile[$id])) {
        $mismatches[] = ['emp_id' => $id, 'name' => $fullName, 'type' => 'emp_only',
                         'issue' => 'Registered employee not found in uploaded file'];
    }
}

/* Nothing matched - stop before the wipe below, so the period keeps its data */
if (!$rows) {
    jsonResponse(['success' => true, 'count' => 0, 'mismatches' => $mismatches]);
}

/* Rates, period share of a month, working days - see computePayLine() in helpers.php */
$ctx = payContext($db, $period_id);

/* Each employee as the pay computation reads them (rate, type, duty hours, switches) */
$empData = [];
foreach ($db->query("SELECT * FROM employees")->fetchAll() as $er) $empData[$er['emp_id']] = payEmployee($er);

try {
    $attRows = [];
    $payRows = [];

    $count = 0;
    foreach ($rows as $r) {
        $emp_id   = trim((string)($r['emp_id']   ?? ''));
        $emp_name = trim((string)($r['emp_name'] ?? ''));
        if (!$emp_id || !$emp_name) continue;

        $hours = (float)($r['hours_worked']   ?? 0);
        $ot    = (float)($r['overtime_hours'] ?? 0);
        $late  = (float)($r['late_hours']     ?? 0);

        /*
         * paid_hours comes with day-by-day files (timesheet, biometric report):
         * the sum of each day capped at the employee's duty hours. A plain
         * totals file has no days, so its hours are paid as they are.
         */
        $perDay    = isset($r['paid_hours']);
        $paidHours = $perDay ? (float)$r['paid_hours'] : $hours;

        $pay = computePayLine($empData[$emp_id], $paidHours, $ot, $late, $perDay, $ctx);
        ['gross' => $gross, 'tax' => $tax, 'ot_late_adj' => $otLate,
         'sss' => $sss, 'philhealth' => $ph, 'pagibig' => $pag, 'net' => $net] = $pay;

        $attRows[] = [$period_id, $emp_id, $emp_name, $hours, $ot, $late, $gross, $tax];
        $payRows[] = [$period_id, $emp_id, $emp_name, $hours, $ot, $late,
                      $gross, $tax, $otLate, $sss, $ph, $pag, 0, 0, $net];
        $count++;
    }

    /* Replace the period's rows - the whole period for the admin, only their
       own employees for a manager. Safe to repeat: see rewritePeriodRows() */
    rewritePeriodRows($db, $period_id, $who, $attRows, $payRows);

    /* Confirmed replace: the period's day records go too, or the next daily
       upload would bring them back and overwrite these totals */
    if ($savedDays) {
        $db->prepare("DELETE FROM biometric_daily WHERE period_id = ?$dayScope")->execute($dayArgs);
    }

    /* Later cut-offs of the month settle their contributions and tax against
       this one, so they are brought up to date too */
    foreach (laterPeriodsInMonth($db, $period_id) as $later) recomputePeriodFromDaily($db, $later, $who);

    /* Return success plus any mismatch data so the front-end can show the modal */
    jsonResponse([
        'success'    => true,
        'count'      => $count,
        'mismatches' => $mismatches,
        'invalid'    => array_slice($invalid, 0, 50),
    ]);

} catch (PDOException $e) {
    jsonResponse(['error' => friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'], 500);
}
