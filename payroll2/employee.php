<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'employee';
$db         = getDB();
$msg        = null;

function nextEmpId(PDO $db): string {
    $st = $db->query("SELECT emp_id FROM employees");
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        if (preg_match('/(\d+)$/', $eid, $m)) $max = max($max, (int)$m[1]);
    }
    return 'EMP-' . str_pad($max + 1, 3, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $emp_id          = trim($_POST['emp_id']          ?? '');
        $name            = trim($_POST['full_name']        ?? '');
        $pos             = trim($_POST['position']         ?? '');
        $branch            = trim($_POST['branch']       ?? '');
        $email           = trim($_POST['email']            ?? '');
        $salary          = floatval($_POST['base_salary']  ?? 0);
        /* Whitelist against the enum - anything unexpected falls back to monthly */
        $salary_type     = in_array($_POST['salary_type'] ?? '', ['monthly', 'kinsenas', 'daily'], true)
                           ? $_POST['salary_type'] : 'monthly';
        $hired           = $_POST['date_hired']            ?: null;
        $portal_password = trim($_POST['portal_password']  ?? '');
        /* Weekly day(s) off, ISO weekdays: never counted absent, never deducted */
        $restDays = implode(',', restDayList(implode(',', (array)($_POST['rest_days'] ?? []))));
        /* Hours in this employee's duty day; blank = the Settings standard */
        $dayHoursRaw = trim((string)($_POST['hours_per_day'] ?? ''));
        $dayHours = (float)$dayHoursRaw;
        $dayHours = $dayHours >= 1 && $dayHours <= 24 ? round($dayHours * 2) / 2 : null;

        /* A salary is a real, non-negative number: a negative one used to be accepted and produced a negative payslip,
           and an absurd one (₱1e12) a payroll nobody could pay. Duty-day hours, when given, are 1 to 24. */
        $formProblem = pesoProblem('Salary', $_POST['base_salary'] ?? '', $salary_type === 'daily' ? MAX_RATE_PESOS : MAX_SALARY_PESOS);
        if ($formProblem === null && $dayHoursRaw !== '' && $dayHours === null) $formProblem = 'Duty-day hours must be a number from 1 to 24 (or blank for the standard day)';

        /* The monthly SSS, PhilHealth, Pag-IBIG and withholding-tax amounts the admin types in. All optional: a blank box
           means this employee has none, and 0 is what is saved. Payroll deducts exactly these. */
        $amounts = [];
        foreach (CONTRIBUTION_LABELS as $k => $label) {
            $raw = trim((string)($_POST["{$k}_amount"] ?? ''));
            $raw = $raw === '' ? '0' : $raw;
            $formProblem ??= pesoProblem("$label (per month)", $raw, $k === 'tax' ? MAX_SALARY_PESOS : MAX_RATE_PESOS);
            $amounts[$k] = (float)$raw;
        }

        if ($formProblem !== null) {
            $msg = ['type' => 'error', 'text' => "Not saved - $formProblem."];
        } elseif ($action === 'add') {
            $emp_id = nextEmpId($db);
            try {
                $db->prepare("INSERT INTO employees (emp_id,full_name,position,branch,email,base_salary,salary_type,date_hired,sss_amount,philhealth_amount,pagibig_amount,tax_amount,rest_days,hours_per_day) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$emp_id, $name, $pos, $branch, $email, $salary, $salary_type, $hired,
                              $amounts['sss'], $amounts['philhealth'], $amounts['pagibig'], $amounts['tax'], $restDays, $dayHours]);
                $msg = ['type' => 'success', 'text' => "Employee $name added (ID: $emp_id)."];
            } catch (PDOException $e) {
                $msg = ['type' => 'error', 'text' => 'The employee was not added. ' . friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'];
            }
        } else {
            $id = (int)$_POST['id'];
            try {
                $prev = $db->prepare("SELECT * FROM employees WHERE id = ?");
                $prev->execute([$id]);
                $prev = $prev->fetch() ?: [];
                $db->prepare("UPDATE employees SET emp_id=?,full_name=?,position=?,branch=?,email=?,base_salary=?,salary_type=?,date_hired=?,sss_amount=?,philhealth_amount=?,pagibig_amount=?,tax_amount=?,rest_days=?,hours_per_day=? WHERE id=?")
                   ->execute([$emp_id, $name, $pos, $branch, $email, $salary, $salary_type, $hired,
                              $amounts['sss'], $amounts['philhealth'], $amounts['pagibig'], $amounts['tax'], $restDays, $dayHours, $id]);
                $msg = ['type' => 'success', 'text' => "Employee $name updated."];
                /* Anything the pay computation reads - rate, salary type, day off,
                   duty hours, the monthly contribution and tax amounts - changes
                   this employee's open payroll: bring it in line now. (The Date
                   Hired is only a record: no pay depends on it.) */
                $changed = (float)($prev['base_salary'] ?? 0) !== (float)$salary
                        || ($prev['salary_type'] ?? '') !== $salary_type
                        || ($prev['rest_days'] ?? '7') !== $restDays
                        || (float)($prev['hours_per_day'] ?? 0) !== (float)($dayHours ?? 0);
                foreach ($amounts as $k => $v) {
                    if ((float)($prev["{$k}_amount"] ?? 0) !== $v) $changed = true;
                }
                if ($changed) {
                    $redone = recomputeEmployeeOpenPeriods($db, $emp_id, '1000-01-01', '9999-12-31');
                    if ($redone) $msg['text'] .= ' Payroll recomputed for ' . implode(', ', $redone) . '.';
                }
            } catch (PDOException $e) {
                $msg = ['type' => 'error', 'text' => 'The changes were not saved. ' . friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'];
            }
        }

        // Save/update employee profile fields
        if (($msg['type'] ?? '') === 'success') {
            $address   = trim($_POST['address']             ?? '');
            $phone     = trim($_POST['phone']               ?? '');
            $ename     = trim($_POST['emergency_name']      ?? '');
            $ephone    = trim($_POST['emergency_phone']     ?? '');
            $erelation = trim($_POST['emergency_relation']  ?? '');
            $db->prepare("INSERT INTO employee_profiles (emp_id,address,phone,emergency_name,emergency_phone,emergency_relation)
                          VALUES (?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE
                            address=VALUES(address), phone=VALUES(phone),
                            emergency_name=VALUES(emergency_name),
                            emergency_phone=VALUES(emergency_phone),
                            emergency_relation=VALUES(emergency_relation)")
               ->execute([$emp_id, $address, $phone, $ename, $ephone, $erelation]);
        }

        // Set portal credentials (hashed) if password was provided
        if (($msg['type'] ?? '') === 'success' && $portal_password !== '') {
            if (!$email) {
                $msg['text'] .= ' (Portal access skipped - email is required for login.)';
            } else {
                /* Every login email must be unique across admin, managers and
                   employees. If another account already uses it, say who -
                   the employee record itself is already saved. */
                $taken = $db->prepare("SELECT full_name, role FROM users
                                        WHERE email = ? AND NOT (role = 'earner' AND emp_id <=> ?) LIMIT 1");
                $taken->execute([$email, $emp_id]);
                $owner = $taken->fetch();
                if ($owner) {
                    $msg['type'] = 'warn';
                    $msg['text'] .= ' Portal access was NOT set: the email ' . $email . ' is already used to log in by '
                                  . $owner['full_name'] . ' (' . ($owner['role'] === 'earner' ? 'employee' : $owner['role'])
                                  . '). Give this employee a different email, then set the portal password again.';
                } else {
                    $hash  = password_hash($portal_password, PASSWORD_BCRYPT);
                    $check = $db->prepare("SELECT id FROM users WHERE emp_id=? AND role='earner'");
                    $check->execute([$emp_id]);
                    if ($check->fetch()) {
                        $db->prepare("UPDATE users SET email=?, password_hash=?, status='Active', full_name=?
                                      WHERE emp_id=? AND role='earner'")
                           ->execute([$email, $hash, $name, $emp_id]);
                    } else {
                        $db->prepare("INSERT INTO users (full_name, email, password_hash, role, emp_id, status)
                                      VALUES (?, ?, ?, 'earner', ?, 'Active')")
                           ->execute([$name, $email, $hash, $emp_id]);
                    }
                    $msg['text'] .= ' Portal access set - employee can log in with their email and this password.';
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            /* Payroll, attendance, leave and payslip records are tied to the
               employee by emp_id only (no database key enforces it), so look
               before deleting: history must never be orphaned. */
            $eid = $db->prepare("SELECT emp_id FROM employees WHERE id = ?");
            $eid->execute([$id]);
            $eid = (string)$eid->fetchColumn();
            $used = [];
            foreach (['payroll' => 'payroll', 'attendance' => 'attendance', 'biometric_daily' => 'daily attendance',
                      'leave_requests' => 'leave', 'bonus_deduction_history' => 'bonus/deduction',
                      'payslip_signatures' => 'payslip signature'] as $table => $what) {
                $st = $db->prepare("SELECT 1 FROM $table WHERE emp_id = ? LIMIT 1");
                $st->execute([$eid]);
                if ($st->fetchColumn()) $used[] = $what;
            }
            if ($eid !== '' && $used) {
                $msg = ['type' => 'error', 'text' => 'This employee has ' . implode(', ', $used) . ' records, '
                      . 'so they cannot be deleted - those records must be kept. Revoke their portal access instead.'];
            } else {
                $db->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
                $msg = ['type' => 'success', 'text' => 'Employee deleted.'];
            }
        } catch (PDOException $e) {
            /* 1451: payroll, attendance, leave or payslip records point at this
               employee. The database refuses so that history is never orphaned. */
            if (($e->errorInfo[1] ?? 0) == 1451) {
                $msg = ['type' => 'error', 'text' => 'This employee has payroll, attendance, leave or payslip records, '
                      . 'so they cannot be deleted - those records must be kept. Revoke their portal access instead.'];
            } else {
                $msg = ['type' => 'error', 'text' => 'The employee was not deleted. ' . friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'];
            }
        }
    }

    if ($action === 'revoke_access') {
        $emp_id = trim($_POST['emp_id_action'] ?? '');
        if ($emp_id) {
            $db->prepare("UPDATE users SET status='Inactive' WHERE emp_id=? AND role='earner'")->execute([$emp_id]);
            $msg = ['type' => 'success', 'text' => "Portal access revoked for employee $emp_id. All their data is kept in the database."];
        }
    }

    if ($action === 'restore_access') {
        $emp_id = trim($_POST['emp_id_action'] ?? '');
        if ($emp_id) {
            $db->prepare("UPDATE users SET status='Active' WHERE emp_id=? AND role='earner'")->execute([$emp_id]);
            $msg = ['type' => 'success', 'text' => "Portal access restored for employee $emp_id."];
        }
    }

}

$next_emp_id = nextEmpId($db);

/* Where Settings' Contribution Schedule currently sends each monthly amount (the note under the amount boxes) */
$scheduleNow = [];
foreach (contributionTiming() as $k => $t) $scheduleNow[] = CONTRIBUTION_LABELS[$k] . ': ' . CONTRIBUTION_TIMING_LABELS[$t];

/* The branch list maintained in Settings, plus any name already sitting on an
   employee row that predates it - an existing assignment must stay selectable
   or editing that employee would silently clear their branch. */
$branchOptions = $db->query("
    SELECT name FROM (
        SELECT name FROM branches
        UNION
        SELECT DISTINCT TRIM(branch) AS name FROM employees
         WHERE branch IS NOT NULL AND TRIM(branch) <> ''
    ) b ORDER BY name
")->fetchAll(PDO::FETCH_COLUMN);

$search    = trim($_GET['q'] ?? '');
$where     = $search ? "WHERE emp_id LIKE ? OR full_name LIKE ? OR branch LIKE ?" : '';
$params    = $search ? ["%$search%", "%$search%", "%$search%"] : [];
$st        = $db->prepare("SELECT e.*, u.status AS portal_status,
                    ep.address, ep.phone, ep.emergency_name, ep.emergency_phone, ep.emergency_relation
                    FROM employees e
                    LEFT JOIN users u ON u.emp_id=e.emp_id AND u.role='earner'
                    LEFT JOIN employee_profiles ep ON ep.emp_id=e.emp_id
                    $where ORDER BY e.emp_id ASC");
$st->execute($params);
$employees = $st->fetchAll();
$total     = count($employees);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees - Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/employee.css">
    <style>
        /* Fit the table to remaining viewport so the horizontal scrollbar
           always sits at the visible bottom - no page scroll needed to reach it. */
        #empTableWrap {
            overflow: auto;          /* both axes */
        }
        /* Keep header row visible while scrolling down the list */
        #empTableWrap .data-table th {
            position: sticky;
            top: 0;
            z-index: 2;
        }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Employee Management</h1>
            <p><?= $total ?> employee<?= $total !== 1 ? 's' : '' ?> in the system</p>
        </div>
        <button class="btn btn-primary" onclick="openModal()">+ Add Employee</button>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <form method="GET" class="toolbar">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="search-input" placeholder="Search by name, ID, or branch…">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search): ?>
            <a href="employee.php" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <div class="box">
        <div class="table-wrap" id="empTableWrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Emp ID</th><th>Name</th><th>Position</th><th>Branch</th>
                        <th>Email</th><th>Base Salary</th><th>Type</th>
                        <th title="The employee's own monthly amounts, deducted from pay as typed">SSS / PhilHealth / Pag-IBIG / Tax (monthly)</th>
                        <th>Hired</th><th>Status</th><th>Portal Access</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="12" style="text-align:center;color:#9ca3af;padding:30px;">No employees found. Add one above.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $e): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($e['emp_id']) ?></strong></td>
                        <td><?= htmlspecialchars($e['full_name']) ?></td>
                        <td><?= htmlspecialchars($e['position'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($e['branch'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($e['email'] ?? '-') ?></td>
                        <td>₱<?= number_format($e['base_salary'], 2) ?></td>
                        <td>
                            <?php $st = $e['salary_type'] ?? 'monthly'; ?>
                            <span class="badge badge-<?= $st === 'daily' ? 'yellow' : ($st === 'kinsenas' ? 'green' : 'blue') ?>">
                                <?= salaryTypeLabel($st) ?>
                            </span>
                            <br><small style="color:#6b7280;white-space:nowrap;" title="Day off">Off: <?= htmlspecialchars(restDayLabel($e['rest_days'] ?? null)) ?></small>
                            <?php if (!empty($e['hours_per_day'])): ?>
                                <br><small style="color:#6b7280;white-space:nowrap;" title="Hours per duty day"><?= rtrim(rtrim(number_format($e['hours_per_day'], 1), '0'), '.') ?>-hour day</small>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-size:.82rem;">
                            <?php $held = false;
                            foreach (CONTRIBUTION_LABELS as $k => $label):
                                $amt = (float)($e["{$k}_amount"] ?? 0);
                                if ($amt <= 0) continue;
                                $held = true; ?>
                                <div><span style="color:#6b7280;"><?= htmlspecialchars($k === 'tax' ? 'Tax' : $label) ?></span> ₱<?= number_format($amt, 2) ?></div>
                            <?php endforeach; ?>
                            <?php if (!$held): ?><span style="color:#9ca3af;">None</span><?php endif; ?>
                        </td>
                        <td><?= $e['date_hired'] ? date('M d, Y', strtotime($e['date_hired'])) : '-' ?></td>
                        <td>
                            <span class="badge badge-<?= $e['status'] === 'Active' ? 'green' : 'red' ?>">
                                <?= $e['status'] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($e['portal_status'] === 'Active'): ?>
                                <span class="badge badge-green">Active</span>
                                <div style="margin-top:5px;">
                                    <form method="POST">
                                        <input type="hidden" name="action"        value="revoke_access">
                                        <input type="hidden" name="emp_id_action" value="<?= htmlspecialchars($e['emp_id']) ?>">
                                        <button class="btn btn-red btn-sm" title="Keeps all data, only removes login.">Revoke</button>
                                    </form>
                                </div>
                            <?php elseif ($e['portal_status'] === 'Inactive'): ?>
                                <span class="badge badge-red">Revoked</span>
                                <div style="margin-top:5px;">
                                    <form method="POST">
                                        <input type="hidden" name="action"        value="restore_access">
                                        <input type="hidden" name="emp_id_action" value="<?= htmlspecialchars($e['emp_id']) ?>">
                                        <button class="btn btn-green btn-sm">Restore</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="badge badge-yellow">No Account</span>
                                <div style="margin-top:5px;font-size:.76rem;color:#9ca3af;">Edit employee to set password</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-ghost btn-sm" onclick='editEmp(<?= json_encode($e) ?>)'>Edit</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete <?= htmlspecialchars($e['full_name']) ?>?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id"     value="<?= $e['id'] ?>">
                                <button type="submit" class="btn btn-red btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit Modal -->
<div id="empModal" class="modal-overlay" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">Close</button>
        <h2 id="modalTitle" class="modal-title">Add Employee</h2>
        <form method="POST">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id"     id="formId"     value="">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group">
                    <label>Employee ID <span style="font-weight:400;color:#9ca3af;">(auto-assigned)</span></label>
                    <input type="text" name="emp_id" id="f_emp_id" class="form-control" readonly
                           style="background:#f3f4f6;color:#6b7280;cursor:default;">
                </div>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" id="f_name" class="form-control" required placeholder="Juan dela Cruz">
                </div>
                <div class="form-group">
                    <label>Position</label>
                    <input type="text" name="position" id="f_pos" class="form-control" placeholder="Cashier">
                </div>
                <div class="form-group">
                    <label>Branch</label>
                    <select name="branch" id="f_branch" class="form-control">
                        <option value="">- None -</option>
                        <?php foreach ($branchOptions as $b): ?>
                        <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="font-size:.78rem;color:#9ca3af;margin-top:4px;display:block;">
                        Add or rename branches in <a href="settings.php" style="color:#6b7280;">Settings &rsaquo; Branches</a>
                    </span>
                </div>
                <div class="form-group">
                    <label>Email <span style="font-weight:400;color:#9ca3af;">(used as portal login)</span></label>
                    <input type="email" name="email" id="f_email" class="form-control" placeholder="juan@company.com">
                </div>
                <div class="form-group">
                    <label>Salary Type</label>
                    <select name="salary_type" id="f_salary_type" class="form-control">
                        <option value="monthly">Monthly</option>
                        <option value="kinsenas">Kinsenas (twice a month)</option>
                        <option value="daily">Daily Wager</option>
                    </select>
                </div>
                <div class="form-group">
                    <label id="salaryLabel">Base Salary <span style="font-weight:400;color:#9ca3af;">(₱/month)</span></label>
                    <input type="number" name="base_salary" id="f_salary" class="form-control" value="0" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label>Date Hired <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                    <input type="date" name="date_hired" id="f_hired" class="form-control">
                </div>
                <div class="form-group">
                    <label>Hours per Duty Day <span style="font-weight:400;color:#9ca3af;">(blank = <?= htmlspecialchars(getSetting('standard_hours', '8')) ?>)</span></label>
                    <input type="number" name="hours_per_day" id="f_day_hours" class="form-control" step="0.5" min="1" max="24"
                           placeholder="<?= htmlspecialchars(getSetting('standard_hours', '8')) ?>">
                    <span style="font-size:.76rem;color:#9ca3af;margin-top:4px;display:block;">For a longer shift, e.g. 10 - a daily rate then covers 10 hours and undertime costs rate ÷ 10 an hour.</span>
                </div>
            </div>
            <p style="font-size:.78rem;color:#9ca3af;margin:12px 0 0;">
                <strong>Pay is worked out from the days in the uploaded timesheet</strong>, not from the Date Hired: an employee added today can have
                this month's, or any earlier month's, file uploaded and is paid for the days that file holds. On a monthly or kinsenas salary, a working
                day that is not in the file (and is not a day off or approved leave) is not paid. The Date Hired is only a record.
            </p>

            <!-- Day-off schedule -->
            <div style="border-top:1px solid #e5e7eb;margin:18px 0 0;padding-top:16px;">
                <p style="font-size:.85rem;font-weight:700;color:#374151;margin-bottom:4px;">Day Off</p>
                <p style="font-size:.78rem;color:#9ca3af;margin-bottom:10px;">
                    The employee's regular day(s) off each week. No duty is expected on these days, so they are never
                    counted as absent and never deducted. Working on a day off still counts as a day worked.
                </p>
                <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:.88rem;" id="f_rest_days">
                    <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $n => $dn): ?>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="rest_days[]" value="<?= $n ?>" <?= $n === 7 ? 'checked' : '' ?>> <?= $dn ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Government contributions and withholding tax: the employee's own monthly amounts -->
            <div style="border-top:1px solid #e5e7eb;margin:18px 0 0;padding-top:16px;">
                <p style="font-size:.85rem;font-weight:700;color:#374151;margin-bottom:4px;">Contributions &amp; Withholding Tax
                    <span style="font-weight:400;color:#9ca3af;">(₱ per month - all optional)</span></p>
                <p style="font-size:.78rem;color:#9ca3af;margin-bottom:10px;">
                    Type what is to be deducted from this employee's pay each month. Leave a box blank - or 0 - when the
                    employee has none: some do not. The amounts are deducted exactly as typed; nothing is worked out from the pay.
                    The company's share is figured on top of the contributions.
                </p>
                <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group">
                        <label>SSS</label>
                        <input type="number" name="sss_amount" id="f_sss" class="form-control" step="0.01" min="0" max="<?= (int)MAX_RATE_PESOS ?>" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>PhilHealth</label>
                        <input type="number" name="philhealth_amount" id="f_ph" class="form-control" step="0.01" min="0" max="<?= (int)MAX_RATE_PESOS ?>" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Pag-IBIG</label>
                        <input type="number" name="pagibig_amount" id="f_pag" class="form-control" step="0.01" min="0" max="<?= (int)MAX_RATE_PESOS ?>" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Withholding Tax</label>
                        <input type="number" name="tax_amount" id="f_tax" class="form-control" step="0.01" min="0" max="<?= (int)MAX_SALARY_PESOS ?>" placeholder="0.00">
                    </div>
                </div>
                <p style="font-size:.76rem;color:#9ca3af;margin-top:4px;">
                    A monthly payroll takes them in full. On a semi-monthly or weekly payroll it follows
                    <a href="settings.php#contribSchedule" style="color:#6b7280;">Settings &rsaquo; Contribution Schedule</a>
                    - now: <?= htmlspecialchars(implode(' · ', $scheduleNow)) ?>.
                </p>
            </div>

            <!-- Portal credentials section -->
            <div style="border-top:1px solid #e5e7eb;margin:18px 0 14px;padding-top:16px;">
                <p style="font-size:.85rem;font-weight:700;color:#374151;margin-bottom:4px;">Employee Portal Access</p>
                <p style="font-size:.78rem;color:#9ca3af;margin-bottom:12px;">
                    Employee logs in at <strong>http://localhost:8765/employee/</strong> using their <strong>Email</strong> + this password.
                </p>
                <div class="form-group">
                    <label>Portal Password <span id="empPassHint" style="font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0;"></span></label>
                    <input type="password" name="portal_password" id="f_portal_pass" class="form-control"
                           placeholder="Set a password to grant portal access" autocomplete="new-password">
                </div>
            </div>

            <!-- Profile / contact info -->
            <div style="border-top:1px solid #e5e7eb;margin:18px 0 14px;padding-top:16px;">
                <p style="font-size:.85rem;font-weight:700;color:#374151;margin-bottom:12px;">Profile &amp; Emergency Contact</p>
                <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Home Address</label>
                        <input type="text" name="address" id="f_address" class="form-control" placeholder="123 Rizal St, Barangay…">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" id="f_phone" class="form-control" placeholder="09XX XXX XXXX">
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <input type="text" name="emergency_name" id="f_ename" class="form-control" placeholder="Maria Cruz">
                    </div>
                    <div class="form-group">
                        <label>Emergency Phone</label>
                        <input type="text" name="emergency_phone" id="f_ephone" class="form-control" placeholder="09XX XXX XXXX">
                    </div>
                    <div class="form-group">
                        <label>Relationship</label>
                        <input type="text" name="emergency_relation" id="f_erelation" class="form-control" placeholder="Spouse, Parent, Sibling…">
                    </div>
                </div>
            </div>

            <div class="form-actions" style="margin-top:4px;">
                <button type="submit" class="btn btn-primary">Save Employee</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>var _nextEmpId = <?= json_encode($next_emp_id) ?>;</script>
<script src="assets/js/employee.js"></script>
<script>
(function () {
    const wrap = document.getElementById('empTableWrap');
    function fit() {
        const top = wrap.getBoundingClientRect().top;
        // Fill from the top of the table to 16px above the window bottom
        wrap.style.maxHeight = (window.innerHeight - top - 16) + 'px';
    }
    fit();
    window.addEventListener('resize', fit);
})();
</script>
</body>
</html>
