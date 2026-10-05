<?php
require_once __DIR__ . '/includes/auth.php';
requireEmployee();

$activePage = 'profile';
$db  = getDB();
$e   = emp();
$msg = null;

$empInfo = $db->prepare("SELECT * FROM employees WHERE emp_id=?");
$empInfo->execute([$e['id']]);
$empInfo = $empInfo->fetch();

$profile = $db->prepare("SELECT * FROM employee_profiles WHERE emp_id=?");
$profile->execute([$e['id']]);
$profile = $profile->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $address  = trim($_POST['address']           ?? '');
        $phone    = trim($_POST['phone']             ?? '');
        $ec_name  = trim($_POST['emergency_name']    ?? '');
        $ec_phone = trim($_POST['emergency_phone']   ?? '');
        $ec_rel   = trim($_POST['emergency_relation'] ?? '');

        $check = $db->prepare("SELECT id FROM employee_profiles WHERE emp_id=?");
        $check->execute([$e['id']]);

        if ($check->fetch()) {
            $db->prepare("
                UPDATE employee_profiles
                SET address=?, phone=?, emergency_name=?, emergency_phone=?, emergency_relation=?
                WHERE emp_id=?
            ")->execute([$address, $phone, $ec_name, $ec_phone, $ec_rel, $e['id']]);
        } else {
            $db->prepare("
                INSERT INTO employee_profiles (emp_id, address, phone, emergency_name, emergency_phone, emergency_relation)
                VALUES (?,?,?,?,?,?)
            ")->execute([$e['id'], $address, $phone, $ec_name, $ec_phone, $ec_rel]);
        }

        $msg = ['type' => 'success', 'text' => 'Profile updated successfully.'];

        $profile = $db->prepare("SELECT * FROM employee_profiles WHERE emp_id=?");
        $profile->execute([$e['id']]);
        $profile = $profile->fetch() ?: [];
    }

    if ($action === 'change_password') {
        $currentPass = trim($_POST['current_password'] ?? '');
        $newPass     = trim($_POST['new_password']     ?? '');
        $confirmPass = trim($_POST['confirm_password'] ?? '');

        $acct = $db->prepare("SELECT id, password_hash FROM users WHERE emp_id=? AND role='earner'");
        $acct->execute([$e['id']]);
        $acct = $acct->fetch();

        if (!$acct || !password_verify($currentPass, $acct['password_hash'])) {
            $msg = ['type' => 'error', 'text' => 'Current password is incorrect.'];
        } elseif ($newPass !== $confirmPass) {
            $msg = ['type' => 'error', 'text' => 'New passwords do not match.'];
        } elseif (strlen($newPass) < 6) {
            $msg = ['type' => 'error', 'text' => 'New password must be at least 6 characters.'];
        } else {
            $db->prepare("UPDATE users SET password_hash=? WHERE id=?")
               ->execute([password_hash($newPass, PASSWORD_BCRYPT), $acct['id']]);
            $msg = ['type' => 'success', 'text' => 'Password changed successfully.'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — Employee Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>My Profile</h1>
            <p>Update your personal information and emergency contacts</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="p-alert p-alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <!-- Employee info (read-only) -->
    <div class="p-box" style="margin-bottom:20px;">
        <div class="p-box-header"><h2>Employee Information</h2></div>
        <div class="p-box-body">
            <div class="p-form-grid">
                <div class="p-form-group">
                    <label>Employee ID</label>
                    <div class="p-form-control" style="background:#f9fafb;color:#6b7280;"><?= htmlspecialchars($empInfo['emp_id']) ?></div>
                </div>
                <div class="p-form-group">
                    <label>Full Name</label>
                    <div class="p-form-control" style="background:#f9fafb;color:#6b7280;"><?= htmlspecialchars($empInfo['full_name']) ?></div>
                </div>
                <div class="p-form-group">
                    <label>Position</label>
                    <div class="p-form-control" style="background:#f9fafb;color:#6b7280;"><?= htmlspecialchars($empInfo['position'] ?? '—') ?></div>
                </div>
                <div class="p-form-group">
                    <label>Branch</label>
                    <div class="p-form-control" style="background:#f9fafb;color:#6b7280;"><?= htmlspecialchars($empInfo['branch'] ?? '—') ?></div>
                </div>
                <div class="p-form-group">
                    <label>Email (Company)</label>
                    <div class="p-form-control" style="background:#f9fafb;color:#6b7280;"><?= htmlspecialchars($empInfo['email'] ?? '—') ?></div>
                </div>
                <div class="p-form-group">
                    <label>Date Hired</label>
                    <div class="p-form-control" style="background:#f9fafb;color:#6b7280;">
                        <?= $empInfo['date_hired'] ? date('F j, Y', strtotime($empInfo['date_hired'])) : '—' ?>
                    </div>
                </div>
            </div>
            <p style="font-size:.78rem;color:#9ca3af;margin-top:10px;">To update company records (name, email, position), contact your admin or HR.</p>
        </div>
    </div>

    <!-- Personal details (editable) -->
    <div class="p-box" style="margin-bottom:20px;">
        <div class="p-box-header"><h2>Personal Details</h2></div>
        <div class="p-box-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="p-form-grid">
                    <div class="p-form-group" style="grid-column: 1 / -1;">
                        <label>Home Address</label>
                        <textarea name="address" class="p-form-control" placeholder="Street, Barangay, City, Province"><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
                    </div>
                    <div class="p-form-group">
                        <label>Personal Phone</label>
                        <input type="tel" name="phone" class="p-form-control" placeholder="09XX XXX XXXX" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
                    </div>
                </div>

                <h3 style="font-size:.9rem;font-weight:700;margin:18px 0 12px;color:#374151;">Emergency Contact</h3>
                <div class="p-form-grid">
                    <div class="p-form-group">
                        <label>Contact Name</label>
                        <input type="text" name="emergency_name" class="p-form-control" placeholder="Full name" value="<?= htmlspecialchars($profile['emergency_name'] ?? '') ?>">
                    </div>
                    <div class="p-form-group">
                        <label>Contact Phone</label>
                        <input type="tel" name="emergency_phone" class="p-form-control" placeholder="09XX XXX XXXX" value="<?= htmlspecialchars($profile['emergency_phone'] ?? '') ?>">
                    </div>
                    <div class="p-form-group">
                        <label>Relationship</label>
                        <select name="emergency_relation" class="p-form-control">
                            <?php foreach (['', 'Spouse','Parent','Sibling','Child','Relative','Friend','Other'] as $rel): ?>
                            <option value="<?= $rel ?>" <?= ($profile['emergency_relation'] ?? '') === $rel ? 'selected' : '' ?>>
                                <?= $rel ?: '— Select —' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="p-form-actions">
                    <button type="submit" class="btn btn-primary">Save Profile</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password -->
    <div class="p-box">
        <div class="p-box-header"><h2>Change Password</h2></div>
        <div class="p-box-body">
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="p-form-grid">
                    <div class="p-form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" class="p-form-control" placeholder="Enter current password" autocomplete="current-password">
                    </div>
                    <div class="p-form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="p-form-control" placeholder="Min. 6 characters" autocomplete="new-password">
                    </div>
                    <div class="p-form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="p-form-control" placeholder="Re-enter new password" autocomplete="new-password">
                    </div>
                </div>
                <div class="p-form-actions">
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
