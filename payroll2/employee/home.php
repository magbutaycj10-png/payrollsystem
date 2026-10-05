<?php
require_once __DIR__ . '/includes/auth.php';
requireEmployee();

/*
 * Landing page shown right after an employee signs in.
 * Answers the two questions people actually log in for — what was my last
 * pay, and where is my leave request — then links out to the full pages.
 */

$activePage = 'home';
$db = getDB();
$e  = emp();

$empInfo = $db->prepare("SELECT * FROM employees WHERE emp_id = ?");
$empInfo->execute([$e['id']]);
$empInfo = $empInfo->fetch();

$latestSt = $db->prepare("
    SELECT p.*, pp.period_label FROM payroll p
    JOIN payroll_periods pp ON pp.id = p.period_id
    WHERE p.emp_id = ?
    ORDER BY pp.period_start DESC, pp.id DESC LIMIT 1
");
$latestSt->execute([$e['id']]);
$latest = $latestSt->fetch();

$totalPayslips = $db->prepare("SELECT COUNT(*) FROM payroll WHERE emp_id = ?");
$totalPayslips->execute([$e['id']]);
$totalPayslips = (int)$totalPayslips->fetchColumn();

$pendingLeave = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE emp_id = ? AND status = 'Pending'");
$pendingLeave->execute([$e['id']]);
$pendingLeave = (int)$pendingLeave->fetchColumn();

$recentLeave = $db->prepare("
    SELECT leave_type, date_from, date_to, status
    FROM leave_requests WHERE emp_id = ?
    ORDER BY created_at DESC LIMIT 3
");
$recentLeave->execute([$e['id']]);
$recentLeave = $recentLeave->fetchAll();

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home — Employee Portal</title>
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

        .lp-split {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
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

        .lp-pay {
            font-size: 2rem; font-weight: 700; color: #16a34a; line-height: 1.15;
        }
        .lp-pay-sub { font-size: .8rem; color: var(--text-muted); margin-bottom: 14px; }
        .lp-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: .84rem; }
        .lp-row:last-child { border-bottom: none; }
        .lp-row span:first-child { color: var(--text-muted); }
        .lp-row span:last-child  { font-weight: 600; }

        .lp-leave { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: .82rem; }
        .lp-leave:last-child { border-bottom: none; }

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

        @media (max-width: 900px) { .lp-split { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">

    <div class="lp-hero">
        <span class="lp-role">Employee</span>
        <h1><?= $greeting ?>, <?= htmlspecialchars($empInfo['full_name'] ?? $e['name']) ?></h1>
        <p>
            <?= htmlspecialchars($empInfo['position'] ?? '') ?><?= (!empty($empInfo['position']) && !empty($empInfo['branch'])) ? ' &nbsp;&middot;&nbsp; ' : '' ?><?= htmlspecialchars($empInfo['branch'] ?? '') ?>
            &nbsp;&middot;&nbsp; <?= date('l, F j, Y') ?>
        </p>
    </div>

    <div class="lp-split">

        <div class="lp-panel">
            <div class="lp-panel-title">
                <h2>Your latest pay</h2>
                <?php if ($latest): ?>
                    <a href="/employee/payslips.php" class="btn btn-ghost btn-sm">View payslip</a>
                <?php endif; ?>
            </div>
            <?php if ($latest): ?>
                <p class="lp-panel-note"><?= htmlspecialchars($latest['period_label']) ?></p>
                <div class="lp-pay">₱<?= number_format($latest['net_pay'], 2) ?></div>
                <div class="lp-pay-sub">Net take-home pay</div>
                <div class="lp-row"><span>Gross pay</span><span>₱<?= number_format($latest['gross_pay'], 2) ?></span></div>
                <div class="lp-row"><span>Hours worked</span><span><?= number_format($latest['hours_worked'], 1) ?> hrs</span></div>
                <div class="lp-row"><span>Overtime</span><span><?= number_format($latest['overtime_hours'], 1) ?> hrs</span></div>
                <div class="lp-row">
                    <span>Status</span>
                    <span class="badge badge-<?= $latest['status'] === 'Finalized' ? 'green' : 'blue' ?>"><?= htmlspecialchars($latest['status']) ?></span>
                </div>
            <?php else: ?>
                <p class="lp-panel-note">No payroll records yet.</p>
                <div class="p-alert p-alert-info" style="margin:0;">
                    Your payslips will appear here once payroll has been processed for you.
                </div>
            <?php endif; ?>
        </div>

        <div class="lp-panel">
            <div class="lp-panel-title">
                <h2>Your leave</h2>
                <a href="/employee/leave.php" class="btn btn-ghost btn-sm">Request leave</a>
            </div>
            <p class="lp-panel-note">
                <?= $pendingLeave > 0
                    ? $pendingLeave . ' request' . ($pendingLeave === 1 ? '' : 's') . ' awaiting a decision.'
                    : 'Nothing pending right now.' ?>
            </p>

            <?php if (empty($recentLeave)): ?>
                <div class="lp-leave" style="color:#9ca3af;">No leave requests filed yet.</div>
            <?php else: ?>
                <?php foreach ($recentLeave as $lr): ?>
                <div class="lp-leave">
                    <span>
                        <?= htmlspecialchars($lr['leave_type']) ?><br>
                        <span style="color:var(--text-muted);font-size:.76rem;">
                            <?= date('M j', strtotime($lr['date_from'])) ?> &ndash; <?= date('M j, Y', strtotime($lr['date_to'])) ?>
                        </span>
                    </span>
                    <span class="badge badge-<?= $lr['status'] === 'Approved' ? 'green' : ($lr['status'] === 'Rejected' ? 'red' : 'yellow') ?>">
                        <?= htmlspecialchars($lr['status']) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="lp-row" style="margin-top:12px;border-top:1px solid var(--border);">
                <span>Payslips on file</span><span><?= $totalPayslips ?></span>
            </div>
        </div>
    </div>

    <div class="lp-section-title">Go to</div>
    <div class="lp-links">
        <a class="lp-link" href="/employee/dashboard.php">
            <b>Dashboard</b><span>Full payslip breakdown</span>
        </a>
        <a class="lp-link" href="/employee/payslips.php">
            <b>Payslips</b><span>History, print and download</span>
        </a>
        <a class="lp-link" href="/employee/leave.php">
            <b>Leave</b><span>File a request, track status</span>
        </a>
        <a class="lp-link" href="/employee/profile.php">
            <b>Profile</b><span>Contact and emergency details</span>
        </a>
    </div>
</div>

</body>
</html>
