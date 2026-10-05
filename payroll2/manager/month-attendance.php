<?php
/*
 * manager/month-attendance.php — the month so far for the manager's own
 * employees. Body: includes/month-attendance-view.php
 */
require_once __DIR__ . '/includes/auth.php';
requireManager();

$activePage = 'month-attendance';
$db = getDB();

$ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$MA     = monthAttendance($db, $ym, mgrEmpIds());
$maMode = 'manager';
$maSelf = '/manager/month-attendance.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>This Month's Attendance — Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>This Month's Attendance</h1>
            <p><?= htmlspecialchars(mgrScopeLabel()) ?> — every day saved so far</p>
        </div>
        <a href="/manager/attendance-upload.php" class="btn btn-primary">Upload Today's File</a>
    </div>

    <?php require __DIR__ . '/../includes/month-attendance-view.php'; ?>
</div>

</body>
</html>
