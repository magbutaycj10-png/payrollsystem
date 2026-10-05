<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'payroll';
$db         = getDB();
$periods    = $db->query("SELECT * FROM payroll_periods ORDER BY period_start DESC")->fetchAll();
$period_id  = (int)($_GET['period'] ?? defaultPeriodId($db, $periods));
$curPeriod  = null;

foreach ($periods as $p) {
    if ($p['id'] == $period_id) { $curPeriod = $p; break; }
}

$payrollRows = [];
if ($period_id) {
    $st = $db->prepare("SELECT * FROM payroll WHERE period_id=? ORDER BY emp_id ASC");
    $st->execute([$period_id]);
    $payrollRows = $st->fetchAll();
}

$isOpen = $curPeriod && $curPeriod['status'] === 'Open';

/* Adjustments recorded against this period (step 3 feeds this). */
$adjBonus = $adjDed = 0.0;
$adjCount = 0;
if ($period_id) {
    $st = $db->prepare("SELECT entry_type, COUNT(*) AS c, SUM(amount) AS s
                          FROM bonus_deduction_history WHERE period_id = ? GROUP BY entry_type");
    $st->execute([$period_id]);
    foreach ($st->fetchAll() as $r) {
        if ($r['entry_type'] === 'Bonus') $adjBonus = (float)$r['s']; else $adjDed = (float)$r['s'];
        $adjCount += (int)$r['c'];
    }
}

/* What the payroll rows themselves carry. These should equal the history
   totals; a gap means money was changed outside the adjustments page. */
$payBonus = array_sum(array_column($payrollRows, 'bonus'));
$payDed   = array_sum(array_column($payrollRows, 'other_deductions'));
$payNet   = array_sum(array_column($payrollRows, 'net_pay'));
$drift    = abs($payBonus - $adjBonus) > 0.01 || abs($payDed - $adjDed) > 0.01;

/* Revision state: has this period been finalized, re-opened and corrected? */
$rev = periodRevisionInfo($period_id);

/*
 * Where this pay period stands, step by step — what the tracker at the top
 * shows. Each step reads the database, so it is true for every user.
 */
$steps = null;
$daysWorked = [];
$schedule = '';
$contribNote = '';
$nAtt = 0;
$current = null;
if ($curPeriod) {
    $ctx = payContext($db, $period_id);
    $schedule = periodTypeLabel($curPeriod);
    /* How SSS / PhilHealth / Pag-IBIG are taken on this pay run (month-to-date) */
    $contribNote = contributionPlanText($ctx);
    /* The month's last cut-off, but nothing earlier this month is in the system */
    $missingEarlier = $ctx['final'] && $ctx['period_type'] !== 'Monthly' && $ctx['earlier_runs'] === 0;

    $q = function (string $sql) use ($db, $period_id) { $st = $db->prepare($sql); $st->execute([$period_id]); return $st->fetch(); };
    $att   = $q("SELECT COUNT(*) n, SUM(manager_approved = 1) ok, SUM(manager_approved = 2) flagged FROM attendance WHERE period_id = ?");
    $dayRc = $q("SELECT COUNT(*) n, MIN(att_date) a, MAX(att_date) b FROM biometric_daily WHERE period_id = ?");
    $print = $q("SELECT COUNT(*) n FROM print_log WHERE period_id = ?");
    try { $sig = $q("SELECT COUNT(*) n FROM payslip_signatures WHERE period_id = ?"); } catch (PDOException $e) { $sig = ['n' => 0]; }

    $st = $db->prepare("SELECT emp_id, COUNT(DISTINCT att_date) d FROM biometric_daily WHERE period_id = ? AND hours_worked > 0 GROUP BY emp_id");
    $st->execute([$period_id]);
    foreach ($st->fetchAll() as $r) $daysWorked[$r['emp_id']] = (int)$r['d'];

    $nAtt = (int)$att['n'];
    $steps = [
        ['Attendance', $nAtt > 0,
         $nAtt ? ($dayRc['n'] ? "{$nAtt} employee(s) · {$dayRc['n']} day record(s), " . date('M j', strtotime($dayRc['a'])) . '–' . date('M j', strtotime($dayRc['b']))
                              : "{$nAtt} employee(s) from a totals file")
               : 'Nothing uploaded yet',
         ['attendance-upload.php?period=' . $period_id, $nAtt ? 'Upload more' : 'Upload attendance']],
        ['Manager approval', $nAtt > 0 && (int)$att['ok'] === $nAtt,
         $nAtt ? ((int)$att['ok'] . " of {$nAtt} approved" . ((int)$att['flagged'] ? ' · ' . (int)$att['flagged'] . ' flagged' : '')) : '—',
         null],
        ['Bonus & deductions', $adjCount > 0, $adjCount ? "{$adjCount} entr" . ($adjCount === 1 ? 'y' : 'ies') : 'Optional — none yet',
         $isOpen ? ['adjustments.php?period=' . $period_id, 'Add'] : null],
        ['Finalize', !$isOpen,
         $isOpen ? 'Not finalized yet' : 'Finalized' . (!empty($curPeriod['finalized_at']) ? ' ' . date('M j', strtotime($curPeriod['finalized_at'])) : ''),
         null],
        ['Payslips', (int)$print['n'] > 0,
         (int)$print['n'] ? (int)$print['n'] . ' printed' . ((int)$sig['n'] ? ' · ' . (int)$sig['n'] . ' signed' : '') : 'Not issued yet',
         !$isOpen ? ['reports.php?period=' . $period_id, 'Issue payslips'] : null],
    ];
    /* The first unfinished required step is "now" (approval and bonuses are advisory) */
    foreach ($steps as $i => $stp) { if (!$stp[1] && !in_array($i, [1, 2], true)) { $current = $i; break; } }
}

/*
 * Company cost for this pay period. The employee shares are what the payroll
 * rows actually deducted; the company shares come from the same breakdown
 * (contributionBreakdown in helpers.php), so they match the same monthly basis.
 */
$company = null;
if ($curPeriod && $payrollRows) {
    $ids = array_column($payrollRows, 'emp_id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $db->prepare("SELECT * FROM employees WHERE emp_id IN ($in)");
    $st->execute($ids);
    $emps = [];
    foreach ($st->fetchAll() as $e) $emps[$e['emp_id']] = payEmployee($e);

    $company = ['rows' => [], 'stale' => false,
                'ee' => ['sss' => 0, 'philhealth' => 0, 'pagibig' => 0, 'tax' => 0],
                'er' => ['sss' => 0, 'ec' => 0, 'philhealth' => 0, 'pagibig' => 0],
                'earnings' => 0];
    foreach ($payrollRows as $r) {
        $e = $emps[$r['emp_id']] ?? payEmployee(['emp_id' => $r['emp_id']]);
        /* gross_pay is everything earned (overtime included); basic pay is gross less overtime − late */
        $b = contributionBreakdown($e, (float)$r['gross_pay'] - (float)$r['ot_late_adj'], (float)$r['gross_pay'], $ctx);
        $earn = (float)$r['gross_pay'] + (float)$r['bonus'];
        foreach (['sss', 'philhealth', 'pagibig'] as $k) {
            $company['ee'][$k] += (float)$r[$k];
            if (abs((float)$r[$k] - $b['ee'][$k]) > 0.01) $company['stale'] = true;
        }
        $company['ee']['tax'] += (float)$r['withholding_tax'];
        foreach ($b['er'] as $k => $v) $company['er'][$k] += $v;
        $company['earnings'] += $earn;
        $company['rows'][] = ['r' => $r, 'b' => $b, 'earn' => $earn, 'cost' => $earn + $b['er_total']];
    }
    $company['er_total'] = array_sum($company['er']);
    $company['cost']     = $company['earnings'] + $company['er_total'];
}

/* The finalize / re-open / revise trail, newest first. */
$audit = [];
if ($period_id) {
    try {
        $st = $db->prepare('SELECT * FROM period_audit WHERE period_id = ?
                             ORDER BY created_at DESC, id DESC LIMIT 30');
        $st->execute([$period_id]);
        $audit = $st->fetchAll();
    } catch (PDOException $e) { $audit = []; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Processing — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Marks pay that was changed after the period had been finalized */
        .rev-tag {
            display: inline-block; margin-left: 4px; padding: 1px 6px;
            border-radius: 999px; background: #fef3c7; color: #92400e;
            font-size: .64rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; vertical-align: middle; white-space: nowrap;
        }
        tr.is-revised td { background: #fffbeb; }

        /* Step tracker: where this pay period stands */
        .pp-track { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 20px; }
        .pp-step { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; }
        .pp-step .pp-n { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 50%;
                         background: #e2e8f0; color: #334155; font-size: .72rem; font-weight: 700; margin-right: 6px; flex-shrink: 0; }
        .pp-step.done { border-color: #86efac; background: #f0fdf4; }
        .pp-step.done .pp-n { background: #16a34a; color: #fff; }
        .pp-step.now { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
        .pp-step.now .pp-n { background: #2563eb; color: #fff; }
        .pp-step h4 { display: flex; align-items: center; margin: 0 0 6px; font-size: .86rem; }
        .pp-step p { margin: 0; font-size: .78rem; color: #475569; line-height: 1.45; }
        .pp-step a { display: inline-block; margin-top: 8px; font-size: .78rem; font-weight: 600; }
        .pp-meta { font-size: .84rem; color: #475569; }

        /* Company cost & remittances */
        .co-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .co-card { border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; background: #fff; }
        .co-card .k { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
        .co-card .v { font-size: 1.25rem; font-weight: 700; margin-top: 4px; }
        .co-card .s { font-size: .76rem; color: #64748b; margin-top: 2px; }
        .co-card.total { background: #0f172a; border-color: #0f172a; color: #fff; }
        .co-card.total .k, .co-card.total .s { color: #cbd5e1; }
        .co-table td.num, .co-table th.num { text-align: right; white-space: nowrap; }
        .co-table tfoot td { font-weight: 700; border-top: 2px solid #cbd5e1; background: #f8fafc; }
        .co-sub { font-size: .74rem; color: #94a3b8; }
        details.co-more { margin-top: 14px; }
        details.co-more summary { cursor: pointer; font-weight: 600; font-size: .88rem; padding: 6px 0; }
        @media (max-width: 900px) { .pp-track { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Payroll Processing</h1>
            <p>One pay period at a time: check attendance, add bonuses or deductions, finalize, then issue payslips.</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($isOpen): ?>
                <button class="btn btn-green" onclick="finalizePayroll()">
                    <?= (int)($curPeriod['finalize_count'] ?? 0) > 0 ? 'Re-finalize Period' : 'Finalize Period' ?>
                </button>
            <?php elseif ($curPeriod): ?>
                <button class="btn btn-ghost" onclick="unlockPayroll()" title="Re-open this period to allow changes">Unlock Period</button>
            <?php endif; ?>
            <button class="btn btn-print" onclick="printFullPayroll()">Print All</button>
        </div>
    </div>

    <!-- Period picker -->
    <div class="box" style="margin-bottom:20px;">
        <div class="box-body" style="padding:14px 20px;">
            <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="font-weight:600;font-size:.875rem;">Pay period:</label>
                <select name="period" class="form-control" style="min-width:260px;" onchange="this.form.submit()">
                    <?php
                    /* Grouped by schedule so kinsenas cut-offs and monthly runs are easy to tell apart */
                    $groups = [];
                    foreach ($periods as $p) $groups[periodType($p['period_type'] ?? null)][] = $p;
                    foreach (['Semi-Monthly', 'Monthly', 'Weekly'] as $g): if (empty($groups[$g])) continue; ?>
                    <optgroup label="<?= $g ?>">
                        <?php foreach ($groups[$g] as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $period_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['period_label']) ?> [<?= htmlspecialchars(periodTypeLabel($p)) ?> · <?= $p['status'] ?>]
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php if ($curPeriod): ?>
                    <span class="badge badge-<?= $curPeriod['status'] === 'Open' ? 'blue' : 'yellow' ?>">
                        <?= $curPeriod['status'] ?>
                    </span>
                <?php endif; ?>
            </form>
            <?php if ($curPeriod): ?>
            <div class="pp-meta" style="margin-top:10px;">
                <strong><?= date('M j, Y', strtotime($curPeriod['period_start'])) ?> – <?= date('M j, Y', strtotime($curPeriod['period_end'])) ?></strong>
                &nbsp;·&nbsp; <?= htmlspecialchars($schedule) ?>
                &nbsp;·&nbsp; <?= htmlspecialchars($contribNote) ?>
                &nbsp;·&nbsp; <a href="settings.php">change in Settings</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($steps): ?>
    <!-- Where this pay period stands -->
    <div class="pp-track">
        <?php foreach ($steps as $i => [$title, $done, $detail, $link]): ?>
        <div class="pp-step <?= $done ? 'done' : ($i === $current ? 'now' : '') ?>">
            <h4><span class="pp-n"><?= $done ? '&#10003;' : $i + 1 ?></span><?= htmlspecialchars($title) ?></h4>
            <p><?= htmlspecialchars($detail) ?></p>
            <?php if ($i === 1 && $nAtt): ?><p style="margin-top:4px;color:#94a3b8;">Managers approve in their Timesheets.</p><?php endif; ?>
            <?php if ($link): ?><a href="<?= htmlspecialchars($link[0]) ?>"><?= htmlspecialchars($link[1]) ?> &rarr;</a><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>


    <div id="alertMsg" class="alert" style="display:none;"></div>

    <?php if ($curPeriod): ?>
        <?php if ($isOpen): ?>
            <div class="alert alert-info">
                <span>
                    <strong><?= htmlspecialchars($curPeriod['period_label']) ?> is open.</strong>
                    Bonuses and deductions can still be recorded and they update these figures immediately.
                    Finalize when the numbers are right.
                    <?php if ($rev['reopened']): ?>
                        This period was already finalized <?= (int)$rev['cycle'] ?>&times; and re-opened
                        <?= (int)$rev['reopen_count'] ?>&times;<?= $rev['reopened_at'] ? ' (last on ' . date('M j, Y g:i A', strtotime($rev['reopened_at'])) . ')' : '' ?> &mdash;
                        anything recorded now is stored as a revision. Finalize it again when you are done correcting it.
                    <?php endif; ?>
                </span>
                <a href="adjustments.php?period=<?= $period_id ?>">Add bonus / deduction</a>
            </div>
        <?php else: ?>
            <div class="alert alert-success">
                <span>
                    <strong><?= htmlspecialchars($curPeriod['period_label']) ?> is finalized and locked.</strong>
                    <?php if (!empty($curPeriod['finalized_at'])): ?>
                        Finalized <?= date('M j, Y g:i A', strtotime($curPeriod['finalized_at'])) ?><?php
                        ?><?= (int)$rev['cycle'] > 1 ? ' (finalized ' . (int)$rev['cycle'] . '&times; in total)' : '' ?>.
                    <?php endif; ?>
                    No bonus or deduction can be added until you unlock it.
                </span>
                <a href="reports.php?period=<?= $period_id ?>">Issue payslips</a>
            </div>
        <?php endif; ?>

        <?php if ($rev['revised'] || $rev['entries']): ?>
        <!-- The period was corrected after a finalize. Both halves of that —
             the flagged payroll rows and the stamped history entries — are
             stored in the database, not derived from the screen. -->
        <div class="alert alert-warn">
            <span>
                <strong>This period was corrected after it had been finalized.</strong>
                <?= (int)$rev['revised'] ?> employee row(s) are flagged <strong>Revised</strong>
                from <?= (int)$rev['entries'] ?> bonus / deduction entr<?= $rev['entries'] === 1 ? 'y' : 'ies' ?>
                recorded after finalize.
            </span>
            <a href="history.php?period_id=<?= $period_id ?>">See what changed</a>
        </div>
        <?php endif; ?>

        <?php if ($payrollRows): ?>
        <div class="card-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-bottom:20px;">
            <div class="card card-accent">
                <div class="card-label">Employees</div>
                <div class="card-value"><?= count($payrollRows) ?></div>
            </div>
            <div class="card card-yellow">
                <div class="card-label">Bonuses Applied</div>
                <div class="card-value">&#8369;<?= number_format($payBonus, 2) ?></div>
            </div>
            <div class="card card-red">
                <div class="card-label">Deductions Applied</div>
                <div class="card-value">&#8369;<?= number_format($payDed, 2) ?></div>
            </div>
            <div class="card card-green">
                <div class="card-label">Total Net Pay</div>
                <div class="card-value">&#8369;<?= number_format($payNet, 2) ?></div>
            </div>
            <div class="card card-purple">
                <div class="card-label">Adjustment Entries</div>
                <div class="card-value"><?= $adjCount ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($drift): ?>
        <div class="alert alert-warn">
            <span>
                The payroll columns hold &#8369;<?= number_format($payBonus, 2) ?> bonus /
                &#8369;<?= number_format($payDed, 2) ?> deductions, but only
                &#8369;<?= number_format($adjBonus, 2) ?> / &#8369;<?= number_format($adjDed, 2) ?>
                is accounted for in the adjustment history. The difference was entered outside this page.
            </span>
            <a href="history.php?period_id=<?= $period_id ?>">Review history</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="box">
        <div class="box-header">
            <h2>Payroll Records</h2>
            <span style="font-size:.85rem;color:#6b7280;"><?= count($payrollRows) ?> employee(s)</span>
        </div>
        <div class="table-wrap">
            <table class="data-table" id="payrollTable">
                <thead>
                    <tr>
                        <th>ID</th><th>Name</th><th title="Days with hours, from day-by-day uploads">Days</th><th title="Unexcused absent days (deducted from salaried pay) / approved leave days (paid) / days off (never deducted)">Absent / Leave / Off</th><th>Hours</th><th>OT Hrs</th><th>Late Hrs</th><th title="Hours short of full duty days, and what they cost">UT Hrs</th>
                        <th title="Pay for the days worked, after undertime and absences">Basic Pay</th>
                        <th title="Overtime pay less late deductions">OT − Late</th>
                        <th title="Basic + OT − late — the timesheet's GROSS PAY">Gross Pay</th>
                        <th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th><th>Tax</th>
                        <th>Bonus</th><th>Deductions</th><th>Net Pay</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($payrollRows)): ?>
                    <tr>
                        <td colspan="19" style="text-align:center;color:#9ca3af;padding:30px;">
                            No attendance in this pay period yet.
                            <a href="attendance-upload.php?period=<?= $period_id ?>">Upload the attendance file</a> — payroll is computed as soon as it is saved.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payrollRows as $r): ?>
                    <?php $isRev = !empty($r['revised_after_finalize']); ?>
                    <tr class="<?= $isRev ? 'is-revised' : '' ?>">
                        <td><?= htmlspecialchars($r['emp_id']) ?></td>
                        <td><?= htmlspecialchars($r['emp_name']) ?></td>
                        <td><?= $daysWorked[$r['emp_id']] ?? '—' ?></td>
                        <td style="white-space:nowrap;">
                            <?php $ab = (float)($r['absent_days'] ?? 0); $lv = (float)($r['leave_days'] ?? 0); $of = (float)($r['days_off'] ?? 0);
                                  $n1 = fn($x) => rtrim(rtrim(number_format($x, 1), '0'), '.'); ?>
                            <span style="color:<?= $ab > 0 ? '#b91c1c' : '#9ca3af' ?>;"><?= $n1($ab) ?></span>
                            / <span style="color:<?= $lv > 0 ? '#1d4ed8' : '#9ca3af' ?>;"><?= $n1($lv) ?></span>
                            / <span style="color:#64748b;"><?= $n1($of) ?></span>
                            <?php if ((float)($r['absent_deduction'] ?? 0) > 0): ?>
                                <br><small style="color:#b91c1c;">−₱<?= number_format($r['absent_deduction'], 2) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($r['hours_worked'], 1) ?></td>
                        <td><?= number_format($r['overtime_hours'], 1) ?></td>
                        <td><?= number_format($r['late_hours'], 1) ?></td>
                        <td style="white-space:nowrap;"><?= number_format((float)($r['undertime_hours'] ?? 0), 1) ?>
                            <?php if ((float)($r['undertime_deduction'] ?? 0) > 0): ?>
                                <br><small style="color:#b91c1c;">−₱<?= number_format($r['undertime_deduction'], 2) ?></small>
                            <?php endif; ?></td>
                        <td>₱<?= number_format($r['gross_pay'] - $r['ot_late_adj'], 2) ?></td>
                        <td>₱<?= number_format($r['ot_late_adj'], 2) ?></td>
                        <td><strong>₱<?= number_format($r['gross_pay'], 2) ?></strong></td>
                        <td>₱<?= number_format($r['sss'], 2) ?></td>
                        <td>₱<?= number_format($r['philhealth'], 2) ?></td>
                        <td>₱<?= number_format($r['pagibig'], 2) ?></td>
                        <td>₱<?= number_format($r['withholding_tax'], 2) ?></td>
                        <td>₱<?= number_format($r['bonus'], 2) ?></td>
                        <td>₱<?= number_format($r['other_deductions'], 2) ?></td>
                        <td><strong>₱<?= number_format($r['net_pay'], 2) ?></strong></td>
                        <td style="white-space:nowrap;">
                            <span class="badge badge-<?= $r['status'] === 'Finalized' ? 'green' : 'blue' ?>">
                                <?= $r['status'] ?>
                            </span>
                            <?php if ($isRev): ?>
                                <span class="rev-tag"
                                      title="Changed after this period was finalized<?= !empty($r['revised_at']) ? ' — ' . date('M j, Y g:i A', strtotime($r['revised_at'])) : '' ?>">Revised</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($company): $peso = fn($v) => '&#8369;' . number_format($v, 2); ?>
    <!-- What this pay period costs the company, and what goes to each agency -->
    <div class="box" style="margin-top:20px;">
        <div class="box-header">
            <h2>Company Cost &amp; Remittances</h2>
            <span style="font-size:.82rem;color:#6b7280;">Employee shares are taken from pay; the company pays its share on top</span>
        </div>
        <div class="box-body">
            <div class="co-cards">
                <div class="co-card">
                    <div class="k">Pay earned</div>
                    <div class="v"><?= $peso($company['earnings']) ?></div>
                    <div class="s">Gross pay (overtime included) + bonuses</div>
                </div>
                <div class="co-card">
                    <div class="k">Company contributions</div>
                    <div class="v"><?= $peso($company['er_total']) ?></div>
                    <div class="s">SSS, EC, PhilHealth, Pag-IBIG — company share</div>
                </div>
                <div class="co-card total">
                    <div class="k">Total cost to the company</div>
                    <div class="v"><?= $peso($company['cost']) ?></div>
                    <div class="s">Pay earned + company contributions</div>
                </div>
                <div class="co-card">
                    <div class="k">Paid out to employees</div>
                    <div class="v"><?= $peso($payNet) ?></div>
                    <div class="s">Net pay, after their deductions</div>
                </div>
            </div>

            <?php if ($missingEarlier): ?>
            <div class="alert alert-info" style="margin-bottom:14px;">
                <span>The earlier cut-off of <?= date('F Y', strtotime($curPeriod['period_start'])) ?> is not in the system,
                so this month's contributions are based on this cut-off's pay only. Upload the 1st half too and these
                figures are recomputed when you upload this cut-off again.</span>
            </div>
            <?php endif; ?>

            <?php if ($company['stale']): ?>
            <div class="alert alert-warn" style="margin-bottom:14px;">
                <span>Settings or an employee's contribution switches changed after this pay run was computed.
                The company shares below follow the current settings — upload the attendance again to refresh the employee shares too.</span>
            </div>
            <?php endif; ?>

            <div class="table-wrap">
                <table class="data-table co-table" style="min-width:0;">
                    <thead>
                        <tr><th>Remit to</th><th class="num">Deducted from employees</th><th class="num">Company share</th><th class="num">Total to remit</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $rem = [
                            ['SSS', 'Regular SSS: employee 5%, company 10% of the salary credit', $company['ee']['sss'], $company['er']['sss']],
                            ['SSS — EC', "Employees' Compensation: company only, ₱10 or ₱30 a month", 0, $company['er']['ec']],
                            ['PhilHealth', '5% of monthly basic pay, split equally', $company['ee']['philhealth'], $company['er']['philhealth']],
                            ['Pag-IBIG', 'Employee 2%, company 2% of pay up to ₱10,000', $company['ee']['pagibig'], $company['er']['pagibig']],
                            ['BIR', 'Withholding tax on compensation — employee only', $company['ee']['tax'], 0],
                        ];
                        $tEE = $tER = 0;
                        foreach ($rem as [$who, $why, $ee, $er]): $tEE += $ee; $tER += $er; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($who) ?></strong><div class="co-sub"><?= htmlspecialchars($why) ?></div></td>
                            <td class="num"><?= $peso($ee) ?></td>
                            <td class="num"><?= $peso($er) ?></td>
                            <td class="num"><strong><?= $peso($ee + $er) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td>Total</td><td class="num"><?= $peso($tEE) ?></td><td class="num"><?= $peso($tER) ?></td><td class="num"><?= $peso($tEE + $tER) ?></td></tr>
                    </tfoot>
                </table>
            </div>

            <details class="co-more">
                <summary>Per employee — how each contribution was figured</summary>
                <p class="co-sub" style="margin:4px 0 10px;">
                    Contributions are monthly: each is read on the pay earned <b>so far this month</b> — SSS on all pay
                    (overtime included), PhilHealth and Pag-IBIG on basic pay — minus what earlier cut-offs already took.
                    This pay run: <?= htmlspecialchars($contribNote) ?>. So minimums such as PhilHealth's ₱250 are charged
                    once a month, never twice, and each month ends exact.
                </p>
                <div class="table-wrap">
                    <table class="data-table co-table">
                        <thead>
                            <tr>
                                <th>Employee</th><th class="num">Pay this month so far</th>
                                <th class="num">SSS<br><span class="co-sub">emp / co. + EC</span></th>
                                <th class="num">PhilHealth<br><span class="co-sub">emp / co.</span></th>
                                <th class="num">Pag-IBIG<br><span class="co-sub">emp / co.</span></th>
                                <th class="num">Company total</th><th class="num">Cost to company</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($company['rows'] as ['r' => $r, 'b' => $b, 'cost' => $cost]): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong><div class="co-sub"><?= htmlspecialchars($r['emp_id']) ?></div></td>
                                <td class="num"><?= $peso($b['comp']) ?>
                                    <div class="co-sub">basic <?= $peso($b['basic']) ?><?= $b['earlier']['g'] > 0 ? ' · earlier cut-offs ' . $peso($b['earlier']['g']) : '' ?></div></td>
                                <td class="num">
                                    <?php if ($b['on']['sss']): ?>
                                        <?= $peso($r['sss']) ?> / <?= $peso($b['er']['sss']) ?> + <?= $peso($b['er']['ec']) ?>
                                        <div class="co-sub">credit <?= $peso($b['msc']) ?><?= $b['due']['sss'] ? '' : ' · waits for the last cut-off' ?></div>
                                    <?php else: ?><span class="co-sub">not deducted</span><?php endif; ?>
                                </td>
                                <td class="num">
                                    <?php if ($b['on']['philhealth']): ?>
                                        <?= $peso($r['philhealth']) ?> / <?= $peso($b['er']['philhealth']) ?>
                                        <div class="co-sub"><?= $b['due']['philhealth']
                                            ? 'month: 2.5% of ' . $peso(max(PH_RULES['philhealth']['floor'], min($b['ph_basis'], PH_RULES['philhealth']['ceiling']))) . ' each'
                                            : 'waits for the last cut-off' ?><?= $b['earlier']['philhealth'] > 0 ? ' · ' . $peso($b['earlier']['philhealth']) . ' taken earlier' : '' ?></div>
                                    <?php else: ?><span class="co-sub">not deducted</span><?php endif; ?>
                                </td>
                                <td class="num">
                                    <?php if ($b['on']['pagibig']): ?>
                                        <?= $peso($r['pagibig']) ?> / <?= $peso($b['er']['pagibig']) ?>
                                        <?php if (!$b['due']['pagibig']): ?><div class="co-sub">waits for the last cut-off</div><?php endif; ?>
                                    <?php else: ?><span class="co-sub">not deducted</span><?php endif; ?>
                                </td>
                                <td class="num"><?= $peso($b['er_total']) ?></td>
                                <td class="num"><strong><?= $peso($cost) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($audit): ?>
    <!-- Finalize / re-open trail. Read straight from period_audit, so it
         survives logout, reprints and further corrections. -->
    <div class="box" style="margin-top:20px;">
        <div class="box-header">
            <h2>Finalize &amp; Revision Trail</h2>
            <span style="font-size:.85rem;color:#6b7280;"><?= count($audit) ?> event(s)</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>When</th><th>Event</th><th>Cycle</th><th>By</th><th>Detail</th></tr>
                </thead>
                <tbody>
                <?php foreach ($audit as $a): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= date('M j, Y g:i A', strtotime($a['created_at'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $a['action'] === 'Finalized' ? 'green'
                                                      : ($a['action'] === 'Reopened' ? 'yellow' : 'red') ?>">
                                <?= htmlspecialchars($a['action']) ?>
                            </span>
                        </td>
                        <td><?= (int)$a['cycle'] ?></td>
                        <td><?= htmlspecialchars($a['performed_by'] ?? '') ?></td>
                        <td style="font-size:.82rem;color:#4b5563;"><?= htmlspecialchars($a['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
window.PAYROLL_PAGE = {
    period_id:     <?= $period_id ?>,
    period_label:  <?= json_encode($curPeriod['period_label'] ?? '') ?>
};
</script>
<script src="assets/js/payroll.js"></script>
</body>
</html>
