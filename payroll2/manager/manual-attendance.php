<?php
/*
 * manager/manual-attendance.php
 * Records one employee's attendance for one DAY, for when the biometric
 * device missed it. The day is saved with the uploaded days (biometric_daily)
 * and the pay period is recomputed from all of them - so it adds to what the
 * daily uploads recorded instead of replacing it, and the next upload keeps
 * it (unless that upload holds the same day, which then replaces it).
 */
require_once __DIR__ . '/includes/auth.php';
requireManager();

$activePage = 'manual-attendance';
$db  = getDB();
$m   = mgr();
$msg = null;

[$scopeWhere, $scopeParams] = mgrScopeWhere();

$employees = $db->prepare("SELECT e.emp_id, e.full_name, e.branch FROM employees e WHERE e.status='Active' $scopeWhere ORDER BY e.full_name");
$employees->execute($scopeParams);
$employees = $employees->fetchAll();

$openPeriods = $db->query("SELECT * FROM payroll_periods WHERE status='Open' ORDER BY period_start DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp_id = trim($_POST['emp_id'] ?? '');
    $date   = trim($_POST['att_date'] ?? '');
    /* "worked" - hours below; "off" - a day off (no duty: not absent, not deducted) */
    $dayOff = ($_POST['day_status'] ?? 'worked') === 'off';
    /* Typed hours are checked, not quietly clamped: 30 h used to become 24 h of pay, and 12 OT h on top of it was accepted */
    $hoursProblem = $dayOff ? null : dayHoursProblem(($_POST['hours_worked'] ?? '') === '' ? 0 : $_POST['hours_worked'],
                                                    ($_POST['overtime_hours'] ?? '') === '' ? 0 : $_POST['overtime_hours'],
                                                    ($_POST['late_hours'] ?? '') === '' ? 0 : $_POST['late_hours']);
    $hours  = $dayOff || $hoursProblem !== null ? 0.0 : (float)($_POST['hours_worked']   ?? 0);
    $ot     = $dayOff || $hoursProblem !== null ? 0.0 : (float)($_POST['overtime_hours'] ?? 0);
    $late   = $dayOff || $hoursProblem !== null ? 0.0 : (float)($_POST['late_hours']     ?? 0);

    $emp = null;
    foreach ($employees as $e) { if ($e['emp_id'] === $emp_id) $emp = $e; }

    /* The Open period that holds this day */
    $period = null;
    foreach ($openPeriods as $p) {
        if ($date >= $p['period_start'] && $date <= $p['period_end']) { $period = $p; break; }
    }

    if (!$emp) {
        $msg = ['type' => 'error', 'text' => 'Employee not found among your assigned employees.'];
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $msg = ['type' => 'error', 'text' => 'Choose the date of the attendance.'];
    } elseif ($hoursProblem !== null) {
        $msg = ['type' => 'error', 'text' => "Not saved - {$hoursProblem}. Check the hours and try again."];
    } elseif (!$period) {
        $msg = ['type' => 'error', 'text' => 'No open pay period covers ' . date('M d, Y', strtotime($date))
                                           . '. Ask the admin to create or unlock it.'];
    } elseif ($other = dailyDaysElsewhere($db, (int)$period['id'], ["$emp_id|$date"])) {
        $msg = ['type' => 'error', 'text' => 'That day is already recorded in ' . reset($other) . '.'];
    } else {
        try {
            $db->prepare("
                INSERT INTO biometric_daily
                    (period_id, emp_id, emp_name, att_date, hours_worked, overtime_hours, late_hours, undertime_hours, day_off, entered_by)
                VALUES (?,?,?,?,?,?,?,NULL,?,?)
                ON DUPLICATE KEY UPDATE
                    emp_name = VALUES(emp_name), hours_worked = VALUES(hours_worked),
                    overtime_hours = VALUES(overtime_hours), late_hours = VALUES(late_hours),
                    undertime_hours = NULL, day_off = VALUES(day_off), entered_by = VALUES(entered_by)
            ")->execute([$period['id'], $emp_id, $emp['full_name'], $date, $hours, $ot, $late, $dayOff ? 1 : 0, $m['name']]);

            /* Only this employee's line is rebuilt, from all their saved days */
            recomputeMonthFrom($db, (int)$period['id'], ['role' => 'manager', 'scope' => [$emp_id]]);

            $msg = ['type' => 'success', 'text' => "Saved {$emp['full_name']}'s " . ($dayOff ? 'day off' : 'attendance') . " for " . date('M d, Y', strtotime($date))
                . " in {$period['period_label']}. Their other days are kept and the period's pay was recomputed."];
        } catch (PDOException $e) {
            $msg = ['type' => 'error', 'text' => 'The record was not saved. ' . friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'];
        }
    }
}

/* Recent hand-entered days for this manager's employees */
[$bScope, $bParams] = mgrScopeWhere('b');
$recentManual = $db->prepare("
    SELECT b.*, pp.period_label FROM biometric_daily b
    JOIN payroll_periods pp ON pp.id = b.period_id
    WHERE b.entered_by IS NOT NULL $bScope
    ORDER BY b.uploaded_at DESC LIMIT 15
");
$recentManual->execute($bParams);
$recentManual = $recentManual->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Attendance - Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>Manual Attendance</h1>
            <p>Enter one day's attendance when the biometric device missed it</p>
        </div>
        <a href="/manager/month-attendance.php" class="btn btn-ghost">This Month's Attendance</a>
    </div>

    <?php if ($msg): ?>
        <div class="p-alert p-alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <?php if (empty($openPeriods)): ?>
        <div class="p-alert p-alert-warn">No open payroll periods. Ask the admin to create or unlock a period.</div>
    <?php else: ?>
    <div class="p-box" style="margin-bottom:20px;">
        <div class="p-box-header"><h2>Enter a Day</h2></div>
        <div class="p-box-body">
            <form method="POST">
                <div class="p-form-grid">
                    <div class="p-form-group">
                        <label>Employee *</label>
                        <select name="emp_id" class="p-form-control" required>
                            <option value="">- Select Employee -</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?= htmlspecialchars($e['emp_id']) ?>" <?= ($_POST['emp_id'] ?? '') === $e['emp_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['full_name']) ?> (<?= htmlspecialchars($e['emp_id']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="p-form-group">
                        <label>Date *</label>
                        <input type="date" name="att_date" class="p-form-control" required
                               value="<?= htmlspecialchars($_POST['att_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="p-form-group">
                        <label>Day</label>
                        <select name="day_status" class="p-form-control" onchange="document.querySelectorAll('.when-worked').forEach(el => el.style.display = this.value === 'off' ? 'none' : '')">
                            <option value="worked" <?= ($_POST['day_status'] ?? '') !== 'off' ? 'selected' : '' ?>>Worked</option>
                            <option value="off"    <?= ($_POST['day_status'] ?? '') === 'off' ? 'selected' : '' ?>>Day off - no duty</option>
                        </select>
                    </div>
                    <div class="p-form-group when-worked">
                        <label>Hours Worked</label>
                        <input type="number" name="hours_worked" class="p-form-control" step="0.25" min="0" max="24" value="<?= htmlspecialchars($_POST['hours_worked'] ?? '8') ?>">
                    </div>
                    <div class="p-form-group when-worked">
                        <label>Overtime Hours</label>
                        <input type="number" name="overtime_hours" class="p-form-control" step="0.25" min="0" value="<?= htmlspecialchars($_POST['overtime_hours'] ?? '0') ?>">
                    </div>
                    <div class="p-form-group when-worked">
                        <label>Late Hours</label>
                        <input type="number" name="late_hours" class="p-form-control" step="0.25" min="0" value="<?= htmlspecialchars($_POST['late_hours'] ?? '0') ?>">
                    </div>
                </div>

                <div class="p-alert p-alert-info" style="margin-top:14px;">
                    The day goes into the open pay period that covers its date, next to the uploaded days - nothing already recorded is removed.
                    Entering the same employee and date again corrects that day. Recorded under your name
                    (<strong><?= htmlspecialchars($m['name']) ?></strong>).
                </div>
                <div class="p-form-actions">
                    <button type="submit" class="btn btn-primary">Save Day</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="p-box">
        <div class="p-box-header"><h2>Recent Manual Entries</h2></div>
        <div class="p-table-wrap">
            <table class="p-table">
                <thead>
                    <tr><th>Employee</th><th>Date</th><th>Period</th><th>Hours</th><th>OT</th><th>Late</th><th>Entered By</th><th>Saved</th></tr>
                </thead>
                <tbody>
                <?php if (empty($recentManual)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:24px;">No manual entries yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentManual as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['emp_name'] ?: $r['emp_id']) ?></td>
                        <td><?= date('M d, Y', strtotime($r['att_date'])) ?></td>
                        <td><span class="badge badge-blue"><?= htmlspecialchars($r['period_label']) ?></span></td>
                        <td><?= (int)($r['day_off'] ?? 0) ? '<span class="badge badge-blue">Day off</span>' : number_format($r['hours_worked'], 2) ?></td>
                        <td><?= number_format($r['overtime_hours'], 2) ?></td>
                        <td><?= number_format($r['late_hours'], 2) ?></td>
                        <td style="color:#7c3aed;font-size:.85rem;"><?= htmlspecialchars($r['entered_by']) ?></td>
                        <td style="color:#6b7280;font-size:.8rem;"><?= date('M d, Y g:i A', strtotime($r['uploaded_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
