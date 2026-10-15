<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'managers';
$db  = getDB();
$msg = null;

/*
 * Rewrite what a manager handles (the rows of user_scopes, one per grant):
 *   branch      the whole company (no branch picked, one Global row) or one whole branch (one Branch row)
 *   department  one whole department (one Department row)
 *   custom      the picked employees (one Employee row each)
 * Branch and department grants are resolved when a page is opened, so employees added later are covered too.
 */
function saveManagerScope(PDO $db, int $managerId, string $scope, ?int $branchId, ?int $departmentId, array $empIds): void {
    $db->prepare("DELETE FROM user_scopes WHERE user_id = ?")->execute([$managerId]);
    $ins = $db->prepare("INSERT INTO user_scopes (user_id, scope_type, branch_id, department_id, employee_id) VALUES (?, ?, ?, ?, ?)");
    if ($scope === 'custom') {
        foreach (employeePks($db, $empIds) as $pk) $ins->execute([$managerId, 'Employee', null, null, $pk]);
    } elseif ($scope === 'department' && $departmentId) {
        $ins->execute([$managerId, 'Department', null, $departmentId, null]);
    } elseif ($scope === 'branch' && $branchId) {
        $ins->execute([$managerId, 'Branch', $branchId, null, null]);
    } else {
        $ins->execute([$managerId, 'Global', null, null, null]);
    }
}

/* Short description of each manager's scope, for the table */
function scopeSummary(array $mgr, array $assignments): string {
    if ($mgr['scope_type'] === 'custom') {
        $n = count($assignments[(int)$mgr['id']] ?? []);
        return $n . ' selected employee' . ($n === 1 ? '' : 's');
    }
    if ($mgr['scope_type'] === 'department') return $mgr['department'] . ' department';
    return $mgr['branch'] !== '' ? $mgr['branch'] . ' branch' : 'All branches';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $password = trim($_POST['password']   ?? '');
        $name     = trim($_POST['full_name']  ?? '');
        $branch   = trim($_POST['branch']     ?? '');
        $dept     = trim($_POST['department'] ?? '');
        $email    = trim($_POST['email']      ?? '');
        $scope    = in_array($_POST['scope_type'] ?? 'branch', ['branch', 'department', 'custom'], true) ? $_POST['scope_type'] : 'branch';
        $empIds   = array_values(array_filter(array_map('trim', (array)($_POST['emp_ids'] ?? []))));

        /* each kind of scope keeps only what it needs */
        if ($scope !== 'custom')     $empIds = [];
        if ($scope !== 'branch')     $branch = '';
        if ($scope !== 'department') $dept   = '';

        $branchId = branchId($db, $branch);
        $deptId   = null;
        if ($dept !== '') {
            $dq = $db->prepare("SELECT id FROM departments WHERE name = ?");
            $dq->execute([$dept]);
            $deptId = $dq->fetchColumn() ?: null;
        }

        if ($scope === 'custom' && !$empIds) {
            $msg = ['type' => 'error', 'text' => 'Select at least one employee, or switch to whole-branch scope.'];
        } elseif ($scope === 'branch' && $branch !== '' && !$branchId) {
            $msg = ['type' => 'error', 'text' => 'That branch is not in the branch list. Add it in Settings first.'];
        } elseif ($scope === 'department' && !$deptId) {
            $msg = ['type' => 'error', 'text' => 'Choose a department (add departments in Settings).'];
        } elseif ($action === 'add') {
            if (!$email || !$password || !$name) {
                $msg = ['type' => 'error', 'text' => 'Name, email, and password are required.'];
            } else {
                try {
                    $db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'Manager')")
                       ->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT)]);
                    saveManagerScope($db, (int)$db->lastInsertId(), $scope, $branchId, $deptId ? (int)$deptId : null, $empIds);
                    $msg = ['type' => 'success', 'text' => "Manager '$name' added. They can log in at /manager/ using $email."];
                } catch (PDOException $e) {
                    $msg = ['type' => 'error', 'text' => 'That email is already registered.'];
                }
            }
        } else {
            $id = (int)$_POST['id'];
            try {
                if ($password) {
                    $db->prepare("UPDATE users SET full_name=?, email=?, password_hash=? WHERE id=? AND role='Manager'")
                       ->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $id]);
                } else {
                    $db->prepare("UPDATE users SET full_name=?, email=? WHERE id=? AND role='Manager'")
                       ->execute([$name, $email, $id]);
                }
                saveManagerScope($db, $id, $scope, $branchId, $deptId ? (int)$deptId : null, $empIds);
                $msg = ['type' => 'success', 'text' => "Manager '$name' updated."];
            } catch (PDOException $e) {
                $msg = ['type' => 'error', 'text' => 'That email is already registered.'];
            }
        }
    }

    if ($action === 'toggle') {
        $id  = (int)$_POST['id'];
        $cur = $db->prepare("SELECT status FROM users WHERE id=? AND role='Manager'");
        $cur->execute([$id]);
        $cur = $cur->fetchColumn();
        $new = $cur === 'Active' ? 'Inactive' : 'Active';
        $db->prepare("UPDATE users SET status=? WHERE id=? AND role='Manager'")->execute([$new, $id]);
        $msg = ['type' => 'success', 'text' => "Manager status changed to $new."];
    }
}

// Select everything except password_hash - never expose hashes to the browser
$managers = $db->query("SELECT id, full_name, email, status, created_at
                        FROM users WHERE role='Manager' ORDER BY full_name ASC")->fetchAll();

/* what each manager handles: the grants, shaped for the table and the edit form */
$assignments = [];      /* manager_id => [emp_id, ...] for the picked employees */
$grants      = [];
foreach ($db->query("SELECT s.user_id, s.scope_type, b.name AS branch, d.name AS department, e.employee_code
                       FROM user_scopes s
                  LEFT JOIN branches b    ON b.id = s.branch_id
                  LEFT JOIN departments d ON d.id = s.department_id
                  LEFT JOIN employees e   ON e.id = s.employee_id
                      ORDER BY s.id")->fetchAll() as $g) {
    $uid = (int)$g['user_id'];
    $grants[$uid] ??= ['scope_type' => 'branch', 'branch' => '', 'department' => ''];
    if ($g['scope_type'] === 'Branch')     { $grants[$uid]['branch'] = (string)$g['branch']; }
    if ($g['scope_type'] === 'Department') { $grants[$uid]['scope_type'] = 'department'; $grants[$uid]['department'] = (string)$g['department']; }
    if ($g['scope_type'] === 'Employee')   { $grants[$uid]['scope_type'] = 'custom'; $assignments[$uid][] = $g['employee_code']; }
}
foreach ($managers as &$m) { $m += $grants[(int)$m['id']] ?? ['scope_type' => 'branch', 'branch' => '', 'department' => '']; }
unset($m);

/* The branch and department lists managed in Settings */
$branches    = $db->query("SELECT name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$departments = $db->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$allEmployees = $db->query("SELECT e.employee_code AS emp_id, e.full_name, COALESCE(br.name, '') AS branch
                              FROM employees e LEFT JOIN branches br ON br.id = e.branch_id
                             WHERE e.status='Active' ORDER BY br.name ASC, e.full_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Managers - Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/employee.css">
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Manager Accounts</h1>
            <p><?= count($managers) ?> manager account(s)</p>
        </div>
        <button class="btn btn-primary" onclick="openModal()">+ Add Manager</button>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <div class="box">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Full Name</th><th>Email</th><th>Manages</th><th>Status</th><th>Created</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if (empty($managers)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:30px;">No manager accounts yet. Add one above.</td></tr>
                <?php else: ?>
                    <?php foreach ($managers as $mgr): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($mgr['full_name']) ?></strong></td>
                        <td><?= htmlspecialchars($mgr['email']) ?></td>
                        <td>
                            <?= htmlspecialchars(scopeSummary($mgr, $assignments)) ?>
                            <?php if (($mgr['scope_type'] ?? 'branch') === 'custom'): ?>
                                <br><small style="color:#9ca3af;font-size:.75rem;">
                                    <?= htmlspecialchars(implode(', ', $assignments[(int)$mgr['id']] ?? [])) ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $mgr['status']==='Active' ? 'green' : 'red' ?>">
                                <?= $mgr['status'] ?>
                            </span>
                        </td>
                        <td style="color:#6b7280;font-size:.85rem;"><?= date('M d, Y', strtotime($mgr['created_at'])) ?></td>
                        <td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                            <button class="btn btn-ghost btn-sm" onclick='editMgr(<?= htmlspecialchars(json_encode($mgr), ENT_QUOTES) ?>)'>Edit</button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id"     value="<?= $mgr['id'] ?>">
                                <?php if ($mgr['status'] === 'Active'): ?>
                                    <button class="btn btn-red btn-sm">Deactivate</button>
                                <?php else: ?>
                                    <button class="btn btn-green btn-sm">Reactivate</button>
                                <?php endif; ?>
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

<!-- Add/Edit Modal -->
<div id="mgrModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">Close</button>
        <h2 id="modalTitle" class="modal-title">Add Manager</h2>
        <form method="POST">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id"     id="formId"     value="">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" id="f_name" class="form-control" required placeholder="Juan dela Cruz">
                </div>
                <div class="form-group">
                    <label>Email * <span style="font-weight:400;color:#9ca3af;">(used to log in)</span></label>
                    <input type="email" name="email" id="f_email" class="form-control" required placeholder="manager@company.com">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Password <span id="passHint" style="font-weight:400;color:#9ca3af;"></span></label>
                    <input type="password" name="password" id="f_password" class="form-control" placeholder="Set a password" autocomplete="new-password">
                </div>

                <div class="form-group" style="grid-column:1/-1;">
                    <label>Employees this manager handles</label>
                    <div class="scope-choice">
                        <label><input type="radio" name="scope_type" value="branch" id="sc_branch" checked onchange="updateScopeUI()"> Whole branch</label>
                        <label><input type="radio" name="scope_type" value="department" id="sc_department" onchange="updateScopeUI()"> Whole department</label>
                        <label><input type="radio" name="scope_type" value="custom" id="sc_custom" onchange="updateScopeUI()"> Selected employees</label>
                    </div>

                    <div id="branchScope">
                        <select name="branch" id="f_branch" class="form-control">
                            <option value="">All branches (entire company)</option>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="scope-hint">Employees added to this branch later are covered automatically.</p>
                    </div>

                    <div id="departmentScope" style="display:none;">
                        <select name="department" id="f_department" class="form-control">
                            <option value="">- choose a department -</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="scope-hint">Everyone whose position belongs to this department, now and later.
                            <?= $departments ? '' : 'No departments yet - add them in Settings.' ?></p>
                    </div>

                    <div id="customScope" style="display:none;">
                        <div class="scope-tools">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="tickAll(true)">Select all</button>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="tickAll(false)">Clear</button>
                            <span id="pickCount" style="font-size:.8rem;color:#6b7280;"></span>
                        </div>
                        <div class="emp-picker">
                            <?php if (empty($allEmployees)): ?>
                                <p style="color:#9ca3af;font-size:.85rem;margin:0;">No active employees yet.</p>
                            <?php else: ?>
                                <?php foreach ($allEmployees as $emp): ?>
                                <label class="emp-row">
                                    <input type="checkbox" name="emp_ids[]" value="<?= htmlspecialchars($emp['emp_id']) ?>" onchange="updateCount()">
                                    <span>
                                        <strong><?= htmlspecialchars($emp['emp_id']) ?></strong>
                                        <?= htmlspecialchars($emp['full_name']) ?>
                                        <small style="color:#9ca3af;"><?= htmlspecialchars($emp['branch'] ?: 'No branch') ?></small>
                                    </span>
                                </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p class="scope-hint">Only these employees. New hires must be added here manually.</p>
                    </div>
                </div>
            </div>
            <div class="form-actions" style="margin-top:18px;">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(3px);z-index:500;display:flex;align-items:center;justify-content:center;padding:16px; }
.modal-box     { background:#fff;border-radius:14px;padding:28px;width:100%;max-width:560px;max-height:92vh;overflow-y:auto;box-shadow:0 4px 24px rgba(0,0,0,.15);position:relative; }
.modal-close   { position:absolute;top:14px;right:14px;background:none;border:1px solid #e5e7eb;border-radius:6px;padding:3px 10px;font-size:.78rem;cursor:pointer;color:#6b7280; }
.modal-title   { font-size:1.05rem;font-weight:700;margin-bottom:18px; }
.scope-choice  { display:flex;gap:20px;margin-bottom:10px;font-size:.86rem; }
.scope-choice label { display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500; }
.scope-hint    { font-size:.76rem;color:#9ca3af;margin:6px 0 0; }
.scope-tools   { display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap; }
.emp-picker    { border:1px solid #e5e7eb;border-radius:8px;max-height:210px;overflow-y:auto;padding:6px; }
.emp-row       { display:flex;align-items:center;gap:9px;padding:7px 8px;border-radius:6px;cursor:pointer;font-size:.85rem;font-weight:400; }
.emp-row:hover { background:#f9fafb; }
.emp-row span  { display:flex;gap:8px;align-items:baseline;flex-wrap:wrap; }
@media (max-width:600px) {
    .modal-overlay { align-items:flex-end; padding:0; }
    .modal-box     { border-radius:16px 16px 0 0; padding:22px 16px; max-height:94vh; }
}
@media (max-width:480px) {
    .modal-box     { padding:18px 12px; }
    .modal-box .form-grid { grid-template-columns:1fr !important; }
}
</style>

<script>
const ASSIGNMENTS = <?= json_encode($assignments, JSON_FORCE_OBJECT) ?>;

function scopeBoxes() {
    return Array.from(document.querySelectorAll('#customScope input[type=checkbox]'));
}
function updateScopeUI() {
    const custom = document.getElementById('sc_custom').checked;
    const dept   = document.getElementById('sc_department').checked;
    document.getElementById('customScope').style.display     = custom ? 'block' : 'none';
    document.getElementById('departmentScope').style.display = dept   ? 'block' : 'none';
    document.getElementById('branchScope').style.display     = (custom || dept) ? 'none' : 'block';
    updateCount();
}
function updateCount() {
    const n = scopeBoxes().filter(c => c.checked).length;
    document.getElementById('pickCount').textContent = n + ' selected';
}
function tickAll(state) {
    scopeBoxes().forEach(c => { c.checked = state; });
    updateCount();
}
function openModal() {
    document.getElementById('modalTitle').textContent = 'Add Manager';
    document.getElementById('formAction').value       = 'add';
    document.getElementById('formId').value           = '';
    document.getElementById('f_name').value           = '';
    document.getElementById('f_email').value          = '';
    document.getElementById('f_password').value       = '';
    document.getElementById('f_branch').value         = '';
    document.getElementById('f_department').value     = '';
    document.getElementById('sc_branch').checked      = true;
    tickAll(false);
    document.getElementById('passHint').textContent   = '* required';
    document.getElementById('f_password').required    = true;
    updateScopeUI();
    document.getElementById('mgrModal').style.display = 'flex';
}
function editMgr(m) {
    document.getElementById('modalTitle').textContent = 'Edit Manager';
    document.getElementById('formAction').value       = 'edit';
    document.getElementById('formId').value           = m.id;
    document.getElementById('f_name').value           = m.full_name;
    document.getElementById('f_email').value          = m.email;
    document.getElementById('f_password').value       = '';
    document.getElementById('f_branch').value         = m.branch || '';
    document.getElementById('passHint').textContent   = '(leave blank to keep current)';
    document.getElementById('f_password').required    = false;

    document.getElementById('f_department').value = m.department || '';
    const custom = (m.scope_type === 'custom'), dept = (m.scope_type === 'department');
    document.getElementById('sc_custom').checked     = custom;
    document.getElementById('sc_department').checked = dept;
    document.getElementById('sc_branch').checked     = !custom && !dept;

    const mine = ASSIGNMENTS[m.id] || [];
    scopeBoxes().forEach(c => { c.checked = mine.indexOf(c.value) !== -1; });

    updateScopeUI();
    document.getElementById('mgrModal').style.display = 'flex';
}
function closeModal() { document.getElementById('mgrModal').style.display = 'none'; }
</script>
</body>
</html>
