<?php
require 'includes/helpers.php';
requireAuth();

$activePage  = 'reports';
$db          = getDB();
$periods     = $db->query("SELECT * FROM payroll_periods ORDER BY period_start DESC")->fetchAll();
$period_id   = (int)($_GET['period'] ?? defaultPeriodId($db, $periods));
$curPeriod   = null;

foreach ($periods as $p) {
    if ($p['id'] == $period_id) { $curPeriod = $p; break; }
}

$rows = [];
if ($period_id) {
    $st = $db->prepare("SELECT * FROM payroll WHERE period_id=? ORDER BY emp_id");
    $st->execute([$period_id]);
    $rows = $st->fetchAll();
}

$isOpen      = $curPeriod && $curPeriod['status'] === 'Open';

/* Revision state: was this period corrected after it had been finalized? */
$rev = periodRevisionInfo($period_id);

$companyName = getSetting('company_name', 'My Company');
$totGross    = array_sum(array_column($rows, 'gross_pay'));
$totNet      = array_sum(array_column($rows, 'net_pay'));
$totBonus    = array_sum(array_column($rows, 'bonus'));
$totDed      = array_sum(array_column($rows, 'other_deductions'));
$totTax      = array_sum(array_column($rows, 'withholding_tax'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Payslips - Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Marks pay that was changed after the period had been finalized */
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
            <h1>Reports &amp; Payslips</h1>
            <p>Issue payslips &amp; acknowledgement receipts for the period</p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <label for="copyMode" style="font-weight:600;font-size:.8rem;color:#6b7280;">Copies:</label>
            <select id="copyMode" class="form-control" style="width:auto;min-width:180px;">
                <option value="both">Employee + Company copy</option>
                <option value="employee">Employee copy only</option>
                <option value="company">Company copy only</option>
            </select>
            <button class="btn btn-print" onclick="printSummary()">Payroll Register</button>
            <button class="btn btn-print" onclick="exportCSV()">Export CSV</button>
            <button class="btn btn-print" onclick="printAllPayslips()">Print All</button>
        </div>
    </div>

    <!-- Period picker -->
    <div class="box" style="margin-bottom:20px;">
        <div class="box-body" style="padding:14px 20px;">
            <form method="GET" style="display:flex;align-items:center;gap:12px;">
                <label style="font-weight:600;font-size:.875rem;">Period:</label>
                <select name="period" class="form-control" style="min-width:220px;" onchange="this.form.submit()">
                    <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $period_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['period_label']) ?> · <?= htmlspecialchars(periodTypeLabel($p)) ?>
                        <?= $p['status'] === 'Open' ? '- Open' : '- Finalized' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($curPeriod): ?>
                    <span class="badge badge-<?= $isOpen ? 'blue' : 'green' ?>"><?= $isOpen ? 'Open' : 'Finalized' ?></span>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($curPeriod && $isOpen): ?>
        <div class="alert alert-warn">
            <span>
                <strong><?= htmlspecialchars($curPeriod['period_label']) ?> is still open.</strong>
                Bonuses and deductions can still change these amounts, so a payslip issued now may not be final.
                Finalize the period first if you are releasing pay.
            </span>
            <a href="payroll.php?period=<?= $period_id ?>">Finalize in Payroll Processing</a>
        </div>
    <?php elseif ($curPeriod): ?>
        <div class="alert alert-success">
            <span>
                <strong><?= htmlspecialchars($curPeriod['period_label']) ?> is finalized</strong><?php
                ?><?= !empty($curPeriod['finalized_at']) ? ' on ' . date('M j, Y', strtotime($curPeriod['finalized_at'])) : '' ?>.
                These figures are locked - safe to issue and have signed.
            </span>
            <a href="sign-payslip.php?period=<?= $period_id ?>">Collect signatures</a>
        </div>
    <?php endif; ?>

    <?php if ($rev['revised']): ?>
        <!-- Corrected after a finalize: anyone reissuing a payslip needs to know
             which ones replace an earlier copy. -->
        <div class="alert alert-warn">
            <span>
                <strong>This period was re-opened and corrected after it was first finalized.</strong>
                <?= (int)$rev['revised'] ?> payslip(s) marked <strong>Revised</strong> below carry figures that
                replace an earlier copy - reissue those and collect the signature again.
            </span>
            <a href="history.php?period_id=<?= $period_id ?>">See what changed</a>
        </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <?php if (!empty($rows)): ?>
    <div class="card-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:20px;">
        <div class="card card-accent">
            <div class="card-label">Employees</div>
            <div class="card-value"><?= count($rows) ?></div>
        </div>
        <div class="card card-green">
            <div class="card-label">Total Gross</div>
            <div class="card-value">&#8369;<?= number_format($totGross, 0) ?></div>
        </div>
        <div class="card card-accent">
            <div class="card-label">Total Net</div>
            <div class="card-value">&#8369;<?= number_format($totNet, 0) ?></div>
        </div>
        <div class="card card-yellow">
            <div class="card-label">Total Bonus</div>
            <div class="card-value">&#8369;<?= number_format($totBonus, 0) ?></div>
        </div>
        <div class="card card-red">
            <div class="card-label">Total Deductions</div>
            <div class="card-value">&#8369;<?= number_format($totDed, 0) ?></div>
        </div>
        <div class="card card-purple">
            <div class="card-label">Tax Withheld</div>
            <div class="card-value">&#8369;<?= number_format($totTax, 0) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="box">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th><th>Name</th><th>Basic Pay</th><th>OT − Late</th><th>Gross Pay</th>
                        <th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th><th>Tax</th>
                        <th>Bonus</th><th>Deductions</th><th>Net Pay</th><th>Payslip</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="13" style="text-align:center;color:#9ca3af;padding:30px;">No data for this period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <?php $isRev = !empty($r['revised_after_finalize']); ?>
                    <tr class="<?= $isRev ? 'is-revised' : '' ?>">
                        <td><?= htmlspecialchars($r['emp_id']) ?></td>
                        <td>
                            <?= htmlspecialchars($r['emp_name']) ?>
                            <?php if ($isRev): ?>
                                <span class="rev-tag"
                                      title="Pay changed after this period was finalized<?= !empty($r['revised_at']) ? ' - ' . date('M j, Y g:i A', strtotime($r['revised_at'])) : '' ?>">Revised</span>
                            <?php endif; ?>
                        </td>
                        <td>₱<?= number_format($r['gross_pay'] - $r['ot_late_adj'], 2) ?></td>
                        <td>₱<?= number_format($r['ot_late_adj'], 2) ?></td>
                        <td><strong>₱<?= number_format($r['gross_pay'], 2) ?></strong></td>
                        <td>₱<?= number_format($r['sss'], 2) ?></td>
                        <td>₱<?= number_format($r['philhealth'], 2) ?></td>
                        <td>₱<?= number_format($r['pagibig'], 2) ?></td>
                        <td<?= (float)$r['withholding_tax'] < 0 ? ' title="Refund of tax withheld earlier this month"' : '' ?>><?= pesoFmt($r['withholding_tax']) ?></td>
                        <td>₱<?= number_format($r['bonus'], 2) ?></td>
                        <td>₱<?= number_format($r['other_deductions'], 2) ?></td>
                        <td><strong>₱<?= number_format($r['net_pay'], 2) ?></strong></td>
                        <td>
                            <button class="btn btn-print btn-sm" onclick="printPayslip(<?= (int)$r['id'] ?>)">
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

<script>
window.REPORTS_DATA = {
    company:   <?= json_encode($companyName) ?>,
    period:    <?= json_encode($curPeriod['period_label'] ?? '') ?>,
    period_id: <?= $period_id ?>,
    rows:      <?= json_encode($rows) ?>
};
</script>
<script src="assets/js/reports.js"></script>
</body>
</html>
