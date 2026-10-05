<?php
/*
 * employee/month-attendance.php — the logged-in employee's own month so far,
 * day by day, as their attendance gets uploaded.
 * Body: includes/month-attendance-view.php
 */
require_once __DIR__ . '/includes/auth.php';
requireEmployee();

$activePage = 'month-attendance';
$db = getDB();
$e  = emp();

$ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$MA     = monthAttendance($db, $ym, [$e['id']], false);
$maMode = 'employee';
$maSelf = '/employee/month-attendance.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance — Employee Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>My Attendance</h1>
            <p>Your days this month, as they are recorded</p>
        </div>
    </div>

    <?php require __DIR__ . '/../includes/month-attendance-view.php'; ?>
</div>

</body>
</html>
