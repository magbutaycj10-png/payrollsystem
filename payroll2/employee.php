<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'employee';
$db         = getDB();
$msg        = null;

function nextEmpId(PDO $db): string {
    $st = $db->query("SELECT employee_code FROM employees");
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
        $first           = trim($_POST['first_name']       ?? '');
        $last            = trim($_POST['last_name']        ?? '');
        $name            = trim($first . ' ' . $last);
        $pos             = trim($_POST['position']         ?? '');
        $branch          = trim($_POST['branch']           ?? '');
        $email           = trim($_POST['email']            ?? '');
        $salary          = floatval($_POST['base_salary']  ?? 0);
        /* Whitelist against the enum - anything unexpected falls back to monthly */
        $salary_type     = in_array($_POST['salary_type'] ?? '', ['monthly', 'kinsenas', 'daily'], true)
                           ? $_POST['salary_type'] : 'monthly';
        $hired           = $_POST['date_hired']            ?: null;
        $portal_password = trim($_POST['portal_password']  ?? '');
        /* How often they are paid, and which cut-off of the month takes each monthly amount (first | split | second). A choice that is
           missing or not one of the offered ones keeps what the employee had; a new employee starts on Semi-Monthly with the usual schedule. */
        $scheduleFor = function (array $prev): array {
            $pay = in_array($_POST['pay_schedule'] ?? '', PAY_SCHEDULES, true) ? $_POST['pay_schedule']
                 : (in_array($prev['pay_schedule'] ?? '', PAY_SCHEDULES, true) ? $prev['pay_schedule'] : 'Semi-Monthly');
            $was = employeeTiming($prev);
            $timing = [];
            foreach (CONTRIBUTION_TIMING_DEFAULT as $k => $def) {
                $v = $_POST["{$k}_timing"] ?? '';
                $timing[$k] = in_array($v, CONTRIBUTION_TIMING_CHOICES, true) ? $v : $was[$k];
            }
            return [$pay, $timing];
        };
        /* Weekly day(s) off, ISO weekdays: never counted absent, never deducted */
        $restDays = implode(',', restDayList(implode(',', (array)($_POST['rest_days'] ?? []))));
        /* Hours in this employee's duty day; blank = the Settings standard */
        $dayHoursRaw = trim((string)($_POST['hours_per_day'] ?? ''));
        $dayHours = (float)$dayHoursRaw;
        $dayHours = $dayHours >= 1 && $dayHours <= 24 ? round($dayHours * 2) / 2 : null;
        /* Pesos per overtime hour for this employee; blank = the company rate in Settings (0 is allowed: no overtime pay) */
        $otRaw = trim((string)($_POST['overtime_rate'] ?? ''));
        /* Contact details and the emergency contact - blank is stored as empty */
        $profile = [];
        foreach (['address', 'phone', 'emergency_name', 'emergency_phone', 'emergency_relation'] as $col) {
            $v = trim((string)($_POST[$col] ?? ''));
            $profile[$col] = $v === '' ? null : $v;
        }

        /* A salary is a real, non-negative number: a negative one used to be accepted and produced a negative payslip,
           and an absurd one (₱1e12) a payroll nobody could pay. Duty-day hours, when given, are 1 to 24. */
        $formProblem = pesoProblem('Salary', $_POST['base_salary'] ?? '', $salary_type === 'daily' ? MAX_RATE_PESOS : MAX_SALARY_PESOS);
        if ($formProblem === null && $otRaw !== '') $formProblem = pesoProblem('Overtime rate', $otRaw, MAX_RATE_PESOS);
        $otRate = $otRaw === '' ? null : round((float)$otRaw, 2);
        if ($formProblem === null && $first === '') $formProblem = 'The first name is required';
        if ($formProblem === null && $dayHoursRaw !== '' && $dayHours === null) $formProblem = 'Duty-day hours must be a number from 1 to 24 (or blank for the standard day)';
        $branchId = branchId($db, $branch);
        if ($formProblem === null && $branch !== '' && !$branchId) $formProblem = 'That branch is not in the branch list (add it in Settings)';

        /* The monthly SSS, PhilHealth, Pag-IBIG and withholding-tax amounts the admin types in. All optional: a blank box
           means this employee has none, and 0 is what is saved. Payroll deducts exactly these. */
        $amounts = [];
        foreach (CONTRIBUTION_LABELS as $k => $label) {
            $raw = trim((string)($_POST["{$k}_amount"] ?? ''));
            $raw = $raw === '' ? '0' : $raw;
            $formProblem ??= pesoProblem("$label (per month)", $raw, $k === 'tax' ? MAX_SALARY_PESOS : MAX_RATE_PESOS);
            $amounts[$k] = (float)$raw;
        }

        $empPk = 0;     /* employees.id of the employee saved - what the portal account points at */
        if ($formProblem !== null) {
            $msg = ['type' => 'error', 'text' => "Not saved - $formProblem."];
        } elseif ($action === 'add') {
            $emp_id = nextEmpId($db);
            [$paySchedule, $timing] = $scheduleFor([]);
            try {
                $db->prepare("INSERT INTO employees (employee_code,first_name,last_name,position_id,branch_id,email,base_salary,salary_type,pay_schedule,date_hired,
                                                     sss_amount,philhealth_amount,pagibig_amount,tax_amount,
                                                     sss_timing,philhealth_timing,pagibig_timing,tax_timing,rest_days,hours_per_day,overtime_rate,
                                                     address,phone,emergency_name,emergency_phone,emergency_relation)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$emp_id, $first, $last, positionId($db, $pos), $branchId, $email !== '' ? $email : null, $salary, $salary_type, $paySchedule, $hired,
                              $amounts['sss'], $amounts['philhealth'], $amounts['pagibig'], $amounts['tax'],
                              $timing['sss'], $timing['philhealth'], $timing['pagibig'], $timing['tax'], $restDays, $dayHours, $otRate,
                              $profile['address'], $profile['phone'], $profile['emergency_name'], $profile['emergency_phone'], $profile['emergency_relation']]);
                $empPk = (int)$db->lastInsertId();
                $msg = ['type' => 'success', 'text' => "Employee $name added (ID: $emp_id)."];
            } catch (PDOException $e) {
                $msg = ['type' => 'error', 'text' => ($e->errorInfo[1] ?? 0) == 1062
                    ? 'The employee was not added: another employee already has that email.'
                    : 'The employee was not added. ' . friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'];
            }
        } else {
            $id = (int)$_POST['id'];
            try {
                $prev = $db->prepare("SELECT * FROM employees WHERE id = ?");
                $prev->execute([$id]);
                $prev = $prev->fetch() ?: [];
                [$paySchedule, $timing] = $scheduleFor($prev);
                $db->prepare("UPDATE employees SET employee_code=?,first_name=?,last_name=?,position_id=?,branch_id=?,email=?,base_salary=?,salary_type=?,pay_schedule=?,date_hired=?,
                                                   sss_amount=?,philhealth_amount=?,pagibig_amount=?,tax_amount=?,
                                                   sss_timing=?,philhealth_timing=?,pagibig_timing=?,tax_timing=?,rest_days=?,hours_per_day=?,overtime_rate=?,
                                                   address=?,phone=?,emergency_name=?,emergency_phone=?,emergency_relation=? WHERE id=?")
                   ->execute([$emp_id, $first, $last, positionId($db, $pos), $branchId, $email !== '' ? $email : null, $salary, $salary_type, $paySchedule, $hired,
                              $amounts['sss'], $amounts['philhealth'], $amounts['pagibig'], $amounts['tax'],
                              $timing['sss'], $timing['philhealth'], $timing['pagibig'], $timing['tax'], $restDays, $dayHours, $otRate,
                              $profile['address'], $profile['phone'], $profile['emergency_name'], $profile['emergency_phone'], $profile['emergency_relation'], $id]);
                $empPk = $prev ? $id : 0;
                /* the portal account carries the employee's name */
                $db->prepare("UPDATE users SET full_name = ? WHERE employee_id = ?")->execute([$name, $id]);
                $msg = ['type' => 'success', 'text' => "Employee $name updated."];
                /* Anything the pay computation reads - rate, salary type, pay schedule, day off,
                   duty hours, the monthly contribution and tax amounts and the cut-off that
                   takes each - changes this employee's open payroll: bring it in line now.
                   (The Date Hired is only a record: no pay depends on it.) */
                $scheduleWas = employeeTiming($prev);
                $changed = (float)($prev['base_salary'] ?? 0) !== (float)$salary
                        || ($prev['salary_type'] ?? '') !== $salary_type
                        || ($prev['pay_schedule'] ?? 'Semi-Monthly') !== $paySchedule
                        || ($prev['rest_days'] ?? '7') !== $restDays
                        || (float)($prev['hours_per_day'] ?? 0) !== (float)($dayHours ?? 0)
                        || (($prev['overtime_rate'] ?? null) === null) !== ($otRate === null)
                        || (float)($prev['overtime_rate'] ?? 0) !== (float)($otRate ?? 0);
                foreach ($amounts as $k => $v) {
                    if ((float)($prev["{$k}_amount"] ?? 0) !== $v) $changed = true;
                }
                foreach ($timing as $k => $v) {
                    if ($scheduleWas[$k] !== $v) $changed = true;
                }
                if (($prev['pay_schedule'] ?? 'Semi-Monthly') !== $paySchedule) {
                    $msg['text'] .= $paySchedule === 'Monthly'
                        ? " Pay schedule is now Monthly: from now on they are paid once a month, in the month's last cut-off, for the whole month (finalized pay periods are left alone)."
                        : " Pay schedule is now Semi-Monthly: from now on they are paid at every cut-off (finalized pay periods are left alone).";
                }
                if ($changed) {
                    $redone = recomputeEmployeeOpenPeriods($db, $emp_id, '1000-01-01', '9999-12-31');
                    if ($redone) $msg['text'] .= ' Payroll recomputed for ' . implode(', ', $redone) . '.';
                }
            } catch (PDOException $e) {
                $msg = ['type' => 'error', 'text' => ($e->errorInfo[1] ?? 0) == 1062
                    ? 'The changes were not saved: another employee already has that email.'
                    : 'The changes were not saved. ' . friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'];
            }
        }

        // Set portal credentials (hashed) if password was provided
        if (($msg['type'] ?? '') === 'success' && $empPk && $portal_password !== '') {
            if (!$email) {
                $msg['text'] .= ' (Portal access skipped - email is required for login.)';
            } else {
                /* Every login email must be unique across admin, managers and
                   employees. If another account already uses it, say who -
                   the employee record itself is already saved. */
                $taken = $db->prepare("SELECT full_name, role FROM users
                                        WHERE email = ? AND NOT (role = 'Employee' AND employee_id <=> ?) LIMIT 1");
                $taken->execute([$email, $empPk]);
                $owner = $taken->fetch();
                if ($owner) {
                    $msg['type'] = 'warn';
                    $msg['text'] .= ' Portal access was NOT set: the email ' . $email . ' is already used to log in by '
                                  . $owner['full_name'] . ' (' . ($owner['role'] === 'Employee' ? 'employee' : strtolower($owner['role']))
                                  . '). Give this employee a different email, then set the portal password again.';
                } else {
                    $hash  = password_hash($portal_password, PASSWORD_BCRYPT);
                    $check = $db->prepare("SELECT id FROM users WHERE employee_id=? AND role='Employee'");
                    $check->execute([$empPk]);
                    if ($check->fetch()) {
                        $db->prepare("UPDATE users SET email=?, password_hash=?, status='Active', full_name=?
                                      WHERE employee_id=? AND role='Employee'")
                           ->execute([$email, $hash, $name, $empPk]);
                    } else {
                        $db->prepare("INSERT INTO users (full_name, email, password_hash, role, employee_id, status)
                                      VALUES (?, ?, ?, 'Employee', ?, 'Active')")
                           ->execute([$name, $email, $hash, $empPk]);
                    }
                    $msg['text'] .= ' Portal access set - employee can log in with their email and this password.';
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            /* Payroll, attendance, leave and bonus/deduction records point at the employee (employee_id) with
               ON DELETE RESTRICT, so the database itself refuses the delete. Looking first says which. (A payslip
               signature hangs off a pay line, so a signed employee always has payroll.) */
            $used = [];
            foreach (['payroll' => 'payroll', 'attendance_summary' => 'attendance', 'biometric_daily' => 'daily attendance',
                      'leave_requests' => 'leave', 'bonus_deductions' => 'bonus/deduction'] as $table => $what) {
                $st = $db->prepare("SELECT 1 FROM $table WHERE employee_id = ? LIMIT 1");
                $st->execute([$id]);
                if ($st->fetchColumn()) $used[] = $what;
            }
            if ($used) {
                $msg = ['type' => 'error', 'text' => 'This employee has ' . implode(', ', $used) . ' records, '
                      . 'so they cannot be deleted - those records must be kept. Revoke their portal access instead.'];
            } else {
                /* Their portal login, manager assignments and device number go with them (ON DELETE CASCADE) */
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
            $db->prepare("UPDATE users SET status='Inactive'
                           WHERE employee_id = (SELECT id FROM employees WHERE employee_code = ?) AND role='Employee'")->execute([$emp_id]);
            $msg = ['type' => 'success', 'text' => "Portal access revoked for employee $emp_id. All their data is kept in the database."];
        }
    }

    if ($action === 'restore_access') {
        $emp_id = trim($_POST['emp_id_action'] ?? '');
        if ($emp_id) {
            $db->prepare("UPDATE users SET status='Active'
                           WHERE employee_id = (SELECT id FROM employees WHERE employee_code = ?) AND role='Employee'")->execute([$emp_id]);
            $msg = ['type' => 'success', 'text' => "Portal access restored for employee $emp_id."];
        }
    }

}

$next_emp_id = nextEmpId($db);

/* The law's brackets, for the "See brackets" window next to the contribution amounts */
$brackets = contributionBrackets();

/* The branch list and the position titles maintained in Settings (a typed position that is new is added to the list when saved) */
$branchOptions   = $db->query("SELECT name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$positionOptions = $db->query("SELECT title FROM positions ORDER BY title")->fetchAll(PDO::FETCH_COLUMN);

$search    = trim($_GET['q'] ?? '');
$where     = $search ? "WHERE e.employee_code LIKE ? OR e.full_name LIKE ? OR br.name LIKE ?" : '';
$params    = $search ? ["%$search%", "%$search%", "%$search%"] : [];
$st        = $db->prepare("SELECT " . EMPLOYEE_COLUMNS . ", u.status AS portal_status
                    FROM employees e " . EMPLOYEE_JOINS . "
                    LEFT JOIN users u ON u.employee_id=e.id AND u.role='Employee'
                    $where ORDER BY e.employee_code ASC");
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
                        <td><?= htmlspecialchars(($e['position'] ?? '') !== '' ? $e['position'] : '-') ?></td>
                        <td><?= htmlspecialchars(($e['branch'] ?? '') !== '' ? $e['branch'] : '-') ?></td>
                        <td><?= htmlspecialchars($e['email'] ?? '-') ?></td>
                        <td>₱<?= number_format($e['base_salary'], 2) ?></td>
                        <td>
                            <?php $st = $e['salary_type'] ?? 'monthly'; ?>
                            <span class="badge badge-<?= $st === 'daily' ? 'yellow' : ($st === 'kinsenas' ? 'green' : 'blue') ?>">
                                <?= salaryTypeLabel($st) ?>
                            </span>
                            <?php $ps = in_array($e['pay_schedule'] ?? '', PAY_SCHEDULES, true) ? $e['pay_schedule'] : 'Semi-Monthly'; ?>
                            <br><small style="color:#6b7280;white-space:nowrap;" title="<?= $ps === 'Monthly' ? 'Paid once a month, at the last cut-off, for the whole month' : 'Paid every cut-off' ?>">Paid <?= $ps === 'Monthly' ? 'monthly' : 'semi-monthly' ?></small>
                            <?php if (isset($e['overtime_rate']) && $e['overtime_rate'] !== null && $e['overtime_rate'] !== ''): ?>
                                <br><small style="color:#6b7280;white-space:nowrap;" title="Overtime rate of this employee (the company rate is in Settings)">OT ₱<?= rtrim(rtrim(number_format((float)$e['overtime_rate'], 2, '.', ''), '0'), '.') ?: '0' ?>/h</small>
                            <?php endif; ?>
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
                    <label>First Name * <span style="font-weight:400;color:#9ca3af;">(with middle initial)</span></label>
                    <input type="text" name="first_name" id="f_first" class="form-control" required placeholder="Juan P.">
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" id="f_last" class="form-control" placeholder="dela Cruz">
                </div>
                <div class="form-group">
                    <label>Position</label>
                    <input type="text" name="position" id="f_pos" class="form-control" placeholder="Cashier" list="positionList" autocomplete="off">
                    <datalist id="positionList">
                        <?php foreach ($positionOptions as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?php endforeach; ?>
                    </datalist>
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
                    <label>Pay Schedule</label>
                    <select name="pay_schedule" id="f_pay_schedule" class="form-control">
                        <?php foreach (PAY_SCHEDULE_LABELS as $val => $text): ?>
                        <option value="<?= $val ?>"><?= htmlspecialchars($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="font-size:.76rem;color:#9ca3af;margin-top:4px;display:block;">
                        Semi-Monthly (kinsenas): paid at every cut-off, 1–15 and 16–end. Monthly: paid once, in the month's last cut-off (16–end),
                        for the whole month - the days of both halves, with the bonuses and deductions of both halves. Their 1st-half days are
                        saved, but they are not in the payroll until the 2nd half (or a whole-month file) is uploaded. Change it at the start
                        of a month: pay already made on the other schedule is not carried over.
                    </span>
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
                <div class="form-group">
                    <label>Overtime Rate <span style="font-weight:400;color:#9ca3af;">(₱ per hour - blank = <?= htmlspecialchars(rtrim(rtrim(number_format((float)getSetting('overtime_rate', '150'), 2, '.', ''), '0'), '.')) ?>, the company rate)</span></label>
                    <input type="number" name="overtime_rate" id="f_ot_rate" class="form-control" step="0.01" min="0" max="<?= (int)MAX_RATE_PESOS ?>"
                           placeholder="<?= htmlspecialchars(rtrim(rtrim(number_format((float)getSetting('overtime_rate', '150'), 2, '.', ''), '0'), '.')) ?>">
                    <span style="font-size:.76rem;color:#9ca3af;margin-top:4px;display:block;">What each overtime hour of this employee pays. Leave it blank to use the company rate set in Settings.</span>
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
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:4px;">
                    <p style="font-size:.85rem;font-weight:700;color:#374151;margin:0;">Contributions &amp; Withholding Tax
                        <span style="font-weight:400;color:#9ca3af;">(₱ per month - all optional)</span></p>
                    <button type="button" class="btn btn-ghost btn-sm" id="seeBrackets" onclick="openBrackets()"
                            title="The law's brackets for SSS, PhilHealth, Pag-IBIG and withholding tax">See brackets</button>
                </div>
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

                <!-- Which cut-off of the month takes each amount (this employee's own schedule) -->
                <p id="contribSchedule" style="font-size:.85rem;font-weight:700;color:#374151;margin:16px 0 4px;">Contribution Schedule
                    <span style="font-weight:400;color:#9ca3af;">(when each amount comes out)</span></p>
                <div class="form-grid" id="f_timing_grid" style="grid-template-columns:1fr 1fr;">
                    <?php foreach (CONTRIBUTION_LABELS as $k => $lbl): ?>
                    <div class="form-group">
                        <label><?= htmlspecialchars($lbl) ?></label>
                        <select name="<?= $k ?>_timing" id="f_<?= $k ?>_timing" class="form-control">
                            <?php foreach (CONTRIBUTION_TIMING_LABELS as $val => $text): ?>
                            <option value="<?= $val ?>"><?= htmlspecialchars(ucfirst($text)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p id="timingNoteSemi" style="font-size:.76rem;color:#9ca3af;margin-top:4px;">
                    With two cut-offs a month, this says which one takes each monthly amount: the 1st cut-off in full, a share at every
                    cut-off (half each), or the last cut-off in full. Whichever is picked, the last cut-off settles whatever is still owed,
                    so the month always comes to exactly the amount typed. The pharmacy's own timesheets take SSS in full on the 1st
                    cut-off and PhilHealth in full on the 2nd.
                </p>
                <p id="timingNoteMonthly" style="font-size:.76rem;color:#9ca3af;margin-top:4px;display:none;">
                    This employee is paid <strong>Monthly</strong>: one pay run a month, so every amount above is taken in full on it
                    and the schedule here does not apply.
                </p>
            </div>

            <!-- Portal credentials section -->
            <div style="border-top:1px solid #e5e7eb;margin:18px 0 14px;padding-top:16px;">
                <p style="font-size:.85rem;font-weight:700;color:#374151;margin-bottom:12px;">Employee Portal Access</p>
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

<!-- See brackets: the law's tables for each contribution and for the withholding tax (reference only - what is typed on the form is what is deducted) -->
<?php
$bracketTabs = ['sss' => ['SSS', ['sss']], 'philhealth' => ['PhilHealth', ['philhealth']], 'pagibig' => ['Pag-IBIG', ['pagibig']],
                'tax' => ['Withholding tax', ['tax_semi', 'tax_month']]];
$bracketBy = [];
foreach ($brackets as $tbl) $bracketBy[$tbl['key']] = $tbl;
?>
<div id="bracketModal" class="modal-overlay brk-overlay" onclick="if(event.target===this)closeBrackets()">
    <div class="modal-box brk-box" role="dialog" aria-labelledby="brkTitle">
        <button type="button" class="modal-close" onclick="closeBrackets()">Close</button>
        <h2 id="brkTitle" class="modal-title">Brackets - contributions &amp; withholding tax</h2>
        <p class="brk-intro">
            What the law prescribes, bracket by bracket. It is a guide for the amounts typed on the employee: payroll does not
            work them out, it deducts exactly the monthly amounts typed on the form.
        </p>
        <p id="brkNow" class="brk-now"></p>
        <div class="brk-tabs" role="tablist">
            <?php $firstTab = true; foreach ($bracketTabs as $tab => [$tabTitle]): ?>
            <button type="button" role="tab" class="brk-tab<?= $firstTab ? ' on' : '' ?>" data-tab="<?= $tab ?>" onclick="showBracketTab('<?= $tab ?>')"><?= htmlspecialchars($tabTitle) ?></button>
            <?php $firstTab = false; endforeach; ?>
        </div>
        <?php $firstTab = true; foreach ($bracketTabs as $tab => [$tabTitle, $keys]): ?>
        <div class="brk-panel" data-panel="<?= $tab ?>" <?= $firstTab ? '' : 'hidden' ?>>
            <?php foreach ($keys as $key): $tbl = $bracketBy[$key]; ?>
            <h3 class="brk-title"><?= htmlspecialchars($tbl['title']) ?></h3>
            <p class="brk-note"><?= htmlspecialchars($tbl['note']) ?></p>
            <div class="brk-scroll">
                <table class="brk-table" data-basis="<?= htmlspecialchars($tbl['basis']) ?>">
                    <thead><tr><?php foreach ($tbl['head'] as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($tbl['rows'] as $row): ?>
                        <tr data-lo="<?= htmlspecialchars((string)$row['lo']) ?>" data-hi="<?= $row['hi'] === null ? '' : htmlspecialchars((string)$row['hi']) ?>">
                            <?php foreach ($row['cells'] as $cell): ?><td><?= htmlspecialchars($cell) ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
        <?php $firstTab = false; endforeach; ?>
    </div>
</div>

<script>
var _nextEmpId = <?= json_encode($next_emp_id) ?>;
/* what a new employee starts with: the pay schedule most employees are on, and the usual contribution schedule */
var _newEmployeeSchedule = <?= json_encode(['pay' => defaultPaySchedule($db), 'timing' => CONTRIBUTION_TIMING_DEFAULT]) ?>;
</script>
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
