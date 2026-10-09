<?php
require 'includes/helpers.php';
require 'includes/upload-panel.php';
requireAuth();

$activePage  = 'attendance-upload';
$db          = getDB();
$periods     = $db->query("SELECT * FROM payroll_periods ORDER BY period_start DESC")->fetchAll();
/* Default schedule for a new period (Settings); each period then keeps its own */
$defaultType = periodType(null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Attendance - Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/employee.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <?php uploadPanelStyles(); ?>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Upload Attendance</h1>
            <p>Bring in the hours for a pay period - then review and finalize it in Payroll Processing.</p>
        </div>
    </div>

    <?php uploadPanel([
        'periods'     => $periods,
        'canCreate'   => true,
        'defaultType' => $defaultType,
    ]); ?>
</div>

<?= uploadPageScript($db, null) ?>
<script>window.UPLOAD_CFG = { defaultSchedule: <?= json_encode($defaultType) ?> };</script>
<script src="assets/js/attendance-formats.js"></script>
<script src="assets/js/attendance-upload.js"></script>
<script>
/*
 * A file picked on the admin landing page (home.php) is handed over through
 * sessionStorage as a data URL. Rebuild it into a File and feed it to the
 * normal handler so mapping and preview behave exactly as a direct pick.
 */
(function () {
    var raw = null;
    try { raw = sessionStorage.getItem('pendingAttendanceFile'); } catch (e) { return; }
    if (!raw) return;
    try { sessionStorage.removeItem('pendingAttendanceFile'); } catch (e) {}

    var payload;
    try { payload = JSON.parse(raw); } catch (e) { return; }
    if (!payload || !payload.data || !payload.name) return;

    fetch(payload.data)
        .then(function (res) { return res.blob(); })
        .then(function (blob) { handleFile(new File([blob], payload.name)); })
        .catch(function () { /* ignore - the file can still be picked here */ });
})();
</script>
</body>
</html>
