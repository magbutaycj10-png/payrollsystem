<?php
require_once __DIR__ . '/includes/auth.php';
requireManager();

/*
 * Team analytics for the open payroll period.
 *
 * Home answers "what is waiting on me" — counts, to-dos, shortcuts.
 * This page deliberately answers a different question: "how is my team
 * tracking" — rates, totals and trends. Nothing here is a to-do list.
 */

$activePage = 'dashboard';
$db  = getDB();
$m   = mgr();

[$scopeWhere, $scopeParams] = mgrScopeWhere('e');

$empCount = $db->prepare("SELECT COUNT(*) FROM employees e WHERE 1=1 $scopeWhere");
$empCount->execute($scopeParams);
$empCount = (int)$empCount->fetchColumn();

/* The period everything on this page is measured against */
$period      = currentPeriod($db, true);
$periodId    = $period['id'] ?? null;
$periodLabel = $period['period_label'] ?? null;

/* ── Attendance totals for the open period ────────────────────────────── */
$attTotal = $attApproved = 0;
$sumHours = $sumOt = $sumLate = 0.0;
$people   = 0;
if ($periodId) {
    $st = $db->prepare("
        SELECT COUNT(*)                              AS rows_total,
               COALESCE(SUM(a.manager_approved),0)   AS approved,
               COALESCE(SUM(a.hours_worked),0)       AS hours,
               COALESCE(SUM(a.overtime_hours),0)     AS ot,
               COALESCE(SUM(a.late_hours),0)         AS late_h,
               COUNT(DISTINCT a.emp_id)              AS people
        FROM attendance a
        JOIN employees e ON e.emp_id = a.emp_id
        WHERE a.period_id = ? $scopeWhere
    ");
    $st->execute(array_merge([$periodId], $scopeParams));
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $attTotal    = (int)($r['rows_total'] ?? 0);
    $attApproved = (int)($r['approved']   ?? 0);
    $sumHours    = (float)($r['hours']    ?? 0);
    $sumOt       = (float)($r['ot']       ?? 0);
    $sumLate     = (float)($r['late_h']   ?? 0);
    $people      = (int)($r['people']     ?? 0);
}
$approvalPct = $attTotal ? round($attApproved / $attTotal * 100) : 0;
/* attendance.hours_worked is regular time only — overtime is kept apart from it */
$avgHours    = $people ? ($sumHours + $sumOt) / $people : 0;
$otShare     = ($sumHours + $sumOt) ? round($sumOt / ($sumHours + $sumOt) * 100, 1) : 0;

/* ── Team hours across the last six periods — the trend ───────────────── */
$trendSt = $db->prepare("
    SELECT pp.period_label,
           COALESCE(SUM(a.hours_worked),0)   AS hours,
           COALESCE(SUM(a.overtime_hours),0) AS ot
    FROM payroll_periods pp
    JOIN attendance a ON a.period_id = pp.id
    JOIN employees  e ON e.emp_id    = a.emp_id
    WHERE 1=1 $scopeWhere
    GROUP BY pp.id, pp.period_label, pp.period_start
    ORDER BY pp.period_start DESC
    LIMIT 6
");
$trendSt->execute($scopeParams);
$trend = array_reverse($trendSt->fetchAll());

/* ── Hours logged per employee this period ────────────────────────────── */
$teamHours = [];
if ($periodId) {
    $th = $db->prepare("
        SELECT e.emp_id, e.full_name, e.position,
               COALESCE(SUM(a.hours_worked),0)     AS hours,
               COALESCE(SUM(a.overtime_hours),0)   AS ot,
               COUNT(a.id)                         AS rows_logged,
               COALESCE(SUM(a.manager_approved),0) AS approved
        FROM employees e
        LEFT JOIN attendance a ON a.emp_id = e.emp_id AND a.period_id = ?
        WHERE 1=1 $scopeWhere
        GROUP BY e.emp_id, e.full_name, e.position
        ORDER BY hours DESC
        LIMIT 8
    ");
    $th->execute(array_merge([$periodId], $scopeParams));
    $teamHours = $th->fetchAll();
}
$maxHours = 0;
foreach ($teamHours as $t) { $maxHours = max($maxHours, (float)$t['hours']); }

/* ── Leave: outcome mix, days taken this year, who is out today ───────── */
$leaveSplit = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
$ls = $db->prepare("
    SELECT lr.status, COUNT(*) AS c
    FROM leave_requests lr
    JOIN employees e ON e.emp_id = lr.emp_id
    WHERE 1=1 $scopeWhere
    GROUP BY lr.status
");
$ls->execute($scopeParams);
foreach ($ls->fetchAll() as $row) { $leaveSplit[$row['status']] = (int)$row['c']; }
$leaveTotal = array_sum($leaveSplit);

$ldSt = $db->prepare("
    SELECT lr.date_from, lr.date_to, e.rest_days
    FROM leave_requests lr
    JOIN employees e ON e.emp_id = lr.emp_id
    WHERE lr.status = 'Approved' AND YEAR(lr.date_from) = YEAR(CURDATE()) $scopeWhere
");
$ldSt->execute($scopeParams);
$leaveDaysYtd = 0;   /* duty days: each employee's days off are not leave */
foreach ($ldSt->fetchAll() as $l) $leaveDaysYtd += leaveDays($l['date_from'], $l['date_to'], $l['rest_days'])['duty'];

$onLeaveToday = $db->prepare("
    SELECT lr.emp_name, lr.leave_type, lr.date_to
    FROM leave_requests lr
    JOIN employees e ON e.emp_id = lr.emp_id
    WHERE lr.status = 'Approved' AND CURDATE() BETWEEN lr.date_from AND lr.date_to $scopeWhere
    ORDER BY lr.date_to ASC LIMIT 5
");
$onLeaveToday->execute($scopeParams);
$onLeaveToday = $onLeaveToday->fetchAll();

$recentLeave = $db->prepare("
    SELECT lr.* FROM leave_requests lr
    JOIN employees e ON e.emp_id = lr.emp_id
    WHERE 1=1 $scopeWhere
    ORDER BY lr.created_at DESC LIMIT 6
");
$recentLeave->execute($scopeParams);
$recentLeave = $recentLeave->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        /* ── Metric strip ──────────────────────────────────────────────
         * Denser and flatter than the .p-card grid on purpose, so the
         * dashboard does not read like the home page at a glance.
         */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 22px;
        }
        .kpi {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 15px;
            box-shadow: var(--shadow-sm);
        }
        .kpi-label {
            font-size: .66rem; font-weight: 700; letter-spacing: .07em;
            text-transform: uppercase; color: #94a3b8; margin-bottom: 6px;
        }
        .kpi-value { font-size: 1.4rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; }
        .kpi-sub   { font-size: .71rem; color: var(--text-muted); margin-top: 4px; }
        .kpi-pill {
            display: inline-block; margin-top: 6px;
            font-size: .64rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
            padding: 2px 8px; border-radius: 20px; background: #f1f5f9; color: #64748b;
        }
        .kpi-pill-ok   { background: #dcfce7; color: #166534; }
        .kpi-pill-warn { background: #fef3c7; color: #92400e; }

        /* Two-up rows that collapse on tablets */
        .dash-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; margin-bottom: 22px; }
        .dash-row-wide { grid-template-columns: 1.3fr 1fr; }

        /* Approval progress */
        .prog-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 8px; }
        .prog-pct { font-size: 1.6rem; font-weight: 700; color: #16a34a; }
        .prog-pct-open { color: #b45309; }
        .prog-bar { height: 10px; background: #eef2f7; border-radius: 20px; overflow: hidden; }
        .prog-fill { height: 100%; border-radius: 20px; background: linear-gradient(90deg, #16a34a, #4ade80); transition: width .4s ease; }
        .prog-fill-open { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .prog-note { font-size: .78rem; color: var(--text-muted); margin-top: 8px; }
        .prog-split { display: flex; gap: 10px; margin-top: 16px; }
        .prog-split div { flex: 1; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; }
        .prog-split b { display: block; font-size: 1.05rem; font-weight: 700; }
        .prog-split span { font-size: .7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }

        /* Ranked hour bars */
        .rank-row { padding: 9px 0; border-bottom: 1px solid var(--border); }
        .rank-row:last-child { border-bottom: none; }
        .rank-top { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 5px; font-size: .85rem; }
        .rank-name  { font-weight: 600; }
        .rank-meta  { color: var(--text-muted); font-size: .75rem; }
        .rank-value { font-weight: 700; white-space: nowrap; }
        .rank-bar   { height: 7px; background: #eef2f7; border-radius: 20px; overflow: hidden; }
        .rank-fill  { height: 100%; border-radius: 20px; background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .rank-fill-amber { background: linear-gradient(90deg, #f59e0b, #fbbf24); }

        .chart-wrap { height: 240px; }
        .chart-wrap-sm { height: 190px; }

        .leave-today {
            display: flex; justify-content: space-between; gap: 10px;
            padding: 9px 0; border-bottom: 1px solid var(--border); font-size: .83rem;
        }
        .leave-today:last-child { border-bottom: none; }

        @media (max-width: 1200px) { .kpi-strip { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 900px)  { .dash-row, .dash-row-wide { grid-template-columns: 1fr; } }
        @media (max-width: 640px)  { .kpi-strip { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>Dashboard</h1>
            <p>
                Team analytics &nbsp;&middot;&nbsp; <?= htmlspecialchars(mgrScopeLabel()) ?>
                &nbsp;&middot;&nbsp; <?= htmlspecialchars($periodLabel ?? 'No open period') ?>
            </p>
        </div>
        <a href="/manager/manual-attendance.php" class="btn btn-primary">+ Manual Attendance</a>
    </div>

    <!-- Derived measures for the open period -->
    <div class="kpi-strip">
        <div class="kpi">
            <div class="kpi-label">Team Size</div>
            <div class="kpi-value"><?= $empCount ?></div>
            <div class="kpi-sub"><?= $people ?> with hours logged</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Approval Rate</div>
            <div class="kpi-value"><?= $approvalPct ?>%</div>
            <span class="kpi-pill <?= $approvalPct >= 100 ? 'kpi-pill-ok' : 'kpi-pill-warn' ?>">
                <?= $approvalPct >= 100 ? 'Complete' : number_format($attTotal - $attApproved) . ' left' ?>
            </span>
        </div>
        <div class="kpi">
            <div class="kpi-label">Hours Logged</div>
            <div class="kpi-value"><?= number_format($sumHours + $sumOt, 1) ?></div>
            <div class="kpi-sub">regular + overtime, <?= number_format($attTotal) ?> record(s)</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Overtime</div>
            <div class="kpi-value"><?= number_format($sumOt, 1) ?></div>
            <div class="kpi-sub"><?= $otShare ?>% of hours worked</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Avg / Employee</div>
            <div class="kpi-value"><?= number_format($avgHours, 1) ?></div>
            <div class="kpi-sub"><?= number_format($sumLate, 1) ?> late hour(s) team-wide</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Out Today</div>
            <div class="kpi-value"><?= count($onLeaveToday) ?></div>
            <div class="kpi-sub"><?= $leaveDaysYtd ?> leave day(s) taken in <?= date('Y') ?></div>
        </div>
    </div>

    <!-- Sign-off progress next to the leave outcome mix -->
    <div class="dash-row dash-row-wide">
        <div class="p-box">
            <div class="p-box-header">
                <h2>Timesheet Approval</h2>
                <span class="badge badge-blue"><?= htmlspecialchars($periodLabel ?? 'No open period') ?></span>
            </div>
            <div class="p-box-body">
                <?php if ($attTotal === 0): ?>
                    <div class="p-alert p-alert-info" style="margin:0;">
                        No attendance has been uploaded for your team in this period yet.
                    </div>
                <?php else: ?>
                    <div class="prog-head">
                        <span><strong><?= number_format($attApproved) ?></strong> of <?= number_format($attTotal) ?> records approved</span>
                        <span class="prog-pct <?= $approvalPct < 100 ? 'prog-pct-open' : '' ?>"><?= $approvalPct ?>%</span>
                    </div>
                    <div class="prog-bar">
                        <div class="prog-fill <?= $approvalPct < 100 ? 'prog-fill-open' : '' ?>" style="width:<?= $approvalPct ?>%"></div>
                    </div>
                    <div class="prog-split">
                        <div><b><?= number_format($attApproved) ?></b><span>Signed off</span></div>
                        <div><b><?= number_format($attTotal - $attApproved) ?></b><span>Outstanding</span></div>
                        <div><b><?= number_format($sumHours, 0) ?></b><span>Hours covered</span></div>
                    </div>
                    <div class="prog-note">
                        <?= $approvalPct >= 100
                            ? 'Everything is signed off — payroll can be processed for your team.'
                            : 'Payroll stays on hold until the remaining records are approved.' ?>
                        <a href="/manager/timesheets.php" style="font-weight:700;color:var(--accent);text-decoration:none;">Open timesheets &rarr;</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-box">
            <div class="p-box-header">
                <h2>Leave Outcomes</h2>
                <span class="badge badge-blue"><?= $leaveTotal ?> total</span>
            </div>
            <div class="p-box-body">
                <?php if ($leaveTotal === 0): ?>
                    <div class="p-alert p-alert-info" style="margin:0;">No leave requests on file for your team.</div>
                <?php else: ?>
                    <div class="chart-wrap-sm"><canvas id="leaveChart"></canvas></div>
                <?php endif; ?>

                <div class="rank-meta" style="margin:14px 0 6px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">
                    On leave today
                </div>
                <?php if (empty($onLeaveToday)): ?>
                    <div class="leave-today" style="color:#9ca3af;">Everyone is in today.</div>
                <?php else: ?>
                    <?php foreach ($onLeaveToday as $ol): ?>
                    <div class="leave-today">
                        <span><?= htmlspecialchars($ol['emp_name']) ?> <span class="rank-meta">&middot; <?= htmlspecialchars($ol['leave_type']) ?></span></span>
                        <span class="rank-meta">back <?= date('M j', strtotime($ol['date_to'] . ' +1 day')) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- How the team's hours have moved period over period -->
    <div class="p-box" style="margin-bottom:22px;">
        <div class="p-box-header">
            <h2>Team Hours Trend</h2>
            <span class="badge badge-blue">Last <?= count($trend) ?> period(s)</span>
        </div>
        <div class="p-box-body">
            <?php if (count($trend) < 2): ?>
                <div class="p-alert p-alert-info" style="margin:0;">
                    At least two periods of attendance are needed before a trend can be drawn.
                </div>
            <?php else: ?>
                <div class="chart-wrap"><canvas id="hoursChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Who carried the hours this period -->
    <div class="p-box" style="margin-bottom:22px;">
        <div class="p-box-header">
            <h2>Hours by Employee</h2>
            <a href="/manager/timesheets.php" class="btn btn-ghost btn-sm">View timesheets</a>
        </div>
        <div class="p-box-body">
            <?php if (empty($teamHours)): ?>
                <div class="p-alert p-alert-info" style="margin:0;">
                    No hours logged yet<?= $periodLabel ? ' for ' . htmlspecialchars($periodLabel) : '' ?>.
                </div>
            <?php else: ?>
                <?php foreach ($teamHours as $t): ?>
                <div class="rank-row">
                    <div class="rank-top">
                        <span>
                            <span class="rank-name"><?= htmlspecialchars($t['full_name']) ?></span>
                            <span class="rank-meta">&nbsp;<?= htmlspecialchars($t['position'] ?: $t['emp_id']) ?><?= $t['ot'] > 0 ? ' &middot; ' . number_format($t['ot'], 1) . ' h OT' : '' ?></span>
                        </span>
                        <span class="rank-value">
                            <?= number_format($t['hours'], 1) ?> h
                            <?php if ((int)$t['rows_logged'] > 0 && (int)$t['approved'] < (int)$t['rows_logged']): ?>
                                <span class="badge badge-yellow" style="margin-left:6px;">unapproved</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="rank-bar">
                        <div class="rank-fill <?= (int)$t['approved'] < (int)$t['rows_logged'] ? 'rank-fill-amber' : '' ?>"
                             style="width:<?= $maxHours ? max(3, round($t['hours'] / $maxHours * 100)) : 3 ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="p-box">
        <div class="p-box-header">
            <h2>Recent Leave Activity</h2>
            <a href="/manager/leave.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="p-table-wrap">
            <table class="p-table">
                <thead>
                    <tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php if (empty($recentLeave)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:24px;">No leave requests yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentLeave as $lr): ?>
                    <tr>
                        <td><?= htmlspecialchars($lr['emp_name']) ?> <small style="color:#9ca3af;">(<?= $lr['emp_id'] ?>)</small></td>
                        <td><?= htmlspecialchars($lr['leave_type']) ?></td>
                        <td><?= date('M d, Y', strtotime($lr['date_from'])) ?></td>
                        <td><?= date('M d, Y', strtotime($lr['date_to'])) ?></td>
                        <td><?= (int)((strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400) + 1 ?></td>
                        <td>
                            <span class="badge badge-<?= $lr['status'] === 'Approved' ? 'green' : ($lr['status'] === 'Rejected' ? 'red' : 'yellow') ?>">
                                <?= $lr['status'] ?>
                            </span>
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
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.color = '#6b7280';

<?php if (count($trend) >= 2): ?>
new Chart(document.getElementById('hoursChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($trend, 'period_label')) ?>,
        datasets: [
            {
                label: 'Regular hours',
                data: <?= json_encode(array_map(fn($r) => round((float)$r['hours'], 1), $trend)) ?>,
                backgroundColor: '#3b82f6',
                borderRadius: 5,
                stack: 'h'
            },
            {
                label: 'Overtime',
                data: <?= json_encode(array_map(fn($r) => round((float)$r['ot'], 1), $trend)) ?>,
                backgroundColor: '#f59e0b',
                borderRadius: 5,
                stack: 'h'
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true, pointStyle: 'circle' } } },
        scales: {
            x: { stacked: true, grid: { display: false } },
            y: { stacked: true, beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { callback: v => v + ' h' } }
        }
    }
});
<?php endif; ?>

<?php if ($leaveTotal > 0): ?>
new Chart(document.getElementById('leaveChart'), {
    type: 'doughnut',
    data: {
        labels: ['Pending', 'Approved', 'Rejected'],
        datasets: [{
            data: [<?= $leaveSplit['Pending'] ?>, <?= $leaveSplit['Approved'] ?>, <?= $leaveSplit['Rejected'] ?>],
            backgroundColor: ['#fbbf24', '#22c55e', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, pointStyle: 'circle', padding: 14 } } }
    }
});
<?php endif; ?>
</script>

</body>
</html>
