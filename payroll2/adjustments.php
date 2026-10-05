<?php
/*
 * adjustments.php — Step 3 of the payroll cycle.
 *
 * Bonuses and deductions always belong to ONE payroll period (the "month"),
 * and they are only ever accepted while that period is still Open:
 *
 *   Open    → the entry is written to bonus_deduction_history AND folded into
 *             the employee's payroll row (bonus / other_deductions / net_pay)
 *             in a single transaction, so history and payroll can never drift.
 *   Locked  → refused. The period has been finalized; to correct it you unlock
 *             it in Payroll Processing (step 2), add the entry here, then
 *             finalize again. That re-finalize picks up the new figures because
 *             the payroll row was already updated while the period was open.
 *
 * Only employees who actually have a payroll row in the selected period can be
 * adjusted — there is nothing to add a bonus to otherwise.
 */

require 'includes/helpers.php';
requireAuth();

$activePage = 'adjustments';
$db  = getDB();
$msg = null;

$periods = $db->query("SELECT * FROM payroll_periods ORDER BY period_start DESC")->fetchAll();

/*
 * Working period: an explicit choice wins, otherwise fall back to the newest
 * Open period (the one being worked on), otherwise just the newest.
 */
$wanted    = (int)($_POST['period_id'] ?? $_GET['period'] ?? 0);
$curPeriod = null;
foreach ($periods as $p) { if ((int)$p['id'] === $wanted) { $curPeriod = $p; break; } }
if (!$curPeriod) { foreach ($periods as $p) { if ($p['status'] === 'Open') { $curPeriod = $p; break; } } }
if (!$curPeriod) { $curPeriod = $periods[0] ?? null; }

$period_id = (int)($curPeriod['id'] ?? 0);
$isOpen    = $curPeriod && $curPeriod['status'] === 'Open';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp_ids = $_POST['emp_ids'] ?? [];
    $type    = ($_POST['entry_type'] ?? 'Bonus') === 'Deduction' ? 'Deduction' : 'Bonus';
    $amount  = round((float)($_POST['amount'] ?? 0), 2);

    $reason_select = $_POST['reason_select'] ?? '';
    $reason = $reason_select === '__custom__'
        ? trim($_POST['reason_custom'] ?? '')
        : trim($reason_select);

    if (!$curPeriod) {
        $msg = ['type' => 'error', 'text' => 'Create a payroll period first before recording a bonus or deduction.'];

    } elseif (!$isOpen) {
        /* The guard the whole page exists for. */
        $msg = ['type' => 'error', 'text' =>
            $curPeriod['period_label'] . ' is already finalized, so nothing can be added to or deducted from it. '
          . 'Unlock the period in Payroll Processing, record the adjustment here, then finalize it again.'];

    } elseif (empty($emp_ids) || $amount <= 0 || $reason === '') {
        $msg = ['type' => 'error', 'text' => 'Select at least one employee, enter an amount above zero, and choose a reason.'];

    } else {
        $who = currentActor();

        /*
         * Which finalize cycle is this? 0 means the period has never been
         * closed, so the entry belongs to the normal first pass. Above 0 means
         * the period was finalized, re-opened, and is now being corrected —
         * that fact is stamped on both the history row (finalize_cycle) and the
         * payroll row (revised_after_finalize) so the correction stays visible
         * long after the period is locked again.
         */
        $cycle      = (int)($curPeriod['finalize_count'] ?? 0);
        $isRevision = $cycle > 0;

        $getPR = $db->prepare(
            "SELECT id, emp_id, emp_name, gross_pay, withholding_tax, ot_late_adj,
                    sss, philhealth, pagibig, bonus, other_deductions
               FROM payroll WHERE period_id = ? AND emp_id = ?"
        );
        /* status stays Draft: the period is open, so these rows are not final */
        $updPR = $db->prepare(
            "UPDATE payroll SET bonus = ?, other_deductions = ?, net_pay = ?, status = 'Draft' WHERE id = ?"
        );
        /* Same update plus the revision stamp, used during a correction pass. */
        $updPRrev = $db->prepare(
            "UPDATE payroll SET bonus = ?, other_deductions = ?, net_pay = ?, status = 'Draft',
                    revised_after_finalize = 1, revised_at = NOW()
              WHERE id = ?"
        );
        $histIns = $db->prepare(
            "INSERT INTO bonus_deduction_history
             (entry_date, emp_id, emp_name, entry_type, amount, reason, processed_by, period_id, finalize_cycle)
             VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $applied = 0;
        $skipped = [];

        try {
            /* History row and payroll row are written together or not at all. */
            $db->beginTransaction();

            foreach ($emp_ids as $eid) {
                $eid = trim((string)$eid);
                if ($eid === '') continue;

                $getPR->execute([$period_id, $eid]);
                $row = $getPR->fetch();
                if (!$row) { $skipped[] = $eid; continue; }

                $bonus = (float)$row['bonus'];
                $ded   = (float)$row['other_deductions'];
                if ($type === 'Bonus') $bonus += $amount; else $ded += $amount;

                /* net = gross (overtime already in it) + bonus − (tax + SSS + PhilHealth + Pag-IBIG + deductions) */
                $net = ((float)$row['gross_pay'] + $bonus)
                     - ((float)$row['withholding_tax'] + (float)$row['sss']
                      + (float)$row['philhealth'] + (float)$row['pagibig'] + $ded);

                ($isRevision ? $updPRrev : $updPR)->execute([$bonus, $ded, round($net, 2), $row['id']]);
                $histIns->execute([$eid, $row['emp_name'], $type, $amount, $reason, $who, $period_id, $cycle]);
                $applied++;
            }

            $db->commit();

            /* The audit trail records the correction itself, not just its effect. */
            if ($applied && $isRevision) {
                logPeriodAudit($period_id, 'Revised', $cycle,
                    "$type of PHP " . number_format($amount, 2) . " ($reason) applied to $applied employee(s) "
                  . "after finalize #$cycle.");
            }

            $text = "$type of \u{20B1}" . number_format($amount, 2) . " applied to $applied employee(s) for "
                  . $curPeriod['period_label'] . '. Payroll and history both updated.';
            if ($isRevision && $applied) {
                $text .= " This period was already finalized {$cycle}\u{00D7}, so the change is recorded as a revision"
                       . ' and those employees are now flagged Revised. Finalize the period again when you are done.';
            }
            if ($skipped) {
                $text .= ' Skipped ' . count($skipped) . ' employee(s) with no payroll record in this period: '
                       . implode(', ', $skipped) . '.';
            }
            $msg = ['type' => $applied ? 'success' : 'warn', 'text' => $applied ? $text
                : 'Nothing applied — none of the selected employees have a payroll record in ' . $curPeriod['period_label'] . '.'];

        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $msg = ['type' => 'error', 'text' => 'Nothing was saved. ' . friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'];
        }
    }
}

/*
 * Roster for the selected period. Driven by the payroll table, not the employee
 * table, so the list is exactly who can be adjusted — and each card can show
 * the figures the adjustment will change.
 */
$roster = [];
if ($period_id) {
    $st = $db->prepare(
        "SELECT p.emp_id, p.emp_name, p.bonus, p.other_deductions, p.net_pay,
                p.revised_after_finalize, e.position
           FROM payroll p
      LEFT JOIN employees e ON e.emp_id = p.emp_id
          WHERE p.period_id = ?
       ORDER BY p.emp_name ASC"
    );
    $st->execute([$period_id]);
    $roster = $st->fetchAll();
}

/* Has this period been finalized and re-opened? Drives the revision notice. */
$rev = periodRevisionInfo($period_id);

/* What has already been recorded against this period. */
$adjBonus = $adjDed = 0.0;
$adjCount = 0;
if ($period_id) {
    $st = $db->prepare(
        "SELECT entry_type, COUNT(*) AS c, SUM(amount) AS s
           FROM bonus_deduction_history WHERE period_id = ? GROUP BY entry_type"
    );
    $st->execute([$period_id]);
    foreach ($st->fetchAll() as $r) {
        if ($r['entry_type'] === 'Bonus') $adjBonus = (float)$r['s']; else $adjDed = (float)$r['s'];
        $adjCount += (int)$r['c'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bonus &amp; Deductions — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .emp-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 8px; max-height: 440px; overflow-y: auto; padding: 2px;
        }
        .emp-card {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 8px;
            cursor: pointer; transition: border-color .15s, background .15s; user-select: none;
        }
        .emp-card:hover    { border-color: var(--accent); background: #eff6ff; }
        .emp-card.selected { border-color: var(--accent); background: #dbeafe; }
        .emp-card input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--accent); flex-shrink: 0; }
        .emp-id   { font-size: .72rem; font-weight: 700; color: var(--text-muted); }
        .emp-name { font-size: .875rem; font-weight: 600; }
        .emp-fig  { font-size: .72rem; color: var(--text-muted); margin-top: 2px; }
        .emp-fig b { color: #111827; font-weight: 600; }
        .step-label {
            font-size: .7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; color: var(--accent); margin-bottom: 2px;
        }
        /* Everything in the entry form is inert while the period is locked */
        .locked-form { opacity: .55; pointer-events: none; }
        /* Marks pay that was changed after the period had been finalized */
        .rev-tag {
            display: inline-block; margin-left: 4px; padding: 1px 6px;
            border-radius: 999px; background: #fef3c7; color: #92400e;
            font-size: .64rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; vertical-align: middle;
        }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Bonus &amp; Deductions</h1>
            <p>Record bonuses and deductions against an open payroll period</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="history.php?period_id=<?= $period_id ?>" class="btn btn-ghost">View History</a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <!-- Period picker: reloads the page so the roster below matches the period -->
    <div class="box" style="margin-bottom:20px;">
        <div class="box-body" style="padding:14px 20px;">
            <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="font-weight:600;font-size:.875rem;">Period (month):</label>
                <select name="period" class="form-control" style="min-width:230px;" onchange="this.form.submit()">
                    <?php if (empty($periods)): ?>
                        <option>— no periods yet —</option>
                    <?php endif; ?>
                    <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === $period_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['period_label']) ?> · <?= htmlspecialchars(periodTypeLabel($p)) ?>
                        <?= $p['status'] === 'Open' ? '— Open' : '— Finalized' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($curPeriod): ?>
                    <span class="badge badge-<?= $isOpen ? 'blue' : 'green' ?>"><?= $isOpen ? 'Open' : 'Finalized' ?></span>
                    <span style="font-size:.82rem;color:#6b7280;">
                        <?= date('M j', strtotime($curPeriod['period_start'])) ?>
                        &ndash; <?= date('M j, Y', strtotime($curPeriod['period_end'])) ?>
                        &nbsp;&bull;&nbsp; <?= count($roster) ?> employee(s) on payroll
                        &nbsp;&bull;&nbsp; <?= $adjCount ?> adjustment(s) recorded
                        (bonus &#8369;<?= number_format($adjBonus, 2) ?> / deduction &#8369;<?= number_format($adjDed, 2) ?>)
                    </span>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (!$curPeriod): ?>
        <div class="alert alert-warn">
            There are no payroll periods yet. Upload an attendance file to create one.
            <a href="attendance-upload.php">Upload Attendance</a>
        </div>

    <?php elseif (!$isOpen): ?>
        <div class="alert alert-warn">
            <span>
                <strong><?= htmlspecialchars($curPeriod['period_label']) ?> is finalized.</strong>
                Nothing can be added or deducted while a period is locked. Unlock it in Payroll Processing,
                record the adjustment here, then finalize the period again.
            </span>
            <a href="payroll.php?period=<?= $period_id ?>">Go to Payroll Processing</a>
        </div>

    <?php elseif (empty($roster)): ?>
        <div class="alert alert-warn">
            <span><?= htmlspecialchars($curPeriod['period_label']) ?> is open but has no payroll records yet,
            so there is nothing to adjust.</span>
            <a href="attendance-upload.php">Upload Attendance</a>
        </div>
    <?php endif; ?>

    <?php if ($isOpen && $rev['reopened']): ?>
        <!-- Correction pass: the period has been closed before, so anything
             recorded from here on is stored as a revision, not a first entry. -->
        <div class="alert alert-info">
            <span>
                <strong>Correction pass &mdash; <?= htmlspecialchars($curPeriod['period_label']) ?>
                was finalized <?= (int)$rev['cycle'] ?>&times; and re-opened <?= (int)$rev['reopen_count'] ?>&times;.</strong>
                Everything you record now is saved with a <em>revision</em> stamp and the employees affected are
                flagged <strong>Revised</strong> on Payroll Processing, Reports and their own payslip.
                <?php if ($rev['entries']): ?>
                    <?= (int)$rev['entries'] ?> entr<?= $rev['entries'] === 1 ? 'y has' : 'ies have' ?>
                    already been recorded as corrections.
                <?php endif; ?>
            </span>
            <a href="history.php?period_id=<?= $period_id ?>">Review corrections</a>
        </div>
    <?php endif; ?>

    <form method="POST" id="adjForm" onsubmit="return validateForm()"
          class="<?= ($isOpen && $roster) ? '' : 'locked-form' ?>">
        <input type="hidden" name="period_id" value="<?= $period_id ?>">

        <!-- Step 1: Details -->
        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">
                <div>
                    <div class="step-label">Step 1</div>
                    <h2>Adjustment Details</h2>
                </div>
                <span style="font-size:.85rem;color:#6b7280;">
                    Applies to <strong><?= htmlspecialchars($curPeriod['period_label'] ?? '—') ?></strong>
                </span>
            </div>
            <div class="box-body">
                <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(210px,1fr));">
                    <div class="form-group">
                        <label>Type</label>
                        <select name="entry_type" id="typeSelect" class="form-control" onchange="updateReasonOptions()">
                            <option value="Bonus">Bonus</option>
                            <option value="Deduction">Deduction</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (₱) *</label>
                        <input type="number" name="amount" class="form-control" min="0.01" step="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Reason *</label>
                        <select name="reason_select" id="reasonSelect" class="form-control" onchange="toggleCustomReason()">
                            <option value="">— Select a reason —</option>
                            <optgroup id="bonusReasons" label="Common Bonus Reasons">
                                <option>Performance Reward</option>
                                <option>13th Month Pay</option>
                                <option>Holiday Bonus</option>
                                <option>Overtime Incentive</option>
                                <option>Attendance Bonus</option>
                                <option>Loyalty Bonus</option>
                            </optgroup>
                            <optgroup id="dedReasons" label="Common Deduction Reasons" style="display:none;">
                                <option>Late Penalty</option>
                                <option>Absence Deduction</option>
                                <option>Loan Repayment</option>
                                <option>Uniform / ID Deduction</option>
                                <option>Cash Advance</option>
                                <option>Other Deduction</option>
                            </optgroup>
                            <option value="__custom__">Enter custom reason...</option>
                        </select>
                        <input type="text" name="reason_custom" id="reasonCustom"
                               class="form-control" placeholder="Type custom reason…"
                               style="display:none;margin-top:6px;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Select Employees -->
        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">
                <div>
                    <div class="step-label">Step 2</div>
                    <h2>Select Employees</h2>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" id="empSearch" class="search-input" placeholder="Search by name or ID…"
                           oninput="filterEmployees()" style="width:200px;">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="selectAll()">Select All</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="clearAll()">Clear All</button>
                    <span id="selCount" style="font-size:.85rem;color:#6b7280;min-width:80px;">0 selected</span>
                </div>
            </div>
            <div class="box-body">
                <?php if (empty($roster)): ?>
                    <p style="color:#9ca3af;text-align:center;padding:20px 0;">
                        No employees on payroll for this period.
                    </p>
                <?php else: ?>
                <div class="emp-grid" id="empGrid">
                    <?php foreach ($roster as $e): ?>
                    <label class="emp-card" data-search="<?= strtolower(htmlspecialchars($e['emp_id'] . ' ' . $e['emp_name'])) ?>">
                        <input type="checkbox" name="emp_ids[]" value="<?= htmlspecialchars($e['emp_id']) ?>"
                               onchange="updateCount();updateCard(this)">
                        <div style="min-width:0;">
                            <div class="emp-id"><?= htmlspecialchars($e['emp_id']) ?></div>
                            <div class="emp-name">
                                <?= htmlspecialchars($e['emp_name']) ?>
                                <?php if (!empty($e['revised_after_finalize'])): ?>
                                    <span class="rev-tag" title="This employee's pay was changed after the period was finalized">Revised</span>
                                <?php endif; ?>
                            </div>
                            <div class="emp-fig">
                                bonus <b>&#8369;<?= number_format($e['bonus'], 2) ?></b>
                                &bull; ded <b>&#8369;<?= number_format($e['other_deductions'], 2) ?></b>
                                &bull; net <b>&#8369;<?= number_format($e['net_pay'], 2) ?></b>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="btn btn-green" id="applyBtn">Apply to Selected Employees</button>
            <button type="button" class="btn btn-ghost" onclick="clearAll();updateCount()">Reset Selection</button>
        </div>
    </form>
</div>

<script>
function updateCount() {
    const n = document.querySelectorAll('#empGrid input[type=checkbox]:checked').length;
    document.getElementById('selCount').textContent = n + ' selected';
    document.getElementById('applyBtn').textContent = n
        ? 'Apply to ' + n + ' Employee' + (n > 1 ? 's' : '')
        : 'Apply to Selected Employees';
}

function updateCard(cb) {
    cb.closest('.emp-card').classList.toggle('selected', cb.checked);
}

function selectAll() {
    document.querySelectorAll('#empGrid .emp-card:not(.hidden) input[type=checkbox]').forEach(cb => {
        cb.checked = true;
        cb.closest('.emp-card').classList.add('selected');
    });
    updateCount();
}

function clearAll() {
    document.querySelectorAll('#empGrid input[type=checkbox]').forEach(cb => {
        cb.checked = false;
        cb.closest('.emp-card').classList.remove('selected');
    });
    updateCount();
}

function filterEmployees() {
    const q = document.getElementById('empSearch').value.toLowerCase();
    document.querySelectorAll('.emp-card').forEach(card => {
        const match = !q || card.dataset.search.includes(q);
        card.classList.toggle('hidden', !match);
        card.style.display = match ? '' : 'none';
    });
}

function updateReasonOptions() {
    const type = document.getElementById('typeSelect').value;
    document.getElementById('bonusReasons').style.display = type === 'Bonus'     ? '' : 'none';
    document.getElementById('dedReasons').style.display   = type === 'Deduction' ? '' : 'none';
    document.getElementById('reasonSelect').value = '';
    document.getElementById('reasonCustom').style.display = 'none';
}

function toggleCustomReason() {
    const sel = document.getElementById('reasonSelect');
    document.getElementById('reasonCustom').style.display = sel.value === '__custom__' ? '' : 'none';
}

function validateForm() {
    const reason = document.getElementById('reasonSelect').value;
    const custom = document.getElementById('reasonCustom').value.trim();
    if (!reason || (reason === '__custom__' && !custom)) {
        alert('Please select or enter a reason.');
        return false;
    }
    if (!document.querySelectorAll('#empGrid input[type=checkbox]:checked').length) {
        alert('Please select at least one employee.');
        return false;
    }
    return true;
}
</script>
</body>
</html>
