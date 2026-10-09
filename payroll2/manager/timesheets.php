<?php
require_once __DIR__ . '/includes/auth.php';
requireManager();

$activePage = 'timesheets';
$db  = getDB();
$m   = mgr();
$msg = null;

[$scopeWhere, $scopeParams] = mgrScopeWhere('e');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* One record - only if it belongs to this manager's employees */
    $mine = function (int $attId) use ($db, $scopeWhere, $scopeParams): bool {
        $st = $db->prepare("SELECT a.id FROM attendance a JOIN employees e ON e.emp_id = a.emp_id
                             WHERE a.id = ? $scopeWhere");
        $st->execute(array_merge([$attId], $scopeParams));
        return (bool)$st->fetchColumn();
    };

    if ($action === 'approve_one') {
        $id = (int)$_POST['att_id'];
        if ($mine($id)) {
            $db->prepare("UPDATE attendance SET manager_approved=1, approved_by=?, approved_at=NOW() WHERE id=?")
               ->execute([$m['name'], $id]);
            $msg = ['type' => 'success', 'text' => 'Timesheet record approved.'];
        } else {
            $msg = ['type' => 'error', 'text' => 'That record does not belong to one of your employees.'];
        }
    }

    if ($action === 'approve_all') {
        $period_id = (int)$_POST['period_id'];
        $params = array_merge([$m['name'], $period_id], $scopeParams);
        $db->prepare("
            UPDATE attendance a
            JOIN employees e ON e.emp_id = a.emp_id
            SET a.manager_approved=1, a.approved_by=?, a.approved_at=NOW()
            WHERE a.period_id=? AND a.manager_approved=0 $scopeWhere
        ")->execute($params);
        $msg = ['type' => 'success', 'text' => 'All pending timesheets approved.'];
    }

    if ($action === 'reject_one') {
        $id = (int)$_POST['att_id'];
        if ($mine($id)) {
            $db->prepare("UPDATE attendance SET manager_approved=2, approved_by=?, approved_at=NOW() WHERE id=?")
               ->execute([$m['name'], $id]);
            $msg = ['type' => 'success', 'text' => 'Timesheet record flagged for review.'];
        } else {
            $msg = ['type' => 'error', 'text' => 'That record does not belong to one of your employees.'];
        }
    }
}

$periods = $db->query("SELECT * FROM payroll_periods ORDER BY period_start DESC")->fetchAll();
$period_id = (int)($_GET['period'] ?? defaultPeriodId($db, $periods));

$rows = [];
if ($period_id) {
    $params = array_merge([$period_id], $scopeParams);
    $st = $db->prepare("
        SELECT a.*, e.full_name, e.branch
        FROM attendance a
        JOIN employees e ON e.emp_id = a.emp_id
        WHERE a.period_id = ? $scopeWhere
        ORDER BY a.manager_approved ASC, e.full_name ASC
    ");
    $st->execute($params);
    $rows = $st->fetchAll();
}

$pendingCount  = count(array_filter($rows, fn($r) => $r['manager_approved'] == 0));
$approvedCount = count(array_filter($rows, fn($r) => $r['manager_approved'] == 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timesheets - Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>Timesheet Approval</h1>
            <p>Review and approve attendance records for your team</p>
        </div>
        <?php if ($pendingCount > 0 && $period_id): ?>
        <form method="POST" onsubmit="return confirm('Approve all <?= $pendingCount ?> pending record(s)?')">
            <input type="hidden" name="action" value="approve_all">
            <input type="hidden" name="period_id" value="<?= $period_id ?>">
            <button type="submit" class="btn btn-green">Approve All (<?= $pendingCount ?>)</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($msg): ?>
        <div class="p-alert p-alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <div class="p-box" style="margin-bottom:16px;">
        <div class="p-box-body" style="padding:12px 16px;">
            <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="font-weight:600;font-size:.875rem;">Period:</label>
                <select name="period" class="p-form-control" style="max-width:220px;" onchange="this.form.submit()">
                    <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $period_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['period_label']) ?> [<?= htmlspecialchars(periodTypeLabel($p)) ?> · <?= $p['status'] ?>]
                    </option>
                    <?php endforeach; ?>
                </select>
                <span style="font-size:.85rem;color:#6b7280;">
                    <?= $pendingCount ?> pending &nbsp;&nbsp; <?= $approvedCount ?> approved
                </span>
            </form>
        </div>
    </div>

    <div class="p-box">
        <div class="p-table-wrap">
            <table class="p-table">
                <thead>
                    <tr>
                        <th>Employee</th><th>Branch</th><th>Hours</th><th>OT Hrs</th>
                        <th>Late Hrs</th><th>Gross Pay</th><th>Entered By</th><th>Status</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:24px;">No attendance records for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($r['emp_name'] ?? $r['emp_id']) ?></strong>
                            <br><small style="color:#9ca3af;"><?= htmlspecialchars($r['emp_id']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($r['branch'] ?? '-') ?></td>
                        <td><?= number_format($r['hours_worked'], 1) ?></td>
                        <td><?= number_format($r['overtime_hours'], 1) ?></td>
                        <td><?= number_format($r['late_hours'], 1) ?></td>
                        <td>₱<?= number_format($r['gross_pay'], 2) ?></td>
                        <td>
                            <?php if ($r['manually_entered_by']): ?>
                                <span style="color:#7c3aed;font-size:.8rem;">Manual: <?= htmlspecialchars($r['manually_entered_by']) ?></span>
                            <?php else: ?>
                                <span style="color:#6b7280;font-size:.8rem;">CSV Upload</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['manager_approved'] == 1): ?>
                                <span class="badge badge-green">Approved</span>
                                <br><small style="color:#9ca3af;font-size:.72rem;"><?= htmlspecialchars($r['approved_by']) ?></small>
                            <?php elseif ($r['manager_approved'] == 2): ?>
                                <span class="badge badge-red">Flagged</span>
                            <?php else: ?>
                                <span class="badge badge-yellow">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['manager_approved'] == 0): ?>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <form method="POST">
                                    <input type="hidden" name="action" value="approve_one">
                                    <input type="hidden" name="att_id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-green btn-sm">Approve</button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Flag this record for review?')">
                                    <input type="hidden" name="action" value="reject_one">
                                    <input type="hidden" name="att_id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-red btn-sm">Flag</button>
                                </form>
                            </div>
                            <?php else: ?>
                                <span style="color:#9ca3af;font-size:.8rem;">-</span>
                            <?php endif; ?>
                        </td>
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
