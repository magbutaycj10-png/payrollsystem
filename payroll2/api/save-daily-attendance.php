<?php
/*
 * api/save-daily-attendance.php
 * Receives daily biometric rows from the front-end, upserts them into
 * biometric_daily by (period_id, emp_id, att_date), then re-aggregates all
 * daily records for the period and rewrites the attendance + payroll tables.
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

/* Registered employees: emp_id => full name and duty-day hours */
$standard = max(1.0, (float)getSetting('standard_hours', '8'));
$empData  = [];
foreach ($db->query("SELECT emp_id, full_name, hours_per_day FROM employees")->fetchAll() as $er) {
    $empData[$er['emp_id']] = ['full_name' => $er['full_name'],
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
    $pr = $db->prepare("SELECT period_start, period_end, period_label FROM payroll_periods WHERE id = ?");
    $pr->execute([$period_id]);
    $per = $pr->fetch();
    $outsidePeriod = 0;

    foreach ($rows as $r) {
        $emp_name = trim((string)($r['emp_name'] ?? ''));
        $att_date = trim((string)($r['att_date'] ?? ''));

        if (!$emp_name || !$att_date) continue;

        /* Validate date format */
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date)) continue;
        if ($att_date < $per['period_start'] || $att_date > $per['period_end']) { $outsidePeriod++; continue; }

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

        /* Stored under the registered name, not however the file typed it */
        $daily["$emp_id|$att_date"] = [$period_id, $emp_id, $empData[$emp_id]['full_name'], $att_date,
                                       round($hours, 2), round($ot, 2), round($late, 2), $under, $off ? 1 : 0];
    }

    /*
     * A day belongs to one pay period only. If an employee's day is already
     * saved in another period that covers that date (overlapping periods, or
     * the file uploaded into the wrong one earlier), it stays there and is
     * not saved here a second time - otherwise it would be paid twice.
     * Days re-uploaded into THIS period simply replace themselves (uq_daily).
     */
    $elsewhere = [];   /* period label => days not saved because of it */
    foreach (dailyDaysElsewhere($db, $period_id, array_keys($daily)) as $key => $label) {
        unset($daily[$key]);
        $elsewhere[$label] = ($elsewhere[$label] ?? 0) + 1;
    }

    $inserted = count($daily);
    if ($inserted === 0 && $elsewhere) {
        jsonResponse(['error' => "These days are already saved in another pay period ("
            . implode(', ', array_keys($elsewhere)) . "), so nothing was saved again. "
            . "Upload the file into that period instead."], 409);
    }
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
                ? "None of the file's days fall inside {$per['period_label']} ({$per['period_start']} to {$per['period_end']}). Choose the pay period that matches the file."
                : 'No valid rows found. Check the Name and Date columns.');
        jsonResponse(['error' => $why], 400);
    }

    bulkInsert($db,
        "INSERT INTO biometric_daily (period_id, emp_id, emp_name, att_date, hours_worked, overtime_hours, late_hours, undertime_hours, day_off) VALUES",
        "(?,?,?,?,?,?,?,?,?)",
        array_values($daily),
        "ON DUPLICATE KEY UPDATE
            emp_name       = VALUES(emp_name),
            hours_worked   = VALUES(hours_worked),
            overtime_hours = VALUES(overtime_hours),
            late_hours     = VALUES(late_hours),
            undertime_hours = VALUES(undertime_hours),
            day_off        = VALUES(day_off),
            entered_by     = NULL");
    $daysOff = count(array_filter($daily, fn($d) => $d[8] === 1));

    /*
     * Days an older upload filed under a biometric number (before that person
     * was registered) move onto the employee's real ID, so they count again.
     * UPDATE IGNORE skips a day the employee already has under their real ID;
     * the DELETE then clears those leftover duplicates. A day the employee
     * already has in ANOTHER period is not moved either - the same
     * one-period-per-day rule as the upload itself.
     */
    $old = $db->prepare("SELECT DISTINCT emp_id, emp_name FROM biometric_daily WHERE period_id = ?");
    $old->execute([$period_id]);
    foreach ($old->fetchAll() as $o) {
        if (isset($empData[$o['emp_id']])) continue;
        $sysId = $resolve('', $o['emp_name']);
        if ($sysId === null || ($scopeSet !== null && !isset($scopeSet[$sysId]))) continue;

        $dates = $db->prepare("SELECT att_date FROM biometric_daily WHERE period_id = ? AND emp_id = ?");
        $dates->execute([$period_id, $o['emp_id']]);
        $keys  = array_map(fn($d) => "$sysId|$d", $dates->fetchAll(PDO::FETCH_COLUMN));
        foreach (dailyDaysElsewhere($db, $period_id, $keys) as $key => $label) {
            $db->prepare("DELETE FROM biometric_daily WHERE period_id = ? AND emp_id = ? AND att_date = ?")
               ->execute([$period_id, $o['emp_id'], explode('|', $key, 2)[1]]);
            $elsewhere[$label] = ($elsewhere[$label] ?? 0) + 1;
        }

        $db->prepare("UPDATE IGNORE biometric_daily SET emp_id = ?, emp_name = ? WHERE period_id = ? AND emp_id = ?")
           ->execute([$sysId, $empData[$sysId]['full_name'], $period_id, $o['emp_id']]);
        $db->prepare("DELETE FROM biometric_daily WHERE period_id = ? AND emp_id = ?")
           ->execute([$period_id, $o['emp_id']]);
    }

    /* ── Step 2: Rebuild the period's payroll from ALL its saved days ──
       Days from earlier uploads stay and count; see recomputePeriodFromDaily() */
    $count = recomputeMonthFrom($db, $period_id, $who);   /* + later cut-offs of the month */

    $message = "$inserted daily record(s) saved. Payroll recomputed for $count employee(s).";
    if ($unmatched) {
        $message .= ' ' . count($unmatched) . ' name(s) in the file match no employee - those rows were not saved.';
    }
    if ($elsewhere) {
        $message .= ' ' . array_sum($elsewhere) . ' day record(s) were already saved in '
                  . implode(', ', array_keys($elsewhere)) . ' and were not counted again.';
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
        'elsewhere'      => $elsewhere,
        'days_off'       => $daysOff,
    ]);

} catch (PDOException $e) {
    jsonResponse(['error' => friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'], 500);
}
