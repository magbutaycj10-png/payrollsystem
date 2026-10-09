<?php
require 'includes/helpers.php';
requireAuth();

/*
 * Landing page shown right after an admin signs in.
 * Gives a short read on where the payroll cycle stands and puts the action
 * that starts every cycle - uploading the attendance file - within reach,
 * without turning into a second dashboard.
 */

$activePage = 'home';
$db         = getDB();

$totalEmployees = (int)$db->query("SELECT COUNT(*) FROM employees")->fetchColumn();

$latestPeriod = currentPeriod($db);   /* the period holding today, else the latest by date */

$pendingLeave = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE status='Pending'")->fetchColumn();

/* Attendance activity - tells the admin whether this cycle has been started */
$lastUpload  = $db->query("SELECT MAX(upload_date) FROM attendance")->fetchColumn();
$periodRows  = 0;
$periodNet   = 0.0;
$payrollRows = 0;
if ($latestPeriod) {
    $st = $db->prepare("SELECT COUNT(*) FROM attendance WHERE period_id = ?");
    $st->execute([$latestPeriod['id']]);
    $periodRows = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(net_pay),0) FROM payroll WHERE period_id = ?");
    $st->execute([$latestPeriod['id']]);
    [$payrollRows, $periodNet] = $st->fetch(PDO::FETCH_NUM);
}

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

/* A finalized pay period's status is 'Locked' (payroll_periods.status is Open | Locked) - comparing with 'Finalized' never matched */
$periodLocked = in_array($latestPeriod['status'] ?? '', ['Locked', 'Finalized'], true);

/* Where the cycle currently stands, in one line */
if (!$latestPeriod) {
    $stepText = 'No payroll period yet - create one and upload attendance to begin.';
} elseif ($periodRows === 0) {
    $stepText = 'Next step: upload the attendance file for ' . $latestPeriod['period_label'] . '.';
} elseif ((int)$payrollRows === 0) {
    $stepText = 'Attendance is in. Next step: process payroll for ' . $latestPeriod['period_label'] . '.';
} elseif (!$periodLocked) {
    $stepText = $latestPeriod['period_label'] . ' is computed and waiting to be reviewed and finalized.';
} else {
    $stepText = $latestPeriod['period_label'] . ' is finalized. Upload the next attendance file when the cycle rolls over.';
}

$companyName = getSetting('company_name', 'L&N Pharmacy');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Welcome band */
        .lp-hero {
            background: linear-gradient(120deg, #0f172a 0%, #1e293b 60%, #334155 100%);
            color: #f8fafc;
            border-radius: var(--radius);
            padding: 26px 28px;
            margin-bottom: 22px;
            box-shadow: var(--shadow-sm);
        }
        .lp-hero h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 4px; }
        .lp-hero p  { color: #94a3b8; font-size: .88rem; }
        .lp-role {
            display: inline-block; margin-bottom: 10px;
            font-size: .68rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase;
            color: #bfdbfe; background: rgba(59,130,246,.18);
            padding: 3px 10px; border-radius: 20px;
        }

        /* Two-column body: upload on the left, cycle status on the right */
        .lp-split {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 20px;
            align-items: start;
            margin-bottom: 22px;
        }

        .lp-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 20px 22px;
        }
        .lp-panel-title {
            display: flex; align-items: baseline; justify-content: space-between; gap: 12px;
            margin-bottom: 4px;
        }
        .lp-panel-title h2 { font-size: 1rem; font-weight: 700; }
        .lp-panel-note { font-size: .8rem; color: var(--text-muted); margin-bottom: 14px; }

        /* Drop zone */
        #lpDrop {
            border: 2px dashed #c7d2fe;
            border-radius: 10px;
            padding: 26px 20px;
            text-align: center;
            cursor: pointer;
            background: linear-gradient(145deg, #f8faff 0%, #f0f4ff 100%);
            transition: all .2s ease;
        }
        #lpDrop:hover, #lpDrop.drag-over {
            border-color: #6366f1;
            background: linear-gradient(145deg, #eff0ff 0%, #e0e7ff 100%);
        }
        #lpDrop.busy { border-color: #22c55e; border-style: solid; background: #f0fdf4; cursor: default; }
        #lpDrop .lp-dz-title { display: block; font-size: .95rem; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        #lpDrop .lp-dz-sub   { display: block; font-size: .82rem; color: #64748b; }
        .lp-formats {
            display: inline-block; margin-top: 10px;
            font-size: .7rem; font-weight: 600;
            background: #e0e7ff; color: #4338ca;
            padding: 3px 10px; border-radius: 20px;
        }
        .lp-upload-foot {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap; margin-top: 14px;
            font-size: .78rem; color: var(--text-muted);
        }

        /* Status list */
        .lp-stat { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--border); }
        .lp-stat:last-of-type { border-bottom: none; }
        .lp-stat-label { font-size: .82rem; color: var(--text-muted); }
        .lp-stat-value { font-size: .95rem; font-weight: 700; text-align: right; }
        .lp-next {
            margin-top: 14px; padding: 11px 13px;
            background: #eff6ff; border-radius: 6px;
            font-size: .82rem; color: #1e40af;
        }

        /* Quick links */
        .lp-links {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 14px;
        }
        .lp-link {
            display: block; text-decoration: none; color: inherit;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            box-shadow: var(--shadow-sm);
            transition: transform .15s, box-shadow .15s;
        }
        .lp-link:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .lp-link b    { display: block; font-size: .9rem; margin-bottom: 2px; }
        .lp-link span { font-size: .78rem; color: var(--text-muted); }

        .lp-section-title {
            font-size: .73rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
            color: #94a3b8; margin-bottom: 12px;
        }

        @media (max-width: 900px) { .lp-split { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">

    <div class="lp-hero">
        <span class="lp-role">Administrator</span>
        <h1><?= $greeting ?>, <?= htmlspecialchars($_SESSION['admin']) ?></h1>
        <p><?= htmlspecialchars($companyName) ?> &nbsp;&middot;&nbsp; <?= date('l, F j, Y') ?></p>
    </div>

    <div class="lp-split">

        <!-- Featured action: start the cycle by uploading attendance -->
        <div class="lp-panel">
            <div class="lp-panel-title">
                <h2>Upload attendance</h2>
                <a href="attendance-upload.php" class="btn btn-ghost btn-sm">Open full upload</a>
            </div>
            <p class="lp-panel-note">Drop the timekeeping export here to start a payroll run. Columns are mapped on the next screen.</p>

            <div id="lpDrop">
                <span class="lp-dz-title">Click to browse or drag &amp; drop</span>
                <span class="lp-dz-sub">Attendance export from the biometric or timekeeping system</span>
                <span class="lp-formats">CSV &middot; XLSX &middot; XLS</span>
            </div>
            <input type="file" id="lpFile" accept=".csv,.xlsx,.xls" style="display:none;">

            <div class="lp-upload-foot">
                <span>
                    <?php if ($lastUpload): ?>
                        Last upload: <?= date('M j, Y g:i A', strtotime($lastUpload)) ?>
                    <?php else: ?>
                        No attendance uploaded yet
                    <?php endif; ?>
                </span>
                <a href="attendance-upload.php" style="color:var(--accent);font-weight:600;text-decoration:none;">Need the template? &rarr;</a>
            </div>
        </div>

        <!-- Where things stand -->
        <div class="lp-panel">
            <div class="lp-panel-title"><h2>Where things stand</h2></div>
            <p class="lp-panel-note">Current payroll cycle at a glance.</p>

            <div class="lp-stat">
                <span class="lp-stat-label">Current period</span>
                <span class="lp-stat-value"><?= htmlspecialchars($latestPeriod['period_label'] ?? 'None yet') ?></span>
            </div>
            <div class="lp-stat">
                <span class="lp-stat-label">Period status</span>
                <span class="lp-stat-value">
                    <span class="badge badge-<?= $periodLocked ? 'green' : 'blue' ?>">
                        <?= htmlspecialchars($latestPeriod ? ($periodLocked ? 'Finalized' : 'Open') : '-') ?>
                    </span>
                </span>
            </div>
            <div class="lp-stat">
                <span class="lp-stat-label">Registered employees</span>
                <span class="lp-stat-value"><?= $totalEmployees ?></span>
            </div>
            <div class="lp-stat">
                <span class="lp-stat-label">Attendance rows this period</span>
                <span class="lp-stat-value"><?= number_format($periodRows) ?></span>
            </div>
            <div class="lp-stat">
                <span class="lp-stat-label">Net pay computed</span>
                <span class="lp-stat-value">&#8369;<?= number_format((float)$periodNet, 2) ?></span>
            </div>
            <div class="lp-stat">
                <span class="lp-stat-label">Pending leave requests</span>
                <span class="lp-stat-value"><?= $pendingLeave ?></span>
            </div>

            <div class="lp-next"><?= htmlspecialchars($stepText) ?></div>
        </div>
    </div>

    <div class="lp-section-title">Go to</div>
    <div class="lp-links">
        <a class="lp-link" href="dashboard.php">
            <b>Dashboard</b><span>Totals, trend chart and forecast</span>
        </a>
        <a class="lp-link" href="payroll.php">
            <b>Payroll Processing</b><span>Compute and finalize the period</span>
        </a>
        <a class="lp-link" href="employee.php">
            <b>Employees</b><span>Add, edit and update staff records</span>
        </a>
        <a class="lp-link" href="adjustments.php">
            <b>Bonus &amp; Deductions</b><span>Per-employee or bulk adjustments</span>
        </a>
        <a class="lp-link" href="reports.php">
            <b>Reports &amp; Payslips</b><span>Print payslips, export CSV</span>
        </a>
        <a class="lp-link" href="leave-requests.php">
            <b>Leave Requests</b><span><?= $pendingLeave ?> pending review</span>
        </a>
        <a class="lp-link" href="forecast.php">
            <b>Salary Forecast</b><span>Predictive budget forecasting</span>
        </a>
        <a class="lp-link" href="settings.php">
            <b>Settings</b><span>Rates, company info, password</span>
        </a>
    </div>
</div>

<script>
/*
 * The landing page only *accepts* the file - parsing, column mapping and
 * saving all stay on attendance-upload.php. The picked file is handed over
 * through sessionStorage as a data URL and rebuilt there.
 */
(function () {
    var dz    = document.getElementById('lpDrop');
    var input = document.getElementById('lpFile');
    var title = dz.querySelector('.lp-dz-title');
    var sub   = dz.querySelector('.lp-dz-sub');
    var MAX   = 4 * 1024 * 1024;   /* bigger files just open the upload page */

    dz.addEventListener('click', function () { input.click(); });
    dz.addEventListener('dragover',  function (e) { e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', function () { dz.classList.remove('drag-over'); });
    dz.addEventListener('drop', function (e) {
        e.preventDefault();
        dz.classList.remove('drag-over');
        if (e.dataTransfer.files[0]) hand(e.dataTransfer.files[0]);
    });
    input.addEventListener('change', function (e) {
        if (e.target.files[0]) hand(e.target.files[0]);
    });

    function hand(file) {
        var ext = file.name.split('.').pop().toLowerCase();
        if (['csv', 'xlsx', 'xls'].indexOf(ext) === -1) {
            title.textContent = 'Unsupported file type';
            sub.textContent   = 'Use a .csv, .xlsx or .xls file';
            return;
        }

        dz.classList.add('busy');
        title.textContent = file.name;
        sub.textContent   = 'Opening the upload screen…';

        if (file.size > MAX) { window.location.href = 'attendance-upload.php'; return; }

        var reader = new FileReader();
        reader.onload = function (ev) {
            try {
                sessionStorage.setItem('pendingAttendanceFile',
                    JSON.stringify({ name: file.name, data: ev.target.result }));
            } catch (err) { /* quota exceeded - fall through to a plain redirect */ }
            window.location.href = 'attendance-upload.php';
        };
        reader.onerror = function () { window.location.href = 'attendance-upload.php'; };
        reader.readAsDataURL(file);
    }
})();
</script>
</body>
</html>
