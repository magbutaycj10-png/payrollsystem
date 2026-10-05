<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'print-history';
$db         = getDB();

$logs = $db->query("
    SELECT pl.*, pp.period_label
    FROM print_log pl
    LEFT JOIN payroll_periods pp ON pl.period_id = pp.id
    ORDER BY pl.log_datetime DESC
    LIMIT 100
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print History — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Print History</h1>
            <p>Audit log of all printed documents and exports</p>
        </div>
        <button class="btn btn-print no-print" onclick="window.open('print-doc.php?doc=printlog','_blank','width=980,height=760')">Print All</button>
    </div>

    <div class="box">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th><th>Date &amp; Time</th><th>Document Name</th>
                        <th>Type</th><th>Period</th><th>Printed By</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:#9ca3af;padding:30px;">No print records yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $i => $l): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= date('M d, Y — h:i A', strtotime($l['log_datetime'])) ?></td>
                        <td><?= htmlspecialchars($l['document_name']) ?></td>
                        <td>
                            <span class="badge badge-<?= $l['document_type'] === 'PDF Export' ? 'blue' : 'green' ?>">
                                <?= htmlspecialchars($l['document_type']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($l['period_label']): ?>
                                <span class="badge badge-yellow"><?= htmlspecialchars($l['period_label']) ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($l['printed_by'] ?? '') ?: '&mdash;' ?></td>
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
