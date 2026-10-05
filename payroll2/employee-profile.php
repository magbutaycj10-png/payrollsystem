<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'employee-profile';
$db  = getDB();
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp_id    = trim($_POST['emp_id']             ?? '');
    $address   = trim($_POST['address']            ?? '');
    $phone     = trim($_POST['phone']              ?? '');
    $ename     = trim($_POST['emergency_name']     ?? '');
    $ephone    = trim($_POST['emergency_phone']    ?? '');
    $erelation = trim($_POST['emergency_relation'] ?? '');

    if ($emp_id) {
        $db->prepare("INSERT INTO employee_profiles (emp_id,address,phone,emergency_name,emergency_phone,emergency_relation)
                      VALUES (?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE
                        address=VALUES(address), phone=VALUES(phone),
                        emergency_name=VALUES(emergency_name),
                        emergency_phone=VALUES(emergency_phone),
                        emergency_relation=VALUES(emergency_relation)")
           ->execute([$emp_id, $address, $phone, $ename, $ephone, $erelation]);
        $msg = ['type' => 'success', 'text' => 'Profile updated.'];
    }
}

$employees = $db->query("
    SELECT e.emp_id, e.full_name, e.branch, e.position,
           ep.address, ep.phone, ep.emergency_name, ep.emergency_phone, ep.emergency_relation, ep.updated_at
    FROM employees e
    LEFT JOIN employee_profiles ep ON ep.emp_id = e.emp_id
    ORDER BY e.emp_id ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Profiles — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/employee.css">
    <style>
        .profile-complete { color:#16a34a; font-size:.75rem; font-weight:600; }
        .profile-missing  { color:#d97706; font-size:.75rem; font-weight:600; }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Employee Profiles</h1>
            <p>Address, phone, and emergency contact information</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <div class="box">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Emp ID</th><th>Name</th><th>Branch</th>
                        <th>Phone</th><th>Address</th>
                        <th>Emergency Contact</th><th>Last Updated</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:30px;">No employees found.</td></tr>
                <?php else: ?>
                    <?php foreach ($employees as $e): ?>
                    <?php $complete = $e['phone'] && $e['emergency_name']; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($e['emp_id']) ?></strong></td>
                        <td><?= htmlspecialchars($e['full_name']) ?></td>
                        <td><?= htmlspecialchars($e['branch'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($e['phone'] ?: '—') ?></td>
                        <td style="max-width:200px;white-space:normal;font-size:.82rem;">
                            <?= htmlspecialchars($e['address'] ?: '—') ?>
                        </td>
                        <td style="font-size:.82rem;">
                            <?php if ($e['emergency_name']): ?>
                                <strong><?= htmlspecialchars($e['emergency_name']) ?></strong><br>
                                <span style="color:#6b7280;"><?= htmlspecialchars($e['emergency_phone'] ?: '') ?></span>
                                <?php if ($e['emergency_relation']): ?>
                                    <em><?= htmlspecialchars($e['emergency_relation']) ?></em>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#9ca3af;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#6b7280;font-size:.8rem;">
                            <?php if ($e['updated_at']): ?>
                                <?= date('M d, Y', strtotime($e['updated_at'])) ?>
                            <?php else: ?>
                                <span class="profile-missing">Not set</span>
                            <?php endif; ?>
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
<div id="profileModal" class="modal-overlay" onclick="if(event.target===this)closeEdit()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeEdit()">Close</button>
        <h2 class="modal-title">Edit Profile</h2>
        <p id="editEmpLabel" style="font-size:.85rem;color:#6b7280;margin-bottom:18px;margin-top:-12px;"></p>
        <form method="POST">
            <input type="hidden" name="emp_id" id="pe_emp_id">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Home Address</label>
                    <input type="text" name="address" id="pe_address" class="form-control" placeholder="123 Rizal St, Barangay…">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" id="pe_phone" class="form-control" placeholder="09XX XXX XXXX">
                </div>
                <div class="form-group">
                    <label>Emergency Contact Name</label>
                    <input type="text" name="emergency_name" id="pe_ename" class="form-control" placeholder="Maria Cruz">
                </div>
                <div class="form-group">
                    <label>Emergency Phone</label>
                    <input type="text" name="emergency_phone" id="pe_ephone" class="form-control" placeholder="09XX XXX XXXX">
                </div>
                <div class="form-group">
                    <label>Relationship</label>
                    <input type="text" name="emergency_relation" id="pe_erelation" class="form-control" placeholder="Spouse, Parent, Sibling…">
                </div>
            </div>
            <div class="form-actions" style="margin-top:18px;">
                <button type="submit" class="btn btn-primary">Save Profile</button>
                <button type="button" class="btn btn-ghost" onclick="closeEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(e) {
    document.getElementById('pe_emp_id').value     = e.emp_id;
    document.getElementById('pe_address').value    = e.address            || '';
    document.getElementById('pe_phone').value      = e.phone              || '';
    document.getElementById('pe_ename').value      = e.emergency_name     || '';
    document.getElementById('pe_ephone').value     = e.emergency_phone    || '';
    document.getElementById('pe_erelation').value  = e.emergency_relation || '';
    document.getElementById('editEmpLabel').textContent = e.full_name + ' (' + e.emp_id + ')';
    document.getElementById('profileModal').classList.add('open');
}
function closeEdit() { document.getElementById('profileModal').classList.remove('open'); }
</script>
</body>
</html>
