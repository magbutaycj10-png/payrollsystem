<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'history';
$db = getDB();

$employees = $db->query("SELECT emp_id, full_name FROM employees ORDER BY full_name ASC")->fetchAll();
$periods   = $db->query("SELECT * FROM payroll_periods ORDER BY period_start DESC")->fetchAll();

$fEmp    = $_GET['emp_id']    ?? '';
$fPeriod = (int)($_GET['period_id'] ?? 0);
$fType   = $_GET['type']      ?? '';
/* rev=1 narrows the list to entries recorded after a period had been finalized */
$fRev    = ($_GET['rev'] ?? '') === '1';

$where  = '1=1';
$params = [];
if ($fEmp)    { $where .= ' AND emp_id = ?';      $params[] = $fEmp; }
if ($fPeriod) { $where .= ' AND period_id = ?';   $params[] = $fPeriod; }
if ($fType)   { $where .= ' AND entry_type = ?';  $params[] = $fType; }
if ($fRev)    { $where .= ' AND finalize_cycle > 0'; }

$st = $db->prepare("SELECT * FROM bonus_deduction_history WHERE $where ORDER BY entry_date DESC, id DESC LIMIT 300");
$st->execute($params);
$history   = $st->fetchAll();
$periodMap = array_column($periods, 'period_label', 'id');

/* How many of the listed entries are corrections made after a finalize. */
$revCount = 0;
foreach ($history as $h) { if ((int)($h['finalize_cycle'] ?? 0) > 0) $revCount++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjustment History — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Marks an entry recorded after the period had been finalized */
        .rev-tag {
            display: inline-block; margin-left: 4px; padding: 1px 6px;
            border-radius: 999px; background: #fef3c7; color: #92400e;
            font-size: .64rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; vertical-align: middle; white-space: nowrap;
        }
        tr.is-revised td { background: #fffbeb; }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Adjustment History</h1>
            <p>All bonuses and deductions recorded across periods</p>
        </div>
        <div style="display:flex;gap:10px;">
            <a href="adjustments.php<?= $fPeriod ? '?period=' . $fPeriod : '' ?>" class="btn btn-green no-print">Add Bonus / Deduction</a>
            <button class="btn btn-print no-print" onclick="printAll()">Print All</button>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="toolbar" style="margin-bottom:16px;">
        <div class="form-group" style="flex-direction:row;align-items:center;gap:6px;">
            <label style="font-size:.8rem;font-weight:600;color:#6b7280;white-space:nowrap;">Employee</label>
            <select name="emp_id" class="form-control" style="min-width:200px;">
                <option value="">All Employees</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= htmlspecialchars($e['emp_id']) ?>" <?= $fEmp === $e['emp_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($e['emp_id']) ?> — <?= htmlspecialchars($e['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex-direction:row;align-items:center;gap:6px;">
            <label style="font-size:.8rem;font-weight:600;color:#6b7280;white-space:nowrap;">Period</label>
            <select name="period_id" class="form-control" style="min-width:180px;">
                <option value="">All Periods</option>
                <?php foreach ($periods as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $fPeriod === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['period_label']) ?> · <?= htmlspecialchars(periodTypeLabel($p)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex-direction:row;align-items:center;gap:6px;">
            <label style="font-size:.8rem;font-weight:600;color:#6b7280;white-space:nowrap;">Type</label>
            <select name="type" class="form-control" style="min-width:140px;">
                <option value="">All Types</option>
                <option value="Bonus"     <?= $fType === 'Bonus'     ? 'selected' : '' ?>>Bonus</option>
                <option value="Deduction" <?= $fType === 'Deduction' ? 'selected' : '' ?>>Deduction</option>
            </select>
        </div>
        <div class="form-group" style="flex-direction:row;align-items:center;gap:6px;">
            <label style="font-size:.8rem;font-weight:600;color:#6b7280;white-space:nowrap;">
                <input type="checkbox" name="rev" value="1" <?= $fRev ? 'checked' : '' ?>
                       style="vertical-align:middle;margin-right:4px;">
                Corrections only
            </label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($fEmp || $fPeriod || $fType || $fRev): ?>
            <a href="history.php" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($revCount): ?>
        <div class="alert alert-warn no-print">
            <span>
                <?= $revCount ?> of the <?= count($history) ?> entr<?= count($history) === 1 ? 'y' : 'ies' ?>
                below <?= $revCount === 1 ? 'was' : 'were' ?> recorded <strong>after</strong> the period had already
                been finalized &mdash; those are corrections, and the employees they touched are flagged
                <strong>Revised</strong> on Payroll Processing and Reports.
            </span>
        </div>
    <?php endif; ?>

    <div class="box">
        <div class="box-header">
            <h2>Records</h2>
            <span style="font-size:.85rem;color:#6b7280;"><?= count($history) ?> entr<?= count($history) === 1 ? 'y' : 'ies' ?></span>
        </div>
        <div class="table-wrap">
            <table class="data-table" id="histTable">
                <thead>
                    <tr>
                        <th>Date</th><th>ID</th><th>Employee</th><th>Type</th>
                        <th>Amount</th><th>Reason</th><th>Period</th><th>By</th>
                        <th class="no-print">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;color:#9ca3af;padding:30px;">
                            No entries found.
                            <?php if ($fEmp || $fPeriod || $fType): ?>
                                <a href="history.php">Clear filters</a>
                            <?php else: ?>
                                <a href="adjustments.php">Add the first entry.</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                    <?php $hCycle = (int)($h['finalize_cycle'] ?? 0); ?>
                    <tr class="<?= $hCycle > 0 ? 'is-revised' : '' ?>">
                        <td><?= date('M d, Y', strtotime($h['entry_date'])) ?></td>
                        <td><?= htmlspecialchars($h['emp_id']) ?></td>
                        <td><?= htmlspecialchars($h['emp_name']) ?></td>
                        <td style="white-space:nowrap;">
                            <span class="badge badge-<?= $h['entry_type'] === 'Bonus' ? 'green' : 'red' ?>">
                                <?= $h['entry_type'] ?>
                            </span>
                            <?php if ($hCycle > 0): ?>
                                <span class="rev-tag"
                                      title="Recorded after the period had been finalized <?= $hCycle ?>&times; — this entry is a correction">Revision</span>
                            <?php endif; ?>
                        </td>
                        <td><strong>₱<?= number_format($h['amount'], 2) ?></strong></td>
                        <td><?= htmlspecialchars($h['reason']) ?></td>
                        <td>
                            <?php if ($h['period_id'] && isset($periodMap[$h['period_id']])): ?>
                                <span class="badge badge-blue"><?= htmlspecialchars($periodMap[$h['period_id']]) ?></span>
                            <?php else: ?>
                                <span style="color:#9ca3af;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($h['processed_by']) ?></td>
                        <td class="no-print">
                            <button class="btn btn-print btn-sm" onclick="printReceipt(<?= (int)$h['id'] ?>)">
                                Print Receipt
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="assets/js/history.js"></script>
</body>
</html>
