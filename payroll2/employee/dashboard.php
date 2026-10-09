<?php
require_once __DIR__ . '/includes/auth.php';
requireEmployee();

/*
 * Pay analytics for the signed-in employee.
 *
 * Home already answers "what did I last get paid, and where is my leave".
 * This page answers the slower questions instead: how does my pay move
 * over time, what share of it goes to deductions, and what have I earned
 * so far this year. Rates and trends, not a status summary.
 */

$activePage = 'dashboard';
$db  = getDB();
$e   = emp();

$empInfo = $db->prepare("SELECT * FROM employees WHERE emp_id=?");
$empInfo->execute([$e['id']]);
$empInfo = $empInfo->fetch();

$latestPayroll = $db->prepare("
    SELECT p.*, pp.period_label FROM payroll p
    JOIN payroll_periods pp ON pp.id = p.period_id
    WHERE p.emp_id = ?
    ORDER BY pp.period_start DESC, pp.id DESC LIMIT 1
");
$latestPayroll->execute([$e['id']]);
$latest = $latestPayroll->fetch();

$hasSigned = false;
if ($latest) {
    $sigCheck = $db->prepare("SELECT net_signed FROM payslip_signatures WHERE payroll_id=? LIMIT 1");
    $sigCheck->execute([$latest['id']]);
    $sigRow    = $sigCheck->fetch();
    $hasSigned = $sigRow && signatureCurrent($sigRow['net_signed'], $latest['net_pay']);
}

/* ── Pay history ────────────────────────────────────────────────────────
 * Last six periods, oldest first, so the chart reads left to right like a
 * timeline. Also drives the "vs. last period" comparison.
 */
$history = $db->prepare("
    SELECT p.net_pay, p.gross_pay, p.hours_worked, p.overtime_hours, pp.period_label
    FROM payroll p
    JOIN payroll_periods pp ON pp.id = p.period_id
    WHERE p.emp_id = ?
    ORDER BY pp.period_start DESC, pp.id DESC
    LIMIT 6
");
$history->execute([$e['id']]);
$history = array_reverse($history->fetchAll());

/* Change against the previous period */
$prevNet = count($history) >= 2 ? (float)$history[count($history) - 2]['net_pay'] : null;
$netDiff = ($prevNet !== null && $latest) ? (float)$latest['net_pay'] - $prevNet : null;
$netPct  = ($prevNet) ? round($netDiff / $prevNet * 100, 1) : null;

/* Year-to-date totals */
$ytd = $db->prepare("
    SELECT COALESCE(SUM(p.gross_pay),0)         AS gross,
           COALESCE(SUM(p.net_pay),0)           AS net,
           COALESCE(SUM(p.sss),0)               AS sss,
           COALESCE(SUM(p.philhealth),0)        AS philhealth,
           COALESCE(SUM(p.pagibig),0)           AS pagibig,
           COALESCE(SUM(p.withholding_tax),0)   AS tax,
           COALESCE(SUM(p.other_deductions),0)  AS other,
           COALESCE(SUM(p.bonus),0)             AS bonus,
           COALESCE(SUM(p.overtime_hours),0)    AS ot,
           COALESCE(MAX(p.net_pay),0)           AS best,
           COUNT(*)                             AS periods
    FROM payroll p
    JOIN payroll_periods pp ON pp.id = p.period_id
    WHERE p.emp_id = ? AND YEAR(pp.period_start) = YEAR(CURDATE())
");
$ytd->execute([$e['id']]);
$ytd = array_map('floatval', $ytd->fetch(PDO::FETCH_ASSOC) ?: []);
$ytdDeductions = ($ytd['sss'] ?? 0) + ($ytd['philhealth'] ?? 0) + ($ytd['pagibig'] ?? 0)
               + ($ytd['tax'] ?? 0) + ($ytd['other'] ?? 0);

/* Derived rates - the numbers a payslip never states outright */
$ytdPeriods = (int)($ytd['periods'] ?? 0);
$avgNet     = $ytdPeriods ? ($ytd['net'] ?? 0) / $ytdPeriods : 0;
$takeHome   = ($ytd['gross'] ?? 0) > 0 ? round(($ytd['net'] ?? 0) / $ytd['gross'] * 100, 1) : 0;
$dedRate    = ($ytd['gross'] ?? 0) > 0 ? round($ytdDeductions / $ytd['gross'] * 100, 1) : 0;

/* Approved leave days taken this year */
$leaveSt = $db->prepare("
    SELECT date_from, date_to FROM leave_requests
    WHERE emp_id = ? AND status = 'Approved' AND YEAR(date_from) = YEAR(CURDATE())
");
$leaveSt->execute([$e['id']]);
$leaveDays = 0;   /* duty days: the employee's days off are not leave */
foreach ($leaveSt->fetchAll() as $l) $leaveDays += leaveDays($l['date_from'], $l['date_to'], $empInfo['rest_days'] ?? null)['duty'];

/* Deduction split for the latest payslip, largest first */
$dedBreakdown = [];
if ($latest) {
    $dedBreakdown = [
        ['label' => 'SSS',              'amount' => (float)$latest['sss']],
        ['label' => 'PhilHealth',       'amount' => (float)$latest['philhealth']],
        ['label' => 'Pag-IBIG',         'amount' => (float)$latest['pagibig']],
        ['label' => 'Withholding tax',  'amount' => (float)$latest['withholding_tax']],
        ['label' => 'Other deductions', 'amount' => (float)$latest['other_deductions']],
    ];
    usort($dedBreakdown, fn($a, $b) => $b['amount'] <=> $a['amount']);
}
$dedTotal = array_sum(array_column($dedBreakdown, 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Employee Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        /* Marks pay that was corrected after the period had been finalized */
        .rev-tag {
            display: inline-block; padding: 1px 6px; border-radius: 999px;
            background: #fef3c7; color: #92400e; font-size: .64rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .04em; vertical-align: middle;
        }

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
        .kpi-value { font-size: 1.3rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.15; }
        .kpi-sub   { font-size: .71rem; color: var(--text-muted); margin-top: 4px; }

        /* Change pill on the period-over-period comparison */
        .delta {
            display: inline-block; margin-top: 6px;
            font-size: .66rem; font-weight: 700;
            padding: 2px 8px; border-radius: 20px;
        }
        .delta-up   { background: #dcfce7; color: #166534; }
        .delta-down { background: #fee2e2; color: #991b1b; }
        .delta-flat { background: #f1f5f9; color: #64748b; }

        /* Two-up rows that stack on tablets */
        .dash-row { display: grid; grid-template-columns: 1.3fr 1fr; gap: 18px; align-items: start; margin-bottom: 22px; }

        .chart-wrap { height: 250px; }
        .chart-wrap-sm { height: 205px; }

        /* Deduction rows */
        .ded-row { padding: 8px 0; border-bottom: 1px solid var(--border); }
        .ded-row:last-child { border-bottom: none; }
        .ded-top {
            display: flex; justify-content: space-between; align-items: baseline;
            gap: 12px; margin-bottom: 5px; font-size: .84rem;
        }
        .ded-amt { font-weight: 700; white-space: nowrap; }
        .ded-bar { height: 6px; background: #eef2f7; border-radius: 20px; overflow: hidden; }
        .ded-fill { height: 100%; border-radius: 20px; background: linear-gradient(90deg, #f87171, #ef4444); }

        /* YTD tiles */
        .ytd-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .ytd-cell { border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; }
        .ytd-cell b { display: block; font-size: 1.05rem; font-weight: 700; }
        .ytd-cell span { font-size: .72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
        .ytd-net   { background: #f0fdf4; border-color: #86efac; }
        .ytd-net b { color: #166534; }
        .ytd-ded   { background: #fff1f2; border-color: #fecaca; }
        .ytd-ded b { color: #991b1b; }

        @media (max-width: 1200px) { .kpi-strip { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 900px)  { .dash-row { grid-template-columns: 1fr; } }
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
                Your pay analytics &nbsp;&middot;&nbsp;
                <?= htmlspecialchars($empInfo['position'] ?? '') ?><?= ($empInfo['position'] && $empInfo['branch']) ? ' &middot; ' : '' ?><?= htmlspecialchars($empInfo['branch'] ?? '') ?>
                &nbsp;&middot;&nbsp; <?= $ytdPeriods ?> period(s) paid in <?= date('Y') ?>
            </p>
        </div>
        <a href="/employee/payslips.php" class="btn btn-ghost">All payslips</a>
    </div>

    <?php if (!$latest): ?>
        <div class="p-alert p-alert-info">
            No payroll records yet. Your pay analytics will appear here once the admin processes your pay.
        </div>
    <?php else: ?>

    <!-- Rates and averages a single payslip never shows -->
    <div class="kpi-strip">
        <div class="kpi">
            <div class="kpi-label">Avg Net / Period</div>
            <div class="kpi-value">₱<?= number_format($avgNet, 0) ?></div>
            <div class="kpi-sub">across <?= $ytdPeriods ?> period(s) this year</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Vs. Last Period</div>
            <div class="kpi-value">
                <?php if ($netDiff === null): ?>
                    -
                <?php else: ?>
                    <?= $netDiff >= 0 ? '+' : '−' ?>₱<?= number_format(abs($netDiff), 0) ?>
                <?php endif; ?>
            </div>
            <?php if ($netDiff === null): ?>
                <div class="kpi-sub">Needs a second period</div>
            <?php else: ?>
                <span class="delta <?= abs($netDiff) < 0.01 ? 'delta-flat' : ($netDiff > 0 ? 'delta-up' : 'delta-down') ?>">
                    <?= $netPct !== null ? ($netDiff >= 0 ? '+' : '−') . abs($netPct) . '%' : 'no change' ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="kpi">
            <div class="kpi-label">Take-home Rate</div>
            <div class="kpi-value"><?= $takeHome ?>%</div>
            <div class="kpi-sub">of gross reaches your pocket</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Deduction Rate</div>
            <div class="kpi-value"><?= $dedRate ?>%</div>
            <div class="kpi-sub">₱<?= number_format($ytdDeductions, 0) ?> withheld this year</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Best Period</div>
            <div class="kpi-value">₱<?= number_format($ytd['best'] ?? 0, 0) ?></div>
            <div class="kpi-sub">highest net pay in <?= date('Y') ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Overtime</div>
            <div class="kpi-value"><?= number_format($ytd['ot'] ?? 0, 1) ?> h</div>
            <div class="kpi-sub"><?= $leaveDays ?> leave day(s) taken this year</div>
        </div>
    </div>

    <!-- How pay has moved, and what the year adds up to -->
    <div class="dash-row">
        <div class="p-box">
            <div class="p-box-header">
                <h2>Pay Trend</h2>
                <span class="badge badge-blue">Last <?= count($history) ?> period(s)</span>
            </div>
            <div class="p-box-body">
                <?php if (count($history) < 2): ?>
                    <div class="p-alert p-alert-info" style="margin:0;">
                        Once you have been paid for a second period, your gross and net pay will be
                        charted here side by side.
                    </div>
                <?php else: ?>
                    <div class="chart-wrap"><canvas id="payChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-box">
            <div class="p-box-header">
                <h2>Year to Date - <?= date('Y') ?></h2>
                <span class="badge badge-blue"><?= $ytdPeriods ?> period(s)</span>
            </div>
            <div class="p-box-body">
                <div class="ytd-grid">
                    <div class="ytd-cell">
                        <b>₱<?= number_format($ytd['gross'] ?? 0, 2) ?></b><span>Gross earned</span>
                    </div>
                    <div class="ytd-cell ytd-net">
                        <b>₱<?= number_format($ytd['net'] ?? 0, 2) ?></b><span>Take-home</span>
                    </div>
                    <div class="ytd-cell ytd-ded">
                        <b>₱<?= number_format($ytdDeductions, 2) ?></b><span>Total deductions</span>
                    </div>
                    <div class="ytd-cell">
                        <b>₱<?= number_format($ytd['bonus'] ?? 0, 2) ?></b><span>Bonuses</span>
                    </div>
                </div>
                <div style="font-size:.78rem;color:var(--text-muted);margin-top:12px;">
                    ₱<?= number_format($ytd['tax'] ?? 0, 2) ?> withheld for tax &middot;
                    ₱<?= number_format(($ytd['sss'] ?? 0) + ($ytd['philhealth'] ?? 0) + ($ytd['pagibig'] ?? 0), 2) ?>
                    in statutory contributions.
                </div>
            </div>
        </div>
    </div>

    <!-- Where the money goes, and the hours behind it -->
    <div class="dash-row">
        <div class="p-box">
            <div class="p-box-header">
                <h2>Deductions - <?= htmlspecialchars($latest['period_label'] ?? 'Latest') ?></h2>
                <span class="badge badge-red">₱<?= number_format($dedTotal, 2) ?></span>
            </div>
            <div class="p-box-body">
                <?php if ($dedTotal <= 0): ?>
                    <div class="p-alert p-alert-info" style="margin:0;">No deductions on your latest payslip.</div>
                <?php else: ?>
                    <?php foreach ($dedBreakdown as $d): ?>
                        <?php if ($d['amount'] <= 0) continue; ?>
                        <div class="ded-row">
                            <div class="ded-top">
                                <span><?= htmlspecialchars($d['label']) ?></span>
                                <span class="ded-amt">
                                    ₱<?= number_format($d['amount'], 2) ?>
                                    <span style="color:var(--text-muted);font-weight:500;">
                                        &middot; <?= round($d['amount'] / $dedTotal * 100) ?>%
                                    </span>
                                </span>
                            </div>
                            <div class="ded-bar">
                                <div class="ded-fill" style="width:<?= max(3, round($d['amount'] / $dedTotal * 100)) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div style="font-size:.78rem;color:var(--text-muted);margin-top:12px;">
                        Gross ₱<?= number_format($latest['gross_pay'], 2) ?><?= (float)$latest['bonus'] > 0 ? ' + bonus ₱' . number_format($latest['bonus'], 2) : '' ?> &minus; deductions ₱<?= number_format($dedTotal, 2) ?>
                        <?= $latest['bonus'] > 0 ? ' + bonus ₱' . number_format($latest['bonus'], 2) : '' ?>
                        = net ₱<?= number_format($latest['net_pay'], 2) ?>.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-box">
            <div class="p-box-header">
                <h2>Hours Worked</h2>
                <span class="badge badge-blue">Regular vs. overtime</span>
            </div>
            <div class="p-box-body">
                <?php if (count($history) < 2): ?>
                    <div class="p-alert p-alert-info" style="margin:0;">
                        Your hours will be charted here once a second period has been paid.
                    </div>
                <?php else: ?>
                    <div class="chart-wrap-sm"><canvas id="hoursChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- The latest payslip, line by line -->
    <div class="p-box">
        <div class="p-box-header">
            <h2>Latest Payslip - <?= htmlspecialchars($latest['period_label']) ?></h2>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <span class="badge badge-<?= $latest['status']==='Finalized' ? 'green' : 'blue' ?>"><?= $latest['status'] ?></span>
                <?php if (!empty($latest['revised_after_finalize'])): ?>
                    <span class="rev-tag" title="Corrected after this period was finalized">Revised</span>
                <?php endif; ?>
                <?php if ($hasSigned): ?>
                    <span class="badge badge-purple">Signed</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="p-box-body">
            <?php if (!empty($latest['revised_after_finalize'])): ?>
            <div class="p-alert p-alert-warn">
                This payslip was corrected after <?= htmlspecialchars($latest['period_label']) ?> had been
                finalized<?= !empty($latest['revised_at']) ? ' on ' . date('M d, Y', strtotime($latest['revised_at'])) : '' ?>.
                The figures below are the corrected ones.
                <a href="/employee/payslips.php" style="color:inherit;font-weight:700;">See what changed</a>
            </div>
            <?php endif; ?>
            <div class="p-cards" style="margin-bottom:0;">
                <div class="p-card">
                    <div class="p-card-label">Hours Worked</div>
                    <div class="p-card-value"><?= number_format($latest['hours_worked'], 1) ?></div>
                    <div class="p-card-sub">hrs</div>
                </div>
                <div class="p-card">
                    <div class="p-card-label">Overtime</div>
                    <div class="p-card-value"><?= number_format($latest['overtime_hours'], 1) ?></div>
                    <div class="p-card-sub">hrs</div>
                </div>
                <div class="p-card">
                    <div class="p-card-label">Gross Pay</div>
                    <div class="p-card-value" style="font-size:1.1rem;">₱<?= number_format($latest['gross_pay'], 2) ?></div>
                </div>
                <div class="p-card">
                    <div class="p-card-label">SSS</div>
                    <div class="p-card-value" style="font-size:1.1rem;color:#dc2626;">₱<?= number_format($latest['sss'], 2) ?></div>
                </div>
                <div class="p-card">
                    <div class="p-card-label">PhilHealth</div>
                    <div class="p-card-value" style="font-size:1.1rem;color:#dc2626;">₱<?= number_format($latest['philhealth'], 2) ?></div>
                </div>
                <div class="p-card">
                    <div class="p-card-label">Pag-IBIG</div>
                    <div class="p-card-value" style="font-size:1.1rem;color:#dc2626;">₱<?= number_format($latest['pagibig'], 2) ?></div>
                </div>
                <div class="p-card">
                    <div class="p-card-label">Bonus</div>
                    <div class="p-card-value" style="font-size:1.1rem;color:#16a34a;">₱<?= number_format($latest['bonus'], 2) ?></div>
                </div>
                <div class="p-card">
                    <div class="p-card-label">Other Deductions</div>
                    <div class="p-card-value" style="font-size:1.1rem;color:#dc2626;">₱<?= number_format($latest['other_deductions'], 2) ?></div>
                </div>
                <div class="p-card p-card-green">
                    <div class="p-card-label">Net Pay</div>
                    <div class="p-card-value">₱<?= number_format($latest['net_pay'], 2) ?></div>
                    <div class="p-card-sub">Take-home amount</div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php if (count($history) >= 2): ?>
<script>
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.color = '#6b7280';

const payLabels = <?= json_encode(array_column($history, 'period_label')) ?>;
const peso = v => '₱' + Number(v).toLocaleString();

new Chart(document.getElementById('payChart'), {
    type: 'line',
    data: {
        labels: payLabels,
        datasets: [
            {
                label: 'Gross pay',
                data: <?= json_encode(array_map(fn($h) => round((float)$h['gross_pay'], 2), $history)) ?>,
                borderColor: '#94a3b8',
                backgroundColor: 'rgba(148,163,184,.12)',
                borderWidth: 2, tension: .3, fill: true,
                pointRadius: 3, pointBackgroundColor: '#94a3b8'
            },
            {
                label: 'Net pay',
                data: <?= json_encode(array_map(fn($h) => round((float)$h['net_pay'], 2), $history)) ?>,
                borderColor: '#16a34a',
                backgroundColor: 'rgba(22,163,74,.14)',
                borderWidth: 2.5, tension: .3, fill: true,
                pointRadius: 4, pointBackgroundColor: '#16a34a'
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true, pointStyle: 'circle' } },
            tooltip: { callbacks: { label: c => c.dataset.label + ': ' + peso(c.parsed.y) } }
        },
        scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { callback: v => peso(v) } }
        }
    }
});

new Chart(document.getElementById('hoursChart'), {
    type: 'bar',
    data: {
        labels: payLabels,
        datasets: [
            {
                label: 'Regular',
                /* hours_worked is regular time only; overtime is its own series */
                data: <?= json_encode(array_map(fn($h) => round((float)$h['hours_worked'], 1), $history)) ?>,
                backgroundColor: '#3b82f6', borderRadius: 5, stack: 'h'
            },
            {
                label: 'Overtime',
                data: <?= json_encode(array_map(fn($h) => round((float)$h['overtime_hours'], 1), $history)) ?>,
                backgroundColor: '#f59e0b', borderRadius: 5, stack: 'h'
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
</script>
<?php endif; ?>

</body>
</html>
