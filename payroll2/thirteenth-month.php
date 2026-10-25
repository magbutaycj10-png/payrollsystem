<?php
/*
 * thirteenth-month.php - 13th Month Pay (Presidential Decree 851).
 *
 *   13th-month pay = total BASIC pay earned in the calendar year ÷ 12
 *
 * The page works the sheet out from the payroll that is already in the system (thirteenthMonthData() in includes/helpers.php),
 * shows every month so the figure can be checked, and records the payment as a Bonus on an OPEN pay period through the same
 * code as Bonus & Deductions (recordAdjustments()): one history row per employee, reason "13th Month Pay <year>", the
 * ₱90,000 tax-exempt ceiling watched, and a second click can never pay the same money twice because the balance shrinks.
 */
require 'includes/helpers.php';
requireAuth();

$activePage = 'thirteenth-month';
$db  = getDB();
$msg = null;
$heldForm = null;      /* employees held back by the ₱90,000 ceiling, to offer "Record anyway" */

/* The years that have payroll; the page opens on the newest */
$years = array_map('intval', $db->query(
    "SELECT DISTINCT YEAR(pp.start_date) AS y FROM payroll_periods pp
      WHERE EXISTS (SELECT 1 FROM payroll p WHERE p.period_id = pp.id) ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN));
$year = (int)($_POST['year'] ?? $_GET['year'] ?? ($years[0] ?? date('Y')));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

/* Open pay periods the payment can be added to (payslips of a finalized period are closed) */
$openPeriods = $db->query("SELECT * FROM payroll_periods WHERE status = 'Open' ORDER BY start_date DESC, id DESC")->fetchAll();

/* ── Record the payment ─────────────────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $target = null;
    foreach ($openPeriods as $p) { if ((int)$p['id'] === (int)($_POST['period_id'] ?? 0)) $target = $p; }
    $data     = thirteenthMonthData($db, $year);
    $amounts  = (array)($_POST['amount'] ?? []);
    $lines    = [];
    $problems = [];
    foreach ((array)($_POST['emp_ids'] ?? []) as $id) {
        $id  = trim((string)$id);
        $row = $data['rows'][$id] ?? null;
        if (!$row) { $problems[] = "$id has no pay in $year"; continue; }
        $n = numberOrNull(trim((string)($amounts[$id] ?? '')));
        if ($n === null || $n <= 0)              { $problems[] = $row['full_name'] . ': enter an amount above zero'; continue; }
        $n = round($n, 2);
        if ($n > $row['balance'] + 0.004)        { $problems[] = $row['full_name'] . ': ₱' . number_format($n, 2) . ' is more than the balance of ₱' . number_format(max(0, $row['balance']), 2); continue; }
        $lines[$id] = $n;
    }

    if (!$target) {
        $msg = ['type' => 'error', 'text' => 'Choose an open pay period to add the 13th-month pay to. (A finalized period is closed: unlock it in Payroll Processing first.)'];
    } elseif (!$lines) {
        $msg = ['type' => 'error', 'text' => 'Nothing was recorded. ' . ($problems ? implode('; ', $problems) . '.' : 'Tick at least one employee.')];
    } else {
        try {
            $res = recordAdjustments($db, $target, $lines, 'Bonus', "13th Month Pay $year", !empty($_POST['confirm_over_exempt']));
            $total = array_sum(array_intersect_key($lines, array_flip($res['applied_ids'])));
            $notes = '';
            if ($problems)       $notes .= ' Not recorded: ' . implode('; ', $problems) . '.';
            if ($res['skipped']) $notes .= ' No payroll line in ' . $target['period_label'] . ' for: ' . implode(', ', $res['skipped']) . ' - upload their attendance into that period first.';
            if ($res['over']) {
                $notes .= ' HELD BACK, because with this payment their ' . $year . ' bonuses pass the ₱' . number_format(BIR_EXEMPT_BENEFITS, 0)
                        . ' tax-exempt ceiling: ' . implode('; ', $res['over']) . '. The excess is taxable and this system does not withhold on a bonus - '
                        . 'record it only if the tax on the excess is being handled separately.';
                $heldForm = ['period_id' => (int)$target['id'], 'ids' => $res['over_ids'], 'amounts' => $lines];
            }
            if ($res['applied']) {
                $msg = ['type' => $res['over'] ? 'warn' : 'success',
                        'text' => "13th Month Pay $year of ₱" . number_format($total, 2) . ' recorded for ' . $res['applied'] . ' employee(s) in '
                                . $target['period_label'] . '. It appears as Bonus on their payslips and in Adjustment History.'
                                . ($res['is_revision'] ? ' This period was finalized before, so the entries are marked as a revision - finalize it again when you are done.' : '')
                                . $notes];
            } else {
                $msg = ['type' => 'error', 'text' => 'Nothing was recorded.' . $notes];
            }
        } catch (PDOException $e) {
            $msg = ['type' => 'error', 'text' => 'Nothing was saved. ' . friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'];
        }
    }
}

$data   = thirteenthMonthData($db, $year);
$rows   = $data['rows'];
$totals = $data['totals'];
$months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/* Which open period is the natural place for the payment: the one holding today, else the newest open one */
$defaultTarget = 0;
$today = date('Y-m-d');
foreach ($openPeriods as $p) { if ($p['start_date'] <= $today && $p['end_date'] >= $today) { $defaultTarget = (int)$p['id']; break; } }
if (!$defaultTarget && $openPeriods) $defaultTarget = (int)$openPeriods[0]['id'];

$deadline  = "$year-12-24";
$daysLeft  = (int)floor((strtotime($deadline) - strtotime($today)) / 86400);
$canPay    = (bool)$openPeriods;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>13th Month Pay - Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .rule { font-size: .85rem; color: #374151; line-height: 1.55; }
        .rule b { color: #111827; }
        .sheet th, .sheet td { white-space: nowrap; }
        .sheet td.num, .sheet th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .sheet .mo { font-size: .76rem; color: #4b5563; }
        .sheet tr.total td { font-weight: 700; background: #f9fafb; border-top: 2px solid var(--border); }
        .sheet tr.accrual td { font-size: .76rem; color: #6b7280; background: #fafafa; }
        .amt-in { width: 110px; text-align: right; padding: 4px 6px; }
        .pay-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .note { font-size: .74rem; color: #92400e; }
        .sheet-tools { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; padding: 12px 20px; border-bottom: 1px solid var(--border); }
        .sheet-tools .search-input { width: 240px; max-width: 100%; }
        .sheet-tools label.chk { display: inline-flex; gap: 6px; align-items: center; font-size: .85rem; font-weight: 600; cursor: pointer; }
        .sheet-tools .count { font-size: .8rem; color: #6b7280; margin-left: auto; }
        #printArea { display: none; }
        .pm-back { position: fixed; inset: 0; background: rgba(17,24,39,.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .pm-back.open { display: flex; }
        .pm-box { background: #fff; border-radius: 10px; padding: 22px 24px; width: 380px; max-width: 92vw; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
        .pm-box h3 { margin: 0 0 6px; font-size: 1.05rem; }
        .pm-box p { margin: 0 0 14px; font-size: .84rem; color: #4b5563; }
        .pm-box .pm-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
        @media print {
            body.printing > *:not(#printArea) { display: none !important; }
            body.printing #printArea { display: block; }
            #printArea .branch-sheet { page-break-after: always; }
            #printArea .branch-sheet:last-child { page-break-after: auto; }
            #printArea h2 { font-size: 12pt; margin: 0 0 2px; }
            #printArea .sub { font-size: 9pt; color: #374151; margin-bottom: 8px; }
            .no-print, .pay-col { display: none !important; }
            .sheet th, .sheet td { padding: 3px 5px; font-size: 8.5pt; }
            .box { box-shadow: none; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>13th Month Pay</h1>
            <p>Presidential Decree 851 - one twelfth of the basic pay earned in the calendar year</p>
        </div>
        <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                <label for="yearSel" style="font-weight:600;font-size:.875rem;">Year:</label>
                <select name="year" id="yearSel" class="form-control" style="width:auto;" onchange="this.form.submit()">
                    <?php foreach (array_unique(array_merge($years ?: [], [$year])) as $y): ?>
                        <option value="<?= (int)$y ?>" <?= (int)$y === $year ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button type="button" class="btn btn-print" onclick="openPrintDialog()">Print sheet</button>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <?php if ($heldForm): ?>
        <!-- Held by the ₱90,000 ceiling: only THESE employees are re-sent, so nobody already paid is paid twice -->
        <form method="POST" class="alert alert-warn no-print" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="action" value="pay">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="period_id" value="<?= (int)$heldForm['period_id'] ?>">
            <input type="hidden" name="confirm_over_exempt" value="1">
            <?php foreach ($heldForm['ids'] as $hid): ?>
                <input type="hidden" name="emp_ids[]" value="<?= htmlspecialchars($hid) ?>">
                <input type="hidden" name="amount[<?= htmlspecialchars($hid) ?>]" value="<?= htmlspecialchars(number_format((float)$heldForm['amounts'][$hid], 2, '.', '')) ?>">
            <?php endforeach; ?>
            <span>Record the 13th-month pay for <?= count($heldForm['ids']) ?> employee(s) anyway, without withholding tax on the part above the ceiling?</span>
            <button type="submit" class="btn btn-red">Record anyway</button>
        </form>
    <?php endif; ?>

    <div class="box" style="margin-bottom:20px;">
        <div class="box-body rule">
            <b>How it is worked out.</b> 13th-month pay = the <b>basic pay earned in <?= $year ?></b> &divide; 12. &ldquo;Basic pay&rdquo; is the
            <i>Basic Pay</i> column of the payroll: pay for the days worked and paid leave, with absences and undertime already taken out -
            <b>overtime, bonuses and allowances are not part of it</b>. Someone who worked only part of the year gets a proportional amount
            (fewer months in the total); someone who has left is still listed because it is due on separation. It is due
            <b>not later than December 24</b> and may be paid in two instalments - record what you pay now, the balance stays here.
            13th-month pay and other benefits are <b>tax-exempt up to &#8369;<?= number_format(BIR_EXEMPT_BENEFITS, 0) ?></b> a year per employee together;
            this system does not withhold tax on a bonus, so it stops and asks before a payment would cross that line.
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="alert alert-warn">There is no payroll for <?= $year ?> yet, so there is nothing to compute. Choose another year, or upload attendance and process payroll first.</div>
    <?php else: ?>

    <?php if ($data['open_periods'] > 0): ?>
        <div class="alert alert-info"><div>
            <?= (int)$data['open_periods'] ?> of the <?= (int)$data['periods'] ?> pay period(s) of <?= $year ?> are still <b>open</b>, so these figures can still change.
            Payroll in the system runs through <b><?= date('M j, Y', strtotime($data['last_end'])) ?></b><?php if ($data['last_end'] < "$year-12-31"): ?>
            - the rest of the year is not in yet, so the 13th month shown is <b>to date</b> and will grow<?php endif; ?>.
        </div></div>
    <?php elseif ($data['last_end'] < "$year-12-31"): ?>
        <div class="alert alert-info"><div>Payroll in the system runs through <b><?= date('M j, Y', strtotime($data['last_end'])) ?></b>; the 13th month shown is <b>to date</b>.</div></div>
    <?php endif; ?>

    <?php if ($year === (int)date('Y') && $totals['balance'] > 0): ?>
        <div class="alert <?= $daysLeft < 0 ? 'alert-error' : ($daysLeft <= 30 ? 'alert-warn' : 'alert-info') ?>"><div>
            <?php if ($daysLeft < 0): ?>
                <b>The December 24 deadline has passed</b> and &#8369;<?= number_format($totals['balance'], 2) ?> of <?= $year ?> 13th-month pay is still unpaid.
            <?php else: ?>
                <b><?= $daysLeft ?> day(s)</b> until the December 24 deadline - &#8369;<?= number_format($totals['balance'], 2) ?> of 13th-month pay is still to be paid.
            <?php endif; ?>
        </div></div>
    <?php endif; ?>

    <div class="card-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-bottom:20px;">
        <div class="card card-accent"><div class="card-label">Employees</div><div class="card-value"><?= count($rows) ?></div></div>
        <div class="card card-green"><div class="card-label">Basic pay earned <?= $year ?></div><div class="card-value">&#8369;<?= number_format($totals['basic'], 2) ?></div></div>
        <div class="card card-sky"><div class="card-label">13th month due (&divide; 12)</div><div class="card-value">&#8369;<?= number_format($totals['due'], 2) ?></div></div>
        <div class="card card-yellow"><div class="card-label">Paid so far</div><div class="card-value">&#8369;<?= number_format($totals['paid'], 2) ?></div></div>
        <div class="card card-red"><div class="card-label">Balance to pay</div><div class="card-value">&#8369;<?= number_format($totals['balance'], 2) ?></div></div>
    </div>

    <form method="POST" id="payForm">
        <input type="hidden" name="action" value="pay">
        <input type="hidden" name="year" value="<?= $year ?>">

        <div class="box">
            <div class="box-header">
                <h2>Computation sheet <?= $year ?></h2>
                <div class="pay-bar no-print">
                    <?php if ($canPay): ?>
                        <label for="periodSel" style="font-size:.85rem;font-weight:600;">Add the payment to:</label>
                        <select name="period_id" id="periodSel" class="form-control" style="width:auto;min-width:210px;">
                            <?php foreach ($openPeriods as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $defaultTarget ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['period_label']) ?> · <?= htmlspecialchars(periodTypeLabel($p)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="payAll('balance')" title="Tick everyone shown with a balance and fill in the whole balance">Pay full balance</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="payAll('half')" title="For everyone shown: half of the 13th month, less what was already paid - the usual mid-year instalment">Pay half (advance)</button>
                        <button type="submit" class="btn btn-primary" onclick="return confirmPay()">Record 13th Month Pay</button>
                    <?php else: ?>
                        <span class="note">No open pay period: create or unlock one to record the payment.</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            $branchList = array_values(array_unique(array_filter(array_column($rows, 'branch'), 'strlen')));
            natcasesort($branchList);
            $noBranch = in_array('', array_column($rows, 'branch'), true);
            ?>
            <div class="sheet-tools no-print">
                <input type="search" id="sheetSearch" class="form-control search-input" placeholder="Search name or ID…" aria-label="Search employee by name or ID" oninput="applyFilter()">
                <select id="branchSel" class="form-control" style="width:auto;min-width:170px;" aria-label="Branch" onchange="applyFilter()">
                    <option value="*">All branches</option>
                    <?php foreach ($branchList as $b): ?>
                        <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                    <?php endforeach; ?>
                    <?php if ($noBranch): ?><option value="">(No branch)</option><?php endif; ?>
                </select>
                <?php if ($canPay): ?>
                    <label class="chk" title="Tick everyone shown who still has a balance"><input type="checkbox" id="selAll" onchange="selectAll(this.checked)"> Select all</label>
                    <label class="chk" title="Tick every Active employee shown who still has a balance"><input type="checkbox" id="selActive" onchange="selectActive(this.checked)"> All active</label>
                <?php endif; ?>
                <span class="count" id="sheetCount"></span>
            </div>
            <div class="table-wrap">
                <table class="data-table sheet">
                    <thead>
                        <tr>
                            <th class="pay-col no-print"></th>
                            <th>ID</th><th>Name</th><th>Branch</th><th>Status</th>
                            <?php foreach ($months as $mn): ?><th class="num mo"><?= $mn ?></th><?php endforeach; ?>
                            <th class="num">Basic pay <?= $year ?></th>
                            <th class="num">13th month</th>
                            <th class="num">Paid</th>
                            <th class="num">Balance</th>
                            <th class="pay-col no-print num">Pay now (&#8369;)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $id => $r): $owed = $r['balance'] > 0.004; ?>
                        <tr data-due="<?= number_format($r['due'], 2, '.', '') ?>" data-paid="<?= number_format($r['paid'], 2, '.', '') ?>" data-balance="<?= number_format(max(0, $r['balance']), 2, '.', '') ?>"
                            data-branch="<?= htmlspecialchars($r['branch']) ?>" data-status="<?= htmlspecialchars($r['status']) ?>" data-find="<?= htmlspecialchars($id . ' ' . $r['full_name']) ?>">
                            <td class="pay-col no-print"><?php if ($owed && $canPay): ?><input type="checkbox" name="emp_ids[]" value="<?= htmlspecialchars($id) ?>" class="pick" onchange="rowToggle(this)"><?php endif; ?></td>
                            <td><?= htmlspecialchars($id) ?></td>
                            <td><strong><?= htmlspecialchars($r['full_name']) ?></strong>
                                <?php if ($r['taxable_excess'] > 0): ?><div class="note" title="Bonuses plus this 13th month pass &#8369;<?= number_format(BIR_EXEMPT_BENEFITS, 0) ?>; the excess is taxable and is not withheld here">taxable excess &#8369;<?= number_format($r['taxable_excess'], 2) ?></div><?php endif; ?>
                            </td>
                            <td><?= $r['branch'] !== '' ? htmlspecialchars($r['branch']) : '-' ?></td>
                            <td><span class="badge badge-<?= $r['status'] === 'Active' ? 'green' : 'yellow' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                            <?php foreach ($r['months'] as $v): ?><td class="num mo"><?= $v > 0 ? number_format($v, 2) : '-' ?></td><?php endforeach; ?>
                            <td class="num"><?= number_format($r['basic'], 2) ?></td>
                            <td class="num"><strong><?= number_format($r['due'], 2) ?></strong></td>
                            <td class="num"><?= number_format($r['paid'], 2) ?></td>
                            <td class="num" style="<?= $r['balance'] < -0.004 ? 'color:#b91c1c;' : '' ?>"><?= $r['balance'] < -0.004 ? 'overpaid ' . pesoFmt(-$r['balance']) : number_format($r['balance'], 2) ?></td>
                            <td class="pay-col no-print num"><?php if ($owed && $canPay): ?><input type="number" step="0.01" min="0.01" max="<?= number_format($r['balance'], 2, '.', '') ?>" name="amount[<?= htmlspecialchars($id) ?>]"
                                value="<?= number_format($r['balance'], 2, '.', '') ?>" class="form-control amt-in" disabled><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total">
                            <td class="pay-col no-print"></td><td></td><td>TOTAL</td><td></td><td></td>
                            <?php foreach ($months as $mi => $mn): $sum = 0; foreach ($rows as $r) $sum += $r['months'][$mi]; ?><td class="num mo"><?= number_format($sum, 2) ?></td><?php endforeach; ?>
                            <td class="num"><?= number_format($totals['basic'], 2) ?></td>
                            <td class="num"><?= number_format($totals['due'], 2) ?></td>
                            <td class="num"><?= number_format($totals['paid'], 2) ?></td>
                            <td class="num"><?= number_format($totals['balance'], 2) ?></td>
                            <td class="pay-col no-print"></td>
                        </tr>
                        <tr class="accrual">
                            <td class="pay-col no-print"></td><td></td><td colspan="3">Set aside each month (basic &divide; 12)</td>
                            <?php foreach ($months as $mi => $mn): ?><td class="num"><?= number_format($totals['accrual'][$mi], 2) ?></td><?php endforeach; ?>
                            <td class="num"><?= number_format(array_sum($totals['accrual']), 2) ?></td><td colspan="3"></td><td class="pay-col no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </form>

    <?php endif; ?>
</div>

<?php if ($rows): ?>
<div class="pm-back" id="printDlg" onclick="if (event.target === this) closePrintDialog()">
    <div class="pm-box" role="dialog" aria-modal="true" aria-labelledby="pmTitle">
        <h3 id="pmTitle">Print 13th Month sheet</h3>
        <p>Choose the branch to print. &ldquo;All branches&rdquo; prints every branch, each on its own page.</p>
        <select id="printBranch" class="form-control" style="width:100%;" aria-label="Branch to print">
            <option value="*">All branches</option>
            <?php foreach ($branchList as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
            <?php endforeach; ?>
            <?php if ($noBranch): ?><option value="">(No branch)</option><?php endif; ?>
        </select>
        <div class="pm-actions">
            <button type="button" class="btn btn-ghost" onclick="closePrintDialog()">Cancel</button>
            <button type="button" class="btn btn-print" onclick="printSheet()">Print</button>
        </div>
    </div>
</div>
<div id="printArea"></div>
<?php endif; ?>

<script>
function openPrintDialog() {
    var sel = document.getElementById('branchSel'), pb = document.getElementById('printBranch');
    if (pb && sel) pb.value = sel.value;
    document.getElementById('printDlg').classList.add('open');
}
function closePrintDialog() { document.getElementById('printDlg').classList.remove('open'); }
function cellNum(td) {
    var t = td.textContent, n = parseFloat(t.replace(/[^0-9.\-]/g, ''));
    if (isNaN(n)) return 0;
    return /overpaid/i.test(t) ? -n : n;
}
/* One sheet per branch (a branch is never mixed with another); "All branches" simply prints every branch in turn */
function printSheet() {
    var choice = document.getElementById('printBranch').value;
    var table = document.querySelector('#payForm table.sheet');
    var all = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var branches = [];
    all.forEach(function (tr) { if (branches.indexOf(tr.dataset.branch) === -1) branches.push(tr.dataset.branch); });
    branches.sort(function (a, b) { return (a === '' ? 1 : 0) - (b === '' ? 1 : 0) || a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }); });
    if (choice !== '*') branches = [choice];

    var area = document.getElementById('printArea');
    area.innerHTML = '';
    var heads = table.querySelectorAll('thead th:not(.pay-col)');
    branches.forEach(function (b) {
        var trs = all.filter(function (tr) { return tr.dataset.branch === b; });
        if (!trs.length) return;
        var wrap = document.createElement('div');
        wrap.className = 'branch-sheet';
        var h = document.createElement('h2');
        h.textContent = '13th Month Pay <?= $year ?> - ' + (b === '' ? '(No branch)' : b);
        var sub = document.createElement('div');
        sub.className = 'sub';
        sub.textContent = trs.length + ' employee(s) · printed ' + new Date().toLocaleDateString();
        var t = document.createElement('table');
        t.className = 'data-table sheet';
        var thead = document.createElement('thead'), hr = document.createElement('tr');
        heads.forEach(function (th) { hr.appendChild(th.cloneNode(true)); });
        thead.appendChild(hr); t.appendChild(thead);
        var tbody = document.createElement('tbody'), sums = [];
        trs.forEach(function (tr) {
            var c = tr.cloneNode(true);
            c.style.display = '';
            c.querySelectorAll('.pay-col').forEach(function (x) { x.remove(); });
            Array.prototype.forEach.call(c.children, function (td, i) { if (i >= 4) sums[i] = (sums[i] || 0) + cellNum(td); });
            tbody.appendChild(c);
        });
        t.appendChild(tbody);
        var tf = document.createElement('tfoot'), fr = document.createElement('tr');
        fr.className = 'total';
        for (var i = 0; i < heads.length; i++) {
            var td = document.createElement('td');
            if (i === 1) td.textContent = 'TOTAL';
            if (i >= 4) { td.className = 'num'; td.textContent = (sums[i] || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
            fr.appendChild(td);
        }
        tf.appendChild(fr); t.appendChild(tf);
        wrap.appendChild(h); wrap.appendChild(sub);
        var tw = document.createElement('div'); tw.className = 'table-wrap'; tw.appendChild(t); wrap.appendChild(tw);
        area.appendChild(wrap);
    });
    closePrintDialog();
    document.body.classList.add('printing');
    var done = function () { document.body.classList.remove('printing'); area.innerHTML = ''; window.removeEventListener('afterprint', done); };
    window.addEventListener('afterprint', done);
    window.print();
}
/* Rows on screen after the search box and the branch filter. Ticks on rows that are filtered out
   are kept (search one name, tick, search the next), and the counter says how many are ticked. */
function shownRows() {
    return Array.prototype.filter.call(document.querySelectorAll('#payForm tbody tr'), function (tr) { return tr.style.display !== 'none'; });
}
function applyFilter() {
    var words = document.getElementById('sheetSearch').value.toLowerCase().split(/\s+/).filter(Boolean);
    var branch = document.getElementById('branchSel').value;
    document.querySelectorAll('#payForm tbody tr').forEach(function (tr) {
        var find = tr.dataset.find.toLowerCase();
        var ok = (branch === '*' || tr.dataset.branch === branch)
              && words.every(function (w) { return find.indexOf(w) !== -1; });
        tr.style.display = ok ? '' : 'none';
    });
    syncChecks();
}
function syncChecks() {
    var rows = shownRows(), all = document.getElementById('selAll'), act = document.getElementById('selActive');
    var picks = rows.map(function (tr) { return tr.querySelector('.pick'); }).filter(Boolean);
    var active = rows.filter(function (tr) { return tr.dataset.status === 'Active'; })
                     .map(function (tr) { return tr.querySelector('.pick'); }).filter(Boolean);
    var every = function (list) { return list.length > 0 && list.every(function (b) { return b.checked; }); };
    if (all) { all.checked = every(picks); all.disabled = !picks.length; }
    if (act) { act.checked = every(active); act.disabled = !active.length; }
    var total = document.querySelectorAll('#payForm tbody tr').length;
    var ticked = document.querySelectorAll('#payForm .pick:checked').length;
    document.getElementById('sheetCount').textContent = 'Showing ' + rows.length + ' of ' + total + (all ? ' · ' + ticked + ' ticked' : '');
}
function rowToggle(box) {
    var tr = box.closest('tr'), amt = tr.querySelector('.amt-in');
    if (amt) amt.disabled = !box.checked;
    syncChecks();
}
function setPick(tr, on) {
    var b = tr.querySelector('.pick'), amt = tr.querySelector('.amt-in');
    if (!b) return;
    b.checked = on;
    if (amt) amt.disabled = !on;
}
function selectAll(on) {
    shownRows().forEach(function (tr) { setPick(tr, on); });
    syncChecks();
}
function selectActive(on) {
    shownRows().forEach(function (tr) { if (tr.dataset.status === 'Active') setPick(tr, on); });
    syncChecks();
}
/* fill the amounts of the rows shown: the whole balance, or half of the 13th month less what was already paid (never above the balance) */
function payAll(mode) {
    shownRows().forEach(function (tr) {
        var box = tr.querySelector('.pick'), amt = tr.querySelector('.amt-in');
        if (!box || !amt) return;
        var due = parseFloat(tr.dataset.due), paid = parseFloat(tr.dataset.paid), bal = parseFloat(tr.dataset.balance);
        var v = mode === 'half' ? Math.min(bal, Math.max(0, Math.round((due / 2 - paid) * 100) / 100)) : bal;
        box.checked = v > 0;
        amt.disabled = !box.checked;
        amt.value = v.toFixed(2);
    });
    syncChecks();
}
if (document.getElementById('sheetSearch')) applyFilter();
function confirmPay() {
    var n = document.querySelectorAll('#payForm .pick:checked').length;
    if (!n) { alert('Tick at least one employee (or press "Pay full balance").'); return false; }
    var sel = document.getElementById('periodSel');
    return confirm('Record 13th Month Pay for ' + n + ' employee(s) in ' + sel.options[sel.selectedIndex].text.trim() + '?\n\nIt is added to their payslips as Bonus and written to Adjustment History.');
}
</script>
</body>
</html>
