<?php
require_once __DIR__ . '/includes/auth.php';
requireManager();

$activePage = 'employee-profiles';
$db  = getDB();
$m   = mgr();
$msg = null;

[$scopeWhere, $scopeParams] = mgrScopeWhere('e');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp_id    = trim($_POST['emp_id']             ?? '');
    $address   = trim($_POST['address']            ?? '');
    $phone     = trim($_POST['phone']              ?? '');
    $ename     = trim($_POST['emergency_name']     ?? '');
    $ephone    = trim($_POST['emergency_phone']    ?? '');
    $erelation = trim($_POST['emergency_relation'] ?? '');

    // Verify the employee belongs to this manager's branch
    $check = $db->prepare("SELECT emp_id FROM employees e WHERE e.emp_id=? $scopeWhere");
    $check->execute(array_merge([$emp_id], $scopeParams));

    if ($check->fetch()) {
        $db->prepare("INSERT INTO employee_profiles (emp_id,address,phone,emergency_name,emergency_phone,emergency_relation)
                      VALUES (?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE
                        address=VALUES(address), phone=VALUES(phone),
                        emergency_name=VALUES(emergency_name),
                        emergency_phone=VALUES(emergency_phone),
                        emergency_relation=VALUES(emergency_relation)")
           ->execute([$emp_id, $address, $phone, $ename, $ephone, $erelation]);
        $msg = ['type' => 'success', 'text' => 'Profile updated.'];
    } else {
        $msg = ['type' => 'error', 'text' => 'That employee is not one you manage.'];
    }
}

$params = $scopeParams;
$employees = $db->prepare("
    SELECT e.emp_id, e.full_name, e.branch, e.position,
           ep.address, ep.phone, ep.emergency_name, ep.emergency_phone, ep.emergency_relation, ep.updated_at
    FROM employees e
    LEFT JOIN employee_profiles ep ON ep.emp_id = e.emp_id
    WHERE 1=1 $scopeWhere
    ORDER BY e.emp_id ASC
");
$employees->execute($params);
$employees = $employees->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Profiles — Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>Employee Profiles</h1>
            <p>Address, phone &amp; emergency contacts<?= ' — ' . htmlspecialchars(mgrScopeLabel()) ?></p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="p-alert p-alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <div class="p-box">
        <div class="p-table-wrap">
            <table class="p-table">
                <thead>
                    <tr>
                        <th>Emp ID</th><th>Name</th><th>Phone</th>
                        <th>Address</th><th>Emergency Contact</th><th>Updated</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:24px;">No employees assigned to you.</td></tr>
                <?php else: ?>
                    <?php foreach ($employees as $e): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($e['emp_id']) ?></strong></td>
                        <td>
                            <?= htmlspecialchars($e['full_name']) ?>
                            <?php if ($e['position']): ?>
                                <br><small style="color:#9ca3af;"><?= htmlspecialchars($e['position']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($e['phone'] ?: '—') ?></td>
                        <td style="max-width:180px;white-space:normal;font-size:.82rem;">
                            <?= htmlspecialchars($e['address'] ?: '—') ?>
                        </td>
                        <td style="font-size:.82rem;">
                            <?php if ($e['emergency_name']): ?>
                                <strong><?= htmlspecialchars($e['emergency_name']) ?></strong>
                                <?php if ($e['emergency_relation']): ?>
                                    <em style="color:#6b7280;"> <?= htmlspecialchars($e['emergency_relation']) ?></em>
                                <?php endif; ?>
                                <br><?= htmlspecialchars($e['emergency_phone'] ?: '') ?>
                            <?php else: ?>
                                <span style="color:#9ca3af;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#6b7280;font-size:.78rem;">
                            <?= $e['updated_at'] ? date('M d, Y', strtotime($e['updated_at'])) : '<span style="color:#d97706;">Not set</span>' ?>
                        </td>
                        <td>
                            <button class="btn btn-ghost btn-sm" onclick='openEdit(<?= json_encode($e) ?>)'>Edit</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Profile Modal -->
<div id="profileModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)closeEdit()">
    <div class="modal-box" style="max-width:500px;">
        <button class="modal-close" onclick="closeEdit()">Close</button>
        <h2 class="modal-title">Edit Profile</h2>
        <p id="editLabel" style="font-size:.84rem;color:#6b7280;margin:-10px 0 18px;"></p>
        <form method="POST">
            <input type="hidden" name="emp_id" id="pe_emp_id">
            <div class="p-form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="p-form-group" style="grid-column:1/-1;">
                    <label>Home Address</label>
                    <input type="text" name="address" id="pe_address" class="p-form-control" placeholder="123 Rizal St, Barangay…">
                </div>
                <div class="p-form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" id="pe_phone" class="p-form-control" placeholder="09XX XXX XXXX">
                </div>
                <div class="p-form-group">
                    <label>Emergency Contact Name</label>
                    <input type="text" name="emergency_name" id="pe_ename" class="p-form-control" placeholder="Maria Cruz">
                </div>
                <div class="p-form-group">
                    <label>Emergency Phone</label>
                    <input type="text" name="emergency_phone" id="pe_ephone" class="p-form-control" placeholder="09XX XXX XXXX">
                </div>
                <div class="p-form-group">
                    <label>Relationship</label>
                    <input type="text" name="emergency_relation" id="pe_erelation" class="p-form-control" placeholder="Spouse, Parent, Sibling…">
                </div>
            </div>
            <div class="p-form-actions">
                <button type="submit" class="btn btn-primary">Save Profile</button>
                <button type="button" class="btn btn-ghost" onclick="closeEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(3px);z-index:500;display:flex;align-items:center;justify-content:center;padding:16px; }
.modal-box     { background:#fff;border-radius:14px;padding:26px;width:100%;max-height:92vh;overflow-y:auto;box-shadow:0 4px 24px rgba(0,0,0,.18);position:relative; }
.modal-close   { position:absolute;top:12px;right:14px;background:none;border:1px solid #e5e7eb;border-radius:6px;padding:3px 10px;font-size:.78rem;cursor:pointer;color:#6b7280; }
.modal-title   { font-size:1.05rem;font-weight:700;margin-bottom:6px; }
@media(max-width:600px){.modal-overlay{align-items:flex-end;padding:0;}.modal-box{border-radius:16px 16px 0 0;padding:20px 14px;max-height:94vh;}}
</style>

<script>
function openEdit(e) {
    document.getElementById('pe_emp_id').value    = e.emp_id;
    document.getElementById('pe_address').value   = e.address            || '';
    document.getElementById('pe_phone').value     = e.phone              || '';
    document.getElementById('pe_ename').value     = e.emergency_name     || '';
    document.getElementById('pe_ephone').value    = e.emergency_phone    || '';
    document.getElementById('pe_erelation').value = e.emergency_relation || '';
    document.getElementById('editLabel').textContent = e.full_name + ' (' + e.emp_id + ')';
    document.getElementById('profileModal').style.display = 'flex';
}
function closeEdit() { document.getElementById('profileModal').style.display = 'none'; }
</script>
</body>
</html>
