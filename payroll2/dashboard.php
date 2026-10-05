<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'dashboard';
$db         = getDB();

/* Most recent payroll period (by insertion order) */
$latestPeriodRow   = $db->query("SELECT id, period_label, status FROM payroll_periods ORDER BY id DESC LIMIT 1")->fetch();
$latestPeriod      = $latestPeriodRow['id']           ?? null;
$latestPeriodLabel = $latestPeriodRow['period_label'] ?? null;

/* Aggregate totals for the latest period, including bonuses and deductions */
$totals = ['headcount'=>0,'total_gross'=>0,'total_net'=>0,'total_tax'=>0,
           'total_ot'=>0,'total_late'=>0,'total_bonus'=>0,'total_ded'=>0];
if ($latestPeriod) {
    $t = $db->prepare("
        SELECT
            COUNT(DISTINCT emp_id)              AS headcount,
            COALESCE(SUM(gross_pay),0)          AS total_gross,
            COALESCE(SUM(net_pay),0)            AS total_net,
            COALESCE(SUM(withholding_tax),0)    AS total_tax,
            COALESCE(SUM(overtime_hours),0)     AS total_ot,
            COALESCE(SUM(late_hours),0)         AS total_late,
            COALESCE(SUM(bonus),0)              AS total_bonus,
            COALESCE(SUM(other_deductions),0)   AS total_ded
        FROM payroll WHERE period_id = ?
    ");
    $t->execute([$latestPeriod]);
    $totals = $t->fetch() ?: $totals;
}

/*
 * Payroll trend data for the chart and linear-regression forecast.
 * Uses ALL periods (Open + Locked) — no longer requires Finalized status —
 * so the prediction works as soon as attendance is uploaded.
 */
$monthly = $db->query("
    SELECT pp.id, pp.period_label, pp.period_start,
           COALESCE(SUM(p.net_pay),0)   AS total_net,
           COALESCE(SUM(p.gross_pay),0) AS total_gross
    FROM payroll_periods pp
    LEFT JOIN payroll p ON p.period_id = pp.id
    GROUP BY pp.id
    HAVING total_net > 0
    ORDER BY pp.period_start ASC, pp.id ASC
    LIMIT 6
")->fetchAll();

/* Linear regression on the net pay series to forecast next month */
$nets      = array_map('floatval', array_column($monthly, 'total_net'));
$n         = count($nets);
$predicted = 0;
if ($n >= 2) {
    $xm = ($n - 1) / 2;
    $ym = array_sum($nets) / $n;
    $num = $den = 0;
    foreach ($nets as $i => $y) {
        $num += ($i - $xm) * ($y - $ym);
        $den += ($i - $xm) ** 2;
    }
    $slope     = $den ? $num / $den : 0;
    $predicted = max(0, $ym + $slope * ($n - $xm));
}

/* Latest 8 payroll records shown in the recent-activity table */
$recentPayroll = [];
if ($latestPeriod) {
    $rq = $db->prepare("
        SELECT p.emp_id, p.emp_name, p.hours_worked, p.gross_pay, p.bonus,
               p.other_deductions, p.net_pay, p.status, pp.period_label
        FROM payroll p
        JOIN payroll_periods pp ON p.period_id = pp.id
        WHERE p.period_id = ?
        ORDER BY p.emp_name ASC
        LIMIT 8
    ");
    $rq->execute([$latestPeriod]);
    $recentPayroll = $rq->fetchAll();
}

$totalEmployeesDB = (int)$db->query("SELECT COUNT(*) FROM employees")->fetchColumn();

/* ── Cycle pipeline ───────────────────────────────────────────────────────
 * Four checkpoints every period goes through. Each one is either done,
 * in progress, or not started, so the admin can see at a glance what is
 * left to do without opening three other pages.
 */
$attRows = $attApproved = $payrollRows = 0;
$periodStatus = $latestPeriodRow['status'] ?? null;
if ($latestPeriod) {
    $q = $db->prepare("SELECT COUNT(*), COALESCE(SUM(manager_approved),0) FROM attendance WHERE period_id = ?");
    $q->execute([$latestPeriod]);
    [$attRows, $attApproved] = array_map('intval', $q->fetch(PDO::FETCH_NUM));

    $q = $db->prepare("SELECT COUNT(*) FROM payroll WHERE period_id = ?");
    $q->execute([$latestPeriod]);
    $payrollRows = (int)$q->fetchColumn();
}
$approvalPct = $attRows ? round($attApproved / $attRows * 100) : 0;
$pipeline = [
    ['label' => 'Attendance uploaded', 'note' => number_format($attRows) . ' row(s)',            'done' => $attRows > 0],
    ['label' => 'Timesheets approved', 'note' => $approvalPct . '% approved by managers',        'done' => $attRows > 0 && $attApproved >= $attRows],
    ['label' => 'Payroll computed',    'note' => number_format($payrollRows) . ' employee(s)',   'done' => $payrollRows > 0],
    ['label' => 'Period finalized',    'note' => $periodStatus ?: 'No period yet',               'done' => $periodStatus === 'Finalized'],
];

/* Where the money goes — feeds the composition doughnut */
$composition = ['net' => 0.0, 'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'tax' => 0.0, 'other' => 0.0];
if ($latestPeriod) {
    $c = $db->prepare("
        SELECT COALESCE(SUM(net_pay),0)          AS net,
               COALESCE(SUM(sss),0)              AS sss,
               COALESCE(SUM(philhealth),0)       AS philhealth,
               COALESCE(SUM(pagibig),0)          AS pagibig,
               COALESCE(SUM(withholding_tax),0)  AS tax,
               COALESCE(SUM(other_deductions),0) AS other
        FROM payroll WHERE period_id = ?
    ");
    $c->execute([$latestPeriod]);
    $composition = array_map('floatval', $c->fetch(PDO::FETCH_ASSOC) ?: $composition);
}
$totalDeductions = $composition['sss'] + $composition['philhealth'] + $composition['pagibig']
                 + $composition['tax'] + $composition['other'];

/* Highest paid this period — bars are drawn relative to the top row */
$topEarners = [];
if ($latestPeriod) {
    $te = $db->prepare("
        SELECT emp_id, emp_name, net_pay, overtime_hours
        FROM payroll WHERE period_id = ? AND net_pay > 0
        ORDER BY net_pay DESC LIMIT 5
    ");
    $te->execute([$latestPeriod]);
    $topEarners = $te->fetchAll();
}
$topNet = $topEarners ? (float)$topEarners[0]['net_pay'] : 0;

/* Headcount and payroll cost per branch */
$byBranch = [];
if ($latestPeriod) {
    $bb = $db->prepare("
        SELECT CASE WHEN e.branch IS NULL OR e.branch = '' THEN 'Unassigned' ELSE e.branch END AS branch,
               COUNT(DISTINCT e.emp_id)         AS headcount,
               COALESCE(SUM(p.net_pay),0)       AS net
        FROM employees e
        LEFT JOIN payroll p ON p.emp_id = e.emp_id AND p.period_id = ?
        GROUP BY branch
        ORDER BY net DESC, headcount DESC
        LIMIT 6
    ");
    $bb->execute([$latestPeriod]);
    $byBranch = $bb->fetchAll();
} else {
    $byBranch = $db->query("
        SELECT CASE WHEN branch IS NULL OR branch = '' THEN 'Unassigned' ELSE branch END AS branch,
               COUNT(*) AS headcount, 0 AS net
        FROM employees GROUP BY branch ORDER BY headcount DESC LIMIT 6
    ")->fetchAll();
}
$branchMaxNet = 0;
foreach ($byBranch as $b) { $branchMaxNet = max($branchMaxNet, (float)$b['net']); }

/* Recent activity — adjustments and finalize/reopen events, newest first */
$activity = [];
try {
    $activity = $db->query("
        SELECT * FROM (
            SELECT h.created_at AS at,
                   CONCAT(h.entry_type, ' for ', h.emp_name) AS what,
                   CONCAT(FORMAT(h.amount, 2), ' by ', COALESCE(NULLIF(h.processed_by,''), 'admin')) AS detail,
                   h.entry_type AS kind
            FROM bonus_deduction_history h
            UNION ALL
            SELECT a.created_at AS at,
                   CONCAT(pp.period_label, ' ', a.action) AS what,
                   COALESCE(NULLIF(a.performed_by,''), 'system') AS detail,
                   a.action AS kind
            FROM period_audit a
            LEFT JOIN payroll_periods pp ON pp.id = a.period_id
        ) feed
        ORDER BY at DESC LIMIT 6
    ")->fetchAll();
} catch (PDOException $e) { $activity = []; }

/* Things that still need a person to act */
$pendingLeaveCount = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE status='Pending'")->fetchColumn();
$unapprovedRows    = max(0, $attRows - $attApproved);

/* Averages, shown under the headline numbers */
$avgNet = $totals['headcount'] ? $totals['total_net'] / $totals['headcount'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        /* Animated highlight on the prediction card when it has a value */
        @keyframes pulse-blue {
            0%   { box-shadow: 0 0 0 0 rgba(100,116,139,.35); }
            70%  { box-shadow: 0 0 0 10px rgba(100,116,139,0); }
            100% { box-shadow: 0 0 0 0 rgba(100,116,139,0); }
        }
        .card-predict-active { animation: pulse-blue 1.8s ease-out 0.4s 2; }

        /* ── Cycle pipeline ── */
        .pipeline {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 28px;
        }
        .pipe-step {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            box-shadow: var(--shadow-sm);
        }
        .pipe-step.current { background: #fffdf5; border-color: #fde68a; }
        .pipe-no {
            font-size: .68rem; font-weight: 700; letter-spacing: .08em;
            text-transform: uppercase; color: #94a3b8; margin-bottom: 4px;
        }
        .pipe-label { font-size: .92rem; font-weight: 700; margin-bottom: 2px; }
        .pipe-note  { font-size: .76rem; color: var(--text-muted); }
        .pipe-state {
            display: inline-block; margin-top: 8px;
            font-size: .68rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
            padding: 2px 8px; border-radius: 20px;
            background: #f1f5f9; color: #64748b;
        }
        .pipe-step.done    .pipe-state { background: #dcfce7; color: #166534; }
        .pipe-step.current .pipe-state { background: #fef3c7; color: #92400e; }

        /* ── Two-up rows ── */
        .dash-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
            align-items: start;
        }
        .dash-row-wide { grid-template-columns: 1.25fr 1fr; }

        /* ── Ranked bar lists (top earners, branches) ── */
        .rank-row { padding: 10px 0; border-bottom: 1px solid var(--border); }
        .rank-row:last-child { border-bottom: none; }
        .rank-top {
            display: flex; justify-content: space-between; align-items: baseline;
            gap: 12px; margin-bottom: 6px; font-size: .86rem;
        }
        .rank-name { font-weight: 600; }
        .rank-meta { color: var(--text-muted); font-size: .76rem; }
        .rank-value { font-weight: 700; white-space: nowrap; }
        .rank-bar { height: 7px; background: #eef2f7; border-radius: 20px; overflow: hidden; }
        .rank-fill { height: 100%; border-radius: 20px; background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .rank-fill-green { background: linear-gradient(90deg, #16a34a, #4ade80); }

        /* ── Activity feed ── */
        .feed-item {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 11px 0; border-bottom: 1px solid var(--border);
        }
        .feed-item:last-child { border-bottom: none; }
        .feed-dot {
            width: 9px; height: 9px; border-radius: 50%;
            background: #94a3b8; margin-top: 6px; flex-shrink: 0;
        }
        .feed-dot-green  { background: #22c55e; }
        .feed-dot-red    { background: #ef4444; }
        .feed-dot-blue   { background: #3b82f6; }
        .feed-what  { font-size: .86rem; font-weight: 600; }
        .feed-meta  { font-size: .76rem; color: var(--text-muted); }
        .feed-when  { margin-left: auto; font-size: .74rem; color: #9ca3af; white-space: nowrap; }

        /* ── Action strip ── */
        .todo-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            margin-bottom: 28px;
        }
        .todo {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 13px 16px;
            text-decoration: none; color: inherit;
            box-shadow: var(--shadow-sm);
            transition: transform .15s, box-shadow .15s;
        }
        .todo:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .todo-open  { background: #fffdf5; border-color: #fde68a; }
        .todo b     { display: block; font-size: .88rem; }
        .todo span  { font-size: .76rem; color: var(--text-muted); }

        /* Same pill treatment as .pipe-state, so a card's status reads the
           same way whether it sits in the pipeline or the action strip. */
        .todo-num, .todo-num-ok {
            flex-shrink: 0;
            padding: 3px 10px; border-radius: 20px;
            font-weight: 700; line-height: 1.5;
        }
        .todo-num    { font-size: 1.05rem; background: #fef3c7; color: #92400e; }
        .todo-num-ok {
            font-size: .68rem; letter-spacing: .05em; text-transform: uppercase;
            background: #dcfce7; color: #166534;
        }
        /* A plain figure, not a status - no pill. */
        .todo-val   { font-size: 1.25rem; font-weight: 700; flex-shrink: 0; }

        @media (max-width: 1100px) {
            .pipeline { grid-template-columns: repeat(2, 1fr); }
            .dash-row, .dash-row-wide { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .pipeline { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Dashboard</h1>
            <p>Welcome back, <?= htmlspecialchars($_SESSION['admin']) ?> &nbsp;&nbsp; <?= date('F j, Y') ?></p>
        </div>
        <a href="attendance-upload.php" class="btn btn-primary">Upload Attendance</a>
    </div>

    <?php if (!$latestPeriod): ?>
    <div class="alert alert-warn">
        No payroll data yet. <a href="attendance-upload.php">Upload an attendance file</a> to get started.
    </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="card-grid">
        <div class="card card-accent">
            <div class="card-label">Registered Employees</div>
            <div class="card-value"><?= $totalEmployeesDB ?></div>
            <div class="card-sub">In employee master list</div>
        </div>
        <div class="card card-green">
            <div class="card-label">Total Gross Pay</div>
            <div class="card-value">&#8369;<?= number_format($totals['total_gross'], 0) ?></div>
            <div class="card-sub"><?= htmlspecialchars($latestPeriodLabel ?? 'No data') ?> &nbsp;&nbsp; before deductions</div>
        </div>
        <div class="card card-accent">
            <div class="card-label">Total Net Pay</div>
            <div class="card-value">&#8369;<?= number_format($totals['total_net'], 0) ?></div>
            <div class="card-sub"><?= htmlspecialchars($latestPeriodLabel ?? 'No data') ?> &nbsp;&nbsp; after all deductions</div>
        </div>
        <div class="card card-red">
            <div class="card-label">Tax Withheld</div>
            <div class="card-value">&#8369;<?= number_format($totals['total_tax'], 0) ?></div>
            <div class="card-sub"><?= htmlspecialchars($latestPeriodLabel ?? 'No data') ?></div>
        </div>
        <div class="card card-yellow">
            <div class="card-label">Total Bonuses</div>
            <div class="card-value">&#8369;<?= number_format($totals['total_bonus'], 0) ?></div>
            <div class="card-sub"><?= htmlspecialchars($latestPeriodLabel ?? 'No data') ?> &nbsp;&nbsp; applied to payroll</div>
        </div>
        <div class="card card-purple">
            <div class="card-label">Total Deductions</div>
            <div class="card-value">&#8369;<?= number_format($totals['total_ded'], 0) ?></div>
            <div class="card-sub"><?= htmlspecialchars($latestPeriodLabel ?? 'No data') ?> &nbsp;&nbsp; other deductions</div>
        </div>
        <div class="card card-yellow">
            <div class="card-label">Overtime Hours</div>
            <div class="card-value"><?= number_format($totals['total_ot'], 1) ?> hrs</div>
            <div class="card-sub"><?= htmlspecialchars($latestPeriodLabel ?? 'No data') ?></div>
        </div>
        <div class="card card-purple">
            <div class="card-label">Late Hours</div>
            <div class="card-value"><?= number_format($totals['total_late'], 1) ?> hrs</div>
            <div class="card-sub"><?= htmlspecialchars($latestPeriodLabel ?? 'No data') ?></div>
        </div>

        <!-- Prediction card — spans full width, pulses when a value is available -->
        <div class="card card-sky <?= $n >= 2 ? 'card-predict-active' : '' ?>"
             style="grid-column: 1 / -1;">
            <div class="card-label">Predicted Next Month Net Payroll</div>
            <div class="card-value" style="color:#0ea5e9;font-size:2rem;">
                <?= $n >= 2 ? '&#8369;' . number_format($predicted, 2) : '&mdash;' ?>
            </div>
            <div class="card-sub">
                <?php if ($n >= 2): ?>
                    Linear regression based on <?= $n ?> period(s) &nbsp;&nbsp;
                    <a href="forecast.php" style="color:#0ea5e9;font-weight:600;">
                        See full AI forecast &rarr;
                    </a>
                <?php else: ?>
                    Upload and process at least 2 payroll periods to enable forecasting
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Cycle pipeline: the four checkpoints of the current period -->
    <div class="pipeline">
        <?php $stepNo = 0; $currentMarked = false; ?>
        <?php foreach ($pipeline as $step): $stepNo++; ?>
            <?php
            /* The first unfinished step is the one being worked on now */
            $isCurrent = !$step['done'] && !$currentMarked;
            if ($isCurrent) $currentMarked = true;
            ?>
            <div class="pipe-step <?= $step['done'] ? 'done' : ($isCurrent ? 'current' : '') ?>">
                <div class="pipe-no">Step <?= $stepNo ?></div>
                <div class="pipe-label"><?= htmlspecialchars($step['label']) ?></div>
                <div class="pipe-note"><?= htmlspecialchars($step['note']) ?></div>
                <span class="pipe-state"><?= $step['done'] ? 'Done' : ($isCurrent ? 'In progress' : 'Waiting') ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- What still needs a person -->
    <div class="todo-strip">
        <a class="todo <?= $unapprovedRows > 0 ? 'todo-open' : '' ?>" href="attendance-upload.php">
            <span>
                <b>Unapproved timesheets</b>
                <span><?= $unapprovedRows > 0 ? 'Managers still have rows to sign off' : 'All attendance approved' ?></span>
            </span>
            <span class="<?= $unapprovedRows > 0 ? 'todo-num' : 'todo-num-ok' ?>"><?= $unapprovedRows > 0 ? $unapprovedRows : 'Clear' ?></span>
        </a>
        <a class="todo <?= $pendingLeaveCount > 0 ? 'todo-open' : '' ?>" href="leave-requests.php">
            <span>
                <b>Leave requests</b>
                <span><?= $pendingLeaveCount > 0 ? 'Waiting for a decision' : 'Nothing pending' ?></span>
            </span>
            <span class="<?= $pendingLeaveCount > 0 ? 'todo-num' : 'todo-num-ok' ?>"><?= $pendingLeaveCount > 0 ? $pendingLeaveCount : 'Clear' ?></span>
        </a>
        <a class="todo <?= $periodStatus !== 'Finalized' && $payrollRows > 0 ? 'todo-open' : '' ?>" href="payroll.php">
            <span>
                <b>Payroll to finalize</b>
                <span><?= $latestPeriodLabel ? htmlspecialchars($latestPeriodLabel) : 'No period yet' ?></span>
            </span>
            <span class="<?= $periodStatus === 'Finalized' ? 'todo-num-ok' : 'todo-num' ?>">
                <?= $periodStatus === 'Finalized' ? 'Closed' : ($payrollRows ?: '—') ?>
            </span>
        </a>
        <a class="todo" href="reports.php">
            <span>
                <b>Average net pay</b>
                <span>Per employee this period</span>
            </span>
            <span class="todo-val">&#8369;<?= number_format($avgNet, 0) ?></span>
        </a>
    </div>

    <!-- Payroll trend chart -->
    <div class="box" style="margin-bottom:28px;">
        <div class="box-header">
            <h2>Payroll Trend &amp; Forecast</h2>
            <span class="badge badge-blue">Predictive Budget Forecasting</span>
        </div>
        <div class="box-body">
            <?php if ($n < 2): ?>
                <div class="alert alert-warn" style="margin:0;">
                    Upload and process at least 2 payroll periods to activate the forecast chart.
                </div>
            <?php else: ?>
                <div class="chart-container" style="height:280px;">
                    <canvas id="forecastChart"></canvas>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Where the gross pay went, and who took home the most -->
    <div class="dash-row">
        <div class="box">
            <div class="box-header">
                <h2>Payroll Composition</h2>
                <span class="badge badge-blue"><?= htmlspecialchars($latestPeriodLabel ?? 'No data') ?></span>
            </div>
            <div class="box-body">
                <?php if ($totals['total_gross'] <= 0): ?>
                    <div class="alert alert-warn" style="margin:0;">No payroll computed yet for this period.</div>
                <?php else: ?>
                    <div class="chart-container" style="height:230px;">
                        <canvas id="compositionChart"></canvas>
                    </div>
                    <div class="rank-row" style="border-bottom:none;padding-bottom:0;">
                        <div class="rank-top">
                            <span class="rank-name">Take-home share</span>
                            <span class="rank-value"><?= $totals['total_gross'] ? round($composition['net'] / $totals['total_gross'] * 100) : 0 ?>%</span>
                        </div>
                        <div class="rank-bar">
                            <div class="rank-fill rank-fill-green" style="width:<?= $totals['total_gross'] ? round($composition['net'] / $totals['total_gross'] * 100) : 0 ?>%"></div>
                        </div>
                        <div class="rank-meta" style="margin-top:6px;">
                            &#8369;<?= number_format($totalDeductions, 2) ?> withheld in total deductions
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="box">
            <div class="box-header">
                <h2>Highest Net Pay</h2>
                <a href="payroll.php" class="btn btn-ghost btn-sm">View payroll</a>
            </div>
            <div class="box-body">
                <?php if (empty($topEarners)): ?>
                    <div class="alert alert-warn" style="margin:0;">No payroll records to rank yet.</div>
                <?php else: ?>
                    <?php foreach ($topEarners as $t): ?>
                    <div class="rank-row">
                        <div class="rank-top">
                            <span>
                                <span class="rank-name"><?= htmlspecialchars($t['emp_name']) ?></span>
                                <span class="rank-meta">&nbsp;<?= htmlspecialchars($t['emp_id']) ?><?= $t['overtime_hours'] > 0 ? ' &middot; ' . number_format($t['overtime_hours'], 1) . ' h OT' : '' ?></span>
                            </span>
                            <span class="rank-value">&#8369;<?= number_format($t['net_pay'], 2) ?></span>
                        </div>
                        <div class="rank-bar">
                            <div class="rank-fill" style="width:<?= $topNet ? max(4, round($t['net_pay'] / $topNet * 100)) : 0 ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Cost per branch, and the audit trail of recent changes -->
    <div class="dash-row dash-row-wide">
        <div class="box">
            <div class="box-header">
                <h2>Payroll by Branch</h2>
                <a href="employee.php" class="btn btn-ghost btn-sm">Employees</a>
            </div>
            <div class="box-body">
                <?php if (empty($byBranch)): ?>
                    <div class="alert alert-warn" style="margin:0;">No employees registered yet.</div>
                <?php else: ?>
                    <?php foreach ($byBranch as $b): ?>
                    <div class="rank-row">
                        <div class="rank-top">
                            <span>
                                <span class="rank-name"><?= htmlspecialchars($b['branch']) ?></span>
                                <span class="rank-meta">&nbsp;<?= $b['headcount'] ?> employee<?= (int)$b['headcount'] === 1 ? '' : 's' ?></span>
                            </span>
                            <span class="rank-value">&#8369;<?= number_format($b['net'], 2) ?></span>
                        </div>
                        <div class="rank-bar">
                            <div class="rank-fill" style="width:<?= $branchMaxNet ? max(4, round($b['net'] / $branchMaxNet * 100)) : 4 ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="box">
            <div class="box-header">
                <h2>Recent Activity</h2>
                <a href="history.php" class="btn btn-ghost btn-sm">Full history</a>
            </div>
            <div class="box-body">
                <?php if (empty($activity)): ?>
                    <div class="alert alert-warn" style="margin:0;">No adjustments or finalize events recorded yet.</div>
                <?php else: ?>
                    <?php foreach ($activity as $a): ?>
                    <?php
                    $kind = $a['kind'] ?? '';
                    $dot  = $kind === 'Bonus'      ? 'feed-dot-green'
                          : ($kind === 'Deduction' ? 'feed-dot-red'
                          : ($kind === 'Finalized' ? 'feed-dot-blue' : ''));
                    ?>
                    <div class="feed-item">
                        <span class="feed-dot <?= $dot ?>"></span>
                        <span>
                            <span class="feed-what"><?= htmlspecialchars($a['what']) ?></span><br>
                            <span class="feed-meta"><?= htmlspecialchars($a['detail']) ?></span>
                        </span>
                        <span class="feed-when"><?= $a['at'] ? date('M j, g:i A', strtotime($a['at'])) : '' ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent payroll records from the latest period -->
    <div class="box">
        <div class="box-header">
            <h2>Recent Payroll Records</h2>
            <?php if ($latestPeriod && !empty($recentPayroll)): ?>
                <a href="payroll.php" class="btn btn-ghost btn-sm">View All</a>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Emp ID</th><th>Name</th><th>Hours</th>
                        <th>Gross Pay</th><th>Bonus</th><th>Deductions</th>
                        <th>Net Pay</th><th>Period</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recentPayroll)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;color:#9ca3af;padding:30px;">
                            No payroll records yet.
                            <a href="attendance-upload.php">Upload an attendance file</a> to populate this table.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentPayroll as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['emp_id']) ?></td>
                        <td><?= htmlspecialchars($r['emp_name']) ?></td>
                        <td><?= number_format($r['hours_worked'], 1) ?> hrs</td>
                        <td>&#8369;<?= number_format($r['gross_pay'], 2) ?></td>
                        <td><?= $r['bonus'] > 0 ? '<span style="color:#16a34a;">&#8369;' . number_format($r['bonus'], 2) . '</span>' : '—' ?></td>
                        <td><?= $r['other_deductions'] > 0 ? '<span style="color:#dc2626;">&#8369;' . number_format($r['other_deductions'], 2) . '</span>' : '—' ?></td>
                        <td><strong>&#8369;<?= number_format($r['net_pay'], 2) ?></strong></td>
                        <td><span class="badge badge-blue"><?= htmlspecialchars($r['period_label']) ?></span></td>
                        <td>
                            <span class="badge badge-<?= $r['status'] === 'Finalized' ? 'green' : 'blue' ?>">
                                <?= $r['status'] ?>
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
window.PAYROLL_DATA = {
    n:         <?= $n ?>,
    labels:    <?= json_encode(array_column($monthly, 'period_label')) ?>,
    netData:   <?= json_encode(array_map('floatval', $nets)) ?>,
    groData:   <?= json_encode(array_map('floatval', array_column($monthly, 'total_gross'))) ?>,
    predicted: <?= round($predicted, 2) ?>
};
</script>
<script src="assets/js/dashboard.js"></script>
<script>
/* Doughnut showing how the period's gross pay splits between take-home and each deduction */
(function () {
    var el = document.getElementById('compositionChart');
    if (!el || typeof Chart === 'undefined') return;

    new Chart(el, {
        type: 'doughnut',
        data: {
            labels: ['Net pay', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding tax', 'Other deductions'],
            datasets: [{
                data: [
                    <?= round($composition['net'], 2) ?>,
                    <?= round($composition['sss'], 2) ?>,
                    <?= round($composition['philhealth'], 2) ?>,
                    <?= round($composition['pagibig'], 2) ?>,
                    <?= round($composition['tax'], 2) ?>,
                    <?= round($composition['other'], 2) ?>
                ],
                backgroundColor: ['#16a34a', '#3b82f6', '#0ea5e9', '#8b5cf6', '#f59e0b', '#ef4444'],
                borderColor: '#fff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            var pct   = total ? Math.round(ctx.parsed / total * 100) : 0;
                            return ctx.label + ': PHP ' + ctx.parsed.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
})();
</script>
</body>
</html>