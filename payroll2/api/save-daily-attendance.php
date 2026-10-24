<?php
/*
 * api/save-daily-attendance.php
 * Receives daily biometric rows from the front-end, upserts them into
 * biometric_daily by (employee_id, att_date), then re-aggregates all
 * daily records inside the period's dates and rewrites the attendance_summary + payroll tables.
 *
 * Days accumulate: today's file adds today, tomorrow's file adds tomorrow,
 * and the days already saved stay. A file holding days already saved (the
 * same sheet re-exported with one more line) just replaces those days.
 *
 * Row shape expected:
 *   emp_name, att_date (YYYY-MM-DD), hours_worked, overtime_hours, late_hours,
 *   undertime_hours (null = work it out), day_off (the timesheet says OFF)
 *   hours_worked null = a full duty day (the file had no hours column)
 * auto_ot: true for a device report - its hours are uncapped, and what goes
 * past each employee's own duty day (8 h, 10 h ...) is split into overtime.
 *
 * There is no ID column - the system keeps its own employee IDs, so each
 * name is matched against Employee Management. Unknown names are not saved.
 *
 * Used by the admin (whole company) and by managers (their own employees).
 * Open periods only.
 */

require_once __DIR__ . '/../includes/helpers.php';
set_time_limit(300); /* cloud DB is slow; a big file must not die halfway */

$who = attendanceUploader();

$db   = getDB();
$body = json_decode(file_get_contents('php://input'), true);

$period_id = (int)($body['period_id'] ?? 0);
$rows      = $body['rows']             ?? [];

if (!$period_id || empty($rows)) {
    jsonResponse(['error' => 'Missing period_id or rows'], 400);
}
uploadPeriodGuard($db, $period_id, $who);
saveBioLinks($db, $body['bio_links'] ?? [], $who);   /* device report -> employee choices */

/* Registered employees: emp_id => employees.id, full name, duty-day hours and the schedule they are paid on */
$standard = max(1.0, (float)getSetting('standard_hours', '8'));
$empData  = [];
foreach ($db->query("SELECT id, employee_code AS emp_id, full_name, hours_per_day, pay_schedule FROM employees")->fetchAll() as $er) {
    $empData[$er['emp_id']] = ['pk' => (int)$er['id'], 'full_name' => $er['full_name'], 'pay_schedule' => $er['pay_schedule'],
                               'day_hours' => (float)$er['hours_per_day'] > 0 ? (float)$er['hours_per_day'] : $standard];
}
$autoOt = !empty($body['auto_ot']);

/* Names in the file that match no registered employee, and names that are
   someone else's employees (manager uploads) - neither is saved, both reported */
$unmatched  = [];
$outOfScope = [];
$invalid    = [];   /* day records with impossible hours - not saved, and reported (see dayHoursProblem) */
$scopeSet   = $who['scope'] === null ? null : array_flip($who['scope']);

try {
    /* ── Step 1: Upsert daily rows into biometric_daily ── */
    $resolve = employeeResolver($db);

    $daily = [];   /* rows to upsert, keyed so a repeated line in the file collapses */

    /* Only days inside the pay period count: a whole-month file uploaded into
       the 16–31 cut-off must not put the 1st–15th into it */
    $pr = $db->prepare("SELECT start_date, end_date, period_label, period_type FROM payroll_periods WHERE id = ?");
    $pr->execute([$period_id]);
    $per = $pr->fetch();
    $payType = periodType($per['period_type'] ?? null);   /* the pay schedule this period is for */
    $outsidePeriod = 0;
    $otherSchedule = [];   /* emp_id => name and schedule: saved, but paid in the pay period of their own schedule (not this one) */

    foreach ($rows as $r) {
        $emp_name = trim((string)($r['emp_name'] ?? ''));
        $att_date = trim((string)($r['att_date'] ?? ''));

        if (!$emp_name || !$att_date) continue;

        /* Validate date format */
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date)) continue;
        if ($att_date < $per['start_date'] || $att_date > $per['end_date']) { $outsidePeriod++; continue; }

        /* Name -> the system's own employee ID */
        $emp_id = $resolve('', $emp_name);
        if ($emp_id === null) {
            $unmatched[nameKey($emp_name)] = $emp_name;
            continue;
        }
        if ($scopeSet !== null && !isset($scopeSet[$emp_id])) {
            $outOfScope[$emp_id] = $emp_name;
            continue;
        }

        $dayHours = $empData[$emp_id]['day_hours'];
        $off   = !empty($r['day_off']);
        /* Impossible hours (80 h in a day, a negative figure, text) are not saved - and not quietly turned into
           something else either: a day with the hours missing is paid as a full duty day. They are reported. */
        if (!$off) {
            $problem = dayHoursProblem($r['hours_worked'] ?? null, $r['overtime_hours'] ?? 0, $r['late_hours'] ?? 0, $r['undertime_hours'] ?? null);
            if ($problem !== null) {
                $invalid["$emp_id|$att_date"] = ['emp_name' => $empData[$emp_id]['full_name'], 'att_date' => $att_date, 'why' => $problem];
                continue;
            }
        }
        $hours = ($r['hours_worked'] ?? null) === null ? $dayHours : max(0, (float)$r['hours_worked']);
        $ot    = max(0, (float)($r['overtime_hours'] ?? 0));
        $late  = max(0, (float)($r['late_hours']     ?? 0));
        /* Undertime as the timesheet states it, when the file has that column */
        $under = isset($r['undertime_hours']) && $r['undertime_hours'] !== '' && $r['undertime_hours'] !== null
            ? max(0, (float)$r['undertime_hours']) : null;
        if ($off) {
            /* a day off on the timesheet: no hours, no undertime, never absent */
            $hours = $ot = $late = 0.0;
            $under = null;
        } elseif ($autoOt && $hours > $dayHours) {
            /* device report: past the employee's own duty day is overtime */
            $ot   += $hours - $dayHours;
            $hours = $dayHours;
        }

        /* Stored under the employee (employees.id), not however the file typed the name. A day is one row per employee and
           date, so a day uploaded again - into this period or any other that holds the date - replaces itself and is never
           paid twice. */
        $daily["$emp_id|$att_date"] = [$empData[$emp_id]['pk'], $att_date,
                                       round($hours, 2), round($ot, 2), round($late, 2), $under, $off ? 1 : 0];
        if (!employeeInPeriod($empData[$emp_id], $payType)) {
            $otherSchedule[$emp_id] = ['name' => $empData[$emp_id]['full_name'], 'schedule' => $empData[$emp_id]['pay_schedule']];
        }
    }

    $inserted = count($daily);
    if ($inserted === 0 && $invalid) {
        $first = reset($invalid);
        jsonResponse(['error' => 'Nothing was saved: ' . count($invalid) . ' day record(s) have hours that cannot be right (e.g. '
            . $first['emp_name'] . ', ' . $first['att_date'] . ': ' . $first['why'] . '). Fix them in the file and upload again.',
            'invalid' => array_slice(array_values($invalid), 0, 50)], 400);
    }
    if ($inserted === 0) {
        $why = $unmatched
            ? 'None of the names in the file match an employee'
              . ($who['role'] === 'manager' ? ' assigned to you' : ' in Employee Management')
              . ' (e.g. ' . implode(', ', array_slice(array_values($unmatched + $outOfScope), 0, 3)) . ').'
            : ($outsidePeriod
                ? "None of the file's days fall inside {$per['period_label']} ({$per['start_date']} to {$per['end_date']}). Choose the pay period that matches the file."
                : 'No valid rows found. Check the Name and Date columns.');
        jsonResponse(['error' => $why], 400);
    }

    bulkInsert($db,
        "INSERT INTO biometric_daily (employee_id, att_date, hours_worked, overtime_hours, late_hours, undertime_hours, is_day_off) VALUES",
        "(?,?,?,?,?,?,?)",
        array_values($daily),
        "ON DUPLICATE KEY UPDATE
            hours_worked   = VALUES(hours_worked),
            overtime_hours = VALUES(overtime_hours),
            late_hours     = VALUES(late_hours),
            undertime_hours = VALUES(undertime_hours),
            is_day_off     = VALUES(is_day_off),
            entered_by     = NULL");
    $daysOff = count(array_filter($daily, fn($d) => $d[6] === 1));

    /* (Older uploads could file days under a biometric number instead of an employee, and this is where they were moved onto
       the employee's real ID. Every day now points at an employees row - the foreign key on biometric_daily.employee_id - so
       there is nothing left to move.) */

    /* ── Step 2: Rebuild the period's payroll from ALL its saved days ──
       Days from earlier uploads stay and count; see recomputePeriodFromDaily() */
    $count = recomputeMonthFrom($db, $period_id, $who);   /* + later cut-offs of the month */
    /* The days of employees paid on another schedule count in the open pay periods of THEIR schedule over these dates: bring those
       up to date - for those employees only, so a file of this schedule's own employees touches nothing else */
    $alsoRecomputed = $otherSchedule ? recomputeParallelPeriods($db, $period_id, ['role' => $who['role'], 'scope' => array_map('strval', array_keys($otherSchedule))]) : [];

    $message = "$inserted daily record(s) saved. Payroll recomputed for $count employee(s).";
    if ($otherSchedule) {
        $message .= ' ' . count($otherSchedule) . " employee(s) in the file are paid on another schedule, so they are not in this $payType pay period"
                  . ' - their days were saved and count in the pay period of their own schedule'
                  . ($alsoRecomputed ? ' (' . implode(', ', $alsoRecomputed) . ' brought up to date).' : '.');
    }
    if ($unmatched) {
        $message .= ' ' . count($unmatched) . ' name(s) in the file match no employee - those rows were not saved.';
    }

    if ($outOfScope) {
        $message .= ' ' . count($outOfScope) . ' employee(s) are not assigned to you - those rows were not saved.';
    }
    if ($invalid) {
        $message .= ' ' . count($invalid) . ' day record(s) were NOT saved because their hours cannot be right.';
    }

    jsonResponse([
        'success'      => true,
        'inserted'     => $inserted,
        'count'        => $count,
        'unmatched'    => array_values($unmatched),
        'out_of_scope' => array_values($outOfScope),
        'invalid'      => array_slice(array_values($invalid), 0, 50),
        'invalid_count' => count($invalid),
        'message'      => $message,
        'outside_period' => $outsidePeriod,
        'days_off'       => $daysOff,
        'other_schedule' => array_slice(array_values($otherSchedule), 0, 50),   /* [{name, schedule}] paid on another schedule than this pay period's */
    ]);

} catch (PDOException $e) {
    jsonResponse(['error' => friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'], 500);
}
