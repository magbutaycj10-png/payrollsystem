<?php
require_once __DIR__ . '/includes/auth.php';
requireManager();

/*
 * Landing page shown right after a manager signs in.
 * Leads with what is waiting on them - timesheets and leave - then the
 * shortcuts into the rest of the portal. Detail stays on the dashboard.
 */

$activePage = 'home';
$db = getDB();
$m  = mgr();

[$scopeWhere, $scopeParams] = mgrScopeWhere('e');

$empCount = $db->prepare("SELECT COUNT(*) FROM employees e WHERE 1=1 $scopeWhere");
$empCount->execute($scopeParams);
$empCount = (int)$empCount->fetchColumn();

$leaveSt = $db->prepare("
    SELECT COUNT(*) FROM leave_requests lr
    JOIN employees e ON e.emp_id = lr.emp_id
    WHERE lr.status='Pending' $scopeWhere
");
$leaveSt->execute($scopeParams);
$pendingLeave = (int)$leaveSt->fetchColumn();

$openPeriod = currentPeriod($db, true);   /* the open period holding today, else the latest by date */

$pendingTimesheets = 0;
if ($openPeriod) {
    $atSt = $db->prepare("
        SELECT COUNT(*) FROM attendance a
        JOIN employees e ON e.emp_id = a.emp_id
        WHERE a.period_id = ? AND a.manager_approved = 0 $scopeWhere
    ");
    $atSt->execute(array_merge([$openPeriod['id']], $scopeParams));
    $pendingTimesheets = (int)$atSt->fetchColumn();
}

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$toDo     = $pendingTimesheets + $pendingLeave;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <style>
        .lp-hero {
            background: linear-gradient(120deg, #0f172a 0%, #1e293b 60%, #334155 100%);
            color: #f8fafc;
            border-radius: var(--radius);
            padding: 26px 28px;
            margin-bottom: 22px;
            box-shadow: var(--shadow-sm);
        }
        .lp-hero h1 { font-size: 1.45rem; font-weight: 700; margin-bottom: 4px; }
        .lp-hero p  { color: #94a3b8; font-size: .88rem; }
        .lp-role {
            display: inline-block; margin-bottom: 10px;
            font-size: .68rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase;
            color: #bfdbfe; background: rgba(59,130,246,.18);
            padding: 3px 10px; border-radius: 20px;
        }

        .lp-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 20px 22px;
            margin-bottom: 22px;
        }
        .lp-panel h2 { font-size: 1rem; font-weight: 700; margin-bottom: 4px; }
        .lp-panel-note { font-size: .8rem; color: var(--text-muted); margin-bottom: 14px; }

        .lp-task {
            display: flex; align-items: center; justify-content: space-between; gap: 14px;
            padding: 13px 15px; border-radius: 8px; margin-bottom: 10px;
            text-decoration: none; color: inherit;
            border: 1px solid var(--border);
            transition: background .15s, transform .15s;
        }
        .lp-task:last-child { margin-bottom: 0; }
        .lp-task:hover { background: #f8fafc; transform: translateX(2px); }
        .lp-task-open { background: #fffbeb; }
        .lp-task b    { display: block; font-size: .9rem; }
        .lp-task span { font-size: .78rem; color: var(--text-muted); }
        .lp-count {
            font-size: 1.35rem; font-weight: 700; color: #b45309; flex-shrink: 0;
        }
        .lp-task-done .lp-count { color: #16a34a; font-size: .85rem; font-weight: 600; }

        .lp-links {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
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
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">

    <div class="lp-hero">
        <span class="lp-role">Manager</span>
        <h1><?= $greeting ?>, <?= htmlspecialchars($m['name']) ?></h1>
        <p><?= htmlspecialchars(mgrScopeLabel()) ?> &nbsp;&middot;&nbsp; <?= $empCount ?> employee<?= $empCount === 1 ? '' : 's' ?> &nbsp;&middot;&nbsp; <?= date('l, F j, Y') ?></p>
    </div>

    <div class="lp-panel">
        <h2><?= $toDo > 0 ? 'Waiting on you' : 'You are all caught up' ?></h2>
        <p class="lp-panel-note">
            <?= $openPeriod
                ? 'Open period: ' . htmlspecialchars($openPeriod['period_label'])
                : 'No open payroll period right now.' ?>
        </p>

        <a class="lp-task <?= $pendingTimesheets > 0 ? 'lp-task-open' : 'lp-task-done' ?>" href="/manager/timesheets.php">
            <span>
                <b>Timesheet approvals</b>
                <span><?= $pendingTimesheets > 0 ? 'Attendance rows still unapproved' : 'Nothing pending' ?></span>
            </span>
            <span class="lp-count"><?= $pendingTimesheets > 0 ? $pendingTimesheets : 'Clear' ?></span>
        </a>

        <a class="lp-task <?= $pendingLeave > 0 ? 'lp-task-open' : 'lp-task-done' ?>" href="/manager/leave.php">
            <span>
                <b>Leave requests</b>
                <span><?= $pendingLeave > 0 ? 'Requests awaiting your decision' : 'Nothing pending' ?></span>
            </span>
            <span class="lp-count"><?= $pendingLeave > 0 ? $pendingLeave : 'Clear' ?></span>
        </a>
    </div>

    <div class="lp-section-title">Go to</div>
    <div class="lp-links">
        <a class="lp-link" href="/manager/dashboard.php">
            <b>Dashboard</b><span>Team numbers and recent activity</span>
        </a>
        <a class="lp-link" href="/manager/timesheets.php">
            <b>Timesheets</b><span>Review and approve attendance</span>
        </a>
        <a class="lp-link" href="/manager/leave.php">
            <b>Leave Requests</b><span>Approve or reject requests</span>
        </a>
        <a class="lp-link" href="/manager/manual-attendance.php">
            <b>Manual Attendance</b><span>Enter time for missed logs</span>
        </a>
        <a class="lp-link" href="/manager/sign-payslip.php">
            <b>Sign Payslip</b><span>Witness employee payslip signing</span>
        </a>
        <a class="lp-link" href="/manager/employee-profiles.php">
            <b>Employee Profiles</b><span>Contact and emergency details</span>
        </a>
    </div>
</div>

</body>
</html>
