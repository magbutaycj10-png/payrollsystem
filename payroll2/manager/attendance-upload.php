<?php
/*
 * manager/attendance-upload.php
 * The admin's Upload Attendance screen for managers (includes/upload-panel.php).
 * The server limits a manager to their own employees and to Open pay periods;
 * creating a pay period stays with the admin, so there is no create form here.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/upload-panel.php';
requireManager();

$activePage = 'attendance-upload';
$db         = getDB();
$periods    = $db->query("SELECT * FROM payroll_periods WHERE status = 'Open' ORDER BY period_start DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Attendance — Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <?php uploadPanelStyles(); ?>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>Upload Attendance</h1>
            <p>Bring in your employees' hours for a pay period &middot; <?= htmlspecialchars(mgrScopeLabel()) ?></p>
        </div>
    </div>

    <?php uploadPanel([
        'periods'     => $periods,
        'canCreate'   => false,
        'defaultType' => periodType(null),
    ]); ?>
</div>

<?= uploadPageScript($db, mgrEmpIds()) ?>
<script>
/* Endpoints sit one folder up; saved uploads land on Timesheets for approval. */
window.UPLOAD_CFG = { api: '/api/', after: '/manager/timesheets.php', afterLabel: 'Timesheets',
                      defaultSchedule: <?= json_encode(periodType(null)) ?>, createHint: 'ask the admin to create one' };
</script>
<script src="/assets/js/attendance-formats.js"></script>
<script src="/assets/js/attendance-upload.js"></script>
</body>
</html>
