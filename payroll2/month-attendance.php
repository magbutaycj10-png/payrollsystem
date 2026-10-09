<?php
/*
 * month-attendance.php - the admin's view of the month filling up.
 * Every daily upload adds its days; this page shows the month so far for
 * every employee. Body: includes/month-attendance-view.php
 */
require 'includes/helpers.php';
requireAuth();

$activePage = 'month-attendance';
$db = getDB();

$ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$MA     = monthAttendance($db, $ym, null);
$maMode = 'admin';
$maSelf = 'month-attendance.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>This Month's Attendance - Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>This Month's Attendance</h1>
            <p>Every day saved so far - each upload adds its days to the month</p>
        </div>
        <a href="attendance-upload.php" class="btn btn-primary">Upload Today's File</a>
    </div>

    <?php require 'includes/month-attendance-view.php'; ?>
</div>

</body>
</html>
