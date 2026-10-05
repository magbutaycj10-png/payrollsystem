<?php
require_once __DIR__ . '/includes/auth.php';
requireEmployee();

$activePage = 'leave';
$db  = getDB();
$e   = emp();
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    $type      = $_POST['leave_type'] ?? '';
    $date_from = $_POST['date_from']  ?? '';
    $date_to   = $_POST['date_to']    ?? '';
    $reason    = trim($_POST['reason'] ?? '');

    $validTypes = ['Vacation','Sick Leave','Emergency','Other'];

    $isDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
    /* a request already covering any of these days, still pending or approved */
    $overlap = null;
    if ($isDate($date_from) && $isDate($date_to)) {
        $ov = $db->prepare("SELECT date_from, date_to, status FROM leave_requests
                             WHERE emp_id = ? AND status IN ('Pending','Approved') AND date_from <= ? AND date_to >= ? LIMIT 1");
        $ov->execute([$e['id'], $date_to, $date_from]);
        $overlap = $ov->fetch();
    }

    if (!in_array($type, $validTypes) || !$date_from || !$date_to) {
        $msg = ['type' => 'error', 'text' => 'Please fill in all required fields.'];
    } elseif (!$isDate($date_from) || !$isDate($date_to)) {
        $msg = ['type' => 'error', 'text' => 'Please choose valid dates.'];
    } elseif ($date_to < $date_from) {
        $msg = ['type' => 'error', 'text' => 'End date cannot be before start date.'];
    } elseif ($overlap) {
        $msg = ['type' => 'error', 'text' => 'You already have a ' . strtolower($overlap['status']) . ' request for '
              . date('M d', strtotime($overlap['date_from'])) . ($overlap['date_to'] !== $overlap['date_from'] ? '–' . date('M d, Y', strtotime($overlap['date_to'])) : ', ' . date('Y', strtotime($overlap['date_from'])))
              . ' that covers some of these days.'];
    } else {
        $empName = $db->prepare("SELECT full_name FROM employees WHERE emp_id=?");
        $empName->execute([$e['id']]);
        $empName = $empName->fetchColumn();

        $db->prepare("
            INSERT INTO leave_requests (emp_id, emp_name, leave_type, date_from, date_to, reason)
            VALUES (?,?,?,?,?,?)
        ")->execute([$e['id'], $empName, $type, $date_from, $date_to, $reason]);

        $msg = ['type' => 'success', 'text' => 'Leave request submitted. Your manager will review it.'];
    }
}

/* ── History filters ──────────────────────────────────────────────────
 * The list only ever holds this employee's own requests, so the search
 * here is by date range, status and type rather than by name.
 */
$dateFrom = trim($_GET['from'] ?? '');
$dateTo   = trim($_GET['to']   ?? '');
$status   = $_GET['status']    ?? 'All';
$type     = $_GET['type']      ?? 'All';

$statuses = ['All', 'Pending', 'Approved', 'Rejected'];
$types    = ['All', 'Vacation', 'Sick Leave', 'Emergency', 'Other'];
if (!in_array($status, $statuses, true)) $status = 'All';
if (!in_array($type,   $types,    true)) $type   = 'All';

$isDate   = fn($d) => $d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$dateFrom = $isDate($dateFrom) ? $dateFrom : '';
$dateTo   = $isDate($dateTo)   ? $dateTo   : '';

$where  = ['emp_id = ?'];
$params = [$e['id']];
/* A request matches the range when its leave dates overlap it */
if ($dateFrom !== '')  { $where[] = "date_to   >= ?"; $params[] = $dateFrom; }
if ($dateTo   !== '')  { $where[] = "date_from <= ?"; $params[] = $dateTo; }
if ($status !== 'All') { $where[] = "status = ?";     $params[] = $status; }
if ($type   !== 'All') { $where[] = "leave_type = ?"; $params[] = $type; }

$requests = $db->prepare("
    SELECT * FROM leave_requests
    WHERE " . implode(' AND ', $where) . "
    ORDER BY created_at DESC
");
$requests->execute($params);
$requests = $requests->fetchAll();

$hasFilters = $dateFrom !== '' || $dateTo !== '' || $status !== 'All' || $type !== 'All';

/* This employee's weekly day(s) off — leave on those days is not counted */
$myRestDays = $db->prepare("SELECT rest_days FROM employees WHERE emp_id = ?");
$myRestDays->execute([$e['id']]);
$myRestDays = $myRestDays->fetchColumn() ?: null;

/* Everything the details dialog shows, keyed by request id */
$detailMap = [];
$daysTotal = 0;
foreach ($requests as $lr) {
    $days = (int)((strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400) + 1;
    $daysTotal += leaveDays($lr['date_from'], $lr['date_to'], $myRestDays)['duty'];
    $detailMap[$lr['id']] = [
        'type'      => $lr['leave_type'],
        'from'      => date('M d, Y', strtotime($lr['date_from'])),
        'to'        => date('M d, Y', strtotime($lr['date_to'])),
        'days'      => $days,
        'days_label' => leaveDaysLabel($lr['date_from'], $lr['date_to'], $myRestDays),
        'reason'    => trim((string)$lr['reason']) !== '' ? $lr['reason'] : 'No reason given.',
        'status'    => $lr['status'],
        'reviewer'  => $lr['reviewed_by'] ?: '—',
        'note'      => trim((string)$lr['review_note']) !== '' ? $lr['review_note'] : '—',
        'submitted' => date('M d, Y g:i A', strtotime($lr['created_at'])),
        'reviewed'  => $lr['reviewed_at'] ? date('M d, Y g:i A', strtotime($lr['reviewed_at'])) : '—',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Requests — Employee Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <style>
        /* ── Filter bar ── */
        .filter-bar {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
            padding: 0 0 16px; margin-bottom: 4px;
        }
        .filter-field { display: flex; flex-direction: column; gap: 5px; }
        .filter-field label {
            font-size: .7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: #94a3b8;
        }
        .filter-field input, .filter-field select {
            padding: 8px 12px; border-radius: 7px; border: 1.5px solid var(--border);
            font-size: .875rem; font-family: var(--font); background: var(--surface); color: var(--text);
        }
        .filter-field input:focus, .filter-field select:focus {
            outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,.12);
        }
        .filter-actions { display: flex; gap: 8px; margin-left: auto; }

        /* ── Details dialog ── */
        .det-grid {
            display: grid; grid-template-columns: repeat(2, 1fr);
            gap: 12px 18px; margin-bottom: 18px;
        }
        .det-cell span {
            display: block; font-size: .7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: 2px;
        }
        .det-cell b { font-size: .9rem; font-weight: 600; }
        .det-reason {
            background: #f8fafc; border: 1px solid var(--border);
            border-radius: 8px; padding: 13px 15px; margin-bottom: 18px;
            font-size: .88rem; line-height: 1.6; white-space: pre-wrap; word-break: break-word;
        }
        .det-section {
            font-size: .7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .07em; color: #94a3b8; margin-bottom: 8px;
        }
        @media (max-width: 900px) {
            .filter-field, .filter-actions { width: 100%; }
            .filter-field input, .filter-field select { width: 100%; }
            .filter-actions { margin-left: 0; }
        }
        @media (max-width: 560px) { .det-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>Leave Requests</h1>
            <p>Submit and track your time-off requests</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="p-alert p-alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <!-- Submit form -->
    <div class="p-box" style="margin-bottom:20px;">
        <div class="p-box-header"><h2>Submit New Request</h2></div>
        <div class="p-box-body">
            <form method="POST">
                <input type="hidden" name="action" value="submit">
                <div class="p-form-grid">
                    <div class="p-form-group">
                        <label>Leave Type *</label>
                        <select name="leave_type" class="p-form-control" required>
                            <option value="">— Select Type —</option>
                            <?php foreach (['Vacation','Sick Leave','Emergency','Other'] as $t): ?>
                            <option value="<?= $t ?>"><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="p-form-group">
                        <label>From *</label>
                        <input type="date" name="date_from" class="p-form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="p-form-group">
                        <label>To *</label>
                        <input type="date" name="date_to" class="p-form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="p-form-group" style="grid-column:1/-1;">
                        <label>Reason</label>
                        <textarea name="reason" class="p-form-control" placeholder="Briefly describe your reason for leave…"></textarea>
                    </div>
                </div>
                <div class="p-form-actions">
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Request history -->
    <div class="p-box">
        <div class="p-box-header">
            <h2>My Requests (<?= count($requests) ?>)</h2>
            <span class="badge badge-blue"><?= $daysTotal ?> duty day(s) of leave</span>
        </div>
        <div class="p-box-body" style="padding-bottom:0;">
            <form method="GET" class="filter-bar">
                <div class="filter-field">
                    <label>Leave dates from</label>
                    <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="filter-field">
                    <label>To</label>
                    <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="filter-field">
                    <label>Status</label>
                    <select name="status">
                        <?php foreach ($statuses as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label>Type</label>
                    <select name="type">
                        <?php foreach ($types as $t): ?>
                        <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <?php if ($hasFilters): ?>
                        <a href="/employee/leave.php" class="btn btn-ghost btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <div class="p-table-wrap">
            <table class="p-table">
                <thead>
                    <tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th>Submitted</th><th>Details</th></tr>
                </thead>
                <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:24px;">
                        <?= $hasFilters ? 'No requests match these filters.' : 'No leave requests yet.' ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $lr): ?>
                    <?php $days = (int)((strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400) + 1; ?>
                    <tr>
                        <td><?= htmlspecialchars($lr['leave_type']) ?></td>
                        <td><?= date('M d, Y', strtotime($lr['date_from'])) ?></td>
                        <td><?= date('M d, Y', strtotime($lr['date_to'])) ?></td>
                        <td><?= htmlspecialchars(leaveDaysLabel($lr['date_from'], $lr['date_to'], $myRestDays)) ?></td>
                        <td>
                            <span class="badge badge-<?= $lr['status']==='Approved' ? 'green' : ($lr['status']==='Rejected' ? 'red' : 'yellow') ?>">
                                <?= $lr['status'] ?>
                            </span>
                        </td>
                        <td style="font-size:.8rem;color:#9ca3af;"><?= date('M d, Y', strtotime($lr['created_at'])) ?></td>
                        <td>
                            <button class="btn btn-ghost btn-sm" onclick="openLeave(<?= (int)$lr['id'] ?>)">Details</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Details dialog: your reason and the manager's note live here -->
<div id="leaveModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)closeLeave()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeLeave()">Close</button>
        <h2 class="modal-title" id="lmTitle">Leave Request</h2>
        <p style="font-size:.82rem;color:#6b7280;margin-bottom:18px;" id="lmSub"></p>

        <div class="det-grid">
            <div class="det-cell"><span>Leave type</span><b id="lmType"></b></div>
            <div class="det-cell"><span>Status</span><b id="lmStatus"></b></div>
            <div class="det-cell"><span>From</span><b id="lmFrom"></b></div>
            <div class="det-cell"><span>To</span><b id="lmTo"></b></div>
            <div class="det-cell"><span>Duration</span><b id="lmDays"></b></div>
            <div class="det-cell"><span>Submitted</span><b id="lmSubmitted"></b></div>
        </div>

        <div class="det-section">Reason you gave</div>
        <div class="det-reason" id="lmReason"></div>

        <div class="det-grid" style="margin-bottom:0;">
            <div class="det-cell"><span>Reviewed by</span><b id="lmReviewer"></b></div>
            <div class="det-cell"><span>Reviewed on</span><b id="lmReviewed"></b></div>
            <div class="det-cell" style="grid-column:1/-1;"><span>Manager note</span><b id="lmNote"></b></div>
        </div>

        <p id="lmPendingHint" style="display:none;font-size:.8rem;color:#92400e;background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;padding:10px 12px;margin-top:16px;">
            This request is still waiting for your manager's decision.
        </p>
    </div>
</div>

<script>
var LEAVES = <?= json_encode($detailMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var modal  = document.getElementById('leaveModal');

function openLeave(id) {
    var d = LEAVES[id];
    if (!d) return;

    document.getElementById('lmTitle').textContent     = d.type + ' leave';
    document.getElementById('lmSub').textContent       = d.from + ' — ' + d.to;
    document.getElementById('lmType').textContent      = d.type;
    document.getElementById('lmFrom').textContent      = d.from;
    document.getElementById('lmTo').textContent        = d.to;
    document.getElementById('lmDays').textContent      = d.days_label;   /* calendar days, and the duty days among them (what payroll pays) */
    document.getElementById('lmSubmitted').textContent = d.submitted;
    document.getElementById('lmReason').textContent    = d.reason;
    document.getElementById('lmReviewer').textContent  = d.reviewer;
    document.getElementById('lmReviewed').textContent  = d.reviewed;
    document.getElementById('lmNote').textContent      = d.note;

    var cls = d.status === 'Approved' ? 'badge-green' : (d.status === 'Rejected' ? 'badge-red' : 'badge-yellow');
    document.getElementById('lmStatus').innerHTML = '<span class="badge ' + cls + '">' + d.status + '</span>';
    document.getElementById('lmPendingHint').style.display = d.status === 'Pending' ? 'block' : 'none';

    modal.style.display = 'flex';
}

function closeLeave() { modal.style.display = 'none'; }

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLeave();
});
</script>
</body>
</html>
